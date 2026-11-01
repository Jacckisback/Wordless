<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Activity;
use Wordless\Backup;
use Wordless\Cache;
use Wordless\Config;
use Wordless\Db;
use Wordless\Http;
use Wordless\Media;
use Wordless\Settings;

/** Sauvegardes, journal d'activité, santé du système. */
final class Tools
{
    public function backups(): string
    {
        Kernel::need('manage_settings');
        $actions = '<form method="post" action="' . e(admin_url('backups')) . '">' . csrf_field() . '<input type="hidden" name="action" value="db"><button class="btn btn-primary" type="submit">' . icon('archive') . e(__('Sauvegarder maintenant')) . '</button></form>';
        return Kernel::page('backups', ['list' => Backup::list(), 'auto' => (bool) Settings::get('backup.auto', true), 'last' => (int) Settings::get('backup.last', 0), 'zip' => class_exists('ZipArchive')],
            __('Sauvegardes'), 'backups', $actions);
    }

    public function backupAction(): string
    {
        Kernel::need('manage_settings');
        $action = (string) ($_POST['action'] ?? '');
        try {
            switch ($action) {
                case 'db':
                    Kernel::flash(__('Sauvegarde créée : {n}', ['n' => Backup::createDb()]));
                    break;
                case 'full':
                    Kernel::flash(__('Sauvegarde complète créée : {n}', ['n' => Backup::createFull()]));
                    break;
                case 'delete':
                    Backup::delete((string) ($_POST['name'] ?? ''));
                    Kernel::flash(__('Sauvegarde supprimée.'));
                    break;
                case 'restore':
                    Backup::restoreDb((string) ($_POST['name'] ?? ''));
                    Activity::log('restore', 'backup', null, (string) ($_POST['name'] ?? ''));
                    Kernel::flash(__('Base restaurée. Une sauvegarde de l’état précédent a été créée.'), 'warn');
                    break;
                case 'auto':
                    Settings::set('backup.auto', !empty($_POST['auto']));
                    Kernel::flash(__('Réglage enregistré.'));
                    break;
            }
        } catch (\Throwable $e) {
            Kernel::flash($e->getMessage(), 'bad');
        }
        redirect(admin_url('backups'));
    }

    public function download(): never
    {
        Kernel::need('manage_settings');
        $file = Backup::path((string) ($_GET['name'] ?? ''));
        if (!$file) {
            http_response_code(404);
            exit;
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    public function activity(): string
    {
        Kernel::need('manage_settings');
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $filter = (string) ($_GET['action'] ?? '');
        $where = $filter !== '' ? 'WHERE a.action = ?' : '';
        $params = $filter !== '' ? [$filter] : [];
        $rows = Db::all("SELECT a.*, u.name AS user_name FROM activity a LEFT JOIN users u ON u.id = a.user_id $where ORDER BY a.id DESC LIMIT 50 OFFSET " . (($page - 1) * 50), $params);
        $total = (int) Db::val("SELECT COUNT(*) FROM activity a $where", $params);
        return Kernel::page('activity', ['rows' => $rows, 'page' => $page, 'pages' => (int) ceil($total / 50), 'filter' => $filter], __('Journal d’activité'), 'activity');
    }

    public function system(): string
    {
        Kernel::need('manage_settings');
        $db = Config::dbPath();
        $checks = [
            [__('Version de PHP'), PHP_VERSION, PHP_VERSION_ID >= 80300 ? 'good' : (PHP_VERSION_ID >= 80200 ? 'warn' : 'bad')],
            [__('SQLite'), (string) Db::val('SELECT sqlite_version()'), 'good'],
            [__('Recherche plein texte (FTS5)'), Db::hasFts() ? __('active') : __('indisponible (recherche simple)'), Db::hasFts() ? 'good' : 'warn'],
            [__('Images WebP (GD)'), function_exists('imagewebp') ? __('active') : __('indisponible'), function_exists('imagewebp') ? 'good' : 'warn'],
            [__('cURL (IA, migration)'), extension_loaded('curl') ? __('actif') : __('absent'), extension_loaded('curl') ? 'good' : 'warn'],
            [__('Chiffrement (sodium)'), function_exists('sodium_crypto_secretbox') ? __('actif') : __('AES-GCM de secours'), 'good'],
            [__('Zip (sauvegardes complètes)'), class_exists('ZipArchive') ? __('actif') : __('absent'), class_exists('ZipArchive') ? 'good' : 'warn'],
            [__('OPcache'), function_exists('opcache_get_status') && @opcache_get_status(false) ? __('actif') : __('inactif'), function_exists('opcache_get_status') && @opcache_get_status(false) ? 'good' : 'warn'],
            [__('Limite d’envoi de fichiers'), (string) ini_get('upload_max_filesize'), 'good'],
            [__('Dossier data/ protégé du web'), Settings::get('security.data_exposed', null) === true ? __('NON — voir ci-dessous') : (Settings::get('security.data_exposed', null) === false ? __('oui') : __('vérification au prochain affichage du tableau de bord')), Settings::get('security.data_exposed', null) === true ? 'bad' : 'good'],
            [__('Mode débogage'), Config::debug() ? __('activé') : __('désactivé'), Config::debug() ? 'warn' : 'good'],
        ];
        $sizes = ['db' => is_file($db) ? (int) filesize($db) : 0, 'media' => Media::diskUsage(), 'cache' => Cache::stats()];
        $code = self::codeSize();
        return Kernel::page('system', compact('checks', 'sizes', 'code'), __('Santé du système'), 'settings');
    }

    /** Poids du code de Wordless (fichiers livrés, hors médias et données). */
    public static function codeSize(): array
    {
        $bytes = 0;
        $files = 0;
        foreach (['core', 'admin', 'themes', 'index.php', 'router.php', '.htaccess', 'bin'] as $p) {
            $path = WL_ROOT . '/' . $p;
            if (is_file($path)) {
                $bytes += (int) filesize($path);
                $files++;
                continue;
            }
            if (!is_dir($path)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $bytes += $f->getSize();
                $files++;
            }
        }
        return ['bytes' => $bytes, 'files' => $files];
    }

    /** Résultat du test d'accès au dossier data/ (fait par le navigateur). */
    public function probe(): never
    {
        Kernel::need('edit_content');
        $exposed = (bool) (Http::json()['exposed'] ?? false);
        if (Settings::get('security.data_exposed', null) !== $exposed) {
            Settings::set('security.data_exposed', $exposed);
        }
        json_response(['ok' => true]);
    }
}
