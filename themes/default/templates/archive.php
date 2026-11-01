<?php
/** Archive (catégorie, étiquette, auteur). @var array $posts @var string $heading @var string $kicker @var string $intro @var array $pagination @var array $crumbs */
use Wordless\Theme;
?>
<header class="archive-header">
  <?= Theme::partial('breadcrumbs', ['crumbs' => $crumbs]) ?>
  <h1><?= e($heading) ?></h1>
  <?php if (trim((string) $intro) !== ''): ?><div class="wl-lead"><?= nl2br(e(plain_text((string) $intro)), false) ?></div><?php endif ?>
</header>
<?php if ($posts): ?>
  <div class="post-list">
    <?php foreach ($posts as $post): ?><?= Theme::partial('post-item', ['post' => $post, 'lead' => false]) ?><?php endforeach ?>
  </div>
<?php else: ?>
  <div class="wl-container"><p class="wl-muted"><?= e(__('Aucun article ici pour le moment.')) ?></p></div>
<?php endif ?>
<?= Theme::partial('pagination', ['pagination' => $pagination]) ?>
