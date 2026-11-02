<?php
/** Éditeur page builder. @var array $data @var array $c */
use Wordless\I18n;
use Wordless\Settings;

$back = admin_url('content?type=' . $c['type']);
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="wl-admin" content="<?= e(admin_url()) ?>">
<title><?= e($c['id'] ? __('Modifier : {t}', ['t' => $c['title']]) : ($c['type'] === 'page' ? __('Nouvelle page') : __('Nouvel article'))) ?> · <?= e((string) Settings::get('site.name')) ?></title>
<script src="<?= e(asset_url('admin/assets/theme-init.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/admin.css')) ?>">
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/editor.css')) ?>">
<link rel="icon" href="<?= e(asset_url('admin/assets/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="wl-editor">
<script type="application/json" id="wl-editor-data"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<header class="ed-top">
  <a class="btn btn-ghost btn-icon" href="<?= e($back) ?>" aria-label="<?= e(__('Retour à la liste')) ?>" title="<?= e(__('Retour à la liste')) ?>"><?= icon('chevron-left') ?></a>
  <button class="btn btn-ghost btn-icon ed-toggle" type="button" data-toggle-panel="left" aria-label="<?= e(__('Blocs')) ?>" title="<?= e(__('Blocs')) ?>"><?= icon('layers') ?></button>
  <label class="sr" for="ed-title"><?= e(__('Titre')) ?></label>
  <input class="ed-title" id="ed-title" maxlength="300" autocomplete="off" placeholder="<?= e($c['type'] === 'page' ? __('Titre de la page') : __('Titre de l’article')) ?>">
  <span class="ed-state" id="ed-state" aria-live="polite"></span>
  <div class="ed-actions">
    <div class="seg seg-hist" role="group" aria-label="<?= e(__('Historique des modifications')) ?>">
      <button type="button" id="ed-undo" disabled title="<?= e(__('Annuler')) ?> (Ctrl+Z)"><?= icon('undo') ?><span class="sr"><?= e(__('Annuler')) ?></span></button>
      <button type="button" id="ed-redo" disabled title="<?= e(__('Rétablir')) ?> (Ctrl+Maj+Z)"><?= icon('redo') ?><span class="sr"><?= e(__('Rétablir')) ?></span></button>
    </div>
    <div class="seg" role="group" aria-label="<?= e(__('Taille de l’aperçu')) ?>">
      <button type="button" data-device="desktop" aria-pressed="true" title="<?= e(__('Ordinateur')) ?>"><?= icon('desktop') ?><span class="sr"><?= e(__('Ordinateur')) ?></span></button>
      <button type="button" data-device="mobile" aria-pressed="false" title="<?= e(__('Mobile')) ?>"><?= icon('mobile') ?><span class="sr"><?= e(__('Mobile')) ?></span></button>
    </div>
    <a class="btn btn-ghost" id="ed-view" href="#" target="_blank" rel="noopener" hidden><?= icon('external') ?><span><?= e(__('Voir')) ?></span></a>
    <button class="btn" type="button" id="ed-preview"><?= icon('eye') ?><span><?= e(__('Aperçu')) ?></span></button>
    <button class="btn" type="button" id="ed-save"><?= e(__('Enregistrer')) ?></button>
    <button class="btn btn-primary" type="button" id="ed-publish"><?= e(__('Publier')) ?></button>
    <button class="btn btn-ghost btn-icon ed-toggle" type="button" data-toggle-panel="right" aria-label="<?= e(__('Réglages')) ?>" title="<?= e(__('Réglages')) ?>"><?= icon('settings') ?></button>
  </div>
