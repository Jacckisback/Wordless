/* Wordless — seul script du site public, chargé uniquement sur les pages avec vidéo.
   La vidéo (YouTube, Vimeo) n'est chargée qu'au clic : page légère, aucun cookie tiers avant lecture. */
document.addEventListener('click', function (e) {
  var a = e.target.closest && e.target.closest('a[data-wl-embed]');
  if (!a) return;
  e.preventDefault();
  var f = document.createElement('iframe');
  f.src = a.getAttribute('data-wl-embed');
  f.title = a.getAttribute('data-wl-title') || 'Vidéo';
  f.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
  f.allowFullscreen = true;
  var box = document.createElement('div');
  box.className = 'wl-video__frame';
  box.appendChild(f);
  a.replaceWith(box);
  f.focus();
});
