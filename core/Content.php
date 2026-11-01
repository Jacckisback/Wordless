<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Contenus (articles et pages) : enregistrement, chemins publics, révisions,
 * programmation, redirections automatiques quand une adresse change.
 */
final class Content
{
    public const TYPES = ['post', 'page'];
    public const STATUSES = ['draft', 'published', 'scheduled', 'private', 'trash'];

    public static function typeLabel(string $type, bool $plural = false): string
    {
        return match ($type) {
            'page' => $plural ? __('Pages') : __('Page'),
            default => $plural ? __('Articles') : __('Article'),
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            'published' => __('Publié'),
            'scheduled' => __('Programmé'),
            'private' => __('Privé'),
            'trash' => __('Corbeille'),
            default => __('Brouillon'),
        };
    }

    public static function decode(array $row): array
    {
        $row['blocks'] = json_dec($row['blocks'], []);
        $row['seo'] = json_dec($row['seo'], []) + ['title' => '', 'description' => '', 'keyword' => '', 'canonical' => '', 'noindex' => false, 'og_image' => null, 'schema' => ''];
        return $row;
    }

    public static function find(?int $id): ?array
    {
        if (!$id) {
            return null;
        }
        $row = Db::one('SELECT * FROM content WHERE id = ?', [$id]);
        return $row ? self::decode($row) : null;
    }

    /** Contenu public pour un chemin (publié et date atteinte). */
    public static function byPath(string $path): ?array
    {
        $row = Db::one("SELECT * FROM content WHERE path = ? AND status = 'published' AND published_at <= ?", [self::normalizePath($path), time()]);
        return $row ? self::decode($row) : null;
    }

    public static function byWpId(int $wpId): ?array
    {
        $row = Db::one("SELECT * FROM content WHERE wp_id = ? AND status <> 'trash'", [$wpId]);
        return $row ? self::decode($row) : null;
    }

    public static function normalizePath(string $path): string
    {
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : $path;
    }

    public static function isVisible(array $c): bool
    {
        return $c['status'] === 'published' && (int) $c['published_at'] <= time();
    }

    public static function isHome(array $c): bool
    {
        return $c['type'] === 'page' && (int) Settings::get('home.page_id') === (int) $c['id'] && Settings::get('home.type') === 'page';
    }

    /** Lien relatif (href) d'un contenu. */
    public static function href(array $c): string
    {
        if (self::isHome($c)) {
            return href('/');
        }
        return href((string) ($c['path'] ?? '/'));
    }

    /** Adresse absolue (canonical, sitemap, partage). */
    public static function permalink(array $c): string
    {
        if (self::isHome($c)) {
            return Config::baseUrl() . '/';
        }
        return url((string) ($c['path'] ?? '/'));
    }

    public static function summary(array $c, int $len = 160): string
    {
        if (trim((string) $c['excerpt']) !== '') {
            return excerpt((string) $c['excerpt'], $len);
        }
        $seo = is_array($c['seo']) ? $c['seo'] : json_dec((string) $c['seo'], []);
        if (!empty($seo['description'])) {
            return excerpt((string) $seo['description'], $len);
        }
        $blocks = is_array($c['blocks']) ? $c['blocks'] : json_dec((string) $c['blocks'], []);
        $intro = Blocks::intro($blocks);
        return excerpt($intro !== '' ? $intro : Blocks::text($blocks), $len);
    }

