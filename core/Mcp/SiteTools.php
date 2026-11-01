<?php
declare(strict_types=1);

namespace Wordless\Mcp;

use Wordless\Activity;
use Wordless\Analytics;
use Wordless\Auth;
use Wordless\Cache;
use Wordless\Color;
use Wordless\Comments;
use Wordless\Config;
use Wordless\Content;
use Wordless\Db;
use Wordless\Media;
use Wordless\Redirects;
use Wordless\Seo;
use Wordless\Settings;
use Wordless\Terms;
use Wordless\Theme;

/**
 * Outils MCP sur le site : vue d'ensemble, statistiques, médias, catégories,
 * redirections, commentaires, design (jetons et contrastes WCAG) et réglages.
 */
final class SiteTools
{
    /** Robots d'IA dont le blocage empêche d'être cité par les assistants. */
    private const KEY_AI_BOTS = ['GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot', 'Google-Extended'];

    // ------------------------------------------------------------------
    // Vue d'ensemble
    // ------------------------------------------------------------------

    public static function overview(array $a): array
    {
        $user = Auth::user() ?? [];
        $report = Seo::siteReport();
        $counts = ['post' => [], 'page' => []];
        foreach (Db::all('SELECT type, status, COUNT(*) AS n FROM content GROUP BY type, status') as $r) {
            $counts[$r['type']][$r['status']] = (int) $r['n'];
        }
        $pending = (int) Db::val("SELECT COUNT(*) FROM comments WHERE status = 'pending'");
        $out = [
            'site' => [
                'name' => (string) Settings::get('site.name'),
                'tagline' => (string) Settings::get('site.tagline', ''),
                'url' => Config::baseUrl() . '/',
                'admin_url' => Config::baseUrl() . Config::adminPath(),
                'language' => (string) Settings::get('language', 'fr'),
                'timezone' => (string) Settings::get('timezone', 'Europe/Paris'),
                'home' => Settings::get('home.type') === 'page' ? 'static page (id ' . (int) Settings::get('home.page_id') . ')' : 'latest posts',
                'brand_voice' => trim((string) Settings::get('ai.brand_voice', '')) !== '' ? 'defined (see wordless://site/guidelines)' : 'not defined',
            ],
            'connected_as' => [
                'name' => (string) ($user['name'] ?? ''),
                'role' => (string) ($user['role'] ?? ''),
                'tools' => array_column(Tools::listing(), 'name'),
            ],
            'counts' => [
                'posts' => self::statusCounts($counts['post']),
                'pages' => self::statusCounts($counts['page']),
                'media' => (int) Db::val('SELECT COUNT(*) FROM media'),
                'categories' => (int) Db::val("SELECT COUNT(*) FROM terms WHERE taxonomy = 'category'"),
                'tags' => (int) Db::val("SELECT COUNT(*) FROM terms WHERE taxonomy = 'tag'"),
                'comments_pending' => $pending,
                'redirects' => (int) Db::val('SELECT COUNT(*) FROM redirects'),
            ],
            'seo_geo' => [
                'published_content' => (int) $report['total'],
                'average_seo_score' => (int) $report['avgSeo'],
                'average_geo_score' => (int) $report['avgGeo'],
                'weak_seo_pages' => (int) $report['weak'],
                'missing_meta_description' => (int) $report['noDesc'],
                'images_without_alt' => (int) $report['altMissing'],
                'posts_not_updated_for_a_year' => (int) $report['stale'],
                'noindex_pages' => (int) $report['noindex'],
                'pages_with_faq' => (int) $report['faqPages'],
                'not_found_urls_30_days' => (int) $report['notFound'],
                'blocked_ai_crawlers' => self::blockedAiCrawlers(),
            ],
        ];
        if (Auth::can('view_stats')) {
            $s = Analytics::summary(30);
            [$ai, $search] = self::splitBots($s['bots']);
            $out['traffic_30_days'] = [
                'views' => (int) $s['views'],
                'visitors' => (int) $s['visitors'],
                'visitors_change_percent' => self::change((int) $s['visitors'], (int) $s['prev_visitors']),
                'channels' => $s['channels'],
                'ai_assistant_referrals' => $s['sources']['ai'] ?? [],
                'ai_crawler_visits' => $ai,
                'search_engine_crawler_visits' => $search,
                'top_pages' => self::topPages(array_slice($s['top'], 0, 5)),
            ];
        }
        $out['issues'] = self::issues($report, $pending);
        $recent = Content::query(['status' => 'any', 'order' => 'updated', 'limit' => 6] + (Auth::can('edit_others') ? [] : ['author' => (int) Auth::id()]));
        $out['recently_updated'] = array_map([ContentTools::class, 'summary'], $recent);
        return $out;
    }

