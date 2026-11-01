<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Site public : routage des adresses (identiques à WordPress après migration),
 * fichiers SEO/GEO, archives, recherche, flux, 404.
 */
final class Front
{
    public static function handle(string $path): void
    {
        Content::publishDue();
        if (Cache::enabled() && Cache::expired()) {
            Cache::purge();
        }
        $method = Http::method();
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            http_response_code(405);
            header('Allow: GET, HEAD');
            return;
        }

        if (self::special($path)) {
            return;
        }
        if (self::legacy($path)) {
            return;
        }
        // Redirections d'abord : une ancienne adresse va à sa cible en un seul saut.
        $r = Redirects::match($path, Http::query());
        if ($r) {
            if ((int) $r['code'] === 410) {
                self::send(self::render404(), 'html', 410, null);
                return;
            }
            $target = preg_match('#^https?://#', $r['target']) ? $r['target'] : href($r['target']);
            redirect($target, (int) $r['code']);
        }
        // Adresse canonique : avec ou sans barre finale selon le réglage du site.
        if ($path !== '/' && !preg_match('#\.[a-z0-9]{2,5}$#i', $path)) {
            $wantSlash = (bool) Settings::get('trailing_slash', true);
            if ($wantSlash !== str_ends_with($path, '/')) {
                $q = Http::query();
                redirect(Config::basePath() . link_path($path) . ($q !== '' ? '?' . $q : ''), 301);
            }
        }
        if (str_ends_with($path, '.md') && Settings::get('geo.markdown', true)) {
            self::markdown(substr($path, 0, -3));
            return;
        }
        $norm = Content::normalizePath($path);
        if (preg_match('#^(.*?)/page/(\d+)$#', $norm, $m)) {
            $page = max(1, (int) $m[2]);
            $base = $m[1] === '' ? '/' : $m[1];
        } else {
            $page = 1;
            $base = $norm;
        }

