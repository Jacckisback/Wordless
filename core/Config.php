<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Configuration écrite à l'installation (data/config.php) : nom du fichier de
 * base, secret, adresse du site, chemin de l'admin. Tout le reste est en base.
 */
final class Config
{
    private static ?array $config = null;

    public static function file(): string
    {
        return WL_DATA . '/config.php';
    }

    public static function installed(): bool
    {
        return is_file(self::file());
    }

    public static function all(): array
    {
        if (self::$config === null) {
            self::$config = self::installed() ? (array) require self::file() : [];
        }
        return self::$config;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $c = self::all();
        $c[$key] = $value;
        self::write($c);
    }

    public static function write(array $config): void
    {
        if (!is_dir(WL_DATA)) {
            mkdir(WL_DATA, 0775, true);
        }
        $php = "<?php\n// Configuration Wordless — ne pas partager ce fichier (il contient le secret du site).\nreturn " . var_export($config, true) . ";\n";
        $tmp = self::file() . '.tmp';
        file_put_contents($tmp, $php, LOCK_EX);
        @chmod($tmp, 0640);
        rename($tmp, self::file());
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate(self::file(), true);
        }
        self::$config = $config;
    }

    public static function dbPath(): string
    {
        return WL_DATA . '/' . basename((string) self::get('db', 'wordless.db'));
    }

    /** Adresse publique du site sans barre finale, ex. https://exemple.fr ou https://exemple.fr/blog */
    public static function baseUrl(): string
    {
        $url = (string) self::get('url', '');
        return $url !== '' ? rtrim($url, '/') : Http::detectBaseUrl();
    }

    /** Chemin de base ('' à la racine, '/blog' dans un sous-dossier). */
    public static function basePath(): string
    {
        return rtrim((string) parse_url(self::baseUrl(), PHP_URL_PATH), '/');
    }

    public static function adminPath(): string
    {
        $p = trim((string) self::get('admin', 'admin'), '/');
        return '/' . ($p === '' ? 'admin' : $p);
    }

    public static function debug(): bool
    {
        return (bool) self::get('debug', false) || getenv('WL_DEBUG') === '1';
    }

    public static function secret(): string
    {
        $s = (string) self::get('secret', '');
        if ($s === '') {
            throw new \RuntimeException('Secret manquant dans data/config.php');
        }
        return $s;
    }

    /** Pour les tests : oublie la configuration chargée. */
    public static function reset(): void
    {
        self::$config = null;
    }
}
