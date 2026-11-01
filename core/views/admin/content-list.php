<?php
/** @var string $type @var string $status @var string $q @var array $items @var array $counts @var int $total @var int $page @var int $per @var array $authors @var string $order */
use Wordless\Auth;
use Wordless\Content;
use Wordless\I18n;

$base = 'content?type=' . $type;
$tabs = ['any' => __('Tous'), 'published' => __('Publiés'), 'draft' => __('Brouillons'), 'scheduled' => __('Programmés'), 'private' => __('Privés'), 'trash' => __('Corbeille')];
$score = static function (?int $s): string {
    if ($s === null) {
        return '<span class="score is-none">—</span>';
    }
    $c = $s >= 80 ? 'is-good' : ($s >= 50 ? 'is-warn' : 'is-bad');
    return '<span class="score ' . $c . '">' . $s . '</span>';
};
$pages = (int) ceil($total / $per);
$back = admin_url($base . ($status !== 'any' ? '&status=' . $status : '') . ($q !== '' ? '&q=' . rawurlencode($q) : ''));
?>
<nav class="tabs" aria-label="<?= e(__('Filtrer par statut')) ?>">
  <?php foreach ($tabs as $k => $label): if ($k !== 'any' && empty($counts[$k])) { continue; } ?>
    <a href="<?= e(admin_url($base . ($k !== 'any' ? '&status=' . $k : ''))) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="n"><?= (int) ($counts[$k] ?? 0) ?></span></a>
  <?php endforeach ?>
