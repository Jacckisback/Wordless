<?php /** @var int $count @var int $missingAlt @var int $usage @var string $maxUpload @var bool $filterAlt */ ?>
<div class="split">
  <section id="media-library" data-missing-alt="<?= $filterAlt ? '1' : '' ?>" data-type="<?= $filterAlt ? 'image' : '' ?>">
    <div class="dropzone" id="media-drop"><?= icon('upload') ?> <?= e(__('Glissez-déposez vos fichiers n’importe où sur cette page. Les images sont automatiquement redimensionnées, converties en WebP et débarrassées de leurs données GPS.')) ?> <span class="muted small"><?= e(__('Taille max. : {m}', ['m' => $maxUpload])) ?></span></div>
    <div class="toolbar" style="margin-top:1rem">
      <div class="tabs" role="tablist" style="margin:0;border:0">
        <button type="button" role="tab" data-filter="" aria-selected="<?= $filterAlt ? 'false' : 'true' ?>"><?= e(__('Tout')) ?></button>
        <button type="button" role="tab" data-filter="image" aria-selected="false"><?= e(__('Images')) ?></button>
        <button type="button" role="tab" data-filter="file" aria-selected="false"><?= e(__('Documents')) ?></button>
        <button type="button" role="tab" data-filter="alt" aria-selected="<?= $filterAlt ? 'true' : 'false' ?>"><?= e(__('Sans texte alternatif')) ?> <span class="n"><?= (int) $missingAlt ?></span></button>
      </div>
      <input type="search" placeholder="<?= e(__('Rechercher…')) ?>" aria-label="<?= e(__('Rechercher un média')) ?>" class="grow">
    </div>
    <div class="media-grid"></div>
    <p style="margin-top:1rem"><button class="btn" type="button" id="media-more" hidden><?= e(__('Afficher plus')) ?></button></p>
  </section>
  <aside class="panel" id="media-details" hidden>
    <div class="panel-body stack">
      <div data-preview></div>
      <p class="muted small" data-meta></p>
      <div class="code-box"><code data-url></code><button class="btn btn-sm" type="button" data-copy-url data-copy=""><?= icon('copy') ?><span class="sr"><?= e(__('Copier l’adresse')) ?></span></button></div>
      <form class="form-stack">
        <div class="field"><label for="m-alt"><?= e(__('Texte alternatif')) ?></label><textarea id="m-alt" name="alt" rows="2" maxlength="250" placeholder="<?= e(__('Décrivez ce que montre l’image')) ?>"></textarea>
          <?php if (\Wordless\Ai\Client::configured()): ?><button class="btn btn-sm" type="button" data-ai-alt><?= icon('sparkles') ?><?= e(__('Décrire avec l’IA')) ?></button><?php endif ?></div>
        <div class="field"><label for="m-title"><?= e(__('Titre')) ?></label><input id="m-title" name="title" maxlength="200"></div>
        <div class="field"><label for="m-cap"><?= e(__('Légende')) ?></label><input id="m-cap" name="caption" maxlength="500"></div>
        <div class="actions-row"><button class="btn btn-primary" type="submit"><?= e(__('Enregistrer')) ?></button><button class="btn btn-danger" type="button" data-delete><?= icon('trash') ?><?= e(__('Supprimer')) ?></button></div>
      </form>
    </div>
  </aside>
</div>
<p class="muted small" style="margin-top:1.5rem"><?= e(_n('{n} fichier', '{n} fichiers', $count)) ?> · <?= e(__('{s} utilisés (variantes comprises)', ['s' => fmt_bytes($usage)])) ?></p>
