<?php
declare(strict_types=1);

namespace Wordless\Migrate;

use Wordless\Blocks;
use Wordless\Html;
use Wordless\Media;

/**
 * Conversion des pages Elementor (méta _elementor_data, JSON) en blocs Wordless.
 *
 * - Section ou conteneur avec image de fond et titre => Bannière (image en fond).
 * - Section/conteneur à plusieurs colonnes de contenu simple (titre, texte,
 *   image, liste, bouton) => Colonnes.
 * - Sinon, les éléments sont repris dans l'ordre de lecture.
 * Widgets : titre, éditeur de texte, image, bouton, vidéo, liste d'icônes,
 * témoignage, galerie, accordéon/bascule (=> FAQ), séparateur, espaceur, HTML,
 * code court, et quelques widgets Pro courants (citation, appel à l'action…).
 */
final class Elementor
{
    /** Widgets acceptés dans des « colonnes simples ». */
    private const SIMPLE = ['heading', 'text-editor', 'image', 'icon-list', 'button', 'spacer', 'divider'];

    public function __construct(private Convert $c)
    {
    }

    /** Décode _elementor_data (JSON, éventuellement échappé par wp_slash). */
    public static function decode(string $raw): ?array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $data = json_decode(stripslashes($raw), true);
        }
        return is_array($data) && $data ? $data : null;
    }

    public function convert(array $elements): void
    {
        foreach ($elements as $el) {
            if (is_array($el)) {
                $this->element($el);
            }
        }
    }

    private function element(array $el): void
    {
        $type = (string) ($el['elType'] ?? '');
        if ($type === 'widget') {
            $this->widget($el);
            return;
        }
        $children = array_values(array_filter((array) ($el['elements'] ?? []), 'is_array'));
        if ($hero = $this->hero($el)) {
            $this->c->push($hero);
            return;
        }
        $cols = $this->columnsOf($el);
        if ($cols !== null && $this->simpleColumns($cols)) {
            $items = [];
            foreach ($cols as $col) {
                if ($item = $this->columnItem($col)) {
                    $items[] = $item;
                }
            }
            if (count($items) >= 2) {
                foreach (array_chunk($items, 4) as $chunk) {
                    $this->c->push(Blocks::make('columns', ['items' => $chunk]));
                }
                return;
            }
        }
        foreach ($children as $child) {
            $this->element($child);
        }
    }

    /** Colonnes d'une section (colonnes) ou d'un conteneur en ligne (sous-conteneurs). */
    private function columnsOf(array $el): ?array
    {
        $children = array_values(array_filter((array) ($el['elements'] ?? []), 'is_array'));
        if (count($children) < 2) {
            return null;
        }
        $type = (string) ($el['elType'] ?? '');
        if ($type === 'section' && count(array_filter($children, static fn ($c) => ($c['elType'] ?? '') === 'column')) === count($children)) {
            return $children;
        }
        $dir = (string) ($el['settings']['flex_direction'] ?? '');
        if ($type === 'container' && in_array($dir, ['row', 'row-reverse'], true)
            && count(array_filter($children, static fn ($c) => ($c['elType'] ?? '') === 'container')) === count($children)) {
            return $children;
        }
        return null;
    }

    /** @return list<array> widgets d'un élément (récursif) */
    private static function widgets(array $el): array
    {
        if (($el['elType'] ?? '') === 'widget') {
            return [$el];
        }
        $out = [];
        foreach ((array) ($el['elements'] ?? []) as $child) {
            if (is_array($child)) {
                array_push($out, ...self::widgets($child));
            }
        }
        return $out;
    }

    private function simpleColumns(array $cols): bool
    {
        foreach ($cols as $col) {
            $images = 0;
            foreach (self::widgets($col) as $w) {
                $wt = (string) ($w['widgetType'] ?? '');
                if (!in_array($wt, self::SIMPLE, true)) {
                    return false;
                }
                $images += $wt === 'image' ? 1 : 0;
            }
            if ($images > 1) {
                return false;
            }
        }
        return true;
    }

    private function columnItem(array $col): ?array
    {
        $item = ['media' => null, 'title' => '', 'text' => ''];
        $html = '';
        foreach (self::widgets($col) as $w) {
            $s = (array) ($w['settings'] ?? []);
            switch ((string) ($w['widgetType'] ?? '')) {
                case 'image':
                    $m = $this->c->mediaRow((int) ($s['image']['id'] ?? 0), (string) ($s['image']['url'] ?? ''));
                    $item['media'] = $m ? (int) $m['id'] : $item['media'];
                    break;
                case 'heading':
                    if ($item['title'] === '' && trim(plain_text($html)) === '') {
                        $item['title'] = plain_text((string) ($s['title'] ?? ''));
                    } else {
                        $html .= '<p><strong>' . e(plain_text((string) ($s['title'] ?? ''))) . '</strong></p>';
                    }
                    break;
                case 'text-editor':
                    $html .= self::paragraphs((string) ($s['editor'] ?? ''));
                    break;
                case 'icon-list':
                    $html .= $this->iconList($s);
                    break;
                case 'button':
                    $label = plain_text((string) ($s['text'] ?? ''));
                    if ($label !== '') {
                        $html .= '<p><a href="' . e((string) ($s['link']['url'] ?? '#')) . '">' . e($label) . '</a></p>';
                    }
                    break;
            }
        }
        $item['text'] = $this->c->inline($html);
        return $item['media'] === null && $item['title'] === '' && trim(plain_text($item['text'])) === '' ? null : $item;
    }

    /** Section/conteneur avec image de fond et un titre => Bannière (image en fond). */
    private function hero(array $el): ?array
    {
        $s = (array) ($el['settings'] ?? []);
        $bg = (array) ($s['background_image'] ?? []);
        if (empty($bg['url']) && empty($bg['id'])) {
            return null;
        }
        $widgets = self::widgets($el);
        if (!$widgets || count($widgets) > 6) {
            return null;
        }
        $title = '';
        $text = '';
        $buttons = [];
        foreach ($widgets as $w) {
            $ws = (array) ($w['settings'] ?? []);
            switch ((string) ($w['widgetType'] ?? '')) {
                case 'heading':
                    if ($title === '') {
                        $title = plain_text((string) ($ws['title'] ?? ''));
                    } else {
                        $text .= '<p>' . e(plain_text((string) ($ws['title'] ?? ''))) . '</p>';
                    }
                    break;
                case 'text-editor':
                    $text .= self::paragraphs((string) ($ws['editor'] ?? ''));
                    break;
                case 'button':
                    $buttons[] = ['label' => plain_text((string) ($ws['text'] ?? '')), 'url' => $this->c->ctx()->rewriteUrl((string) ($ws['link']['url'] ?? ''), true)];
                    break;
                case 'spacer':
                case 'divider':
                    break;
                default:
                    return null; // contenu plus riche : pas une simple bannière
            }
        }
        if ($title === '') {
            return null;
        }
        $m = $this->c->mediaRow((int) ($bg['id'] ?? 0), (string) ($bg['url'] ?? ''));
        return Blocks::make('hero', [
            'title' => $title, 'text' => $this->c->inline($text),
            'button' => $buttons[0]['label'] ?? '', 'url' => $buttons[0]['url'] ?? '',
            'button2' => $buttons[1]['label'] ?? '', 'url2' => $buttons[1]['url'] ?? '',
            'media' => $m ? (int) $m['id'] : null, 'layout' => $m ? 'cover' : 'center', 'tone' => $m ? 'default' : 'soft',
        ]);
    }

    /** Texte d'éditeur : paragraphes automatiques s'il n'y a pas de balises de bloc. */
    private static function paragraphs(string $html): string
    {
        return preg_match('#<(p|div|h[1-6]|ul|ol|table|blockquote|figure)\b#i', $html) ? $html : Html::autop($html);
    }

    private function iconList(array $s): string
    {
        $items = '';
        foreach ((array) ($s['icon_list'] ?? []) as $it) {
            $text = trim((string) ($it['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $url = (string) ($it['link']['url'] ?? '');
            $items .= '<li>' . ($url !== '' ? '<a href="' . e($url) . '">' . e(plain_text($text)) . '</a>' : e(plain_text($text))) . '</li>';
        }
        return $items !== '' ? '<ul>' . $items . '</ul>' : '';
    }

    private function widget(array $w): void
    {
        $s = (array) ($w['settings'] ?? []);
        $type = (string) ($w['widgetType'] ?? '');
        $ctx = $this->c->ctx();
        switch ($type) {
            case 'heading':
                $title = trim((string) ($s['title'] ?? ''));
                if ($title === '') {
                    return;
                }
                $tag = preg_match('/^h[1-6]$/', (string) ($s['header_size'] ?? '')) ? (string) $s['header_size'] : 'h2';
                $url = (string) ($s['link']['url'] ?? '');
                $inner = $url !== '' ? '<a href="' . e($url) . '">' . $title . '</a>' : $title;
                $this->c->html('<' . $tag . '>' . $inner . '</' . $tag . '>');
                return;
            case 'text-editor':
                $this->c->richText(self::paragraphs((string) ($s['editor'] ?? '')), false);
                return;
            case 'image':
                $img = (array) ($s['image'] ?? []);
                $m = $this->c->mediaRow((int) ($img['id'] ?? 0), (string) ($img['url'] ?? ''));
                $caption = match ((string) ($s['caption_source'] ?? '')) {
                    'custom' => plain_text((string) ($s['caption'] ?? '')),
                    'attachment' => $m ? (string) $m['caption'] : '',
                    default => '',
                };
                $link = match ((string) ($s['link_to'] ?? '')) {
                    'file' => $m ? '/media/' . $m['path'] : (string) ($img['url'] ?? ''),
                    'custom' => (string) ($s['link']['url'] ?? ''),
                    default => '',
                };
                $this->c->push($this->c->image((int) ($img['id'] ?? 0), (string) ($img['url'] ?? ''), (string) ($img['alt'] ?? ''), $caption, $link));
                return;
            case 'button':
                $label = plain_text((string) ($s['text'] ?? ''));
                if ($label !== '') {
                    $this->c->push(Blocks::make('button', [
                        'label' => $label, 'url' => $ctx->rewriteUrl((string) ($s['link']['url'] ?? ''), true),
                        'style' => 'primary', 'align' => ($s['align'] ?? '') === 'center' ? 'center' : 'left',
                    ]));
                }
                return;
            case 'video':
                $url = match ((string) ($s['video_type'] ?? 'youtube')) {
                    'vimeo' => (string) ($s['vimeo_url'] ?? ''),
                    'dailymotion' => (string) ($s['dailymotion_url'] ?? ''),
                    'hosted' => (string) ($s['hosted_url']['url'] ?? ($s['external_url']['url'] ?? '')),
                    default => (string) ($s['youtube_url'] ?? ''),
                };
                if ($url === '') {
                    return;
                }
                if (($s['video_type'] ?? '') === 'hosted') {
                    $m = $this->c->mediaRow((int) ($s['hosted_url']['id'] ?? 0), $url);
                    $this->c->push(Blocks::make('video', ['url' => $m ? '/media/' . $m['path'] : $ctx->rewriteUrl($url, true)]));
                    return;
                }
                $this->c->embed($url);
                return;
            case 'icon-list':
                $this->c->html($this->iconList($s));
                return;
            case 'testimonial':
                $this->testimonial([
                    'quote' => plain_text((string) ($s['testimonial_content'] ?? '')),
                    'name' => plain_text((string) ($s['testimonial_name'] ?? '')),
                    'role' => plain_text((string) ($s['testimonial_job'] ?? '')),
                    'image' => (array) ($s['testimonial_image'] ?? []),
                ]);
                return;
            case 'testimonial-carousel':
            case 'reviews':
                foreach ((array) ($s['slides'] ?? []) as $slide) {
                    $this->testimonial([
                        'quote' => plain_text((string) ($slide['content'] ?? '')), 'name' => plain_text((string) ($slide['name'] ?? '')),
                        'role' => plain_text((string) ($slide['title'] ?? '')), 'image' => (array) ($slide['image'] ?? []),
                    ]);
                }
                return;
            case 'image-gallery':
            case 'gallery':
            case 'image-carousel':
                $list = (array) ($s['wp_gallery'] ?? ($s['gallery'] ?? ($s['carousel'] ?? [])));
                $ids = [];
                foreach ($list as $img) {
                    $m = $this->c->mediaRow((int) ($img['id'] ?? 0), (string) ($img['url'] ?? ''));
                    if ($m) {
                        $ids[] = (int) $m['id'];
                    }
                }
                $this->c->pushGallery($ids, (int) ($s['gallery_columns'] ?? ($s['columns'] ?? 3)));
                return;
            case 'accordion':
            case 'toggle':
                $items = [];
                foreach ((array) ($s['tabs'] ?? []) as $tab) {
                    $items[] = ['q' => plain_text((string) ($tab['tab_title'] ?? '')), 'a' => (string) ($tab['tab_content'] ?? '')];
                }
                $this->c->pushFaq($items);
                return;
            case 'nested-accordion':
                $items = [];
                foreach ((array) ($s['items'] ?? []) as $i => $it) {
                    $answer = '';
                    foreach (self::widgets((array) ($w['elements'][$i] ?? [])) as $inner) {
                        $answer .= self::paragraphs((string) ($inner['settings']['editor'] ?? ''));
                    }
                    $items[] = ['q' => plain_text((string) ($it['item_title'] ?? '')), 'a' => $answer];
                }
                $this->c->pushFaq($items);
                return;
            case 'tabs':
                foreach ((array) ($s['tabs'] ?? []) as $tab) {
                    $this->c->html('<h3>' . e(plain_text((string) ($tab['tab_title'] ?? ''))) . '</h3>' . self::paragraphs((string) ($tab['tab_content'] ?? '')));
                }
                return;
            case 'divider':
                $this->c->push(Blocks::make('divider', ['style' => 'line']));
                return;
            case 'spacer':
            case 'menu-anchor':
                return;
            case 'html':
                $this->c->htmlBlock((string) ($s['html'] ?? ''));
                return;
            case 'shortcode':
                $this->c->richText((string) ($s['shortcode'] ?? ''), true);
                return;
            case 'text-path':
                $this->c->html('<p>' . e(plain_text((string) ($s['text'] ?? ''))) . '</p>');
                return;
            case 'icon-box':
            case 'image-box':
                if ($type === 'image-box' && !empty($s['image'])) {
                    $this->c->push($this->c->image((int) ($s['image']['id'] ?? 0), (string) ($s['image']['url'] ?? ''), '', '', ''));
                }
                $this->c->html('<h3>' . e(plain_text((string) ($s['title_text'] ?? ''))) . '</h3>' . self::paragraphs((string) ($s['description_text'] ?? '')));
                return;
            case 'counter':
                $value = trim((string) ($s['prefix'] ?? '') . (string) ($s['ending_number'] ?? '') . (string) ($s['suffix'] ?? ''));
                $label = plain_text((string) ($s['title'] ?? ''));
                $last = &$this->c->last();
                if ($last !== null && $last['type'] === 'stats' && count($last['data']['items']) < 6) {
                    $last['data']['items'][] = ['value' => $value, 'label' => $label, 'source' => ''];
                } else {
                    unset($last);
                    $this->c->push(Blocks::make('stats', ['items' => [['value' => $value, 'label' => $label, 'source' => '']]]));
                }
                return;
            case 'blockquote':
                $this->c->push(Blocks::make('quote', ['text' => plain_text((string) ($s['blockquote_content'] ?? '')), 'author' => plain_text((string) ($s['author_name'] ?? ''))]));
                return;
            case 'call-to-action':
                $this->c->push(Blocks::make('cta', [
                    'title' => plain_text((string) ($s['title'] ?? '')), 'text' => $this->c->inline(self::paragraphs((string) ($s['description'] ?? ''))),
                    'button' => plain_text((string) ($s['button'] ?? '')), 'url' => $ctx->rewriteUrl((string) ($s['link']['url'] ?? ''), true),
                ]));
                return;
            case 'alert':
                $this->c->html('<p><strong>' . e(plain_text((string) ($s['alert_title'] ?? ''))) . '</strong></p>' . self::paragraphs((string) ($s['alert_description'] ?? '')));
                return;
            case 'social-icons':
                $items = '';
                foreach ((array) ($s['social_icon_list'] ?? []) as $it) {
                    $url = (string) ($it['link']['url'] ?? '');
                    if ($url !== '') {
                        $name = preg_replace('/^(fab|fas|far)\s+fa-/', '', (string) ($it['social_icon']['value'] ?? ($it['social'] ?? '')));
                        $items .= '<li><a href="' . e($url) . '">' . e(ucfirst((string) $name) ?: $url) . '</a></li>';
                    }
                }
                if ($items !== '') {
                    $this->c->html('<ul>' . $items . '</ul>');
                }
                return;
            case 'form':
                $this->c->push(Blocks::make('contact', ['title' => $this->c->stealHeading()]));
                $ctx->issues[] = __('Formulaire Elementor remplacé par le formulaire de contact Wordless : vérifiez les champs.');
                $ctx->job()->warn('form', __('Formulaires remplacés par le formulaire de contact Wordless'), 'elementor/form');
                return;
            case 'google_maps':
                $ctx->issues[] = __('Carte Google Maps non reprise : ajoutez un lien ou une image de plan.');
                $ctx->job()->warn('elementor', __('Widgets Elementor non convertis'), $type);
                return;
        }
        // Widget inconnu : on garde au moins son texte s'il en a un.
        $text = (string) ($s['editor'] ?? ($s['title'] ?? ($s['text'] ?? ($s['description'] ?? ''))));
        $ctx->job()->warn('elementor', __('Widgets Elementor non convertis'), $type);
        if (trim(plain_text($text)) !== '') {
            $this->c->html(self::paragraphs($text));
            $ctx->issues[] = __('Widget Elementor « {w} » simplifié en texte.', ['w' => $type]);
        } else {
            $ctx->issues[] = __('Widget Elementor « {w} » non converti.', ['w' => $type]);
        }
    }

    /** Témoignage : regroupé avec le précédent s'il suit directement. */
    private function testimonial(array $t): void
    {
        if ($t['quote'] === '') {
            return;
        }
        $m = !empty($t['image']) ? $this->c->mediaRow((int) ($t['image']['id'] ?? 0), (string) ($t['image']['url'] ?? '')) : null;
        $item = ['quote' => $t['quote'], 'name' => $t['name'], 'role' => $t['role'], 'media' => $m && Media::isImage($m) ? (int) $m['id'] : null];
        $last = &$this->c->last();
        if ($last !== null && $last['type'] === 'testimonials' && count($last['data']['items']) < 12) {
            $last['data']['items'][] = $item;
            return;
        }
        unset($last);
        $this->c->push(Blocks::make('testimonials', ['title' => $this->c->stealHeading(), 'items' => [$item]]));
    }
}
