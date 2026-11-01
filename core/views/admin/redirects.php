<?php /** @var array $rows @var array $notFound @var string $prefill */ ?>
<div class="split">
  <div class="stack">
    <form class="panel" method="post" action="<?= e(admin_url('seo/redirects')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="add">
      <div class="panel-body field-row" style="align-items:end">
        <div class="field"><label for="source"><?= e(__('Ancienne adresse')) ?></label><input id="source" name="source" required placeholder="/ancienne-page" value="<?= e($prefill) ?>" class="mono"></div>
        <div class="field"><label for="target"><?= e(__('Nouvelle adresse')) ?></label><input id="target" name="target" placeholder="/nouvelle-page ou https://…" class="mono"></div>
        <div class="field"><label for="code"><?= e(__('Type')) ?></label><select id="code" name="code"><option value="301"><?= e(__('301 — définitive')) ?></option><option value="302"><?= e(__('302 — temporaire')) ?></option><option value="410"><?= e(__('410 — supprimée')) ?></option></select></div>
        <div><button class="btn btn-primary" type="submit"><?= e(__('Ajouter')) ?></button></div>
      </div>
    </form>
    <section class="panel"><div class="panel-head"><h2><?= e(_n('{n} redirection', '{n} redirections', count($rows))) ?></h2></div>
      <?php if ($rows): ?><div class="table-wrap"><table class="table"><thead><tr><th><?= e(__('De')) ?></th><th><?= e(__('Vers')) ?></th><th><?= e(__('Type')) ?></th><th class="num"><?= e(__('Visites')) ?></th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?><tr><td class="mono small"><?= e($r['source']) ?></td><td class="mono small"><?= e($r['target']) ?></td><td><span class="badge"><?= (int) $r['code'] ?></span><?= (int) $r['auto'] ? ' <span class="badge badge--accent">' . e(__('auto')) . '</span>' : '' ?></td><td class="num"><?= (int) $r['hits'] ?></td>
          <td><form method="post" action="<?= e(admin_url('seo/redirects')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit" aria-label="<?= e(__('Supprimer')) ?>"><?= icon('trash') ?></button></form></td></tr><?php endforeach ?>
      </tbody></table></div><?php else: ?><div class="empty"><p><?= e(__('Les redirections créées automatiquement (changement d’adresse) apparaîtront ici.')) ?></p></div><?php endif ?>
    </section>
  </div>
  <aside class="stack">
    <section class="panel"><div class="panel-head"><h2><?= e(__('Erreurs 404 récentes')) ?></h2></div>
      <?php if ($notFound): ?><ul class="feed"><?php foreach ($notFound as $n): ?><li><span><span class="mono small"><?= e(excerpt($n['path'], 40)) ?></span><?php if ($n['suggest'] !== ''): ?><span class="muted small" style="display:block">→ <?= e($n['suggest']) ?></span><?php endif ?></span><span class="badge"><?= (int) $n['hits'] ?></span>
        <form method="post" action="<?= e(admin_url('seo/redirects')) ?>" class="row"><?= csrf_field() ?>
          <?php if ($n['suggest'] !== ''): ?><input type="hidden" name="action" value="add"><input type="hidden" name="source" value="<?= e($n['path']) ?>"><input type="hidden" name="target" value="<?= e($n['suggest']) ?>"><button class="btn btn-sm" type="submit"><?= e(__('Rediriger')) ?></button>
          <?php else: ?><a class="btn btn-sm" href="<?= e(admin_url('seo/redirects?from=' . rawurlencode($n['path']))) ?>"><?= e(__('Choisir')) ?></a><?php endif ?></form></li><?php endforeach ?></ul>
      <?php else: ?><div class="panel-body"><p class="muted small"><?= e(__('Aucune pour le moment.')) ?></p></div><?php endif ?></section>
    <form class="panel" method="post" action="<?= e(admin_url('seo/redirects')) ?>" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="action" value="import">
      <div class="panel-body stack-s"><h2><?= e(__('Importer un fichier CSV')) ?></h2><p class="hint"><?= e(__('Colonnes : ancienne adresse, nouvelle adresse, code (facultatif). Compatible avec l’export de l’extension Redirection de WordPress.')) ?></p>
        <input type="file" name="csv" accept=".csv,text/csv" required><button class="btn btn-sm" type="submit"><?= e(__('Importer')) ?></button></div>
    </form>
  </aside>
</div>
