<?php
declare(strict_types=1);

namespace Wordless;

/**
 * SEO et GEO natifs : analyse de chaque contenu (score SEO + score GEO),
 * balises <head>, JSON-LD (@graph), sitemaps, robots.txt et llms.txt.
 */
final class Seo
{
    // ------------------------------------------------------------------
    // Titre et description
    // ------------------------------------------------------------------

    public static function title(array $c): string
    {
        $custom = trim((string) ($c['seo']['title'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        if (Content::isHome($c) && trim((string) Settings::get('seo.home_title', '')) !== '') {
            return (string) Settings::get('seo.home_title');
        }
        return self::applyTemplate($c['title']);
    }

    public static function applyTemplate(string $title): string
    {
        $site = (string) Settings::get('site.name', '');
        if ($title === '' || $title === $site) {
            return $site;
        }
        $t = strtr((string) Settings::get('seo.title_template', '{title} {sep} {site}'), [
            '{title}' => $title, '{sep}' => (string) Settings::get('seo.separator', '–'), '{site}' => $site,
        ]);
        return trim(preg_replace('/\s+/', ' ', $t) ?? $t);
    }

    public static function description(array $c): string
    {
        $d = trim((string) ($c['seo']['description'] ?? ''));
        if ($d !== '') {
            return $d;
        }
        if (Content::isHome($c) && trim((string) Settings::get('seo.home_description', '')) !== '') {
            return (string) Settings::get('seo.home_description');
        }
        return Content::summary($c, 158);
    }

    // ------------------------------------------------------------------
    // Analyse d'un contenu
    // ------------------------------------------------------------------

    /** @return array{seo: array{score: int, checks: list<array>}, geo: array{score: int, checks: list<array>}, stats: array} */
    public static function analyze(array $c): array
    {
        $blocks = $c['blocks'];
        $text = Blocks::text($blocks);
        $words = word_count($text);
        $title = self::title($c);
        $desc = trim((string) ($c['seo']['description'] ?? ''));
        $kw = mb_strtolower(trim((string) ($c['seo']['keyword'] ?? '')));
        $headings = Blocks::headings($blocks);
        $intro = Blocks::intro($blocks);
        $links = Blocks::links($blocks);
        $images = Blocks::images($blocks);
        $isPost = $c['type'] === 'post';
        $hasHeroTitle = ($blocks[0]['type'] ?? '') === 'hero';
        $base = (string) parse_url(Config::baseUrl(), PHP_URL_HOST);
        $internal = 0;
        $external = 0;
        foreach ($links as $l) {
            $h = (string) parse_url($l['href'], PHP_URL_HOST);
            if ($h === '' || $h === $base) {
                $internal++;
            } elseif (preg_match('#^https?://#', $l['href'])) {
                $external++;
            }
        }
        $types = array_column($blocks, 'type');

        $seo = [];
        $add = static function (array &$list, string $id, string $status, string $label, string $hint = '', int $weight = 1): void {
            $list[] = ['id' => $id, 'status' => $status, 'label' => $label, 'hint' => $hint, 'weight' => $weight];
        };
        $tl = mb_strlen($title);
        $add($seo, 'title_length', $tl >= 30 && $tl <= 62 ? 'good' : ($tl === 0 ? 'bad' : 'warn'),
            __('Titre SEO : {n} caractères', ['n' => $tl]), $tl > 62 ? __('Google coupe au-delà de ~60 caractères. Raccourcissez.') : ($tl < 30 ? __('Un titre de 30 à 60 caractères est plus explicite.') : ''), 3);
        $dl = mb_strlen($desc);
        $add($seo, 'meta_description', $dl === 0 ? 'warn' : ($dl >= 110 && $dl <= 165 ? 'good' : 'warn'),
            $dl === 0 ? __('Pas de méta-description (un résumé automatique est utilisé)') : __('Méta-description : {n} caractères', ['n' => $dl]),
            $dl === 0 ? __('Écrivez 120 à 160 caractères qui donnent envie de cliquer.') : ($dl > 165 ? __('Trop longue : visez 120 à 160 caractères.') : ($dl < 110 ? __('Un peu courte : visez 120 à 160 caractères.') : '')), 2);
        $minWords = $isPost ? 600 : 150;
        $add($seo, 'length', $words >= $minWords ? 'good' : ($words >= $minWords / 2 ? 'warn' : 'bad'),
            _n('{n} mot', '{n} mots', $words), $words < $minWords ? __('Visez au moins {n} mots pour couvrir le sujet.', ['n' => $minWords]) : '', 2);
        $h2 = count(array_filter($headings, static fn ($h) => $h['level'] === 2));
        $add($seo, 'headings', $h2 > 0 || $words < 300 ? 'good' : 'warn', __('Titres de section (H2) : {n}', ['n' => $h2]),
            $h2 === 0 && $words >= 300 ? __('Découpez le texte avec des titres H2 explicites.') : '', 2);
        $skipped = false;
        $prev = 1;
        foreach ($headings as $h) {
            if ($h['level'] > $prev + 1) {
                $skipped = true;
            }
            $prev = $h['level'];
        }
        $add($seo, 'heading_order', $skipped ? 'warn' : 'good', $skipped ? __('Niveaux de titres sautés (ex. H2 puis H4)') : __('Hiérarchie des titres correcte'),
            $skipped ? __('Respectez l’ordre H2 > H3 > H4 pour les lecteurs d’écran et Google.') : '', 1);
        $noAlt = count(array_filter($images, static fn ($i) => empty($i['decorative']) && trim((string) $i['alt']) === ''));
        $add($seo, 'alt', $noAlt === 0 ? 'good' : 'bad', $noAlt === 0 ? __('Toutes les images ont un texte alternatif') : _n('{n} image sans texte alternatif', '{n} images sans texte alternatif', $noAlt),
            $noAlt > 0 ? __('Décrivez chaque image (l’IA peut le faire pour vous).') : '', 2);
        if ($isPost || $words > 300) {
            $add($seo, 'internal_links', $internal > 0 ? 'good' : 'warn', _n('{n} lien interne', '{n} liens internes', $internal),
                $internal === 0 ? __('Ajoutez au moins un lien vers une autre page de votre site.') : '', 1);
        }
        if ($isPost) {
            $add($seo, 'featured', !empty($c['featured_id']) ? 'good' : 'warn', !empty($c['featured_id']) ? __('Image à la une définie') : __('Pas d’image à la une'),
                empty($c['featured_id']) ? __('Elle sert d’aperçu sur les réseaux sociaux et dans Discover.') : '', 1);
        }
        $slugWords = count(explode('-', (string) $c['slug']));
        $add($seo, 'slug', $slugWords <= 6 ? 'good' : 'warn', __('Adresse : /{s}', ['s' => $c['slug']]), $slugWords > 6 ? __('Une adresse courte (3 à 5 mots) se partage et se retient mieux.') : '', 1);
        if ($kw !== '') {
            $kwIn = static fn (string $hay) => str_contains(self::fold($hay), self::fold($kw));
            $add($seo, 'kw_title', $kwIn($title) ? 'good' : 'bad', __('Mot-clé dans le titre SEO'), $kwIn($title) ? '' : __('Placez « {k} » dans le titre, de préférence au début.', ['k' => $kw]), 2);
            $add($seo, 'kw_desc', $kwIn($desc) ? 'good' : 'warn', __('Mot-clé dans la méta-description'), '', 1);
            $add($seo, 'kw_intro', $kwIn($intro) ? 'good' : 'warn', __('Mot-clé dans l’introduction'), $kwIn($intro) ? '' : __('Citez-le dans les premières phrases.'), 1);
            $inH2 = (bool) array_filter($headings, static fn ($h) => $kwIn($h['text']));
            $add($seo, 'kw_heading', $inH2 ? 'good' : 'warn', __('Mot-clé dans un titre de section'), '', 1);
            $kwSlug = slugify($kw);
            $add($seo, 'kw_slug', $kwSlug !== '' && str_contains((string) $c['slug'], $kwSlug) ? 'good' : 'warn', __('Mot-clé dans l’adresse'), '', 1);
            $count = $words > 0 ? substr_count(self::fold($text), self::fold($kw)) : 0;
            $density = $words > 0 ? round($count * max(1, count(explode(' ', $kw))) / $words * 100, 1) : 0;
            $add($seo, 'kw_density', $density >= 0.4 && $density <= 3 ? 'good' : 'warn', __('Densité du mot-clé : {d} %', ['d' => I18n::number($density, 1)]),
                $density > 3 ? __('Trop répété : écrivez naturellement, utilisez des synonymes.') : ($density < 0.4 ? __('Mentionnez-le un peu plus (naturellement).') : ''), 1);
        } else {
            $add($seo, 'keyword', 'warn', __('Aucun mot-clé principal défini'), __('Indiquez l’expression que vos lecteurs tapent dans Google.'), 1);
        }
        $read = self::readability($text);
        if ($read !== null && $words >= 100) {
            $add($seo, 'readability', $read >= 50 ? 'good' : ($read >= 30 ? 'warn' : 'bad'), __('Lisibilité : {s}/100', ['s' => (int) $read]),
                $read < 50 ? __('Phrases plus courtes et mots plus simples aideront vos lecteurs.') : '', 1);
        }
        if (!empty($c['seo']['noindex'])) {
            $add($seo, 'noindex', 'warn', __('Page exclue de Google (noindex)'), __('Retirez l’option si cette page doit être trouvée.'), 0);
        }
        if ($c['title'] !== '') {
            $dupes = (int) Db::val("SELECT COUNT(*) FROM content WHERE title = ? AND id <> ? AND status = 'published'", [$c['title'], (int) ($c['id'] ?? 0)]);
            if ($dupes > 0) {
                $add($seo, 'duplicate_title', 'warn', __('Titre identique à une autre page'), __('Chaque page doit avoir un titre unique.'), 1);
            }
        }

        // GEO : être cité par ChatGPT, Claude, Perplexity, Gemini, AI Overviews
        $geo = [];
        $introWords = word_count($intro);
        $add($geo, 'answer_first', $introWords >= 15 && $introWords <= 90 ? 'good' : 'warn',
            $introWords === 0 ? __('Pas de paragraphe d’introduction') : __('Introduction de {n} mots', ['n' => $introWords]),
            __('Commencez par une réponse directe à la question principale, en 2 ou 3 phrases (40 à 60 mots).'), 3);
        $questions = count(array_filter($headings, static fn ($h) => self::isQuestion($h['text'])));
        $add($geo, 'question_headings', $questions > 0 ? 'good' : 'warn', _n('{n} titre formulé en question', '{n} titres formulés en question', $questions),
            $questions === 0 ? __('Formulez certains H2/H3 comme les questions que posent vos clients.') : '', 2);
        $faqCount = count(Blocks::faqs($blocks));
        $add($geo, 'faq', $faqCount >= 3 ? 'good' : 'warn', $faqCount > 0 ? _n('FAQ : {n} question', 'FAQ : {n} questions', $faqCount) : __('Pas de FAQ'),
            $faqCount < 3 ? __('Ajoutez un bloc FAQ (3 questions ou plus) : il est balisé en données structurées.') : '', 2);
        $structured = in_array('takeaways', $types, true) || in_array('steps', $types, true) || in_array('features', $types, true)
            || (bool) preg_match('#<(ul|ol|table)\b#', implode('', array_map(static fn ($b) => (string) ($b['data']['html'] ?? ''), $blocks)));
        $add($geo, 'structure', $structured ? 'good' : 'warn', $structured ? __('Contenu structuré (listes, tableaux, étapes)') : __('Peu de structure exploitable'),
            $structured ? '' : __('Listes, tableaux et étapes sont repris tels quels par les moteurs IA.'), 2);
        $hasStats = (bool) preg_match('/\d+(?:[.,]\d+)?\s?(%|€|\$|£|k€|M€|millions?|milliards?|ans|heures|jours|minutes|fois|x\b)/iu', $text) || in_array('stats', $types, true);
        $add($geo, 'statistics', $hasStats ? 'good' : 'warn', $hasStats ? __('Chiffres et données présents') : __('Aucun chiffre précis'),
            $hasStats ? '' : __('Ajoutez des données chiffrées et datées : elles augmentent les chances d’être cité.'), 2);
        $add($geo, 'sources', $external > 0 ? 'good' : 'warn', _n('{n} source externe citée', '{n} sources externes citées', $external),
            $external === 0 ? __('Citez vos sources (études, organismes officiels) avec un lien.') : '', 2);
        $hasQuote = in_array('quote', $types, true) || in_array('testimonials', $types, true) || str_contains(implode('', array_map(static fn ($b) => (string) ($b['data']['html'] ?? ''), $blocks)), '<blockquote');
        $add($geo, 'quotes', $hasQuote ? 'good' : 'warn', $hasQuote ? __('Citation d’expert ou de client') : __('Aucune citation'),
            $hasQuote ? '' : __('Une citation attribuée (expert, client) renforce la crédibilité.'), 1);
        $author = !empty($c['author_id']) ? Db::one('SELECT bio FROM users WHERE id = ?', [(int) $c['author_id']]) : null;
        if ($isPost) {
            $hasBio = $author && trim((string) $author['bio']) !== '';
            $add($geo, 'author', $hasBio ? 'good' : 'warn', $hasBio ? __('Auteur identifié avec biographie') : __('Biographie de l’auteur manquante'),
                $hasBio ? '' : __('Complétez votre profil : l’expertise de l’auteur (E-E-A-T) compte pour Google et les IA.'), 2);
        }
        $age = time() - (int) ($c['updated_at'] ?? time());
        $add($geo, 'freshness', $age < 365 * 86400 ? 'good' : 'warn', __('Mis à jour {t}', ['t' => human_time((int) ($c['updated_at'] ?? time()))]),
            $age >= 365 * 86400 ? __('Actualisez ce contenu : les IA privilégient les informations récentes.') : '', 1);
        $blockedAi = array_keys(array_filter((array) Settings::get('geo.ai_crawlers', []), static fn ($v) => !$v));
        $important = array_intersect($blockedAi, ['GPTBot', 'OAI-SearchBot', 'ClaudeBot', 'Claude-SearchBot', 'PerplexityBot', 'Google-Extended']);
        $add($geo, 'crawlers', $important ? 'warn' : 'good', $important ? __('Certains robots IA sont bloqués : {l}', ['l' => implode(', ', $important)]) : __('Lisible par les robots des moteurs IA'),
            $important ? __('Débloquez-les dans SEO & GEO si vous voulez être cité.') : '', 1);

        return [
            'seo' => ['score' => self::score($seo), 'checks' => $seo],
            'geo' => ['score' => self::score($geo), 'checks' => $geo],
            'stats' => ['words' => $words, 'reading_minutes' => max(1, (int) round($words / 220)), 'internal' => $internal, 'external' => $external, 'readability' => $read],
        ];
    }

    private static function score(array $checks): int
    {
        $total = 0;
        $got = 0.0;
        foreach ($checks as $ch) {
            $w = (int) $ch['weight'];
            $total += $w;
            $got += $ch['status'] === 'good' ? $w : ($ch['status'] === 'warn' ? $w * 0.4 : 0);
        }
        return $total > 0 ? (int) round($got / $total * 100) : 0;
    }

    public static function fold(string $s): string
    {
        $s = mb_strtolower($s);
        return strtr($s, ['à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ú' => 'u', 'ñ' => 'n', '’' => "'"]);
    }

    public static function isQuestion(string $t): bool
    {
        $t = trim($t);
        if (str_ends_with($t, '?')) {
            return true;
        }
        return (bool) preg_match('/^(comment|pourquoi|quand|quel|quelle|quels|quelles|qu[’\']est|combien|où|qui|faut-il|peut-on|est-ce|how|why|what|when|which|who|where|can|should|is|are|does|do|cómo|por qué|cuándo|qué|cuál|cuánto|dónde|quién)\b/iu', $t);
    }

    /** Lisibilité 0-100 (Kandel-Moles en français, Flesch en anglais, Fernández-Huerta en espagnol). */
    public static function readability(string $text): ?float
    {
        $sentences = preg_split('/[.!?…]+(\s|$)/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sentences = array_filter($sentences, static fn ($s) => word_count($s) > 0);
        $words = preg_split('/[\s\x{00A0}]+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $nw = count($words);
        $ns = max(1, count($sentences));
        if ($nw < 30) {
            return null;
        }
        $syl = 0;
        foreach ($words as $w) {
            $w = mb_strtolower(preg_replace('/[^\p{L}]/u', '', $w) ?? '');
            $n = preg_match_all('/[aeiouyàâäéèêëîïôöùûüáíóú]+/u', $w);
            if (I18n::lang() !== 'es' && preg_match('/[^aeiouy]e$/u', $w) && $n > 1) {
                $n--;
            }
            $syl += max(1, (int) $n);
        }
        $asl = $nw / $ns;
        $asw = $syl / $nw;
        $score = match (I18n::lang()) {
            'en' => 206.835 - 1.015 * $asl - 84.6 * $asw,
            'es' => 206.84 - 0.60 * ($asw * 100) - 1.02 * ($ns / $nw * 100),
            default => 207 - 1.015 * $asl - 73.6 * $asw,
        };
        return max(0.0, min(100.0, round($score, 1)));
    }

    // ------------------------------------------------------------------
    // Balises <head>
    // ------------------------------------------------------------------

    /**
     * $p : title, description, canonical, noindex, type (website|article),
     * image (media), content (array), published, modified, prev, next, alternates.
     */
    public static function head(array $p): string
    {
        $site = (string) Settings::get('site.name', '');
        $title = $p['title'] ?? $site;
        $desc = trim((string) ($p['description'] ?? ''));
        $canonical = (string) ($p['canonical'] ?? '');
        $noindex = !empty($p['noindex']) || Settings::get('seo.noindex_site', false);
        $out = '<title>' . e($title) . "</title>\n";
        if ($desc !== '') {
            $out .= '<meta name="description" content="' . e($desc) . "\">\n";
        }
        $out .= '<meta name="robots" content="' . ($noindex ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1') . "\">\n";
        if ($canonical !== '' && !$noindex) {
            $out .= '<link rel="canonical" href="' . e($canonical) . "\">\n";
        }
        if (!empty($p['prev'])) {
            $out .= '<link rel="prev" href="' . e($p['prev']) . "\">\n";
        }
        if (!empty($p['next'])) {
            $out .= '<link rel="next" href="' . e($p['next']) . "\">\n";
        }
        foreach ((array) ($p['alternates'] ?? []) as $lang => $href) {
            $out .= '<link rel="alternate" hreflang="' . e($lang) . '" href="' . e($href) . "\">\n";
        }
        if (!empty($p['markdown'])) {
            $out .= '<link rel="alternate" type="text/markdown" href="' . e($p['markdown']) . '" title="' . e(__('Version Markdown (pour les IA)')) . "\">\n";
        }
        $out .= '<link rel="alternate" type="application/rss+xml" title="' . e($site) . '" href="' . e(url('/feed/')) . "\">\n";

        // Open Graph & cartes sociales
        $img = $p['image'] ?? null;
        if (!$img && Settings::get('seo.og_image_id')) {
            $img = Media::find((int) Settings::get('seo.og_image_id'));
        }
        $og = [
            'og:type' => $p['type'] ?? 'website',
            'og:site_name' => $site,
            'og:title' => $p['social_title'] ?? $title,
            'og:description' => $desc,
            'og:url' => $canonical,
            'og:locale' => I18n::locale(),
        ];
        if ($img) {
            $og['og:image'] = Media::absoluteUrl($img, 1440);
            $og['og:image:width'] = (string) min(1440, (int) $img['width']);
            $og['og:image:height'] = (string) (int) round(min(1440, (int) $img['width']) * (int) $img['height'] / max(1, (int) $img['width']));
            $og['og:image:alt'] = (string) $img['alt'];
        }
        if (($p['type'] ?? '') === 'article') {
            $og['article:published_time'] = !empty($p['published']) ? gmdate('c', (int) $p['published']) : '';
            $og['article:modified_time'] = !empty($p['modified']) ? gmdate('c', (int) $p['modified']) : '';
            if (!empty($p['section'])) {
                $og['article:section'] = $p['section'];
            }
        }
        foreach ($og as $k => $v) {
            if ($v !== '' && $v !== null) {
                $out .= '<meta property="' . e($k) . '" content="' . e($v) . "\">\n";
            }
        }
        foreach ((array) ($p['tags'] ?? []) as $tag) {
            $out .= '<meta property="article:tag" content="' . e($tag) . "\">\n";
        }
        $out .= '<meta name="twitter:card" content="' . ($img ? 'summary_large_image' : 'summary') . "\">\n";
        foreach ((array) Settings::get('seo.verification', []) as $name => $value) {
            if ($value !== '' && in_array($name, ['google-site-verification', 'msvalidate.01', 'yandex-verification', 'p:domain_verify', 'facebook-domain-verification'], true)) {
                $out .= '<meta name="' . e($name) . '" content="' . e($value) . "\">\n";
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Données structurées JSON-LD
    // ------------------------------------------------------------------

    public static function orgNode(): array
    {
        $base = Config::baseUrl();
        $type = Settings::get('site.org_type', 'Organization') === 'Person' ? 'Person' : 'Organization';
        $node = ['@type' => $type, '@id' => $base . '/#org', 'name' => (string) (Settings::get('site.org_name') ?: Settings::get('site.name')), 'url' => $base . '/'];
        $logo = Media::find(Settings::get('site.logo_id') ? (int) Settings::get('site.logo_id') : null);
        if ($logo) {
            $node[$type === 'Person' ? 'image' : 'logo'] = ['@type' => 'ImageObject', 'url' => Media::absoluteUrl($logo), 'width' => (int) $logo['width'], 'height' => (int) $logo['height']];
        }
        $same = array_values(array_filter((array) Settings::get('site.social', []), static fn ($u) => is_string($u) && preg_match('#^https://#', $u)));
        if ($same) {
            $node['sameAs'] = $same;
        }
        return $node;
    }

    public static function websiteNode(): array
    {
        $base = Config::baseUrl();
        return [
            '@type' => 'WebSite', '@id' => $base . '/#website', 'url' => $base . '/', 'name' => (string) Settings::get('site.name'),
            'description' => (string) Settings::get('site.tagline', ''), 'publisher' => ['@id' => $base . '/#org'],
            'inLanguage' => str_replace('_', '-', I18n::locale()),
            'potentialAction' => ['@type' => 'SearchAction', 'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $base . '/search?q={search_term_string}'], 'query-input' => 'required name=search_term_string'],
        ];
    }

    /**
     * Graphe complet pour une page. $p : url, title, description, content, crumbs [[name, url]],
     * page_type (WebPage, CollectionPage, SearchResultsPage, ProfilePage…), image (media).
     */
    public static function jsonld(array $p): string
    {
        $base = Config::baseUrl();
        $url = (string) $p['url'];
        $lang = str_replace('_', '-', I18n::locale());
        $graph = [self::orgNode(), self::websiteNode()];
        $c = $p['content'] ?? null;
        $pageType = $p['page_type'] ?? 'WebPage';
        if ($c && in_array($c['seo']['schema'] ?? '', ['AboutPage', 'ContactPage'], true)) {
            $pageType = $c['seo']['schema'];
        }
        $page = ['@type' => $pageType, '@id' => $url . '#webpage', 'url' => $url, 'name' => (string) $p['title'], 'isPartOf' => ['@id' => $base . '/#website'], 'inLanguage' => $lang];
        if (!empty($p['description'])) {
            $page['description'] = (string) $p['description'];
        }
        $img = $p['image'] ?? null;
        if ($img) {
            $graph[] = ['@type' => 'ImageObject', '@id' => $url . '#primaryimage', 'url' => Media::absoluteUrl($img), 'contentUrl' => Media::absoluteUrl($img), 'width' => (int) $img['width'], 'height' => (int) $img['height'], 'caption' => (string) $img['alt']];
            $page['primaryImageOfPage'] = ['@id' => $url . '#primaryimage'];
        }
        if ($c) {
            $page['datePublished'] = gmdate('c', (int) ($c['published_at'] ?: $c['created_at']));
            $page['dateModified'] = gmdate('c', (int) $c['updated_at']);
        }
        $crumbs = (array) ($p['crumbs'] ?? []);
        if (count($crumbs) > 1) {
            $items = [];
            foreach (array_values($crumbs) as $i => [$name, $u]) {
                $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $u];
            }
            $graph[] = ['@type' => 'BreadcrumbList', '@id' => $url . '#breadcrumb', 'itemListElement' => $items];
            $page['breadcrumb'] = ['@id' => $url . '#breadcrumb'];
        }
        $graph[] = $page;

        if ($c) {
            $blocks = $c['blocks'];
            $author = !empty($c['author_id']) ? Db::one('SELECT id, name, slug, bio, links FROM users WHERE id = ?', [(int) $c['author_id']]) : null;
            $personNode = null;
            if ($author) {
                $personNode = ['@type' => 'Person', '@id' => $base . '/#/person/' . $author['slug'], 'name' => $author['name'], 'url' => url('/author/' . $author['slug'])];
                if (trim((string) $author['bio']) !== '') {
                    $personNode['description'] = excerpt((string) $author['bio'], 300);
                }
                $links = array_values(array_filter(json_dec($author['links'], []), static fn ($u) => is_string($u) && preg_match('#^https://#', $u)));
                if ($links) {
                    $personNode['sameAs'] = $links;
                }
            }
            $schema = (string) ($c['seo']['schema'] ?? '');
            if ($c['type'] === 'post' || in_array($schema, ['Article', 'BlogPosting', 'NewsArticle'], true)) {
                $art = [
                    '@type' => $schema !== '' && in_array($schema, ['Article', 'BlogPosting', 'NewsArticle'], true) ? $schema : 'BlogPosting',
                    '@id' => $url . '#article', 'headline' => mb_substr((string) $c['title'], 0, 110), 'description' => (string) ($p['description'] ?? ''),
                    'datePublished' => $page['datePublished'], 'dateModified' => $page['dateModified'], 'inLanguage' => $lang,
                    'mainEntityOfPage' => ['@id' => $url . '#webpage'], 'isPartOf' => ['@id' => $url . '#webpage'],
                    'publisher' => ['@id' => $base . '/#org'], 'wordCount' => (int) $c['word_count'],
                ];
                if ($img) {
                    $art['image'] = ['@id' => $url . '#primaryimage'];
                }
                if ($personNode) {
                    $art['author'] = ['@id' => $personNode['@id']];
                    $graph[] = $personNode;
                }
                $cats = array_column(Terms::forContent((int) $c['id'], 'category'), 'name');
                $tags = array_column(Terms::forContent((int) $c['id'], 'tag'), 'name');
                if ($cats) {
                    $art['articleSection'] = $cats;
                }
                if ($tags) {
                    $art['keywords'] = implode(', ', $tags);
                }
                $graph[] = $art;
            } elseif (in_array($schema, ['Service', 'Product', 'LocalBusiness', 'Event', 'Course'], true)) {
                $graph[] = array_filter(['@type' => $schema, '@id' => $url . '#main', 'name' => (string) $c['title'], 'description' => (string) ($p['description'] ?? ''),
                    'url' => $url, 'provider' => $schema === 'Service' || $schema === 'Course' ? ['@id' => $base . '/#org'] : null,
                    'image' => $img ? Media::absoluteUrl($img) : null]);
            }
            $faqs = Blocks::faqs($blocks);
            if ($faqs) {
                $graph[] = ['@type' => 'FAQPage', '@id' => $url . '#faq', 'isPartOf' => ['@id' => $url . '#webpage'], 'mainEntity' => array_map(static fn ($f) => [
                    '@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => Html::clean($f['a'], 'inline')],
                ], $faqs)];
            }
            $steps = Blocks::steps($blocks);
            if (!empty($steps['items'])) {
                $graph[] = ['@type' => 'HowTo', '@id' => $url . '#howto', 'name' => $steps['title'] !== '' ? $steps['title'] : (string) $c['title'],
                    'step' => array_map(static fn ($s, $i) => ['@type' => 'HowToStep', 'position' => $i + 1, 'name' => (string) $s['title'], 'text' => plain_text((string) $s['text'])], $steps['items'], array_keys($steps['items']))];
            }
            foreach (Blocks::videos($blocks) as $i => $v) {
                $poster = Media::find($v['poster'] ?? null);
                $yt = Blocks::youtubeId((string) $v['url']);
                $graph[] = array_filter([
                    '@type' => 'VideoObject', '@id' => $url . '#video-' . ($i + 1), 'name' => $v['title'] !== '' ? $v['title'] : (string) $c['title'],
                    'description' => $v['caption'] !== '' ? $v['caption'] : (string) ($p['description'] ?? $c['title']),
                    'thumbnailUrl' => $poster ? Media::absoluteUrl($poster) : ($yt ? 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg' : null),
                    'uploadDate' => $page['datePublished'], 'embedUrl' => $yt ? 'https://www.youtube-nocookie.com/embed/' . $yt : null,
                    'contentUrl' => preg_match('#\.(mp4|webm)$#i', (string) $v['url']) ? url((string) $v['url']) : null,
                ]);
            }
        }
        if (!empty($p['extra'])) {
            array_push($graph, ...$p['extra']);
        }
        $graph = wl_filter('jsonld', $graph, $p);
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => array_values($graph)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        return '<script type="application/ld+json">' . $json . "</script>\n";
    }

    // ------------------------------------------------------------------
    // Sitemaps, robots.txt, llms.txt
    // ------------------------------------------------------------------

    public static function sitemapIndex(): string
    {
        $parts = [];
        foreach (['post', 'page'] as $type) {
            $last = Db::val("SELECT MAX(updated_at) FROM content WHERE type = ? AND status = 'published' AND published_at <= ?", [$type, time()]);
            if ($last) {
                $parts[] = [url('/sitemap-' . $type . 's.xml'), (int) $last];
            }
        }
        if (Db::val("SELECT 1 FROM terms t JOIN content_terms ct ON ct.term_id = t.id JOIN content c ON c.id = ct.content_id WHERE c.status = 'published' LIMIT 1")) {
            $parts[] = [url('/sitemap-taxonomies.xml'), (int) Db::val("SELECT MAX(updated_at) FROM content WHERE status = 'published'")];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($parts as [$loc, $mod]) {
            $xml .= '<sitemap><loc>' . e($loc) . '</loc><lastmod>' . gmdate('c', $mod) . "</lastmod></sitemap>\n";
        }
        return $xml . '</sitemapindex>';
    }

    public static function sitemap(string $which): ?string
    {
        $urls = [];
        if ($which === 'posts' || $which === 'pages') {
            $type = rtrim($which, 's');
            $rows = Db::all("SELECT * FROM content WHERE type = ? AND status = 'published' AND published_at <= ? ORDER BY published_at DESC LIMIT 5000", [$type, time()]);
            if ($type === 'page' && Settings::get('home.type') === 'posts') {
                $urls[] = ['loc' => Config::baseUrl() . '/', 'lastmod' => (int) Db::val("SELECT MAX(updated_at) FROM content WHERE status = 'published'"), 'images' => []];
            }
            foreach ($rows as $r) {
                $c = Content::decode($r);
                if (!empty($c['seo']['noindex']) || (!empty($c['seo']['canonical']) && $c['seo']['canonical'] !== Content::permalink($c))) {
                    continue;
                }
                $imgs = [];
                $fid = $c['featured_id'] ?: Blocks::firstImage($c['blocks']);
                foreach (array_unique(array_filter([$fid, ...array_column(Blocks::images($c['blocks']), 'id')])) as $mid) {
                    $m = Media::find((int) $mid);
                    if ($m) {
                        $imgs[] = Media::absoluteUrl($m);
                    }
                }
                $urls[] = ['loc' => Content::permalink($c), 'lastmod' => (int) $c['updated_at'], 'images' => array_slice($imgs, 0, 20)];
            }
        } elseif ($which === 'taxonomies') {
            foreach (Db::all("SELECT t.*, MAX(c.updated_at) AS lastmod FROM terms t JOIN content_terms ct ON ct.term_id = t.id JOIN content c ON c.id = ct.content_id WHERE c.status = 'published' AND c.published_at <= ? GROUP BY t.id", [time()]) as $t) {
                $urls[] = ['loc' => Terms::url($t), 'lastmod' => (int) $t['lastmod'], 'images' => []];
            }
        } else {
            return null;
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
        foreach ($urls as $u) {
            $xml .= '<url><loc>' . e($u['loc']) . '</loc>' . ($u['lastmod'] ? '<lastmod>' . gmdate('c', $u['lastmod']) . '</lastmod>' : '');
            foreach ($u['images'] as $img) {
                $xml .= '<image:image><image:loc>' . e($img) . '</image:loc></image:image>';
            }
            $xml .= "</url>\n";
        }
        return $xml . '</urlset>';
    }

    public static function robots(): string
    {
        $lines = ['# robots.txt généré par Wordless'];
        if (Settings::get('seo.noindex_site', false)) {
            return implode("\n", [...$lines, 'User-agent: *', 'Disallow: /', '']);
        }
        foreach ((array) Settings::get('geo.ai_crawlers', []) as $bot => $allowed) {
            if (!$allowed) {
                $lines[] = '';
                $lines[] = 'User-agent: ' . $bot;
                $lines[] = 'Disallow: /';
            }
        }
        $admin = Config::basePath() . Config::adminPath();
        array_push($lines, '', 'User-agent: *', 'Disallow: ' . $admin . '/', 'Disallow: ' . Config::basePath() . '/search', 'Disallow: ' . Config::basePath() . '/wl/', 'Allow: ' . Config::basePath() . '/media/',
            '', 'Sitemap: ' . Config::baseUrl() . '/sitemap.xml', '');
        return implode("\n", $lines);
    }

    /** llms.txt (proposition llmstxt.org) : carte du site lisible par les modèles de langage. */
    public static function llmsTxt(bool $full = false): string
    {
        $site = (string) Settings::get('site.name');
        $out = '# ' . $site . "\n\n";
        $tagline = trim((string) (Settings::get('seo.home_description') ?: Settings::get('site.tagline', '')));
        if ($tagline !== '') {
            $out .= '> ' . str_replace("\n", ' ', $tagline) . "\n\n";
        }
        $org = (string) (Settings::get('site.org_name') ?: $site);
        $out .= __('Site officiel de {o}. Chaque page existe aussi en Markdown : ajoutez .md à son adresse.', ['o' => $org]) . "\n\n";
        $sections = ['page' => __('Pages'), 'post' => __('Articles')];
        foreach ($sections as $type => $label) {
            $rows = Db::all("SELECT * FROM content WHERE type = ? AND status = 'published' AND published_at <= ? ORDER BY " . ($type === 'page' ? 'menu_order, title' : 'published_at DESC') . ' LIMIT ' . ($full ? 2000 : 200), [$type, time()]);
            $items = [];
            foreach ($rows as $r) {
                $c = Content::decode($r);
                if (!empty($c['seo']['noindex'])) {
                    continue;
                }
                if ($full) {
                    $items[] = "\n---\n\n# " . $c['title'] . "\n\n" . __('Source : {u}', ['u' => Content::permalink($c)]) . "\n" . __('Mis à jour : {d}', ['d' => gmdate('Y-m-d', (int) $c['updated_at'])]) . "\n\n" . Blocks::toMarkdown($c['blocks']);
                } else {
                    $md = Content::isHome($c) ? Config::baseUrl() . '/index.md' : rtrim(Content::permalink($c), '/') . '.md';
                    $items[] = '- [' . $c['title'] . '](' . $md . '): ' . Content::summary($c, 140);
                }
            }
            if ($items) {
                $out .= ($full ? '' : '## ' . $label . "\n\n") . implode("\n", $items) . "\n\n";
            }
        }
        if (!$full) {
            $out .= "## Optional\n\n- [" . __('Contenu complet') . '](' . Config::baseUrl() . "/llms-full.txt)\n- [" . __('Plan du site') . '](' . Config::baseUrl() . "/sitemap.xml)\n";
        }
        return rtrim($out) . "\n";
    }

    // ------------------------------------------------------------------
    // Vue d'ensemble pour le tableau de bord
    // ------------------------------------------------------------------

    public static function siteReport(): array
    {
        $published = "status = 'published'";
        $total = (int) Db::val("SELECT COUNT(*) FROM content WHERE $published");
        $avgSeo = (int) round((float) Db::val("SELECT AVG(seo_score) FROM content WHERE $published"));
        $avgGeo = (int) round((float) Db::val("SELECT AVG(geo_score) FROM content WHERE $published"));
        $noDesc = (int) Db::val("SELECT COUNT(*) FROM content WHERE $published AND COALESCE(json_extract(seo, '$.description'), '') = ''");
        $weak = (int) Db::val("SELECT COUNT(*) FROM content WHERE $published AND COALESCE(seo_score, 0) < 60");
        $altMissing = (int) Db::val("SELECT COUNT(*) FROM media WHERE mime LIKE 'image/%' AND alt = ''");
        $stale = (int) Db::val("SELECT COUNT(*) FROM content WHERE $published AND type = 'post' AND updated_at < ?", [time() - 365 * 86400]);
        $noindex = (int) Db::val("SELECT COUNT(*) FROM content WHERE $published AND json_extract(seo, '$.noindex') = 1");
        $faqPages = (int) Db::val("SELECT COUNT(*) FROM content WHERE $published AND blocks LIKE '%\"type\":\"faq\"%'");
        $notFound = (int) Db::val('SELECT COUNT(*) FROM not_found WHERE last_at > ?', [time() - 30 * 86400]);
        return compact('total', 'avgSeo', 'avgGeo', 'noDesc', 'weak', 'altMissing', 'stale', 'noindex', 'faqPages', 'notFound');
    }
}
