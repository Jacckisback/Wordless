<?php
/**
 * Statistiques de visite sans cookie ni donnée personnelle.
 * Une ligne par vue est ajoutée à un journal du jour ; l'agrégation en base se
 * fait plus tard (Analytics::aggregate). L'identifiant de visiteur est un hachage
 * salé avec un sel qui change chaque jour et n'est jamais conservé : impossible
 * de suivre quelqu'un d'un jour à l'autre (pas de bandeau cookies nécessaire).
 */
declare(strict_types=1);

if (!function_exists('wl_track_hit')) {
    /** Robots connus : nom affiché => motif dans le User-Agent. */
    function wl_known_bots(): array
    {
        return [
            // Moteurs de réponse IA (GEO)
            'GPTBot' => 'GPTBot', 'OAI-SearchBot' => 'OAI-SearchBot', 'ChatGPT-User' => 'ChatGPT-User',
            'ClaudeBot' => 'ClaudeBot', 'Claude-User' => 'Claude-User', 'Claude-SearchBot' => 'Claude-SearchBot',
            'PerplexityBot' => 'PerplexityBot', 'Perplexity-User' => 'Perplexity-User',
            'Google-CloudVertexBot' => 'Google-CloudVertexBot', 'Gemini' => 'Gemini-Deep-Research',
            'Meta AI' => 'meta-externalagent', 'MistralAI-User' => 'MistralAI-User', 'DuckAssistBot' => 'DuckAssistBot',
            'Amazonbot' => 'Amazonbot', 'Bytespider' => 'Bytespider', 'CCBot' => 'CCBot', 'cohere-ai' => 'cohere-ai',
            // Moteurs de recherche
            'Googlebot' => 'Googlebot', 'Bingbot' => 'bingbot', 'Applebot' => 'Applebot', 'DuckDuckBot' => 'DuckDuckBot',
            'YandexBot' => 'YandexBot', 'Qwant' => 'Qwantbot', 'Baiduspider' => 'Baiduspider',
        ];
    }

    function wl_ai_bots(): array
    {
        return ['GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-User', 'Claude-SearchBot', 'PerplexityBot',
            'Perplexity-User', 'Google-CloudVertexBot', 'Gemini', 'Meta AI', 'MistralAI-User', 'DuckAssistBot', 'Amazonbot',
            'Bytespider', 'CCBot', 'cohere-ai'];
    }

    function wl_stats_dir(): string
    {
        return (defined('WL_DATA') ? WL_DATA : WL_ROOT . '/data') . '/stats';
    }

    /**
     * Enregistre une vue. $botsOnly : versions pour les IA (.md, llms.txt) — on ne compte
     * que le passage des robots, pour savoir si les moteurs d'IA lisent ces fichiers.
     */
    function wl_track_hit(string $path, bool $botsOnly = false): void
    {
        if ($botsOnly && !preg_match('#(\.md|/llms(-full)?\.txt)$#', $path)) {
            return;
        }
        $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $purpose = strtolower((string) ($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? ''));
        if (str_contains($purpose, 'prefetch')) {
            return;
        }
        $dir = wl_stats_dir();
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            return;
        }
        $day = gmdate('Ymd');
        $path = substr(str_replace(["\t", "\n", "\r"], ' ', $path), 0, 300);

        foreach (wl_known_bots() as $name => $needle) {
            if (stripos($ua, $needle) !== false) {
                @file_put_contents("$dir/bots-$day.log", time() . "\t$name\t$path\n", FILE_APPEND | LOCK_EX);
                return;
            }
        }
        if ($botsOnly) {
            return;
        }
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|java\/|go-http|okhttp|headless|lighthouse|pingdom|uptime|scrapy|httpclient|axios|node-fetch|phantom/i', $ua)) {
            return;
        }

        $saltFile = "$dir/salt-$day.key";
        $salt = @file_get_contents($saltFile);
        if ($salt === false || $salt === '') {
            $salt = bin2hex(random_bytes(16));
            @file_put_contents($saltFile, $salt, LOCK_EX);
        }
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        $visitor = substr(hash('sha256', $salt . '|' . $ip . '|' . $ua), 0, 16);

        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $refHost = $ref !== '' ? strtolower((string) parse_url($ref, PHP_URL_HOST)) : '';
        $selfHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $selfHost = preg_replace('/:\d+$/', '', $selfHost);
        if ($refHost !== '' && ($refHost === $selfHost || $refHost === 'www.' . $selfHost || 'www.' . $refHost === $selfHost)) {
            $refHost = '-'; // navigation interne
        }
        $utm = '';
        if (isset($_GET['utm_source']) && is_string($_GET['utm_source'])) {
            $utm = strtolower(substr(preg_replace('/[^a-zA-Z0-9._-]/', '', $_GET['utm_source']), 0, 60));
        }
        $device = preg_match('/iPad|Tablet/i', $ua) ? 't' : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'm' : 'd');

        @file_put_contents(
            "$dir/hits-$day.log",
            time() . "\t$path\t$refHost\t$visitor\t$device\t$utm\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