    /**
     * Crée ou met à jour un contenu. Champs acceptés : type, status, title, slug,
     * parent_id, author_id, excerpt, blocks, template, featured_id, seo, lang,
     * menu_order, comments, sticky, published_at, categories, tags, wp_id.
     * Options : revision (bool), trusted (HTML brut autorisé), log (bool), path (forcer un chemin, import).
     */
    public static function save(array $in, ?int $id = null, array $opt = []): int
    {
        $old = $id ? self::find($id) : null;
        if ($id && !$old) {
            throw new \RuntimeException(__('Contenu introuvable.'));
        }
        $now = time();
        $c = $old ?? [
            'type' => 'post', 'status' => 'draft', 'title' => '', 'slug' => '', 'path' => null, 'parent_id' => null,
            'author_id' => Auth::id(), 'excerpt' => '', 'blocks' => [], 'template' => '', 'featured_id' => null,
            'seo' => [], 'lang' => '', 'tgroup' => null, 'menu_order' => 0, 'comments' => (int) (bool) Settings::get('comments.enabled', true),
            'sticky' => 0, 'published_at' => null, 'created_at' => $now, 'wp_id' => null,
        ];
        if (!$old && isset($in['type']) && $in['type'] === 'page') {
            $c['comments'] = 0;
        }

        foreach (['title', 'excerpt', 'template', 'lang'] as $k) {
            if (array_key_exists($k, $in)) {
                $c[$k] = trim(strip_tags((string) $in[$k]));
            }
        }
        $c['title'] = mb_substr($c['title'], 0, 300);
        if (isset($in['type']) && in_array($in['type'], self::TYPES, true)) {
            $c['type'] = $in['type'];
        }
        foreach (['parent_id', 'author_id', 'featured_id'] as $k) {
            if (array_key_exists($k, $in)) {
                $c[$k] = $in[$k] !== null && (int) $in[$k] > 0 ? (int) $in[$k] : null;
            }
        }
        if ($c['type'] !== 'page') {
            $c['parent_id'] = null;
        }
        if ($c['parent_id'] && $id && (int) $c['parent_id'] === $id) {
            $c['parent_id'] = null;
        }
        foreach (['menu_order', 'comments', 'sticky', 'wp_id'] as $k) {
            if (array_key_exists($k, $in)) {
                $c[$k] = $in[$k] === null ? null : (int) $in[$k];
            }
        }
        if (array_key_exists('blocks', $in)) {
            $blocks = is_string($in['blocks']) ? json_dec($in['blocks'], []) : (array) $in['blocks'];
            $c['blocks'] = Blocks::sanitize($blocks, (bool) ($opt['trusted'] ?? Auth::can('*')));
        }
        if (array_key_exists('seo', $in)) {
            $c['seo'] = self::cleanSeo((array) $in['seo'], (array) $c['seo']);
        }

        // Statut et date de publication
        $status = isset($in['status']) && in_array($in['status'], self::STATUSES, true) ? $in['status'] : $c['status'];
        $publishedAt = array_key_exists('published_at', $in) && $in['published_at'] !== null && $in['published_at'] !== ''
            ? (is_numeric($in['published_at']) ? (int) $in['published_at'] : (int) strtotime((string) $in['published_at']))
            : ($c['published_at'] ? (int) $c['published_at'] : null);
        if (in_array($status, ['published', 'scheduled'], true)) {
            $publishedAt = $publishedAt ?: $now;
            $status = $publishedAt > $now + 30 ? 'scheduled' : 'published';
        }
        $c['status'] = $status;
        $c['published_at'] = $publishedAt;

        // Adresse
        $slug = array_key_exists('slug', $in) && trim((string) $in['slug']) !== '' ? slugify((string) $in['slug']) : ($c['slug'] !== '' ? $c['slug'] : slugify($c['title']));
        if ($slug === '') {
            $slug = $c['type'] === 'page' ? 'page' : 'article';
        }
        $c['slug'] = $slug;
        $terms = self::incomingTerms($in);
        // Contenu importé de WordPress : il garde son adresse d'origine (même hors du modèle de
        // Wordless : accents, sous-dossiers, types personnalisés) tant que le slug et le parent restent.
        $keepPath = $old && (string) ($old['path'] ?? '') !== '' && !empty($old['wp_id'])
            && (!array_key_exists('slug', $in) || trim((string) $in['slug']) === '' || slugify((string) $in['slug']) === slugify((string) $old['slug']))
            && (int) ($c['parent_id'] ?? 0) === (int) ($old['parent_id'] ?? 0);
        if (!empty($opt['path'])) {
            $c['path'] = self::normalizePath((string) $opt['path']);
            $c['path'] = self::uniquePath($c['path'], $id);
        } elseif ($keepPath) {
            $c['path'] = self::uniquePath((string) $old['path'], $id);
        } else {
            $c['path'] = self::uniquePath(self::buildPath($c, $terms['category'] ?? null), $id);
        }
        $c['slug'] = basename($c['path']) ?: $c['slug'];

        // Mesures
        $text = Blocks::text($c['blocks']);
        $c['word_count'] = word_count($text);
        $c['updated_at'] = (int) ($in['updated_at'] ?? $now);

        $row = [
            'type' => $c['type'], 'status' => $c['status'], 'title' => $c['title'], 'slug' => $c['slug'], 'path' => $c['path'],
            'parent_id' => $c['parent_id'], 'author_id' => $c['author_id'], 'excerpt' => mb_substr((string) $c['excerpt'], 0, 1000),
            'blocks' => json_enc($c['blocks']), 'template' => $c['template'], 'featured_id' => $c['featured_id'], 'seo' => json_enc($c['seo']),
            'lang' => $c['lang'], 'menu_order' => (int) $c['menu_order'], 'comments' => (int) $c['comments'], 'sticky' => (int) $c['sticky'],
            'word_count' => $c['word_count'], 'published_at' => $c['published_at'], 'updated_at' => $c['updated_at'], 'wp_id' => $c['wp_id'],
        ];

        $id = Db::tx(static function () use ($row, $id, $c, $old, $terms, $opt, $now): int {
            if ($id) {
                Db::update('content', $row, ['id' => $id]);
            } else {
                $row['created_at'] = (int) ($c['created_at'] ?? $now);
                $id = Db::insert('content', $row);
            }
            foreach ($terms as $tax => $ids) {
                Terms::assign($id, $tax, $ids);
            }
            // Ancienne adresse publiée => redirection 301 automatique.
            if ($old && $old['status'] === 'published' && $old['path'] !== $row['path'] && $old['path']) {
                Redirects::add((string) $old['path'], (string) $row['path'], 301, true);
                self::rebaseChildren($id, (string) $old['path'], (string) $row['path']);
            }
            Redirects::removeSource((string) $row['path']);
            if (!empty($opt['revision'])) {
                self::addRevision($id);
            }
            return $id;
        });

        $saved = self::find($id);
        $analysis = Seo::analyze($saved);
        Db::update('content', ['seo_score' => $analysis['seo']['score'], 'geo_score' => $analysis['geo']['score']], ['id' => $id]);
        Search::index($saved);

        $wasVisible = $old && self::isVisible($old);
        $isVisible = self::isVisible($saved);
        if ($wasVisible || $isVisible || $saved['status'] === 'scheduled') {
            Cache::purge();
        }
        if ($saved['status'] === 'scheduled') {
            self::scheduleNext();
        }
        if ($isVisible && empty($opt['silent'])) {
            IndexNow::queue(self::permalink($saved));
        }
        if (($opt['log'] ?? true) && empty($opt['silent'])) {
            $action = !$old ? 'create' : ($isVisible && !$wasVisible ? 'publish' : 'update');
            Activity::log($action, $saved['type'], $id, $saved['title']);
        }
        wl_do('content_saved', $saved, $old);
        return $id;
    }

