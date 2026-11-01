# Connecteur IA (MCP) : piloter votre site depuis Claude ou ChatGPT

Wordless intègre un **connecteur MCP** (Model Context Protocol, le standard ouvert qui relie les assistants IA à des outils).
Une fois connecté, Claude ou ChatGPT peut, à votre demande :

- lire vos statistiques et vos rapports SEO/GEO, et vous dire quoi améliorer en priorité ;
- rédiger des articles et des pages optimisés pour Google **et** pour les moteurs de réponse IA, les enregistrer en brouillon, puis les publier ou les programmer ;
- corriger le référencement d'une page (titre, méta-description, FAQ, sources, liens internes, textes alternatifs) ;
- importer des images, gérer les redirections, modérer les commentaires, ajuster le design (couleurs, typographies) en gardant un contraste lisible.

Rien à installer : le connecteur fait partie de Wordless.

| | |
|---|---|
| **Adresse du connecteur** | `https://votre-site.fr/mcp` |
| **Connexion** | OAuth (Claude.ai, Claude Desktop, ChatGPT : vous vous connectez à votre administration puis cliquez sur « Autoriser ») ou clé API (Claude Code, Cursor, VS Code, API Claude) |
| **Droits de l'IA** | ceux du compte qui l'a autorisée, ni plus ni moins |
| **Réglages** | Administration › **Connecteur Claude / ChatGPT** (activation, applications autorisées, clés API) |

---

## Avant de commencer