</header>
<div class="ed-body">
  <aside class="ed-left" id="ed-left" aria-label="<?= e(__('Blocs')) ?>">
    <div class="tabs" role="tablist">
      <button type="button" role="tab" aria-selected="true" data-tab="library"><?= e(__('Ajouter')) ?></button>
      <button type="button" role="tab" aria-selected="false" data-tab="outline"><?= e(__('Structure')) ?></button>
    </div>
    <div class="ed-scroll" data-panel="library">
      <input type="search" id="ed-block-search" placeholder="<?= e(__('Chercher un bloc…')) ?>" aria-label="<?= e(__('Chercher un bloc')) ?>">
      <div id="ed-library"></div>
    </div>
    <div class="ed-scroll" data-panel="outline" hidden><ol class="ed-outline" id="ed-outline"></ol></div>
  </aside>
  <main class="ed-stage" id="ed-stage">
    <div class="ed-frame-wrap" id="ed-frame-wrap">
      <iframe id="ed-frame" title="<?= e(__('Aperçu modifiable de la page')) ?>" src="<?= e(admin_url('editor/frame')) ?>"></iframe>
    </div>
  </main>
  <aside class="ed-right" id="ed-right" aria-label="<?= e(__('Réglages')) ?>">
    <div class="tabs" role="tablist">
      <button type="button" role="tab" aria-selected="true" data-tab="block"><?= e(__('Bloc')) ?></button>
      <button type="button" role="tab" aria-selected="false" data-tab="page"><?= e($c['type'] === 'page' ? __('Page') : __('Article')) ?></button>
      <button type="button" role="tab" aria-selected="false" data-tab="seo"><?= e(__('SEO & GEO')) ?> <span class="ed-score" id="ed-score-badge"></span></button>
      <button type="button" role="tab" aria-selected="false" data-tab="ai"><?= icon('sparkles') ?><?= e(__('IA')) ?></button>
    </div>
    <div class="ed-scroll" data-panel="block" id="ed-block"></div>
    <div class="ed-scroll" data-panel="page" id="ed-page" hidden></div>
    <div class="ed-scroll" data-panel="seo" id="ed-seo" hidden></div>
    <div class="ed-scroll" data-panel="ai" id="ed-ai" hidden></div>
  </aside>
</div>
<dialog id="ed-templates" class="wide" aria-labelledby="ed-templates-title">
  <div class="dialog-head"><h2 id="ed-templates-title"><?= e(__('Par quoi commencer ?')) ?></h2><button class="btn btn-ghost btn-icon" type="button" data-close aria-label="<?= e(__('Fermer')) ?>"><?= icon('x') ?></button></div>
  <div class="dialog-body"><div class="preset-grid" id="ed-template-list"></div></div>
</dialog>
<div class="toasts" aria-live="polite"></div>
<dialog id="cmdk" aria-label="<?= e(__('Recherche rapide')) ?>">
  <input class="cmdk-input" type="search" placeholder="<?= e(__('Aller à une page, chercher un contenu…')) ?>" aria-label="<?= e(__('Recherche')) ?>" autocomplete="off">
  <ul class="cmdk-list" role="listbox"></ul>
</dialog>
<nav data-cmdk-pages hidden>
  <a href="<?= e(admin_url()) ?>"><?= e(__('Tableau de bord')) ?></a>
<?php foreach ([['content?type=page', __('Pages'), 'edit_content'], ['content?type=post', __('Articles'), 'edit_content'], ['media', __('Médias'), 'upload'],
    ['menus', __('Menus'), 'manage_menus'], ['seo', __('SEO & GEO'), 'manage_seo'], ['design', __('Design & DA IA'), 'manage_design'], ['settings', __('Réglages'), 'manage_settings']] as [$path, $label, $cap]): ?>
<?php if (\Wordless\Auth::can($cap)): ?>  <a href="<?= e(admin_url($path)) ?>"><?= e($label) ?></a>
<?php endif ?>
<?php endforeach ?>
</nav>
<script src="<?= e(asset_url('admin/assets/admin.js')) ?>" defer></script>
<script src="<?= e(asset_url('admin/assets/media.js')) ?>" defer></script>
<script src="<?= e(asset_url('admin/assets/editor.js')) ?>" defer></script>
</body>
</html>
