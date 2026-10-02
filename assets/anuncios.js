/* MiSalaUCR — Carrusel de anuncios + vista previa de subida (panel del admin).
 *
 * El carrusel es scroll-snap nativo: en el teléfono se desliza con el dedo sin
 * ninguna librería. Este script solo agrega flechas, puntos, el cambio
 * automático y el conteo de vistas.
 *
 * student.php se recarga solo cada 60 s; el índice actual se guarda en
 * sessionStorage para que el carrusel no vuelva siempre al primer anuncio.
 */
(function () {
  'use strict';

  var reducido = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function leer(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }
  function guardar(k, v) { try { sessionStorage.setItem(k, v); } catch (e) { /* modo privado */ } }

  function iniciar(root) {
    var pista  = root.querySelector('.anuncios-pista');
    var slides = pista ? pista.children : [];
    var n = slides.length;
    if (!n) return;

    var puntos = root.querySelectorAll('.anuncios-puntos button');
    var seg    = Math.max(3, parseInt(root.getAttribute('data-seg'), 10) || 6);
    var clave  = root.getAttribute('data-key');
    var urlVista = root.getAttribute('data-vista');
    var csrf   = root.getAttribute('data-csrf') || '';
    var idx = 0, visible = false, pausaHasta = 0, enviados = {};

    function reportarVista() {
      if (!urlVista || !visible || document.hidden) return;
      var id = slides[idx] && slides[idx].getAttribute('data-id');
      if (!id || enviados[id]) return;
      enviados[id] = true; // el servidor además deduplica por hora
      var datos = new FormData();
      datos.append('vista', id);
      datos.append('csrf', csrf);
      if (navigator.sendBeacon) navigator.sendBeacon(urlVista, datos);
      else fetch(urlVista, { method: 'POST', body: datos, credentials: 'same-origin', keepalive: true }).catch(function () {});
    }

    function marcar(i) {
      idx = i;
      for (var k = 0; k < puntos.length; k++) puntos[k].className = k === i ? 'on' : '';
      if (clave) guardar(clave, String(i));
      reportarVista();
    }

    function ir(i, suave) {
      i = (i + n) % n;
      pista.scrollTo({ left: i * pista.clientWidth, behavior: suave && !reducido ? 'smooth' : 'auto' });
      marcar(i);
    }

    // El usuario deslizó con el dedo o con el trackpad.
    var t;
    pista.addEventListener('scroll', function () {
      clearTimeout(t);
      t = setTimeout(function () {
        var i = Math.round(pista.scrollLeft / Math.max(1, pista.clientWidth));
        if (i !== idx && i >= 0 && i < n) marcar(i);
      }, 90);
    }, { passive: true });

    function pausar(ms) { pausaHasta = Date.now() + ms; }
    ['pointerdown', 'touchstart', 'wheel'].forEach(function (ev) {
      pista.addEventListener(ev, function () { pausar(10000); }, { passive: true });
    });
    root.addEventListener('mouseenter', function () { pausar(1e9); });
    root.addEventListener('mouseleave', function () { pausar(seg * 1000); });
    root.addEventListener('focusin', function () { pausar(1e9); });
    root.addEventListener('focusout', function () { pausar(seg * 1000); });

    var prev = root.querySelector('.anuncios-flecha.prev');
    var next = root.querySelector('.anuncios-flecha.next');
    if (prev) prev.addEventListener('click', function () { pausar(10000); ir(idx - 1, true); });
    if (next) next.addEventListener('click', function () { pausar(10000); ir(idx + 1, true); });
    for (var p = 0; p < puntos.length; p++) {
      (function (k) { puntos[k].addEventListener('click', function () { pausar(10000); ir(k, true); }); })(p);
    }
    pista.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft')  { e.preventDefault(); ir(idx - 1, true); }
      if (e.key === 'ArrowRight') { e.preventDefault(); ir(idx + 1, true); }
    });

    // Solo cuenta como vista si el carrusel está realmente en pantalla.
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(function (es) {
        visible = es[0].isIntersecting;
        reportarVista();
      }, { threshold: 0.5 }).observe(root);
    } else {
      visible = true;
    }
    document.addEventListener('visibilitychange', reportarVista);

    // Retoma donde iba antes de la recarga automática.
    var guardado = parseInt(leer(clave), 10);
    if (guardado > 0 && guardado < n) ir(guardado, false); else marcar(0);

    if (n > 1 && !reducido) {
      setInterval(function () {
        if (document.hidden || Date.now() < pausaHasta) return;
        ir(idx + 1, true);
      }, seg * 1000);
    }
  }

  // Vista previa antes de guardar: muestra el recorte 8:3 tal como lo verán.
  function previaSubida(input) {
    var destino = document.getElementById(input.getAttribute('data-anuncio-previa'));
    if (!destino) return;
    var img = destino.querySelector('img');
    input.addEventListener('change', function () {
      var f = input.files && input.files[0];
      if (!f || !/^image\//.test(f.type)) { destino.hidden = true; return; }
      if (img.src && img.src.indexOf('blob:') === 0) URL.revokeObjectURL(img.src);
      img.src = URL.createObjectURL(f);
      destino.hidden = false;
    });
  }

  function arrancar() {
    var cs = document.querySelectorAll('.anuncios');
    for (var i = 0; i < cs.length; i++) iniciar(cs[i]);
    var ins = document.querySelectorAll('input[data-anuncio-previa]');
    for (var j = 0; j < ins.length; j++) previaSubida(ins[j]);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arrancar);
  else arrancar();
})();