        if ($base === '/') {
            self::home($page);
            return;
        }
        if ($page === 1) {
            $c = Content::byPath($base);
            if ($c) {
                if (Content::isHome($c)) {
                    redirect(href('/'), 301);
                }
                if ((int) Settings::get('blog.page_id') === (int) $c['id']) {
                    self::blog($c, 1);
                    return;
                }
                self::single($c);
                return;
            }
        } else {
            $c = Content::byPath($base);
            if ($c && (int) Settings::get('blog.page_id') === (int) $c['id']) {
                self::blog($c, $page);
                return;
            }
        }
        if (preg_match('#^/(category|tag)/(.+)$#', $base, $m)) {
            $slug = basename($m[2]);
            $term = Terms::bySlug($m[1], $slug);
            if ($term) {
                self::termArchive($term, $page);
                return;
            }
        }
        if (preg_match('#^/author/([^/]+)$#', $base, $m)) {
            $author = Db::one("SELECT * FROM users WHERE slug = ? AND status <> 'disabled'", [$m[1]]);
            if ($author) {
                self::authorArchive($author, $page);
                return;
            }
        }
        self::notFound($path);
    }

    /** Fichiers techniques : robots, sitemaps, llms.txt, flux, recherche, clé IndexNow. */
    private static function special(string $path): bool
    {
        switch ($path) {
            case '/robots.txt':
                self::send(Seo::robots(), 'txt', 200, $path);
                return true;
            case '/sitemap.xml':
                self::send(Seo::sitemapIndex(), 'xml', 200, $path);
                return true;
            case '/sitemap_index.xml':
            case '/wp-sitemap.xml':
            case '/sitemap-index.xml':
                redirect(href('/sitemap.xml'), 301);
            case '/llms.txt':
            case '/llms-full.txt':
                if (!Settings::get('geo.llms_txt', true)) {
                    return false;
                }
                self::send(Seo::llmsTxt($path === '/llms-full.txt'), 'txt', 200, $path);
                return true;
            case '/feed':
            case '/feed/':
            case '/rss':
            case '/rss.xml':
            case '/feed.xml':
                if ($path !== '/feed/') {
                    redirect(href('/feed/'), 301);
                }
                self::send(Feed::rss(), 'rss', 200, $path);
                return true;
            case '/search':
            case '/search/':
                self::search();
                return true;
        }
        if (preg_match('#^/sitemap-(posts|pages|taxonomies)\.xml$#', $path, $m)) {
            $xml = Seo::sitemap($m[1]);
            if ($xml !== null) {
                self::send($xml, 'xml', 200, $path);
                return true;
            }
        }
        if (preg_match('#^/(category|tag)/([^/]+)/feed/?$#', $path, $m)) {
            $term = Terms::bySlug($m[1], $m[2]);
            if ($term) {
                self::send(Feed::rss($term), 'rss', 200, '/' . $m[1] . '/' . $m[2] . '/feed/');
                return true;
            }
        }
        if (preg_match('#^/([a-f0-9]{32})\.txt$#', $path, $m) && hash_equals(IndexNow::key(), $m[1])) {
            self::send($m[1], 'txt', 200, null);
            return true;
        }
        return false;
    }

    /** Adresses héritées de WordPress : ?p=, ?page_id=, /wp-content/uploads… */
    private static function legacy(string $path): bool
    {
        if (preg_match('#^/(wp-login\.php|xmlrpc\.php|wp-cron\.php|wp-json(/.*)?|wp-includes/.*|wp-signup\.php|wp-trackback\.php)$#', $path)) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found';
            return true;
        }
        if ($path === '/wp-admin' || str_starts_with($path, '/wp-admin/')) {
            redirect(admin_url(), 302);
        }
        // Anciennes images : /wp-content/uploads/… (aussi sous un sous-dossier ou en multisite).
        if (preg_match('#^(?:/[^/]+)*?/wp-content/uploads/(?:sites/\d+/)?(.+)$#', $path, $m)) {
            $rel = $m[1];
            if (!str_contains($rel, '..')) {
                if (!is_file(WL_MEDIA . '/' . $rel)) {
                    // Variantes créées par WordPress : -300x200, -scaled, -rotated, -e1700000000000 (image retouchée).
                    $a = preg_replace('#-\d+x\d+(\.[a-z0-9]+)$#i', '$1', $rel) ?? $rel;
                    $b = preg_replace('#-(?:scaled|rotated)(\.[a-z0-9]+)$#i', '$1', $a) ?? $a;
                    $c = preg_replace('#-e\d{13}(\.[a-z0-9]+)$#i', '$1', $b) ?? $b;
                    foreach ([$a, $b, $c] as $cand) {
                        if (is_file(WL_MEDIA . '/' . $cand)) {
                            $rel = $cand;
                            break;
                        }
                    }
                }
                if (is_file(WL_MEDIA . '/' . $rel)) {
                    redirect(media_url($rel), 301);
                }
            }
            return false;
        }
        if ($path === '/' && $_GET) {
            if (isset($_GET['s']) && is_string($_GET['s'])) {
                redirect(href('/search') . '?q=' . rawurlencode($_GET['s']), 301);
            }
            foreach (['p', 'page_id', 'attachment_id'] as $k) {
                if (isset($_GET[$k]) && is_numeric($_GET[$k])) {
                    $c = Content::byWpId((int) $_GET[$k]);
                    if ($c && Content::isVisible($c)) {
                        redirect(Content::href($c), 301);
                    }
                }
            }
            if (isset($_GET['cat']) && is_numeric($_GET['cat'])) {
                $t = Db::one("SELECT * FROM terms WHERE wp_id = ? AND taxonomy = 'category'", [(int) $_GET['cat']]);
                if ($t) {
                    redirect(Terms::href($t), 301);
                }
            }
            if (isset($_GET['feed'])) {
                redirect(href('/feed/'), 301);
            }
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    private static function home(int $page): void
    {
        if (Settings::get('home.type') === 'page' && $page === 1) {
            $c = Content::find((int) Settings::get('home.page_id'));
            if ($c && Content::isVisible($c)) {
                self::single($c);
                return;
            }
        }
        $site = (string) Settings::get('site.name');
        self::postList(
            '/',
            $page,
            ['type' => 'post', 'visible' => true, 'sticky_first' => $page === 1],
            [
                'title' => (string) (Settings::get('seo.home_title') ?: $site . (Settings::get('site.tagline') ? ' ' . Settings::get('seo.separator', '–') . ' ' . Settings::get('site.tagline') : '')),
                'heading' => $site,
                'description' => (string) (Settings::get('seo.home_description') ?: Settings::get('site.tagline', '')),
                'template' => 'home',
            ]
        );
    }

    private static function blog(array $c, int $page): void
    {
        self::postList((string) $c['path'], $page, ['type' => 'post', 'visible' => true, 'sticky_first' => $page === 1], [
            'title' => Seo::title($c), 'heading' => $c['title'], 'description' => Seo::description($c), 'template' => 'home', 'content' => $c,
        ]);
    }

    private static function termArchive(array $term, int $page): void
    {
        $label = $term['taxonomy'] === 'category' ? __('Catégorie') : __('Étiquette');
        $seo = json_dec((string) $term['seo'], []);
        self::postList(($term['taxonomy'] === 'category' ? '/category/' : '/tag/') . $term['slug'], $page, ['type' => 'post', 'visible' => true, 'term' => (int) $term['id']], [
            'title' => (string) (($seo['title'] ?? '') ?: Seo::applyTemplate($term['name'])),
            'heading' => $term['name'], 'kicker' => $label,
            'description' => (string) (($seo['description'] ?? '') ?: ($term['description'] !== '' ? excerpt(plain_text($term['description']), 158) : __('{label} « {name} » : tous les articles.', ['label' => $label, 'name' => $term['name']]))),
            'intro' => $term['description'], 'template' => 'archive', 'term' => $term,
        ]);
    }

    private static function authorArchive(array $author, int $page): void
    {
        self::postList('/author/' . $author['slug'], $page, ['type' => 'post', 'visible' => true, 'author' => (int) $author['id']], [
            'title' => Seo::applyTemplate($author['name']), 'heading' => $author['name'],
            'description' => $author['bio'] !== '' ? excerpt($author['bio'], 158) : __('Articles de {n}', ['n' => $author['name']]),
            'intro' => $author['bio'], 'template' => 'archive', 'author' => $author, 'page_type' => 'ProfilePage',
        ]);
    }

    private static function postList(string $base, int $page, array $q, array $o): void
    {
        $per = max(1, (int) Settings::get('blog.per_page', 10));
        $total = Content::count($q);
        $pages = max(1, (int) ceil($total / $per));
        if ($page > $pages) {
            self::notFound(Http::path());
            return;
        }
        $posts = Content::query($q + ['limit' => $per, 'offset' => ($page - 1) * $per]);
        $pageUrl = static fn (int $n): string => $n <= 1 ? url($base) : url(rtrim($base, '/') . '/page/' . $n);
        $canonical = $base === '/' && $page === 1 ? Config::baseUrl() . '/' : $pageUrl($page);
        $title = $o['title'] . ($page > 1 ? ' ' . Settings::get('seo.separator', '–') . ' ' . __('Page {n}', ['n' => $page]) : '');
        $crumbs = [[__('Accueil'), Config::baseUrl() . '/']];
        if ($base !== '/') {
            $crumbs[] = [$o['heading'], url($base)];
        }
        $seo = [
            'title' => $title, 'description' => $o['description'], 'canonical' => $canonical, 'type' => 'website',
            'prev' => $page > 1 ? $pageUrl($page - 1) : null, 'next' => $page < $pages ? $pageUrl($page + 1) : null,
            'jsonld' => ['url' => $canonical, 'title' => $title, 'description' => $o['description'], 'crumbs' => $crumbs, 'page_type' => $o['page_type'] ?? 'CollectionPage'],
        ];
        $html = Theme::page($o['template'], [
            'posts' => $posts, 'heading' => $o['heading'], 'kicker' => $o['kicker'] ?? '', 'intro' => $o['intro'] ?? '',
            'pagination' => ['current' => $page, 'total' => $pages, 'url' => $pageUrl], 'term' => $o['term'] ?? null,
            'author' => $o['author'] ?? null, 'content' => $o['content'] ?? null, 'crumbs' => $crumbs, 'is_home' => $base === '/',
        ], $seo);
        self::send($html, 'html', 200, Http::path());
    }

    public static function single(array $c, bool $preview = false): void
    {
        $isPost = $c['type'] === 'post';
        $canonical = $c['seo']['canonical'] !== '' ? $c['seo']['canonical'] : Content::permalink($c);
        $imgId = $c['seo']['og_image'] ?: ($c['featured_id'] ?: Blocks::firstImage($c['blocks']));
        $img = Media::find($imgId ? (int) $imgId : null);
        $cats = Terms::forContent((int) $c['id'], 'category');
        $tags = Terms::forContent((int) $c['id'], 'tag');
        $crumbs = [[__('Accueil'), Config::baseUrl() . '/']];
        if ($isPost && $cats) {
            $crumbs[] = [$cats[0]['name'], Terms::url($cats[0])];
        } elseif (!$isPost && $c['parent_id']) {
            $chain = [];
            $pid = (int) $c['parent_id'];
            for ($i = 0; $pid && $i < 5; $i++) {
                $p = Content::find($pid);
                if (!$p) {
                    break;
                }
                array_unshift($chain, [$p['title'], Content::permalink($p)]);
                $pid = (int) $p['parent_id'];
            }
            array_push($crumbs, ...$chain);
        }
        if (!Content::isHome($c)) {
            $crumbs[] = [$c['title'], Content::permalink($c)];
        }
        $title = Seo::title($c);
        $desc = Seo::description($c);
        $alternates = [];
        if (!empty($c['tgroup'])) {
            foreach (Db::all("SELECT * FROM content WHERE tgroup = ? AND status = 'published' AND lang <> ''", [$c['tgroup']]) as $tr) {
                $alternates[$tr['lang']] = Content::permalink(Content::decode($tr));
            }
        }
        $mdUrl = Content::isHome($c) ? Config::baseUrl() . '/index.md' : rtrim(Content::permalink($c), '/') . '.md';
        $seo = [
            'title' => $title, 'description' => $desc, 'canonical' => $canonical, 'noindex' => $preview || !empty($c['seo']['noindex']),
            'type' => $isPost ? 'article' : 'website', 'image' => $img, 'published' => $c['published_at'], 'modified' => $c['updated_at'],
            'section' => $cats[0]['name'] ?? '', 'tags' => array_column($tags, 'name'), 'alternates' => count($alternates) > 1 ? $alternates : [],
            'markdown' => Settings::get('geo.markdown', true) ? $mdUrl : null,
            'jsonld' => ['url' => $canonical, 'title' => $c['title'], 'description' => $desc, 'content' => $c, 'crumbs' => $crumbs, 'image' => $img],
        ];
        $author = $c['author_id'] ? Db::one('SELECT * FROM users WHERE id = ?', [(int) $c['author_id']]) : null;
        $template = $c['template'] !== '' && is_file(Theme::template($c['template'])) ? $c['template'] : ($isPost ? 'single' : 'page');
        $html = Theme::page($template, [
            'content' => $c, 'author' => $author, 'categories' => $cats, 'tags' => $tags, 'crumbs' => $crumbs,
            'featured' => $isPost ? Media::find($c['featured_id'] ? (int) $c['featured_id'] : null) : null,
            'reading_minutes' => max(1, (int) round((int) $c['word_count'] / 220)), 'preview' => $preview,
        ], $seo);
        $hasCode = in_array('html', array_column($c['blocks'], 'type'), true);
        $csp = $hasCode ? "Content-Security-Policy: frame-ancestors 'self'; base-uri 'self'; object-src 'none'" : null;
        self::send($html, 'html', 200, $preview ? null : Http::path(), $csp);
    }

    private static function markdown(string $path): void
    {
        $c = $path === '/index' || $path === '' ? Content::find((int) Settings::get('home.page_id')) : Content::byPath($path);
        if (!$c || !Content::isVisible($c) || !empty($c['seo']['noindex'])) {
            self::notFound($path . '.md');
            return;
        }
        $author = $c['author_id'] ? Db::val('SELECT name FROM users WHERE id = ?', [(int) $c['author_id']]) : '';
        $md = '# ' . $c['title'] . "\n\n"
            . '> ' . Seo::description($c) . "\n\n"
            . '- ' . __('Source : {v}', ['v' => Content::permalink($c)]) . "\n"
            . ($author ? '- ' . __('Auteur : {v}', ['v' => $author]) . "\n" : '')
            . '- ' . __('Publié le : {v}', ['v' => gmdate('Y-m-d', (int) $c['published_at'])]) . "\n"
            . '- ' . __('Mis à jour le : {v}', ['v' => gmdate('Y-m-d', (int) $c['updated_at'])]) . "\n\n"
            . Blocks::toMarkdown($c['blocks']) . "\n";
        header('Link: <' . Content::permalink($c) . '>; rel="canonical"');
        self::send($md, 'md', 200, $path === '' ? '/index.md' : $path . '.md');
    }

    private static function search(): void
    {
        $q = trim((string) ($_GET['q'] ?? ''));
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $per = 10;
        $res = $q !== '' ? Search::query($q, $per, ($page - 1) * $per) : ['total' => 0, 'items' => []];
        $title = $q !== '' ? __('Résultats pour « {q} »', ['q' => $q]) : __('Rechercher');
        $html = Theme::page('search', [
            'query' => $q, 'results' => $res['items'], 'total' => $res['total'], 'page' => $page, 'pages' => (int) ceil($res['total'] / $per),
        ], ['title' => Seo::applyTemplate($title), 'description' => '', 'canonical' => url('/search'), 'noindex' => true, 'jsonld' => ['url' => url('/search'), 'title' => $title, 'page_type' => 'SearchResultsPage']]);
        self::send($html, 'html', 200, null);
    }

    public static function notFound(string $path): void
    {
        Redirects::log404($path);
        self::send(self::render404($path), 'html', 404, null);
    }

    private static function render404(string $path = ''): string
    {
        $suggest = $path !== '' ? Redirects::suggest($path) : null;
        return Theme::page('404', ['suggestion' => $suggest, 'path' => $path], [
            'title' => Seo::applyTemplate(__('Page introuvable')), 'description' => '', 'canonical' => '', 'noindex' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // Réponse
    // ------------------------------------------------------------------

    public static function send(string $body, string $type, int $status, ?string $cachePath, ?string $csp = null): void
    {
        $types = [
            'html' => 'text/html; charset=utf-8', 'xml' => 'application/xml; charset=utf-8', 'rss' => 'application/rss+xml; charset=utf-8',
            'txt' => 'text/plain; charset=utf-8', 'md' => 'text/markdown; charset=utf-8',
        ];
        http_response_code($status);
        header('Content-Type: ' . $types[$type]);
        $headers = $type === 'html' ? Http::securityHeaders('front', Theme::styleHash()) : ['X-Content-Type-Options: nosniff'];
        if ($csp !== null) {
            $headers = array_map(static fn ($h) => str_starts_with($h, 'Content-Security-Policy:') ? $csp : $h, $headers);
        }
        if ($type === 'md') {
            $headers[] = 'X-Robots-Tag: noindex';
        }
        Http::sendHeaders($headers);
        $logged = Auth::check();
        header('Cache-Control: ' . ($logged || $status !== 200 ? 'no-store' : 'public, max-age=0, must-revalidate'));
        if ($status === 200 && $cachePath !== null && !$logged) {
            Cache::store($cachePath, $body, $csp);
            header('X-Wordless-Cache: MISS');
        }
        if (Http::method() !== 'HEAD') {
            echo $body;
        }
        if (in_array($type, ['html', 'md', 'txt'], true) && $status === 200 && !$logged && Settings::get('stats.enabled', true)) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            require_once WL_CORE . '/hit.php';
            wl_track_hit(Http::path(), $type !== 'html');
        }
    }
}
