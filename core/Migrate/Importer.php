<?php
declare(strict_types=1);

namespace Wordless\Migrate;

use Wordless\Activity;
use Wordless\App;
use Wordless\Auth;
use Wordless\Cache;
use Wordless\Config;
use Wordless\Content;
use Wordless\Crypto;
use Wordless\Db;
use Wordless\Html;
use Wordless\HttpClient;
use Wordless\I18n;
use Wordless\Media;
use Wordless\Redirects;
use Wordless\Search;
use Wordless\Settings;
use Wordless\Terms;

/**
 * Migration WordPress -> Wordless, en quelques clics et sans perte de référencement.
 *
 * Interface publique (utilisée par l'administration et la ligne de commande) :
 *
 *   Importer::create(array $source, array $options = []): array
 *     $source = ['type' => 'wxr', 'file' => '/chemin/absolu/export.xml']
 *            ou ['type' => 'rest', 'url' => 'https://site.tld', 'user' => '', 'password' => '']
 *     (mot de passe d'application WordPress, facultatif : sans lui, seuls les
 *     contenus publics publiés sont importés). Analyse rapidement la source,
 *     enregistre la tâche dans data/import/<id>.json et renvoie son état.
 *     En cas de problème (fichier illisible, site injoignable…), l'état
 *     renvoyé a 'finished' => true et 'error' => message lisible.
 *
 *   Importer::step(string $jobId, int $budgetSeconds = 8): array
 *     Traite la suite pendant le temps imparti puis renvoie l'état. À appeler
 *     en boucle (AJAX) jusqu'à 'finished' => true. Reprise et idempotence
 *     assurées par la table import_map : relancer ne crée jamais de doublon.
 *
 *   Importer::get(string $jobId): ?array      Importer::cancel(string $jobId): void
 *
 * État renvoyé : id, source (sans mot de passe), options, phase, phases
 * (clé => label, done, total, status pending|running|done), log (50 derniers
 * messages {time, message}), finished, error, cancelled, report :
 *   counts[type] = {imported, updated, skipped, reasons{raison: n}} pour
 *     categories, tags, users, media, pages, posts, other, comments, menus, redirects ;
 *   media = {downloaded, failed: [{url, error}]} ;
 *   warnings = [{code, message, count, examples[]}] ;
 *   review = [{id, title, path, issues[]}] (contenus « à revoir ») ;
 *   skipped = [{type, wp_id, title, reason}] ;
 *   urls = {total, identical, redirected, identical_list[], redirected_list[{from, to}], conflicts[{path, reason}]} ;
 *   notices[] (remarques générales), next_steps[] (prochaines étapes),
 *   started_at, finished_at, duration.
 *
 * Phases, dans l'ordre : terms, users, media, content (pages, puis articles,
 * puis autres types), comments, menus, finalize.
 */
final class Importer
{
    public const DEFAULTS = [
        'media' => true, 'comments' => true, 'users' => true, 'menus' => true, 'settings' => true,
        'replace_starter' => true, 'seo_fallback' => false, 'other_types' => 'pages',
    ];

    /** Types de contenus natifs de WordPress (jamais traités comme « autres types »). */
    private const BUILTIN = ['post', 'page', 'attachment', 'nav_menu_item', 'wp_block', 'wp_template', 'wp_template_part',
        'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face', 'revision', 'custom_css', 'customize_changeset',
        'oembed_cache', 'user_request'];

    /** @var array<string, Rest> */
    private static array $rest = [];
    /** @var array<string, array> cache des listes lues sur disque */
    private static array $lists = [];

    // ------------------------------------------------------------------
    // Interface publique
    // ------------------------------------------------------------------

    public static function create(array $source, array $options = []): array
    {
        @set_time_limit(900);
        $options = self::options($options);
        $type = (string) ($source['type'] ?? '');
        $public = ['type' => $type];
        if ($type === 'wxr') {
            $public['file'] = (string) ($source['file'] ?? '');
            $public['name'] = basename($public['file']);
        } elseif ($type === 'rest') {
            $public['url'] = trim((string) ($source['url'] ?? ''));
            $public['user'] = trim((string) ($source['user'] ?? ''));
        }
        $job = Job::create($public, $options);
        $password = (string) ($source['password'] ?? '');
        if ($type === 'rest' && $password !== '') {
            $job->d['secret'] = Crypto::encrypt($password);
        }
        try {
            if ($type === 'wxr') {
                self::analyzeWxr($job, (string) ($source['file'] ?? ''));
            } elseif ($type === 'rest') {
                self::analyzeRest($job, $public['url'], $public['user'], $password);
            } else {
                throw new \InvalidArgumentException(__('Source de migration inconnue (fichier d’export ou adresse du site attendus).'));
            }
        } catch (\Throwable $e) {
            if (!$e instanceof \RuntimeException && !$e instanceof \InvalidArgumentException) {
                App::logError($e);
            }
            $job->d['error'] = $e->getMessage();
            $job->d['finished'] = true;
            $job->log(__('Analyse impossible : {m}', ['m' => $e->getMessage()]));
            $job->cleanup();
        }
        $job->save();
        return $job->publicState();
    }

    public static function step(string $jobId, int $budgetSeconds = 8): array
    {
        if (!Job::validId($jobId)) {
            return self::missing($jobId);
        }
        $lock = Job::acquire($jobId);
        if ($lock === null) {
            // Déjà en cours dans une autre requête : on renvoie simplement l'état.
            return self::get($jobId) ?? self::missing($jobId);
        }
        $job = Job::load($jobId);
        if (!$job) {
            Job::release($lock);
            return self::missing($jobId);
        }
        if ($job->d['finished']) {
            Job::release($lock);
            return $job->publicState();
        }
        // Une seule migration à la fois pour un même site WordPress (double clic, onglets…).
        $sourceLock = Job::acquire('src-' . sha1((string) ($job->d['state']['source_key'] ?? $jobId)));
        if ($sourceLock === null) {
            Job::release($lock);
            $state = $job->publicState();
            $state['log'][] = __('Une autre migration de ce site est en cours : patientez ou annulez-la.');
            return $state;
        }
        @set_time_limit(max(120, $budgetSeconds * 10));
        @ignore_user_abort(true);
        $deadline = microtime(true) + max(1, $budgetSeconds);
        try {
            if (!Job::cancelRequested($jobId)) {
                self::run($job, $deadline);
            }
            $job->d['state']['errors'] = 0;
        } catch (\Throwable $e) {
            App::logError($e);
            $st = &$job->state();
            $st['errors'] = (int) ($st['errors'] ?? 0) + 1;
            unset($st['inflight']);
            if ($st['errors'] >= 3) {
                $job->d['error'] = $e->getMessage();
                $job->d['finished'] = true;
                $job->log(__('Migration interrompue : {m}', ['m' => $e->getMessage()]));
                $job->cleanup();
            } else {
                $job->log(__('Erreur passagère, nouvel essai à l’étape suivante : {m}', ['m' => $e->getMessage()]));
            }
        } finally {
            if (Job::cancelRequested($jobId) && !$job->d['finished']) {
                self::markCancelled($job);
            }
            $job->save();
            Job::release($sourceLock);
            Job::release($lock);
            if ($job->d['finished']) {
                Job::removeMarkers($jobId);
            }
        }
        return $job->publicState();
    }

    public static function get(string $jobId): ?array
    {
        $job = Job::load($jobId);
        return $job?->publicState();
    }

    public static function cancel(string $jobId): void
    {
        $job = Job::load($jobId);
        if (!$job || $job->d['finished']) {
            return;
        }
        // Si une étape est en cours, elle verra la demande et s'arrêtera proprement.
        Job::requestCancel($jobId);
        $lock = Job::acquire($jobId);
        if ($lock === null) {
            return;
        }
        $job = Job::load($jobId);
        if ($job && !$job->d['finished']) {
            self::markCancelled($job);
            $job->save();
        }
        Job::release($lock);
        Job::removeMarkers($jobId);
    }

    private static function markCancelled(Job $job): void
    {
        $job->d['finished'] = true;
        $job->d['cancelled'] = true;
        $job->d['error'] = __('Migration annulée.');
        foreach ($job->d['phases'] as $k => $p) {
            if ($p['status'] === 'running') {
                $job->d['phases'][$k]['status'] = 'pending';
            }
        }
        $job->log(__('Migration annulée : les éléments déjà importés sont conservés (relancer ne crée pas de doublon).'));
        $job->cleanup();
    }

    private static function missing(string $jobId): array
    {
        return [
            'id' => $jobId, 'source' => [], 'options' => [], 'phase' => '', 'phases' => [], 'log' => [],
            'finished' => true, 'error' => __('Migration introuvable.'), 'cancelled' => false, 'report' => Job::emptyReport(),
        ];
    }

