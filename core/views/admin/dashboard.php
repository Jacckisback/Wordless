<?php
/**
 * Tableau de bord 360°.
 * @var array $pillars @var array $todo @var ?array $stats @var int $days @var array $aiBots @var array $counts
 * @var array $recent @var array $stale @var array $scheduled @var array $activity @var ?array $onboarding @var array $user
 */
use Wordless\Activity;
use Wordless\Admin\Charts;
use Wordless\Content;
use Wordless\I18n;

$statusLabel = ['good' => __('Bon'), 'warn' => __('À améliorer'), 'bad' => __('Critique')];
$statusIcon = ['good' => 'check', 'warn' => 'alert', 'bad' => 'alert'];
$hour = (int) (new DateTimeImmutable('now', new DateTimeZone((string) \Wordless\Settings::get('timezone', 'Europe/Paris'))))->format('G');
$hello = $hour < 5 || $hour >= 18 ? __('Bonsoir {n}', ['n' => $user['name']]) : __('Bonjour {n}', ['n' => $user['name']]);
$delta = static function (int $now, int $before): string {
    if ($before === 0) {
        return $now > 0 ? '<span class="delta up">' . e(__('nouveau')) . '</span>' : '';
    }
    $pct = (int) round(($now - $before) / $before * 100);
    $cls = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
    return '<span class="delta ' . $cls . '" title="' . e(__('par rapport à la période précédente')) . '">' . ($pct > 0 ? '+' : '') . $pct . ' %</span>';
};
?>
<div class="hello">
  <div><h1><?= e($hello) ?></h1><p class="muted"><?= e(fmt_date(time())) ?> · <?= e(__('Voici l’état de votre site en un coup d’œil.')) ?></p></div>
</div>

<?php if ($onboarding): $done = count(array_filter($onboarding, static fn ($s) => $s[3])); ?>
<section class="panel" aria-labelledby="ob-title">
  <div class="panel-head">
    <div><h2 id="ob-title"><?= e(__('Bien démarrer')) ?></h2><p class="muted small"><?= e(__('{a} étapes sur {b} terminées', ['a' => $done, 'b' => count($onboarding)])) ?></p></div>
    <form method="post" action="<?= e(admin_url()) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit"><?= e(__('Masquer')) ?></button></form>
  </div>
  <div class="panel-body">
    <ul class="onboard">
      <?php foreach ($onboarding as [$title, $text, $href, $isDone]): ?>
        <li class="<?= $isDone ? 'is-done' : '' ?>"><a href="<?= e($href) ?>"><span class="tick-box" aria-hidden="true"></span><span><strong><?= e($title) ?></strong><span class="muted small" style="display:block"><?= e($text) ?></span></span><?php if ($isDone): ?><span class="sr"><?= e(__('(fait)')) ?></span><?php endif ?></a></li>
      <?php endforeach ?>
    </ul>
  </div>
</section>
<div class="stack"></div>
<?php endif ?>

<section aria-label="<?= e(__('Santé du site')) ?>">
  <div class="health">
    <?php foreach ($pillars as $key => $p): ?>
      <a class="pillar" href="<?= e($p['href']) ?>">
        <span class="pillar-head"><?= icon($p['icon']) ?><?= e($p['label']) ?></span>
        <span class="pillar-value"><?= (int) $p['score'] ?><small> /100</small></span>
        <span class="status is-<?= e($p['status']) ?>"><?= icon($statusIcon[$p['status']]) ?><?= e($statusLabel[$p['status']]) ?></span>
        <span class="meter is-<?= e($p['status']) ?>" role="img" aria-label="<?= e($p['label'] . ' : ' . $p['score'] . ' / 100') ?>"><span style="width:<?= (int) $p['score'] ?>%"></span></span>
        <span class="pillar-note"><?= e($p['note']) ?></span>
      </a>
    <?php endforeach ?>
  </div>
</section>

