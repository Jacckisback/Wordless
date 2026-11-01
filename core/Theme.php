<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Thème : jetons de design (couleurs, typographies, échelle, rayons),
 * directions artistiques prêtes à l'emploi, CSS critique en ligne, gabarits PHP.
 */
final class Theme
{
    /** Piles de polices système (Modern Font Stacks, CC0) : zéro téléchargement. */
    public const FONTS = [
        'system' => ['label' => 'Système (interface)', 'stack' => 'system-ui, -apple-system, "Segoe UI", sans-serif'],
        'humanist' => ['label' => 'Humaniste', 'stack' => 'Seravek, "Gill Sans Nova", Ubuntu, Calibri, "DejaVu Sans", source-sans-pro, sans-serif'],
        'geometric' => ['label' => 'Géométrique', 'stack' => 'Avenir, Montserrat, Corbel, "URW Gothic", source-sans-pro, sans-serif'],
        'classical' => ['label' => 'Humaniste classique', 'stack' => 'Optima, Candara, "Noto Sans", source-sans-pro, sans-serif'],
        'grotesque' => ['label' => 'Grotesque', 'stack' => '"Helvetica Neue", "Arial Nova", "Nimbus Sans", Arial, sans-serif'],
        'industrial' => ['label' => 'Industrielle', 'stack' => 'Bahnschrift, "DIN Alternate", "Franklin Gothic Medium", "Nimbus Sans Narrow", sans-serif-condensed, sans-serif'],
        'rounded' => ['label' => 'Arrondie', 'stack' => 'ui-rounded, "Hiragino Maru Gothic ProN", Quicksand, Comfortaa, Manjari, "Arial Rounded MT", Calibri, source-sans-pro, sans-serif'],
        'transitional' => ['label' => 'Serif transitionnelle', 'stack' => 'Charter, "Bitstream Charter", "Sitka Text", Cambria, serif'],
        'oldstyle' => ['label' => 'Serif elzévirienne', 'stack' => '"Iowan Old Style", "Palatino Linotype", "URW Palladio L", P052, serif'],
        'didone' => ['label' => 'Didone (mode, luxe)', 'stack' => 'Didot, "Bodoni MT", "Noto Serif Display", "URW Palladio L", P052, Sylfaen, serif'],
        'slab' => ['label' => 'Slab', 'stack' => 'Rockwell, "Rockwell Nova", "Roboto Slab", "DejaVu Serif", "Sitka Small", serif'],
        'antique' => ['label' => 'Antique', 'stack' => 'Superclarendon, "Bookman Old Style", "URW Bookman", "URW Bookman L", "Georgia Pro", Georgia, serif'],
        'mono' => ['label' => 'Chasse fixe', 'stack' => 'ui-monospace, "Cascadia Code", "Source Code Pro", Menlo, Consolas, "DejaVu Sans Mono", monospace'],
    ];

