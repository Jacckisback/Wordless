<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Auth;
use Wordless\Config;
use Wordless\Http;
use Wordless\I18n;
use Wordless\Router;
use Wordless\Settings;
use Wordless\View;

/**
 * Administration : routes, contrôle d'accès, CSRF, mise en page.
 */
final class Kernel
{
    /** Pages accessibles sans être connecté. */
    private const PUBLIC = ['/login', '/2fa', '/forgot', '/reset', '/logout'];

    public static function routes(): Router
    {
        $r = new Router();
        $r->get('/', [Dashboard::class, 'index']);
        $r->any('/login', [Account::class, 'login']);
        $r->any('/2fa', [Account::class, 'twofa']);
        $r->any('/forgot', [Account::class, 'forgot']);
        $r->any('/reset', [Account::class, 'reset']);
        $r->any('/logout', [Account::class, 'logout']);
        $r->any('/profile', [Account::class, 'profile']);
        $r->any('/profile/2fa', [Account::class, 'twofaSetup']);

        $r->get('/content', [ContentController::class, 'index']);
        $r->post('/content/bulk', [ContentController::class, 'bulk']);
        $r->get('/content/new', [ContentController::class, 'create']);
        $r->get('/content/{id}', [ContentController::class, 'edit']);
        $r->get('/content/{id}/preview', [ContentController::class, 'preview']);
        $r->post('/content/preview', [ContentController::class, 'previewUnsaved']);
        $r->get('/editor/frame', [ContentController::class, 'frame']);
        $r->post('/api/content/save', [ContentController::class, 'save']);
        $r->post('/api/content/render', [ContentController::class, 'render']);
        $r->post('/api/content/analyze', [ContentController::class, 'analyze']);
        $r->get('/api/content/search', [ContentController::class, 'search']);
        $r->post('/api/content/{id}/revision', [ContentController::class, 'restoreRevision']);

        $r->get('/media', [MediaController::class, 'index']);
        $r->get('/api/media', [MediaController::class, 'list']);
        $r->post('/api/media/upload', [MediaController::class, 'upload']);
        $r->post('/api/media/import', [MediaController::class, 'importUrl']);
        $r->post('/api/media/{id}', [MediaController::class, 'update']);
        $r->post('/api/media/{id}/delete', [MediaController::class, 'delete']);

        $r->get('/comments', [Community::class, 'comments']);
        $r->post('/comments/action', [Community::class, 'commentAction']);
        $r->get('/messages', [Community::class, 'messages']);
        $r->post('/messages/action', [Community::class, 'messageAction']);
        $r->get('/messages/export', [Community::class, 'exportMessages']);

        $r->get('/menus', [Structure::class, 'menus']);
        $r->post('/menus', [Structure::class, 'menuAction']);
        $r->post('/api/menus/{id}', [Structure::class, 'saveMenu']);
        $r->get('/terms', [Structure::class, 'terms']);
        $r->post('/terms', [Structure::class, 'termAction']);

        $r->get('/users', [Users::class, 'index']);
        $r->any('/users/edit', [Users::class, 'edit']);
        $r->post('/users/action', [Users::class, 'action']);

        $r->any('/settings', [SettingsController::class, 'general']);
        $r->any('/settings/{section}', [SettingsController::class, 'section']);
        $r->any('/seo', [SettingsController::class, 'seo']);
        $r->any('/seo/redirects', [SettingsController::class, 'redirects']);

        $r->get('/design', [DesignController::class, 'index']);
        $r->get('/design/preview', [DesignController::class, 'preview']);
        $r->post('/api/design/save', [DesignController::class, 'save']);
        $r->post('/api/design/audit', [DesignController::class, 'audit']);
        $r->post('/api/design/direction', [DesignController::class, 'direction']);
        $r->post('/api/design/critique', [DesignController::class, 'critique']);
        $r->post('/api/design/font', [DesignController::class, 'installFont']);

        $r->any('/ai', [AiController::class, 'index']);
        $r->post('/api/ai/test', [AiController::class, 'test']);
        $r->post('/api/ai/run', [AiController::class, 'run']);
        $r->post('/api/ai/article', [AiController::class, 'article']);

        $r->get('/import', [ImportController::class, 'index']);
        $r->post('/api/import/start', [ImportController::class, 'start']);
        $r->post('/api/import/step', [ImportController::class, 'step']);
        $r->post('/api/import/cancel', [ImportController::class, 'cancel']);

        $r->get('/backups', [Tools::class, 'backups']);
        $r->post('/backups', [Tools::class, 'backupAction']);
        $r->get('/backups/download', [Tools::class, 'download']);
        $r->get('/activity', [Tools::class, 'activity']);
        $r->get('/system', [Tools::class, 'system']);
        $r->post('/api/system/probe', [Tools::class, 'probe']);

        $r->get('/connect', [Connect::class, 'index']);
        $r->post('/connect', [Connect::class, 'action']);
        return $r;
    }

