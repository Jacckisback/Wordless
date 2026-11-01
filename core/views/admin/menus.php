<?php /** @var array $menus @var ?array $menu @var array $items @var array $pages @var array $cats @var array $locations */ ?>
<div class="toolbar">
  <nav class="tabs" style="margin:0;border:0"><?php foreach ($menus as $m): ?><a href="<?= e(admin_url('menus?id=' . $m['id'])) ?>"<?= $menu && (int) $menu['id'] === (int) $m['id'] ? ' aria-current="page"' : '' ?>><?= e($m['name']) ?></a><?php endforeach ?></nav>
  <form method="post" action="<?= e(admin_url('menus')) ?>" class="row"><?= csrf_field() ?><input type="hidden" name="action" value="create"><input name="name" placeholder="<?= e(__('Nom du nouveau menu')) ?>" style="max-width:14rem"><button class="btn" type="submit"><?= icon('plus') ?><?= e(__('Créer')) ?></button></form>
</div>
<?php if ($menu): ?>
<script type="application/json" id="menu-data"><?= json_encode(['id' => (int) $menu['id'], 'name' => $menu['name'], 'items' => $items, 'locations' => array_keys(array_filter($locations, static fn ($v) => (int) $v === (int) $menu['id']))], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<div class="split">
  <section class="panel">
    <div class="panel-head"><input id="menu-name" value="<?= e($menu['name']) ?>" aria-label="<?= e(__('Nom du menu')) ?>" style="max-width:18rem;font-weight:600"><button class="btn btn-primary" type="button" id="menu-save"><?= e(__('Enregistrer le menu')) ?></button></div>
    <div class="panel-body">
      <ol class="ed-outline" id="menu-items"></ol>
      <p class="hint" style="margin-top:.75rem"><?= e(__('Glissez pour réordonner. → décale un élément sous le précédent (sous-menu), ← le remonte.')) ?></p>
      <fieldset style="margin-top:1.25rem"><legend><?= e(__('Emplacement')) ?></legend>
        <label class="check"><input type="checkbox" data-loc="primary"><span><?= e(__('Navigation principale (en-tête)')) ?></span></label>
        <label class="check"><input type="checkbox" data-loc="footer"><span><?= e(__('Pied de page')) ?></span></label>
      </fieldset>
    </div>
    <div class="panel-foot"><form method="post" action="<?= e(admin_url('menus')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $menu['id'] ?>"><button class="btn btn-sm btn-danger" type="submit" data-confirm="<?= e(__('Supprimer ce menu ?')) ?>"><?= e(__('Supprimer le menu')) ?></button></form></div>
  </section>
  <aside class="stack">
    <div class="panel"><div class="panel-head"><h2><?= e(__('Ajouter des pages')) ?></h2></div><div class="panel-body stack-s">
      <div class="cat-list" style="max-height:14rem;overflow:auto"><?php foreach ($pages as $p): ?><label class="check"><input type="checkbox" data-add-content="<?= (int) $p['id'] ?>" data-label="<?= e($p['title']) ?>"><span><?= e($p['title']) ?> <span class="muted small"><?= e($p['type'] === 'page' ? __('page') : __('article')) ?></span></span></label><?php endforeach ?></div>
      <button class="btn btn-sm" type="button" id="add-content"><?= e(__('Ajouter au menu')) ?></button></div></div>
    <?php if ($cats): ?><div class="panel"><div class="panel-head"><h2><?= e(__('Ajouter des catégories')) ?></h2></div><div class="panel-body stack-s">
      <div class="cat-list"><?php foreach ($cats as $c): ?><label class="check"><input type="checkbox" data-add-term="<?= (int) $c['id'] ?>" data-label="<?= e($c['name']) ?>"><span><?= e($c['name']) ?></span></label><?php endforeach ?></div>
      <button class="btn btn-sm" type="button" id="add-terms"><?= e(__('Ajouter au menu')) ?></button></div></div><?php endif ?>
    <div class="panel"><div class="panel-head"><h2><?= e(__('Lien personnalisé')) ?></h2></div><div class="panel-body form-stack">
      <div class="field"><label for="l-url"><?= e(__('Adresse')) ?></label><input id="l-url" placeholder="https://… ou /page"></div>
      <div class="field"><label for="l-label"><?= e(__('Texte du lien')) ?></label><input id="l-label"></div>
      <button class="btn btn-sm" type="button" id="add-link"><?= e(__('Ajouter au menu')) ?></button></div></div>
  </aside>
</div>
<?php else: ?>
<div class="panel"><div class="empty"><h2><?= e(__('Aucun menu')) ?></h2><p><?= e(__('Créez un menu, ajoutez-y vos pages, puis choisissez son emplacement.')) ?></p></div></div>
<?php endif ?>
