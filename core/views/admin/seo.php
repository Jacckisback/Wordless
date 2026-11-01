<?php
/** @var array $report @var array $weak @var array $notFound @var ?array $ogImage @var array $indexnow */
use Wordless\Config;
use Wordless\I18n;
use Wordless\Media;
use Wordless\Settings;

$S = static fn (string $k, mixed $d = null) => Settings::get($k, $d);
$checked = static fn (bool $b): string => $b ? 'checked' : '';
$botInfo = [
    'GPTBot' => __('OpenAI — entraînement des modèles'), 'OAI-SearchBot' => __('OpenAI — recherche ChatGPT (citations)'), 'ChatGPT-User' => __('ChatGPT — visite à la demande d’un utilisateur'),
    'ClaudeBot' => __('Anthropic — entraînement'), 'Claude-SearchBot' => __('Anthropic — recherche de Claude'), 'Claude-User' => __('Claude — visite à la demande'),
    'PerplexityBot' => __('Perplexity — index de recherche'), 'Perplexity-User' => __('Perplexity — visite à la demande'), 'Google-Extended' => __('Google — Gemini et IA (n’affecte pas la recherche Google)'),
    'Applebot-Extended' => __('Apple — Apple Intelligence'), 'Meta-ExternalAgent' => __('Meta — IA de Meta'), 'CCBot' => __('Common Crawl — corpus ouvert utilisé par de nombreuses IA'), 'Bytespider' => __('ByteDance — entraînement'),
];
?>
<div class="kpis panel" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:1.25rem">
  <div class="kpi"><div class="kpi-label"><?= e(__('Score SEO moyen')) ?></div><div class="kpi-value num"><?= (int) $report['avgSeo'] ?><span class="muted small">/100</span></div></div>
  <div class="kpi"><div class="kpi-label"><?= e(__('Score GEO moyen')) ?></div><div class="kpi-value num"><?= (int) $report['avgGeo'] ?><span class="muted small">/100</span></div></div>
  <div class="kpi"><div class="kpi-label"><?= e(__('Pages sans méta-description')) ?></div><div class="kpi-value num"><?= I18n::number($report['noDesc']) ?></div></div>
  <div class="kpi"><div class="kpi-label"><?= e(__('Pages avec FAQ')) ?></div><div class="kpi-value num"><?= I18n::number($report['faqPages']) ?></div></div>
</div>
<div class="split">
<form class="stack" method="post" action="<?= e(admin_url('seo')) ?>"><?= csrf_field() ?>
  <section class="panel"><div class="panel-head"><h2><?= e(__('Référencement (Google, Bing)')) ?></h2></div><div class="panel-body form-stack">
    <div class="field"><label for="home_title"><?= e(__('Titre de la page d’accueil')) ?></label><input id="home_title" name="home_title" maxlength="160" value="<?= e((string) $S('seo.home_title')) ?>" placeholder="<?= e((string) $S('site.name')) ?>"></div>
    <div class="field"><label for="home_description"><?= e(__('Description de la page d’accueil')) ?></label><textarea id="home_description" name="home_description" rows="3" maxlength="400" placeholder="<?= e(__('Ce que vous faites, pour qui, où. 120 à 160 caractères.')) ?>"><?= e((string) $S('seo.home_description')) ?></textarea></div>
    <div class="field-row">
      <div class="field"><label for="title_template"><?= e(__('Modèle des titres')) ?></label><input id="title_template" name="title_template" value="<?= e((string) $S('seo.title_template')) ?>" class="mono"><p class="hint">{title} {sep} {site}</p></div>
      <div class="field"><label for="separator"><?= e(__('Séparateur')) ?></label><input id="separator" name="separator" value="<?= e((string) $S('seo.separator')) ?>" maxlength="3" style="max-width:5rem"></div>
    </div>
    <div class="field"><span class="label"><?= e(__('Image de partage par défaut')) ?></span><div class="row" data-media-field><input type="hidden" name="og_image_id" value="<?= (int) ($ogImage['id'] ?? 0) ?: '' ?>"><div class="media-thumb" style="width:7rem;height:3.7rem;border-radius:6px" data-media-thumb><?= $ogImage ? Media::picture($ogImage, ['sizes' => '112px']) : '' ?></div><button class="btn btn-sm" type="button" data-media-pick><?= e(__('Choisir')) ?></button><button class="btn btn-sm btn-ghost" type="button" data-media-clear><?= e(__('Retirer')) ?></button></div><p class="hint"><?= e(__('1200 × 630 px, utilisée quand une page n’a pas d’image.')) ?></p></div>
    <fieldset><legend><?= e(__('Validation des outils pour webmasters')) ?></legend><div class="field-row">
      <?php foreach (['google-site-verification' => 'Google Search Console', 'msvalidate.01' => 'Bing Webmaster Tools', 'yandex-verification' => 'Yandex', 'facebook-domain-verification' => 'Meta'] as $k => $l): ?>
        <div class="field"><label for="v-<?= e($k) ?>"><?= e($l) ?></label><input id="v-<?= e($k) ?>" name="verif[<?= e($k) ?>]" value="<?= e((string) (($S('seo.verification', [])[$k] ?? ''))) ?>" placeholder="<?= e(__('code de la balise meta')) ?>"></div>
      <?php endforeach ?></div></fieldset>
    <label class="switch"><span class="switch-text"><span class="label"><?= e(__('IndexNow (Bing, Yandex, Seznam…)')) ?></span><span class="hint"><?= e(__('Prévient les moteurs à chaque publication.')) ?><?php if (!empty($indexnow['at'])): ?> <?= e(__('Dernier envoi : {t} ({n} adresses).', ['t' => human_time((int) $indexnow['at']), 'n' => (int) ($indexnow['count'] ?? 0)])) ?><?php endif ?></span></span><input type="checkbox" name="indexnow" value="1" <?= $checked((bool) $S('seo.indexnow', true)) ?>></label>
    <label class="switch"><span class="switch-text"><span class="label"><?= e(__('Masquer tout le site aux moteurs (noindex)')) ?></span><span class="hint"><?= e(__('Uniquement pendant la construction du site.')) ?></span></span><input type="checkbox" name="noindex_site" value="1" <?= $checked((bool) $S('seo.noindex_site', false)) ?>></label>
  </div></section>

  <section class="panel"><div class="panel-head"><div><h2><?= e(__('GEO : visibilité dans les moteurs de réponse IA')) ?></h2><p class="muted small"><?= e(__('ChatGPT, Claude, Perplexity, Gemini et les AI Overviews de Google citent les sites qu’ils peuvent lire et comprendre.')) ?></p></div></div><div class="panel-body form-stack">
    <label class="switch"><span class="switch-text"><span class="label"><?= e(__('Fichier llms.txt')) ?></span><span class="hint"><a href="<?= e(href('/llms.txt')) ?>" target="_blank" rel="noopener">/llms.txt</a> · <a href="<?= e(href('/llms-full.txt')) ?>" target="_blank" rel="noopener">/llms-full.txt</a> — <?= e(__('carte du site pour les modèles de langage')) ?></span></span><input type="checkbox" name="llms" value="1" <?= $checked((bool) $S('geo.llms_txt', true)) ?>></label>
    <label class="switch"><span class="switch-text"><span class="label"><?= e(__('Version Markdown de chaque page')) ?></span><span class="hint"><?= e(__('Ajoutez .md à une adresse : texte propre, sans mise en page, idéal pour les IA (non indexé par Google).')) ?></span></span><input type="checkbox" name="markdown" value="1" <?= $checked((bool) $S('geo.markdown', true)) ?>></label>
    <fieldset><legend><?= e(__('Robots d’IA autorisés')) ?></legend><div class="stack-s">
      <?php foreach ((array) $S('geo.ai_crawlers', []) as $bot => $on): ?>
        <label class="switch"><span class="switch-text"><span class="label mono small"><?= e($bot) ?></span><span class="hint"><?= e($botInfo[$bot] ?? '') ?></span></span><input type="checkbox" name="bot[<?= e($bot) ?>]" value="1" <?= $checked((bool) $on) ?>></label>
      <?php endforeach ?>
      <p class="hint"><?= e(__('Pour être cité, gardez autorisés au moins les robots de recherche (OAI-SearchBot, Claude-SearchBot, PerplexityBot). Bloquer l’entraînement (GPTBot, ClaudeBot, CCBot) est un choix éditorial.')) ?></p>
    </div></fieldset>
  </div></section>
  <div><button class="btn btn-primary" type="submit"><?= e(__('Enregistrer')) ?></button></div>
