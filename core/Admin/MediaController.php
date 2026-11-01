<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Activity;
use Wordless\Db;
use Wordless\Http;
use Wordless\Media;

/** Médiathèque. */
final class MediaController
{
    public function index(): string
    {
        Kernel::need('upload');
        $count = (int) Db::val('SELECT COUNT(*) FROM media');
        $missingAlt = (int) Db::val("SELECT COUNT(*) FROM media WHERE mime LIKE 'image/%' AND alt = ''");
        $actions = '<label class="btn btn-primary">' . icon('upload') . e(__('Envoyer des fichiers')) . '<input id="media-file" type="file" multiple hidden accept="image/*,.pdf,.mp4,.webm,.mp3,.m4a,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.zip,.csv,.txt,.epub"></label>';
        return Kernel::page('media', [
            'count' => $count, 'missingAlt' => $missingAlt, 'usage' => Media::diskUsage(), 'maxUpload' => ini_get('upload_max_filesize'),
            'filterAlt' => !empty($_GET['missing_alt']),
        ], __('Médias'), 'media', $actions, ['scripts' => ['media.js']]);
    }

    public static function json(array $m): array
    {
        return [
            'id' => (int) $m['id'], 'url' => Media::url($m), 'thumb' => Media::isImage($m) ? Media::url($m, 480) : Media::url($m),
            'mime' => $m['mime'], 'alt' => $m['alt'], 'title' => $m['title'], 'caption' => $m['caption'], 'width' => $m['width'], 'height' => $m['height'],
            'size' => (int) $m['size'], 'size_label' => fmt_bytes((int) $m['size']), 'variants' => count(array_filter(array_keys($m['variants']), static fn ($k) => $k !== 'full')),
            'created' => fmt_date((int) $m['created_at']),
        ];
    }

    public function list(): never
    {
        Kernel::need('upload');
        if (!empty($_GET['id'])) {
            $m = Media::find((int) $_GET['id']);
            json_response(['items' => $m ? [self::json($m)] : [], 'more' => false]);
        }
        $limit = max(1, min(100, (int) ($_GET['limit'] ?? 60)));
        $items = Media::list(['q' => (string) ($_GET['q'] ?? ''), 'type' => (string) ($_GET['type'] ?? ''), 'missing_alt' => !empty($_GET['missing_alt']), 'limit' => $limit + 1, 'offset' => (int) ($_GET['offset'] ?? 0)]);
        $more = count($items) > $limit;
        json_response(['items' => array_map([self::class, 'json'], array_slice($items, 0, $limit)), 'more' => $more]);
    }

    public function upload(): never
    {
        Kernel::need('upload');
        if (empty($_FILES['file'])) {
            json_response(['error' => __('Aucun fichier reçu (il dépasse peut-être la limite du serveur : {m}).', ['m' => ini_get('post_max_size')])], 400);
        }
        try {
            $m = Media::upload($_FILES['file'], ['alt' => (string) ($_POST['alt'] ?? '')]);
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        json_response(['ok' => true, 'media' => self::json($m)]);
    }

    public function importUrl(): never
    {
        Kernel::need('upload');
        $url = trim((string) (Http::json()['url'] ?? ''));
        try {
            $m = Media::importUrl($url, ['alt' => (string) (Http::json()['alt'] ?? '')]);
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        json_response(['ok' => true, 'media' => self::json($m)]);
    }

    public function update(string $id): never
    {
        Kernel::need('upload');
        $m = Media::find((int) $id);
        if (!$m) {
            json_response(['error' => __('Fichier introuvable.')], 404);
        }
        $in = Http::json();
        Db::update('media', [
            'alt' => mb_substr(trim(strip_tags((string) ($in['alt'] ?? $m['alt']))), 0, 250),
            'title' => mb_substr(trim(strip_tags((string) ($in['title'] ?? $m['title']))), 0, 200),
            'caption' => mb_substr(trim(strip_tags((string) ($in['caption'] ?? $m['caption']))), 0, 500),
        ], ['id' => (int) $id]);
        \Wordless\Cache::purge();
        $row = Db::one('SELECT * FROM media WHERE id = ?', [(int) $id]);
        json_response(['ok' => true, 'media' => self::json(Media::decode($row))]);
    }

    public function delete(string $id): never
    {
        Kernel::need('upload');
        if (!\Wordless\Auth::can('delete_content')) {
            json_response(['error' => __('Vous n’avez pas l’autorisation de supprimer des fichiers.')], 403);
        }
        Media::delete((int) $id);
        \Wordless\Cache::purge();
        json_response(['ok' => true]);
    }
}
