<?php
declare(strict_types=1);

namespace Wordless\Mcp;

use Wordless\Auth;
use Wordless\Config;
use Wordless\Db;

/** Commandes du connecteur IA pour bin/wordless. */
final class Cli
{
    public static function commands(): array
    {
        return [
            'mcp:key' => ['mcp:key <email> [--label=…] — ' . __('crée une clé API MCP'), [self::class, 'key']],
        ];
    }

    /** php bin/wordless mcp:key <email> [--label=…] [--quiet] */
    public static function key(array $opts, array $args): int
    {
        $email = mb_strtolower(trim((string) ($args[0] ?? '')));
        if ($email === '') {
            fwrite(STDERR, __('Usage : php bin/wordless mcp:key <email> [--label=…] [--quiet]') . PHP_EOL);
            return 1;
        }
        $user = Db::one("SELECT * FROM users WHERE email = ? AND status = 'active'", [$email]);
        if (!$user) {
            fwrite(STDERR, __('Utilisateur introuvable ou désactivé : {e}', ['e' => $email]) . PHP_EOL);
            return 1;
        }
        $label = is_string($opts['label'] ?? null) && trim($opts['label']) !== '' ? (string) $opts['label'] : __('Ligne de commande');
        $key = ApiKeys::create((int) $user['id'], $label);
        if (isset($opts['quiet'])) {
            echo $key . PHP_EOL;
            return 0;
        }
        $endpoint = Config::baseUrl() . '/mcp';
        $role = __((string) (Auth::ROLES[$user['role']]['label'] ?? $user['role']));
        echo __('Clé API créée pour {u} ({r}) : « {l} »', ['u' => $user['email'], 'r' => $role, 'l' => $label]) . PHP_EOL . PHP_EOL;
        echo '  ' . $key . PHP_EOL . PHP_EOL;
        echo __('Elle ne sera plus jamais affichée : copiez-la maintenant dans un gestionnaire de mots de passe.') . PHP_EOL;
        echo __('Elle donne à l’IA les droits de ce compte ; révoquez-la dans l’administration (Connecteur Claude / ChatGPT) en cas de fuite.') . PHP_EOL . PHP_EOL;
        echo __('Claude Code :') . PHP_EOL;
        echo '  claude mcp add --transport http wordless ' . $endpoint . ' --header "Authorization: Bearer ' . $key . '"' . PHP_EOL;
        return 0;
    }
}
