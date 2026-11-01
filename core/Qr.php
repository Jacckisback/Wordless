<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Générateur de QR code (mode octet, correction M, versions 1 à 20) en SVG,
 * sans bibliothèque. Sert à la configuration de la double authentification.
 * Algorithme d'après la norme ISO/IEC 18004 (structure inspirée de la
 * bibliothèque « QR Code generator » de Nayuki, licence MIT).
 */
final class Qr
{
    private const ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26];
    private const BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16];

    private int $size;
    /** @var array<int, array<int, bool>> */
    private array $m = [];
    /** @var array<int, array<int, bool>> */
    private array $fn = [];

    private function __construct(private int $version)
    {
        $this->size = $version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->m = array_fill(0, $this->size, $row);
        $this->fn = array_fill(0, $this->size, $row);
    }

    /** SVG du QR code pour $text (null si trop long). */
    public static function svg(string $text, int $moduleSize = 4): ?string
    {
        $qr = self::encode($text);
        if (!$qr) {
            return null;
        }
        $n = $qr->size;
        $q = 4;
        $path = '';
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($qr->m[$y][$x]) {
                    $path .= 'M' . ($x + $q) . ',' . ($y + $q) . 'h1v1h-1z';
                }
            }
        }
        $dim = $n + 2 * $q;
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $dim . ' ' . $dim . '" width="' . ($dim * $moduleSize) . '" height="' . ($dim * $moduleSize) . '" shape-rendering="crispEdges" role="img" aria-label="QR code"><rect width="100%" height="100%" fill="#fff"/><path d="' . $path . '" fill="#000"/></svg>';
    }

    /** Matrice brute (tests). @return list<list<bool>>|null */
    public static function matrix(string $text, ?int $forceMask = null): ?array
    {
        $qr = self::encode($text, $forceMask);
        return $qr ? $qr->m : null;
    }

    private static function encode(string $text, ?int $forceMask = null): ?self
    {
        $len = strlen($text);
        for ($v = 1; $v <= 20; $v++) {
            $cap = self::dataCodewords($v) * 8;
            $need = 4 + ($v <= 9 ? 8 : 16) + $len * 8;
            if ($need <= $cap) {
                break;
            }
        }
        if ($v > 20) {
            return null;
        }
        // Flux de bits : mode octet (0100), longueur, données, terminaison, remplissage.
        $bits = [];
        $push = static function (int $val, int $n) use (&$bits): void {
            for ($i = $n - 1; $i >= 0; $i--) {
                $bits[] = ($val >> $i) & 1;
            }
        };
        $push(4, 4);
        $push($len, $v <= 9 ? 8 : 16);
        for ($i = 0; $i < $len; $i++) {
            $push(ord($text[$i]), 8);
        }
        $capBits = self::dataCodewords($v) * 8;
        $push(0, min(4, $capBits - count($bits)));
        $push(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capBits; $pad ^= 0xEC ^ 0x11) {
            $push($pad, 8);
        }
        $data = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $data[] = (int) bindec(implode('', $byte));
        }

        $qr = new self($v);
        $qr->drawFunctionPatterns();
        $qr->drawCodewords($qr->interleave($data));
        $best = $forceMask ?? 0;
        if ($forceMask === null) {
            $min = PHP_INT_MAX;
            for ($mask = 0; $mask < 8; $mask++) {
                $qr->applyMask($mask);
                $qr->drawFormat($mask);
                $p = $qr->penalty();
                if ($p < $min) {
                    $min = $p;
                    $best = $mask;
                }
                $qr->applyMask($mask); // annule (XOR)
            }
        }
        $qr->applyMask($best);
        $qr->drawFormat($best);
        return $qr;
    }

    private static function rawModules(int $v): int
    {
        $r = (16 * $v + 128) * $v + 64;
        if ($v >= 2) {
            $na = intdiv($v, 7) + 2;
            $r -= (25 * $na - 10) * $na - 55;
            if ($v >= 7) {
                $r -= 36;
            }
        }
        return $r;
    }

    private static function dataCodewords(int $v): int
    {
        return intdiv(self::rawModules($v), 8) - self::ECC_PER_BLOCK[$v] * self::BLOCKS[$v];
    }

    private function set(int $x, int $y, bool $dark): void
    {
        $this->m[$y][$x] = $dark;
        $this->fn[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        $n = $this->size;
        for ($i = 0; $i < $n; $i++) {
            $this->set(6, $i, $i % 2 === 0);
            $this->set($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) {
                        $d = max(abs($dx), abs($dy));
                        $this->set($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        $pos = $this->alignmentPositions();
        $last = count($pos) - 1;
        foreach ($pos as $i => $px) {
            foreach ($pos as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) {
                    continue;
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->drawFormat(0);
        if ($this->version >= 7) {
            $rem = $this->version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($this->version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bits >> $i) & 1) === 1;
                $a = $n - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $bit);
                $this->set($b, $a, $bit);
            }
        }
    }

    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $num = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 4 + $num * 2 + 1, $num * 2 - 2) * 2;
        $res = [6];
        $p = [];
        for ($i = $num - 1, $pos = $this->size - 7; $i >= 1; $i--, $pos -= $step) {
            $p[$i] = $pos;
        }
        ksort($p);
        return array_merge($res, array_values($p));
    }

    private function drawFormat(int $mask): void
    {
        $data = (0 << 3) | $mask; // niveau M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;
        $n = $this->size;
        for ($i = 0; $i <= 5; $i++) {
            $this->set(8, $i, $bit($i));
        }
        $this->set(8, 7, $bit(6));
        $this->set(8, 8, $bit(7));
        $this->set(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->set(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($n - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->set(8, $n - 15 + $i, $bit($i));
        }
        $this->set(8, $n - 8, true);
    }

    private function interleave(array $data): array
    {
        $v = $this->version;
        $numBlocks = self::BLOCKS[$v];
        $eccLen = self::ECC_PER_BLOCK[$v];
        $raw = intdiv(self::rawModules($v), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);
        $divisor = self::rsDivisor($eccLen);
        $blocks = [];
        $k = 0;
        for ($i = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $numShort) {
                $dat[] = 0;
            }
            $blocks[] = array_merge($dat, $ecc);
        }
        $out = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $b) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $out[] = $b[$i];
                }
            }
        }
        return $out;
    }

    private static function rsMul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z & 0xFF;
    }

    private static function rsDivisor(int $degree): array
    {
        $r = array_fill(0, $degree, 0);
        $r[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $r[$j] = self::rsMul($r[$j], $root);
                if ($j + 1 < $degree) {
                    $r[$j] ^= $r[$j + 1];
                }
            }
            $root = self::rsMul($root, 0x02);
        }
        return $r;
    }

    private static function rsRemainder(array $data, array $divisor): array
    {
        $r = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($divisor as $i => $d) {
                $r[$i] ^= self::rsMul($d, $factor);
            }
        }
        return $r;
    }

    private function drawCodewords(array $data): void
    {
        $n = $this->size;
        $total = count($data) * 8;
        $i = 0;
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $n; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $up = (($right + 1) & 2) === 0;
                    $y = $up ? $n - 1 - $vert : $vert;
                    if (!$this->fn[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        $n = $this->size;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                if ($this->fn[$y][$x]) {
                    continue;
                }
                $inv = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => ((($x + $y) % 2) + ($x * $y) % 3) % 2 === 0,
                };
                if ($inv) {
                    $this->m[$y][$x] = !$this->m[$y][$x];
                }
            }
        }
    }

    /** Pénalités de la norme (règles 1 à 4) pour choisir le meilleur masque. */
    private function penalty(): int
    {
        $n = $this->size;
        $p = 0;
        $lines = [];
        for ($i = 0; $i < $n; $i++) {
            $row = '';
            $col = '';
            for ($j = 0; $j < $n; $j++) {
                $row .= $this->m[$i][$j] ? '1' : '0';
                $col .= $this->m[$j][$i] ? '1' : '0';
            }
            $lines[] = $row;
            $lines[] = $col;
        }
        foreach ($lines as $l) {
            if (preg_match_all('/0{5,}|1{5,}/', $l, $mm)) {
                foreach ($mm[0] as $run) {
                    $p += 3 + strlen($run) - 5;
                }
            }
            $p += 40 * (substr_count($l, '10111010000') + substr_count($l, '00001011101'));
        }
        $dark = 0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $c = $this->m[$y][$x];
                $dark += $c ? 1 : 0;
                if ($x < $n - 1 && $y < $n - 1 && $c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) {
                    $p += 3;
                }
            }
        }
        $total = $n * $n;
        $p += (int) (floor(abs($dark * 100 / $total - 50) / 5) * 10);
        return $p;
    }
}
