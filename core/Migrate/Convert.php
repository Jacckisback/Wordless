<?php
declare(strict_types=1);

namespace Wordless\Migrate;

use DOMElement;
use DOMNode;
use DOMText;
use Wordless\Blocks;
use Wordless\Html;
use Wordless\Media;

/**
 * Conversion du contenu WordPress en blocs Wordless.
 *
 * Trois formats d'entrée :
 * - éditeur de blocs (Gutenberg) : <!-- wp:nom {json} -->…<!-- /wp:nom -->, imbriqués ;
 * - éditeur classique : texte avec sauts de ligne (wpautop) et codes courts ;
 * - HTML déjà rendu par WordPress (API REST sans mot de passe d'application).
 * (Elementor est traité par la classe Elementor, qui s'appuie sur celle-ci.)
 *
 * Les blocs « texte » consécutifs (paragraphes, titres, listes, citations,
 * tableaux…) sont fusionnés en un seul bloc Texte. Tout le HTML passe par
 * Html::clean() et les adresses de l'ancien site sont réécrites (pages en
 * chemins relatifs, fichiers vers la médiathèque Wordless).
 */
final class Convert
{
    /** Blocs WordPress convertis en HTML dans un bloc Texte. */
    private const TEXT_BLOCKS = [
        'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/pullquote', 'core/table',
        'core/preformatted', 'core/code', 'core/verse', 'core/details', 'core/freeform', 'core/audio', 'core/file',
    ];

    /** Blocs de conteneur : leurs enfants sont convertis à la suite. */
    private const CONTAINERS = ['core/group', 'core/row', 'core/stack', 'core/grid', 'core/column', 'core/comments', 'core/post-content'];

    /** Blocs dynamiques de thème (rendus par WordPress) : ignorés. */
    private const DYNAMIC = [
        'core/navigation', 'core/site-title', 'core/site-tagline', 'core/site-logo', 'core/template-part', 'core/pattern',
        'core/post-title', 'core/post-date', 'core/post-author', 'core/post-excerpt', 'core/post-featured-image', 'core/post-terms',
        'core/post-navigation-link', 'core/query-pagination', 'core/query-title', 'core/query-no-results', 'core/loginout',
        'core/search', 'core/archives', 'core/categories', 'core/tag-cloud', 'core/calendar', 'core/rss', 'core/latest-comments',
        'core/page-list', 'core/comment-template', 'core/comments-title', 'core/post-comments-form', 'core/read-more',
        'core/avatar', 'core/footnotes', 'core/home-link', 'core/term-description', 'core/accordion',
    ];

    /** Codes courts transformés en blocs. */
    private const FORM_SHORTCODES = ['contact-form-7', 'contact-form', 'wpforms', 'gravityform', 'gravityforms', 'ninja_form', 'ninja_forms',
        'formidable', 'fluentform', 'forminator_form', 'caldera_form', 'everest_form', 'ws_form', 'si-contact-form', 'cforms'];

    private Context $ctx;
    /** @var callable|null fn(int $ref): ?string — contenu d'une composition synchronisée (wp_block) */
    private $patterns;
    /** @var list<array> */
    private array $blocks = [];
    private string $buf = '';
    private int $depth = 0;

    public function __construct(Context $ctx, ?callable $patterns = null)
    {
        $this->ctx = $ctx;
        $this->patterns = $patterns;
    }

    public function ctx(): Context
    {
        return $this->ctx;
    }

    /** Convertit un élément normalisé (WXR ou REST) en liste de blocs Wordless. */
    public function item(array $item): array
    {
        $this->blocks = [];
        $this->buf = '';
        $content = (string) ($item['content'] ?? '');
        $meta = (array) ($item['meta'] ?? []);
        if (!empty($meta['_elementor_data']) && ($meta['_elementor_edit_mode'] ?? 'builder') === 'builder') {
            $data = Elementor::decode((string) $meta['_elementor_data']);
            if ($data) {
                (new Elementor($this))->convert($data);
                $blocks = $this->finish();
                if ($blocks) {
                    return $blocks;
                }
            }
            $this->ctx->issues[] = __('Mise en page Elementor non convertie : texte de secours utilisé, à vérifier.');
        }
        $this->blocks = [];
        $this->buf = '';
        if (!empty($item['rendered'])) {
            $this->rendered($content);
        } elseif (str_contains($content, '<!-- wp:')) {
            $this->walk(self::parse($content));
        } else {
            $this->richText($content, true);
        }
        return $this->finish();
    }

    /** Termine la conversion : vide le tampon de texte et renvoie les blocs. */
    public function finish(): array
    {
        $this->flush();
        $out = $this->blocks;
        $this->blocks = [];
        return $out;
    }

    // ------------------------------------------------------------------
    // Accumulation : texte fusionné, blocs
    // ------------------------------------------------------------------

    /** Ajoute du HTML au bloc Texte en cours. */
    public function html(string $html): void
    {
        $this->buf .= $html . "\n";
    }

    /** Ajoute un bloc (le texte en attente est d'abord transformé en bloc Texte). */
    public function push(?array $block): void
    {
        if ($block === null) {
            return;
        }
        $this->flush();
        $this->blocks[] = $block;
    }

    /** Dernier bloc produit (fusion de témoignages, chiffres…). */
    public function &last(): ?array
    {
        $this->flush();
        $null = null;
        if (!$this->blocks) {
            return $null;
        }
        return $this->blocks[count($this->blocks) - 1];
    }

    private function flush(): void
    {
        if (trim($this->buf) === '') {
            $this->buf = '';
            return;
        }
        $html = $this->ctx->rewriteHtml(Html::clean($this->buf, 'full'));
        $this->buf = '';
        if (trim(plain_text($html)) === '' && !preg_match('/<(img|iframe|video|audio|hr|table)\b/i', $html)) {
            return;
        }
        $n = count($this->blocks);
        if ($n > 0 && $this->blocks[$n - 1]['type'] === 'text') {
            $this->blocks[$n - 1]['data']['html'] .= "\n" . $html;
            return;
        }
        $this->blocks[] = Blocks::make('text', ['html' => $html]);
    }

    /**
     * Retire le titre qui précède immédiatement (h2-h4) pour en faire le titre
     * du bloc suivant (FAQ, derniers articles…) : évite un titre en double.
     */
    public function stealHeading(): string
    {
        // Dernier titre du tampon, sans autre titre à l'intérieur (motif « tempéré »).
        $re = '#<h([2-6])\b[^>]*>((?:(?!</?h[1-6]\b).)*)</h\1>\s*$#is';
        if (trim($this->buf) !== '') {
            if (preg_match($re, rtrim($this->buf), $m)) {
                $this->buf = substr(rtrim($this->buf), 0, -strlen($m[0]));
                return plain_text($m[2]);
            }
            return '';
        }
        $n = count($this->blocks);
        if ($n > 0 && $this->blocks[$n - 1]['type'] === 'text' && preg_match($re, rtrim($this->blocks[$n - 1]['data']['html']), $m)) {
            $rest = trim(substr(rtrim($this->blocks[$n - 1]['data']['html']), 0, -strlen($m[0])));
            if ($rest === '') {
                array_pop($this->blocks);
            } else {
                $this->blocks[$n - 1]['data']['html'] = $rest;
            }
            return plain_text($m[2]);
        }
        return '';
    }

