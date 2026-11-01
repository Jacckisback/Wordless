<?php
declare(strict_types=1);

namespace Wordless;

/** Journal d'activité (qui a fait quoi, quand) pour la sécurité et le tableau de bord. */
final class Activity
{
    public static function log(string $action, string $object = '', ?int $objectId = null, string $label = '', ?int $userId = null): void
    {
        try {
            Db::insert('activity', [
                'user_id' => $userId ?? Auth::id(),
                'action' => $action,
                'object' => $object,
                'object_id' => $objectId,
                'label' => mb_substr($label, 0, 200),
                'ip' => function_exists('ip_hash') ? ip_hash() : '',
                'created_at' => time(),
            ]);
        } catch (\Throwable) {
            // Le journal ne doit jamais bloquer une action.
        }
    }

    public static function recent(int $limit = 20): array
    {
        return Db::all('SELECT a.*, u.name AS user_name FROM activity a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT ?', [$limit]);
    }

    public static function describe(array $row): string
    {
        $label = $row['label'] !== '' ? '« ' . $row['label'] . ' »' : '';
        return match ($row['action']) {
            'login' => __('s’est connecté'),
            'login_failed' => __('Échec de connexion {l}', ['l' => $label]),
            'publish' => __('a publié {l}', ['l' => $label]),
            'update' => __('a modifié {l}', ['l' => $label]),
            'create' => __('a créé {l}', ['l' => $label]),
            'trash' => __('a mis à la corbeille {l}', ['l' => $label]),
            'delete' => __('a supprimé {l}', ['l' => $label]),
            'restore' => __('a restauré {l}', ['l' => $label]),
            'settings' => __('a modifié les réglages {l}', ['l' => $label]),
            'upload' => __('a ajouté le média {l}', ['l' => $label]),
            'import' => __('a importé {l}', ['l' => $label]),
            'design' => __('a changé le design {l}', ['l' => $label]),
            'backup' => __('a créé une sauvegarde {l}', ['l' => $label]),
            'user' => __('a modifié l’utilisateur {l}', ['l' => $label]),
            'mcp' => __('via l’IA connectée (MCP) : {l}', ['l' => $label]),
            '2fa_on' => __('a activé la double authentification'),
            '2fa_off' => __('a désactivé la double authentification'),
            default => $row['action'] . ' ' . $label,
        };
    }

    public static function purge(int $days = 180): void
    {
        Db::run('DELETE FROM activity WHERE created_at < ?', [time() - $days * 86400]);
    }
}