    private static function cleanSeo(array $in, array $current): array
    {
        $s = array_merge(['title' => '', 'description' => '', 'keyword' => '', 'canonical' => '', 'noindex' => false, 'og_image' => null, 'schema' => ''], $current);
        foreach (['title' => 160, 'description' => 400, 'keyword' => 120] as $k => $max) {
            if (array_key_exists($k, $in)) {
                $s[$k] = mb_substr(trim(strip_tags((string) $in[$k])), 0, $max);
            }
        }
        if (array_key_exists('canonical', $in)) {
            $s['canonical'] = Html::safeUrl(trim((string) $in['canonical']));
        }
        if (array_key_exists('noindex', $in)) {
            $s['noindex'] = filter_var($in['noindex'], FILTER_VALIDATE_BOOLEAN);
        }
        if (array_key_exists('og_image', $in)) {
            $s['og_image'] = is_numeric($in['og_image']) && (int) $in['og_image'] > 0 ? (int) $in['og_image'] : null;
        }
        if (array_key_exists('schema', $in)) {
            $s['schema'] = in_array($in['schema'], ['', 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'AboutPage', 'ContactPage', 'FAQPage', 'Service', 'Product', 'LocalBusiness', 'Event', 'Recipe', 'Course'], true) ? $in['schema'] : '';
        }
        return $s;
    }