1. **Votre site est en HTTPS et accessible depuis Internet.** Claude.ai et ChatGPT se connectent depuis leurs propres serveurs : un site en local (`localhost`) ne fonctionne qu'avec un client installé sur la même machine (Claude Code, Cursor…).
2. **Utilisez l'adresse exacte du site**, celle des réglages (avec ou sans `www`), suivie de `/mcp`. Une autre variante fait échouer la vérification OAuth.
3. **Wordless est installé à la racine du domaine** (`https://votre-site.fr`, pas `https://votre-site.fr/blog`). Sinon, voir [Installation dans un sous-dossier](#installation-dans-un-sous-dossier).
4. **Le connecteur est activé** (il l'est par défaut) : Administration › Connecteur Claude / ChatGPT.
5. **Choisissez le compte qui autorisera l'IA.** L'IA aura exactement ses droits. Pour la limiter à la rédaction, créez-lui un compte **Auteur** et connectez-vous avec ce compte au moment d'autoriser.

Vérification rapide : l'adresse `https://votre-site.fr/.well-known/oauth-protected-resource` doit afficher un petit document JSON contenant `"resource": "https://votre-site.fr/mcp"`.

---

## Claude.ai (web) et Claude Desktop

1. Ouvrez **Paramètres › Connecteurs** (*Settings › Connectors*).
2. Cliquez sur **Ajouter un connecteur personnalisé** (*Add custom connector*).
3. Nom : par exemple `Wordless – Mon site`. URL : `https://votre-site.fr/mcp`.
   Laissez vides les champs avancés « OAuth Client ID / Secret » : Claude s'inscrit tout seul auprès de votre site.
4. Cliquez sur **Ajouter**, puis sur **Se connecter** (*Connect*).
5. Une fenêtre s'ouvre sur **votre** site : connectez-vous à l'administration si besoin (double authentification comprise), lisez l'écran d'autorisation puis cliquez sur **Autoriser**. Vous revenez sur Claude, le connecteur est actif.
6. Dans une conversation, activez le connecteur depuis le menu des outils (bouton **+** ou « Recherche et outils »).

Claude demande votre accord avant chaque action. Vous pouvez choisir « Toujours autoriser » pour les outils de lecture et garder la confirmation pour ceux qui modifient le site.

- **Claude Desktop** : même menu (Paramètres › Connecteurs). Les connecteurs ajoutés sur claude.ai sont aussi disponibles dans les applications de bureau et mobiles.
- **Offres Team et Enterprise** : un propriétaire de l'organisation ajoute le connecteur une fois (Paramètres d'administration › Connecteurs) ; chaque membre clique ensuite sur « Se connecter » et autorise avec **son propre** compte Wordless.
- **Offres** : les connecteurs personnalisés sont proposés sur les offres payantes (Pro, Max, Team, Enterprise) et, avec des limites, sur l'offre gratuite. Ces conditions évoluent : consultez le centre d'aide d'Anthropic.

---

## ChatGPT

1. Ouvrez **Settings › Apps & Connectors › Advanced settings** et activez le **Developer mode**.
2. Revenez dans **Apps & Connectors** et cliquez sur **Create**.
3. Renseignez : nom (`Wordless – Mon site`), description (facultative), **MCP Server URL** `https://votre-site.fr/mcp`, **Authentication** : *OAuth*. Cochez « I trust this application », puis **Create**.
4. ChatGPT ouvre l'écran d'autorisation de votre site : connectez-vous si besoin et cliquez sur **Autoriser**.
5. Dans une conversation : bouton **+** › **More** › **Developer mode**, puis sélectionnez votre connecteur.

ChatGPT demande une confirmation avant les actions qui modifient le site.

- **Offres** : au moment de la rédaction, le mode développeur est réservé à certaines offres (Plus, Pro, Business, Enterprise, Edu ; pour Business et Enterprise, un administrateur doit l'autoriser). OpenAI fait évoluer ces conditions et les noms des menus.
- Sans mode développeur, les fonctions de recherche de ChatGPT (deep research, connaissances) peuvent utiliser les deux outils de **lecture** `search` et `fetch` : Wordless les fournit dans le format attendu par OpenAI.

---

## Claude Code

### Avec une clé API (recommandé)

1. Créez une clé : **Administration › Connecteur Claude / ChatGPT › Clés API**, ou sur le serveur :

   ```bash
   php bin/wordless mcp:key vous@exemple.fr --label="Claude Code (portable)"
   ```

   La clé commence par `wl_` et **ne s'affiche qu'une fois** : copiez-la dans votre gestionnaire de mots de passe.
2. Ajoutez le connecteur :

   ```bash
   claude mcp add --transport http wordless https://votre-site.fr/mcp --header "Authorization: Bearer wl_..."
   ```

   Ajoutez `--scope user` pour l'utiliser dans tous vos projets.
3. Vérifiez avec `claude mcp list`, puis `/mcp` dans Claude Code.

Si vous partagez la configuration dans un fichier `.mcp.json` versionné, n'y écrivez jamais la clé : utilisez une variable d'environnement.

```json
{
  "mcpServers": {
    "wordless": {
      "type": "http",
      "url": "https://votre-site.fr/mcp",
      "headers": { "Authorization": "Bearer ${WORDLESS_API_KEY}" }
    }
  }
}
```

### Avec OAuth (sans clé)

```bash
claude mcp add --transport http wordless https://votre-site.fr/mcp
```

Puis, dans Claude Code, tapez `/mcp`, choisissez `wordless` › **Authenticate** : votre navigateur ouvre l'écran d'autorisation de votre site.

---

## Claude Desktop avec une clé API (configuration manuelle)

Le menu Connecteurs (OAuth, voir plus haut) est la méthode la plus simple. Pour un site de test non joignable depuis Internet, vous pouvez passer par le pont `mcp-remote` (Node.js requis) dans `claude_desktop_config.json` :

```json
{
  "mcpServers": {
    "wordless": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://votre-site.fr/mcp", "--header", "Authorization:${WORDLESS_AUTH}"],
      "env": { "WORDLESS_AUTH": "Bearer wl_..." }
    }
  }
}
```

---

## Cursor, VS Code et autres clients MCP

**Cursor** — fichier `~/.cursor/mcp.json` (ou `.cursor/mcp.json` dans un projet) :

```json
{
  "mcpServers": {
    "wordless": {
      "url": "https://votre-site.fr/mcp",
      "headers": { "Authorization": "Bearer wl_..." }
    }
  }
}
```

Utilisez une clé API : l'adresse de retour OAuth de Cursor (`cursor://…`) n'est pas acceptée par Wordless, qui n'autorise que des adresses `https://` ou locales (`http://localhost`).

**VS Code** (GitHub Copilot) — fichier `.vscode/mcp.json`, la clé est demandée au premier lancement et n'est pas écrite dans le fichier :

```json
{
  "inputs": [
    { "type": "promptString", "id": "wordless-key", "description": "Clé API Wordless", "password": true }
  ],
  "servers": {
    "wordless": {
      "type": "http",
      "url": "https://votre-site.fr/mcp",
      "headers": { "Authorization": "Bearer ${input:wordless-key}" }
    }
  }
}
```

**Autres clients** : tout client compatible avec le transport MCP « Streamable HTTP » fonctionne, soit avec l'en-tête `Authorization: Bearer wl_...`, soit avec OAuth 2.1 (inscription dynamique et PKCE, que Wordless prend en charge).
Pour tester : `npx @modelcontextprotocol/inspector`, transport *Streamable HTTP*, URL `https://votre-site.fr/mcp`.

---

## API Claude (connecteur MCP de l'API Messages)

Les serveurs d'Anthropic se connectent à votre site : il doit être joignable en HTTPS. Utilisez une clé API Wordless comme jeton :

```bash
curl https://api.anthropic.com/v1/messages \
  -H "x-api-key: $ANTHROPIC_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "anthropic-beta: mcp-client-2025-11-20" \
  -H "content-type: application/json" \
  -d '{
    "model": "claude-opus-5-5",
    "max_tokens": 16000,
    "mcp_servers": [
      { "type": "url", "url": "https://votre-site.fr/mcp", "name": "wordless", "authorization_token": "wl_..." }
    ],
    "tools": [ { "type": "mcp_toolset", "mcp_server_name": "wordless" } ],
    "messages": [ { "role": "user", "content": "Fais le point sur le SEO de mon site et propose trois améliorations." } ]
  }'
```

Le champ `mcp_servers` et l'entrée `mcp_toolset` de `tools` sont obligatoires ensemble ; `mcp_server_name` reprend le `name` du serveur.

---

## Ce que l'IA peut faire

L'IA ne voit que les outils permis par le rôle du compte connecté (un auteur n'a pas accès au design ni aux réglages, par exemple).

### Comprendre le site (lecture seule)

| Outil | Rôle |
|---|---|
| `site_overview` | Vue d'ensemble : contenus, scores SEO et GEO, trafic sur 30 jours (dont visites venues des assistants IA et passages des robots d'IA), problèmes prioritaires, derniers contenus modifiés |
| `get_analytics` | Statistiques détaillées (sans cookie) : visiteurs, canaux, pages les plus vues, sources, appareils, robots |
| `search`, `fetch` | Recherche plein texte et lecture complète d'une page (format compatible ChatGPT) |
| `list_content`, `get_content` | Liste filtrable (par statut, catégorie, score SEO…) et détail d'un contenu (Markdown ou blocs, champs SEO, catégories, scores) |
| `analyze_content` | Audit SEO et GEO d'une page : chaque contrôle avec son conseil |
| `list_terms`, `list_media`, `list_comments`, `get_design` | Catégories et étiquettes, médiathèque, commentaires, jetons de design et contrastes |

