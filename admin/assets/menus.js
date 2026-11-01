/* Éditeur de menus : glisser-déposer, niveaux (sous-menus), emplacements. */
(function () {
  var data = document.getElementById('menu-data');
  if (!data) return;
  var T = WL.t, M = JSON.parse(data.textContent);
  // Aplatit l'arbre en liste avec profondeur (plus simple à manipuler).
  var flat = [];
  (function walk(items, depth) { items.forEach(function (it) { var c = it.children || []; delete it.children; it.depth = depth; flat.push(it); walk(c, depth + 1); }); })(M.items, 0);
  var list = document.getElementById('menu-items'), drag = null;
  function render() {
    list.innerHTML = '';
    if (!flat.length) { var li = document.createElement('li'); li.className = 'muted small'; li.textContent = T('Menu vide : ajoutez des pages depuis la droite.', 'Empty menu: add pages from the right.', 'Menú vacío.'); list.appendChild(li); }
    flat.forEach(function (it, i) {
      var li = document.createElement('li'); li.draggable = true; li.style.marginLeft = (it.depth * 1.75) + 'rem';
      var lbl = document.createElement('input'); lbl.value = it.label; lbl.setAttribute('aria-label', T('Texte du lien', 'Link text', 'Texto del enlace')); lbl.style.flex = '1';
      lbl.addEventListener('input', function () { it.label = lbl.value; });
      var kind = document.createElement('span'); kind.className = 'muted small'; kind.textContent = it.type === 'url' ? (it.url || '') : (it.type === 'term' ? T('catégorie', 'category', 'categoría') : T('page', 'page', 'página'));
      var acts = document.createElement('span'); acts.className = 'acts';
      [['←', function () { it.depth = Math.max(0, it.depth - 1); }], ['→', function () { if (i > 0) it.depth = Math.min(2, Math.min(flat[i - 1].depth + 1, it.depth + 1)); }], ['✕', function () { flat.splice(i, 1); }]].forEach(function (a) {
        var b = document.createElement('button'); b.type = 'button'; b.textContent = a[0]; b.addEventListener('click', function () { a[1](); render(); }); acts.appendChild(b);
      });
      li.appendChild(lbl); li.appendChild(kind); li.appendChild(acts);
      li.addEventListener('dragstart', function () { drag = i; });
      li.addEventListener('dragover', function (e) { e.preventDefault(); li.classList.add('is-drag-over'); });
      li.addEventListener('dragleave', function () { li.classList.remove('is-drag-over'); });
      li.addEventListener('drop', function (e) { e.preventDefault(); if (drag === null || drag === i) return; var m = flat.splice(drag, 1)[0]; flat.splice(drag < i ? i - 1 : i, 0, m); drag = null; render(); });
      list.appendChild(li);
    });
  }
  function tree() {
    var root = [], stack = [{ depth: -1, children: root }];
    flat.forEach(function (it) {
      var node = { label: it.label, type: it.type, id: it.id, url: it.url, children: [] };
      while (stack.length > 1 && stack[stack.length - 1].depth >= it.depth) stack.pop();
      stack[stack.length - 1].children.push(node);
      stack.push({ depth: it.depth, children: node.children });
    });
    return root;
  }
  document.querySelectorAll('[data-loc]').forEach(function (cb) { cb.checked = M.locations.indexOf(cb.dataset.loc) !== -1; });
  document.getElementById('add-content').addEventListener('click', function () {
    document.querySelectorAll('[data-add-content]:checked').forEach(function (cb) { flat.push({ label: cb.dataset.label, type: 'content', id: +cb.dataset.addContent, depth: 0 }); cb.checked = false; });
    render();
  });
  var at = document.getElementById('add-terms');
  if (at) at.addEventListener('click', function () {
    document.querySelectorAll('[data-add-term]:checked').forEach(function (cb) { flat.push({ label: cb.dataset.label, type: 'term', id: +cb.dataset.addTerm, depth: 0 }); cb.checked = false; });
    render();
  });
  document.getElementById('add-link').addEventListener('click', function () {
    var u = document.getElementById('l-url'), l = document.getElementById('l-label');
    if (!u.value.trim() || !l.value.trim()) { WL.toast(T('Indiquez l’adresse et le texte.', 'Enter URL and text.', 'Indica la dirección y el texto.'), 'bad'); return; }
    flat.push({ label: l.value.trim(), type: 'url', url: u.value.trim(), depth: 0 }); u.value = ''; l.value = ''; render();
  });
  var save = document.getElementById('menu-save');
  save.addEventListener('click', function () {
    WL.busy(save, true);
    var locs = Array.prototype.filter.call(document.querySelectorAll('[data-loc]'), function (c) { return c.checked; }).map(function (c) { return c.dataset.loc; });
    WL.api('api/menus/' + M.id, { name: document.getElementById('menu-name').value, items: tree(), locations: locs }).then(function () { WL.busy(save, false); WL.toast(T('Menu enregistré', 'Menu saved', 'Menú guardado')); })
      .catch(function (e) { WL.busy(save, false); WL.toast(e.message, 'bad'); });
  });
  render();
})();
