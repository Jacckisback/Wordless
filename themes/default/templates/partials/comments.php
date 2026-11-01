<?php
/** @var array $content */
use Wordless\Comments;
use Wordless\Config;
use Wordless\Crypto;

$tree = Comments::tree((int) $content['id']);
$count = Comments::count((int) $content['id']);
$open = (int) $content['comments'] === 1 && Comments::enabled();
if (!$open && $count === 0) {
    return;
}
$render = static function (array $nodes) use (&$render): string {
    $out = '';
    foreach ($nodes as $c) {
        $out .= '<li id="comment-' . (int) $c['id'] . '"><article class="comment"><p class="comment-meta"><strong>' . e($c['author']) . '</strong> · <time datetime="' . e(gmdate('c', (int) $c['created_at'])) . '">' . e(fmt_date((int) $c['created_at'], 'datetime')) . '</time></p>'
            . '<div class="comment-body">' . $c['body'] . '</div></article>';
        if (!empty($c['children'])) {
            $out .= '<ol>' . $render($c['children']) . '</ol>';
        }
        $out .= '</li>';
    }
    return $out;
};
$ts = (string) time();
$state = (string) ($_GET['comment'] ?? '');
?>
<section class="comments" id="comments" aria-labelledby="comments-title">
  <h2 id="comments-title"><?= e($count > 0 ? _n('{n} commentaire', '{n} commentaires', $count) : __('Commentaires')) ?></h2>
  <?php if ($tree): ?><ol class="comment-list"><?= $render($tree) ?></ol><?php endif ?>
  <?php if ($state === 'pending'): ?><p class="wl-notice" role="status"><?= e(__('Merci ! Votre commentaire sera publié après validation.')) ?></p><?php endif ?>
  <?php if ($state === 'error'): ?><p class="wl-notice wl-notice--error" role="alert"><?= e(__('Votre commentaire n’a pas pu être envoyé. Vérifiez les champs.')) ?></p><?php endif ?>
  <?php if ($open): ?>
  <form class="comment-form" method="post" action="<?= e(Config::basePath() . '/wl/comment') ?>">
    <input type="hidden" name="content_id" value="<?= (int) $content['id'] ?>">
    <input type="hidden" name="ts" value="<?= e($ts) ?>"><input type="hidden" name="sig" value="<?= e(Crypto::sign($ts . '|c' . (int) $content['id'], 'comment')) ?>">
    <p class="wl-hp" aria-hidden="true"><label>Website<input name="website" tabindex="-1" autocomplete="off"></label></p>
    <p><label for="c-body"><?= e(__('Votre commentaire')) ?></label><textarea id="c-body" name="body" rows="5" required maxlength="5000"></textarea></p>
    <p><label for="c-name"><?= e(__('Nom')) ?></label><input id="c-name" name="author" required maxlength="80" autocomplete="name"></p>
    <p><label for="c-email"><?= e(__('E-mail (non publié)')) ?></label><input id="c-email" name="email" type="email" required maxlength="120" autocomplete="email"></p>
    <p><button class="wl-btn wl-btn--primary" type="submit"><?= e(__('Publier le commentaire')) ?></button></p>
  </form>
  <?php endif ?>
</section>