</nav>
<form class="toolbar" method="get" action="<?= e(admin_url('content')) ?>">
  <input type="hidden" name="type" value="<?= e($type) ?>"><?php if ($status !== 'any'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
  <label class="sr" for="q"><?= e(__('Rechercher')) ?></label>
  <input type="search" id="q" name="q" value="<?= e($q) ?>" placeholder="<?= e(__('Rechercher un titre ou un texte…')) ?>">
  <label class="sr" for="order"><?= e(__('Trier')) ?></label>
  <select id="order" name="order" data-autosubmit>
    <option value="<?= $type === 'page' ? 'menu' : 'recent' ?>"><?= e($type === 'page' ? __('Ordre du site') : __('Plus récents')) ?></option>
    <option value="updated" <?= $order === 'updated' ? 'selected' : '' ?>><?= e(__('Modifiés récemment')) ?></option>
    <option value="title" <?= $order === 'title' ? 'selected' : '' ?>><?= e(__('Titre (A→Z)')) ?></option>
    <option value="seo" <?= $order === 'seo' ? 'selected' : '' ?>><?= e(__('Score SEO le plus faible')) ?></option>
    <option value="oldest_update" <?= $order === 'oldest_update' ? 'selected' : '' ?>><?= e(__('Les moins récemment mis à jour')) ?></option>
  </select>
  <button class="btn" type="submit"><?= e(__('Filtrer')) ?></button>
</form>

<?php if (!$items): ?>
  <div class="panel"><div class="empty">
    <h2><?= e($q !== '' ? __('Aucun résultat') : ($type === 'page' ? __('Aucune page ici') : __('Aucun article ici'))) ?></h2>
    <p><?= e($type === 'page' ? __('Une page présente votre activité : accueil, services, à propos, contact.') : __('Un article régulier, utile et précis, est le meilleur moyen d’être trouvé sur Google et cité par les IA.')) ?></p>
    <div class="actions-row"><a class="btn btn-primary" href="<?= e(admin_url('content/new?type=' . $type)) ?>"><?= icon('plus') ?><?= e($type === 'page' ? __('Créer une page') : __('Écrire un article')) ?></a></div>
  </div></div>
<?php else: ?>
<form class="panel" method="post" action="<?= e(admin_url('content/bulk')) ?>">
  <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
  <div class="table-wrap"><table class="table">
    <thead><tr>
      <th style="width:2rem"><input type="checkbox" data-check-all aria-label="<?= e(__('Tout sélectionner')) ?>"></th>
      <th><?= e(__('Titre')) ?></th>
      <?php if ($type === 'post'): ?><th><?= e(__('Auteur')) ?></th><?php endif ?>
      <th><abbr title="<?= e(__('Score de référencement Google')) ?>">SEO</abbr></th>
      <th><abbr title="<?= e(__('Score de visibilité dans les moteurs de réponse IA')) ?>">GEO</abbr></th>
      <th class="num"><?= e(__('Mots')) ?></th>
      <th><?= e(__('Modifié')) ?></th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $c): ?>
      <tr>
        <td><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" aria-label="<?= e(__('Sélectionner {t}', ['t' => $c['title']])) ?>"></td>
        <td class="title-cell">
          <?= str_repeat('<span class="muted" aria-hidden="true">— </span>', (int) ($c['depth'] ?? 0)) ?><a href="<?= e(admin_url('content/' . $c['id'])) ?>"><?= e($c['title'] !== '' ? $c['title'] : __('(sans titre)')) ?></a>
          <?php if ($c['status'] !== 'published'): ?> <span class="badge<?= $c['status'] === 'scheduled' ? ' badge--accent' : '' ?>"><?= e(Content::statusLabel($c['status'])) ?><?= $c['status'] === 'scheduled' ? ' · ' . e(fmt_date((int) $c['published_at'], 'datetime')) : '' ?></span><?php endif ?>
          <?php if (Content::isHome($c)): ?> <span class="badge badge--accent"><?= e(__('Accueil')) ?></span><?php endif ?>
          <?php if ((int) $c['sticky']): ?> <span class="badge"><?= e(__('Épinglé')) ?></span><?php endif ?>
          <div class="row-actions">
            <a href="<?= e(admin_url('content/' . $c['id'])) ?>"><?= e(__('Modifier')) ?></a>
            <?php if ($c['status'] === 'published'): ?><a href="<?= e(Content::href($c)) ?>" target="_blank" rel="noopener"><?= e(__('Voir')) ?></a><?php else: ?><a href="<?= e(admin_url('content/' . $c['id'] . '/preview')) ?>" target="_blank" rel="noopener"><?= e(__('Aperçu')) ?></a><?php endif ?>
            <?php if ($c['status'] === 'trash'): ?>
              <button type="submit" name="restore_one" value="<?= (int) $c['id'] ?>"><?= e(__('Restaurer')) ?></button>
            <?php endif ?>
          </div>
        </td>
        <?php if ($type === 'post'): ?><td class="muted small"><?= e($authors[(int) $c['author_id']] ?? '—') ?></td><?php endif ?>
        <td><?= $score($c['seo_score'] !== null ? (int) $c['seo_score'] : null) ?></td>
        <td><?= $score($c['geo_score'] !== null ? (int) $c['geo_score'] : null) ?></td>
        <td class="num muted"><?= I18n::number((int) $c['word_count']) ?></td>
        <td class="muted small nowrap"><?= e(human_time((int) $c['updated_at'])) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table></div>
  <div class="panel-foot row-between">
    <div class="row">
      <label class="sr" for="bulk"><?= e(__('Action groupée')) ?></label>
      <select id="bulk" name="action" style="width:auto">
        <option value=""><?= e(__('Action sur la sélection…')) ?></option>
        <?php if ($status === 'trash'): ?>
          <option value="restore"><?= e(__('Restaurer')) ?></option>
          <?php if (Auth::can('delete_content')): ?><option value="delete"><?= e(__('Supprimer définitivement')) ?></option><?php endif ?>
        <?php else: ?>
          <?php if (Auth::can('publish')): ?><option value="publish"><?= e(__('Publier')) ?></option><?php endif ?>
          <option value="draft"><?= e(__('Repasser en brouillon')) ?></option>
          <option value="duplicate"><?= e(__('Dupliquer')) ?></option>
          <option value="trash"><?= e(__('Mettre à la corbeille')) ?></option>
        <?php endif ?>
      </select>
      <button class="btn" type="submit" data-confirm="<?= e(__('Appliquer cette action aux éléments sélectionnés ?')) ?>"><?= e(__('Appliquer')) ?></button>
    </div>
    <span class="muted small"><?= e(_n('{n} élément', '{n} éléments', $total)) ?></span>
  </div>
</form>
<?php if ($pages > 1): ?>
  <nav class="actions-row" style="margin-top:1rem" aria-label="<?= e(__('Pagination')) ?>">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
      <a class="btn btn-sm<?= $i === $page ? ' btn-primary' : '' ?>" href="<?= e($back . '&p=' . $i) ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
    <?php endfor ?>
  </nav>
<?php endif ?>
<?php endif ?>
