<?php
/**
 * Erreur de connexion du connecteur IA (application inconnue, adresse de retour
 * refusée, session expirée…). On n'y redirige jamais vers une adresse non vérifiée.
 * Page autonome : aucun script ni style en ligne (CSP stricte).
 *
 * @var string $title
 * @var string $message
 * @var string $siteName
 */
use Wordless\I18n;

$logo = WL_ROOT . '/admin/assets/logo.svg';
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e($siteName) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/admin.css')) ?>">
<link rel="icon" href="<?= e(asset_url('admin/assets/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="wl-auth">
<main class="auth-card">
  <div class="auth-brand"><?= is_file($logo) ? (string) file_get_contents($logo) : 'Wordless' ?></div>
  <h1><?= e($title) ?></h1>
  <div class="notice notice--bad" role="alert"><p><?= e($message) ?></p></div>
  <p class="muted"><?= e(__('Connexion d’un assistant IA à {site}. Vous pouvez fermer cette page.', ['site' => $siteName])) ?></p>
  <div class="actions-row">
    <a class="btn btn-ghost" href="<?= e(admin_url()) ?>"><?= e(__('Aller à l’administration')) ?></a>
  </div>
</main>
</body>
</html>
