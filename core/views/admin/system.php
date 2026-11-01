<?php /** @var array $checks @var array $sizes @var array $code */ ?>
<div class="split">
  <section class="panel"><div class="panel-head"><h2><?= e(__('Environnement')) ?></h2></div>
    <div class="table-wrap"><table class="table"><tbody>
      <?php foreach ($checks as [$label, $value, $status]): ?><tr><td><?= e($label) ?></td><td><span class="status is-<?= e($status) ?>"><?= icon($status === 'good' ? 'check' : 'alert') ?><?= e($value) ?></span></td></tr><?php endforeach ?>
    </tbody></table></div>
  </section>
  <aside class="stack">
    <div class="panel"><div class="panel-body stack-s"><h2>Wordless <?= e(WL_VERSION) ?></h2>
      <p class="muted small"><?= e(__('Logiciel libre sous licence GNU AGPL-3.0.')) ?> <a href="<?= e(WL_SOURCE) ?>" target="_blank" rel="noopener"><?= e(__('Code source')) ?></a></p>
    </div></div>
    <div class="panel"><div class="panel-body stack-s"><h2><?= e(__('Poids')) ?></h2>
      <p><?= e(__('Code de Wordless : {s} ({n} fichiers)', ['s' => fmt_bytes($code['bytes']), 'n' => $code['files']])) ?></p>
      <p><?= e(__('Base de données : {s}', ['s' => fmt_bytes($sizes['db'])])) ?></p>
      <p><?= e(__('Médias : {s}', ['s' => fmt_bytes($sizes['media'])])) ?></p>
      <p><?= e(__('Cache : {n} pages ({s})', ['n' => $sizes['cache']['pages'], 's' => fmt_bytes($sizes['cache']['bytes'])])) ?></p>
    </div></div>
    <div class="panel"><div class="panel-body stack-s"><h2><?= e(__('Serveur Nginx ?')) ?></h2>
      <p class="muted small"><?= e(__('Nginx ignore les fichiers .htaccess. Ajoutez ces règles à la configuration du site :')) ?></p>
      <pre class="code-box" style="white-space:pre;display:block">location ~ ^/(data|core|tests|bin)/ { deny all; }
location ~ /\.(?!well-known) { deny all; }
location ~ \.php$ { try_files /index.php =404; … }
location / { try_files $uri /index.php?$args; }</pre>
    </div></div>
  </aside>
</div>
