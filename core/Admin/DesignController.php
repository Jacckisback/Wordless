<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Activity;
use Wordless\Ai\ArtDirector;
use Wordless\Ai\Client;
use Wordless\Cache;
use Wordless\Color;
use Wordless\Content;
use Wordless\Front;
use Wordless\Http;
use Wordless\HttpClient;
use Wordless\Settings;
use Wordless\Theme;

/** Design : directions artistiques, jetons, aperçu en direct, directeur artistique. */
final class DesignController
{
    public function index(): string
    {
        Kernel::need('manage_design');
        $pages = array_map(static fn ($c) => ['path' => link_path((string) $c['path']), 'title' => $c['title'], 'id' => (int) $c['id']],
            Content::query(['type' => 'page', 'visible' => true, 'limit' => 30, 'order' => 'menu']));
        $actions = '<button class="btn btn-primary" type="button" id="design-save">' . e(__('Enregistrer le design')) . '</button>';
        return Kernel::page('design', [
            'tokens' => Theme::tokens(), 'presets' => Theme::presets(), 'fonts' => Theme::FONTS, 'findings' => ArtDirector::audit(),
            'ai' => Client::configured() && \Wordless\Auth::can('use_ai'), 'pages' => $pages, 'homeId' => (int) Settings::get('home.page_id'),
        ], __('Design & directeur artistique'), 'design', $actions, ['scripts' => ['design.js']]);
    }

    /** Nettoie des jetons reçus du navigateur. */
    public static function clean(array $in): array
    {
        $presets = Theme::presets();
        $preset = isset($presets[$in['preset'] ?? '']) ? $in['preset'] : 'atelier';
        $t = $presets[$preset];
        $t['preset'] = $preset;
        $current = Theme::tokens();
        $t['custom_fonts'] = (array) ($current['custom_fonts'] ?? []);
        foreach (['bg', 'surface', 'text', 'muted', 'accent', 'accent_text', 'line'] as $k) {
            $v = (string) ($in['colors'][$k] ?? '');
            if (Color::valid($v)) {
                $t['colors'][$k] = '#' . strtolower(ltrim($v, '#'));
            }
        }
        foreach (['heading', 'body'] as $k) {
            $f = (string) ($in['fonts'][$k] ?? '');
            if (isset(Theme::FONTS[$f]) || isset($t['custom_fonts'][$f])) {
                $t['fonts'][$k] = $f;
            }
        }
        $num = static fn (string $k, float $min, float $max, float $def) => isset($in[$k]) && is_numeric($in[$k]) ? max($min, min($max, (float) $in[$k])) : $def;
        $t['base'] = (int) $num('base', 15, 22, (float) $t['base']);
        $t['ratio'] = round($num('ratio', 1.1, 1.6, (float) $t['ratio']), 3);
        $t['measure'] = (int) $num('measure', 52, 80, (float) $t['measure']);
        $t['radius'] = (int) $num('radius', 0, 28, (float) $t['radius']);
        $t['heading_weight'] = (int) round($num('heading_weight', 400, 900, (float) $t['heading_weight']) / 100) * 100;
        $t['tracking'] = round($num('tracking', -0.04, 0.05, (float) $t['tracking']), 3);
        $t['width'] = (int) $num('width', 56, 90, 72);
        $t['density'] = in_array($in['density'] ?? '', ['compact', 'normal', 'airy'], true) ? $in['density'] : $t['density'];
        $t['scheme'] = ($in['scheme'] ?? $t['scheme']) === 'dark' ? 'dark' : 'light';
        $t['header'] = ($in['header'] ?? 'split') === 'center' ? 'center' : 'split';
        $t['buttons'] = 'solid';
        $css = (string) ($in['custom_css'] ?? $current['custom_css'] ?? '');
        $t['custom_css'] = \Wordless\Auth::can('*') ? mb_substr(str_ireplace(['</style', '<script'], '', $css), 0, 20000) : (string) ($current['custom_css'] ?? '');
        return $t;
    }

    public function save(): never
    {
        Kernel::need('manage_design');
        $t = self::clean(Http::json());
        Settings::setMany(['design' => $t, 'design.chosen' => true]);
        Theme::resetCache();
        Cache::purge();
        Activity::log('design', 'design', null, $t['preset']);
        json_response(['ok' => true, 'findings' => ArtDirector::audit($t)]);
    }