    private static function options(array $o): array
    {
        $out = self::DEFAULTS;
        foreach (self::DEFAULTS as $k => $def) {
            if (!array_key_exists($k, $o)) {
                continue;
            }
            $out[$k] = is_bool($def) ? filter_var($o[$k], FILTER_VALIDATE_BOOLEAN) : (in_array($o[$k], ['pages', 'skip'], true) ? $o[$k] : $def);
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Analyse de la source
    // ------------------------------------------------------------------

    private static function analyzeWxr(Job $job, string $file): void
    {
        $file = trim($file);
        if ($file === '' || !is_file($file) || !is_readable($file)) {
            throw new \RuntimeException(__('Fichier d’export introuvable ou illisible.'));
        }
        $a = Wxr::spool($file, $job->workDir(), $job->d['options']);
        $site = $a['site'];
        $home = Context::normalizeBase($site['base_blog_url'] !== '' ? $site['base_blog_url'] : ($site['link'] !== '' ? $site['link'] : $site['base_site_url']));
        if (!preg_match('#^https?://#', $home)) {
            throw new \RuntimeException(__('Adresse du site WordPress absente du fichier d’export.'));
        }
        $homePath = rtrim((string) parse_url($home, PHP_URL_PATH), '/');
        $st = &$job->state();
        $st['source_key'] = $home;
        $st['site'] = [
            'home' => $home,
            'siteurl' => Context::normalizeBase($site['base_site_url'] !== '' ? $site['base_site_url'] : $home),
            'name' => html_entity_decode($site['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'description' => html_entity_decode($site['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'language' => $site['language'],
            'uploads_path' => $a['uploads'] ?? ($homePath . '/wp-content/uploads'),
            'front_page' => $a['front_page'],
            'show_on_front' => $a['front_page'] ? 'page' : null,
            'timezone' => self::guessTimezone($a['dates'], (string) $site['language']),
            'media_hosts' => $a['media_hosts'],
        ];
        $catPaths = self::categoryPaths(Wxr::header($job->workDir())['terms'] ?? []);
        $st['permalink'] = self::inferPermalink($a['samples'], $catPaths, $homePath);
        $st['trailing_slash'] = $a['trailing']['slash'] + $a['trailing']['noslash'] > 0 ? $a['trailing']['slash'] >= $a['trailing']['noslash'] : null;
        $st['wxr_ns'] = true;

        $o = $job->d['options'];
        $terms = count(array_filter(Wxr::header($job->workDir())['terms'] ?? [], static fn ($t) => in_array($t['taxonomy'], ['category', 'tag'], true)));
        $menuCount = count(Wxr::header($job->workDir())['menus'] ?? []) ?: ($a['kinds']['wp_navigation'] > 0 ? 1 : 0);
        self::totals($job, [
            'terms' => $terms,
            'users' => $o['users'] ? count($a['authors']) : 0,
            'media' => $o['media'] ? $a['kinds']['attachment'] : 0,
            'content' => $a['kinds']['page'] + $a['kinds']['post'] + $a['kinds']['other'],
            'comments' => $o['comments'] ? $a['comments']['total'] : 0,
            'menus' => $o['menus'] ? $menuCount : 0,
        ]);
        $st['counts'] = ['pages' => $a['kinds']['page'], 'posts' => $a['kinds']['post'], 'other' => $a['kinds']['other'], 'media' => $a['kinds']['attachment'],
            'comments' => $a['comments'], 'types' => $a['types']];
        self::analysisWarnings($job, $a['shop'], $a['cpt'], $a['other_taxonomies']);
        if (!empty($a['xml_error'])) {
            $job->notice($a['xml_error']);
            $job->warn('source', $a['xml_error']);
        }
        $job->notice(__('Les rôles des utilisateurs ne figurent pas dans le fichier d’export : les comptes créés ont le rôle « Auteur ».'));
        $job->log(__('Fichier analysé : {p} pages, {a} articles, {m} médias, {c} commentaires.', [
            'p' => $a['kinds']['page'], 'a' => $a['kinds']['post'], 'm' => $a['kinds']['attachment'], 'c' => $a['comments']['total'],
        ]));
    }

    private static function analyzeRest(Job $job, string $url, string $user, string $password): void
    {
        if ($url === '') {
            throw new \RuntimeException(__('Indiquez l’adresse du site WordPress.'));
        }
        $disc = Rest::discover($url);
        $index = $disc['index'];
        $auth = $user !== '' && $password !== '';
        $api = new Rest($disc['api'], $user, $password, $job->workDir());
        $st = &$job->state();
        $st['api'] = $disc['api'];
        $st['auth'] = $auth;
        if ($auth) {
            $me = $api->request('/wp/v2/users/me', ['context' => 'edit']);
            if (in_array($me['status'], [401, 403], true) || !is_array($me['data']) || empty($me['data']['id'])) {
                throw new \RuntimeException(__('WordPress a refusé l’identifiant ou le mot de passe d’application. Créez-en un dans Utilisateurs > Profil > Mots de passe d’application.'));
            }
            if (!in_array('administrator', (array) ($me['data']['roles'] ?? []), true)) {
                $job->notice(__('Le compte WordPress utilisé n’est pas administrateur : certains contenus, menus ou réglages peuvent manquer.'));
            }
        } else {
            $job->notice(__('Import sans mot de passe d’application : seuls les contenus publics publiés sont repris (pas de brouillons, de menus ni de contenus privés).'));
        }
        $home = Context::normalizeBase((string) ($index['home'] ?? ($index['url'] ?? $url)));
        $settings = $auth ? (array) ($api->get('/wp/v2/settings') ?? []) : [];
        $st['source_key'] = $home;
        $st['site'] = [
            'home' => $home,
            'siteurl' => Context::normalizeBase((string) ($index['url'] ?? $home)),
            'name' => html_entity_decode((string) ($settings['title'] ?? ($index['name'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'description' => html_entity_decode((string) ($settings['description'] ?? ($index['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'timezone' => (string) (($settings['timezone'] ?? '') !== '' ? $settings['timezone'] : ($index['timezone_string'] ?? '')),
            'language' => (string) ($settings['language'] ?? ''),
            'show_on_front' => $settings['show_on_front'] ?? ($index['show_on_front'] ?? null),
            'page_on_front' => (int) ($settings['page_on_front'] ?? ($index['page_on_front'] ?? 0)),
            'page_for_posts' => (int) ($settings['page_for_posts'] ?? ($index['page_for_posts'] ?? 0)),
            'posts_per_page' => (int) ($settings['posts_per_page'] ?? 0),
            'site_logo' => (int) ($settings['site_logo'] ?? ($index['site_logo'] ?? 0)),
            'site_icon' => (int) ($settings['site_icon'] ?? ($index['site_icon'] ?? 0)),
        ];
        $homePath = rtrim((string) parse_url($home, PHP_URL_PATH), '/');
        if ($st['site']['language'] === '') {
            try {
                $html = HttpClient::get($home . '/', ['timeout' => 20, 'max_bytes' => 1024 * 1024]);
                if (preg_match('#<html\b[^>]*\blang="([a-zA-Z_-]+)"#i', (string) $html['body'], $m)) {
                    $st['site']['language'] = $m[1];
                }
            } catch (\Throwable) {
                // langue inconnue : réglage inchangé
            }
        }

        $edit = $auth ? ['context' => 'edit'] : [];
        $any = $auth ? ['context' => 'edit', 'status' => 'any'] : [];
        $counts = [
            'categories' => (int) $api->total('/wp/v2/categories'),
            'tags' => (int) $api->total('/wp/v2/tags'),
            'users' => (int) $api->total('/wp/v2/users', $edit),
            'media' => (int) $api->total('/wp/v2/media', $edit),
            'pages' => (int) $api->total('/wp/v2/pages', $any),
            'posts' => (int) $api->total('/wp/v2/posts', $any),
            'comments' => (int) $api->total('/wp/v2/comments', $auth ? ['context' => 'edit', 'status' => 'all'] : []),
            'menus' => $auth ? (int) $api->total('/wp/v2/menus', $edit) : 0,
        ];
        // Commentaires non importés (comptés dans le rapport) : indésirables, corbeille, rétroliens.
        $st['comments_skipped'] = [
            __('indésirables ou à la corbeille') => $auth ? (int) $api->total('/wp/v2/comments', ['context' => 'edit', 'status' => 'spam']) + (int) $api->total('/wp/v2/comments', ['context' => 'edit', 'status' => 'trash']) : 0,
            __('rétroliens (pingbacks) non importés') => (int) $api->total('/wp/v2/comments', ($auth ? ['context' => 'edit', 'status' => 'all'] : []) + ['type' => 'pingback'])
                + (int) $api->total('/wp/v2/comments', ($auth ? ['context' => 'edit', 'status' => 'all'] : []) + ['type' => 'trackback']),
        ];
        if ($counts['menus'] === 0) {
            $counts['menus'] = (int) $api->total('/wp/v2/navigation', $edit) > 0 ? 1 : 0;
        }
        // Types de contenus personnalisés exposés par l'API.
        $cpts = [];
        $shop = [];
        $skippedCpt = [];
        foreach ((array) ($api->get('/wp/v2/types', $edit) ?? []) as $slug => $t) {
            $slug = (string) ($t['slug'] ?? $slug);
            if (in_array($slug, self::BUILTIN, true) || Wxr::isInternal($slug) || empty($t['rest_base'])) {
                continue;
            }
            $route = '/' . trim((string) ($t['rest_namespace'] ?? 'wp/v2'), '/') . '/' . $t['rest_base'];
            $n = (int) $api->total($route, $any);
            if ($n === 0) {
                continue;
            }
            if (in_array($slug, Wxr::SHOP, true)) {
                $shop[$slug] = $n;
            } elseif ($job->d['options']['other_types'] === 'pages') {
                $cpts[] = ['route' => $route, 'type' => $slug, 'count' => $n];
            } else {
                $skippedCpt[$slug] = $n;
            }
        }
        $st['cpts'] = $cpts;
        $other = array_sum(array_column($cpts, 'count'));

        // Structure des permaliens et barre finale.
        $first = $api->page('/wp/v2/posts', $auth ? ['context' => 'edit', 'status' => 'publish'] : [], 1, 20);
        $tpl = (string) ($first['items'][0]['permalink_template'] ?? '');
        $perm = $tpl !== '' ? self::permalinkFromTemplate($tpl, $home) : null;
        if ($perm !== null) {
            $st['permalink'] = $perm['pattern'];
            $st['trailing_slash'] = $perm['trailing'];
        } else {
            $samples = [];
            $slash = 0;
            foreach ($first['items'] as $p) {
                $link = (string) ($p['link'] ?? '');
                if ($link === '' || str_contains($link, '?')) {
                    continue;
                }
                str_ends_with($link, '/') ? $slash++ : $slash--;
                $samples[] = ['link' => $link, 'slug' => rawurldecode((string) ($p['slug'] ?? '')), 'date' => str_replace('T', ' ', (string) ($p['date'] ?? '')), 'id' => (int) ($p['id'] ?? 0), 'cats' => []];
            }
            $st['permalink'] = self::inferPermalink($samples, [], $homePath);
            $st['trailing_slash'] = $samples ? $slash >= 0 : null;
        }
        // Dossier des téléversements.
        $media = $api->page('/wp/v2/media', $edit, 1, 1);
        $m0 = $media['items'][0] ?? null;
        $st['site']['uploads_path'] = $homePath . '/wp-content/uploads';
        $st['site']['media_hosts'] = [];
        $mediaHost = $m0 ? Context::hostKey((string) ($m0['source_url'] ?? '')) : '';
        if ($mediaHost !== '' && !in_array($mediaHost, [Context::hostKey($home), Context::hostKey($st['site']['siteurl'])], true)) {
            $st['site']['media_hosts'][] = $mediaHost; // médias servis par un CDN
        } elseif ($m0 && !empty($m0['source_url']) && !empty($m0['media_details']['file'])) {
            $path = rawurldecode((string) parse_url((string) $m0['source_url'], PHP_URL_PATH));
            $file = ltrim((string) $m0['media_details']['file'], '/');
            if (str_ends_with($path, '/' . $file)) {
                $st['site']['uploads_path'] = substr($path, 0, -strlen($file) - 1);
            }
        }

        $o = $job->d['options'];
        self::totals($job, [
            'terms' => $counts['categories'] + $counts['tags'],
            'users' => $o['users'] ? $counts['users'] : 0,
            'media' => $o['media'] ? $counts['media'] : 0,
            'content' => $counts['pages'] + $counts['posts'] + $other,
            'comments' => $o['comments'] ? $counts['comments'] : 0,
            'menus' => $o['menus'] ? $counts['menus'] : 0,
        ]);
        $st['counts'] = $counts + ['other' => $other];
        self::analysisWarnings($job, $shop, $skippedCpt, []);
        $job->log(__('Site analysé : {p} pages, {a} articles, {m} médias, {c} commentaires.', [
            'p' => $counts['pages'], 'a' => $counts['posts'], 'm' => $counts['media'], 'c' => $counts['comments'],
        ]));
    }

    /**
     * Fuseau horaire du site d'après les dates locales et GMT des contenus
     * (le fichier d'export ne contient pas le réglage) : le premier fuseau dont
     * les décalages (heure d'été comprise) correspondent à tous les exemples.
     */
    public static function guessTimezone(array $pairs, string $language = ''): ?string
    {
        $samples = [];
        foreach ($pairs as [$local, $gmt]) {
            $l = strtotime($local . ' UTC');
            $g = strtotime($gmt . ' UTC');
            if ($l !== false && $g !== false) {
                $samples[] = [$g, $l - $g];
            }
        }
        if (!$samples) {
            return null;
        }
        $byLang = [
            'fr' => ['Europe/Paris', 'Europe/Brussels', 'Europe/Zurich', 'Europe/Luxembourg', 'America/Montreal', 'Indian/Reunion', 'America/Martinique'],
            'en' => ['Europe/London', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Australia/Sydney', 'Europe/Dublin'],
            'es' => ['Europe/Madrid', 'America/Mexico_City', 'America/Bogota', 'America/Argentina/Buenos_Aires', 'America/Santiago'],
            'de' => ['Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich'],
        ];
        $lang = strtolower(substr($language, 0, 2));
        $candidates = array_unique([(string) Settings::get('timezone', 'Europe/Paris'), ...($byLang[$lang] ?? []), ...array_merge(...array_values($byLang)), 'UTC', ...\DateTimeZone::listIdentifiers()]);
        foreach ($candidates as $name) {
            try {
                $tz = new \DateTimeZone($name);
            } catch (\Throwable) {
                continue;
            }
            foreach ($samples as [$g, $offset]) {
                if ($tz->getOffset(new \DateTimeImmutable('@' . $g)) !== $offset) {
                    continue 2;
                }
            }
            return $name;
        }
        return null;
    }

    private static function totals(Job $job, array $totals): void
    {
        foreach ($totals as $phase => $n) {
            $p = &$job->phase($phase);
            $p['total'] = (int) $n;
            unset($p);
        }
        $p = &$job->phase('finalize');
        $p['total'] = 5;
        unset($p);
        foreach (['users', 'media', 'comments', 'menus'] as $opt) {
            if (empty($job->d['options'][$opt])) {
                $p = &$job->phase($opt);
                $p['skipped'] = true;
                unset($p);
            }
        }
    }

    private static function analysisWarnings(Job $job, array $shop, array $cpt, array $taxonomies): void
    {
        foreach ($shop as $type => $n) {
            $job->warn('cpt', __('Produits et catalogues e-commerce non importés (WooCommerce…)'), $type . ' (' . $n . ')');
            $job->count('other', 'skipped', (int) $n, __('boutique non importée'));
        }
        foreach ($cpt as $type => $n) {
            if ($job->d['options']['other_types'] === 'skip') {
                $job->warn('cpt', __('Types de contenus personnalisés ignorés (option « ignorer »)'), $type . ' (' . $n . ')');
                $job->count('other', 'skipped', (int) $n, __('type personnalisé ignoré'));
            }
        }
        foreach ($taxonomies as $tax => $n) {
            $job->warn('taxonomy', __('Taxonomies personnalisées non importées'), $tax . ' (' . $n . ')');
        }
    }

    /** Chemins complets des catégories (parent/enfant) pour reconnaître %category%. */
    private static function categoryPaths(array $terms): array
    {
        $parent = [];
        foreach ($terms as $t) {
            if ($t['taxonomy'] === 'category') {
                $parent[$t['slug']] = $t['parent_slug'] ?? '';
            }
        }
        $out = [];
        foreach (array_keys($parent) as $slug) {
            $path = [$slug];
            $p = $parent[$slug];
            for ($i = 0; $p !== '' && $i < 10; $i++) {
                array_unshift($path, $p);
                $p = $parent[$p] ?? '';
            }
            $out[$slug] = implode('/', $path);
        }
        return $out;
    }

    /** Structure WordPress (%postname%…) => modèle Wordless ({slug}…). */
    public static function permalinkFromTemplate(string $tpl, string $home): ?array
    {
        $tpl = trim($tpl);
        $homeNorm = rtrim($home, '/');
        $p = parse_url($tpl);
        $path = (string) ($p['path'] ?? '');
        $homePath = rtrim((string) parse_url($homeNorm, PHP_URL_PATH), '/');
        if ($homePath !== '' && str_starts_with($path, $homePath)) {
            $path = substr($path, strlen($homePath));
        }
        if (!str_contains($path, '%')) {
            return null;
        }
        $pattern = strtr(rtrim($path, '/'), [
            '%postname%' => '{slug}', '%year%' => '{year}', '%monthnum%' => '{month}', '%day%' => '{day}',
            '%post_id%' => '{id}', '%category%' => '{category}',
        ]);
        if (preg_match('/%[a-z_]+%/', $pattern) || (!str_contains($pattern, '{slug}') && !str_contains($pattern, '{id}'))) {
            return null;
        }
        return ['pattern' => '/' . ltrim($pattern, '/'), 'trailing' => str_ends_with($path, '/')];
    }

    /** Devine la structure des permaliens à partir de liens d'articles publiés. */
    public static function inferPermalink(array $samples, array $catPaths, string $homePath): ?string
    {
        $votes = [];
        foreach ($samples as $s) {
            $path = rawurldecode((string) parse_url((string) $s['link'], PHP_URL_PATH));
            if ($homePath !== '' && str_starts_with($path, $homePath)) {
                $path = substr($path, strlen($homePath));
            }
            $segs = array_values(array_filter(explode('/', trim($path, '/')), static fn ($x) => $x !== ''));
            $slug = (string) $s['slug'];
            if (!$segs || $slug === '') {
                continue;
            }
            $ts = strtotime((string) ($s['date'] ?? '')) ?: time();
            $used = [];
            $out = [];
            for ($i = 0, $n = count($segs); $i < $n; $i++) {
                $seg = $segs[$i];
                if (!isset($used['slug']) && $seg === $slug) {
                    $out[] = '{slug}';
                    $used['slug'] = true;
                    continue;
                }
                if (!isset($used['slug']) && $i === $n - 1 && str_starts_with($seg, $slug . '.')) {
                    $out[] = '{slug}' . substr($seg, strlen($slug));
                    $used['slug'] = true;
                    continue;
                }
                foreach (['year' => 'Y', 'month' => 'm', 'day' => 'd'] as $tok => $fmt) {
                    if (!isset($used[$tok]) && $seg === gmdate($fmt, $ts)) {
                        $out[] = '{' . $tok . '}';
                        $used[$tok] = true;
                        continue 2;
                    }
                }
                if (!isset($used['id']) && $seg === (string) $s['id']) {
                    $out[] = '{id}';
                    $used['id'] = true;
                    continue;
                }
                if (!isset($used['category'])) {
                    foreach ((array) ($s['cats'] ?? []) as $cat) {
                        $cp = $catPaths[$cat] ?? $cat;
                        $len = count(explode('/', $cp));
                        if (implode('/', array_slice($segs, $i, $len)) === $cp) {
                            $out[] = '{category}';
                            $used['category'] = true;
                            $i += $len - 1;
                            continue 2;
                        }
                    }
                }
                $out[] = $seg;
            }
            if (!isset($used['slug']) && !isset($used['id'])) {
                continue;
            }
            $p = '/' . implode('/', $out);
            $votes[$p] = ($votes[$p] ?? 0) + 1;
        }
        if (!$votes) {
            return null;
        }
        arsort($votes);
        $best = (string) array_key_first($votes);
        return $votes[$best] * 2 >= array_sum($votes) ? $best : null;
    }

    // ------------------------------------------------------------------
    // Exécution par étapes
    // ------------------------------------------------------------------

    private static function run(Job $job, float $deadline): void
    {
        $st = &$job->state();
        $ctx = new Context($job);
        if (empty($st['started'])) {
            self::start($job);
            $st['started'] = true;
            $st['started_at'] = time();
            $job->d['report']['started_at'] = time();
            $job->save();
        }
        if (!empty($st['inflight'])) {
            // L'élément en cours a provoqué une erreur fatale lors de l'étape précédente : on le passe.
            $f = (array) $st['inflight'];
            $job->skipped((string) ($f['type'] ?? ''), (int) ($f['id'] ?? 0), (string) ($f['title'] ?? ''), __('erreur fatale pendant l’import'));
            $job->count((string) ($f['report'] ?? 'posts'), 'skipped', 1, __('erreur fatale pendant l’import'));
            $job->log(__('Élément ignoré après une erreur fatale : {t}', ['t' => (string) ($f['title'] ?? '')]));
            unset($st['inflight']);
        }
        if (!isset($st['cursor']) || !is_array($st['cursor'])) {
            $st['cursor'] = [];
        }
        $first = true;
        while (!$job->d['finished'] && ($first || microtime(true) < $deadline)) {
            $first = false;
            $phase = (string) $job->d['phase'];
            $p = &$job->phase($phase);
            $p['status'] = 'running';
            unset($p);
            $done = match ($phase) {
                'terms' => self::phaseTerms($job, $ctx, $deadline),
                'users' => self::phaseUsers($job, $ctx, $deadline),
                'media' => self::phaseMedia($job, $ctx, $deadline),
                'content' => self::phaseContent($job, $ctx, $deadline),
                'comments' => self::phaseComments($job, $ctx, $deadline),
                'menus' => self::phaseMenus($job, $ctx, $deadline),
                default => self::phaseFinalize($job, $ctx, $deadline),
            };
            if (!$done) {
                continue;
            }
            $p = &$job->phase($phase);
            $p['status'] = 'done';
            $p['total'] = max((int) $p['total'], (int) $p['done']);
            unset($p);
            $idx = array_search($phase, Job::PHASES, true);
            $next = Job::PHASES[$idx + 1] ?? null;
            $st['cursor'] = [];
            if ($next === null) {
                break;
            }
            $job->d['phase'] = $next;
        }
    }

    /** Premier passage : contenu de démonstration, réglages du site, auteur par défaut. */
    private static function start(Job $job): void
    {
        $st = &$job->state();
        $o = $job->d['options'];
        if ($o['replace_starter']) {
            self::removeStarter($job);
        }
        if ($o['settings']) {
            self::applySiteSettings($job);
        }
        $st['fallback_user'] = Auth::id() ?: (int) (Db::val("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id LIMIT 1") ?: 0);
        $job->log(__('Migration démarrée depuis {u}.', ['u' => (string) ($st['site']['home'] ?? '')]));
    }

    /** Supprime le contenu de démonstration créé à l'installation, s'il est encore là. */
    private static function removeStarter(Job $job): void
    {
        $ids = array_map('intval', (array) Settings::get('starter.content', []));
        $menus = array_map('intval', (array) Settings::get('starter.menus', []));
        $n = 0;
        foreach ($ids as $id) {
            if ($id > 0 && Db::val('SELECT id FROM content WHERE id = ?', [$id])) {
                Content::delete($id);
                $n++;
            }
        }
        $m = 0;
        foreach ($menus as $id) {
            if ($id > 0) {
                $m += Db::delete('menus', ['id' => $id]);
            }
        }
        if (!$ids && !$menus) {
            return;
        }
        $set = ['starter.content' => [], 'starter.menus' => []];
        if (in_array((int) Settings::get('home.page_id'), $ids, true)) {
            $set['home.page_id'] = null;
        }
        if (in_array((int) Settings::get('blog.page_id'), $ids, true)) {
            $set['blog.page_id'] = null;
        }
        $locs = (array) Settings::get('menus.locations', []);
        foreach ($locs as $k => $v) {
            if (in_array((int) $v, $menus, true)) {
                $locs[$k] = null;
            }
        }
        $set['menus.locations'] = $locs;
        Settings::setMany($set);
        if ($n || $m) {
            $job->log(__('Contenu de démonstration remplacé ({n} contenus, {m} menus supprimés).', ['n' => $n, 'm' => $m]));
        }
    }

    /** Langue WordPress (fr_FR, en-US…) => langue Wordless (fr, en, es). */
    private static function lang(string $wp): ?string
    {
        $code = strtolower(substr(str_replace('_', '-', trim($wp)), 0, 2));
        return $code !== '' && isset(I18n::LANGUAGES[$code]) ? $code : null;
    }

    private static function applySiteSettings(Job $job): void
    {
        $st = $job->d['state'];
        $site = (array) ($st['site'] ?? []);
        $set = [];
        $name = trim(strip_tags((string) ($site['name'] ?? '')));
        if ($name !== '') {
            $set['site.name'] = mb_substr($name, 0, 120);
            $set['site.org_name'] = mb_substr($name, 0, 120);
        }
        if (array_key_exists('description', $site)) {
            $set['site.tagline'] = mb_substr(trim(strip_tags((string) $site['description'])), 0, 250);
        }
        $lang = self::lang((string) ($site['language'] ?? ''));
        if ($lang !== null) {
            $set['language'] = $lang;
        } elseif (!empty($site['language'])) {
            $job->notice(__('Langue WordPress « {l} » non prise en charge : la langue de Wordless est inchangée.', ['l' => (string) $site['language']]));
        }
        if (!empty($site['timezone']) && in_array($site['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $set['timezone'] = $site['timezone'];
        }
        if (isset($st['trailing_slash']) && $st['trailing_slash'] !== null) {
            $set['trailing_slash'] = (bool) $st['trailing_slash'];
        }
        if (!empty($st['permalink'])) {
            $set['permalink'] = (string) $st['permalink'];
        } else {
            $job->notice(__('Structure des permaliens non reconnue : les nouveaux articles utiliseront /{slug} (les adresses importées restent identiques).'));
        }
        if (!empty($site['posts_per_page'])) {
            $set['blog.per_page'] = max(1, min(100, (int) $site['posts_per_page']));
        }
        if ($set) {
            Settings::setMany($set);
            Cache::purge();
            $job->log(__('Réglages repris de WordPress : nom du site, slogan, langue et forme des adresses.'));
        }
    }

    /** Marque l'élément en cours (reprise propre après une erreur fatale). */
    private static function begin(Job $job, string $report, string $type, int $id, string $title): void
    {
        $job->d['state']['inflight'] = ['report' => $report, 'type' => $type, 'id' => $id, 'title' => mb_substr($title, 0, 120)];
        $job->save();
    }

    private static function end(Job $job): void
    {
        unset($job->d['state']['inflight']);
    }

    private static function tick(Job $job, string $phase, int $n = 1): void
    {
        $p = &$job->phase($phase);
        $p['done'] = (int) $p['done'] + $n;
        unset($p);
    }

    // ------------------------------------------------------------------
    // Accès aux données de la source (WXR préparé ou API REST)
    // ------------------------------------------------------------------

    private static function rest(Job $job): Rest
    {
        $id = $job->d['id'];
        if (!isset(self::$rest[$id])) {
            $secret = (string) ($job->d['secret'] ?? '');
            $password = $secret !== '' ? (string) (Crypto::decrypt($secret) ?? '') : '';
            self::$rest[$id] = new Rest((string) $job->d['state']['api'], (string) ($job->d['source']['user'] ?? ''), $password, $job->workDir());
        }
        return self::$rest[$id];
    }

    private static function isWxr(Job $job): bool
    {
        return ($job->d['source']['type'] ?? '') === 'wxr';
    }

    /** Routes REST d'un type d'élément. */
    private static function endpoints(Job $job, string $kind): array
    {
        $auth = !empty($job->d['state']['auth']);
        $order = ['orderby' => 'id', 'order' => 'asc'];
        $any = ($auth ? ['context' => 'edit', 'status' => 'any'] : []) + $order;
        return match ($kind) {
            'attachment' => [['route' => '/wp/v2/media', 'query' => ($auth ? ['context' => 'edit'] : []) + $order]],
            'page' => [['route' => '/wp/v2/pages', 'query' => $any]],
            'post' => [['route' => '/wp/v2/posts', 'query' => $any]],
            'other' => array_map(static fn ($c) => ['route' => $c['route'], 'query' => $any], (array) ($job->d['state']['cpts'] ?? [])),
            'comments' => [['route' => '/wp/v2/comments', 'query' => ($auth ? ['context' => 'edit', 'status' => 'all'] : []) + $order]],
            default => [],
        };
    }

    /** Élément suivant d'un type ; le curseur avance AVANT le traitement (un élément défaillant est sauté). */
    private static function next(Job $job, string $kind, array &$cur): ?array
    {
        if (self::isWxr($job)) {
            $offset = (int) ($cur['offset'] ?? 0);
            $row = Wxr::next($job->workDir(), $kind, $offset);
            $cur['offset'] = $offset;
            return $row;
        }
        $eps = self::endpoints($job, $kind);
        $api = self::rest($job);
        $cur += ['ep' => 0, 'page' => 1, 'index' => 0];
        while ($cur['ep'] < count($eps)) {
            $ep = $eps[$cur['ep']];
            $page = $api->page($ep['route'], $ep['query'], (int) $cur['page']);
            $i = (int) $cur['index'];
            if ($i < count($page['items'])) {
                $cur['index'] = $i + 1;
                $raw = $page['items'][$i];
                return match ($kind) {
                    'attachment' => Rest::media($raw),
                    'comments' => Rest::comment($raw),
                    default => Rest::item($raw),
                };
            }
            $api->forget($ep['route'], $ep['query'], (int) $cur['page']);
            if ($page['items'] && (int) $cur['page'] < (int) $page['pages']) {
                $cur['page']++;
                $cur['index'] = 0;
                continue;
            }
            $cur['ep']++;
            $cur['page'] = 1;
            $cur['index'] = 0;
        }
        return null;
    }

    /** Liste complète (petite) : termes, auteurs, menus… mise en cache sur disque pour les étapes suivantes. */
    private static function cachedList(Job $job, string $name, callable $load): array
    {
        $key = $job->d['id'] . ':' . $name;
        if (isset(self::$lists[$key])) {
            return self::$lists[$key];
        }
        $file = $job->workDir() . '/list-' . $name . '.json';
        if (is_file($file)) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                return self::$lists[$key] = $data;
            }
        }
        $data = array_values($load());
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        return self::$lists[$key] = $data;
    }

    /** Toutes les pages d'une collection REST. */
    private static function restAll(Job $job, string $route, array $query = []): array
    {
        $api = self::rest($job);
        $out = [];
        for ($page = 1; $page <= 500; $page++) {
            $res = $api->page($route, $query, $page);
            array_push($out, ...$res['items']);
            $api->forget($route, $query, $page);
            if (!$res['items'] || $page >= $res['pages']) {
                break;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Phase 1 : catégories et étiquettes
    // ------------------------------------------------------------------

    private static function termsList(Job $job): array
    {
        return self::cachedList($job, 'terms', static function () use ($job): array {
            if (self::isWxr($job)) {
                $terms = array_values(array_filter(Wxr::header($job->workDir())['terms'] ?? [], static fn ($t) => in_array($t['taxonomy'], ['category', 'tag'], true)));
            } else {
                $terms = [];
                foreach (self::restAll($job, '/wp/v2/categories', ['orderby' => 'id', 'order' => 'asc']) as $t) {
                    $terms[] = Rest::term($t, 'category');
                }
                foreach (self::restAll($job, '/wp/v2/tags', ['orderby' => 'id', 'order' => 'asc']) as $t) {
                    $terms[] = Rest::term($t, 'tag');
                }
            }
            // Parents d'abord (profondeur croissante).
            $byId = [];
            $bySlug = [];
            foreach ($terms as $t) {
                if ($t['taxonomy'] === 'category') {
                    $byId[$t['id']] = $t;
                    $bySlug[$t['slug']] = $t;
                }
            }
            $depth = static function (array $t) use ($byId, $bySlug): int {
                $d = 0;
                for ($i = 0; $i < 20; $i++) {
                    $p = $t['parent'] > 0 ? ($byId[$t['parent']] ?? null) : (($t['parent_slug'] ?? '') !== '' ? ($bySlug[$t['parent_slug']] ?? null) : null);
                    if (!$p) {
                        break;
                    }
                    $t = $p;
                    $d++;
                }
                return $d;
            };
            usort($terms, static fn ($a, $b) => [$a['taxonomy'] === 'tag' ? 1 : 0, $depth($a)] <=> [$b['taxonomy'] === 'tag' ? 1 : 0, $depth($b)]);
            return $terms;
        });
    }

    private static function phaseTerms(Job $job, Context $ctx, float $deadline): bool
    {
        $terms = self::termsList($job);
        $cur = &$job->d['state']['cursor'];
        $cur += ['i' => 0];
        while ($cur['i'] < count($terms)) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            $t = $terms[$cur['i']++];
            try {
                self::importTerm($job, $ctx, $t);
            } catch (\Throwable $e) {
                $job->count($t['taxonomy'] === 'tag' ? 'tags' : 'categories', 'skipped', 1, $e->getMessage());
            }
            self::tick($job, 'terms');
        }
        $c = $job->d['report']['counts'];
        $job->log(__('Catégories : {c} ; étiquettes : {t}.', ['c' => $c['categories']['imported'] + $c['categories']['updated'], 't' => $c['tags']['imported'] + $c['tags']['updated']]));
        return true;
    }

    private static function importTerm(Job $job, Context $ctx, array $t): void
    {
        $tax = $t['taxonomy'] === 'tag' ? 'tag' : 'category';
        $type = $tax === 'tag' ? 'tags' : 'categories';
        $wpSlug = (string) ($t['slug'] !== '' ? $t['slug'] : $t['name']);
        $slug = slugify($wpSlug) ?: 'terme';
        $key = $t['id'] > 0 ? $t['id'] : 'slug:' . $wpSlug;
        $parentId = null;
        if ($tax === 'category') {
            if ($t['parent'] > 0) {
                $parentId = $ctx->termId('category', (int) $t['parent']);
            } elseif (($t['parent_slug'] ?? '') !== '') {
                $parentId = $ctx->map('category', 'slug:' . $t['parent_slug']) ?? ((int) (Terms::bySlug('category', slugify($t['parent_slug']))['id'] ?? 0) ?: null);
            }
        }
        $name = trim(strip_tags(html_entity_decode((string) $t['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: $slug;
        $desc = trim(strip_tags(html_entity_decode((string) $t['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $existing = (is_int($key) ? $ctx->termId($tax, $key) : null) ?? ((int) (Terms::bySlug($tax, $slug)['id'] ?? 0) ?: null);
        $row = ['name' => mb_substr($name, 0, 120), 'description' => $desc, 'parent_id' => $parentId, 'wp_id' => $t['id'] > 0 ? (int) $t['id'] : null];
        if ($existing) {
            Db::update('terms', $row, ['id' => $existing]);
            $id = $existing;
            $job->count($type, 'updated');
        } else {
            $id = Terms::ensure($tax, $name, $slug, $parentId, $desc);
            Db::update('terms', ['wp_id' => $row['wp_id']], ['id' => $id]);
            $job->count($type, 'imported');
        }
        $ctx->setMap($tax, $key, $id);
        if ($t['id'] > 0) {
            $ctx->setMap($tax, 'slug:' . $wpSlug, $id);
        }
        // Adresse d'archive différente (base personnalisée, slug assaini) : redirection en fin de migration.
        $base = $tax === 'category' ? '/category/' : '/tag/';
        $realSlug = (string) (Terms::find($id)['slug'] ?? $slug);
        $old = (string) ($t['link'] ?? '') !== '' ? $ctx->wpPath((string) $t['link']) : null;
        if ($old === null && $wpSlug !== $realSlug) {
            $old = $base . $wpSlug;
        }
        // Wordless sert aussi /category/parent/enfant : pas de redirection dans ce cas.
        $served = $old !== null && str_starts_with($old, $base) && basename($old) === $realSlug && ($tax === 'category' || $old === $base . $realSlug);
        if ($old !== null && !$served) {
            $ctx->setMap('termpath', $old, $id);
        }
    }

    // ------------------------------------------------------------------
    // Phase 2 : auteurs
    // ------------------------------------------------------------------

    private static function usersList(Job $job): array
    {
        return self::cachedList($job, 'users', static function () use ($job): array {
            if (self::isWxr($job)) {
                return array_values(Wxr::header($job->workDir())['authors'] ?? []);
            }
            $auth = !empty($job->d['state']['auth']);
            return array_map([Rest::class, 'user'], self::restAll($job, '/wp/v2/users', $auth ? ['context' => 'edit'] : []));
        });
    }

    private static function phaseUsers(Job $job, Context $ctx, float $deadline): bool
    {
        if (empty($job->d['options']['users'])) {
            return true;
        }
        $users = self::usersList($job);
        $cur = &$job->d['state']['cursor'];
        $cur += ['i' => 0];
        while ($cur['i'] < count($users)) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            $u = $users[$cur['i']++];
            try {
                self::importUser($job, $ctx, $u);
            } catch (\Throwable $e) {
                $job->count('users', 'skipped', 1, $e->getMessage());
            }
            self::tick($job, 'users');
        }
        $c = $job->d['report']['counts']['users'];
        if ($c['imported'] > 0) {
            $job->notice(__('Les auteurs importés n’ont pas de mot de passe utilisable : invitez-les à choisir le leur avec « Mot de passe oublié » sur la page de connexion.'));
        }
        $job->log(__('Auteurs : {n} créés, {e} associés à des comptes existants.', ['n' => $c['imported'], 'e' => $c['updated']]));
        return true;
    }

    private static function importUser(Job $job, Context $ctx, array $u): void
    {
        $key = (int) $u['id'] > 0 ? (int) $u['id'] : 'login:' . $u['login'];
        $email = mb_strtolower(trim((string) $u['email']));
        $valid = (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
        $existing = is_int($key) ? $ctx->userId($key) : $ctx->existing('user', $key, 'users');
        if (!$existing && $valid) {
            $existing = (int) (Db::val('SELECT id FROM users WHERE email = ?', [$email]) ?: 0) ?: null;
        }
        $name = trim(strip_tags((string) $u['name'])) !== '' ? trim(strip_tags((string) $u['name'])) : (trim(strip_tags($u['first_name'] . ' ' . $u['last_name'])) ?: (string) $u['login']);
        $role = match ((string) ($u['role'] ?? '')) {
            'administrator' => 'admin',
            'editor' => 'editor',
            default => 'author',
        };
        if ($existing) {
            $row = Db::one('SELECT * FROM users WHERE id = ?', [$existing]);
            $upd = [];
            if (empty($row['wp_login'])) {
                $upd['wp_login'] = (string) $u['login'];
            }
            if (empty($row['wp_id']) && (int) $u['id'] > 0) {
                $upd['wp_id'] = (int) $u['id'];
            }
            if ((int) ($row['wp_id'] ?? 0) === (int) $u['id'] && $ctx->map('user', $key) === $existing && trim((string) ($u['bio'] ?? '')) !== '') {
                $upd['bio'] = mb_substr(trim(strip_tags((string) $u['bio'])), 0, 2000);
            }
            if ($upd) {
                Db::update('users', $upd, ['id' => $existing]);
            }
            $ctx->setMap('user', $key, $existing);
            $job->count('users', 'updated');
            return;
        }
        if (!$valid) {
            $email = self::placeholderEmail((string) ($u['login'] ?: $name));
            $job->warn('users', __('Auteurs sans adresse e-mail : adresse provisoire à remplacer'), $name);
        }
        $slug = self::uniqueUserSlug(slugify(rawurldecode((string) ($u['slug'] ?: $u['login'])) ?: $name) ?: 'auteur');
        $created = strtotime((string) ($u['registered'] ?? '') . ' UTC') ?: time();
        $id = Db::insert('users', [
            'email' => $email,
            'name' => mb_substr($name, 0, 120),
            'slug' => $slug,
            'role' => $role,
            // Mot de passe aléatoire jamais communiqué : la personne choisira le sien (« mot de passe oublié »).
            'password' => Auth::hashPassword(Crypto::token(32)),
            'bio' => mb_substr(trim(strip_tags((string) ($u['bio'] ?? ''))), 0, 2000),
            'status' => 'active',
            'wp_login' => (string) $u['login'],
            'wp_id' => (int) $u['id'] > 0 ? (int) $u['id'] : null,
            'created_at' => $created,
        ]);
        $ctx->setMap('user', $key, $id);
        if ((string) ($u['slug'] ?? '') !== '' && $slug !== slugify(rawurldecode((string) $u['slug']))) {
            $job->warn('users', __('Adresse d’archive d’auteur modifiée (nom déjà utilisé)'), '/author/' . $u['slug'] . ' -> /author/' . $slug);
        }
        $job->count('users', 'imported');
    }

    private static function uniqueUserSlug(string $base): string
    {
        $slug = $base;
        for ($i = 2; Db::val('SELECT 1 FROM users WHERE slug = ?', [$slug]); $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }

    private static function placeholderEmail(string $login): string
    {
        $base = slugify($login, 40) ?: 'auteur';
        $email = $base . '@import.invalid';
        for ($i = 2; Db::val('SELECT 1 FROM users WHERE email = ?', [$email]); $i++) {
            $email = $base . '-' . $i . '@import.invalid';
        }
        return $email;
    }

    /** Auteur Wordless d'un contenu (sinon l'administrateur qui lance la migration). */
    private static function authorFor(Job $job, Context $ctx, array $item): ?int
    {
        $fallback = (int) ($job->d['state']['fallback_user'] ?? 0) ?: null;
        if (empty($job->d['options']['users'])) {
            return $fallback;
        }
        if (isset($item['author_id']) && (int) $item['author_id'] > 0) {
            return $ctx->userId((int) $item['author_id']) ?? $fallback;
        }
        $login = (string) ($item['author'] ?? '');
        if ($login !== '') {
            foreach (self::usersList($job) as $u) {
                if ($u['login'] === $login) {
                    $key = (int) $u['id'] > 0 ? (int) $u['id'] : 'login:' . $login;
                    return (is_int($key) ? $ctx->userId($key) : $ctx->existing('user', $key, 'users')) ?? $fallback;
                }
            }
        }
        return $fallback;
    }

    // ------------------------------------------------------------------
    // Phase 3 : médias
    // ------------------------------------------------------------------

    private static function phaseMedia(Job $job, Context $ctx, float $deadline): bool
    {
        if (empty($job->d['options']['media'])) {
            return true;
        }
        $cur = &$job->d['state']['cursor'];
        $batch = 0;
        while (true) {
            if (microtime(true) >= $deadline || $batch >= 25) {
                return false;
            }
            $item = self::next($job, 'attachment', $cur);
            if ($item === null) {
                break;
            }
            $batch++;
            self::begin($job, 'media', 'attachment', (int) $item['id'], (string) $item['title']);
            try {
                self::importAttachment($job, $ctx, $item);
            } catch (\Throwable $e) {
                $job->count('media', 'skipped', 1, __('erreur'));
                $job->mediaFailed((string) ($item['attachment_url'] ?? ''), $e->getMessage());
            }
            self::end($job);
            self::tick($job, 'media');
        }
        $r = $job->d['report'];
        $job->log(__('Médias : {n} téléchargés, {f} en échec.', ['n' => $r['media']['downloaded'], 'f' => count($r['media']['failed'])]));
        return true;
    }

    private static function importAttachment(Job $job, Context $ctx, array $item): void
    {
        $wpId = (int) $item['id'];
        $url = trim((string) ($item['attachment_url'] !== '' ? $item['attachment_url'] : $item['guid']));
        $file = ltrim((string) ($item['meta']['_wp_attached_file'] ?? ''), '/');
        $u = $ctx->oldUrl($url);
        if ($file === '' && $u && $ctx->isUploads($u['path'])) {
            $file = $ctx->uploadsRel($u['path']);
        }
        // Fichier original (WordPress 5.3+ réduit les grandes images en « -scaled »).
        $sizes = (array) ($item['file_sizes'] ?? []);
        $original = (string) ($item['original_image'] ?? '');
        if (isset($item['meta']['_wp_attachment_metadata'])) {
            $meta = SeoMeta::unserialize((string) $item['meta']['_wp_attachment_metadata']);
            if (is_array($meta)) {
                $original = (string) ($meta['original_image'] ?? $original);
                foreach ((array) ($meta['sizes'] ?? []) as $s) {
                    if (!empty($s['file'])) {
                        $sizes[] = (string) $s['file'];
                    }
                }
            }
        }
        $dir = str_contains($file, '/') ? dirname($file) . '/' : '';
        $origRel = $original !== '' ? $dir . $original : Context::originalRel($file);
        $rels = array_values(array_unique(array_filter([$origRel, $file, ...array_map(static fn ($s) => $dir . basename($s), $sizes)])));

        $existing = $ctx->mediaId($wpId) ?? ($origRel !== '' ? $ctx->map('file', $origRel) : null);
        $m = $existing ? Media::find($existing) : null;
        if ($m) {
            Db::update('media', ['alt' => mb_substr(trim((string) ($item['meta']['_wp_attachment_image_alt'] ?? $m['alt'])), 0, 250), 'wp_id' => $wpId], ['id' => (int) $m['id']]);
            $job->count('media', 'updated');
        } else {
            $metaIn = [
                'alt' => (string) ($item['meta']['_wp_attachment_image_alt'] ?? ''),
                'title' => trim((string) $item['title']) !== '' ? (string) $item['title'] : Context::titleFromFile($file),
                'caption' => trim(strip_tags((string) ($item['excerpt'] ?? ''))),
                'wp_id' => $wpId,
                'created_at' => self::ts((string) ($item['date_gmt'] ?? '')) ?? time(),
            ];
            $candidates = [];
            if ($origRel !== '' && $origRel !== $file) {
                $candidates[] = $ctx->oldAbsolute($ctx->uploadsPath . '/' . Context::encodePath($origRel));
            }
            if ($url !== '') {
                $candidates[] = $url;
            }
            $error = __('adresse du fichier inconnue');
            foreach (array_unique($candidates) as $candidate) {
                try {
                    $m = Media::importUrl($candidate, $metaIn, Context::targetRel($origRel !== '' ? $origRel : basename($file)));
                    break;
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                }
            }
            if (!$m) {
                $job->mediaFailed($url, $error);
                $job->count('media', 'skipped', 1, __('téléchargement impossible'));
                $job->skipped('attachment', $wpId, (string) $item['title'], $error);
                return;
            }
            if ((int) ($m['wp_id'] ?? 0) === 0) {
                Db::update('media', ['wp_id' => $wpId], ['id' => (int) $m['id']]);
            }
            $job->d['report']['media']['downloaded']++;
            $job->count('media', 'imported');
            if (count($job->d['log']) < 50 || $wpId % 10 === 0) {
                $job->log(__('Média importé : {f}', ['f' => (string) $m['path']]));
            }
        }
        $id = (int) $m['id'];
        $ctx->setMap('media', $wpId, $id);
        foreach ($rels as $rel) {
            $ctx->setMap('file', $rel, $id);
        }
        $ctx->mediaRedirects($m, $rels);
        // Page de pièce jointe (/article/nom-image/) => fichier.
        $att = $ctx->wpPath((string) ($item['link'] ?? ''));
        if ($att !== null && $att !== '/' && !$ctx->isUploads($att)) {
            $ctx->setMap('attpath', $att, $id);
        }
    }

    // ------------------------------------------------------------------
    // Phase 4 : contenus (pages, puis articles, puis autres types)
    // ------------------------------------------------------------------

    private static function phaseContent(Job $job, Context $ctx, float $deadline): bool
    {
        $cur = &$job->d['state']['cursor'];
        $cur += ['kind' => 'page'];
        while (true) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            $kind = (string) $cur['kind'];
            $item = self::next($job, $kind, $cur);
            if ($item === null) {
                $nextKind = ['page' => 'post', 'post' => 'other'][$kind] ?? null;
                if ($nextKind === null) {
                    break;
                }
                $cur = ['kind' => $nextKind];
                continue;
            }
            $report = $kind === 'page' ? 'pages' : ($kind === 'post' ? 'posts' : 'other');
            self::begin($job, $report, (string) $item['type'], (int) $item['id'], (string) $item['title']);
            try {
                self::importContent($job, $ctx, $item, $kind);
            } catch (\Throwable $e) {
                App::logError($e);
                $job->count($report, 'skipped', 1, __('erreur'));
                $job->skipped((string) $item['type'], (int) $item['id'], (string) $item['title'], $e->getMessage());
            }
            self::end($job);
            self::tick($job, 'content');
        }
        self::resolveParents($job, $ctx);
        $c = $job->d['report']['counts'];
        $job->log(__('Contenus : {p} pages et {a} articles importés ou mis à jour.', [
            'p' => $c['pages']['imported'] + $c['pages']['updated'] + $c['other']['imported'] + $c['other']['updated'],
            'a' => $c['posts']['imported'] + $c['posts']['updated'],
        ]));
        return true;
    }

    private static function ts(string $date): ?int
    {
        $date = trim($date);
        if ($date === '' || str_starts_with($date, '0000-00-00')) {
            return null;
        }
        $t = strtotime($date . ' UTC');
        return $t === false ? null : $t;
    }

    /** Date locale WordPress => horodatage (fuseau du site importé). */
    private static function localTs(string $date): ?int
    {
        $date = trim($date);
        if ($date === '' || str_starts_with($date, '0000-00-00')) {
            return null;
        }
        try {
            $tz = new \DateTimeZone((string) Settings::get('timezone', 'Europe/Paris'));
            return (new \DateTimeImmutable($date, $tz))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function importContent(Job $job, Context $ctx, array $item, string $kind): void
    {
        $report = $kind === 'page' ? 'pages' : ($kind === 'post' ? 'posts' : 'other');
        $wpId = (int) $item['id'];
        $title = trim(html_entity_decode((string) $item['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $wpStatus = (string) $item['status'];
        if (in_array($wpStatus, ['trash', 'auto-draft', 'inherit'], true)) {
            $reason = $wpStatus === 'trash' ? __('dans la corbeille') : __('brouillon automatique');
            $job->count($report, 'skipped', 1, $reason);
            $job->skipped((string) $item['type'], $wpId, $title, $reason);
            return;
        }
        $ctx->issues = [];
        $status = match ($wpStatus) {
            'publish' => 'published',
            'future' => 'scheduled',
            'private' => 'private',
            default => 'draft',
        };
        if ((string) $item['password'] !== '') {
            if ($status === 'published' || $status === 'scheduled') {
                $status = 'private';
            }
            $ctx->issues[] = __('Contenu protégé par mot de passe sur WordPress : importé en privé (Wordless ne gère pas les mots de passe de page).');
            $job->warn('protected', __('Contenus protégés par mot de passe importés en privé'), $title);
        }
        // Brouillon : pas de date de publication (elle sera fixée à la publication, comme WordPress).
        $published = $status === 'draft' ? null
            : (self::ts((string) $item['date_gmt']) ?? self::localTs((string) $item['date']));
        if ($title === '') {
            $title = $kind === 'post' ? __('Article sans titre') : __('Page sans titre');
        }

        // Adresse : identique à WordPress. Sans permalien « joli » (brouillon, programmé,
        // ?p=123), elle est calculée avec la structure des permaliens du site.
        $wpPath = $ctx->wpPath((string) $item['link']);
        $slug = (string) $item['slug'] !== '' ? (string) $item['slug'] : (slugify($title) ?: ($kind === 'post' ? 'article-' : 'page-') . $wpId);
        $computed = null;
        $tpl = (string) ($item['permalink_template'] ?? '');
        if ($wpPath === null && $kind !== 'page' && str_contains($tpl, '%') && (string) $item['slug'] !== '') {
            $tplLink = str_replace(['%postname%', '%pagename%'], (string) $item['slug'], $tpl);
            $computed = !str_contains($tplLink, '%') ? $ctx->wpPath($tplLink) : null;
        }
        $path = $wpPath !== null && $wpPath !== '/' ? $wpPath : ($computed ?? self::computePath($job, $ctx, $item, $kind, $slug));

        // Conversion du contenu.
        $conv = new Convert($ctx, static fn (int $ref): ?string => self::pattern($job, $ref));
        if ((string) $item['password'] !== '' && !empty($item['rendered']) && trim(strip_tags((string) $item['content'])) === '') {
            $ctx->issues[] = __('Contenu protégé non accessible sans mot de passe d’application : seul le titre est importé.');
        }
        $blocks = $conv->item($item);
        if (!self::isWxr($job) && str_starts_with((string) ($item['template'] ?? ''), 'elementor')) {
            $ctx->issues[] = __('Page Elementor : l’API ne donne que le texte de secours (mise en page simplifiée). Pour une conversion complète, importez le fichier d’export WordPress.');
            $job->warn('elementor', __('Pages Elementor importées par l’API : mise en page simplifiée'), $title);
        }

        // Termes
        [$cats, $tags, $catNames, $tagNames] = self::itemTerms($ctx, $item);

        // SEO
        $site = (array) ($job->d['state']['site'] ?? []);
        $authorId = self::authorFor($job, $ctx, $item);
        $vars = [
            'title' => $title, 'sitename' => (string) ($site['name'] ?? ''), 'sitedesc' => (string) ($site['description'] ?? ''),
            'sep' => (string) Settings::get('seo.separator', '–'), 'excerpt' => excerpt(plain_text((string) $item['excerpt']), 155),
            'category' => $catNames[0] ?? '', 'tag' => $tagNames[0] ?? '', 'id' => $wpId,
            'author' => $authorId ? (string) Db::val('SELECT name FROM users WHERE id = ?', [$authorId]) : '',
            'date' => $published ? fmt_date($published) : '', 'modified' => '',
        ];
        $seo = SeoMeta::fromMeta((array) $item['meta'], $vars, $ctx, $wpPath);
        if (!$seo && is_array($item['yoast'] ?? null)) {
            $seo = SeoMeta::fromYoastHead($item['yoast'], $vars, $ctx, $wpPath);
        }
        if (!$seo && !self::isWxr($job) && !empty($job->d['options']['seo_fallback']) && $wpStatus === 'publish' && (string) $item['link'] !== '') {
            try {
                $res = HttpClient::get((string) $item['link'], ['timeout' => 20, 'max_bytes' => 4 * 1024 * 1024]);
                if ($res['status'] === 200) {
                    $seo = SeoMeta::fromHtml((string) $res['body'], $vars, $ctx, $wpPath);
                }
            } catch (\Throwable) {
                // pas de SEO de secours pour ce contenu
            }
        }

        $featuredWp = (int) ($item['meta']['_thumbnail_id'] ?? ($item['featured'] ?? 0));
        $data = [
            'type' => $kind === 'post' ? 'post' : 'page',
            'status' => $status,
            'title' => $title,
            'slug' => $slug,
            'excerpt' => mb_substr(plain_text((string) $item['excerpt']), 0, 1000),
            'blocks' => $blocks,
            'seo' => $seo,
            'featured_id' => $featuredWp > 0 ? $ctx->mediaId($featuredWp) : null,
            'author_id' => $authorId,
            'menu_order' => (int) $item['menu_order'],
            'comments' => (string) $item['comment_status'] === 'open' ? 1 : 0,
            'sticky' => !empty($item['sticky']) ? 1 : 0,
            'published_at' => $published,
            'updated_at' => self::ts((string) ($item['modified_gmt'] ?? '')) ?? time(),
            'wp_id' => $wpId,
        ];
        if ($kind === 'post') {
            $data['categories'] = $cats;
            $data['tags'] = $tags;
        } else {
            $parentWp = (int) $item['parent'];
            $data['parent_id'] = $parentWp > 0 ? $ctx->contentId($parentWp) : null;
        }
        if ($featuredWp > 0 && $data['featured_id'] === null && !empty($job->d['options']['media'])) {
            $ctx->issues[] = __('Image à la une introuvable (#{id}).', ['id' => $featuredWp]);
        }

        $existing = $ctx->contentId($wpId);
        $id = Content::save($data, $existing, ['path' => $path, 'trusted' => false, 'silent' => true]);
        $created = self::ts((string) $item['date_gmt']) ?? self::localTs((string) $item['date']);
        if (!$existing && $created) {
            Db::update('content', ['created_at' => $created], ['id' => $id]);
        }
        $ctx->setMap('content', $wpId, $id);
        if ($wpPath !== null) {
            $ctx->setMap('path', $wpPath, $id);
        }
        if ($wpPath === '/') {
            $job->d['state']['front_content'] = $id;
        }
        if ($kind !== 'post' && (int) $item['parent'] > 0 && $data['parent_id'] === null) {
            $job->d['state']['pending_parents'][(string) $id] = (int) $item['parent'];
        }
        // Anciennes adresses (slug modifié dans WordPress) => redirection en fin de migration.
        foreach ((array) ($item['old_slugs'] ?? []) as $old) {
            $old = (string) $old;
            if ($old !== '' && $wpPath !== null && $wpPath !== '/' && $old !== $slug) {
                $oldPath = (string) preg_replace('#' . preg_quote($slug, '#') . '(?=[^/]*$)#', $old, $wpPath, 1);
                if ($oldPath !== $wpPath) {
                    $ctx->setMap('oldpath', $oldPath, $id);
                }
            }
        }
        $saved = (string) Db::val('SELECT path FROM content WHERE id = ?', [$id]);
        if ($ctx->issues) {
            $job->review($id, $title, $saved, $ctx->issues);
        }
        $job->count($report, $existing ? 'updated' : 'imported');
        $vars = ['t' => $title, 'p' => $wpPath === '/' ? '/' : $saved];
        $job->log($existing ? __('Mis à jour : {t} ({p})', $vars) : __('Importé : {t} ({p})', $vars));
    }

    /** Chemin d'un contenu sans permalien « joli » (brouillon, programmé, ?p=123). */
    private static function computePath(Job $job, Context $ctx, array $item, string $kind, string $slug): string
    {
        if ($kind !== 'post') {
            $prefix = '';
            if ((int) $item['parent'] > 0 && ($pid = $ctx->contentId((int) $item['parent']))) {
                $prefix = rtrim((string) Db::val('SELECT path FROM content WHERE id = ?', [$pid]), '/');
            } elseif ($kind === 'other') {
                $prefix = '/' . slugify((string) $item['type']);
            }
            return $prefix . '/' . $slug;
        }
        $pattern = (string) ($job->d['state']['permalink'] ?? '') ?: '/{slug}';
        $ts = self::localTs((string) $item['date']) ?? time();
        $d = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone((string) Settings::get('timezone', 'Europe/Paris')));
        $cat = 'non-classe';
        foreach ((array) ($item['terms'] ?? []) as $t) {
            if ($t['taxonomy'] === 'category') {
                $cat = slugify((string) $t['slug']) ?: $cat;
                break;
            }
        }
        if (!empty($item['term_ids']['category'][0]) && ($tid = $ctx->termId('category', (int) $item['term_ids']['category'][0]))) {
            $cat = (string) (Terms::find($tid)['slug'] ?? $cat);
        }
        return Content::normalizePath(strtr($pattern, [
            '{slug}' => $slug, '{year}' => $d->format('Y'), '{month}' => $d->format('m'), '{day}' => $d->format('d'),
            '{category}' => $cat, '{id}' => (string) $item['id'],
        ]));
    }

    /** @return array{0: list<int>, 1: list<int>, 2: list<string>, 3: list<string>} */
    private static function itemTerms(Context $ctx, array $item): array
    {
        $cats = [];
        $tags = [];
        foreach ((array) ($item['terms'] ?? []) as $t) {
            $tax = $t['taxonomy'] === 'post_tag' ? 'tag' : ($t['taxonomy'] === 'category' ? 'category' : null);
            if ($tax === null) {
                continue;
            }
            $id = $ctx->map($tax, 'slug:' . $t['slug']) ?? ((int) (Terms::bySlug($tax, slugify($t['slug']) ?: '-')['id'] ?? 0) ?: null);
            if ($id === null && trim((string) $t['name']) !== '') {
                $id = Terms::ensure($tax, (string) $t['name'], $t['slug']); // terme absent de l'en-tête du fichier
            }
            if ($id) {
                $tax === 'tag' ? $tags[] = $id : $cats[] = $id;
            }
        }
        foreach (['category', 'tag'] as $tax) {
            foreach ((array) ($item['term_ids'][$tax] ?? []) as $wpId) {
                if ($id = $ctx->termId($tax, (int) $wpId)) {
                    $tax === 'tag' ? $tags[] = $id : $cats[] = $id;
                }
            }
        }
        $cats = array_values(array_unique($cats));
        $tags = array_values(array_unique($tags));
        $names = static fn (array $ids): array => array_map(static fn ($id) => (string) (Terms::find($id)['name'] ?? ''), $ids);
        return [$cats, $tags, $names($cats), $names($tags)];
    }

    /** Contenu brut d'une composition synchronisée (wp_block). */
    private static function pattern(Job $job, int $ref): ?string
    {
        $all = self::cachedList($job, 'patterns', static function () use ($job): array {
            $out = [];
            if (self::isWxr($job)) {
                foreach (Wxr::all($job->workDir(), 'wp_block') as $b) {
                    $out[] = ['id' => (int) $b['id'], 'content' => (string) $b['content']];
                }
                return $out;
            }
            $auth = !empty($job->d['state']['auth']);
            foreach (self::restAll($job, '/wp/v2/blocks', $auth ? ['context' => 'edit'] : []) as $b) {
                $out[] = ['id' => (int) $b['id'], 'content' => (string) ($b['content']['raw'] ?? ($b['content']['rendered'] ?? ''))];
            }
            return $out;
        });
        foreach ($all as $p) {
            if ((int) $p['id'] === $ref) {
                return (string) $p['content'];
            }
        }
        return null;
    }

    /** Pages dont le parent a été importé après elles. */
    private static function resolveParents(Job $job, Context $ctx): void
    {
        $pending = (array) ($job->d['state']['pending_parents'] ?? []);
        foreach ($pending as $id => $wpParent) {
            $pid = $ctx->contentId((int) $wpParent);
            if ($pid && $pid !== (int) $id) {
                Db::update('content', ['parent_id' => $pid], ['id' => (int) $id]);
            }
        }
        $job->d['state']['pending_parents'] = [];
    }

    // ------------------------------------------------------------------
    // Phase 5 : commentaires
    // ------------------------------------------------------------------

    private static function phaseComments(Job $job, Context $ctx, float $deadline): bool
    {
        if (empty($job->d['options']['comments'])) {
            return true;
        }
        $cur = &$job->d['state']['cursor'];
        while (true) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            $c = self::next($job, 'comments', $cur);
            if ($c === null) {
                break;
            }
            try {
                self::importComment($job, $ctx, $c);
            } catch (\Throwable $e) {
                $job->count('comments', 'skipped', 1, __('erreur'));
            }
            self::tick($job, 'comments');
        }
        foreach ((array) ($job->d['state']['comments_skipped'] ?? []) as $reason => $n) {
            if ((int) $n > 0) {
                $job->count('comments', 'skipped', (int) $n, (string) $reason);
            }
        }
        $r = $job->d['report']['counts']['comments'];
        $job->log(__('Commentaires : {n} importés, {s} ignorés.', ['n' => $r['imported'] + $r['updated'], 's' => $r['skipped']]));
        return true;
    }

    private static function importComment(Job $job, Context $ctx, array $c): void
    {
        if (in_array($c['type'], ['pingback', 'trackback'], true)) {
            $job->count('comments', 'skipped', 1, __('rétroliens (pingbacks) non importés'));
            return;
        }
        $status = match ((string) $c['approved']) {
            '1' => 'approved',
            '0' => 'pending',
            default => null,
        };
        if ($status === null) {
            $job->count('comments', 'skipped', 1, __('indésirables ou à la corbeille'));
            return;
        }
        $contentId = $ctx->contentId((int) $c['post']);
        if (!$contentId) {
            $job->count('comments', 'skipped', 1, __('contenu non importé'));
            return;
        }
        $email = mb_strtolower(trim((string) $c['email']));
        $url = trim((string) $c['url']);
        $author = mb_substr(trim(strip_tags(html_entity_decode((string) $c['author'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 80);
        $row = [
            'content_id' => $contentId,
            'parent_id' => (int) $c['parent'] > 0 ? $ctx->existing('comment', (int) $c['parent'], 'comments') : null,
            'user_id' => (int) $c['user_id'] > 0 ? $ctx->userId((int) $c['user_id']) : null,
            'author' => $author !== '' ? $author : __('Anonyme'),
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
            'url' => preg_match('#^https?://#i', $url) ? mb_substr(Html::safeUrl($url), 0, 300) : '',
            'body' => Html::clean(Html::autop((string) $c['content']), 'comment'),
            'status' => $status,
            'ip_hash' => trim((string) $c['ip']) !== '' ? ip_hash(trim((string) $c['ip'])) : '',
            'created_at' => self::ts((string) $c['date_gmt']) ?? self::localTs((string) $c['date']) ?? time(),
            'wp_id' => (int) $c['id'],
        ];
        if ($row['body'] === '') {
            $job->count('comments', 'skipped', 1, __('vides'));
            return;
        }
        $existing = $ctx->existing('comment', (int) $c['id'], 'comments');
        if ($existing) {
            Db::update('comments', $row, ['id' => $existing]);
            $job->count('comments', 'updated');
            return;
        }
        $id = Db::insert('comments', $row);
        $ctx->setMap('comment', (int) $c['id'], $id);
        $job->count('comments', 'imported');
    }

    // ------------------------------------------------------------------
    // Phase 6 : menus
    // ------------------------------------------------------------------

    /** Menus normalisés : [{key, name, slug, locations[], items: [{id, parent, order, label, type, object, object_id, url}]}] */
    private static function menusList(Job $job): array
    {
        return self::cachedList($job, 'menus', static function () use ($job): array {
            $menus = [];
            if (self::isWxr($job)) {
                $items = Wxr::all($job->workDir(), 'nav_menu_item');
                foreach (Wxr::header($job->workDir())['menus'] ?? [] as $m) {
                    $menus[$m['slug']] = ['key' => 'menu:' . ($m['id'] ?: $m['slug']), 'name' => $m['name'], 'slug' => $m['slug'], 'locations' => [], 'items' => []];
                }
                foreach ($items as $it) {
                    $menuSlug = '';
                    $menuName = '';
                    foreach ($it['terms'] as $t) {
                        if ($t['taxonomy'] === 'nav_menu') {
                            $menuSlug = $t['slug'];
                            $menuName = (string) $t['name'];
                        }
                    }
                    if ($menuSlug === '' || ($it['status'] ?? 'publish') !== 'publish') {
                        continue;
                    }
                    if (!isset($menus[$menuSlug])) {
                        // Menu absent de l'en-tête du fichier : nom repris du lien.
                        $menus[$menuSlug] = ['key' => 'menu:' . $menuSlug, 'name' => $menuName !== '' ? $menuName : $menuSlug, 'slug' => $menuSlug, 'locations' => [], 'items' => []];
                    }
                    $meta = $it['meta'];
                    $menus[$menuSlug]['items'][] = [
                        'id' => (int) $it['id'], 'parent' => (int) ($meta['_menu_item_menu_item_parent'] ?? 0), 'order' => (int) $it['menu_order'],
                        'label' => trim(html_entity_decode((string) $it['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                        'type' => (string) ($meta['_menu_item_type'] ?? 'custom'), 'object' => (string) ($meta['_menu_item_object'] ?? ''),
                        'object_id' => (int) ($meta['_menu_item_object_id'] ?? 0), 'url' => (string) ($meta['_menu_item_url'] ?? ''),
                    ];
                }
                if (!$menus) {
                    foreach (Wxr::all($job->workDir(), 'wp_navigation') as $nav) {
                        if (($nav['status'] ?? '') === 'publish') {
                            $menus[] = ['key' => 'nav:' . $nav['id'], 'name' => (string) $nav['title'], 'slug' => (string) $nav['slug'], 'locations' => [], 'items' => self::navigationItems((string) $nav['content'], false)];
                        }
                    }
                }
                return array_values($menus);
            }
            if (!empty($job->d['state']['auth'])) {
                $api = self::rest($job);
                foreach ((array) ($api->get('/wp/v2/menus', ['context' => 'edit', 'per_page' => 100]) ?? []) as $m) {
                    $menus[(int) $m['id']] = ['key' => 'menu:' . (int) $m['id'], 'name' => html_entity_decode((string) $m['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'slug' => (string) $m['slug'], 'locations' => (array) ($m['locations'] ?? []), 'items' => []];
                }
                if ($menus) {
                    foreach (self::restAll($job, '/wp/v2/menu-items', ['context' => 'edit']) as $it) {
                        $mid = (int) ($it['menus'] ?? 0);
                        if (!isset($menus[$mid]) || ($it['status'] ?? 'publish') !== 'publish') {
                            continue;
                        }
                        $menus[$mid]['items'][] = [
                            'id' => (int) $it['id'], 'parent' => (int) ($it['parent'] ?? 0), 'order' => (int) ($it['menu_order'] ?? 0),
                            'label' => Rest::text($it['title'] ?? ''), 'type' => (string) ($it['type'] ?? 'custom'),
                            'object' => (string) ($it['object'] ?? ''), 'object_id' => (int) ($it['object_id'] ?? 0), 'url' => (string) ($it['url'] ?? ''),
                        ];
                    }
                    return array_values($menus);
                }
            }
            // Thème à blocs ou accès public : menus de navigation (wp_navigation).
            $auth = !empty($job->d['state']['auth']);
            foreach (self::restAll($job, '/wp/v2/navigation', $auth ? ['context' => 'edit'] : []) as $nav) {
                $raw = is_array($nav['content'] ?? null) && array_key_exists('raw', $nav['content']);
                $menus[] = ['key' => 'nav:' . (int) $nav['id'], 'name' => Rest::text($nav['title'] ?? ''), 'slug' => (string) ($nav['slug'] ?? ''), 'locations' => [],
                    'items' => self::navigationItems((string) ($raw ? $nav['content']['raw'] : ($nav['content']['rendered'] ?? '')), !$raw)];
            }
            return $menus;
        });
    }

    /** Liens d'un menu de navigation (blocs core/navigation-link ou HTML rendu). */
    private static function navigationItems(string $content, bool $rendered): array
    {
        $items = [];
        $seq = 0;
        if ($rendered) {
            [, $root] = Html::load($content);
            $walk = static function (\DOMNode $ul, int $parent) use (&$walk, &$items, &$seq): void {
                foreach ($ul->childNodes as $li) {
                    if (!$li instanceof \DOMElement || strtolower($li->tagName) !== 'li') {
                        continue;
                    }
                    $a = null;
                    $sub = null;
                    foreach ($li->childNodes as $c) {
                        if ($c instanceof \DOMElement && strtolower($c->tagName) === 'a' && !$a) {
                            $a = $c;
                        } elseif ($c instanceof \DOMElement && strtolower($c->tagName) === 'ul') {
                            $sub = $c;
                        }
                    }
                    if (!$a) {
                        continue;
                    }
                    $id = ++$seq;
                    $items[] = ['id' => $id, 'parent' => $parent, 'order' => $id, 'label' => trim($a->textContent), 'type' => 'custom', 'object' => '', 'object_id' => 0, 'url' => $a->getAttribute('href')];
                    if ($sub) {
                        $walk($sub, $id);
                    }
                }
            };
            $walk($root, 0);
            return $items;
        }
        $walk = static function (array $blocks, int $parent) use (&$walk, &$items, &$seq): void {
            foreach ($blocks as $b) {
                $name = (string) $b['blockName'];
                $a = $b['attrs'];
                if (in_array($name, ['core/navigation-link', 'core/navigation-submenu', 'core/home-link'], true)) {
                    $id = ++$seq;
                    $kind = (string) ($a['kind'] ?? 'custom');
                    $type = $kind === 'post-type' ? 'post_type' : ($kind === 'taxonomy' ? 'taxonomy' : 'custom');
                    $object = (string) ($a['type'] ?? '');
                    $items[] = [
                        'id' => $id, 'parent' => $parent, 'order' => $id, 'label' => plain_text((string) ($a['label'] ?? '')),
                        'type' => $name === 'core/home-link' ? 'custom' : $type, 'object' => $object === 'tag' ? 'post_tag' : $object,
                        'object_id' => (int) ($a['id'] ?? 0), 'url' => $name === 'core/home-link' ? '/' : (string) ($a['url'] ?? ''),
                    ];
                    if ($b['innerBlocks']) {
                        $walk($b['innerBlocks'], $id);
                    }
                } elseif ($b['innerBlocks']) {
                    $walk($b['innerBlocks'], $parent);
                }
            }
        };
        $walk(Convert::parse($content), 0);
        return $items;
    }

    private static function phaseMenus(Job $job, Context $ctx, float $deadline): bool
    {
        if (empty($job->d['options']['menus'])) {
            return true;
        }
        $menus = self::menusList($job);
        $cur = &$job->d['state']['cursor'];
        $cur += ['i' => 0];
        while ($cur['i'] < count($menus)) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            $m = $menus[$cur['i']++];
            try {
                self::importMenu($job, $ctx, $m);
            } catch (\Throwable $e) {
                $job->count('menus', 'skipped', 1, $e->getMessage());
            }
            self::tick($job, 'menus');
        }
        self::assignMenuLocations($job, $ctx, $menus);
        return true;
    }

    private static function importMenu(Job $job, Context $ctx, array $menu): void
    {
        $byParent = [];
        foreach ($menu['items'] as $it) {
            $byParent[(int) $it['parent']][] = $it;
        }
        foreach ($byParent as &$list) {
            usort($list, static fn ($a, $b) => [$a['order'], $a['id']] <=> [$b['order'], $b['id']]);
        }
        unset($list);
        $known = array_flip(array_map(static fn ($i) => (int) $i['id'], $menu['items']));
        // Éléments dont le parent est absent : rattachés à la racine.
        foreach ($byParent as $pid => $list) {
            if ($pid !== 0 && !isset($known[$pid])) {
                $byParent[0] = array_merge($byParent[0] ?? [], $list);
                unset($byParent[$pid]);
            }
        }
        $count = 0;
        $build = function (int $parent, int $depth) use (&$build, $byParent, $ctx, $job, &$count): array {
            $out = [];
            foreach ($byParent[$parent] ?? [] as $it) {
                $entry = self::menuEntry($ctx, $job, $it);
                $children = $build((int) $it['id'], $depth + 1);
                if ($entry === null) {
                    array_push($out, ...$children); // lien introuvable : ses enfants remontent
                    continue;
                }
                $count++;
                if ($depth >= 2) {
                    // Wordless affiche 3 niveaux : les niveaux plus profonds remontent au 3e.
                    $out[] = $entry;
                    array_push($out, ...self::flattenMenu($children));
                    continue;
                }
                $entry['children'] = $children;
                $out[] = $entry;
            }
            return $out;
        };
        $items = $build(0, 0);
        $name = trim(strip_tags((string) $menu['name'])) !== '' ? mb_substr(trim(strip_tags((string) $menu['name'])), 0, 120) : __('Menu');
        $existing = $ctx->existing('menu', (string) $menu['key'], 'menus');
        if ($existing) {
            Db::update('menus', ['name' => $name, 'items' => json_enc($items)], ['id' => $existing]);
            $id = $existing;
            $job->count('menus', 'updated');
        } else {
            $id = Db::insert('menus', ['name' => $name, 'location' => null, 'items' => json_enc($items)]);
            $job->count('menus', 'imported');
        }
        $ctx->setMap('menu', (string) $menu['key'], $id);
        $job->log(__('Menu « {m} » importé ({n} liens).', ['m' => $name, 'n' => $count]));
    }

    private static function flattenMenu(array $items): array
    {
        $out = [];
        foreach ($items as $it) {
            $children = $it['children'] ?? [];
            $it['children'] = [];
            $out[] = $it;
            array_push($out, ...self::flattenMenu($children));
        }
        return $out;
    }

    /** Élément de menu Wordless : {label, type content|term|url, id, url, children}. */
    private static function menuEntry(Context $ctx, Job $job, array $it): ?array
    {
        $label = mb_substr(trim(strip_tags(html_entity_decode((string) $it['label'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 0, 120);
        $url = trim((string) $it['url']);
        if ($url !== '' && Html::safeUrl($url) === '') {
            $job->warn('menus', __('Liens de menu non sûrs (javascript:…) retirés'), $label);
            return null;
        }
        if ($it['type'] === 'post_type' && $it['object_id'] > 0) {
            $cid = $ctx->contentId((int) $it['object_id']);
            if ($cid) {
                return ['label' => $label !== '' ? $label : (string) Db::val('SELECT title FROM content WHERE id = ?', [$cid]), 'type' => 'content', 'id' => $cid, 'url' => '', 'children' => []];
            }
        } elseif ($it['type'] === 'taxonomy' && $it['object_id'] > 0 && in_array($it['object'], ['category', 'post_tag'], true)) {
            $tid = $ctx->termId($it['object'] === 'post_tag' ? 'tag' : 'category', (int) $it['object_id']);
            if ($tid) {
                return ['label' => $label !== '' ? $label : (string) (Terms::find($tid)['name'] ?? ''), 'type' => 'term', 'id' => $tid, 'url' => '', 'children' => []];
            }
        }
        if ($url === '' && $it['type'] !== 'custom') {
            $job->warn('menus', __('Liens de menu vers des contenus non importés : retirés'), $label);
            return null;
        }
        if ($url === '' || $label === '') {
            return $url === '' ? null : ['label' => $label !== '' ? $label : $url, 'type' => 'url', 'id' => null, 'url' => $url, 'children' => []];
        }
        if ($url === '#') {
            return ['label' => $label, 'type' => 'url', 'id' => null, 'url' => '#', 'children' => []];
        }
        // Lien court ?p=123 / ?page_id=45 vers un contenu importé.
        $u = $ctx->oldUrl($url);
        if ($u !== null && preg_match('/[?&](?:p|page_id)=(\d+)/', $u['query'], $m) && ($cid = $ctx->contentId((int) $m[1]))) {
            return ['label' => $label, 'type' => 'content', 'id' => $cid, 'url' => '', 'children' => []];
        }
        // Lien vers une page importée (menus de navigation) : rattaché au contenu.
        $path = $ctx->wpPath($url);
        if ($path !== null) {
            $cid = $path === '/' ? null : $ctx->map('path', $path);
            if ($cid && Db::val("SELECT id FROM content WHERE id = ? AND status <> 'trash'", [$cid])) {
                return ['label' => $label, 'type' => 'content', 'id' => $cid, 'url' => '', 'children' => []];
            }
        }
        return ['label' => $label, 'type' => 'url', 'id' => null, 'url' => $ctx->rewriteUrl($url, true), 'children' => []];
    }

    /** Emplacements : menu principal (emplacement WordPress ou plus grand menu) et pied de page. */
    private static function assignMenuLocations(Job $job, Context $ctx, array $menus): void
    {
        if (!$menus) {
            return;
        }
        $force = !empty($job->d['options']['settings']);
        $primary = null;
        $footer = null;
        $biggest = null;
        $max = -1;
        foreach ($menus as $m) {
            $id = $ctx->existing('menu', (string) $m['key'], 'menus');
            if (!$id) {
                continue;
            }
            $hay = mb_strtolower($m['name'] . ' ' . $m['slug'] . ' ' . implode(' ', (array) $m['locations']));
            if ($footer === null && preg_match('/footer|pied|bas-de-page|bas de page|pie de pagina/u', $hay)) {
                $footer = $id;
                continue;
            }
            if ($primary === null && preg_match('/primary|main|principal|header|en-t[êe]te|menu-1|top|navigation/u', $hay)) {
                $primary = $id;
            }
            if (count($m['items']) > $max) {
                $max = count($m['items']);
                $biggest = $id;
            }
        }
        $primary ??= $biggest;
        $locs = (array) Settings::get('menus.locations', []);
        if ($primary && ($force || empty($locs['primary']))) {
            $locs['primary'] = $primary;
            Db::run('UPDATE menus SET location = NULL WHERE location = ?', ['primary']);
            Db::update('menus', ['location' => 'primary'], ['id' => $primary]);
        }
        if ($footer && $footer !== $primary && ($force || empty($locs['footer']))) {
            $locs['footer'] = $footer;
            Db::run('UPDATE menus SET location = NULL WHERE location = ?', ['footer']);
            Db::update('menus', ['location' => 'footer'], ['id' => $footer]);
        }
        Settings::set('menus.locations', $locs);
    }

    // ------------------------------------------------------------------
    // Phase 7 : finalisation
    // ------------------------------------------------------------------

    private static function phaseFinalize(Job $job, Context $ctx, float $deadline): bool
    {
        $cur = &$job->d['state']['cursor'];
        $cur += ['sub' => 'links', 'after' => 0];
        while (true) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            switch ($cur['sub']) {
                case 'links':
                    if (!self::fixLinks($job, $ctx, $cur, $deadline)) {
                        return false;
                    }
                    $cur = ['sub' => 'settings', 'after' => 0];
                    self::tick($job, 'finalize');
                    break;
                case 'settings':
                    if (!empty($job->d['options']['settings'])) {
                        self::homeSettings($job, $ctx);
                    }
                    $cur = ['sub' => 'redirects', 'after' => 0];
                    self::tick($job, 'finalize');
                    break;
                case 'redirects':
                    if (!self::redirects($job, $ctx, $cur, $deadline)) {
                        return false;
                    }
                    $cur = ['sub' => 'search', 'after' => 0];
                    self::tick($job, 'finalize');
                    break;
                case 'search':
                    $n = Search::rebuild();
                    Content::scheduleNext();
                    Cache::purge();
                    $job->log(__('Index de recherche reconstruit ({n} contenus).', ['n' => $n]));
                    $cur = ['sub' => 'report', 'after' => 0];
                    self::tick($job, 'finalize');
                    break;
                default:
                    self::finishReport($job, $ctx);
                    self::tick($job, 'finalize');
                    return true;
            }
        }
    }

    /** Liens ?p=123 / ?page_id=123 / ?attachment_id=123 => adresse définitive. */
    private static function fixLinks(Job $job, Context $ctx, array &$cur, float $deadline): bool
    {
        $base = Config::basePath();
        while (true) {
            $rows = Db::all(
                "SELECT c.id, c.blocks FROM content c JOIN import_map m ON m.new_id = c.id AND m.source = ? AND m.kind = 'content'
                 WHERE c.id > ? AND (c.blocks LIKE '%?p=%' OR c.blocks LIKE '%page_id=%' OR c.blocks LIKE '%attachment_id=%') ORDER BY c.id LIMIT 50",
                [$ctx->source, (int) $cur['after']]
            );
            if (!$rows) {
                return true;
            }
            foreach ($rows as $r) {
                $cur['after'] = (int) $r['id'];
                $blocks = json_dec((string) $r['blocks'], []);
                $changed = false;
                array_walk_recursive($blocks, static function (&$v) use ($ctx, $base, &$changed): void {
                    if (!is_string($v) || !preg_match('/[?&](p|page_id|attachment_id)=\d/', $v)) {
                        return;
                    }
                    $whole = (bool) preg_match('#^(?:' . preg_quote($base, '#') . ')?/(?:index\.php)?\?(?:p|page_id|attachment_id)=\d+$#', $v);
                    $new = preg_replace_callback('#(?:' . preg_quote($base, '#') . ')?/(?:index\.php)?\?(p|page_id|attachment_id)=(\d+)(?=["\'\s<>&\#]|$)#', static function ($m) use ($ctx, $base, $whole) {
                        if ($m[1] === 'attachment_id') {
                            $mid = $ctx->mediaId((int) $m[2]);
                            $media = $mid ? Media::find($mid) : null;
                            return $media ? ($whole ? '/media/' . $media['path'] : Media::url($media)) : $m[0];
                        }
                        $cid = $ctx->contentId((int) $m[2]);
                        $path = $cid ? Db::val('SELECT path FROM content WHERE id = ?', [$cid]) : null;
                        return $path ? ($whole ? (string) $path : $base . link_path((string) $path)) : $m[0];
                    }, $v);
                    if ($new !== null && $new !== $v) {
                        $v = $new;
                        $changed = true;
                    }
                });
                if ($changed) {
                    Db::update('content', ['blocks' => json_enc($blocks)], ['id' => (int) $r['id']]);
                }
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
        }
    }

    /** Redirections 301 : contenus dont l'adresse a changé, anciens slugs, pages de pièces jointes, archives. */
    private static function redirects(Job $job, Context $ctx, array &$cur, float $deadline): bool
    {
        $urls = &$job->d['report']['urls'];
        $wlBase = Config::basePath();
        $prefixed = $ctx->homePath !== $wlBase; // WordPress et Wordless installés dans des dossiers différents
        while (true) {
            $rows = Db::all("SELECT rowid AS rid, kind, old_id, new_id FROM import_map WHERE source = ? AND kind IN ('path', 'oldpath', 'attpath', 'termpath') AND rowid > ? ORDER BY rowid LIMIT 200",
                [$ctx->source, (int) $cur['after']]);
            if (!$rows) {
                return true;
            }
            foreach ($rows as $r) {
                $cur['after'] = (int) $r['rid'];
                $old = (string) $r['old_id'];
                $target = null;
                if ($r['kind'] === 'attpath') {
                    $m = Media::find((int) $r['new_id']);
                    $target = $m ? '/media/' . $m['path'] : null;
                } elseif ($r['kind'] === 'termpath') {
                    $t = Terms::find((int) $r['new_id']);
                    $target = $t ? ($t['taxonomy'] === 'category' ? '/category/' : '/tag/') . $t['slug'] : null;
                } else {
                    $c = Db::one('SELECT id, path, status FROM content WHERE id = ?', [(int) $r['new_id']]);
                    if (!$c || $c['status'] !== 'published') {
                        continue; // seules les adresses publiques comptent
                    }
                    $target = (string) $c['path'];
                    if ($r['kind'] === 'path') {
                        $urls['total']++;
                        $isHome = (int) $c['id'] === (int) Settings::get('home.page_id') && Settings::get('home.type') === 'page';
                        if ($old === '/' && !$isHome) {
                            $urls['conflicts'][] = ['path' => '/', 'reason' => __('l’accueil WordPress n’est pas la page d’accueil de Wordless')];
                            continue;
                        }
                        if ($old === $target || $old === '/') {
                            $urls['identical']++;
                            if (count($urls['identical_list']) < 1000) {
                                $urls['identical_list'][] = $old;
                            }
                            if ($prefixed && $old !== '/') {
                                self::prefixRedirect($ctx, $old, $target);
                            }
                            continue;
                        }
                    }
                }
                if ($target === null || $old === $target || $old === '/') {
                    continue;
                }
                // Ne jamais masquer un contenu Wordless publié à cette adresse.
                $occupied = Db::val("SELECT id FROM content WHERE path = ? AND status <> 'trash'", [$old]);
                if ($occupied) {
                    if ($r['kind'] === 'path' && count($urls['conflicts']) < 300) {
                        $urls['conflicts'][] = ['path' => $old, 'reason' => __('adresse déjà utilisée par un autre contenu')];
                    }
                    continue;
                }
                $known = Db::val('SELECT target FROM redirects WHERE source = ?', [Redirects::normalize($old)]);
                Redirects::add($old, $target, 301);
                $job->count('redirects', $known === null ? 'imported' : 'updated');
                if ($r['kind'] === 'path') {
                    $urls['redirected']++;
                    if (count($urls['redirected_list']) < 1000) {
                        $urls['redirected_list'][] = ['from' => $old, 'to' => $target];
                    }
                }
                if ($prefixed) {
                    self::prefixRedirect($ctx, $old, $target);
                }
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
        }
    }

    /** WordPress était dans un sous-dossier différent de Wordless : l'ancienne adresse complète est redirigée. */
    private static function prefixRedirect(Context $ctx, string $old, string $target): void
    {
        $base = Config::basePath();
        $full = rtrim($ctx->homePath . $old, '/');
        if ($full === '' || ($base !== '' && !str_starts_with($full, $base . '/'))) {
            return;
        }
        $source = $base !== '' ? substr($full, strlen($base)) : $full;
        if ($source !== $target && $source !== '/' && !Db::val("SELECT id FROM content WHERE path = ? AND status <> 'trash'", [$source])) {
            Redirects::add($source, $target, 301);
        }
    }

    /** Page d'accueil et page des articles. */
    private static function homeSettings(Job $job, Context $ctx): void
    {
        $site = (array) ($job->d['state']['site'] ?? []);
        $front = $site['show_on_front'] ?? null;
        $homeId = !empty($site['page_on_front']) ? $ctx->contentId((int) $site['page_on_front']) : null;
        $homeId ??= !empty($job->d['state']['front_content']) ? (int) $job->d['state']['front_content'] : null;
        if (!$homeId && $front !== 'posts') {
            $homeId = self::guessPage($ctx, ['accueil', 'home', 'inicio', 'startseite', 'homepage', 'page-d-accueil', 'start', 'inizio', 'portada'], false);
        }
        $blogId = !empty($site['page_for_posts']) ? $ctx->contentId((int) $site['page_for_posts']) : null;
        if (!$blogId && empty($site['page_for_posts'])) {
            $blogId = self::guessPage($ctx, ['blog', 'actualites', 'news', 'articles', 'journal', 'actus', 'le-blog', 'noticias', 'blogue'], true);
        }
        $set = [];
        if ($front === 'posts' || !$homeId) {
            $set['home.type'] = 'posts';
            $set['home.page_id'] = null;
        } else {
            $set['home.type'] = 'page';
            $set['home.page_id'] = $homeId;
        }
        if ($blogId && $blogId !== $homeId) {
            $set['blog.page_id'] = $blogId;
        }
        foreach (['site_logo' => 'site.logo_id', 'site_icon' => 'site.icon_id'] as $wp => $key) {
            if (!empty($site[$wp]) && ($mid = $ctx->mediaId((int) $site[$wp]))) {
                $set[$key] = $mid;
            }
        }
        Settings::setMany($set);
        $job->log($set['home.type'] === 'page'
            ? __('Page d’accueil : « {t} ».', ['t' => (string) Db::val('SELECT title FROM content WHERE id = ?', [$homeId])])
            : __('Page d’accueil : derniers articles.'));
    }

    private static function guessPage(Context $ctx, array $slugs, bool $emptyOnly): ?int
    {
        $rows = Db::all("SELECT c.id, c.slug, c.blocks FROM content c JOIN import_map m ON m.new_id = c.id AND m.source = ? AND m.kind = 'content'
                         WHERE c.type = 'page' AND c.status = 'published' AND c.parent_id IS NULL", [$ctx->source]);
        foreach ($slugs as $s) {
            foreach ($rows as $r) {
                if ($r['slug'] === $s && (!$emptyOnly || in_array(trim((string) $r['blocks']), ['', '[]'], true))) {
                    return (int) $r['id'];
                }
            }
        }
        return null;
    }

    /** Rapport final, prochaines étapes, journal d'activité, nettoyage. */
    private static function finishReport(Job $job, Context $ctx): void
    {
        $r = &$job->d['report'];
        $r['warnings'] = array_values($r['warnings']);
        $r['finished_at'] = time();
        $r['duration'] = time() - (int) ($job->d['state']['started_at'] ?? $job->d['created_at']);
        $r['source'] = ['type' => $job->d['source']['type'], 'url' => (string) ($job->d['state']['site']['home'] ?? '')];
        $c = $r['counts'];
        $contents = $c['pages']['imported'] + $c['pages']['updated'] + $c['posts']['imported'] + $c['posts']['updated'] + $c['other']['imported'] + $c['other']['updated'];

        $next = [];
        $next[] = __('Parcourez le site : la page d’accueil, le menu principal et quelques articles.');
        if ($r['review']) {
            $next[] = _n('Vérifiez le contenu marqué « à revoir » (codes courts, formulaires, blocs non convertis).', 'Vérifiez les {n} contenus marqués « à revoir » (codes courts, formulaires, blocs non convertis).', count($r['review']));
        }
        if ($c['users']['imported'] > 0) {
            $next[] = __('Prévenez vos auteurs : ils choisissent leur mot de passe avec « Mot de passe oublié » sur la page de connexion.');
        }
        if ($r['media']['failed']) {
            $next[] = __('Certains fichiers n’ont pas pu être téléchargés : relancez la migration (sans doublon) une fois l’ancien site accessible, ou ajoutez-les à la main.');
        }
        if (self::isWxr($job)) {
            $next[] = __('Le fichier d’export ne contient pas tous les réglages : vérifiez la page d’accueil, le fuseau horaire et les rôles des utilisateurs dans Réglages.');
        }
        $next[] = __('Quand tout vous convient, faites pointer votre nom de domaine vers Wordless : adresses, images et redirections sont déjà en place.');
        $next[] = __('Déclarez ensuite le plan du site (/sitemap.xml) dans la Google Search Console et surveillez le rapport des pages introuvables (404) quelques jours.');
        $next[] = __('Gardez une sauvegarde de WordPress quelques semaines, par précaution.');
        $r['next_steps'] = $next;

        Activity::log('import', 'site', null, __('WordPress {u} : {n} contenus', ['u' => (string) ($job->d['state']['site']['home'] ?? ''), 'n' => $contents]));
        $job->log(__('Migration terminée en {d} : {n} contenus, {m} médias, {r} redirections.', [
            'd' => self::duration((int) $r['duration']), 'n' => $contents, 'm' => $r['media']['downloaded'], 'r' => $c['redirects']['imported'],
        ]));
        $job->d['finished'] = true;
        $job->cleanup();
    }

    private static function duration(int $s): string
    {
        return $s < 60 ? $s . ' s' : intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s';
    }
}
