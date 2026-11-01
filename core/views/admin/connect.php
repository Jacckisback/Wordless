<?php /** @var string $mcpUrl @var bool $enabled @var array $keys @var array $clients @var string $newKey @var bool $root @var bool $https */ use Wordless\Auth; ?>
<?php if (!$enabled): ?><div class="notice notice--warn"><?= icon('alert') ?><p><?= e(__('Le connecteur est désactivé : aucune IA ne peut accéder au site.')) ?></p></div><div class="stack"></div><?php endif ?>
<?php if (!$https): ?><div class="notice notice--warn"><?= icon('alert') ?><p><?= e(__('Claude.ai et ChatGPT exigent une adresse en HTTPS accessible depuis Internet. En local, utilisez Claude Code avec une clé API.')) ?></p></div><div class="stack"></div><?php endif ?>
<div class="split">
  <div class="stack">
    <section class="panel"><div class="panel-body stack">
      <div><h2><?= e(__('Pilotez votre site depuis Claude ou ChatGPT')) ?></h2>
        <p class="muted"><?= e(__('Une fois connecté, demandez simplement : « Fais-moi le point sur le site cette semaine », « Écris un article sur… et mets-le en brouillon », « Corrige les méta-descriptions manquantes », « Quelles pages lisent les robots IA ? ».')) ?></p></div>
      <div class="field"><span class="label"><?= e(__('Adresse du connecteur (MCP)')) ?></span><div class="code-box"><code><?= e($mcpUrl) ?></code><button class="btn btn-sm" type="button" data-copy="<?= e($mcpUrl) ?>"><?= icon('copy') ?><?= e(__('Copier')) ?></button></div></div>
    </div></section>
    <section class="panel"><div class="panel-head"><h2>Claude.ai <span class="muted small">(<?= e(__('web, ordinateur, mobile')) ?>)</span></h2></div><div class="panel-body">
      <ol class="steps-list"><li><?= e(__('Dans Claude : Réglages > Connecteurs > « Ajouter un connecteur personnalisé ».')) ?></li><li><?= e(__('Nom : le nom de votre site. URL : l’adresse ci-dessus. Validez.')) ?></li><li><?= e(__('Cliquez « Se connecter » : votre page de connexion Wordless s’ouvre, autorisez l’accès.')) ?></li><li><?= e(__('Dans une conversation, activez le connecteur depuis le menu des outils.')) ?></li></ol>
    </div></section>
    <section class="panel"><div class="panel-head"><h2>ChatGPT</h2></div><div class="panel-body">
      <ol class="steps-list"><li><?= e(__('Dans ChatGPT : Paramètres > Applications et connecteurs > Paramètres avancés > activer le « mode développeur ».')) ?></li><li><?= e(__('« Créer » un connecteur : collez l’adresse ci-dessus, authentification OAuth.')) ?></li><li><?= e(__('Connectez-vous à Wordless et autorisez l’accès. ChatGPT demande confirmation avant chaque modification.')) ?></li></ol>
      <p class="hint"><?= e(__('La disponibilité des connecteurs personnalisés dépend de votre abonnement ChatGPT.')) ?></p>
    </div></section>
    <section class="panel"><div class="panel-head"><h2>Claude Code, Cursor, API</h2></div><div class="panel-body stack-s">
      <p class="muted small"><?= e(__('Créez une clé API ci-contre, puis :')) ?></p>
      <div class="code-box"><code>claude mcp add --transport http wordless <?= e($mcpUrl) ?> --header "Authorization: Bearer <?= e($newKey !== '' ? $newKey : 'wl_…') ?>"</code><button class="btn btn-sm" type="button" data-copy="claude mcp add --transport http wordless <?= e($mcpUrl) ?> --header &quot;Authorization: Bearer <?= e($newKey !== '' ? $newKey : 'wl_…') ?>&quot;"><?= icon('copy') ?></button></div>
    </div></section>
    <?php if (!$root): ?><div class="notice"><?= icon('info') ?><p><?= e(__('Site installé dans un sous-dossier : la connexion OAuth (Claude.ai, ChatGPT) nécessite une redirection des adresses /.well-known/ à la racine du domaine. Les clés API fonctionnent sans réglage.')) ?></p></div><?php endif ?>
  </div>
  <aside class="stack">
    <?php if ($newKey !== ''): ?>
      <div class="notice notice--warn"><?= icon('key') ?><div class="stack-s"><p><strong><?= e(__('Votre nouvelle clé (affichée une seule fois)')) ?></strong></p><div class="code-box"><code><?= e($newKey) ?></code><button class="btn btn-sm" type="button" data-copy="<?= e($newKey) ?>"><?= icon('copy') ?></button></div></div></div>
    <?php endif ?>
    <section class="panel"><div class="panel-head"><h2><?= e(__('Applications autorisées')) ?></h2></div>
      <?php if ($clients): ?><ul class="feed"><?php foreach ($clients as $c): ?><li><span><strong><?= e((string) ($c['name'] ?? $c['client_name'] ?? '')) ?></strong><span class="muted small" style="display:block"><?= e(__('dernière utilisation {t}', ['t' => human_time((int) ($c['last_used_at'] ?? 0) ?: null)])) ?></span></span>
        <form method="post" action="<?= e(admin_url('connect')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="revoke_client"><input type="hidden" name="id" value="<?= e((string) ($c['id'] ?? $c['client_id'] ?? '')) ?>"><button class="btn btn-sm btn-danger" type="submit" data-confirm="<?= e(__('Révoquer l’accès de cette application ?')) ?>"><?= e(__('Révoquer')) ?></button></form></li><?php endforeach ?></ul>
      <?php else: ?><div class="panel-body"><p class="muted small"><?= e(__('Aucune application connectée.')) ?></p></div><?php endif ?></section>
    <section class="panel"><div class="panel-head"><h2><?= e(__('Clés API')) ?></h2></div>
      <?php if ($keys): ?><ul class="feed"><?php foreach ($keys as $k): ?><li><span><strong><?= e((string) $k['label']) ?></strong><span class="muted small" style="display:block"><?= e(__('créée {c} · utilisée {u}', ['c' => human_time((int) $k['created_at']), 'u' => $k['last_used_at'] ? human_time((int) $k['last_used_at']) : __('jamais')])) ?></span></span>
        <form method="post" action="<?= e(admin_url('connect')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="revoke_key"><input type="hidden" name="id" value="<?= e((string) $k['id']) ?>"><button class="btn btn-sm btn-ghost" type="submit" data-confirm="<?= e(__('Révoquer cette clé ?')) ?>" aria-label="<?= e(__('Révoquer')) ?>"><?= icon('trash') ?></button></form></li><?php endforeach ?></ul><?php endif ?>
      <form class="panel-body row" method="post" action="<?= e(admin_url('connect')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="key"><input name="label" placeholder="<?= e(__('Nom (ex. Claude Code)')) ?>" style="flex:1"><button class="btn" type="submit"><?= icon('key') ?><?= e(__('Créer une clé')) ?></button></form>
    </section>
    <?php if (Auth::can('manage_settings')): ?>
    <form class="panel" method="post" action="<?= e(admin_url('connect')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle">
      <div class="panel-body stack-s"><label class="switch"><span class="switch-text"><span class="label"><?= e(__('Connecteur activé')) ?></span><span class="hint"><?= e(__('Chaque IA agit avec les droits de la personne qui l’a autorisée. Toutes ses actions sont tracées dans le journal.')) ?></span></span><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?>></label><button class="btn btn-sm" type="submit"><?= e(__('Enregistrer')) ?></button></div>
    </form>
    <?php endif ?>
  </aside>
</div>
