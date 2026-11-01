<?php
declare(strict_types=1);

namespace Wordless\Mcp;

use Wordless\App;
use Wordless\Auth;
use Wordless\Theme;

/**
 * Outils MCP : définitions (noms, descriptions et schémas en anglais, lus par le
 * modèle), droits requis, annotations (lecture seule / destructif…), validation
 * des arguments et exécution. Seuls les outils permis par le rôle sont listés.
 */
final class Tools
{
    private static ?array $defs = null;

    /** @return array<string, array> */
    public static function defs(): array
    {
        if (self::$defs !== null) {
            return self::$defs;
        }
        $id = ['type' => 'integer', 'minimum' => 1, 'description' => 'Content ID (from search, list_content or get_content).'];
        $ref = [
            'id' => $id,
            'url' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000, 'description' => 'Alternative to id: public URL or path of the page (e.g. "/about" or "https://example.com/about/").'],
        ];
        $seo = static fn (bool $full) => ['type' => 'object', 'additionalProperties' => false, 'description' => 'SEO fields. Only the provided keys are changed.', 'properties' => [
            'title' => ['type' => 'string', 'maxLength' => 160, 'description' => 'SEO title shown in search results: 30-60 characters, main keyword first. Empty = the content title.'],
            'description' => ['type' => 'string', 'maxLength' => 400, 'description' => 'Meta description: 120-160 characters that make people click, with the keyword.'],
            'keyword' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Main keyword or expression this content should rank for.'],
        ] + ($full ? [
            'canonical' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Canonical URL, only when this content duplicates another page. Empty = itself.'],
            'noindex' => ['type' => 'boolean', 'description' => 'true hides this content from search engines.'],
            'schema' => ['type' => 'string', 'enum' => ['', 'Article', 'BlogPosting', 'NewsArticle', 'WebPage', 'AboutPage', 'ContactPage', 'FAQPage', 'Service', 'Product', 'LocalBusiness', 'Event', 'Recipe', 'Course'], 'description' => 'schema.org type for structured data. Empty = automatic.'],
        ] : [])];
        $terms = static fn (string $what) => ['type' => 'array', 'maxItems' => 30, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
            'description' => ucfirst($what) . ' names or numeric IDs. Unknown names are created. Replaces the current list.'];
        $markdown = ['type' => 'string', 'maxLength' => 400000, 'description' => 'Body in Markdown (## sections, ### sub-sections, lists, tables, > quotes, links, images). No "# H1": the title is the H1. A "## FAQ" section made of "### Question?" headings, each followed by its answer, becomes a FAQ block with FAQPage structured data.'];
        $blocks = static fn (string $reference): array => ['type' => 'array', 'maxItems' => 300,
            'description' => 'Alternative to markdown: the full list of page-builder blocks, as returned by get_content with format "blocks". Each item is {"type": ..., "data": {...}} (keep "id" when editing). ' . $reference,
            'items' => ['type' => 'object', 'required' => ['type'], 'properties' => [
                'id' => ['type' => 'string', 'maxLength' => 40],
                'type' => ['type' => 'string', 'maxLength' => 40],
                'data' => ['type' => 'object'],
            ], 'additionalProperties' => false]];
        $publishAt = ['type' => 'string', 'maxLength' => 64, 'description' => 'Publication date-time, ISO 8601 (e.g. "2026-12-01T09:00:00+01:00"; without offset = site timezone). In the future = scheduled.'];
        $featured = [
            'featured_image_url' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 2000, 'description' => 'Public image URL to import into the media library and use as featured image (social sharing preview).'],
            'featured_image_alt' => ['type' => 'string', 'maxLength' => 250, 'description' => 'Alt text for the imported featured image.'],
            'featured_media_id' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Existing media ID to use as featured image (0 removes it).'],
        ];
        $presets = array_keys(Theme::presets());
        $fonts = array_keys(Theme::FONTS);
        $hex = ['type' => 'string', 'pattern' => '^#?([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$'];

        return self::$defs = [
            'site_overview' => [
                'title' => 'Site overview',
                'description' => 'Overview of the website: name, URL, language, content counts, SEO/GEO report (average scores, weak pages, missing descriptions, images without alt text, 404s), 30-day traffic highlights (visitors, channels, AI assistant referrals, AI crawler visits), prioritized issues to fix, and recently updated content. Call this first.',
                'cap' => 'edit_content', 'hints' => [true, false, true, false],
                'schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                'handler' => [SiteTools::class, 'overview'],
            ],
            'search' => [
                'title' => 'Search content',
                'description' => 'Full-text search in the site content (published first, then drafts you can edit). Returns {"results": [{"id", "title", "url", "text"}]}; use fetch with an id to read a result.',
                'cap' => 'edit_content', 'hints' => [true, false, true, false],
                'schema' => ['type' => 'object', 'required' => ['query'], 'additionalProperties' => false, 'properties' => [
                    'query' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Words to search for.'],
                ]],
                'handler' => [ContentTools::class, 'search'],
            ],
            'fetch' => [
                'title' => 'Fetch content',
                'description' => 'Full text (Markdown) of a page or article, by id (from search) or URL. Returns {"id", "title", "text", "url", "metadata"}.',
                'cap' => 'edit_content', 'hints' => [true, false, true, false],
                'schema' => ['type' => 'object', 'required' => ['id'], 'additionalProperties' => false, 'properties' => [
                    'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000, 'description' => 'Content id returned by search, or the page URL.'],
                ]],
                'handler' => [ContentTools::class, 'fetch'],
            ],
            'list_content' => [
                'title' => 'List content',
                'description' => 'List articles and pages with status, URL, dates and SEO/GEO scores. Filter by type, status, category or text; sort by date, title or lowest SEO score (to find pages to improve).',
                'cap' => 'edit_content', 'hints' => [true, false, true, false],
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['any', 'post', 'page'], 'description' => 'post = article, page = static page. Default: any.'],
                    'status' => ['type' => 'string', 'enum' => ['any', 'draft', 'published', 'scheduled', 'private', 'trash'], 'description' => 'Default: any (everything except trash).'],
                    'search' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Text contained in the title or body.'],
                    'category' => ['type' => 'string', 'maxLength' => 120, 'description' => 'Category slug or ID.'],
                    'max_seo_score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100, 'description' => 'Only content whose SEO score is below this value.'],
                    'missing_description' => ['type' => 'boolean', 'description' => 'Only content without a meta description.'],
                    'order' => ['type' => 'string', 'enum' => ['recent', 'updated', 'title', 'seo'], 'description' => 'recent (default), updated, title, or seo (lowest SEO score first).'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 20.'],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'For pagination.'],
                ]],
                'handler' => [ContentTools::class, 'list'],
            ],
            'get_content' => [
                'title' => 'Get content',
                'description' => 'Everything about one article or page: body (Markdown, or page-builder blocks with format "blocks"), SEO fields, effective title and description, categories, tags, featured image, SEO and GEO scores, public and admin URLs.',
                'cap' => 'edit_content', 'hints' => [true, false, true, false],
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $ref + [
                    'format' => ['type' => 'string', 'enum' => ['markdown', 'blocks'], 'description' => 'markdown (default, easy to read) or blocks (exact structure, required to edit pages built with layout blocks).'],
                ]],
                'handler' => [ContentTools::class, 'get'],
            ],
            'create_content' => [
                'title' => 'Create content',
                'description' => 'Create an article (post) or a page from Markdown or blocks, saved as a DRAFT unless status says otherwise. Set SEO fields, categories, tags, excerpt and a featured image. Returns the id, the public URL, the admin edit URL and the SEO/GEO scores with suggestions.',
                'cap' => 'edit_content', 'hints' => [false, false, false, false],
                'schema' => ['type' => 'object', 'required' => ['title'], 'additionalProperties' => false, 'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['post', 'page'], 'description' => 'post (article, default) or page.'],
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300, 'description' => 'Title, displayed as the H1.'],
                    'markdown' => $markdown,
                    'blocks' => $blocks(ContentTools::blockReference()),
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'scheduled', 'private'], 'description' => 'Default: draft. Publish only if the user asked for it.'],
                    'publish_at' => $publishAt,
                    'seo' => $seo(false),
                    'categories' => $terms('category'),
                    'tags' => $terms('tag'),
                    'excerpt' => ['type' => 'string', 'maxLength' => 1000, 'description' => 'Short summary used in lists and as fallback description.'],
                    'slug' => ['type' => 'string', 'maxLength' => 200, 'description' => 'URL slug, 3-5 words with the keyword. Default: from the title.'],
                    'parent_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Parent page ID (pages only).'],
                ] + $featured],
                'handler' => [ContentTools::class, 'create'],
            ],
            'update_content' => [
                'title' => 'Update content',
                'description' => 'Change an existing article or page. Only the provided fields are modified (SEO fields are merged). markdown or blocks REPLACE the whole body: for pages using layout blocks (hero, columns, features, cta…), send blocks from get_content format "blocks", or use append_markdown to add a section. A revision is saved first. Changing the slug of a published page creates a 301 redirect automatically.',
                'cap' => 'edit_content', 'hints' => [false, true, true, false],
                'schema' => ['type' => 'object', 'required' => ['id'], 'additionalProperties' => false, 'properties' => [
                    'id' => $id,
                    'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 300],
                    'markdown' => $markdown,
                    'blocks' => $blocks('Block types and fields: see the blocks parameter of create_content.'),
                    'append_markdown' => ['type' => 'string', 'maxLength' => 200000, 'description' => 'Markdown appended at the end of the existing body (e.g. a FAQ section), keeping the current blocks.'],
                    'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'scheduled', 'private'], 'description' => 'Prefer publish_content to publish.'],
                    'publish_at' => $publishAt,
                    'seo' => $seo(true),
                    'categories' => $terms('category'),
                    'tags' => $terms('tag'),
                    'excerpt' => ['type' => 'string', 'maxLength' => 1000],
                    'slug' => ['type' => 'string', 'maxLength' => 200],
                    'parent_id' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Parent page ID (0 = none).'],
                    'comments' => ['type' => 'boolean', 'description' => 'Allow comments.'],
                ] + $featured],
                'handler' => [ContentTools::class, 'update'],
            ],
            'publish_content' => [
                'title' => 'Publish content',
                'description' => 'Publish a draft now, or schedule it with publish_at (ISO 8601 date in the future). Only when the user asked to publish.',
                'cap' => 'publish', 'hints' => [false, false, true, false],
                'schema' => ['type' => 'object', 'required' => ['id'], 'additionalProperties' => false, 'properties' => [
                    'id' => $id,
                    'publish_at' => $publishAt,
                ]],
                'handler' => [ContentTools::class, 'publish'],
            ],
            'delete_content' => [
                'title' => 'Move content to trash',
                'description' => 'Move an article or page to the trash (it can be restored from the admin). Its URL stops working: suggest a redirect with manage_redirects if it had traffic. Ask the user for confirmation first.',
                'cap' => 'delete_content', 'hints' => [false, true, true, false],
                'schema' => ['type' => 'object', 'required' => ['id'], 'additionalProperties' => false, 'properties' => ['id' => $id]],
                'handler' => [ContentTools::class, 'delete'],
            ],
            'analyze_content' => [
                'title' => 'Analyze SEO & GEO',
                'description' => 'SEO and GEO audit of one article or page: scores (0-100) and every check with its status (good/warn/bad) and a hint to fix it, plus word count and readability. Use after creating or editing content.',
                'cap' => 'edit_content', 'hints' => [true, false, true, false],
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => $ref],
                'handler' => [ContentTools::class, 'analyze'],
            ],
            'list_media' => [
                'title' => 'List media',
                'description' => 'Browse the media library (images and files) with URL, alt text, title and size. Use missing_alt to find images that need an alt text.',
                'cap' => 'upload', 'hints' => [true, false, true, false],
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'search' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Text in the title, alt text or file name.'],
                    'type' => ['type' => 'string', 'enum' => ['any', 'image', 'file'], 'description' => 'Default: any.'],
                    'missing_alt' => ['type' => 'boolean', 'description' => 'Only images without alt text.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 30.'],
                    'offset' => ['type' => 'integer', 'minimum' => 0],
                ]],
                'handler' => [SiteTools::class, 'listMedia'],
            ],
            'upload_media' => [
                'title' => 'Upload media from URL',
                'description' => 'Download a public image or document into the media library (images are resized, converted to WebP and stripped of metadata). Returns the media id, URL and a Markdown snippet to insert it. Always give a descriptive alt text for images.',
                'cap' => 'upload', 'hints' => [false, false, true, true],
                'schema' => ['type' => 'object', 'required' => ['url'], 'additionalProperties' => false, 'properties' => [
                    'url' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 2000, 'description' => 'Public http(s) URL of the file (jpg, png, gif, webp, avif, pdf…).'],
                    'alt' => ['type' => 'string', 'maxLength' => 250, 'description' => 'Alt text describing the image.'],
                    'title' => ['type' => 'string', 'maxLength' => 200],
                    'caption' => ['type' => 'string', 'maxLength' => 500],
                ]],
                'handler' => [SiteTools::class, 'uploadMedia'],
            ],
            'update_media' => [
                'title' => 'Update media',
                'description' => 'Set the alt text, title or caption of a media item (fixes the "image without alt text" SEO issue everywhere it is used).',
                'cap' => 'upload', 'hints' => [false, true, true, false],
                'schema' => ['type' => 'object', 'required' => ['id'], 'additionalProperties' => false, 'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Media ID.'],
                    'alt' => ['type' => 'string', 'maxLength' => 250],
                    'title' => ['type' => 'string', 'maxLength' => 200],
                    'caption' => ['type' => 'string', 'maxLength' => 500],
                ]],
                'handler' => [SiteTools::class, 'updateMedia'],
            ],
            'list_terms' => [
                'title' => 'List categories and tags',
                'description' => 'Categories and tags with their slug, number of published items and URL.',
                'cap' => 'edit_content', 'hints' => [true, false, true, false],
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'taxonomy' => ['type' => 'string', 'enum' => ['any', 'category', 'tag'], 'description' => 'Default: any.'],
                ]],
                'handler' => [SiteTools::class, 'listTerms'],
            ],
            'create_term' => [
                'title' => 'Create category or tag',
                'description' => 'Create a category or a tag (returns the existing one if the slug already exists).',
                'cap' => 'edit_content', 'hints' => [false, false, true, false],
                'schema' => ['type' => 'object', 'required' => ['taxonomy', 'name'], 'additionalProperties' => false, 'properties' => [
                    'taxonomy' => ['type' => 'string', 'enum' => ['category', 'tag']],
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                    'slug' => ['type' => 'string', 'maxLength' => 120],
                    'description' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Shown on the category page (good for SEO).'],
                    'parent_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Parent category ID.'],
                ]],
                'handler' => [SiteTools::class, 'createTerm'],
            ],
            'manage_redirects' => [
                'title' => 'Manage redirects',
                'description' => 'List redirects and the most frequent 404 errors (action "list"), add a 301/302/307/308 redirect or a 410 "gone" (action "add"), or delete one (action "delete"). Use it to fix broken URLs and keep backlinks when a page moves.',
                'cap' => 'manage_seo', 'hints' => [false, true, true, false],
                'schema' => ['type' => 'object', 'required' => ['action'], 'additionalProperties' => false, 'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'add', 'delete']],
                    'source' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Old path or URL on this site (add, delete), e.g. "/old-page".'],
                    'target' => ['type' => 'string', 'maxLength' => 2000, 'description' => 'Destination path on this site or absolute URL (add; not needed for 410).'],
                    'code' => ['type' => 'integer', 'enum' => [301, 302, 307, 308, 410], 'description' => 'Default 301 (permanent).'],
                    'search' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Filter for list.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'For list, default 50.'],
                ]],
                'handler' => [SiteTools::class, 'redirects'],
            ],
            'get_analytics' => [
                'title' => 'Get analytics',
                'description' => 'Cookie-less visit statistics for the last N days: views, unique visitors and change vs the previous period, channels (search, AI assistants, social, referral, direct), top pages, referrers, AI assistant referrals (ChatGPT, Claude, Perplexity…), AI crawler and search engine bot visits, devices, daily series.',
                'cap' => 'view_stats', 'hints' => [true, false, true, false],
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'Period length in days (default 30).'],
                ]],
                'handler' => [SiteTools::class, 'analytics'],
            ],
            'list_comments' => [
                'title' => 'List comments',
                'description' => 'Comments by status (pending by default) with their article, author name and text.',
                'cap' => 'moderate', 'hints' => [true, false, true, false],
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'properties' => [
                    'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'spam', 'trash', 'any'], 'description' => 'Default: pending.'],
                    'content_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Only comments on this content.'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'description' => 'Default 20.'],
                ]],
                'handler' => [SiteTools::class, 'listComments'],
            ],
            'moderate_comment' => [
                'title' => 'Moderate comment',
                'description' => 'Approve, unapprove (back to pending), mark as spam or trash a comment.',
                'cap' => 'moderate', 'hints' => [false, true, true, false],
                'schema' => ['type' => 'object', 'required' => ['id', 'action'], 'additionalProperties' => false, 'properties' => [
                    'id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Comment ID.'],
                    'action' => ['type' => 'string', 'enum' => ['approve', 'unapprove', 'spam', 'trash']],
                ]],
                'handler' => [SiteTools::class, 'moderateComment'],
            ],
            'get_design' => [
                'title' => 'Get design tokens',
                'description' => 'Current design system: preset, colors, fonts, type scale, line length, corner radius, density, light/dark scheme, available presets and fonts, and WCAG contrast ratios of the main color pairs.',
                'cap' => 'manage_design', 'hints' => [true, false, true, false],
                'schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
                'handler' => [SiteTools::class, 'getDesign'],
            ],
            'update_design' => [
                'title' => 'Update design tokens',
                'description' => 'Change the site design: switch preset (resets colors/fonts to the preset unless given), colors (hex), fonts, base font size, type scale ratio, line length, radius, density, scheme. Colors are corrected automatically to keep WCAG AA contrast (text/background, muted/background, accent text/accent ≥ 4.5:1) and the corrections are reported. Describe the change to the user and get their agreement first.',
                'cap' => 'manage_design', 'hints' => [false, true, true, false],
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'minProperties' => 1, 'properties' => [
                    'preset' => ['type' => 'string', 'enum' => $presets, 'description' => 'Ready-made art direction (see get_design).'],
                    'colors' => ['type' => 'object', 'additionalProperties' => false, 'description' => 'Hex colors (#rrggbb).', 'properties' => [
                        'bg' => $hex + ['description' => 'Page background.'],
                        'surface' => $hex + ['description' => 'Cards, tinted sections.'],
                        'text' => $hex + ['description' => 'Body text.'],
                        'muted' => $hex + ['description' => 'Secondary text (dates, captions).'],
                        'accent' => $hex + ['description' => 'Brand color: buttons, links, highlights.'],
                        'accent_text' => $hex + ['description' => 'Text on accent-colored buttons.'],
                        'line' => $hex + ['description' => 'Borders and separators.'],
                    ]],
                    'fonts' => ['type' => 'object', 'additionalProperties' => false, 'description' => 'System font stacks (no download).', 'properties' => [
                        'heading' => ['type' => 'string', 'enum' => $fonts],
                        'body' => ['type' => 'string', 'enum' => $fonts],
                    ]],
                    'base' => ['type' => 'integer', 'minimum' => 15, 'maximum' => 22, 'description' => 'Base font size in px.'],
                    'ratio' => ['type' => 'number', 'minimum' => 1.1, 'maximum' => 1.6, 'description' => 'Type scale ratio (1.2 calm … 1.5 dramatic).'],
                    'measure' => ['type' => 'integer', 'minimum' => 52, 'maximum' => 80, 'description' => 'Max line length in characters (60-75 is comfortable).'],
                    'radius' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 28, 'description' => 'Corner radius in px.'],
                    'density' => ['type' => 'string', 'enum' => ['compact', 'normal', 'airy']],
                    'scheme' => ['type' => 'string', 'enum' => ['light', 'dark']],
                ]],
                'handler' => [SiteTools::class, 'updateDesign'],
            ],
            'update_site_settings' => [
                'title' => 'Update site settings',
                'description' => 'Administrators only: site name, tagline, home page SEO title and description, and which AI crawlers may read the site (robots.txt; blocking GPTBot, ClaudeBot, PerplexityBot… prevents being cited by those assistants).',
                'cap' => 'manage_settings', 'hints' => [false, true, true, false],
                'schema' => ['type' => 'object', 'additionalProperties' => false, 'minProperties' => 1, 'properties' => [
                    'site_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120, 'description' => 'Setting site.name.'],
                    'site_tagline' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Setting site.tagline.'],
                    'home_title' => ['type' => 'string', 'maxLength' => 160, 'description' => 'Setting seo.home_title (SEO title of the home page).'],
                    'home_description' => ['type' => 'string', 'maxLength' => 320, 'description' => 'Setting seo.home_description (meta description of the home page, also used by llms.txt).'],
                    'ai_crawlers' => ['type' => 'object', 'additionalProperties' => ['type' => 'boolean'], 'description' => 'Setting geo.ai_crawlers: {"GPTBot": true, "CCBot": false, …}. true = allowed. Only the given bots change.'],
                ]],
                'handler' => [SiteTools::class, 'updateSettings'],
            ],
        ];
    }

    /** Outils permis à l'utilisateur courant, au format MCP (tools/list). */
    public static function listing(): array
    {
        $out = [];
        foreach (self::defs() as $name => $def) {
            if (!self::allowed($def)) {
                continue;
            }
            [$read, $destructive, $idempotent, $open] = $def['hints'];
            $annotations = ['title' => $def['title'], 'readOnlyHint' => $read];
            if (!$read) {
                $annotations['destructiveHint'] = $destructive;
            }
            $annotations['idempotentHint'] = $idempotent;
            $annotations['openWorldHint'] = $open;
            $out[] = [
                'name' => $name,
                'title' => $def['title'],
                'description' => $def['description'],
                'inputSchema' => Validator::publish($def['schema']),
                'annotations' => $annotations,
            ];
        }
        return $out;
    }

    private static function allowed(array $def): bool
    {
        return $def['cap'] === null || Auth::can((string) $def['cap']);
    }

    /** tools/call : valide les arguments puis exécute. Les échecs reviennent en « isError ». */
    public static function call(\stdClass $params): array
    {
        $name = $params->name ?? null;
        if (!is_string($name) || $name === '') {
            throw new RpcError(-32602, 'Invalid params: name is required');
        }
        $def = self::defs()[$name] ?? null;
        if ($def === null) {
            throw new RpcError(-32602, 'Unknown tool: ' . substr($name, 0, 100));
        }
        if (!self::allowed($def)) {
            return self::failure(sprintf('Permission denied: the connected account (role "%s") cannot use %s.', (string) (Auth::user()['role'] ?? ''), $name));
        }
        $args = $params->arguments ?? new \stdClass();
        if ($args === []) {
            $args = new \stdClass();
        }
        if (!$args instanceof \stdClass) {
            return self::failure('Invalid arguments: "arguments" must be a JSON object.');
        }
        [$clean, $errors] = Validator::validate($args, $def['schema']);
        if ($errors) {
            return self::failure("Invalid arguments:\n- " . implode("\n- ", array_slice($errors, 0, 20)));
        }
        try {
            $out = call_user_func($def['handler'], (array) $clean);
        } catch (ToolError $e) {
            return self::failure($e->getMessage());
        } catch (\PDOException | \Error $e) {
            App::logError($e);
            return self::failure('Internal error while running ' . $name . '. The error was logged on the server; try again or do it from the admin.');
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            return self::failure($e->getMessage()); // erreurs métier du noyau (messages prévus pour l'utilisateur)
        } catch (\Throwable $e) {
            App::logError($e);
            return self::failure('Internal error while running ' . $name . '. The error was logged on the server.');
        }
        return self::success($out);
    }

    /** @param array<string, mixed> $out */
    public static function success(array $out): array
    {
        return [
            'content' => [['type' => 'text', 'text' => Response::encode($out)]],
            'structuredContent' => $out === [] ? new \stdClass() : $out,
            'isError' => false,
        ];
    }

    public static function failure(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }

    /** Raccourci pour les modules : l'utilisateur courant a-t-il ce droit (sur ce contenu) ? Sinon ToolError. */
    public static function need(string $cap, ?array $content = null): void
    {
        $role = (string) (Auth::user()['role'] ?? '');
        if (!Auth::can($cap)) {
            throw new ToolError(sprintf('Permission denied: the connected account (role "%s") lacks the "%s" capability. Ask a site administrator.', $role, $cap));
        }
        if ($content !== null && !Auth::can($cap, $content)) {
            throw new ToolError(sprintf('Permission denied: the connected account (role "%s") can only change its own content, and "%s" (id %d) belongs to another author.', $role, (string) ($content['title'] ?? ''), (int) ($content['id'] ?? 0)));
        }
    }
}
