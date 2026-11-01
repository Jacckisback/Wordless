<?php
/** @var array $tokens @var array $presets @var array $fonts @var array $findings @var bool $ai @var array $pages @var int $homeId */
$labels = ['bg' => __('Fond'), 'surface' => __('Sections teintées'), 'text' => __('Texte'), 'muted' => __('Texte secondaire'), 'accent' => __('Couleur principale'), 'accent_text' => __('Texte sur couleur principale'), 'line' => __('Filets et bordures')];
?>
<script type="application/json" id="design-data"><?= json_encode(['tokens' => $tokens, 'presets' => $presets, 'findings' => $findings, 'home' => $homeId], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<div class="split">
  <div class="stack">
    <section class="panel" aria-labelledby="p-title">
      <div class="panel-head"><div><h2 id="p-title"><?= e(__('Directions artistiques')) ?></h2><p class="muted small"><?= e(__('Six points de départ conçus pour éviter l’allure « site généré ». Tout reste ajustable.')) ?></p></div></div>
      <div class="panel-body"><div class="preset-grid">
        <?php foreach ($presets as $key => $p): $c = $p['colors']; ?>
          <button type="button" class="preset" data-preset="<?= e($key) ?>" aria-pressed="<?= ($tokens['preset'] ?? '') === $key ? 'true' : 'false' ?>">
            <span class="preset-sample" style="background:<?= e($c['bg']) ?>;color:<?= e($c['text']) ?>;font-family:<?= e($fonts[$p['fonts']['body']]['stack']) ?>">
              <b style="font-family:<?= e($fonts[$p['fonts']['heading']]['stack']) ?>;font-weight:<?= (int) $p['heading_weight'] ?>;letter-spacing:<?= e((string) $p['tracking']) ?>em"><?= e(__('Votre titre ici')) ?></b>
              <i style="color:<?= e($c['muted']) ?>"><?= e(__('Un texte de présentation lisible.')) ?></i>
              <span style="background:<?= e($c['accent']) ?>;color:<?= e($c['accent_text']) ?>;border-radius:<?= (int) $p['radius'] ?>px"><?= e(__('Bouton')) ?></span>
            </span>
            <span class="preset-meta"><strong><?= e($p['label']) ?></strong><span class="muted"><?= e($p['description']) ?></span></span>
          </button>
        <?php endforeach ?>
      </div></div>
    </section>

    <section class="panel" aria-labelledby="t-title">
      <div class="panel-head"><h2 id="t-title"><?= e(__('Réglages fins')) ?></h2></div>
      <div class="panel-body form-stack" id="design-form">
        <fieldset><legend><?= e(__('Couleurs')) ?></legend>
          <div class="field-row">
            <?php foreach ($labels as $k => $l): ?>
              <div class="field"><label for="c-<?= e($k) ?>"><?= e($l) ?></label>
                <div class="row"><input type="color" id="c-<?= e($k) ?>-picker" data-color="<?= e($k) ?>" aria-label="<?= e($l) ?>"><input id="c-<?= e($k) ?>" data-hex="<?= e($k) ?>" maxlength="7" class="mono" style="width:6.5rem"><span class="counter" data-contrast="<?= e($k) ?>"></span></div>
              </div>
            <?php endforeach ?>
          </div>
        </fieldset>
        <fieldset><legend><?= e(__('Typographie')) ?></legend>
          <div class="field-row">
            <div class="field"><label for="f-heading"><?= e(__('Police des titres')) ?></label><select id="f-heading" data-font="heading"></select></div>
            <div class="field"><label for="f-body"><?= e(__('Police du texte')) ?></label><select id="f-body" data-font="body"></select></div>
          </div>
          <details style="margin-top:.75rem"><summary class="small"><?= e(__('Ajouter une police Google Fonts (hébergée chez vous, conforme RGPD)')) ?></summary>
            <div class="row" style="margin-top:.6rem"><input id="gf-family" placeholder="<?= e(__('Nom exact, ex. Lora, Fraunces, Outfit')) ?>" style="max-width:16rem">
              <select id="gf-fallback" style="max-width:12rem"><?php foreach ($fonts as $k => $f): ?><option value="<?= e($k) ?>"><?= e(__('Secours : {l}', ['l' => $f['label']])) ?></option><?php endforeach ?></select>
              <button class="btn" type="button" id="gf-install"><?= e(__('Installer')) ?></button></div>
          </details>
          <div class="field-row" style="margin-top:1rem">
            <div class="field"><label for="r-base"><?= e(__('Taille du texte')) ?> <span class="counter" data-out="base"></span></label><input type="range" id="r-base" data-num="base" min="15" max="22" step="1"></div>
            <div class="field"><label for="r-ratio"><?= e(__('Contraste des tailles')) ?> <span class="counter" data-out="ratio"></span></label><input type="range" id="r-ratio" data-num="ratio" min="1.15" max="1.5" step="0.01"></div>
            <div class="field"><label for="r-measure"><?= e(__('Longueur de ligne')) ?> <span class="counter" data-out="measure"></span></label><input type="range" id="r-measure" data-num="measure" min="55" max="80" step="1"></div>
            <div class="field"><label for="r-hw"><?= e(__('Graisse des titres')) ?> <span class="counter" data-out="heading_weight"></span></label><input type="range" id="r-hw" data-num="heading_weight" min="400" max="900" step="100"></div>
            <div class="field"><label for="r-tr"><?= e(__('Interlettrage des titres')) ?> <span class="counter" data-out="tracking"></span></label><input type="range" id="r-tr" data-num="tracking" min="-0.04" max="0.04" step="0.005"></div>
          </div>
        </fieldset>
        <fieldset><legend><?= e(__('Formes et rythme')) ?></legend>
          <div class="field-row">
            <div class="field"><label for="r-radius"><?= e(__('Arrondi des angles')) ?> <span class="counter" data-out="radius"></span></label><input type="range" id="r-radius" data-num="radius" min="0" max="28" step="1"></div>
            <div class="field"><label for="r-width"><?= e(__('Largeur maximale')) ?> <span class="counter" data-out="width"></span></label><input type="range" id="r-width" data-num="width" min="56" max="90" step="1"></div>
            <div class="field"><label for="s-density"><?= e(__('Espacement')) ?></label><select id="s-density" data-sel="density"><option value="compact"><?= e(__('Serré')) ?></option><option value="normal"><?= e(__('Normal')) ?></option><option value="airy"><?= e(__('Aéré')) ?></option></select></div>
            <div class="field"><label for="s-scheme"><?= e(__('Ambiance')) ?></label><select id="s-scheme" data-sel="scheme"><option value="light"><?= e(__('Claire')) ?></option><option value="dark"><?= e(__('Sombre')) ?></option></select></div>
            <div class="field"><label for="s-header"><?= e(__('En-tête')) ?></label><select id="s-header" data-sel="header"><option value="split"><?= e(__('Logo à gauche, menu à droite')) ?></option><option value="center"><?= e(__('Centré')) ?></option></select></div>
          </div>
        </fieldset>
        <?php if (\Wordless\Auth::can('*')): ?>
        <details><summary class="small"><?= e(__('CSS personnalisé (avancé)')) ?></summary><textarea id="custom-css" class="mono" rows="6" style="margin-top:.5rem" placeholder=".site-header { … }"></textarea></details>
        <?php endif ?>
      </div>
    </section>

    <section class="panel" aria-labelledby="pv-title">
      <div class="panel-head"><h2 id="pv-title"><?= e(__('Aperçu en direct')) ?></h2>
        <div class="row"><label class="sr" for="pv-path"><?= e(__('Page')) ?></label><select id="pv-path" style="width:auto"><option value="/"><?= e(__('Accueil')) ?></option><?php foreach ($pages as $p): if ($p['id'] === $homeId) { continue; } ?><option value="<?= e($p['path']) ?>"><?= e($p['title']) ?></option><?php endforeach ?></select>
          <div class="seg" role="group"><button type="button" data-pv="desktop" aria-pressed="true"><?= icon('desktop') ?></button><button type="button" data-pv="mobile" aria-pressed="false"><?= icon('mobile') ?></button></div></div></div>
      <div class="panel-body"><iframe class="frame-preview" id="design-preview" title="<?= e(__('Aperçu du site')) ?>"></iframe></div>
    </section>
  </div>

  <aside class="stack">
    <section class="panel" aria-labelledby="da-title">
      <div class="panel-head"><div><h2 id="da-title"><?= icon('palette') ?> <?= e(__('Directeur artistique')) ?></h2><p class="muted small"><?= e(__('Règles inspirées d’Impeccable : lisibilité, hiérarchie, et chasse aux tics des sites générés par IA.')) ?></p></div></div>
      <ul class="findings" id="da-findings"></ul>
      <div class="panel-foot">
        <div class="actions-row">
          <button class="btn btn-sm" type="button" data-cmd="bolder"><?= e(__('Plus affirmé')) ?></button>
          <button class="btn btn-sm" type="button" data-cmd="quieter"><?= e(__('Plus sobre')) ?></button>
          <button class="btn btn-sm" type="button" data-cmd="distill"><?= e(__('Épurer')) ?></button>
          <button class="btn btn-sm" type="button" data-cmd="repair"><?= e(__('Corriger les contrastes')) ?></button>
        </div>
      </div>
    </section>
    <?php if ($ai): ?>
    <section class="panel" aria-labelledby="ai-da-title">
      <div class="panel-head"><h2 id="ai-da-title"><?= icon('sparkles') ?> <?= e(__('Créer une identité avec l’IA')) ?></h2></div>
      <div class="panel-body form-stack">
        <div class="field"><label for="da-brief"><?= e(__('Votre brief')) ?></label><textarea id="da-brief" rows="5" placeholder="<?= e(__('Activité, clientèle, ville, valeurs, ce que vous aimez et détestez visuellement, couleurs imposées… Ex. « Atelier de céramique à Lyon, clientèle adulte, matières brutes, terre cuite, lumière du matin, pas de pastel. »')) ?>"></textarea></div>
        <div class="row"><button class="btn btn-primary" type="button" id="da-direction"><?= e(__('Proposer une direction')) ?></button></div>
        <div id="da-result" class="ai-result" hidden></div>
      </div>
      <div class="panel-foot form-stack">
        <div class="row"><label class="sr" for="da-page"><?= e(__('Page à critiquer')) ?></label><select id="da-page"><option value="<?= (int) $homeId ?>"><?= e(__('Accueil')) ?></option><?php foreach ($pages as $p): if ($p['id'] === $homeId) { continue; } ?><option value="<?= (int) $p['id'] ?>"><?= e($p['title']) ?></option><?php endforeach ?></select>
          <button class="btn" type="button" id="da-critique"><?= e(__('Critiquer la page')) ?></button></div>
        <div id="da-report" hidden></div>
      </div>
    </section>
    <?php else: ?>
    <div class="notice"><?= icon('info') ?><p><?= e(__('Ajoutez une clé Claude ou OpenAI dans « Assistant IA » pour que le directeur artistique crée une identité complète et critique vos pages.')) ?></p></div>
    <?php endif ?>
  </aside>
</div>
