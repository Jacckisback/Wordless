<?php
declare(strict_types=1);

namespace Wordless\Ai;

use Wordless\Blocks;
use Wordless\Content;
use Wordless\Db;
use Wordless\Html;
use Wordless\Media;
use Wordless\Settings;

/**
 * Assistant de rédaction : contenus optimisés SEO (Google) et GEO (moteurs de
 * réponse IA : ChatGPT, Claude, Perplexity, AI Overviews), sans inventer de faits.
 */
final class Assistant
{
    private const LANGS = ['fr' => 'français', 'en' => 'anglais', 'es' => 'espagnol', 'de' => 'allemand', 'it' => 'italien', 'pt' => 'portugais'];

    public static function system(): string
    {
        $site = (string) Settings::get('site.name');
        $voice = trim((string) Settings::get('ai.brand_voice', ''));
        $tagline = trim((string) Settings::get('site.tagline', ''));
        return "Tu es le rédacteur web expert du site « {$site} »" . ($tagline !== '' ? " ({$tagline})" : '') . ".\n"
            . ($voice !== '' ? "Voix de marque et public :\n{$voice}\n\n" : '')
            . "Règles de rédaction :\n"
            . "- Réponse directe d'abord : le premier paragraphe répond à la question principale en 2 ou 3 phrases (40 à 60 mots), sans préambule.\n"
            . "- Titres H2/H3 formulés comme les questions réelles des lecteurs quand c'est naturel ; un sujet par section.\n"
            . "- Phrases courtes, mots concrets, exemples précis, listes et tableaux quand ils clarifient.\n"
            . "- Données chiffrées, dates et sources nommées : seulement si elles sont fournies ou de notoriété publique et vérifiables. N'invente JAMAIS de chiffre, d'étude, de citation, de témoignage, de prix ni d'URL. S'il manque une donnée utile, écris [à vérifier : …].\n"
            . "- Pas de jargon creux (révolutionnaire, innovant, de pointe, booster, synergie, solutions sur mesure), pas de cadence d'aphorismes (« Pas X. Y. »), tirets cadratins rares.\n"
            . "- Mot-clé principal dans le titre, l'introduction et un intertitre, sans bourrage ; utilise les synonymes.\n"
            . "- Écris pour des humains d'abord : utile, honnête, spécifique au site.\n"
            . "- Markdown simple : ## et ### pour les titres, listes, **gras** rare, liens [texte](url) seulement vers des URL fournies.";
    }

    private static function langName(?string $code = null): string
    {
        // Langue du contenu : celle du site, même si l'administration s'affiche dans une autre langue.
        return self::LANGS[$code ?? (string) Settings::get('language', 'fr')] ?? 'français';
    }

    /** @return array<string, mixed> résultat appliqué par l'éditeur */
    public static function run(string $task, array $in): array
    {
        $state = (array) ($in['state'] ?? []);
        $blocks = Blocks::sanitize((array) ($state['blocks'] ?? []), false);
        $title = trim((string) ($state['title'] ?? ''));
        $keyword = trim((string) ($in['keyword'] ?? $state['seo']['keyword'] ?? ''));
        $md = Blocks::toMarkdown($blocks);
        $onDelta = $in['on_delta'] ?? null;
        return match ($task) {
            'write' => self::write((string) ($in['brief'] ?? ''), $title, $keyword, (string) ($state['type'] ?? 'post'), $onDelta),
            'geo' => self::geo($title, $keyword, $blocks, $md, $onDelta),
            'takeaways' => self::takeaways($title, $md),
            'faq' => self::faq($title, $keyword, $md),
            'seo_meta' => self::seoMeta($title, $keyword, $md),
            'rewrite' => self::rewrite($blocks, (string) ($in['block'] ?? ''), (string) ($in['how'] ?? 'clarify')),
            'alts' => self::alts($blocks),
            'alt' => ['alt' => self::altFor((int) ($in['media_id'] ?? 0), $title)],
            'translate' => self::translate((int) ($state['id'] ?? 0), (string) ($in['lang'] ?? 'en')),
            default => throw new \InvalidArgumentException(__('Action IA inconnue.')),
        };
    }

