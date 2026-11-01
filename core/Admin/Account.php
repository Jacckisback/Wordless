<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Activity;
use Wordless\Auth;
use Wordless\Config;
use Wordless\Crypto;
use Wordless\Db;
use Wordless\Http;
use Wordless\I18n;
use Wordless\Mailer;
use Wordless\Media;
use Wordless\Qr;
use Wordless\Settings;
use Wordless\Throttle;
use Wordless\Totp;

/** Connexion, double authentification, mot de passe oublié, profil. */
final class Account
{
    /** Jeton anti-CSRF des formulaires publics (avant connexion) : cookie + champ. */
    private static function preToken(): string
    {
        $t = (string) ($_COOKIE['wl_pre'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $t)) {
            $t = bin2hex(random_bytes(16));
            setcookie('wl_pre', $t, ['expires' => 0, 'path' => Config::basePath() . Config::adminPath(), 'httponly' => true, 'samesite' => 'Strict', 'secure' => Http::isHttps()]);
            $_COOKIE['wl_pre'] = $t;
        }
        return $t;
    }

    private static function preValid(): bool
    {
        $c = (string) ($_COOKIE['wl_pre'] ?? '');
        return $c !== '' && hash_equals($c, (string) ($_POST['_pre'] ?? ''));
    }

    public function login(): string
    {
        if (Auth::check()) {
            redirect(Kernel::safeRedirect($_GET['redirect'] ?? null, admin_url()));
        }
        $error = null;
        $email = '';
        if (Http::method() === 'POST') {
            $email = trim((string) ($_POST['email'] ?? ''));
            if (!self::preValid()) {
                $error = __('La page a expiré, merci de réessayer.');
            } else {
                $res = Auth::attempt($email, (string) ($_POST['password'] ?? ''));
                if ($res['ok']) {
                    Auth::login($res['user'], !empty($_POST['remember']));
                    $to = Kernel::safeRedirect($_POST['redirect'] ?? null, admin_url());
                    if ((int) $res['user']['totp_enabled'] === 1) {
                        redirect(admin_url('2fa') . '?redirect=' . rawurlencode($to));
                    }
                    redirect($to);
                }
                $error = $res['error'];
            }
        }
        return Kernel::bare('login', ['error' => $error, 'email' => $email, 'pre' => self::preToken(), 'redirect' => (string) ($_GET['redirect'] ?? $_POST['redirect'] ?? '')]);
    }

    public function twofa(): string
    {
        if (!Auth::pending2fa()) {
            redirect(Auth::check() ? admin_url() : admin_url('login'));
        }
        $error = null;
        if (Http::method() === 'POST') {
            if (!self::preValid()) {
                $error = __('La page a expiré, merci de réessayer.');
            } elseif (Auth::verify2fa((string) ($_POST['code'] ?? ''))) {
                redirect(Kernel::safeRedirect($_POST['redirect'] ?? null, admin_url()));
            } else {
                $error = __('Code incorrect ou expiré. Utilisez le code affiché maintenant dans votre application, ou un code de secours.');
            }
        }
        return Kernel::bare('twofa', ['error' => $error, 'pre' => self::preToken(), 'redirect' => (string) ($_GET['redirect'] ?? $_POST['redirect'] ?? '')]);
    }

    public function logout(): string
    {
        if (Http::method() === 'POST' && (Auth::verifyCsrf() || Auth::pending2fa())) {
            Auth::logout();
            Kernel::flash(__('Vous êtes déconnecté.'));
        }
        redirect(admin_url('login'));
    }

