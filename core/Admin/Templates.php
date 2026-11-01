<?php
declare(strict_types=1);

namespace Wordless\Admin;

use Wordless\Blocks;

/** Modèles de départ proposés dans l'éditeur (structures éprouvées, textes à remplacer). */
final class Templates
{
    public static function list(string $type): array
    {
        $faq = static fn (string $title) => Blocks::make('faq', ['title' => $title, 'items' => [
            ['q' => __('Question que vos clients posent souvent ?'), 'a' => '<p>' . e(__('Réponse directe en deux ou trois phrases, avec un chiffre ou un exemple concret.')) . '</p>'],
            ['q' => __('Deuxième question fréquente ?'), 'a' => '<p>' . e(__('Réponse claire, sans jargon.')) . '</p>'],
            ['q' => __('Troisième question ?'), 'a' => '<p>' . e(__('Réponse courte et utile.')) . '</p>'],
        ]]);
        if ($type === 'post') {
            return [
                ['key' => 'article', 'label' => __('Article optimisé SEO & GEO'), 'description' => __('L’essentiel en tête, réponse directe, sections en questions, FAQ.'), 'blocks' => [
                    Blocks::make('takeaways', ['items' => [['text' => __('Premier point clé, une phrase.')], ['text' => __('Deuxième point clé.')], ['text' => __('Troisième point clé.')]]]),
                    Blocks::make('text', ['html' => '<p>' . e(__('Réponse directe à la question de l’article en 2 à 3 phrases (40 à 60 mots) : c’est ce passage que Google et les IA reprennent.')) . '</p>'
                        . '<h2>' . e(__('Pourquoi est-ce important ?')) . '</h2><p>' . e(__('Développez avec des faits, des chiffres datés et vos sources.')) . '</p>'
                        . '<h2>' . e(__('Comment faire concrètement ?')) . '</h2><ul><li>' . e(__('Étape ou conseil précis')) . '</li><li>' . e(__('Deuxième conseil')) . '</li></ul>']),
                    $faq(__('Questions fréquentes')),
                ]],
                ['key' => 'howto', 'label' => __('Tutoriel pas à pas'), 'description' => __('Introduction, étapes numérotées balisées (HowTo), conseils.'), 'blocks' => [
                    Blocks::make('text', ['html' => '<p>' . e(__('Ce que le lecteur saura faire à la fin, en combien de temps, avec quel matériel.')) . '</p>']),
                    Blocks::make('steps', ['title' => __('Les étapes'), 'items' => [['title' => __('Préparer'), 'text' => '<p>' . e(__('Ce qu’il faut avant de commencer.')) . '</p>'], ['title' => __('Réaliser'), 'text' => '<p>' . e(__('L’action principale, précisément.')) . '</p>'], ['title' => __('Vérifier'), 'text' => '<p>' . e(__('Comment savoir que c’est réussi.')) . '</p>']]]),
                    Blocks::make('text', ['html' => '<h2>' . e(__('Les erreurs à éviter')) . '</h2><ul><li>' . e(__('Erreur fréquente et comment l’éviter')) . '</li></ul>']),
                ]],
                ['key' => 'blank', 'label' => __('Page blanche'), 'description' => __('Un simple bloc de texte.'), 'blocks' => [Blocks::make('text')]],
            ];
        }
        return [
            ['key' => 'landing', 'label' => __('Page de vente / accueil'), 'description' => __('Bannière, avantages, témoignages, FAQ, appel à l’action.'), 'blocks' => [
                Blocks::make('hero', ['title' => __('Le bénéfice principal, en une phrase'), 'text' => '<p>' . e(__('Pour qui, quel résultat, en combien de temps.')) . '</p>', 'button' => __('Commencer'), 'url' => '/contact', 'layout' => 'split']),
                Blocks::make('features', ['title' => __('Pourquoi nous choisir'), 'style' => 'grid']),
                Blocks::make('testimonials', ['title' => __('Ils nous font confiance')]),
                $faq(__('Questions fréquentes')),
                Blocks::make('cta', ['title' => __('Prêt à démarrer ?'), 'text' => '<p>' . e(__('Une phrase qui lève le dernier doute.')) . '</p>', 'button' => __('Nous contacter'), 'url' => '/contact']),
            ]],
            ['key' => 'service', 'label' => __('Page de service'), 'description' => __('Présentation, étapes, tarifs, FAQ et contact.'), 'blocks' => [
                Blocks::make('hero', ['title' => __('Nom du service'), 'text' => '<p>' . e(__('Ce que le client obtient, concrètement.')) . '</p>', 'button' => __('Demander un devis'), 'url' => '/contact', 'layout' => 'center', 'tone' => 'soft']),
                Blocks::make('text', ['html' => '<h2>' . e(__('En quoi consiste ce service ?')) . '</h2><p>' . e(__('Réponse directe, puis détails.')) . '</p>']),
                Blocks::make('steps', ['title' => __('Comment ça se passe')]),
                Blocks::make('pricing', ['title' => __('Tarifs')]),
                $faq(__('Questions fréquentes')),
                Blocks::make('contact', ['title' => __('Demander un devis')]),
            ]],
            ['key' => 'about', 'label' => __('À propos'), 'description' => __('Histoire, chiffres clés, équipe, citation.'), 'blocks' => [
                Blocks::make('text', ['html' => '<p>' . e(__('Qui vous êtes, depuis quand, pour qui vous travaillez.')) . '</p>']),
                Blocks::make('stats', ['title' => __('En chiffres')]),
                Blocks::make('quote', ['text' => __('Une phrase qui résume votre engagement.'), 'author' => __('Votre nom'), 'role' => __('Fondateur')]),
            ]],
            ['key' => 'blank', 'label' => __('Page blanche'), 'description' => __('Partir de zéro.'), 'blocks' => [Blocks::make('text')]],
        ];
    }
}
