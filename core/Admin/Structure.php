<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Activity;
use Wordless\Cache;
use Wordless\Db;
use Wordless\Http;
use Wordless\Settings;
use Wordless\Terms;

/** Menus de navigation, catégories et étiquettes. */
final class Structure
{
    public function menus(): string
    {
        Kernel::need('manage_menus');
        $menus = Db::all('SELECT * FROM menus ORDER BY id');
        $current = (int) ($_GET['id'] ?? ($menus[0]['id'] ?? 0));
        $menu = $current ? Db::one('SELECT * FROM menus WHERE id = ?', [$current]) : null;
        $pages = Db::all("SELECT id, title, type FROM content WHERE status = 'published' ORDER BY type DESC, title");
        $cats = Terms::all('category');
        return Kernel::page('menus', [
            'menus' => $menus, 'menu' => $menu, 'items' => $menu ? json_dec($menu['items'], []) : [], 'pages' => $pages, 'cats' => $cats,
            'locations' => (array) Settings::get('menus.locations', []),
        ], __('Menus'), 'menus', '', ['scripts' => ['menus.js']]);
    }

    public function menuAction(): string
    {
        Kernel::need('manage_menus');
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create') {
            $name = trim(strip_tags((string) ($_POST['name'] ?? ''))) ?: __('Nouveau menu');
            $id = Db::insert('menus', ['name' => mb_substr($name, 0, 80), 'items' => '[]']);
            redirect(admin_url('menus?id=' . $id));
        }
        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            Db::delete('menus', ['id' => $id]);
            $loc = (array) Settings::get('menus.locations', []);
            foreach ($loc as $k => $v) {
                if ((int) $v === $id) {
                    $loc[$k] = null;
                }
            }
            Settings::set('menus.locations', $loc);
            Cache::purge();
            Kernel::flash(__('Menu supprimé.'));
        }
        redirect(admin_url('menus'));
    }

    private static function cleanItems(array $items, int $depth = 0): array
    {
        $out = [];
        foreach (array_slice($items, 0, 60) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $type = in_array($it['type'] ?? '', ['content', 'term', 'url'], true) ? $it['type'] : 'url';
            $item = ['label' => mb_substr(trim(strip_tags((string) ($it['label'] ?? ''))), 0, 80), 'type' => $type, 'children' => []];
            if ($type === 'url') {
                $item['url'] = \Wordless\Html::safeUrl(trim((string) ($it['url'] ?? '')));
            } else {
                $item['id'] = (int) ($it['id'] ?? 0);
            }
            if ($item['label'] === '') {
                continue;
            }
            if ($depth < 2 && !empty($it['children']) && is_array($it['children'])) {
                $item['children'] = self::cleanItems($it['children'], $depth + 1);
            }
            $out[] = $item;
        }
        return $out;
    }

    public function saveMenu(string $id): never
    {
        Kernel::need('manage_menus');
        $in = Http::json();
        $menu = Db::one('SELECT * FROM menus WHERE id = ?', [(int) $id]);
        if (!$menu) {
            json_response(['error' => __('Menu introuvable.')], 404);
        }
        Db::update('menus', ['name' => mb_substr(trim(strip_tags((string) ($in['name'] ?? $menu['name']))), 0, 80) ?: $menu['name'], 'items' => self::cleanItems((array) ($in['items'] ?? []))], ['id' => (int) $id]);
        $loc = (array) Settings::get('menus.locations', []);
        foreach (['primary', 'footer'] as $l) {
            if (in_array($l, (array) ($in['locations'] ?? []), true)) {
                $loc[$l] = (int) $id;
            } elseif ((int) ($loc[$l] ?? 0) === (int) $id) {
                $loc[$l] = null;
            }
        }
        Settings::set('menus.locations', $loc);
        Cache::purge();
        Activity::log('settings', 'menu', (int) $id, (string) ($in['name'] ?? ''));
        json_response(['ok' => true]);
    }

    public function terms(): string
    {
        Kernel::need('edit_others');
        $tax = ($_GET['taxonomy'] ?? 'category') === 'tag' ? 'tag' : 'category';
        $edit = !empty($_GET['edit']) ? Terms::find((int) $_GET['edit']) : null;
        return Kernel::page('terms', ['tax' => $tax, 'terms' => Terms::all($tax), 'edit' => $edit, 'all' => Terms::all('category')], $tax === 'tag' ? __('Étiquettes') : __('Catégories'), 'posts');
    }

    public function termAction(): string
    {
        Kernel::need('edit_others');
        $tax = ($_POST['taxonomy'] ?? 'category') === 'tag' ? 'tag' : 'category';
        try {
            if (($_POST['action'] ?? '') === 'delete') {
                Terms::delete((int) $_POST['id']);
                Kernel::flash(__('Supprimé.'));
            } else {
                $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
                Terms::save(['name' => $_POST['name'] ?? '', 'slug' => $_POST['slug'] ?? '', 'description' => $_POST['description'] ?? '', 'parent_id' => $_POST['parent_id'] ?? null, 'taxonomy' => $tax], $id);
                Kernel::flash(__('Enregistré.'));
            }
        } catch (\Throwable $e) {
            Kernel::flash($e->getMessage(), 'bad');
        }
        redirect(admin_url('terms?taxonomy=' . $tax));
    }
}
