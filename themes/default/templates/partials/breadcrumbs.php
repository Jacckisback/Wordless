<?php /** @var array $crumbs */ if (count($crumbs) < 2) { return; } ?>
<nav class="crumbs" aria-label="<?= e(__('Fil d’Ariane')) ?>"><ol>
<?php foreach ($crumbs as $i => [$name, $url]): ?>
  <li><?php if ($i < count($crumbs) - 1): ?><a href="<?= e($url) ?>"><?= e($name) ?></a><?php else: ?><span aria-current="page"><?= e(excerpt($name, 60)) ?></span><?php endif ?></li>
<?php endforeach ?>
</ol></nav>
