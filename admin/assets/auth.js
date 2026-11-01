/* Wordless — formulaires de connexion et d'installation. */
(function () {
  document.querySelectorAll('[data-reveal]').forEach(function (btn) {
    var input = document.getElementById(btn.getAttribute('data-reveal'));
    if (!input) return;
    var show = btn.textContent, hide = document.documentElement.lang === 'en' ? 'Hide' : (document.documentElement.lang === 'es' ? 'Ocultar' : 'Masquer');
    btn.addEventListener('click', function () {
      var visible = input.type === 'text';
      input.type = visible ? 'password' : 'text';
      btn.textContent = visible ? show : hide;
      input.focus();
    });
  });
  var tz = document.querySelector('[data-timezone]');
  if (tz) { try { tz.value = Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) {} }
  document.querySelectorAll('[data-strength]').forEach(function (input) {
    var label = input.closest('.field').querySelector('[data-strength-label]');
    if (!label) return;
    var base = label.textContent;
    var fr = document.documentElement.lang !== 'en' && document.documentElement.lang !== 'es';
    var words = fr ? ['Trop court', 'Faible', 'Correct', 'Solide', 'Excellent'] : (document.documentElement.lang === 'es' ? ['Demasiado corta', 'Débil', 'Aceptable', 'Sólida', 'Excelente'] : ['Too short', 'Weak', 'Fair', 'Strong', 'Excellent']);
    input.addEventListener('input', function () {
      var v = input.value, s = 0;
      if (!v) { label.textContent = base; label.className = 'hint'; return; }
      if (v.length >= 10) s++;
      if (v.length >= 14) s++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
      if (/\d/.test(v) || /[^\w]/.test(v)) s++;
      if (v.length < 10) s = 0;
      label.textContent = words[s];
      label.className = 'hint counter ' + (s >= 3 ? 'is-good' : (s >= 2 ? 'is-warn' : 'is-bad'));
    });
  });
  document.querySelectorAll('form').forEach(function (f) {
    f.addEventListener('submit', function () {
      var b = f.querySelector('button[type=submit]');
      if (b) setTimeout(function () { b.classList.add('is-busy'); b.disabled = true; }, 0);
    });
  });
})();
