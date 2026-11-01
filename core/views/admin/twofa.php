<?php /** @var ?string $error @var string $pre @var string $redirect */
$pageTitle = __('Double authentification'); include __DIR__ . '/_auth_head.php'; ?>
  <h1><?= e(__('Code de vérification')) ?></h1>
  <p class="muted"><?= e(__('Ouvrez votre application d’authentification et saisissez le code à 6 chiffres.')) ?></p>
  <?php if ($error): ?><div class="notice notice--bad" role="alert"><p><?= e($error) ?></p></div><?php endif ?>
  <form method="post" class="form-stack" action="<?= e(admin_url('2fa')) ?>">
    <input type="hidden" name="_pre" value="<?= e($pre) ?>">
    <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <div class="field"><label for="code"><?= e(__('Code')) ?></label><input id="code" class="otp-input" name="code" inputmode="numeric" autocomplete="one-time-code" required maxlength="12" autofocus></div>
    <button class="btn btn-primary btn-lg" type="submit"><?= e(__('Valider')) ?></button>
  </form>
  <p class="auth-foot muted small"><?= e(__('Téléphone perdu ? Saisissez l’un de vos codes de secours (format XXXXX-XXXXX).')) ?></p>
<?php include __DIR__ . '/_auth_foot.php'; ?>