</form>

<aside class="stack">
  <section class="panel"><div class="panel-head"><h2><?= e(__('Pages à améliorer en priorité')) ?></h2></div>
    <ul class="feed"><?php foreach ($weak as $c): ?><li><a href="<?= e(admin_url('content/' . $c['id'])) ?>"><?= e($c['title']) ?></a><span class="score <?= (int) $c['seo_score'] >= 80 ? 'is-good' : ((int) $c['seo_score'] >= 50 ? 'is-warn' : 'is-bad') ?>" title="SEO"><?= (int) $c['seo_score'] ?></span><span class="score <?= (int) $c['geo_score'] >= 80 ? 'is-good' : ((int) $c['geo_score'] >= 50 ? 'is-warn' : 'is-bad') ?>" title="GEO"><?= (int) $c['geo_score'] ?></span></li><?php endforeach ?></ul></section>
  <section class="panel"><div class="panel-head"><h2><?= e(__('Pages introuvables (404)')) ?></h2><a class="btn btn-sm" href="<?= e(admin_url('seo/redirects')) ?>"><?= e(__('Gérer')) ?></a></div>
    <?php if ($notFound): ?><ul class="feed"><?php foreach (array_slice($notFound, 0, 8) as $n): ?><li><span class="mono small"><?= e(excerpt($n['path'], 50)) ?></span><span class="badge"><?= (int) $n['hits'] ?></span><a class="btn btn-sm" href="<?= e(admin_url('seo/redirects?from=' . rawurlencode($n['path']))) ?>"><?= e(__('Rediriger')) ?></a></li><?php endforeach ?></ul>
    <?php else: ?><div class="panel-body"><p class="muted small"><?= e(__('Aucune erreur 404 enregistrée.')) ?></p></div><?php endif ?></section>
  <section class="panel"><div class="panel-body stack-s"><h2><?= e(__('Fichiers générés automatiquement')) ?></h2>
    <p class="small"><a href="<?= e(href('/sitemap.xml')) ?>" target="_blank" rel="noopener">sitemap.xml</a> · <a href="<?= e(href('/robots.txt')) ?>" target="_blank" rel="noopener">robots.txt</a> · <a href="<?= e(href('/feed/')) ?>" target="_blank" rel="noopener">RSS</a> · <a href="<?= e(href('/llms.txt')) ?>" target="_blank" rel="noopener">llms.txt</a></p>
    <p class="hint"><?= e(__('Déclarez {u}/sitemap.xml dans Google Search Console et Bing Webmaster Tools.', ['u' => Config::baseUrl()])) ?></p></div></section>
</aside>
</div>
