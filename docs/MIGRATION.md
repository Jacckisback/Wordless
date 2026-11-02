# Passer de WordPress à Wordless

Wordless reprend votre site WordPress en quelques clics : pages, articles, images, catégories,
auteurs, commentaires, menus et réglages SEO. **Vos adresses restent identiques** : Google, vos
partenaires et vos visiteurs retrouvent chaque page au même endroit, sans erreur 404.

La migration ne modifie jamais votre site WordPress : elle ne fait que le lire.

---

## Avant de commencer

1. **Installez Wordless** sur votre nouvel hébergement (idéalement à l’adresse définitive, ou
   provisoirement sur une sous-adresse). Le contenu de démonstration créé à l’installation est
   remplacé automatiquement par votre site.
2. **Laissez WordPress en ligne** pendant la migration : les images et fichiers sont téléchargés
   directement depuis votre site actuel.
3. Connectez-vous à Wordless avec un compte **administrateur**.

Ouvrez ensuite **Outils > Migration WordPress**. Deux méthodes sont proposées.

---

## Méthode 1 : le fichier d’export (recommandée)

1. Dans WordPress : **Outils > Exporter > Tout le contenu > Télécharger le fichier d’export**.
   Vous obtenez un fichier `.xml` (il peut dépasser 100 Mo pour un gros site, ce n’est pas un problème).
2. Dans Wordless : choisissez **Fichier d’export**, envoyez le fichier, puis lancez la migration.

C’est la méthode la plus complète : le fichier contient les métadonnées des extensions
(Yoast SEO, Rank Math…) et la mise en page **Elementor**, que l’API de WordPress ne fournit pas.

Ce que le fichier ne contient pas, et que Wordless devine : la page d’accueil (la page dont
l’adresse est celle du site), la page des articles, le fuseau horaire (déduit des dates des
articles) et l’emplacement des menus (« principal », « pied de page » d’après leur nom).
Les rôles des utilisateurs ne figurent pas dans le fichier : les comptes créés ont le rôle
« Auteur », à ajuster ensuite dans **Utilisateurs**.

## Méthode 2 : la connexion directe (API WordPress)

1. Dans WordPress : **Utilisateurs > Profil > Mots de passe d’application**. Donnez un nom
   (par exemple « Wordless ») et cliquez sur **Ajouter**. Copiez le mot de passe affiché
   (`abcd efgh ijkl mnop qrst uvwx`). Utilisez un compte **administrateur**.
2. Dans Wordless : choisissez **Connexion directe**, indiquez l’adresse du site
   (`https://www.mon-site.fr`), votre identifiant WordPress et le mot de passe d’application.

Avec le mot de passe d’application, Wordless récupère aussi les brouillons, les contenus
programmés et privés, les menus, les rôles des auteurs et les réglages (page d’accueil,
fuseau horaire, nombre d’articles par page).

**Sans mot de passe**, seuls les contenus publics publiés sont repris (pas de brouillons, ni de
contenus privés, ni d’adresses e-mail des auteurs). C’est utile pour un premier essai.

Bon à savoir :
- les mots de passe d’application exigent normalement HTTPS sur le site WordPress ;
- le mot de passe est **chiffré** pendant la migration et n’est jamais enregistré en clair ;
  vous pouvez le **révoquer** dans WordPress dès la migration terminée ;
- l’option qui lit le titre et la description dans les pages publiées récupère le SEO de
  chaque page quand l’API ne l’expose pas (Rank Math, SEOPress…) ; elle allonge la migration.

---

## Pendant la migration

La migration avance par étapes : catégories et étiquettes, auteurs, images et fichiers, pages et
articles, commentaires, menus, finalisation. Vous suivez la progression à l’écran. Vous pouvez
fermer l’onglet ou perdre la connexion : en revenant sur la page Migration, un bandeau « Une
migration n’est pas terminée » propose de la **reprendre** là où elle s’était arrêtée, ou de
l’abandonner. La reprise reste proposée pendant 7 jours. En ligne de commande, la migration va
d’une traite jusqu’au bout.

**Relancer une migration ne crée jamais de doublon** : les éléments déjà importés sont mis à
jour. C’est pratique pour reprendre les derniers articles publiés sur WordPress juste avant de
basculer votre nom de domaine.

