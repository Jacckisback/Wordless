<?php
/** Liste d'articles (accueil ou page « Blog »). @var array $posts @var string $heading @var array $pagination @var bool $is_home */
use Wordless\Settings;
use Wordless\Theme;
?>
<header class="archive-header">
  <h1><?= e($heading) ?></h1>
  <?php if (!empty($is_home) && Settings::get('site.tagline')): ?><p class="wl-lead"><?= e((string) Settings::get('site.tagline')) ?></p><?php endif ?>
</header>
<?php if ($posts): ?>
  <div class="post-list">
    <?php foreach ($posts as $i => $post): ?><?= Theme::partial('post-item', ['post' => $post, 'lead' => $i === 0 && $pagination['current'] === 1]) ?><?php endforeach ?>
  </div>
<?php else: ?>
  <div class="wl-container"><p class="wl-muted"><?= e(__('Aucun article publié pour le moment.')) ?></p></div>
<?php endif ?>
<?= Theme::partial('pagination', ['pagination' => $pagination]) ?>
