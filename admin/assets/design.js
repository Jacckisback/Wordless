/* Page Design : directions, jetons, aperçu en direct, directeur artistique. */
(function () {
  'use strict';
  var T = WL.t;
  var D = JSON.parse(document.getElementById('design-data').textContent);
  var tk = JSON.parse(JSON.stringify(D.tokens));
  var frame = document.getElementById('design-preview');
  var $ = function (s) { return document.querySelector(s); };
  var $$ = function (s) { return Array.prototype.slice.call(document.querySelectorAll(s)); };
  var dirty = false;
  var b64 = function (o) { return btoa(unescape(encodeURIComponent(JSON.stringify(o)))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); };

  function lum(hex) {
    var c = hex.replace('#', ''); if (c.length === 3) c = c.split('').map(function (x) { return x + x; }).join('');
    var v = [0, 2, 4].map(function (i) { var s = parseInt(c.substr(i, 2), 16) / 255; return s <= 0.04045 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4); });
    return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2];
  }
  function ratio(a, b) { var x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
  var against = { text: 'bg', muted: 'bg', accent_text: 'accent', accent: 'bg' };

  function fill() {
    Object.keys(tk.colors).forEach(function (k) {
      var hex = $('[data-hex="' + k + '"]'), pick = $('[data-color="' + k + '"]');
      if (hex && document.activeElement !== hex) hex.value = tk.colors[k];
      if (pick) pick.value = tk.colors[k];
      var out = $('[data-contrast="' + k + '"]');
      if (out && against[k]) {
        var r = ratio(tk.colors[k], tk.colors[against[k]]);
        var min = k === 'accent' ? 3 : 4.5;
        out.textContent = r.toFixed(1) + ':1';
        out.className = 'counter ' + (r >= min ? 'is-good' : 'is-bad');
        out.title = r >= min ? T('Contraste suffisant (WCAG AA)', 'Sufficient contrast (WCAG AA)', 'Contraste suficiente') : T('Contraste insuffisant', 'Insufficient contrast', 'Contraste insuficiente');
      }
    });
    ['heading', 'body'].forEach(function (k) {
      var sel = $('[data-font="' + k + '"]');
      if (!sel.options.length) {
        Object.keys(D.fontsList).forEach(function (f) { var o = document.createElement('option'); o.value = f; o.textContent = D.fontsList[f]; sel.appendChild(o); });
      }
      sel.value = tk.fonts[k];
    });
    $$('[data-num]').forEach(function (r) { r.value = tk[r.dataset.num]; var o = $('[data-out="' + r.dataset.num + '"]'); if (o) o.textContent = tk[r.dataset.num] + ({ base: ' px', measure: ' car.', radius: ' px', width: ' rem', tracking: ' em' }[r.dataset.num] || ''); });
    $$('[data-sel]').forEach(function (s) { s.value = tk[s.dataset.sel]; });
    var css = $('#custom-css'); if (css && document.activeElement !== css) css.value = tk.custom_css || '';
    $$('[data-preset]').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.preset === tk.preset ? 'true' : 'false'); });
  }
  D.fontsList = {};
  Array.prototype.forEach.call(document.querySelectorAll('#gf-fallback option'), function (o) { D.fontsList[o.value] = o.textContent.replace(/^[^:]*:\s*/, ''); });
  Object.keys(tk.custom_fonts || {}).forEach(function (k) { D.fontsList[k] = tk.custom_fonts[k].family + ' ★'; });

  var previewSoon = (function () { var t; return function () { clearTimeout(t); t = setTimeout(preview, 350); }; })();
  function preview() {
    frame.src = WL.base + '/design/preview?path=' + encodeURIComponent($('#pv-path').value) + '&t=' + b64(tk);
  }
  var auditSoon = (function () { var t; return function () { clearTimeout(t); t = setTimeout(function () { WL.api('api/design/audit', tk).then(function (r) { findings(r.findings); }); }, 700); }; })();
  function changed() { dirty = true; fill(); previewSoon(); auditSoon(); }

  function findings(list) {
    var ul = $('#da-findings');
    ul.innerHTML = '';
    if (!list.length) {
      var li = document.createElement('li'); li.textContent = T('Aucun problème détecté. Vérifiez quand même le rendu sur mobile.', 'No issue found. Still check the mobile rendering.', 'Ningún problema detectado.');
      ul.appendChild(li); return;
    }
    list.forEach(function (f) {
      var li = document.createElement('li');
      var badge = document.createElement('span'); badge.className = 'prio prio-' + f.prio; badge.textContent = 'P' + f.prio;
      var box = document.createElement('div');
      var h = document.createElement('strong'); h.textContent = f.title; box.appendChild(h);
      var p = document.createElement('p'); p.className = 'muted small'; p.textContent = f.detail + ' ' + f.fix; box.appendChild(p);
      if (f.patch) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-sm'; b.textContent = T('Appliquer', 'Apply', 'Aplicar');
        b.addEventListener('click', function () {
          Object.keys(f.patch).forEach(function (path) { var ks = path.split('.'); if (ks.length === 2) tk[ks[0]][ks[1]] = f.patch[path]; else tk[path] = f.patch[path]; });
          changed();
        });
        box.appendChild(b);
      }
      li.appendChild(badge); li.appendChild(box);
      ul.appendChild(li);
    });
  }

  // Événements
  $$('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = JSON.parse(JSON.stringify(D.presets[b.dataset.preset]));
      ['colors', 'fonts', 'base', 'ratio', 'measure', 'radius', 'heading_weight', 'tracking', 'density', 'scheme'].forEach(function (k) { tk[k] = p[k]; });
      tk.preset = b.dataset.preset;
      changed();
    });
  });
  $$('[data-color]').forEach(function (i) { i.addEventListener('input', function () { tk.colors[i.dataset.color] = i.value; changed(); }); });
  $$('[data-hex]').forEach(function (i) { i.addEventListener('input', function () { if (/^#?[0-9a-f]{6}$/i.test(i.value)) { tk.colors[i.dataset.hex] = '#' + i.value.replace('#', '').toLowerCase(); changed(); } }); });
  $$('[data-font]').forEach(function (s) { s.addEventListener('change', function () { tk.fonts[s.dataset.font] = s.value; changed(); }); });
  $$('[data-num]').forEach(function (r) { r.addEventListener('input', function () { tk[r.dataset.num] = parseFloat(r.value); changed(); }); });
  $$('[data-sel]').forEach(function (s) { s.addEventListener('change', function () { tk[s.dataset.sel] = s.value; changed(); }); });
  var css = $('#custom-css'); if (css) css.addEventListener('input', function () { tk.custom_css = css.value; dirty = true; previewSoon(); });
  $('#pv-path').addEventListener('change', preview);
  $$('[data-pv]').forEach(function (b) {
    b.addEventListener('click', function () {
      $$('[data-pv]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      frame.style.width = b.dataset.pv === 'mobile' ? '390px' : '100%';
    });
  });
  $$('[data-cmd]').forEach(function (b) {
    b.addEventListener('click', function () {
      WL.busy(b, true);
      WL.api('api/design/direction', { command: b.dataset.cmd, tokens: tk }).then(function (r) { WL.busy(b, false); tk = r.tokens; changed(); findings(r.findings); })
        .catch(function (e) { WL.busy(b, false); WL.toast(e.message, 'bad'); });
    });
  });
  var gf = $('#gf-install');
  if (gf) gf.addEventListener('click', function () {
    WL.busy(gf, true);
    WL.api('api/design/font', { family: $('#gf-family').value, fallback: $('#gf-fallback').value }).then(function (r) {
      WL.busy(gf, false);
      D.fontsList[r.key] = r.label;
      tk.custom_fonts = tk.custom_fonts || {};
      ['heading', 'body'].forEach(function (k) { var sel = $('[data-font="' + k + '"]'); var o = document.createElement('option'); o.value = r.key; o.textContent = r.label; sel.appendChild(o); });
      tk.fonts.heading = r.key;
      WL.toast(T('Police installée et appliquée aux titres.', 'Font installed and applied to headings.', 'Fuente instalada.'));
      return WL.api('api/design/save', tk).then(function () { location.reload(); });
    }).catch(function (e) { WL.busy(gf, false); WL.toast(e.message, 'bad'); });
  });
  var dir = $('#da-direction');
  if (dir) dir.addEventListener('click', function () {
    var brief = $('#da-brief').value.trim();
    if (!brief) { WL.toast(T('Décrivez votre activité et vos goûts.', 'Describe your business and taste.', 'Describe tu actividad.'), 'bad'); return; }
    WL.busy(dir, true);
    WL.api('api/design/direction', { brief: brief, tokens: tk }).then(function (r) {
      WL.busy(dir, false);
      var tokens = r.tokens; tokens.preset = tk.preset; tk = tokens;
      var res = $('#da-result'); res.hidden = false; res.textContent = '';
      var h = document.createElement('strong'); h.textContent = r.name; res.appendChild(h);
      var p = document.createElement('p'); p.textContent = r.rationale; res.appendChild(p);
      var n = document.createElement('p'); n.className = 'muted small'; n.textContent = T('Aperçu appliqué. Cliquez « Enregistrer le design » pour la mettre en ligne.', 'Preview applied. Click “Save design” to publish it.', 'Vista previa aplicada.'); res.appendChild(n);
      changed(); findings(r.findings);
    }).catch(function (e) { WL.busy(dir, false); WL.toast(e.message, 'bad'); });
  });
  var crit = $('#da-critique');
  if (crit) crit.addEventListener('click', function () {
    WL.busy(crit, true);
    var rep = $('#da-report');
    WL.stream('api/design/critique', { task: 'critique', id: +$('#da-page').value }).then(function (r) {
      WL.busy(crit, false); rep.hidden = false; rep.textContent = '';
      var add = function (tag, text, cls) { var el = document.createElement(tag); el.textContent = text; if (cls) el.className = cls; rep.appendChild(el); return el; };
      if (r.score !== null && r.score !== undefined) add('p', T('Score de conception : ', 'Design score: ', 'Puntuación: ') + r.score + '/100', 'score ' + (r.score >= 80 ? 'is-good' : (r.score >= 50 ? 'is-warn' : 'is-bad')));
      add('p', r.verdict); add('p', r.impression, 'muted small');
      if (r.strengths && r.strengths.length) { add('h3', T('Points forts', 'Strengths', 'Puntos fuertes')); var ul = add('ul', ''); ul.className = 'checklist'; r.strengths.forEach(function (s) { var li = document.createElement('li'); li.textContent = s; ul.appendChild(li); }); }
      add('h3', T('Priorités', 'Priorities', 'Prioridades'));
      (r.priorities || []).forEach(function (p) { var d = add('div', ''); d.className = 'stack-s'; var h = document.createElement('p'); h.innerHTML = '<span class="prio prio-' + p.prio.slice(1) + '">' + p.prio + '</span> '; h.appendChild(document.createTextNode(p.title)); d.appendChild(h); var w = document.createElement('p'); w.className = 'muted small'; w.textContent = p.why + ' → ' + p.fix; d.appendChild(w); });
      var tbl = document.createElement('details'); var s = document.createElement('summary'); s.textContent = T('Heuristiques de Nielsen', 'Nielsen heuristics', 'Heurísticas de Nielsen'); tbl.appendChild(s);
      (r.heuristics || []).forEach(function (h) { var row = document.createElement('p'); row.className = 'small'; row.textContent = h.name + ' : ' + (h.score === null ? 'n/a' : h.score + '/4') + ' — ' + h.note; tbl.appendChild(row); });
      rep.appendChild(tbl);
    }).catch(function (e) { WL.busy(crit, false); WL.toast(e.message, 'bad'); });
  });
  var save = document.getElementById('design-save');
  save.addEventListener('click', function () {
    WL.busy(save, true);
    WL.api('api/design/save', tk).then(function (r) { WL.busy(save, false); dirty = false; findings(r.findings); WL.toast(T('Design enregistré et publié.', 'Design saved and published.', 'Diseño guardado.')); })
      .catch(function (e) { WL.busy(save, false); WL.toast(e.message, 'bad'); });
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  fill();
  findings(D.findings);
  preview();
})();
