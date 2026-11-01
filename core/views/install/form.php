<?php
/** @var array $checks @var array $optional @var bool $ok @var array $errors @var array $old @var string $token */
use Wordless\I18n;
?><!doctype html>
<html lang="<?= e(I18n::lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(__('Installer Wordless')) ?></title>
<link rel="stylesheet" href="<?= e(asset_url('admin/assets/admin.css')) ?>">
<link rel="icon" href="<?= e(asset_url('admin/assets/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="wl-auth">
<main class="auth-card auth-card--wide">
  <div class="auth-brand"><?= file_get_contents(WL_ROOT . '/admin/assets/logo.svg') ?: 'Wordless' ?></div>
  <h1><?= e(__('Créez votre site en une minute')) ?></h1>
  <p class="muted"><?= e(__('Pas de base de données à configurer : Wordless s’installe tout seul. Il suffit d’un compte administrateur.')) ?></p>

  <?php if (!$ok): ?>
    <div class="notice notice--bad" role="alert">
      <strong><?= e(__('Votre hébergement ne remplit pas encore les conditions :')) ?></strong>
      <ul class="checklist">
        <?php foreach ($checks as [$label, $pass, $detail]): ?>
          <li class="<?= $pass ? 'is-ok' : 'is-bad' ?>"><?= e(__($label)) ?><?php if ($detail !== ''): ?> <span class="muted">(<?= e($detail) ?>)</span><?php endif ?></li>
        <?php endforeach ?>
      </ul>
    </div>
  <?php else: ?>
    <?php if ($errors): ?>
      <div class="notice notice--bad" role="alert"><?php foreach ($errors as $err): ?><p><?= e($err) ?></p><?php endforeach ?></div>
    <?php endif ?>
    <form method="post" class="form-stack" autocomplete="on" data-install>
      <input type="hidden" name="_token" value="<?= e($token) ?>">
      <input type="hidden" name="timezone" value="" data-timezone>
      <div class="field">
        <label for="site_name"><?= e(__('Nom du site')) ?></label>
        <input id="site_name" name="site_name" required maxlength="120" value="<?= e($old['site_name']) ?>" placeholder="<?= e(__('Ex. Atelier Lumière')) ?>" autofocus>
      </div>
      <div class="field-row">
        <div class="field">
          <label for="name"><?= e(__('Votre nom')) ?></label>
          <input id="name" name="name" maxlength="80" value="<?= e($old['name']) ?>" autocomplete="name">
        </div>
        <div class="field">
          <label for="language"><?= e(__('Langue')) ?></label>
          <select id="language" name="language">
            <?php foreach (I18n::LANGUAGES as $code => $label): ?>
              <option value="<?= e($code) ?>" <?= $old['language'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach ?>
          </select>
        </div>
      </div>
      <div class="field">
        <label for="email"><?= e(__('E-mail de connexion')) ?></label>
        <input id="email" name="email" type="email" required value="<?= e($old['email']) ?>" autocomplete="email">
      </div>
      <div class="field">
        <label for="password"><?= e(__('Mot de passe')) ?></label>
        <div class="input-group">
          <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password" data-strength>
          <button type="button" class="btn btn-ghost" data-reveal="password"><?= e(__('Afficher')) ?></button>
        </div>
        <p class="hint" data-strength-label><?= e(__('10 caractères minimum. Une courte phrase est plus sûre et plus facile à retenir.')) ?></p>
      </div>
      <label class="check">
        <input type="checkbox" name="migrate" value="1" <?= $old['migrate'] === '1' ? 'checked' : '' ?>>
        <span><?= e(__('J’ai déjà un site WordPress : je veux le migrer juste après')) ?></span>
      </label>
      <button class="btn btn-primary btn-lg" type="submit"><?= e(__('Créer mon site')) ?></button>
    </form>
    <details class="requirements">
      <summary><?= e(__('Vérifications de l’hébergement')) ?></summary>
      <ul class="checklist">
        <?php foreach ($checks as [$label, $pass]): ?><li class="<?= $pass ? 'is-ok' : 'is-bad' ?>"><?= e(__($label)) ?></li><?php endforeach ?>
        <?php foreach ($optional as [$label, $pass]): ?><li class="<?= $pass ? 'is-ok' : 'is-warn' ?>"><?= e(__($label)) ?><?= $pass ? '' : ' — ' . e(__('optionnel')) ?></li><?php endforeach ?>
      </ul>
    </details>
  <?php endif ?>
</main>
<script src="<?= e(asset_url('admin/assets/auth.js')) ?>" defer></script>
</body>
</html>
