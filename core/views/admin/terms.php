<?php /** @var string $tax @var array $terms @var ?array $edit @var array $all */ ?>
<nav class="tabs"><a href="<?= e(admin_url('terms?taxonomy=category')) ?>"<?= $tax === 'category' ? ' aria-current="page"' : '' ?>><?= e(__('Catégories')) ?></a><a href="<?= e(admin_url('terms?taxonomy=tag')) ?>"<?= $tax === 'tag' ? ' aria-current="page"' : '' ?>><?= e(__('Étiquettes')) ?></a></nav>
<div class="split">
  <section class="panel"><?php if ($terms): ?><div class="table-wrap"><table class="table"><thead><tr><th><?= e(__('Nom')) ?></th><th><?= e(__('Adresse')) ?></th><th class="num"><?= e(__('Articles')) ?></th><th></th></tr></thead><tbody>
    <?php foreach ($terms as $t): ?><tr><td class="title-cell"><a href="<?= e(admin_url('terms?taxonomy=' . $tax . '&edit=' . $t['id'])) ?>"><?= e($t['name']) ?></a></td><td class="muted small mono"><?= e(link_path(($tax === 'category' ? '/category/' : '/tag/') . $t['slug'])) ?></td><td class="num"><a href="<?= e(admin_url('content?type=post&term=' . $t['id'])) ?>"><?= (int) $t['count'] ?></a></td>
      <td><form method="post" action="<?= e(admin_url('terms')) ?>"><?= csrf_field() ?><input type="hidden" name="taxonomy" value="<?= e($tax) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $t['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit" data-confirm="<?= e(__('Supprimer ? Les articles ne sont pas supprimés.')) ?>" aria-label="<?= e(__('Supprimer')) ?>"><?= icon('trash') ?></button></form></td></tr><?php endforeach ?>
  </tbody></table></div><?php else: ?><div class="empty"><p><?= e(__('Aucun élément pour l’instant.')) ?></p></div><?php endif ?></section>
  <form class="panel" method="post" action="<?= e(admin_url('terms')) ?>"><?= csrf_field() ?><input type="hidden" name="taxonomy" value="<?= e($tax) ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif ?>
    <div class="panel-head"><h2><?= e($edit ? __('Modifier') : __('Ajouter')) ?></h2></div>
    <div class="panel-body form-stack">
      <div class="field"><label for="t-name"><?= e(__('Nom')) ?></label><input id="t-name" name="name" required value="<?= e($edit['name'] ?? '') ?>"></div>
      <div class="field"><label for="t-slug"><?= e(__('Adresse (slug)')) ?></label><input id="t-slug" name="slug" value="<?= e($edit['slug'] ?? '') ?>"><?php if ($edit): ?><p class="hint"><?= e(__('Si vous la changez, l’ancienne adresse est redirigée (301).')) ?></p><?php endif ?></div>
      <?php if ($tax === 'category'): ?><div class="field"><label for="t-parent"><?= e(__('Catégorie parente')) ?></label><select id="t-parent" name="parent_id"><option value=""><?= e(__('Aucune')) ?></option><?php foreach ($all as $c): if ($edit && (int) $c['id'] === (int) $edit['id']) { continue; } ?><option value="<?= (int) $c['id'] ?>" <?= (int) ($edit['parent_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach ?></select></div><?php endif ?>
      <div class="field"><label for="t-desc"><?= e(__('Description')) ?></label><textarea id="t-desc" name="description" rows="4"><?= e($edit['description'] ?? '') ?></textarea><p class="hint"><?= e(__('Affichée en tête de la page de la catégorie : un vrai texte aide son référencement.')) ?></p></div>
    </div>
    <div class="panel-foot"><button class="btn btn-primary" type="submit"><?= e(__('Enregistrer')) ?></button></div>
  </form>
</div>
