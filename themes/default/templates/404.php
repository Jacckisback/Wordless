<?php
/** Page introuvable. @var ?array $suggestion */
use Wordless\Config;
use Wordless\Content;
?>
<header class="archive-header">
  <h1><?= e(__('Cette page n’existe pas (ou plus)')) ?></h1>
  <p class="wl-lead"><?= e(__('L’adresse a peut-être changé. Essayez la recherche, ou repartez de l’accueil.')) ?></p>
  <?php if (!empty($suggestion)): ?>
    <p class="wl-notice"><?= e(__('Vous cherchiez peut-être :')) ?> <a href="<?= e(Content::href($suggestion)) ?>"><?= e($suggestion['title']) ?></a></p>
  <?php endif ?>
  <form class="search-form" role="search" action="<?= e(Config::basePath() . '/search') ?>" method="get">
    <label class="wl-sr" for="q404"><?= e(__('Rechercher')) ?></label>
    <input id="q404" name="q" type="search" placeholder="<?= e(__('Votre recherche…')) ?>">
    <button class="wl-btn wl-btn--primary" type="submit"><?= e(__('Rechercher')) ?></button>
  </form>
  <p class="back-home"><a href="<?= e(href('/')) ?>"><?= e(__('← Retour à l’accueil')) ?></a></p>
</header>
