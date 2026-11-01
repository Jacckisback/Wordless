<?php
/** @var array $u @var array $sessions @var string $current @var ?array $avatar */
use Wordless\Auth;
use Wordless\I18n;
use Wordless\Media;
?>
<div class="split">
  <div class="stack">
    <form class="panel" method="post" action="<?= e(admin_url('profile')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="profile">
      <div class="panel-head"><div><h2><?= e(__('Profil public')) ?></h2><p class="muted small"><?= e(__('Affiché sous vos articles : Google et les IA s’en servent pour juger de votre expertise (E-E-A-T).')) ?></p></div></div>
      <div class="panel-body form-stack">
        <div class="field-row">
          <div class="field"><label for="name"><?= e(__('Nom affiché')) ?></label><input id="name" name="name" value="<?= e($u['name']) ?>" required maxlength="80"></div>
          <div class="field"><label for="email"><?= e(__('E-mail')) ?></label><input id="email" name="email" type="email" value="<?= e($u['email']) ?>" required></div>
        </div>
        <div class="field"><label for="bio"><?= e(__('Biographie')) ?></label><textarea id="bio" name="bio" rows="4" maxlength="1500" placeholder="<?= e(__('Votre métier, vos années d’expérience, vos spécialités, ce qui vous rend légitime sur vos sujets.')) ?>"><?= e($u['bio']) ?></textarea></div>
        <div class="field"><label for="links"><?= e(__('Profils en ligne (un lien https par ligne)')) ?></label><textarea id="links" name="links" rows="3" placeholder="https://www.linkedin.com/in/…"><?= e(implode("\n", json_dec($u['links'], []))) ?></textarea><p class="hint"><?= e(__('LinkedIn, site personnel, page Amazon auteur… Reliés à vous dans les données structurées (sameAs).')) ?></p></div>
        <div class="field"><span class="label"><?= e(__('Photo')) ?></span>
          <div class="row" data-media-field>
            <input type="hidden" name="avatar_id" value="<?= (int) ($u['avatar_id'] ?? 0) ?: '' ?>">
            <div class="media-thumb" style="width:4rem;border-radius:50%;overflow:hidden" data-media-thumb><?= $avatar ? Media::picture($avatar, ['sizes' => '64px', 'alt' => '']) : icon('user') ?></div>
            <button type="button" class="btn btn-sm" data-media-pick><?= e(__('Choisir une photo')) ?></button>
            <button type="button" class="btn btn-sm btn-ghost" data-media-clear><?= e(__('Retirer')) ?></button>
          </div>
        </div>
        <div class="field"><label for="lang"><?= e(__('Langue de l’administration')) ?></label>
          <select id="lang" name="lang"><option value=""><?= e(__('Langue du site')) ?></option><?php foreach (I18n::LANGUAGES as $k => $l): ?><option value="<?= e($k) ?>" <?= $u['lang'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      </div>
      <div class="panel-foot"><button class="btn btn-primary" type="submit"><?= e(__('Enregistrer le profil')) ?></button></div>
    </form>

    <form class="panel" method="post" action="<?= e(admin_url('profile')) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="password">
      <div class="panel-head"><h2><?= e(__('Mot de passe')) ?></h2></div>
      <div class="panel-body form-stack">
        <div class="field-row">
          <div class="field"><label for="current"><?= e(__('Mot de passe actuel')) ?></label><input id="current" name="current" type="password" required autocomplete="current-password"></div>
          <div class="field"><label for="password"><?= e(__('Nouveau mot de passe')) ?></label><input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"></div>
        </div>
      </div>
      <div class="panel-foot"><button class="btn" type="submit"><?= e(__('Changer le mot de passe')) ?></button></div>
    </form>
  </div>

  <div class="stack">
    <div class="panel"><div class="panel-body stack-s">
      <h2><?= e(__('Double authentification')) ?></h2>
      <?php if ((int) $u['totp_enabled']): ?>
        <p class="status is-good"><?= icon('shield') ?> <?= e(__('Activée')) ?></p>
        <p class="muted small"><?= e(__('Un code de votre téléphone est demandé à chaque connexion.')) ?></p>
      <?php else: ?>
        <p class="status is-bad"><?= icon('alert') ?> <?= e(__('Désactivée')) ?></p>
        <p class="muted small"><?= e(__('Protège votre compte même si votre mot de passe fuite. 2 minutes avec Google Authenticator, 1Password ou Bitwarden.')) ?></p>
      <?php endif ?>
      <a class="btn<?= (int) $u['totp_enabled'] ? '' : ' btn-primary' ?>" href="<?= e(admin_url('profile/2fa')) ?>"><?= e((int) $u['totp_enabled'] ? __('Gérer') : __('Activer maintenant')) ?></a>
    </div></div>
    <div class="panel">
      <div class="panel-head"><h2><?= e(__('Appareils connectés')) ?></h2></div>
      <ul class="feed">
        <?php foreach ($sessions as $s): ?>
          <li><span><?= e(preg_match('/(Firefox|Edg|Chrome|Safari)/', $s['ua'], $m) ? str_replace('Edg', 'Edge', $m[1]) : __('Navigateur')) ?> · <?= e(preg_match('/(Windows|Mac OS|Android|iPhone|iPad|Linux)/', $s['ua'], $m2) ? $m2[1] : '') ?><?= hash_equals($s['id'], $current) ? ' — <strong>' . e(__('cet appareil')) . '</strong>' : '' ?></span><time><?= e(human_time((int) $s['seen_at'])) ?></time></li>
        <?php endforeach ?>
      </ul>
      <?php if (count($sessions) > 1): ?>
        <form class="panel-foot" method="post" action="<?= e(admin_url('profile')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="sessions"><button class="btn btn-sm" type="submit"><?= e(__('Déconnecter les autres appareils')) ?></button></form>
      <?php endif ?>
    </div>
    <div class="panel"><div class="panel-body stack-s">
      <h2><?= e(__('Apparence de l’administration')) ?></h2>
      <div class="actions-row" role="group" aria-label="<?= e(__('Thème')) ?>">
        <button class="btn btn-sm" type="button" data-set-theme="light"><?= icon('sun') ?><?= e(__('Clair')) ?></button>
        <button class="btn btn-sm" type="button" data-set-theme="dark"><?= icon('moon') ?><?= e(__('Sombre')) ?></button>
        <button class="btn btn-sm" type="button" data-set-theme="auto"><?= e(__('Automatique')) ?></button>
      </div>
    </div></div>
  </div>
</div>