    /** Aperçu d'une page du site avec des jetons non enregistrés. */
    public function preview(): string
    {
        Kernel::need('manage_design');
        $raw = (string) ($_GET['t'] ?? '');
        $in = $raw !== '' ? json_decode((string) base64_decode(strtr($raw, '-_', '+/'), true), true) : null;
        if (is_array($in)) {
            Theme::$override = self::clean($in);
        }
        $path = (string) ($_GET['path'] ?? '/');
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            $path = '/';
        }
        Http::setPath($path);
        ob_start();
        Front::handle(link_path($path));
        return (string) ob_get_clean();
    }

    public function audit(): never
    {
        Kernel::need('manage_design');
        $t = self::clean(Http::json());
        json_response(['findings' => ArtDirector::audit($t)]);
    }

    /** Commandes : plus affirmé / plus sobre / épurer (sans IA), ou direction complète (IA). */
    public function direction(): never
    {
        Kernel::need('manage_design');
        $in = Http::json();
        $cmd = (string) ($in['command'] ?? '');
        $t = self::clean((array) ($in['tokens'] ?? []));
        if (in_array($cmd, ['bolder', 'quieter', 'distill', 'repair'], true)) {
            $t = $cmd === 'repair' ? ArtDirector::repair($t) : ArtDirector::command($cmd, $t);
            json_response(['tokens' => $t, 'findings' => ArtDirector::audit($t)]);
        }
        Kernel::need('use_ai');
        try {
            $r = ArtDirector::direction(trim((string) ($in['brief'] ?? '')));
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        json_response($r);
    }

    public function critique(): never
    {
        (new AiController())->run();
    }

    /**
     * Installe une police Google Fonts en local (RGPD : aucun appel à Google à l'affichage).
     * Formats woff2, sous-ensembles latin et latin étendu, graisses 400/600/700.
     */
    public function installFont(): never
    {
        Kernel::need('manage_design');
        $in = Http::json();
        $family = trim(preg_replace('/[^\p{L}\p{N} ]/u', '', (string) ($in['family'] ?? '')) ?? '');
        $fallback = isset(Theme::FONTS[$in['fallback'] ?? '']) ? $in['fallback'] : 'humanist';
        if ($family === '' || mb_strlen($family) > 60) {
            json_response(['error' => __('Nom de police invalide.')], 422);
        }
        $url = 'https://fonts.googleapis.com/css2?family=' . rawurlencode($family) . ':wght@400;600;700&display=swap';
        try {
            $res = HttpClient::get($url, ['headers' => ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'], 'timeout' => 20]);
            if ($res['status'] !== 200) {
                throw new \RuntimeException(__('Police introuvable sur Google Fonts : vérifiez son nom exact.'));
            }
            preg_match_all('#/\*\s*([a-z-]+)\s*\*/\s*@font-face\s*\{([^}]+)\}#', $res['body'], $m, PREG_SET_ORDER);
            $slug = slugify($family);
            $faces = [];
            foreach ($m as [, $subset, $decl]) {
                if (!in_array($subset, ['latin', 'latin-ext'], true)) {
                    continue;
                }
                preg_match('/font-weight:\s*(\d+)/', $decl, $w);
                preg_match('/font-style:\s*(\w+)/', $decl, $st);
                preg_match('/url\((https:[^)]+\.woff2)\)/', $decl, $src);
                preg_match('/unicode-range:\s*([^;]+);/', $decl, $range);
                if (empty($src[1])) {
                    continue;
                }
                $rel = 'fonts/' . $slug . '/' . ($w[1] ?? '400') . '-' . $subset . '.woff2';
                $dest = WL_MEDIA . '/' . $rel;
                if (!is_dir(dirname($dest))) {
                    mkdir(dirname($dest), 0775, true);
                }
                $dl = HttpClient::download($src[1], $dest, 2 * 1024 * 1024);
                if ($dl['status'] !== 200) {
                    continue;
                }
                $faces[] = ['file' => $rel, 'weight' => (int) ($w[1] ?? 400), 'style' => $st[1] ?? 'normal', 'range' => trim($range[1] ?? '')];
            }
            if (!$faces) {
                throw new \RuntimeException(__('Aucun fichier de police téléchargé.'));
            }
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        $key = 'g:' . $slug;
        $design = (array) Settings::get('design', []);
        $design['custom_fonts'][$key] = ['family' => $family, 'fallback' => $fallback, 'faces' => $faces,
            'preload' => array_values(array_filter($faces, static fn ($f) => $f['weight'] === 400 && str_ends_with($f['file'], '-latin.woff2')))[0]['file'] ?? null];
        Settings::set('design', $design);
        Theme::resetCache();
        json_response(['ok' => true, 'key' => $key, 'family' => $family, 'label' => $family . ' (' . __('hébergée localement') . ')']);
    }
}