    public static function presets(): array
    {
        return [
            'encre' => ['label' => __('Encre — éditorial'), 'description' => __('Serif littéraire, encre noire, rouge vermillon. Pour les blogs, médias, auteurs.'),
                'colors' => ['bg' => '#ffffff', 'surface' => '#f2f3f1', 'text' => '#17191d', 'muted' => '#555b66', 'accent' => '#b3311d', 'accent_text' => '#ffffff', 'line' => '#dcdfdc'],
                'fonts' => ['heading' => 'oldstyle', 'body' => 'transitional'], 'base' => 19, 'ratio' => 1.333, 'measure' => 66, 'radius' => 2, 'heading_weight' => 600, 'tracking' => -0.01, 'density' => 'normal', 'scheme' => 'light', 'buttons' => 'solid'],
            'atelier' => ['label' => __('Atelier — artisan'), 'description' => __('Sans-serif humaniste, vert profond, formes douces. Commerces, artisans, restaurants.'),
                'colors' => ['bg' => '#ffffff', 'surface' => '#edf3ef', 'text' => '#1c2420', 'muted' => '#53605a', 'accent' => '#2c6a4d', 'accent_text' => '#ffffff', 'line' => '#d6e0da'],
                'fonts' => ['heading' => 'humanist', 'body' => 'humanist'], 'base' => 18, 'ratio' => 1.25, 'measure' => 68, 'radius' => 10, 'heading_weight' => 700, 'tracking' => -0.015, 'density' => 'normal', 'scheme' => 'light', 'buttons' => 'solid'],
            'clarte' => ['label' => __('Clarté — services'), 'description' => __('Géométrique, bleu franc, beaucoup d’air. Consultants, cabinets, SaaS.'),
                'colors' => ['bg' => '#ffffff', 'surface' => '#f1f5fa', 'text' => '#0e1828', 'muted' => '#4b586c', 'accent' => '#1d5bd0', 'accent_text' => '#ffffff', 'line' => '#dbe3ee'],
                'fonts' => ['heading' => 'geometric', 'body' => 'system'], 'base' => 18, 'ratio' => 1.3, 'measure' => 68, 'radius' => 8, 'heading_weight' => 700, 'tracking' => -0.02, 'density' => 'airy', 'scheme' => 'light', 'buttons' => 'solid'],
            'nuit' => ['label' => __('Nuit — studio'), 'description' => __('Fond sombre, ambre, typographie industrielle. Studios, créatifs, tech.'),
                'colors' => ['bg' => '#0f1114', 'surface' => '#181b20', 'text' => '#eceef1', 'muted' => '#a2a8b3', 'accent' => '#f0b232', 'accent_text' => '#1b1405', 'line' => '#2b2f36'],
                'fonts' => ['heading' => 'industrial', 'body' => 'system'], 'base' => 18, 'ratio' => 1.333, 'measure' => 66, 'radius' => 6, 'heading_weight' => 600, 'tracking' => -0.01, 'density' => 'normal', 'scheme' => 'dark', 'buttons' => 'solid'],
            'brut' => ['label' => __('Brut — affirmé'), 'description' => __('Grotesque très gras, noir et orange, angles vifs. Événements, marques fortes.'),
                'colors' => ['bg' => '#ffffff', 'surface' => '#f0f0ee', 'text' => '#111111', 'muted' => '#4a4a48', 'accent' => '#e8461b', 'accent_text' => '#111111', 'line' => '#d8d8d4'],
                'fonts' => ['heading' => 'grotesque', 'body' => 'grotesque'], 'base' => 18, 'ratio' => 1.414, 'measure' => 64, 'radius' => 0, 'heading_weight' => 800, 'tracking' => -0.03, 'density' => 'normal', 'scheme' => 'light', 'buttons' => 'solid'],
            'douceur' => ['label' => __('Douceur — bien-être'), 'description' => __('Arrondie, framboise et sauge, rythme posé. Santé, coaching, bien-être.'),
                'colors' => ['bg' => '#ffffff', 'surface' => '#f2f6f3', 'text' => '#22201f', 'muted' => '#5d5856', 'accent' => '#a83f62', 'accent_text' => '#ffffff', 'line' => '#e1e6e2'],
                'fonts' => ['heading' => 'rounded', 'body' => 'humanist'], 'base' => 18, 'ratio' => 1.25, 'measure' => 66, 'radius' => 16, 'heading_weight' => 700, 'tracking' => -0.01, 'density' => 'airy', 'scheme' => 'light', 'buttons' => 'solid'],
        ];
    }

    /** Jetons temporaires (aperçu du design avant enregistrement). */
    public static ?array $override = null;

