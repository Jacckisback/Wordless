<?php
declare(strict_types=1);

namespace Wordless\Mcp;

use Wordless\Color;
use Wordless\Config;
use Wordless\Db;
use Wordless\Settings;
use Wordless\Theme;

/**
 * Ressources MCP : consignes éditoriales du site (SEO + GEO, ton de la marque)
 * et système de design. Contenu en anglais (lu par le modèle), sauf ce que
 * l'administrateur a lui-même écrit (ton de la marque, noms de catégories).
 */
final class Resources
{
    public const GUIDELINES = 'wordless://site/guidelines';
    public const DESIGN = 'wordless://design/system';

    public static function listing(): array
    {
        return [
            [
                'uri' => self::GUIDELINES,
                'name' => 'site-guidelines',
                'title' => 'Editorial guidelines (SEO & GEO)',
                'description' => 'Who the site is for, the brand voice, and the writing rules that make content rank in search engines and get cited by AI assistants. Read before writing.',
                'mimeType' => 'text/markdown',
            ],
            [
                'uri' => self::DESIGN,
                'name' => 'design-system',
                'title' => 'Design system',
                'description' => 'Current design tokens (colors, fonts, type scale, spacing), contrast ratios and art direction principles. Read before changing the design.',
                'mimeType' => 'text/markdown',
            ],
        ];
    }

    public static function read(\stdClass $params): array
    {
        $uri = $params->uri ?? null;
        if (!is_string($uri) || $uri === '') {
            throw new RpcError(-32602, 'Invalid params: uri is required');
        }
        $text = match ($uri) {
            self::GUIDELINES => self::guidelines(),
            self::DESIGN => self::designSystem(),
            default => throw new RpcError(-32002, 'Resource not found', ['uri' => substr($uri, 0, 300)]),
        };
        return ['contents' => [['uri' => $uri, 'mimeType' => 'text/markdown', 'text' => $text]]];
    }

