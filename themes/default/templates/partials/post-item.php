<?php
/** @var array $post @var bool $lead */
use Wordless\Content;
use Wordless\Media;

$thumb = Media::find($post['featured_id'] ? (int) $post['featured_id'] : null);
$href = Content::href($post);
?>
<article class="post-item<?= $thumb ? ' has-thumb' : '' ?><?= !empty($lead) && $thumb ? ' is-lead' : '' ?>">
  <?php if ($thumb): ?><a href="<?= e($href) ?>" tabindex="-1" aria-hidden="true"><?= Media::picture($thumb, ['sizes' => !empty($lead) ? '(min-width: 64rem) 40rem, 100vw' : '(min-width: 44rem) 18rem, 100vw', 'alt' => '', 'priority' => !empty($lead)]) ?></a><?php endif ?>
  <div>
    <h2><a href="<?= e($href) ?>"><?= e($post['title']) ?></a></h2>
    <p class="entry-meta"><time datetime="<?= e(gmdate('c', (int) $post['published_at'])) ?>"><?= e(fmt_date((int) $post['published_at'])) ?></time><span><?= e(_n('{n} min de lecture', '{n} min de lecture', max(1, (int) round((int) $post['word_count'] / 220)))) ?></span></p>
    <p class="post-excerpt"><?= e(Content::summary($post, 190)) ?></p>
  </div>
</article>