    public static function tokens(): array
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $presets = self::presets();
        $design = (array) Settings::get('design', []);
        $preset = $presets[$design['preset'] ?? 'atelier'] ?? $presets['atelier'];
        $t = array_replace_recursive($preset, $design);
        $t['colors'] = array_merge($preset['colors'], (array) ($design['colors'] ?? []));
        $t['fonts'] = array_merge($preset['fonts'], (array) ($design['fonts'] ?? []));
        $t['header'] = $t['header'] ?? 'split';
        $t['width'] = (int) ($t['width'] ?? 72);
        return $t;
    }

    public static function fontStack(string $key, array $custom = []): string
    {
        if (isset($custom[$key])) {
            return '"' . $custom[$key]['family'] . '", ' . (self::FONTS[$custom[$key]['fallback'] ?? 'system']['stack'] ?? self::FONTS['system']['stack']);
        }
        return self::FONTS[$key]['stack'] ?? self::FONTS['system']['stack'];
    }

    /** Variables CSS calculées à partir des jetons. */
    public static function cssVars(array $t): string
    {
        $c = $t['colors'];
        $base = max(15, min(22, (int) $t['base']));
        $ratio = max(1.1, min(1.6, (float) $t['ratio']));
        $size = static function (int $step) use ($base, $ratio): string {
            $px = $base * ($ratio ** $step);
            $px = min($px, 76);
            $min = max($base, $px * ($step >= 3 ? 0.62 : 0.8));
            $vw = round($px / 12, 2);
            return 'clamp(' . round($min / 16, 3) . 'rem, ' . round($min / 16 * 0.55, 3) . 'rem + ' . $vw . 'vw, ' . round($px / 16, 3) . 'rem)';
        };
        $space = ['compact' => 1.25, 'normal' => 1.5, 'airy' => 1.85][$t['density'] ?? 'normal'] ?? 1.5;
        $custom = (array) ($t['custom_fonts'] ?? []);
        $vars = [
            '--c-bg' => $c['bg'], '--c-surface' => $c['surface'], '--c-text' => $c['text'], '--c-muted' => $c['muted'],
            '--c-accent' => $c['accent'], '--c-accent-text' => $c['accent_text'], '--c-line' => $c['line'],
            '--c-accent-soft' => Color::mix($c['accent'], $c['bg'], 0.12),
            '--c-link' => Color::contrast($c['accent'], $c['bg']) >= 4.5 ? $c['accent'] : $c['text'],
            '--f-heading' => self::fontStack((string) $t['fonts']['heading'], $custom),
            '--f-body' => self::fontStack((string) $t['fonts']['body'], $custom),
            '--fs-base' => round($base / 16, 4) . 'rem',
            '--fs-sm' => round($base / $ratio / 16 * 1.08, 4) . 'rem',
            '--fs-lead' => round($base * 1.15 / 16, 4) . 'rem',
            '--fs-h4' => $size(1), '--fs-h3' => $size(2), '--fs-h2' => $size(3), '--fs-h1' => $size(4), '--fs-display' => $size(5),
            '--measure' => max(52, min(80, (int) $t['measure'])) . 'ch',
            '--radius' => max(0, min(28, (int) $t['radius'])) . 'px',
            '--space' => $space . 'rem',
            '--hw' => (string) max(400, min(900, (int) $t['heading_weight'])),
            '--ht' => max(-0.04, min(0.05, (float) $t['tracking'])) . 'em',
            '--wide' => max(56, min(90, (int) $t['width'])) . 'rem',
        ];
        $css = ':root{';
        foreach ($vars as $k => $v) {
            $css .= $k . ':' . $v . ';';
        }
        $css .= 'color-scheme:' . (($t['scheme'] ?? 'light') === 'dark' ? 'dark' : 'light') . '}';
        foreach ($custom as $f) {
            foreach ((array) ($f['faces'] ?? []) as $face) {
                $css .= '@font-face{font-family:"' . $f['family'] . '";src:url(' . media_url($face['file']) . ') format("woff2");font-weight:' . $face['weight'] . ';font-style:' . $face['style'] . ';font-display:swap' . (!empty($face['range']) ? ';unicode-range:' . $face['range'] : '') . '}';
            }
        }
        return $css;
    }

    public static function dir(): string
    {
        $name = basename((string) Settings::get('theme', 'default'));
        $dir = WL_THEMES . '/' . $name;
        return is_dir($dir) ? $dir : WL_THEMES . '/default';
    }

    /** CSS complet (jetons + feuille du thème), minifié, mis en cache. */
    public static function inlineCss(): string
    {
        static $css = null;
        if (self::$override !== null) {
            $file = self::dir() . '/assets/theme.css';
            return self::minify(self::cssVars(self::$override) . (is_file($file) ? (string) file_get_contents($file) : '') . (string) (self::$override['custom_css'] ?? Settings::get('design.custom_css', '')));
        }
        if ($css !== null) {
            return $css;
        }
        $t = self::tokens();
        $file = self::dir() . '/assets/theme.css';
        $key = md5(json_enc($t) . (is_file($file) ? filemtime($file) : '') . WL_VERSION . Config::basePath());
        $cacheFile = WL_DATA . '/cache/theme-' . $key . '.css';
        if (is_file($cacheFile)) {
            return $css = (string) file_get_contents($cacheFile);
        }
        $raw = self::cssVars($t) . (is_file($file) ? (string) file_get_contents($file) : '');
        $raw .= (string) ($t['custom_css'] ?? '');
        $css = self::minify($raw);
        foreach (glob(WL_DATA . '/cache/theme-*.css') ?: [] as $old) {
            @unlink($old);
        }
        @file_put_contents($cacheFile, $css);
        return $css;
    }

    public static function minify(string $css): string
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        $css = preg_replace('/\s*([{}:;,>~])\s*/', '$1', $css) ?? $css;
        return trim(str_replace(';}', '}', $css));
    }

    public static function styleHash(): string
    {
        return "'sha256-" . base64_encode(hash('sha256', self::inlineCss(), true)) . "'";
    }

    public static function resetCache(): void
    {
        foreach (glob(WL_DATA . '/cache/theme-*.css') ?: [] as $f) {
            @unlink($f);
        }
    }

    // ------------------------------------------------------------------
    // Gabarits
    // ------------------------------------------------------------------

    public static function template(string $name): string
    {
        $file = self::dir() . '/templates/' . $name . '.php';
        return is_file($file) ? $file : WL_THEMES . '/default/templates/' . $name . '.php';
    }

    public static function partial(string $name, array $vars = []): string
    {
        return View::render(self::template('partials/' . $name), $vars);
    }

    /**
     * Rend une page complète. $seo : paramètres de Seo::head + jsonld,
     * $vars : variables pour le gabarit.
     */
    public static function page(string $template, array $vars, array $seo): string
    {
        Blocks::$needsScript = false;
        $body = View::render(self::template($template), $vars);
        $head = self::head($seo);
        return View::render(self::template('layout'), $vars + ['body' => $body, 'head' => $head, 'template' => $template, 'seo' => $seo]);
    }

    public static function head(array $seo): string
    {
        $t = self::tokens();
        $out = '<meta charset="utf-8">' . "\n" . '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
        $out .= Seo::head($seo);
        $out .= '<meta name="theme-color" content="' . e($t['colors']['bg']) . '">' . "\n";
        $icon = Media::find(Settings::get('site.icon_id') ? (int) Settings::get('site.icon_id') : null);
        if ($icon) {
            $out .= '<link rel="icon" href="' . e(Media::url($icon, 480)) . '">' . "\n" . '<link rel="apple-touch-icon" href="' . e(Media::url($icon, 480)) . '">' . "\n";
        }
        foreach ((array) ($t['custom_fonts'] ?? []) as $f) {
            if (!empty($f['preload'])) {
                $out .= '<link rel="preload" href="' . e(media_url($f['preload'])) . '" as="font" type="font/woff2" crossorigin>' . "\n";
            }
        }
        $out .= '<style>' . self::inlineCss() . "</style>\n";
        if (!empty($seo['jsonld'])) {
            $out .= Seo::jsonld($seo['jsonld']);
        }
        if (Blocks::$needsScript) {
            $out .= '<script src="' . e(asset_url('themes/default/assets/wl.js')) . '" defer></script>' . "\n";
        }
        $out .= (string) Settings::get('code.head', '');
        return wl_filter('theme_head', $out, $seo);
    }

    // ------------------------------------------------------------------
    // Menus
    // ------------------------------------------------------------------

    public static function menu(string $location): array
    {
        $id = (int) (((array) Settings::get('menus.locations', []))[$location] ?? 0);
        $row = $id ? Db::one('SELECT items FROM menus WHERE id = ?', [$id]) : null;
        if (!$row) {
            return $location === 'primary' ? self::fallbackMenu() : [];
        }
        return json_dec($row['items'], []);
    }

    private static function fallbackMenu(): array
    {
        $items = [];
        foreach (Db::all("SELECT id, title FROM content WHERE type = 'page' AND status = 'published' AND parent_id IS NULL ORDER BY menu_order, title LIMIT 6") as $p) {
            $items[] = ['label' => $p['title'], 'type' => 'content', 'id' => (int) $p['id'], 'children' => []];
        }
        return $items;
    }

    /** Lien d'un élément de menu (null si le contenu visé n'est pas publié). */
    public static function itemHref(array $item): ?string
    {
        if (($item['type'] ?? '') === 'content' && !empty($item['id'])) {
            $c = Db::one('SELECT id, type, path, status, published_at FROM content WHERE id = ?', [(int) $item['id']]);
            return $c && Content::isVisible($c) ? Content::href($c) : null;
        }
        if (($item['type'] ?? '') === 'term' && !empty($item['id'])) {
            $t = Terms::find((int) $item['id']);
            return $t ? Terms::href($t) : '#';
        }
        return href((string) ($item['url'] ?? '#'));
    }

    public static function menuHtml(string $location, string $label): string
    {
        $items = self::menu($location);
        if (!$items) {
            return '';
        }
        $current = Config::basePath() . link_path(Http::path());
        $render = static function (array $items, int $depth) use (&$render, $current): string {
            $html = '<ul' . ($depth > 0 ? ' class="sub"' : '') . '>';
            foreach ($items as $it) {
                $href = self::itemHref($it);
                if ($href === null) {
                    continue;
                }
                $isCurrent = rtrim($href, '/') === rtrim($current, '/');
                $external = preg_match('#^https?://#', $href) && !str_starts_with($href, Config::baseUrl());
                $html .= '<li><a href="' . e($href) . '"' . ($isCurrent ? ' aria-current="page"' : '') . ($external ? ' rel="noopener"' : '') . '>' . e((string) $it['label']) . '</a>';
                if (!empty($it['children']) && $depth < 2) {
                    $html .= $render($it['children'], $depth + 1);
                }
                $html .= '</li>';
            }
            return $html . '</ul>';
        };
        return '<nav class="site-nav" aria-label="' . e($label) . '">' . $render($items, 0) . '</nav>';
    }
}
