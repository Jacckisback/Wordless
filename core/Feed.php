<?php
declare(strict_types=1);

namespace Wordless;

/** Flux RSS 2.0 (contenu complet, compatible avec les lecteurs et agrégateurs). */
final class Feed
{
    public static function rss(?array $term = null): string
    {
        $q = ['type' => 'post', 'visible' => true, 'limit' => 20];
        if ($term) {
            $q['term'] = (int) $term['id'];
        }
        $posts = Content::query($q);
        $site = (string) Settings::get('site.name');
        $self = $term ? url(($term['taxonomy'] === 'category' ? '/category/' : '/tag/') . $term['slug'] . '/feed/') : url('/feed/');
        $last = $posts ? max(array_map(static fn ($p) => (int) $p['updated_at'], $posts)) : time();
        $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/elements/1.1/">' . "\n<channel>\n"
            . '<title>' . e($site . ($term ? ' – ' . $term['name'] : '')) . "</title>\n"
            . '<link>' . e(Config::baseUrl() . '/') . "</link>\n"
            . '<atom:link href="' . e($self) . '" rel="self" type="application/rss+xml"/>' . "\n"
            . '<description>' . e((string) Settings::get('site.tagline', '')) . "</description>\n"
            . '<language>' . e(str_replace('_', '-', I18n::locale())) . "</language>\n"
            . '<lastBuildDate>' . gmdate(DATE_RSS, $last) . "</lastBuildDate>\n"
            . '<generator>Wordless ' . WL_VERSION . "</generator>\n";
        foreach ($posts as $p) {
            $author = $p['author_id'] ? (string) Db::val('SELECT name FROM users WHERE id = ?', [(int) $p['author_id']]) : '';
            $html = Blocks::render($p['blocks'], ['content' => $p, 'heading_level' => 2]);
            $html = preg_replace('#(src|href)="/(?!/)#', '$1="' . rtrim(Config::baseUrl(), '/') . '/', $html) ?? $html;
            if (Config::basePath() !== '') {
                $html = str_replace(Config::baseUrl() . Config::basePath() . '/', Config::baseUrl() . '/', $html);
            }
            $featured = Media::find($p['featured_id'] ? (int) $p['featured_id'] : null);
            if ($featured) {
                $html = '<p><img src="' . e(Media::absoluteUrl($featured, 960)) . '" alt="' . e($featured['alt']) . '"></p>' . $html;
            }
            $x .= "<item>\n<title>" . e($p['title']) . "</title>\n"
                . '<link>' . e(Content::permalink($p)) . "</link>\n"
                . '<guid isPermaLink="true">' . e(Content::permalink($p)) . "</guid>\n"
                . '<pubDate>' . gmdate(DATE_RSS, (int) $p['published_at']) . "</pubDate>\n"
                . ($author !== '' ? '<dc:creator>' . e($author) . "</dc:creator>\n" : '');
            foreach (Terms::forContent((int) $p['id']) as $t) {
                $x .= '<category>' . e($t['name']) . "</category>\n";
            }
            $x .= '<description>' . e(Content::summary($p, 300)) . "</description>\n"
                . '<content:encoded><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $html) . "]]></content:encoded>\n</item>\n";
        }
        return $x . "</channel>\n</rss>\n";
    }
}
