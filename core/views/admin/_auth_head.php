<?php use Wordless\I18n; ?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle ?? 'Wordless') ?></title>
<script src="<?= e(asset_url('admin/assets/theme-init.js')) ?>"></script>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/admin.css')) ?>">
<link rel="icon" href="<?= e(asset_url('admin/assets/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="wl-auth">
<main class="auth-card">
  <div class="auth-brand"><?= file_get_contents(WL_ROOT . '/admin/assets/logo.svg') ?: 'Wordless' ?></div>
  <?php if (!empty($flash)): ?><div class="notice notice--<?= e($flash['type']) ?>" role="status"><p><?= e($flash['message']) ?></p></div><?php endif ?>
