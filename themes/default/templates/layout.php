<?php
/** Gabarit principal. @var string $head @var string $body @var string $template */
use Wordless\Auth;
use Wordless\I18n;
use Wordless\Media;
use Wordless\Settings;
use Wordless\Theme;

$t = Theme::tokens();
$site = (string) Settings::get('site.name');
$logo = Media::find(Settings::get('site.logo_id') ? (int) Settings::get('site.logo_id') : null);
$nav = Theme::menuHtml('primary', __('Navigation principale'));
$footerNav = Theme::menuHtml('footer', __('Liens de pied de page'));
?><!doctype html>
<html lang="<?= e(str_replace('_', '-', I18n::locale())) ?>">
<head>
<?= $head ?>
</head>
<body class="tpl-<?= e($template) ?> header-<?= e($t['header']) ?>">
<a class="skip" href="#main"><?= e(__('Aller au contenu')) ?></a>
<header class="site-header">
  <div class="site-header__inner">
    <a class="site-brand" href="<?= e(href('/')) ?>" rel="home"><?php if ($logo): ?><?= Media::picture($logo, ['sizes' => '12rem', 'alt' => $site, 'class' => 'site-logo', 'loading' => 'eager']) ?><?php else: ?><?= e($site) ?><?php endif ?></a>
    <?php if ($nav !== ''): ?>
      <button class="nav-btn" type="button" popovertarget="site-menu" aria-label="<?= e(__('Ouvrir le menu')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg><span><?= e(__('Menu')) ?></span></button>
      <?= preg_replace('#^<nav class="site-nav"([^>]*)>#', '<nav class="site-nav" id="site-menu" popover$1><button class="nav-btn nav-close" type="button" popovertarget="site-menu" popovertargetaction="hide" aria-label="' . e(__('Fermer le menu')) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>', $nav, 1) ?>
    <?php endif ?>
  </div>
</header>
<main id="main" tabindex="-1">
<?= $body ?>
</main>
<footer class="site-footer">
  <div class="site-footer__inner">
    <p>© <?= date('Y') ?> <?= e((string) (Settings::get('site.org_name') ?: $site)) ?></p>
    <?= $footerNav ?>
  </div>
</footer>
<?php if (Auth::check() && !empty($content['id'])): ?>
  <a class="wl-editbar" href="<?= e(admin_url('content/' . (int) $content['id'])) ?>"><?= e(__('Modifier cette page')) ?></a>
<?php endif ?>
<?= (string) Settings::get('code.footer', '') ?>
</body>
</html>
