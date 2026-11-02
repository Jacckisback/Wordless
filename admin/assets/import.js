/* Assistant de migration WordPress : lancement, étapes successives, rapport. */
(function () {
  var T = WL.t, form = document.getElementById('import-form');
  if (!form) return;
  form.setAttribute('data-no-busy', '');
  document.querySelectorAll('[data-method]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('[data-method]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
      form.elements.import_method.value = b.dataset.method;
      document.querySelectorAll('[data-pane]').forEach(function (p) { p.hidden = p.dataset.pane !== b.dataset.method; });
    });
  });
  var phasesEl = document.getElementById('import-phases'), logEl = document.getElementById('import-log'), statusEl = document.getElementById('import-status');
  function render(job) {
    phasesEl.innerHTML = '';
    Object.keys(job.phases || {}).forEach(function (k) {
      var p = job.phases[k], li = document.createElement('li');
      var name = document.createElement('span'); name.textContent = p.label; li.appendChild(name);
      var meter = document.createElement('span'); meter.className = 'meter' + (p.status === 'done' ? ' is-good' : ''); var fill = document.createElement('span');
      fill.style.width = (p.total ? Math.round(p.done / p.total * 100) : (p.status === 'done' ? 100 : 0)) + '%'; meter.appendChild(fill); li.appendChild(meter);
      var n = document.createElement('span'); n.className = 'muted small num'; n.textContent = p.total ? p.done + ' / ' + p.total : (p.status === 'done' ? '✓' : ''); li.appendChild(n);
      phasesEl.appendChild(li);
    });
    logEl.textContent = (job.log || []).slice(-30).join('\n');
    logEl.scrollTop = logEl.scrollHeight;
  }
  function report(job) {
    var box = document.getElementById('import-report-body'); box.innerHTML = '';
    var r = job.report || {}, c = r.counts || {};
    var el = function (tag, text, cls, parent) { var n = document.createElement(tag); if (text != null) n.textContent = text; if (cls) n.className = cls; (parent || box).appendChild(n); return n; };
    var num = function (n) { return Number(n || 0).toLocaleString(document.documentElement.lang || 'fr'); };
    var dur = r.duration ? (r.duration < 60 ? r.duration + ' s' : Math.round(r.duration / 60) + ' min') : '';
    el('p', T('Migration terminée', 'Migration complete', 'Migración terminada') + (dur ? ' · ' + dur : ''), 'status is-good');

    // Ce qui a été importé
    var rows = [['pages', T('Pages', 'Pages', 'Páginas')], ['posts', T('Articles', 'Posts', 'Artículos')], ['other', T('Autres contenus', 'Other content', 'Otros contenidos')],
      ['media', T('Images et fichiers', 'Images and files', 'Imágenes y archivos')], ['categories', T('Catégories', 'Categories', 'Categorías')], ['tags', T('Étiquettes', 'Tags', 'Etiquetas')],
      ['users', T('Auteurs', 'Authors', 'Autores')], ['comments', T('Commentaires', 'Comments', 'Comentarios')], ['menus', T('Menus', 'Menus', 'Menús')], ['redirects', T('Redirections 301', '301 redirects', 'Redirecciones 301')]];
    var wrap = el('div', null, 'table-wrap'), table = el('table', null, 'table', wrap), head = el('tr', null, null, el('thead', null, null, table)), body = el('tbody', null, null, table);
    [T('Élément', 'Item', 'Elemento'), T('Importés', 'Imported', 'Importados'), T('Mis à jour', 'Updated', 'Actualizados'), T('Ignorés', 'Skipped', 'Omitidos')].forEach(function (h, i) { el('th', h, i ? 'num' : '', head); });
    rows.forEach(function (row) {
      var k = c[row[0]]; if (!k || !(k.imported || k.updated || k.skipped)) return;
      var tr = el('tr', null, null, body);
      el('td', row[1], null, tr); el('td', num(k.imported), 'num', tr); el('td', num(k.updated), 'num', tr); el('td', num(k.skipped), 'num', tr);
    });

    // Adresses conservées
    var u = r.urls || {};
    if (u.total) {
      el('p', num(u.identical) + ' / ' + num(u.total) + ' ' + T('adresses identiques à WordPress', 'URLs identical to WordPress', 'direcciones idénticas a WordPress')
        + (u.redirected ? ', ' + num(u.redirected) + ' ' + T('redirigées automatiquement (301)', 'redirected automatically (301)', 'redirigidas automáticamente (301)') : '') + '.', 'status ' + ((u.conflicts || []).length ? 'is-warn' : 'is-good'));
      (u.conflicts || []).slice(0, 10).forEach(function (x) { el('p', '⚠ ' + x.path + ' : ' + x.reason, 'small'); });
    }

    // Contenus à revoir
    if ((r.review || []).length) {
      el('h3', T('À revoir', 'To review', 'Por revisar') + ' (' + r.review.length + ')');
      var ul = el('ul', null, 'small');
      r.review.slice(0, 50).forEach(function (it) {
        var li = el('li', null, null, ul), a = el('a', it.title || it.path, null, li);
        a.href = WL.base + '/content/' + it.id;
        if ((it.issues || []).length) el('span', ' — ' + it.issues.join(' '), 'muted', li);
      });
    }

    // Fichiers non récupérés, remarques
    var failed = (r.media && r.media.failed) || [];
    if (failed.length) {
      el('p', '⚠ ' + failed.length + ' ' + T('fichier(s) non récupéré(s) :', 'file(s) not retrieved:', 'archivo(s) no recuperado(s):'), 'small');
      failed.slice(0, 8).forEach(function (f) { el('p', f.url + (f.error ? ' (' + f.error + ')' : ''), 'small muted mono'); });
    }
    (r.warnings || []).slice(0, 20).forEach(function (w) {
      if (typeof w === 'string') { el('p', '⚠ ' + w, 'small'); return; }
      el('p', '⚠ ' + w.message + (w.count > 1 ? ' (' + w.count + ')' : '') + ((w.examples || []).length ? ' : ' + w.examples.slice(0, 3).join(', ') : ''), 'small');
    });
    (r.notices || []).slice(0, 10).forEach(function (n) { el('p', n, 'small muted'); });

    // Prochaines étapes
    var steps = r.next_steps || [];
    if (steps.length) {
      el('h3', T('Prochaines étapes', 'Next steps', 'Próximos pasos'));
      var ol = el('ol', null, 'small');
      steps.forEach(function (s) { el('li', s, null, ol); });
    }
    var links = el('div', null, 'actions-row');
    [[T('Voir le site', 'View site', 'Ver el sitio'), WL.base.replace(/\/[^/]+$/, '/'), 'btn btn-primary btn-sm'], [T('Voir les articles', 'View posts', 'Ver artículos'), WL.base + '/content?type=post', 'btn btn-sm'], [T('Choisir le design', 'Pick a design', 'Elegir el diseño'), WL.base + '/design', 'btn btn-sm']].forEach(function (l) {
      var a = el('a', l[0], l[2], links); a.href = l[1];
    });
    var rep = document.getElementById('import-report');
    rep.hidden = false;
    rep.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  function loop(job) {
    render(job);
    if (job.error) { statusEl.textContent = job.error; WL.toast(job.error, 'bad'); return; }
    if (job.finished) { statusEl.textContent = T('Terminé', 'Done', 'Terminado'); report(job); return; }
    statusEl.textContent = T('En cours…', 'Running…', 'En curso…');
    WL.api('api/import/step', { id: job.id }).then(function (r) { loop(r.job); }).catch(function (e) {
      statusEl.textContent = e.message;
      logEl.textContent += '\n' + T('Interruption : nouvelle tentative dans 3 s…', 'Interrupted: retrying in 3 s…', 'Interrupción: reintento en 3 s…');
      setTimeout(function () { loop(job); }, 3000);
    });
  }
  // Migration interrompue : reprise là où elle s'était arrêtée (l'état est conservé sur le serveur).
  var resumeBox = document.getElementById('import-resume');
  if (resumeBox) {
    resumeBox.addEventListener('click', function (e) {
      var go = e.target.closest('[data-resume]'), drop = e.target.closest('[data-discard]');
      if (go) {
        resumeBox.hidden = true;
        form.querySelector('[type=submit]').disabled = true;
        document.getElementById('import-progress').hidden = false;
        statusEl.textContent = T('Reprise…', 'Resuming…', 'Reanudando…');
        WL.api('api/import/step', { id: go.dataset.resume }).then(function (r) { loop(r.job); })
          .catch(function (err) { statusEl.textContent = ''; WL.toast(err.message, 'bad'); resumeBox.hidden = false; form.querySelector('[type=submit]').disabled = false; });
      } else if (drop) {
        WL.api('api/import/cancel', { id: drop.dataset.discard }).then(function () { resumeBox.hidden = true; })
          .catch(function (err) { WL.toast(err.message, 'bad'); });
      }
    });
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = form.querySelector('[type=submit]');
    if (form.elements.import_method.value === 'rest' && !form.elements.url.value.trim()) { WL.toast(T('Indiquez l’adresse de votre site WordPress.', 'Enter your WordPress site URL.', 'Indica la dirección de tu WordPress.'), 'bad'); return; }
    WL.busy(btn, true);
    document.getElementById('import-progress').hidden = false;
    statusEl.textContent = T('Analyse du site…', 'Analyzing site…', 'Analizando el sitio…');
    WL.api('api/import/start', new FormData(form)).then(function (r) { WL.busy(btn, false); btn.disabled = true; loop(r.job); })
      .catch(function (err) { WL.busy(btn, false); statusEl.textContent = ''; WL.toast(err.message, 'bad'); });
  });
})();
