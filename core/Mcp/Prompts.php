<?php
declare(strict_types=1);

namespace Wordless\Mcp;

use Wordless\Settings;

/**
 * Invites MCP prêtes à l'emploi (proposées par Claude/ChatGPT comme raccourcis) :
 * rédiger un article, améliorer une page, revoir le design. Chaque invite dit
 * au modèle quels outils utiliser, dans quel ordre, et joint les consignes du site.
 */
final class Prompts
{
    /** @return array<string, array{title: string, description: string, arguments: list<array>}> */
    private static function defs(): array
    {
        return [
            'write_article' => [
                'title' => 'Write an article (SEO + GEO)',
                'description' => 'Research the site, write a new article optimized for search engines and AI assistants, and save it as a draft.',
                'arguments' => [
                    ['name' => 'topic', 'description' => 'What the article is about.', 'required' => true],
                    ['name' => 'keyword', 'description' => 'Main keyword to rank for (optional: chosen from the topic).', 'required' => false],
                    ['name' => 'audience', 'description' => 'Who it is for (optional: from the site guidelines).', 'required' => false],
                ],
            ],
            'improve_page' => [
                'title' => 'Improve a page',
                'description' => 'Audit an existing article or page (SEO, GEO, readability) and propose then apply improvements.',
                'arguments' => [
                    ['name' => 'id', 'description' => 'Content ID or URL of the page.', 'required' => true],
                ],
            ],
            'design_review' => [
                'title' => 'Design review',
                'description' => 'Review the site design (colors, contrast, typography, rhythm) and propose improvements.',
                'arguments' => [
                    ['name' => 'target', 'description' => 'What to review or the goal (e.g. "readability", "more premium look", "home page"). Optional.', 'required' => false],
                ],
            ],
        ];
    }

    public static function listing(): array
    {
        $out = [];
        foreach (self::defs() as $name => $def) {
            $out[] = ['name' => $name] + $def;
        }
        return $out;
    }

    public static function get(\stdClass $params): array
    {
        $name = $params->name ?? null;
        if (!is_string($name) || $name === '') {
            throw new RpcError(-32602, 'Invalid params: name is required');
        }
        $def = self::defs()[$name] ?? null;
        if ($def === null) {
            throw new RpcError(-32602, 'Unknown prompt: ' . substr($name, 0, 100));
        }
        $given = $params->arguments ?? new \stdClass();
        $args = [];
        foreach ($def['arguments'] as $arg) {
            $v = $given instanceof \stdClass ? ($given->{$arg['name']} ?? null) : null;
            $v = is_scalar($v) ? trim(mb_substr((string) $v, 0, 2000)) : '';
            if ($arg['required'] && $v === '') {
                throw new RpcError(-32602, 'Missing required argument: ' . $arg['name']);
            }
            $args[$arg['name']] = $v;
        }
        $text = match ($name) {
            'write_article' => self::writeArticle($args),
            'improve_page' => self::improvePage($args),
            default => self::designReview($args),
        };
        $messages = [['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]]];
        $resource = $name === 'design_review' ? Resources::DESIGN : Resources::GUIDELINES;
        $messages[] = ['role' => 'user', 'content' => ['type' => 'resource', 'resource' => [
            'uri' => $resource,
            'mimeType' => 'text/markdown',
            'text' => $name === 'design_review' ? Resources::designSystem() : Resources::guidelines(),
        ]]];
        return ['description' => $def['description'], 'messages' => $messages];
    }

    private static function writeArticle(array $a): string
    {
        $site = (string) Settings::get('site.name', 'Wordless');
        $keyword = $a['keyword'] !== '' ? 'Main keyword: "' . $a['keyword'] . '".' : 'Choose the main keyword people would type to find this topic and tell me which one you chose.';
        $audience = $a['audience'] !== '' ? 'Audience: ' . $a['audience'] . '.' : 'Audience: as described in the site guidelines (attached).';
        return <<<TXT
Write a new article for {$site} about: {$a['topic']}
{$keyword}
{$audience}

Follow these steps:
1. Read the site guidelines attached below (language, brand voice, SEO and GEO rules).
2. Call `search` with the topic: if a page already covers it, tell me and suggest improving it instead (prompt improve_page). Note 2-3 related pages to link to.
3. Call `list_terms` to pick an existing category (create one only if nothing fits).
4. Write the article in Markdown: answer-first introduction (40-60 words), "##" sections phrased as questions where natural, precise and dated figures with their sources (never invent numbers: ask me if needed), lists or a table, a short key-takeaways list, an attributed quote if relevant, internal links, and a final "## FAQ" section of at least 3 "### Question?" headings each followed by its answer. No "# H1" in the body.
5. Call `create_content` with type "post", status "draft", the title, the markdown, seo {title (30-60 characters), description (120-160 characters), keyword}, categories, tags, an excerpt and a short slug.
6. Call `analyze_content` on the new id and fix the important warnings with `update_content`.
7. Give me a short summary, the scores and the admin edit link. Publish only if I ask (`publish_content`).
TXT;
    }

    private static function improvePage(array $a): string
    {
        $ref = ctype_digit($a['id']) ? 'id ' . $a['id'] : 'URL ' . $a['id'];
        $param = ctype_digit($a['id']) ? '`id: ' . $a['id'] . '`' : '`url: "' . $a['id'] . '"`';
        return <<<TXT
Improve the page with {$ref}.

1. Call `get_content` with {$param} (format "blocks" if it uses layout blocks) and `analyze_content` with the same reference.
2. Read the site guidelines attached below.
3. Explain the main problems in plain words, ordered by impact: search intent and keyword, SEO title and meta description, answer-first introduction, question headings, missing facts or sources, FAQ, internal links, image alt texts, readability, outdated information.
4. Propose concrete changes (new title, description, rewritten introduction, sections or FAQ to add) and wait for my agreement before saving.
5. Apply the agreed changes with `update_content` (merge SEO fields; use `append_markdown` to add a section; keep layout blocks by sending blocks). Do not change the slug of a published page unless I ask (a 301 redirect would be created).
6. Run `analyze_content` again and show me the before/after scores.
TXT;
    }

    private static function designReview(array $a): string
    {
        $target = $a['target'] !== '' ? 'Focus: ' . $a['target'] . '.' : 'Focus: the overall look and readability of the site.';
        return <<<TXT
Review the design of the website. {$target}

1. Call `get_design` and read the design system attached below (tokens, contrast ratios, presets, principles).
2. Optionally call `site_overview` to understand the type of site and its audience.
3. Assess: color contrast (WCAG AA 4.5:1 for text), palette harmony and use of the accent color, typography (fonts suited to the brand, base size, scale ratio, line length), spacing and density, corner radius, light or dark scheme.
4. Propose up to 3 improvements, each with the exact token changes (e.g. colors.accent "#1d5bd0", fonts.heading "transitional", ratio 1.25) and why they help. Mention a preset if one fits better.
5. Apply them with `update_design` only after I agree, then report the contrast corrections it made, if any.
TXT;
    }
}
