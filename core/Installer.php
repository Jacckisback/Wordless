<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Installation en un écran : aucun identifiant de base de données à saisir
 * (SQLite), un compte administrateur, et le site est en ligne.
 */
final class Installer
{
    public static function requirements(): array
    {
        $checks = [
            ['PHP 8.1 ou plus récent', PHP_VERSION_ID >= 80100, PHP_VERSION],
            ['Extension pdo_sqlite', extension_loaded('pdo_sqlite'), ''],
            ['Extension mbstring', extension_loaded('mbstring'), ''],
            ['Extensions dom et xml', extension_loaded('dom') && extension_loaded('xmlreader'), ''],
            ['Dossier data/ inscriptible', self::writable(WL_DATA), WL_DATA],
            ['Dossier media/ inscriptible', self::writable(WL_MEDIA), WL_MEDIA],
        ];
        $optional = [
            ['GD (images WebP, miniatures)', extension_loaded('gd') && function_exists('imagewebp')],
            ['cURL (IA, import WordPress)', extension_loaded('curl')],
            ['intl (dates et translittération)', extension_loaded('intl')],
            ['sodium (chiffrement des clés)', function_exists('sodium_crypto_secretbox')],
            ['zip (sauvegardes complètes)', class_exists('ZipArchive')],
        ];
        return [$checks, $optional];
    }

    private static function writable(string $dir): bool
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) && is_writable($dir);
    }

    public static function handle(): void
    {
        I18n::load((string) ($_POST['language'] ?? I18n::detect()));
        $path = Http::path();
        if ($path !== '/' && !str_starts_with($path, '/admin')) {
            // Avant l'installation, toutes les adresses mènent à l'assistant.
            redirect(rtrim((string) parse_url(Http::detectBaseUrl(), PHP_URL_PATH), '/') . '/');
        }
        Http::sendHeaders(Http::securityHeaders('admin'));
        [$checks, $optional] = self::requirements();
        $ok = !in_array(false, array_column($checks, 1), true);
        $errors = [];
        $old = ['site_name' => '', 'email' => '', 'name' => '', 'language' => I18n::lang(), 'migrate' => ''];

        if ($ok && Http::method() === 'POST') {
            $old = array_merge($old, array_map(static fn ($v) => is_string($v) ? trim($v) : '', array_intersect_key($_POST, $old)));
            $cookie = (string) ($_COOKIE['wl_install'] ?? '');
            if ($cookie === '' || !hash_equals($cookie, (string) ($_POST['_token'] ?? ''))) {
                $errors[] = __('La session a expiré, merci de renvoyer le formulaire.');
            }
            if ($old['site_name'] === '') {
                $errors[] = __('Donnez un nom à votre site.');
            }
            if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = __('Adresse e-mail invalide.');
            }
            $password = (string) ($_POST['password'] ?? '');
            if ($p = Auth::passwordProblem($password, $old['email'])) {
                $errors[] = $p;
            }
            if (!$errors) {
                self::install([
                    'site_name' => $old['site_name'],
                    'email' => $old['email'],
                    'name' => $old['name'] !== '' ? $old['name'] : (string) strstr($old['email'], '@', true),
                    'password' => $password,
                    'language' => isset(I18n::LANGUAGES[$old['language']]) ? $old['language'] : 'fr',
                    'timezone' => (string) ($_POST['timezone'] ?? ''),
                ]);
                $user = Db::one('SELECT * FROM users ORDER BY id LIMIT 1');
                Auth::login($user, true);
                setcookie('wl_install', '', ['expires' => time() - 3600, 'path' => '/']);
                redirect(admin_url($old['migrate'] === '1' ? 'import?welcome=1' : '?welcome=1'));
            }
        }

        $token = (string) ($_COOKIE['wl_install'] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(16));
            setcookie('wl_install', $token, ['expires' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Strict', 'secure' => Http::isHttps()]);
        }
        header('Content-Type: text/html; charset=utf-8');
        echo View::render(WL_CORE . '/views/install/form.php', compact('checks', 'optional', 'ok', 'errors', 'old', 'token'));
    }

    /** Crée la configuration, la base, le compte et le contenu de départ. */
    public static function install(array $in): void
    {
        foreach ([WL_DATA, WL_DATA . '/cache/pages', WL_DATA . '/logs', WL_DATA . '/backups', WL_DATA . '/stats', WL_DATA . '/import', WL_MEDIA] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
        }
        self::protectDirectories();

        Config::write([
            'db' => 'wordless-' . bin2hex(random_bytes(6)) . '.db',
            'secret' => Crypto::token(48),
            'url' => (string) ($in['url'] ?? Http::detectBaseUrl()),
            'admin' => 'admin',
            'debug' => false,
            'installed_at' => gmdate('c'),
        ]);
        Db::close();
        Settings::reset();
        Schema::migrate();

        $tz = in_array($in['timezone'] ?? '', \DateTimeZone::listIdentifiers(), true) ? $in['timezone'] : 'Europe/Paris';
        Settings::setMany([
            'site.name' => $in['site_name'],
            'site.org_name' => $in['site_name'],
            'language' => $in['language'],
            'timezone' => $tz,
            'seo.indexnow_key' => bin2hex(random_bytes(16)),
            'mail.from' => $in['email'],
        ]);
        I18n::load($in['language']);

        $userId = Db::insert('users', [
            'email' => mb_strtolower($in['email']),
            'name' => $in['name'],
            'slug' => slugify($in['name']) ?: 'admin',
            'role' => 'admin',
            'password' => Auth::hashPassword($in['password']),
            'lang' => $in['language'],
            'created_at' => time(),
        ]);
        if (empty($in['skip_starter'])) {
            Starter::create($userId);
        }
        Cache::refreshMeta();
    }

    /** .htaccess de protection (Apache/LiteSpeed). Nginx : voir docs/NGINX.md. */
    public static function protectDirectories(): void
    {
        $deny = "# Wordless : dossier privé, jamais servi par le web.\nRequire all denied\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
        @file_put_contents(WL_DATA . '/.htaccess', $deny);
        @file_put_contents(WL_DATA . '/index.html', '');
        @file_put_contents(WL_DATA . '/probe.txt', 'wordless-private');
        $media = "# Wordless : aucun script exécutable dans les médias.\nOptions -Indexes -ExecCGI\n"
            . "<FilesMatch \"\\.(php\\d?|phtml|phar|pl|py|cgi|sh|asp|aspx|jsp|shtml|htaccess)$\">\n  Require all denied\n</FilesMatch>\n"
            . "<IfModule mod_php.c>\n  php_flag engine off\n</IfModule>\n"
            . "<IfModule mod_headers.c>\n  Header set X-Content-Type-Options \"nosniff\"\n  Header set Cache-Control \"public, max-age=31536000, immutable\"\n</IfModule>\n";
        if (!is_file(WL_MEDIA . '/.htaccess')) {
            @file_put_contents(WL_MEDIA . '/.htaccess', $media);
        }
    }
}
