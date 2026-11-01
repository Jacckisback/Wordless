<?php
declare(strict_types=1);

namespace Wordless;

/**
 * Contenu de départ : un site complet et propre dès l'installation
 * (accueil construit avec des blocs, blog, à propos, contact, pages légales).
 */
final class Starter
{
    public static function create(int $userId): void
    {
        $site = (string) Settings::get('site.name');
        $opts = ['trusted' => true, 'silent' => true];
        $base = ['author_id' => $userId, 'status' => 'published'];

        $contactId = Content::save($base + ['type' => 'page', 'title' => __('Contact'), 'slug' => slugify(__('Contact')), 'seo' => ['schema' => 'ContactPage', 'description' => __('Écrivez-nous : nous répondons rapidement à toutes les demandes.')], 'blocks' => [
            Blocks::make('text', ['html' => '<p>' . e(__('Une question, un projet ? Laissez-nous un message, nous vous répondons sous 24 heures ouvrées.')) . '</p>']),
            Blocks::make('contact', ['title' => __('Envoyer un message')]),
        ], 'menu_order' => 30], null, $opts);

        $aboutId = Content::save($base + ['type' => 'page', 'title' => __('À propos'), 'slug' => slugify(__('À propos')), 'seo' => ['schema' => 'AboutPage', 'description' => __('Qui nous sommes, ce que nous faisons et pourquoi nous le faisons.')], 'blocks' => [
            Blocks::make('text', ['html' => '<p>' . e(__('Présentez-vous ici : votre histoire, votre métier, ce qui vous distingue. Écrivez comme vous parleriez à un client, avec des faits précis (années d’expérience, nombre de clients, lieu).')) . '</p><h2>' . e(__('Notre approche')) . '</h2><p>' . e(__('Expliquez comment vous travaillez, étape par étape. Les visiteurs et les moteurs de réponse IA apprécient les explications concrètes.')) . '</p>']),
        ], 'menu_order' => 20], null, $opts);

        $blogId = Content::save($base + ['type' => 'page', 'title' => __('Blog'), 'slug' => 'blog', 'seo' => ['description' => __('Nos articles, conseils et actualités.')], 'blocks' => [], 'menu_order' => 10], null, $opts);

        $homeId = Content::save($base + ['type' => 'page', 'title' => __('Accueil'), 'slug' => slugify(__('Accueil')), 'seo' => ['title' => $site, 'description' => __('{s} : présentez ici votre activité en une phrase claire, avec votre ville et votre spécialité.', ['s' => $site])], 'blocks' => [
            Blocks::make('hero', [
                'title' => __('Dites en une phrase ce que vous faites, et pour qui'),
                'text' => '<p>' . e(__('Remplacez ce texte : le bénéfice principal pour vos clients, sans jargon. Cliquez sur n’importe quel texte pour le modifier.')) . '</p>',
                'button' => __('Nous contacter'), 'url' => '/' . slugify(__('Contact')),
                'button2' => __('Lire le blog'), 'url2' => '/blog', 'layout' => 'center', 'tone' => 'soft',
            ]),
            Blocks::make('features', [
                'title' => __('Ce que nous proposons'), 'style' => 'grid',
                'items' => [
                    ['title' => __('Votre premier service'), 'text' => __('Décrivez-le en une ou deux phrases concrètes : ce que le client obtient.')],
                    ['title' => __('Votre deuxième service'), 'text' => __('Un chiffre précis vaut mieux qu’un adjectif : délai, prix de départ, résultat.')],
                    ['title' => __('Votre troisième service'), 'text' => __('Terminez par ce qui vous rend différent des autres.')],
                ],
            ]),
            Blocks::make('faq', ['title' => __('Questions fréquentes'), 'items' => [
                ['q' => __('Quels sont vos délais ?'), 'a' => '<p>' . e(__('Répondez directement, en deux ou trois phrases. Ces réponses sont balisées pour Google et reprises par les assistants IA.')) . '</p>'],
                ['q' => __('Combien coûtent vos services ?'), 'a' => '<p>' . e(__('Donnez une fourchette ou un prix de départ : les réponses chiffrées sont plus souvent citées.')) . '</p>'],
                ['q' => __('Dans quelle zone intervenez-vous ?'), 'a' => '<p>' . e(__('Indiquez vos villes ou votre région : c’est essentiel pour le référencement local.')) . '</p>'],
            ]]),
            Blocks::make('posts', ['title' => __('Derniers articles'), 'count' => 3]),
            Blocks::make('cta', ['title' => __('Parlons de votre projet'), 'text' => '<p>' . e(__('Une phrase qui rassure et donne envie d’écrire. Réponse sous 24 h.')) . '</p>', 'button' => __('Prendre contact'), 'url' => '/' . slugify(__('Contact')), 'tone' => 'accent']),
        ], 'menu_order' => 0], null, $opts);

        $catId = Terms::ensure('category', __('Actualités'));
        Content::save($base + ['type' => 'post', 'title' => __('Bienvenue sur votre nouveau site'), 'slug' => slugify(__('Bienvenue sur votre nouveau site')), 'categories' => [$catId],
            'seo' => ['keyword' => 'wordless', 'description' => __('Votre site Wordless est prêt : rapide, sécurisé et optimisé pour Google et les moteurs IA. Voici comment l’utiliser en 5 minutes.')],
            'blocks' => [
                Blocks::make('takeaways', ['items' => [
                    ['text' => __('Cliquez sur un texte pour le modifier, comme dans un document.')],
                    ['text' => __('Le score SEO et le score GEO vous disent quoi améliorer, page par page.')],
                    ['text' => __('Ajoutez votre clé Claude ou ChatGPT pour écrire et optimiser avec l’IA.')],
                ]]),
                Blocks::make('text', ['html' => '<p>' . e(__('Wordless est un CMS léger : vos pages se chargent en quelques millisecondes, sans extension à installer. Le référencement (SEO) et la visibilité dans les moteurs de réponse IA (GEO) sont intégrés dès le départ.')) . '</p>'
                    . '<h2>' . e(__('Comment modifier une page ?')) . '</h2><p>' . e(__('Dans l’administration, ouvrez une page : le texte se modifie directement dans l’aperçu. Le panneau de droite règle le bloc sélectionné, la page et son référencement.')) . '</p>'
                    . '<h2>' . e(__('Que fait Wordless pour mon référencement ?')) . '</h2><ul><li>' . e(__('Balises title, description, Open Graph et données structurées JSON-LD générées automatiquement.')) . '</li><li>' . e(__('Plan du site XML, robots.txt, flux RSS et fichier llms.txt pour les IA.')) . '</li><li>' . e(__('Une version Markdown de chaque page, que les assistants IA lisent facilement.')) . '</li><li>' . e(__('Redirections 301 automatiques quand une adresse change.')) . '</li></ul>'
                    . '<h2>' . e(__('Je viens de WordPress : comment migrer ?')) . '</h2><p>' . e(__('Ouvrez Outils > Migration WordPress : indiquez l’adresse de votre site ou envoyez le fichier d’export. Articles, pages, images, catégories, commentaires, menus et balises SEO (Yoast, Rank Math) sont importés, et vos adresses restent identiques.')) . '</p>']),
                Blocks::make('faq', ['title' => __('Questions fréquentes'), 'items' => [
                    ['q' => __('Faut-il installer des extensions ?'), 'a' => '<p>' . e(__('Non. Cache, sécurité, SEO, formulaire de contact, sauvegardes et statistiques sont intégrés.')) . '</p>'],
                    ['q' => __('Mes données sont-elles en sécurité ?'), 'a' => '<p>' . e(__('Oui : mots de passe chiffrés (Argon2id), double authentification, protection contre les attaques par force brute et sauvegardes quotidiennes.')) . '</p>'],
                    ['q' => __('Puis-je piloter mon site depuis Claude ou ChatGPT ?'), 'a' => '<p>' . e(__('Oui, grâce au connecteur MCP intégré : Réglages > Connecteur IA.')) . '</p>'],
                ]]),
            ]], null, $opts);

        $legal = __('Mentions légales');
        $legalId = Content::save(['author_id' => $userId, 'status' => 'draft', 'type' => 'page', 'title' => $legal, 'slug' => slugify($legal), 'seo' => ['noindex' => true], 'blocks' => [
            Blocks::make('text', ['html' => '<p>' . e(__('[À compléter] Éditeur du site : nom ou raison sociale, adresse, numéro SIREN/SIRET ou NIF, e-mail, directeur de la publication. Hébergeur : nom, adresse et téléphone.')) . '</p>']),
        ], 'menu_order' => 90], null, $opts);
        $privacy = __('Politique de confidentialité');
        $privacyId = Content::save(['author_id' => $userId, 'status' => 'draft', 'type' => 'page', 'title' => $privacy, 'slug' => slugify($privacy), 'seo' => ['noindex' => true], 'blocks' => [
            Blocks::make('text', ['html' => '<p>' . e(__('[À compléter] Ce site mesure son audience sans cookie ni donnée personnelle. Les données envoyées via le formulaire de contact ou les commentaires servent uniquement à vous répondre et ne sont jamais cédées. Indiquez la durée de conservation et comment exercer vos droits (accès, rectification, suppression).')) . '</p>']),
        ], 'menu_order' => 91], null, $opts);

        $primary = Db::insert('menus', ['name' => __('Menu principal'), 'location' => 'primary', 'items' => json_enc([
            ['label' => __('Accueil'), 'type' => 'content', 'id' => $homeId, 'children' => []],
            ['label' => __('Blog'), 'type' => 'content', 'id' => $blogId, 'children' => []],
            ['label' => __('À propos'), 'type' => 'content', 'id' => $aboutId, 'children' => []],
            ['label' => __('Contact'), 'type' => 'content', 'id' => $contactId, 'children' => []],
        ])]);
        $footer = Db::insert('menus', ['name' => __('Pied de page'), 'location' => 'footer', 'items' => json_enc([
            ['label' => $legal, 'type' => 'content', 'id' => $legalId, 'children' => []],
            ['label' => $privacy, 'type' => 'content', 'id' => $privacyId, 'children' => []],
        ])]);
        Settings::setMany([
            // Contenu de démonstration : la migration WordPress peut le remplacer proprement.
            'starter.content' => array_map('intval', Db::col('SELECT id FROM content')),
            'starter.menus' => [$primary, $footer],
            'home.type' => 'page', 'home.page_id' => $homeId, 'blog.page_id' => $blogId,
            'menus.locations' => ['primary' => $primary, 'footer' => $footer],
            'design' => ['preset' => 'atelier'],
        ]);
        foreach (Db::col("SELECT id FROM content WHERE status = 'published'") as $id) {
            Search::index(Content::find((int) $id));
        }
    }
}
