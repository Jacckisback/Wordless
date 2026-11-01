<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Authentification : sessions stockées en base (jeton aléatoire en cookie
 * HttpOnly, seule son empreinte SHA-256 est conservée), CSRF par session,
 * double authentification TOTP, rôles et permissions.
 */
final class Auth
{
    public const COOKIE = 'wl_s';

    public const ROLES = [
        'admin' => ['label' => 'Administrateur', 'caps' => ['*']],
        'editor' => ['label' => 'Éditeur', 'caps' => [
            'edit_content', 'edit_others', 'publish', 'delete_content', 'upload', 'moderate',
            'manage_menus', 'manage_seo', 'manage_design', 'use_ai', 'view_stats',
        ]],
        'author' => ['label' => 'Auteur', 'caps' => ['edit_content', 'publish', 'delete_content', 'upload', 'use_ai', 'view_stats']],
    ];

    private static ?array $user = null;
    private static ?array $session = null;
    private static bool $resolved = false;

    /** Utilisateur connecté (et ayant validé la double authentification). */
    public static function user(): ?array
    {
        self::resolve();
        return self::$session && !self::$session['pending_2fa'] ? self::$user : null;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function pending2fa(): ?array
    {
        self::resolve();
        return self::$session && self::$session['pending_2fa'] ? self::$user : null;
    }

    /** Permet à un autre mécanisme (jeton MCP/OAuth) de définir l'utilisateur courant. */
    public static function actAs(array $user): void
    {
        self::$user = $user;
        self::$session = ['id' => '', 'csrf' => '', 'pending_2fa' => 0];
        self::$resolved = true;
    }

    private static function resolve(): void
    {
        if (self::$resolved) {
            return;
        }
        self::$resolved = true;
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($token === '' || strlen($token) > 100) {
            return;
        }
        $session = Db::one('SELECT * FROM sessions WHERE id = ?', [Crypto::hash($token)]);
        if (!$session || (int) $session['expires_at'] < time()) {
            if ($session) {
                Db::delete('sessions', ['id' => $session['id']]);
            }
            self::clearCookie();
            return;
        }
        $user = Db::one("SELECT * FROM users WHERE id = ? AND status = 'active'", [(int) $session['user_id']]);
        if (!$user) {
            return;
        }
        if (time() - (int) $session['seen_at'] > 300) {
            Db::update('sessions', ['seen_at' => time()], ['id' => $session['id']]);
        }
        self::$session = $session;
        self::$user = $user;
    }

    /**
     * Vérifie identifiant + mot de passe avec limitation des tentatives.
     * @return array{ok: bool, error?: string, user?: array}
     */
    public static function attempt(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $ipKey = 'login:ip:' . ip_hash();
        $userKey = 'login:user:' . hash('sha256', $email);
        $wait = max(Throttle::blocked($ipKey), Throttle::blocked($userKey));
        if ($wait > 0) {
            return ['ok' => false, 'error' => __('Trop de tentatives. Réessayez dans {n} minute(s).', ['n' => (int) ceil($wait / 60)])];
        }
        $user = Db::one('SELECT * FROM users WHERE email = ? OR slug = ?', [$email, $email]);
        $hash = $user['password'] ?? '';
        // Vérification à temps constant même si l'utilisateur n'existe pas (empreinte aléatoire,
        // jamais vérifiable) ; un compte sans mot de passe (ex. importé) ne peut pas se connecter.
        $valid = password_verify($password, $hash !== '' ? $hash : self::hashPassword(bin2hex(random_bytes(16))));
        if (!$user || !$valid || $hash === '' || $user['status'] !== 'active') {
            Throttle::hit($ipKey, 30, 900, 900);
            Throttle::hit($userKey, 5, 900, 900);
            Activity::log('login_failed', 'user', $user ? (int) $user['id'] : null, $email);
            return ['ok' => false, 'error' => __('Adresse e-mail ou mot de passe incorrect.')];
        }
        Throttle::clear($userKey);
        if (password_needs_rehash($hash, self::algo())) {
            Db::update('users', ['password' => self::hashPassword($password)], ['id' => $user['id']]);
        }
        return ['ok' => true, 'user' => $user];
    }

    public static function algo(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, self::algo());
    }

    /** Ouvre une session. Si la 2FA est active, la session reste « en attente ». */
    public static function login(array $user, bool $remember = false): void
    {
        $token = Crypto::token(32);
        $ttl = $remember ? 30 * 86400 : 12 * 3600;
        $pending = (int) $user['totp_enabled'] === 1 ? 1 : 0;
        Db::insert('sessions', [
            'id' => Crypto::hash($token),
            'user_id' => (int) $user['id'],
            'csrf' => Crypto::token(24),
            'pending_2fa' => $pending,
            'ip' => ip_hash(),
            'ua' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
            'created_at' => time(),
            'seen_at' => time(),
            'expires_at' => time() + $ttl,
        ]);
        self::setCookie($token, $remember ? time() + $ttl : 0);
        self::$resolved = false;
        $_COOKIE[self::COOKIE] = $token;
        if (!$pending) {
            self::completeLogin((int) $user['id']);
        }
    }

