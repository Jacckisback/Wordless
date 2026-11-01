# Journal des versions

## 1.0.1 — 1er novembre 2026

Version corrective : douze écarts relevés entre la documentation et le comportement réel sont
corrigés, et le connecteur MCP accepte les applications qui s'identifient par l'adresse de leur
fiche (spécification MCP du 25 novembre 2025). La base de données ne change pas : remplacez les
fichiers comme d'habitude (voir « Mettre à jour » dans le README), sans toucher à `data/` ni `media/`.

- **Migration WordPress** : une migration interrompue (onglet fermé, connexion perdue) est
  proposée à la reprise sur la page Migration pendant 7 jours, avec un bouton « Abandonner ».
- **Analyse GEO** : le critère « chiffres » reconnaît aussi les unités anglaises et espagnoles
  (`ms`, `KB`, `MB`, `days`, `años`, `veces`…) ; la lisibilité est calculée avec la formule de la
  langue du contenu, et non plus celle de l'administration.
- **llms.txt** : quand les versions Markdown sont désactivées, les liens pointent vers les pages
  elles-mêmes au lieu d'adresses `.md` inexistantes.
- **Éditeur** : la recherche rapide (Ctrl+K) fonctionne aussi dans l'éditeur, y compris quand le
  curseur est dans la page modifiée.
- **Bloc « L'essentiel »** : ses points clés alimentent enfin les données structurées
  (`abstract` de la page et de l'article, `speakable` vers le bloc).
- **Langue de l'administration** : la langue choisie dans le profil s'applique. Le site public,
  l'assistant IA et la mesure de lisibilité gardent la langue du site ou du contenu.
- **Formulaire de contact** : l'aide du champ destinataire décrit l'envoi réel (vide = tous les
  administrateurs du site).
- **Sessions** : la documentation décrit le bouton tel qu'il est (« déconnecter vos autres
  appareils »).
- **Connexion** : seule l'adresse e-mail ouvre une session ; l'identifiant public d'auteur
  (`/author/<slug>/`) n'est plus accepté.
- **Derrière un CDN ou un proxy** : nouveau réglage `trusted_proxies` (ou variable
  `WL_TRUSTED_PROXIES`). Le blocage des connexions et les statistiques utilisent alors l'adresse
  réelle des visiteurs ; sans réglage, `X-Forwarded-For` reste ignoré.
- **Connecteur MCP** : prise en charge des « Client ID Metadata Documents ». La fiche est lue après
  la connexion de l'utilisateur (5 Ko au plus, HTTPS, sans redirection, adresses internes refusées),
  vérifiée, gardée en cache selon ses en-têtes HTTP (24 h au plus) ; l'écran de consentement
  affiche le site qui la publie.
- **Divers** : commentaire de `robots.txt`, aide et messages de la ligne de commande dans la langue
  du site ; une commande inconnue renvoie le code de sortie 1 ; le message de santé du site renvoie
  vers Réglages › Santé du système ; le User-Agent des requêtes sortantes annonce
  `https://wordless.cloud` ; un commentaire du code citait un fichier `docs/NGINX.md` inexistant
  (la configuration Nginx est dans le README).

Tests : 609 vérifications automatiques (migration 309, connecteur MCP 249, correctifs 51).

## 1.0.0 — 1er novembre 2026

Première version publique.
