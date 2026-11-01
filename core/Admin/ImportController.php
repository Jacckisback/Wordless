<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Http;
use Wordless\Migrate\Importer;

/** Migration WordPress : assistant (fichier d'export ou connexion directe). */
final class ImportController
{
    public function index(): string
    {
        Kernel::need('import');
        return Kernel::page('import', [
            'maxUpload' => ini_get('upload_max_filesize'), 'welcome' => !empty($_GET['welcome']),
            'available' => class_exists(Importer::class),
        ], __('Migration depuis WordPress'), 'import', '', ['scripts' => ['import.js']]);
    }

    public function start(): never
    {
        Kernel::need('import');
        @set_time_limit(120);
        $options = [
            'media' => !empty($_POST['media']), 'comments' => !empty($_POST['comments']), 'users' => !empty($_POST['users']),
            'menus' => !empty($_POST['menus']), 'settings' => !empty($_POST['settings']), 'replace_starter' => !empty($_POST['replace_starter']),
            'seo_fallback' => !empty($_POST['seo_fallback']), 'other_types' => ($_POST['other_types'] ?? 'pages') === 'skip' ? 'skip' : 'pages',
        ];
        try {
            if (($_POST['import_method'] ?? '') === 'file') {
                $f = $_FILES['wxr'] ?? null;
                if (!$f || ($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $f['tmp_name'])) {
                    throw new \RuntimeException(__('Fichier non reçu (taille maximale du serveur : {m}).', ['m' => ini_get('upload_max_filesize')]));
                }
                $head = (string) file_get_contents((string) $f['tmp_name'], false, null, 0, 4000);
                if (!str_contains($head, '<rss') || !str_contains($head, 'wordpress.org/export')) {
                    throw new \RuntimeException(__('Ce fichier n’est pas un export WordPress (Outils > Exporter > Tout le contenu).'));
                }
                $dir = WL_DATA . '/import';
                if (!is_dir($dir)) {
                    mkdir($dir, 0775, true);
                }
                $dest = $dir . '/wxr-' . bin2hex(random_bytes(6)) . '.xml';
                move_uploaded_file((string) $f['tmp_name'], $dest);
                try {
                    $job = Importer::create(['type' => 'wxr', 'file' => $dest], $options);
                } finally {
                    @unlink($dest); // le fichier n'est lu qu'à la création de la tâche
                }
            } else {
                $url = trim((string) ($_POST['url'] ?? ''));
                if (!preg_match('#^https?://#i', $url)) {
                    $url = 'https://' . ltrim($url, '/');
                }
                $job = Importer::create(['type' => 'rest', 'url' => rtrim($url, '/'), 'user' => trim((string) ($_POST['user'] ?? '')), 'password' => trim((string) ($_POST['password'] ?? ''))], $options);
            }
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        json_response(['ok' => true, 'job' => $job]);
    }

    public function step(): never
    {
        Kernel::need('import');
        @set_time_limit(120);
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) (Http::json()['id'] ?? '')) ?? '';
        try {
            $job = Importer::step($id, 8);
        } catch (\Throwable $e) {
            \Wordless\App::logError($e);
            json_response(['error' => $e->getMessage()], 500);
        }
        json_response(['ok' => true, 'job' => $job]);
    }
}
