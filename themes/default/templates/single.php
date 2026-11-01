<?php
/** Article. @var array $content @var ?array $author @var array $categories @var array $tags @var array $crumbs @var ?array $featured @var int $reading_minutes */
use Wordless\Blocks;
use Wordless\Content;
use Wordless\Media;
use Wordless\Terms;
use Wordless\Theme;

$c = $content;
$updated = (int) $c['updated_at'] > (int) $c['published_at'] + 86400;
$avatar = $author ? Media::find($author['avatar_id'] ? (int) $author['avatar_id'] : null) : null;
$related = $categories ? Content::query(['type' => 'post', 'visible' => true, 'term' => (int) $categories[0]['id'], 'limit' => 3, 'exclude' => [(int) $c['id']]]) : [];
?>
<article class="entry entry--post">
  <header class="entry-header">
    <?= Theme::partial('breadcrumbs', ['crumbs' => $crumbs]) ?>
    <h1><?= e($c['title']) ?></h1>
    <p class="entry-meta">
      <?php if ($author): ?><span><?= e(__('Par')) ?> <a href="<?= e(href('/author/' . $author['slug'])) ?>" rel="author"><?= e($author['name']) ?></a></span><?php endif ?>
      <time datetime="<?= e(gmdate('c', (int) $c['published_at'])) ?>"><?= e(fmt_date((int) $c['published_at'])) ?></time>
      <?php if ($updated): ?><span><?= e(__('Mis à jour le {d}', ['d' => fmt_date((int) $c['updated_at'])])) ?></span><?php endif ?>
      <span><?= e(_n('{n} min de lecture', '{n} min de lecture', $reading_minutes)) ?></span>
      <?php foreach ($categories as $cat): ?><a href="<?= e(Terms::href($cat)) ?>"><?= e($cat['name']) ?></a><?php endforeach ?>
    </p>
  </header>
  <?php if ($featured): ?>
    <figure class="entry-featured"><?= Media::picture($featured, ['sizes' => '(min-width: 72rem) 72rem, 100vw', 'priority' => true]) ?></figure>
  <?php endif ?>
  <div class="entry-content">
    <?= Blocks::render($c['blocks'], ['content' => $c, 'heading_level' => 2]) ?>
  </div>
  <footer class="entry-footer">
    <?php if ($tags): ?>
      <ul class="tags" aria-label="<?= e(__('Étiquettes')) ?>"><?php foreach ($tags as $tag): ?><li><a href="<?= e(Terms::href($tag)) ?>" rel="tag"><?= e($tag['name']) ?></a></li><?php endforeach ?></ul>
    <?php endif ?>
    <?php if ($author && trim((string) $author['bio']) !== ''): ?>
      <aside class="author-box" aria-label="<?= e(__('À propos de l’auteur')) ?>">
        <?php if ($avatar): ?><?= Media::picture($avatar, ['sizes' => '56px', 'class' => 'wl-avatar', 'alt' => '']) ?><?php endif ?>
        <div><strong><a href="<?= e(href('/author/' . $author['slug'])) ?>"><?= e($author['name']) ?></a></strong><p><?= e($author['bio']) ?></p></div>
      </aside>
    <?php endif ?>
  </footer>
</article>
<?php if ($related): ?>
<section class="related" aria-labelledby="related-title">
  <h2 id="related-title"><?= e(__('À lire aussi')) ?></h2>
  <ul class="wl-posts__list">
    <?php foreach ($related as $r): $m = Media::find($r['featured_id'] ? (int) $r['featured_id'] : null); ?>
      <li><?php if ($m): ?><a href="<?= e(Content::href($r)) ?>" tabindex="-1" aria-hidden="true"><?= Media::picture($m, ['sizes' => '(min-width: 64rem) 22rem, 100vw', 'alt' => '']) ?></a><?php endif ?>
        <h3><a href="<?= e(Content::href($r)) ?>"><?= e($r['title']) ?></a></h3>
        <p class="wl-muted wl-small"><?= e(fmt_date((int) $r['published_at'])) ?></p></li>
    <?php endforeach ?>
  </ul>
</section>
<?php endif ?>
<?= Theme::partial('comments', ['content' => $c]) ?>
