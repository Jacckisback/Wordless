<?php
declare(strict_types=1);

namespace Wordless\Migrate;

use Wordless\Config;
use Wordless\Db;
use Wordless\Media;
use Wordless\Redirects;

/**
 * Contexte partagé pendant une migration : correspondances WordPress -> Wordless
 * (table import_map), réécriture des adresses de l'ancien site, recherche et
 * téléchargement des médias, problèmes relevés sur le contenu en cours.
 *
 * Clés de import_map (source = URL d'accueil WordPress) :
 *   category, tag, user, media, content, comment, menu : identifiant WordPress -> identifiant Wordless
 *   file : chemin relatif dans wp-content/uploads (exact ou « original ») -> média
 *   url  : adresse absolue d'un fichier hors médiathèque -> média
 *   path : chemin WordPress d'un contenu (sans barre finale) -> contenu
 */
final class Context
{
    /** Clé de la source dans import_map (URL d'accueil WordPress, sans barre finale). */
    public string $source;
    /** URL d'accueil WordPress (home_url), sans barre finale. */
    public string $home;
    /** Chemin de l'accueil WordPress sur son domaine ('' ou '/blog'). */
    public string $homePath;
    /** Chemin absolu des téléversements sur l'ancien domaine (ex. /wp-content/uploads). */
    public string $uploadsPath;
    /** Hôtes (avec port éventuel) considérés comme l'ancien site. */
    public array $hosts = [];
    /** Hôtes servant les médias (CDN, stockage externe) : seuls leurs téléversements sont repris. */
    public array $mediaHosts = [];
    /** Téléchargement des médias autorisé (option « media »). */
    public bool $downloadMedia;
    /** Informations du site source (nom, slogan…), pour les variables SEO. */
    public array $site;
    /** Problèmes relevés sur le contenu en cours (« à revoir »). */
    public array $issues = [];

    private Job $job;
    private string $wlBase;
    /** @var array<string, ?int> */
    private array $cache = [];
    /** @var array<string, bool> */
    private array $failed = [];

    public function __construct(Job $job)
    {
        $this->job = $job;
        $st = $job->d['state'];
        $site = (array) ($st['site'] ?? []);
        $this->site = $site;
        $this->source = (string) ($st['source_key'] ?? '');
        $this->home = rtrim((string) ($site['home'] ?? $this->source), '/');
        $this->homePath = rtrim((string) parse_url($this->home, PHP_URL_PATH), '/');
        $this->uploadsPath = rtrim((string) ($site['uploads_path'] ?? ($this->homePath . '/wp-content/uploads')), '/');
        foreach ([$this->home, (string) ($site['siteurl'] ?? '')] as $u) {
            $h = self::hostKey($u);
            if ($h !== '' && !in_array($h, $this->hosts, true)) {
                $this->hosts[] = $h;
            }
        }
        foreach ((array) ($site['media_hosts'] ?? []) as $h) {
            if (is_string($h) && $h !== '' && !in_array($h, $this->hosts, true)) {
                $this->mediaHosts[] = $h;
            }
        }
        $this->downloadMedia = (bool) ($job->d['options']['media'] ?? true);
        $this->wlBase = Config::basePath();
        foreach ((array) ($st['failed_urls'] ?? []) as $u) {
            $this->failed[(string) $u] = true;
        }
    }

    public function job(): Job
    {
        return $this->job;
    }

