<?php
/** @var string $section @var array $pages @var string $url @var ?array $logo @var ?array $icon @var array $smtp */
use Wordless\I18n;
use Wordless\Media;
use Wordless\Settings;

$tabs = ['general' => __('Général'), 'reading' => __('Lecture et adresses'), 'comments' => __('Commentaires'), 'email' => __('E-mails'), 'performance' => __('Performance'), 'security' => __('Sécurité'), 'advanced' => __('Avancé')];
$S = static fn (string $k, mixed $d = null) => Settings::get($k, $d);
$checked = static fn (bool $b): string => $b ? 'checked' : '';
?>
<nav class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="<?= e(admin_url('settings' . ($k === 'general' ? '' : '/' . $k))) ?>"<?= $section === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach ?><a href="<?= e(admin_url('system')) ?>"><?= e(__('Santé du système')) ?></a></nav>
<form class="panel" method="post" action="<?= e(admin_url('settings' . ($section === 'general' ? '' : '/' . $section))) ?>"><?= csrf_field() ?>
<div class="panel-body form-stack" style="max-width:46rem">
<?php if ($section === 'general'): ?>
  <div class="field-row">
    <div class="field"><label for="site_name"><?= e(__('Nom du site')) ?></label><input id="site_name" name="site_name" required value="<?= e((string) $S('site.name')) ?>"></div>
    <div class="field"><label for="tagline"><?= e(__('Slogan')) ?></label><input id="tagline" name="tagline" value="<?= e((string) $S('site.tagline')) ?>"></div>
  </div>
  <div class="field"><label for="site_url"><?= e(__('Adresse du site')) ?></label><input id="site_url" name="site_url" type="url" value="<?= e($url) ?>"><p class="hint"><?= e(__('À modifier seulement après un changement de domaine ou le passage en HTTPS.')) ?></p></div>
  <div class="field-row">
    <div class="field"><label for="language"><?= e(__('Langue')) ?></label><select id="language" name="language"><?php foreach (I18n::LANGUAGES as $k => $l): ?><option value="<?= e($k) ?>" <?= $S('language') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="timezone"><?= e(__('Fuseau horaire')) ?></label><select id="timezone" name="timezone"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option <?= $S('timezone') === $tz ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach ?></select></div>
  </div>
  <fieldset><legend><?= e(__('Identité (données structurées)')) ?></legend>
    <div class="field-row">
      <div class="field"><label for="org_type"><?= e(__('Le site représente')) ?></label><select id="org_type" name="org_type"><option value="Organization"><?= e(__('Une entreprise ou organisation')) ?></option><option value="Person" <?= $S('site.org_type') === 'Person' ? 'selected' : '' ?>><?= e(__('Une personne')) ?></option></select></div>
      <div class="field"><label for="org_name"><?= e(__('Nom officiel')) ?></label><input id="org_name" name="org_name" value="<?= e((string) $S('site.org_name')) ?>"></div>
    </div>
    <div class="field" style="margin-top:1rem"><label for="social"><?= e(__('Profils officiels (un lien https par ligne)')) ?></label><textarea id="social" name="social" rows="4" placeholder="https://www.linkedin.com/company/…&#10;https://www.instagram.com/…&#10;https://g.page/…"><?= e(implode("\n", (array) $S('site.social', []))) ?></textarea><p class="hint"><?= e(__('Reliés à votre site dans le JSON-LD (sameAs) : Google et les IA reconnaissent mieux votre marque.')) ?></p></div>
  </fieldset>
  <div class="field-row">
    <div class="field"><span class="label"><?= e(__('Logo')) ?></span><div class="row" data-media-field><input type="hidden" name="logo_id" value="<?= (int) ($logo['id'] ?? 0) ?: '' ?>"><div class="media-thumb" style="width:5rem;height:3rem;border-radius:6px" data-media-thumb><?= $logo ? Media::picture($logo, ['sizes' => '80px']) : '' ?></div><button class="btn btn-sm" type="button" data-media-pick><?= e(__('Choisir')) ?></button><button class="btn btn-sm btn-ghost" type="button" data-media-clear><?= e(__('Retirer')) ?></button></div></div>
    <div class="field"><span class="label"><?= e(__('Icône du site (favicon)')) ?></span><div class="row" data-media-field><input type="hidden" name="icon_id" value="<?= (int) ($icon['id'] ?? 0) ?: '' ?>"><div class="media-thumb" style="width:3rem;height:3rem;border-radius:6px" data-media-thumb><?= $icon ? Media::picture($icon, ['sizes' => '48px']) : '' ?></div><button class="btn btn-sm" type="button" data-media-pick><?= e(__('Choisir')) ?></button><button class="btn btn-sm btn-ghost" type="button" data-media-clear><?= e(__('Retirer')) ?></button></div><p class="hint"><?= e(__('Image carrée, 512 × 512 px idéalement.')) ?></p></div>
  </div>
