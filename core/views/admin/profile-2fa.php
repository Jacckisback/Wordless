<?php /** @var array $u @var ?array $codes @var ?string $qr @var ?string $secret @var ?string $sealed @var ?string $uri */ ?>
<?php if ($codes): ?>
  <div class="panel"><div class="panel-body stack">
    <div class="notice notice--good"><?= icon('shield') ?><p><strong><?= e(__('Double authentification activée.')) ?></strong></p></div>
    <h2><?= e(__('Vos codes de secours')) ?></h2>
    <p><?= e(__('Notez-les maintenant et gardez-les en lieu sûr : chacun permet de vous connecter une fois si vous perdez votre téléphone. Ils ne seront plus affichés.')) ?></p>
    <div class="codes"><?php foreach ($codes as $c): ?><span><?= e($c) ?></span><?php endforeach ?></div>
    <div class="actions-row"><button class="btn" type="button" data-copy="<?= e(implode("\n", $codes)) ?>"><?= icon('copy') ?><?= e(__('Copier les codes')) ?></button><a class="btn btn-primary" href="<?= e(admin_url()) ?>"><?= e(__('C’est noté, continuer')) ?></a></div>
  </div></div>
<?php elseif ((int) $u['totp_enabled']): ?>
  <form class="panel" method="post" action="<?= e(admin_url('profile/2fa')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="disable">
    <div class="panel-body stack">
      <p class="status is-good"><?= icon('shield') ?> <?= e(__('La double authentification est active sur votre compte.')) ?></p>
      <div class="field"><label for="password"><?= e(__('Pour la désactiver, confirmez votre mot de passe')) ?></label><input id="password" name="password" type="password" required autocomplete="current-password"></div>
    </div>
    <div class="panel-foot"><button class="btn btn-danger" type="submit"><?= e(__('Désactiver la double authentification')) ?></button></div>
  </form>
<?php else: ?>
  <form class="panel" method="post" action="<?= e(admin_url('profile/2fa')) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="enable"><input type="hidden" name="secret" value="<?= e($sealed) ?>">
    <div class="panel-body stack">
      <ol class="steps-list">
        <li><?= e(__('Installez une application d’authentification (Google Authenticator, Microsoft Authenticator, 1Password, Bitwarden…).')) ?></li>
        <li><?= e(__('Scannez ce QR code avec l’application :')) ?></li>
      </ol>
      <div class="qr"><?= $qr ?></div>
      <details><summary class="small"><?= e(__('Impossible de scanner ? Saisissez la clé à la main')) ?></summary>
        <div class="code-box" style="margin-top:.5rem"><code><?= e(trim(chunk_split((string) $secret, 4, ' '))) ?></code><button class="btn btn-sm" type="button" data-copy="<?= e((string) $secret) ?>"><?= icon('copy') ?></button></div>
        <p class="hint" style="margin-top:.4rem"><a href="<?= e((string) $uri) ?>"><?= e(__('Ouvrir directement dans l’application (sur téléphone)')) ?></a></p>
      </details>
      <ol class="steps-list" start="3"><li><?= e(__('Saisissez le code à 6 chiffres affiché :')) ?></li></ol>
      <div class="field"><label class="sr" for="code"><?= e(__('Code')) ?></label><input id="code" class="otp-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required></div>
    </div>
    <div class="panel-foot"><button class="btn btn-primary" type="submit"><?= e(__('Activer la double authentification')) ?></button></div>
  </form>
<?php endif ?>