    private static function statusCounts(array $byStatus): array
    {
        return [
            'published' => (int) ($byStatus['published'] ?? 0),
            'draft' => (int) ($byStatus['draft'] ?? 0),
            'scheduled' => (int) ($byStatus['scheduled'] ?? 0),
            'private' => (int) ($byStatus['private'] ?? 0),
            'trash' => (int) ($byStatus['trash'] ?? 0),
        ];
    }

    /** Problèmes à traiter, du plus grave au moins grave, avec l'outil pour les corriger. */
    private static function issues(array $report, int $pendingComments): array
    {
        $issues = [];
        $add = static function (string $severity, string $issue, string $fix, array $items = []) use (&$issues): void {
            $issues[] = ['severity' => $severity, 'issue' => $issue, 'fix' => $fix] + ($items ? ['items' => $items] : []);
        };
        if (Settings::get('seo.noindex_site', false)) {
            $add('critical', 'The whole site is hidden from search engines (noindex option enabled in the SEO settings).', 'Ask an administrator to disable it in the admin (SEO & GEO settings) when the site is ready.');
        }
        $blocked = array_values(array_intersect(self::blockedAiCrawlers(), self::KEY_AI_BOTS));
        if ($blocked) {
            $add('high', 'AI crawlers blocked in robots.txt: ' . implode(', ', $blocked) . '. The site cannot be cited by these assistants.', 'update_site_settings ai_crawlers (administrators).');
        }
        if ((int) $report['weak'] > 0) {
            $weak = Db::all("SELECT id, title, type, seo_score, geo_score FROM content WHERE status = 'published' AND COALESCE(seo_score, 0) < 60 ORDER BY COALESCE(seo_score, 0) ASC LIMIT 5");
            $add('high', $report['weak'] . ' published page(s) with an SEO score below 60/100.', 'analyze_content, then update_content.',
                array_map(static fn ($r) => ['id' => (int) $r['id'], 'title' => (string) $r['title'], 'seo_score' => (int) $r['seo_score'], 'geo_score' => (int) $r['geo_score']], $weak));
        }
        if ((int) $report['notFound'] > 0) {
            $nf = Db::all('SELECT path, hits FROM not_found WHERE last_at > ? ORDER BY hits DESC LIMIT 5', [time() - 30 * 86400]);
            $add('high', $report['notFound'] . ' URL(s) returned "404 not found" in the last 30 days.', 'manage_redirects action "add" (301 to the most relevant page).',
                array_map(static fn ($r) => ['path' => (string) $r['path'], 'hits' => (int) $r['hits']], $nf));
        }
        if ((int) $report['noDesc'] > 0) {
            $add('medium', $report['noDesc'] . ' published page(s) without meta description.', 'list_content missing_description=true, then update_content seo.description (120-160 characters).');
        }
        if ((int) $report['altMissing'] > 0) {
            $add('medium', $report['altMissing'] . ' image(s) without alt text.', 'list_media missing_alt=true, then update_media alt.');
        }
        if ((int) $report['total'] > 0 && (int) $report['avgGeo'] < 60) {
            $add('medium', 'Average GEO score ' . $report['avgGeo'] . '/100: content is rarely structured to be cited by AI assistants (' . $report['faqPages'] . ' page(s) with a FAQ).', 'Add answer-first intros, question headings, FAQ sections, sourced statistics (update_content append_markdown).');
        }
        if ((int) $report['stale'] > 0) {
            $add('low', $report['stale'] . ' post(s) not updated for more than a year.', 'Refresh facts, figures and dates with update_content.');
        }
        if ($pendingComments > 0) {
            $add('low', $pendingComments . ' comment(s) waiting for moderation.', 'list_comments, then moderate_comment.');
        }
        if (trim((string) Settings::get('seo.home_description', '')) === '' && trim((string) Settings::get('site.tagline', '')) === '') {
            $add('low', 'No home page description nor tagline: search engines and llms.txt have no summary of the site.', 'update_site_settings home_description (administrators).');
        }
        if (trim((string) Settings::get('ai.brand_voice', '')) === '') {
            $add('info', 'No brand voice defined for AI writing.', 'Ask the user about their audience and tone, or set it in the admin (AI settings).');
        }
        return $issues;
    }

    private static function blockedAiCrawlers(): array
    {
        return array_keys(array_filter((array) Settings::get('geo.ai_crawlers', []), static fn ($v) => !$v));
    }

