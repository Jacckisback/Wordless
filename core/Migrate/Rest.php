<?php
declare(strict_types=1);

namespace Wordless\Migrate;

use Wordless\HttpClient;

/**
 * Client de l'API REST WordPress (/wp-json/wp/v2/…).
 *
 * - Découverte de l'API (/wp-json/, ?rest_route=/ ou en-tête Link de l'accueil).
 * - Authentification « Basic » avec un mot de passe d'application (facultatif :
 *   sans lui, seuls les contenus publics publiés sont accessibles).
 * - Les redirections ne sont suivies que vers le même site : l'en-tête
 *   d'authentification n'est jamais envoyé à un autre domaine.
 * - Les pages de résultats (100 éléments) sont gardées sur disque le temps de
 *   les traiter : une étape interrompue ne retélécharge pas tout.
 */
final class Rest
{
    private string $api;
    private string $auth = '';
    private ?string $cacheDir;

    public function __construct(string $api, string $user = '', string $password = '', ?string $cacheDir = null)
    {
        $this->api = rtrim($api, '/');
        if (str_ends_with($api, '?rest_route=')) {
            $this->api = $api;
        }
        if ($user !== '' && $password !== '') {
            $this->auth = 'Authorization: Basic ' . base64_encode($user . ':' . $password);
        }
        $this->cacheDir = $cacheDir;
    }

    public function authenticated(): bool
    {
        return $this->auth !== '';
    }

