<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Redirections 301/302/410 et suivi des pages introuvables (404).
 * Les redirections automatiques sont créées quand une adresse change.
 */
final class Redirects
{
    public static function normalize(string $path): string
    {
        $path = trim($path);
        if (preg_match('#^https?://[^/]+(/.*)?$#i', $path, $m)) {
            $path = $m[1] ?? '/';
        }
        $q = '';
        if (($p = strpos($path, '?')) !== false) {
            $q = substr($path, $p);
            $path = substr($path, 0, $p);
        }
        $path = '/' . trim(rawurldecode($path), '/');
        return ($path === '/' ? '/' : $path) . $q;
    }

    public static function add(string $source, string $target, int $code = 301, bool $auto = false): void
    {
        $source = self::normalize($source);
        $targetNorm = preg_match('#^https?://#i', $target) ? $target : self::normalize($target);
        if ($source === $targetNorm || $source === '/') {
            return;
        }
        // Évite les chaînes : toute redirection vers $source pointe désormais vers $target.
        Db::run('UPDATE redirects SET target = ? WHERE target = ?', [$targetNorm, $source]);
        Db::upsert('redirects', [
            'source' => $source, 'target' => $targetNorm, 'code' => in_array($code, [301, 302, 307, 308, 410], true) ? $code : 301,
            'auto' => $auto ? 1 : 0, 'created_at' => time(),
        ], ['source'], ['target', 'code', 'auto']);
        Db::run('DELETE FROM redirects WHERE source = target');
        Db::delete('not_found', ['path' => $source]);
    }

    public static function removeSource(string $source): void
    {
        Db::delete('redirects', ['source' => self::normalize($source)]);
    }

    /** Redirection pour un chemin demandé (avec ou sans paramètres). */
    public static function match(string $path, string $query = ''): ?array
    {
        $norm = self::normalize($path);
        $candidates = $query !== '' ? [$norm . '?' . $query, $norm] : [$norm];
        foreach ($candidates as $c) {
            $r = Db::one('SELECT * FROM redirects WHERE source = ?', [$c]);
            if ($r) {
                Db::run('UPDATE redirects SET hits = hits + 1, last_hit_at = ? WHERE id = ?', [time(), $r['id']]);
                return $r;
            }
        }
        return null;
    }

    public static function log404(string $path): void
    {
        if (strlen($path) > 300 || preg_match('#\.(php|asp|aspx|env|git|sql|bak|ini|cgi)$#i', $path) || str_contains($path, '/.')) {
            return; // sondes de robots : on n'encombre pas le journal
        }
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        Db::run('INSERT INTO not_found (path, hits, referrer, first_at, last_at) VALUES (?, 1, ?, ?, ?)
                 ON CONFLICT(path) DO UPDATE SET hits = hits + 1, last_at = excluded.last_at, referrer = CASE WHEN excluded.referrer <> \'\' THEN excluded.referrer ELSE referrer END',
            [$path, mb_substr($ref, 0, 300), time(), time()]);
    }

    /** Suggestion de destination pour une 404 (contenu au slug proche). */
    public static function suggest(string $path): ?array
    {
        $slug = basename(rtrim($path, '/'));
        $slug = preg_replace('/\.(html?|php)$/', '', $slug) ?? $slug;
        if ($slug === '') {
            return null;
        }
        $row = Db::one("SELECT * FROM content WHERE status = 'published' AND (slug = ? OR path LIKE ?) ORDER BY id DESC LIMIT 1", [$slug, '%/' . $slug]);
        if ($row) {
            return Content::decode($row);
        }
        $best = null;
        $bestScore = 0;
        foreach (Db::all("SELECT id, slug, path, title, type, status, blocks, seo, published_at, excerpt FROM content WHERE status = 'published' ORDER BY updated_at DESC LIMIT 500") as $r) {
            similar_text($slug, (string) $r['slug'], $pct);
            if ($pct > $bestScore) {
                $bestScore = $pct;
                $best = $r;
            }
        }
        return $bestScore >= 70 && $best ? Content::decode($best) : null;
    }

    public static function importCsv(string $csv): int
    {
        $n = 0;
        foreach (preg_split('/\r?\n/', $csv) ?: [] as $i => $line) {
            $cols = str_getcsv($line, str_contains($line, ';') && !str_contains($line, ',') ? ';' : ',');
            if (count($cols) < 2 || ($i === 0 && preg_match('/^(source|from|url)/i', (string) $cols[0]))) {
                continue;
            }
            $code = isset($cols[2]) && is_numeric($cols[2]) ? (int) $cols[2] : 301;
            self::add((string) $cols[0], (string) $cols[1], $code);
            $n++;
        }
        return $n;
    }
}
