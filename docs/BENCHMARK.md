# Wordless face à WordPress : mesures

Mesures faites le 1er novembre 2026, sur la même machine et dans les mêmes conditions pour les deux logiciels.

- **WordPress 7.1.4** : archive officielle `latest.zip` de wordpress.org, thème par défaut Twenty Twenty-Five, extension officielle *SQLite Database Integration* (aucun serveur MySQL dans l'environnement de test).
- **Wordless 1.0** : archive `dist/wordless-1.0.0.zip` de ce dépôt pour les mesures de performance. Le poids du logiciel est donné pour la version corrective 1.0.1 (`dist/wordless-1.0.1.zip`), qui produit les mêmes pages à un ajout près : le résumé « L'essentiel » dans les données structurées.
- **Même site des deux côtés** : le site de démonstration « Atelier Lumière » (7 pages, 11 articles, 5 images, menus, commentaires) a été construit sous WordPress, puis migré vers Wordless avec l'outil de migration intégré. Mêmes contenus, mêmes images, mêmes adresses.

## Poids du logiciel

| | WordPress 7.1.4 | Wordless 1.0.1 | Écart |
|---|---:|---:|---:|
| Archive à télécharger | 37,2 Mo | 620 Ko | 60× plus petite |
| Taille une fois décompressé | 110,1 Mo | 1,95 Mo | 56× plus léger |
| Nombre de fichiers | 3 782 | 172 | 22× moins |
| Lignes de PHP | 635 602 | 28 529 (dont environ 3 300 de traductions) | 22× moins |
| JavaScript livré | 64,4 Mo | 129 Ko | |
| CSS livré | 8,7 Mo | 64 Ko | |
| Base de données | serveur MySQL/MariaDB à créer | un fichier SQLite créé tout seul | |

Les tailles sont en unités décimales (1 Mo = 1 000 000 octets). Wordless intègre pourtant d'origine ce qui demande des extensions sous WordPress : page builder, SEO et GEO, cache de pages, sécurité et double authentification, redirections, statistiques sans cookies, sauvegardes, assistant IA, connecteur MCP et import WordPress.

## Pages publiques (même contenu)

Mesurées dans Chromium (Playwright), cache du navigateur vide.

| | WordPress | Wordless | Écart |
|---|---:|---:|---:|
| **Accueil** : requêtes | 7 | 2 | 3,5× moins |
| Accueil : poids total | 252 Ko | 42 Ko | 6× plus léger |
| Accueil : HTML | 81 Ko | 33 Ko | |
| Accueil : CSS | 20 Ko (1 fichier) | intégré au HTML | |
| Accueil : JavaScript | 67 Ko (3 fichiers) | 0 | |
| Accueil : police web | 52 Ko | 0 (polices système) | |
| Accueil : images | 31 Ko (JPEG) | 9 Ko (WebP) | |
| Accueil : éléments HTML (DOM) | 189 | 130 | |
| **Article** « Les secrets de l'émaillage » : requêtes | 11 | 3 | |
| Article : poids total | 346 Ko | 54 Ko | 6,4× plus léger |
| Article : éléments HTML (DOM) | 391 | 238 | |

Le CSS du thème Wordless est intégré dans la page, avec une empreinte `sha256` autorisée par la politique de sécurité du contenu (CSP) : aucun style ni script externe ne peut être injecté.

## Lighthouse (mobile, médiane de 3 passages)

Lighthouse 13.6.0, profil mobile par défaut (limitation simulée du réseau et du processeur).

| | WordPress, accueil | Wordless, accueil | WordPress, article | Wordless, article |
|---|:---:|:---:|:---:|:---:|
| Performance | 99 | 100 | 99 | 100 |
| Accessibilité | 98 | 98 | 100 | 100 |
| Bonnes pratiques | 100 | 100 | 100 | 100 |
| SEO | 92 | 100 | 92 | 100 |
| First Contentful Paint | 1,20 s | 0,85 s | 1,35 s | 0,87 s |
| Largest Contentful Paint | 2,10 s | 1,05 s | 2,01 s | 1,05 s |
| Total Blocking Time | 0 ms | 26 ms | 0 ms | 4 ms |
| Cumulative Layout Shift | 0 | 0 | 0 | 0 |

WordPress obtient déjà un bon score de performance avec son thème par défaut. L'écart porte sur le temps d'affichage réel (LCP divisé par deux) et sur le SEO : WordPress perd 8 points faute de méta-description, que Wordless écrit dès la publication. L'audit d'accessibilité signale des deux côtés le même saut de niveau de titre, présent dans le contenu d'origine.

## Travail du serveur

Temps jusqu'au premier octet : médiane de 40 requêtes successives, serveur PHP 8.3.6 intégré (`php -S`), OPcache désactivé pour les deux. Fichiers et requêtes SQL comptés par une instrumentation temporaire (`get_included_files()`, compteur de requêtes).

| | WordPress 7.1.4 | Wordless, page en cache | Wordless, sans cache |
|---|---:|---:|---:|
| Accueil : temps serveur | 65,9 ms | 0,5 ms | 4,2 ms |
| Article : temps serveur | 89,8 ms | 0,5 ms | 3,9 ms |
| Accueil : fichiers PHP chargés | 530 | 4 | 30 |
| Accueil : requêtes SQL | 32 | 0 | 24 |
| Article : fichiers PHP chargés | 534 | 4 | 33 |
| Article : requêtes SQL | 50 | 0 | 28 |
| Mémoire PHP maximale | 10 Mo | 2 Mo | 2 Mo |

Les visiteurs reçoivent la page en cache ; le rendu complet ne concerne que la première visite après une modification et les personnes connectées.

## Sécurité, comportements par défaut

Vérifiés par des requêtes HTTP sur les deux sites de démonstration.

| | WordPress 7.1.4 | Wordless 1.0 |
|---|---|---|
| `xmlrpc.php` | actif, 80 méthodes listées | absent (405) |
| `/wp-json/wp/v2/users` | liste publique des comptes (identifiants et noms) | absent (404) |
| `/?author=1` | redirige vers `/author/admin/` (identifiant révélé) | page d'accueil, rien de révélé |
| Tentatives de connexion | illimitées | bloquées après 6 échecs (15 min, puis doublement) |
| Double authentification | absente du cœur | intégrée (TOTP + codes de secours) |

Contexte : selon Patchstack, 93 % des failles WordPress recensées en 2022 venaient des extensions, 6,7 % des thèmes et 0,6 % du cœur ([WP Tavern](https://wptavern.com/patchstack-tracks-328-more-security-bugs-reported-in-wordpress-plugins-in-2022)).

## Conditions et limites de la mesure

- WordPress tourne avec l'extension SQLite officielle faute de serveur MySQL. Avec MySQL et OPcache, WordPress répond plus vite qu'ici ; Wordless aussi profite d'OPcache. Les valeurs absolues changent selon l'hébergement, l'ordre de grandeur reste.
- Le serveur intégré de PHP traite les requêtes une par une : ces mesures portent sur le travail par page, pas sur la tenue en charge.
- Les tailles de WordPress portent sur l'archive nue, sans extension. Un site WordPress réel en charge souvent plusieurs dizaines.
- Les scores Lighthouse varient légèrement d'un passage à l'autre : la médiane de 3 passages est retenue.

## Refaire la mesure

```bash
# Temps serveur : médiane de 40 requêtes
for i in $(seq 40); do curl -s -o /dev/null -w "%{time_starttransfer}\n" https://votre-site.fr/; done \
  | sort -n | awk '{a[NR]=$1} END {print a[int((NR+1)/2)]*1000 " ms"}'

# Rendu PHP complet (contourne le cache de pages de Wordless)
curl -s -o /dev/null -H "Cookie: wl_s=x" -w "%{time_starttransfer}\n" https://votre-site.fr/

# Lighthouse, profil mobile
npx lighthouse https://votre-site.fr/ --only-categories=performance,accessibility,best-practices,seo

# Poids du logiciel
find . -type f | wc -l                                  # fichiers
find . -name '*.php' -print0 | xargs -0 cat | wc -l     # lignes de PHP
```

Le poids des pages et le nombre de requêtes se lisent aussi dans l'onglet Réseau des outils de développement du navigateur (cache vidé). Le site WordPress de test se reconstruit à l'identique avec `tests/wp-fixture/build.sh`.
