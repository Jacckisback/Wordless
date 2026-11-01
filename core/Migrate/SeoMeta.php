<?php
declare(strict_types=1);

namespace Wordless\Migrate;

use Wordless\Settings;

/**
 * Métadonnées SEO des extensions WordPress -> champ « seo » de Wordless
 * (title, description, keyword, canonical, noindex, og_image).
 *
 * Extensions reconnues : Yoast SEO, Rank Math, SEOPress, All in One SEO
 * (ancienne et nouvelle version), The SEO Framework. Les variables de
 * modèle (%%title%%, %sitename%, #post_title…) sont remplacées par leur
 * valeur ; un titre identique au modèle par défaut « Titre – Site » est
 * laissé vide pour que le modèle de Wordless s'applique (et reste cohérent
 * si le nom du site change).
 */
final class SeoMeta
{
    /** Séparateurs courants des extensions SEO. */
    private const SEPARATORS = ['-', '–', '—', '|', '·', '•', '»', '«', '~', ':', '/', '*', '>', '<', '⋆', '‹', '›'];

    /**
     * @param array $meta postmeta de l'élément
     * @param array $vars title, sitename, sitedesc, excerpt, category, tag, author, date, modified, id
     * @param string|null $ownPath chemin WordPress du contenu (pour ignorer une canonique vers lui-même)
     */
    public static function fromMeta(array $meta, array $vars, Context $ctx, ?string $ownPath): array
    {
        $get = static function (array $keys) use ($meta): string {
            foreach ($keys as $k) {
                if (isset($meta[$k]) && is_scalar($meta[$k]) && trim((string) $meta[$k]) !== '') {
                    return trim((string) $meta[$k]);
                }
            }
            return '';
        };
        $out = [];
        $vars['focuskw'] = self::firstKeyword($get(['_yoast_wpseo_focuskw', 'rank_math_focus_keyword', '_seopress_analysis_target_kw']));

        $title = $get(['_yoast_wpseo_title', 'rank_math_title', '_seopress_titles_title', '_aioseo_title', '_aioseop_title', '_genesis_title']);
        if ($title !== '') {
            $resolved = self::resolve($title, $vars);
            $out['title'] = self::isDefaultTitle($resolved, $vars) ? '' : $resolved;
        }
        $desc = $get(['_yoast_wpseo_metadesc', 'rank_math_description', '_seopress_titles_desc', '_aioseo_description', '_aioseop_description', '_genesis_description']);
        if ($desc !== '') {
            $out['description'] = self::resolve($desc, $vars);
        }
        if ($vars['focuskw'] !== '') {
            $out['keyword'] = $vars['focuskw'];
        }
        $canonical = $get(['_yoast_wpseo_canonical', 'rank_math_canonical_url', '_seopress_robots_canonical', '_aioseo_canonical_url', '_aioseop_custom_link', '_genesis_canonical_uri']);
        if ($canonical !== '') {
            $out['canonical'] = self::canonical($canonical, $ctx, $ownPath);
        }

        // Indexation
        $noindex = null;
        if (isset($meta['_yoast_wpseo_meta-robots-noindex']) && (string) $meta['_yoast_wpseo_meta-robots-noindex'] !== '') {
            $v = (string) $meta['_yoast_wpseo_meta-robots-noindex'];
            $noindex = $v === '1' ? true : ($v === '2' ? false : null);
        }
        if ($noindex === null && isset($meta['rank_math_robots'])) {
            $robots = self::unserialize((string) $meta['rank_math_robots']);
            if (is_array($robots)) {
                $noindex = in_array('noindex', array_map('strval', $robots), true);
            }
        }
        if ($noindex === null && isset($meta['_seopress_robots_index']) && (string) $meta['_seopress_robots_index'] !== '') {
            $noindex = (string) $meta['_seopress_robots_index'] === 'yes';
        }
        if ($noindex === null && isset($meta['_aioseop_noindex']) && (string) $meta['_aioseop_noindex'] !== '') {
            $noindex = in_array((string) $meta['_aioseop_noindex'], ['on', '1'], true);
        }
        if ($noindex === null && isset($meta['_genesis_noindex']) && (string) $meta['_genesis_noindex'] !== '') {
            $noindex = (string) $meta['_genesis_noindex'] === '1';
        }
        if ($noindex !== null) {
            $out['noindex'] = $noindex;
        }

        // Image de partage (Open Graph)
        $ogId = (int) $get(['_yoast_wpseo_opengraph-image-id', 'rank_math_facebook_image_id', '_seopress_social_fb_img_attachment_id', '_social_image_id']);
        $og = $ogId > 0 ? $ctx->mediaId($ogId) : null;
        if ($og === null) {
            $ogUrl = $get(['_yoast_wpseo_opengraph-image', 'rank_math_facebook_image', '_seopress_social_fb_img', '_social_image_url']);
            if ($ogUrl !== '' && ($m = $ctx->mediaForUrl($ogUrl))) {
                $og = (int) $m['id'];
            }
        }
        if ($og !== null) {
            $out['og_image'] = $og;
        }
        return $out;
    }

