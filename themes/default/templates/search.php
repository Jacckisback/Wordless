<?php
/** Recherche. @var string $query @var array $results @var int $total @var int $page @var int $pages */
use Wordless\Config;
use Wordless\Content;
?>
<header class="archive-header">
  <h1><?= e($query !== '' ? __('Résultats pour « {q} »', ['q' => $query]) : __('Rechercher sur le site')) ?></h1>
  <form class="search-form" role="search" action="<?= e(Config::basePath() . '/search') ?>" method="get">
    <label class="wl-sr" for="q"><?= e(__('Rechercher')) ?></label>
    <input id="q" name="q" type="search" value="<?= e($query) ?>" placeholder="<?= e(__('Votre recherche…')) ?>">
    <button class="wl-btn wl-btn--primary" type="submit"><?= e(__('Rechercher')) ?></button>
  </form>
  <?php if ($query !== ''): ?><p class="wl-muted search-count"><?= e(_n('{n} résultat', '{n} résultats', $total)) ?></p><?php endif ?>
</header>
<?php if ($results): ?>
<ol class="search-results">
  <?php foreach ($results as $r): ?>
    <li><h2><a href="<?= e(Content::href($r)) ?>"><?= e($r['title']) ?></a></h2><p><?= $r['snippet'] ?></p></li>
  <?php endforeach ?>
</ol>
<?php endif ?>
<?php if ($pages > 1): ?>
<nav class="pagination" aria-label="<?= e(__('Pagination')) ?>">
  <?php for ($i = 1; $i <= min($pages, 10); $i++): ?>
    <?php if ($i === $page): ?><span aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= e(Config::basePath() . '/search?q=' . rawurlencode($query) . '&page=' . $i) ?>"><?= $i ?></a><?php endif ?>
  <?php endfor ?>
</nav>
<?php endif ?>
