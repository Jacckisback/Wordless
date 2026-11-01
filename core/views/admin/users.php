<?php /** @var array $rows */ use Wordless\Auth; ?>
<section class="panel"><div class="table-wrap"><table class="table">
  <thead><tr><th><?= e(__('Nom')) ?></th><th><?= e(__('Rôle')) ?></th><th><?= e(__('2FA')) ?></th><th class="num"><?= e(__('Contenus')) ?></th><th><?= e(__('Dernière connexion')) ?></th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $u): ?>
    <tr><td class="title-cell"><a href="<?= e(admin_url('users/edit?id=' . $u['id'])) ?>"><?= e($u['name']) ?></a><div class="muted small"><?= e($u['email']) ?></div></td>
      <td><span class="badge<?= $u['role'] === 'admin' ? ' badge--accent' : '' ?>"><?= e(__(Auth::ROLES[$u['role']]['label'] ?? $u['role'])) ?></span><?= $u['status'] !== 'active' ? ' <span class="badge badge--warn">' . e($u['status'] === 'disabled' ? __('désactivé') : __('invité')) . '</span>' : '' ?></td>
      <td><?= (int) $u['totp_enabled'] ? '<span class="status is-good">' . icon('shield') . e(__('active')) . '</span>' : '<span class="status is-warn">' . e(__('non')) . '</span>' ?></td>
      <td class="num"><?= (int) $u['posts'] ?></td>
      <td class="muted small"><?= e($u['last_login_at'] ? human_time((int) $u['last_login_at']) : __('jamais')) ?></td>
      <td><?php if ((int) $u['id'] !== Auth::id()): ?><form method="post" action="<?= e(admin_url('users/action')) ?>" class="actions-row"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
        <?php if ((int) $u['totp_enabled']): ?><button class="btn btn-sm" name="action" value="reset2fa" type="submit" data-confirm="<?= e(__('Réinitialiser la double authentification de cette personne (téléphone perdu) ?')) ?>"><?= e(__('Réinit. 2FA')) ?></button><?php endif ?>
        <button class="btn btn-sm btn-ghost" name="action" value="delete" type="submit" data-confirm="<?= e(__('Supprimer cet utilisateur ? Ses contenus vous seront attribués.')) ?>" aria-label="<?= e(__('Supprimer')) ?>"><?= icon('trash') ?></button></form><?php endif ?></td></tr>
  <?php endforeach ?></tbody>
</table></div></section>
<div class="notice" style="margin-top:1rem"><?= icon('info') ?><p><?= e(__('Administrateur : tout. Éditeur : tous les contenus, médias, menus, SEO et design. Auteur : ses propres articles.')) ?></p></div>
