<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Auth;
use Wordless\Config;
use Wordless\Settings;

/** Connecteur IA (MCP) : Claude.ai, ChatGPT, Claude Code, clés API, applications autorisées. */
final class Connect
{
    public function index(): string
    {
        Kernel::need('edit_content');
        $uid = (int) Auth::id();
        $keys = class_exists('Wordless\\Mcp\\ApiKeys') ? \Wordless\Mcp\ApiKeys::list($uid) : [];
        $clients = class_exists('Wordless\\Mcp\\OAuth') && method_exists('Wordless\\Mcp\\OAuth', 'clients') ? \Wordless\Mcp\OAuth::clients($uid) : [];
        $newKey = (string) ($_COOKIE['wl_newkey'] ?? '');
        if ($newKey !== '') {
            setcookie('wl_newkey', '', ['expires' => time() - 3600, 'path' => Config::basePath() . Config::adminPath()]);
        }
        $root = Config::basePath() === '';
        return Kernel::page('connect', [
            'mcpUrl' => Config::baseUrl() . '/mcp', 'enabled' => (bool) Settings::get('mcp.enabled', true), 'keys' => $keys, 'clients' => $clients,
            'newKey' => $newKey !== '' ? (string) \Wordless\Crypto::decrypt($newKey) : '', 'root' => $root, 'https' => str_starts_with(Config::baseUrl(), 'https://'),
        ], __('Connecteur Claude / ChatGPT'), 'connect');
    }

    public function action(): string
    {
        Kernel::need('edit_content');
        $uid = (int) Auth::id();
        $a = (string) ($_POST['action'] ?? '');
        try {
            if ($a === 'toggle' && Auth::can('manage_settings')) {
                Settings::set('mcp.enabled', !empty($_POST['enabled']));
                Kernel::flash(__('Réglage enregistré.'));
            } elseif ($a === 'key' && class_exists('Wordless\\Mcp\\ApiKeys')) {
                $label = mb_substr(trim(strip_tags((string) ($_POST['label'] ?? ''))), 0, 60) ?: 'Claude Code';
                $key = \Wordless\Mcp\ApiKeys::create($uid, $label);
                // Affichée une seule fois : transmise à la page suivante dans un cookie chiffré de courte durée.
                setcookie('wl_newkey', \Wordless\Crypto::encrypt($key), ['expires' => time() + 120, 'path' => Config::basePath() . Config::adminPath(), 'httponly' => true, 'samesite' => 'Strict', 'secure' => \Wordless\Http::isHttps()]);
                Kernel::flash(__('Clé créée. Copiez-la maintenant : elle ne sera plus affichée.'), 'warn');
            } elseif ($a === 'revoke_key' && class_exists('Wordless\\Mcp\\ApiKeys')) {
                \Wordless\Mcp\ApiKeys::revoke((string) ($_POST['id'] ?? ''), $uid);
                Kernel::flash(__('Clé révoquée.'));
            } elseif ($a === 'revoke_client' && class_exists('Wordless\\Mcp\\OAuth')) {
                \Wordless\Mcp\OAuth::revokeClient((string) ($_POST['id'] ?? ''), $uid);
                Kernel::flash(__('Accès révoqué.'));
            }
        } catch (\Throwable $e) {
            Kernel::flash($e->getMessage(), 'bad');
        }
        redirect(admin_url('connect'));
    }
}