    /** HTML nettoyé en profil « inline » (champs enrichis des blocs) et adresses réécrites. */
    public function inline(string $html): string
    {
        return $this->ctx->rewriteHtml(Html::clean($html, 'inline'));
    }

    // ------------------------------------------------------------------
    // Éditeur de blocs : analyse (portage simplifié de WP_Block_Parser)
    // ------------------------------------------------------------------

    /**
     * @return list<array{blockName: ?string, attrs: array, innerBlocks: list<array>, innerHTML: string, innerContent: list<?string>}>
     */
    public static function parse(string $doc): array
    {
        $re = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';
        $out = [];
        $stack = [];
        $offset = 0;
        $len = strlen($doc);
        $freeform = static fn (string $html): array => ['blockName' => null, 'attrs' => [], 'innerBlocks' => [], 'innerHTML' => $html, 'innerContent' => [$html]];
        $addHtml = static function (array &$block, string $html): void {
            if ($html === '') {
                return;
            }
            $block['innerHTML'] .= $html;
            $block['innerContent'][] = $html;
        };
        while (true) {
            $has = preg_match($re, $doc, $m, PREG_OFFSET_CAPTURE, $offset);
            if (!$has) {
                $rest = substr($doc, $offset);
                if ($stack) {
                    // Blocs jamais fermés : on les referme proprement.
                    $addHtml($stack[count($stack) - 1], $rest);
                    while (count($stack) > 1) {
                        $child = array_pop($stack);
                        $stack[count($stack) - 1]['innerBlocks'][] = $child;
                        $stack[count($stack) - 1]['innerContent'][] = null;
                    }
                    $out[] = array_pop($stack);
                } elseif (trim($rest) !== '') {
                    $out[] = $freeform($rest);
                }
                break;
            }
            $start = $m[0][1];
            $tokenLen = strlen($m[0][0]);
            $name = ($m['namespace'][0] ?? '') !== '' && $m['namespace'][1] >= 0 ? $m['namespace'][0] . $m['name'][0] : 'core/' . $m['name'][0];
            $attrs = isset($m['attrs']) && $m['attrs'][1] >= 0 && $m['attrs'][0] !== '' ? (json_decode($m['attrs'][0], true) ?? []) : [];
            $isCloser = isset($m['closer']) && $m['closer'][1] >= 0 && $m['closer'][0] !== '';
            $isVoid = isset($m['void']) && $m['void'][1] >= 0 && $m['void'][0] !== '';
            $html = substr($doc, $offset, $start - $offset);
            $offset = $start + $tokenLen;

            if ($isCloser) {
                if (!$stack) {
                    continue; // fermeture orpheline : ignorée
                }
                $block = array_pop($stack);
                $addHtml($block, $html);
                if ($stack) {
                    $stack[count($stack) - 1]['innerBlocks'][] = $block;
                    $stack[count($stack) - 1]['innerContent'][] = null;
                } else {
                    $out[] = $block;
                }
                continue;
            }
            if ($stack) {
                $addHtml($stack[count($stack) - 1], $html);
            } elseif (trim($html) !== '') {
                $out[] = $freeform($html);
            }
            $block = ['blockName' => $name, 'attrs' => is_array($attrs) ? $attrs : [], 'innerBlocks' => [], 'innerHTML' => '', 'innerContent' => []];
            if ($isVoid) {
                if ($stack) {
                    $stack[count($stack) - 1]['innerBlocks'][] = $block;
                    $stack[count($stack) - 1]['innerContent'][] = null;
                } else {
                    $out[] = $block;
                }
            } else {
                $stack[] = $block;
            }
            if ($offset >= $len && !$stack) {
                break;
            }
        }
        return $out;
    }

    /** HTML complet d'un bloc (y compris ses blocs internes), sans les commentaires. */
    public static function serialize(array $b): string
    {
        $html = '';
        $i = 0;
        foreach ($b['innerContent'] as $chunk) {
            if (is_string($chunk)) {
                $html .= $chunk;
            } elseif (isset($b['innerBlocks'][$i])) {
                $html .= self::serialize($b['innerBlocks'][$i++]);
            }
        }
        return $html;
    }

