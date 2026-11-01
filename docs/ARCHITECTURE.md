# Architecture de Wordless

Wordless est un CMS PHP **sans aucune dépendance** (pas de Composer, pas de framework, pas d'étape de build).
PHP 8.1+, SQLite (un seul fichier), aucun JavaScript sur le site public (sauf la vidéo chargée au clic).

## Arborescence

```
index.php            Point d'entrée unique (cache rapide puis App::run)
router.php           Routeur pour `php -S` (développement)
.htaccess            Réécritures Apache/LiteSpeed + protections
bin/wordless         Ligne de commande (install, cron, backup, import…)
bin/build-dist.php   Construit l'archive à installer : dist/wordless-<version>.zip
bin/i18n-missing.php Liste les chaînes de l'interface non traduites (en, es)
tests/               Tests de bout en bout : migration (php tests/migrate_test.php) et MCP
dist/                Archive prête à envoyer par FTP
core/                Code PHP, namespace Wordless\ (chargement automatique core/<Classe>.php)
  fastcache.php      Sert une page en cache sans démarrer l'application
  hit.php            Statistiques sans cookie (journal du jour)
  Admin/             Administration (Kernel = routes /admin/*)
  Ai/                Clients Claude/OpenAI, assistant de rédaction, directeur artistique
  Mcp/               Serveur MCP (Claude.ai, ChatGPT…) + OAuth 2.1
  Migrate/           Migration WordPress (WXR, API REST)
  views/             Vues PHP de l'admin et de l'installation
  lang/              Traductions (les chaînes source sont en français)
admin/assets/        CSS/JS/icônes de l'admin
themes/default/      Thème : templates/*.php, assets/theme.css, assets/wl.js
plugins/<nom>/plugin.php  Extensions (actions/filtres)
media/               Fichiers envoyés (images + variantes WebP)
data/                Privé : config.php, base SQLite, cache, journaux, sauvegardes, imports
```

## API du noyau (à utiliser par les modules)

| Classe | Rôle | Méthodes principales |
|---|---|---|
| `Db` | SQLite (PDO, requêtes préparées) | `all($sql,$p)`, `one()`, `val()`, `col()`, `insert($table,$row)`, `update($table,$row,$where)`, `upsert()`, `delete()`, `tx(fn)` |
| `Settings` | Réglages clé => JSON | `get($k,$def)`, `set()`, `setMany()`, `secret($k)` / `setSecret($k,$v)` (chiffré) |
| `Config` | data/config.php | `baseUrl()`, `basePath()`, `adminPath()`, `secret()` |
| `Auth` | Sessions, CSRF, rôles | `user()`, `id()`, `can($cap,$content)`, `userCan($user,$cap)`, `actAs($user)`, `csrf()`, `verifyCsrf()`, `hashPassword()` |
| `Content` | Articles/pages | `save(array $in, ?int $id, array $opt): int`, `find($id)`, `byPath()`, `byWpId()`, `query($q)`, `count($q)`, `setStatus($id,$s)`, `permalink($c)`, `href($c)`, `summary($c)` |
| `Blocks` | Page builder | `make($type,$data)`, `sanitize($blocks)`, `render($blocks,$ctx)`, `toMarkdown($blocks)`, `fromMarkdown($md)`, `text($blocks)`, `registry()` |
| `Html` | HTML sûr | `clean($html,'full'|'inline'|'comment')`, `toMarkdown()`, `fromMarkdown()`, `autop()`, `safeUrl()` |
| `Media` | Médiathèque | `import($tmp,$name,$meta,$targetRel)`, `importUrl($url,$meta,$targetRel)`, `find($id)`, `picture($m,$opts)`, `url($m)`, `absoluteUrl($m)` |
| `Terms` | Catégories/étiquettes | `ensure($tax,$name,$slug,$parentId,$desc)`, `assign($contentId,$tax,$ids)`, `forContent()`, `bySlug()` |
| `Redirects` | 301/302/410, 404 | `add($from,$to,$code,$auto)`, `match()`, `importCsv()` |
| `Seo` | SEO + GEO | `analyze($content)` => scores et contrôles, `title()`, `description()`, `siteReport()` |
| `Search` | Plein texte FTS5 | `index($content)`, `query($q)`, `rebuild()` |
| `Cache` | Cache de pages | `purge()` (à appeler après toute modification visible) |
| `Activity` | Journal | `log($action,$object,$id,$label)` |
| `HttpClient` | Requêtes sortantes, anti-SSRF | `get($url,$opts)`, `postJson()`, `download($url,$dest)` |
| `Crypto` | Jetons, signatures, chiffrement | `token()`, `hash()`, `sign()`, `verify()`, `encrypt()`, `decrypt()` |
| `Throttle` | Limitation de fréquence | `blocked($key)`, `hit($key,$max,$window,$lock)` |

### Contenu : champs de `Content::save()`

`type` (post|page), `status` (draft|published|scheduled|private|trash), `title`, `slug`, `parent_id`, `author_id`,
`excerpt`, `blocks` (liste de `{id,type,data}`), `featured_id`, `seo` (`title`, `description`, `keyword`,
`canonical`, `noindex`, `og_image`, `schema`), `lang`, `menu_order`, `comments` (0/1), `sticky`, `published_at`
(timestamp ou date), `categories` / `tags` (ids ou noms), `wp_id`.
Options : `['trusted' => true]` (HTML brut autorisé dans le bloc Code), `['path' => '/2024/05/mon-article']`
(forcer l'adresse, utilisé par la migration pour garder les URL WordPress), `['silent' => true]` (pas de journal ni IndexNow),
`['revision' => true]`.

### Rôles et permissions

`admin` (tout), `editor` (tous les contenus, médias, menus, SEO, design, IA), `author` (ses contenus).
Permissions : `edit_content`, `edit_others`, `publish`, `delete_content`, `upload`, `moderate`, `manage_menus`,
`manage_seo`, `manage_design`, `use_ai`, `view_stats`, `manage_settings`, `manage_users`, `import`.

### Conventions

- Chaînes d'interface : `__('Texte en français')`, `_n('{n} article', '{n} articles', $n)`.
- Échappement : `e($valeur)` dans toutes les vues. Le HTML d'utilisateur passe par `Html::clean()`.
- Écritures admin : POST + CSRF (`csrf_field()` dans les formulaires, en-tête `X-CSRF-Token` en AJAX).
- Liens internes : `href('/chemin')`, absolus : `url('/chemin')`, admin : `admin_url('content')`.
- Pas de style ni de script en ligne sur le site public (CSP stricte avec empreinte du CSS).
