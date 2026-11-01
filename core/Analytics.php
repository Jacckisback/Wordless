<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Statistiques sans cookie : agrégation des journaux de visites, origine du
 * trafic (moteurs, IA, réseaux sociaux), passages des robots d'IA.
 */
final class Analytics
{
    private const AI = [
        'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'perplexity.ai' => 'Perplexity', 'claude.ai' => 'Claude',
        'gemini.google.com' => 'Gemini', 'bard.google.com' => 'Gemini', 'copilot.microsoft.com' => 'Copilot', 'chat.mistral.ai' => 'Le Chat',
        'chat.deepseek.com' => 'DeepSeek', 'grok.com' => 'Grok', 'x.ai' => 'Grok', 'you.com' => 'You.com', 'phind.com' => 'Phind',
        'meta.ai' => 'Meta AI', 'duck.ai' => 'Duck.ai', 'kimi.com' => 'Kimi', 'chat.qwen.ai' => 'Qwen',
    ];
    private const SEARCH = [
        'google' => 'Google', 'bing.com' => 'Bing', 'duckduckgo.com' => 'DuckDuckGo', 'yahoo.com' => 'Yahoo', 'ecosia.org' => 'Ecosia',
        'qwant.com' => 'Qwant', 'search.brave.com' => 'Brave', 'yandex' => 'Yandex', 'baidu.com' => 'Baidu', 'startpage.com' => 'Startpage', 'kagi.com' => 'Kagi',
    ];
    private const SOCIAL = [
        'facebook.com' => 'Facebook', 'instagram.com' => 'Instagram', 't.co' => 'X', 'twitter.com' => 'X', 'x.com' => 'X', 'linkedin.com' => 'LinkedIn',
        'lnkd.in' => 'LinkedIn', 'pinterest' => 'Pinterest', 'youtube.com' => 'YouTube', 'tiktok.com' => 'TikTok', 'reddit.com' => 'Reddit',
        'news.ycombinator.com' => 'Hacker News', 'threads.net' => 'Threads', 'bsky.app' => 'Bluesky', 'whatsapp.com' => 'WhatsApp', 'wa.me' => 'WhatsApp',
    ];

    /** @return array{0: string, 1: string} [type, libellé] */
    public static function classify(string $host, string $utm = ''): array
    {
        $probe = $utm !== '' ? $utm : $host;
        if ($probe === '' || $probe === '-') {
            return $host === '-' ? ['internal', ''] : ['direct', __('Accès direct')];
        }
        $probe = preg_replace('/^(www|m|l|lm|out)\./', '', strtolower($probe)) ?? $probe;
        foreach (self::AI as $k => $label) {
            if ($probe === $k || str_ends_with($probe, '.' . $k) || ($utm !== '' && str_contains($probe, strtok($k, '.')))) {
                return ['ai', $label];
            }
        }
        foreach (self::SEARCH as $k => $label) {
            if ($probe === $k || str_contains($probe, $k . '.') || str_ends_with($probe, '.' . $k) || ($k === 'google' && preg_match('/(^|\.)google\.[a-z.]+$/', $probe))) {
                return ['search', $label];
            }
        }
        foreach (self::SOCIAL as $k => $label) {
            if ($probe === $k || str_ends_with($probe, '.' . $k) || str_contains($probe, $k . '.')) {
                return ['social', $label];
            }
        }
        return ['referral', $probe];
    }

