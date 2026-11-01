# Wordless

**Le CMS léger, rapide et sûr, pensé pour être trouvé sur Google et cité par les IA.** On passe de WordPress à Wordless en quelques clics, sans perdre ses adresses ni son référencement.

**[Télécharger Wordless 1.0.0](https://github.com/Jacckisback/Wordless/raw/main/dist/wordless-1.0.0.zip)** (567 Ko) · Logiciel libre sous licence [GNU AGPL-3.0](LICENSE)

- **1,8 Mo** et **169 fichiers**, contre 110 Mo et 3 782 fichiers pour WordPress 7.1.4.
- **1 requête et 32 Ko** pour afficher une page d'accueil, contre 7 requêtes et 252 Ko pour WordPress avec son thème par défaut.
- **3 ms** de temps serveur sans cache, **0,5 ms** avec le cache intégré, contre 50 à 57 ms pour WordPress mesuré dans les mêmes conditions ([détails](docs/BENCHMARK.md)).
- **Aucune dépendance** : pas de Composer, pas de npm, pas de base MySQL à créer. Un hébergement PHP 8.1 suffit.

## Ce qui est inclus d'origine

Ce qu'un site WordPress obtient avec une dizaine d'extensions est ici intégré et réglé par défaut.

| Domaine | Inclus |
|---|---|
| **Page builder** | 22 blocs (bannière, colonnes, FAQ, étapes, tarifs, témoignages, chiffres clés, galerie, vidéo…), édition directe dans la page, réorganisation par glisser-déposer, annuler/rétablir, révisions, aperçu mobile, modèles de pages |
| **SEO** | Titres et descriptions, analyse en direct avec score, sitemaps (avec images), données structurées JSON-LD (article, FAQ, guide pratique, vidéo, fil d'Ariane, organisation), balises Open Graph, URL canoniques, IndexNow (Bing, Yandex…), redirections 301 automatiques quand une adresse change, journal des pages introuvables avec suggestion de redirection |
| **GEO** (visibilité dans ChatGPT, Claude, Perplexity, Google AI) | Score GEO par page, fichiers `llms.txt` et `llms-full.txt`, version Markdown de chaque page (`/page.md`), réglage fin des robots d'IA autorisés, bloc « L'essentiel », FAQ balisée, suivi des visites venues des assistants IA |
| **Vitesse** | Cache de pages servi avant même le démarrage de l'application, CSS intégré, zéro JavaScript sur le site public, images converties en WebP en 3 tailles, chargement différé, polices système |
| **Sécurité** | Double authentification (application TOTP + codes de secours), mots de passe Argon2id, blocage progressif des tentatives, sessions en base, protection CSRF, politique de sécurité du contenu stricte, nettoyage du HTML, protection contre la falsification de requêtes côté serveur, clés d'API chiffrées |
| **Assistant IA** | Rédaction d'articles optimisés SEO et GEO, méta-descriptions, FAQ, points clés, réécriture d'un bloc, textes alternatifs des images (vision), traduction, avec votre clé Claude ou ChatGPT |
| **Directeur artistique IA** | Basé sur les principes d'[Impeccable](https://github.com/pbakaus/impeccable) : 6 styles prêts, identité visuelle créée à partir d'une description, audit de contraste et de typographie, critique d'une page, correction en un clic |
| **Connecteur MCP** | Pilotez le site depuis Claude.ai, Claude Desktop, Claude Code, ChatGPT ou Cursor : écrire, publier, analyser, gérer les images, les redirections et le design ([guide](docs/MCP.md)) |
| **Migration WordPress** | Import par fichier d'export ou directement depuis l'adresse du site : contenus, Gutenberg, Elementor, codes courts, images, catégories, menus, commentaires, réglages Yoast et Rank Math, mêmes adresses ([guide](docs/MIGRATION.md)) |
| **Tableau de bord** | Santé du site sur 5 piliers (vitesse, SEO, GEO, sécurité, contenu), statistiques sans cookies ni bandeau, sources de trafic dont les IA, tâches prioritaires |
| **Le reste** | Commentaires avec modération, formulaire de contact, menus, catégories et étiquettes, utilisateurs et rôles, sauvegardes automatiques, flux RSS, recherche plein texte, interface en français, anglais et espagnol, mode sombre |

## Installer

### Sur un hébergement mutualisé (FTP)

1. Téléchargez l'archive [`wordless-1.0.0.zip`](https://github.com/Jacckisback/Wordless/raw/main/dist/wordless-1.0.0.zip) (567 Ko), décompressez-la et envoyez le contenu du dossier `wordless/` à la racine de votre site par FTP.
2. Ouvrez l'adresse du site dans le navigateur.
3. Renseignez le nom du site, votre e-mail et un mot de passe. C'est tout : la base de données est créée automatiquement.

Configuration requise : PHP 8.1 ou plus récent avec les extensions `pdo_sqlite`, `mbstring`, `dom` et `xml` (présentes chez tous les hébergeurs courants). Recommandées : `gd` (images WebP), `curl` (IA, import WordPress), `intl`, `sodium`, `zip`. L'installeur vérifie tout et indique ce qui manque.

### En ligne de commande

```bash
php bin/wordless install --url=https://exemple.fr --email=vous@exemple.fr --password="Une phrase de passe longue" --site="Mon site"
```

Pour essayer en local :

```bash
php -S 127.0.0.1:8080 router.php
```

## Migrer depuis WordPress

Dans l'administration, menu **Outils > Migration WordPress** :

- **Depuis l'adresse du site** : collez l'adresse de votre site WordPress, Wordless récupère tout par l'API publique (et les brouillons avec un mot de passe d'application).
- **Depuis un fichier** : dans WordPress, *Outils > Exporter > Tout le contenu*, puis envoyez le fichier `.xml`.

Les adresses des pages et articles restent identiques, les anciennes formes (`?p=123`, `/wp-content/uploads/…`, `/feed/`, sitemaps Yoast et Rank Math) sont redirigées automatiquement. Le détail, les limites et la procédure de bascule DNS sont dans [docs/MIGRATION.md](docs/MIGRATION.md).

## Connecter Claude ou ChatGPT

Menu **Intelligence artificielle > Connecteur Claude / ChatGPT** dans l'administration :

- **Claude.ai, Claude Desktop, ChatGPT** : ajoutez un connecteur personnalisé avec l'adresse `https://votre-site.fr/mcp`. La connexion se fait par OAuth, vous autorisez l'accès depuis votre compte Wordless.
- **Claude Code, Cursor, VS Code** : créez une clé API sur la même page, puis copiez la commande proposée, par exemple :

```bash
claude mcp add --transport http wordless https://votre-site.fr/mcp --header "Authorization: Bearer wl_…"
```

L'IA agit avec les droits de votre compte et chaque action est inscrite dans le journal d'activité. Voir [docs/MCP.md](docs/MCP.md).

## Assistant IA

Menu **Intelligence artificielle > Assistant IA** : collez votre clé Claude (console.anthropic.com) ou OpenAI (platform.openai.com). La clé est chiffrée dans la base et n'est jamais réaffichée. Par défaut, Wordless utilise Claude Opus 5.5 ; côté OpenAI, il choisit le modèle le plus récent disponible pour votre clé. Vous pouvez décrire la voix de votre marque : elle sera appliquée à tous les textes générés.

## Configuration du serveur

**Apache et LiteSpeed** : le fichier `.htaccess` fourni suffit (réécritures, protection des dossiers privés, en-têtes de cache, compression).

**Nginx** : exemple de bloc `server` (adaptez le chemin et la version de PHP-FPM) :

```nginx
server {
    server_name exemple.fr;
    root /var/www/wordless;
    index index.php;

    # Dossiers privés et fichiers cachés (sauf .well-known)
    location ~ ^/(data|core|tests|bin)(/|$) { return 403; }
    location ~ /\.(?!well-known/) { return 403; }

    # Médias : jamais de PHP, cache long
    location ^~ /media/ {
        location ~ \.php$ { return 403; }
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Seul index.php s'exécute
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
    location ~ \.php$ { return 403; }

    location / { try_files $uri /index.php$is_args$args; }
}
```

Les tâches de fond (publication programmée, sauvegardes, nettoyage) se lancent toutes seules lors des visites. Sur un serveur très peu visité, vous pouvez ajouter une tâche planifiée :

```
*/10 * * * * php /var/www/wordless/bin/wordless cron
```

## Ligne de commande

```bash
php bin/wordless help                       # toutes les commandes
php bin/wordless backup --full              # sauvegarde base + médias (zip)
php bin/wordless cache:clear                # vider le cache de pages
php bin/wordless user:password <e-mail> <nouveau mot de passe>
php bin/wordless user:2fa-reset <e-mail>    # téléphone perdu
php bin/wordless mcp:key <e-mail> --label="Claude Code"   # clé API MCP
php bin/wordless plugin:list                # extensions
```

## Sauvegardes et mises à jour

- Une sauvegarde de la base est faite chaque jour automatiquement (14 conservées), et vous pouvez en créer une complète (base + médias) depuis **Outils > Sauvegardes**.
- Pour mettre à jour : remplacez `index.php`, `router.php`, `.htaccess`, `core/`, `admin/` et `themes/default/`. Ne touchez pas à `data/` (votre base et vos réglages) ni à `media/` (vos fichiers). La base est mise à niveau automatiquement à la visite suivante.

## Où sont mes données ?

Tout est dans deux dossiers : `data/` (la base SQLite, la configuration, le cache, les journaux et les sauvegardes, inaccessible depuis le web) et `media/` (vos images et fichiers). Copier ces deux dossiers suffit pour déménager un site.

## Pour les développeurs

- [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) : organisation du code et API du noyau.
- [plugins/README.md](plugins/README.md) : écrire une extension (actions et filtres).
- Tests : `php tests/mcp_test.php` (213 vérifications) et `php tests/migrate_test.php` (305 vérifications, nécessite le site WordPress de test décrit en tête du fichier).
- Traductions : les textes source sont en français dans le code ; `php bin/i18n-missing.php` liste ce qui manque dans `core/lang/en.php` et `core/lang/es.php`.
- Archive d'installation : `php bin/build-dist.php`.

## Crédits et licence

Le directeur artistique reprend et adapte les principes et règles d'[Impeccable](https://github.com/pbakaus/impeccable) de Paul Bakaus, publiés sous licence Apache 2.0 (voir `core/Ai/impeccable/NOTICE`).

Wordless n'est ni affilié ni approuvé par la WordPress Foundation ou Automattic. WordPress est une marque de la WordPress Foundation.

Copyright © 2026 Thierry Gustin.

Wordless est un logiciel libre : vous pouvez le redistribuer et le modifier selon les termes de la licence publique générale GNU Affero, version 3 (AGPL-3.0-only), publiée par la Free Software Foundation. Il est distribué sans aucune garantie. Le texte complet est dans le fichier [LICENSE](LICENSE).

En pratique : vous pouvez l'utiliser librement, y compris pour un usage commercial. Si vous modifiez Wordless et le mettez à disposition d'autres personnes, y compris en ligne comme service hébergé, vous devez publier le code source de votre version sous la même licence (pensez alors à changer l'adresse `WL_SOURCE` dans `core/bootstrap.php`).