    public static function handle(string $path): void
    {
        Http::sendHeaders(Http::securityHeaders('admin'));
        header('X-Robots-Tag: noindex, nofollow');
        $path = '/' . trim($path, '/');
        $path = $path === '/' ? '/' : $path;

        if (!in_array($path, self::PUBLIC, true)) {
            if (Auth::pending2fa()) {
                redirect(admin_url('2fa'));
            }
            if (!Auth::check()) {
                if (Http::isAjax() || Http::method() !== 'GET') {
                    json_response(['error' => __('Votre session a expiré. Reconnectez-vous.')], 401);
                }
                $back = Config::basePath() . Config::adminPath() . ($path === '/' ? '' : $path) . (Http::query() !== '' ? '?' . Http::query() : '');
                redirect(admin_url('login') . '?redirect=' . rawurlencode($back));
            }
            // Langue de l'administration choisie dans le profil (vide = langue du site).
            $lang = (string) (Auth::user()['lang'] ?? '');
            if ($lang !== '' && $lang !== I18n::lang() && isset(I18n::LANGUAGES[$lang])) {
                I18n::load($lang);
            }
            if (Settings::get('security.require_2fa', false) && Auth::user()['role'] === 'admin' && !(int) Auth::user()['totp_enabled']
                && !in_array($path, ['/profile/2fa', '/logout'], true)) {
                self::flash(__('La double authentification est obligatoire pour les administrateurs. Activez-la pour continuer.'), 'warn');
                redirect(admin_url('profile/2fa'));
            }
            if (Http::method() === 'POST' && !Auth::verifyCsrf()) {
                if (Http::isAjax()) {
                    json_response(['error' => __('Jeton de sécurité expiré : rechargez la page.')], 419);
                }
                self::flash(__('Jeton de sécurité expiré : merci de recommencer.'), 'bad');
                redirect((string) ($_SERVER['HTTP_REFERER'] ?? admin_url()));
            }
        }

        $match = self::routes()->match(Http::method(), $path);
        if ($match === null) {
            http_response_code(404);
            echo self::page('empty', ['heading' => __('Page introuvable'), 'text' => __('Cette page de l’administration n’existe pas.')], __('Introuvable'), '');
            return;
        }
        if ($match[0] === '405') {
            http_response_code(405);
            return;
        }
        $out = Router::call($match[0], $match[1]);
        if (is_string($out)) {
            echo $out;
        }
    }

    // ------------------------------------------------------------------
    // Rendu
    // ------------------------------------------------------------------

    /**
     * Page complète avec la barre latérale. $active = clé du menu,
     * $actions = HTML des boutons de la barre du haut.
     */
    public static function page(string $view, array $vars, string $title, string $active, string $actions = '', array $o = []): string
    {
        $content = View::admin($view, $vars);
        return View::admin('layout', [
            'content' => $content, 'title' => $title, 'active' => $active, 'actions' => $actions,
            'flash' => self::takeFlash(), 'narrow' => !empty($o['narrow']), 'scripts' => $o['scripts'] ?? [], 'bodyClass' => $o['body'] ?? '',
        ]);
    }

    /** Page sans barre latérale (connexion, 2FA). */
    public static function bare(string $view, array $vars): string
    {
        return View::admin($view, $vars + ['flash' => self::takeFlash()]);
    }

    public static function flash(string $message, string $type = 'good'): void
    {
        $value = base64_encode(json_enc(['m' => $message, 't' => $type]));
        setcookie('wl_flash', $value, ['expires' => time() + 120, 'path' => Config::basePath() . Config::adminPath(), 'httponly' => true, 'samesite' => 'Lax', 'secure' => Http::isHttps()]);
        $_COOKIE['wl_flash_next'] = $value;
    }

    public static function takeFlash(): ?array
    {
        $raw = (string) ($_COOKIE['wl_flash'] ?? '');
        if ($raw === '') {
            return null;
        }
        setcookie('wl_flash', '', ['expires' => time() - 3600, 'path' => Config::basePath() . Config::adminPath()]);
        $d = json_decode((string) base64_decode($raw, true), true);
        return is_array($d) && isset($d['m']) ? ['message' => (string) $d['m'], 'type' => in_array($d['t'] ?? '', ['good', 'warn', 'bad', 'info'], true) ? $d['t'] : 'good'] : null;
    }

    /** Vérifie une permission ; sinon 403. */
    public static function need(string $cap, ?array $content = null): void
    {
        if (!Auth::can($cap, $content)) {
            if (Http::isAjax()) {
                json_response(['error' => __('Vous n’avez pas l’autorisation d’effectuer cette action.')], 403);
            }
            http_response_code(403);
            echo self::page('empty', ['heading' => __('Accès refusé'), 'text' => __('Votre rôle ne permet pas d’ouvrir cette page. Demandez à un administrateur.')], __('Accès refusé'), '');
            exit;
        }
    }

    /** Lien de retour sûr (chemin local uniquement). */
    public static function safeRedirect(?string $to, string $fallback): string
    {
        $to = (string) $to;
        if ($to !== '' && str_starts_with($to, '/') && !str_starts_with($to, '//') && !str_contains($to, '\\')) {
            return $to;
        }
        return $fallback;
    }
}
