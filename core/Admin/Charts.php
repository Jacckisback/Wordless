<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\I18n;

/**
 * Graphiques du tableau de bord, rendus côté serveur en SVG accessible
 * (une seule teinte, barres fines, grille discrète, info-bulle au survol,
 * tableau de données équivalent).
 */
final class Charts
{
    /** Échelle « ronde » : 0, 5, 10, 20, 50, 100, 200… */
    private static function niceMax(int $max): int
    {
        if ($max <= 4) {
            return 4;
        }
        $pow = 10 ** (int) floor(log10($max));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $pow >= $max) {
                return (int) ($m * $pow);
            }
        }
        return (int) (10 * $pow);
    }

    /** Histogramme d'une série journalière. $series : ['2026-11-01' => 12, …] */
    public static function columns(array $series, string $label): string
    {
        $w = 720;
        $h = 200;
        $left = 40;
        $right = 8;
        $top = 10;
        $bottom = 26;
        $plotW = $w - $left - $right;
        $plotH = $h - $top - $bottom;
        $n = max(1, count($series));
        $max = self::niceMax((int) max([0, ...array_values($series)]));
        $slot = $plotW / $n;
        $barW = min(22, $slot * 0.62);
        $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($label) . '" data-chart>';
        foreach ([0, 0.5, 1] as $f) {
            $y = round($top + $plotH - $plotH * $f, 1);
            $svg .= '<line class="' . ($f === 0 ? 'axis' : 'grid-line') . '" x1="' . $left . '" x2="' . ($w - $right) . '" y1="' . $y . '" y2="' . $y . '"/>';
            $svg .= '<text class="tick" x="' . ($left - 8) . '" y="' . ($y + 4) . '" text-anchor="end">' . e(I18n::number((int) round($max * $f))) . '</text>';
        }
        $i = 0;
        $keys = array_keys($series);
        foreach ($series as $day => $v) {
            $x = $left + $i * $slot + ($slot - $barW) / 2;
            $bh = $max > 0 ? $plotH * $v / $max : 0;
            $y = $top + $plotH - $bh;
            $date = fmt_date((int) strtotime($day . ' 12:00:00 UTC'), 'short');
            $svg .= '<rect class="hit" x="' . round($left + $i * $slot, 1) . '" y="' . $top . '" width="' . round($slot, 1) . '" height="' . $plotH . '" tabindex="0" data-label="' . e($date) . '" data-value="' . e(I18n::number((int) $v)) . '"/>';
            if ($bh > 0) {
                $r = min(4, $bh, $barW / 2);
                $x2 = $x + $barW;
                $yb = $top + $plotH;
                $svg .= '<path class="bar" d="M' . round($x, 1) . ',' . round($yb, 1) . 'V' . round($y + $r, 1) . 'Q' . round($x, 1) . ',' . round($y, 1) . ' ' . round($x + $r, 1) . ',' . round($y, 1) . 'H' . round($x2 - $r, 1) . 'Q' . round($x2, 1) . ',' . round($y, 1) . ' ' . round($x2, 1) . ',' . round($y + $r, 1) . 'V' . round($yb, 1) . 'Z"/>';
            }
            if ($i === 0 || $i === $n - 1 || $i === intdiv($n, 2)) {
                $anchor = $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle');
                $tx = $i === 0 ? $left + $i * $slot : ($i === $n - 1 ? $left + ($i + 1) * $slot : $left + ($i + 0.5) * $slot);
                $svg .= '<text class="tick" x="' . round($tx, 1) . '" y="' . ($h - 6) . '" text-anchor="' . $anchor . '">' . e($date) . '</text>';
            }
            $i++;
        }
        $svg .= '</svg>';
        $table = '<details class="small muted"><summary>' . e(__('Voir les données')) . '</summary><table class="table"><thead><tr><th>' . e(__('Jour')) . '</th><th class="num">' . e($label) . '</th></tr></thead><tbody>';
        foreach (array_reverse($series, true) as $day => $v) {
            $table .= '<tr><td>' . e(fmt_date((int) strtotime($day . ' 12:00:00 UTC'))) . '</td><td class="num">' . e(I18n::number((int) $v)) . '</td></tr>';
        }
        $table .= '</tbody></table></details>';
        unset($keys);
        return '<div class="chart">' . $svg . '<div class="chart-tip" hidden></div>' . $table . '</div>';
    }

    /** Barres horizontales classées (une teinte, valeur au bout). @param list<array{0: string, 1: int}> $rows */
    public static function bars(array $rows, string $empty): string
    {
        $rows = array_values(array_filter($rows, static fn ($r) => $r[1] > 0));
        if (!$rows) {
            return '<p class="muted small">' . e($empty) . '</p>';
        }
        usort($rows, static fn ($a, $b) => $b[1] <=> $a[1]);
        $max = max(array_column($rows, 1));
        $out = '<ul class="hbars">';
        foreach (array_slice($rows, 0, 8) as [$label, $v]) {
            $pct = $max > 0 ? max(1, round($v / $max * 100, 1)) : 0;
            $out .= '<li class="hbar" title="' . e($label . ' : ' . I18n::number($v)) . '"><span class="hbar-label">' . e($label) . '</span><span class="hbar-track"><span class="hbar-fill" style="width:' . $pct . '%"></span></span><span class="hbar-value">' . e(I18n::number($v)) . '</span></li>';
        }
        return $out . '</ul>';
    }
}
