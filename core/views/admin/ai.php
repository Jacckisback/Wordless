<?php
/** @var string $provider @var string $anthropicKey @var string $openaiKey @var string $anthropicModel @var string $openaiModel @var string $voice @var array $usage @var bool $configured @var array $categories @var bool $canSettings */
use Wordless\Ai\Client;
use Wordless\I18n;
?>
<div class="split">
  <div class="stack">
    <?php if ($configured): ?>
    <section class="panel" aria-labelledby="w-title">
      <div class="panel-head"><div><h2 id="w-title"><?= e(__('Rédiger un contenu avec l’IA')) ?></h2><p class="muted small"><?= e(__('Un brouillon complet, structuré pour Google et les moteurs IA : réponse directe, intertitres en questions, « L’essentiel », FAQ balisée. Vous relisez avant de publier.')) ?></p></div></div>
      <form class="panel-body form-stack" id="ai-article">
        <div class="field"><label for="brief"><?= e(__('Sujet et consignes')) ?></label><textarea id="brief" name="brief" rows="5" required placeholder="<?= e(__('Ex. « Comment choisir un cours de céramique pour débutant à Lyon : prix, durée, matériel, erreurs à éviter. Public : adultes curieux. Ton chaleureux. »')) ?>"></textarea></div>
        <div class="field-row">
          <div class="field"><label for="kw"><?= e(__('Mot-clé principal')) ?></label><input id="kw" name="keyword" placeholder="<?= e(__('facultatif')) ?>"></div>
          <div class="field"><label for="type"><?= e(__('Type')) ?></label><select id="type" name="type"><option value="post"><?= e(__('Article')) ?></option><option value="page"><?= e(__('Page')) ?></option></select></div>
          <div class="field"><label for="cat"><?= e(__('Catégorie')) ?></label><select id="cat" name="category"><option value=""><?= e(__('Aucune')) ?></option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach ?></select></div>
        </div>
        <div class="row"><button class="btn btn-primary" type="submit"><?= icon('sparkles') ?><?= e(__('Rédiger le brouillon')) ?></button><span class="muted small" id="ai-progress" aria-live="polite"></span></div>
      </form>
    </section>
    <?php endif ?>

    <form class="panel" method="post" action="<?= e(admin_url('ai')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <div class="panel-head"><div><h2><?= e(__('Clés et modèles')) ?></h2><p class="muted small"><?= e(__('Vos clés sont chiffrées sur votre serveur. Le contenu n’est envoyé au fournisseur que lorsque vous lancez une action IA.')) ?></p></div></div>
      <div class="panel-body form-stack">
        <fieldset><legend><?= e(__('Fournisseur utilisé')) ?></legend>
          <div class="row">
            <label class="check"><input type="radio" name="provider" value="anthropic" <?= $provider === 'anthropic' ? 'checked' : '' ?> <?= $canSettings ? '' : 'disabled' ?>><span>Claude (Anthropic)</span></label>
            <label class="check"><input type="radio" name="provider" value="openai" <?= $provider === 'openai' ? 'checked' : '' ?> <?= $canSettings ? '' : 'disabled' ?>><span>ChatGPT (OpenAI)</span></label>
          </div>
        </fieldset>
        <div class="field">
          <label for="anthropic_key"><?= e(__('Clé API Claude')) ?></label>
          <div class="input-group"><input id="anthropic_key" name="anthropic_key" type="password" value="<?= e($anthropicKey) ?>" placeholder="sk-ant-…" <?= $canSettings ? '' : 'disabled' ?>><button class="btn" type="button" data-test-key="anthropic"><?= e(__('Tester')) ?></button></div>
          <p class="hint"><?= e(__('À créer sur console.anthropic.com > Settings > API keys.')) ?> <?php if ($anthropicKey !== ''): ?><label class="check" style="display:inline-flex"><input type="checkbox" name="remove_anthropic" value="1"> <span><?= e(__('supprimer')) ?></span></label><?php endif ?></p>
        </div>
        <div class="field"><label for="anthropic_model"><?= e(__('Modèle Claude')) ?></label>
          <select id="anthropic_model" name="anthropic_model" <?= $canSettings ? '' : 'disabled' ?>>
            <?php foreach (Client::ANTHROPIC_MODELS as $id => $label): ?><option value="<?= e($id) ?>" <?= $anthropicModel === $id ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
            <?php if (!isset(Client::ANTHROPIC_MODELS[$anthropicModel])): ?><option value="<?= e($anthropicModel) ?>" selected><?= e($anthropicModel) ?></option><?php endif ?>
          </select></div>
        <div class="field">
          <label for="openai_key"><?= e(__('Clé API OpenAI')) ?></label>
          <div class="input-group"><input id="openai_key" name="openai_key" type="password" value="<?= e($openaiKey) ?>" placeholder="sk-…" <?= $canSettings ? '' : 'disabled' ?>><button class="btn" type="button" data-test-key="openai"><?= e(__('Tester')) ?></button></div>
          <p class="hint"><?= e(__('À créer sur platform.openai.com > API keys.')) ?> <?php if ($openaiKey !== ''): ?><label class="check" style="display:inline-flex"><input type="checkbox" name="remove_openai" value="1"> <span><?= e(__('supprimer')) ?></span></label><?php endif ?></p>
        </div>
        <div class="field"><label for="openai_model"><?= e(__('Modèle OpenAI')) ?></label>
          <input id="openai_model" name="openai_model" value="<?= e($openaiModel) ?>" list="openai-models" placeholder="<?= e(__('Choisi automatiquement (le plus récent de votre compte)')) ?>" <?= $canSettings ? '' : 'disabled' ?>>
          <datalist id="openai-models"></datalist>
          <p class="hint"><?= e(__('« Tester » charge la liste des modèles disponibles avec votre clé.')) ?></p></div>
        <div class="field"><label for="brand_voice"><?= e(__('Voix de marque et public')) ?></label>
          <textarea id="brand_voice" name="brand_voice" rows="5" maxlength="3000" placeholder="<?= e(__('Qui sont vos clients ? Quel ton (tutoiement, humour, expertise) ? Quels mots éviter ? Vos chiffres et preuves vérifiés (années d’expérience, nombre de clients…) que l’IA peut citer.')) ?>" <?= $canSettings ? '' : 'disabled' ?>><?= e($voice) ?></textarea></div>
      </div>
      <?php if ($canSettings): ?><div class="panel-foot"><button class="btn btn-primary" type="submit"><?= e(__('Enregistrer')) ?></button></div><?php endif ?>
    </form>
  </div>
  <aside class="stack">
    <div class="panel"><div class="panel-body stack-s">
      <h2><?= e(__('Consommation (30 jours)')) ?></h2>
      <p><strong class="num"><?= I18n::number($usage['calls']) ?></strong> <?= e(__('actions IA')) ?> · <span class="num"><?= I18n::number($usage['tokens']) ?></span> <?= e(__('jetons')) ?></p>
      <?php if ($usage['cost'] > 0): ?><p class="muted small"><?= e(__('Coût estimé (Claude) : {c} $', ['c' => I18n::number($usage['cost'], 2)])) ?></p><?php endif ?>
      <p class="hint"><?= e(__('Facturé directement par votre fournisseur IA, sans intermédiaire.')) ?></p>
    </div></div>
    <div class="panel"><div class="panel-body stack-s">
      <h2><?= e(__('Ce que fait l’IA dans Wordless')) ?></h2>
      <ul class="checklist">
        <li><?= e(__('Rédaction d’articles et de pages structurés SEO/GEO')) ?></li>
        <li><?= e(__('Optimisation d’une page existante pour les moteurs IA')) ?></li>
        <li><?= e(__('FAQ, « L’essentiel », titre et méta-description')) ?></li>
        <li><?= e(__('Textes alternatifs des images (vision)')) ?></li>
        <li><?= e(__('Traduction avec balises hreflang')) ?></li>
        <li><?= e(__('Directeur artistique : identité visuelle et critique de page')) ?></li>
      </ul>
      <p class="hint"><?= e(__('L’IA n’invente pas de chiffres ni de sources : ce qui manque est signalé [à vérifier].')) ?></p>
    </div></div>
  </aside>
</div>
