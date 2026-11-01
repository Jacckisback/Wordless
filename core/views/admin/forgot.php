<?php /** @var bool $sent @var string $pre */
$pageTitle = __('Mot de passe oublié'); include __DIR__ . '/_auth_head.php'; ?>
  <h1><?= e(__('Mot de passe oublié')) ?></h1>
  <?php if ($sent): ?>
    <div class="notice notice--good" role="status"><p><?= e(__('Si un compte existe avec cette adresse, un lien de réinitialisation vient d’être envoyé. Il est valable une heure.')) ?></p></div>
  <?php else: ?>
    <p class="muted"><?= e(__('Indiquez votre e-mail : nous vous envoyons un lien pour choisir un nouveau mot de passe.')) ?></p>
    <form method="post" class="form-stack" action="<?= e(admin_url('forgot')) ?>">
      <input type="hidden" name="_pre" value="<?= e($pre) ?>">
      <div class="field"><label for="email"><?= e(__('E-mail')) ?></label><input id="email" name="email" type="email" required autocomplete="email" autofocus></div>
      <button class="btn btn-primary btn-lg" type="submit"><?= e(__('Envoyer le lien')) ?></button>
    </form>
  <?php endif ?>
  <p class="auth-foot"><a href="<?= e(admin_url('login')) ?>"><?= e(__('← Retour à la connexion')) ?></a></p>
<?php include __DIR__ . '/_auth_foot.php'; ?>