    public function forgot(): string
    {
        $sent = false;
        if (Http::method() === 'POST' && self::preValid()) {
            $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
            $key = 'forgot:' . ip_hash();
            if (!Throttle::blocked($key) && Throttle::hit($key, 5, 3600, 3600) === 0) {
                $user = Db::one("SELECT * FROM users WHERE email = ? AND status <> 'disabled'", [$email]);
                if ($user) {
                    $token = Crypto::token(32);
                    Db::run("DELETE FROM tokens WHERE kind = 'reset' AND user_id = ?", [$user['id']]);
                    Db::insert('tokens', ['id' => Crypto::hash($token), 'kind' => 'reset', 'user_id' => $user['id'], 'expires_at' => time() + 3600, 'created_at' => time()]);
                    $link = Config::baseUrl() . Config::adminPath() . '/reset?token=' . $token;
                    Mailer::send($user['email'], __('Réinitialisation de votre mot de passe – {s}', ['s' => Settings::get('site.name')]),
                        __("Bonjour {n},\n\nPour choisir un nouveau mot de passe, ouvrez ce lien (valable une heure) :\n{l}\n\nSi vous n’êtes pas à l’origine de cette demande, ignorez ce message.", ['n' => $user['name'], 'l' => $link]));
                }
            }
            $sent = true;
        }
        return Kernel::bare('forgot', ['sent' => $sent, 'pre' => self::preToken()]);
    }

    public function reset(): string
    {
        $token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
        $row = $token !== '' ? Db::one("SELECT * FROM tokens WHERE id = ? AND kind = 'reset' AND expires_at > ?", [Crypto::hash($token), time()]) : null;
        $user = $row ? Db::one('SELECT * FROM users WHERE id = ?', [(int) $row['user_id']]) : null;
        $error = null;
        if ($user && Http::method() === 'POST' && self::preValid()) {
            $pass = (string) ($_POST['password'] ?? '');
            $error = Auth::passwordProblem($pass, $user['email']);
            if (!$error) {
                Db::update('users', ['password' => Auth::hashPassword($pass), 'status' => 'active'], ['id' => $user['id']]);
                Db::delete('tokens', ['id' => $row['id']]);
                Auth::logoutEverywhere((int) $user['id']);
                Activity::log('user', 'user', (int) $user['id'], __('mot de passe réinitialisé'), (int) $user['id']);
                Kernel::flash(__('Mot de passe enregistré. Vous pouvez vous connecter.'));
                redirect(admin_url('login'));
            }
        }
        return Kernel::bare('reset', ['valid' => (bool) $user, 'token' => $token, 'error' => $error, 'pre' => self::preToken(), 'user' => $user]);
    }

