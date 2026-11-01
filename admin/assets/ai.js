/* Page Assistant IA : test des clés, liste des modèles, rédaction d'un brouillon. */
(function () {
  var T = WL.t;
  document.querySelectorAll('[data-test-key]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var provider = btn.getAttribute('data-test-key');
      var input = document.getElementById(provider + '_key');
      WL.busy(btn, true);
      WL.api('api/ai/test', { provider: provider, key: input.value }).then(function (r) {
        WL.busy(btn, false);
        WL.toast(T('Clé valide : ', 'Valid key: ', 'Clave válida: ') + r.models.length + T(' modèles disponibles', ' models available', ' modelos disponibles'));
        if (provider === 'openai') {
          var dl = document.getElementById('openai-models'); dl.innerHTML = '';
          r.models.forEach(function (m) { var o = document.createElement('option'); o.value = m.id; dl.appendChild(o); });
          var mi = document.getElementById('openai_model'); if (!mi.value) mi.value = r.suggested || '';
        }
      }).catch(function (e) { WL.busy(btn, false); WL.toast(e.message, 'bad'); });
    });
  });
  var form = document.getElementById('ai-article');
  if (form) {
    form.setAttribute('data-no-busy', '');
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('[type=submit]'), out = document.getElementById('ai-progress');
      WL.busy(btn, true);
      out.textContent = T('L’IA prépare le plan…', 'AI is planning…', 'La IA prepara el plan…');
      WL.stream('api/ai/article', { brief: form.brief.value, keyword: form.keyword.value, type: form.type.value, category: form.category.value }, function (p) {
        out.textContent = T('Rédaction en cours… ', 'Writing… ', 'Redactando… ') + p.chars + T(' caractères', ' characters', ' caracteres');
      }).then(function (r) {
        out.textContent = T('Brouillon prêt, ouverture…', 'Draft ready, opening…', 'Borrador listo, abriendo…');
        location.href = r.edit;
      }).catch(function (err) { WL.busy(btn, false); out.textContent = ''; WL.toast(err.message, 'bad'); });
    });
  }
})();
