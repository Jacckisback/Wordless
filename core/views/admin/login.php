<?php /** @var ?string $error @var string $email @var string $pre @var string $redirect */
use Wordless\Settings;
$pageTitle = __('Connexion'); include __DIR__ . '/_auth_head.php'; ?>
  <h1><?= e(__('Connexion')) ?></h1>
  <p class="muted"><?= e(Settings::get('site.name')) ?></p>
  <?php if ($error): ?><div class="notice notice--bad" role="alert"><p><?= e($error) ?></p></div><?php endif ?>
  <form method="post" class="form-stack" action="<?= e(admin_url('login')) ?>">
    <input type="hidden" name="_pre" value="<?= e($pre) ?>">
    <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <div class="field"><label for="email"><?= e(__('E-mail')) ?></label><input id="email" name="email" type="email" required autocomplete="username" value="<?= e($email) ?>" autofocus></div>
    <div class="field"><label for="password"><?= e(__('Mot de passe')) ?></label>
      <div class="input-group"><input id="password" name="password" type="password" required autocomplete="current-password"><button type="button" class="btn" data-reveal="password"><?= e(__('Afficher')) ?></button></div></div>
    <label class="check"><input type="checkbox" name="remember" value="1" checked><span><?= e(__('Rester connecté 30 jours sur cet appareil')) ?></span></label>
    <button class="btn btn-primary btn-lg" type="submit"><?= e(__('Se connecter')) ?></button>
  </form>
  <p class="auth-foot"><a href="<?= e(admin_url('forgot')) ?>"><?= e(__('Mot de passe oublié ?')) ?></a></p>
<?php include __DIR__ . '/_auth_foot.php'; ?>
