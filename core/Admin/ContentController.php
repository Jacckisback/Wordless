<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Auth;
use Wordless\Blocks;
use Wordless\Cache;
use Wordless\Config;
use Wordless\Content;
use Wordless\Db;
use Wordless\Front;
use Wordless\Http;
use Wordless\I18n;
use Wordless\Media;
use Wordless\Seo;
use Wordless\Settings;
use Wordless\Terms;
use Wordless\Theme;
use Wordless\View;

/** Liste des contenus et éditeur (page builder). */
final class ContentController
{
    public function index(): string
    {
        Kernel::need('edit_content');
        $type = ($_GET['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $status = in_array($_GET['status'] ?? '', ['published', 'draft', 'scheduled', 'private', 'trash'], true) ? $_GET['status'] : 'any';
        $q = trim((string) ($_GET['q'] ?? ''));
        $order = in_array($_GET['order'] ?? '', ['title', 'updated', 'seo', 'oldest_update'], true) ? $_GET['order'] : ($type === 'page' ? 'menu' : 'recent');
        $page = max(1, (int) ($_GET['p'] ?? 1));
        $per = 30;
        $query = ['type' => $type, 'status' => $status, 'search' => $q, 'order' => $order];
        if (($_GET['missing'] ?? '') === 'description') {
            $query['missing'] = 'description';
            $query['status'] = 'published';
        }
        if (!Auth::can('edit_others')) {
            $query['author'] = Auth::id();
        }
        if (!empty($_GET['term'])) {
            $query['term'] = (int) $_GET['term'];
        }
        $total = Content::count($query);
        $items = Content::query($query + ['limit' => $per, 'offset' => ($page - 1) * $per]);
        $counts = ['any' => 0];
        foreach (Db::all('SELECT status, COUNT(*) AS n FROM content WHERE type = ?' . (Auth::can('edit_others') ? '' : ' AND author_id = ' . (int) Auth::id()) . ' GROUP BY status', [$type]) as $r) {
            $counts[$r['status']] = (int) $r['n'];
            if ($r['status'] !== 'trash') {
                $counts['any'] += (int) $r['n'];
            }
        }
        if ($type === 'page' && $order === 'menu' && $status === 'any' && $q === '') {
            $items = self::hierarchy($items);
        }
        $authors = [];
        foreach (Db::all('SELECT id, name FROM users') as $u) {
            $authors[(int) $u['id']] = $u['name'];
        }
        $actions = '<a class="btn btn-primary" href="' . e(admin_url('content/new?type=' . $type)) . '">' . icon('plus') . e($type === 'page' ? __('Nouvelle page') : __('Nouvel article')) . '</a>';
        if ($type === 'post' && Auth::can('edit_others')) {
            $actions = '<a class="btn" href="' . e(admin_url('terms?taxonomy=category')) . '">' . icon('tag') . e(__('Catégories')) . '</a>' . $actions;
        }
        return Kernel::page('content-list', compact('type', 'status', 'q', 'items', 'counts', 'total', 'page', 'per', 'authors', 'order'),
            Content::typeLabel($type, true), $type === 'page' ? 'pages' : 'posts', $actions);
    }

    /** Pages enfants affichées sous leur parent. */
    private static function hierarchy(array $items): array
    {
        $byParent = [];
        foreach ($items as $i) {
            $byParent[(int) ($i['parent_id'] ?? 0)][] = $i;
        }
        $ids = array_column($items, 'id');
        $out = [];
        $walk = static function (int $parent, int $depth) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $i) {
                $i['depth'] = $depth;
                $out[] = $i;
                $walk((int) $i['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        foreach ($items as $i) {
            if ($i['parent_id'] && !in_array((int) $i['parent_id'], $ids, true)) {
                $i['depth'] = 0;
                $out[] = $i;
            }
        }
        return $out;
    }

    public function bulk(): string
    {
        Kernel::need('edit_content');
        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        $action = (string) ($_POST['action'] ?? '');
        if (!empty($_POST['restore_one'])) {
            $ids = [(int) $_POST['restore_one']];
            $action = 'restore';
        }
        $n = 0;
        foreach ($ids as $id) {
            $c = Content::find($id);
            if (!$c || !Auth::can('edit_content', $c)) {
                continue;
            }
            match ($action) {
                'publish' => Auth::can('publish', $c) ? Content::save(['status' => 'published'], $id) : null,
                'draft' => Content::save(['status' => 'draft'], $id),
                'trash' => Content::setStatus($id, 'trash'),
                'restore' => Content::restore($id),
                'delete' => $c['status'] === 'trash' && Auth::can('delete_content', $c) ? Content::delete($id) : null,
                'duplicate' => Content::duplicate($id),
                default => null,
            };
            $n++;
        }
        Kernel::flash(_n('{n} élément mis à jour.', '{n} éléments mis à jour.', $n));
        redirect(Kernel::safeRedirect($_POST['back'] ?? null, admin_url('content')));
    }

    // ------------------------------------------------------------------
    // Éditeur
    // ------------------------------------------------------------------

    public function create(): string
    {
        Kernel::need('edit_content');
        $type = ($_GET['type'] ?? 'post') === 'page' ? 'page' : 'post';
        $c = [
            'id' => null, 'type' => $type, 'status' => 'draft', 'title' => '', 'slug' => '', 'path' => null, 'parent_id' => null,
            'author_id' => Auth::id(), 'excerpt' => '', 'blocks' => $type === 'post' ? [Blocks::make('text')] : [], 'template' => '',
            'featured_id' => null, 'seo' => ['title' => '', 'description' => '', 'keyword' => '', 'canonical' => '', 'noindex' => false, 'og_image' => null, 'schema' => ''],
            'lang' => '', 'menu_order' => 0, 'comments' => $type === 'post' ? (int) Settings::get('comments.enabled', true) : 0, 'sticky' => 0,
            'published_at' => null, 'updated_at' => time(), 'created_at' => time(),
        ];
        return $this->editor($c);
    }

    public function edit(string $id): string
    {
        $c = Content::find((int) $id);
        if (!$c) {
            Kernel::flash(__('Ce contenu n’existe plus.'), 'bad');
            redirect(admin_url('content'));
        }
        Kernel::need('edit_content', $c);
        return $this->editor($c);
    }

    private function editor(array $c): string
    {
        $cats = Terms::all('category');
        $selected = $c['id'] ? Terms::forContent((int) $c['id']) : [];
        $data = [
            'content' => [
                'id' => $c['id'], 'type' => $c['type'], 'status' => $c['status'], 'title' => $c['title'], 'slug' => $c['slug'],
                'path' => $c['path'], 'url' => $c['id'] && $c['path'] ? Content::permalink($c) : null, 'parent_id' => $c['parent_id'],
                'author_id' => $c['author_id'], 'excerpt' => $c['excerpt'], 'blocks' => $c['blocks'], 'featured_id' => $c['featured_id'],
                'seo' => $c['seo'], 'comments' => (int) $c['comments'], 'sticky' => (int) $c['sticky'], 'menu_order' => (int) $c['menu_order'],
                'published_at' => $c['published_at'] ? (new \DateTimeImmutable('@' . $c['published_at']))->setTimezone(new \DateTimeZone((string) Settings::get('timezone', 'Europe/Paris')))->format('Y-m-d\TH:i') : '',
                'updated_at' => (int) $c['updated_at'], 'template' => $c['template'], 'lang' => $c['lang'],
                'categories' => array_values(array_map('intval', array_column(array_filter($selected, static fn ($t) => $t['taxonomy'] === 'category'), 'id'))),
                'tags' => array_values(array_column(array_filter($selected, static fn ($t) => $t['taxonomy'] === 'tag'), 'name')),
                'featured' => self::mediaJson($c['featured_id'] ? (int) $c['featured_id'] : null),
                'og_image' => self::mediaJson(!empty($c['seo']['og_image']) ? (int) $c['seo']['og_image'] : null),
                'is_home' => $c['id'] ? Content::isHome($c) : false,
            ],
            'defs' => Blocks::editorDefinitions(),
            'groups' => Blocks::groups(),
            'categories' => array_map(static fn ($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'parent_id' => $t['parent_id']], $cats),
            'tags' => array_column(Terms::all('tag'), 'name'),
            'pages' => $c['type'] === 'page' ? array_map(static fn ($p) => ['id' => (int) $p['id'], 'title' => $p['title']], Db::all("SELECT id, title FROM content WHERE type = 'page' AND status <> 'trash' AND id <> ? ORDER BY title", [(int) $c['id']])) : [],
            'revisions' => $c['id'] ? array_map(static fn ($r) => ['id' => (int) $r['id'], 'at' => human_time((int) $r['created_at']), 'by' => $r['user_name']], Content::revisions((int) $c['id'])) : [],
            'site' => [
                'name' => Settings::get('site.name'), 'url' => Config::baseUrl(), 'template' => Settings::get('seo.title_template', '{title} {sep} {site}'),
                'sep' => Settings::get('seo.separator', '–'), 'trailing' => (bool) Settings::get('trailing_slash', true), 'permalink' => Settings::get('permalink', '/{slug}'),
            ],
            'caps' => ['publish' => Auth::can('publish', $c['id'] ? $c : null), 'admin' => Auth::can('*'), 'ai' => Auth::can('use_ai'), 'delete' => Auth::can('delete_content', $c['id'] ? $c : null)],
            'ai' => \Wordless\Ai\Client::configured(),
            'templates' => Templates::list($c['type']),
            'lang' => I18n::lang(),
        ];
        return View::admin('editor', ['data' => $data, 'c' => $c]);
    }

    public static function mediaJson(?int $id): ?array
    {
        $m = Media::find($id);
        if (!$m) {
            return null;
        }
        return ['id' => (int) $m['id'], 'url' => Media::url($m), 'thumb' => Media::url($m, 480), 'alt' => $m['alt'], 'title' => $m['title'], 'width' => $m['width'], 'height' => $m['height'], 'mime' => $m['mime']];
    }

    /** Toile de l'éditeur : le vrai thème, avec des zones éditables. */
    public function frame(): string
    {
        Kernel::need('edit_content');
        header('Content-Type: text/html; charset=utf-8');
        $head = '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Aperçu</title>'
            . '<style>' . Theme::inlineCss() . '</style>'
            . '<link rel="stylesheet" href="' . e(asset_url('admin/assets/editor-frame.css')) . '">'
            . '<script src="' . e(asset_url('admin/assets/editor-frame.js')) . '" defer></script>';
        $body = '<article class="entry" id="wl-entry"><header class="entry-header" id="wl-title-area"><h1 id="wl-title" data-wl-title></h1></header>'
            . '<div class="entry-content" id="wl-canvas" aria-live="polite"></div></article>';
        return View::render(Theme::template('layout'), ['head' => $head, 'body' => $body, 'template' => 'editor', 'content' => null]);
    }

    // ------------------------------------------------------------------
    // API JSON
    // ------------------------------------------------------------------

    /** État envoyé par l'éditeur => champs de Content::save. */
    private static function input(array $in): array
    {
        $tz = new \DateTimeZone((string) Settings::get('timezone', 'Europe/Paris'));
        $published = null;
        if (!empty($in['published_at'])) {
            try {
                $published = (new \DateTimeImmutable((string) $in['published_at'], $tz))->getTimestamp();
            } catch (\Throwable) {
                $published = null;
            }
        }
        $out = [
            'type' => ($in['type'] ?? 'post') === 'page' ? 'page' : 'post',
            'title' => (string) ($in['title'] ?? ''),
            'slug' => (string) ($in['slug'] ?? ''),
            'excerpt' => (string) ($in['excerpt'] ?? ''),
            'blocks' => is_array($in['blocks'] ?? null) ? $in['blocks'] : [],
            'seo' => is_array($in['seo'] ?? null) ? $in['seo'] : [],
            'featured_id' => $in['featured_id'] ?? null,
            'parent_id' => $in['parent_id'] ?? null,
            'comments' => !empty($in['comments']) ? 1 : 0,
            'sticky' => !empty($in['sticky']) ? 1 : 0,
            'menu_order' => (int) ($in['menu_order'] ?? 0),
            'template' => preg_replace('/[^a-z0-9_-]/', '', (string) ($in['template'] ?? '')) ?? '',
            'lang' => (string) ($in['lang'] ?? ''),
            'categories' => array_map('intval', (array) ($in['categories'] ?? [])),
            'tags' => array_values(array_filter(array_map(static fn ($t) => trim((string) $t), (array) ($in['tags'] ?? [])))),
        ];
        if ($published !== null) {
            $out['published_at'] = $published;
        }
        return $out;
    }

    public function save(): never
    {
        $in = Http::json();
        $id = !empty($in['id']) ? (int) $in['id'] : null;
        $old = $id ? Content::find($id) : null;
        if ($id && !$old) {
            json_response(['error' => __('Ce contenu n’existe plus.')], 404);
        }
        Kernel::need('edit_content', $old);
        $data = self::input($in);
        $status = (string) ($in['status'] ?? ($old['status'] ?? 'draft'));
        if (in_array($status, ['published', 'scheduled'], true) && !Auth::can('publish', $old)) {
            json_response(['error' => __('Votre rôle ne permet pas de publier. Enregistrez en brouillon et demandez une relecture.')], 403);
        }
        $data['status'] = in_array($status, Content::STATUSES, true) && $status !== 'trash' ? $status : 'draft';
        if (!$old) {
            $data['author_id'] = Auth::id();
        }
        try {
            $newId = Content::save($data, $id, ['revision' => true]);
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        $c = Content::find($newId);
        json_response([
            'ok' => true, 'id' => $newId, 'status' => $c['status'], 'slug' => $c['slug'], 'path' => $c['path'],
            'url' => Content::permalink($c), 'updated_at' => (int) $c['updated_at'], 'blocks' => $c['blocks'],
            'analysis' => Seo::analyze($c), 'status_label' => Content::statusLabel($c['status']),
            'published_at_label' => $c['published_at'] ? fmt_date((int) $c['published_at'], 'datetime') : '',
        ]);
    }

    public function render(): never
    {
        Kernel::need('edit_content');
        $in = Http::json();
        $blocks = Blocks::sanitize((array) ($in['blocks'] ?? []), Auth::can('*'));
        $content = ['id' => (int) ($in['content']['id'] ?? 0), 'type' => ($in['content']['type'] ?? 'post') === 'page' ? 'page' : 'post', 'title' => (string) ($in['content']['title'] ?? '')];
        $level = $content['type'] === 'post' ? 2 : null;
        if (!empty($in['only'])) {
            $index = (int) ($in['index'] ?? 1);
            $ctx = ['edit' => true, 'content' => $content, 'index' => $index, 'headings' => Blocks::headings((array) ($in['all'] ?? $blocks))];
            if ($level !== null) {
                $ctx['heading_level'] = $level;
            }
            json_response(['html' => Blocks::renderOne($blocks[0] ?? [], $ctx), 'block' => $blocks[0] ?? null]);
        }
        $ctx = ['edit' => true, 'content' => $content];
        if ($level !== null) {
            $ctx['heading_level'] = $level;
        }
        json_response(['html' => Blocks::render($blocks, $ctx), 'blocks' => $blocks, 'script' => Blocks::$needsScript]);
    }

    public function analyze(): never
    {
        Kernel::need('edit_content');
        $in = Http::json();
        $data = self::input($in);
        $c = [
            'id' => (int) ($in['id'] ?? 0), 'type' => $data['type'], 'title' => $data['title'], 'slug' => slugify($data['slug'] !== '' ? $data['slug'] : $data['title']) ?: 'page',
            'blocks' => Blocks::sanitize($data['blocks'], Auth::can('*')), 'seo' => $data['seo'] + ['title' => '', 'description' => '', 'keyword' => '', 'canonical' => '', 'noindex' => false],
            'excerpt' => $data['excerpt'], 'featured_id' => $data['featured_id'], 'author_id' => (int) ($in['author_id'] ?? Auth::id()), 'updated_at' => time(), 'status' => 'draft',
        ];
        $home = !empty($in['is_home']);
        $title = trim((string) ($c['seo']['title'] ?? '')) !== '' ? (string) $c['seo']['title'] : Seo::applyTemplate($c['title']);
        $desc = trim((string) ($c['seo']['description'] ?? '')) !== '' ? (string) $c['seo']['description'] : Content::summary($c, 158);
        $path = $home ? '/' : (($in['path'] ?? null) ?: Content::buildPath($c + ['parent_id' => $data['parent_id'], 'published_at' => null], $data['categories'] ?: null));
        json_response(Seo::analyze($c) + ['preview' => ['title' => $title, 'description' => $desc, 'url' => $home ? Config::baseUrl() . '/' : url((string) $path)]]);
    }

    public function search(): never
    {
        Kernel::need('edit_content');
        $q = trim((string) ($_GET['q'] ?? ''));
        $rows = $q === '' ? [] : Db::all("SELECT id, type, title, path, status FROM content WHERE status <> 'trash' AND title LIKE ? ORDER BY (status = 'published') DESC, updated_at DESC LIMIT 10", ['%' . $q . '%']);
        json_response(['items' => array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'title' => $r['title'], 'type' => $r['type'], 'type_label' => Content::typeLabel($r['type']),
            'path' => link_path((string) $r['path']), 'status' => $r['status'],
        ], $rows)]);
    }

    public function restoreRevision(string $id): never
    {
        $c = Content::find((int) $id);
        Kernel::need('edit_content', $c);
        try {
            Content::restoreRevision((int) $id, (int) (Http::json()['revision'] ?? 0));
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        json_response(['ok' => true]);
    }

    /** Aperçu du contenu enregistré (même en brouillon). */
    public function preview(string $id): string
    {
        $c = Content::find((int) $id);
        if (!$c) {
            redirect(admin_url('content'));
        }
        Kernel::need('edit_content', $c);
        ob_start();
        Front::single($c, true);
        return (string) ob_get_clean();
    }

    /** Aperçu de l'état non enregistré (formulaire envoyé dans un nouvel onglet). */
    public function previewUnsaved(): string
    {
        Kernel::need('edit_content');
        $in = json_dec((string) ($_POST['state'] ?? ''), []);
        $data = self::input($in);
        $c = [
            'id' => (int) ($in['id'] ?? 0), 'type' => $data['type'], 'status' => 'draft', 'title' => $data['title'], 'slug' => slugify($data['title']) ?: 'apercu',
            'path' => '/apercu', 'parent_id' => null, 'author_id' => Auth::id(), 'excerpt' => $data['excerpt'], 'blocks' => Blocks::sanitize($data['blocks'], Auth::can('*')),
            'template' => '', 'featured_id' => $data['featured_id'], 'seo' => $data['seo'] + ['title' => '', 'description' => '', 'keyword' => '', 'canonical' => '', 'noindex' => true, 'og_image' => null, 'schema' => ''],
            'lang' => '', 'tgroup' => null, 'menu_order' => 0, 'comments' => 0, 'sticky' => 0, 'word_count' => 0, 'published_at' => time(), 'created_at' => time(), 'updated_at' => time(),
        ];
        ob_start();
        Front::single($c, true);
        return (string) ob_get_clean();
    }
}
