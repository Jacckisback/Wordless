<?php
declare(strict_types=1);

namespace Wordless\Mcp;

use Wordless\Activity;
use Wordless\Auth;
use Wordless\Blocks;
use Wordless\Config;
use Wordless\Content;
use Wordless\Db;
use Wordless\Media;
use Wordless\Search;
use Wordless\Seo;
use Wordless\Settings;
use Wordless\Terms;

/**
 * Outils MCP sur les contenus : recherche et lecture (formats compatibles
 * OpenAI search/fetch), liste, création, modification, publication, corbeille
 * et analyse SEO/GEO. Le HTML envoyé par l'IA n'est jamais considéré comme sûr
 * (une injection de consignes ne doit pas pouvoir insérer de script).
 */
final class ContentTools
{
    /** Options d'enregistrement : HTML nettoyé, révision, journal fait ici (« mcp »). */
    private const SAVE = ['trusted' => false, 'revision' => true, 'log' => false];

    /** Blocs que le Markdown restitue sans perte. */
    private const MARKDOWN_SAFE = ['text', 'heading', 'faq', 'quote', 'divider'];

    private static ?\DateTimeZone $tz = null;

    // ------------------------------------------------------------------
    // Lecture
    // ------------------------------------------------------------------

    public static function search(array $a): array
    {
        $q = trim((string) $a['query']);
        $limit = 20;
        $results = [];
        $seen = [];
        foreach (Search::query($q, $limit)['items'] as $c) {
            $seen[] = (int) $c['id'];
            $snippet = html_entity_decode(strip_tags((string) ($c['snippet'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $results[] = self::result($c, $snippet !== '' ? $snippet : Content::summary($c, 240));
        }
        if (count($results) < $limit) {
            foreach (self::searchOthers($q, $limit - count($results), $seen) as $c) {
                $results[] = self::result($c, Content::summary($c, 240));
            }
        }
        return ['results' => $results];
    }

    /** Contenus non publiés (ou absents de l'index) visibles par l'utilisateur, tous les mots requis. */
    private static function searchOthers(string $q, int $limit, array $exclude): array
    {
        $words = array_slice(preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 6);
        if (!$words) {
            return [];
        }
        $where = ["status <> 'trash'"];
        $p = [];
        if (!Auth::can('edit_others')) {
            $where[] = "(author_id = ? OR (status = 'published' AND published_at <= ?))";
            array_push($p, (int) Auth::id(), time());
        }
        foreach ($words as $w) {
            $like = '%' . addcslashes($w, '%_\\') . '%';
            $where[] = "(title LIKE ? ESCAPE '\\' OR excerpt LIKE ? ESCAPE '\\' OR blocks LIKE ? ESCAPE '\\')";
            array_push($p, $like, $like, $like);
        }
        if ($exclude) {
            $where[] = 'id NOT IN (' . Db::in($exclude) . ')';
            array_push($p, ...$exclude);
        }
        $rows = Db::all('SELECT * FROM content WHERE ' . implode(' AND ', $where) . " ORDER BY (title LIKE ? ESCAPE '\\') DESC, updated_at DESC LIMIT ?",
            [...$p, '%' . addcslashes($q, '%_\\') . '%', $limit]);
        return array_map([Content::class, 'decode'], $rows);
    }

    private static function result(array $c, string $text): array
    {
        $flag = Content::isVisible($c) ? '' : '[' . $c['status'] . '] ';
        return [
            'id' => (string) $c['id'],
            'title' => (string) $c['title'] !== '' ? (string) $c['title'] : '(untitled)',
            'url' => Content::permalink($c),
            'text' => $flag . excerpt($text, 300),
        ];
    }

    public static function fetch(array $a): array
    {
        $ref = trim((string) $a['id']);
        $c = ctype_digit($ref) ? self::byId((int) $ref) : self::byUrl($ref);
        $author = $c['author_id'] ? Db::val('SELECT name FROM users WHERE id = ?', [(int) $c['author_id']]) : null;
        return [
            'id' => (string) $c['id'],
            'title' => (string) $c['title'],
            'text' => Blocks::toMarkdown($c['blocks']),
            'url' => Content::permalink($c),
            'metadata' => [
                'type' => (string) $c['type'],
                'status' => (string) $c['status'],
                'published_at' => self::iso($c['published_at']),
                'updated_at' => self::iso($c['updated_at']),
                'author' => $author !== null ? (string) $author : null,
                'seo_title' => Seo::title($c),
                'meta_description' => Seo::description($c),
                'keyword' => (string) $c['seo']['keyword'],
                'categories' => array_column(Terms::forContent((int) $c['id'], 'category'), 'name'),
                'tags' => array_column(Terms::forContent((int) $c['id'], 'tag'), 'name'),
                'seo_score' => $c['seo_score'] !== null ? (int) $c['seo_score'] : null,
                'geo_score' => $c['geo_score'] !== null ? (int) $c['geo_score'] : null,
                'words' => (int) $c['word_count'],
                'admin_url' => self::adminUrl((int) $c['id']),
            ],
        ];
    }

    public static function list(array $a): array
    {
        $q = [
            'type' => ($a['type'] ?? 'any') === 'any' ? '' : (string) $a['type'],
            'status' => (string) ($a['status'] ?? 'any'),
            'search' => trim((string) ($a['search'] ?? '')),
            'order' => (string) ($a['order'] ?? 'recent'),
            'limit' => (int) ($a['limit'] ?? 20),
            'offset' => (int) ($a['offset'] ?? 0),
        ];
        if (!Auth::can('edit_others')) {
            $q['author'] = (int) Auth::id();
        }
        $category = trim((string) ($a['category'] ?? ''));
        if ($category !== '') {
            $term = ctype_digit($category) ? Terms::find((int) $category) : Terms::bySlug('category', slugify($category));
            if (!$term) {
                throw new ToolError('Unknown category "' . $category . '". Use list_terms to see the categories.');
            }
            $q['term'] = (int) $term['id'];
        }
        if (isset($a['max_seo_score'])) {
            $q['max_seo'] = (int) $a['max_seo_score'];
        }
        if (!empty($a['missing_description'])) {
            $q['missing'] = 'description';
        }
        $items = array_map([self::class, 'summary'], Content::query($q));
        $total = Content::count($q);
        $out = ['total' => $total, 'offset' => $q['offset'], 'items' => $items];
        if ($total > $q['offset'] + count($items)) {
            $out['next_offset'] = $q['offset'] + count($items);
        }
        if (!Auth::can('edit_others')) {
            $out['note'] = 'Only your own content is listed (author role).';
        }
        return $out;
    }

    public static function get(array $a): array
    {
        $c = self::resolve($a);
        $out = self::detail($c);
        if (($a['format'] ?? 'markdown') === 'blocks') {
            $out['blocks'] = $c['blocks'];
            $out['media'] = self::mediaMap($c['blocks']);
            return $out;
        }
        $out['markdown'] = Blocks::toMarkdown($c['blocks']);
        $layout = array_values(array_diff(array_unique(array_column($c['blocks'], 'type')), self::MARKDOWN_SAFE));
        if ($layout) {
            $out['note'] = 'This content uses blocks that Markdown cannot fully represent (' . implode(', ', $layout) . '). To edit its body without losing them, call get_content with format "blocks" and send the edited blocks to update_content, or use append_markdown.';
        }
        return $out;
    }

    public static function analyze(array $a): array
    {
        $c = self::resolve($a);
        $an = Seo::analyze($c);
        if ((int) ($c['seo_score'] ?? -1) !== $an['seo']['score'] || (int) ($c['geo_score'] ?? -1) !== $an['geo']['score']) {
            // Les scores mémorisés (tableaux de bord) suivent l'analyse fraîche.
            Db::update('content', ['seo_score' => $an['seo']['score'], 'geo_score' => $an['geo']['score']], ['id' => (int) $c['id']]);
        }
        $checks = static fn (array $list): array => array_map(static fn (array $ch): array => [
            'id' => $ch['id'], 'status' => $ch['status'], 'label' => $ch['label'], 'hint' => $ch['hint'], 'weight' => $ch['weight'],
        ], $list);
        return [
            'id' => (int) $c['id'],
            'title' => (string) $c['title'],
            'url' => Content::permalink($c),
            'status' => (string) $c['status'],
            'scores' => ['seo' => $an['seo']['score'], 'geo' => $an['geo']['score']],
            'priorities' => self::suggestions($an, 10),
            'seo_checks' => $checks($an['seo']['checks']),
            'geo_checks' => $checks($an['geo']['checks']),
            'stats' => $an['stats'],
            'legend' => 'status: good / warn / bad; weight = importance in the score. Labels and hints are in the site language.',
        ];
    }

    // ------------------------------------------------------------------
    // Écriture
    // ------------------------------------------------------------------

    public static function create(array $a): array
    {
        Tools::need('edit_content');
        $warnings = [];
        $in = self::input($a, null, $warnings);
        $in['type'] = (string) ($a['type'] ?? 'post');
        $in += self::statusInput((string) ($a['status'] ?? (isset($a['publish_at']) ? 'scheduled' : 'draft')), $a['publish_at'] ?? null, null, $warnings);
        $id = Content::save($in, null, self::SAVE);
        $c = Content::find($id);
        self::log(__('Création : {title} ({type}, {status})', [
            'title' => $c['title'], 'type' => Content::typeLabel($c['type']), 'status' => mb_strtolower(Content::statusLabel($c['status'])),
        ]), $c);
        return ['created' => true] + self::saved($c, $warnings);
    }

    public static function update(array $a): array
    {
        $c = self::byId((int) $a['id']);
        Tools::need('edit_content', $c);
        if ($c['status'] === 'trash') {
            throw new ToolError('This content is in the trash: restore it from the admin first.');
        }
        $warnings = [];
        $in = self::input($a, $c, $warnings);
        if (isset($a['status'])) {
            $in += self::statusInput((string) $a['status'], $a['publish_at'] ?? null, $c, $warnings);
        } elseif (isset($a['publish_at'])) {
            if (in_array($c['status'], ['published', 'scheduled'], true)) {
                Tools::need('publish', $c);
            }
            $in['published_at'] = self::parseDate((string) $a['publish_at']);
        }
        if (!$in) {
            throw new ToolError('Nothing to update: provide at least one field to change.');
        }
        Content::addRevision((int) $c['id']); // état précédent, pour pouvoir revenir en arrière
        Content::save($in, (int) $c['id'], self::SAVE);
        $new = Content::find((int) $c['id']);
        if ($c['status'] === 'published' && $c['path'] !== $new['path']) {
            $warnings[] = 'The URL changed: ' . url((string) $c['path']) . ' now redirects (301) to ' . Content::permalink($new) . '.';
        }
        $changed = array_keys($in);
        self::log(__('Modification : {title} ({fields})', ['title' => $new['title'], 'fields' => implode(', ', $changed)]), $new);
        return ['updated' => $changed] + self::saved($new, $warnings);
    }

    public static function publish(array $a): array
    {
        $c = self::byId((int) $a['id']);
        Tools::need('publish', $c);
        if ($c['status'] === 'trash') {
            throw new ToolError('This content is in the trash: restore it from the admin first.');
        }
        $at = isset($a['publish_at']) ? self::parseDate((string) $a['publish_at']) : null;
        if ($at === null && Content::isVisible($c)) {
            return ['already_published' => true] + self::saved($c, []);
        }
        Content::save(['status' => 'published', 'published_at' => $at ?? time()], (int) $c['id'], self::SAVE);
        $new = Content::find((int) $c['id']);
        self::log($new['status'] === 'scheduled'
            ? __('Programmation : {title} ({date})', ['title' => $new['title'], 'date' => fmt_date((int) $new['published_at'], 'datetime')])
            : __('Publication : {title}', ['title' => $new['title']]), $new);
        return self::saved($new, []);
    }

    public static function delete(array $a): array
    {
        $c = self::byId((int) $a['id']);
        Tools::need('delete_content', $c);
        if ($c['status'] === 'trash') {
            return ['id' => (int) $c['id'], 'status' => 'trash', 'note' => 'Already in the trash.'];
        }
        $id = (int) $c['id'];
        if (Settings::get('home.type') === 'page' && $id === (int) Settings::get('home.page_id')) {
            throw new ToolError('This page is the home page of the site: choose another home page in the admin settings before deleting it.');
        }
        if ($id === (int) Settings::get('blog.page_id')) {
            throw new ToolError('This page is the blog page of the site: change it in the admin settings before deleting it.');
        }
        $wasVisible = Content::isVisible($c);
        Content::setStatus($id, 'trash');
        self::log(__('Mise à la corbeille : {title}', ['title' => $c['title']]), $c);
        return [
            'id' => $id,
            'title' => (string) $c['title'],
            'status' => 'trash',
            'previous_url' => Content::permalink($c),
            'note' => 'Moved to the trash (it can be restored from the admin).'
                . ($wasVisible ? ' Its URL now returns a 404 error: if it had traffic or backlinks, add a 301 redirect with manage_redirects.' : ''),
        ];
    }

    /** Champs communs à la création et à la modification. */
    private static function input(array $a, ?array $old, array &$warnings): array
    {
        $in = [];
        if (isset($a['title'])) {
            $in['title'] = (string) $a['title'];
        }
        $bodies = array_intersect(['markdown', 'blocks', 'append_markdown'], array_keys($a));
        if (count($bodies) > 1) {
            throw new ToolError('Provide only one of: ' . implode(', ', $bodies) . '.');
        }
        if (isset($a['blocks'])) {
            $in['blocks'] = self::checkBlocks((array) $a['blocks'], $warnings);
        } elseif (isset($a['markdown'])) {
            $in['blocks'] = self::markdownBlocks((string) $a['markdown'], $warnings, true);
        } elseif (isset($a['append_markdown'])) {
            $added = self::markdownBlocks((string) $a['append_markdown'], $warnings, false);
            if (in_array('faq', array_column($added, 'type'), true) && in_array('faq', array_column($old['blocks'] ?? [], 'type'), true)) {
                $warnings[] = 'The content already had a FAQ block: it now has two FAQ sections. To merge them, edit the blocks (get_content format "blocks").';
            }
            $in['blocks'] = array_merge($old['blocks'] ?? [], $added);
        }
        if (isset($a['seo'])) {
            $in['seo'] = (array) $a['seo'];
        }
        foreach (['excerpt', 'slug'] as $k) {
            if (isset($a[$k])) {
                $in[$k] = (string) $a[$k];
            }
        }
        foreach (['categories' => 'category', 'tags' => 'tag'] as $k => $tax) {
            if (isset($a[$k])) {
                $in[$k] = self::termRefs((array) $a[$k], $tax);
            }
        }
        if (isset($a['comments'])) {
            $in['comments'] = $a['comments'] ? 1 : 0;
        }
        if (array_key_exists('parent_id', $a)) {
            $type = (string) ($old['type'] ?? $a['type'] ?? 'post');
            $parentId = (int) $a['parent_id'];
            if ($type !== 'page') {
                $warnings[] = 'parent_id ignored: only pages can have a parent.';
            } elseif ($parentId === 0) {
                $in['parent_id'] = null;
            } else {
                $parent = Content::find($parentId);
                if (!$parent || $parent['type'] !== 'page' || $parent['status'] === 'trash' || ($old && $parentId === (int) $old['id'])) {
                    throw new ToolError('parent_id ' . $parentId . ' is not a valid parent page.');
                }
                $in['parent_id'] = $parentId;
            }
        }
        $title = (string) ($a['title'] ?? $old['title'] ?? '');
        if (array_key_exists('featured_media_id', $a)) {
            $mid = (int) $a['featured_media_id'];
            if ($mid > 0 && !Media::find($mid)) {
                throw new ToolError('Media ' . $mid . ' not found (use list_media or upload_media).');
            }
            $in['featured_id'] = $mid > 0 ? $mid : null;
        } elseif (isset($a['featured_image_url'])) {
            if (!Auth::can('upload')) {
                $warnings[] = 'Featured image not imported: the connected account cannot upload media.';
            } else {
                try {
                    $m = SiteTools::import((string) $a['featured_image_url'], ['alt' => (string) ($a['featured_image_alt'] ?? $title), 'title' => $title]);
                    if (Media::isImage($m)) {
                        $in['featured_id'] = (int) $m['id'];
                    } else {
                        $warnings[] = 'featured_image_url is not an image: it was added to the media library but not used as featured image.';
                    }
                } catch (ToolError $e) {
                    $warnings[] = 'Featured image not imported: ' . $e->getMessage();
                }
            }
        }
        return $in;
    }

    /** Statut demandé => champs status / published_at, avec contrôle du droit de publier. */
    private static function statusInput(string $status, mixed $publishAt, ?array $old, array &$warnings): array
    {
        $out = ['status' => $status];
        if (in_array($status, ['published', 'scheduled'], true)) {
            Tools::need('publish', $old);
        }
        if ($publishAt !== null && $publishAt !== '') {
            $ts = self::parseDate((string) $publishAt);
            $out['published_at'] = $ts;
            if ($status === 'scheduled' && $ts <= time() + 30) {
                $warnings[] = 'publish_at is not in the future: the content was published immediately with that date.';
            }
        } elseif ($status === 'scheduled') {
            throw new ToolError('status "scheduled" requires publish_at (a date in the future).');
        } elseif ($status === 'published' && (!$old || !in_array($old['status'], ['published', 'scheduled'], true))) {
            $out['published_at'] = time();
        }
        return $out;
    }

    /** Catégories / étiquettes : identifiants vérifiés, noms créés au besoin par le noyau. */
    private static function termRefs(array $refs, string $taxonomy): array
    {
        $out = [];
        foreach ($refs as $r) {
            $r = trim((string) $r);
            if ($r === '') {
                continue;
            }
            if (ctype_digit($r)) {
                $t = Terms::find((int) $r);
                if (!$t || $t['taxonomy'] !== $taxonomy) {
                    throw new ToolError('Unknown ' . $taxonomy . ' id ' . $r . ' (use list_terms, or give a name).');
                }
                $out[] = (int) $r;
            } else {
                $out[] = $r;
            }
        }
        return $out;
    }

    /** @return list<array> blocs vérifiés (le nettoyage complet est fait par Content::save) */
    private static function checkBlocks(array $blocks, array &$warnings): array
    {
        $registry = Blocks::registry();
        $out = [];
        foreach ($blocks as $i => $b) {
            $type = (string) ($b['type'] ?? '');
            if (!isset($registry[$type])) {
                throw new ToolError(sprintf('blocks[%d]: unknown block type "%s". Valid types: %s.', $i, $type, implode(', ', array_keys($registry))));
            }
            $data = $b['data'] ?? [];
            $unknown = array_diff(array_keys((array) $data), array_keys($registry[$type]['fields']));
            if ($unknown) {
                $warnings[] = sprintf('blocks[%d] (%s): unknown fields ignored: %s.', $i, $type, implode(', ', $unknown));
            }
            $out[] = ['id' => $b['id'] ?? null, 'type' => $type, 'data' => (array) $data];
        }
        foreach (self::mediaIds($out) as $mid) {
            if (!Media::find($mid)) {
                $warnings[] = 'Media ' . $mid . ' referenced in blocks does not exist (use upload_media first).';
            }
        }
        return $out;
    }

    private static function markdownBlocks(string $md, array &$warnings, bool $isBody): array
    {
        $faqTitle = null;
        $md = self::prepareMarkdown($md, $warnings, $isBody, $faqTitle);
        $blocks = Blocks::fromMarkdown($md);
        foreach ($blocks as $i => $b) {
            if ($b['type'] !== 'faq') {
                continue;
            }
            if ($faqTitle !== null) {
                $blocks[$i]['data']['title'] = $faqTitle;
            }
            foreach ((array) $b['data']['items'] as $item) {
                if (trim(plain_text((string) ($item['a'] ?? ''))) === '') {
                    $warnings[] = 'FAQ item without answer: "' . mb_substr((string) $item['q'], 0, 80) . '". In the FAQ section, each "### Question?" heading must be followed by its answer, with no other text before the first question.';
                }
            }
        }
        return $blocks;
    }

    /**
     * Prépare le Markdown de l'IA : retire un « # Titre » initial (le titre est
     * déjà le H1) et reconnaît les variantes du titre de FAQ (« ## Questions
     * fréquentes sur… », « ## FAQ : … ») pour que la FAQ devienne un vrai bloc.
     */
    private static function prepareMarkdown(string $md, array &$warnings, bool $isBody, ?string &$faqTitle): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $md));
        $fence = false;
        $first = true;
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(```|~~~)/', $line)) {
                $fence = !$fence;
                $first = false;
                continue;
            }
            if ($fence || trim($line) === '') {
                continue;
            }
            if ($first && $isBody && preg_match('/^#\s+\S/', $line)) {
                unset($lines[$i]);
                $warnings[] = 'The leading "# ..." heading was removed: the title is already displayed as the H1.';
                $first = false;
                continue;
            }
            $first = false;
            if ($faqTitle === null && preg_match('/^##\s+(.+?)\s*#*\s*$/u', $line, $m) && self::isFaqHeading($m[1])) {
                $faqTitle = trim($m[1]);
                $lines[$i] = '## FAQ';
            }
        }
        return trim(implode("\n", $lines));
    }

    private static function isFaqHeading(string $text): bool
    {
        $t = Seo::fold(trim($text, " \t:*_"));
        return (bool) preg_match('/^(faq\b|f\.a\.q|foire aux questions|questions frequentes|questions et reponses|vos questions|frequently asked questions|preguntas frecuentes)/u', $t)
            || (bool) preg_match('/\(faq\)$/u', $t);
    }

    // ------------------------------------------------------------------
    // Résolution et présentation
    // ------------------------------------------------------------------

    /** Contenu désigné par id ou par url, lisible par l'utilisateur connecté. */
    public static function resolve(array $a): array
    {
        if (isset($a['id'])) {
            return self::byId((int) $a['id']);
        }
        if (trim((string) ($a['url'] ?? '')) !== '') {
            return self::byUrl((string) $a['url']);
        }
        throw new ToolError('Provide either id or url.');
    }

    public static function byId(int $id): array
    {
        $c = Content::find($id);
        if (!$c || !self::canRead($c)) {
            throw new ToolError('Content ' . $id . ' not found. Use search or list_content to find valid ids.');
        }
        return $c;
    }

    public static function byUrl(string $url): array
    {
        $path = self::pathFromUrl($url);
        if ($path === null) {
            throw new ToolError('This URL is not on this site (' . Config::baseUrl() . ').');
        }
        if (preg_match('#^' . preg_quote(Config::adminPath(), '#') . '/content/(\d+)#', $path, $m)) {
            return self::byId((int) $m[1]);
        }
        if ($path === '/') {
            $home = Settings::get('home.type') === 'page' ? (int) Settings::get('home.page_id') : 0;
            if ($home > 0) {
                return self::byId($home);
            }
            throw new ToolError('The home page lists the latest posts: it is not an editable content.');
        }
        $row = Db::one("SELECT * FROM content WHERE path = ? AND status <> 'trash' ORDER BY id DESC LIMIT 1", [$path]);
        if (!$row) {
            $r = Db::one('SELECT target FROM redirects WHERE source = ?', [$path]);
            if ($r && !preg_match('#^https?://#i', (string) $r['target'])) {
                $row = Db::one("SELECT * FROM content WHERE path = ? AND status <> 'trash' ORDER BY id DESC LIMIT 1", [Content::normalizePath((string) $r['target'])]);
            }
        }
        $c = $row ? Content::decode($row) : null;
        if (!$c || !self::canRead($c)) {
            throw new ToolError('No content found at ' . $path . '. Use search or list_content.');
        }
        return $c;
    }

    /** Chemin interne d'une adresse du site (null si l'adresse est ailleurs). */
    public static function pathFromUrl(string $url): ?string
    {
        $url = trim($url);
        if (preg_match('#^https?://#i', $url)) {
            $p = parse_url($url);
            $host = strtolower((string) ($p['host'] ?? ''));
            $site = strtolower((string) parse_url(Config::baseUrl(), PHP_URL_HOST));
            if ($host === '' || ($host !== $site && 'www.' . $host !== $site && $host !== 'www.' . $site)) {
                return null;
            }
            $path = (string) ($p['path'] ?? '/');
            $base = Config::basePath();
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base));
            }
        } else {
            $path = (string) (parse_url('http://x/' . ltrim($url, '/'), PHP_URL_PATH) ?? '/');
        }
        $path = rawurldecode($path);
        if (str_ends_with($path, '.md')) {
            $path = substr($path, 0, -3); // version Markdown d'une page
            if ($path === '/index') {
                $path = '/';
            }
        }
        return Content::normalizePath($path);
    }

    public static function canRead(array $c): bool
    {
        return Auth::can('edit_others') || (int) $c['author_id'] === (int) Auth::id() || Content::isVisible($c);
    }

    public static function summary(array $c): array
    {
        return [
            'id' => (int) $c['id'],
            'type' => (string) $c['type'],
            'status' => (string) $c['status'],
            'title' => (string) $c['title'],
            'url' => Content::permalink($c),
            'published_at' => self::iso($c['published_at']),
            'updated_at' => self::iso($c['updated_at']),
            'seo_score' => $c['seo_score'] !== null ? (int) $c['seo_score'] : null,
            'geo_score' => $c['geo_score'] !== null ? (int) $c['geo_score'] : null,
            'words' => (int) $c['word_count'],
        ];
    }

    public static function detail(array $c): array
    {
        $id = (int) $c['id'];
        $author = $c['author_id'] ? Db::one('SELECT id, name FROM users WHERE id = ?', [(int) $c['author_id']]) : null;
        $featured = $c['featured_id'] ? Media::find((int) $c['featured_id']) : null;
        $terms = static fn (string $tax): array => array_map(
            static fn (array $t): array => ['id' => (int) $t['id'], 'name' => (string) $t['name'], 'slug' => (string) $t['slug']],
            Terms::forContent($id, $tax)
        );
        return [
            'id' => $id,
            'type' => (string) $c['type'],
            'status' => (string) $c['status'],
            'title' => (string) $c['title'],
            'slug' => (string) $c['slug'],
            'path' => (string) $c['path'],
            'url' => Content::permalink($c),
            'admin_url' => self::adminUrl($id),
            'is_home' => Content::isHome($c),
            'excerpt' => (string) $c['excerpt'],
            'author' => $author ? ['id' => (int) $author['id'], 'name' => (string) $author['name']] : null,
            'parent_id' => $c['parent_id'] ? (int) $c['parent_id'] : null,
            'published_at' => self::iso($c['published_at']),
            'updated_at' => self::iso($c['updated_at']),
            'created_at' => self::iso($c['created_at']),
            'comments_enabled' => (bool) $c['comments'],
            'words' => (int) $c['word_count'],
            'seo' => [
                'title' => (string) $c['seo']['title'],
                'description' => (string) $c['seo']['description'],
                'keyword' => (string) $c['seo']['keyword'],
                'canonical' => (string) $c['seo']['canonical'],
                'noindex' => (bool) $c['seo']['noindex'],
                'schema' => (string) $c['seo']['schema'],
                'effective_title' => Seo::title($c),
                'effective_description' => Seo::description($c),
            ],
            'categories' => $terms('category'),
            'tags' => $terms('tag'),
            'featured_image' => $featured ? ['id' => (int) $featured['id'], 'url' => Media::absoluteUrl($featured), 'alt' => (string) $featured['alt']] : null,
            'scores' => [
                'seo' => $c['seo_score'] !== null ? (int) $c['seo_score'] : null,
                'geo' => $c['geo_score'] !== null ? (int) $c['geo_score'] : null,
            ],
        ];
    }

    /** Réponse après écriture : adresses, scores et pistes d'amélioration. */
    private static function saved(array $c, array $warnings): array
    {
        $an = Seo::analyze($c);
        $out = [
            'id' => (int) $c['id'],
            'type' => (string) $c['type'],
            'status' => (string) $c['status'],
            'title' => (string) $c['title'],
            'url' => Content::permalink($c),
            'admin_url' => self::adminUrl((int) $c['id']),
            'published_at' => self::iso($c['published_at']),
            'scores' => ['seo' => $an['seo']['score'], 'geo' => $an['geo']['score']],
            'suggestions' => self::suggestions($an, 6),
            'note' => match ((string) $c['status']) {
                'published' => 'Published: live at the public URL.',
                'scheduled' => 'Scheduled: it will go live on ' . self::iso($c['published_at']) . '.',
                'private' => 'Private: only visible to logged-in editors.',
                default => 'Saved as a draft: not visible on the site. Give the user the admin_url to review it; publish only when they ask (publish_content).',
            },
        ];
        if ($warnings) {
            $out['warnings'] = array_values(array_unique($warnings));
        }
        return $out;
    }

    /** Contrôles à corriger en priorité (mauvais d'abord, puis par importance). */
    public static function suggestions(array $analysis, int $max): array
    {
        $items = [];
        foreach (['seo' => 'SEO', 'geo' => 'GEO'] as $key => $label) {
            foreach ($analysis[$key]['checks'] as $ch) {
                if ($ch['status'] === 'good' || trim((string) $ch['hint']) === '') {
                    continue;
                }
                $items[] = ['rank' => ($ch['status'] === 'bad' ? 100 : 0) + 10 * (int) $ch['weight'], 'text' => $label . ' — ' . $ch['label'] . ' : ' . $ch['hint']];
            }
        }
        usort($items, static fn (array $x, array $y): int => $y['rank'] <=> $x['rank']);
        return array_slice(array_column($items, 'text'), 0, $max);
    }

    /** Médias utilisés par des blocs : id => {url, alt} (null si introuvable). */
    private static function mediaMap(array $blocks): array
    {
        $out = [];
        foreach (self::mediaIds($blocks) as $mid) {
            $m = Media::find($mid);
            $out[(string) $mid] = $m ? ['url' => Media::absoluteUrl($m), 'alt' => (string) $m['alt'], 'width' => $m['width'] !== null ? (int) $m['width'] : null, 'height' => $m['height'] !== null ? (int) $m['height'] : null] : null;
        }
        return $out === [] ? [] : $out;
    }

    /** @return list<int> */
    private static function mediaIds(array $blocks): array
    {
        $ids = [];
        $walk = static function (mixed $v) use (&$walk, &$ids): void {
            if (!is_array($v)) {
                return;
            }
            foreach ($v as $k => $item) {
                if (($k === 'media' || $k === 'poster') && is_numeric($item) && (int) $item > 0) {
                    $ids[] = (int) $item;
                } elseif ($k === 'medias' && is_array($item)) {
                    foreach ($item as $mid) {
                        if (is_numeric($mid) && (int) $mid > 0) {
                            $ids[] = (int) $mid;
                        }
                    }
                } else {
                    $walk($item);
                }
            }
        };
        foreach ($blocks as $b) {
            $walk($b['data'] ?? []);
        }
        return array_values(array_unique($ids));
    }

    /** Référence compacte des blocs (types et champs), générée depuis le registre. */
    public static function blockReference(): string
    {
        static $ref = null;
        if ($ref !== null) {
            return $ref;
        }
        $parts = [];
        foreach (Blocks::registry() as $type => $def) {
            $fields = [];
            foreach ((array) $def['fields'] as $name => $f) {
                $fields[] = self::fieldRef((string) $name, (array) $f);
            }
            $parts[] = $type . '{' . implode(', ', $fields) . '}';
        }
        return $ref = 'Block types: ' . implode('; ', $parts) . '. "html" fields take simple HTML (p, h2-h4, ul/ol/li, a, strong, em, blockquote, table); media fields take media IDs (see upload_media).';
    }

    private static function fieldRef(string $name, array $f): string
    {
        return match ($f['type'] ?? '') {
            'rich', 'code' => $name . ':html',
            'select' => $name . ':' . implode('|', array_map('strval', array_keys((array) $f['options']))),
            'media', 'term', 'number' => $name . ':int',
            'medias' => $name . ':int[]',
            'toggle' => $name . ':bool',
            'list' => $name . ':[{' . implode(', ', array_map(static fn ($n, $sf) => self::fieldRef((string) $n, (array) $sf), array_keys((array) $f['fields']), (array) $f['fields'])) . '}]',
            default => $name,
        };
    }

    public static function adminUrl(int $id): string
    {
        return Config::baseUrl() . Config::adminPath() . '/content/' . $id;
    }

    /** Date ISO 8601 dans le fuseau du site. */
    public static function iso(mixed $ts): ?string
    {
        if ($ts === null || $ts === '' || (int) $ts <= 0) {
            return null;
        }
        return (new \DateTimeImmutable('@' . (int) $ts))->setTimezone(self::tz())->format(DATE_ATOM);
    }

    /** Date envoyée par l'IA (ISO 8601 ; sans décalage = fuseau du site) => horodatage. */
    public static function parseDate(string $s): int
    {
        $s = trim($s);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?)?(Z|[+-]\d{2}:?\d{2})?$/i', $s)) {
            throw new ToolError('Invalid date "' . mb_substr($s, 0, 40) . '": use ISO 8601, e.g. 2026-12-01T09:00:00+01:00 (without offset = site timezone ' . self::tz()->getName() . ').');
        }
        try {
            $d = new \DateTimeImmutable($s, self::tz());
        } catch (\Exception) {
            throw new ToolError('Invalid date "' . mb_substr($s, 0, 40) . '".');
        }
        $errors = \DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            throw new ToolError('Invalid date "' . mb_substr($s, 0, 40) . '" (impossible calendar date).');
        }
        return $d->getTimestamp();
    }

    private static function tz(): \DateTimeZone
    {
        if (self::$tz === null) {
            try {
                self::$tz = new \DateTimeZone((string) Settings::get('timezone', 'Europe/Paris'));
            } catch (\Exception) {
                self::$tz = new \DateTimeZone('UTC');
            }
        }
        return self::$tz;
    }

    private static function log(string $label, array $c): void
    {
        Activity::log('mcp', (string) $c['type'], (int) $c['id'], $label);
    }
}
