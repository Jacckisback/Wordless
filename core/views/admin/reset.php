<?php /** @var bool $valid @var string $token @var ?string $error @var string $pre */
$pageTitle = __('Nouveau mot de passe'); include __DIR__ . '/_auth_head.php'; ?>
  <h1><?= e(__('Nouveau mot de passe')) ?></h1>
  <?php if (!$valid): ?>
    <div class="notice notice--bad" role="alert"><p><?= e(__('Ce lien n’est plus valable. Demandez-en un nouveau.')) ?></p></div>
    <p class="auth-foot"><a href="<?= e(admin_url('forgot')) ?>"><?= e(__('Demander un nouveau lien')) ?></a></p>
  <?php else: ?>
    <?php if ($error): ?><div class="notice notice--bad" role="alert"><p><?= e($error) ?></p></div><?php endif ?>
    <form method="post" class="form-stack" action="<?= e(admin_url('reset')) ?>">
      <input type="hidden" name="_pre" value="<?= e($pre) ?>"><input type="hidden" name="token" value="<?= e($token) ?>">
      <div class="field"><label for="password"><?= e(__('Mot de passe')) ?></label>
        <div class="input-group"><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password" data-strength autofocus><button type="button" class="btn" data-reveal="password"><?= e(__('Afficher')) ?></button></div>
        <p class="hint" data-strength-label><?= e(__('10 caractères minimum. Une courte phrase est plus sûre et plus facile à retenir.')) ?></p></div>
      <button class="btn btn-primary btn-lg" type="submit"><?= e(__('Enregistrer')) ?></button>
    </form>
  <?php endif ?>
<?php include __DIR__ . '/_auth_foot.php'; ?>
