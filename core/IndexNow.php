<?php
declare(strict_types=1);

namespace Wordless;

/**
 * IndexNow : prévient Bing, Yandex, Seznam, Naver… (et les moteurs IA qui
 * s'appuient sur Bing) dès qu'une page est publiée ou modifiée.
 */
final class IndexNow
{
    public static function key(): string
    {
        $k = (string) Settings::get('seo.indexnow_key', '');
        if ($k === '') {
            $k = bin2hex(random_bytes(16));
            Settings::set('seo.indexnow_key', $k);
        }
        return $k;
    }

    public static function active(): bool
    {
        $host = (string) parse_url(Config::baseUrl(), PHP_URL_HOST);
        $local = $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) || str_ends_with($host, '.local') || str_ends_with($host, '.test');
        return !$local && !Settings::get('seo.noindex_site', false) && (bool) Settings::get('seo.indexnow', true);
    }

    public static function queue(string $url): void
    {
        if (!self::active()) {
            return;
        }
        $q = (array) Settings::get('indexnow.queue', []);
        $q[] = $url;
        Settings::set('indexnow.queue', array_slice(array_values(array_unique($q)), -1000));
    }

    public static function flush(): int
    {
        $q = (array) Settings::get('indexnow.queue', []);
        if (!$q || !self::active()) {
            return 0;
        }
        Settings::set('indexnow.queue', []);
        $host = (string) parse_url(Config::baseUrl(), PHP_URL_HOST);
        try {
            $res = HttpClient::postJson('https://api.indexnow.org/indexnow', [
                'host' => $host,
                'key' => self::key(),
                'keyLocation' => Config::baseUrl() . '/' . self::key() . '.txt',
                'urlList' => array_values($q),
            ], [], 15);
            Settings::set('indexnow.last', ['at' => time(), 'count' => count($q), 'status' => $res['status']]);
            return count($q);
        } catch (\Throwable $e) {
            Settings::set('indexnow.last', ['at' => time(), 'count' => 0, 'status' => 0, 'error' => $e->getMessage()]);
            return 0;
        }
    }
}