    // ------------------------------------------------------------------
    // Statistiques
    // ------------------------------------------------------------------

    public static function analytics(array $a): array
    {
        $days = (int) ($a['days'] ?? 30);
        $s = Analytics::summary($days);
        [$ai, $search] = self::splitBots($s['bots']);
        $sources = $s['sources'];
        $devices = [];
        foreach ($sources['device'] ?? [] as $d) {
            $devices[['d' => 'desktop', 'm' => 'mobile', 't' => 'tablet'][$d['source']] ?? $d['source']] = (int) $d['views'];
        }
        unset($sources['device'], $sources['bot']);
        $daily = [];
        foreach ($s['series'] as $day => $v) {
            $daily[] = ['date' => (string) $day, 'views' => (int) $v['views'], 'visitors' => (int) $v['visitors']];
        }
        return [
            'period' => ['days' => $days, 'from' => $daily[0]['date'] ?? null, 'to' => $daily[count($daily) - 1]['date'] ?? null],
            'views' => (int) $s['views'],
            'visitors' => (int) $s['visitors'],
            'previous_period' => ['views' => (int) $s['prev_views'], 'visitors' => (int) $s['prev_visitors']],
            'change_percent' => ['views' => self::change((int) $s['views'], (int) $s['prev_views']), 'visitors' => self::change((int) $s['visitors'], (int) $s['prev_visitors'])],
            'channels' => $s['channels'],
            'top_pages' => self::topPages($s['top']),
            'referrers' => $sources,
            'ai_assistant_referrals' => $s['sources']['ai'] ?? [],
            'ai_crawler_visits' => $ai,
            'search_engine_crawler_visits' => $search,
            'devices' => $devices,
            'daily' => $daily,
            'notes' => 'Cookie-less statistics: visitors are counted once per day; channels count visitors\' first visit of the day. Bot visits are not counted as views. Data is aggregated about every 10 minutes.',
        ];
    }

    /** @return array{0: list<array>, 1: list<array>} [robots d'IA, moteurs de recherche] */
    private static function splitBots(array $bots): array
    {
        require_once WL_CORE . '/hit.php';
        $aiNames = function_exists('wl_ai_bots') ? wl_ai_bots() : [];
        $ai = [];
        $search = [];
        foreach ($bots as $b) {
            $row = ['bot' => (string) $b['source'], 'visits' => (int) $b['views']];
            if (in_array($b['source'], $aiNames, true)) {
                $ai[] = $row;
            } else {
                $search[] = $row;
            }
        }
        return [$ai, $search];
    }

    private static function topPages(array $top): array
    {
        $out = [];
        foreach ($top as $t) {
            $path = (string) $t['path'];
            $title = Db::val("SELECT title FROM content WHERE path = ? AND status = 'published'", [Content::normalizePath($path)]);
            $out[] = ['path' => $path, 'title' => $title !== null ? (string) $title : null, 'views' => (int) $t['views']];
        }
        return $out;
    }

    private static function change(int $now, int $before): ?float
    {
        return $before > 0 ? round(($now - $before) / $before * 100, 1) : null;
    }

    // ------------------------------------------------------------------
    // Médias
    // ------------------------------------------------------------------

    public static function listMedia(array $a): array
    {
        $q = [
            'q' => trim((string) ($a['search'] ?? '')),
            'type' => ($a['type'] ?? 'any') === 'any' ? '' : (string) $a['type'],
            'missing_alt' => !empty($a['missing_alt']),
            'limit' => (int) ($a['limit'] ?? 30),
            'offset' => (int) ($a['offset'] ?? 0),
        ];
        $items = array_map([self::class, 'mediaInfo'], Media::list($q));
        return ['offset' => $q['offset'], 'count' => count($items), 'items' => $items];
    }

    public static function uploadMedia(array $a): array
    {
        Tools::need('upload');
        $before = (int) Db::val('SELECT COALESCE(MAX(id), 0) FROM media');
        $meta = array_intersect_key($a, ['alt' => 1, 'title' => 1, 'caption' => 1]);
        $m = self::import((string) $a['url'], $meta);
        $existing = (int) $m['id'] <= $before;
        if (!$existing) {
            Activity::log('mcp', 'media', (int) $m['id'], __('Média ajouté : {t}', ['t' => (string) $m['title']]));
        }
        $info = self::mediaInfo($m);
        return ['existing' => $existing] + $info + [
            'markdown' => Media::isImage($m) ? '![' . str_replace(['[', ']'], '', (string) $m['alt']) . '](' . $info['url'] . ')' : '[' . str_replace(['[', ']'], '', (string) $m['title']) . '](' . $info['url'] . ')',
            'note' => $existing
                ? 'This file was already in the media library: the existing item is returned (empty texts were filled, existing ones kept; use update_media to change them).'
                : 'Imported. Use the id in image blocks or featured_media_id, or the markdown snippet in a body.',
        ];
    }

