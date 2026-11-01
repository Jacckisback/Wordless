# WordPress 7.1.4 test fixture — "Atelier Lumière"

Realistic, reproducible WordPress site (French ceramics workshop in Lyon) used to test a WordPress → new-CMS migration tool, both from the **WXR export** and from the **live REST API**.
Everything below is generated from the live database by `inventory.php`; the same data is available as machine-readable JSON in `fixture.json`.

## Quick start

| What | Value |
|---|---|
| Site URL | http://127.0.0.1:8081/ |
| REST API root | http://127.0.0.1:8081/wp-json/ |
| Admin | http://127.0.0.1:8081/wp-admin/ |
| (Re)start server | `/tmp/wp-test/start.sh` (idempotent; PHP built-in server in background, log `/tmp/wp-test/server.log`) |
| Rebuild everything from scratch | `/tmp/wp-test/build.sh` (wipes `site/`, reinstalls, reseeds, re-exports, regenerates this file, starts the server, runs `verify.sh`) |
| Stop server | `kill $(cat /tmp/wp-test/server.pid)` |
| Smoke tests | `/tmp/wp-test/verify.sh` (HTTP + REST + application password + “crawling does not modify content”) |
| WXR export | `/tmp/wp-test/export.xml` (regenerate: `php /tmp/wp-test/export.php`) |
| Machine-readable inventory | `/tmp/wp-test/fixture.json` |
| Seed script | `/tmp/wp-test/seed.php` (runs on a fresh install only) |

## Credentials

