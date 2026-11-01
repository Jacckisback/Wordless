<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Cache de pages : chaque page publique générée est écrite sur disque et
 * servie ensuite par core/fastcache.php sans démarrer l'application.
 * Purge complète à chaque modification (simple et sûr), régénération à la demande.
 */
final class Cache
{
    public static function dir(): string
    {
        return WL_DATA . '/cache';
    }

    /** Doit rester identique à la correspondance de core/fastcache.php. */
    public static function extFor(string $path): string
    {
        return match (true) {
            str_ends_with($path, '.xml') => 'xml',
            str_ends_with($path, '.txt') => 'txt',
            str_ends_with($path, '.md') => 'md',
            (bool) preg_match('#/feed/?$#', $path) => 'rss',
            default => 'html',
        };
    }

    public static function file(string $path): string
    {
        return self::dir() . '/pages/' . sha1($path) . '.' . self::extFor($path);
    }

    public static function enabled(): bool
    {
        return (bool) Settings::get('cache.enabled', true);
    }

    /** Peut-on mettre en cache cette réponse ? (GET anonyme, sans paramètres réels) */
    public static function cacheable(): bool
    {
        if (!self::enabled() || Http::method() !== 'GET' || Auth::check() || !empty($_COOKIE[Auth::COOKIE])) {
            return false;
        }
        foreach (array_keys($_GET) as $k) {
            if (!preg_match('/^(utm_[a-z]+|fbclid|gclid|msclkid|mc_cid|mc_eid|ref)$/', (string) $k)) {
                return false;
            }
        }
        return true;
    }

    public static function store(string $path, string $body, ?string $csp = null): void
    {
        if (!self::cacheable()) {
            return;
        }
        $file = self::file($path);
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, $body) !== false) {
            @rename($tmp, $file);
        }
        if ($csp !== null) {
            @file_put_contents($file . '.csp', $csp);
        }
        if (!is_file(self::dir() . '/meta.php')) {
            self::refreshMeta();
        }
    }

    public static function purge(): void
    {
        foreach (glob(self::dir() . '/pages/*') ?: [] as $f) {
            @unlink($f);
        }
        self::refreshMeta();
    }

    /**
     * Métadonnées lues par le chemin rapide : chemin de base, date d'expiration
     * (prochain contenu programmé, au plus 12 h), en-têtes de sécurité.
     */
    public static function refreshMeta(): void
    {
        if (!is_dir(self::dir())) {
            @mkdir(self::dir() . '/pages', 0775, true);
        }
        $file = self::dir() . '/meta.php';
        if (!self::enabled()) {
            @unlink($file);
            return;
        }
        $expires = time() + 12 * 3600;
        $next = (int) Settings::get('schedule.next', 0);
        if ($next > time() && $next < $expires) {
            $expires = $next;
        }
        $headers = Http::securityHeaders('front', Theme::styleHash());
        $csp = '';
        foreach ($headers as $i => $h) {
            if (str_starts_with($h, 'Content-Security-Policy:')) {
                $csp = $h;
                unset($headers[$i]);
            }
        }
        $headers[] = $csp;
        $meta = [
            'base' => Config::basePath(),
            'expires' => $expires,
            'headers' => array_values(array_filter($headers)),
            'stats' => (bool) Settings::get('stats.enabled', true),
        ];
        $tmp = $file . '.tmp';
        @file_put_contents($tmp, "<?php\nreturn " . var_export($meta, true) . ";\n");
        @rename($tmp, $file);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }

    public static function expired(): bool
    {
        $file = self::dir() . '/meta.php';
        if (!is_file($file)) {
            return true;
        }
        $meta = include $file;
        return !is_array($meta) || time() >= (int) ($meta['expires'] ?? 0);
    }

    public static function stats(): array
    {
        $files = glob(self::dir() . '/pages/*.{html,xml,rss,txt,md}', GLOB_BRACE) ?: [];
        $size = 0;
        foreach ($files as $f) {
            $size += (int) @filesize($f);
        }
        return ['pages' => count($files), 'bytes' => $size];
    }
}