<div class="grid grid-2" style="margin-top:1.25rem">
  <section class="panel" aria-labelledby="todo-title">
    <div class="panel-head"><div><h2 id="todo-title"><?= e(__('À faire maintenant')) ?></h2><p class="muted small"><?= e(__('Classé par impact : P0 bloquant, P3 finition.')) ?></p></div></div>
    <?php if ($todo): ?>
      <ul class="todo">
        <?php foreach ($todo as $t): ?>
          <li><span class="prio prio-<?= (int) $t['prio'] ?>">P<?= (int) $t['prio'] ?></span>
            <span class="todo-text"><strong><?= e($t['title']) ?></strong><span class="muted small"><?= e($t['text']) ?></span></span>
            <a class="btn btn-sm" href="<?= e($t['href']) ?>"><?= e($t['action']) ?></a></li>
        <?php endforeach ?>
      </ul>
    <?php else: ?>
      <div class="empty"><h2><?= e(__('Rien d’urgent')) ?></h2><p><?= e(__('Votre site est en bonne santé. Prochaine étape : publier régulièrement.')) ?></p></div>
    <?php endif ?>
  </section>

  <section class="panel" aria-labelledby="content-title">
    <div class="panel-head"><h2 id="content-title"><?= e(__('Contenus')) ?></h2><a class="btn btn-sm" href="<?= e(admin_url('content?type=post')) ?>"><?= e(__('Tout voir')) ?></a></div>
    <div class="kpis" style="grid-template-columns:repeat(4,minmax(0,1fr))">
      <div class="kpi"><div class="kpi-label"><?= e(__('Articles publiés')) ?></div><div class="kpi-value"><?= I18n::number($counts['post']['published'] ?? 0) ?></div></div>
      <div class="kpi"><div class="kpi-label"><?= e(__('Pages')) ?></div><div class="kpi-value"><?= I18n::number($counts['page']['published'] ?? 0) ?></div></div>
      <div class="kpi"><div class="kpi-label"><?= e(__('Brouillons')) ?></div><div class="kpi-value"><?= I18n::number(($counts['post']['draft'] ?? 0) + ($counts['page']['draft'] ?? 0)) ?></div></div>
      <div class="kpi"><div class="kpi-label"><?= e(__('Programmés')) ?></div><div class="kpi-value"><?= I18n::number(($counts['post']['scheduled'] ?? 0) + ($counts['page']['scheduled'] ?? 0)) ?></div></div>
    </div>
    <ul class="feed">
      <?php foreach ($recent as $c): ?>
        <li><a href="<?= e(admin_url('content/' . $c['id'])) ?>"><?= e($c['title'] !== '' ? $c['title'] : __('(sans titre)')) ?></a>
          <span class="badge<?= $c['status'] === 'published' ? ' badge--good' : ($c['status'] === 'scheduled' ? ' badge--accent' : '') ?>"><?= e(Content::statusLabel($c['status'])) ?></span>
          <time><?= e(human_time((int) $c['updated_at'])) ?></time></li>
      <?php endforeach ?>
    </ul>
  </section>
</div>

<?php if ($stats !== null): ?>
<div class="section-title">
  <h2><?= e(__('Audience')) ?> <span class="muted small"><?= e(__('sans cookie, conforme RGPD')) ?></span></h2>
  <nav class="actions-row" aria-label="<?= e(__('Période')) ?>">
    <?php foreach ([7, 30, 90] as $d): ?><a class="btn btn-sm<?= $d === $days ? ' btn-primary' : '' ?>" href="<?= e(admin_url('?days=' . $d)) ?>"<?= $d === $days ? ' aria-current="page"' : '' ?>><?= e(__('{n} jours', ['n' => $d])) ?></a><?php endforeach ?>
  </nav>
</div>
<?php
$aiVisits = (int) ($stats['channels']['ai'] ?? 0);
$botRows = array_values(array_filter($stats['bots'], static fn ($b) => in_array($b['source'], $aiBots, true)));
$aiCrawls = array_sum(array_column($botRows, 'views'));
?>
<section class="panel">
  <div class="kpis">
    <div class="kpi"><div class="kpi-label"><?= e(__('Visiteurs')) ?></div><div class="kpi-value num"><?= I18n::number($stats['visitors']) ?><?= $delta((int) $stats['visitors'], (int) $stats['prev_visitors']) ?></div></div>
    <div class="kpi"><div class="kpi-label"><?= e(__('Pages vues')) ?></div><div class="kpi-value num"><?= I18n::number($stats['views']) ?><?= $delta((int) $stats['views'], (int) $stats['prev_views']) ?></div></div>
    <div class="kpi"><div class="kpi-label"><?= e(__('Visites venues des IA')) ?></div><div class="kpi-value num"><?= I18n::number($aiVisits) ?></div></div>
    <div class="kpi"><div class="kpi-label"><?= e(__('Lectures par des robots IA')) ?></div><div class="kpi-value num"><?= I18n::number($aiCrawls) ?></div></div>
  </div>
  <?php $visitSeries = array_map(static fn ($d) => (int) $d['visitors'], $stats['series']); ?>
  <h3 class="sr"><?= e(__('Visiteurs par jour')) ?></h3>
  <?= $stats['views'] > 0 ? Charts::columns($visitSeries, __('Visiteurs par jour')) : '<div class="empty"><h2>' . e(__('Pas encore de visites')) . '</h2><p>' . e(__('Les statistiques apparaissent dès les premières visites. Aucun cookie, aucune donnée personnelle : pas de bandeau de consentement nécessaire.')) . '</p></div>' ?>