<?php elseif ($section === 'reading'): ?>
  <fieldset><legend><?= e(__('Page d’accueil')) ?></legend>
    <label class="check"><input type="radio" name="home_type" value="page" <?= $checked($S('home.type') === 'page') ?>><span><?= e(__('Une page fixe')) ?></span></label>
    <label class="check"><input type="radio" name="home_type" value="posts" <?= $checked($S('home.type') === 'posts') ?>><span><?= e(__('Les derniers articles')) ?></span></label>
    <div class="field-row" style="margin-top:.75rem">
      <div class="field"><label for="home_page_id"><?= e(__('Page d’accueil')) ?></label><select id="home_page_id" name="home_page_id"><option value=""><?= e(__('—')) ?></option><?php foreach ($pages as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $S('home.page_id') === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="blog_page_id"><?= e(__('Page des articles (blog)')) ?></label><select id="blog_page_id" name="blog_page_id"><option value=""><?= e(__('—')) ?></option><?php foreach ($pages as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $S('blog.page_id') === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option><?php endforeach ?></select></div>
    </div>
  </fieldset>
  <div class="field"><label for="per_page"><?= e(__('Articles par page')) ?></label><input id="per_page" name="per_page" type="number" min="1" max="50" value="<?= (int) $S('blog.per_page', 10) ?>" style="max-width:8rem"></div>
  <fieldset><legend><?= e(__('Adresse des articles')) ?></legend>
    <?php $pl = (string) $S('permalink', '/{slug}'); $opts = ['/{slug}' => '/mon-article', '/blog/{slug}' => '/blog/mon-article', '/{year}/{month}/{slug}' => '/2026/11/mon-article', '/{category}/{slug}' => '/conseils/mon-article']; ?>
    <?php foreach ($opts as $k => $ex): ?><label class="check"><input type="radio" name="permalink" value="<?= e($k) ?>" <?= $checked($pl === $k) ?>><span class="mono small"><?= e($ex) ?></span></label><?php endforeach ?>
    <label class="check"><input type="radio" name="permalink" value="custom" <?= $checked(!isset($opts[$pl])) ?>><span><?= e(__('Personnalisée')) ?> <input name="permalink_custom" value="<?= e(isset($opts[$pl]) ? '' : $pl) ?>" placeholder="/{year}/{slug}" class="mono" style="max-width:14rem"></span></label>
    <p class="hint"><?= e(__('Les articles existants gardent leur adresse ; si elle change, une redirection 301 est créée automatiquement.')) ?></p>
  </fieldset>
  <label class="switch"><span class="switch-text"><span class="label"><?= e(__('Barre oblique finale (/mon-article/)')) ?></span><span class="hint"><?= e(__('Comme WordPress. Gardez le même choix qu’avant une migration.')) ?></span></span><input type="checkbox" name="trailing_slash" value="1" <?= $checked((bool) $S('trailing_slash', true)) ?>></label>
<?php elseif ($section === 'comments'): ?>
  <label class="switch"><span class="switch-text"><span class="label"><?= e(__('Autoriser les commentaires')) ?></span><span class="hint"><?= e(__('Réglable ensuite article par article.')) ?></span></span><input type="checkbox" name="enabled" value="1" <?= $checked((bool) $S('comments.enabled', true)) ?>></label>
  <fieldset><legend><?= e(__('Modération')) ?></legend>
    <label class="check"><input type="radio" name="moderation" value="first" <?= $checked($S('comments.moderation') === 'first') ?>><span><?= e(__('Valider le premier commentaire de chaque personne (recommandé)')) ?></span></label>
    <label class="check"><input type="radio" name="moderation" value="all" <?= $checked($S('comments.moderation') === 'all') ?>><span><?= e(__('Tout valider à la main')) ?></span></label>
    <label class="check"><input type="radio" name="moderation" value="none" <?= $checked($S('comments.moderation') === 'none') ?>><span><?= e(__('Publier directement (filtre anti-spam seul)')) ?></span></label>
  </fieldset>
  <label class="switch"><span class="switch-text"><span class="label"><?= e(__('M’avertir par e-mail des commentaires à valider')) ?></span></span><input type="checkbox" name="notify" value="1" <?= $checked((bool) $S('comments.notify', true)) ?>></label>
<?php elseif ($section === 'email'): ?>
  <div class="field"><label for="from"><?= e(__('Adresse d’expédition')) ?></label><input id="from" name="from" type="email" value="<?= e((string) $S('mail.from')) ?>"><p class="hint"><?= e(__('Utilisez une adresse de votre domaine pour éviter les spams.')) ?></p></div>
  <fieldset><legend><?= e(__('Serveur SMTP (recommandé)')) ?></legend><p class="hint" style="margin-bottom:.75rem"><?= e(__('Sans SMTP, la fonction mail() de l’hébergement est utilisée : souvent classée en spam. Brevo, Mailjet, Gmail, OVH ou votre hébergeur fournissent ces informations.')) ?></p>
    <div class="field-row">
      <div class="field"><label for="smtp_host"><?= e(__('Serveur')) ?></label><input id="smtp_host" name="smtp_host" value="<?= e((string) ($smtp['host'] ?? '')) ?>" placeholder="smtp-relay.brevo.com"></div>
      <div class="field"><label for="smtp_port"><?= e(__('Port')) ?></label><input id="smtp_port" name="smtp_port" type="number" value="<?= (int) ($smtp['port'] ?? 587) ?>"></div>
      <div class="field"><label for="smtp_secure"><?= e(__('Chiffrement')) ?></label><select id="smtp_secure" name="smtp_secure"><option value="tls">STARTTLS (587)</option><option value="ssl" <?= ($smtp['secure'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL (465)</option></select></div>
    </div>
    <div class="field-row" style="margin-top:1rem">
      <div class="field"><label for="smtp_user"><?= e(__('Identifiant')) ?></label><input id="smtp_user" name="smtp_user" value="<?= e((string) ($smtp['user'] ?? '')) ?>" autocomplete="off"></div>
      <div class="field"><label for="smtp_pass"><?= e(__('Mot de passe')) ?></label><input id="smtp_pass" name="smtp_pass" type="password" value="<?= !empty($smtp['pass']) ? '••••…' : '' ?>" autocomplete="new-password"></div>
    </div>
  </fieldset>
  <label class="check"><input type="checkbox" name="test" value="1"><span><?= e(__('M’envoyer un e-mail de test après l’enregistrement')) ?></span></label>
<?php elseif ($section === 'performance'): ?>
  <label class="switch"><span class="switch-text"><span class="label"><?= e(__('Cache de pages')) ?></span><span class="hint"><?= e(__('Les pages publiques sont servies déjà prêtes, en moins d’une milliseconde. Vidé automatiquement à chaque modification.')) ?></span></span><input type="checkbox" name="cache" value="1" <?= $checked((bool) $S('cache.enabled', true)) ?>></label>
  <label class="switch"><span class="switch-text"><span class="label"><?= e(__('Statistiques de visite intégrées')) ?></span><span class="hint"><?= e(__('Sans cookie ni donnée personnelle : aucun bandeau de consentement nécessaire.')) ?></span></span><input type="checkbox" name="stats" value="1" <?= $checked((bool) $S('stats.enabled', true)) ?>></label>
  <label class="check"><input type="checkbox" name="purge" value="1"><span><?= e(__('Reconstruire l’index de recherche')) ?></span></label>
<?php elseif ($section === 'security'): ?>
  <label class="switch"><span class="switch-text"><span class="label"><?= e(__('Double authentification obligatoire pour les administrateurs')) ?></span></span><input type="checkbox" name="require_2fa" value="1" <?= $checked((bool) $S('security.require_2fa', false)) ?>></label>
  <label class="switch"><span class="switch-text"><span class="label"><?= e(__('HSTS (forcer le HTTPS)')) ?></span><span class="hint"><?= e(__('À activer seulement quand tout le site fonctionne en HTTPS.')) ?></span></span><input type="checkbox" name="hsts" value="1" <?= $checked((bool) $S('security.hsts', false)) ?>></label>
  <div class="notice"><?= icon('shield') ?><div><p><strong><?= e(__('Protections toujours actives')) ?></strong></p><p class="small"><?= e(__('Mots de passe Argon2id, sessions en cookie HttpOnly, jetons CSRF, blocage progressif des tentatives de connexion, en-têtes de sécurité et CSP stricte, aucun XML-RPC, aucune énumération des comptes, aucun script exécutable dans les médias, images ré-encodées (données GPS supprimées), clés API chiffrées.')) ?></p></div></div>
<?php elseif ($section === 'advanced'): ?>
  <div class="notice notice--warn"><?= icon('alert') ?><p><?= e(__('Le code ajouté ici (Google Analytics, pixels…) ralentit le site et désactive la protection CSP stricte. Les statistiques intégrées suffisent souvent.')) ?></p></div>
  <div class="field"><label for="code_head"><?= e(__('Code dans <head>')) ?></label><textarea id="code_head" name="code_head" rows="6" class="mono"><?= e((string) $S('code.head', '')) ?></textarea></div>
  <div class="field"><label for="code_footer"><?= e(__('Code avant </body>')) ?></label><textarea id="code_footer" name="code_footer" rows="6" class="mono"><?= e((string) $S('code.footer', '')) ?></textarea></div>
<?php endif ?>
</div>
<div class="panel-foot"><button class="btn btn-primary" type="submit"><?= e(__('Enregistrer')) ?></button></div>
</form>
