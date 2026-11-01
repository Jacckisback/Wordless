<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Jetons aléatoires, hachages et chiffrement des secrets stockés (clés API).
 * Chiffrement authentifié : libsodium (XSalsa20-Poly1305) ou, à défaut, AES-256-GCM.
 */
final class Crypto
{
    public static function token(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    private static function key(string $purpose): string
    {
        return hash_hmac('sha256', $purpose, Config::secret(), true);
    }

    public static function sign(string $data, string $purpose = 'sign'): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $data, self::key($purpose), true)), '+/', '-_'), '=');
    }

    public static function verify(string $data, string $signature, string $purpose = 'sign'): bool
    {
        return hash_equals(self::sign($data, $purpose), $signature);
    }

    public static function encrypt(string $plain): string
    {
        $key = self::key('encrypt');
        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 's1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'g1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): ?string
    {
        $key = self::key('encrypt');
        $raw = base64_decode(substr($encoded, 3), true);
        if ($raw === false) {
            return null;
        }
        if (str_starts_with($encoded, 's1:') && function_exists('sodium_crypto_secretbox_open')) {
            $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), $key);
            return $plain === false ? null : $plain;
        }
        if (str_starts_with($encoded, 'g1:')) {
            $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
            return $plain === false ? null : $plain;
        }
        return null;
    }

    /** Affichage partiel d'un secret : sk-ant-…a1B2 */
    public static function mask(string $secret): string
    {
        if ($secret === '') {
            return '';
        }
        return substr($secret, 0, min(7, (int) (strlen($secret) / 3))) . '…' . substr($secret, -4);
    }
}
