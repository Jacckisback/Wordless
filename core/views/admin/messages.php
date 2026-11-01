<?php /** @var array $rows @var ?array $open @var string $status */ ?>
<nav class="tabs"><a href="<?= e(admin_url('messages')) ?>"<?= $status === 'inbox' ? ' aria-current="page"' : '' ?>><?= e(__('Boîte de réception')) ?></a><a href="<?= e(admin_url('messages?status=archived')) ?>"<?= $status === 'archived' ? ' aria-current="page"' : '' ?>><?= e(__('Archivés')) ?></a></nav>
<div class="split">
  <section class="panel">
    <?php if ($rows): ?><ul class="feed"><?php foreach ($rows as $r): $d = json_dec($r['data'], []); ?>
      <li><a href="<?= e(admin_url('messages?id=' . $r['id'] . ($status === 'archived' ? '&status=archived' : ''))) ?>"><?= $r['status'] === 'new' ? '<strong>' . e($d['name'] ?? '') . '</strong>' : e($d['name'] ?? '') ?></a><span class="muted small"><?= e(excerpt((string) ($d['subject'] ?? $d['message'] ?? ''), 60)) ?></span><?php if ($r['status'] === 'new'): ?><span class="badge badge--accent"><?= e(__('Nouveau')) ?></span><?php endif ?><time><?= e(human_time((int) $r['created_at'])) ?></time></li>
    <?php endforeach ?></ul><?php else: ?><div class="empty"><h2><?= e(__('Aucun message')) ?></h2><p><?= e(__('Les messages envoyés via un bloc « Formulaire de contact » arrivent ici et par e-mail.')) ?></p></div><?php endif ?>
  </section>
  <?php if ($open): $d = json_dec($open['data'], []); ?>
  <aside class="panel"><div class="panel-body stack">
    <div><h2><?= e($d['name'] ?? '') ?></h2><p class="muted small"><?= e(fmt_date((int) $open['created_at'], 'datetime')) ?><?= $open['content_title'] ? ' · ' . e($open['content_title']) : '' ?></p></div>
    <p><a href="mailto:<?= e($d['email'] ?? '') ?>?subject=<?= rawurlencode('Re: ' . ($d['subject'] ?? '')) ?>"><?= e($d['email'] ?? '') ?></a><?= !empty($d['phone']) ? ' · <a href="tel:' . e($d['phone']) . '">' . e($d['phone']) . '</a>' : '' ?></p>
    <?php if (!empty($d['subject'])): ?><p><strong><?= e($d['subject']) ?></strong></p><?php endif ?>
    <p style="white-space:pre-wrap"><?= e($d['message'] ?? '') ?></p>
    <div class="actions-row">
      <a class="btn btn-primary" href="mailto:<?= e($d['email'] ?? '') ?>?subject=<?= rawurlencode('Re: ' . ($d['subject'] ?? '')) ?>"><?= icon('mail') ?><?= e(__('Répondre')) ?></a>
      <form method="post" action="<?= e(admin_url('messages/action')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $open['id'] ?>"><button class="btn" name="action" value="archive" type="submit"><?= e(__('Archiver')) ?></button><button class="btn btn-ghost" name="action" value="delete" type="submit" data-confirm="<?= e(__('Supprimer ce message ?')) ?>"><?= icon('trash') ?></button></form>
    </div>
  </div></aside>
  <?php endif ?>
</div>
