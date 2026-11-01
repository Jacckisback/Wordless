<?php
declare(strict_types=1);

namespace Wordless\Ai;

use Wordless\Blocks;
use Wordless\Color;
use Wordless\Content;
use Wordless\Settings;
use Wordless\Theme;

/**
 * Directeur artistique : principes et règles adaptés d'Impeccable
 * (https://github.com/pbakaus/impeccable, Apache-2.0, voir impeccable/NOTICE),
 * appliqués aux jetons de design du thème et aux pages construites avec les blocs.
 * L'audit fonctionne sans IA ; la direction et la critique utilisent la clé configurée.
 */
final class ArtDirector
{
    /** Principes de direction artistique (consigne système de l'IA, ressource MCP). */
    public static function principles(): string
    {
        return <<<'MD'
# Principes de direction artistique (adaptés d'Impeccable, Apache-2.0)

Travaille comme un directeur artistique primé : un point de vue clair, une compréhension fine de ce dont le client et ses visiteurs ont besoin, et une exécution irréprochable. Le brief l'emporte toujours : respecte les couleurs, polices et références imposées.

## Choisir le mode d'après la page, pas d'après le produit
- **Convaincre** (accueil, page de vente, tarifs) : le visiteur décide et agit. Ambition assumée, un parti pris visuel net.
- **Opérer** (outils, tableaux de bord) : précision et sobriété ; les conventions connues ont leur place.
- **Lire** (articles, guides, documentation) : structure au service de la compréhension, puis une lecture agréable.
- **Montrer** (portfolio, galerie) : l'œuvre mène, l'interface s'efface.

## Plancher de qualité (à vérifier sur le rendu)
- Contraste : texte courant ≥ 4,5:1, grands textes ≥ 3:1. Sur un fond coloré, teinter le texte secondaire avec la couleur du fond, jamais du gris.
- Typographie : 65 à 75 caractères par ligne, une vraie hiérarchie (rapport ≥ 1,25 entre niveaux), interlettrage pas plus serré que -0,04 em, titres équilibrés.
- Espacements : groupes serrés, séparations généreuses, plus d'espace au-dessus d'un titre qu'en dessous.
- Profondeur : une ombre a un décalage et un flou ; un halo coloré sans décalage est une décoration.
- Mouvement : un seul moment mémorable, ralenti exponentiel, jamais d'effet rebond.
- États : survol, focus clavier, désactivé, chargement, erreur, vide.
- Surfaces du navigateur : sélection de texte, curseur, focus, soulignés et chiffres de tableaux thémés depuis la palette.
- Textes : la langue du produit ; un bouton nomme son action ; une erreur dit le problème et la solution.

## À refuser (réflexes de mise en page générée par IA)
- Grilles de cartes identiques « icône + titre + texte » comme structure de page ; cartes imbriquées dans des cartes.
- Le gabarit « gros chiffre + petit libellé + chiffres secondaires » en tête de page.
- Les surtitres (petit libellé en capitales espacées au-dessus d'un titre) : interdits. Le titre porte son propre poids.
- La numérotation des sections (01 / 02 / 03) quand l'ordre n'apporte rien.
- Le texte en dégradé ; le verre dépoli décoratif ; les bordures latérales colorées épaisses ; les ombres pleines décalées hors d'un univers néo-brutaliste.
- Les polices surutilisées comme voix de marque par défaut (Inter, Roboto, Fraunces, Geist, Plus Jakarta Sans, Space Grotesk) ; le titre italique en serif comme accroche de bannière.
- Les palettes réflexes : dégradés violet-bleu, cyan sur fond sombre, fond crème « de bon goût » choisi par habitude.
- Les émojis à la place d'un système d'icônes ; les points qui clignotent pour simuler l'activité ; les défilés automatiques.
- Le jargon marketing creux (révolutionnaire, innovant, de pointe, booster, synergie, solutions sur mesure, world-class, supercharge) et la cadence aphoristique (« Pas un outil. Une plateforme. »).
- Clair ou sombre choisi par catégorie : choisis-le selon la scène d'usage (qui, où, sous quelle lumière).

## Vocabulaire de commandes
- **Plus affirmé** : amplifier un design trop sage (contraste d'échelle, graisse, couleur assumée).
- **Plus sobre** : calmer un design trop bruyant (moins de couleur, plus d'air, moins de graisse).
- **Épurer** : retirer ce dont la page n'a pas besoin.
- **Coloriser** : apporter une couleur stratégique à une interface grise.
- **Typographie** : corriger les polices, la hiérarchie et les tailles.
- **Mise en page** : corriger espacements, rythme et hiérarchie visuelle.
- **Finition** : dernière passe avant publication.
- **Clarifier** : réécrire les textes flous, libellés et messages.
MD;
    }

    /** Polices surutilisées (règle « overused-font »). */
    private const OVERUSED = ['inter', 'roboto', 'fraunces', 'geist', 'plus jakarta sans', 'space grotesk'];

    private const BUZZWORDS = [
        'révolutionnaire', 'révolutionner', 'innovant', 'innovante', 'de pointe', 'à la pointe', 'leader du marché', 'solutions sur mesure', 'solution clé en main',
        'boostez', 'booster', 'propulsez', 'synergie', 'disruptif', 'disruptive', 'next-gen', 'nouvelle génération', 'sans précédent', 'incontournable', 'ultime',
        'cutting-edge', 'world-class', 'best-in-class', 'game-changer', 'game changer', 'empower', 'supercharge', 'streamline', 'seamless', 'enterprise-grade', 'next-generation', 'unleash',
        'revolucionario', 'innovador', 'de vanguardia', 'líder del mercado', 'soluciones a medida', 'potencia tu',
    ];

    // ------------------------------------------------------------------
    // Audit déterministe (sans IA)
    // ------------------------------------------------------------------

    /**
     * @return list<array{id: string, prio: int, title: string, detail: string, fix: string, patch?: array}>
     */
    public static function audit(?array $tokens = null, ?array $blocks = null): array
    {
        $t = $tokens ?? Theme::tokens();
        $c = $t['colors'];
        $f = [];
        $add = static function (string $id, int $prio, string $title, string $detail, string $fix, array $patch = []) use (&$f): void {
            $f[] = compact('id', 'prio', 'title', 'detail', 'fix') + ($patch ? ['patch' => $patch] : []);
        };
        $pairs = [
            ['text', 'bg', 4.5, __('Texte principal sur le fond')],
            ['muted', 'bg', 4.5, __('Texte secondaire sur le fond')],
            ['muted', 'surface', 4.5, __('Texte secondaire sur les sections teintées')],
            ['text', 'surface', 4.5, __('Texte principal sur les sections teintées')],
            ['accent_text', 'accent', 4.5, __('Texte des boutons sur la couleur principale')],
        ];
        foreach ($pairs as [$fg, $bg, $min, $label]) {
            $ratio = Color::contrast($c[$fg], $c[$bg]);
            if ($ratio < $min) {
                $fixed = Color::ensureContrast($c[$fg], $c[$bg], $min);
                $add('low-contrast', $ratio < 3 ? 0 : 1, __('Contraste insuffisant : {l}', ['l' => $label]),
                    __('Rapport {r}:1, minimum {m}:1 (WCAG AA). Une partie des visiteurs ne peut pas lire ce texte.', ['r' => number_format($ratio, 2, ',', ' '), 'm' => $min]),
                    __('Utiliser {h} (même teinte, plus contrastée).', ['h' => $fixed]), ['colors.' . $fg => $fixed]);
            }
        }
        if (Color::contrast($c['accent'], $c['bg']) < 3) {
            $add('weak-accent', 2, __('Couleur principale peu visible sur le fond'), __('Les liens et éléments d’accent ressortent mal (moins de 3:1).'), __('Choisir une couleur principale plus soutenue.'));
        }
        [$L, $C, $H] = Color::oklch($c['accent']);
        if ($C > 0.09 && $H >= 275 && $H <= 320) {
            $add('ai-color-palette', 2, __('Palette violette « générée par IA »'), __('Le violet est devenu la couleur réflexe des sites générés automatiquement : il ne distingue plus votre marque.'), __('Choisir une couleur issue de votre univers (matière, lieu, produit) plutôt qu’une teinte par défaut.'));
        }
        [$bL, $bC, $bH] = Color::oklch($c['bg']);
        if ($bL > 0.93 && $bC > 0.012 && $bC < 0.06 && $bH >= 55 && $bH <= 105) {
            $add('cream-palette', 3, __('Fond crème par réflexe'), __('Le blanc cassé « beige » est devenu le fond par défaut des sites générés : il donne un air daté et sans parti pris.'), __('Partir d’un blanc franc ou d’une teinte tirée de votre univers.'), ['colors.bg' => '#ffffff']);
        }
        if (($t['scheme'] ?? 'light') === 'dark' && $C > 0.1 && $H >= 180 && $H <= 230) {
            $add('cyan-on-dark', 2, __('Cyan sur fond sombre'), __('Combinaison typique des interfaces « tech » générées.'), __('Préférer une couleur chaude ou issue de votre marque.'));
        }
        foreach ((array) ($t['custom_fonts'] ?? []) as $role => $font) {
            if (in_array(mb_strtolower((string) ($font['family'] ?? '')), self::OVERUSED, true)) {
                $add('overused-font', 2, __('Police surutilisée : {f}', ['f' => $font['family']]), __('Cette police est utilisée par une grande partie des sites récents : elle n’apporte plus de personnalité.'), __('Choisir une police dont le caractère correspond à votre métier.'));
            }
        }
        if ((float) $t['ratio'] < 1.2) {
            $add('flat-type-hierarchy', 2, __('Hiérarchie typographique trop plate'), __('Les titres se distinguent à peine du texte (rapport {r}).', ['r' => $t['ratio']]), __('Passer à un rapport de 1,25 ou plus.'), ['ratio' => 1.25]);
        }
        if ((int) $t['base'] < 16) {
            $add('tiny-text', 1, __('Texte trop petit'), __('Corps de {b} px : fatigant à lire, surtout sur mobile.', ['b' => $t['base']]), __('Revenir à 17 ou 18 px.'), ['base' => 18]);
        }
        if ((int) $t['measure'] > 75) {
            $add('line-length', 2, __('Lignes trop longues'), __('{m} caractères par ligne : l’œil perd sa place en revenant à la ligne.', ['m' => $t['measure']]), __('Limiter à 65–72 caractères.'), ['measure' => 68]);
        }
        if ((string) $t['fonts']['heading'] === (string) $t['fonts']['body'] && in_array($t['fonts']['heading'], ['system', 'grotesque'], true)) {
            $add('generic-voice', 3, __('Voix typographique générique'), __('Titres et texte utilisent la police système : le site ressemble à tous les autres.'), __('Donner aux titres une police de caractère (serif, géométrique, industrielle…).'));
        }

        // Pages : textes et structure
        $blocks ??= self::homeBlocks();
        $text = Blocks::text($blocks);
        $lower = mb_strtolower($text);
        $found = array_values(array_filter(self::BUZZWORDS, static fn ($w) => str_contains($lower, $w)));
        if ($found) {
            $add('marketing-buzzword', 2, __('Jargon marketing creux'), __('Expressions relevées : {l}. Elles ne disent rien de précis et signalent un texte générique.', ['l' => implode(', ', array_slice($found, 0, 6))]), __('Remplacer chaque formule par un fait : un chiffre, un délai, un exemple client.'));
        }
        $dashes = substr_count($text, '—') + substr_count($text, ' -- ');
        if ($dashes >= 8 && mb_strlen($text) / max(1, $dashes) < 500) {
            $add('em-dash-overuse', 3, __('Trop de tirets cadratins'), __('{n} tirets « — » : une cadence typique des textes générés.', ['n' => $dashes]), __('Remplacer la plupart par des points ou des virgules.'));
        }
        if (preg_match_all('/(?:^|[.!?]\s+)(?:Pas|Not|No|Ni|Sin)\s[^.!?]{1,40}[.!?]\s+(?:Un|Une|Juste|Just|A|An|Simplement)\s[^.!?]{1,40}[.!?]/u', $text) >= 2) {
            $add('aphoristic-cadence', 3, __('Cadence d’aphorismes'), __('Plusieurs formules du type « Pas un outil. Une plateforme. » : une tournure devenue un tic des textes générés.'), __('Dire simplement ce que fait le produit.'));
        }
        $tones = 0;
        foreach ($blocks as $i => $b) {
            $d = (array) ($b['data'] ?? []);
            if (in_array($d['tone'] ?? '', ['accent', 'dark'], true)) {
                $tones++;
            }
            if ($b['type'] === 'hero' && word_count((string) ($d['title'] ?? '')) > 12) {
                $add('oversized-h1', 2, __('Titre de bannière trop long'), __('Une phrase entière en très grand occupe tout l’écran et se lit mal.'), __('Viser 4 à 9 mots ; le détail va dans le texte d’introduction.'));
            }
            if ($b['type'] === 'features' && ($d['style'] ?? '') === 'grid' && count((array) ($d['items'] ?? [])) >= 3) {
                $lens = array_map(static fn ($it) => mb_strlen((string) ($it['text'] ?? '')), (array) $d['items']);
                if ($lens && max($lens) - min($lens) < 40) {
                    $add('identical-card-grid', 3, __('Grille de cartes identiques'), __('Trois éléments de même taille « titre + texte » : la structure la plus répandue des pages générées.'), __('Mettre en avant le point principal, passer en liste, ou ajouter une preuve (chiffre, photo, client).'));
                }
            }
            if ($b['type'] === 'stats' && $i === 0) {
                $add('hero-metric', 3, __('Gros chiffres en ouverture'), __('Le gabarit « grands chiffres + petits libellés » en tête de page fait générique.'), __('Ouvrir sur la promesse ; placer les chiffres plus bas, avec leur source.'));
            }
            if ($b['type'] === 'image' && empty($d['media'])) {
                $add('broken-image', 1, __('Bloc image vide'), __('Un emplacement d’image sans fichier.'), __('Choisir une image ou supprimer le bloc.'));
            }
        }
        if ($tones > 3) {
            $add('too-many-tones', 3, __('Trop de sections colorées'), __('{n} sections en couleur principale ou en sombre : plus rien ne ressort.', ['n' => $tones]), __('Garder la couleur pour 1 ou 2 moments clés (appel à l’action).'));
        }
        usort($f, static fn ($a, $b) => $a['prio'] <=> $b['prio']);
        return $f;
    }

    private static function homeBlocks(): array
    {
        $home = Content::find((int) Settings::get('home.page_id'));
        return $home['blocks'] ?? [];
    }

    /** Applique les corrections automatiques (contrastes) à des jetons. */
    public static function repair(array $t): array
    {
        $c = &$t['colors'];
        $c['text'] = Color::ensureContrast($c['text'], $c['bg'], 7);
        $c['muted'] = Color::ensureContrast($c['muted'], $c['bg'], 4.6);
        $c['muted'] = Color::ensureContrast($c['muted'], $c['surface'], 4.5);
        if (Color::contrast($c['accent_text'], $c['accent']) < 4.5) {
            $alt = Color::onColor($c['accent']);
            $c['accent_text'] = Color::contrast($alt, $c['accent']) >= 4.5 ? $alt : $c['accent_text'];
            if (Color::contrast($c['accent_text'], $c['accent']) < 4.5) {
                $c['accent'] = Color::ensureContrast($c['accent'], $c['accent_text'], 4.5);
            }
        }
        return $t;
    }

    /** Commandes sans IA : plus affirmé, plus sobre, épurer. */
    public static function command(string $cmd, array $t): array
    {
        [$L, $C, $H] = Color::oklch($t['colors']['accent']);
        switch ($cmd) {
            case 'bolder':
                $t['ratio'] = min(1.5, round((float) $t['ratio'] + 0.08, 3));
                $t['heading_weight'] = min(900, (int) $t['heading_weight'] + 100);
                $t['tracking'] = max(-0.035, (float) $t['tracking'] - 0.01);
                $t['colors']['accent'] = Color::fromOklch($L, min(0.3, $C * 1.25 + 0.01), $H);
                break;
            case 'quieter':
                $t['ratio'] = max(1.2, round((float) $t['ratio'] - 0.06, 3));
                $t['heading_weight'] = max(500, (int) $t['heading_weight'] - 100);
                $t['density'] = 'airy';
                $t['colors']['accent'] = Color::fromOklch($L, $C * 0.72, $H);
                $t['colors']['surface'] = Color::mix($t['colors']['surface'], $t['colors']['bg'], 0.6);
                break;
            case 'distill':
                $t['density'] = 'airy';
                $t['radius'] = max(0, (int) $t['radius'] - 4);
                $t['colors']['surface'] = Color::mix($t['colors']['surface'], $t['colors']['bg'], 0.5);
                $t['colors']['line'] = Color::mix($t['colors']['line'], $t['colors']['bg'], 0.6);
                break;
        }
        return self::repair($t);
    }

    // ------------------------------------------------------------------
    // Avec l'IA
    // ------------------------------------------------------------------

    private static function tokenSchema(): array
    {
        $hex = ['type' => 'string', 'description' => 'Couleur hexadécimale #rrggbb'];
        return [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['name', 'rationale', 'scheme', 'colors', 'fonts', 'base', 'ratio', 'measure', 'radius', 'density', 'heading_weight', 'tracking', 'header'],
            'properties' => [
                'name' => ['type' => 'string'],
                'rationale' => ['type' => 'string', 'description' => 'Pourquoi cette direction, en 3 à 5 phrases, liée au brief.'],
                'scheme' => ['type' => 'string', 'enum' => ['light', 'dark']],
                'colors' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['bg', 'surface', 'text', 'muted', 'accent', 'accent_text', 'line'],
                    'properties' => ['bg' => $hex, 'surface' => $hex, 'text' => $hex, 'muted' => $hex, 'accent' => $hex, 'accent_text' => $hex, 'line' => $hex]],
                'fonts' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['heading', 'body'],
                    'properties' => ['heading' => ['type' => 'string', 'enum' => array_keys(Theme::FONTS)], 'body' => ['type' => 'string', 'enum' => array_keys(Theme::FONTS)]]],
                'base' => ['type' => 'integer', 'description' => 'Taille du texte en px, 16 à 21'],
                'ratio' => ['type' => 'number', 'description' => 'Rapport de l’échelle typographique, 1.2 à 1.5'],
                'measure' => ['type' => 'integer', 'description' => 'Longueur de ligne en caractères, 60 à 75'],
                'radius' => ['type' => 'integer', 'description' => 'Arrondi des angles en px, 0 à 24'],
                'density' => ['type' => 'string', 'enum' => ['compact', 'normal', 'airy']],
                'heading_weight' => ['type' => 'integer', 'description' => '400 à 900'],
                'tracking' => ['type' => 'number', 'description' => 'Interlettrage des titres en em, -0.04 à 0.02'],
                'header' => ['type' => 'string', 'enum' => ['split', 'center']],
            ],
        ];
    }

    /** Propose une direction artistique complète à partir d'un brief. */
    public static function direction(string $brief): array
    {
        $fonts = implode("\n", array_map(static fn ($k, $f) => '- ' . $k . ' : ' . $f['label'] . ' (' . $f['stack'] . ')', array_keys(Theme::FONTS), Theme::FONTS));
        $site = (string) Settings::get('site.name');
        $prompt = "Site : « {$site} ».\nBrief du client :\n{$brief}\n\nPolices disponibles (piles système, aucun téléchargement) :\n{$fonts}\n\n"
            . "Propose UNE direction artistique pour ce site : palette de 7 couleurs (fond, surface teintée, texte, texte secondaire, couleur principale, texte sur la couleur principale, filets), "
            . "polices des titres et du texte, échelle et rythme. Choisis clair ou sombre selon la scène d'usage des visiteurs. Évite les réflexes listés dans tes principes. "
            . "Contrastes WCAG AA obligatoires. Réponds dans la langue du brief.";
        $res = Client::complete(['system' => self::principles(), 'prompt' => $prompt, 'schema' => self::tokenSchema(), 'effort' => 'high', 'max_tokens' => 8000, 'task' => 'design_direction']);
        $d = $res['json'];
        $t = Theme::tokens();
        foreach (['bg', 'surface', 'text', 'muted', 'accent', 'accent_text', 'line'] as $k) {
            if (isset($d['colors'][$k]) && Color::valid((string) $d['colors'][$k])) {
                $t['colors'][$k] = '#' . ltrim(strtolower((string) $d['colors'][$k]), '#');
            }
        }
        foreach (['heading', 'body'] as $k) {
            if (isset(Theme::FONTS[$d['fonts'][$k] ?? ''])) {
                $t['fonts'][$k] = $d['fonts'][$k];
            }
        }
        $t['scheme'] = ($d['scheme'] ?? 'light') === 'dark' ? 'dark' : 'light';
        $t['base'] = max(16, min(21, (int) ($d['base'] ?? 18)));
        $t['ratio'] = max(1.2, min(1.5, (float) ($d['ratio'] ?? 1.25)));
        $t['measure'] = max(60, min(75, (int) ($d['measure'] ?? 68)));
        $t['radius'] = max(0, min(24, (int) ($d['radius'] ?? 8)));
        $t['density'] = in_array($d['density'] ?? '', ['compact', 'normal', 'airy'], true) ? $d['density'] : 'normal';
        $t['heading_weight'] = max(400, min(900, (int) ($d['heading_weight'] ?? 700)));
        $t['tracking'] = max(-0.04, min(0.02, (float) ($d['tracking'] ?? -0.01)));
        $t['header'] = ($d['header'] ?? 'split') === 'center' ? 'center' : 'split';
        $t = self::repair($t);
        return ['tokens' => $t, 'name' => (string) ($d['name'] ?? ''), 'rationale' => (string) ($d['rationale'] ?? ''), 'findings' => self::audit($t)];
    }

    /** Critique d'une page (heuristiques de Nielsen, priorités P0-P3). */
    public static function critique(array $content): array
    {
        $t = Theme::tokens();
        $findings = self::audit($t, $content['blocks']);
        $structure = implode("\n", array_map(static function ($b) {
            $d = (array) ($b['data'] ?? []);
            $info = array_filter([$d['layout'] ?? null, $d['style'] ?? null, isset($d['tone']) ? 'ambiance ' . $d['tone'] : null, isset($d['items']) ? count((array) $d['items']) . ' éléments' : null]);
            return '- ' . $b['type'] . ($info ? ' (' . implode(', ', $info) . ')' : '');
        }, $content['blocks']));
        $prompt = "Page : « {$content['title']} » (type : {$content['type']}).\n\nStructure en blocs :\n{$structure}\n\nJetons de design :\n" . json_enc(['colors' => $t['colors'], 'fonts' => $t['fonts'], 'base' => $t['base'], 'ratio' => $t['ratio'], 'radius' => $t['radius'], 'density' => $t['density'], 'scheme' => $t['scheme']])
            . "\n\nContrôles automatiques déjà relevés :\n" . json_enc(array_map(static fn ($x) => $x['title'], $findings))
            . "\n\nContenu (Markdown) :\n" . mb_substr(Blocks::toMarkdown($content['blocks']), 0, 20000)
            . "\n\nFais une critique honnête de cette page comme directeur artistique : note les 10 heuristiques de Nielsen de 0 à 4 (null si non applicable), un verdict sur la singularité (la page semble-t-elle faite pour ce site ou interchangeable ?), 2 ou 3 points forts, 3 à 5 problèmes prioritaires (P0 à P3) avec une correction concrète faisable dans Wordless (blocs, textes, jetons de design). Réponds en français sauf si la page est dans une autre langue.";
        $schema = [
            'type' => 'object', 'additionalProperties' => false,
            'required' => ['verdict', 'impression', 'heuristics', 'strengths', 'priorities'],
            'properties' => [
                'verdict' => ['type' => 'string'],
                'impression' => ['type' => 'string'],
                'heuristics' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['name', 'score', 'note'],
                    'properties' => ['name' => ['type' => 'string'], 'score' => ['anyOf' => [['type' => 'integer'], ['type' => 'null']]], 'note' => ['type' => 'string']]]],
                'strengths' => ['type' => 'array', 'items' => ['type' => 'string']],
                'priorities' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['prio', 'title', 'why', 'fix'],
                    'properties' => ['prio' => ['type' => 'string', 'enum' => ['P0', 'P1', 'P2', 'P3']], 'title' => ['type' => 'string'], 'why' => ['type' => 'string'], 'fix' => ['type' => 'string']]]],
            ],
        ];
        $res = Client::complete(['system' => self::principles(), 'prompt' => $prompt, 'schema' => $schema, 'effort' => 'high', 'max_tokens' => 12000, 'task' => 'design_critique']);
        $r = $res['json'];
        $scored = array_filter(array_column($r['heuristics'] ?? [], 'score'), static fn ($s) => $s !== null);
        $r['score'] = $scored ? (int) round(array_sum($scored) / (4 * count($scored)) * 100) : null;
        $r['findings'] = $findings;
        return $r;
    }
}
