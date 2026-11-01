<?php
/**
 * Chemin rapide : sert une page déjà générée sans démarrer l'application.
 * Inclus uniquement pour les requêtes GET anonymes. Ne fait rien si la page
 * n'est pas en cache ou si le cache a expiré (contenu programmé à publier).
 * La correspondance chemin -> extension doit rester identique à Cache::extFor().
 */
declare(strict_types=1);

(static function (): void {
    $dir = WL_ROOT . '/data/cache';
    $metaFile = $dir . '/meta.php';
    if (!is_file($metaFile)) {
        return;
    }
    $meta = include $metaFile;
    if (!is_array($meta) || time() >= (int) ($meta['expires'] ?? 0)) {
        return;
    }

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $q = strpos($uri, '?');
    $path = $q === false ? $uri : substr($uri, 0, $q);
    if ($q !== false && $q < strlen($uri) - 1) {
        parse_str(substr($uri, $q + 1), $params);
        foreach (array_keys($params) as $k) {
            if (!preg_match('/^(utm_[a-z]+|fbclid|gclid|msclkid|mc_cid|mc_eid|ref)$/', (string) $k)) {
                return; // paramètres réels : on laisse l'application répondre
            }
        }
    }
    $path = rawurldecode($path);
    $base = (string) ($meta['base'] ?? '');
    if ($base !== '' && str_starts_with($path, $base)) {
        $path = substr($path, strlen($base));
    }
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }

    $ext = match (true) {
        str_ends_with($path, '.xml') => 'xml',
        str_ends_with($path, '.txt') => 'txt',
        str_ends_with($path, '.md') => 'md',
        (bool) preg_match('#/feed/?$#', $path) => 'rss',
        default => 'html',
    };
    $file = $dir . '/pages/' . sha1($path) . '.' . $ext;
    if (!is_file($file)) {
        return;
    }

    $types = [
        'html' => 'text/html; charset=utf-8',
        'xml' => 'application/xml; charset=utf-8',
        'rss' => 'application/rss+xml; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
    ];
    $etag = '"' . dechex((int) filemtime($file)) . '-' . dechex((int) filesize($file)) . '"';
    header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: public, max-age=0, must-revalidate');
    header('ETag: ' . $etag);
    header('X-Wordless-Cache: HIT');
    if ($ext === 'md') {
        header('X-Robots-Tag: noindex'); // version pour les IA : pas de contenu dupliqué dans Google
    }
    foreach ((array) ($meta['headers'] ?? []) as $h) {
        if (str_starts_with((string) $h, 'Content-Security-Policy:') && is_file($file . '.csp')) {
            $h = (string) file_get_contents($file . '.csp'); // page avec code intégré : CSP adaptée
        }
        header((string) $h);
    }
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    readfile($file);

    if (!empty($meta['stats']) && in_array($ext, ['html', 'md', 'txt'], true)) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        require __DIR__ . '/hit.php';
        wl_track_hit($path, $ext !== 'html', (array) ($meta['proxies'] ?? []));
    }
    exit;
})();