    /**
     * Trouve la racine de l'API à partir de l'adresse du site.
     * @return array{api: string, index: array}
     */
    public static function discover(string $url): array
    {
        $url = trim($url);
        $bases = [];
        if (preg_match('#^https?://#i', $url)) {
            $bases[] = $url;
        } else {
            $bases[] = 'https://' . ltrim($url, '/');
            $bases[] = 'http://' . ltrim($url, '/');
        }
        $lastError = '';
        foreach ($bases as $base) {
            $base = rtrim((string) preg_replace('#/(wp-json|index\.php)(/.*)?$#i', '', (string) preg_replace('#[?\#].*$#', '', $base)), '/');
            foreach ([$base . '/wp-json', $base . '/?rest_route='] as $api) {
                try {
                    $client = new self($api);
                    $res = $client->request('/');
                    if ($res['status'] === 200 && is_array($res['data']) && in_array('wp/v2', (array) ($res['data']['namespaces'] ?? []), true)) {
                        return ['api' => self::apiFromFinalUrl($res['url'], $api), 'index' => $res['data']];
                    }
                    $lastError = 'HTTP ' . $res['status'];
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                }
            }
            // En-tête Link: <…/wp-json/>; rel="https://api.w.org/" de la page d'accueil.
            try {
                $res = HttpClient::get($base . '/', ['timeout' => 20, 'max_bytes' => 2 * 1024 * 1024]);
                if (preg_match('#<([^>]+)>;\s*rel="https://api\.w\.org/"#', (string) ($res['headers']['link'] ?? ''), $m)
                    && Context::hostKey($m[1]) === Context::hostKey($base)) {
                    $client = new self($m[1]);
                    $r2 = $client->request('/');
                    if ($r2['status'] === 200 && is_array($r2['data']) && in_array('wp/v2', (array) ($r2['data']['namespaces'] ?? []), true)) {
                        return ['api' => self::apiFromFinalUrl($r2['url'], rtrim($m[1], '/')), 'index' => $r2['data']];
                    }
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }
        throw new \RuntimeException(__('API WordPress introuvable à cette adresse. Vérifiez l’adresse du site, ou utilisez le fichier d’export (Outils > Exporter dans WordPress).')
            . ($lastError !== '' ? ' (' . $lastError . ')' : ''));
    }

    public function url(string $route, array $query = []): string
    {
        $route = '/' . ltrim($route, '/');
        $qs = $query ? http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '';
        if (str_ends_with($this->api, '?rest_route=')) {
            return $this->api . rawurlencode($route) . ($qs !== '' ? '&' . $qs : '');
        }
        return $this->api . $route . ($qs !== '' ? '?' . $qs : '');
    }

    /**
     * Requête GET. Avec authentification, aucune redirection n'est suivie : le
     * mot de passe d'application n'est envoyé qu'à l'adresse découverte.
     * @return array{status: int, headers: array<string, string>, data: mixed, url: string}
     */
    public function request(string $route, array $query = []): array
    {
        $url = $this->url($route, $query);
        $headers = ['Accept: application/json'];
        if ($this->auth !== '') {
            $headers[] = $this->auth;
        }
        try {
            $res = HttpClient::request('GET', $url, [
                'headers' => $headers, 'redirects' => $this->auth !== '' ? 0 : 5, 'timeout' => 60, 'max_bytes' => 64 * 1024 * 1024,
            ]);
        } catch (\RuntimeException $e) {
            if ($this->auth !== '' && str_starts_with($e->getMessage(), 'Trop de redirections')) {
                throw new \RuntimeException(__('Le site WordPress a répondu par une redirection : indiquez son adresse exacte (avec https:// et le bon domaine).'));
            }
            throw $e;
        }
        if ($this->auth !== '' && $res['status'] >= 300 && $res['status'] < 400) {
            // Le mot de passe d'application ne suit jamais une redirection.
            throw new \RuntimeException(__('Le site WordPress a répondu par une redirection : indiquez son adresse exacte (avec https:// et le bon domaine).'));
        }
        $body = (string) $res['body'];
        // Certaines extensions écrivent du texte avant le JSON (BOM, avertissements PHP).
        $data = json_decode($body, true);
        if ($data === null && $body !== '' && preg_match('/[\[{]/', $body, $m, PREG_OFFSET_CAPTURE)) {
            $data = json_decode(substr($body, (int) $m[0][1]), true);
        }
        return ['status' => (int) $res['status'], 'headers' => $res['headers'], 'data' => $data, 'url' => (string) ($res['url'] ?? $url)];
    }

    /** Racine canonique de l'API d'après l'adresse finale (après redirections). */
    private static function apiFromFinalUrl(string $final, string $fallback): string
    {
        $path = (string) preg_replace('#\?.*$#', '', $final);
        if (preg_match('#^(https?://.+?/wp-json)/?$#i', $path, $m)) {
            return $m[1];
        }
        if (str_contains($final, 'rest_route=')) {
            return rtrim($path, '/') . '/?rest_route=';
        }
        return $fallback;
    }

    /** Données d'une route ; null si elle n'existe pas (404) ou est refusée (401/403). */
    public function get(string $route, array $query = []): mixed
    {
        $res = $this->request($route, $query);
        if ($res['status'] === 200) {
            return $res['data'];
        }
        if (in_array($res['status'], [400, 401, 403, 404], true)) {
            return null;
        }
        throw new \RuntimeException(__('Réponse inattendue de WordPress (HTTP {c}) pour {r}.', ['c' => $res['status'], 'r' => $route]));
    }

    /** Nombre total d'éléments d'une collection (en-tête X-WP-Total), null si inaccessible. */
    public function total(string $route, array $query = []): ?int
    {
        $res = $this->request($route, $query + ['per_page' => 1]);
        if ($res['status'] !== 200) {
            return null;
        }
        if (isset($res['headers']['x-wp-total'])) {
            return (int) $res['headers']['x-wp-total'];
        }
        return is_array($res['data']) ? count($res['data']) : 0;
    }

    /**
     * Une page d'une collection (100 éléments), lue depuis le cache disque si possible.
     * @return array{items: list<array>, pages: int, total: int}
     */
    public function page(string $route, array $query, int $page, int $perPage = 100): array
    {
        $query += ['per_page' => $perPage, 'page' => $page];
        $file = $this->cacheDir !== null ? $this->cacheDir . '/rest-' . sha1($this->url($route, $query)) . '.json' : null;
        if ($file !== null && is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $res = $this->request($route, $query);
        if ($res['status'] === 400 && is_array($res['data']) && ($res['data']['code'] ?? '') === 'rest_post_invalid_page_number') {
            return ['items' => [], 'pages' => max(0, $page - 1), 'total' => 0];
        }
        if (in_array($res['status'], [401, 403, 404], true)) {
            return ['items' => [], 'pages' => 0, 'total' => 0, 'denied' => $res['status']];
        }
        if ($res['status'] !== 200 || !is_array($res['data'])) {
            throw new \RuntimeException(__('Réponse inattendue de WordPress (HTTP {c}) pour {r}.', ['c' => $res['status'], 'r' => $route]));
        }
        $items = array_values(array_filter($res['data'], 'is_array'));
        // Certaines extensions de cache retirent X-WP-TotalPages : on continue tant que les pages sont pleines.
        $pages = isset($res['headers']['x-wp-totalpages']) ? (int) $res['headers']['x-wp-totalpages'] : (count($items) >= $perPage ? $page + 1 : $page);
        $out = [
            'items' => $items,
            'pages' => $pages,
            'total' => (int) ($res['headers']['x-wp-total'] ?? count($items)),
        ];
        if ($file !== null) {
            @file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        }
        return $out;
    }

    /** Oublie une page mise en cache (une fois traitée). */
    public function forget(string $route, array $query, int $page, int $perPage = 100): void
    {
        if ($this->cacheDir !== null) {
            $query += ['per_page' => $perPage, 'page' => $page];
            @unlink($this->cacheDir . '/rest-' . sha1($this->url($route, $query)) . '.json');
        }
    }

    // ------------------------------------------------------------------
    // Conversion des objets REST vers le format commun (identique au WXR)
    // ------------------------------------------------------------------

    public static function text(mixed $field): string
    {
        if (is_array($field)) {
            if (array_key_exists('raw', $field)) {
                return (string) $field['raw'];
            }
            return html_entity_decode(strip_tags((string) ($field['rendered'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return (string) $field;
    }

    private static function gmt(?string $date): string
    {
        return $date ? str_replace('T', ' ', substr($date, 0, 19)) : '';
    }

    /** Article, page ou type personnalisé. */
    public static function item(array $j): array
    {
        $content = $j['content'] ?? [];
        $raw = is_array($content) && array_key_exists('raw', $content);
        $excerpt = $j['excerpt'] ?? [];
        return [
            'id' => (int) ($j['id'] ?? 0),
            'type' => (string) ($j['type'] ?? 'post'),
            'status' => (string) ($j['status'] ?? 'publish'),
            'title' => self::text($j['title'] ?? ''),
            'link' => (string) ($j['link'] ?? ''),
            'guid' => self::text($j['guid'] ?? ''),
            'author_id' => (int) ($j['author'] ?? 0),
            'content' => $raw ? (string) $content['raw'] : (string) ($content['rendered'] ?? ''),
            'rendered' => !$raw,
            'excerpt' => is_array($excerpt) && array_key_exists('raw', $excerpt) ? (string) $excerpt['raw'] : '',
            'date' => self::gmt($j['date'] ?? null),
            'date_gmt' => self::gmt($j['date_gmt'] ?? null),
            'modified_gmt' => self::gmt($j['modified_gmt'] ?? null),
            'comment_status' => (string) ($j['comment_status'] ?? 'closed'),
            'slug' => rawurldecode((string) (($j['slug'] ?? '') !== '' ? $j['slug'] : ($j['generated_slug'] ?? ''))),
            'parent' => (int) ($j['parent'] ?? 0),
            'menu_order' => (int) ($j['menu_order'] ?? 0),
            'password' => (string) ($j['password'] ?? (!empty($content['protected']) ? '*' : '')),
            'sticky' => !empty($j['sticky']),
            'attachment_url' => '',
            'term_ids' => ['category' => array_map('intval', (array) ($j['categories'] ?? [])), 'tag' => array_map('intval', (array) ($j['tags'] ?? []))],
            'terms' => [],
            'featured' => (int) ($j['featured_media'] ?? 0),
            'meta' => is_array($j['meta'] ?? null) ? $j['meta'] : [],
            'old_slugs' => [],
            'permalink_template' => (string) ($j['permalink_template'] ?? ''),
            'yoast' => is_array($j['yoast_head_json'] ?? null) ? $j['yoast_head_json'] : null,
            'template' => (string) ($j['template'] ?? ''),
        ];
    }

    /** Pièce jointe (médiathèque). */
    public static function media(array $j): array
    {
        $d = (array) ($j['media_details'] ?? []);
        $sizes = [];
        foreach ((array) ($d['sizes'] ?? []) as $s) {
            if (!empty($s['file'])) {
                $sizes[] = (string) $s['file'];
            }
        }
        $caption = $j['caption'] ?? '';
        return [
            'id' => (int) ($j['id'] ?? 0),
            'type' => 'attachment',
            'status' => 'inherit',
            'title' => self::text($j['title'] ?? ''),
            'link' => (string) ($j['link'] ?? ''),
            'guid' => self::text($j['guid'] ?? ''),
            'attachment_url' => (string) ($j['source_url'] ?? ''),
            'date_gmt' => self::gmt($j['date_gmt'] ?? null),
            'excerpt' => is_array($caption) && array_key_exists('raw', $caption) ? (string) $caption['raw'] : trim(self::text($caption)),
            'parent' => (int) ($j['post'] ?? 0),
            'mime' => (string) ($j['mime_type'] ?? ''),
            'meta' => [
                '_wp_attached_file' => (string) ($d['file'] ?? ''),
                '_wp_attachment_image_alt' => (string) ($j['alt_text'] ?? ''),
            ],
            'file_sizes' => $sizes,
            'original_image' => (string) ($d['original_image'] ?? ''),
        ];
    }

    public static function comment(array $j): array
    {
        $status = (string) ($j['status'] ?? 'approved');
        return [
            'id' => (int) ($j['id'] ?? 0),
            'post' => (int) ($j['post'] ?? 0),
            'parent' => (int) ($j['parent'] ?? 0),
            'author' => (string) ($j['author_name'] ?? ''),
            'email' => (string) ($j['author_email'] ?? ''),
            'url' => (string) ($j['author_url'] ?? ''),
            'ip' => (string) ($j['author_ip'] ?? ''),
            'date_gmt' => self::gmt($j['date_gmt'] ?? null),
            'date' => self::gmt($j['date'] ?? null),
            'content' => is_array($j['content'] ?? null) ? (string) ($j['content']['raw'] ?? $j['content']['rendered'] ?? '') : (string) ($j['content'] ?? ''),
            'approved' => match ($status) {
                'approved', 'approve', '1' => '1',
                'hold', 'unapproved', '0' => '0',
                default => $status,
            },
            'type' => (string) ($j['type'] ?? 'comment'),
            'user_id' => (int) ($j['author'] ?? 0),
        ];
    }

    public static function user(array $j): array
    {
        return [
            'id' => (int) ($j['id'] ?? 0),
            'login' => (string) ($j['username'] ?? ($j['slug'] ?? '')),
            'email' => (string) ($j['email'] ?? ''),
            'name' => (string) ($j['name'] ?? ''),
            'first_name' => (string) ($j['first_name'] ?? ''),
            'last_name' => (string) ($j['last_name'] ?? ''),
            'slug' => rawurldecode((string) ($j['slug'] ?? '')),
            'role' => (string) (((array) ($j['roles'] ?? []))[0] ?? ''),
            'bio' => (string) ($j['description'] ?? ''),
            'registered' => self::gmt($j['registered_date'] ?? null),
        ];
    }

    public static function term(array $j, string $taxonomy): array
    {
        return [
            'id' => (int) ($j['id'] ?? 0),
            'taxonomy' => $taxonomy,
            'slug' => rawurldecode((string) ($j['slug'] ?? '')),
            'name' => html_entity_decode((string) ($j['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'parent' => (int) ($j['parent'] ?? 0),
            'parent_slug' => '',
            'description' => (string) ($j['description'] ?? ''),
            'link' => (string) ($j['link'] ?? ''),
        ];
    }
}