---

## Ce qui est repris

| Élément | Détail |
|---|---|
| Pages | Hiérarchie (pages parentes), ordre, adresse identique, page d’accueil et page des articles |
| Articles | Publiés, programmés (à la même date), brouillons, privés, épinglés, extraits, date de publication |
| Catégories et étiquettes | Noms, descriptions, hiérarchie, mêmes adresses (`/category/…/`, `/tag/…/`) |
| Auteurs | Nom, identifiant d’URL (`/author/…/`), biographie, rôle (connexion directe) ; un compte existant avec la même adresse e-mail est réutilisé |
| Images et fichiers | Fichier original (pas les miniatures : Wordless crée ses propres formats WebP optimisés), texte alternatif, légende, image à la une ; même chemin `AAAA/MM/fichier.jpg` |
| Commentaires | Approuvés et en attente, réponses en fil de discussion, date, auteur |
| Menus | Liens vers pages, articles, catégories et adresses externes, sous-menus (3 niveaux), menu principal et pied de page |
| SEO | Titre, méta-description, expression clé, noindex, URL canonique, image de partage (Yoast SEO, Rank Math, SEOPress, All in One SEO, The SEO Framework) |
| Réglages | Nom du site, slogan, langue, structure des permaliens, barre finale des adresses, fuseau horaire |

### Conversion de la mise en page

Le contenu est converti en blocs Wordless, modifiables directement dans l’éditeur :

| WordPress | Wordless |
|---|---|
| Paragraphes, titres, listes, citations, tableaux, code | Bloc Texte (regroupés) |
| Image, galerie, vidéo YouTube/Vimeo/MP4 | Image, Galerie, Vidéo (chargée au clic) |
| Boutons | Bouton |
| Colonnes, média et texte | Colonnes |
| Couverture (image avec titre) | Bannière |
| FAQ Yoast / Rank Math, accordéons Elementor | FAQ (balisée pour Google et les IA) |
| Sommaire Yoast / Rank Math | Sommaire |
| Derniers articles | Derniers articles |
| Formulaires (Contact Form 7, WPForms, Gravity Forms…) | Formulaire de contact Wordless (à vérifier) |
| Code HTML personnalisé | Bloc Code HTML, nettoyé (scripts et iframes non autorisés retirés) |
| Éditeur classique, `[caption]`, `[gallery]`, `[embed]`, lien YouTube seul sur sa ligne | Texte, figure légendée, Galerie, Vidéo |
| Elementor (fichier d’export) | Bannière, Colonnes, Texte, Image, Vidéo, FAQ, Témoignages… |
| Divi, WPBakery, Avada | Texte et images (mise en page simplifiée) |

Les liens vers votre ancien site deviennent des liens internes et les images pointent vers la
médiathèque Wordless. Les images citées dans les articles mais absentes de la médiathèque
WordPress sont téléchargées aussi.

---

## Ce qui n’est pas repris

- **Les extensions et leurs données** : tables personnalisées, entrées de formulaires,
  abonnements, cours en ligne, statistiques… Wordless intègre déjà cache, SEO, sécurité,
  formulaire de contact, sauvegardes et statistiques.
- **WooCommerce** : produits, commandes et clients ne sont pas importés (signalés dans le rapport).
- **Le design du thème** : couleurs, polices, widgets et barres latérales. Choisissez un style
  dans les réglages de design de Wordless ; la structure des pages, elle, est reprise.
- Les **champs personnalisés** (ACF…) et les **taxonomies personnalisées**. Les types de contenus
  personnalisés (portfolio, références…) sont importés comme pages, à la même adresse ;
  vous pouvez aussi choisir de les ignorer.
- Les **codes courts d’extensions** (`[mon_code …]`) : laissés tels quels dans le texte et
  signalés « à revoir ».
- Les **mots de passe** des utilisateurs : chaque auteur choisit le sien avec
  **« Mot de passe oublié »** sur la page de connexion.
- Les contenus **protégés par mot de passe** : importés en **privé** (Wordless n’a pas de mot
  de passe par page).
