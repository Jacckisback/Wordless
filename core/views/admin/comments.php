<?php /** @var array $rows @var string $status @var array $counts */ use Wordless\Content; ?>
<nav class="tabs"><?php foreach (['pending' => __('En attente'), 'approved' => __('Approuvés'), 'spam' => __('Indésirables'), 'trash' => __('Corbeille')] as $k => $l): ?><a href="<?= e(admin_url('comments?status=' . $k)) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?> <span class="n"><?= (int) ($counts[$k] ?? 0) ?></span></a><?php endforeach ?></nav>
<?php if (!$rows): ?>
  <div class="panel"><div class="empty"><h2><?= e($status === 'pending' ? __('Aucun commentaire en attente') : __('Rien ici')) ?></h2><p><?= e(__('Le filtre anti-spam intégré (champ piège, délai minimal, limite de fréquence) écarte les robots sans service externe.')) ?></p></div></div>
<?php else: ?>
<form class="panel" method="post" action="<?= e(admin_url('comments/action')) ?>"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e(admin_url('comments?status=' . $status)) ?>">
  <div class="table-wrap"><table class="table">
    <thead><tr><th style="width:2rem"><input type="checkbox" data-check-all aria-label="<?= e(__('Tout sélectionner')) ?>"></th><th><?= e(__('Auteur')) ?></th><th><?= e(__('Commentaire')) ?></th><th><?= e(__('Sur')) ?></th><th><?= e(__('Date')) ?></th></tr></thead>
    <tbody><?php foreach ($rows as $c): ?>
      <tr><td><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>"></td>
        <td><strong><?= e($c['author']) ?></strong><div class="muted small"><?= e($c['email']) ?></div></td>
        <td><div class="small"><?= e(excerpt(plain_text($c['body']), 220)) ?></div>
          <div class="row-actions">
            <?php if ($status !== 'approved'): ?><button type="submit" name="one" value="approved:<?= (int) $c['id'] ?>"><?= e(__('Approuver')) ?></button><?php endif ?>
            <?php if ($status === 'approved'): ?><button type="submit" name="one" value="pending:<?= (int) $c['id'] ?>"><?= e(__('Désapprouver')) ?></button><?php endif ?>
            <?php if ($status !== 'spam'): ?><button type="submit" name="one" value="spam:<?= (int) $c['id'] ?>"><?= e(__('Indésirable')) ?></button><?php endif ?>
            <?php if ($status !== 'trash'): ?><button type="submit" name="one" value="trash:<?= (int) $c['id'] ?>" class="danger"><?= e(__('Corbeille')) ?></button><?php else: ?><button type="submit" name="one" value="delete:<?= (int) $c['id'] ?>" class="danger" data-confirm="<?= e(__('Supprimer définitivement ?')) ?>"><?= e(__('Supprimer')) ?></button><?php endif ?>
          </div></td>
        <td class="small"><?php if ($c['cid']): ?><a href="<?= e(admin_url('content/' . $c['cid'])) ?>"><?= e($c['content_title']) ?></a><?php endif ?></td>
        <td class="muted small nowrap"><?= e(human_time((int) $c['created_at'])) ?></td></tr>
    <?php endforeach ?></tbody>
  </table></div>
  <div class="panel-foot row"><select name="action" style="width:auto"><option value="approved"><?= e(__('Approuver')) ?></option><option value="spam"><?= e(__('Marquer indésirable')) ?></option><option value="trash"><?= e(__('Mettre à la corbeille')) ?></option><?php if ($status === 'trash' || $status === 'spam'): ?><option value="delete"><?= e(__('Supprimer définitivement')) ?></option><?php endif ?></select><button class="btn" type="submit"><?= e(__('Appliquer à la sélection')) ?></button></div>
</form>
<?php endif ?>