    private static function articleSchema(): array
    {
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['title', 'seo_title', 'meta_description', 'slug', 'excerpt', 'takeaways', 'body_markdown', 'faq'],
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'Titre H1, clair, avec le mot-clé'],
                'seo_title' => ['type' => 'string', 'description' => '50 à 60 caractères'],
                'meta_description' => ['type' => 'string', 'description' => '120 à 155 caractères, donne envie de cliquer'],
                'slug' => ['type' => 'string', 'description' => '3 à 5 mots, minuscules, tirets'],
                'excerpt' => ['type' => 'string', 'description' => '1 à 2 phrases'],
                'takeaways' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '3 à 5 points clés, une phrase chacun'],
                'body_markdown' => ['type' => 'string', 'description' => 'Corps en Markdown, commençant par la réponse directe, sans titre H1 ni FAQ'],
                'faq' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['question', 'answer'],
                    'properties' => ['question' => ['type' => 'string'], 'answer' => ['type' => 'string', 'description' => '2 à 4 phrases']]]],
            ],
        ];
    }

    private static function toBlocks(array $j): array
    {
        $out = [];
        $points = array_values(array_filter(array_map('trim', (array) ($j['takeaways'] ?? []))));
        if ($points) {
            $out[] = Blocks::make('takeaways', ['items' => array_map(static fn ($p) => ['text' => $p], array_slice($points, 0, 6))]);
        }
        foreach (Blocks::fromMarkdown((string) ($j['body_markdown'] ?? '')) as $b) {
            $out[] = $b;
        }
        $faq = array_values(array_filter((array) ($j['faq'] ?? []), static fn ($f) => trim((string) ($f['question'] ?? '')) !== ''));
        if ($faq) {
            $out[] = Blocks::make('faq', ['title' => __('Questions fréquentes'), 'items' => array_map(static fn ($f) => ['q' => trim((string) $f['question']), 'a' => Html::fromMarkdown((string) $f['answer'])], $faq)]);
        }
        return Blocks::sanitize($out);
    }

    private static function write(string $brief, string $title, string $keyword, string $type, ?callable $onDelta): array
    {
        if (trim($brief) === '' && $title === '') {
            throw new \InvalidArgumentException(__('Décrivez le sujet à traiter.'));
        }
        $links = Db::all("SELECT title, path FROM content WHERE status = 'published' ORDER BY updated_at DESC LIMIT 25");
        $linkList = implode("\n", array_map(static fn ($l) => '- ' . $l['title'] . ' : ' . link_path((string) $l['path']), $links));
        $prompt = "Rédige " . ($type === 'page' ? 'le contenu d’une page' : 'un article de blog') . ' en ' . self::langName() . ".\n"
            . ($title !== '' ? "Titre de travail : {$title}\n" : '')
            . ($keyword !== '' ? "Mot-clé principal : {$keyword}\n" : '')
            . "Brief : " . ($brief !== '' ? $brief : $title) . "\n\n"
            . "Pages existantes du site (pour 2 ou 3 liens internes pertinents, chemins relatifs) :\n{$linkList}\n\n"
            . "Longueur : celle qui couvre le sujet sans remplissage (généralement 900 à 1600 mots). Termine le corps par une courte conclusion actionnable.";
        $res = Client::complete(['system' => self::system(), 'prompt' => $prompt, 'schema' => self::articleSchema(), 'effort' => 'medium', 'max_tokens' => 24000, 'task' => 'write', 'on_delta' => $onDelta]);
        $j = $res['json'];
        return [
            'title' => trim((string) $j['title']),
            'blocks' => self::toBlocks($j),
            'excerpt' => trim((string) $j['excerpt']),
            'seo' => ['title' => trim((string) $j['seo_title']), 'description' => trim((string) $j['meta_description']), 'keyword' => $keyword],
            'slug' => slugify((string) $j['slug']),
        ];
    }

    private static function geo(string $title, string $keyword, array $blocks, string $md, ?callable $onDelta): array
    {
        $simple = ['text', 'heading', 'takeaways', 'faq', 'toc', 'quote', 'steps', 'divider', 'image'];
        $isText = !array_diff(array_unique(array_column($blocks, 'type')), $simple);
        if (!$isText) {
            $res = Client::complete(['system' => self::system(), 'effort' => 'medium', 'max_tokens' => 4000, 'task' => 'geo_advice',
                'prompt' => "Page « {$title} » construite avec des sections. Contenu :\n\n" . mb_substr($md, 0, 30000) . "\n\nDonne 5 améliorations concrètes et priorisées pour qu’elle soit davantage citée par les moteurs de réponse IA (ChatGPT, Perplexity, Gemini, AI Overviews) : réponse directe, questions, FAQ, données, sources, structure. Réponds en " . self::langName() . ', en liste numérotée courte.']);
            return ['note' => $res['text']];
        }
        $prompt = "Réécris ce contenu en " . self::langName() . " pour qu'il soit davantage cité par les moteurs de réponse IA et mieux classé sur Google, SANS perdre d'information ni inventer de faits. "
            . "Garde les liens et les images Markdown existants. Ajoute une réponse directe en introduction, des intertitres en questions, des listes quand c'est utile, 3 à 5 points clés et une FAQ de 4 à 6 questions.\n"
            . "Titre : {$title}\n" . ($keyword !== '' ? "Mot-clé : {$keyword}\n" : '') . "\nContenu actuel :\n" . mb_substr($md, 0, 60000);
        $res = Client::complete(['system' => self::system(), 'prompt' => $prompt, 'schema' => self::articleSchema(), 'effort' => 'medium', 'max_tokens' => 24000, 'task' => 'geo', 'on_delta' => $onDelta]);
        $j = $res['json'];
        return ['blocks' => self::toBlocks($j), 'seo' => ['title' => trim((string) $j['seo_title']), 'description' => trim((string) $j['meta_description'])], 'excerpt' => trim((string) $j['excerpt'])];
    }

    private static function takeaways(string $title, string $md): array
    {
        $res = Client::complete(['system' => self::system(), 'effort' => 'low', 'max_tokens' => 2000, 'task' => 'takeaways',
            'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['points'], 'properties' => ['points' => ['type' => 'array', 'items' => ['type' => 'string']]]],
            'prompt' => "Résume ce contenu en 3 à 5 points clés (une phrase chacun, factuels, en " . self::langName() . ") pour un encadré « L’essentiel ».\nTitre : {$title}\n\n" . mb_substr($md, 0, 40000)]);
        $points = array_values(array_filter(array_map('trim', (array) $res['json']['points'])));
        return ['prepend' => Blocks::sanitize([Blocks::make('takeaways', ['items' => array_map(static fn ($p) => ['text' => $p], array_slice($points, 0, 6))])])];
    }

    private static function faq(string $title, string $keyword, string $md): array
    {
        $res = Client::complete(['system' => self::system(), 'effort' => 'medium', 'max_tokens' => 4000, 'task' => 'faq',
            'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['faq'], 'properties' => ['faq' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['question', 'answer'], 'properties' => ['question' => ['type' => 'string'], 'answer' => ['type' => 'string']]]]]],
            'prompt' => "Propose 4 à 6 questions que les lecteurs posent vraiment sur ce sujet (formulées comme dans un moteur de recherche ou un assistant IA), avec des réponses directes de 2 à 4 phrases, fondées uniquement sur le contenu ci-dessous ou des faits notoires. Langue : " . self::langName() . ".\nTitre : {$title}\n" . ($keyword !== '' ? "Mot-clé : {$keyword}\n" : '') . "\n" . mb_substr($md, 0, 40000)]);
        $items = array_map(static fn ($f) => ['q' => trim((string) $f['question']), 'a' => Html::fromMarkdown((string) $f['answer'])], (array) $res['json']['faq']);
        return ['append' => Blocks::sanitize([Blocks::make('faq', ['title' => __('Questions fréquentes'), 'items' => $items])])];
    }

    private static function seoMeta(string $title, string $keyword, string $md): array
    {
        $res = Client::complete(['system' => self::system(), 'effort' => 'low', 'max_tokens' => 1500, 'task' => 'seo_meta',
            'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['seo_title', 'meta_description', 'keyword'], 'properties' => [
                'seo_title' => ['type' => 'string'], 'meta_description' => ['type' => 'string'], 'keyword' => ['type' => 'string']]],
            'prompt' => "Écris en " . self::langName() . " un titre SEO (50 à 60 caractères, mot-clé au début si naturel) et une méta-description (120 à 155 caractères, bénéfice concret + incitation à cliquer, sans guillemets) pour cette page. Propose aussi le mot-clé principal le plus pertinent" . ($keyword !== '' ? " (actuel : {$keyword})" : '') . ".\nTitre de la page : {$title}\n\n" . mb_substr($md, 0, 20000)]);
        $j = $res['json'];
        return ['seo' => ['title' => mb_substr(trim((string) $j['seo_title']), 0, 160), 'description' => mb_substr(trim((string) $j['meta_description']), 0, 400), 'keyword' => $keyword !== '' ? $keyword : trim((string) $j['keyword'])]];
    }

    // ------------------------------------------------------------------
    // Réécriture et traduction bloc par bloc (structure conservée)
    // ------------------------------------------------------------------

    /** Aplatit les champs texte d'un bloc : [{path, value, html}]. */
    public static function flatten(array $block): array
    {
        $def = Blocks::registry()[$block['type']] ?? null;
        if (!$def) {
            return [];
        }
        $out = [];
        $walk = static function (array $fields, array $data, string $prefix) use (&$walk, &$out): void {
            foreach ($fields as $name => $f) {
                $v = $data[$name] ?? null;
                $path = $prefix . $name;
                if (in_array($f['type'], ['text', 'textarea', 'rich'], true) && is_string($v) && trim($v) !== '' && $name !== 'anchor') {
                    $out[] = ['path' => $path, 'value' => $v, 'html' => $f['type'] === 'rich'];
                } elseif ($f['type'] === 'list' && is_array($v)) {
                    foreach ($v as $i => $item) {
                        $walk($f['fields'], (array) $item, $path . '.' . $i . '.');
                    }
                }
            }
        };
        $walk($def['fields'], (array) $block['data'], '');
        return $out;
    }

    private static function inject(array $block, array $pairs): array
    {
        foreach ($pairs as $p) {
            $keys = explode('.', (string) $p['path']);
            $ref = &$block['data'];
            foreach ($keys as $k) {
                if (!is_array($ref) || !array_key_exists($k, $ref)) {
                    unset($ref);
                    continue 2;
                }
                $ref = &$ref[$k];
            }
            if (is_string($ref)) {
                $ref = (string) $p['value'];
            }
            unset($ref);
        }
        return $block;
    }

    private static function pairsSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => ['items'], 'properties' => ['items' => ['type' => 'array', 'items' => [
            'type' => 'object', 'additionalProperties' => false, 'required' => ['path', 'value'], 'properties' => ['path' => ['type' => 'string'], 'value' => ['type' => 'string']]]]]];
    }

    private static function rewrite(array $blocks, string $id, string $how): array
    {
        $block = null;
        foreach ($blocks as $b) {
            if ($b['id'] === $id) {
                $block = $b;
            }
        }
        if (!$block) {
            throw new \InvalidArgumentException(__('Bloc introuvable.'));
        }
        $pairs = self::flatten($block);
        if (!$pairs) {
            throw new \InvalidArgumentException(__('Ce bloc ne contient pas de texte à réécrire.'));
        }
        $instruction = [
            'clarify' => 'plus clair, plus direct et plus concret',
            'shorten' => 'environ deux fois plus court, en gardant l’essentiel',
            'expand' => 'plus développé, avec des précisions utiles (sans inventer de faits)',
            'warm' => 'plus chaleureux et humain',
            'pro' => 'plus professionnel et rassurant',
            'fix' => 'corrigé (orthographe, grammaire, typographie) sans changer le sens',
        ][$how] ?? 'plus clair';
        $res = Client::complete(['system' => self::system(), 'effort' => 'low', 'max_tokens' => 8000, 'task' => 'rewrite', 'schema' => self::pairsSchema(),
            'prompt' => "Réécris chaque valeur pour la rendre {$instruction}. Garde la langue d'origine. Les valeurs marquées html=true contiennent du HTML simple : conserve les balises (p, strong, em, a, ul, li) et les liens. Renvoie exactement les mêmes chemins (path).\n\n" . json_enc($pairs)]);
        return ['block' => Blocks::sanitize([self::inject($block, (array) $res['json']['items'])])[0] ?? $block];
    }

    private static function translate(int $id, string $lang): array
    {
        $c = Content::find($id);
        if (!$c) {
            throw new \InvalidArgumentException(__('Enregistrez d’abord ce contenu.'));
        }
        $target = self::langName($lang);
        $all = [['path' => '_title', 'value' => $c['title'], 'html' => false]];
        foreach (['title', 'description'] as $k) {
            if (trim((string) ($c['seo'][$k] ?? '')) !== '') {
                $all[] = ['path' => '_seo.' . $k, 'value' => $c['seo'][$k], 'html' => false];
            }
        }
        if (trim((string) $c['excerpt']) !== '') {
            $all[] = ['path' => '_excerpt', 'value' => $c['excerpt'], 'html' => false];
        }
        foreach ($c['blocks'] as $b) {
            foreach (self::flatten($b) as $p) {
                $p['path'] = $b['id'] . ':' . $p['path'];
                $all[] = $p;
            }
        }
        $res = Client::complete(['system' => self::system(), 'effort' => 'medium', 'max_tokens' => 32000, 'task' => 'translate', 'schema' => self::pairsSchema(),
            'prompt' => "Traduis chaque valeur en {$target}, avec un style naturel et idiomatique (localise les exemples, unités et formulations, pas de traduction mot à mot). Les valeurs html=true contiennent du HTML : conserve les balises et les URL. Renvoie exactement les mêmes chemins (path).\n\n" . json_enc($all)]);
        $map = [];
        foreach ((array) $res['json']['items'] as $p) {
            $map[(string) $p['path']] = (string) $p['value'];
        }
        $blocks = [];
        foreach ($c['blocks'] as $b) {
            $pairs = [];
            foreach (self::flatten($b) as $p) {
                if (isset($map[$b['id'] . ':' . $p['path']])) {
                    $pairs[] = ['path' => $p['path'], 'value' => $map[$b['id'] . ':' . $p['path']]];
                }
            }
            $copy = self::inject($b, $pairs);
            $copy['id'] = 'b' . bin2hex(random_bytes(5));
            $blocks[] = $copy;
        }
        $group = $c['tgroup'] ?: bin2hex(random_bytes(8));
        if (!$c['tgroup']) {
            Db::update('content', ['tgroup' => $group, 'lang' => $c['lang'] !== '' ? $c['lang'] : (string) Settings::get('language', 'fr')], ['id' => $id]);
        }
        $title = $map['_title'] ?? $c['title'];
        $newId = Content::save([
            'type' => $c['type'], 'status' => 'draft', 'title' => $title, 'slug' => $lang . '-' . slugify($title), 'lang' => $lang,
            'blocks' => $blocks, 'excerpt' => $map['_excerpt'] ?? '', 'featured_id' => $c['featured_id'],
            'seo' => ['title' => $map['_seo.title'] ?? '', 'description' => $map['_seo.description'] ?? '', 'keyword' => ''],
        ], null, ['trusted' => true]);
        Db::update('content', ['tgroup' => $group], ['id' => $newId]);
        return ['translation' => $newId];
    }

    // ------------------------------------------------------------------
    // Textes alternatifs (vision)
    // ------------------------------------------------------------------

    public static function altFor(int $mediaId, string $context = ''): string
    {
        $m = Media::find($mediaId);
        if (!$m || !Media::isImage($m)) {
            throw new \InvalidArgumentException(__('Image introuvable.'));
        }
        $file = WL_MEDIA . '/' . ((string) (($m['variants']['960'] ?? [])[pathinfo($m['path'], PATHINFO_EXTENSION)] ?? $m['path']));
        if (!is_file($file)) {
            $file = WL_MEDIA . '/' . $m['path'];
        }
        $mime = (string) (mime_content_type($file) ?: $m['mime']);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new \InvalidArgumentException(__('Format d’image non pris en charge par l’IA.'));
        }
        $res = Client::complete(['effort' => 'low', 'max_tokens' => 600, 'task' => 'alt', 'images' => [[$mime, base64_encode((string) file_get_contents($file))]],
            'prompt' => "Écris le texte alternatif de cette image en " . self::langName() . " : une phrase de 8 à 20 mots qui décrit ce qu'elle montre d'utile pour comprendre la page" . ($context !== '' ? " « {$context} »" : '') . ". Pas de « image de » ni de guillemets. Réponds uniquement par le texte."]);
        $alt = trim(str_replace(['"', '«', '»'], '', strip_tags($res['text'])));
        $alt = mb_substr($alt, 0, 250);
        if ($alt !== '') {
            Db::update('media', ['alt' => $alt], ['id' => $mediaId]);
        }
        return $alt;
    }

    private static function alts(array $blocks): array
    {
        $done = 0;
        foreach (Blocks::images($blocks) as $img) {
            if (!empty($img['id']) && trim((string) $img['alt']) === '' && empty($img['decorative']) && $done < 10) {
                self::altFor((int) $img['id']);
                $done++;
            }
        }
        \Wordless\Cache::purge();
        return ['alts' => $done];
    }
}
