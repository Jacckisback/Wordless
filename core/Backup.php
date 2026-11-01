<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Sauvegardes : base de données (instantané cohérent, quotidien automatique,
 * 14 conservés) et sauvegarde complète téléchargeable (base + médias + config).
 */
final class Backup
{
    public static function dir(): string
    {
        $d = WL_DATA . '/backups';
        if (!is_dir($d)) {
            mkdir($d, 0775, true);
        }
        return $d;
    }

    public static function createDb(string $label = 'manuel'): string
    {
        $name = 'wordless-' . gmdate('Ymd-His') . '-' . preg_replace('/[^a-z0-9]/', '', $label) . '.db';
        $file = self::dir() . '/' . $name;
        try {
            Db::pdo()->exec('VACUUM INTO ' . Db::pdo()->quote($file));
        } catch (\Throwable) {
            Db::pdo()->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            copy(Config::dbPath(), $file);
        }
        @chmod($file, 0640);
        Settings::set('backup.last', time());
        if ($label === 'manuel') {
            Activity::log('backup', 'backup', null, $name);
        }
        return $name;
    }

    public static function createFull(): string
    {
        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException(__('L’extension zip n’est pas disponible sur cet hébergement.'));
        }
        @set_time_limit(300);
        $db = self::createDb('complet');
        $name = 'wordless-complet-' . gmdate('Ymd-His') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open(self::dir() . '/' . $name, \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException(__('Impossible de créer l’archive.'));
        }
        $zip->addFile(self::dir() . '/' . $db, 'data/' . basename(Config::dbPath()));
        $zip->addFile(Config::file(), 'data/config.php');
        if (is_dir(WL_MEDIA)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(WL_MEDIA, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $zip->addFile($f->getPathname(), 'media/' . substr($f->getPathname(), strlen(WL_MEDIA) + 1));
            }
        }
        $zip->addFromString('LISEZMOI.txt', "Sauvegarde Wordless du " . gmdate('c') . "\nRestauration : copiez data/ et media/ dans une installation Wordless fraîche (avant l'installation).\nATTENTION : data/config.php contient le secret du site (clés API chiffrées).\n");
        $zip->close();
        @unlink(self::dir() . '/' . $db);
        Activity::log('backup', 'backup', null, $name);
        return $name;
    }

    public static function list(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/wordless-*.{db,zip}', GLOB_BRACE) ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'time' => (int) filemtime($f)];
        }
        usort($out, static fn ($a, $b) => $b['time'] <=> $a['time']);
        return $out;
    }

    public static function path(string $name): ?string
    {
        $name = basename($name);
        $file = self::dir() . '/' . $name;
        return preg_match('/^wordless-[\w-]+\.(db|zip)$/', $name) && is_file($file) ? $file : null;
    }

    public static function delete(string $name): void
    {
        $f = self::path($name);
        if ($f) {
            @unlink($f);
        }
    }

    /** Sauvegarde automatique quotidienne, 14 conservées. */
    public static function auto(): void
    {
        if (!Settings::get('backup.auto', true) || time() - (int) Settings::get('backup.last', 0) < 86400) {
            return;
        }
        self::createDb('auto');
        $autos = array_values(array_filter(self::list(), static fn ($b) => str_ends_with($b['name'], '-auto.db')));
        foreach (array_slice($autos, 14) as $old) {
            self::delete($old['name']);
        }
    }

    /** Restaure une base sauvegardée (après une sauvegarde de sécurité de l'état actuel). */
    public static function restoreDb(string $name): void
    {
        $file = self::path($name);
        if (!$file || !str_ends_with($file, '.db')) {
            throw new \RuntimeException(__('Sauvegarde introuvable.'));
        }
        $check = new \PDO('sqlite:' . $file);
        if ($check->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new \RuntimeException(__('Ce fichier de sauvegarde est endommagé.'));
        }
        $check = null;
        self::createDb('avantrestauration');
        Db::close();
        copy($file, Config::dbPath() . '.restore');
        @unlink(Config::dbPath() . '-wal');
        @unlink(Config::dbPath() . '-shm');
        rename(Config::dbPath() . '.restore', Config::dbPath());
        Cache::purge();
    }
}