    public static function guidelines(): string
    {
        $site = (string) Settings::get('site.name', 'Wordless');
        $lang = (string) Settings::get('language', 'fr');
        $language = Server::languageName($lang);
        $tagline = trim((string) Settings::get('site.tagline', ''));
        $about = trim((string) Settings::get('seo.home_description', ''));
        $voice = trim((string) Settings::get('ai.brand_voice', ''));
        $md = "# {$site} — editorial guidelines\n\n";
        $md .= '- Website: ' . Config::baseUrl() . "/\n";
        $md .= "- Language: {$language} (`{$lang}`). Write every page in {$language} unless the user asks otherwise.\n";
        if ($tagline !== '') {
            $md .= '- Tagline: ' . $tagline . "\n";
        }
        if ($about !== '') {
            $md .= '- What the site is about: ' . $about . "\n";
        }
        $md .= "\n## Audience and brand voice\n\n";
        $md .= $voice !== ''
            ? $voice . "\n"
            : "No brand voice has been defined yet. Ask the user who their readers are and which tone they want (formal or friendly, expert or accessible) before writing a long piece, and suggest saving it in the admin (AI settings).\n";

        $md .= <<<'MD'

## Writing content that ranks (SEO)

- One page = one search intent. Before writing, `search` the site: do not create a near-duplicate of an existing page (update it instead).
- Choose one main keyword (the words people actually type) and set it in `seo.keyword`.
- SEO title (`seo.title`): 30-60 characters, keyword first, specific and enticing. Meta description (`seo.description`): 120-160 characters, keyword included, a concrete promise.
- Slug: 3-5 words with the keyword, no stop words.
- Never put a "# H1" in the body: the title is the H1. Use "##" for sections and "###" for sub-sections, without skipping levels; put the keyword in at least one "##".
- Length: at least 600 useful words for an article, 150 for a page. No filler.
- Link to at least one other page of the site with a descriptive anchor (find them with `search`), and link out to authoritative sources.
- Every image needs a descriptive alt text; articles need a featured image (`featured_image_url` or `featured_media_id`).
- Short sentences and paragraphs, concrete words, active voice: readability is scored.

## Writing content that AI assistants cite (GEO)

ChatGPT, Claude, Perplexity, Gemini and Google AI Overviews quote pages that answer clearly and prove what they say:

1. **Answer first**: the first paragraph answers the main question directly in 40-60 words, then the article develops.
2. **Question headings**: phrase several "##"/"###" headings as the questions readers ask ("How much does … cost?").
3. **Facts with sources**: precise, dated statistics with their source and a link ("According to INSEE (2025), …"). Never invent figures: if you are not sure, say so or ask the user.
4. **Structure**: bullet lists, numbered steps, comparison tables; they are extracted as-is by AI engines.
5. **Key takeaways**: a short list of 3-5 points summarizing the page (e.g. a "## Key takeaways" section near the top or the end).
6. **Quotes**: an attributed quote from an expert or a customer (Markdown blockquote: `> quote` then `> — Name, role`).
7. **FAQ**: end with a section titled exactly "## FAQ" (or "## Questions fréquentes"), made only of "### Question?" headings, each directly followed by a 2-3 sentence answer. Wordless turns it into a FAQ block with FAQPage structured data. At least 3 questions.
8. **Freshness and expertise**: mention the date of figures, update old content, and write from real experience (examples, numbers from the business).

## Markdown supported

Paragraphs, `##`/`###`/`####` headings, **bold**, *italic*, [links](https://example.com), bullet and numbered lists, tables, `> quotes`, images `![alt text](url)` (better: `upload_media` first, then use the returned URL), code blocks. Raw HTML is cleaned.

## Workflow

- Create content as a **draft** unless the user explicitly asks to publish or schedule it; then share the admin edit link (`admin_url`).
- After writing, call `analyze_content` and fix the warnings that matter (title length, description, FAQ, sources, internal links, alt texts).
- Pages built with layout blocks (hero, columns, features, call to action…) are edited with `get_content` format "blocks" + `update_content` blocks, never by replacing them with Markdown.
- Never delete or unpublish content, change the design or site settings without the user's explicit agreement.

MD;
        $cats = Db::all("SELECT t.name, t.slug, (SELECT COUNT(*) FROM content_terms ct JOIN content c ON c.id = ct.content_id WHERE ct.term_id = t.id AND c.status = 'published') AS n
            FROM terms t WHERE t.taxonomy = 'category' ORDER BY n DESC, t.name LIMIT 20");
        if ($cats) {
            $md .= "\n## Existing categories\n\n";
            foreach ($cats as $c) {
                $md .= '- ' . $c['name'] . ' (`' . $c['slug'] . '`, ' . (int) $c['n'] . " published)\n";
            }
        }
        $pages = Db::all("SELECT title, path FROM content WHERE type = 'page' AND status = 'published' AND published_at <= ? ORDER BY menu_order, title LIMIT 25", [time()]);
        if ($pages) {
            $md .= "\n## Main pages (for internal links)\n\n";
            foreach ($pages as $p) {
                $md .= '- [' . $p['title'] . '](' . url((string) $p['path']) . ")\n";
            }
        }
        return $md;
    }

    public static function designSystem(): string
    {
        $t = Theme::tokens();
        $c = $t['colors'];
        $font = static fn (string $key): string => (Theme::FONTS[$key]['label'] ?? $key) . ' — `' . (Theme::FONTS[$key]['stack'] ?? '') . '`';
        $md = '# Design system — ' . Settings::get('site.name', 'Wordless') . "\n\n";
        $md .= '- Preset (art direction): **' . ($t['preset'] ?? 'atelier') . '** — ' . ($t['label'] ?? '') . "\n";
        $md .= '- Scheme: ' . ($t['scheme'] ?? 'light') . ', density: ' . ($t['density'] ?? 'normal') . ', corner radius: ' . (int) $t['radius'] . "px\n";
        $md .= '- Typography: base ' . (int) $t['base'] . 'px, scale ratio ' . $t['ratio'] . ', line length ' . (int) $t['measure'] . "ch\n";
        $md .= '- Heading font: ' . $font((string) $t['fonts']['heading']) . "\n";
        $md .= '- Body font: ' . $font((string) $t['fonts']['body']) . "\n\n";
        $md .= "## Colors\n\n| Token | Value | Role |\n| --- | --- | --- |\n";
        $roles = ['bg' => 'Page background', 'surface' => 'Cards, tinted sections', 'text' => 'Body text', 'muted' => 'Secondary text', 'accent' => 'Brand color: buttons, links', 'accent_text' => 'Text on accent buttons', 'line' => 'Borders'];
        foreach ($roles as $k => $role) {
            $md .= '| ' . $k . ' | ' . ($c[$k] ?? '') . ' | ' . $role . " |\n";
        }
        $md .= "\n## Contrast (WCAG 2.x, AA = 4.5:1 for text)\n\n| Pair | Ratio | AA |\n| --- | --- | --- |\n";
        foreach (SiteTools::contrastReport($c) as $r) {
            $md .= '| ' . $r['pair'] . ' | ' . $r['ratio'] . ':1 | ' . ($r['passes'] ? 'yes' : '**no**') . " |\n";
        }
        $md .= "\nLinks use the accent color only when it reaches 4.5:1 on the background (" . (Color::contrast((string) $c['accent'], (string) $c['bg']) >= 4.5 ? 'it does' : 'it does not: links use the text color') . ").\n";
        $md .= "\n## Presets\n\n";
        foreach (Theme::presets() as $key => $p) {
            $md .= '- `' . $key . '`: ' . $p['label'] . ' — ' . $p['description'] . "\n";
        }
        $md .= "\n## Fonts available\n\n" . implode(', ', array_map(static fn ($k) => '`' . $k . '`', array_keys(Theme::FONTS))) . " (system font stacks: no download, instant display).\n";
        $md .= <<<'MD'

## Principles

- Readability first: body text 17-20px, lines of 60-75 characters, contrast ≥ 4.5:1 (update_design corrects colors automatically).
- One accent color, used sparingly for actions; neutral backgrounds; generous spacing.
- At most two font families; serif for editorial sites, humanist or geometric sans for services and shops.
- Change one thing at a time and explain the effect to the user before applying it.

MD;
        $director = 'Wordless\\Ai\\ArtDirector';
        try {
            if (class_exists($director) && method_exists($director, 'principles')) {
                $extra = trim((string) $director::principles());
                if ($extra !== '') {
                    $md .= "\n## Art direction\n\n" . $extra . "\n";
                }
            }
        } catch (\Throwable) {
            // module optionnel : la ressource reste utilisable sans lui
        }
        return $md;
    }
}
