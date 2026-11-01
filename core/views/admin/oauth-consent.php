<?php
/**
 * Écran de consentement OAuth du connecteur IA (Claude.ai, ChatGPT…).
 * Page autonome : aucun script ni style en ligne (CSP stricte).
 *
 * @var array $user                   utilisateur connecté
 * @var string $roleLabel             rôle lisible
 * @var array{name: string, host: string, local: bool} $client
 * @var list<string> $permissions     ce que l'application pourra faire
 * @var array<string, string> $fields paramètres d'origine, renvoyés tels quels
 * @var string $notice                avertissement éventuel (approbation trop rapide)
 * @var string $action                adresse du formulaire
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
<title><?= e(__('Autoriser {app}', ['app' => $client['name']])) ?> · <?= e($siteName) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/admin.css')) ?>">
<link rel="icon" href="<?= e(asset_url('admin/assets/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="wl-auth">
<main class="auth-card">
  <div class="auth-brand"><?= is_file($logo) ? (string) file_get_contents($logo) : 'Wordless' ?></div>
  <h1><?= e(__('Connecter « {app} » à {site}', ['app' => $client['name'], 'site' => $siteName])) ?></h1>
  <p class="muted"><?= e(__('Connecté en tant que {name} ({email}), rôle : {role}.', ['name' => $user['name'], 'email' => $user['email'], 'role' => $roleLabel])) ?></p>

  <?php if (($notice ?? '') !== ''): ?>
    <div class="notice notice--bad" role="alert"><p><?= e($notice) ?></p></div>
  <?php endif ?>

  <div class="notice">
    <p><strong><?= e($client['name']) ?></strong>
      <?php if ($client['local']): ?>
        <?= e(__('est une application installée sur votre ordinateur ({host}).', ['host' => $client['host']])) ?>
      <?php else: ?>
        <?= e(__('vous renverra ensuite vers {host}.', ['host' => $client['host']])) ?>
      <?php endif ?>
    </p>
    <p><?= e(__('Autorisez seulement si vous venez de lancer cette connexion vous-même, depuis cet assistant. Dans le doute, refusez.')) ?></p>
  </div>

  <?php if ($permissions): ?>
    <p><?= e(__('En votre nom, cette application pourra :')) ?></p>
    <ul class="checklist">
      <?php foreach ($permissions as $permission): ?>
        <li class="is-ok"><?= e($permission) ?></li>
      <?php endforeach ?>
    </ul>
  <?php else: ?>
    <div class="notice notice--bad" role="alert"><p><?= e(__('Votre compte n’a aucun droit utilisable par un assistant IA.')) ?></p></div>
  <?php endif ?>
  <p class="muted"><?= e(__('Elle ne verra jamais votre mot de passe et ne pourra ni gérer les comptes, ni modifier les clés d’API. Chaque action est inscrite au journal d’activité. Vous pourrez retirer cet accès à tout moment dans Réglages > Connecteur IA.')) ?></p>

  <form method="post" action="<?= e($action) ?>" class="form-stack">
    <?= csrf_field() ?>
    <?php foreach ($fields as $name => $value): ?>
      <input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
    <?php endforeach ?>
    <div class="actions-row">
      <button class="btn btn-primary btn-lg" type="submit" name="decision" value="approve"><?= e(__('Autoriser')) ?></button>
      <button class="btn btn-ghost btn-lg" type="submit" name="decision" value="deny" autofocus><?= e(__('Refuser')) ?></button>
    </div>
  </form>
</main>
</body>
</html>
