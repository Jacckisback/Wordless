<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Double authentification TOTP (RFC 6238), compatible Google Authenticator,
 * Authy, 1Password, Bitwarden… Fenêtre de ±1 pas de 30 s.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function secret(int $bytes = 20): string
    {
        return self::base32(random_bytes($bytes));
    }

    public static function base32(string $raw): string
    {
        $bits = '';
        foreach (str_split($raw) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function decode(string $b32): string
    {
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32) ?? '');
        $bits = '';
        foreach (str_split($b32) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public static function code(string $secret, ?int $time = null): string
    {
        $counter = intdiv($time ?? time(), 30);
        $h = hash_hmac('sha1', pack('N*', 0) . pack('N*', $counter), self::decode($secret), true);
        $o = ord($h[19]) & 0xf;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if ($secret === '' || strlen($code) !== 6) {
            return false;
        }
        foreach ([-1, 0, 1] as $step) {
            if (hash_equals(self::code($secret, time() + $step * 30), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account)
            . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    /** 8 codes de secours à usage unique (affichés une fois, stockés hachés). */
    public static function recoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $c = strtoupper(substr(self::base32(random_bytes(6)), 0, 10));
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        return $codes;
    }
}
