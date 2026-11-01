<?php /** @var array $pagination */ if ($pagination['total'] < 2) { return; }
$cur = $pagination['current']; $total = $pagination['total']; $u = $pagination['url']; ?>
<nav class="pagination" aria-label="<?= e(__('Pagination')) ?>">
  <?php if ($cur > 1): ?><a href="<?= e($u($cur - 1)) ?>" rel="prev"><?= e(__('← Précédent')) ?></a><?php endif ?>
  <?php for ($i = max(1, $cur - 2); $i <= min($total, $cur + 2); $i++): ?>
    <?php if ($i === $cur): ?><span aria-current="page"><?= $i ?></span><?php else: ?><a href="<?= e($u($i)) ?>"><?= $i ?></a><?php endif ?>
  <?php endfor ?>
  <?php if ($cur < $total): ?><a href="<?= e($u($cur + 1)) ?>" rel="next"><?= e(__('Suivant →')) ?></a><?php endif ?>
</nav>
