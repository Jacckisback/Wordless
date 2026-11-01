/* Wordless — éditeur page builder.
   Modèle : liste de blocs {id, type, data}. L'aperçu (iframe) affiche le vrai thème ;
   les textes s'y modifient directement, le panneau de droite règle le reste. */
(function () {
  'use strict';
  var D = JSON.parse(document.getElementById('wl-editor-data').textContent);
  var T = WL.t;
  var S = {
    c: D.content, blocks: D.content.blocks || [], sel: null, dirty: false, saving: false, ready: false,
    analysis: null, slugTouched: !!D.content.id, tab: 'block'
  };
  var H = { stack: [], pos: -1, timer: null }; // historique pour annuler / rétablir
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var el = function (tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      var v = attrs[k];
      if (v === null || v === undefined || v === false) return;
      if (k === 'text') n.textContent = v;
      else if (k === 'html') n.innerHTML = v;
      else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), v);
      else if (k === 'class') n.className = v;
      else n.setAttribute(k, v === true ? '' : v);
    });
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  };
  var svg = function (name) {
    return '<svg class="i" aria-hidden="true"><use href="' + D.iconUrl + '#' + name + '"/></svg>';
  };
  D.iconUrl = (document.querySelector('link[rel=stylesheet][href*="admin.css"]').href.split('admin.css')[0]) + 'icons.svg';
  var clone = function (o) { return JSON.parse(JSON.stringify(o)); };
  var uid = function () { return 'b' + Math.random().toString(16).slice(2, 12); };
  var frame = $('#ed-frame');
  var debounce = function (fn, ms) { var t; return function () { var a = arguments, s = this; clearTimeout(t); t = setTimeout(function () { fn.apply(s, a); }, ms); }; };

  // ------------------------------------------------------------------
  // Modèle
  // ------------------------------------------------------------------
  function idx(id) { for (var i = 0; i < S.blocks.length; i++) if (S.blocks[i].id === id) return i; return -1; }
  function block(id) { return S.blocks[idx(id)] || null; }
  function getPath(obj, path) { return path.split('.').reduce(function (o, k) { return o == null ? undefined : o[k]; }, obj); }
  function setPath(obj, path, val) {
    var ks = path.split('.'), last = ks.pop();
    var o = ks.reduce(function (o, k) { if (o[k] == null) o[k] = /^\d+$/.test(k) ? [] : {}; return o[k]; }, obj);
    o[last] = val;
  }
  function label(b) {
    var def = D.defs[b.type] || { label: b.type };
    var d = b.data || {};
    var txt = d.title || d.text || d.label || d.q || '';
    if (!txt && d.html) txt = d.html.replace(/<[^>]+>/g, ' ');
    if (!txt && d.items && d.items[0]) txt = d.items[0].title || d.items[0].q || d.items[0].text || d.items[0].name || '';
    return { title: def.label, snippet: String(txt).replace(/\s+/g, ' ').trim().slice(0, 60) };
  }

  function setState(text, cls) {
    var s = $('#ed-state');
    s.textContent = text;
    s.className = 'ed-state' + (cls ? ' ' + cls : '');
  }
  function dirty() {
    S.dirty = true;
    setState(T('Modifications non enregistrées', 'Unsaved changes', 'Cambios sin guardar'), 'is-dirty');
    analyzeSoon();
    saveLocal();
    recordSoon();
  }

  // ------------------------------------------------------------------
  // Historique : annuler / rétablir (une étape par action ou par pause de frappe)
  // ------------------------------------------------------------------
  function snapshot() { return JSON.stringify({ blocks: S.blocks, title: S.c.title || '' }); }
  function record() {
    clearTimeout(H.timer); H.timer = null;
    var s = snapshot();
    if (H.stack[H.pos] !== s) {
      H.stack = H.stack.slice(0, H.pos + 1);
      H.stack.push(s);
      if (H.stack.length > 100) H.stack.shift();
      H.pos = H.stack.length - 1;
    }
    histButtons();
  }
  function recordSoon() { clearTimeout(H.timer); H.timer = setTimeout(record, 600); histButtons(); }
  function histButtons() {
    var u = $('#ed-undo'), r = $('#ed-redo');
    if (u) u.disabled = !(H.pos > 0 || H.timer);
    if (r) r.disabled = !!H.timer || H.pos >= H.stack.length - 1;
  }
  function restore(s) {
    var st = JSON.parse(s);
    S.blocks = st.blocks; S.c.title = st.title;
    $('#ed-title').value = st.title;
    if (S.sel && idx(S.sel) < 0) S.sel = null;
    post({ type: 'select', id: S.sel }); // l'aperçu suit la sélection sans attendre le nouveau rendu
    S.dirty = true;
    setState(T('Modifications non enregistrées', 'Unsaved changes', 'Cambios sin guardar'), 'is-dirty');
    analyzeSoon(); saveLocal();
    renderAll(); outline(); inspector(); histButtons();
  }
  function undo() { if (H.timer) record(); if (H.pos > 0) { H.pos--; restore(H.stack[H.pos]); } }
  function redo() { if (H.timer) record(); if (H.pos < H.stack.length - 1) { H.pos++; restore(H.stack[H.pos]); } }
  var saveLocal = debounce(function () {
    try { localStorage.setItem('wl-draft-' + (S.c.id || 'new-' + S.c.type), JSON.stringify({ at: Date.now(), state: payload(S.c.status) })); } catch (e) {}
  }, 800);

  // ------------------------------------------------------------------
  // Aperçu (iframe)
  // ------------------------------------------------------------------
  function post(msg) { msg.source = 'wl-editor'; msg.lang = D.lang; if (frame.contentWindow) frame.contentWindow.postMessage(msg, location.origin); }
  var seq = 0;
  function labels() { return S.blocks.map(function (b) { return [b.id, (D.defs[b.type] || {}).label || b.type]; }); }
  function renderAll() {
    var my = ++seq;
    return WL.api('api/content/render', { blocks: S.blocks, content: { id: S.c.id, type: S.c.type, title: S.c.title } }).then(function (r) {
      if (my !== seq) return;
      mergeSanitized(r.blocks || []);
      post({ type: 'html', html: r.html, labels: labels() });
      sendTitle();
    }).catch(function (e) { WL.toast(e.message, 'bad'); });
  }
  function renderBlock(id) {
    var i = idx(id);
    if (i < 0) return;
    WL.api('api/content/render', { blocks: [S.blocks[i]], only: true, index: i, all: S.blocks, content: { id: S.c.id, type: S.c.type, title: S.c.title } }).then(function (r) {
      post({ type: 'block', id: id, html: r.html, label: (D.defs[S.blocks[i].type] || {}).label });
    }).catch(function (e) { WL.toast(e.message, 'bad'); });
  }
  var renderBlockSoon = debounce(renderBlock, 250);
  /** Récupère les ancres générées côté serveur sans écraser la saisie en cours. */
  function mergeSanitized(clean) {
    clean.forEach(function (cb) {
      var b = block(cb.id);
      if (b && b.type === 'heading' && cb.data && cb.data.anchor) b.data.anchor = cb.data.anchor;
    });
  }
  function sendTitle() {
    post({ type: 'title', title: S.c.title, hide: S.c.type === 'page' && S.blocks[0] && S.blocks[0].type === 'hero', placeholder: S.c.type === 'page' ? T('Titre de la page', 'Page title', 'Título de la página') : T('Titre de l’article', 'Post title', 'Título del artículo') });
  }

  window.addEventListener('message', function (e) {
    if (e.origin !== location.origin || e.source !== frame.contentWindow || !e.data || e.data.source !== 'wl-frame') return;
    var d = e.data;
    if (d.type === 'ready') { S.ready = true; renderAll(); }
    else if (d.type === 'select') { select(d.id, false); }
    else if (d.type === 'field') {
      var b = block(d.id);
      if (!b) return;
      setPath(b.data, d.field, d.value);
      dirty();
      outline();
      if (S.tab === 'block' && S.sel === d.id) syncInspectorValue(d.field, d.value);
    }
    else if (d.type === 'action') { act(d.action, d.id); }
    else if (d.type === 'title') { S.c.title = d.title; $('#ed-title').value = d.title; autoSlug(); dirty(); }
    else if (d.type === 'save') { save(); }
    else if (d.type === 'search') { if (WL.openSearch) WL.openSearch(); }
    else if (d.type === 'undo') { undo(); }
    else if (d.type === 'redo') { redo(); }
  });

  // ------------------------------------------------------------------
  // Sélection, insertion, déplacement
  // ------------------------------------------------------------------
  function select(id, scroll) {
    S.sel = id;
    post({ type: 'select', id: id, scroll: !!scroll });
    outline();
    if (S.tab !== 'block' && id) tab('block');
    inspector();
  }
  function insert(type, data) {
    var def = D.defs[type];
    var b = { id: uid(), type: type, data: Object.assign(clone(def.defaults), data || {}) };
    var at = S.sel ? idx(S.sel) + 1 : S.blocks.length;
    S.blocks.splice(at, 0, b);
    S.sel = b.id;
    dirty();
    renderAll().then(function () { post({ type: 'select', id: b.id, scroll: true }); });
    outline();
    inspector();
    closePanels();
  }
  function act(action, id) {
    var i = idx(id);
    if (i < 0) return;
    if (action === 'up' && i > 0) { S.blocks.splice(i - 1, 0, S.blocks.splice(i, 1)[0]); }
    else if (action === 'down' && i < S.blocks.length - 1) { S.blocks.splice(i + 1, 0, S.blocks.splice(i, 1)[0]); }
    else if (action === 'duplicate') { var copy = clone(S.blocks[i]); copy.id = uid(); S.blocks.splice(i + 1, 0, copy); S.sel = copy.id; }
    else if (action === 'delete') {
      if (!confirm(T('Supprimer ce bloc ?', 'Delete this block?', '¿Eliminar este bloque?'))) return;
      S.blocks.splice(i, 1);
      S.sel = S.blocks[Math.min(i, S.blocks.length - 1)] ? S.blocks[Math.min(i, S.blocks.length - 1)].id : null;
    } else return;
    dirty();
    renderAll();
    outline();
    inspector();
  }

  // ------------------------------------------------------------------
  // Panneau gauche : bibliothèque et structure
  // ------------------------------------------------------------------
  function library(filter) {
    var box = $('#ed-library');
    box.innerHTML = '';
    filter = (filter || '').toLowerCase();
    Object.keys(D.groups).forEach(function (g) {
      var items = Object.keys(D.defs).filter(function (t) {
        var d = D.defs[t];
        if (d.group !== g || (t === 'html' && !D.caps.admin)) return false;
        return !filter || (d.label + ' ' + (d.description || '')).toLowerCase().indexOf(filter) !== -1;
      });
      if (!items.length) return;
      var grid = el('div', { class: 'lib-grid' });
      items.forEach(function (t) {
        var d = D.defs[t];
        var b = el('button', { type: 'button', class: 'lib-item', title: d.description || '', onclick: function () { insert(t); } });
        b.innerHTML = svg(d.icon || 'text');
        b.appendChild(el('span', { text: d.label }));
        b.appendChild(el('small', { text: d.description || '' }));
        grid.appendChild(b);
      });
      box.appendChild(el('div', { class: 'lib-group' }, [el('h3', { text: D.groups[g] }), grid]));
    });
  }
  $('#ed-block-search').addEventListener('input', function (e) { library(e.target.value); });

  var dragFrom = null;
  function outline() {
    var ol = $('#ed-outline');
    ol.innerHTML = '';
    if (!S.blocks.length) { ol.appendChild(el('li', { class: 'muted small', text: T('Aucun bloc pour l’instant.', 'No blocks yet.', 'Aún no hay bloques.') })); return; }
    S.blocks.forEach(function (b, i) {
      var l = label(b);
      var li = el('li', { class: b.id === S.sel ? 'is-selected' : '', draggable: 'true', 'data-i': i });
      var grip = el('span', { class: 'grip', title: T('Glisser pour déplacer', 'Drag to move', 'Arrastra para mover') }); grip.innerHTML = svg('grip');
      var lbl = el('span', { class: 'lbl', tabindex: '0', role: 'button', onclick: function () { select(b.id, true); }, onkeydown: function (e) { if (e.key === 'Enter') select(b.id, true); } }, [el('strong', { text: l.title }), el('span', { text: l.snippet })]);
      var acts = el('span', { class: 'acts' });
      [['up', 'arrow-up', T('Monter', 'Up', 'Subir')], ['down', 'arrow-down', T('Descendre', 'Down', 'Bajar')], ['duplicate', 'copy', T('Dupliquer', 'Duplicate', 'Duplicar')], ['delete', 'trash', T('Supprimer', 'Delete', 'Eliminar')]].forEach(function (a) {
        var btn = el('button', { type: 'button', title: a[2], 'aria-label': a[2], onclick: function () { act(a[0], b.id); } }); btn.innerHTML = svg(a[1]); acts.appendChild(btn);
      });
      li.appendChild(grip); li.appendChild(lbl); li.appendChild(acts);
      li.addEventListener('dragstart', function (e) { dragFrom = i; e.dataTransfer.effectAllowed = 'move'; });
      li.addEventListener('dragover', function (e) { e.preventDefault(); li.classList.add('is-drag-over'); });
      li.addEventListener('dragleave', function () { li.classList.remove('is-drag-over'); });
      li.addEventListener('drop', function (e) {
        e.preventDefault(); li.classList.remove('is-drag-over');
        if (dragFrom === null || dragFrom === i) return;
        var moved = S.blocks.splice(dragFrom, 1)[0];
        S.blocks.splice(dragFrom < i ? i - 1 : i, 0, moved);
        dragFrom = null; dirty(); renderAll(); outline();
      });
      ol.appendChild(li);
    });
  }

  // ------------------------------------------------------------------
  // Onglets
  // ------------------------------------------------------------------
  function tab(name) {
    S.tab = name;
    $$('.ed-right [role=tab]').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === name ? 'true' : 'false'); });
    $$('.ed-right [data-panel]').forEach(function (p) { p.hidden = p.dataset.panel !== name; });
    if (name === 'block') inspector();
    if (name === 'page') pagePanel();
    if (name === 'seo') seoPanel();
    if (name === 'ai') aiPanel();
  }
  $$('.ed-right [role=tab]').forEach(function (b) { b.addEventListener('click', function () { tab(b.dataset.tab); }); });
  $$('.ed-left [role=tab]').forEach(function (b) {
    b.addEventListener('click', function () {
      $$('.ed-left [role=tab]').forEach(function (x) { x.setAttribute('aria-selected', x === b ? 'true' : 'false'); });
      $$('.ed-left [data-panel]').forEach(function (p) { p.hidden = p.dataset.panel !== b.dataset.tab; });
      if (b.dataset.tab === 'outline') outline();
    });
  });
  $$('[data-toggle-panel]').forEach(function (b) {
    b.addEventListener('click', function () { var p = b.dataset.togglePanel === 'left' ? $('#ed-left') : $('#ed-right'); p.classList.toggle('is-open'); });
  });
  function closePanels() { $('#ed-left').classList.remove('is-open'); }

  // ------------------------------------------------------------------
  // Champs de formulaire génériques
  // ------------------------------------------------------------------
  function counter(input, min, max) {
    var c = el('span', { class: 'counter' });
    var upd = function () {
      var n = input.value.length;
      c.textContent = n + ' / ' + max;
      c.className = 'counter ' + (n === 0 ? '' : (n >= min && n <= max ? 'is-good' : (n > max ? 'is-bad' : 'is-warn')));
    };
    input.addEventListener('input', upd); upd();
    return c;
  }
  function mediaBox(m) {
    var box = el('div', { class: 'media-thumb' });
    if (m) box.appendChild(el('img', { src: m.thumb || m.url, alt: '' }));
    return box;
  }
  /** Construit un champ d'après sa définition. onChange(valeur, {render:bool}) */
  function field(def, value, name, onChange) {
    var id = 'f-' + name.replace(/[^a-z0-9]/gi, '-') + '-' + Math.random().toString(36).slice(2, 6);
    var wrap = el('div', { class: 'field' });
    var lbl = el('label', { for: id, text: def.label || name });
    var help = def.help ? el('p', { class: 'hint', text: def.help }) : null;
    var input;
    switch (def.type) {
      case 'textarea':
      case 'code':
        input = el('textarea', { id: id, rows: def.type === 'code' ? 8 : 3, class: def.type === 'code' ? 'mono' : '' });
        input.value = value || '';
        input.addEventListener('input', function () { onChange(input.value, { render: true }); });
        break;
      case 'rich':
        lbl = el('span', { class: 'label', text: def.label || name });
        var tools = el('div', { class: 'rich-tools' });
        input = el('div', { class: 'rich-input', contenteditable: 'true', role: 'textbox', 'aria-multiline': 'true', 'aria-label': def.label || name });
        input.innerHTML = value || '';
        [['bold', 'B'], ['italic', 'I'], ['createLink', '🔗'], ['insertUnorderedList', '•']].forEach(function (c) {
          tools.appendChild(el('button', { type: 'button', text: c[1], onmousedown: function (e) { e.preventDefault(); }, onclick: function () {
            if (c[0] === 'createLink') { var u = prompt('URL', 'https://'); if (u) document.execCommand('createLink', false, u); } else document.execCommand(c[0]);
            onChange(input.innerHTML, { render: true });
          } }));
        });
        input.addEventListener('input', function () { onChange(input.innerHTML, { render: true }); });
        wrap.appendChild(lbl); wrap.appendChild(tools); wrap.appendChild(input);
        wrap.appendChild(el('p', { class: 'hint', text: T('Astuce : vous pouvez aussi écrire directement dans l’aperçu.', 'Tip: you can also type directly in the preview.', 'Consejo: también puedes escribir directamente en la vista previa.') }));
        input.dataset.field = name;
        return wrap;
      case 'select':
        input = el('select', { id: id });
        Object.keys(def.options).forEach(function (k) { var o = el('option', { value: k, text: def.options[k] }); if (String(value) === k) o.selected = true; input.appendChild(o); });
        input.addEventListener('change', function () { onChange(input.value, { render: true }); });
        break;
      case 'toggle':
        var sw = el('label', { class: 'switch' });
        input = el('input', { type: 'checkbox', id: id });
        input.checked = !!value;
        input.addEventListener('change', function () { onChange(input.checked, { render: true }); });
        sw.appendChild(el('span', { class: 'switch-text' }, [el('span', { class: 'label', text: def.label })])); sw.appendChild(input);
        wrap.appendChild(sw);
        if (help) wrap.appendChild(help);
        return wrap;
      case 'number':
        input = el('input', { type: 'number', id: id, min: def.min, max: def.max });
        input.value = value == null ? '' : value;
        input.addEventListener('input', function () { onChange(input.value === '' ? null : +input.value, { render: true }); });
        break;
      case 'media':
        lbl = el('span', { class: 'label', text: def.label || name });
        var row = el('div', { class: 'media-field' });
        var m = value ? (S.mediaCache[value] || { id: value, url: '', thumb: '' }) : null;
        var thumbBox = mediaBox(m && m.url ? m : null);
        row.appendChild(thumbBox);
        row.appendChild(el('button', { type: 'button', class: 'btn btn-sm', text: value ? T('Changer', 'Change', 'Cambiar') : T('Choisir', 'Choose', 'Elegir'), onclick: function () {
          WLMedia.pick({ type: 'image' }).then(function (ms) { if (!ms[0]) return; S.mediaCache[ms[0].id] = ms[0]; onChange(ms[0].id, { render: true, rebuild: true }); });
        } }));
        if (value) row.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-ghost', text: T('Retirer', 'Remove', 'Quitar'), onclick: function () { onChange(null, { render: true, rebuild: true }); } }));
        if (value && !S.mediaCache[value]) fetchMedia(value, function (mm) { if (mm) { thumbBox.replaceWith(mediaBox(mm)); } });
        wrap.appendChild(lbl); wrap.appendChild(row); if (help) wrap.appendChild(help);
        return wrap;
      case 'medias':
        lbl = el('span', { class: 'label', text: def.label || name });
        var list = el('div', { class: 'medias-field' });
        (value || []).forEach(function (mid, i) {
          var b = mediaBox(S.mediaCache[mid]);
          if (!S.mediaCache[mid]) fetchMedia(mid, function (mm) { if (mm) b.appendChild(el('img', { src: mm.thumb || mm.url, alt: '' })); });
          b.appendChild(el('button', { type: 'button', title: T('Retirer', 'Remove', 'Quitar'), text: '✕', onclick: function () { var v = (value || []).slice(); v.splice(i, 1); onChange(v, { render: true, rebuild: true }); } }));
          list.appendChild(b);
        });
        wrap.appendChild(lbl); wrap.appendChild(list);
        wrap.appendChild(el('button', { type: 'button', class: 'btn btn-sm', text: T('Ajouter des images', 'Add images', 'Añadir imágenes'), onclick: function () {
          WLMedia.pick({ multiple: true, type: 'image' }).then(function (ms) { ms.forEach(function (mm) { S.mediaCache[mm.id] = mm; }); onChange((value || []).concat(ms.map(function (mm) { return mm.id; })), { render: true, rebuild: true }); });
        } }));
        return wrap;
      case 'term':
        input = el('select', { id: id });
        input.appendChild(el('option', { value: '', text: T('Toutes', 'All', 'Todas') }));
        D.categories.forEach(function (c) { var o = el('option', { value: c.id, text: c.name }); if (+value === c.id) o.selected = true; input.appendChild(o); });
        input.addEventListener('change', function () { onChange(input.value ? +input.value : null, { render: true }); });
        break;
      case 'list':
        return listField(def, value || [], name, onChange);
      case 'url':
        input = el('input', { type: 'text', id: id, inputmode: 'url', placeholder: '/contact  ·  https://…', list: 'ed-links' });
        input.value = value || '';
        input.addEventListener('input', function () { onChange(input.value, { render: true }); linkSuggest(input.value); });
        break;
      case 'email':
        input = el('input', { type: 'email', id: id });
        input.value = value || '';
        input.addEventListener('input', function () { onChange(input.value, { render: false }); });
        break;
      default:
        input = el('input', { type: 'text', id: id });
        input.value = value == null ? '' : value;
        input.addEventListener('input', function () { onChange(input.value, { render: true }); });
    }
    input.dataset.field = name;
    wrap.appendChild(lbl); wrap.appendChild(input); if (help) wrap.appendChild(help);
    return wrap;
  }

  function listField(def, items, name, onChange) {
    var wrap = el('div', { class: 'field' });
    wrap.appendChild(el('span', { class: 'label', text: def.label }));
    var list = el('div', { class: 'list-field' });
    var commit = function (opts) { onChange(items, opts || { render: true }); };
    items.forEach(function (it, i) {
      var title = it.title || it.q || it.name || it.text || it.value || it.label || (def.item || '') + ' ' + (i + 1);
      var det = el('details', { class: 'list-item' });
      var sum = el('summary', {}, [el('span', { class: 'ttl', text: String(title).replace(/<[^>]+>/g, '') })]);
      [['up', 'arrow-up'], ['down', 'arrow-down'], ['dup', 'copy'], ['del', 'trash']].forEach(function (a) {
        var b = el('button', { type: 'button', 'aria-label': a[0], onclick: function (e) {
          e.preventDefault();
          if (a[0] === 'up' && i > 0) items.splice(i - 1, 0, items.splice(i, 1)[0]);
          else if (a[0] === 'down' && i < items.length - 1) items.splice(i + 1, 0, items.splice(i, 1)[0]);
          else if (a[0] === 'dup') { if (def.max && items.length >= def.max) return; items.splice(i + 1, 0, clone(it)); }
          else if (a[0] === 'del') items.splice(i, 1);
          commit({ render: true, rebuild: true });
        } });
        b.innerHTML = svg(a[1]);
        sum.appendChild(b);
      });
      det.appendChild(sum);
      var body = el('div', { class: 'list-body' });
      Object.keys(def.fields).forEach(function (k) {
        body.appendChild(field(def.fields[k], it[k], name + '.' + i + '.' + k, function (v, opts) { it[k] = v; commit(opts); }));
      });
      det.appendChild(body);
      list.appendChild(det);
    });
    wrap.appendChild(list);
    if (!def.max || items.length < def.max) {
      wrap.appendChild(el('button', { type: 'button', class: 'btn btn-sm', text: '+ ' + T('Ajouter', 'Add', 'Añadir') + ' ' + (def.item || '').toLowerCase(), onclick: function () {
        var blank = {}; Object.keys(def.fields).forEach(function (k) { blank[k] = def.fields[k].type === 'toggle' ? false : (def.fields[k].type === 'media' ? null : ''); });
        items.push(blank); commit({ render: true, rebuild: true });
        setTimeout(function () { var d = $$('#ed-block .list-item'); if (d.length) d[d.length - 1].open = true; }, 30);
      } }));
    }
    return wrap;
  }

  S.mediaCache = {};
  [D.content.featured, D.content.og_image].forEach(function (m) { if (m) S.mediaCache[m.id] = m; });
  function fetchMedia(id, cb) {
    WL.api('api/media?id=' + id).then(function (r) { var m = r.items && r.items[0]; if (m) S.mediaCache[m.id] = m; cb(m); }).catch(function () { cb(null); });
  }
  var linkList = el('datalist', { id: 'ed-links' });
  document.body.appendChild(linkList);
  var linkSuggest = debounce(function (q) {
    if (!q || q.length < 2 || /^https?:/.test(q)) return;
    WL.api('api/content/search?q=' + encodeURIComponent(q.replace(/^\//, ''))).then(function (r) {
      linkList.innerHTML = '';
      r.items.forEach(function (it) { linkList.appendChild(el('option', { value: it.path, text: it.title })); });
    }).catch(function () {});
  }, 200);

  // ------------------------------------------------------------------
  // Inspecteur du bloc
  // ------------------------------------------------------------------
  function inspector() {
    var box = $('#ed-block');
    if (S.tab !== 'block') return;
    box.innerHTML = '';
    var b = S.sel ? block(S.sel) : null;
    if (!b) {
      box.appendChild(el('div', { class: 'insp-empty' }, [
        el('p', { text: T('Cliquez sur un bloc dans l’aperçu pour le régler, ou ajoutez-en un depuis le panneau de gauche.', 'Click a block in the preview to edit it, or add one from the left panel.', 'Haz clic en un bloque de la vista previa para editarlo, o añade uno desde el panel izquierdo.') })
      ]));
      return;
    }
    var def = D.defs[b.type];
    var head = el('div', { class: 'insp-head' }); head.innerHTML = svg(def.icon || 'text'); head.appendChild(el('h2', { text: def.label }));
    box.appendChild(head);
    var fields = el('div', { class: 'insp-fields' });
    Object.keys(def.fields).forEach(function (k) {
      if (b.type === 'html' && !D.caps.admin) return;
      fields.appendChild(field(def.fields[k], b.data[k], k, function (v, opts) {
        b.data[k] = v;
        dirty();
        outline();
        if (opts && opts.render) renderBlockSoon(b.id);
        if (opts && opts.rebuild) inspector();
      }));
    });
    box.appendChild(fields);
    if (b.type === 'faq' || b.type === 'takeaways' || b.type === 'steps') {
      box.appendChild(el('p', { class: 'ed-tip', text: T('Ce bloc est balisé en données structurées (schema.org) : Google et les assistants IA le reprennent plus facilement.', 'This block ships structured data (schema.org): Google and AI assistants pick it up more easily.', 'Este bloque incluye datos estructurados (schema.org).') }));
    }
    if (b.type === 'text') {
      box.appendChild(el('p', { class: 'ed-tip', text: T('Écrivez directement dans l’aperçu. Sélectionnez un mot pour le mettre en gras, créer un lien ou un titre H2/H3.', 'Type directly in the preview. Select text to bold it, add a link or an H2/H3 heading.', 'Escribe directamente en la vista previa. Selecciona texto para darle formato.') }));
    }
  }
  /** Saisie dans l'aperçu => met à jour le champ correspondant de l'inspecteur. */
  function syncInspectorValue(path, value) {
    var input = $('#ed-block [data-field="' + path + '"]');
    if (!input || input === document.activeElement) return;
    if (input.classList.contains('rich-input')) input.innerHTML = value; else input.value = value;
  }

  // ------------------------------------------------------------------
  // Onglet Page / Article
  // ------------------------------------------------------------------
  function pagePanel() {
    var box = $('#ed-page');
    box.innerHTML = '';
    var f = el('div', { class: 'insp-fields' });
    var c = S.c;
    var st = el('div', { class: 'field' }, [el('span', { class: 'label', text: T('Statut', 'Status', 'Estado') }),
      el('p', {}, [el('span', { class: 'badge ' + (c.status === 'published' ? 'badge--good' : (c.status === 'scheduled' ? 'badge--accent' : '')), text: statusLabel(c.status) })])]);
    f.appendChild(st);
    var date = el('input', { type: 'datetime-local', id: 'pg-date' }); date.value = c.published_at || '';
    date.addEventListener('change', function () { c.published_at = date.value; dirty(); publishLabel(); });
    f.appendChild(el('div', { class: 'field' }, [el('label', { for: 'pg-date', text: T('Date de publication', 'Publish date', 'Fecha de publicación') }), date,
      el('p', { class: 'hint', text: T('Une date future programme la publication.', 'A future date schedules the post.', 'Una fecha futura programa la publicación.') })]));
    var slug = el('input', { type: 'text', id: 'pg-slug' }); slug.value = c.slug || '';
    slug.addEventListener('input', function () { c.slug = slug.value; S.slugTouched = true; dirty(); urlHint(); });
    var hint = el('p', { class: 'hint' });
    var urlHint = function () { hint.textContent = (c.is_home ? D.site.url + '/' : (c.url && c.slug === D.content.slug ? c.url : D.site.url + '/…/' + (slugify(c.slug || c.title) || '…') + (D.site.trailing ? '/' : ''))); };
    urlHint();
    f.appendChild(el('div', { class: 'field' }, [el('label', { for: 'pg-slug', text: T('Adresse (slug)', 'URL slug', 'Slug') }), slug, hint,
      c.id && c.status === 'published' ? el('p', { class: 'hint', text: T('Si vous la changez, l’ancienne adresse sera redirigée automatiquement (301).', 'If you change it, the old URL is redirected automatically (301).', 'Si la cambias, la antigua se redirige automáticamente (301).') }) : null]));
    if (c.type === 'page') {
      var par = el('select', { id: 'pg-parent' }); par.appendChild(el('option', { value: '', text: T('(aucune)', '(none)', '(ninguna)') }));
      D.pages.forEach(function (p) { var o = el('option', { value: p.id, text: p.title }); if (+c.parent_id === p.id) o.selected = true; par.appendChild(o); });
      par.addEventListener('change', function () { c.parent_id = par.value ? +par.value : null; dirty(); });
      f.appendChild(el('div', { class: 'field' }, [el('label', { for: 'pg-parent', text: T('Page parente', 'Parent page', 'Página madre') }), par]));
      var ord = el('input', { type: 'number', id: 'pg-order' }); ord.value = c.menu_order || 0;
      ord.addEventListener('input', function () { c.menu_order = +ord.value || 0; dirty(); });
      f.appendChild(el('div', { class: 'field' }, [el('label', { for: 'pg-order', text: T('Ordre', 'Order', 'Orden') }), ord]));
    } else {
      var cats = el('div', { class: 'cat-list' });
      D.categories.forEach(function (cat) {
        var cb = el('input', { type: 'checkbox', value: cat.id }); cb.checked = (c.categories || []).indexOf(cat.id) !== -1;
        cb.addEventListener('change', function () {
          c.categories = (c.categories || []).filter(function (x) { return x !== cat.id; });
          if (cb.checked) c.categories.push(cat.id);
          dirty();
        });
        cats.appendChild(el('label', { class: 'check' }, [cb, el('span', { text: cat.name })]));
      });
      f.appendChild(el('div', { class: 'field' }, [el('span', { class: 'label', text: T('Catégories', 'Categories', 'Categorías') }), cats]));
      var tags = el('input', { type: 'text', id: 'pg-tags', list: 'ed-tags', placeholder: T('séparées par des virgules', 'comma separated', 'separadas por comas') }); tags.value = (c.tags || []).join(', ');
      tags.addEventListener('input', function () { c.tags = tags.value.split(',').map(function (t) { return t.trim(); }).filter(Boolean); dirty(); });
      var dl = el('datalist', { id: 'ed-tags' }); D.tags.forEach(function (t) { dl.appendChild(el('option', { value: t })); });
      f.appendChild(el('div', { class: 'field' }, [el('label', { for: 'pg-tags', text: T('Étiquettes', 'Tags', 'Etiquetas') }), tags, dl]));
      var sticky = el('input', { type: 'checkbox' }); sticky.checked = !!c.sticky;
      sticky.addEventListener('change', function () { c.sticky = sticky.checked ? 1 : 0; dirty(); });
      f.appendChild(el('label', { class: 'check' }, [sticky, el('span', { text: T('Épingler en tête du blog', 'Pin to top of blog', 'Fijar arriba del blog') })]));
    }
    f.appendChild(field({ type: 'media', label: T('Image à la une', 'Featured image', 'Imagen destacada'), help: T('Utilisée pour les partages (réseaux sociaux, Discover) et les listes.', 'Used for social sharing and lists.', 'Usada para compartir y listados.') }, c.featured_id, 'featured', function (v) {
      c.featured_id = v; dirty(); pagePanel();
    }));
    var ex = el('textarea', { id: 'pg-ex', rows: 3, maxlength: 1000 }); ex.value = c.excerpt || '';
    ex.addEventListener('input', function () { c.excerpt = ex.value; dirty(); });
    f.appendChild(el('div', { class: 'field' }, [el('label', { for: 'pg-ex', text: T('Résumé', 'Excerpt', 'Extracto') }), ex,
      el('p', { class: 'hint', text: T('Affiché dans les listes d’articles. Vide = début du texte.', 'Shown in post lists. Empty = start of text.', 'Se muestra en los listados.') })]));
    var com = el('input', { type: 'checkbox' }); com.checked = !!c.comments;
    com.addEventListener('change', function () { c.comments = com.checked ? 1 : 0; dirty(); });
    f.appendChild(el('label', { class: 'switch' }, [el('span', { class: 'switch-text' }, [el('span', { class: 'label', text: T('Commentaires ouverts', 'Comments open', 'Comentarios abiertos') })]), com]));
    box.appendChild(f);
    if (D.revisions.length) {
      var sec = el('div', { class: 'ed-section' }, [el('h3', { text: T('Versions précédentes', 'Previous versions', 'Versiones anteriores') })]);
      var ul = el('ul', { class: 'feed' });
      D.revisions.slice(0, 8).forEach(function (r) {
        ul.appendChild(el('li', {}, [el('span', { text: r.at + (r.by ? ' · ' + r.by : '') }), el('button', { type: 'button', class: 'btn btn-sm', text: T('Restaurer', 'Restore', 'Restaurar'), onclick: function () {
          if (!confirm(T('Remplacer le contenu actuel par cette version ? (la version actuelle reste dans l’historique)', 'Replace current content with this version?', '¿Reemplazar por esta versión?'))) return;
          WL.api('api/content/' + S.c.id + '/revision', { revision: r.id }).then(function () { S.dirty = false; location.reload(); }).catch(function (e) { WL.toast(e.message, 'bad'); });
        } })]));
      });
      sec.appendChild(ul);
      box.appendChild(sec);
    }
    if (c.id && D.caps.delete) {
      var trashForm = el('form', { method: 'post', action: WL.base + '/content/bulk', class: 'ed-section' });
      trashForm.innerHTML = '<input type="hidden" name="_csrf" value="' + WL.csrf + '"><input type="hidden" name="action" value="trash"><input type="hidden" name="ids[]" value="' + c.id + '"><input type="hidden" name="back" value="' + WL.escape(new URL(WL.base + '/content?type=' + c.type, location.href).pathname + '?type=' + c.type) + '">';
      var btn = el('button', { type: 'submit', class: 'btn btn-danger btn-sm', text: T('Mettre à la corbeille', 'Move to trash', 'Mover a la papelera'), 'data-confirm': T('Mettre ce contenu à la corbeille ?', 'Move to trash?', '¿Mover a la papelera?') });
      trashForm.appendChild(btn);
      trashForm.addEventListener('submit', function () { S.dirty = false; });
      box.appendChild(trashForm);
    }
  }
  function statusLabel(s) {
    return { published: T('Publié', 'Published', 'Publicado'), scheduled: T('Programmé', 'Scheduled', 'Programado'), private: T('Privé', 'Private', 'Privado'), trash: T('Corbeille', 'Trash', 'Papelera') }[s] || T('Brouillon', 'Draft', 'Borrador');
  }
  function slugify(s) {
    return String(s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/œ/g, 'oe').replace(/æ/g, 'ae').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
  }

  // ------------------------------------------------------------------
  // Onglet SEO & GEO
  // ------------------------------------------------------------------
  var analyzeSoon = debounce(analyze, 1200);
  function analyze() {
    WL.api('api/content/analyze', Object.assign(payload(S.c.status), { is_home: S.c.is_home, path: S.c.path, author_id: S.c.author_id })).then(function (r) {
      S.analysis = r;
      badge();
      if (S.tab === 'seo') renderChecks();
    }).catch(function () {});
  }
  function scoreClass(n) { return n >= 80 ? 'is-good' : (n >= 50 ? 'is-warn' : 'is-bad'); }
  function badge() {
    var b = $('#ed-score-badge');
    if (!S.analysis) { b.textContent = ''; b.className = 'ed-score'; return; }
    var n = Math.round((S.analysis.seo.score + S.analysis.geo.score) / 2);
    b.textContent = n; b.className = 'ed-score ' + scoreClass(n);
  }
  function seoPanel() {
    var box = $('#ed-seo');
    box.innerHTML = '';
    var seo = S.c.seo;
    var serp = el('div', { class: 'serp', 'aria-label': T('Aperçu dans Google', 'Google preview', 'Vista previa en Google') }, [
      el('div', { class: 'serp-site' }, [el('i'), el('span', { text: D.site.name }), el('span', { class: 'serp-url', id: 'serp-url' })]),
      el('span', { class: 'serp-title', id: 'serp-title' }), el('p', { class: 'serp-desc', id: 'serp-desc' })
    ]);
    box.appendChild(serp);
    var f = el('div', { class: 'insp-fields', style: 'margin-top:1rem' });
    var t = el('input', { type: 'text', id: 'seo-title', maxlength: 160, placeholder: T('Vide = titre de la page + nom du site', 'Empty = page title + site name', 'Vacío = título + nombre del sitio') }); t.value = seo.title || '';
    t.addEventListener('input', function () { seo.title = t.value; dirty(); serpUpdate(); });
    f.appendChild(el('div', { class: 'field' }, [el('div', { class: 'row-between' }, [el('label', { for: 'seo-title', text: T('Titre SEO', 'SEO title', 'Título SEO') }), counter(t, 30, 60)]), t]));
    var d = el('textarea', { id: 'seo-desc', rows: 3, maxlength: 400, placeholder: T('Ce qui s’affiche sous le titre dans Google. Donnez envie de cliquer.', 'Shown under the title in Google. Make people want to click.', 'Se muestra bajo el título en Google.') }); d.value = seo.description || '';
    d.addEventListener('input', function () { seo.description = d.value; dirty(); serpUpdate(); });
    f.appendChild(el('div', { class: 'field' }, [el('div', { class: 'row-between' }, [el('label', { for: 'seo-desc', text: T('Méta-description', 'Meta description', 'Meta descripción') }), counter(d, 120, 160)]), d]));
    var k = el('input', { type: 'text', id: 'seo-kw', maxlength: 120, placeholder: T('ex. cours de céramique Lyon', 'e.g. pottery class Lyon', 'p. ej. clases de cerámica Madrid') }); k.value = seo.keyword || '';
    k.addEventListener('input', function () { seo.keyword = k.value; dirty(); });
    f.appendChild(el('div', { class: 'field' }, [el('label', { for: 'seo-kw', text: T('Mot-clé principal', 'Focus keyword', 'Palabra clave principal') }), k]));
    if (D.ai && D.caps.ai) {
      f.appendChild(el('div', { class: 'actions-row' }, [
        aiButton(T('Rédiger titre et description', 'Write title & description', 'Redactar título y descripción'), 'seo_meta'),
        aiButton(T('Ajouter une FAQ', 'Add an FAQ', 'Añadir FAQ'), 'faq')
      ]));
    }
    box.appendChild(f);
    var sc = el('div', { class: 'scores', id: 'ed-scores' });
    box.appendChild(sc);
    box.appendChild(el('div', { id: 'ed-checks' }));
    var adv = el('details', { class: 'ed-section' });
    adv.appendChild(el('summary', { text: T('Réglages avancés', 'Advanced', 'Ajustes avanzados') }));
    var advBox = el('div', { class: 'insp-fields', style: 'margin-top:.75rem' });
    advBox.appendChild(field({ type: 'media', label: T('Image de partage (réseaux sociaux)', 'Social share image', 'Imagen para redes sociales'), help: T('Vide = image à la une.', 'Empty = featured image.', 'Vacío = imagen destacada.') }, seo.og_image, 'og', function (v) { seo.og_image = v; dirty(); seoPanel(); }));
    var noi = el('input', { type: 'checkbox' }); noi.checked = !!seo.noindex;
    noi.addEventListener('change', function () { seo.noindex = noi.checked; dirty(); });
    advBox.appendChild(el('label', { class: 'switch' }, [el('span', { class: 'switch-text' }, [el('span', { class: 'label', text: T('Masquer aux moteurs (noindex)', 'Hide from search engines (noindex)', 'Ocultar a buscadores (noindex)') }), el('span', { class: 'hint', text: T('Pour les pages de remerciement, mentions légales…', 'For thank-you pages, legal notices…', 'Para páginas de agradecimiento, avisos legales…') })]), noi]));
    var can = el('input', { type: 'url', id: 'seo-can', placeholder: 'https://…' }); can.value = seo.canonical || '';
    can.addEventListener('input', function () { seo.canonical = can.value; dirty(); });
    advBox.appendChild(el('div', { class: 'field' }, [el('label', { for: 'seo-can', text: T('URL canonique', 'Canonical URL', 'URL canónica') }), can, el('p', { class: 'hint', text: T('Seulement si ce contenu est republié depuis une autre adresse.', 'Only if this content is republished from another URL.', 'Solo si este contenido se publica desde otra URL.') })]));
    var types = { '': T('Automatique', 'Automatic', 'Automático'), Article: 'Article', BlogPosting: 'BlogPosting', NewsArticle: 'NewsArticle', WebPage: 'WebPage', AboutPage: T('À propos', 'About page', 'Acerca de'), ContactPage: 'Contact', Service: 'Service', Product: T('Produit', 'Product', 'Producto'), LocalBusiness: T('Commerce local', 'Local business', 'Negocio local'), Course: T('Formation', 'Course', 'Curso'), Event: T('Événement', 'Event', 'Evento') };
    advBox.appendChild(field({ type: 'select', label: T('Type de contenu (schema.org)', 'Content type (schema.org)', 'Tipo de contenido (schema.org)'), options: types }, seo.schema || '', 'schema', function (v) { seo.schema = v; dirty(); }));
    adv.appendChild(advBox);
    box.appendChild(adv);
    serpUpdate();
    renderChecks();
    if (!S.analysis) analyze();
  }
  function serpUpdate() {
    var p = (S.analysis && S.analysis.preview) || {};
    var title = S.c.seo.title || (S.c.title ? D.site.template.replace('{title}', S.c.title).replace('{sep}', D.site.sep).replace('{site}', D.site.name) : D.site.name);
    var desc = S.c.seo.description || p.description || '';
    if ($('#serp-title')) {
      $('#serp-title').textContent = title.length > 62 ? title.slice(0, 60) + '…' : title;
      $('#serp-desc').textContent = desc.length > 160 ? desc.slice(0, 157) + '…' : desc;
      $('#serp-url').textContent = (p.url || D.site.url).replace(/^https?:\/\//, '').replace(/\/$/, '').split('/').join(' › ');
    }
  }
  function renderChecks() {
    var sc = $('#ed-scores'), ch = $('#ed-checks');
    if (!sc || !S.analysis) return;
    sc.innerHTML = '';
    [['SEO', S.analysis.seo.score, T('Google', 'Google', 'Google')], ['GEO', S.analysis.geo.score, T('Moteurs IA', 'AI engines', 'Motores IA')]].forEach(function (s) {
      sc.appendChild(el('div', { class: 'scorebox' }, [el('span', { class: 'muted small', text: s[0] + ' · ' + s[2] }), el('div', {}, [el('b', { text: s[1] }), el('span', { class: 'muted', text: ' /100' })]),
        el('div', { class: 'meter ' + scoreClass(s[1]) }, [el('span', { style: 'width:' + s[1] + '%' })])]));
    });
    ch.innerHTML = '';
    [['seo', T('Référencement Google', 'Search (SEO)', 'Posicionamiento (SEO)')], ['geo', T('Visibilité dans les IA (GEO)', 'AI visibility (GEO)', 'Visibilidad en IA (GEO)')]].forEach(function (g) {
      ch.appendChild(el('h3', { text: g[1] }));
      var ul = el('ul', { class: 'checks' });
      var list = S.analysis[g[0]].checks.slice().sort(function (a, b) { var o = { bad: 0, warn: 1, good: 2 }; return o[a.status] - o[b.status]; });
      list.forEach(function (c) {
        ul.appendChild(el('li', { class: 'is-' + c.status }, [el('span', { class: 'dot', 'aria-hidden': 'true' }), el('span', {}, [
          el('span', { class: 'sr', text: { good: 'OK : ', warn: T('À améliorer : ', 'To improve: ', 'Mejorar: '), bad: T('Problème : ', 'Problem: ', 'Problema: ') }[c.status] }),
          document.createTextNode(c.label), c.hint && c.status !== 'good' ? el('span', { class: 'hint', text: c.hint }) : null])]));
      });
      ch.appendChild(ul);
    });
    serpUpdate();
  }

  // ------------------------------------------------------------------
  // Onglet IA
  // ------------------------------------------------------------------
  function aiButton(text, task, extra) {
    var b = el('button', { type: 'button', class: 'btn btn-sm', onclick: function () { runAi(task, extra || {}, b); } });
    b.innerHTML = svg('sparkles');
    b.appendChild(document.createTextNode(text));
    return b;
  }
  function aiPanel() {
    var box = $('#ed-ai');
    box.innerHTML = '';
    if (!D.ai) {
      box.appendChild(el('div', { class: 'notice' }, [el('p', {}, [document.createTextNode(T('Ajoutez votre clé Claude ou ChatGPT pour rédiger, optimiser et traduire en un clic. ', 'Add your Claude or ChatGPT key to write, optimize and translate in one click. ', 'Añade tu clave de Claude o ChatGPT. ')), el('a', { href: WL.base + '/ai', text: T('Configurer l’IA', 'Set up AI', 'Configurar la IA') })])]));
      return;
    }
    if (!D.caps.ai) { box.appendChild(el('p', { class: 'muted', text: T('Votre rôle ne permet pas d’utiliser l’IA.', 'Your role cannot use AI.', 'Tu rol no permite usar la IA.') })); return; }
    var brief = el('textarea', { id: 'ai-brief', rows: 4, placeholder: T('Sujet, public, points à couvrir, ton… ex. « Guide pour débuter la céramique à Lyon, pour adultes, ton chaleureux, 1200 mots »', 'Topic, audience, key points, tone…', 'Tema, público, puntos clave, tono…') });
    var kw = el('input', { type: 'text', id: 'ai-kw', placeholder: T('Mot-clé principal (facultatif)', 'Focus keyword (optional)', 'Palabra clave (opcional)') });
    kw.value = S.c.seo.keyword || '';
    box.appendChild(el('div', { class: 'insp-fields' }, [
      el('div', { class: 'field' }, [el('label', { for: 'ai-brief', text: T('Rédiger avec l’IA', 'Write with AI', 'Redactar con IA') }), brief]),
      el('div', { class: 'field' }, [el('label', { for: 'ai-kw', class: 'sr', text: 'keyword' }), kw]),
      el('div', { class: 'actions-row' }, [aiButton(T('Rédiger le contenu', 'Write the content', 'Redactar el contenido'), 'write', { get brief() { return brief.value; }, get keyword() { return kw.value; } })])
    ]));
    var sec = el('div', { class: 'ed-section' }, [el('h3', { text: T('Améliorer la page', 'Improve the page', 'Mejorar la página') })]);
    var acts = el('div', { class: 'ai-actions' });
    acts.appendChild(aiButton(T('Optimiser pour les IA (GEO)', 'Optimize for AI engines (GEO)', 'Optimizar para IA (GEO)'), 'geo'));
    acts.appendChild(aiButton(T('Ajouter « L’essentiel » en tête', 'Add key takeaways', 'Añadir lo esencial'), 'takeaways'));
    acts.appendChild(aiButton(T('Générer une FAQ', 'Generate an FAQ', 'Generar una FAQ'), 'faq'));
    acts.appendChild(aiButton(T('Titre et méta-description', 'Title & meta description', 'Título y meta descripción'), 'seo_meta'));
    acts.appendChild(aiButton(T('Textes alternatifs des images', 'Image alt texts', 'Textos alternativos'), 'alts'));
    sec.appendChild(acts);
    box.appendChild(sec);
    var sel = S.sel ? block(S.sel) : null;
    var sec2 = el('div', { class: 'ed-section' }, [el('h3', { text: T('Bloc sélectionné', 'Selected block', 'Bloque seleccionado') })]);
    if (sel && /text|hero|cta|faq|features|columns|steps|takeaways/.test(sel.type)) {
      var how = el('select', { id: 'ai-how' });
      [['clarify', T('Plus clair et direct', 'Clearer', 'Más claro')], ['shorten', T('Plus court', 'Shorter', 'Más corto')], ['expand', T('Plus développé', 'Longer', 'Más extenso')], ['warm', T('Ton plus chaleureux', 'Warmer tone', 'Tono más cálido')], ['pro', T('Ton plus professionnel', 'More professional', 'Más profesional')], ['fix', T('Corriger les fautes', 'Fix mistakes', 'Corregir errores')]].forEach(function (o) { how.appendChild(el('option', { value: o[0], text: o[1] })); });
      sec2.appendChild(el('div', { class: 'insp-fields' }, [el('div', { class: 'field' }, [el('label', { for: 'ai-how', text: (D.defs[sel.type] || {}).label }), how]),
        el('div', {}, [aiButton(T('Réécrire ce bloc', 'Rewrite this block', 'Reescribir este bloque'), 'rewrite', { get how() { return how.value; }, block: sel.id })])]));
    } else {
      sec2.appendChild(el('p', { class: 'muted small', text: T('Sélectionnez un bloc de texte dans l’aperçu pour le réécrire.', 'Select a text block in the preview to rewrite it.', 'Selecciona un bloque de texto para reescribirlo.') }));
    }
    box.appendChild(sec2);
    var sec3 = el('div', { class: 'ed-section' }, [el('h3', { text: T('Traduire', 'Translate', 'Traducir') })]);
    var lang = el('select', { id: 'ai-lang' });
    [['en', 'English'], ['es', 'Español'], ['fr', 'Français'], ['de', 'Deutsch'], ['it', 'Italiano'], ['pt', 'Português']].forEach(function (o) { lang.appendChild(el('option', { value: o[0], text: o[1] })); });
    sec3.appendChild(el('div', { class: 'row' }, [lang, aiButton(T('Créer la traduction', 'Create translation', 'Crear traducción'), 'translate', { get lang() { return lang.value; } })]));
    sec3.appendChild(el('p', { class: 'hint', text: T('Crée un brouillon traduit, relié à cette page (balises hreflang automatiques).', 'Creates a linked translated draft (automatic hreflang).', 'Crea un borrador traducido enlazado (hreflang automático).') }));
    box.appendChild(sec3);
    box.appendChild(el('div', { id: 'ai-out' }));
  }

  function runAi(task, extra, btn) {
    if (task === 'translate' && !S.c.id) { WL.toast(T('Enregistrez d’abord la page.', 'Save the page first.', 'Guarda la página primero.'), 'bad'); return; }
    var body = Object.assign({ task: task, state: payload(S.c.status) }, {});
    Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });
    if (task === 'write' && !String(body.brief || '').trim() && !S.c.title) { WL.toast(T('Décrivez le sujet ou donnez un titre.', 'Describe the topic or set a title.', 'Describe el tema o pon un título.'), 'bad'); return; }
    WL.busy(btn, true);
    setState(T('L’IA travaille…', 'AI is working…', 'La IA está trabajando…'));
    WL.stream('api/ai/run', body, function (p) {
      if (p.chars) setState(T('L’IA écrit… ', 'AI is writing… ', 'La IA escribe… ') + p.chars + T(' caractères', ' characters', ' caracteres'));
    }).then(function (r) {
      WL.busy(btn, false);
      setState(S.dirty ? T('Modifications non enregistrées', 'Unsaved changes', 'Cambios sin guardar') : '', S.dirty ? 'is-dirty' : '');
      applyAi(task, r, extra);
    }).catch(function (e) { WL.busy(btn, false); setState(e.message, 'is-error'); WL.toast(e.message, 'bad'); });
  }
  function applyAi(task, r, extra) {
    if (r.seo) { Object.assign(S.c.seo, r.seo); }
    if (r.title && (!S.c.title || task === 'write')) { S.c.title = r.title; $('#ed-title').value = r.title; autoSlug(); }
    if (r.excerpt && !S.c.excerpt) S.c.excerpt = r.excerpt;
    if (r.blocks && task === 'write') {
      if (S.blocks.length && S.blocks.some(function (b) { return label(b).snippet; }) && !confirm(T('Remplacer le contenu actuel par la proposition de l’IA ?', 'Replace current content with the AI draft?', '¿Reemplazar el contenido actual?'))) {
        S.blocks = S.blocks.concat(r.blocks);
      } else { S.blocks = r.blocks; }
    } else if (r.prepend) { S.blocks = r.prepend.concat(S.blocks); }
    else if (r.append) { S.blocks = S.blocks.concat(r.append); }
    else if (r.block && extra && extra.block) { var i = idx(extra.block); if (i >= 0) { r.block.id = extra.block; S.blocks[i] = r.block; } }
    else if (r.blocks && task === 'geo') { S.blocks = r.blocks; }
    if (r.alts) { WL.toast(T('Textes alternatifs mis à jour : ', 'Alt texts updated: ', 'Textos alternativos: ') + r.alts); }
    if (r.translation) { WL.toast(T('Traduction créée en brouillon.', 'Translation created as draft.', 'Traducción creada como borrador.')); window.open(WL.base + '/content/' + r.translation, '_blank'); return; }
    dirty();
    renderAll();
    outline();
    if (S.tab === 'seo') seoPanel();
    if (r.note) { var out = $('#ai-out'); if (out) { out.innerHTML = ''; out.appendChild(el('div', { class: 'ai-result', text: r.note })); } }
    WL.toast(T('Proposition de l’IA appliquée. Relisez avant de publier.', 'AI suggestion applied. Review before publishing.', 'Propuesta aplicada. Revisa antes de publicar.'));
  }

  // ------------------------------------------------------------------
  // Enregistrement
  // ------------------------------------------------------------------
  function payload(status) {
    var c = S.c;
    return { id: c.id, type: c.type, title: c.title, slug: c.slug, status: status, blocks: S.blocks, seo: c.seo, excerpt: c.excerpt, featured_id: c.featured_id,
      parent_id: c.parent_id, comments: c.comments, sticky: c.sticky, menu_order: c.menu_order, template: c.template, lang: c.lang,
      categories: c.categories || [], tags: c.tags || [], published_at: c.published_at };
  }
  function publishLabel() {
    var b = $('#ed-publish'), s = $('#ed-save');
    var future = S.c.published_at && new Date(S.c.published_at) > new Date(Date.now() + 60000);
    if (S.c.status === 'published') { b.textContent = T('Mettre à jour', 'Update', 'Actualizar'); s.textContent = T('Repasser en brouillon', 'Switch to draft', 'Pasar a borrador'); }
    else { b.textContent = future ? T('Programmer', 'Schedule', 'Programar') : T('Publier', 'Publish', 'Publicar'); s.textContent = T('Enregistrer le brouillon', 'Save draft', 'Guardar borrador'); }
    b.hidden = !D.caps.publish;
    var view = $('#ed-view');
    view.hidden = !(S.c.id && S.c.url && S.c.status === 'published');
    if (S.c.url) view.href = S.c.url;
  }
  function save(status) {
    if (S.saving) return;
    status = status || S.c.status || 'draft';
    if (status === 'published' && S.c.published_at && new Date(S.c.published_at) > new Date(Date.now() + 60000)) status = 'scheduled';
    S.saving = true;
    setState(T('Enregistrement…', 'Saving…', 'Guardando…'));
    return WL.api('api/content/save', payload(status)).then(function (r) {
      var wasNew = !S.c.id;
      S.c.id = r.id; S.c.status = r.status; S.c.slug = r.slug; S.c.path = r.path; S.c.url = r.url; S.c.updated_at = r.updated_at;
      D.content.slug = r.slug;
      S.blocks = r.blocks; S.analysis = r.analysis;
      S.dirty = false; S.saving = false;
      try { localStorage.removeItem('wl-draft-' + (wasNew ? 'new-' + S.c.type : S.c.id)); } catch (e) {}
      if (wasNew) history.replaceState(null, '', WL.base + '/content/' + r.id);
      setState(T('Enregistré', 'Saved', 'Guardado') + ' · ' + new Date().toLocaleTimeString(D.lang, { hour: '2-digit', minute: '2-digit' }));
      publishLabel(); badge();
      if (S.tab === 'page') pagePanel();
      if (S.tab === 'seo') renderChecks();
      WL.toast(r.status === 'published' ? T('Publié', 'Published', 'Publicado') : (r.status === 'scheduled' ? T('Programmé : ', 'Scheduled: ', 'Programado: ') + r.published_at_label : T('Brouillon enregistré', 'Draft saved', 'Borrador guardado')));
    }).catch(function (e) {
      S.saving = false;
      setState(e.message, 'is-error');
      WL.toast(e.message, 'bad');
    });
  }
  $('#ed-publish').addEventListener('click', function () { save('published'); });
  $('#ed-save').addEventListener('click', function () { save(S.c.status === 'published' ? 'draft' : (S.c.status === 'scheduled' ? 'scheduled' : 'draft')); });
  $('#ed-preview').addEventListener('click', function () {
    var f = el('form', { method: 'post', action: WL.base + '/content/preview', target: '_blank' });
    f.appendChild(el('input', { type: 'hidden', name: '_csrf', value: WL.csrf }));
    f.appendChild(el('input', { type: 'hidden', name: 'state', value: JSON.stringify(payload(S.c.status)) }));
    document.body.appendChild(f); f.submit(); f.remove();
  });
  document.addEventListener('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
    var k = e.key.toLowerCase(), t = e.target;
    if (k === 's') { e.preventDefault(); save(); return; }
    if (k !== 'z' && k !== 'y') return;
    if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return; // annulation native du champ
    e.preventDefault();
    if (k === 'y' || e.shiftKey) redo(); else undo();
  });
  $('#ed-undo').addEventListener('click', undo);
  $('#ed-redo').addEventListener('click', redo);
  window.addEventListener('beforeunload', function (e) { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });

  // Titre
  function autoSlug() { if (!S.slugTouched && S.c.status !== 'published') S.c.slug = slugify(S.c.title); }
  var titleInput = $('#ed-title');
  titleInput.value = S.c.title || '';
  titleInput.addEventListener('input', function () { S.c.title = titleInput.value; autoSlug(); dirty(); sendTitle(); });

  // Aperçu ordinateur / mobile
  $$('[data-device]').forEach(function (b) {
    b.addEventListener('click', function () {
      $$('[data-device]').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      $('#ed-frame-wrap').classList.toggle('is-mobile', b.dataset.device === 'mobile');
    });
  });

  // Modèles de départ (page vide)
  function templates() {
    var dlg = $('#ed-templates'), list = $('#ed-template-list');
    list.innerHTML = '';
    D.templates.forEach(function (t) {
      var b = el('button', { type: 'button', class: 'preset', onclick: function () {
        S.blocks = clone(t.blocks).map(function (bl) { bl.id = uid(); return bl; });
        dlg.close(); dirty(); renderAll(); outline();
      } }, [el('div', { class: 'preset-sample' }, [el('b', { text: t.label })]), el('div', { class: 'preset-meta' }, [el('span', { class: 'muted', text: t.description })])]);
      list.appendChild(b);
    });
    dlg.querySelector('[data-close]').onclick = function () { dlg.close(); };
    dlg.showModal();
  }

  // Brouillon local plus récent que la version enregistrée ?
  try {
    var local = JSON.parse(localStorage.getItem('wl-draft-' + (S.c.id || 'new-' + S.c.type)) || 'null');
    if (local && local.state && local.at > (S.c.updated_at || 0) * 1000 + 2000) {
      var bar = el('div', { class: 'notice notice--warn', style: 'margin:.5rem .75rem 0' }, [el('p', {}, [document.createTextNode(T('Des modifications non enregistrées ont été retrouvées sur cet appareil. ', 'Unsaved changes were found on this device. ', 'Se encontraron cambios sin guardar. ')),
        el('button', { type: 'button', class: 'btn btn-sm', text: T('Les restaurer', 'Restore them', 'Restaurarlos'), onclick: function () {
          var st = local.state; S.blocks = st.blocks || S.blocks; ['title', 'slug', 'seo', 'excerpt', 'featured_id', 'categories', 'tags'].forEach(function (k) { if (st[k] !== undefined) S.c[k] = st[k]; });
          titleInput.value = S.c.title || ''; bar.remove(); dirty(); renderAll(); outline();
        } }), document.createTextNode(' '),
        el('button', { type: 'button', class: 'btn btn-sm btn-ghost', text: T('Ignorer', 'Dismiss', 'Ignorar'), onclick: function () { localStorage.removeItem('wl-draft-' + (S.c.id || 'new-' + S.c.type)); bar.remove(); } })])]);
      document.body.insertBefore(bar, $('.ed-body'));
    }
  } catch (e) {}

  library('');
  outline();
  inspector();
  publishLabel();
  setState(S.c.id ? T('Enregistré', 'Saved', 'Guardado') : T('Nouveau brouillon', 'New draft', 'Nuevo borrador'));
  analyze();
  if (!S.blocks.length && D.templates.length) setTimeout(templates, 300);
  record(); // état de départ de l'historique
  // Exposé pour les autres scripts (IA)
  window.WLEditor = { state: S, renderAll: renderAll, dirty: dirty, undo: undo, redo: redo };
})();