    public function profile(): string
    {
        $u = Auth::user();
        if (Http::method() === 'POST') {
            $action = (string) ($_POST['action'] ?? 'profile');
            if ($action === 'profile') {
                $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    Kernel::flash(__('Adresse e-mail invalide.'), 'bad');
                    redirect(admin_url('profile'));
                }
                if (Db::val('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $u['id']])) {
                    Kernel::flash(__('Cette adresse est déjà utilisée par un autre compte.'), 'bad');
                    redirect(admin_url('profile'));
                }
                $links = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($_POST['links'] ?? '')) ?: []), static fn ($l) => (bool) preg_match('#^https://\S+$#', $l)));
                Db::update('users', [
                    'name' => mb_substr(trim(strip_tags((string) ($_POST['name'] ?? $u['name']))), 0, 80) ?: $u['name'],
                    'email' => $email,
                    'bio' => mb_substr(trim(strip_tags((string) ($_POST['bio'] ?? ''))), 0, 1500),
                    'links' => json_enc(array_slice($links, 0, 10)),
                    'avatar_id' => !empty($_POST['avatar_id']) ? (int) $_POST['avatar_id'] : null,
                    'lang' => isset(I18n::LANGUAGES[$_POST['lang'] ?? '']) ? $_POST['lang'] : '',
                ], ['id' => $u['id']]);
                \Wordless\Cache::purge();
                Kernel::flash(__('Profil enregistré.'));
            } elseif ($action === 'password') {
                if (!password_verify((string) ($_POST['current'] ?? ''), $u['password'])) {
                    Kernel::flash(__('Mot de passe actuel incorrect.'), 'bad');
                } elseif ($p = Auth::passwordProblem((string) ($_POST['password'] ?? ''), $u['email'])) {
                    Kernel::flash($p, 'bad');
                } else {
                    Db::update('users', ['password' => Auth::hashPassword((string) $_POST['password'])], ['id' => $u['id']]);
                    Db::run('DELETE FROM sessions WHERE user_id = ? AND id <> ?', [$u['id'], Crypto::hash((string) ($_COOKIE[Auth::COOKIE] ?? ''))]);
                    Activity::log('user', 'user', (int) $u['id'], __('mot de passe modifié'));
                    Kernel::flash(__('Mot de passe modifié. Vos autres sessions ont été fermées.'));
                }
            } elseif ($action === 'sessions') {
                Db::run('DELETE FROM sessions WHERE user_id = ? AND id <> ?', [$u['id'], Crypto::hash((string) ($_COOKIE[Auth::COOKIE] ?? ''))]);
                Kernel::flash(__('Les autres appareils ont été déconnectés.'));
            }
            redirect(admin_url('profile'));
        }
        $sessions = Db::all('SELECT * FROM sessions WHERE user_id = ? AND expires_at > ? ORDER BY seen_at DESC', [$u['id'], time()]);
        $current = Crypto::hash((string) ($_COOKIE[Auth::COOKIE] ?? ''));
        return Kernel::page('profile', ['u' => $u, 'sessions' => $sessions, 'current' => $current, 'avatar' => Media::find($u['avatar_id'] ? (int) $u['avatar_id'] : null)], __('Mon profil'), 'profile', '', ['scripts' => ['media.js']]);
    }

    public function twofaSetup(): string
    {
        $u = Auth::user();
        $codes = null;
        if (Http::method() === 'POST') {
            $action = (string) ($_POST['action'] ?? '');
            if ($action === 'enable') {
                $secret = (string) (Crypto::decrypt((string) ($_POST['secret'] ?? '')) ?? '');
                if ($secret !== '' && Totp::verify($secret, (string) ($_POST['code'] ?? ''))) {
                    $codes = Totp::recoveryCodes();
                    Db::update('users', [
                        'totp_secret' => Crypto::encrypt($secret), 'totp_enabled' => 1,
                        'recovery_codes' => array_map(static fn ($c) => hash('sha256', str_replace('-', '', $c)), $codes),
                    ], ['id' => $u['id']]);
                    Activity::log('2fa_on', 'user', (int) $u['id']);
                    return Kernel::page('profile-2fa', ['u' => Db::one('SELECT * FROM users WHERE id = ?', [$u['id']]), 'codes' => $codes], __('Double authentification'), 'profile', '', ['narrow' => true]);
                }
                Kernel::flash(__('Code incorrect : vérifiez l’heure de votre téléphone et réessayez.'), 'bad');
            } elseif ($action === 'disable') {
                if (password_verify((string) ($_POST['password'] ?? ''), $u['password'])) {
                    Db::update('users', ['totp_secret' => '', 'totp_enabled' => 0, 'recovery_codes' => '[]'], ['id' => $u['id']]);
                    Activity::log('2fa_off', 'user', (int) $u['id']);
                    Kernel::flash(__('Double authentification désactivée.'), 'warn');
                } else {
                    Kernel::flash(__('Mot de passe incorrect.'), 'bad');
                }
            }
            redirect(admin_url('profile/2fa'));
        }
        $secret = Totp::secret();
        $issuer = (string) Settings::get('site.name', 'Wordless');
        $uri = Totp::uri($secret, $u['email'], $issuer);
        return Kernel::page('profile-2fa', [
            'u' => $u, 'secret' => $secret, 'sealed' => Crypto::encrypt($secret), 'uri' => $uri, 'qr' => Qr::svg($uri), 'codes' => null,
        ], __('Double authentification'), 'profile', '', ['narrow' => true]);
    }
}
