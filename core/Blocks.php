<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Blocs du page builder : définition (champs), nettoyage, rendu HTML
 * (sans JavaScript côté visiteur), export Markdown et données structurées.
 * Un contenu = liste ordonnée de blocs {id, type, data}.
 */
final class Blocks
{
    private static ?array $registry = null;
    /** Le rendu a-t-il besoin du petit script (vidéos) ? */
    public static bool $needsScript = false;

    public static function registry(): array
    {
        if (self::$registry !== null) {
            return self::$registry;
        }
        $tones = ['default' => __('Neutre'), 'soft' => __('Teinté'), 'accent' => __('Couleur principale'), 'dark' => __('Sombre')];
        $r = [
            'text' => ['label' => __('Texte'), 'group' => 'text', 'icon' => 'text', 'description' => __('Paragraphes, titres, listes, liens'),
                'fields' => ['html' => ['type' => 'rich', 'profile' => 'full', 'label' => __('Texte'), 'inline' => true]],
                'defaults' => ['html' => '']],
            'heading' => ['label' => __('Titre'), 'group' => 'text', 'icon' => 'heading', 'description' => __('Titre de section (H2, H3, H4)'),
                'fields' => [
                    'text' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'level' => ['type' => 'select', 'label' => __('Niveau'), 'options' => ['2' => 'H2', '3' => 'H3', '4' => 'H4']],
                    'anchor' => ['type' => 'text', 'label' => __('Ancre (#)'), 'help' => __('Généré automatiquement')],
                ], 'defaults' => ['text' => '', 'level' => '2', 'anchor' => '']],
            'image' => ['label' => __('Image'), 'group' => 'media', 'icon' => 'image', 'description' => __('Image responsive optimisée'),
                'fields' => [
                    'media' => ['type' => 'media', 'label' => __('Image')],
                    'alt' => ['type' => 'text', 'label' => __('Texte alternatif'), 'help' => __('Décrit l’image pour Google et les lecteurs d’écran. Vide = celui de la médiathèque.')],
                    'caption' => ['type' => 'text', 'label' => __('Légende'), 'inline' => true],
                    'link' => ['type' => 'url', 'label' => __('Lien')],
                    'size' => ['type' => 'select', 'label' => __('Largeur'), 'options' => ['content' => __('Texte'), 'wide' => __('Large'), 'full' => __('Pleine largeur')]],
                ], 'defaults' => ['media' => null, 'alt' => '', 'caption' => '', 'link' => '', 'size' => 'content']],
            'gallery' => ['label' => __('Galerie'), 'group' => 'media', 'icon' => 'gallery', 'description' => __('Grille de photos'),
                'fields' => [
                    'medias' => ['type' => 'medias', 'label' => __('Images')],
                    'columns' => ['type' => 'select', 'label' => __('Colonnes'), 'options' => ['2' => '2', '3' => '3', '4' => '4']],
                    'caption' => ['type' => 'text', 'label' => __('Légende'), 'inline' => true],
                ], 'defaults' => ['medias' => [], 'columns' => '3', 'caption' => '']],
            'video' => ['label' => __('Vidéo'), 'group' => 'media', 'icon' => 'video', 'description' => __('YouTube, Vimeo ou MP4, chargée au clic'),
                'fields' => [
                    'url' => ['type' => 'url', 'label' => __('Adresse de la vidéo')],
                    'title' => ['type' => 'text', 'label' => __('Titre de la vidéo')],
                    'poster' => ['type' => 'media', 'label' => __('Image d’aperçu')],
                    'caption' => ['type' => 'text', 'label' => __('Légende'), 'inline' => true],
                ], 'defaults' => ['url' => '', 'title' => '', 'poster' => null, 'caption' => '']],
            'hero' => ['label' => __('Bannière'), 'group' => 'layout', 'icon' => 'hero', 'description' => __('Grand titre, texte, boutons et image'),
                'fields' => [
                    'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'text' => ['type' => 'rich', 'profile' => 'inline', 'label' => __('Texte'), 'inline' => true],
                    'button' => ['type' => 'text', 'label' => __('Bouton principal')],
                    'url' => ['type' => 'url', 'label' => __('Lien du bouton principal')],
                    'button2' => ['type' => 'text', 'label' => __('Bouton secondaire')],
                    'url2' => ['type' => 'url', 'label' => __('Lien du bouton secondaire')],
                    'media' => ['type' => 'media', 'label' => __('Image')],
                    'layout' => ['type' => 'select', 'label' => __('Disposition'), 'options' => ['split' => __('Texte et image'), 'center' => __('Centré'), 'cover' => __('Image en fond')]],
                    'tone' => ['type' => 'select', 'label' => __('Ambiance'), 'options' => $tones],
                ], 'defaults' => ['title' => '', 'text' => '', 'button' => '', 'url' => '', 'button2' => '', 'url2' => '', 'media' => null, 'layout' => 'split', 'tone' => 'default']],
            'columns' => ['label' => __('Colonnes'), 'group' => 'layout', 'icon' => 'columns', 'description' => __('2 à 4 colonnes avec image, titre et texte'),
                'fields' => [
                    'items' => ['type' => 'list', 'label' => __('Colonnes'), 'max' => 4, 'item' => __('Colonne'), 'fields' => [
                        'media' => ['type' => 'media', 'label' => __('Image')],
                        'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                        'text' => ['type' => 'rich', 'profile' => 'inline', 'label' => __('Texte'), 'inline' => true],
                    ]],
                    'tone' => ['type' => 'select', 'label' => __('Ambiance'), 'options' => $tones],
                ], 'defaults' => ['items' => [['media' => null, 'title' => '', 'text' => ''], ['media' => null, 'title' => '', 'text' => '']], 'tone' => 'default']],
            'features' => ['label' => __('Points forts'), 'group' => 'layout', 'icon' => 'list', 'description' => __('Liste d’avantages ou de services'),
                'fields' => [
                    'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'intro' => ['type' => 'rich', 'profile' => 'inline', 'label' => __('Introduction'), 'inline' => true],
                    'items' => ['type' => 'list', 'label' => __('Points'), 'max' => 12, 'item' => __('Point'), 'fields' => [
                        'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                        'text' => ['type' => 'textarea', 'label' => __('Texte'), 'inline' => true],
                    ]],
                    'style' => ['type' => 'select', 'label' => __('Présentation'), 'options' => ['list' => __('Liste'), 'grid' => __('Grille')]],
                    'tone' => ['type' => 'select', 'label' => __('Ambiance'), 'options' => $tones],
                ], 'defaults' => ['title' => '', 'intro' => '', 'items' => [['title' => '', 'text' => ''], ['title' => '', 'text' => ''], ['title' => '', 'text' => '']], 'style' => 'list', 'tone' => 'default']],
            'cta' => ['label' => __('Appel à l’action'), 'group' => 'conversion', 'icon' => 'cta', 'description' => __('Titre, phrase et bouton'),
                'fields' => [
                    'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'text' => ['type' => 'rich', 'profile' => 'inline', 'label' => __('Texte'), 'inline' => true],
                    'button' => ['type' => 'text', 'label' => __('Bouton')],
                    'url' => ['type' => 'url', 'label' => __('Lien du bouton')],
                    'tone' => ['type' => 'select', 'label' => __('Ambiance'), 'options' => $tones],
                ], 'defaults' => ['title' => '', 'text' => '', 'button' => '', 'url' => '', 'tone' => 'accent']],
            'faq' => ['label' => __('FAQ'), 'group' => 'geo', 'icon' => 'faq', 'description' => __('Questions/réponses balisées pour Google et les IA'),
                'fields' => [
                    'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'items' => ['type' => 'list', 'label' => __('Questions'), 'max' => 30, 'item' => __('Question'), 'fields' => [
                        'q' => ['type' => 'text', 'label' => __('Question'), 'inline' => true],
                        'a' => ['type' => 'rich', 'profile' => 'inline', 'label' => __('Réponse'), 'inline' => true],
                    ]],
                ], 'defaults' => ['title' => __('Questions fréquentes'), 'items' => [['q' => '', 'a' => ''], ['q' => '', 'a' => '']]]],
            'takeaways' => ['label' => __('L’essentiel'), 'group' => 'geo', 'icon' => 'spark', 'description' => __('Résumé en points clés, repris par les IA'),
                'fields' => [
                    'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'items' => ['type' => 'list', 'label' => __('Points clés'), 'max' => 10, 'item' => __('Point'), 'fields' => [
                        'text' => ['type' => 'textarea', 'label' => __('Point clé'), 'inline' => true],
                    ]],
                ], 'defaults' => ['title' => __('L’essentiel'), 'items' => [['text' => ''], ['text' => ''], ['text' => '']]]],
            'steps' => ['label' => __('Étapes'), 'group' => 'geo', 'icon' => 'steps', 'description' => __('Mode d’emploi pas à pas (HowTo)'),
                'fields' => [
                    'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'items' => ['type' => 'list', 'label' => __('Étapes'), 'max' => 20, 'item' => __('Étape'), 'fields' => [
                        'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                        'text' => ['type' => 'rich', 'profile' => 'inline', 'label' => __('Texte'), 'inline' => true],
                    ]],
                ], 'defaults' => ['title' => '', 'items' => [['title' => '', 'text' => ''], ['title' => '', 'text' => '']]]],
            'toc' => ['label' => __('Sommaire'), 'group' => 'geo', 'icon' => 'toc', 'description' => __('Généré à partir des titres'),
                'fields' => ['title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true]],
                'defaults' => ['title' => __('Sommaire')]],
            'quote' => ['label' => __('Citation'), 'group' => 'text', 'icon' => 'quote', 'description' => __('Citation mise en avant'),
                'fields' => [
                    'text' => ['type' => 'textarea', 'label' => __('Citation'), 'inline' => true],
                    'author' => ['type' => 'text', 'label' => __('Auteur'), 'inline' => true],
                    'role' => ['type' => 'text', 'label' => __('Fonction ou source'), 'inline' => true],
                ], 'defaults' => ['text' => '', 'author' => '', 'role' => '']],
            'testimonials' => ['label' => __('Témoignages'), 'group' => 'conversion', 'icon' => 'users', 'description' => __('Avis de clients'),
                'fields' => [
                    'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'items' => ['type' => 'list', 'label' => __('Témoignages'), 'max' => 12, 'item' => __('Témoignage'), 'fields' => [
                        'quote' => ['type' => 'textarea', 'label' => __('Témoignage'), 'inline' => true],
                        'name' => ['type' => 'text', 'label' => __('Nom'), 'inline' => true],
                        'role' => ['type' => 'text', 'label' => __('Fonction, ville…'), 'inline' => true],
                        'media' => ['type' => 'media', 'label' => __('Photo')],
                    ]],
                    'tone' => ['type' => 'select', 'label' => __('Ambiance'), 'options' => $tones],
                ], 'defaults' => ['title' => '', 'items' => [['quote' => '', 'name' => '', 'role' => '', 'media' => null], ['quote' => '', 'name' => '', 'role' => '', 'media' => null]], 'tone' => 'default']],
            'pricing' => ['label' => __('Tarifs'), 'group' => 'conversion', 'icon' => 'price', 'description' => __('Offres et prix'),
                'fields' => [
                    'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'items' => ['type' => 'list', 'label' => __('Offres'), 'max' => 4, 'item' => __('Offre'), 'fields' => [
                        'name' => ['type' => 'text', 'label' => __('Nom'), 'inline' => true],
                        'price' => ['type' => 'text', 'label' => __('Prix'), 'inline' => true],
                        'period' => ['type' => 'text', 'label' => __('Période'), 'inline' => true],
                        'description' => ['type' => 'textarea', 'label' => __('Description'), 'inline' => true],
                        'features' => ['type' => 'textarea', 'label' => __('Inclus (une ligne par élément)')],
                        'button' => ['type' => 'text', 'label' => __('Bouton')],
                        'url' => ['type' => 'url', 'label' => __('Lien')],
                        'featured' => ['type' => 'toggle', 'label' => __('Mettre en avant')],
                    ]],
                    'tone' => ['type' => 'select', 'label' => __('Ambiance'), 'options' => $tones],
                ], 'defaults' => ['title' => '', 'items' => [
                    ['name' => '', 'price' => '', 'period' => '', 'description' => '', 'features' => '', 'button' => '', 'url' => '', 'featured' => false],
                    ['name' => '', 'price' => '', 'period' => '', 'description' => '', 'features' => '', 'button' => '', 'url' => '', 'featured' => true],
                ], 'tone' => 'default']],
            'stats' => ['label' => __('Chiffres clés'), 'group' => 'conversion', 'icon' => 'chart', 'description' => __('Chiffres et preuves'),
                'fields' => [
                    'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'items' => ['type' => 'list', 'label' => __('Chiffres'), 'max' => 6, 'item' => __('Chiffre'), 'fields' => [
                        'value' => ['type' => 'text', 'label' => __('Valeur'), 'inline' => true],
                        'label' => ['type' => 'text', 'label' => __('Libellé'), 'inline' => true],
                        'source' => ['type' => 'text', 'label' => __('Source')],
                    ]],
                    'tone' => ['type' => 'select', 'label' => __('Ambiance'), 'options' => $tones],
                ], 'defaults' => ['title' => '', 'items' => [['value' => '', 'label' => '', 'source' => ''], ['value' => '', 'label' => '', 'source' => ''], ['value' => '', 'label' => '', 'source' => '']], 'tone' => 'default']],
            'button' => ['label' => __('Bouton'), 'group' => 'conversion', 'icon' => 'button', 'description' => __('Lien mis en forme'),
                'fields' => [
                    'label' => ['type' => 'text', 'label' => __('Texte'), 'inline' => true],
                    'url' => ['type' => 'url', 'label' => __('Lien')],
                    'style' => ['type' => 'select', 'label' => __('Style'), 'options' => ['primary' => __('Principal'), 'secondary' => __('Secondaire'), 'link' => __('Lien')]],
                    'align' => ['type' => 'select', 'label' => __('Alignement'), 'options' => ['left' => __('Gauche'), 'center' => __('Centre')]],
                ], 'defaults' => ['label' => '', 'url' => '', 'style' => 'primary', 'align' => 'left']],
            'contact' => ['label' => __('Formulaire de contact'), 'group' => 'conversion', 'icon' => 'mail', 'description' => __('Anti-spam intégré, messages dans l’admin'),
                'fields' => [
                    'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'intro' => ['type' => 'rich', 'profile' => 'inline', 'label' => __('Introduction'), 'inline' => true],
                    'phone' => ['type' => 'toggle', 'label' => __('Demander le téléphone')],
                    'subject' => ['type' => 'toggle', 'label' => __('Demander l’objet')],
                    'consent' => ['type' => 'text', 'label' => __('Mention RGPD'), 'help' => __('Affichée sous le bouton')],
                    'button' => ['type' => 'text', 'label' => __('Texte du bouton')],
                    'success' => ['type' => 'text', 'label' => __('Message de confirmation')],
                    'recipient' => ['type' => 'email', 'label' => __('Envoyer à (e-mail)'), 'help' => __('Vide = e-mail du site')],
                ], 'defaults' => ['title' => __('Contactez-nous'), 'intro' => '', 'phone' => false, 'subject' => false,
                    'consent' => __('Vos données servent uniquement à vous répondre.'), 'button' => __('Envoyer'),
                    'success' => __('Merci, votre message est bien parti. Nous vous répondons rapidement.'), 'recipient' => '']],
            'posts' => ['label' => __('Derniers articles'), 'group' => 'layout', 'icon' => 'posts', 'description' => __('Liste mise à jour automatiquement'),
                'fields' => [
                    'title' => ['type' => 'text', 'label' => __('Titre'), 'inline' => true],
                    'count' => ['type' => 'number', 'label' => __('Nombre'), 'min' => 1, 'max' => 12],
                    'category' => ['type' => 'term', 'taxonomy' => 'category', 'label' => __('Catégorie')],
                    'layout' => ['type' => 'select', 'label' => __('Présentation'), 'options' => ['cards' => __('Cartes'), 'list' => __('Liste')]],
                ], 'defaults' => ['title' => __('Derniers articles'), 'count' => 3, 'category' => null, 'layout' => 'cards']],
            'divider' => ['label' => __('Séparateur'), 'group' => 'layout', 'icon' => 'divider', 'description' => __('Ligne ou espace'),
                'fields' => ['style' => ['type' => 'select', 'label' => __('Style'), 'options' => ['line' => __('Ligne'), 'space' => __('Espace')]]],
                'defaults' => ['style' => 'line']],
            'html' => ['label' => __('Code HTML'), 'group' => 'advanced', 'icon' => 'code', 'description' => __('Intégration personnalisée (administrateurs)'),
                'fields' => ['code' => ['type' => 'code', 'label' => __('HTML')]],
                'defaults' => ['code' => '']],
        ];
        return self::$registry = wl_filter('blocks', $r);
    }

    public static function groups(): array
    {
        return ['text' => __('Texte'), 'media' => __('Médias'), 'layout' => __('Mise en page'), 'conversion' => __('Conversion'), 'geo' => __('SEO & IA'), 'advanced' => __('Avancé')];
    }

    public static function make(string $type, array $data = []): array
    {
        $def = self::registry()[$type] ?? null;
        if (!$def) {
            throw new \InvalidArgumentException('Bloc inconnu : ' . $type);
        }
        return ['id' => 'b' . bin2hex(random_bytes(5)), 'type' => $type, 'data' => array_merge($def['defaults'], $data)];
    }

    // ------------------------------------------------------------------
    // Nettoyage
    // ------------------------------------------------------------------

    /** Nettoie une liste de blocs envoyée par l'éditeur, l'IA, MCP ou un import. */
    public static function sanitize(array $blocks, bool $trusted = false): array
    {
        $out = [];
        $ids = [];
        $anchors = [];
        foreach (array_slice($blocks, 0, 300) as $b) {
            if (!is_array($b) || !isset($b['type'], self::registry()[$b['type']])) {
                continue;
            }
            $def = self::registry()[$b['type']];
            $id = is_string($b['id'] ?? null) && preg_match('/^[a-z0-9_-]{2,40}$/i', $b['id']) && !isset($ids[$b['id']]) ? $b['id'] : 'b' . bin2hex(random_bytes(5));
            $ids[$id] = true;
            $data = self::cleanFields($def['fields'], (array) ($b['data'] ?? []), $def['defaults'], $trusted);
            if ($b['type'] === 'text') {
                $data['html'] = Html::anchorHeadings($data['html'], $anchors);
            } elseif ($b['type'] === 'heading') {
                $anchor = slugify($data['anchor'] !== '' ? $data['anchor'] : $data['text'], 60) ?: 'section';
                $base = $anchor;
                $i = 2;
                while (isset($anchors[$anchor])) {
                    $anchor = $base . '-' . $i++;
                }
                $anchors[$anchor] = true;
                $data['anchor'] = $anchor;
            } elseif ($b['type'] === 'video') {
                $data = self::ensureVideoPoster($data);
            }
            $out[] = ['id' => $id, 'type' => $b['type'], 'data' => $data];
        }
        return $out;
    }

    private static function cleanFields(array $fields, array $data, array $defaults, bool $trusted): array
    {
        $out = [];
        foreach ($fields as $name => $f) {
            $v = $data[$name] ?? ($defaults[$name] ?? null);
            $out[$name] = self::cleanValue($f, $v, $defaults[$name] ?? null, $trusted);
        }
        return $out;
    }

    private static function cleanValue(array $f, mixed $v, mixed $default, bool $trusted): mixed
    {
        switch ($f['type']) {
            case 'text':
                return mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) (is_scalar($v) ? $v : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? ''), 0, 600);
            case 'textarea':
                $t = str_replace("\r", '', strip_tags(html_entity_decode((string) (is_scalar($v) ? $v : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                return mb_substr(trim(preg_replace("/\n{3,}/", "\n\n", $t) ?? ''), 0, 8000);
            case 'rich':
                return Html::clean((string) (is_scalar($v) ? $v : ''), $f['profile'] ?? 'inline');
            case 'code':
                $code = (string) (is_scalar($v) ? $v : '');
                return $trusted ? $code : Html::clean($code, 'full');
            case 'url':
                return mb_substr(Html::safeUrl((string) (is_scalar($v) ? $v : '')), 0, 2000);
            case 'email':
                $e = trim((string) (is_scalar($v) ? $v : ''));
                return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
            case 'select':
                $v = (string) (is_scalar($v) ? $v : '');
                return array_key_exists($v, $f['options']) ? $v : (string) ($default ?? array_key_first($f['options']));
            case 'toggle':
                return filter_var($v, FILTER_VALIDATE_BOOLEAN);
            case 'number':
                $n = is_numeric($v) ? (int) $v : (int) $default;
                return max($f['min'] ?? PHP_INT_MIN, min($f['max'] ?? PHP_INT_MAX, $n));
            case 'media':
            case 'term':
                return is_numeric($v) && (int) $v > 0 ? (int) $v : null;
            case 'medias':
                return array_values(array_slice(array_map('intval', array_filter((array) $v, 'is_numeric')), 0, 60));
            case 'list':
                $items = [];
                $itemDefaults = array_map(static fn ($sf) => null, $f['fields']);
                foreach (array_slice(is_array($v) ? array_values($v) : [], 0, (int) ($f['max'] ?? 50)) as $item) {
                    if (is_array($item)) {
                        $items[] = self::cleanFields($f['fields'], $item, $itemDefaults, $trusted);
                    }
                }
                return $items;
        }
        return null;
    }

    /** Vidéo YouTube : récupère l'aperçu en local (pas d'appel à Google à l'affichage). */
    private static function ensureVideoPoster(array $data): array
    {
        if (!empty($data['poster']) || $data['url'] === '') {
            return $data;
        }
        $yt = self::youtubeId($data['url']);
        if ($yt === null) {
            return $data;
        }
        try {
            $m = Media::importUrl('https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg', ['title' => $data['title'] !== '' ? $data['title'] : 'YouTube ' . $yt, 'alt' => $data['title']]);
            $data['poster'] = (int) $m['id'];
        } catch (\Throwable) {
            // hors ligne : l'aperçu reste vide
        }
        return $data;
    }

    public static function youtubeId(string $url): ?string
    {
        if (preg_match('#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([\w-]{11})#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    public static function vimeoId(string $url): ?string
    {
        return preg_match('#vimeo\.com/(?:video/)?(\d+)#', $url, $m) ? $m[1] : null;
    }

    // ------------------------------------------------------------------
    // Rendu
    // ------------------------------------------------------------------

    /**
     * Rend les blocs. $ctx : edit (bool, attributs d'édition), content (array),
     * heading_level (niveau du titre principal de la bannière).
     */
    public static function render(array $blocks, array $ctx = []): string
    {
        $ctx += ['edit' => false, 'content' => null];
        $ctx['headings'] = self::headings($blocks);
        $html = '';
        $run = ''; // boutons consécutifs : regroupés sur une même ligne
        foreach ($blocks as $i => $b) {
            $ctx['index'] = $i;
            $out = self::renderOne($b, $ctx);
            if (($b['type'] ?? '') === 'button' && $out !== '') {
                $run .= $out;
                continue;
            }
            $html .= self::buttonRun($run) . $out;
            $run = '';
        }
        return $html . self::buttonRun($run);
    }

    private static function buttonRun(string $run): string
    {
        return substr_count($run, 'wl-button') > 1 ? '<div class="wl-b wl-buttons">' . $run . '</div>' : $run;
    }

    public static function renderOne(array $b, array $ctx): string
    {
        $ctx += ['edit' => false, 'index' => 1, 'headings' => []];
        $type = $b['type'] ?? '';
        $def = self::registry()[$type] ?? null;
        if (!$def) {
            return '';
        }
        $d = array_merge($def['defaults'], (array) ($b['data'] ?? []));
        $b['data'] = $d;
        $custom = wl_filter('block_render_' . $type, null, $b, $ctx);
        if (is_string($custom)) {
            return $custom;
        }
        $method = 'r' . ucfirst($type);
        return method_exists(self::class, $method) ? self::$method($b, $d, $ctx) : '';
    }

    /** Attributs d'édition inline (uniquement dans l'éditeur). */
    private static function fa(array $ctx, string $field, string $placeholder = '', string $rich = ''): string
    {
        if (empty($ctx['edit'])) {
            return '';
        }
        return ' data-wl-field="' . e($field) . '"' . ($placeholder !== '' ? ' data-wl-placeholder="' . e($placeholder) . '"' : '') . ($rich !== '' ? ' data-wl-rich="' . e($rich) . '"' : '');
    }

    private static function open(string $tag, array $b, array $ctx, string $class, string $extra = ''): string
    {
        $a = '<' . $tag . ' class="wl-b wl-' . e($b['type']) . ($class !== '' ? ' ' . e($class) : '') . '"';
        if (!empty($ctx['edit'])) {
            $a .= ' data-wl-block="' . e($b['id']) . '" data-wl-type="' . e($b['type']) . '"';
        }
        return $a . $extra . '>';
    }

    /** Élément texte : omis s'il est vide (sauf dans l'éditeur, pour pouvoir le remplir). */
    private static function el(string $tag, string $value, array $ctx, string $field, string $placeholder, string $class = '', bool $rich = false, string $richMode = 'inline'): string
    {
        if ($value === '' && empty($ctx['edit'])) {
            return '';
        }
        $content = $rich ? $value : e($value);
        return '<' . $tag . ($class !== '' ? ' class="' . e($class) . '"' : '') . self::fa($ctx, $field, $placeholder, $rich ? $richMode : '') . '>' . $content . '</' . $tag . '>';
    }

    private static function nl2p(string $text): string
    {
        $parts = preg_split("/\n\s*\n/", trim($text)) ?: [];
        return implode('', array_map(static fn ($p) => '<p>' . nl2br(e(trim($p)), false) . '</p>', array_filter($parts, static fn ($p) => trim($p) !== '')));
    }

    private static function button(string $label, string $url, string $style, array $ctx, string $field): string
    {
        if ($label === '' && empty($ctx['edit'])) {
            return '';
        }
        $href = $url !== '' ? href($url) : '#';
        return '<a class="wl-btn wl-btn--' . e($style) . '" href="' . e($href) . '"' . self::fa($ctx, $field, __('Texte du bouton')) . '>' . e($label) . '</a>';
    }

    private static function heroLevel(array $ctx): int
    {
        return (int) ($ctx['heading_level'] ?? (($ctx['index'] ?? 1) === 0 ? 1 : 2));
    }

    private static function rText(array $b, array $d, array $ctx): string
    {
        if ($d['html'] === '' && empty($ctx['edit'])) {
            return '';
        }
        $extra = self::fa($ctx, 'html', __('Écrivez ici… Sélectionnez du texte pour le mettre en forme.'), 'full');
        return self::open('div', $b, $ctx, 'wl-prose', $extra) . $d['html'] . '</div>';
    }

    private static function rHeading(array $b, array $d, array $ctx): string
    {
        $lvl = in_array($d['level'], ['2', '3', '4'], true) ? $d['level'] : '2';
        if ($d['text'] === '' && empty($ctx['edit'])) {
            return '';
        }
        $tag = 'h' . $lvl;
        return self::open($tag, $b, $ctx, '', ' id="' . e($d['anchor']) . '"' . self::fa($ctx, 'text', __('Titre de section'))) . e($d['text']) . '</' . $tag . '>';
    }

    private static function rImage(array $b, array $d, array $ctx): string
    {
        $m = Media::find($d['media']);
        if (!$m && empty($ctx['edit'])) {
            return '';
        }
        $sizes = ['content' => '(min-width: 44rem) 44rem, 100vw', 'wide' => '(min-width: 72rem) 72rem, 100vw', 'full' => '100vw'][$d['size']] ?? '100vw';
        $pic = $m ? Media::picture($m, ['sizes' => $sizes, 'alt' => $d['alt'] !== '' ? $d['alt'] : null, 'priority' => ($ctx['index'] ?? 1) === 0])
            : '<div class="wl-placeholder">' . e(__('Choisissez une image dans le panneau de droite')) . '</div>';
        if ($d['link'] !== '' && $m) {
            $pic = '<a href="' . e(href($d['link'])) . '">' . $pic . '</a>';
        }
        $cap = self::el('figcaption', $d['caption'], $ctx, 'caption', __('Légende (facultative)'));
        return self::open('figure', $b, $ctx, 'size-' . $d['size']) . $pic . $cap . '</figure>';
    }

    private static function rGallery(array $b, array $d, array $ctx): string
    {
        $items = '';
        foreach ($d['medias'] as $id) {
            $m = Media::find((int) $id);
            if ($m) {
                $items .= '<li><a href="' . e(Media::url($m)) . '">' . Media::picture($m, ['sizes' => '(min-width: 72rem) 24rem, 50vw']) . '</a></li>';
            }
        }
        if ($items === '' && empty($ctx['edit'])) {
            return '';
        }
        if ($items === '') {
            $items = '<li class="wl-placeholder">' . e(__('Ajoutez des images dans le panneau de droite')) . '</li>';
        }
        $cap = self::el('figcaption', $d['caption'], $ctx, 'caption', __('Légende (facultative)'));
        return self::open('figure', $b, $ctx, 'cols-' . e($d['columns'])) . '<ul>' . $items . '</ul>' . $cap . '</figure>';
    }

    private static function rVideo(array $b, array $d, array $ctx): string
    {
        $url = $d['url'];
        if ($url === '') {
            return empty($ctx['edit']) ? '' : self::open('div', $b, $ctx, '') . '<div class="wl-placeholder">' . e(__('Collez l’adresse d’une vidéo YouTube, Vimeo ou MP4')) . '</div></div>';
        }
        $title = $d['title'] !== '' ? $d['title'] : __('Vidéo');
        $poster = Media::find($d['poster']);
        $cap = self::el('figcaption', $d['caption'], $ctx, 'caption', __('Légende (facultative)'));
        if (preg_match('#\.(mp4|webm)(\?|$)#i', $url)) {
            return self::open('figure', $b, $ctx, '') . '<video controls preload="none" playsinline src="' . e(href($url)) . '"' . ($poster ? ' poster="' . e(Media::url($poster, 960)) . '"' : '') . '></video>' . $cap . '</figure>';
        }
        $yt = self::youtubeId($url);
        $vm = self::vimeoId($url);
        $embed = $yt ? 'https://www.youtube-nocookie.com/embed/' . $yt . '?autoplay=1' : ($vm ? 'https://player.vimeo.com/video/' . $vm . '?autoplay=1' : '');
        if ($embed === '') {
            return self::open('p', $b, $ctx, '') . '<a href="' . e($url) . '">' . e($title) . '</a></p>';
        }
        self::$needsScript = true;
        $thumb = $poster ? Media::picture($poster, ['sizes' => '(min-width: 44rem) 44rem, 100vw', 'alt' => '']) : '';
        return self::open('figure', $b, $ctx, '') . '<a class="wl-video__frame" href="' . e($url) . '" data-wl-embed="' . e($embed) . '" data-wl-title="' . e($title) . '">'
            . $thumb . '<span class="wl-video__play" aria-hidden="true"></span><span class="wl-sr">' . e(__('Lire la vidéo : {t}', ['t' => $title])) . '</span></a>' . $cap . '</figure>';
    }

    private static function rHero(array $b, array $d, array $ctx): string
    {
        $lvl = self::heroLevel($ctx);
        $m = Media::find($d['media']);
        $layout = in_array($d['layout'], ['split', 'center', 'cover'], true) ? $d['layout'] : 'split';
        $media = '';
        if ($m) {
            $media = '<div class="wl-hero__media">' . Media::picture($m, ['sizes' => $layout === 'split' ? '(min-width: 64rem) 36rem, 100vw' : '100vw', 'priority' => ($ctx['index'] ?? 1) === 0, 'alt' => $layout === 'cover' ? '' : null]) . '</div>';
        } elseif (!empty($ctx['edit']) && $layout !== 'center') {
            $media = '<div class="wl-hero__media"><div class="wl-placeholder">' . e(__('Image : choisissez-la dans le panneau de droite')) . '</div></div>';
        }
        $actions = self::button($d['button'], $d['url'], 'primary', $ctx, 'button') . ($d['button2'] !== '' || !empty($ctx['edit']) ? self::button($d['button2'], $d['url2'], 'secondary', $ctx, 'button2') : '');
        $text = '<div class="wl-hero__text">'
            . self::el('h' . $lvl, $d['title'], $ctx, 'title', __('Un titre clair qui dit ce que vous proposez'), 'wl-hero__title')
            . self::el('div', $d['text'], $ctx, 'text', __('Une phrase qui explique pour qui et pourquoi.'), 'wl-hero__lead', true)
            . ($actions !== '' ? '<p class="wl-actions">' . $actions . '</p>' : '')
            . '</div>';
        return self::open('section', $b, $ctx, 'layout-' . $layout . ' tone-' . e($d['tone']) . ($m ? ' has-media' : '')) . '<div class="wl-hero__inner">' . $text . $media . '</div></section>';
    }

    private static function rColumns(array $b, array $d, array $ctx): string
    {
        $cols = '';
        foreach ($d['items'] as $i => $it) {
            $m = Media::find($it['media'] ?? null);
            $cols .= '<div class="wl-col">' . ($m ? Media::picture($m, ['sizes' => '(min-width: 64rem) 22rem, 100vw']) : '')
                . self::el('h3', (string) $it['title'], $ctx, "items.$i.title", __('Titre'))
                . self::el('div', (string) $it['text'], $ctx, "items.$i.text", __('Texte de la colonne'), 'wl-col__text', true)
                . '</div>';
        }
        return self::open('section', $b, $ctx, 'tone-' . e($d['tone'])) . '<div class="wl-columns__grid cols-' . count($d['items']) . '">' . $cols . '</div></section>';
    }

    private static function rFeatures(array $b, array $d, array $ctx): string
    {
        $items = '';
        foreach ($d['items'] as $i => $it) {
            if ($it['title'] === '' && $it['text'] === '' && empty($ctx['edit'])) {
                continue;
            }
            $items .= '<li>' . self::el('h3', (string) $it['title'], $ctx, "items.$i.title", __('Avantage'))
                . (($it['text'] !== '' || !empty($ctx['edit'])) ? '<p' . self::fa($ctx, "items.$i.text", __('Expliquez-le en une ou deux phrases.')) . '>' . nl2br(e((string) $it['text']), false) . '</p>' : '')
                . '</li>';
        }
        if ($items === '' && empty($ctx['edit'])) {
            return '';
        }
        return self::open('section', $b, $ctx, 'style-' . e($d['style']) . ' tone-' . e($d['tone'])) . '<div class="wl-section__inner">'
            . self::el('h2', $d['title'], $ctx, 'title', __('Pourquoi nous choisir'))
            . self::el('div', $d['intro'], $ctx, 'intro', __('Introduction (facultative)'), 'wl-lead', true)
            . '<ul class="wl-features__list">' . $items . '</ul></div></section>';
    }

    private static function rCta(array $b, array $d, array $ctx): string
    {
        return self::open('section', $b, $ctx, 'tone-' . e($d['tone'])) . '<div class="wl-section__inner">'
            . self::el('h2', $d['title'], $ctx, 'title', __('Prêt à vous lancer ?'))
            . self::el('div', $d['text'], $ctx, 'text', __('Une phrase pour lever le dernier doute.'), 'wl-lead', true)
            . '<p class="wl-actions">' . self::button($d['button'], $d['url'], 'primary', $ctx, 'button') . '</p></div></section>';
    }

    private static function rFaq(array $b, array $d, array $ctx): string
    {
        $items = '';
        foreach ($d['items'] as $i => $it) {
            if ($it['q'] === '' && empty($ctx['edit'])) {
                continue;
            }
            $open = !empty($ctx['edit']) || $i === 0 ? ' open' : '';
            $items .= '<details' . $open . '><summary><h3' . self::fa($ctx, "items.$i.q", __('Question')) . '>' . e((string) $it['q']) . '</h3></summary>'
                . '<div class="wl-faq__answer"' . self::fa($ctx, "items.$i.a", __('Réponse directe, en 2 à 4 phrases.'), 'inline') . '>' . $it['a'] . '</div></details>';
        }
        if ($items === '' && empty($ctx['edit'])) {
            return '';
        }
        return self::open('section', $b, $ctx, '') . self::el('h2', $d['title'], $ctx, 'title', __('Questions fréquentes')) . '<div class="wl-faq__list">' . $items . '</div></section>';
    }

    private static function rTakeaways(array $b, array $d, array $ctx): string
    {
        $items = '';
        foreach ($d['items'] as $i => $it) {
            if ($it['text'] === '' && empty($ctx['edit'])) {
                continue;
            }
            $items .= '<li' . self::fa($ctx, "items.$i.text", __('Un point clé, une phrase.')) . '>' . e((string) $it['text']) . '</li>';
        }
        if ($items === '') {
            return '';
        }
        return self::open('aside', $b, $ctx, '', ' aria-label="' . e($d['title']) . '"') . self::el('h2', $d['title'], $ctx, 'title', __('L’essentiel')) . '<ul>' . $items . '</ul></aside>';
    }

    private static function rSteps(array $b, array $d, array $ctx): string
    {
        $items = '';
        foreach ($d['items'] as $i => $it) {
            if ($it['title'] === '' && $it['text'] === '' && empty($ctx['edit'])) {
                continue;
            }
            $items .= '<li>' . self::el('h3', (string) $it['title'], $ctx, "items.$i.title", __('Étape'))
                . self::el('div', (string) $it['text'], $ctx, "items.$i.text", __('Ce qu’il faut faire.'), 'wl-steps__text', true) . '</li>';
        }
        if ($items === '' && empty($ctx['edit'])) {
            return '';
        }
        return self::open('section', $b, $ctx, '') . self::el('h2', $d['title'], $ctx, 'title', __('Comment faire, étape par étape')) . '<ol class="wl-steps__list">' . $items . '</ol></section>';
    }

    private static function rToc(array $b, array $d, array $ctx): string
    {
        $heads = array_filter($ctx['headings'] ?? [], static fn ($h) => $h['level'] <= 3 && $h['id'] !== '');
        if (!$heads) {
            return empty($ctx['edit']) ? '' : self::open('nav', $b, $ctx, '') . '<p class="wl-placeholder">' . e(__('Le sommaire se remplit avec les titres H2 et H3 de la page.')) . '</p></nav>';
        }
        $html = '<ol>';
        $inSub = false;
        $first = true;
        foreach ($heads as $h) {
            if ($h['level'] === 3 && !$first) {
                if (!$inSub) {
                    $html = preg_replace('#</li>$#', '', $html) . '<ol>';
                    $inSub = true;
                }
            } elseif ($inSub) {
                $html .= '</ol></li>';
                $inSub = false;
            }
            $html .= '<li><a href="#' . e($h['id']) . '">' . e($h['text']) . '</a></li>';
            $first = false;
        }
        $html .= $inSub ? '</ol></li></ol>' : '</ol>';
        return self::open('nav', $b, $ctx, '', ' aria-label="' . e($d['title']) . '"') . self::el('p', $d['title'], $ctx, 'title', __('Sommaire'), 'wl-toc__title') . $html . '</nav>';
    }

    private static function rQuote(array $b, array $d, array $ctx): string
    {
        if ($d['text'] === '' && empty($ctx['edit'])) {
            return '';
        }
        $who = ($d['author'] !== '' || !empty($ctx['edit']))
            ? '<figcaption><span' . self::fa($ctx, 'author', __('Auteur')) . '>' . e($d['author']) . '</span>' . (($d['role'] !== '' || !empty($ctx['edit'])) ? ' <span class="wl-muted"' . self::fa($ctx, 'role', __('Fonction ou source')) . '>' . e($d['role']) . '</span>' : '') . '</figcaption>'
            : '';
        return self::open('figure', $b, $ctx, '') . '<blockquote><p' . self::fa($ctx, 'text', __('Une citation marquante')) . '>' . nl2br(e($d['text']), false) . '</p></blockquote>' . $who . '</figure>';
    }

    private static function rTestimonials(array $b, array $d, array $ctx): string
    {
        $items = '';
        foreach ($d['items'] as $i => $it) {
            if ($it['quote'] === '' && empty($ctx['edit'])) {
                continue;
            }
            $m = Media::find($it['media'] ?? null);
            $items .= '<li><figure><blockquote><p' . self::fa($ctx, "items.$i.quote", __('Ce que le client dit, avec ses mots.')) . '>' . nl2br(e((string) $it['quote']), false) . '</p></blockquote>'
                . '<figcaption>' . ($m ? Media::picture($m, ['sizes' => '48px', 'class' => 'wl-avatar', 'alt' => '']) : '')
                . '<span><strong' . self::fa($ctx, "items.$i.name", __('Nom')) . '>' . e((string) $it['name']) . '</strong>'
                . (($it['role'] !== '' || !empty($ctx['edit'])) ? '<span class="wl-muted"' . self::fa($ctx, "items.$i.role", __('Fonction, ville…')) . '>' . e((string) $it['role']) . '</span>' : '')
                . '</span></figcaption></figure></li>';
        }
        if ($items === '' && empty($ctx['edit'])) {
            return '';
        }
        return self::open('section', $b, $ctx, 'tone-' . e($d['tone'])) . '<div class="wl-section__inner">' . self::el('h2', $d['title'], $ctx, 'title', __('Ils nous font confiance')) . '<ul class="wl-testimonials__list">' . $items . '</ul></div></section>';
    }

    private static function rPricing(array $b, array $d, array $ctx): string
    {
        $items = '';
        foreach ($d['items'] as $i => $it) {
            $features = '';
            foreach (array_filter(array_map('trim', explode("\n", (string) $it['features']))) as $line) {
                $features .= '<li>' . e($line) . '</li>';
            }
            $items .= '<li class="' . (!empty($it['featured']) ? 'is-featured' : '') . '">'
                . self::el('h3', (string) $it['name'], $ctx, "items.$i.name", __('Nom de l’offre'))
                . '<p class="wl-price"><span class="wl-price__amount"' . self::fa($ctx, "items.$i.price", __('Prix')) . '>' . e((string) $it['price']) . '</span>'
                . (($it['period'] !== '' || !empty($ctx['edit'])) ? ' <span class="wl-price__period"' . self::fa($ctx, "items.$i.period", __('/ mois')) . '>' . e((string) $it['period']) . '</span>' : '') . '</p>'
                . (($it['description'] !== '' || !empty($ctx['edit'])) ? '<p' . self::fa($ctx, "items.$i.description", __('Pour qui est cette offre')) . '>' . e((string) $it['description']) . '</p>' : '')
                . ($features !== '' ? '<ul class="wl-pricing__features">' . $features . '</ul>' : '')
                . self::button((string) $it['button'], (string) $it['url'], !empty($it['featured']) ? 'primary' : 'secondary', $ctx, "items.$i.button")
                . '</li>';
        }
        return self::open('section', $b, $ctx, 'tone-' . e($d['tone'])) . '<div class="wl-section__inner">' . self::el('h2', $d['title'], $ctx, 'title', __('Nos tarifs')) . '<ul class="wl-pricing__list">' . $items . '</ul></div></section>';
    }

    private static function rStats(array $b, array $d, array $ctx): string
    {
        $items = '';
        foreach ($d['items'] as $i => $it) {
            if ($it['value'] === '' && empty($ctx['edit'])) {
                continue;
            }
            $items .= '<div><dt' . self::fa($ctx, "items.$i.label", __('Ce que mesure ce chiffre')) . '>' . e((string) $it['label']) . '</dt>'
                . '<dd' . self::fa($ctx, "items.$i.value", '00') . '>' . e((string) $it['value']) . '</dd>'
                . ($it['source'] !== '' ? '<dd class="wl-stats__source">' . e(__('Source : {s}', ['s' => $it['source']])) . '</dd>' : '') . '</div>';
        }
        if ($items === '' && empty($ctx['edit'])) {
            return '';
        }
        return self::open('section', $b, $ctx, 'tone-' . e($d['tone'])) . '<div class="wl-section__inner">' . self::el('h2', $d['title'], $ctx, 'title', __('En chiffres')) . '<dl class="wl-stats__list">' . $items . '</dl></div></section>';
    }

    private static function rButton(array $b, array $d, array $ctx): string
    {
        $btn = self::button($d['label'], $d['url'], $d['style'], $ctx, 'label');
        return $btn === '' ? '' : self::open('p', $b, $ctx, 'align-' . e($d['align'])) . $btn . '</p>';
    }

    private static function rContact(array $b, array $d, array $ctx): string
    {
        $content = $ctx['content'] ?? null;
        $sent = ($_GET['sent'] ?? '') === $b['id'];
        $error = ($_GET['form_error'] ?? '') !== '' && ($_GET['form'] ?? '') === $b['id'];
        $inner = self::el('h2', $d['title'], $ctx, 'title', __('Contactez-nous'))
            . self::el('div', $d['intro'], $ctx, 'intro', __('Introduction (facultative)'), 'wl-lead', true);
        if ($sent && empty($ctx['edit'])) {
            $inner .= '<p class="wl-notice" role="status">' . e($d['success']) . '</p>';
            return self::open('section', $b, $ctx, '', ' id="' . e($b['id']) . '"') . $inner . '</section>';
        }
        $ts = (string) time();
        $sig = Crypto::sign($ts . '|' . $b['id'], 'form');
        $field = static fn (string $name, string $label, string $type = 'text', bool $req = true, string $auto = '') =>
            '<p class="wl-field"><label for="' . e($b['id'] . '-' . $name) . '">' . e($label) . ($req ? ' <span aria-hidden="true">*</span>' : '') . '</label>'
            . ($type === 'textarea'
                ? '<textarea id="' . e($b['id'] . '-' . $name) . '" name="' . $name . '" rows="6"' . ($req ? ' required' : '') . ' maxlength="5000"></textarea>'
                : '<input id="' . e($b['id'] . '-' . $name) . '" name="' . $name . '" type="' . $type . '"' . ($req ? ' required' : '') . ($auto !== '' ? ' autocomplete="' . $auto . '"' : '') . ' maxlength="200">')
            . '</p>';
        $inner .= '<form class="wl-form" method="post" action="' . e(Config::basePath() . '/wl/form') . '">'
            . ($error ? '<p class="wl-notice wl-notice--error" role="alert">' . e(__('Le message n’a pas pu être envoyé. Vérifiez les champs et réessayez.')) . '</p>' : '')
            . '<input type="hidden" name="content_id" value="' . (int) ($content['id'] ?? 0) . '">'
            . '<input type="hidden" name="block" value="' . e($b['id']) . '">'
            . '<input type="hidden" name="ts" value="' . e($ts) . '"><input type="hidden" name="sig" value="' . e($sig) . '">'
            . '<p class="wl-hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></p>'
            . '<div class="wl-form__row">' . $field('name', __('Nom'), 'text', true, 'name') . $field('email', __('E-mail'), 'email', true, 'email') . '</div>'
            . ($d['phone'] ? $field('phone', __('Téléphone'), 'tel', false, 'tel') : '')
            . ($d['subject'] ? $field('subject', __('Objet'), 'text', false) : '')
            . $field('message', __('Message'), 'textarea')
            . '<p><button class="wl-btn wl-btn--primary" type="submit"' . self::fa($ctx, 'button', __('Envoyer')) . '>' . e($d['button']) . '</button></p>'
            . self::el('p', $d['consent'], $ctx, 'consent', __('Mention RGPD'), 'wl-muted wl-small')
            . '</form>';
        return self::open('section', $b, $ctx, '', ' id="' . e($b['id']) . '"') . $inner . '</section>';
    }

    private static function rPosts(array $b, array $d, array $ctx): string
    {
        $q = ['type' => 'post', 'visible' => true, 'limit' => (int) $d['count']];
        if (!empty($d['category'])) {
            $q['term'] = (int) $d['category'];
        }
        $current = $ctx['content']['id'] ?? null;
        $posts = array_filter(Content::query($q), static fn ($p) => (int) $p['id'] !== (int) $current);
        $items = '';
        foreach ($posts as $p) {
            $m = Media::find($p['featured_id'] ? (int) $p['featured_id'] : null);
            $items .= '<li>' . ($m && $d['layout'] === 'cards' ? '<a href="' . e(Content::href($p)) . '" tabindex="-1" aria-hidden="true">' . Media::picture($m, ['sizes' => '(min-width: 64rem) 22rem, 100vw', 'alt' => '']) . '</a>' : '')
                . '<h3><a href="' . e(Content::href($p)) . '">' . e($p['title']) . '</a></h3>'
                . '<p class="wl-muted wl-small"><time datetime="' . e(gmdate('c', (int) $p['published_at'])) . '">' . e(fmt_date((int) $p['published_at'])) . '</time></p>'
                . '<p>' . e(Content::summary($p, 150)) . '</p></li>';
        }
        if ($items === '' && empty($ctx['edit'])) {
            return '';
        }
        return self::open('section', $b, $ctx, 'layout-' . e($d['layout'])) . '<div class="wl-section__inner">' . self::el('h2', $d['title'], $ctx, 'title', __('Derniers articles'))
            . ($items !== '' ? '<ul class="wl-posts__list">' . $items . '</ul>' : '<p class="wl-placeholder">' . e(__('Vos articles publiés apparaîtront ici.')) . '</p>') . '</div></section>';
    }

    private static function rDivider(array $b, array $d, array $ctx): string
    {
        if ($d['style'] === 'space') {
            return self::open('div', $b, $ctx, 'is-space', ' aria-hidden="true"') . '</div>';
        }
        return self::open('hr', $b, $ctx, '');
    }

    private static function rHtml(array $b, array $d, array $ctx): string
    {
        if (!empty($ctx['edit'])) {
            return self::open('div', $b, $ctx, 'is-editing') . '<pre class="wl-code-preview">' . e(mb_substr($d['code'], 0, 400) ?: __('Collez votre code dans le panneau de droite')) . '</pre></div>';
        }
        return self::open('div', $b, $ctx, '') . $d['code'] . '</div>';
    }

    // ------------------------------------------------------------------
    // Extraction (SEO, GEO, recherche, Markdown)
    // ------------------------------------------------------------------

    /** Titres H2-H4 dans l'ordre (sommaire, analyse SEO). */
    public static function headings(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $d = (array) ($b['data'] ?? []);
            switch ($b['type'] ?? '') {
                case 'text':
                    array_push($out, ...array_filter(Html::headings((string) ($d['html'] ?? '')), static fn ($h) => $h['level'] <= 4));
                    break;
                case 'heading':
                    $out[] = ['level' => (int) ($d['level'] ?? 2), 'text' => (string) ($d['text'] ?? ''), 'id' => (string) ($d['anchor'] ?? '')];
                    break;
                case 'features': case 'faq': case 'steps': case 'cta': case 'testimonials': case 'pricing': case 'stats': case 'contact': case 'posts':
                    if (!empty($d['title'])) {
                        $out[] = ['level' => 2, 'text' => (string) $d['title'], 'id' => ''];
                    }
                    if (in_array($b['type'], ['faq', 'steps'], true)) {
                        foreach ((array) ($d['items'] ?? []) as $it) {
                            $t = (string) ($it['q'] ?? $it['title'] ?? '');
                            if ($t !== '') {
                                $out[] = ['level' => 3, 'text' => $t, 'id' => ''];
                            }
                        }
                    }
                    break;
            }
        }
        return $out;
    }

    /** Texte brut (comptage de mots, recherche, analyse). */
    public static function text(array $blocks): string
    {
        $parts = [];
        $walk = static function (array $fields, array $data) use (&$walk, &$parts): void {
            foreach ($fields as $name => $f) {
                $v = $data[$name] ?? null;
                if (in_array($f['type'], ['text', 'textarea'], true) && is_string($v) && !in_array($name, ['anchor', 'url', 'url2', 'button', 'button2', 'consent', 'success', 'recipient'], true)) {
                    $parts[] = $v;
                } elseif ($f['type'] === 'rich' && is_string($v)) {
                    $parts[] = plain_text($v);
                } elseif ($f['type'] === 'list' && is_array($v)) {
                    foreach ($v as $item) {
                        $walk($f['fields'], (array) $item);
                    }
                }
            }
        };
        foreach ($blocks as $b) {
            $def = self::registry()[$b['type'] ?? ''] ?? null;
            if ($def && !in_array($b['type'], ['html', 'contact', 'posts'], true)) {
                $walk($def['fields'], (array) ($b['data'] ?? []));
            }
        }
        return trim(implode("\n", array_filter($parts, static fn ($p) => trim($p) !== '')));
    }

    /** Premier paragraphe de texte (« réponse directe » pour le GEO). */
    public static function intro(array $blocks): string
    {
        foreach ($blocks as $b) {
            $d = (array) ($b['data'] ?? []);
            if (($b['type'] ?? '') === 'text' && preg_match('#<p\b[^>]*>(.*?)</p>#is', (string) ($d['html'] ?? ''), $m)) {
                return plain_text($m[1]);
            }
            if (($b['type'] ?? '') === 'hero' && !empty($d['text'])) {
                return plain_text((string) $d['text']);
            }
        }
        return '';
    }

    /** @return list<array{q: string, a: string}> */
    public static function faqs(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'faq') {
                foreach ((array) ($b['data']['items'] ?? []) as $it) {
                    if (trim((string) ($it['q'] ?? '')) !== '' && trim(plain_text((string) ($it['a'] ?? ''))) !== '') {
                        $out[] = ['q' => (string) $it['q'], 'a' => (string) $it['a']];
                    }
                }
            }
        }
        return $out;
    }

    public static function steps(array $blocks): array
    {
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'steps') {
                return ['title' => (string) ($b['data']['title'] ?? ''), 'items' => array_values(array_filter((array) ($b['data']['items'] ?? []), static fn ($s) => trim((string) ($s['title'] ?? '')) !== ''))];
            }
        }
        return [];
    }

    public static function videos(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            if (($b['type'] ?? '') === 'video' && !empty($b['data']['url'])) {
                $out[] = $b['data'];
            }
        }
        return $out;
    }

    /** Images utilisées (analyse des textes alternatifs, image de partage). */
    public static function images(array $blocks): array
    {
        $out = [];
        foreach ($blocks as $b) {
            $d = (array) ($b['data'] ?? []);
            switch ($b['type'] ?? '') {
                case 'image':
                    if (!empty($d['media'])) {
                        $m = Media::find((int) $d['media']);
                        $out[] = ['id' => (int) $d['media'], 'alt' => ($d['alt'] ?? '') !== '' ? $d['alt'] : ($m['alt'] ?? '')];
                    }
                    break;
                case 'hero':
                    if (!empty($d['media'])) {
                        $m = Media::find((int) $d['media']);
                        $out[] = ['id' => (int) $d['media'], 'alt' => $m['alt'] ?? '', 'decorative' => ($d['layout'] ?? '') === 'cover'];
                    }
                    break;
                case 'gallery':
                    foreach ((array) ($d['medias'] ?? []) as $id) {
                        $m = Media::find((int) $id);
                        $out[] = ['id' => (int) $id, 'alt' => $m['alt'] ?? ''];
                    }
                    break;
                case 'text':
                    foreach (Html::images((string) ($d['html'] ?? '')) as $img) {
                        $out[] = ['id' => null, 'src' => $img['src'], 'alt' => (string) $img['alt']];
                    }
                    break;
            }
        }
        return $out;
    }

    public static function links(array $blocks): array
    {
        $out = [];
        $walk = static function (array $fields, array $data) use (&$walk, &$out): void {
            foreach ($fields as $name => $f) {
                $v = $data[$name] ?? null;
                if ($f['type'] === 'rich' && is_string($v)) {
                    array_push($out, ...Html::links($v));
                } elseif ($f['type'] === 'url' && is_string($v) && $v !== '') {
                    $out[] = ['href' => $v, 'text' => ''];
                } elseif ($f['type'] === 'list' && is_array($v)) {
                    foreach ($v as $item) {
                        $walk($f['fields'], (array) $item);
                    }
                }
            }
        };
        foreach ($blocks as $b) {
            $def = self::registry()[$b['type'] ?? ''] ?? null;
            if ($def) {
                $walk($def['fields'], (array) ($b['data'] ?? []));
            }
        }
        return $out;
    }

    public static function firstImage(array $blocks): ?int
    {
        foreach (self::images($blocks) as $img) {
            if (!empty($img['id'])) {
                return (int) $img['id'];
            }
        }
        return null;
    }

    /** Export Markdown fidèle (versions .md, llms-full.txt, MCP). */
    public static function toMarkdown(array $blocks): string
    {
        $md = [];
        foreach ($blocks as $b) {
            $d = (array) ($b['data'] ?? []);
            $t = static fn (string $k): string => trim((string) ($d[$k] ?? ''));
            switch ($b['type'] ?? '') {
                case 'text':
                    $md[] = Html::toMarkdown((string) ($d['html'] ?? ''));
                    break;
                case 'heading':
                    $md[] = str_repeat('#', (int) ($d['level'] ?? 2)) . ' ' . $t('text');
                    break;
                case 'hero':
                    $md[] = ($t('title') !== '' ? '# ' . $t('title') . "\n\n" : '') . Html::toMarkdown((string) $d['text'])
                        . ($t('button') !== '' && $t('url') !== '' ? "\n\n[" . $t('button') . '](' . url($t('url')) . ')' : '');
                    break;
                case 'image':
                    $m = Media::find((int) ($d['media'] ?? 0));
                    if ($m) {
                        $md[] = '![' . ($t('alt') !== '' ? $t('alt') : $m['alt']) . '](' . Media::absoluteUrl($m) . ')' . ($t('caption') !== '' ? "\n*" . $t('caption') . '*' : '');
                    }
                    break;
                case 'video':
                    if ($t('url') !== '') {
                        $md[] = '[' . ($t('title') !== '' ? $t('title') : __('Vidéo')) . '](' . $t('url') . ')';
                    }
                    break;
                case 'columns':
                    foreach ((array) $d['items'] as $it) {
                        $md[] = (trim((string) $it['title']) !== '' ? '### ' . trim((string) $it['title']) . "\n\n" : '') . Html::toMarkdown((string) $it['text']);
                    }
                    break;
                case 'features':
                    $lines = [$t('title') !== '' ? '## ' . $t('title') : ''];
                    if ($t('intro') !== '') {
                        $lines[] = Html::toMarkdown((string) $d['intro']);
                    }
                    foreach ((array) $d['items'] as $it) {
                        $lines[] = '- **' . trim((string) $it['title']) . '** : ' . trim((string) $it['text']);
                    }
                    $md[] = implode("\n\n", array_filter($lines));
                    break;
                case 'cta':
                    $md[] = '## ' . $t('title') . "\n\n" . Html::toMarkdown((string) $d['text']) . ($t('button') !== '' && $t('url') !== '' ? "\n\n[" . $t('button') . '](' . url($t('url')) . ')' : '');
                    break;
                case 'faq':
                    $lines = [$t('title') !== '' ? '## ' . $t('title') : ''];
                    foreach ((array) $d['items'] as $it) {
                        $lines[] = '### ' . trim((string) $it['q']) . "\n\n" . Html::toMarkdown((string) $it['a']);
                    }
                    $md[] = implode("\n\n", array_filter($lines));
                    break;
                case 'takeaways':
                    $md[] = '## ' . $t('title') . "\n\n" . implode("\n", array_map(static fn ($it) => '- ' . trim((string) $it['text']), (array) $d['items']));
                    break;
                case 'steps':
                    $lines = [$t('title') !== '' ? '## ' . $t('title') : ''];
                    foreach (array_values((array) $d['items']) as $i => $it) {
                        $lines[] = ($i + 1) . '. **' . trim((string) $it['title']) . '** — ' . plain_text((string) $it['text']);
                    }
                    $md[] = implode("\n", array_filter($lines));
                    break;
                case 'quote':
                    $md[] = '> ' . str_replace("\n", "\n> ", $t('text')) . ($t('author') !== '' ? "\n>\n> — " . $t('author') . ($t('role') !== '' ? ', ' . $t('role') : '') : '');
                    break;
                case 'testimonials':
                    foreach ((array) $d['items'] as $it) {
                        $md[] = '> ' . trim((string) $it['quote']) . "\n>\n> — " . trim((string) $it['name']) . (trim((string) $it['role']) !== '' ? ', ' . trim((string) $it['role']) : '');
                    }
                    break;
                case 'pricing':
                    $lines = [$t('title') !== '' ? '## ' . $t('title') : ''];
                    foreach ((array) $d['items'] as $it) {
                        $lines[] = '### ' . trim((string) $it['name']) . ' — ' . trim((string) $it['price']) . ' ' . trim((string) $it['period']) . "\n\n" . trim((string) $it['description'])
                            . "\n\n" . implode("\n", array_map(static fn ($l) => '- ' . trim($l), array_filter(explode("\n", (string) $it['features']))));
                    }
                    $md[] = implode("\n\n", array_filter($lines));
                    break;
                case 'stats':
                    $md[] = ($t('title') !== '' ? '## ' . $t('title') . "\n\n" : '') . implode("\n", array_map(static fn ($it) => '- **' . trim((string) $it['value']) . '** ' . trim((string) $it['label']) . (trim((string) ($it['source'] ?? '')) !== '' ? ' (source : ' . trim((string) $it['source']) . ')' : ''), (array) $d['items']));
                    break;
                case 'button':
                    if ($t('label') !== '' && $t('url') !== '') {
                        $md[] = '[' . $t('label') . '](' . url($t('url')) . ')';
                    }
                    break;
                case 'divider':
                    $md[] = '---';
                    break;
            }
        }
        return trim(implode("\n\n", array_filter($md, static fn ($x) => trim($x) !== '')));
    }

    /**
     * Convertit du Markdown (IA, MCP) en blocs : texte, avec FAQ détectée
     * (section « FAQ » / « Questions fréquentes » faite de ### questions).
     */
    public static function fromMarkdown(string $md): array
    {
        $md = str_replace("\r\n", "\n", trim($md));
        $blocks = [];
        if (preg_match('/^##\s+(FAQ|Questions fréquentes|Foire aux questions|Preguntas frecuentes|Frequently asked questions)\s*$/mi', $md, $m, PREG_OFFSET_CAPTURE)) {
            $start = $m[0][1];
            $rest = substr($md, $start + strlen($m[0][0]));
            $end = preg_match('/^##\s+/m', $rest, $mm, PREG_OFFSET_CAPTURE) ? $mm[0][1] : strlen($rest);
            $faqMd = substr($rest, 0, $end);
            $items = [];
            foreach (preg_split('/^###\s+/m', $faqMd) ?: [] as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }
                [$q, $a] = array_pad(explode("\n", $chunk, 2), 2, '');
                $items[] = ['q' => trim($q), 'a' => Html::fromMarkdown(trim($a))];
            }
            $before = trim(substr($md, 0, $start));
            $after = trim(substr($rest, $end));
            if ($before !== '') {
                $blocks[] = self::make('text', ['html' => Html::fromMarkdown($before)]);
            }
            if ($items) {
                $blocks[] = self::make('faq', ['title' => trim($m[1][0]), 'items' => $items]);
            }
            if ($after !== '') {
                $blocks[] = self::make('text', ['html' => Html::fromMarkdown($after)]);
            }
            return $blocks;
        }
        return $md === '' ? [] : [self::make('text', ['html' => Html::fromMarkdown($md)])];
    }

    /** Données pour l'éditeur (JS) : définitions traduites. */
    public static function editorDefinitions(): array
    {
        $out = [];
        foreach (self::registry() as $type => $def) {
            if ($type === 'html' && !Auth::can('*')) {
                $def['adminOnly'] = true;
            }
            $out[$type] = $def;
        }
        return $out;
    }
}
