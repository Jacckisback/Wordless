# Extensions

Wordless n'a pas besoin d'extensions pour le SEO, le cache, la sécurité ou les formulaires : tout est intégré. Ce dossier sert aux ajouts propres à votre site.

Une extension est un dossier `plugins/<nom>/` contenant un fichier `plugin.php`. Elle n'est chargée que si vous l'activez :

```bash
php bin/wordless plugin:list
php bin/wordless plugin:enable mon-extension
php bin/wordless plugin:disable mon-extension
```

Exemple, `plugins/mon-extension/plugin.php` :

```php
<?php
// Ajoute une balise dans le <head> de toutes les pages publiques.
wl_on('theme_head', fn (string $html): string => $html . '<meta name="author" content="Atelier Démo">');

// Réagit à chaque enregistrement de contenu.
wl_on('content_saved', function (array $saved, ?array $old): void {
    // ex. prévenir un outil externe
});
```

Points d'accroche disponibles :

| Nom | Type | Rôle |
|---|---|---|
| `init` | action | Démarrage de l'application |
| `theme_head` | filtre `(string $html, array $seo)` | Contenu ajouté au `<head>` |
| `jsonld` | filtre `(array $graph, array $page)` | Données structurées schema.org |
| `blocks` | filtre `(array $registre)` | Déclarer ou modifier des types de blocs |
| `block_render_<type>` | filtre `(?string $html, array $bloc, array $ctx)` | Remplacer le rendu d'un bloc |
| `content_saved` | action `(array $nouveau, ?array $ancien)` | Après chaque enregistrement |
| `content_published` | action `(array $contenu)` | Publication programmée arrivée à échéance |
| `cron` | action | Tâches de fond (toutes les 10 minutes environ) |
| `mcp_allowed_origins` | filtre `(array $origines)` | Origines web autorisées sur `/mcp` |

Le site public applique une politique de sécurité stricte (CSP) : un script ajouté par une extension doit être un fichier servi par le site, pas du code en ligne.
