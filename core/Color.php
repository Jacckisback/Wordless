<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Couleurs : contraste WCAG, mélange, conversions OKLCH (perceptuellement
 * uniforme) pour corriger une palette sans changer sa teinte.
 */
final class Color
{
    /** @return array{0: float, 1: float, 2: float} composantes sRGB 0-1 */
    public static function rgb(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            return [0.0, 0.0, 0.0];
        }
        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }

    public static function hex(array $rgb): string
    {
        return '#' . implode('', array_map(static fn ($v) => str_pad(dechex((int) round(max(0, min(1, $v)) * 255)), 2, '0', STR_PAD_LEFT), $rgb));
    }

    public static function valid(string $hex): bool
    {
        return (bool) preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($hex));
    }

    private static function lin(float $c): float
    {
        return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    }

    private static function delin(float $c): float
    {
        return $c <= 0.0031308 ? 12.92 * $c : 1.055 * ($c ** (1 / 2.4)) - 0.055;
    }

    public static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map([self::class, 'lin'], self::rgb($hex));
        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** Rapport de contraste WCAG 2.x (1 à 21). */
    public static function contrast(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);
        return round((max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05), 2);
    }

    /** Mélange $a dans $b (ratio = part de $a). */
    public static function mix(string $a, string $b, float $ratio): string
    {
        $x = self::rgb($a);
        $y = self::rgb($b);
        return self::hex([$x[0] * $ratio + $y[0] * (1 - $ratio), $x[1] * $ratio + $y[1] * (1 - $ratio), $x[2] * $ratio + $y[2] * (1 - $ratio)]);
    }

    /** @return array{0: float, 1: float, 2: float} L (0-1), C, H (degrés) */
    public static function oklch(string $hex): array
    {
        [$r, $g, $b] = array_map([self::class, 'lin'], self::rgb($hex));
        $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
        $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
        $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;
        [$l, $m, $s] = [self::cbrt($l), self::cbrt($m), self::cbrt($s)];
        $L = 0.2104542553 * $l + 0.7936177850 * $m - 0.0040720468 * $s;
        $A = 1.9779984951 * $l - 2.4285922050 * $m + 0.4505937099 * $s;
        $B = 0.0259040371 * $l + 0.7827717662 * $m - 0.8086757660 * $s;
        $C = sqrt($A * $A + $B * $B);
        $H = fmod(rad2deg(atan2($B, $A)) + 360, 360);
        return [$L, $C, $H];
    }

    public static function fromOklch(float $L, float $C, float $H): string
    {
        // Réduit la chroma jusqu'à entrer dans le gamut sRGB.
        for ($i = 0; $i < 40; $i++) {
            $a = $C * cos(deg2rad($H));
            $b = $C * sin(deg2rad($H));
            $l = ($L + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
            $m = ($L - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
            $s = ($L - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;
            $rgb = [
                4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
                -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
                -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
            ];
            if (min($rgb) >= -0.0005 && max($rgb) <= 1.0005) {
                return self::hex(array_map([self::class, 'delin'], array_map(static fn ($v) => max(0, min(1, $v)), $rgb)));
            }
            $C *= 0.93;
        }
        return self::hex(array_map([self::class, 'delin'], [max(0, min(1, $L)), max(0, min(1, $L)), max(0, min(1, $L))]));
    }

    private static function cbrt(float $x): float
    {
        return $x < 0 ? -((-$x) ** (1 / 3)) : $x ** (1 / 3);
    }

    /**
     * Ajuste la clarté de $fg (teinte conservée) jusqu'à atteindre $target
     * de contraste sur $bg. Renvoie la couleur corrigée.
     */
    public static function ensureContrast(string $fg, string $bg, float $target = 4.5): string
    {
        if (self::contrast($fg, $bg) >= $target) {
            return $fg;
        }
        [$L, $C, $H] = self::oklch($fg);
        $darker = self::luminance($bg) > 0.4;
        for ($i = 0; $i < 60; $i++) {
            $L = $darker ? $L - 0.015 : $L + 0.015;
            $L = max(0, min(1, $L));
            $candidate = self::fromOklch($L, $C, $H);
            if (self::contrast($candidate, $bg) >= $target) {
                return $candidate;
            }
        }
        return $darker ? '#000000' : '#ffffff';
    }

    /** Texte lisible (noir ou blanc teinté) sur une couleur de fond. */
    public static function onColor(string $bg): string
    {
        return self::contrast('#ffffff', $bg) >= self::contrast('#111111', $bg) ? '#ffffff' : '#111111';
    }

    public static function hue(string $hex): float
    {
        return self::oklch($hex)[2];
    }
}
