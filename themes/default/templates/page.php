<?php
/** Page. @var array $content @var array $crumbs */
use Wordless\Blocks;
use Wordless\Content;
use Wordless\Theme;

$c = $content;
$heroFirst = ($c['blocks'][0]['type'] ?? '') === 'hero';
?>
<article class="entry entry--page">
  <?php if (!$heroFirst): ?>
    <header class="entry-header">
      <?php if (!Content::isHome($c)): ?><?= Theme::partial('breadcrumbs', ['crumbs' => $crumbs]) ?><?php endif ?>
      <h1><?= e($c['title']) ?></h1>
    </header>
  <?php endif ?>
  <div class="entry-content">
    <?= Blocks::render($c['blocks'], ['content' => $c, 'heading_level' => $heroFirst ? 1 : 2]) ?>
  </div>
</article>
<?php if ((int) $c['comments'] === 1): ?><?= Theme::partial('comments', ['content' => $c]) ?><?php endif ?>