    /** @return array<string, list<int>> */
    private static function incomingTerms(array $in): array
    {
        $out = [];
        foreach (['categories' => 'category', 'tags' => 'tag'] as $key => $tax) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $ids = [];
            foreach ((array) $in[$key] as $t) {
                if (is_numeric($t)) {
                    $ids[] = (int) $t;
                } elseif (is_string($t) && trim($t) !== '') {
                    $ids[] = Terms::ensure($tax, trim($t));
                }
            }
            $out[$tax] = array_values(array_unique($ids));
        }
        return $out;
    }

    /** Construit le chemin selon le type (pages hiérarchiques, articles selon le réglage). */
    public static function buildPath(array $c, ?array $categoryIds = null): string
    {
        if ($c['type'] === 'page') {
            $prefix = '';
            if (!empty($c['parent_id'])) {
                $parent = Db::one('SELECT path FROM content WHERE id = ?', [(int) $c['parent_id']]);
                $prefix = rtrim((string) ($parent['path'] ?? ''), '/');
            }
            return $prefix . '/' . $c['slug'];
        }
        $pattern = (string) Settings::get('permalink', '/{slug}');
        $ts = (int) ($c['published_at'] ?: time());
        $tz = new \DateTimeZone((string) Settings::get('timezone', 'Europe/Paris'));
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone($tz);
        $cat = 'non-classe';
        if (str_contains($pattern, '{category}')) {
            $catId = $categoryIds[0] ?? (isset($c['id']) ? Db::val("SELECT t.id FROM terms t JOIN content_terms ct ON ct.term_id = t.id WHERE ct.content_id = ? AND t.taxonomy = 'category' ORDER BY t.id LIMIT 1", [(int) $c['id']]) : null);
            if ($catId) {
                $cat = (string) (Db::val('SELECT slug FROM terms WHERE id = ?', [(int) $catId]) ?: $cat);
            }
        }
        $path = strtr($pattern, [
            '{slug}' => $c['slug'], '{year}' => $d->format('Y'), '{month}' => $d->format('m'), '{day}' => $d->format('d'),
            '{category}' => $cat, '{id}' => (string) ($c['id'] ?? ''),
        ]);
        return self::normalizePath($path);
    }

    /** Chemin unique : ajoute -2, -3… et évite les adresses réservées. */
    public static function uniquePath(string $path, ?int $excludeId): string
    {
        $path = self::normalizePath($path);
        $reserved = ['/admin', '/mcp', '/oauth', '/wl', '/media', '/feed', '/sitemap.xml', '/robots.txt', '/llms.txt', '/llms-full.txt', '/search', '/category', '/tag', '/author', '/page', '/core', '/data', '/themes', '/plugins', '/wp-admin', '/wp-content', '/wp-includes', '/wp-login.php', '/xmlrpc.php'];
        $candidate = $path;
        $i = 2;
        while (in_array($candidate, $reserved, true) || $candidate === '/' || Db::val("SELECT id FROM content WHERE path = ? AND status <> 'trash' AND id <> ?", [$candidate, $excludeId ?? 0])) {
            $candidate = $path . '-' . $i++;
        }
        return $candidate;
    }

    /** Pages enfants : met à jour leurs chemins quand le parent change d'adresse. */
    private static function rebaseChildren(int $parentId, string $oldPath, string $newPath): void
    {
        foreach (Db::all("SELECT id, path, status FROM content WHERE parent_id = ? AND status <> 'trash'", [$parentId]) as $child) {
            if (str_starts_with((string) $child['path'], $oldPath . '/')) {
                $np = $newPath . substr((string) $child['path'], strlen($oldPath));
                Db::update('content', ['path' => $np], ['id' => $child['id']]);
                if ($child['status'] === 'published') {
                    Redirects::add((string) $child['path'], $np, 301, true);
                }
                self::rebaseChildren((int) $child['id'], (string) $child['path'], $np);
            }
        }
    }

    public static function setStatus(int $id, string $status): void
    {
        $c = self::find($id);
        if (!$c) {
            return;
        }
        if ($status === 'trash') {
            Db::update('content', ['status' => 'trash', 'updated_at' => time()], ['id' => $id]);
            Activity::log('trash', $c['type'], $id, $c['title']);
            Search::remove($id);
            Cache::purge();
            return;
        }
        self::save(['status' => $status], $id);
    }

    public static function restore(int $id): void
    {
        $c = self::find($id);
        if (!$c || $c['status'] !== 'trash') {
            return;
        }
        $path = self::uniquePath((string) $c['path'], $id);
        Db::update('content', ['status' => 'draft', 'path' => $path], ['id' => $id]);
        Activity::log('restore', $c['type'], $id, $c['title']);
        $saved = self::find($id);
        Search::index($saved);
    }

    public static function delete(int $id): void
    {
        $c = self::find($id);
        if (!$c) {
            return;
        }
        Db::delete('content', ['id' => $id]);
        Search::remove($id);
        Activity::log('delete', $c['type'], $id, $c['title']);
        Cache::purge();
    }

    public static function duplicate(int $id): int
    {
        $c = self::find($id);
        if (!$c) {
            throw new \RuntimeException(__('Contenu introuvable.'));
        }
        $blocks = array_map(static function ($b) {
            $b['id'] = 'b' . bin2hex(random_bytes(5));
            return $b;
        }, $c['blocks']);
        return self::save([
            'type' => $c['type'], 'status' => 'draft', 'title' => $c['title'] . ' ' . __('(copie)'), 'slug' => $c['slug'] . '-copie',
            'parent_id' => $c['parent_id'], 'excerpt' => $c['excerpt'], 'blocks' => $blocks, 'template' => $c['template'],
            'featured_id' => $c['featured_id'], 'seo' => array_merge($c['seo'], ['canonical' => '']),
            'categories' => array_column(Terms::forContent($id, 'category'), 'id'), 'tags' => array_column(Terms::forContent($id, 'tag'), 'id'),
        ], null, ['trusted' => true]);
    }

    // ------------------------------------------------------------------
    // Requêtes
    // ------------------------------------------------------------------

    /**
     * Liste filtrée. Clés : type, status (ou 'any'), visible (bool), author, term,
     * search, sticky_first, order (recent|title|updated|oldest_update), limit, offset, missing (seo|alt…)
     */
    public static function query(array $q): array
    {
        [$where, $params] = self::where($q);
        $order = match ($q['order'] ?? 'recent') {
            'title' => 'c.title COLLATE NOCASE ASC',
            'updated' => 'c.updated_at DESC',
            'oldest_update' => 'c.updated_at ASC',
            'menu' => 'c.menu_order ASC, c.title COLLATE NOCASE ASC',
            'seo' => 'COALESCE(c.seo_score, 0) ASC',
            default => 'COALESCE(c.published_at, c.created_at) DESC, c.id DESC',
        };
        if (!empty($q['sticky_first'])) {
            $order = 'c.sticky DESC, ' . $order;
        }
        $limit = max(1, min(500, (int) ($q['limit'] ?? 20)));
        $offset = max(0, (int) ($q['offset'] ?? 0));
        $rows = Db::all('SELECT c.* FROM content c WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', [...$params, $limit, $offset]);
        return array_map([self::class, 'decode'], $rows);
    }

    public static function count(array $q): int
    {
        [$where, $params] = self::where($q);
        return (int) Db::val('SELECT COUNT(*) FROM content c WHERE ' . $where, $params);
    }

    private static function where(array $q): array
    {
        $w = ['1=1'];
        $p = [];
        if (!empty($q['type'])) {
            $w[] = 'c.type = ?';
            $p[] = $q['type'];
        }
        if (!empty($q['visible'])) {
            $w[] = "c.status = 'published' AND c.published_at <= ?";
            $p[] = time();
        } elseif (($q['status'] ?? 'any') === 'any') {
            $w[] = "c.status <> 'trash'";
        } elseif (!empty($q['status'])) {
            $w[] = 'c.status = ?';
            $p[] = $q['status'];
        }
        if (!empty($q['author'])) {
            $w[] = 'c.author_id = ?';
            $p[] = (int) $q['author'];
        }
        if (!empty($q['term'])) {
            $w[] = 'c.id IN (SELECT content_id FROM content_terms WHERE term_id = ?)';
            $p[] = (int) $q['term'];
        }
        if (!empty($q['search'])) {
            $w[] = '(c.title LIKE ? OR c.blocks LIKE ?)';
            $p[] = '%' . $q['search'] . '%';
            $p[] = '%' . $q['search'] . '%';
        }
        if (!empty($q['lang'])) {
            $w[] = 'c.lang = ?';
            $p[] = $q['lang'];
        }
        if (($q['missing'] ?? '') === 'description') {
            $w[] = "COALESCE(json_extract(c.seo, '$.description'), '') = ''";
        }
        if (isset($q['max_seo'])) {
            $w[] = 'COALESCE(c.seo_score, 0) < ?';
            $p[] = (int) $q['max_seo'];
        }
        if (!empty($q['exclude'])) {
            $w[] = 'c.id NOT IN (' . Db::in((array) $q['exclude']) . ')';
            array_push($p, ...array_map('intval', (array) $q['exclude']));
        }
        return [implode(' AND ', $w), $p];
    }

    /** Publie les contenus programmés dont l'heure est venue. */
    public static function publishDue(): void
    {
        $next = (int) Settings::get('schedule.next', 0);
        if ($next === 0 || $next > time()) {
            return;
        }
        $due = Db::all("SELECT id FROM content WHERE status = 'scheduled' AND published_at <= ?", [time()]);
        foreach ($due as $row) {
            Db::update('content', ['status' => 'published'], ['id' => (int) $row['id']]);
            $c = self::find((int) $row['id']);
            if ($c) {
                Search::index($c);
                IndexNow::queue(self::permalink($c));
                wl_do('content_published', $c);
            }
        }
        self::scheduleNext();
        Cache::purge();
    }

    public static function scheduleNext(): void
    {
        $next = (int) (Db::val("SELECT MIN(published_at) FROM content WHERE status = 'scheduled'") ?: 0);
        Settings::set('schedule.next', $next);
        Cache::refreshMeta();
    }

    // ------------------------------------------------------------------
    // Révisions
    // ------------------------------------------------------------------

    public static function addRevision(int $id): void
    {
        $c = Db::one('SELECT title, excerpt, blocks, seo FROM content WHERE id = ?', [$id]);
        if (!$c) {
            return;
        }
        $last = Db::one('SELECT data FROM revisions WHERE content_id = ? ORDER BY id DESC LIMIT 1', [$id]);
        $data = json_enc($c);
        if ($last && $last['data'] === $data) {
            return;
        }
        Db::insert('revisions', ['content_id' => $id, 'user_id' => Auth::id(), 'data' => $data, 'created_at' => time()]);
        Db::run('DELETE FROM revisions WHERE content_id = ? AND id NOT IN (SELECT id FROM revisions WHERE content_id = ? ORDER BY id DESC LIMIT 25)', [$id, $id]);
    }

    public static function revisions(int $id): array
    {
        return Db::all('SELECT r.id, r.created_at, u.name AS user_name, LENGTH(r.data) AS size FROM revisions r LEFT JOIN users u ON u.id = r.user_id WHERE r.content_id = ? ORDER BY r.id DESC', [$id]);
    }

    public static function restoreRevision(int $contentId, int $revisionId): void
    {
        $r = Db::one('SELECT data FROM revisions WHERE id = ? AND content_id = ?', [$revisionId, $contentId]);
        if (!$r) {
            throw new \RuntimeException(__('Révision introuvable.'));
        }
        $d = json_dec($r['data'], []);
        self::addRevision($contentId);
        self::save(['title' => $d['title'] ?? '', 'excerpt' => $d['excerpt'] ?? '', 'blocks' => json_dec($d['blocks'] ?? '[]', []), 'seo' => json_dec($d['seo'] ?? '{}', [])], $contentId, ['trusted' => true]);
    }
}
