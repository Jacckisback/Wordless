/* Wordless — médiathèque : sélecteur (fenêtre), page Médias, envoi par glisser-déposer. */
(function () {
  'use strict';
  var T = function (fr, en, es) { return window.WL ? WL.t(fr, en, es) : fr; };

  function upload(files, onDone) {
    var list = Array.prototype.slice.call(files || []);
    var results = [];
    var next = function () {
      var f = list.shift();
      if (!f) { onDone(results); return; }
      var fd = new FormData();
      fd.append('file', f);
      WL.toast(T('Envoi de « ', 'Uploading “', 'Subiendo «') + f.name + T(' »…', '”…', '»…'));
      WL.api('api/media/upload', fd).then(function (r) { results.push(r.media); next(); })
        .catch(function (e) { WL.toast(f.name + ' : ' + e.message, 'bad'); next(); });
    };
    next();
  }

  function thumb(m) {
    var div = document.createElement('div');
    div.className = 'media-thumb';
    if (m && /^image\//.test(m.mime || 'image/')) {
      var img = document.createElement('img'); img.src = m.thumb || m.url; img.alt = ''; img.loading = 'lazy'; div.appendChild(img);
    } else {
      div.textContent = (m && m.url ? m.url.split('.').pop().toUpperCase() : '');
    }
    return div;
  }

  function item(m, selected) {
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'media-item'; b.setAttribute('aria-pressed', selected ? 'true' : 'false'); b.dataset.id = m.id;
    b.appendChild(thumb(m));
    if (/^image\//.test(m.mime) && !m.alt) {
      var flag = document.createElement('span'); flag.className = 'badge badge--warn media-flag'; flag.textContent = T('sans alt', 'no alt', 'sin alt'); b.appendChild(flag);
    }
    var n = document.createElement('span'); n.className = 'media-name'; n.textContent = m.title || m.url.split('/').pop(); b.appendChild(n);
    return b;
  }

  /** Ouvre le sélecteur. opts : {multiple, type:'image'|''} -> Promise<media[]> */
  function pick(opts) {
    opts = opts || {};
    return new Promise(function (resolve) {
      var dlg = document.createElement('dialog');
      dlg.className = 'wide';
      dlg.innerHTML = '<div class="dialog-head"><h2></h2><button class="btn btn-ghost btn-icon" type="button" data-x aria-label="Fermer">✕</button></div>'
        + '<div class="dialog-body"><div class="toolbar"><input type="search"><label class="btn"><input type="file" hidden multiple><span></span></label></div>'
        + '<div class="dropzone" hidden></div><div class="media-grid"></div><p class="muted small more" hidden></p></div>'
        + '<div class="dialog-foot"><span class="muted small grow sel"></span><button class="btn" type="button" data-x></button><button class="btn btn-primary" type="button" data-ok disabled></button></div>';
      dlg.querySelector('h2').textContent = T('Médiathèque', 'Media library', 'Biblioteca de medios');
      dlg.querySelector('input[type=search]').placeholder = T('Rechercher…', 'Search…', 'Buscar…');
      dlg.querySelector('label.btn span').textContent = T('Envoyer des fichiers', 'Upload files', 'Subir archivos');
      dlg.querySelector('.dropzone').textContent = T('Déposez vos fichiers ici', 'Drop your files here', 'Suelta tus archivos aquí');
      dlg.querySelector('[data-x].btn:not(.btn-icon)').textContent = T('Annuler', 'Cancel', 'Cancelar');
      dlg.querySelector('[data-ok]').textContent = T('Choisir', 'Select', 'Elegir');
      document.body.appendChild(dlg);
      var grid = dlg.querySelector('.media-grid'), chosen = [], all = {}, offset = 0, search = '', more = dlg.querySelector('.more');
      var refreshSel = function () {
        dlg.querySelector('.sel').textContent = chosen.length ? chosen.length + T(' sélectionné(s)', ' selected', ' seleccionado(s)') : '';
        dlg.querySelector('[data-ok]').disabled = chosen.length === 0;
        grid.querySelectorAll('.media-item').forEach(function (b) { b.setAttribute('aria-pressed', chosen.indexOf(+b.dataset.id) !== -1 ? 'true' : 'false'); });
      };
      var load = function (reset) {
        if (reset) { offset = 0; grid.innerHTML = ''; }
        WL.api('api/media?limit=60&offset=' + offset + '&type=' + (opts.type || '') + '&q=' + encodeURIComponent(search)).then(function (r) {
          r.items.forEach(function (m) { all[m.id] = m; grid.appendChild(item(m, chosen.indexOf(m.id) !== -1)); });
          offset += r.items.length;
          more.hidden = !r.more;
          more.textContent = T('Faites défiler pour charger la suite', 'Scroll to load more', 'Desplázate para cargar más');
          if (!r.items.length && reset) { var p = document.createElement('p'); p.className = 'muted'; p.textContent = T('Aucun média. Envoyez votre premier fichier.', 'No media yet. Upload your first file.', 'Sin medios. Sube tu primer archivo.'); grid.appendChild(p); }
        });
      };
      grid.addEventListener('click', function (e) {
        var b = e.target.closest('.media-item'); if (!b) return;
        var id = +b.dataset.id, i = chosen.indexOf(id);
        if (opts.multiple) { if (i === -1) chosen.push(id); else chosen.splice(i, 1); } else { chosen = [id]; }
        refreshSel();
      });
      grid.addEventListener('dblclick', function (e) { if (!opts.multiple && e.target.closest('.media-item')) dlg.querySelector('[data-ok]').click(); });
      dlg.querySelector('.dialog-body').addEventListener('scroll', function (e) {
        var el = e.target; if (!more.hidden && el.scrollTop + el.clientHeight > el.scrollHeight - 120) { more.hidden = true; load(false); }
      });
      var t;
      dlg.querySelector('input[type=search]').addEventListener('input', function (e) { clearTimeout(t); t = setTimeout(function () { search = e.target.value; load(true); }, 250); });
      var onFiles = function (files) {
        upload(files, function (ms) {
          ms.forEach(function (m) { all[m.id] = m; grid.insertBefore(item(m, true), grid.firstChild); if (opts.multiple) chosen.push(m.id); else chosen = [m.id]; });
          refreshSel();
        });
      };
      dlg.querySelector('input[type=file]').addEventListener('change', function (e) { onFiles(e.target.files); e.target.value = ''; });
      var dz = dlg.querySelector('.dropzone');
      dlg.addEventListener('dragover', function (e) { e.preventDefault(); dz.hidden = false; dz.classList.add('is-over'); });
      dlg.addEventListener('dragleave', function (e) { if (e.target === dz) { dz.classList.remove('is-over'); } });
      dlg.addEventListener('drop', function (e) { e.preventDefault(); dz.hidden = true; onFiles(e.dataTransfer.files); });
      var close = function (val) { dlg.close(); dlg.remove(); resolve(val); };
      dlg.querySelectorAll('[data-x]').forEach(function (b) { b.addEventListener('click', function () { close([]); }); });
      dlg.addEventListener('cancel', function (e) { e.preventDefault(); close([]); });
      dlg.querySelector('[data-ok]').addEventListener('click', function () { close(chosen.map(function (id) { return all[id]; }).filter(Boolean)); });
      dlg.showModal();
      load(true);
    });
  }

  window.WLMedia = { pick: pick, upload: upload, thumb: thumb };

  // Champs « média » dans les formulaires classiques (profil, réglages).
  document.addEventListener('click', function (e) {
    var pickBtn = e.target.closest('[data-media-pick]');
    var clearBtn = e.target.closest('[data-media-clear]');
    var field = (pickBtn || clearBtn) ? (pickBtn || clearBtn).closest('[data-media-field]') : null;
    if (!field) return;
    var input = field.querySelector('input[type=hidden]');
    var box = field.querySelector('[data-media-thumb]');
    if (clearBtn) { input.value = ''; box.innerHTML = ''; return; }
    pick({ type: 'image' }).then(function (ms) {
      if (!ms[0]) return;
      input.value = ms[0].id;
      box.innerHTML = '';
      var img = document.createElement('img'); img.src = ms[0].thumb || ms[0].url; img.alt = ''; box.appendChild(img);
    });
  });

  // Page « Médias » : grille + panneau de détails
  var page = document.getElementById('media-library');
  if (!page) return;
  var grid = page.querySelector('.media-grid'), details = document.getElementById('media-details'), offset = 0, search = '', current = null;
  var filters = { type: page.dataset.type || '', missing_alt: page.dataset.missingAlt || '' };
  var more = document.getElementById('media-more');
  function load(reset) {
    if (reset) { offset = 0; grid.innerHTML = ''; }
    WL.api('api/media?limit=60&offset=' + offset + '&type=' + filters.type + '&missing_alt=' + filters.missing_alt + '&q=' + encodeURIComponent(search)).then(function (r) {
      r.items.forEach(function (m) { grid.appendChild(item(m, false)).__m = m; });
      offset += r.items.length;
      more.hidden = !r.more;
      if (!r.items.length && reset) { var p = document.createElement('p'); p.className = 'muted'; p.textContent = T('Aucun média ne correspond.', 'No matching media.', 'Ningún medio coincide.'); grid.appendChild(p); }
    });
  }
  function show(m) {
    current = m;
    details.hidden = false;
    details.querySelector('[data-preview]').innerHTML = '';
    details.querySelector('[data-preview]').appendChild(thumb(m));
    details.querySelector('[name=alt]').value = m.alt || '';
    details.querySelector('[name=title]').value = m.title || '';
    details.querySelector('[name=caption]').value = m.caption || '';
    details.querySelector('[data-url]').textContent = location.origin + m.url;
    details.querySelector('[data-copy-url]').setAttribute('data-copy', location.origin + m.url);
    details.querySelector('[data-meta]').textContent = [m.width && m.height ? m.width + ' × ' + m.height + ' px' : '', m.size_label, m.variants ? m.variants + T(' versions optimisées', ' optimized versions', ' versiones optimizadas') : ''].filter(Boolean).join(' · ');
    var aiBtn = details.querySelector('[data-ai-alt]');
    if (aiBtn) aiBtn.hidden = !/^image\//.test(m.mime);
    grid.querySelectorAll('.media-item').forEach(function (b) { b.setAttribute('aria-pressed', +b.dataset.id === m.id ? 'true' : 'false'); });
  }
  grid.addEventListener('click', function (e) { var b = e.target.closest('.media-item'); if (b) show(b.__m); });
  more.addEventListener('click', function () { load(false); });
  var st;
  page.querySelector('input[type=search]').addEventListener('input', function (e) { clearTimeout(st); st = setTimeout(function () { search = e.target.value; load(true); }, 250); });
  page.querySelectorAll('[data-filter]').forEach(function (b) {
    b.addEventListener('click', function () {
      page.querySelectorAll('[data-filter]').forEach(function (x) { x.setAttribute('aria-selected', 'false'); });
      b.setAttribute('aria-selected', 'true');
      filters.type = b.dataset.filter === 'alt' ? 'image' : b.dataset.filter;
      filters.missing_alt = b.dataset.filter === 'alt' ? '1' : '';
      load(true);
    });
  });
  var dz = document.getElementById('media-drop'), fileInput = document.getElementById('media-file');
  fileInput.addEventListener('change', function () { upload(fileInput.files, function () { load(true); }); fileInput.value = ''; });
  ['dragover', 'dragenter'].forEach(function (ev) { document.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('is-over'); }); });
  document.addEventListener('dragleave', function (e) { if (!e.relatedTarget) dz.classList.remove('is-over'); });
  document.addEventListener('drop', function (e) { e.preventDefault(); dz.classList.remove('is-over'); upload(e.dataTransfer.files, function () { load(true); }); });
  details.querySelector('form').addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = e.submitter;
    WL.busy(btn, true);
    WL.api('api/media/' + current.id, { alt: this.alt.value, title: this.title.value, caption: this.caption.value }).then(function (r) {
      WL.toast(T('Enregistré', 'Saved', 'Guardado')); Object.assign(current, r.media); WL.busy(btn, false); load(true);
    }).catch(function (err) { WL.toast(err.message, 'bad'); WL.busy(btn, false); });
  });
  var del = details.querySelector('[data-delete]');
  del.addEventListener('click', function () {
    if (!confirm(T('Supprimer définitivement ce fichier ? Les pages qui l’utilisent n’afficheront plus l’image.', 'Delete this file permanently?', '¿Eliminar este archivo definitivamente?'))) return;
    WL.api('api/media/' + current.id + '/delete', {}).then(function () { details.hidden = true; WL.toast(T('Supprimé', 'Deleted', 'Eliminado')); load(true); }).catch(function (err) { WL.toast(err.message, 'bad'); });
  });
  var ai = details.querySelector('[data-ai-alt]');
  if (ai) ai.addEventListener('click', function () {
    WL.busy(ai, true);
    WL.stream('api/ai/run', { task: 'alt', media_id: current.id }).then(function (r) {
      details.querySelector('[name=alt]').value = r.alt || ''; WL.busy(ai, false);
    }).catch(function (err) { WL.toast(err.message, 'bad'); WL.busy(ai, false); });
  });
  load(true);
})();
