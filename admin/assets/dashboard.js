/* Info-bulles des graphiques du tableau de bord (survol et clavier). */
(function () {
  document.querySelectorAll('.chart').forEach(function (chart) {
    var tip = chart.querySelector('.chart-tip');
    var svg = chart.querySelector('svg');
    if (!tip || !svg) return;
    var show = function (hit) {
      var box = chart.getBoundingClientRect(), r = hit.getBoundingClientRect();
      tip.textContent = '';
      var strong = document.createElement('strong'); strong.textContent = hit.getAttribute('data-value');
      var label = document.createElement('span'); label.textContent = hit.getAttribute('data-label');
      tip.appendChild(strong); tip.appendChild(label);
      tip.hidden = false;
      var bar = hit.nextElementSibling && hit.nextElementSibling.classList.contains('bar') ? hit.nextElementSibling.getBoundingClientRect() : r;
      tip.style.left = (r.left - box.left + r.width / 2) + 'px';
      tip.style.top = (Math.min(bar.top, r.bottom - 10) - box.top) + 'px';
      svg.querySelectorAll('.bar.is-on').forEach(function (b) { b.classList.remove('is-on'); });
      if (hit.nextElementSibling && hit.nextElementSibling.classList.contains('bar')) hit.nextElementSibling.classList.add('is-on');
    };
    var hide = function () { tip.hidden = true; svg.querySelectorAll('.bar.is-on').forEach(function (b) { b.classList.remove('is-on'); }); };
    svg.addEventListener('pointerover', function (e) { if (e.target.classList.contains('hit')) show(e.target); });
    svg.addEventListener('focusin', function (e) { if (e.target.classList.contains('hit')) show(e.target); });
    svg.addEventListener('pointerleave', hide);
    svg.addEventListener('focusout', hide);
  });
  // Vérifie que le dossier data/ n'est pas accessible depuis le web (Nginx sans règle).
  var admin = document.querySelector('meta[name="wl-admin"]');
  if (admin && window.WL) {
    var root = admin.content.replace(/\/[^/]+$/, '');
    fetch(root + '/data/probe.txt', { cache: 'no-store', credentials: 'omit' }).then(function (r) { return r.ok ? r.text() : ''; })
      .then(function (t) { return WL.api('api/system/probe', { exposed: t.indexOf('wordless-private') !== -1 }); })
      .catch(function () {});
  }
})();