    public static function completeLogin(int $userId): void
    {
        Db::update('users', ['last_login_at' => time()], ['id' => $userId]);
        Activity::log('login', 'user', $userId, '', $userId);
    }

    /** Valide le code TOTP (ou un code de secours) d'une session en attente. */
    public static function verify2fa(string $code): bool
    {
        $user = self::pending2fa();
        if (!$user) {
            return false;
        }
        $key = 'totp:' . $user['id'];
        if (Throttle::blocked($key)) {
            return false;
        }
        $ok = Totp::verify((string) Crypto::decrypt($user['totp_secret']), $code);
        if (!$ok) {
            $codes = json_dec($user['recovery_codes'], []);
            $h = hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? ''));
            if (($i = array_search($h, $codes, true)) !== false) {
                unset($codes[$i]);
                Db::update('users', ['recovery_codes' => array_values($codes)], ['id' => $user['id']]);
                $ok = true;
            }
        }
        if (!$ok) {
            Throttle::hit($key, 5, 600, 600);
            return false;
        }
        Throttle::clear($key);
        Db::update('sessions', ['pending_2fa' => 0], ['id' => self::$session['id']]);
        self::$session['pending_2fa'] = 0;
        self::completeLogin((int) $user['id']);
        return true;
    }

    public static function logout(): void
    {
        self::resolve();
        if (self::$session && self::$session['id'] !== '') {
            Db::delete('sessions', ['id' => self::$session['id']]);
        }
        self::$session = null;
        self::$user = null;
        self::clearCookie();
    }

    /** Ferme toutes les sessions et coupe les connexions d'IA (OAuth) ; les clés API restent. */
    public static function logoutEverywhere(int $userId): void
    {
        Db::delete('sessions', ['user_id' => $userId]);
        Db::run("DELETE FROM tokens WHERE user_id = ? AND kind IN ('oauth_code', 'oauth_access', 'oauth_refresh')", [$userId]);
    }

    public static function csrf(): string
    {
        self::resolve();
        return (string) (self::$session['csrf'] ?? '');
    }

    public static function verifyCsrf(?string $token = null): bool
    {
        $token ??= (string) ($_POST['_csrf'] ?? Http::header('X-CSRF-Token'));
        $expected = self::csrf();
        return $expected !== '' && $token !== '' && hash_equals($expected, $token);
    }

    public static function can(string $cap, ?array $content = null): bool
    {
        $user = self::user();
        if (!$user) {
            return false;
        }
        return self::userCan($user, $cap, $content);
    }

    public static function userCan(array $user, string $cap, ?array $content = null): bool
    {
        $caps = self::ROLES[$user['role']]['caps'] ?? [];
        if (in_array('*', $caps, true)) {
            return true;
        }
        if (!in_array($cap, $caps, true)) {
            return false;
        }
        // Un auteur ne modifie que ses propres contenus.
        if ($content !== null && in_array($cap, ['edit_content', 'publish', 'delete_content'], true)
            && (int) ($content['author_id'] ?? 0) !== (int) $user['id']) {
            return in_array('edit_others', $caps, true);
        }
        return true;
    }

    /** Règles minimales de mot de passe ; renvoie un message d'erreur ou null. */
    public static function passwordProblem(string $password, string $email = ''): ?string
    {
        if (mb_strlen($password) < 10) {
            return __('Le mot de passe doit contenir au moins 10 caractères.');
        }
        $lower = mb_strtolower($password);
        $common = ['motdepasse', 'password', '1234567890', 'azertyuiop', 'qwertyuiop', '0123456789', 'administrateur', 'wordpress', 'wordless', 'motdepasse1', 'password123', 'iloveyou12', 'soleil1234', 'contraseña'];
        if (in_array($lower, $common, true) || preg_match('/^(.)\1+$/', $password)) {
            return __('Ce mot de passe est trop courant. Choisissez une phrase plus personnelle.');
        }
        $local = strtolower((string) strstr($email, '@', true));
        if ($local !== '' && strlen($local) > 3 && str_contains($lower, $local)) {
            return __('Le mot de passe ne doit pas contenir votre adresse e-mail.');
        }
        return null;
    }

    private static function setCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(self::COOKIE, $value, [
            'expires' => $expires,
            'path' => Config::basePath() !== '' ? Config::basePath() . '/' : '/',
            'secure' => Http::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private static function clearCookie(): void
    {
        self::setCookie('', time() - 3600);
        unset($_COOKIE[self::COOKIE]);
    }

    public static function purgeSessions(): void
    {
        Db::run('DELETE FROM sessions WHERE expires_at < ?', [time()]);
    }

    /** Réinitialise l'état (tests, changement d'utilisateur). */
    public static function reset(): void
    {
        self::$user = null;
        self::$session = null;
        self::$resolved = false;
    }
}
