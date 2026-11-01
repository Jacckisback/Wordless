/* Wordless — script de la toile de l'éditeur (dans l'iframe) : édition directe des
   textes, sélection des blocs, barre d'outils de mise en forme. Communique avec
   l'éditeur parent par postMessage (même origine uniquement). */
(function () {
  'use strict';
  var origin = location.origin;
  var canvas = document.getElementById('wl-canvas');
  var titleEl = document.getElementById('wl-title');
  var titleArea = document.getElementById('wl-title-area');
  var selected = null, lang = 'fr';
  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}

  function send(msg) { msg.source = 'wl-frame'; parent.postMessage(msg, origin); }
  function q(id) { return canvas.querySelector('[data-wl-block="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]'); }
  function T(fr, en, es) { return lang === 'en' ? en : (lang === 'es' ? es : fr); }

  function updateEmpty(el) { el.classList.toggle('is-empty', el.textContent.trim() === '' && !el.querySelector('img,iframe,hr')); }

  function enhance(root) {
    root.querySelectorAll('[data-wl-field]').forEach(function (el) {
      if (el.hasAttribute('data-wl-rich')) {
        el.contentEditable = 'true';
      } else {
        el.contentEditable = 'plaintext-only';
        if (el.contentEditable !== 'plaintext-only') el.contentEditable = 'true';
      }
      el.spellcheck = true;
      updateEmpty(el);
    });
    root.querySelectorAll('a').forEach(function (a) { a.setAttribute('draggable', 'false'); });
    root.querySelectorAll('details').forEach(function (d) { d.open = true; });
  }

  function mark(id, scroll) {
    canvas.querySelectorAll('.is-selected').forEach(function (n) { n.classList.remove('is-selected'); });
    selected = id;
    var el = id ? q(id) : null;
    if (el) {
      el.classList.add('is-selected');
      if (scroll) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    placeBar();
  }

  // Barre d'actions du bloc sélectionné
  var bar = document.createElement('div');
  bar.className = 'wl-blockbar';
  bar.hidden = true;
  document.body.appendChild(bar);
  function placeBar() {
    var el = selected ? q(selected) : null;
    if (!el) { bar.hidden = true; return; }
    bar.innerHTML = '';
    var label = document.createElement('span'); label.className = 'lbl'; label.textContent = el.getAttribute('data-wl-label') || ''; if (label.textContent) bar.appendChild(label);
    [['up', '↑', T('Monter', 'Move up', 'Subir')], ['down', '↓', T('Descendre', 'Move down', 'Bajar')], ['duplicate', '⧉', T('Dupliquer', 'Duplicate', 'Duplicar')], ['delete', '✕', T('Supprimer', 'Delete', 'Eliminar')]].forEach(function (a) {
      var b = document.createElement('button'); b.type = 'button'; b.textContent = a[1]; b.title = a[2]; b.setAttribute('aria-label', a[2]);
      b.addEventListener('mousedown', function (e) { e.preventDefault(); });
      b.addEventListener('click', function () { send({ type: 'action', action: a[0], id: selected }); });
      bar.appendChild(b);
    });
    var r = el.getBoundingClientRect();
    bar.hidden = false;
    bar.style.top = (window.scrollY + Math.max(r.top, 46)) + 'px';
    bar.style.left = (window.scrollX + Math.max(8, Math.min(r.right - bar.offsetWidth, window.innerWidth - bar.offsetWidth - 8))) + 'px';
  }
  window.addEventListener('scroll', function () { if (!bar.hidden) placeBar(); }, { passive: true });
  window.addEventListener('resize', placeBar);

  // Barre de mise en forme du texte riche
  var rtb = document.createElement('div');
  rtb.className = 'wl-rtb';
  rtb.hidden = true;
  document.body.appendChild(rtb);
  var richButtons = [
    ['bold', 'B', 'bold', 'inline'], ['italic', 'I', 'italic', 'inline'], ['link', '🔗', 'link', 'inline'],
    ['h2', 'H2', 'heading', 'full'], ['h3', 'H3', 'heading', 'full'], ['ul', '•', 'list', 'inline'], ['ol', '1.', 'list', 'inline'],
    ['quote', '❝', 'quote', 'full'], ['clear', '⌫', 'clear', 'inline']
  ];
  function showRtb(field) {
    var sel = window.getSelection();
    if (!sel || sel.isCollapsed || !sel.rangeCount) { rtb.hidden = true; return; }
    var mode = field.getAttribute('data-wl-rich');
    rtb.innerHTML = '';
    richButtons.forEach(function (b) {
      if (b[3] === 'full' && mode !== 'full') return;
      var btn = document.createElement('button'); btn.type = 'button'; btn.textContent = b[1]; btn.dataset.cmd = b[0];
      btn.title = { bold: T('Gras', 'Bold', 'Negrita'), italic: T('Italique', 'Italic', 'Cursiva'), link: T('Lien', 'Link', 'Enlace'), h2: T('Titre de section', 'Section heading', 'Título de sección'), h3: T('Sous-titre', 'Subheading', 'Subtítulo'), ul: T('Liste', 'List', 'Lista'), ol: T('Liste numérotée', 'Numbered list', 'Lista numerada'), quote: T('Citation', 'Quote', 'Cita'), clear: T('Effacer la mise en forme', 'Clear formatting', 'Borrar formato') }[b[0]];
      btn.addEventListener('mousedown', function (e) { e.preventDefault(); });
      btn.addEventListener('click', function () { format(b[0], field); });
      rtb.appendChild(btn);
    });
    var r = sel.getRangeAt(0).getBoundingClientRect();
    rtb.hidden = false;
    rtb.style.top = (window.scrollY + r.top - rtb.offsetHeight - 8) + 'px';
    rtb.style.left = (window.scrollX + Math.max(8, r.left + r.width / 2 - rtb.offsetWidth / 2)) + 'px';
  }
  function format(cmd, field) {
    switch (cmd) {
      case 'bold': document.execCommand('bold'); break;
      case 'italic': document.execCommand('italic'); break;
      case 'link':
        var url = window.prompt(T('Adresse du lien (https://… ou /page)', 'Link address (https://… or /page)', 'Dirección del enlace (https://… o /página)'), 'https://');
        if (url && url !== 'https://') document.execCommand('createLink', false, url); else if (url === '') document.execCommand('unlink');
        break;
      case 'h2': document.execCommand('formatBlock', false, 'h2'); break;
      case 'h3': document.execCommand('formatBlock', false, 'h3'); break;
      case 'ul': document.execCommand('insertUnorderedList'); break;
      case 'ol': document.execCommand('insertOrderedList'); break;
      case 'quote': document.execCommand('formatBlock', false, 'blockquote'); break;
      case 'clear': document.execCommand('removeFormat'); document.execCommand('unlink'); document.execCommand('formatBlock', false, 'p'); break;
    }
    emit(field);
    rtb.hidden = true;
  }

  function emit(field) {
    updateEmpty(field);
    var block = field.closest('[data-wl-block]');
    if (!block) return;
    var rich = field.hasAttribute('data-wl-rich');
    send({ type: 'field', id: block.getAttribute('data-wl-block'), field: field.getAttribute('data-wl-field'), rich: rich, value: rich ? field.innerHTML : field.innerText.replace(/\n{3,}/g, '\n\n').trim() });
  }

  canvas.addEventListener('input', function (e) {
    var f = e.target.closest && e.target.closest('[data-wl-field]');
    if (f) emit(f);
  });
  canvas.addEventListener('click', function (e) {
    if (e.target.closest('a')) e.preventDefault();
    if (e.target.closest('summary') && !e.target.closest('[data-wl-field]')) e.preventDefault();
    var b = e.target.closest('[data-wl-block]');
    if (b) { mark(b.getAttribute('data-wl-block')); send({ type: 'select', id: selected }); }
  });
  canvas.addEventListener('submit', function (e) { e.preventDefault(); });
  canvas.addEventListener('focusin', function (e) {
    var b = e.target.closest && e.target.closest('[data-wl-block]');
    if (b && b.getAttribute('data-wl-block') !== selected) { mark(b.getAttribute('data-wl-block')); send({ type: 'select', id: selected }); }
  });
  canvas.addEventListener('keydown', function (e) {
    var f = e.target.closest && e.target.closest('[data-wl-field]');
    if (!f) return;
    if (e.key === 'Enter' && !f.hasAttribute('data-wl-rich') && !/text|quote|description|features/.test(f.getAttribute('data-wl-field'))) { e.preventDefault(); f.blur(); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b' && f.hasAttribute('data-wl-rich')) { e.preventDefault(); format('bold', f); }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'i' && f.hasAttribute('data-wl-rich')) { e.preventDefault(); format('italic', f); }
  });
  canvas.addEventListener('paste', function (e) {
    var f = e.target.closest && e.target.closest('[data-wl-field]');
    if (!f) return;
    e.preventDefault();
    var text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
    if (f.hasAttribute('data-wl-rich')) {
      var paras = text.replace(/\r/g, '').split(/\n{2,}/).map(function (p) {
        var d = document.createElement('div'); d.textContent = p.trim(); return d.innerHTML.replace(/\n/g, '<br>');
      }).filter(Boolean);
      document.execCommand('insertHTML', false, paras.length > 1 ? '<p>' + paras.join('</p><p>') + '</p>' : (paras[0] || ''));
    } else {
      document.execCommand('insertText', false, text.replace(/\s*\n\s*/g, ' '));
    }
  });
  document.addEventListener('selectionchange', function () {
    var sel = window.getSelection();
    var node = sel && sel.anchorNode ? (sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement) : null;
    var f = node && node.closest ? node.closest('[data-wl-rich]') : null;
    if (f) showRtb(f); else rtb.hidden = true;
  });
  document.addEventListener('keydown', function (e) {
    if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
    var k = e.key.toLowerCase(), t = e.target;
    if (k === 's') { e.preventDefault(); send({ type: 'save' }); return; }
    if (k === 'k') { e.preventDefault(); send({ type: 'search' }); return; }
    // Annuler / rétablir : hors d'un texte en cours d'édition, c'est l'historique de l'éditeur qui agit.
    if ((k === 'z' || k === 'y') && !(t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)))) {
      e.preventDefault();
      send({ type: k === 'y' || e.shiftKey ? 'redo' : 'undo' });
    }
  });

  // Titre (éditable directement dans l'aperçu)
  titleEl.contentEditable = 'plaintext-only';
  if (titleEl.contentEditable !== 'plaintext-only') titleEl.contentEditable = 'true';
  titleEl.addEventListener('input', function () { send({ type: 'title', title: titleEl.innerText.trim() }); });
  titleEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); titleEl.blur(); } });

  window.addEventListener('message', function (e) {
    if (e.origin !== origin || !e.data || e.data.source !== 'wl-editor') return;
    var d = e.data;
    if (d.lang) lang = d.lang;
    if (d.type === 'html') {
      canvas.innerHTML = d.html || '';
      if (!d.html) {
        var empty = document.createElement('div'); empty.className = 'wl-empty-canvas';
        empty.textContent = T('Page vide : ajoutez un bloc depuis le panneau de gauche, ou choisissez un modèle.', 'Empty page: add a block from the left panel or pick a template.', 'Página vacía: añade un bloque desde el panel izquierdo o elige una plantilla.');
        canvas.appendChild(empty);
      }
      (d.labels || []).forEach(function (l) { var el = q(l[0]); if (el) el.setAttribute('data-wl-label', l[1]); });
      enhance(canvas);
      mark(selected);
    } else if (d.type === 'block') {
      var old = q(d.id);
      var tpl = document.createElement('template');
      tpl.innerHTML = (d.html || '').trim();
      var fresh = tpl.content.firstElementChild;
      if (old && fresh) {
        if (d.label) fresh.setAttribute('data-wl-label', d.label);
        old.replaceWith(fresh);
        enhance(fresh);
        mark(selected);
      }
    } else if (d.type === 'title') {
      if (document.activeElement !== titleEl) titleEl.textContent = d.title || '';
      titleEl.setAttribute('data-placeholder', d.placeholder || '');
      titleArea.hidden = !!d.hide;
    } else if (d.type === 'select') {
      mark(d.id, d.scroll);
    }
  });

  enhance(canvas);
  send({ type: 'ready' });
})();
