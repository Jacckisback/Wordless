<?php /** @var array $list @var bool $auto @var int $last @var bool $zip */ ?>
<div class="split">
  <section class="panel">
    <div class="panel-head"><div><h2><?= e(__('Sauvegardes disponibles')) ?></h2><p class="muted small"><?= e($last ? __('Dernière sauvegarde {t}', ['t' => human_time($last)]) : __('Aucune sauvegarde pour l’instant.')) ?></p></div></div>
    <?php if ($list): ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th><?= e(__('Fichier')) ?></th><th><?= e(__('Date')) ?></th><th class="num"><?= e(__('Taille')) ?></th><th></th></tr></thead>
      <tbody><?php foreach ($list as $b): ?>
        <tr><td class="mono small"><?= e($b['name']) ?></td><td class="muted small"><?= e(fmt_date($b['time'], 'datetime')) ?></td><td class="num muted"><?= e(fmt_bytes($b['size'])) ?></td>
          <td><div class="actions-row">
            <a class="btn btn-sm" href="<?= e(admin_url('backups/download?name=' . rawurlencode($b['name']))) ?>"><?= icon('import') ?><?= e(__('Télécharger')) ?></a>
            <?php if (str_ends_with($b['name'], '.db')): ?>
            <form method="post" action="<?= e(admin_url('backups')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="restore"><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn btn-sm" type="submit" data-confirm="<?= e(__('Restaurer cette sauvegarde ? Le contenu actuel sera remplacé (une copie de sécurité est créée avant).')) ?>"><?= e(__('Restaurer')) ?></button></form>
            <?php endif ?>
            <form method="post" action="<?= e(admin_url('backups')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="name" value="<?= e($b['name']) ?>"><button class="btn btn-sm btn-ghost" type="submit" data-confirm="<?= e(__('Supprimer cette sauvegarde ?')) ?>" aria-label="<?= e(__('Supprimer')) ?>"><?= icon('trash') ?></button></form>
          </div></td></tr>
      <?php endforeach ?></tbody>
    </table></div>
    <?php else: ?><div class="empty"><p><?= e(__('Cliquez sur « Sauvegarder maintenant » : la base entière tient dans un seul fichier.')) ?></p></div><?php endif ?>
  </section>
  <aside class="stack">
    <form class="panel" method="post" action="<?= e(admin_url('backups')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="auto">
      <div class="panel-body stack-s">
        <label class="switch"><span class="switch-text"><span class="label"><?= e(__('Sauvegarde automatique quotidienne')) ?></span><span class="hint"><?= e(__('14 dernières conservées, sans tâche planifiée à configurer.')) ?></span></span><input type="checkbox" name="auto" value="1" <?= $auto ? 'checked' : '' ?>></label>
        <button class="btn btn-sm" type="submit"><?= e(__('Enregistrer')) ?></button>
      </div>
    </form>
    <?php if ($zip): ?>
    <form class="panel" method="post" action="<?= e(admin_url('backups')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="full">
      <div class="panel-body stack-s"><h2><?= e(__('Sauvegarde complète')) ?></h2><p class="muted small"><?= e(__('Base + médias + configuration dans un zip, pour déménager ou archiver le site. Gardez-la en lieu sûr : elle contient le secret du site.')) ?></p>
      <button class="btn" type="submit"><?= icon('archive') ?><?= e(__('Créer une sauvegarde complète')) ?></button></div>
    </form>
    <?php endif ?>
    <div class="notice"><?= icon('info') ?><p><?= e(__('Conseil : téléchargez une sauvegarde complète chaque mois et stockez-la hors de votre hébergement.')) ?></p></div>
  </aside>
</div>
