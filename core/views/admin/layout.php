<?php
/** Mise en page de l'admin. @var string $content @var string $title @var string $active @var string $actions @var ?array $flash */
use Wordless\Auth;
use Wordless\Db;
use Wordless\I18n;
use Wordless\Settings;

$u = Auth::user();
$pendingComments = Auth::can('moderate') ? (int) Db::val("SELECT COUNT(*) FROM comments WHERE status = 'pending'") : 0;
$newMessages = Auth::can('moderate') ? (int) Db::val("SELECT COUNT(*) FROM form_entries WHERE status = 'new'") : 0;
$nav = [
    ['', '', [['dashboard', 'dashboard', __('Tableau de bord'), '', null]]],
    ['content', __('Contenu'), [
        ['pages', 'page', __('Pages'), 'content?type=page', 'edit_content'],
        ['posts', 'post', __('Articles'), 'content?type=post', 'edit_content'],
        ['media', 'image', __('Médias'), 'media', 'upload'],
        ['comments', 'comment', __('Commentaires'), 'comments', 'moderate', $pendingComments],
        ['messages', 'inbox', __('Messages'), 'messages', 'moderate', $newMessages],
    ]],
    ['site', __('Site'), [
        ['design', 'palette', __('Design & DA IA'), 'design', 'manage_design'],
        ['menus', 'menu', __('Menus'), 'menus', 'manage_menus'],
        ['seo', 'seo', __('SEO & GEO'), 'seo', 'manage_seo'],
    ]],
    ['ai', __('Intelligence artificielle'), [
        ['ai', 'sparkles', __('Assistant IA'), 'ai', 'use_ai'],
        ['connect', 'plug', __('Connecteur Claude / ChatGPT'), 'connect', 'edit_content'],
    ]],
    ['tools', __('Outils'), [
        ['import', 'import', __('Migration WordPress'), 'import', 'import'],
        ['backups', 'archive', __('Sauvegardes'), 'backups', 'manage_settings'],
        ['activity', 'activity', __('Journal d’activité'), 'activity', 'manage_settings'],
    ]],
    ['settings', __('Réglages'), [
        ['settings', 'settings', __('Réglages'), 'settings', 'manage_settings'],
        ['users', 'users', __('Utilisateurs'), 'users', 'manage_users'],
    ]],
];
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="wl-admin" content="<?= e(admin_url()) ?>">
<title><?= e($title) ?> · <?= e((string) Settings::get('site.name')) ?></title>
<script src="<?= e(asset_url('admin/assets/theme-init.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/admin.css')) ?>">
<link rel="icon" href="<?= e(asset_url('admin/assets/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="wl-admin <?= e($bodyClass ?? '') ?>">
<div class="app">
  <aside class="sidebar" id="sidebar" aria-label="<?= e(__('Navigation de l’administration')) ?>">
    <a class="brand" href="<?= e(admin_url()) ?>"><?= file_get_contents(WL_ROOT . '/admin/assets/logo.svg') ?: 'Wordless' ?></a>
    <span class="brand-site"><?= e((string) Settings::get('site.name')) ?></span>
    <nav class="nav">
      <?php foreach ($nav as [$key, $label, $items]): ?>
        <?php $visible = array_filter($items, static fn ($i) => $i[4] === null || Auth::can($i[4])); if (!$visible) { continue; } ?>
        <?php if ($label !== ''): ?><div class="nav-group"><?= e($label) ?></div><?php endif ?>
        <?php foreach ($visible as $item): ?>
          <a href="<?= e(admin_url($item[3])) ?>"<?= $active === $item[0] ? ' aria-current="page"' : '' ?>><?= icon($item[1]) ?><span><?= e($item[2]) ?></span><?php if (!empty($item[5])): ?><span class="count" aria-label="<?= e(_n('{n} en attente', '{n} en attente', (int) $item[5])) ?>"><?= (int) $item[5] ?></span><?php endif ?></a>
        <?php endforeach ?>
      <?php endforeach ?>
    </nav>
    <div class="sidebar-foot nav">
      <a href="<?= e(href('/')) ?>" target="_blank" rel="noopener"><?= icon('external') ?><span><?= e(__('Voir le site')) ?></span></a>
      <a href="<?= e(admin_url('profile')) ?>"<?= $active === 'profile' ? ' aria-current="page"' : '' ?>><?= icon('user') ?><span><?= e($u['name'] ?? '') ?></span></a>
      <form method="post" action="<?= e(admin_url('logout')) ?>"><?= csrf_field() ?><button class="btn btn-ghost logout-btn" type="submit"><?= icon('logout') ?><span><?= e(__('Se déconnecter')) ?></span></button></form>
    </div>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="btn btn-ghost btn-icon menu-btn" type="button" data-nav-toggle aria-controls="sidebar" aria-label="<?= e(__('Menu')) ?>"><?= icon('menu') ?></button>
      <h1><?= e($title) ?></h1>
      <div class="actions">
        <?= $actions ?>
        <button class="btn btn-ghost" type="button" data-cmdk title="<?= e(__('Rechercher (Ctrl+K)')) ?>"><?= icon('search') ?><span class="sr"><?= e(__('Rechercher')) ?></span><kbd aria-hidden="true">Ctrl K</kbd></button>
      </div>
    </header>
    <div class="page<?= !empty($narrow) ? ' page-narrow' : '' ?>">
      <?php if ($flash): ?>
        <div class="notice notice--<?= e($flash['type'] === 'info' ? 'good' : $flash['type']) ?>" role="status" data-flash><?= icon($flash['type'] === 'bad' ? 'alert' : 'check') ?><p><?= e($flash['message']) ?></p></div>
        <div class="stack"></div>
      <?php endif ?>
      <?= $content ?>
    </div>
  </div>
</div>
<div class="toasts" aria-live="polite" aria-atomic="true"></div>
<dialog id="cmdk" aria-label="<?= e(__('Recherche rapide')) ?>">
  <input class="cmdk-input" type="search" placeholder="<?= e(__('Aller à une page, chercher un contenu…')) ?>" aria-label="<?= e(__('Recherche')) ?>" autocomplete="off">
  <ul class="cmdk-list" role="listbox"></ul>
</dialog>
<script src="<?= e(asset_url('admin/assets/admin.js')) ?>" defer></script>
<?php foreach ($scripts ?? [] as $s): ?><script src="<?= e(asset_url('admin/assets/' . $s)) ?>" defer></script><?php endforeach ?>
</body>
</html>