    /** Hôte normalisé (sans www., en minuscules, avec le port s'il est explicite). */
    public static function hostKey(string $url): string
    {
        $p = parse_url(str_starts_with($url, '//') ? 'http:' . $url : $url);
        if (empty($p['host'])) {
            return '';
        }
        $host = strtolower((string) $p['host']);
        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }
        return $host . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    /** Normalise une URL de site (schéma + hôte en minuscules, sans barre finale). */
    public static function normalizeBase(string $url): string
    {
        $url = trim($url);
        $p = parse_url($url);
        if (empty($p['host'])) {
            return rtrim($url, '/');
        }
        return strtolower(($p['scheme'] ?? 'https') . '://' . $p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . rtrim((string) ($p['path'] ?? ''), '/');
    }

    // ------------------------------------------------------------------
    // Table import_map
    // ------------------------------------------------------------------

    public function map(string $kind, int|string $old): ?int
    {
        $key = $kind . "\0" . $old;
        if (!array_key_exists($key, $this->cache)) {
            $v = Db::val('SELECT new_id FROM import_map WHERE source = ? AND kind = ? AND old_id = ?', [$this->source, $kind, (string) $old]);
            $this->cache[$key] = $v === null ? null : (int) $v;
        }
        return $this->cache[$key];
    }

    public function setMap(string $kind, int|string $old, int $new): void
    {
        Db::upsert('import_map', ['source' => $this->source, 'kind' => $kind, 'old_id' => (string) $old, 'new_id' => $new], ['source', 'kind', 'old_id']);
        $this->cache[$kind . "\0" . $old] = $new;
    }

    /** Correspondance vérifiée : l'élément Wordless existe encore. */
    public function existing(string $kind, int|string $old, string $table): ?int
    {
        $id = $this->map($kind, $old);
        if ($id === null) {
            return null;
        }
        $ok = $table === 'content'
            ? Db::val("SELECT id FROM content WHERE id = ? AND status <> 'trash'", [$id])
            : Db::val('SELECT id FROM ' . $table . ' WHERE id = ?', [$id]);
        return $ok ? $id : null;
    }

    public function contentId(int $wpId): ?int
    {
        return $wpId > 0 ? $this->existing('content', $wpId, 'content') : null;
    }

    public function termId(string $taxonomy, int $wpId): ?int
    {
        return $wpId > 0 ? $this->existing($taxonomy === 'tag' ? 'tag' : 'category', $wpId, 'terms') : null;
    }

    public function userId(int $wpId): ?int
    {
        return $wpId > 0 ? $this->existing('user', $wpId, 'users') : null;
    }

    /** Média Wordless correspondant à une pièce jointe WordPress. */
    public function mediaId(int $wpId): ?int
    {
        if ($wpId <= 0) {
            return null;
        }
        $id = $this->map('media', $wpId);
        return $id && Media::find($id) ? $id : null;
    }

    // ------------------------------------------------------------------
    // Adresses
    // ------------------------------------------------------------------

    /**
     * Décompose une adresse si elle pointe vers l'ancien site WordPress.
     * Les chemins relatifs à la racine (/contact/) sont considérés comme internes.
     * @return array{path: string, query: string, fragment: string}|null
     */
    public function oldUrl(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || $url[0] === '#' || preg_match('#^(mailto|tel|data|javascript|sms|skype):#i', $url)) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'http:' . $url;
        }
        if (preg_match('#^https?://#i', $url)) {
            $host = self::hostKey($url);
            // Jetpack Photon : https://i0.wp.com/site.tld/wp-content/uploads/… (paramètres de taille ignorés)
            if (preg_match('#^i[0-3]\.wp\.com$#', $host) && preg_match('#^https?://[^/]+/([^/?\#]+)(/[^?\#]*)#i', $url, $m)
                && in_array(self::hostKey('http://' . $m[1]), $this->hosts, true)) {
                return ['path' => $m[2], 'query' => '', 'fragment' => ''];
            }
            // CDN ou stockage externe des médias : seul le chemin des téléversements est repris.
            if (in_array($host, $this->mediaHosts, true)) {
                $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
                $pos = strpos($path, '/wp-content/uploads/');
                if ($pos === false) {
                    return null;
                }
                return ['path' => $this->uploadsPath . substr($path, $pos + strlen('/wp-content/uploads')), 'query' => '', 'fragment' => ''];
            }
            if (!in_array($host, $this->hosts, true)) {
                return null;
            }
        } elseif ($url[0] !== '/') {
            return null; // relatif au document : laissé tel quel
        }
        $p = parse_url($url);
        if ($p === false) {
            return null;
        }
        $path = (string) ($p['path'] ?? '/');
        return [
            'path' => $path === '' ? '/' : $path,
            'query' => isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '',
            'fragment' => isset($p['fragment']) && $p['fragment'] !== '' ? '#' . $p['fragment'] : '',
        ];
    }

    /** Chemin relatif à l'accueil WordPress, ou null s'il est en dehors. */
    public function relPath(string $absPath): ?string
    {
        $absPath = '/' . ltrim($absPath, '/');
        if ($this->homePath === '') {
            return $absPath;
        }
        if ($absPath === $this->homePath || $absPath === $this->homePath . '/') {
            return '/';
        }
        return str_starts_with($absPath, $this->homePath . '/') ? substr($absPath, strlen($this->homePath)) : null;
    }

    /** Chemin normalisé (décodé, sans barre finale) utilisé comme clé de contenu. */
    public static function pathKey(string $path): string
    {
        $path = rawurldecode($path);
        $path = '/' . trim((string) preg_replace('#/{2,}#', '/', $path), '/');
        return $path;
    }

    /**
     * Chemin WordPress (relatif à l'accueil) d'un permalien, ou null si le lien
     * n'est pas « joli » (?p=123) ou n'appartient pas au site.
     */
    public function wpPath(string $link): ?string
    {
        $u = $this->oldUrl($link);
        if ($u === null || $u['query'] !== '') {
            return null;
        }
        $rel = $this->relPath($u['path']);
        return $rel === null ? null : self::pathKey($rel);
    }

    public function isUploads(string $absPath): bool
    {
        return str_starts_with(rawurldecode($absPath), $this->uploadsPath . '/');
    }

    /** Chemin relatif au dossier des téléversements (décodé). */
    public function uploadsRel(string $absPath): string
    {
        return ltrim(substr(rawurldecode($absPath), strlen($this->uploadsPath)), '/');
    }

    /** Version « originale » d'un fichier : sans suffixe de taille, -scaled, -rotated ni -e123… */
    public static function originalRel(string $rel): string
    {
        $rel = (string) preg_replace('#-\d+x\d+(?=\.[a-z0-9]+$)#i', '', $rel);
        $rel = (string) preg_replace('#-(scaled|rotated)(?=\.[a-z0-9]+$)#i', '', $rel);
        $rel = (string) preg_replace('#-e\d{10,13}(?=\.[a-z0-9]+$)#i', '', $rel);
        return $rel;
    }

    /** Largeur demandée par un suffixe de taille WordPress (-300x200), sinon null. */
    public static function sizeWidth(string $rel): ?int
    {
        return preg_match('#-(\d+)x\d+\.[a-z0-9]+$#i', $rel, $m) ? (int) $m[1] : null;
    }

    /** Adresse absolue sur l'ancien site d'un chemin absolu. */
    public function oldAbsolute(string $absPath): string
    {
        $p = parse_url($this->home);
        return ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '') . $absPath;
    }

    /**
     * Réécrit une adresse de l'ancien site :
     * - fichier téléversé => adresse du média Wordless (téléchargé si besoin) ;
     * - page de l'ancien site => chemin relatif à la racine (identique après migration) ;
     * - adresse externe => inchangée.
     * $field = true pour un champ de bloc (rendu via href(), donc sans le sous-dossier
     * d'installation) ; false pour du HTML enrichi (rendu tel quel).
     */
    public function rewriteUrl(string $url, bool $field = false, ?int $width = null): string
    {
        $u = $this->oldUrl($url);
        if ($u === null) {
            return trim($url);
        }
        if ($this->isUploads($u['path'])) {
            $m = $this->mediaForUrl($this->oldAbsolute($u['path']));
            if ($m) {
                $w = $width ?? self::sizeWidth($this->uploadsRel($u['path']));
                $src = Media::url($m, $w !== null && Media::isImage($m) ? $w : null);
                return ($field ? $this->stripBase($src) : $src) . $u['fragment'];
            }
            return $this->oldAbsolute($u['path']) . $u['query'] . $u['fragment'];
        }
        $rel = $this->relPath($u['path']);
        if ($rel === null) {
            return $this->oldAbsolute($u['path']) . $u['query'] . $u['fragment'];
        }
        // Image ou fichier de l'ancien site hors médiathèque (thème, ancien dossier…)
        if (preg_match('#\.(jpe?g|png|gif|webp|avif|pdf)$#i', $u['path']) && !str_contains($u['path'], '/wp-content/plugins/')) {
            $m = $this->mediaForUrl($this->oldAbsolute($u['path']));
            if ($m) {
                $src = Media::url($m);
                return ($field ? $this->stripBase($src) : $src) . $u['fragment'];
            }
        }
        return ($field ? '' : $this->wlBase) . $rel . $u['query'] . $u['fragment'];
    }

    private function stripBase(string $url): string
    {
        return $this->wlBase !== '' && str_starts_with($url, $this->wlBase . '/') ? substr($url, strlen($this->wlBase)) : $url;
    }

    /** Réécrit les attributs href/src/poster d'un HTML déjà nettoyé. */
    public function rewriteHtml(string $html): string
    {
        if ($html === '' || !preg_match('/\b(href|src|poster)=/i', $html)) {
            return $html;
        }
        return (string) preg_replace_callback('/\b(href|src|poster)=("|\')(.*?)\2/is', function (array $m): string {
            $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $width = null;
            if (strtolower($m[1]) === 'src') {
                $width = self::sizeWidth((string) parse_url($url, PHP_URL_PATH));
            }
            $new = $this->rewriteUrl($url, false, $width);
            return $m[1] . '="' . htmlspecialchars($new, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }, $html);
    }

    // ------------------------------------------------------------------
    // Médias
    // ------------------------------------------------------------------

    /**
     * Média correspondant à une adresse de fichier de l'ancien site. Cherche
     * d'abord dans la médiathèque importée (fichier exact puis original), puis
     * télécharge le fichier s'il est absent (images citées dans le contenu).
     */
    public function mediaForUrl(string $absUrl, bool $download = true): ?array
    {
        $u = $this->oldUrl($absUrl);
        if ($u === null) {
            return null;
        }
        if ($this->isUploads($u['path'])) {
            $rel = $this->uploadsRel($u['path']);
            foreach ([$rel, self::originalRel($rel)] as $key) {
                $id = $this->map('file', $key);
                if ($id && ($m = Media::find($id))) {
                    return $m;
                }
            }
            if (!$download || !$this->downloadMedia) {
                return null;
            }
            $orig = self::originalRel($rel);
            $candidates = array_unique([$this->oldAbsolute($this->uploadsPath . '/' . self::encodePath($orig)), $this->oldAbsolute($this->uploadsPath . '/' . self::encodePath($rel))]);
            $error = '';
            foreach ($candidates as $i => $candidate) {
                if (isset($this->failed[$candidate])) {
                    continue;
                }
                try {
                    $target = self::targetRel($i === 0 ? $orig : $rel);
                    $m = Media::importUrl($candidate, ['title' => self::titleFromFile($orig)], $target);
                    $this->setMap('file', $rel, (int) $m['id']);
                    $this->setMap('file', $orig, (int) $m['id']);
                    $this->job->d['report']['media']['downloaded']++;
                    $this->job->count('media', 'imported', 1);
                    $this->mediaRedirects($m, [$orig, $rel]);
                    return $m;
                } catch (\Throwable $e) {
                    $error = $e->getMessage();
                    $this->markFailed($candidate);
                }
            }
            if ($error !== '') {
                $this->job->mediaFailed($absUrl, $error);
                $this->issues[] = __('Image introuvable sur l’ancien site : {u}', ['u' => $absUrl]);
            }
            return null;
        }
        // Fichier de l'ancien site en dehors des téléversements.
        $key = $this->oldAbsolute($u['path']);
        $id = $this->map('url', $key);
        if ($id && ($m = Media::find($id))) {
            return $m;
        }
        if (!$download || !$this->downloadMedia || isset($this->failed[$key])) {
            return null;
        }
        try {
            $m = Media::importUrl($key, ['title' => self::titleFromFile(basename($u['path']))]);
            $this->setMap('url', $key, (int) $m['id']);
            $this->job->d['report']['media']['downloaded']++;
            $this->job->count('media', 'imported', 1);
            return $m;
        } catch (\Throwable $e) {
            $this->markFailed($key);
            $this->job->mediaFailed($key, $e->getMessage());
            return null;
        }
    }

    private function markFailed(string $url): void
    {
        $this->failed[$url] = true;
        $list = &$this->job->d['state']['failed_urls'];
        if (!is_array($list)) {
            $list = [];
        }
        if (count($list) < 2000) {
            $list[] = $url;
        }
    }

    /** Encode chaque segment d'un chemin (fichiers accentués ou avec espaces). */
    public static function encodePath(string $rel): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $rel)));
    }

    public static function titleFromFile(string $file): string
    {
        $name = pathinfo($file, PATHINFO_FILENAME);
        return trim((string) preg_replace('/[-_]+/', ' ', $name));
    }

    /**
     * Chemin cible dans media/ : conserve AAAA/MM/nom.ext quand c'est possible
     * (les anciennes adresses /wp-content/uploads/… sont alors servies
     * automatiquement), sinon un nom de fichier assaini.
     */
    public static function targetRel(string $rel): string
    {
        $dir = '';
        $file = $rel;
        if (preg_match('#^(\d{4}/\d{2})/([^/]+)$#', $rel, $m)) {
            $dir = $m[1] . '/';
            $file = $m[2];
        } elseif (str_contains($rel, '/')) {
            $file = basename($rel);
        }
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $name = pathinfo($file, PATHINFO_FILENAME);
        if (!preg_match('/^[\w.\-]+$/', $name)) {
            $name = slugify($name, 80) ?: 'fichier';
        }
        return $dir . $name . ($ext !== '' ? '.' . $ext : '');
    }

    /**
     * Les anciennes adresses /wp-content/uploads/AAAA/MM/fichier(-300x200).jpg sont
     * redirigées automatiquement par Wordless quand le fichier garde le même
     * chemin. Sinon (nom assaini, dossier de téléversement non standard),
     * on crée des redirections 301 explicites pour chaque variante connue.
     */
    public function mediaRedirects(array $m, array $rels): int
    {
        // Même logique que Front::legacy() : fichier exact, sans suffixe de taille, puis sans -scaled.
        $prefixOk = $this->uploadsPath === $this->wlBase . '/wp-content/uploads';
        $base = $this->uploadsPath;
        if ($this->wlBase !== '') {
            if (!str_starts_with($base . '/', $this->wlBase . '/')) {
                return 0; // ancien dossier en dehors de l'installation Wordless : rien à rediriger ici
            }
            $base = substr($base, strlen($this->wlBase));
        }
        $n = 0;
        foreach (array_unique(array_filter($rels)) as $rel) {
            $alt = (string) preg_replace('#-\d+x\d+(\.[a-z0-9]+)$#i', '$1', $rel);
            $alt2 = (string) preg_replace('#-scaled(\.[a-z0-9]+)$#i', '$1', $alt);
            if ($prefixOk && in_array((string) $m['path'], [$rel, $alt, $alt2], true)) {
                continue;
            }
            Redirects::add($base . '/' . $rel, '/media/' . $m['path'], 301);
            $n++;
        }
        return $n;
    }
}
