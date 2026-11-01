<?php
/**
 * Adresse IP du visiteur, y compris derrière un proxy ou un CDN de confiance.
 *
 * Déclarez les adresses de vos proxys dans data/config.php :
 *     'trusted_proxies' => ['203.0.113.10', '173.245.48.0/20'],
 * ou dans la variable d'environnement WL_TRUSTED_PROXIES (séparées par des virgules).
 *
 * Sans proxy déclaré, seule REMOTE_ADDR compte : un en-tête X-Forwarded-For envoyé
 * par un visiteur ne permet donc pas d'usurper une autre adresse. Derrière un proxy
 * déclaré, l'adresse retenue est le premier saut non fiable de X-Forwarded-For en
 * partant de la droite (chaque proxy ajoute l'adresse qu'il a reçue).
 *
 * Fichier autonome : il sert aussi au cache rapide, avant le démarrage de l'application.
 */
declare(strict_types=1);

if (!function_exists('wl_client_ip')) {
    /** @param list<string> $ranges adresses exactes ou plages CIDR (IPv4 et IPv6) */
    function wl_ip_in(string $ip, array $ranges): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($ranges as $range) {
            $range = trim((string) $range);
            if ($range === '') {
                continue;
            }
            [$net, $bits] = str_contains($range, '/') ? explode('/', $range, 2) : [$range, null];
            $netBin = @inet_pton(trim($net));
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $len = strlen($bin) * 8;
            $bits = $bits === null ? $len : max(0, min($len, (int) $bits));
            $bytes = intdiv($bits, 8);
            if (substr($bin, 0, $bytes) !== substr($netBin, 0, $bytes)) {
                continue;
            }
            $rest = $bits % 8;
            if ($rest === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $rest)) & 0xFF;
            if ((ord($bin[$bytes]) & $mask) === (ord($netBin[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> proxys déclarés dans la configuration et dans WL_TRUSTED_PROXIES */
    function wl_trusted_proxies(array $configured = []): array
    {
        $env = (string) getenv('WL_TRUSTED_PROXIES');
        $list = array_merge($configured, $env !== '' ? explode(',', $env) : []);
        return array_values(array_filter(array_map(static fn ($p) => trim((string) $p), $list), static fn ($p) => $p !== ''));
    }

    /** @param list<string> $trusted */
    function wl_client_ip(array $trusted = []): string
    {
        $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        if (!$trusted || !wl_ip_in($remote, $trusted)) {
            return $remote;
        }
        $hops = array_values(array_filter(array_map('trim', explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')))));
        for ($i = count($hops) - 1; $i >= 0; $i--) {
            $hop = preg_replace(['/^\[([^\]]+)\](?::\d+)?$/', '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/'], '$1', $hops[$i]) ?? '';
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!wl_ip_in($hop, $trusted)) {
                return $hop;
            }
        }
        $real = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
        return filter_var($real, FILTER_VALIDATE_IP) !== false ? $real : $remote;
    }
}
