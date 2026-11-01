# Wordless face à WordPress : mesures

Mesures faites le 1er novembre 2026, sur la même machine et dans les mêmes conditions pour les deux logiciels. Les chiffres de WordPress viennent de l'archive officielle `latest.zip` (version 7.1.4) téléchargée sur wordpress.org.

## Poids du logiciel

| | WordPress 7.1.4 | Wordless 1.0 |
|---|---:|---:|
| Archive à télécharger | 37,2 Mo | 554 Ko |
| Taille une fois décompressé | 110,1 Mo | 1,8 Mo |
| Nombre de fichiers | 3 782 | 168 |
| Lignes de PHP | 635 602 | 27 988 (dont 3 200 de traductions) |
| JavaScript livré | 64,4 Mo | 127 Ko |
| CSS livré | 8,7 Mo | 64 Ko |
| Base de données | serveur MySQL/MariaDB à créer | un fichier SQLite créé tout seul |

Wordless intègre pourtant d'origine ce qui demande des extensions sous WordPress : page builder, SEO et GEO (Yoast/Rank Math), cache de pages (WP Rocket), sécurité et double authentification (Wordfence), redirections, statistiques sans cookies, sauvegardes, assistant IA, connecteur MCP et import WordPress.

## Pages publiques

Thème par défaut des deux côtés (Twenty Twenty-Five pour WordPress, le thème de base pour Wordless), page d'accueil et un article, mesurés dans Chromium avec Playwright.

| | WordPress 7.1.4 | Wordless 1.0 |
|---|---:|---:|
| Requêtes pour afficher l'accueil | 7 | **1** |
| Poids total de l'accueil | 252 Ko (220 Ko hors image) | **32 Ko** |
| Fichiers JavaScript sur l'accueil | 3 (67 Ko) | **0** |
| Police téléchargée | 1 (52 Ko) | **0** (polices système) |
| Éléments HTML (DOM) de l'accueil | 189 | 107 |
| Requêtes pour afficher un article | 7 | **1** |
| Poids d'un article | 328 Ko (dont 127 Ko d'image) | **35 Ko** (sans image) |

Le CSS du thème Wordless est intégré dans la page (une seule requête), avec une empreinte `sha256` autorisée par la politique de sécurité du contenu (CSP) : aucun style ni script externe ne peut être injecté.

## Temps de réponse du serveur

Médiane de 30 requêtes successives (temps jusqu'au premier octet), serveur PHP 8.3.6 intégré (`php -S`), OPcache désactivé pour les deux.

| | WordPress 7.1.4 | Wordless 1.0 |
|---|---:|---:|
| Accueil, page en cache | pas de cache d'origine | **0,5 ms** |
| Accueil, rendu PHP complet | 57 ms | **2,9 ms** |
| Article, rendu PHP complet | 50 ms | **3,5 ms** |

Soit 15 à 20 fois moins de temps serveur sans cache, et plus de 100 fois moins avec le cache de pages intégré, actif par défaut.

## Conditions et limites de la mesure

- WordPress tourne ici avec l'extension officielle *SQLite Database Integration* 1.8.0 (équipe Performance de WordPress), faute de serveur MySQL dans l'environnement de test. Avec MySQL et OPcache, WordPress répond plus vite qu'ici ; Wordless aussi profite d'OPcache. L'écart en valeur absolue change donc selon l'hébergement, l'ordre de grandeur reste.
- Les contenus des deux sites ne sont pas identiques (site de démonstration de chaque côté). La comparaison porte sur le poids du socle et du thème, pas sur des pages au contenu identique.
- Les chiffres de taille de WordPress portent sur l'archive nue, sans aucune extension. Un site WordPress réel en charge souvent 20 à 40.

## Refaire la mesure

```bash
# Temps serveur : médiane de 30 requêtes
for i in $(seq 30); do curl -s -o /dev/null -w "%{time_starttransfer}\n" https://votre-site.fr/; done \
  | sort -n | awk '{a[NR]=$1} END {print a[int((NR+1)/2)]*1000 " ms"}'

# Rendu PHP complet (contourne le cache de pages de Wordless)
curl -s -o /dev/null -H "Cookie: wl_s=x" -w "%{time_starttransfer}\n" https://votre-site.fr/

# Poids du logiciel
find . -type f | wc -l                                  # fichiers
find . -name '*.php' -print0 | xargs -0 cat | wc -l     # lignes de PHP
```

Le poids des pages et le nombre de requêtes se lisent dans l'onglet Réseau des outils de développement du navigateur (cache vidé).
