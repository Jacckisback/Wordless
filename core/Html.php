<?php
declare(strict_types=1);

namespace Wordless;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Outils HTML : nettoyage par liste blanche (anti-XSS), ancres de titres,
 * extraction (titres, liens, images), conversion HTML <-> Markdown, autop.
 */
final class Html
{
    private const DROP = ['script', 'style', 'object', 'embed', 'applet', 'form', 'input', 'button', 'select', 'textarea',
        'noscript', 'template', 'svg', 'math', 'head', 'meta', 'link', 'title', 'base', 'frame', 'frameset', 'canvas', 'dialog'];

    private const PROFILES = [
        'full' => ['p', 'br', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'b', 'i', 'u', 's', 'del', 'ins', 'mark', 'small', 'sub', 'sup',
            'a', 'ul', 'ol', 'li', 'blockquote', 'q', 'cite', 'code', 'pre', 'kbd', 'samp', 'var', 'hr', 'img', 'figure', 'figcaption',
            'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col', 'abbr', 'time', 'dl', 'dt', 'dd',
            'iframe', 'video', 'audio', 'source', 'details', 'summary'],
        'inline' => ['p', 'br', 'strong', 'em', 'b', 'i', 'u', 's', 'mark', 'small', 'sub', 'sup', 'a', 'code', 'ul', 'ol', 'li'],
        'comment' => ['p', 'br', 'strong', 'em', 'b', 'i', 'a', 'code', 'pre', 'blockquote', 'ul', 'ol', 'li'],
    ];

    private const ATTRS = [
        'a' => ['href', 'title', 'rel', 'target'],
        'img' => ['src', 'alt', 'width', 'height', 'title'],
        'ol' => ['start', 'reversed', 'type'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'td' => ['colspan', 'rowspan'],
        'col' => ['span'],
        'abbr' => ['title'],
        'time' => ['datetime'],
        'q' => ['cite'],
        'blockquote' => ['cite'],
        'code' => ['class'],
        'iframe' => ['src', 'title', 'width', 'height', 'allowfullscreen', 'loading'],
        'video' => ['src', 'controls', 'poster', 'width', 'height', 'preload', 'muted', 'loop', 'playsinline'],
        'audio' => ['src', 'controls', 'preload'],
        'source' => ['src', 'type'],
        'details' => ['open'],
    ];

    private const INLINE = ['a', 'strong', 'em', 'b', 'i', 'u', 's', 'del', 'ins', 'mark', 'small', 'sub', 'sup', 'code', 'kbd',
        'samp', 'var', 'abbr', 'time', 'q', 'cite', 'img', 'br', 'span'];

    public const IFRAME_HOSTS = ['www.youtube.com', 'youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com'];

    /** Charge un fragment HTML dans un DOM ; renvoie [document, racine]. */
    public static function load(string $html): array
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = null;
        foreach ($doc->childNodes as $n) {
            if ($n instanceof DOMElement) {
                $root = $n;
                break;
            }
        }
        if (!$root) {
            $root = $doc->createElement('div');
            $doc->appendChild($root);
        }
        return [$doc, $root];
    }