    /** Données SEO exposées par Yoast dans l'API REST (yoast_head_json). */
    public static function fromYoastHead(array $y, array $vars, Context $ctx, ?string $ownPath): array
    {
        $out = [];
        $title = trim(html_entity_decode((string) ($y['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title !== '') {
            $out['title'] = self::isDefaultTitle($title, $vars) ? '' : $title;
        }
        $desc = trim(html_entity_decode((string) ($y['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($desc !== '') {
            $out['description'] = $desc;
        }
        if (!empty($y['canonical'])) {
            $out['canonical'] = self::canonical((string) $y['canonical'], $ctx, $ownPath);
        }
        $robots = (array) ($y['robots'] ?? []);
        if (isset($robots['index'])) {
            $out['noindex'] = $robots['index'] === 'noindex';
        }
        return $out;
    }

    /** Lecture du <head> HTML d'une page publiée (option « seo_fallback »). */
    public static function fromHtml(string $html, array $vars, Context $ctx, ?string $ownPath): array
    {
        $head = preg_match('#<head\b.*?</head>#is', $html, $m) ? $m[0] : substr($html, 0, 200000);
        $out = [];
        if (preg_match('#<title\b[^>]*>(.*?)</title>#is', $head, $t)) {
            $title = trim(html_entity_decode(strip_tags($t[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') {
                $out['title'] = self::isDefaultTitle($title, $vars) ? '' : $title;
            }
        }
        foreach (self::tags($head, 'meta') as $a) {
            $name = strtolower($a['name'] ?? ($a['property'] ?? ''));
            $content = trim(html_entity_decode((string) ($a['content'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($name === 'description' && $content !== '') {
                $out['description'] = $content;
            } elseif ($name === 'robots') {
                $out['noindex'] = str_contains(strtolower($content), 'noindex');
            }
        }
        foreach (self::tags($head, 'link') as $a) {
            if (strtolower($a['rel'] ?? '') === 'canonical' && !empty($a['href'])) {
                $out['canonical'] = self::canonical(html_entity_decode((string) $a['href'], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $ctx, $ownPath);
            }
        }
        return $out;
    }

    /** @return list<array<string, string>> attributs de chaque balise <$tag> */
    private static function tags(string $html, string $tag): array
    {
        $out = [];
        if (preg_match_all('#<' . $tag . '\b([^>]*)>#i', $html, $m)) {
            foreach ($m[1] as $attrs) {
                $a = [];
                if (preg_match_all('/([\w:-]+)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>]+))/', $attrs, $mm, PREG_SET_ORDER)) {
                    foreach ($mm as $x) {
                        $a[strtolower($x[1])] = $x[3] !== '' ? $x[3] : (($x[4] ?? '') !== '' ? $x[4] : ($x[5] ?? ''));
                    }
                }
                $out[] = $a;
            }
        }
        return $out;
    }

    /** Premier mot-clé d'une liste « mot-clé 1,mot-clé 2 » (Rank Math, SEOPress). */
    public static function firstKeyword(string $list): string
    {
        $first = trim(explode(',', $list)[0] ?? '');
        return mb_substr($first, 0, 120);
    }

    /** Canonique : vide si elle désigne le contenu lui-même, adresse du nouveau site si elle vise une autre page de l'ancien. */
    private static function canonical(string $url, Context $ctx, ?string $ownPath): string
    {
        $url = trim($url);
        $u = $ctx->oldUrl($url);
        if ($u === null) {
            return preg_match('#^https?://#i', $url) ? $url : '';
        }
        $rel = $ctx->relPath($u['path']);
        if ($rel === null) {
            return $ctx->oldAbsolute($u['path']) . $u['query'];
        }
        if ($ownPath !== null && Context::pathKey($rel) === $ownPath && $u['query'] === '') {
            return '';
        }
        return \url($rel) . $u['query'];
    }

    /** Remplace les variables de modèle par leur valeur (variables inconnues supprimées). */
    public static function resolve(string $tpl, array $vars): string
    {
        $v = $vars + ['title' => '', 'sitename' => '', 'sitedesc' => '', 'excerpt' => '', 'category' => '', 'tag' => '', 'author' => '', 'date' => '', 'modified' => '', 'id' => '', 'focuskw' => ''];
        $sep = (string) ($v['sep'] ?? Settings::get('seo.separator', '–'));
        $year = gmdate('Y');
        $map = [
            'title' => $v['title'], 'pagetitle' => $v['title'], 'post_title' => $v['title'], 'term_title' => $v['title'], 'archive_title' => $v['title'],
            'sitename' => $v['sitename'], 'sitetitle' => $v['sitename'], 'site_title' => $v['sitename'], 'blogname' => $v['sitename'],
            'sitedesc' => $v['sitedesc'], 'tagline' => $v['sitedesc'], 'site_description' => $v['sitedesc'],
            'sep' => $sep, 'separator' => $sep, 'separator_sa' => $sep,
            'excerpt' => $v['excerpt'], 'excerpt_only' => $v['excerpt'], 'post_excerpt' => $v['excerpt'], 'post_content' => $v['excerpt'], 'wc_shortdesc' => $v['excerpt'],
            'category' => $v['category'], 'primary_category' => $v['category'], 'categories' => $v['category'], 'post_category' => $v['category'], 'category_title' => $v['category'],
            'tag' => $v['tag'], 'tags' => $v['tag'], 'post_tag' => $v['tag'],
            'name' => $v['author'], 'author_name' => $v['author'], 'post_author' => $v['author'], 'author_first_name' => $v['author'],
            'date' => $v['date'], 'post_date' => $v['date'], 'modified' => $v['modified'], 'post_modified_date' => $v['modified'],
            'currentyear' => $year, 'current_year' => $year, 'currentdate' => gmdate('Y-m-d'), 'current_date' => gmdate('Y-m-d'),
            'currentmonth' => gmdate('m'), 'current_month' => gmdate('m'), 'currentday' => gmdate('d'), 'current_day' => gmdate('d'),
            'focuskw' => $v['focuskw'], 'keyword' => $v['focuskw'], 'focus_keyword' => $v['focuskw'], 'target_keyword' => $v['focuskw'],
            'id' => (string) $v['id'], 'post_id' => (string) $v['id'],
            'page' => '', 'pagenumber' => '', 'pagetotal' => '', 'page_number' => '', 'cpt_plural' => '', 'searchphrase' => '', 'search_keywords' => '',
        ];
        // Yoast, SEOPress : %%variable%% (les variables inconnues, ex. %%cf_…%%, sont retirées)
        $out = (string) preg_replace_callback('/%%([a-z0-9_]+)%%/i', static fn ($m) => $map[strtolower($m[1])] ?? '', $tpl);
        // Rank Math : %variable% (seulement les variables connues : « 100 % » reste intact)
        $out = (string) preg_replace_callback('/%([a-z][a-z0-9_]*)(?:\([^)]*\))?%/i', static fn ($m) => array_key_exists(strtolower($m[1]), $map) ? $map[strtolower($m[1])] : $m[0], $out);
        // All in One SEO : #variable
        $out = (string) preg_replace_callback('/#([a-z][a-z_]+)\b/i', static fn ($m) => array_key_exists(strtolower($m[1]), $map) ? $map[strtolower($m[1])] : $m[0], $out);
        return self::tidy($out, $sep);
    }

    /** Espaces et séparateurs orphelins (variables vides). */
    private static function tidy(string $s, string $sep): string
    {
        $s = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $seps = implode('', array_map(static fn ($x) => preg_quote($x, '/'), array_unique([...self::SEPARATORS, $sep])));
        $s = (string) preg_replace('/(\s[' . $seps . '])(\s[' . $seps . '])+(?=\s|$)/u', '$1', $s);
        $s = (string) preg_replace('/^([' . $seps . ']\s)+|(\s[' . $seps . '])+$/u', '', $s);
        return trim($s);
    }

    /** Le titre résolu correspond-il au modèle par défaut « Titre Séparateur Site » ? */
    public static function isDefaultTitle(string $resolved, array $vars): bool
    {
        $norm = static fn (string $s): string => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $s)));
        $r = $norm($resolved);
        $title = (string) ($vars['title'] ?? '');
        $site = (string) ($vars['sitename'] ?? '');
        if ($r === '' || $title === '') {
            return $r === '';
        }
        if ($site === '' && $r === $norm($title)) {
            return true;
        }
        foreach (array_unique([...self::SEPARATORS, (string) ($vars['sep'] ?? '–')]) as $sep) {
            if ($r === $norm($title . ' ' . $sep . ' ' . $site)) {
                return true;
            }
        }
        return false;
    }

    /** Désérialisation sûre d'une méta WordPress (aucun objet instancié). */
    public static function unserialize(string $value): mixed
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(a|s|i|b|d|N):/', $value)) {
            $json = json_decode($value, true);
            return $json ?? $value;
        }
        $data = @unserialize($value, ['allowed_classes' => false]);
        return $data === false && $value !== 'b:0;' ? $value : $data;
    }
}