    /** Aplatit les conteneurs (groupe, ligne, pile…) d'une liste de blocs. */
    private static function flatten(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            if (in_array($b['blockName'], self::CONTAINERS, true) && $b['innerBlocks']) {
                array_push($out, ...self::flatten($b['innerBlocks']));
            } elseif ($b['blockName'] !== null || trim($b['innerHTML']) !== '') {
                $out[] = $b;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Éditeur de blocs : conversion
    // ------------------------------------------------------------------

    private function walk(array $blocks): void
    {
        foreach ($blocks as $b) {
            $this->block($b);
        }
    }

    private function block(array $b): void
    {
        $name = $b['blockName'];
        $a = $b['attrs'];
        if ($name === null) {
            if (trim($b['innerHTML']) !== '') {
                $this->richText($b['innerHTML'], true); // HTML libre entre les blocs (contenu classique)
            }
            return;
        }
        if (in_array($name, self::TEXT_BLOCKS, true)) {
            $this->richText(self::serialize($b), $name === 'core/freeform');
            return;
        }
        if (in_array($name, self::CONTAINERS, true)) {
            $this->container($b);
            return;
        }
        switch ($name) {
            case 'core/separator':
                $this->html('<hr>');
                return;
            case 'core/spacer':
            case 'core/more':
            case 'core/nextpage':
                return;
            case 'core/image':
                $this->push($this->imageBlock($b));
                return;
            case 'core/gallery':
                $this->gallery($b);
                return;
            case 'core/embed':
                $this->embed((string) ($a['url'] ?? ''), $b);
                return;
            case 'core/video':
                $this->videoBlock($b);
                return;
            case 'core/buttons':
                $align = ($a['layout']['justifyContent'] ?? '') === 'center' ? 'center' : 'left';
                foreach ($b['innerBlocks'] as $btn) {
                    $this->push($this->button($btn, $align));
                }
                return;
            case 'core/button':
                $this->push($this->button($b, 'left'));
                return;
            case 'core/columns':
                $this->columns($b);
                return;
            case 'core/cover':
                $this->cover($b);
                return;
            case 'core/media-text':
                $this->mediaText($b);
                return;
            case 'core/html':
                $this->htmlBlock($b['innerHTML']);
                return;
            case 'core/block':
                $this->pattern((int) ($a['ref'] ?? 0));
                return;
            case 'core/latest-posts':
            case 'core/query':
                $this->posts($a);
                return;
            case 'core/shortcode':
                $this->richText($b['innerHTML'], true);
                return;
            case 'core/missing':
                $this->richText((string) ($a['originalContent'] ?? $b['innerHTML']), true);
                return;
            case 'core/social-links':
                $this->socialLinks($b);
                return;
            case 'core/table-of-contents':
            case 'yoast-seo/table-of-contents':
            case 'rank-math/toc-block':
            case 'rank-math/toc':
            case 'simpletoc/toc':
                $this->toc($b);
                return;
            case 'yoast/faq-block':
            case 'rank-math/faq-block':
                $this->faq($b);
                return;
            case 'yoast/how-to-block':
            case 'rank-math/howto-block':
                $this->richText(self::serialize($b), false);
                return;
        }
        if (str_starts_with($name, 'core-embed/')) {
            $this->embed((string) ($a['url'] ?? ''), $b);
            return;
        }
        if (in_array($name, self::DYNAMIC, true) || str_starts_with($name, 'core/post-') || str_starts_with($name, 'core/query-') || str_starts_with($name, 'core/comment')) {
            $this->ctx->job()->warn('dynamic', __('Blocs dynamiques de thème ignorés (navigation, titre du site…)'), $name);
            return;
        }
        $this->unknown($b);
    }

    /** Conteneur : le HTML autour des enfants (rare) est gardé, les enfants convertis à la suite. */
    private function container(array $b): void
    {
        $i = 0;
        foreach ($b['innerContent'] as $chunk) {
            if (is_string($chunk)) {
                $text = trim(plain_text($chunk));
                if ($text !== '') {
                    $this->html($chunk);
                }
            } elseif (isset($b['innerBlocks'][$i])) {
                $this->block($b['innerBlocks'][$i++]);
            }
        }
    }

    /** Bloc d'extension inconnu : enfants convertis, sinon HTML intérieur nettoyé dans un bloc Code HTML. */
    private function unknown(array $b): void
    {
        $name = (string) $b['blockName'];
        if ($b['innerBlocks']) {
            $this->ctx->job()->warn('block', __('Blocs d’extension simplifiés (contenu conservé, mise en forme perdue)'), $name);
            $this->container($b);
            return;
        }
        $html = self::serialize($b);
        if (trim(plain_text($html)) === '' && !preg_match('/<(img|iframe|video)\b/i', $html)) {
            $this->ctx->job()->warn('block', __('Blocs d’extension sans contenu enregistré (rendus par l’extension) : ignorés'), $name);
            $this->ctx->issues[] = __('Bloc « {b} » non converti (généré par une extension WordPress).', ['b' => $name]);
            return;
        }
        $this->ctx->job()->warn('block', __('Blocs inconnus convertis en code HTML'), $name);
        $this->ctx->issues[] = __('Bloc « {b} » conservé en code HTML, à vérifier.', ['b' => $name]);
        $this->push(Blocks::make('html', ['code' => $this->ctx->rewriteHtml(Html::clean($html, 'full'))]));
    }

    /** Éléments d'une figure : image, lien, légende. */
    public static function figureParts(string $html): array
    {
        $out = ['src' => '', 'alt' => '', 'href' => '', 'caption' => '', 'width' => null];
        if (!preg_match('/<img\b/i', $html)) {
            if (preg_match('#<figcaption\b[^>]*>(.*?)</figcaption>#is', $html, $m)) {
                $out['caption'] = plain_text($m[1]);
            }
            return $out;
        }
        [, $root] = Html::load($html);
        $img = $root->getElementsByTagName('img')->item(0);
        if ($img instanceof DOMElement) {
            $out['src'] = $img->getAttribute('src') !== '' ? $img->getAttribute('src') : $img->getAttribute('data-src');
            $out['alt'] = trim($img->getAttribute('alt'));
            $out['width'] = (int) $img->getAttribute('width') ?: null;
            $p = $img->parentNode;
            while ($p instanceof DOMElement && $p !== $root) {
                if (strtolower($p->tagName) === 'a') {
                    $out['href'] = $p->getAttribute('href');
                    break;
                }
                $p = $p->parentNode;
            }
        }
        $cap = $root->getElementsByTagName('figcaption')->item(0);
        if ($cap) {
            $out['caption'] = plain_text(Html::inner($cap));
        }
        return $out;
    }

    /** Média Wordless d'après l'identifiant WordPress ou l'adresse du fichier. */
    public function mediaRow(int $wpId, string $url): ?array
    {
        if ($wpId > 0 && ($id = $this->ctx->mediaId($wpId))) {
            return Media::find($id);
        }
        $url = trim($url);
        return $url !== '' ? $this->ctx->mediaForUrl($url) : null;
    }

    /** Bloc Image, ou image distante gardée dans le texte si le média est introuvable. */
    public function image(int $wpId, string $src, string $alt, string $caption, string $href, string $size = 'content'): ?array
    {
        $m = $this->mediaRow($wpId, $src);
        if (!$m || !Media::isImage($m)) {
            if ($src !== '') {
                $this->html('<figure><img src="' . e($src) . '" alt="' . e($alt) . '">' . ($caption !== '' ? '<figcaption>' . e($caption) . '</figcaption>' : '') . '</figure>');
            }
            return null;
        }
        $link = $href !== '' ? $this->ctx->rewriteUrl($href, true) : '';
        return Blocks::make('image', [
            'media' => (int) $m['id'],
            'alt' => $alt !== (string) $m['alt'] ? $alt : '',
            'caption' => $caption,
            'link' => $link,
            'size' => in_array($size, ['wide', 'full'], true) ? $size : 'content',
        ]);
    }

    private function imageBlock(array $b): ?array
    {
        $p = self::figureParts($b['innerHTML']);
        $src = $p['src'] !== '' ? $p['src'] : (string) ($b['attrs']['url'] ?? '');
        return $this->image((int) ($b['attrs']['id'] ?? 0), $src, $p['alt'], $p['caption'], $p['href'], (string) ($b['attrs']['align'] ?? ''));
    }

    private function gallery(array $b): void
    {
        $a = $b['attrs'];
        $ids = [];
        if ($b['innerBlocks']) {
            foreach ($b['innerBlocks'] as $img) {
                if ($img['blockName'] === 'core/image') {
                    $p = self::figureParts($img['innerHTML']);
                    $m = $this->mediaRow((int) ($img['attrs']['id'] ?? 0), $p['src']);
                    if ($m) {
                        $ids[] = (int) $m['id'];
                    }
                }
            }
        } elseif (!empty($a['ids'])) {
            foreach ((array) $a['ids'] as $wpId) {
                if ($id = $this->ctx->mediaId((int) $wpId)) {
                    $ids[] = $id;
                }
            }
        } elseif (!empty($a['images'])) {
            foreach ((array) $a['images'] as $img) {
                $m = $this->mediaRow((int) ($img['id'] ?? 0), (string) ($img['url'] ?? ''));
                if ($m) {
                    $ids[] = (int) $m['id'];
                }
            }
        } elseif (preg_match_all('#<img\b[^>]*\bsrc="([^"]+)"#i', $b['innerHTML'], $mm)) {
            foreach ($mm[1] as $src) {
                $m = $this->mediaRow(0, html_entity_decode($src));
                if ($m) {
                    $ids[] = (int) $m['id'];
                }
            }
        }
        $caption = preg_match('#<figcaption\b[^>]*>(.*?)</figcaption>\s*</figure>\s*$#is', $b['innerHTML'], $m) ? plain_text($m[1]) : '';
        $this->pushGallery($ids, (int) ($a['columns'] ?? 3), $caption);
        if (!$ids) {
            $this->html(self::serialize($b));
        }
    }

    public function pushGallery(array $ids, int $columns, string $caption = ''): void
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if (!$ids) {
            return;
        }
        $this->push(Blocks::make('gallery', ['medias' => $ids, 'columns' => (string) max(2, min(4, $columns ?: 3)), 'caption' => $caption]));
    }

    /** Vidéo YouTube/Vimeo ; les autres intégrations deviennent un simple lien. */
    public function embed(string $url, ?array $b = null): void
    {
        if ($url === '' && $b) {
            $url = preg_match('#https?://[^\s<>"]+#', strip_tags($b['innerHTML']), $m) ? $m[0] : '';
        }
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '') {
            return;
        }
        $caption = $b && preg_match('#<figcaption\b[^>]*>(.*?)</figcaption>#is', $b['innerHTML'], $m) ? plain_text($m[1]) : '';
        if (Blocks::youtubeId($url) !== null || Blocks::vimeoId($url) !== null) {
            $this->push(Blocks::make('video', ['url' => $url, 'caption' => $caption]));
            return;
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $this->ctx->job()->warn('embed', __('Intégrations (hors YouTube et Vimeo) converties en lien'), $host);
        $this->html('<p><a href="' . e($this->ctx->rewriteUrl($url)) . '">' . e($caption !== '' ? $caption : $url) . '</a></p>');
    }

    private function videoBlock(array $b): void
    {
        $src = preg_match('#<video\b[^>]*\bsrc="([^"]+)"#i', $b['innerHTML'], $m) ? html_entity_decode($m[1]) : (string) ($b['attrs']['src'] ?? '');
        $caption = preg_match('#<figcaption\b[^>]*>(.*?)</figcaption>#is', $b['innerHTML'], $m) ? plain_text($m[1]) : '';
        $media = $this->mediaRow((int) ($b['attrs']['id'] ?? 0), $src);
        $url = $media ? '/media/' . $media['path'] : $this->ctx->rewriteUrl($src, true);
        if ($url === '') {
            return;
        }
        $poster = null;
        if (preg_match('#\bposter="([^"]+)"#i', $b['innerHTML'], $m) && ($pm = $this->mediaRow(0, html_entity_decode($m[1])))) {
            $poster = (int) $pm['id'];
        }
        $this->push(Blocks::make('video', ['url' => $url, 'caption' => $caption, 'poster' => $poster]));
    }

    private function button(array $b, string $align): ?array
    {
        if ($b['blockName'] !== 'core/button') {
            return null;
        }
        $html = self::serialize($b);
        $href = preg_match('#<a\b[^>]*\bhref="([^"]*)"#i', $html, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : (string) ($b['attrs']['url'] ?? '');
        $label = preg_match('#<a\b[^>]*>(.*?)</a>#is', $html, $m) ? plain_text($m[1]) : plain_text((string) ($b['attrs']['text'] ?? $html));
        if ($label === '') {
            return null;
        }
        $outline = str_contains((string) ($b['attrs']['className'] ?? ''), 'is-style-outline') || str_contains($html, 'is-style-outline');
        return Blocks::make('button', ['label' => $label, 'url' => $this->ctx->rewriteUrl($href, true), 'style' => $outline ? 'secondary' : 'primary', 'align' => $align]);
    }

    /** Une colonne de contenu simple : image, titre, texte (sinon null). */
    private function columnItem(array $blocks): ?array
    {
        $item = ['media' => null, 'title' => '', 'text' => ''];
        $html = '';
        foreach (self::flatten($blocks) as $b) {
            $name = $b['blockName'];
            if ($name === 'core/image' && $item['media'] === null) {
                $p = self::figureParts($b['innerHTML']);
                $m = $this->mediaRow((int) ($b['attrs']['id'] ?? 0), $p['src']);
                if ($m) {
                    $item['media'] = (int) $m['id'];
                    continue;
                }
                $html .= $b['innerHTML'];
            } elseif ($name === 'core/heading' && $item['title'] === '' && trim(plain_text($html)) === '') {
                $item['title'] = plain_text(self::serialize($b));
            } elseif ($name === 'core/buttons' || $name === 'core/button') {
                foreach ($name === 'core/button' ? [$b] : $b['innerBlocks'] as $btn) {
                    if (preg_match('#<a\b[^>]*\bhref="([^"]*)"[^>]*>(.*?)</a>#is', self::serialize($btn), $m)) {
                        $html .= '<p><a href="' . $m[1] . '">' . e(plain_text($m[2])) . '</a></p>';
                    }
                }
            } elseif (in_array($name, ['core/spacer', 'core/separator', 'core/more'], true)) {
                continue;
            } else {
                $html .= self::serialize($b);
            }
        }
        $item['text'] = $this->inline($html);
        return $item['media'] === null && $item['title'] === '' && trim(plain_text($item['text'])) === '' ? null : $item;
    }

    /** Une colonne est « simple » si elle ne contient que texte, image et boutons. */
    private static function simpleColumn(array $blocks): bool
    {
        $images = 0;
        foreach (self::flatten($blocks) as $b) {
            $name = (string) $b['blockName'];
            if ($name === 'core/image') {
                $images++;
                continue;
            }
            if (!in_array($name, ['core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/buttons', 'core/button',
                'core/spacer', 'core/separator', 'core/more', 'core/freeform'], true) && $name !== '') {
                return false;
            }
        }
        return $images <= 1;
    }

    private function columns(array $b): void
    {
        $cols = array_values(array_filter($b['innerBlocks'], static fn ($c) => $c['blockName'] === 'core/column'));
        foreach ($cols as $c) {
            if (!self::simpleColumn($c['innerBlocks'])) {
                $this->container($b); // colonnes complexes (galerie, vidéo…) : contenu à la suite
                return;
            }
        }
        $items = [];
        foreach ($cols as $c) {
            if ($item = $this->columnItem($c['innerBlocks'])) {
                $items[] = $item;
            }
        }
        if (count($items) === 1) {
            $it = $items[0];
            if ($it['title'] !== '') {
                $this->html('<h3>' . e($it['title']) . '</h3>');
            }
            $this->html($it['text']);
            if ($it['media']) {
                $this->push(Blocks::make('image', ['media' => $it['media']]));
            }
            return;
        }
        foreach (array_chunk($items, 4) as $chunk) {
            $this->push(Blocks::make('columns', ['items' => $chunk]));
        }
    }

    private function cover(array $b): void
    {
        $a = $b['attrs'];
        $media = null;
        if (($a['backgroundType'] ?? 'image') === 'image' && (!empty($a['id']) || !empty($a['url']))) {
            $m = $this->mediaRow((int) ($a['id'] ?? 0), (string) ($a['url'] ?? ''));
            $media = $m ? (int) $m['id'] : null;
        }
        $title = '';
        $text = '';
        $buttons = [];
        foreach (self::flatten($b['innerBlocks']) as $inner) {
            $name = $inner['blockName'];
            if ($name === 'core/heading' && $title === '') {
                $title = plain_text(self::serialize($inner));
            } elseif ($name === 'core/buttons' || $name === 'core/button') {
                foreach ($name === 'core/button' ? [$inner] : $inner['innerBlocks'] as $btn) {
                    if ($bb = $this->button($btn, 'left')) {
                        $buttons[] = $bb['data'];
                    }
                }
            } elseif ($name !== null) {
                $text .= self::serialize($inner);
            }
        }
        if ($title === '' && trim(plain_text($text)) === '' && $media === null) {
            return;
        }
        $this->push(Blocks::make('hero', [
            'title' => $title,
            'text' => $this->inline($text),
            'button' => $buttons[0]['label'] ?? '', 'url' => $buttons[0]['url'] ?? '',
            'button2' => $buttons[1]['label'] ?? '', 'url2' => $buttons[1]['url'] ?? '',
            'media' => $media,
            'layout' => $media ? 'cover' : 'center',
            'tone' => $media ? 'default' : 'soft',
        ]));
    }

    private function mediaText(array $b): void
    {
        $a = $b['attrs'];
        $m = $this->mediaRow((int) ($a['mediaId'] ?? 0), (string) ($a['mediaUrl'] ?? ''));
        if (!$m && preg_match('#<img\b[^>]*\bsrc="([^"]+)"#i', $b['innerHTML'], $mm)) {
            $m = $this->mediaRow(0, html_entity_decode($mm[1]));
        }
        $text = $this->columnItem($b['innerBlocks']) ?? ['media' => null, 'title' => '', 'text' => ''];
        $mediaItem = ['media' => $m ? (int) $m['id'] : null, 'title' => '', 'text' => ''];
        $items = ($a['mediaPosition'] ?? 'left') === 'right' ? [$text, $mediaItem] : [$mediaItem, $text];
        $items = array_values(array_filter($items, static fn ($i) => $i['media'] !== null || $i['title'] !== '' || $i['text'] !== ''));
        if (count($items) < 2) {
            $this->container($b);
            return;
        }
        $this->push(Blocks::make('columns', ['items' => $items]));
    }

    /** Bloc Code HTML : conservé, nettoyé à l'enregistrement ; on signale ce qui est retiré. */
    public function htmlBlock(string $code): void
    {
        if (trim($code) === '') {
            return;
        }
        $clean = Html::clean($code, 'full');
        $lost = [];
        foreach (['script', 'iframe', 'form', 'style', 'object', 'embed', 'svg', 'canvas', 'input', 'select', 'textarea', 'button'] as $tag) {
            $re = '/<' . $tag . '\b/i';
            if (preg_match_all($re, $code) > preg_match_all($re, $clean)) {
                $lost[] = $tag;
            }
        }
        if ($lost) {
            $this->ctx->issues[] = __('Code HTML : éléments retirés pour la sécurité ({t}), à remplacer si besoin.', ['t' => implode(', ', $lost)]);
            $this->ctx->job()->warn('html', __('Code HTML nettoyé (scripts, iframes non autorisées, formulaires retirés)'), implode(', ', $lost));
        }
        if (trim(plain_text($clean)) === '' && !preg_match('/<(img|iframe|video|audio)\b/i', $clean)) {
            return;
        }
        $this->push(Blocks::make('html', ['code' => $this->ctx->rewriteHtml($code)]));
    }

    /** Composition synchronisée (bloc réutilisable) : son contenu est intégré à la page. */
    private function pattern(int $ref): void
    {
        if ($ref <= 0 || $this->depth > 5 || !$this->patterns) {
            return;
        }
        $raw = ($this->patterns)($ref);
        if ($raw === null) {
            $this->ctx->job()->warn('pattern', __('Compositions synchronisées introuvables'), (string) $ref);
            return;
        }
        $this->ctx->job()->warn('pattern', __('Compositions synchronisées intégrées au contenu (elles ne sont plus partagées)'), (string) $ref);
        $this->depth++;
        $this->walk(self::parse($raw));
        $this->depth--;
    }

    private function posts(array $a): void
    {
        $count = (int) ($a['postsToShow'] ?? ($a['query']['perPage'] ?? 5));
        $cats = (array) ($a['categories'] ?? ($a['query']['taxQuery']['category'] ?? []));
        $first = $cats[0] ?? null;
        $wpCat = is_array($first) ? (int) ($first['id'] ?? 0) : (int) $first;
        $this->push(Blocks::make('posts', [
            'title' => $this->stealHeading(),
            'count' => max(1, min(12, $count ?: 5)),
            'category' => $wpCat > 0 ? $this->ctx->termId('category', $wpCat) : null,
            'layout' => !empty($a['displayFeaturedImage']) || isset($a['query']) ? 'cards' : 'list',
        ]));
    }

    private function socialLinks(array $b): void
    {
        $items = '';
        foreach ($b['innerBlocks'] as $l) {
            $url = (string) ($l['attrs']['url'] ?? '');
            if ($url !== '') {
                $label = (string) ($l['attrs']['label'] ?? '') !== '' ? (string) $l['attrs']['label'] : ucfirst((string) ($l['attrs']['service'] ?? $url));
                $items .= '<li><a href="' . e($url) . '">' . e($label) . '</a></li>';
            }
        }
        if ($items !== '') {
            $this->html('<ul>' . $items . '</ul>');
        }
    }

    private function toc(array $b): void
    {
        $title = preg_match('#<h[2-6]\b[^>]*>(.*?)</h[2-6]>#is', $b['innerHTML'], $m) ? plain_text($m[1]) : (string) ($b['attrs']['title'] ?? '');
        $this->push(Blocks::make('toc', $title !== '' ? ['title' => $title] : []));
    }

    /** FAQ Yoast / Rank Math : questions et réponses lues dans le HTML enregistré. */
    private function faq(array $b): void
    {
        $items = self::faqItems($b['innerHTML']);
        if (!$items) {
            foreach ((array) ($b['attrs']['questions'] ?? []) as $q) {
                $question = plain_text((string) ($q['jsonQuestion'] ?? ($q['question'] ?? ($q['title'] ?? ''))));
                $answer = (string) ($q['jsonAnswer'] ?? ($q['answer'] ?? ($q['content'] ?? '')));
                if ($question !== '') {
                    $items[] = ['q' => $question, 'a' => $answer];
                }
            }
        }
        $this->pushFaq($items);
    }

    /** @return list<array{q: string, a: string}> */
    public static function faqItems(string $html): array
    {
        if (!preg_match('/schema-faq-section|rank-math-list-item|rank-math-question/', $html)) {
            return [];
        }
        [, $root] = Html::load($html);
        $items = [];
        $xpath = new \DOMXPath($root->ownerDocument);
        $has = static fn (string $class): string => "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";
        foreach ([['schema-faq-section', 'schema-faq-question', 'schema-faq-answer'], ['rank-math-list-item', 'rank-math-question', 'rank-math-answer']] as [$section, $q, $a]) {
            foreach ($xpath->query('.//*[' . $has($section) . ']', $root) ?: [] as $sec) {
                $qn = $xpath->query('.//*[' . $has($q) . ']', $sec)->item(0);
                $an = $xpath->query('.//*[' . $has($a) . ']', $sec)->item(0);
                if ($qn) {
                    $items[] = ['q' => plain_text(Html::inner($qn)), 'a' => $an ? Html::inner($an) : ''];
                }
            }
        }
        return $items;
    }

    public function pushFaq(array $items): void
    {
        $out = [];
        foreach ($items as $it) {
            $q = trim((string) $it['q']);
            if ($q === '') {
                continue;
            }
            $a = (string) $it['a'];
            if (!preg_match('/<(p|ul|ol)\b/i', $a)) {
                $a = Html::autop($a);
            }
            $out[] = ['q' => $q, 'a' => $this->inline($a)];
        }
        if (!$out) {
            return;
        }
        $this->push(Blocks::make('faq', ['title' => $this->stealHeading(), 'items' => array_slice($out, 0, 30)]));
    }

    // ------------------------------------------------------------------
    // Texte classique et codes courts
    // ------------------------------------------------------------------

    /**
     * Texte avec codes courts : [caption] -> figure, [gallery] -> Galerie,
     * [embed]/URL seule sur sa ligne -> Vidéo, formulaires -> Formulaire de contact,
     * constructeurs de pages -> balises retirées, autres codes -> laissés et signalés.
     * $autop : appliquer les paragraphes automatiques (éditeur classique).
     */
    public function richText(string $text, bool $autop): void
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (!str_contains($text, '[') && !preg_match('#^[ \t]*https?://\S+[ \t]*$#mi', $text)) {
            if (trim($text) !== '') {
                $this->html($autop ? Html::autop($text) : $text);
            }
            return;
        }
        $text = (string) preg_replace_callback('#\[caption([^\]]*)\](.*?)\[/caption\]#is', fn ($m) => self::caption($m[1], $m[2]), $text);
        $text = $this->pageBuilders($text);

        $tokens = [];
        $forms = implode('|', array_map(static fn ($t) => preg_quote($t, '#'), self::FORM_SHORTCODES));
        $text = (string) preg_replace_callback('#\[(gallery|embed|video|audio|playlist|' . $forms . ')\b([^\]]*)\](?:(.*?)\[/\1\])?#is', static function ($m) use (&$tokens) {
            $tokens[] = ['tag' => strtolower($m[1]), 'attrs' => self::attrs($m[2] ?? ''), 'inner' => $m[3] ?? ''];
            return "\n\n\x01" . (count($tokens) - 1) . "\x01\n\n";
        }, $text);
        // URL seule sur sa ligne : WordPress l'intègre automatiquement (oEmbed).
        $text = (string) preg_replace_callback('#^[ \t]*(https?://[^\s<>"\[\]]+)[ \t]*$#mi', static function ($m) use (&$tokens) {
            $tokens[] = ['tag' => 'embed', 'attrs' => [], 'inner' => $m[1]];
            return "\n\n\x01" . (count($tokens) - 1) . "\x01\n\n";
        }, $text);
        $this->reportShortcodes($text);

        $parts = preg_split('/\x01(\d+)\x01/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                if (trim($part) !== '') {
                    $this->html($autop ? Html::autop($part) : $part);
                }
                continue;
            }
            $this->shortcode($tokens[(int) $part]);
        }
    }

    /** [caption …]<img …> Légende[/caption] => <figure><img><figcaption>. */
    private static function caption(string $attrs, string $inner): string
    {
        $a = self::attrs($attrs);
        $inner = trim($inner);
        $img = '';
        $cap = $inner;
        if (preg_match('#^((?:<a\b[^>]*>\s*)?<img\b[^>]*>(?:\s*</a>)?)(.*)$#is', $inner, $m)) {
            $img = $m[1];
            $cap = trim($m[2]);
        }
        if ($cap === '' && !empty($a['caption'])) {
            $cap = (string) $a['caption'];
        }
        return "\n\n<figure>" . $img . ($cap !== '' ? '<figcaption>' . $cap . '</figcaption>' : '') . "</figure>\n\n";
    }

    /** Divi, WPBakery, Avada… : balises retirées, contenu gardé (et quelques images/vidéos récupérées). */
    private function pageBuilders(string $text): string
    {
        if (!preg_match('#\[/?(et_pb_|vc_|fusion_|av_|cs_|mk_|ux_|tatsu_|themify_)#i', $text)) {
            return $text;
        }
        $this->ctx->job()->warn('builder', __('Contenus de constructeurs de pages (Divi, WPBakery, Avada…) : mise en page simplifiée'));
        $this->ctx->issues[] = __('Mise en page d’un constructeur de pages simplifiée : vérifiez l’ordre et la présentation.');
        $text = (string) preg_replace_callback('#\[(et_pb_image|et_pb_fullwidth_image)\b([^\]]*)\]#i', static function ($m) {
            $a = self::attrs($m[2]);
            return !empty($a['src']) ? "\n\n<p><img src=\"" . e($a['src']) . '" alt="' . e($a['alt'] ?? '') . "\"></p>\n\n" : '';
        }, $text);
        $text = (string) preg_replace_callback('#\[vc_single_image\b([^\]]*)\]#i', function ($m) {
            $a = self::attrs($m[1]);
            $id = $this->ctx->mediaId((int) ($a['image'] ?? 0));
            $media = $id ? Media::find($id) : null;
            return $media ? "\n\n<p><img src=\"" . e(Media::url($media)) . '" alt="' . e((string) $media['alt']) . "\"></p>\n\n" : '';
        }, $text);
        $text = (string) preg_replace_callback('#\[(et_pb_video|vc_video|fusion_youtube|fusion_vimeo)\b([^\]]*)\]#i', static function ($m) {
            $a = self::attrs($m[2]);
            $url = (string) ($a['src'] ?? ($a['link'] ?? ($a['id'] ?? '')));
            if ($url !== '' && !str_contains($url, '/') && strtolower($m[1]) === 'fusion_youtube') {
                $url = 'https://www.youtube.com/watch?v=' . $url;
            }
            return $url !== '' ? "\n\n" . $url . "\n\n" : '';
        }, $text);
        return (string) preg_replace('#\[/?(?:et_pb_|vc_|fusion_|av_|cs_|mk_|ux_|tatsu_|themify_)[\w-]*\b[^\]]*\]#i', "\n", $text);
    }

    /** Signale les codes courts restants (laissés tels quels dans le texte). */
    private function reportShortcodes(string $text): void
    {
        if (!preg_match_all('#\[([a-z][a-z0-9_-]*)((?:\s+[\w-]+=)[^\]]*|\s[^\]]*)?\]#i', $text, $mm, PREG_SET_ORDER)) {
            return;
        }
        $found = [];
        foreach ($mm as $m) {
            $tag = strtolower($m[1]);
            $hasAttrs = isset($m[2]) && preg_match('/[\w-]+=/', $m[2]);
            if ($hasAttrs || str_contains($text, '[/' . $m[1] . ']') || preg_match('/[_-]/', $tag)) {
                $found[$tag] = true;
            }
        }
        foreach (array_keys($found) as $tag) {
            $this->ctx->job()->warn('shortcode', __('Codes courts non convertis (laissés dans le texte)'), '[' . $tag . ']');
            $this->ctx->issues[] = __('Code court [{t}] non converti : il s’affiche tel quel.', ['t' => $tag]);
        }
    }

    /** Attributs d'un code court (équivalent de shortcode_parse_atts). */
    public static function attrs(string $text): array
    {
        $out = [];
        $text = (string) preg_replace('/[\x{00a0}\x{200b}]+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = strtr($text, ['“' => '"', '”' => '"', '″' => '"', '‘' => "'", '’' => "'"]);
        if (preg_match_all('/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/', $text, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                if (($m[1] ?? '') !== '') {
                    $out[strtolower($m[1])] = $m[2];
                } elseif (($m[3] ?? '') !== '') {
                    $out[strtolower($m[3])] = $m[4];
                } elseif (($m[5] ?? '') !== '') {
                    $out[strtolower($m[5])] = $m[6];
                } elseif (($m[7] ?? '') !== '') {
                    $out[] = $m[7];
                } elseif (($m[8] ?? '') !== '') {
                    $out[] = $m[8];
                } elseif (($m[9] ?? '') !== '') {
                    $out[] = $m[9];
                }
            }
        }
        return $out;
    }

    /** Code court transformé en bloc. */
    private function shortcode(array $t): void
    {
        $a = $t['attrs'];
        switch ($t['tag']) {
            case 'gallery':
                $ids = [];
                foreach (array_filter(array_map('trim', explode(',', (string) ($a['ids'] ?? ($a['include'] ?? ''))))) as $wpId) {
                    if ($id = $this->ctx->mediaId((int) $wpId)) {
                        $ids[] = $id;
                    }
                }
                if (!$ids) {
                    $this->ctx->issues[] = __('Galerie [gallery] sans liste d’images : à recréer.');
                    $this->ctx->job()->warn('shortcode', __('Galeries [gallery] sans images identifiables'));
                    return;
                }
                $this->pushGallery($ids, (int) ($a['columns'] ?? 3));
                return;
            case 'embed':
                $this->embed(trim(strip_tags((string) $t['inner'])));
                return;
            case 'video':
                $src = '';
                foreach (['src', 'mp4', 'webm', 'm4v', 'ogv', 'mov'] as $k) {
                    if (!empty($a[$k])) {
                        $src = (string) $a[$k];
                        break;
                    }
                }
                if ($src === '') {
                    return;
                }
                if (Blocks::youtubeId($src) !== null || Blocks::vimeoId($src) !== null) {
                    $this->embed($src);
                    return;
                }
                $media = $this->mediaRow(0, $src);
                $poster = !empty($a['poster']) ? $this->mediaRow(0, (string) $a['poster']) : null;
                $this->push(Blocks::make('video', ['url' => $media ? '/media/' . $media['path'] : $this->ctx->rewriteUrl($src, true), 'poster' => $poster ? (int) $poster['id'] : null]));
                return;
            case 'audio':
                $src = (string) ($a['src'] ?? ($a['mp3'] ?? ($a['m4a'] ?? ($a['ogg'] ?? ($a['wav'] ?? '')))));
                if ($src !== '') {
                    $this->html('<p><audio controls preload="none" src="' . e($src) . '"></audio></p>');
                }
                return;
            case 'playlist':
                $this->ctx->issues[] = __('Liste de lecture [playlist] non convertie.');
                $this->ctx->job()->warn('shortcode', __('Codes courts non convertis (laissés dans le texte)'), '[playlist]');
                return;
        }
        // Formulaires de contact (Contact Form 7, WPForms, Gravity Forms…)
        $title = $this->stealHeading();
        $this->push(Blocks::make('contact', ['title' => $title]));
        $this->ctx->issues[] = __('Formulaire [{t}] remplacé par le formulaire de contact Wordless : vérifiez les champs et l’adresse de réception.', ['t' => $t['tag']]);
        $this->ctx->job()->warn('form', __('Formulaires remplacés par le formulaire de contact Wordless'), '[' . $t['tag'] . ']');
    }

    // ------------------------------------------------------------------
    // HTML déjà rendu (API REST publique, texte de secours d'Elementor)
    // ------------------------------------------------------------------

    public function rendered(string $html): void
    {
        if (trim($html) === '') {
            return;
        }
        [, $root] = Html::load($html);
        $this->renderedChildren($root);
    }

    private function renderedChildren(DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $n) {
            $children[] = $n;
        }
        foreach ($children as $n) {
            if ($n instanceof DOMText) {
                if (trim($n->wholeText) !== '') {
                    $this->richText($n->wholeText, true);
                }
                continue;
            }
            if (!$n instanceof DOMElement) {
                continue;
            }
            $tag = strtolower($n->tagName);
            $class = ' ' . preg_replace('/\s+/', ' ', $n->getAttribute('class')) . ' ';
            $outer = (string) $n->ownerDocument->saveHTML($n);
            $is = static fn (string $c): bool => str_contains($class, ' ' . $c . ' ');

            if ($tag === 'figure' && ($is('wp-block-image') || ($n->getElementsByTagName('img')->length === 1 && !$is('wp-block-gallery')))) {
                $p = self::figureParts($outer);
                $wpId = preg_match('/wp-image-(\d+)/', $outer, $m) ? (int) $m[1] : 0;
                $this->push($this->image($wpId, $p['src'], $p['alt'], $p['caption'], $p['href'], $is('alignwide') ? 'wide' : ($is('alignfull') ? 'full' : 'content')));
            } elseif ($is('wp-block-gallery') || $is('gallery') || $is('tiled-gallery')) {
                $ids = [];
                foreach ($n->getElementsByTagName('img') as $img) {
                    $wpId = preg_match('/wp-image-(\d+)/', $img->getAttribute('class'), $m) ? (int) $m[1] : (int) $img->getAttribute('data-id');
                    $media = $this->mediaRow($wpId, $img->getAttribute('src'));
                    if ($media) {
                        $ids[] = (int) $media['id'];
                    }
                }
                $cols = preg_match('/columns-(\d)/', $class, $m) ? (int) $m[1] : (preg_match('/gallery-columns-(\d)/', $class, $m) ? (int) $m[1] : 3);
                $ids ? $this->pushGallery($ids, $cols) : $this->html($outer);
            } elseif ($is('wp-block-embed')) {
                $iframe = $n->getElementsByTagName('iframe')->item(0);
                $url = $iframe instanceof DOMElement ? $iframe->getAttribute('src') : (preg_match('#https?://[^\s<>"]+#', $n->textContent, $m) ? $m[0] : '');
                $this->embed($url, ['innerHTML' => $outer]);
            } elseif ($is('wp-block-latest-posts')) {
                $this->push(Blocks::make('posts', ['title' => $this->stealHeading(), 'count' => max(1, min(12, $n->getElementsByTagName('li')->length ?: 3)), 'category' => null, 'layout' => 'list']));
            } elseif ($tag === 'form' || $is('wpcf7') || $is('wpforms-container') || $is('gform_wrapper') || $is('nf-form-cont') || $is('frm_forms') || $is('fluentform')) {
                $this->push(Blocks::make('contact', ['title' => $this->stealHeading()]));
                $this->ctx->issues[] = __('Formulaire remplacé par le formulaire de contact Wordless : vérifiez les champs et l’adresse de réception.');
                $this->ctx->job()->warn('form', __('Formulaires remplacés par le formulaire de contact Wordless'), trim($class) !== '' ? trim(explode(' ', trim($class))[0]) : $tag);
            } elseif ($is('wp-block-media-text')) {
                $img = $n->getElementsByTagName('img')->item(0);
                $media = $img instanceof DOMElement ? $this->mediaRow(preg_match('/wp-image-(\d+)/', $img->getAttribute('class'), $mm) ? (int) $mm[1] : 0, $img->getAttribute('src')) : null;
                $text = null;
                foreach ($n->childNodes as $c) {
                    if ($c instanceof DOMElement && str_contains($c->getAttribute('class'), 'wp-block-media-text__content')) {
                        $text = $this->renderedColumn($c);
                    }
                }
                $items = array_values(array_filter([['media' => $media ? (int) $media['id'] : null, 'title' => '', 'text' => ''], $text], static fn ($i) => $i && ($i['media'] || $i['title'] !== '' || $i['text'] !== '')));
                if ($is('has-media-on-the-right')) {
                    $items = array_reverse($items);
                }
                count($items) === 2 ? $this->push(Blocks::make('columns', ['items' => $items])) : $this->renderedChildren($n);
            } elseif ($tag === 'iframe' && (Blocks::youtubeId($n->getAttribute('src')) !== null || Blocks::vimeoId($n->getAttribute('src')) !== null)) {
                $this->embed($n->getAttribute('src'));
            } elseif ($tag === 'p' && $this->renderedVideoParagraph($n)) {
                continue;
            } elseif ($is('wp-block-buttons')) {
                foreach ($n->getElementsByTagName('a') as $a) {
                    $outline = $a->parentNode instanceof DOMElement && str_contains($a->parentNode->getAttribute('class'), 'is-style-outline');
                    $label = plain_text(Html::inner($a));
                    if ($label !== '') {
                        $this->push(Blocks::make('button', ['label' => $label, 'url' => $this->ctx->rewriteUrl($a->getAttribute('href'), true), 'style' => $outline ? 'secondary' : 'primary']));
                    }
                }
            } elseif ($is('schema-faq') || $is('wp-block-yoast-faq-block') || $is('rank-math-block') || $is('wp-block-rank-math-faq-block')) {
                $items = self::faqItems($outer);
                $items ? $this->pushFaq($items) : $this->html($outer);
            } elseif ($is('wp-block-columns')) {
                $items = [];
                foreach ($n->childNodes as $col) {
                    if ($col instanceof DOMElement && str_contains(' ' . $col->getAttribute('class') . ' ', ' wp-block-column ')) {
                        $item = $this->renderedColumn($col);
                        if ($item) {
                            $items[] = $item;
                        }
                    }
                }
                if (count($items) >= 2) {
                    foreach (array_chunk($items, 4) as $chunk) {
                        $this->push(Blocks::make('columns', ['items' => $chunk]));
                    }
                } else {
                    $this->renderedChildren($n);
                }
            } elseif ($is('wp-block-cover')) {
                $img = $n->getElementsByTagName('img')->item(0);
                $media = $img instanceof DOMElement ? $this->mediaRow(preg_match('/wp-image-(\d+)/', $img->getAttribute('class'), $m) ? (int) $m[1] : 0, $img->getAttribute('src')) : null;
                $h = null;
                foreach (['h1', 'h2', 'h3'] as $ht) {
                    $h = $h ?? $n->getElementsByTagName($ht)->item(0);
                }
                $text = '';
                foreach ($n->getElementsByTagName('p') as $p) {
                    $text .= $n->ownerDocument->saveHTML($p);
                }
                $this->push(Blocks::make('hero', [
                    'title' => $h ? plain_text(Html::inner($h)) : '', 'text' => $this->inline($text),
                    'media' => $media ? (int) $media['id'] : null, 'layout' => $media ? 'cover' : 'center', 'tone' => $media ? 'default' : 'soft',
                ]));
            } elseif (in_array($tag, ['div', 'section', 'article', 'main', 'header', 'footer', 'aside', 'span'], true)) {
                $this->renderedChildren($n);
            } elseif (str_contains($outer, '[')) {
                $this->richText($outer, false); // codes courts restés visibles (extension désactivée)
            } else {
                $this->html($outer);
            }
        }
    }

    /** Paragraphe ne contenant qu'un lien YouTube/Vimeo (intégration non résolue) => Vidéo. */
    private function renderedVideoParagraph(DOMElement $p): bool
    {
        $text = trim($p->textContent);
        if (!preg_match('#^https?://\S+$#', $text) || (Blocks::youtubeId($text) === null && Blocks::vimeoId($text) === null)) {
            return false;
        }
        $this->embed($text);
        return true;
    }

    private function renderedColumn(DOMElement $col): ?array
    {
        $item = ['media' => null, 'title' => '', 'text' => ''];
        $img = $col->getElementsByTagName('img')->item(0);
        if ($img instanceof DOMElement) {
            $m = $this->mediaRow(preg_match('/wp-image-(\d+)/', $img->getAttribute('class'), $mm) ? (int) $mm[1] : 0, $img->getAttribute('src'));
            $item['media'] = $m ? (int) $m['id'] : null;
        }
        $html = '';
        foreach ($col->childNodes as $c) {
            if ($c instanceof DOMElement && preg_match('/^h[1-6]$/', strtolower($c->tagName)) && $item['title'] === '' && trim(plain_text($html)) === '') {
                $item['title'] = plain_text(Html::inner($c));
                continue;
            }
            if ($c instanceof DOMElement && strtolower($c->tagName) === 'figure') {
                continue;
            }
            $html .= (string) $col->ownerDocument->saveHTML($c);
        }
        $item['text'] = $this->inline($html);
        return $item['media'] === null && $item['title'] === '' && trim(plain_text($item['text'])) === '' ? null : $item;
    }
}
