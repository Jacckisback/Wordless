<?php /** @var string $maxUpload @var bool $welcome @var bool $available */ ?>
<?php if ($welcome): ?><div class="notice notice--good"><?= icon('check') ?><p><?= e(__('Votre site Wordless est installé. Importons maintenant votre site WordPress : comptez quelques minutes.')) ?></p></div><div class="stack"></div><?php endif ?>
<div class="split" id="import-app">
  <form class="panel" id="import-form" enctype="multipart/form-data">
    <div class="panel-head"><div><h2><?= e(__('1. Comment récupérer votre site ?')) ?></h2><p class="muted small"><?= e(__('Articles, pages, images, catégories, étiquettes, commentaires, menus, auteurs et balises SEO (Yoast, Rank Math, SEOPress…). Vos adresses restent identiques.')) ?></p></div></div>
    <div class="panel-body form-stack">
      <div class="tabs" role="tablist">
        <button type="button" role="tab" aria-selected="true" data-method="rest"><?= e(__('Connexion directe (recommandé)')) ?></button>
        <button type="button" role="tab" aria-selected="false" data-method="file"><?= e(__('Fichier d’export')) ?></button>
      </div>
      <input type="hidden" name="import_method" value="rest">
      <div data-pane="rest" class="form-stack">
        <div class="field"><label for="url"><?= e(__('Adresse de votre site WordPress')) ?></label><input id="url" name="url" placeholder="https://www.mon-site.fr" inputmode="url"></div>
        <details><summary class="small"><?= e(__('Importer aussi les brouillons, pages privées et menus (recommandé)')) ?></summary>
          <div class="form-stack" style="margin-top:.75rem">
            <ol class="steps-list small"><li><?= e(__('Dans WordPress : Utilisateurs > Profil > « Mots de passe d’application ».')) ?></li><li><?= e(__('Nommez-le « Wordless », cliquez « Ajouter », copiez le mot de passe affiché.')) ?></li></ol>
            <div class="field-row">
              <div class="field"><label for="user"><?= e(__('Identifiant WordPress')) ?></label><input id="user" name="user" autocomplete="off"></div>
              <div class="field"><label for="password"><?= e(__('Mot de passe d’application')) ?></label><input id="password" name="password" type="password" autocomplete="off" placeholder="xxxx xxxx xxxx xxxx xxxx xxxx"></div>
            </div>
            <p class="hint"><?= e(__('Utilisé une seule fois pour l’import, stocké chiffré, supprimable ensuite dans WordPress.')) ?></p>
          </div>
        </details>
      </div>
      <div data-pane="file" class="form-stack" hidden>
        <ol class="steps-list small"><li><?= e(__('Dans WordPress : Outils > Exporter > « Tout le contenu » > Télécharger le fichier d’export.')) ?></li><li><?= e(__('Envoyez ce fichier .xml ici (limite du serveur : {m}).', ['m' => $maxUpload])) ?></li></ol>
        <input type="file" name="wxr" accept=".xml,application/xml,text/xml">
      </div>
      <fieldset><legend><?= e(__('2. Que voulez-vous importer ?')) ?></legend>
        <div class="field-row">
          <label class="check"><input type="checkbox" name="media" value="1" checked><span><?= e(__('Images et fichiers')) ?></span></label>
          <label class="check"><input type="checkbox" name="comments" value="1" checked><span><?= e(__('Commentaires')) ?></span></label>
          <label class="check"><input type="checkbox" name="users" value="1" checked><span><?= e(__('Auteurs')) ?></span></label>
          <label class="check"><input type="checkbox" name="menus" value="1" checked><span><?= e(__('Menus')) ?></span></label>
          <label class="check"><input type="checkbox" name="settings" value="1" checked><span><?= e(__('Nom du site, page d’accueil, adresses')) ?></span></label>
          <label class="check"><input type="checkbox" name="replace_starter" value="1" checked><span><?= e(__('Remplacer le contenu de démonstration')) ?></span></label>
          <label class="check"><input type="checkbox" name="seo_fallback" value="1"><span><?= e(__('Relire les balises SEO sur les pages publiées (plus lent)')) ?></span></label>
        </div>
        <div class="field" style="margin-top:.75rem"><label for="other_types"><?= e(__('Autres types de contenu (portfolio, produits…)')) ?></label><select id="other_types" name="other_types"><option value="pages"><?= e(__('Importer comme pages (adresses conservées)')) ?></option><option value="skip"><?= e(__('Ignorer')) ?></option></select></div>
      </fieldset>
      <?php if (!$available): ?><div class="notice notice--bad"><?= icon('alert') ?><p><?= e(__('Le module de migration est absent de cette installation.')) ?></p></div><?php endif ?>
    </div>
    <div class="panel-foot"><button class="btn btn-primary btn-lg" type="submit" <?= $available ? '' : 'disabled' ?>><?= icon('import') ?><?= e(__('Lancer la migration')) ?></button></div>
  </form>
  <aside class="stack">
    <section class="panel" id="import-progress" hidden>
      <div class="panel-head"><h2><?= e(__('Progression')) ?></h2><span class="muted small" id="import-status"></span></div>
      <div class="panel-body stack"><ul class="progress-phases" id="import-phases"></ul><div class="log-box" id="import-log" aria-live="polite"></div></div>
    </section>
    <section class="panel" id="import-report" hidden><div class="panel-head"><h2><?= e(__('Résultat')) ?></h2></div><div class="panel-body stack" id="import-report-body"></div></section>
    <div class="panel"><div class="panel-body stack-s"><h2><?= e(__('Ce qui est garanti')) ?></h2>
      <ul class="checklist small">
        <li><?= e(__('Mêmes adresses (URL) que sur WordPress : aucune perte de référencement')) ?></li>
        <li><?= e(__('Anciennes images /wp-content/uploads/ redirigées (301)')) ?></li>
        <li><?= e(__('Titres et descriptions Yoast, Rank Math, SEOPress repris')) ?></li>
        <li><?= e(__('Blocs Gutenberg, Elementor et anciens shortcodes convertis')) ?></li>
        <li><?= e(__('Relançable sans créer de doublons')) ?></li>
      </ul>
      <p class="hint"><?= e(__('Ce qui ne suit pas : le thème (choisissez une direction artistique), les extensions et leurs données propres (ex. WooCommerce). Votre site WordPress n’est jamais modifié.')) ?></p></div></div>
  </aside>
</div>
