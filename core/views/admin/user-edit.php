<?php /** @var ?array $u */ use Wordless\Auth; ?>
<form class="panel" method="post" action="<?= e(admin_url('users/edit')) ?>"><?= csrf_field() ?><?php if ($u): ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>"><?php endif ?>
  <div class="panel-body form-stack">
    <div class="field-row">
      <div class="field"><label for="name"><?= e(__('Nom')) ?></label><input id="name" name="name" required value="<?= e($u['name'] ?? '') ?>"></div>
      <div class="field"><label for="email"><?= e(__('E-mail')) ?></label><input id="email" name="email" type="email" required value="<?= e($u['email'] ?? '') ?>"></div>
    </div>
    <div class="field"><label for="role"><?= e(__('Rôle')) ?></label><select id="role" name="role"><?php foreach (Auth::ROLES as $k => $r): ?><option value="<?= e($k) ?>" <?= ($u['role'] ?? 'author') === $k ? 'selected' : '' ?>><?= e(__($r['label'])) ?></option><?php endforeach ?></select></div>
    <?php if ($u): ?><div class="field"><label for="status"><?= e(__('Statut')) ?></label><select id="status" name="status"><option value="active" <?= $u['status'] === 'active' ? 'selected' : '' ?>><?= e(__('Actif')) ?></option><option value="disabled" <?= $u['status'] === 'disabled' ? 'selected' : '' ?>><?= e(__('Désactivé (ne peut plus se connecter)')) ?></option></select></div>
    <?php else: ?><p class="hint"><?= e(__('La personne reçoit un e-mail pour choisir son mot de passe.')) ?></p><?php endif ?>
  </div>
  <div class="panel-foot"><button class="btn btn-primary" type="submit"><?= e($u ? __('Enregistrer') : __('Envoyer l’invitation')) ?></button></div>
</form>
