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
    var r = job.report || {};
    var add = function (tag, text, cls) { var el = document.createElement(tag); el.textContent = text; if (cls) el.className = cls; box.appendChild(el); return el; };
    add('p', T('Migration terminée.', 'Migration complete.', 'Migración terminada.'), 'status is-good');
    if (r.summary) add('p', r.summary);
    if (r.counts) { var ul = add('ul', '', 'checklist small'); Object.keys(r.counts).forEach(function (k) { var c = r.counts[k]; var li = document.createElement('li'); li.textContent = k + ' : ' + (typeof c === 'object' ? JSON.stringify(c) : c); ul.appendChild(li); }); }
    (r.warnings || []).slice(0, 20).forEach(function (w) { add('p', '⚠ ' + (typeof w === 'string' ? w : JSON.stringify(w)), 'small muted'); });
    (r.next_steps || r.nextSteps || []).forEach(function (s) { add('p', '→ ' + s, 'small'); });
    var links = document.createElement('div'); links.className = 'actions-row';
    [[T('Voir les articles', 'View posts', 'Ver artículos'), WL.base + '/content?type=post'], [T('Voir le site', 'View site', 'Ver el sitio'), WL.base.replace(/\/[^/]+$/, '/')], [T('Choisir le design', 'Pick a design', 'Elegir el diseño'), WL.base + '/design']].forEach(function (l) {
      var a = document.createElement('a'); a.className = 'btn btn-sm'; a.href = l[1]; a.textContent = l[0]; links.appendChild(a);
    });
    box.appendChild(links);
    document.getElementById('import-report').hidden = false;
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
