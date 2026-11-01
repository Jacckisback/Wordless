/* Wordless — administration : utilitaires communs (API, notifications, recherche rapide, thème). */
(function () {
  'use strict';
  var meta = function (n) { var m = document.querySelector('meta[name="' + n + '"]'); return m ? m.content : ''; };
  var WL = window.WL = window.WL || {};
  WL.base = meta('wl-admin');
  WL.csrf = meta('csrf-token');
  WL.lang = document.documentElement.lang || 'fr';
  WL.t = function (fr, en, es) { return WL.lang === 'en' ? (en || fr) : (WL.lang === 'es' ? (es || fr) : fr); };

  /** Appel JSON à l'API de l'admin. */
  WL.api = function (path, data, opts) {
    opts = opts || {};
    var isForm = typeof FormData !== 'undefined' && data instanceof FormData;
    return fetch(WL.base + '/' + path.replace(/^\//, ''), {
      method: opts.method || (data === undefined ? 'GET' : 'POST'),
      credentials: 'same-origin',
      headers: Object.assign({ 'X-CSRF-Token': WL.csrf, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, isForm || data === undefined ? {} : { 'Content-Type': 'application/json' }),
      body: data === undefined ? undefined : (isForm ? data : JSON.stringify(data)),
      signal: opts.signal
    }).then(function (r) {
      return r.text().then(function (txt) {
        var json = {};
        try { json = txt ? JSON.parse(txt) : {}; } catch (e) { json = { error: WL.t('Réponse inattendue du serveur.', 'Unexpected server response.', 'Respuesta inesperada del servidor.') }; }
        if (!r.ok) { var err = new Error(json.error || ('HTTP ' + r.status)); err.status = r.status; err.data = json; throw err; }
        return json;
      });
    });
  };

  /** Appel long (IA) : réponse NDJSON avec progression, puis résultat. */
  WL.stream = function (path, data, onProgress) {
    return fetch(WL.base + '/' + path.replace(/^\//, ''), {
      method: 'POST', credentials: 'same-origin',
      headers: { 'X-CSRF-Token': WL.csrf, 'Content-Type': 'application/json', 'Accept': 'application/x-ndjson', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(data)
    }).then(function (r) {
      if (!r.ok || !r.body) {
        return r.text().then(function (t) { var j = {}; try { j = JSON.parse(t); } catch (e) {} throw new Error(j.error || ('HTTP ' + r.status)); });
      }
      var reader = r.body.getReader(), dec = new TextDecoder(), buf = '', result = null, error = null;
      var pump = function () {
        return reader.read().then(function (chunk) {
          buf += dec.decode(chunk.value || new Uint8Array(), { stream: !chunk.done });
          var lines = buf.split('\n'); buf = lines.pop();
          lines.forEach(function (line) {
            if (!line.trim()) return;
            var ev; try { ev = JSON.parse(line); } catch (e) { return; }
            if (ev.type === 'progress' && onProgress) onProgress(ev);
            else if (ev.type === 'result') result = ev.data;
            else if (ev.type === 'error') error = ev.error;
          });
          if (chunk.done) { if (error) throw new Error(error); if (!result) throw new Error(WL.t('Réponse interrompue.', 'Response interrupted.', 'Respuesta interrumpida.')); return result; }
          return pump();
        });
      };
      return pump();
    });
  };

  WL.toast = function (msg, type) {
    var box = document.querySelector('.toasts');
    if (!box) return;
    var t = document.createElement('div');
    t.className = 'toast' + (type === 'bad' ? ' is-bad' : '');
    t.setAttribute('role', type === 'bad' ? 'alert' : 'status');
    t.textContent = msg;
    box.appendChild(t);
    setTimeout(function () { t.remove(); }, type === 'bad' ? 7000 : 3500);
  };

  WL.busy = function (btn, on) { if (!btn) return; btn.classList.toggle('is-busy', !!on); btn.disabled = !!on; };

  WL.copy = function (text, btn) {
    (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).then(function () {
      WL.toast(WL.t('Copié dans le presse-papiers', 'Copied to clipboard', 'Copiado'));
    }).catch(function () {
      var ta = document.createElement('textarea'); ta.value = text; document.body.appendChild(ta); ta.select();
      try { document.execCommand('copy'); WL.toast(WL.t('Copié', 'Copied', 'Copiado')); } catch (e) {}
      ta.remove();
    });
  };

  WL.escape = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };

  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-copy]');
    if (c) { e.preventDefault(); WL.copy(c.getAttribute('data-copy'), c); }
    var nav = e.target.closest('[data-nav-toggle]');
    if (nav) { document.body.classList.toggle('nav-open'); }
    else if (document.body.classList.contains('nav-open') && !e.target.closest('.sidebar')) { document.body.classList.remove('nav-open'); }
    var th = e.target.closest('[data-set-theme]');
    if (th) {
      var v = th.getAttribute('data-set-theme');
      try { if (v === 'auto') localStorage.removeItem('wl-theme'); else localStorage.setItem('wl-theme', v); } catch (err) {}
      if (v === 'auto') document.documentElement.removeAttribute('data-theme'); else document.documentElement.setAttribute('data-theme', v);
    }
  });

  // Confirmation des actions destructrices : <button data-confirm="…">
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var btn = e.submitter || f.querySelector('[type=submit]');
    var msg = (btn && btn.getAttribute('data-confirm')) || f.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
    if (btn && !f.hasAttribute('data-no-busy')) setTimeout(function () { WL.busy(btn, true); }, 0);
  }, true);

  // Listes déroulantes qui appliquent le filtre immédiatement
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-autosubmit]') && e.target.form) e.target.form.submit();
  });

  // Tableaux : case « tout sélectionner »
  document.addEventListener('change', function (e) {
    if (e.target.matches('[data-check-all]')) {
      var table = e.target.closest('table');
      table.querySelectorAll('tbody input[type=checkbox]').forEach(function (cb) { cb.checked = e.target.checked; });
    }
  });

  // Recherche rapide (Ctrl+K)
  var dlg = document.getElementById('cmdk');
  if (dlg) {
    var input = dlg.querySelector('input'), list = dlg.querySelector('ul'), items = [], sel = 0, timer;
    var pages = Array.prototype.map.call(document.querySelectorAll('.sidebar .nav a[href], [data-cmdk-pages] a[href]'), function (a) {
      return { label: a.textContent.trim(), href: a.href, group: WL.t('Aller à', 'Go to', 'Ir a') };
    }).concat([
      { label: WL.t('Écrire un article', 'Write a post', 'Escribir un artículo'), href: WL.base + '/content/new?type=post', group: WL.t('Action', 'Action', 'Acción') },
      { label: WL.t('Créer une page', 'Create a page', 'Crear una página'), href: WL.base + '/content/new?type=page', group: WL.t('Action', 'Action', 'Acción') }
    ]);
    var render = function () {
      list.innerHTML = '';
      items.forEach(function (it, i) {
        var li = document.createElement('li'), a = document.createElement('a');
        a.href = it.href; a.setAttribute('role', 'option'); a.setAttribute('aria-selected', i === sel ? 'true' : 'false');
        var s = document.createElement('span'); s.textContent = it.label; a.appendChild(s);
        var g = document.createElement('span'); g.className = 'muted small'; g.style.marginLeft = 'auto'; g.textContent = it.group; a.appendChild(g);
        li.appendChild(a); list.appendChild(li);
      });
    };
    var filter = function () {
      var q = input.value.trim().toLowerCase();
      items = pages.filter(function (p) { return !q || p.label.toLowerCase().indexOf(q) !== -1; }).slice(0, 8);
      sel = 0; render();
      clearTimeout(timer);
      if (q.length >= 2) {
        timer = setTimeout(function () {
          WL.api('api/content/search?q=' + encodeURIComponent(q)).then(function (res) {
            (res.items || []).forEach(function (c) { items.push({ label: c.title, href: WL.base + '/content/' + c.id, group: c.type_label }); });
            render();
          }).catch(function () {});
        }, 180);
      }
    };
    var open = function () { if (dlg.open) return; input.value = ''; filter(); dlg.showModal(); input.focus(); };
    WL.openSearch = open; // aussi depuis l'aperçu de l'éditeur (iframe)
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); dlg.open ? dlg.close() : open(); }
    });
    document.querySelectorAll('[data-cmdk]').forEach(function (b) { b.addEventListener('click', open); });
    input.addEventListener('input', filter);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { sel = Math.min(items.length - 1, sel + 1); render(); e.preventDefault(); }
      if (e.key === 'ArrowUp') { sel = Math.max(0, sel - 1); render(); e.preventDefault(); }
      if (e.key === 'Enter' && items[sel]) { location.href = items[sel].href; }
    });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  }

  // Les messages de confirmation disparaissent seuls
  var flash = document.querySelector('[data-flash]');
  if (flash && flash.classList.contains('notice--good')) setTimeout(function () { flash.style.transition = 'opacity .4s'; flash.style.opacity = '0'; setTimeout(function () { flash.remove(); }, 400); }, 5000);
})();