### Écrire et modifier

| Outil | Rôle |
|---|---|
| `create_content` | Crée un article ou une page (Markdown ou blocs), **en brouillon par défaut**, avec titre et description SEO, mot-clé, catégories, image à la une |
| `update_content` | Modifie seulement les champs indiqués ; une révision est enregistrée avant chaque modification |
| `publish_content` | Publie maintenant ou programme à une date |
| `delete_content` | Met à la corbeille (récupérable depuis l'administration) ; la page d'accueil est protégée |
| `upload_media`, `update_media` | Importe une image depuis une adresse publique, complète textes alternatifs et légendes |
| `create_term` | Crée une catégorie ou une étiquette |
| `manage_redirects` | Liste les redirections et les erreurs 404 fréquentes, ajoute ou supprime une redirection 301/302 ou une adresse « supprimée » (410) |
| `moderate_comment` | Approuve, met en attente, en indésirable ou à la corbeille |
| `update_design` | Change le style (préréglage, couleurs, polices, tailles, arrondis, densité, mode sombre) ; **une couleur peu lisible est corrigée automatiquement** pour respecter un contraste WCAG AA de 4,5:1, et la correction vous est signalée |
| `update_site_settings` | Administrateurs seulement : nom, slogan, titre et description SEO de l'accueil, robots d'IA autorisés |

### Consignes et raccourcis

- **Ressources** que l'assistant lit avant d'écrire : `wordless://site/guidelines` (langue du site, ton de votre marque, règles SEO et GEO) et `wordless://design/system` (votre charte graphique).
  Pour que l'IA écrive « comme vous », décrivez votre public et votre ton dans les réglages IA de l'administration (« ton de la marque »).
- **Invites prêtes à l'emploi** (proposées par Claude comme raccourcis) : `write_article` (sujet, mot-clé, public), `improve_page` (une page à améliorer), `design_review` (revue du design).

### Exemples de demandes

- « Fais le point sur mon site : qu'est-ce qui m'empêche d'être mieux référencé ? »
- « Écris un article sur l'entretien d'un vélo électrique pour des débutants, avec une FAQ. Garde-le en brouillon. »
- « Améliore la page Tarifs pour qu'elle soit citée par ChatGPT et Perplexity. Montre-moi les changements avant de les enregistrer. »
- « Quelles pages renvoient des erreurs 404 ? Redirige-les vers la page la plus proche. »
- « Ajoute un texte alternatif à toutes les images qui n'en ont pas. »
- « D'où viennent mes visiteurs ce mois-ci, et combien arrivent depuis des assistants IA ? »
- « Propose une palette plus chaleureuse, vérifie le contraste, et applique-la si je suis d'accord. »

### Comment l'IA écrit pour être bien classée et citée

Les consignes envoyées à l'assistant lui demandent : une réponse directe dès l'introduction (40 à 60 mots), des intertitres formulés comme les questions de vos lecteurs, des chiffres précis et datés avec leurs sources, des listes et tableaux, un encadré « L'essentiel », une citation attribuée, des liens internes, et une section **FAQ** en fin d'article (un titre `## FAQ` suivi de questions `###`), que Wordless transforme en bloc FAQ avec données structurées. Le score SEO et le score GEO de chaque contenu lui permettent ensuite de corriger ce qui manque.

---

## Sécurité

- **Consentement explicite.** Aucune IA n'accède à votre site sans que vous ayez cliqué sur « Autoriser » sur **votre** site. L'écran indique l'application, l'adresse vers laquelle vous serez renvoyé et ce qu'elle pourra faire. Vérifiez que vous venez bien de lancer la connexion vous-même ; dans le doute, refusez (la touche Entrée refuse).
  L'écran est protégé contre le « clic piégé » : il ne peut pas être affiché dans un cadre, et une autorisation donnée en moins d'une seconde est ignorée et redemandée.
- **Mêmes droits que votre compte.** L'IA agit au nom de l'utilisateur qui l'a autorisée, avec son rôle (Administrateur, Éditeur ou Auteur). Elle ne voit jamais les mots de passe, ne gère pas les comptes et ne modifie pas les clés d'API. Si le compte est désactivé ou change de rôle, l'accès suit immédiatement.
- **Tout est tracé.** Chaque action apparaît dans le journal d'activité avec la mention « via l'IA connectée (MCP) ».
- **Lecture seule ou écriture.** Chaque outil est marqué comme « lecture seule » ou « modifie le site » (et « destructif » pour la corbeille, le design, les réglages). Claude et ChatGPT s'en servent pour vous demander confirmation : n'accordez « toujours autoriser » qu'aux outils de lecture.
- **Prudence par défaut.** Les contenus sont créés en brouillon, la publication se fait à votre demande, la corbeille est récupérable et une révision est enregistrée avant chaque modification. Le HTML produit par l'IA est nettoyé (aucun script), les fichiers importés sont vérifiés et ré-encodés, et seules des adresses publiques peuvent être téléchargées.
- **Jetons de courte durée.** Avec OAuth, l'assistant reçoit un jeton valable 1 heure, renouvelé automatiquement ; l'accès expire après 60 jours sans utilisation. Chaque renouvellement remplace l'ancien jeton : si un ancien jeton est réutilisé (signe de vol), tout l'accès de l'application est révoqué. Les jetons et les clés ne sont jamais stockés en clair, seulement leur empreinte.
- **Révocation.** Administration › Connecteur Claude / ChatGPT › Applications autorisées › Révoquer. Pour tout couper d'un coup, désactivez le connecteur : `/mcp` et l'autorisation OAuth cessent alors de répondre.
- **Clés API.** Une clé par appareil ou par usage, avec un libellé clair. Elle n'est affichée qu'une fois, reste valable 10 ans et donne les droits du compte : révoquez-la immédiatement si elle a pu fuiter, et ne la publiez jamais (dépôt Git, capture d'écran…).
- **Injections de consignes.** Une IA qui lit des pages web ou des documents peut tomber sur des instructions cachées. Gardez la confirmation pour les actions qui modifient le site, surtout la corbeille, le design et les réglages.
- **Protections techniques.** HTTPS, PKCE obligatoire, codes d'autorisation à usage unique (10 minutes), contrôle de l'origine des requêtes (protection contre le « DNS rebinding »), limitation à 240 requêtes par minute et par jeton.

---

## Limites

### Installation dans un sous-dossier

Claude.ai et ChatGPT cherchent les informations OAuth **à la racine du domaine**. Si Wordless est installé dans `https://exemple.fr/blog`, ils interrogent `https://exemple.fr/.well-known/oauth-authorization-server/blog`, une adresse que Wordless ne reçoit pas.
Les clés API (Claude Code, Cursor, API Claude) fonctionnent sans rien changer. Pour OAuth, ajoutez à la racine du domaine une redirection vers Wordless (remplacez `blog` par votre dossier).

Apache (fichier `.htaccess` à la racine du domaine, pas celui de Wordless) :

```apache
RewriteEngine On
RewriteRule ^\.well-known/oauth-authorization-server/blog$ /blog/.well-known/oauth-authorization-server [R=302,L]
RewriteRule ^\.well-known/openid-configuration/blog$ /blog/.well-known/openid-configuration [R=302,L]
RewriteRule ^\.well-known/oauth-protected-resource/blog/mcp$ /blog/.well-known/oauth-protected-resource [R=302,L]
```

Nginx :

```nginx
location = /.well-known/oauth-authorization-server/blog { return 302 /blog/.well-known/oauth-authorization-server; }
location = /.well-known/openid-configuration/blog       { return 302 /blog/.well-known/openid-configuration; }
location = /.well-known/oauth-protected-resource/blog/mcp { return 302 /blog/.well-known/oauth-protected-resource; }
```

Une réécriture interne vers le `index.php` de Wordless (au lieu d'une redirection) fonctionne aussi : Wordless reconnaît ces adresses.

### Autres limites

- **Adresse exacte** : `https://www.exemple.fr/mcp` et `https://exemple.fr/mcp` sont deux adresses différentes pour OAuth ; utilisez celle des réglages du site.
- **Adresses de retour** : seules les adresses `https://` et locales (`http://localhost`, `http://127.0.0.1`) sont acceptées. Les applications qui utilisent une adresse personnalisée (`cursor://`, `vscode://`…) doivent utiliser une clé API.
- **Fichiers** : l'IA importe des images depuis une adresse publique ; elle ne peut pas envoyer un fichier de votre ordinateur. Ajoutez-le à la médiathèque, puis demandez-lui de l'utiliser.
- **Pages construites en blocs** (bannière, colonnes, appels à l'action…) : l'IA les modifie bloc par bloc pour ne pas perdre la mise en page ; un remplacement en Markdown les transformerait en texte simple.
- **Pas de notifications en temps réel** : le connecteur répond à chaque demande (JSON, transport « Streamable HTTP » sans flux SSE), ce que tous les clients actuels acceptent.

---

## Dépannage

| Symptôme | Cause probable et solution |
|---|---|
| « Impossible de joindre le serveur MCP » | Ouvrez `https://votre-site.fr/.well-known/oauth-protected-resource` : il doit afficher du JSON. Sinon, vérifiez l'adresse (HTTPS, `www`), que le connecteur est activé (réponse 404 s'il est désactivé) et qu'aucun pare-feu ne bloque ces adresses. |
| Erreur 401 en boucle, même après autorisation | Le serveur web ne transmet pas l'en-tête `Authorization` à PHP. Apache : gardez la règle du `.htaccess` fourni. Nginx + PHP-FPM : ajoutez `fastcgi_param HTTP_AUTHORIZATION $http_authorization;`. |
| « Application inconnue » sur l'écran d'autorisation | L'inscription de l'application a expiré ou a été supprimée : retirez le connecteur dans Claude ou ChatGPT, puis ajoutez-le de nouveau. |
| « Adresse de retour refusée » | L'application n'utilise pas l'adresse qu'elle a déclarée, ou une adresse non autorisée (voir Limites). |
| La page de connexion de l'administration s'affiche | Normal si vous n'étiez pas connecté : connectez-vous, vous serez ramené à l'écran d'autorisation. |
| « Vérifiez la demande, puis cliquez à nouveau » | Protection contre le clic piégé : relisez l'écran et cliquez de nouveau sur « Autoriser ». |
| Erreur 403 depuis un navigateur | La page qui appelle le connecteur n'est pas une origine autorisée (le site lui-même, claude.ai, claude.com, chatgpt.com, chat.openai.com ou localhost). |
| Erreur 429 | Plus de 240 requêtes par minute avec le même jeton : patientez une minute. |
| Réponses vides ou bloquées | Ne mettez pas en cache `/mcp`, `/oauth/*` et `/.well-known/*` (CDN, cache serveur) ; si un pare-feu applicatif (ModSecurity, WAF) bloque les requêtes JSON contenant du HTML, ajoutez une exception pour `/mcp`. |

---

## Pour les développeurs

| Adresse | Rôle |
|---|---|
| `POST /mcp` | Point d'accès MCP (JSON-RPC 2.0, versions 2025-11-25, 2025-06-18 et 2025-03-26, messages simples ou en lot) |
| `GET /.well-known/oauth-protected-resource` | Métadonnées de la ressource protégée (RFC 9728), aussi sous `/mcp` |
| `GET /.well-known/oauth-authorization-server` | Métadonnées du serveur d'autorisation (RFC 8414), aussi à `/.well-known/openid-configuration` |
| `POST /oauth/register` | Inscription dynamique des clients (RFC 7591) |
| `GET/POST /oauth/authorize` | Autorisation par code avec PKCE S256 obligatoire, paramètre `iss` dans la réponse (RFC 9207) |
| `POST /oauth/token` | Jetons (`authorization_code`, `refresh_token` avec rotation) |
| `POST /oauth/revoke` | Révocation (RFC 7009) |

Les clients authentifient leurs requêtes avec `Authorization: Bearer <jeton>` ; sans jeton valable, la réponse 401 contient `WWW-Authenticate: Bearer resource_metadata="https://votre-site.fr/.well-known/oauth-protected-resource"`.
Le test de bout en bout `php tests/mcp_test.php` rejoue tous ces parcours sur une copie temporaire du site.
