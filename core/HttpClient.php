<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Client HTTP sortant (import WordPress, médias distants, IA, IndexNow).
 * Protection SSRF : les adresses privées/locales sont refusées sauf si
 * data/config.php contient 'allow_private_fetch' => true (tests locaux).
 */
final class HttpClient
{
    public const UA = 'Wordless/' . WL_VERSION . ' (+https://wordless.cloud)';

    /**
     * @return array{status: int, headers: array<string, string>, body: string, url: string}
     */
    public static function request(string $method, string $url, array $opts = []): array
    {
        $maxBytes = (int) ($opts['max_bytes'] ?? 50 * 1024 * 1024);
        $timeout = (int) ($opts['timeout'] ?? 30);
        $headers = $opts['headers'] ?? [];
        $body = $opts['body'] ?? null;
        $redirects = (int) ($opts['redirects'] ?? 5);

        for ($hop = 0; $hop <= $redirects; $hop++) {
            $pin = empty($opts['trusted']) ? self::assertAllowed($url) : null;
            $res = function_exists('curl_init')
                ? self::curl($method, $url, $headers, $body, $timeout, $maxBytes, $opts['sink'] ?? null, $pin)
                : self::stream($method, $url, $headers, $body, $timeout, $maxBytes);
            if (in_array($res['status'], [301, 302, 303, 307, 308], true) && isset($res['headers']['location'])) {
                if ($redirects === 0) {
                    break; // redirections désactivées : l'appelant décide
                }
                $next = self::resolve($url, $res['headers']['location']);
                if (strtolower((string) parse_url($next, PHP_URL_HOST)) !== strtolower((string) parse_url($url, PHP_URL_HOST))) {
                    // Changement d'hôte : les identifiants ne suivent pas la redirection.
                    $headers = array_values(array_filter($headers, static fn ($h) => !preg_match('/^\s*(authorization|proxy-authorization|cookie|x-api-key)\s*:/i', (string) $h)));
                }
                $url = $next;
                if ($res['status'] === 303) {
                    $method = 'GET';
                    $body = null;
                }
                continue;
            }
            $res['url'] = $url;
            return $res;
        }
        if ($redirects === 0 && isset($res)) {
            $res['url'] = $url;
            return $res;
        }
        throw new \RuntimeException('Trop de redirections : ' . $url);
    }

    public static function get(string $url, array $opts = []): array
    {
        return self::request('GET', $url, $opts);
    }

    public static function postJson(string $url, array $data, array $headers = [], int $timeout = 60): array
    {
        $headers[] = 'Content-Type: application/json';
        return self::request('POST', $url, ['headers' => $headers, 'body' => json_enc($data), 'timeout' => $timeout]);
    }

    /** Télécharge vers un fichier local (médias volumineux) sans tout charger en mémoire. */
    public static function download(string $url, string $dest, int $maxBytes = 64 * 1024 * 1024): array
    {
        $fh = fopen($dest, 'wb');
        if (!$fh) {
            throw new \RuntimeException('Écriture impossible : ' . $dest);
        }
        try {
            $res = self::request('GET', $url, ['sink' => $fh, 'max_bytes' => $maxBytes, 'timeout' => 120]);
        } finally {
            fclose($fh);
        }
        return $res;
    }

    private static function curl(string $method, string $url, array $headers, ?string $body, int $timeout, int $maxBytes, $sink, ?string $pin = null): array
    {
        $ch = curl_init($url);
        if ($pin !== null) {
            // Épingle l'IP vérifiée : empêche un changement DNS entre contrôle et connexion.
            $p = parse_url($url);
            $port = $p['port'] ?? (strtolower($p['scheme']) === 'https' ? 443 : 80);
            curl_setopt($ch, CURLOPT_RESOLVE, [trim($p['host'], '[]') . ':' . $port . ':' . (str_contains($pin, ':') ? '[' . $pin . ']' : $pin)]);
        }
        $respHeaders = [];
        $buffer = '';
        $size = 0;
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$buffer, &$size, $maxBytes, $sink): int {
                $size += strlen($chunk);
                if ($size > $maxBytes) {
                    return 0; // interrompt le transfert
                }
                if ($sink) {
                    fwrite($sink, $chunk);
                } else {
                    $buffer .= $chunk;
                }
                return strlen($chunk);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($ok === false && $size <= $maxBytes) {
            throw new \RuntimeException('Requête impossible (' . $err . ') : ' . $url);
        }
        if ($size > $maxBytes) {
            throw new \RuntimeException('Fichier trop volumineux : ' . $url);
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $buffer];
    }

    private static function stream(string $method, string $url, array $headers, ?string $body, int $timeout, int $maxBytes): array
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", [...$headers, 'User-Agent: ' . self::UA]),
            'content' => $body ?? '',
            'timeout' => $timeout,
            'follow_location' => 0,
            'ignore_errors' => true,
        ]]);
        $data = @file_get_contents($url, false, $ctx, 0, $maxBytes + 1);
        if ($data === false) {
            throw new \RuntimeException('Requête impossible : ' . $url);
        }
        $respHeaders = [];
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                $status = (int) $m[1];
                $respHeaders = [];
            } elseif (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => $data];
    }

    public static function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        $p = parse_url($base);
        $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
        if (str_starts_with($location, '//')) {
            return ($p['scheme'] ?? 'https') . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
        return $origin . $dir . $location;
    }

    /**
     * Refuse les schémas non HTTP et les adresses internes (anti-SSRF).
     * Renvoie l'IP vérifiée à épingler (null si le nom d'hôte est déjà une IP ou si
     * les adresses privées sont autorisées).
     */
    public static function assertAllowed(string $url): ?string
    {
        $p = parse_url($url);
        $scheme = strtolower($p['scheme'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || empty($p['host'])) {
            throw new \RuntimeException('Adresse refusée : ' . $url);
        }
        if (Config::installed() && Config::get('allow_private_fetch', false)) {
            return null;
        }
        if (getenv('WL_ALLOW_PRIVATE_FETCH') === '1') {
            return null;
        }
        $host = trim($p['host'], '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if (function_exists('dns_get_record') && !filter_var($host, FILTER_VALIDATE_IP)) {
            foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $r) {
                if (!empty($r['ipv6'])) {
                    $ips[] = $r['ipv6'];
                }
            }
        }
        if (!$ips) {
            throw new \RuntimeException('Nom de domaine introuvable : ' . $host);
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new \RuntimeException('Adresse interne refusée : ' . $host);
            }
        }
        return filter_var($host, FILTER_VALIDATE_IP) ? null : $ips[0];
    }
}