    public static function inner(DOMNode $root): string
    {
        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $root->ownerDocument->saveHTML($child);
        }
        return $out;
    }

    /** Nettoie un HTML non fiable selon un profil (full, inline, comment). */
    public static function clean(string $html, string $profile = 'full'): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }
        [$doc, $root] = self::load($html);
        $allowed = array_flip(self::PROFILES[$profile] ?? self::PROFILES['full']);
        self::walk($root, $allowed, $profile);
        self::wrapLoose($doc, $root);
        $out = self::inner($root);
        $out = preg_replace('#<p>(\s|&nbsp;|<br>)*</p>#u', '', $out) ?? $out;
        return trim($out);
    }

    private static function walk(DOMNode $node, array $allowed, string $profile): void
    {
        $children = [];
        foreach ($node->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $child) {
            if ($child instanceof DOMText) {
                continue;
            }
            if (!$child instanceof DOMElement) {
                $node->removeChild($child); // commentaires, instructions
                continue;
            }
            $tag = strtolower($child->tagName);
            if ($tag === 'h1') {
                $child = self::rename($child, 'h2');
                $tag = 'h2';
            }
            if (in_array($tag, self::DROP, true) || ($tag === 'iframe' && !self::iframeAllowed($child))) {
                $node->removeChild($child);
                continue;
            }
            if (!isset($allowed[$tag])) {
                if ($profile !== 'full' && preg_match('/^h[2-6]$/', $tag)) {
                    $child = self::rename($child, 'p');
                    $tag = 'p';
                } else {
                    self::walk($child, $allowed, $profile);
                    self::unwrap($child);
                    continue;
                }
            }
            self::filterAttributes($child, $tag, $profile);
            self::walk($child, $allowed, $profile);
        }
    }

    private static function iframeAllowed(DOMElement $el): bool
    {
        $host = strtolower((string) parse_url($el->getAttribute('src'), PHP_URL_HOST));
        return in_array($host, self::IFRAME_HOSTS, true);
    }

    private static function filterAttributes(DOMElement $el, string $tag, string $profile): void
    {
        $keep = self::ATTRS[$tag] ?? [];
        $keepId = in_array($tag, ['h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'figure', 'table', 'blockquote', 'section', 'dt'], true);
        $remove = [];
        foreach ($el->attributes as $attr) {
            $name = strtolower($attr->name);
            if ($name === 'id' && $keepId && preg_match('/^[A-Za-z][\w-]{0,80}$/', $attr->value)) {
                continue;
            }
            if ($name === 'class') {
                $classes = array_filter(preg_split('/\s+/', $attr->value) ?: [], static fn ($c) => (bool) preg_match('/^(language-[a-z0-9+#-]+|align-(left|center|right)|wl-[a-z0-9-]+)$/', $c));
                if ($classes) {
                    $el->setAttribute('class', implode(' ', $classes));
                    continue;
                }
                $remove[] = $attr->name;
                continue;
            }
            if (!in_array($name, $keep, true)) {
                $remove[] = $attr->name;
            }
        }
        foreach ($remove as $r) {
            $el->removeAttribute($r);
        }
        foreach (['href', 'src', 'cite', 'poster'] as $u) {
            if ($el->hasAttribute($u)) {
                $safe = self::safeUrl($el->getAttribute($u), $u === 'src' && $tag === 'img');
                if ($safe === '') {
                    $el->removeAttribute($u);
                } else {
                    $el->setAttribute($u, $safe);
                }
            }
        }
        if ($tag === 'a') {
            $rel = array_filter(preg_split('/\s+/', strtolower($el->getAttribute('rel'))) ?: [], static fn ($r) => in_array($r, ['nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc', 'external', 'me'], true));
            if ($el->getAttribute('target') !== '' && $el->getAttribute('target') !== '_blank') {
                $el->removeAttribute('target');
            }
            if ($el->getAttribute('target') === '_blank') {
                $rel[] = 'noopener';
            }
            if ($profile === 'comment') {
                $rel[] = 'nofollow';
                $rel[] = 'ugc';
            }
            $rel = array_unique($rel);
            if ($rel) {
                $el->setAttribute('rel', implode(' ', $rel));
            } else {
                $el->removeAttribute('rel');
            }
        }
        if ($tag === 'img' && !$el->hasAttribute('alt')) {
            $el->setAttribute('alt', '');
        }
        if ($tag === 'iframe') {
            $src = $el->getAttribute('src');
            $el->setAttribute('src', str_replace(['www.youtube.com/embed', 'youtube.com/embed'], 'www.youtube-nocookie.com/embed', $src));
            $el->setAttribute('loading', 'lazy');
        }
    }

    /** Autorise http(s), mailto, tel et les chemins relatifs. Rejette javascript:, data:… */
    public static function safeUrl(string $url, bool $allowDataImage = false): string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $probe = strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', $url) ?? '');
        if ($probe === '') {
            return '';
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/', $probe, $m)) {
            if (in_array($m[1], ['http', 'https', 'mailto', 'tel'], true)) {
                return $url;
            }
            if ($allowDataImage && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $probe)) {
                return $url;
            }
            return '';
        }
        return $url; // relatif : /chemin, #ancre, ?q=, page.html
    }

    private static function rename(DOMElement $el, string $tag): DOMElement
    {
        $new = $el->ownerDocument->createElement($tag);
        foreach ($el->attributes as $a) {
            $new->setAttribute($a->name, $a->value);
        }
        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($new, $el);
        return $new;
    }

    private static function unwrap(DOMElement $el): void
    {
        $parent = $el->parentNode;
        $block = in_array(strtolower($el->tagName), ['div', 'section', 'article', 'header', 'footer', 'main', 'aside', 'center', 'nav'], true);
        if ($block && $el->previousSibling) {
            $parent->insertBefore($el->ownerDocument->createTextNode("\n"), $el);
        }
        while ($el->firstChild) {
            $parent->insertBefore($el->firstChild, $el);
        }
        $parent->removeChild($el);
    }

    /** Regroupe le texte « orphelin » à la racine dans des paragraphes. */
    private static function wrapLoose(DOMDocument $doc, DOMElement $root): void
    {
        $run = [];
        $flush = static function () use (&$run, $doc, $root): void {
            if (!$run) {
                return;
            }
            $text = '';
            $hasMedia = false;
            foreach ($run as $n) {
                $text .= $n->textContent;
                if ($n instanceof DOMElement && in_array(strtolower($n->tagName), ['img', 'a', 'iframe'], true)) {
                    $hasMedia = true;
                }
            }
            if (trim($text) === '' && !$hasMedia) {
                foreach ($run as $n) {
                    if ($n->parentNode === $root && ($n instanceof DOMText)) {
                        continue;
                    }
                }
                $run = [];
                return;
            }
            $p = $doc->createElement('p');
            $root->insertBefore($p, $run[0]);
            foreach ($run as $n) {
                $p->appendChild($n);
            }
            $run = [];
        };
        $children = [];
        foreach ($root->childNodes as $c) {
            $children[] = $c;
        }
        foreach ($children as $c) {
            $inline = $c instanceof DOMText || ($c instanceof DOMElement && in_array(strtolower($c->tagName), self::INLINE, true));
            if ($inline) {
                $run[] = $c;
            } else {
                $flush();
            }
        }
        $flush();
    }

    /** Ajoute un id unique aux titres h2-h4 (ancres stables pour le sommaire). */
    public static function anchorHeadings(string $html, array &$used = []): string
    {
        if (!preg_match('/<h[2-4]\b/i', $html)) {
            return $html;
        }
        [$doc, $root] = self::load($html);
        foreach (['h2', 'h3', 'h4'] as $tag) {
            foreach ($root->getElementsByTagName($tag) as $h) {
                /** @var DOMElement $h */
                $id = $h->getAttribute('id');
                if ($id === '') {
                    $id = slugify($h->textContent, 60) ?: 'section';
                }
                $base = $id;
                $i = 2;
                while (isset($used[$id])) {
                    $id = $base . '-' . $i++;
                }
                $used[$id] = true;
                $h->setAttribute('id', $id);
            }
        }
        return self::inner($root);
    }

    /** @return list<array{level: int, text: string, id: string}> */
    public static function headings(string $html): array
    {
        $out = [];
        if (preg_match_all('#<h([2-6])\b([^>]*)>(.*?)</h\1>#is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $h) {
                $id = preg_match('/\bid="([^"]+)"/', $h[2], $mm) ? $mm[1] : '';
                $out[] = ['level' => (int) $h[1], 'text' => plain_text($h[3]), 'id' => $id];
            }
        }
        return $out;
    }

    /** @return list<array{href: string, text: string}> */
    public static function links(string $html): array
    {
        $out = [];
        if (preg_match_all('#<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $a) {
                $out[] = ['href' => html_entity_decode($a[1]), 'text' => plain_text($a[2])];
            }
        }
        return $out;
    }

    /** @return list<array{src: string, alt: ?string}> */
    public static function images(string $html): array
    {
        $out = [];
        if (preg_match_all('#<img\b([^>]*)>#i', $html, $m)) {
            foreach ($m[1] as $attrs) {
                $src = preg_match('/\bsrc="([^"]*)"/i', $attrs, $s) ? html_entity_decode($s[1]) : '';
                $alt = preg_match('/\balt="([^"]*)"/i', $attrs, $a) ? html_entity_decode($a[1]) : null;
                $out[] = ['src' => $src, 'alt' => $alt];
            }
        }
        return $out;
    }

    /**
     * Équivalent de wpautop : transforme un texte « classique » (sauts de ligne)
     * en paragraphes. Les blocs HTML existants sont respectés.
     */
    public static function autop(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return '';
        }
        $blocks = 'table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|math|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary|iframe|video|audio|object|source';
        $pre = [];
        $text = preg_replace_callback('#<pre\b.*?</pre>#is', static function ($m) use (&$pre) {
            $key = '<!--WLPRE' . count($pre) . '-->';
            $pre[$key] = $m[0];
            return $key;
        }, $text) ?? $text;
        $text = preg_replace('#(<(?:' . $blocks . ')[\s/>])#i', "\n\n$1", $text) ?? $text;
        $text = preg_replace('#(</(?:' . $blocks . ')>)#i', "$1\n\n", $text) ?? $text;
        $paras = preg_split('/\n\s*\n/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = '';
        foreach ($paras as $p) {
            $p = trim($p);
            if ($p === '') {
                continue;
            }
            if (preg_match('#^</?(?:' . $blocks . ')[\s/>]#i', $p) || str_starts_with($p, '<!--WLPRE')) {
                $out .= $p . "\n";
            } else {
                $out .= '<p>' . preg_replace('/\n/', "<br>\n", $p) . "</p>\n";
            }
        }
        return trim(strtr($out, $pre));
    }

    // ------------------------------------------------------------------
    // HTML -> Markdown (versions .md, llms-full.txt, MCP, IA)
    // ------------------------------------------------------------------

    public static function toMarkdown(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        [, $root] = self::load($html);
        $md = self::mdChildren($root, 0);
        $md = preg_replace("/\n{3,}/", "\n\n", $md) ?? $md;
        return trim($md);
    }

    private static function mdChildren(DOMNode $node, int $depth): string
    {
        $out = '';
        foreach ($node->childNodes as $c) {
            $out .= self::mdNode($c, $depth);
        }
        return $out;
    }

    private static function mdInline(DOMNode $node): string
    {
        return trim(preg_replace('/[ \t]*\n[ \t]*/', ' ', self::mdChildren($node, 0)) ?? '');
    }

    private static function mdNode(DOMNode $n, int $depth): string
    {
        if ($n instanceof DOMText) {
            $t = preg_replace('/\s+/u', ' ', $n->wholeText) ?? '';
            return preg_replace('/([*_`\[\]])/', '\\\\$1', $t) ?? $t;
        }
        if (!$n instanceof DOMElement) {
            return '';
        }
        $tag = strtolower($n->tagName);
        switch ($tag) {
            case 'h1': case 'h2': case 'h3': case 'h4': case 'h5': case 'h6':
                return "\n\n" . str_repeat('#', (int) $tag[1]) . ' ' . self::mdInline($n) . "\n\n";
            case 'p':
                return "\n\n" . trim(self::mdChildren($n, $depth)) . "\n\n";
            case 'br':
                return "  \n";
            case 'hr':
                return "\n\n---\n\n";
            case 'strong': case 'b':
                $t = self::mdInline($n);
                return $t === '' ? '' : '**' . $t . '**';
            case 'em': case 'i':
                $t = self::mdInline($n);
                return $t === '' ? '' : '*' . $t . '*';
            case 'code':
                return $n->parentNode instanceof DOMElement && strtolower($n->parentNode->tagName) === 'pre' ? $n->textContent : '`' . $n->textContent . '`';
            case 'pre':
                return "\n\n```\n" . rtrim($n->textContent) . "\n```\n\n";
            case 'a':
                $href = $n->getAttribute('href');
                $t = self::mdInline($n);
                return $href === '' ? $t : '[' . ($t !== '' ? $t : $href) . '](' . self::absUrl($href) . ')';
            case 'img':
                return '![' . $n->getAttribute('alt') . '](' . self::absUrl($n->getAttribute('src')) . ')';
            case 'blockquote':
                $inner = trim(self::mdChildren($n, $depth));
                return "\n\n" . preg_replace('/^/m', '> ', $inner) . "\n\n";
            case 'ul': case 'ol':
                $i = (int) ($n->getAttribute('start') ?: 1);
                $out = $depth === 0 ? "\n\n" : "\n";
                foreach ($n->childNodes as $li) {
                    if (!$li instanceof DOMElement || strtolower($li->tagName) !== 'li') {
                        continue;
                    }
                    $bullet = $tag === 'ol' ? ($i++) . '. ' : '- ';
                    $content = trim(self::mdChildren($li, $depth + 1));
                    $content = preg_replace("/\n(?!\n)/", "\n" . str_repeat(' ', strlen($bullet)), $content) ?? $content;
                    $out .= str_repeat('  ', $depth) . $bullet . $content . "\n";
                }
                return $out . ($depth === 0 ? "\n" : '');
            case 'table':
                return self::mdTable($n);
            case 'figure':
                return "\n\n" . trim(self::mdChildren($n, $depth)) . "\n\n";
            case 'figcaption':
                return "\n*" . self::mdInline($n) . "*\n";
            case 'iframe': case 'video':
                $src = $n->getAttribute('src');
                return $src !== '' ? "\n\n[" . __('Vidéo') . '](' . self::absUrl($src) . ")\n\n" : '';
            case 'details':
                return "\n\n" . trim(self::mdChildren($n, $depth)) . "\n\n";
            case 'summary':
                return '**' . self::mdInline($n) . "**\n\n";
            default:
                return self::mdChildren($n, $depth);
        }
    }

    private static function mdTable(DOMElement $table): string
    {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = str_replace('|', '\|', self::mdInline($cell));
                }
            }
            if ($cells) {
                $rows[] = $cells;
            }
        }
        if (!$rows) {
            return '';
        }
        $cols = max(array_map('count', $rows));
        $out = "\n\n";
        foreach ($rows as $i => $r) {
            $r = array_pad($r, $cols, '');
            $out .= '| ' . implode(' | ', $r) . " |\n";
            if ($i === 0) {
                $out .= '|' . str_repeat(' --- |', $cols) . "\n";
            }
        }
        return $out . "\n";
    }

    private static function absUrl(string $u): string
    {
        if ($u === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $u) || str_starts_with($u, '#')) {
            return $u;
        }
        if (str_starts_with($u, '//')) {
            return 'https:' . $u;
        }
        if (Config::installed() && str_starts_with($u, '/')) {
            $base = Config::baseUrl();
            $basePath = Config::basePath();
            if ($basePath !== '' && str_starts_with($u, $basePath . '/')) {
                $u = substr($u, strlen($basePath));
            }
            return $base . $u;
        }
        return $u;
    }

    // ------------------------------------------------------------------
    // Markdown -> HTML (contenus écrits par l'IA ou envoyés via MCP)
    // ------------------------------------------------------------------

    public static function fromMarkdown(string $md): string
    {
        $md = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $md);
        $lines = explode("\n", $md);
        $html = self::mdBlocks($lines);
        return self::clean($html, 'full');
    }

    private static function mdBlocks(array $lines): string
    {
        $out = '';
        $n = count($lines);
        $i = 0;
        $para = [];
        $flush = static function () use (&$para, &$out): void {
            if ($para) {
                $out .= '<p>' . self::mdInlineToHtml(implode("\n", $para)) . "</p>\n";
                $para = [];
            }
        };
        while ($i < $n) {
            $line = $lines[$i];
            $trim = trim($line);
            if ($trim === '') {
                $flush();
                $i++;
                continue;
            }
            if (preg_match('/^(```|~~~)\s*([\w+#-]*)/', $trim, $m)) {
                $flush();
                $fence = $m[1];
                $lang = $m[2];
                $code = [];
                $i++;
                while ($i < $n && !str_starts_with(trim($lines[$i]), $fence)) {
                    $code[] = $lines[$i];
                    $i++;
                }
                $i++;
                $out .= '<pre><code' . ($lang !== '' ? ' class="language-' . e(strtolower($lang)) . '"' : '') . '>' . e(implode("\n", $code)) . "</code></pre>\n";
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/', $trim, $m)) {
                $flush();
                $level = max(2, strlen($m[1]));
                $out .= "<h$level>" . self::mdInlineToHtml($m[2]) . "</h$level>\n";
                $i++;
                continue;
            }
            if (preg_match('/^(\*\s*\*\s*\*[\s*]*|-\s*-\s*-[\s-]*|_\s*_\s*_[\s_]*)$/', $trim)) {
                $flush();
                $out .= "<hr>\n";
                $i++;
                continue;
            }
            if (str_starts_with($trim, '>')) {
                $flush();
                $quote = [];
                while ($i < $n && str_starts_with(trim($lines[$i]), '>')) {
                    $quote[] = preg_replace('/^\s*>\s?/', '', $lines[$i]);
                    $i++;
                }
                $out .= '<blockquote>' . self::mdBlocks($quote) . "</blockquote>\n";
                continue;
            }
            if (preg_match('/^\|.*\|$/', $trim) && $i + 1 < $n && preg_match('/^\|?\s*:?-{2,}/', trim($lines[$i + 1]))) {
                $flush();
                $rows = [];
                while ($i < $n && preg_match('/^\|.*\|$/', trim($lines[$i]))) {
                    $rows[] = trim($lines[$i]);
                    $i++;
                }
                $out .= self::mdTableToHtml($rows);
                continue;
            }
            if (preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+/', $line)) {
                $flush();
                $list = [];
                while ($i < $n && (preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+/', $lines[$i]) || (trim($lines[$i]) !== '' && preg_match('/^\s{2,}/', $lines[$i])))) {
                    $list[] = $lines[$i];
                    $i++;
                }
                $out .= self::mdList($list);
                continue;
            }
            $para[] = $trim;
            $i++;
        }
        $flush();
        return $out;
    }

    private static function mdList(array $lines): string
    {
        preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+/', $lines[0], $m);
        $indent = strlen($m[1]);
        $ordered = !in_array($m[2], ['-', '*', '+'], true);
        $start = $ordered ? (int) $m[2] : 1;
        $items = [];
        foreach ($lines as $line) {
            if (preg_match('/^(\s*)([-*+]|\d{1,3}[.)])\s+(.*)$/', $line, $mm) && strlen($mm[1]) <= $indent) {
                $items[] = [$mm[3]];
            } elseif ($items) {
                $items[count($items) - 1][] = $line;
            }
        }
        $tag = $ordered ? 'ol' : 'ul';
        $html = '<' . $tag . ($ordered && $start !== 1 ? ' start="' . $start . '"' : '') . '>';
        foreach ($items as $item) {
            $first = array_shift($item);
            $html .= '<li>' . self::mdInlineToHtml($first);
            if ($item) {
                $sub = array_values(array_filter($item, static fn ($l) => trim($l) !== ''));
                if ($sub && preg_match('/^\s*([-*+]|\d{1,3}[.)])\s+/', $sub[0])) {
                    $html .= self::mdList($sub);
                } elseif ($sub) {
                    $html .= '<br>' . self::mdInlineToHtml(implode(' ', array_map('trim', $sub)));
                }
            }
            $html .= '</li>';
        }
        return $html . "</$tag>\n";
    }

    private static function mdTableToHtml(array $rows): string
    {
        $split = static fn (string $r): array => array_map('trim', explode('|', trim(trim($r), '|')));
        $head = $split($rows[0]);
        $html = '<table><thead><tr>';
        foreach ($head as $c) {
            $html .= '<th>' . self::mdInlineToHtml($c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach (array_slice($rows, 2) as $r) {
            $html .= '<tr>';
            foreach ($split($r) as $c) {
                $html .= '<td>' . self::mdInlineToHtml($c) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . "</tbody></table>\n";
    }

    public static function mdInlineToHtml(string $text): string
    {
        $codes = [];
        $text = preg_replace_callback('/`([^`]+)`/', static function ($m) use (&$codes) {
            $codes[] = '<code>' . e($m[1]) . '</code>';
            return "\x01" . (count($codes) - 1) . "\x01";
        }, $text) ?? $text;
        $text = e($text);
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/', static fn ($m) => '<img src="' . $m[2] . '" alt="' . $m[1] . '">', $text) ?? $text;
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+&quot;([^&]*)&quot;)?\)/', static fn ($m) => '<a href="' . $m[2] . '">' . $m[1] . '</a>', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])\*\*(?=\S)(.+?)(?<=\S)\*\*(?![\w*])/u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![\w_])__(?=\S)(.+?)(?<=\S)__(?![\w_])/u', '<strong>$1</strong>', $text) ?? $text;
        $text = preg_replace('/(?<![\w*])\*(?=\S)(.+?)(?<=\S)\*(?![\w*])/u', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/(?<![\w_])_(?=\S)(.+?)(?<=\S)_(?![\w_])/u', '<em>$1</em>', $text) ?? $text;
        $text = preg_replace('/~~(?=\S)(.+?)(?<=\S)~~/u', '<del>$1</del>', $text) ?? $text;
        $text = preg_replace('/ {2,}\n/', "<br>\n", $text) ?? $text;
        $text = preg_replace('/\\\\([*_`\[\]#\\\\])/', '$1', $text) ?? $text;
        return preg_replace_callback("/\x01(\d+)\x01/", static fn ($m) => $codes[(int) $m[1]], $text) ?? $text;
    }
}