    public static function aggregate(): void
    {
        require_once WL_CORE . '/hit.php';
        $dir = wl_stats_dir();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/hits-*.log') ?: [] as $file) {
            $day = self::dayFromFile($file);
            $tmp = $file . '.' . bin2hex(random_bytes(3)) . '.work';
            if (!@rename($file, $tmp)) {
                continue;
            }
            $pages = $sources = [];
            $views = $newVisitors = 0;
            Db::tx(static function () use ($tmp, $day, &$pages, &$sources, &$views, &$newVisitors): void {
                foreach (file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $p = explode("\t", $line);
                    if (count($p) < 6) {
                        continue;
                    }
                    [, $path, $ref, $visitor, $device, $utm] = $p;
                    $views++;
                    $pages[$path] = ($pages[$path] ?? 0) + 1;
                    if (Db::run('INSERT OR IGNORE INTO stats_seen (day, visitor) VALUES (?, ?)', [$day, $visitor])->rowCount() > 0) {
                        $newVisitors++;
                        [$kind, $label] = self::classify($ref, $utm);
                        if ($kind !== 'internal') {
                            $sources[$kind . "\t" . $label] = ($sources[$kind . "\t" . $label] ?? 0) + 1;
                        }
                        $sources["device\t" . $device] = ($sources["device\t" . $device] ?? 0) + 1;
                    } elseif ($utm !== '' || ($ref !== '' && $ref !== '-')) {
                        [$kind, $label] = self::classify($ref, $utm);
                        if ($kind !== 'internal' && $kind !== 'direct') {
                            $sources[$kind . "\t" . $label] = ($sources[$kind . "\t" . $label] ?? 0) + 1;
                        }
                    }
                }
                foreach ($pages as $path => $n) {
                    Db::run('INSERT INTO stats_pages (day, path, views) VALUES (?, ?, ?) ON CONFLICT(day, path) DO UPDATE SET views = views + excluded.views', [$day, $path, $n]);
                }
                foreach ($sources as $k => $n) {
                    [$kind, $label] = explode("\t", $k, 2);
                    Db::run('INSERT INTO stats_sources (day, kind, source, views) VALUES (?, ?, ?, ?) ON CONFLICT(day, kind, source) DO UPDATE SET views = views + excluded.views', [$day, $kind, $label, $n]);
                }
                Db::run('INSERT INTO stats_days (day, views, visitors) VALUES (?, ?, ?) ON CONFLICT(day) DO UPDATE SET views = views + excluded.views, visitors = visitors + excluded.visitors', [$day, $views, $newVisitors]);
            });
            @unlink($tmp);
        }
        foreach (glob($dir . '/bots-*.log') ?: [] as $file) {
            $day = self::dayFromFile($file);
            $tmp = $file . '.work';
            if (!@rename($file, $tmp)) {
                continue;
            }
            $bots = [];
            $files = []; // fichiers prévus pour les IA (llms.txt, versions .md) lus par leurs robots
            foreach (file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $p = explode("\t", $line);
                if (isset($p[1])) {
                    $bots[$p[1]] = ($bots[$p[1]] ?? 0) + 1;
                    $path = (string) ($p[2] ?? '');
                    if (in_array($p[1], wl_ai_bots(), true)) {
                        $f = str_ends_with($path, '.md') ? 'md' : (preg_match('#/(llms(-full)?\.txt)$#', $path, $m) ? $m[1] : null);
                        if ($f !== null) {
                            $files[$f] = ($files[$f] ?? 0) + 1;
                        }
                    }
                }
            }
            foreach ($bots as $name => $n) {
                Db::run("INSERT INTO stats_sources (day, kind, source, views) VALUES (?, 'bot', ?, ?) ON CONFLICT(day, kind, source) DO UPDATE SET views = views + excluded.views", [$day, $name, $n]);
            }
            foreach ($files as $name => $n) {
                Db::run("INSERT INTO stats_sources (day, kind, source, views) VALUES (?, 'aifile', ?, ?) ON CONFLICT(day, kind, source) DO UPDATE SET views = views + excluded.views", [$day, $name, $n]);
            }
            @unlink($tmp);
        }
        $today = gmdate('Ymd');
        foreach (glob($dir . '/salt-*.key') ?: [] as $f) {
            if (!str_contains($f, $today)) {
                @unlink($f);
            }
        }
        Db::run('DELETE FROM stats_seen WHERE day < ?', [gmdate('Y-m-d', time() - 86400)]);
    }

    private static function dayFromFile(string $file): string
    {
        preg_match('/-(\d{8})\.log/', $file, $m);
        $d = $m[1] ?? gmdate('Ymd');
        return substr($d, 0, 4) . '-' . substr($d, 4, 2) . '-' . substr($d, 6, 2);
    }

    /** Synthèse pour le tableau de bord. */
    public static function summary(int $days = 30): array
    {
        $from = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $prevFrom = gmdate('Y-m-d', time() - (2 * $days - 1) * 86400);
        $series = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $series[gmdate('Y-m-d', time() - $i * 86400)] = ['views' => 0, 'visitors' => 0];
        }
        foreach (Db::all('SELECT * FROM stats_days WHERE day >= ?', [$from]) as $r) {
            $series[$r['day']] = ['views' => (int) $r['views'], 'visitors' => (int) $r['visitors']];
        }
        $prev = Db::one('SELECT COALESCE(SUM(views), 0) AS views, COALESCE(SUM(visitors), 0) AS visitors FROM stats_days WHERE day >= ? AND day < ?', [$prevFrom, $from]);
        $top = Db::all('SELECT path, SUM(views) AS views FROM stats_pages WHERE day >= ? GROUP BY path ORDER BY views DESC LIMIT 8', [$from]);
        $sources = [];
        foreach (Db::all('SELECT kind, source, SUM(views) AS views FROM stats_sources WHERE day >= ? GROUP BY kind, source ORDER BY views DESC', [$from]) as $r) {
            $sources[$r['kind']][] = ['source' => $r['source'], 'views' => (int) $r['views']];
        }
        $sum = static fn (string $k) => array_sum(array_column($sources[$k] ?? [], 'views'));
        return [
            'series' => $series,
            'views' => array_sum(array_column($series, 'views')),
            'visitors' => array_sum(array_column($series, 'visitors')),
            'prev_views' => (int) ($prev['views'] ?? 0),
            'prev_visitors' => (int) ($prev['visitors'] ?? 0),
            'top' => $top,
            'sources' => $sources,
            'channels' => ['search' => $sum('search'), 'ai' => $sum('ai'), 'social' => $sum('social'), 'referral' => $sum('referral'), 'direct' => $sum('direct')],
            'bots' => $sources['bot'] ?? [],
            'ai_files' => array_column($sources['aifile'] ?? [], 'views', 'source'),
        ];
    }
}