    /**
     * Importe une adresse (anti-SSRF dans HttpClient) et complète alt / titre /
     * légende si fournis. Les erreurs deviennent des ToolError lisibles.
     */
    public static function import(string $url, array $meta): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            throw new ToolError('Only public http(s) URLs can be imported.');
        }
        // Caractères non ASCII ou espaces : encodés comme le ferait un navigateur.
        $url = (string) preg_replace_callback('/[^\x21-\x7e]+/', static fn (array $m): string => rawurlencode($m[0]), $url);
        try {
            $m = Media::importUrl($url, array_filter($meta, static fn ($v) => $v !== null && $v !== ''));
        } catch (\PDOException $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            throw new ToolError('Import failed: ' . $e->getMessage());
        }
        // Fichier déjà présent (même adresse ou même contenu) : on complète seulement les textes vides
        // (un texte alternatif soigné n'est jamais écrasé ; update_media sert à le changer).
        $changes = [];
        foreach (['alt' => 250, 'title' => 200, 'caption' => 500] as $k => $max) {
            $v = isset($meta[$k]) ? mb_substr(trim(strip_tags((string) $meta[$k])), 0, $max) : '';
            if ($v !== '' && trim((string) ($m[$k] ?? '')) === '') {
                $changes[$k] = $v;
            }
        }
        if ($changes && self::canEditMedia($m)) {
            Db::update('media', $changes, ['id' => (int) $m['id']]);
            $m = array_merge($m, $changes);
        }
        return $m;
    }

    public static function updateMedia(array $a): array
    {
        $row = Db::one('SELECT * FROM media WHERE id = ?', [(int) $a['id']]);
        if (!$row) {
            throw new ToolError('Media ' . (int) $a['id'] . ' not found (use list_media).');
        }
        $m = Media::decode($row);
        if (!self::canEditMedia($m)) {
            throw new ToolError('Permission denied: you can only edit the media you uploaded.');
        }
        $changes = [];
        foreach (['alt' => 250, 'title' => 200, 'caption' => 500] as $k => $max) {
            if (isset($a[$k])) {
                $changes[$k] = mb_substr(trim(strip_tags((string) $a[$k])), 0, $max);
            }
        }
        if (!$changes) {
            throw new ToolError('Nothing to update: give alt, title or caption.');
        }
        Db::update('media', $changes, ['id' => (int) $m['id']]);
        Cache::purge();
        Activity::log('mcp', 'media', (int) $m['id'], __('Média modifié : {t} ({f})', ['t' => (string) ($changes['title'] ?? $m['title']), 'f' => implode(', ', array_keys($changes))]));
        return ['updated' => array_keys($changes)] + self::mediaInfo(array_merge($m, $changes));
    }

    private static function canEditMedia(array $m): bool
    {
        return Auth::can('upload') && (Auth::can('edit_others') || (int) ($m['user_id'] ?? 0) === (int) Auth::id());
    }

    public static function mediaInfo(array $m): array
    {
        return [
            'id' => (int) $m['id'],
            'url' => Media::absoluteUrl($m),
            'title' => (string) $m['title'],
            'alt' => (string) $m['alt'],
            'caption' => (string) $m['caption'],
            'mime' => (string) $m['mime'],
            'width' => $m['width'] !== null ? (int) $m['width'] : null,
            'height' => $m['height'] !== null ? (int) $m['height'] : null,
            'size' => (int) $m['size'],
            'created_at' => ContentTools::iso($m['created_at'] ?? null),
        ];
    }

    // ------------------------------------------------------------------
    // Catégories et étiquettes
    // ------------------------------------------------------------------

    public static function listTerms(array $a): array
    {
        $which = (string) ($a['taxonomy'] ?? 'any');
        $out = [];
        foreach (['category' => 'categories', 'tag' => 'tags'] as $tax => $key) {
            if ($which === 'any' || $which === $tax) {
                $out[$key] = array_map([self::class, 'termInfo'], Terms::all($tax));
            }
        }
        return $out;
    }

    public static function createTerm(array $a): array
    {
        $tax = (string) $a['taxonomy'];
        Tools::need($tax === 'category' ? 'edit_others' : 'edit_content');
        $name = trim(strip_tags((string) $a['name']));
        $slug = slugify(trim((string) ($a['slug'] ?? '')) !== '' ? (string) $a['slug'] : $name);
        if ($name === '' || $slug === '') {
            throw new ToolError('The name must contain letters or digits.');
        }
        $existing = Terms::bySlug($tax, $slug);
        if ($existing) {
            return ['created' => false, 'note' => 'A ' . $tax . ' with this slug already exists.'] + self::termInfo($existing);
        }
        $parent = null;
        if (isset($a['parent_id'])) {
            $p = Terms::find((int) $a['parent_id']);
            if ($tax !== 'category' || !$p || $p['taxonomy'] !== 'category') {
                throw new ToolError('parent_id must be an existing category (categories only).');
            }
            $parent = (int) $p['id'];
        }
        $id = Terms::save(['taxonomy' => $tax, 'name' => $name, 'slug' => $slug, 'description' => (string) ($a['description'] ?? ''), 'parent_id' => $parent]);
        $term = Terms::find($id);
        Activity::log('mcp', $tax, $id, ($tax === 'category' ? __('Catégorie créée : {n}', ['n' => $name]) : __('Étiquette créée : {n}', ['n' => $name])));
        return ['created' => true] + self::termInfo($term + ['count' => 0]);
    }

    private static function termInfo(array $t): array
    {
        return [
            'id' => (int) $t['id'],
            'taxonomy' => (string) $t['taxonomy'],
            'name' => (string) $t['name'],
            'slug' => (string) $t['slug'],
            'parent_id' => $t['parent_id'] !== null ? (int) $t['parent_id'] : null,
            'description' => (string) $t['description'],
            'published_items' => (int) ($t['count'] ?? 0),
            'url' => Terms::url($t),
        ];
    }

    // ------------------------------------------------------------------
    // Redirections
    // ------------------------------------------------------------------

    public static function redirects(array $a): array
    {
        Tools::need('manage_seo');
        $action = (string) $a['action'];
        if ($action === 'list') {
            $where = '1=1';
            $p = [];
            $search = trim((string) ($a['search'] ?? ''));
            if ($search !== '') {
                $where = "(source LIKE ? ESCAPE '\\' OR target LIKE ? ESCAPE '\\')";
                $like = '%' . addcslashes($search, '%_\\') . '%';
                $p = [$like, $like];
            }
            $limit = (int) ($a['limit'] ?? 50);
            $rows = Db::all('SELECT * FROM redirects WHERE ' . $where . ' ORDER BY id DESC LIMIT ?', [...$p, $limit]);
            $notFound = Db::all('SELECT path, hits, referrer, last_at FROM not_found ORDER BY hits DESC, last_at DESC LIMIT 20');
            return [
                'total' => (int) Db::val('SELECT COUNT(*) FROM redirects WHERE ' . $where, $p),
                'redirects' => array_map(static fn ($r) => [
                    'source' => (string) $r['source'], 'target' => (string) $r['target'], 'code' => (int) $r['code'],
                    'hits' => (int) $r['hits'], 'last_hit_at' => ContentTools::iso($r['last_hit_at']), 'automatic' => (bool) $r['auto'],
                ], $rows),
                'top_404' => array_map(static fn ($r) => [
                    'path' => (string) $r['path'], 'hits' => (int) $r['hits'], 'referrer' => (string) $r['referrer'], 'last_seen' => ContentTools::iso($r['last_at']),
                ], $notFound),
            ];
        }
        $source = self::sitePath((string) ($a['source'] ?? ''));
        if ($source === null) {
            throw new ToolError('source is required and must be a path or URL of this site (' . Config::baseUrl() . ').');
        }
        if ($action === 'delete') {
            $n = Db::delete('redirects', ['source' => $source]);
            if ($n === 0) {
                throw new ToolError('No redirect found for ' . $source . ' (use action "list").');
            }
            Cache::purge();
            Activity::log('mcp', 'redirect', null, __('Redirection supprimée : {s}', ['s' => $source]));
            return ['deleted' => true, 'source' => $source];
        }
        // add
        $code = (int) ($a['code'] ?? 301);
        if ($source === '/') {
            throw new ToolError('The home page cannot be redirected.');
        }
        $warnings = [];
        if ($code === 410) {
            $target = '/';
        } else {
            $raw = trim((string) ($a['target'] ?? ''));
            if ($raw === '') {
                throw new ToolError('target is required (or use code 410 for a page that is gone for good).');
            }
            $internal = self::sitePath($raw);
            if ($internal !== null) {
                $target = $internal;
            } elseif (preg_match('#^https?://[^\s]+$#i', $raw)) {
                $target = $raw;
            } else {
                throw new ToolError('target must be a path of this site (e.g. "/new-page") or an absolute http(s) URL.');
            }
            if ($target === $source) {
                throw new ToolError('source and target are the same: this would create a redirect loop.');
            }
            if (!preg_match('#^https?://#i', $target) && $target !== '/' && !Db::val("SELECT 1 FROM content WHERE path = ? AND status = 'published'", [Content::normalizePath(strtok($target, '?') ?: $target)])) {
                $warnings[] = 'No published content at ' . $target . ': check the target URL.';
            }
        }
        if (Db::val("SELECT 1 FROM content WHERE path = ? AND status = 'published'", [Content::normalizePath(strtok($source, '?') ?: $source)])) {
            $warnings[] = 'A published page exists at ' . $source . ': the redirect hides it until removed.';
        }
        Redirects::add($source, $target, $code);
        Cache::purge();
        Activity::log('mcp', 'redirect', null, $code === 410 ? __('Adresse supprimée (410) : {s}', ['s' => $source]) : __('Redirection {c} : {s} → {t}', ['c' => $code, 's' => $source, 't' => $target]));
        $out = ['added' => true, 'source' => $source, 'target' => $code === 410 ? null : $target, 'code' => $code];
        if ($warnings) {
            $out['warnings'] = $warnings;
        }
        return $out;
    }

    /** Chemin interne normalisé d'une adresse du site (null si vide ou externe). */
    private static function sitePath(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (preg_match('#^https?://#i', $s)) {
            $host = strtolower((string) parse_url($s, PHP_URL_HOST));
            $site = strtolower((string) parse_url(Config::baseUrl(), PHP_URL_HOST));
            if ($host !== $site && 'www.' . $host !== $site && $host !== 'www.' . $site) {
                return null;
            }
            $path = (string) (parse_url($s, PHP_URL_PATH) ?? '/');
            $query = (string) (parse_url($s, PHP_URL_QUERY) ?? '');
            $base = Config::basePath();
            if ($base !== '' && str_starts_with($path, $base)) {
                $path = substr($path, strlen($base));
            }
            $s = ($path === '' ? '/' : $path) . ($query !== '' ? '?' . $query : '');
        } elseif (!str_starts_with($s, '/')) {
            $s = '/' . $s;
        }
        return Redirects::normalize($s);
    }

    // ------------------------------------------------------------------
    // Commentaires
    // ------------------------------------------------------------------

    public static function listComments(array $a): array
    {
        $status = (string) ($a['status'] ?? 'pending');
        $where = [];
        $p = [];
        if ($status !== 'any') {
            $where[] = 'cm.status = ?';
            $p[] = $status;
        }
        if (isset($a['content_id'])) {
            $where[] = 'cm.content_id = ?';
            $p[] = (int) $a['content_id'];
        }
        $p[] = (int) ($a['limit'] ?? 20);
        $rows = Db::all('SELECT cm.id, cm.content_id, cm.parent_id, cm.author, cm.url, cm.body, cm.status, cm.created_at, c.title AS content_title, c.path AS content_path
            FROM comments cm LEFT JOIN content c ON c.id = cm.content_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY cm.created_at DESC LIMIT ?', $p);
        return [
            'counts' => Comments::counts(),
            'items' => array_map(static fn ($r) => [
                'id' => (int) $r['id'],
                'status' => (string) $r['status'],
                'author' => (string) $r['author'],
                'author_website' => (string) $r['url'],
                'text' => excerpt(plain_text((string) $r['body']), 2000),
                'created_at' => ContentTools::iso($r['created_at']),
                'reply_to' => $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
                'content' => ['id' => (int) $r['content_id'], 'title' => (string) ($r['content_title'] ?? ''), 'url' => $r['content_path'] !== null ? url((string) $r['content_path']) : null],
            ], $rows),
        ];
    }

    public static function moderateComment(array $a): array
    {
        $row = Db::one('SELECT id, status, author FROM comments WHERE id = ?', [(int) $a['id']]);
        if (!$row) {
            throw new ToolError('Comment ' . (int) $a['id'] . ' not found (use list_comments).');
        }
        $status = ['approve' => 'approved', 'unapprove' => 'pending', 'spam' => 'spam', 'trash' => 'trash'][(string) $a['action']];
        Comments::setStatus((int) $row['id'], $status);
        Activity::log('mcp', 'comment', (int) $row['id'], __('Commentaire de {a} : {s}', ['a' => (string) $row['author'], 's' => $status]));
        return ['id' => (int) $row['id'], 'previous_status' => (string) $row['status'], 'status' => $status];
    }

    // ------------------------------------------------------------------
    // Design
    // ------------------------------------------------------------------

    /** Paires de couleurs contrôlées : [texte, fond, minimum, corrigé automatiquement]. */
    private const PAIRS = [
        'text_on_bg' => ['text', 'bg', 4.5, true],
        'muted_on_bg' => ['muted', 'bg', 4.5, true],
        'accent_text_on_accent' => ['accent_text', 'accent', 4.5, true],
        'accent_on_bg' => ['accent', 'bg', 4.5, false],
        'text_on_surface' => ['text', 'surface', 4.5, false],
        'muted_on_surface' => ['muted', 'surface', 4.5, false],
    ];

    private const TOKEN_KEYS = ['base', 'ratio', 'measure', 'radius', 'density', 'scheme'];

    public static function getDesign(array $a): array
    {
        $t = Theme::tokens();
        $design = (array) Settings::get('design', []);
        $presets = [];
        foreach (Theme::presets() as $key => $p) {
            $presets[$key] = ['label' => (string) $p['label'], 'description' => (string) $p['description'], 'scheme' => (string) $p['scheme']];
        }
        return self::tokenSummary($t) + [
            'customized' => array_values(array_diff(array_keys($design), ['preset'])),
            'contrast' => self::contrastReport($t['colors']),
            'available_presets' => $presets,
            'available_fonts' => array_map(static fn ($f) => (string) $f['label'], Theme::FONTS),
            'notes' => 'Links use the accent color only when it reaches 4.5:1 on the background (otherwise the text color). See also the resource wordless://design/system.',
        ];
    }

    public static function updateDesign(array $a): array
    {
        Tools::need('manage_design');
        $design = (array) Settings::get('design', []);
        $presets = Theme::presets();
        if (isset($a['preset'])) {
            // Nouvelle direction artistique : on repart des valeurs du préréglage.
            foreach (array_merge(['colors', 'fonts', 'heading_weight', 'tracking', 'buttons'], self::TOKEN_KEYS) as $k) {
                unset($design[$k]);
            }
            $design['preset'] = (string) $a['preset'];
        }
        foreach ((array) ($a['colors'] ?? []) as $k => $hex) {
            $design['colors'][$k] = self::hex((string) $hex);
        }
        foreach ((array) ($a['fonts'] ?? []) as $k => $font) {
            $design['fonts'][$k] = (string) $font;
        }
        foreach (self::TOKEN_KEYS as $k) {
            if (isset($a[$k])) {
                $design[$k] = $k === 'ratio' ? round((float) $a[$k], 3) : (in_array($k, ['density', 'scheme'], true) ? (string) $a[$k] : (int) $a[$k]);
            }
        }
        // Couleurs effectives (préréglage + personnalisation), puis contrastes WCAG AA.
        $preset = $presets[$design['preset'] ?? 'atelier'] ?? $presets['atelier'];
        $colors = array_merge($preset['colors'], (array) ($design['colors'] ?? []));
        $corrections = [];
        foreach (self::PAIRS as $pair => [$fg, $bg, $min, $fix]) {
            if (!$fix) {
                continue;
            }
            $before = Color::contrast($colors[$fg], $colors[$bg]);
            if ($before >= $min) {
                continue;
            }
            $fixed = self::ensureContrast($colors[$fg], $colors[$bg], $min);
            $corrections[] = [
                'token' => $fg, 'on' => $bg, 'requested' => $colors[$fg], 'applied' => $fixed,
                'contrast_before' => $before, 'contrast_after' => Color::contrast($fixed, $colors[$bg]), 'minimum' => $min,
            ];
            $colors[$fg] = $fixed;
            $design['colors'][$fg] = $fixed;
        }
        Settings::set('design', $design);
        Theme::resetCache();
        Cache::purge();
        $changed = array_keys($a);
        Activity::log('mcp', 'design', null, __('Design modifié : {f}', ['f' => implode(', ', $changed)]) . ($corrections ? ' — ' . __('{n} couleur(s) corrigée(s) pour la lisibilité', ['n' => count($corrections)]) : ''));
        $t = Theme::tokens();
        $out = ['updated' => $changed] + self::tokenSummary($t) + ['contrast' => self::contrastReport($t['colors'])];
        $out['contrast_corrections'] = $corrections;
        if ($corrections) {
            $out['note'] = 'Some colors were adjusted to keep text readable (WCAG AA 4.5:1). Tell the user which ones and why.';
        }
        $warnings = array_values(array_filter(array_map(static fn (array $r) => $r['passes'] ? null : $r['pair'] . ' is ' . $r['ratio'] . ':1 (below ' . $r['minimum'] . ':1).', $out['contrast'])));
        if ($warnings) {
            $out['warnings'] = $warnings;
        }
        return $out;
    }

    private static function tokenSummary(array $t): array
    {
        return [
            'preset' => (string) ($t['preset'] ?? 'atelier'),
            'colors' => $t['colors'],
            'fonts' => [
                'heading' => (string) $t['fonts']['heading'],
                'body' => (string) $t['fonts']['body'],
            ],
            'base' => (int) $t['base'],
            'ratio' => (float) $t['ratio'],
            'measure' => (int) $t['measure'],
            'radius' => (int) $t['radius'],
            'density' => (string) ($t['density'] ?? 'normal'),
            'scheme' => (string) ($t['scheme'] ?? 'light'),
        ];
    }

    /** @return list<array{pair: string, ratio: float, minimum: float, passes: bool}> */
    public static function contrastReport(array $colors): array
    {
        $out = [];
        foreach (self::PAIRS as $pair => [$fg, $bg, $min]) {
            if (!isset($colors[$fg], $colors[$bg])) {
                continue;
            }
            $ratio = Color::contrast((string) $colors[$fg], (string) $colors[$bg]);
            $out[] = ['pair' => $pair, 'foreground' => (string) $colors[$fg], 'background' => (string) $colors[$bg], 'ratio' => $ratio, 'minimum' => $min, 'passes' => $ratio >= $min];
        }
        return $out;
    }

    /** Couleur corrigée (teinte conservée) ; repli sur le noir ou le blanc pour les fonds de clarté moyenne. */
    private static function ensureContrast(string $fg, string $bg, float $min): string
    {
        $fixed = Color::ensureContrast($fg, $bg, $min);
        if (Color::contrast($fixed, $bg) >= $min) {
            return $fixed;
        }
        $on = Color::onColor($bg);
        if (Color::contrast($on, $bg) >= $min) {
            return $on;
        }
        return Color::contrast('#000000', $bg) >= Color::contrast('#ffffff', $bg) ? '#000000' : '#ffffff';
    }

    private static function hex(string $hex): string
    {
        if (!Color::valid($hex)) {
            throw new ToolError('Invalid color "' . $hex . '": use #rrggbb.');
        }
        return Color::hex(Color::rgb($hex));
    }

    // ------------------------------------------------------------------
    // Réglages
    // ------------------------------------------------------------------

    public static function updateSettings(array $a): array
    {
        Tools::need('manage_settings');
        $map = ['site_name' => 'site.name', 'site_tagline' => 'site.tagline', 'home_title' => 'seo.home_title', 'home_description' => 'seo.home_description'];
        $changes = [];
        foreach ($map as $arg => $key) {
            if (isset($a[$arg])) {
                $changes[$key] = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $a[$arg])) ?? '');
            }
        }
        if (isset($changes['site.name']) && $changes['site.name'] === '') {
            throw new ToolError('site_name cannot be empty.');
        }
        if (isset($a['ai_crawlers'])) {
            $current = (array) Settings::get('geo.ai_crawlers', []);
            $known = array_unique(array_merge(array_keys((array) (Settings::defaults()['geo.ai_crawlers'] ?? [])), array_keys($current)));
            foreach ((array) $a['ai_crawlers'] as $bot => $allowed) {
                if (!in_array((string) $bot, $known, true)) {
                    throw new ToolError('Unknown crawler "' . $bot . '". Known crawlers: ' . implode(', ', $known) . '.');
                }
                $current[(string) $bot] = (bool) $allowed;
            }
            $changes['geo.ai_crawlers'] = $current;
        }
        if (!$changes) {
            throw new ToolError('Nothing to update.');
        }
        Settings::setMany($changes);
        Cache::purge();
        Activity::log('mcp', 'settings', null, __('Réglages modifiés : {k}', ['k' => implode(', ', array_keys($changes))]));
        return [
            'updated' => array_keys($changes),
            'settings' => [
                'site.name' => (string) Settings::get('site.name'),
                'site.tagline' => (string) Settings::get('site.tagline', ''),
                'seo.home_title' => (string) Settings::get('seo.home_title', ''),
                'seo.home_description' => (string) Settings::get('seo.home_description', ''),
                'geo.ai_crawlers' => (array) Settings::get('geo.ai_crawlers', []),
            ],
            'blocked_ai_crawlers' => self::blockedAiCrawlers(),
        ];
    }
}