</section>

<div class="grid grid-3" style="margin-top:1.25rem">
  <section class="panel"><div class="panel-head"><h2><?= e(__('D’où viennent vos visiteurs')) ?></h2></div><div class="panel-body">
    <?= Charts::bars([
        [__('Moteurs de recherche'), (int) $stats['channels']['search']], [__('Assistants IA'), (int) $stats['channels']['ai']],
        [__('Réseaux sociaux'), (int) $stats['channels']['social']], [__('Autres sites'), (int) $stats['channels']['referral']], [__('Accès direct'), (int) $stats['channels']['direct']],
    ], __('Aucune donnée sur la période.')) ?>
  </div></section>
  <section class="panel"><div class="panel-head"><div><h2><?= e(__('Les IA qui vous recommandent')) ?></h2><p class="muted small"><?= e(__('Visites depuis ChatGPT, Perplexity, Claude, Gemini…')) ?></p></div></div><div class="panel-body">
    <?= Charts::bars(array_map(static fn ($s) => [$s['source'], (int) $s['views']], $stats['sources']['ai'] ?? []), __('Aucune visite venue d’un assistant IA pour l’instant. Le score GEO de vos pages vous dit comment y remédier.')) ?>
  </div></section>
  <section class="panel"><div class="panel-head"><div><h2><?= e(__('Robots IA qui lisent votre site')) ?></h2><p class="muted small"><?= e(__('Pages lues par GPTBot, ClaudeBot, PerplexityBot…')) ?></p></div></div><div class="panel-body">
    <?= Charts::bars(array_map(static fn ($s) => [$s['source'], (int) $s['views']], $botRows), __('Aucun passage enregistré sur la période.')) ?>
  </div></section>
</div>

<section class="panel" style="margin-top:1.25rem">
  <div class="panel-head"><h2><?= e(__('Pages les plus vues')) ?></h2></div>
  <?php if ($stats['top']): ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th><?= e(__('Adresse')) ?></th><th class="num"><?= e(__('Vues')) ?></th></tr></thead>
    <tbody><?php foreach ($stats['top'] as $t): ?><tr><td><a href="<?= e(href($t['path'])) ?>" target="_blank" rel="noopener"><?= e($t['path']) ?></a></td><td class="num"><?= I18n::number((int) $t['views']) ?></td></tr><?php endforeach ?></tbody>
  </table></div>
  <?php else: ?><div class="panel-body"><p class="muted small"><?= e(__('Aucune donnée sur la période.')) ?></p></div><?php endif ?>
</section>
<?php endif ?>

<div class="grid grid-2" style="margin-top:1.25rem">
  <section class="panel"><div class="panel-head"><h2><?= e(__('Activité récente')) ?></h2><?php if (\Wordless\Auth::can('manage_settings')): ?><a class="btn btn-sm" href="<?= e(admin_url('activity')) ?>"><?= e(__('Journal complet')) ?></a><?php endif ?></div>
    <ul class="feed"><?php foreach ($activity as $a): ?><li><span><strong><?= e($a['user_name'] ?? __('Système')) ?></strong> <?= e(Activity::describe($a)) ?></span><time><?= e(human_time((int) $a['created_at'])) ?></time></li><?php endforeach ?></ul>
  </section>
  <section class="panel"><div class="panel-head"><div><h2><?= e(__('À rafraîchir')) ?></h2><p class="muted small"><?= e(__('Vos articles les plus anciens : une mise à jour relance leur visibilité.')) ?></p></div></div>
    <?php if ($stale || $scheduled): ?>
    <ul class="feed">
      <?php foreach ($scheduled as $c): ?><li><a href="<?= e(admin_url('content/' . $c['id'])) ?>"><?= e($c['title']) ?></a><span class="badge badge--accent"><?= e(__('Programmé')) ?></span><time><?= e(fmt_date((int) $c['published_at'], 'datetime')) ?></time></li><?php endforeach ?>
      <?php foreach ($stale as $c): ?><li><a href="<?= e(admin_url('content/' . $c['id'])) ?>"><?= e($c['title']) ?></a><time><?= e(__('modifié {t}', ['t' => human_time((int) $c['updated_at'])])) ?></time></li><?php endforeach ?>
    </ul>
    <?php else: ?><div class="panel-body"><p class="muted small"><?= e(__('Rien à signaler.')) ?></p></div><?php endif ?>
  </section>
</div>
