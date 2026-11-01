<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Requête et réponse HTTP : chemin demandé, détection HTTPS, en-têtes de sécurité.
 */
final class Http
{
    private static ?string $path = null;

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
        return (string) ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    public static function detectBaseUrl(): string
    {
        $scheme = self::isHttps() ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $host) ?: 'localhost';
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $dir = rtrim(dirname($script), '/.');
        return $scheme . '://' . $host . $dir;
    }

    /** Chemin demandé, relatif au dossier d'installation, toujours préfixé par '/'. */
    public static function path(): string
    {
        if (self::$path !== null) {
            return self::$path;
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = rawurldecode((string) parse_url('http://x' . $uri, PHP_URL_PATH));
        $base = Config::installed() ? Config::basePath() : rtrim((string) parse_url(self::detectBaseUrl(), PHP_URL_PATH), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }
        $path = preg_replace('#/{2,}#', '/', $path) ?? '/';
        return self::$path = $path;
    }

    public static function setPath(string $path): void
    {
        self::$path = $path;
    }

    public static function query(): string
    {
        return (string) ($_SERVER['QUERY_STRING'] ?? '');
    }

    public static function isAjax(): bool
    {
        return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
    }

    /** Corps JSON de la requête (API admin, MCP, OAuth). */
    public static function json(): array
    {
        static $data = null;
        if ($data === null) {
            $raw = file_get_contents('php://input') ?: '';
            $data = $raw !== '' ? json_decode($raw, true) : [];
            if (!is_array($data)) {
                $data = [];
            }
        }
        return $data;
    }

    public static function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$key] ?? '');
    }

    public static function bearer(): string
    {
        $h = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($h === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (strtolower((string) $k) === 'authorization') {
                    $h = (string) $v;
                }
            }
        }
        return preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m) ? $m[1] : '';
    }

    /**
     * En-têtes de sécurité. Sur le site public, la CSP est stricte (aucun script en
     * ligne) sauf si l'administrateur a ajouté du code tiers (analytics, pixels).
     */
    public static function securityHeaders(string $context = 'front', ?string $styleHash = null): array
    {
        $h = [
            'X-Content-Type-Options: nosniff',
            'Referrer-Policy: strict-origin-when-cross-origin',
            'Permissions-Policy: camera=(), microphone=(), geolocation=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy: same-origin',
        ];
        if (Config::installed() && Settings::get('security.hsts', false) && self::isHttps()) {
            $h[] = 'Strict-Transport-Security: max-age=31536000; includeSubDomains';
        }
        if ($context === 'admin') {
            $h[] = 'X-Frame-Options: SAMEORIGIN';
            $h[] = "Content-Security-Policy: default-src 'self'; img-src 'self' data: blob: https:; media-src 'self' blob: https:; "
                . "style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; "
                . "frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com; frame-ancestors 'self'; "
                . "form-action 'self' https:; base-uri 'self'; object-src 'none'";
            $h[] = 'Cache-Control: no-store';
            return $h;
        }
        $relaxed = Config::installed() && (Settings::get('code.head', '') !== '' || Settings::get('code.footer', '') !== '');
        $h[] = 'X-Frame-Options: SAMEORIGIN';
        if ($relaxed) {
            $h[] = "Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'; form-action 'self'";
        } else {
            $style = $styleHash ? "'self' $styleHash" : "'self' 'unsafe-inline'";
            $h[] = "Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; media-src 'self' https:; "
                . "style-src $style; script-src 'self'; connect-src 'self'; "
                . "frame-src https://www.youtube-nocookie.com https://player.vimeo.com; "
                . "frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'; upgrade-insecure-requests";
        }
        return $h;
    }

    public static function sendHeaders(array $headers): void
    {
        if (headers_sent()) {
            return;
        }
        foreach ($headers as $h) {
            header($h);
        }
    }
}