- Les **rétroliens** (pingbacks), les commentaires indésirables, la corbeille et les révisions.
- Les **compositions synchronisées** (blocs réutilisables) : leur contenu est intégré à chaque
  page qui les utilise.

---

## Vos garanties SEO

- **Adresses identiques** : chaque page et article garde exactement son chemin
  (`/mon-article/`, `/2024/05/mon-article/`, `/rubrique/mon-article/`…), avec ou sans barre
  finale comme sur WordPress. La page d’accueil statique reste sur `/`.
- **Anciennes adresses redirigées (301)** :
  - `/?p=123`, `/?page_id=45`, `/?cat=7` vers l’adresse définitive ;
  - les anciens slugs d’articles renommés dans WordPress ;
  - les images `/wp-content/uploads/2024/05/photo.jpg`, **y compris les miniatures**
    (`photo-300x200.jpg`) et les versions réduites (`photo-scaled.jpg`) ;
  - les pages de pièces jointes vers le fichier ;
  - toute adresse qui a dû changer (conflit, sous-dossier différent) : elle est listée dans le rapport.
- **Balises SEO reprises** : titre, méta-description, noindex, canonique et image de partage.
  Un titre identique au modèle par défaut (« Titre – Nom du site ») est laissé vide : le modèle
  de Wordless s’applique et reste cohérent si vous renommez le site.
- **Plan du site** : `/sitemap.xml` (les anciennes adresses `/wp-sitemap.xml` et
  `/sitemap_index.xml` y mènent), flux RSS sur `/feed/`.

---

## Après la migration

Le rapport de fin de migration indique :
- le nombre d’éléments importés, mis à jour ou ignorés (avec la raison) ;
- les fichiers qui n’ont pas pu être téléchargés ;
- les contenus **« à revoir »** (formulaire remplacé, code court, bloc d’extension, code HTML nettoyé…) ;
- les adresses conservées et celles redirigées.

Prochaines étapes conseillées :
1. Parcourez le site : accueil, menu, quelques articles et pages.
2. Corrigez les contenus « à revoir ».
3. Prévenez les auteurs (« Mot de passe oublié » pour choisir leur mot de passe).
4. Faites pointer votre nom de domaine vers Wordless. Si de nouveaux articles ont été publiés
   entre-temps sur WordPress, relancez la migration : seules les nouveautés sont ajoutées.
5. Déclarez `/sitemap.xml` dans la Google Search Console et surveillez quelques jours la liste
   des pages introuvables (erreurs 404) dans l’administration : une redirection s’y ajoute en un clic.
6. Gardez une sauvegarde de WordPress quelques semaines.

---

## En ligne de commande

Pour les gros sites ou les hébergements avec un temps d’exécution limité :

```bash
php bin/wordless import:wxr /chemin/export.xml
php bin/wordless import:wp https://www.mon-site.fr --user=admin --password="abcd efgh ijkl mnop qrst uvwx"
```

Options : `--no-media` (ne pas télécharger les fichiers), `--no-comments`, `--no-users`,
`--no-menus`, `--no-settings`, `--keep-starter` (garder le contenu de démonstration),
`--seo-fallback` (lire le SEO dans les pages publiées), `--skip-other-types` (ignorer les types
de contenus personnalisés). Le mot de passe peut aussi être passé par la variable
d’environnement `WL_WP_PASSWORD`.

---

## Questions fréquentes

**Combien de temps dure la migration ?** Quelques secondes pour un petit site, quelques minutes
pour plusieurs milliers d’articles : le plus long est le téléchargement des images.

**Mon site WordPress était dans un sous-dossier (`/blog`).** Installez Wordless dans le même
sous-dossier pour des adresses strictement identiques. Sinon, les anciennes adresses
`/blog/…` sont redirigées vers les nouvelles.

**Des images n’ont pas été téléchargées.** Vérifiez qu’elles s’affichent encore sur WordPress,
puis relancez la migration : seuls les éléments manquants sont repris.

**Puis-je migrer plusieurs fois ?** Oui. Chaque élément WordPress est relié à son équivalent
Wordless : une nouvelle migration met à jour au lieu de dupliquer. Attention, elle remplace
aussi les modifications faites entre-temps dans Wordless sur ces mêmes contenus.