| User | Login | Password | Role | E-mail |
|---|---|---|---|---|
| Émilie Roux (#1) | `admin` | `Admin-Test-2026!` | administrator | admin@example.com |
| Claire Martin (#2) | `claire` | `Claire-Test-2026!` | editor | claire@example.com |
| Lucas Petit (#3) | `lucas` | `Lucas-Test-2026!` | author | lucas@example.com |

**Application password** (admin, name “Migration fixture (REST import)”): `Fixt Atel Lumi 2026 Rest ApiK` (spaces optional: `FixtAtelLumi2026RestApiK`). It is fixed by the seed script, so it survives rebuilds.

```bash
curl -u 'admin:Fixt Atel Lumi 2026 Rest ApiK' 'http://127.0.0.1:8081/wp-json/wp/v2/posts?context=edit&status=any&per_page=100'
# header form: Authorization: Basic YWRtaW46Rml4dCBBdGVsIEx1bWkgMjAyNiBSZXN0IEFwaUs=
```

Application Passwords normally require HTTPS: the mu-plugin `wp-content/mu-plugins/fixture-tweaks.php` adds `add_filter( 'wp_is_application_passwords_available', '__return_true' )` (and disables outgoing e-mail).

## Environment

- WordPress 7.1.4 (db_version 61833), PHP 8.3.6, theme **Twenty Twenty-Five** (block theme, no classic menu locations), language `en_US`, content in French, timezone `Europe/Paris` (dates in the DB are local; `*_gmt` columns are UTC, DST-aware).
- Database: **SQLite** through the official *SQLite Database Integration* plugin (3.1.2). `wp-content/db.php` is the plugin's `db.copy` with `{SQLITE_IMPLEMENTATION_FOLDER_PATH}` and `{SQLITE_PLUGIN}` replaced exactly like the plugin's activation routine does; the plugin is also marked active. `DB_PATH` is pinned in `wp-config.php` to `/tmp/wp-test/site/wp-content/database/.ht.sqlite` (instead of the plugin's randomized default path).
- Web server: `php -S 127.0.0.1:8081 -t /tmp/wp-test/site /tmp/wp-test/router.php` with 4 workers. `router.php` serves existing files directly, routes everything else to `index.php` (pretty permalinks, `/wp-json/`), and returns 403 for dotfiles (the `.ht.sqlite` database).
- Hermetic settings in `wp-config.php`: `WP_HTTP_BLOCK_EXTERNAL` (no outgoing HTTP: oEmbed lookups fail → embeds render as plain links, cached as `_oembed_<md5> = {{unknown}}`), `DISABLE_WP_CRON` (the scheduled post never auto-publishes), `AUTOMATIC_UPDATER_DISABLED`, `WP_DEBUG_LOG` → `/tmp/wp-test/debug.log` (display off).
- wp-admin works (log in as `admin`), but note: opening the Dashboard creates an auto-draft (Quick Draft box) and wp-admin visits trigger update checks that fail (external HTTP blocked) and log PHP warnings in `debug.log`. Auto-drafts are ignored by WXR, REST and the fingerprint; run `build.sh` to get a pristine fixture again.
- `server.log` contains harmless “Failed to poll event” lines: PHP's built-in server prints them when several workers wait for the same connection.
- Determinism: fixed dates (post/modified/comment/registration), fixed upload folders (`2026/01`…`2026/06`), GD-generated images (fixed pixels), fixed application password. Browsing or calling the REST API does not change content tables: `verify.sh` checks the content fingerprint `4323f5a7618d4a52…` before/after a crawl.

## Inventory summary

| Type | Count | Detail |
|---|---|---|
| Users | 3 | administrator, editor, author |
| Posts | 11 | draft: 1, future: 1, private: 1, publish: 8 (publish includes 1 sticky and 1 password-protected) |
| Pages | 7 | publish: 7 (1 child page, 1 Elementor page) |
| Media (attachments) | 5 | 28 files on disk incl. WordPress sub-sizes and one `-scaled` image |
| Categories | 7 | incl. default “Uncategorized”, 2 levels |
| Tags | 8 | |
| Comments | 10 | approved: 8 (incl. 1 pingback), pending: 1, spam: 1 |
| Nav menus (classic) | 2 | 12 items |
| Synced patterns (`wp_block`) | 1 | |
| Navigation posts (`wp_navigation`) | 1 | generated by WordPress from “Menu principal” |
| Application passwords | 1 | admin |

## Users

| ID | Login / slug | Display name | Role | Published posts | Bio |
|---|---|---|---|---|---|
| 1 | `admin` / `admin` | Émilie Roux | administrator | 2 (+ draft 0, future 1, private 1) | Céramiste et fondatrice de l'Atelier Lumière. Formée à Saint-Quentin-la-Poterie, Émilie tourne le grès depuis quinze ans et anime les stages intensifs. |
| 2 | `claire` / `claire` | Claire Martin | editor | 3 (+ draft 1, future 0, private 0) | Responsable de l'émaillage et des cuissons. Claire anime le cours du jeudi soir et teste chaque semaine de nouvelles recettes d'émaux. |
| 3 | `lucas` / `lucas` | Lucas Petit | author | 3 (+ draft 0, future 0, private 0) | Passionné de raku et de modelage, Lucas encadre les ateliers enfants du mercredi et les cuissons raku du samedi. |

## Taxonomies

| ID | Category | Slug | Parent | Posts | URL path | Description |
|---|---|---|---|---|---|---|
| 1 | Uncategorized | `uncategorized` | — | 1 | `/category/uncategorized/` |  |
| 2 | Ateliers | `ateliers` | — | 2 | `/category/ateliers/` | Tous nos ateliers de poterie et de céramique : cours réguliers, stages et séances découverte. |
| 3 | Ateliers enfants | `ateliers-enfants` | `ateliers` | 1 | `/category/ateliers/ateliers-enfants/` | Modelage et poterie pour les 6–12 ans, le mercredi et pendant les vacances scolaires. |
| 4 | Ateliers adultes | `ateliers-adultes` | `ateliers` | 1 | `/category/ateliers/ateliers-adultes/` | Cours hebdomadaires, stages intensifs et soirées découverte pour adultes. |
| 5 | Techniques | `techniques` | — | 3 | `/category/techniques/` | Tournage, émaillage, cuisson : nos articles pour comprendre et progresser. |
| 6 | Émaillage | `emaillage` | `techniques` | 2 | `/category/techniques/emaillage/` | Recettes, modes d'application et défauts d'émail expliqués. |
| 7 | Actualités | `actualites` | — | 2 | `/category/actualites/` | La vie de l'atelier : nouveautés, événements, marchés et portes ouvertes. |

| ID | Tag | Slug | Posts | Description |
|---|---|---|---|---|
| 8 | argile | `argile` | 2 | Terres et argiles : grès, faïence, porcelaine… |
| 9 | émail | `email` | 2 | Tout sur les émaux céramiques : composition, application, cuisson. |
| 10 | grès | `gres` | 2 | Le grès, terre reine de la vaisselle utilitaire. |
| 11 | raku | `raku` | 1 | Technique de cuisson d'origine japonaise, spectaculaire et imprévisible. |
| 12 | tournage | `tournage` | 1 | Le travail au tour de potier : centrage, montée, tournassage. |
| 13 | cuisson | `cuisson` | 2 | Fours, températures et courbes de cuisson. |
| 14 | porcelaine | `porcelaine` | 1 | Travailler la porcelaine, fine et translucide. |
| 15 | Lyon | `lyon` | 3 | Ateliers et événements céramique à Lyon et alentours. |

Accented names get accent-free slugs (`émail` → `email`, `grès` → `gres`, `Émaillage` → `emaillage`). Post format terms: `post-format-video` (#16, 1 post).

## Media

| ID | File (`_wp_attached_file`) | Size | Attached to | Title / alt / caption | Notable sub-size |
|---|---|---|---|---|---|
| 4 | `2026/01/atelier-tour-de-potier.jpg` | 1600×1000 image/jpeg | unattached | Le tour de potier / alt: Mains de potière façonnant un bol sur le tour / caption: Le tour de potier, cœur de l'atelier. | `atelier-tour-de-potier-300x188.jpg` |
| 5 | `2026/02/panorama-atelier-croix-rousse-scaled.jpg` | 2560×1707 image/jpeg | #16 | Panorama de l'atelier / alt: Vue d'ensemble de l'atelier avec les étagères de pièces crues / caption: L'atelier de la Croix-Rousse vu depuis la mezzanine. | `panorama-atelier-croix-rousse-300x200.jpg` |
| 6 | `2026/03/bols-gres-emailles.jpg` | 1200×1200 image/jpeg | #17 | Bols en grès émaillés / alt: Trois bols en grès émaillés bleu céladon / caption: Bols en grès, émail céladon maison. | `bols-gres-emailles-300x300.jpg` |
| 7 | `2026/04/cuisson-raku.jpg` | 800×600 image/jpeg | #18 | Cuisson raku / alt: Pièce incandescente sortie du four raku / caption: Sortie du four à 950 °C. | `cuisson-raku-300x225.jpg` |
| 8 | `2026/06/atelier-enfants-modelage.png` | 1200×800 image/png | #20 | Atelier enfants / alt: Enfants modelant de l'argile autour d'une table / caption: Atelier du mercredi : modelage libre. | `atelier-enfants-modelage-300x200.png` |

Uploads live in `/tmp/wp-test/site/wp-content/uploads` and are served at `http://127.0.0.1:8081/wp-content/uploads/…`. Sub-sizes: thumbnail 150×150 (crop), medium ≤300, medium_large 768w, large ≤1024, 1536×1536, 2048×2048 (only when the source is bigger).
The panorama (#5) is 3000×2000, above `big_image_size_threshold` (2560): WordPress keeps the original (`original_image` in the metadata, URL `/wp-content/uploads/2026/02/panorama-atelier-croix-rousse.jpg`) and serves `/wp-content/uploads/2026/02/panorama-atelier-croix-rousse-scaled.jpg` as the attachment URL (`_wp_attached_file` = `2026/02/panorama-atelier-croix-rousse-scaled.jpg`).

## Posts

| ID | Slug | Title | Status | Author | Date (Paris) | Categories | Tags | Featured | Editor |
|---|---|---|---|---|---|---|---|---|---|
| 16 | `notre-nouvel-atelier-croix-rousse` | Notre nouvel atelier à la Croix-Rousse | publish, **sticky** | admin | 2026-02-10 09:30:00 | actualites | argile, lyon | #5 | gutenberg |
| 17 | `secrets-emaillage-guide-complet` | Les secrets de l'émaillage : guide complet | publish | claire | 2026-03-18 14:00:00 | techniques, emaillage | cuisson, gres, email | #6 | gutenberg |
| 18 | `cuisson-raku-feu-et-fumee` | Cuisson raku : le feu et la fumée | publish | lucas | 2026-04-22 18:15:00 | techniques | cuisson, raku | #7 | classic |
| 19 | `bols-gres-nouvelle-saison` | Nos bols en grès pour la nouvelle saison | publish | claire | 2026-05-30 11:00:00 | actualites | gres, porcelaine | #6 | classic |
| 20 | `atelier-enfants-modeler-argile` | Atelier enfants : modeler l'argile dès 6 ans | publish | lucas | 2026-06-12 08:45:00 | ateliers, ateliers-enfants | argile, lyon | #8 | gutenberg |
| 21 | `stage-ete-tournage-intensif` | Stage d'été : tournage intensif en 5 jours | publish | admin | 2026-07-03 10:00:00 | ateliers, ateliers-adultes | lyon, tournage | #4 | gutenberg |
| 22 | `fermeture-estivale` | Fermeture estivale de l'atelier | publish | lucas | 2026-08-01 12:00:00 | uncategorized |  | — | classic |
| 23 | `recette-email-celadon` | Recette d'émail céladon (réservé aux élèves) | publish, **password** | claire | 2026-09-09 17:30:00 | techniques, emaillage | email | — | gutenberg |
| 24 | `notes-internes-inventaire-emaux` | Notes internes : inventaire des émaux | private | admin | 2026-09-21 19:05:00 | emaillage |  | — | gutenberg |
| 25 | `(empty)` | Nouveaux horaires d'hiver | draft | claire | 2026-10-30 16:20:00 | actualites |  | — | gutenberg |
| 26 | `portes-ouvertes-premier-bol` | Portes ouvertes : venez tourner votre premier bol | future | admin | 2030-04-06 10:00:00 | actualites, ateliers | lyon, tournage | #4 | gutenberg |

### Post details (special features)

#### #16 — Notre nouvel atelier à la Croix-Rousse

- URL: http://127.0.0.1:8081/notre-nouvel-atelier-croix-rousse/ · REST: `/wp/v2/posts/16` · status `publish` · author `admin` · date 2026-02-10 09:30:00 (GMT 2026-02-10 08:30:00) · modified 2026-02-10 09:30:00
- STICKY post
- Gutenberg: core/cover (h2 inside) with the -scaled panorama, core/more, core/columns, core/gallery (3 images, caption), core/embed YouTube (caption), core/separator, core/html (div + OpenStreetMap iframe), core/buttons (2, one is-style-outline)
- Yoast meta
- Blocks: `core/button`×2, `core/buttons`×1, `core/column`×2, `core/columns`×1, `core/cover`×1, `core/embed`×1, `core/gallery`×1, `core/heading`×5, `core/html`×1, `core/image`×3, `core/more`×1, `core/paragraph`×5, `core/separator`×1
- URL alone on its own line (oEmbed candidate): https://www.youtube.com/watch?v=dQw4w9WgXcQ
- Images in content: `/wp-content/uploads/2026/02/panorama-atelier-croix-rousse-1024x683.jpg` (#5 large), `/wp-content/uploads/2026/01/atelier-tour-de-potier-1024x640.jpg` (#4 large), `/wp-content/uploads/2026/03/bols-gres-emailles-1024x1024.jpg` (#6 large), `/wp-content/uploads/2026/04/cuisson-raku.jpg` (#7 full)
- Internal links: `/a-propos/tarifs/` → page #13, `/contact/` → page #14
- SEO (Yoast postmeta): `_yoast_wpseo_content_score` = `60`; `_yoast_wpseo_estimated-reading-time-minutes` = `3`; `_yoast_wpseo_focuskw` = `atelier céramique Lyon`; `_yoast_wpseo_metadesc` = `L'Atelier Lumière s'installe sur les pentes de la Croix-Rousse : 120 m², huit tours et un four raku. Visite en images et en vidéo.`; `_yoast_wpseo_title` = `%%title%% %%page%% %%sep%% %%sitename%%`
- Comments: pingback_approved: 1
- Other postmeta: `_oembed_cc2d7cb3bed61ed522814a412e9d5d36`, `_thumbnail_id`

#### #17 — Les secrets de l'émaillage : guide complet

- URL: http://127.0.0.1:8081/secrets-emaillage-guide-complet/ · REST: `/wp/v2/posts/17` · status `publish` · author `claire` · date 2026-03-18 14:00:00 (GMT 2026-03-18 13:00:00) · modified 2026-04-02 09:15:00
- Gutenberg: h2 with anchor id, h3, ordered list, nested unordered list, core/quote with cite, core/table (thead + caption, U+202F thousands separators), core/image large + caption linked to the media file, core/separator, core/block ref (synced pattern)
- Custom excerpt
- _wp_old_slug = guide-emaillage (old URL should redirect)
- Yoast meta incl. _yoast_wpseo_canonical (external URL) and _yoast_wpseo_primary_category
- Comment thread (3 levels)
- Modified after publication
- Blocks: `core/block`×1, `core/heading`×4, `core/image`×1, `core/list`×3, `core/list-item`×9, `core/paragraph`×4, `core/quote`×1, `core/separator`×1, `core/table`×1
- Custom excerpt: “Silice, fondants, alumine : comprendre la composition d'un émail, choisir sa température de cuisson et éviter les défauts les plus courants.”
- Images in content: `/wp-content/uploads/2026/03/bols-gres-emailles-1024x1024.jpg` (#6 large)
- Internal links: `/wp-content/uploads/2026/03/bols-gres-emailles.jpg` → attachment_file #6, `/notre-nouvel-atelier-croix-rousse/` → post #16, `/a-propos/tarifs/` → page #13
- SEO (Yoast postmeta): `_yoast_wpseo_canonical` = `https://www.atelier-lumiere.example/blog/secrets-emaillage/`; `_yoast_wpseo_content_score` = `90`; `_yoast_wpseo_estimated-reading-time-minutes` = `4`; `_yoast_wpseo_focuskw` = `émaillage céramique`; `_yoast_wpseo_metadesc` = `Composition d'un émail, températures de cuisson, grandes familles d'émaux : notre guide complet de l'émaillage céramique.`; `_yoast_wpseo_opengraph-description` = `Tout ce que nous enseignons sur les émaux, résumé en un article.`; `_yoast_wpseo_opengraph-image` = `http://127.0.0.1:8081/wp-content/uploads/2026/03/bols-gres-emailles.jpg`; `_yoast_wpseo_opengraph-image-id` = `6`; `_yoast_wpseo_opengraph-title` = `Guide de l'émaillage céramique`; `_yoast_wpseo_primary_category` = `6`; `_yoast_wpseo_title` = `%%title%% %%sep%% %%sitename%%`
- Comments: approved: 4
- Other postmeta: `_thumbnail_id`, `_wp_old_slug`

#### #18 — Cuisson raku : le feu et la fumée

- URL: http://127.0.0.1:8081/cuisson-raku-feu-et-fumee/ · REST: `/wp/v2/posts/18` · status `publish` · author `lucas` · date 2026-04-22 18:15:00 (GMT 2026-04-22 16:15:00) · modified 2026-04-22 18:15:00
- CLASSIC editor content (no block comments): blank-line separated paragraphs + single line break (wpautop -> <p>/<br />)
- [caption id="attachment_X" align="aligncenter" width="800"] shortcode around a full-size <img>
- Bare YouTube URL alone on its own line (oEmbed autoembed)
- Unknown shortcode [contact-form-7 id="12" title="Contact"] (plugin not installed, renders as-is)
- Raw <h2> and <ul> HTML in classic content
- Post format: video
- Custom excerpt
- Yoast meta with _yoast_wpseo_meta-robots-noindex = 1
- Shortcodes: `[caption id="attachment_7" align="aligncenter" width="800"]` `[contact-form-7 id="12" title="Contact"]`
- URL alone on its own line (oEmbed candidate): https://www.youtube.com/watch?v=dQw4w9WgXcQ
- Custom excerpt: “Sortir une pièce incandescente du four, l'enfumer dans la sciure et découvrir ses craquelures : bienvenue dans le monde du raku.”
- Images in content: `/wp-content/uploads/2026/04/cuisson-raku.jpg` (#7 full)
- Internal links: `/secrets-emaillage-guide-complet/` → post #17
- SEO (Yoast postmeta): `_yoast_wpseo_content_score` = `30`; `_yoast_wpseo_focuskw` = `cuisson raku`; `_yoast_wpseo_meta-robots-noindex` = `1`; `_yoast_wpseo_metadesc` = `Le raku, cuisson japonaise spectaculaire : déroulement, enfumage, craquelures. Réservez votre session à Lyon.`; `_yoast_wpseo_title` = `%%title%% %%sep%% %%sitename%%`
- Comments: approved: 2
- Other postmeta: `_oembed_cc2d7cb3bed61ed522814a412e9d5d36`, `_thumbnail_id`

#### #19 — Nos bols en grès pour la nouvelle saison

- URL: http://127.0.0.1:8081/bols-gres-nouvelle-saison/ · REST: `/wp/v2/posts/19` · status `publish` · author `claire` · date 2026-05-30 11:00:00 (GMT 2026-05-30 09:00:00) · modified 2026-05-30 11:00:00
- CLASSIC editor content
- <!--more--> teaser split
- Inline <img> using the -300x200 medium URL of the -scaled panorama (class alignright size-medium wp-image-X), linked to the full image
- [gallery ids="..." columns="3" size="medium" link="file"] shortcode
- Internal link in ?p=ID (shortlink) form to the raku post
- &nbsp; spacer paragraph
- Rank Math meta (rank_math_robots = [index])
- Shortcodes: `[gallery ids="4,6,7" columns="3" size="medium" link="file"]`
- Images in content: `/wp-content/uploads/2026/02/panorama-atelier-croix-rousse-300x200.jpg` (#5 medium)
- Internal links: `/wp-content/uploads/2026/02/panorama-atelier-croix-rousse-scaled.jpg` → attachment_file #5, `/a-propos/tarifs/` → page #13, `/?p=18` → post #18
- SEO (Rank Math postmeta): `rank_math_description` = `Découvrez notre collection de bols en grès tournés à la main à Lyon : céladon, tenmoku et blanc satiné.`; `rank_math_focus_keyword` = `bols en grès,céramique artisanale`; `rank_math_internal_links_processed` = `1`; `rank_math_primary_category` = `7`; `rank_math_robots` = `["index"] (serialized array)`; `rank_math_seo_score` = `81`; `rank_math_title` = `%title% %sep% %sitename%`
- Other postmeta: `_thumbnail_id`

#### #20 — Atelier enfants : modeler l'argile dès 6 ans

- URL: http://127.0.0.1:8081/atelier-enfants-modeler-argile/ · REST: `/wp/v2/posts/20` · status `publish` · author `lucas` · date 2026-06-12 08:45:00 (GMT 2026-06-12 06:45:00) · modified 2026-06-12 08:45:00
- Yoast FAQ block <!-- wp:yoast/faq-block --> with 4 questions (answers contain <strong> and <a>; HTML in the JSON attributes is \u003c-escaped)
- core/image medium (-300x200 PNG) aligned right, linked to the media file
- Link to a category archive URL
- Yoast meta incl. _yoast_wpseo_primary_category
- Comments: 1 approved, 1 pending, 1 spam
- Blocks: `core/heading`×2, `core/image`×1, `core/list`×1, `core/list-item`×4, `core/paragraph`×3, `yoast/faq-block`×1
- Images in content: `/wp-content/uploads/2026/06/atelier-enfants-modelage-300x200.png` (#8 medium)
- Internal links: `/wp-content/uploads/2026/06/atelier-enfants-modelage.png` → attachment_file #8, `/category/ateliers/ateliers-enfants/` → category #3, `/bols-gres-nouvelle-saison/` → post #19, `/contact/` → page #14
- SEO (Yoast postmeta): `_yoast_wpseo_content_score` = `90`; `_yoast_wpseo_focuskw` = `atelier poterie enfants`; `_yoast_wpseo_metadesc` = `Atelier poterie pour enfants de 6 à 12 ans à Lyon : modelage, engobes et cuisson. Questions fréquentes et inscriptions.`; `_yoast_wpseo_primary_category` = `3`; `_yoast_wpseo_title` = `Atelier poterie enfants à Lyon %%sep%% %%sitename%%`
- Comments: hold: 1, approved: 1, spam: 1
- Other postmeta: `_thumbnail_id`

#### #21 — Stage d'été : tournage intensif en 5 jours

- URL: http://127.0.0.1:8081/stage-ete-tournage-intensif/ · REST: `/wp/v2/posts/21` · status `publish` · author `admin` · date 2026-07-03 10:00:00 (GMT 2026-07-03 08:00:00) · modified 2026-07-03 10:00:00
- Gutenberg: core/table without caption, list, buttons
- Custom excerpt
- Rank Math meta with rank_math_robots = [noindex, nofollow]
- Blocks: `core/button`×1, `core/buttons`×1, `core/heading`×2, `core/list`×1, `core/list-item`×3, `core/paragraph`×2, `core/table`×1
- Custom excerpt: “Du lundi au vendredi, cinq matinées pour apprendre à centrer, monter et tournasser vos premières pièces.”
- Internal links: `/atelier-enfants-modeler-argile/` → post #20, `/contact/` → page #14
- SEO (Rank Math postmeta): `rank_math_description` = `Stage intensif de tournage à Lyon en juillet : 5 matinées, 6 participants maximum, terre et cuissons comprises.`; `rank_math_focus_keyword` = `stage tournage lyon,stage poterie été`; `rank_math_internal_links_processed` = `1`; `rank_math_robots` = `["noindex","nofollow"] (serialized array)`; `rank_math_seo_score` = `74`; `rank_math_title` = `Stage de tournage à Lyon %sep% %sitename%`
- Other postmeta: `_thumbnail_id`

#### #22 — Fermeture estivale de l'atelier

- URL: http://127.0.0.1:8081/fermeture-estivale/ · REST: `/wp/v2/posts/22` · status `publish` · author `lucas` · date 2026-08-01 12:00:00 (GMT 2026-08-01 10:00:00) · modified 2026-08-01 12:00:00
- CLASSIC editor content with emoji (4-byte UTF-8)
- No category given -> default category "Uncategorized"
- No tags, no featured image
- Other postmeta: —

#### #23 — Recette d'émail céladon (réservé aux élèves)

- URL: http://127.0.0.1:8081/recette-email-celadon/ · REST: `/wp/v2/posts/23` · status `publish` · author `claire` · date 2026-09-09 17:30:00 (GMT 2026-09-09 15:30:00) · modified 2026-09-09 17:30:00
- PASSWORD-PROTECTED (post_password = celadon2026): REST returns content.protected=true and empty rendered content unless ?password= is given
- Blocks: `core/paragraph`×2, `core/table`×1
- Other postmeta: —

#### #24 — Notes internes : inventaire des émaux

- URL: http://127.0.0.1:8081/notes-internes-inventaire-emaux/ · REST: `/wp/v2/posts/24` · status `private` · author `admin` · date 2026-09-21 19:05:00 (GMT 2026-09-21 17:05:00) · modified 2026-09-21 19:05:00
- PRIVATE post (only visible with authentication)
- Blocks: `core/list`×1, `core/list-item`×3, `core/paragraph`×2
- Other postmeta: —

#### #25 — Nouveaux horaires d'hiver

- URL: http://127.0.0.1:8081/?p=25 · REST: `/wp/v2/posts/25` · status `draft` · author `claire` · date 2026-10-30 16:20:00 (GMT 0000-00-00 00:00:00) · modified 2026-10-30 16:45:00
- DRAFT: empty post_name (WordPress does not assign slugs to drafts) and post_date_gmt 0000-00-00 00:00:00 (floating date)
- Blocks: `core/list`×1, `core/list-item`×3, `core/paragraph`×2
- Other postmeta: —

#### #26 — Portes ouvertes : venez tourner votre premier bol

- URL: http://127.0.0.1:8081/?p=26 · REST: `/wp/v2/posts/26` · status `future` · author `admin` · date 2030-04-06 10:00:00 (GMT 2030-04-06 08:00:00) · modified 2026-10-28 11:00:00
- SCHEDULED post (status future, 2030-04-06 10:00 Europe/Paris). WP-Cron is disabled so it never auto-publishes
- Blocks: `core/button`×1, `core/buttons`×1, `core/image`×1, `core/paragraph`×1
- Images in content: `/wp-content/uploads/2026/01/atelier-tour-de-potier-300x188.jpg` (#4 medium)
- Internal links: `/contact/` → page #14
- Other postmeta: `_thumbnail_id`

## Pages

| ID | Path | Title | Parent | Order | Author | Editor | Role |
|---|---|---|---|---|---|---|---|
| 3 | `/politique-de-confidentialite/` | Politique de confidentialité | — | 90 | admin | gutenberg | privacy policy page |
| 10 | `/` | Accueil | — | 0 | admin | gutenberg | **static front page** (`page_on_front`; `/accueil/` 301 → `/`) |
| 11 | `/blog/` | Blog | — | 40 | admin | empty | **posts page** (`page_for_posts`) |
| 12 | `/a-propos/` | À propos | — | 20 | admin | gutenberg |  |
| 13 | `/a-propos/tarifs/` | Tarifs | #12 | 1 | claire | gutenberg |  |
| 14 | `/contact/` | Contact | — | 50 | admin | gutenberg |  |
| 15 | `/nos-ateliers/` | Nos ateliers | — | 10 | admin | elementor | **Elementor** (template `elementor_header_footer`) |

#### Page #3 — Politique de confidentialité

- Default WordPress privacy page (#3) translated and published; still wp_page_for_privacy_policy
- Blocks: `core/heading`×3, `core/paragraph`×3
- Other postmeta: `_wp_page_template`

#### Page #10 — Accueil

- Static front page (show_on_front=page, page_on_front)
- core/cover (h1 inside), core/columns x3, core/buttons, dynamic core/latest-posts, core/block ref to the synced pattern
- Yoast meta (title uses %%sitedesc%%)
- Blocks: `core/block`×1, `core/button`×2, `core/buttons`×1, `core/column`×3, `core/columns`×1, `core/cover`×1, `core/heading`×5, `core/latest-posts`×1, `core/paragraph`×5
- SEO (Yoast postmeta): `_yoast_wpseo_focuskw` = `atelier poterie Lyon`; `_yoast_wpseo_metadesc` = `Cours de poterie, stages de tournage et ateliers enfants à Lyon Croix-Rousse : céramique artisanale en grès.`; `_yoast_wpseo_title` = `%%sitename%% %%sep%% %%sitedesc%%`
- Other postmeta: —

#### Page #11 — Blog

- Posts page (page_for_posts) - empty content, WordPress lists the posts at /blog/
- Other postmeta: —

#### Page #12 — À propos

- Parent page of "Tarifs"
- core/media-text, core/list, core/quote
- Rank Math meta
- Blocks: `core/heading`×2, `core/list`×1, `core/list-item`×3, `core/media-text`×1, `core/paragraph`×4, `core/quote`×1
- SEO (Rank Math postmeta): `rank_math_description` = `L'histoire de l'Atelier Lumière et de son équipe : Émilie, Claire et Lucas, céramistes à Lyon.`; `rank_math_focus_keyword` = `atelier céramique lyon,céramiste lyon`; `rank_math_seo_score` = `68`; `rank_math_title` = `%title% %sep% %sitename%`
- Other postmeta: —

#### Page #13 — Tarifs

- Child page of "À propos" (URL /a-propos/tarifs/)
- core/table
- Yoast meta
- Blocks: `core/button`×1, `core/buttons`×1, `core/heading`×1, `core/paragraph`×2, `core/table`×1
- SEO (Yoast postmeta): `_yoast_wpseo_focuskw` = `tarif cours poterie lyon`; `_yoast_wpseo_metadesc` = `Atelier découverte 45 €, cours hebdomadaire 420 € le trimestre, stage intensif 390 € : tous nos tarifs.`; `_yoast_wpseo_title` = `Tarifs des cours de poterie %%sep%% %%sitename%%`
- Other postmeta: —

#### Page #14 — Contact

- core/shortcode block with the unknown shortcode [contact-form-7 id="12" title="Contact"]
- core/html with an OpenStreetMap <iframe>
- tel: and mailto: links
- Yoast meta description only
- Blocks: `core/heading`×3, `core/html`×1, `core/list`×1, `core/list-item`×3, `core/paragraph`×2, `core/shortcode`×1
- Shortcodes: `[contact-form-7 id="12" title="Contact"]`
- SEO (Yoast postmeta): `_yoast_wpseo_metadesc` = `Contactez l'Atelier Lumière : 27 bis montée de la Grande-Côte, Lyon 1er. Téléphone, e-mail, horaires et plan d'accès.`
- Other postmeta: —

#### Page #15 — Nos ateliers

- Elementor page: real content only in postmeta _elementor_data (JSON); post_content is Elementor's plain-HTML fallback
- Widgets: heading, text-editor, button, image, icon-list, spacer, divider, video (youtube_url), accordion, toggle, testimonial; containers (incl. nested isInner containers) + one legacy section/column structure
- Elementor postmeta: `_elementor_edit_mode` = `builder`, `_elementor_template_type` = `wp-page`, `_elementor_version` = `3.25.0`, `_elementor_page_settings` = `{"hide_title":"yes"}`, `_elementor_page_assets` = `{"styles":["widget-heading","widget-text-editor","widget-image","widget-icon-list","widget-spacer","widget-divider","widget-video","widget-accordion","widget-toggle","widget-testimonial"]}`, `_elementor_data` = `JSON, 8035 bytes (see elementor.data_summary)`
- `_elementor_data`: 5 top-level elements; element types {"container":6,"widget":17,"section":1,"column":2}; widgets {"accordion":1,"button":1,"divider":1,"heading":5,"icon-list":1,"image":1,"spacer":2,"testimonial":1,"text-editor":2,"toggle":1,"video":1}; images used: #8, #6, #5; video `https://www.youtube.com/watch?v=dQw4w9WgXcQ`.
- Stored like Elementor does: wp_slash( wp_json_encode( $data ) ) -> slashes escaped as \/ and accents as \u00e9 in the database and in the WXR.
- `post_content` is only Elementor's plain-HTML fallback (headings, paragraphs, links, `<figure>`, `<ul>`, the bare YouTube URL…), not the real layout.
- Other postmeta: `_oembed_cc2d7cb3bed61ed522814a412e9d5d36`, `_wp_page_template`

## Synced pattern and navigation posts

- #9 `wp_block` “Bandeau newsletter” (slug `bandeau-newsletter`, REST `/wp/v2/blocks/9`): blocks {"core\/heading":1,"core\/paragraph":1}; referenced by #10 Accueil, #17 Les secrets de l'émaillage : guide complet. Synced pattern (post_type wp_block) referenced with <!-- wp:block {"ref":ID} /--> from the home page and from the émaillage post
- #39 `wp_navigation` “Menu principal” (slug `menu-principal`, REST `/wp/v2/navigation/39`): blocks {"core\/navigation-link":6,"core\/navigation-submenu":2}. wp_navigation post generated by WordPress (WP_Classic_To_Block_Menu_Converter) from the classic menu "Menu principal"; used by the Twenty Twenty-Five header Navigation block

## Comments

| ID | Post | Parent | Depth | Type | Status | Author | User | Date | In WXR | Public REST |
|---|---|---|---|---|---|---|---|---|---|---|
| 2 | #17 `secrets-emaillage-guide-complet` | — | 1 | comment | approved (`1`) | Sophie Bernard | — | 2026-03-19 08:12:00 | yes | yes |
| 3 | #17 `secrets-emaillage-guide-complet` | 2 | 2 | comment | approved (`1`) | Claire Martin | `claire` | 2026-03-19 10:40:00 | yes | yes |
| 4 | #17 `secrets-emaillage-guide-complet` | 3 | 3 | comment | approved (`1`) | Sophie Bernard | — | 2026-03-19 12:05:00 | yes | yes |
| 5 | #17 `secrets-emaillage-guide-complet` | — | 1 | comment | approved (`1`) | Marc Dubois | — | 2026-03-21 18:30:00 | yes | yes |
| 6 | #16 `notre-nouvel-atelier-croix-rousse` | — | 1 | pingback | approved (`1`) | Les secrets de l'émaillage : guide complet – Atelier Lumière | — | 2026-03-18 14:00:07 | yes | with `?type=pingback` |
| 7 | #18 `cuisson-raku-feu-et-fumee` | — | 1 | comment | approved (`1`) | Pierre Garnier | — | 2026-04-23 09:00:00 | yes | yes |
| 8 | #18 `cuisson-raku-feu-et-fumee` | 7 | 2 | comment | approved (`1`) | Lucas Petit | `lucas` | 2026-04-23 11:30:00 | yes | yes |
| 9 | #20 `atelier-enfants-modeler-argile` | — | 1 | comment | approved (`1`) | Julie Moreau | — | 2026-06-13 20:15:00 | yes | yes |
| 10 | #20 `atelier-enfants-modeler-argile` | — | 1 | comment | hold (`0`) | Antoine Lefèvre | — | 2026-06-14 07:50:00 | yes | auth only |
| 11 | #20 `atelier-enfants-modeler-argile` | — | 1 | comment | spam (`spam`) | Montres de luxe pas chères | — | 2026-06-15 03:22:00 | **no** | auth only |

Comment tree:

```
#17 Les secrets de l'émaillage : guide complet
   └─ #2 Sophie Bernard [comment, approved] Merci pour ce guide très clair ! Est-ce que vous proposez d…
      └─ #3 Claire Martin [comment, approved] Bonjour Sophie, tous nos émaux sont sans plomb et testés po…
         └─ #4 Sophie Bernard [comment, approved] Super, je viens m'inscrire alors 😊
   └─ #5 Marc Dubois [comment, approved] Le tableau des températures est très utile.  Pour le grès, …
#16 Notre nouvel atelier à la Croix-Rousse
   └─ #6 Les secrets de l'émaillage : guide complet – Atelier Lumière [pingback, approved] […] En attendant, découvrez notre nouvel atelier et nos tar…
#18 Cuisson raku : le feu et la fumée
   └─ #7 Pierre Garnier [comment, approved] Superbe vidéo ! La prochaine session raku est prévue quand ?
      └─ #8 Lucas Petit [comment, approved] Merci Pierre ! La prochaine cuisson a lieu le samedi 16 mai…
#20 Atelier enfants : modeler l'argile dès 6 ans
   └─ #9 Julie Moreau [comment, approved] Ma fille de 7 ans a adoré la séance de mercredi, elle ne pa…
   └─ #10 Antoine Lefèvre [comment, hold] Bonjour, reste-t-il de la place pour les vacances de la Tou…
   └─ #11 Montres de luxe pas chères [comment, spam] Best replica watches, free shipping!!! Visit https://cheap-…
```

## Menus

### “Menu principal” (nav_menu term #17, slug `menu-principal`, 8 items, location: none)

```
- [1] item #27 “Accueil” → http://127.0.0.1:8081/ (post_type:page #10)
- [2] item #28 “Nos ateliers” → http://127.0.0.1:8081/nos-ateliers/ (post_type:page #15)
  - [3] item #29 “Ateliers enfants” → http://127.0.0.1:8081/category/ateliers/ateliers-enfants/ (taxonomy:category #3)
- [4] item #30 “À propos” → http://127.0.0.1:8081/a-propos/ (post_type:page #12)
  - [5] item #31 “Tarifs” → http://127.0.0.1:8081/a-propos/tarifs/ (post_type:page #13)
- [6] item #32 “Le blog” → http://127.0.0.1:8081/blog/ (post_type:page #11) [custom title]
- [7] item #33 “Contact” → http://127.0.0.1:8081/contact/ (post_type:page #14)
- [8] item #34 “Boutique en ligne” → https://boutique.atelier-lumiere.example/ (custom) [custom title, target=_blank, classes=menu-externe menu-cta, rel=noopener, title attr, description]
```

### “Pied de page” (nav_menu term #18, slug `pied-de-page`, 4 items, location: none)

```
- [1] item #35 “Politique de confidentialité” → http://127.0.0.1:8081/politique-de-confidentialite/ (post_type:page #3)
- [2] item #36 “Contact” → http://127.0.0.1:8081/contact/ (post_type:page #14)
- [3] item #37 “Guide de l'émaillage” → http://127.0.0.1:8081/secrets-emaillage-guide-complet/ (post_type:post #17) [custom title]
- [4] item #38 “Instagram” → https://www.instagram.com/atelier.lumiere.lyon/ (custom) [custom title, target=_blank, rel=me noopener]
```

Twenty Twenty-Five registers **no classic menu locations**, so neither menu is assigned to a location (`/wp/v2/menu-locations` returns `[]`). The theme header’s Navigation block uses `wp_navigation` #39, which WordPress’ own `WP_Classic_To_Block_Menu_Converter` generated from “Menu principal” during seeding (WordPress would otherwise create it lazily on the first front-end visit). Note: the `post_parent` column of `nav_menu_item` posts holds the parent of the *linked object* (core behaviour); the menu hierarchy is in `_menu_item_menu_item_parent`.

## Feature index (for assertions)

| Feature | IDs |
|---|---|
| `bare_url_on_own_line` | #15 Nos ateliers, #16 Notre nouvel atelier à la Croix-Rousse, #18 Cuisson raku : le feu et la fumée |
| `block:core/block` | #10 Accueil, #17 Les secrets de l'émaillage : guide complet |
| `block:core/button` | #10 Accueil, #13 Tarifs, #16 Notre nouvel atelier à la Croix-Rousse, #21 Stage d'été : tournage intensif en 5 jours, #26 Portes ouvertes : venez tourner votre premier bol |
| `block:core/buttons` | #10 Accueil, #13 Tarifs, #16 Notre nouvel atelier à la Croix-Rousse, #21 Stage d'été : tournage intensif en 5 jours, #26 Portes ouvertes : venez tourner votre premier bol |
| `block:core/column` | #10 Accueil, #16 Notre nouvel atelier à la Croix-Rousse |
| `block:core/columns` | #10 Accueil, #16 Notre nouvel atelier à la Croix-Rousse |
| `block:core/cover` | #10 Accueil, #16 Notre nouvel atelier à la Croix-Rousse |
| `block:core/embed` | #16 Notre nouvel atelier à la Croix-Rousse |
| `block:core/gallery` | #16 Notre nouvel atelier à la Croix-Rousse |
| `block:core/heading` | #3 Politique de confidentialité, #10 Accueil, #12 À propos, #13 Tarifs, #14 Contact, #16 Notre nouvel atelier à la Croix-Rousse, #17 Les secrets de l'émaillage : guide complet, #20 Atelier enfants : modeler l'argile dès 6 ans, #21 Stage d'été : tournage intensif en 5 jours |
| `block:core/html` | #14 Contact, #16 Notre nouvel atelier à la Croix-Rousse |
| `block:core/image` | #16 Notre nouvel atelier à la Croix-Rousse, #17 Les secrets de l'émaillage : guide complet, #20 Atelier enfants : modeler l'argile dès 6 ans, #26 Portes ouvertes : venez tourner votre premier bol |
| `block:core/latest-posts` | #10 Accueil |
| `block:core/list` | #12 À propos, #14 Contact, #17 Les secrets de l'émaillage : guide complet, #20 Atelier enfants : modeler l'argile dès 6 ans, #21 Stage d'été : tournage intensif en 5 jours, #24 Notes internes : inventaire des émaux, #25 Nouveaux horaires d'hiver |
| `block:core/list-item` | #12 À propos, #14 Contact, #17 Les secrets de l'émaillage : guide complet, #20 Atelier enfants : modeler l'argile dès 6 ans, #21 Stage d'été : tournage intensif en 5 jours, #24 Notes internes : inventaire des émaux, #25 Nouveaux horaires d'hiver |
| `block:core/media-text` | #12 À propos |
| `block:core/more` | #16 Notre nouvel atelier à la Croix-Rousse |
| `block:core/paragraph` | #3 Politique de confidentialité, #10 Accueil, #12 À propos, #13 Tarifs, #14 Contact, #16 Notre nouvel atelier à la Croix-Rousse, #17 Les secrets de l'émaillage : guide complet, #20 Atelier enfants : modeler l'argile dès 6 ans, #21 Stage d'été : tournage intensif en 5 jours, #23 Recette d'émail céladon (réservé aux élèves), #24 Notes internes : inventaire des émaux, #25 Nouveaux horaires d'hiver, #26 Portes ouvertes : venez tourner votre premier bol |
| `block:core/quote` | #12 À propos, #17 Les secrets de l'émaillage : guide complet |
| `block:core/separator` | #16 Notre nouvel atelier à la Croix-Rousse, #17 Les secrets de l'émaillage : guide complet |
| `block:core/shortcode` | #14 Contact |
| `block:core/table` | #13 Tarifs, #17 Les secrets de l'émaillage : guide complet, #21 Stage d'été : tournage intensif en 5 jours, #23 Recette d'émail céladon (réservé aux élèves) |
| `block:yoast/faq-block` | #20 Atelier enfants : modeler l'argile dès 6 ans |
| `child_page` | #13 Tarifs |
| `content_image_subsize_url` | #10 Accueil, #12 À propos, #15 Nos ateliers, #16 Notre nouvel atelier à la Croix-Rousse, #17 Les secrets de l'émaillage : guide complet, #19 Nos bols en grès pour la nouvelle saison, #20 Atelier enfants : modeler l'argile dès 6 ans, #26 Portes ouvertes : venez tourner votre premier bol |
| `custom_excerpt` | #17 Les secrets de l'émaillage : guide complet, #18 Cuisson raku : le feu et la fumée, #21 Stage d'été : tournage intensif en 5 jours |
| `editor_classic` | #18 Cuisson raku : le feu et la fumée, #19 Nos bols en grès pour la nouvelle saison, #22 Fermeture estivale de l'atelier |
| `editor_elementor` | #15 Nos ateliers |
| `editor_empty` | #11 Blog |
| `editor_gutenberg` | #3 Politique de confidentialité, #10 Accueil, #12 À propos, #13 Tarifs, #14 Contact, #16 Notre nouvel atelier à la Croix-Rousse, #17 Les secrets de l'émaillage : guide complet, #20 Atelier enfants : modeler l'argile dès 6 ans, #21 Stage d'été : tournage intensif en 5 jours, #23 Recette d'émail céladon (réservé aux élèves), #24 Notes internes : inventaire des émaux, #25 Nouveaux horaires d'hiver, #26 Portes ouvertes : venez tourner votre premier bol |
| `elementor` | #15 Nos ateliers |
| `emoji_4byte_utf8` | #22 Fermeture estivale de l'atelier |
| `empty_slug` | #25 Nouveaux horaires d'hiver |
| `featured_image` | #16 Notre nouvel atelier à la Croix-Rousse, #17 Les secrets de l'émaillage : guide complet, #18 Cuisson raku : le feu et la fumée, #19 Nos bols en grès pour la nouvelle saison, #20 Atelier enfants : modeler l'argile dès 6 ans, #21 Stage d'été : tournage intensif en 5 jours, #26 Portes ouvertes : venez tourner votre premier bol |
| `internal_link_category` | #20 Atelier enfants : modeler l'argile dès 6 ans |
| `internal_link_shortlink` | #19 Nos bols en grès pour la nouvelle saison |
| `more_tag` | #16 Notre nouvel atelier à la Croix-Rousse, #19 Nos bols en grès pour la nouvelle saison |
| `oembed_cache_meta` | #15 Nos ateliers, #16 Notre nouvel atelier à la Croix-Rousse, #18 Cuisson raku : le feu et la fumée |
| `old_slug_redirect` | #17 Les secrets de l'émaillage : guide complet |
| `password_protected` | #23 Recette d'émail céladon (réservé aux élèves) |
| `post_format_video` | #18 Cuisson raku : le feu et la fumée |
| `seo_rankmath` | #12 À propos, #19 Nos bols en grès pour la nouvelle saison, #21 Stage d'été : tournage intensif en 5 jours |
| `seo_rankmath_noindex` | #21 Stage d'été : tournage intensif en 5 jours |
| `seo_yoast` | #10 Accueil, #13 Tarifs, #14 Contact, #16 Notre nouvel atelier à la Croix-Rousse, #17 Les secrets de l'émaillage : guide complet, #18 Cuisson raku : le feu et la fumée, #20 Atelier enfants : modeler l'argile dès 6 ans |
| `seo_yoast_canonical` | #17 Les secrets de l'émaillage : guide complet |
| `seo_yoast_noindex` | #18 Cuisson raku : le feu et la fumée |
| `seo_yoast_primary_category` | #17 Les secrets de l'émaillage : guide complet, #20 Atelier enfants : modeler l'argile dès 6 ans |
| `shortcode:caption` | #18 Cuisson raku : le feu et la fumée |
| `shortcode:contact-form-7` | #14 Contact, #18 Cuisson raku : le feu et la fumée |
| `shortcode:gallery` | #19 Nos bols en grès pour la nouvelle saison |
| `status_draft` | #25 Nouveaux horaires d'hiver |
| `status_future` | #26 Portes ouvertes : venez tourner votre premier bol |
| `status_private` | #24 Notes internes : inventaire des émaux |
| `status_publish` | #3 Politique de confidentialité, #10 Accueil, #11 Blog, #12 À propos, #13 Tarifs, #14 Contact, #15 Nos ateliers, #16 Notre nouvel atelier à la Croix-Rousse, #17 Les secrets de l'émaillage : guide complet, #18 Cuisson raku : le feu et la fumée, #19 Nos bols en grès pour la nouvelle saison, #20 Atelier enfants : modeler l'argile dès 6 ans, #21 Stage d'été : tournage intensif en 5 jours, #22 Fermeture estivale de l'atelier, #23 Recette d'émail céladon (réservé aux élèves) |
| `sticky` | #16 Notre nouvel atelier à la Croix-Rousse |
| `synced_pattern_ref` | #10 Accueil, #17 Les secrets de l'émaillage : guide complet |

## WXR export (`export.xml`)

- Produced by WordPress itself: `export_wp( array( 'content' => 'all' ) )` (same as Tools → Export → All content), WXR 1.2, 150,940 bytes, base URL http://127.0.0.1:8081.
- 3 `<wp:author>`, 7 `<wp:category>`, 8 `<wp:tag>`, `<wp:term>` by taxonomy: {"category":7,"nav_menu":4,"post_format":1,"post_tag":8}.
- 37 `<item>` by type/status: {"attachment":{"inherit":5},"nav_menu_item":{"publish":12},"page":{"publish":7},"post":{"publish":8,"private":1,"draft":1,"future":1},"wp_block":{"publish":1},"wp_navigation":{"publish":1}}.
- 9 `<wp:comment>` (IDs 2, 3, 4, 5, 6, 7, 8, 9, 10); spam comment(s) 11 are **not** exported.
- export_wp() skips spam comments (comment_approved <> "spam") and auto-drafts. Drafts, scheduled, private and password-protected posts ARE exported. Revisions are not exported (none exist anyway). Options/settings, theme mods, users without posts and application passwords are never part of WXR.
- WordPress 7.1 also emits every category, tag, nav_menu and post_format term as a generic <wp:term> (get_terms() is called with an empty custom-taxonomy list), so categories/tags appear twice (as <wp:category>/<wp:tag> and as <wp:term>) - dedupe on term_id + taxonomy.
- nav_menu terms are emitted twice as <wp:term> (generic loop + wxr_nav_menu_terms()).
- Attachments: <wp:attachment_url> is wp_get_attachment_url(), i.e. the "-scaled" file for the 3000x2000 panorama; <guid> keeps the original upload URL; _wp_attachment_metadata (serialized) lists original_image and all sub-sizes.
- _oembed_<md5> postmeta ({{unknown}}) and _wp_old_slug, _thumbnail_id, _wp_page_template, _menu_item_* are exported as plain postmeta.
- The <pubDate> and the "created" generator comment change on every export; everything else is deterministic.

## REST API (`http://127.0.0.1:8081/wp-json/`)

| Request | As | HTTP | X-WP-Total / count | IDs |
|---|---|---|---|---|
| `GET /wp/v2/posts` | anonymous | 200 | 8 | 23, 22, 21, 20, 19, 18, 17, 16 |
| `GET /wp/v2/pages` | anonymous | 200 | 7 | 15, 13, 3, 14, 12, 11, 10 |
| `GET /wp/v2/media` | anonymous | 200 | 5 | 8, 7, 6, 5, 4 |
| `GET /wp/v2/categories` | anonymous | 200 | 7 | 7, 2, 4, 3, 5, 1, 6 |
| `GET /wp/v2/tags` | anonymous | 200 | 8 | 8, 13, 10, 15, 14, 11, 12, 9 |
| `GET /wp/v2/users` | anonymous | 200 | 3 | 2, 3, 1 |
| `GET /wp/v2/comments` | anonymous | 200 | 7 | 9, 8, 7, 5, 4, 3, 2 |
| `GET /wp/v2/menus` | anonymous | 401 | — |  |
| `GET /wp/v2/blocks` | anonymous | 200 | 1 |  |
| `GET /wp/v2/navigation` | anonymous | 200 | 1 | 39 |
| `GET /wp/v2/posts?status=any&context=edit` | admin (app password) | 200 | 11 | 26, 25, 24, 23, 22, 21, 20, 19, 18, 17, 16 |
| `GET /wp/v2/pages?status=any&context=edit` | admin (app password) | 200 | 7 | 15, 13, 3, 14, 12, 11, 10 |
| `GET /wp/v2/users` | admin (app password) | 200 | 3 | 2, 3, 1 |
| `GET /wp/v2/comments?status=all` | admin (app password) | 200 | 8 | 10, 9, 8, 7, 5, 4, 3, 2 |
| `GET /wp/v2/comments?status=hold` | admin (app password) | 200 | 1 | 10 |
| `GET /wp/v2/comments?status=spam` | admin (app password) | 200 | 1 | 11 |
| `GET /wp/v2/comments?type=pingback` | admin (app password) | 200 | 1 | 6 |
| `GET /wp/v2/menus` | admin (app password) | 200 | 2 | 17, 18 |
| `GET /wp/v2/menu-items` | admin (app password) | 200 | 12 | 27, 35, 28, 36, 29, 37, 30, 38, 31, 32, 33, 34 |
| `GET /wp/v2/menu-locations` | admin (app password) | 200 | 0 |  |
| `GET /wp/v2/blocks` | admin (app password) | 200 | 1 | 9 |
| `GET /wp/v2/navigation` | admin (app password) | 200 | 1 | 39 |
| `GET /wp/v2/settings` | admin (app password) | 200 | — |  |

- SEO (Yoast/Rank Math) and Elementor postmeta are NOT exposed by the REST API (protected "_" keys / unregistered meta, plugins not installed: no yoast_head / yoast_head_json either). Get them from the WXR, or register them yourself.
- The only registered post meta is "footnotes" (core), so meta = {"footnotes": ""}.
- Embeds render as plain links in content.rendered (oEmbed lookups are blocked); use content.raw (context=edit) for block markup.
- /wp/v2/comments lists only type=comment by default; pingbacks need ?type=pingback.
- Public /wp/v2/users only lists users with published posts.
- Anonymous GET /wp/v2/blocks answers [] while X-WP-Total says 1 (core: the published wp_block is counted, then filtered out by the per-item read permission check). Authenticated, it returns the pattern.
- Published wp_navigation posts are readable anonymously (/wp/v2/navigation).
- Private posts: anonymous visitors get plain ?p=ID permalinks; authenticated users get the pretty URL (core wp_force_plain_post_permalink()). Drafts and scheduled posts always have ?p=ID links.
- Password-protected post #23 anonymously: `content.protected = true`, `content.rendered = ""`. Pass ?password=celadon2026 to get the rendered content.
- Draft #25 has `slug: ""`; with `context=edit` the REST API also returns `generated_slug`.
- `_embed` works (author, featured media, terms): e.g. `http://127.0.0.1:8081/wp-json/wp/v2/posts?_embed`.

## `fixture.json` layout

Top-level keys: `fixture`, `paths`, `environment`, `site`, `credentials`, `users`, `categories`, `tags`, `post_formats`, `media`, `posts`, `pages`, `synced_patterns`, `navigation_posts`, `comments`, `menus`, `menu_locations`, `counts`, `wxr`, `rest`, `features`, `content_fingerprint`, `ids`.

- `ids`: symbolic name → ID, grouped (`ids.posts.emaillage`, `ids.pages.nos_ateliers`, `ids.media.panorama`, `ids.comments.spam`, `ids.menu_items.main_boutique`…).
- `posts[]` / `pages[]`: one entry per post/page with slug, status, dates (local + GMT), URL, author, terms, featured media, block counts, shortcodes, internal links (resolved to IDs), images (resolved to attachment + size), SEO meta, other meta keys, comment counts, curated `notes`.
- `features`: feature name → list of IDs (same as the table above). `wxr`: counts parsed from export.xml. `rest.expected`: status code, `X-WP-Total` and IDs for each endpoint, anonymous and as admin.
- `content_fingerprint.sha256`: hash of the content tables; `php /tmp/wp-test/inventory.php --fingerprint` recomputes it.

## Things that are intentionally *not* there

- Yoast SEO, Rank Math and Elementor are **not installed**: their data exists only as postmeta / block markup, exactly as a migration source would contain it. The `yoast/faq-block` therefore renders its saved HTML as-is on the front end.
- No WooCommerce, no custom post types, no ACF fields, no multisite, no revisions, no trashed content.
- `[contact-form-7 id="12" title="Contact"]` refers to a form that does not exist (Contact Form 7 not installed); the shortcode is printed verbatim. Post ID 12 happens to be the page “À propos” — do not resolve the shortcode id as a post ID.

