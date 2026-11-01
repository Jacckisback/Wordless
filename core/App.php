<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Aiguillage principal : installation, admin, MCP/OAuth, formulaires publics, site.
 */
final class App
{
    public static function run(): void
    {
        self::handleErrors();

        if (!Config::installed()) {
            Installer::handle();
            return;
        }
        self::boot();

        $path = Http::path();
        $admin = Config::adminPath();

        if ($path === $admin || str_starts_with($path, $admin . '/')) {
            Admin\Kernel::handle(substr($path, strlen($admin)) ?: '/');
            return;
        }
        if ($path === '/mcp' || str_starts_with($path, '/mcp/')) {
            Mcp\Server::handle(substr($path, 4));
            return;
        }
        if (str_starts_with($path, '/.well-known/oauth') || str_starts_with($path, '/.well-known/openid') || str_starts_with($path, '/oauth/')) {
            Mcp\OAuth::handle($path);
            return;
        }
        if (Http::method() === 'POST' && $path === '/wl/comment') {
            Comments::submit();
            return;
        }
        if (Http::method() === 'POST' && $path === '/wl/form') {
            Forms::submit();
            return;
        }
        register_shutdown_function([Cron::class, 'maybeRun']);
        Front::handle($path);
    }

    /** Connexion à la base, migrations, langue, extensions. */
    public static function boot(): void
    {
        Db::pdo();
        if (Schema::version() < Schema::latest()) {
            Schema::migrate();
        }
        I18n::load((string) Settings::get('language', 'fr'));
        Hooks::loadPlugins();
        wl_do('init');
    }

    private static function handleErrors(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', Config::installed() && Config::debug() ? '1' : '0');
        set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
            if (!(error_reporting() & $no)) {
                return false;
            }
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
        set_exception_handler([self::class, 'fail']);
    }

    public static function fail(\Throwable $e): void
    {
        self::logError($e);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
        }
        $debug = Config::installed() && Config::debug();
        if (Http::isAjax() || str_starts_with(Http::path(), '/mcp')) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['error' => $debug ? $e->getMessage() : 'Erreur interne'], JSON_UNESCAPED_UNICODE);
            return;
        }
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }
        $detail = $debug ? '<pre style="white-space:pre-wrap;font-size:13px">' . htmlspecialchars($e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString()) . '</pre>' : '';
        echo '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Erreur</title>'
            . '<body style="font:16px/1.6 system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1.25rem;color:#1d2433">'
            . '<h1 style="font-size:1.5rem">Une erreur est survenue</h1><p>Le problème a été enregistré dans <code>data/logs</code>. Réessayez dans un instant.</p>'
            . $detail . '</body></html>';
    }

    public static function logError(\Throwable $e): void
    {
        $dir = WL_DATA . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf("[%s] %s: %s in %s:%d %s\n", gmdate('c'), get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), Http::path());
        @file_put_contents($dir . '/error-' . gmdate('Y-m') . '.log', $line, FILE_APPEND | LOCK_EX);
    }
}
