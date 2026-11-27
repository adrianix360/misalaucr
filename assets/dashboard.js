/* MiSalaUCR — gráficas del dashboard (Chart.js 4). Si el CDN falla, los números y tablas siguen visibles. */
(function () {
  'use strict';
  function init() {
    var el = document.getElementById('dash-data');
    if (!el) return;
    var data;
    try { data = JSON.parse(el.textContent); } catch (e) { return; }
    var charts = (data && data.charts) || [];

    if (typeof Chart === 'undefined') {
      charts.forEach(function (c) {
        var cv = document.getElementById(c.id);
        if (!cv || !cv.parentNode) return;
        cv.parentNode.innerHTML = '<p class="mini dash-nochart">La gráfica no se pudo cargar (sin conexión al CDN). Los números de arriba siguen siendo correctos.</p>';
      });
      return;
    }

    var cs = getComputedStyle(document.body);
    function v(n, fb) { var x = cs.getPropertyValue(n).trim(); return x || fb; }
    var pal = {
      teal:  v('--teal', '#2ec5d8'),
      navy:  v('--navy', '#123a5e'),
      verde: v('--verde', '#0e9c86'),
      rojo:  v('--rojo', '#c14a44'),
      gris:  v('--gris', '#6c7680'),
      amber: v('--dash-amber', '#e0a100')
    };
    // En algunos temas --navy y --teal casi coinciden (naranja/azul): se elige para la serie "navy" un color distinguible
    function lum(h) {
      var m = /^#([0-9a-f]{6})$/i.exec(h); if (!m) return null;
      var n = parseInt(m[1], 16);
      return [n >> 16, (n >> 8) & 255, n & 255].map(function (c) { c /= 255; return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); })
        .reduce(function (a, c, i) { return a + c * [0.2126, 0.7152, 0.0722][i]; }, 0);
    }
    function cr(a, b) { var x = lum(a), y = lum(b); if (x === null || y === null) return 99; return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); }
    [v('--tinta', ''), pal.gris, pal.verde].some(function (alt) {
      if (cr(pal.navy, pal.teal) >= 2.5) return true;
      if (alt && cr(alt, pal.teal) >= 2.5) { pal.navy = alt; return true; }
      return false;
    });
    var tick = pal.gris, grid = v('--borde', '#e2ddd0');
    Chart.defaults.font.family = v('--f-body', 'sans-serif');
    Chart.defaults.color = tick;

    function col(n) { return pal[n] || n; }
    function alpha(hex, a) {
      var m = /^#([0-9a-f]{6})$/i.exec(hex);
      if (!m) return hex;
      var n = parseInt(m[1], 16);
      return 'rgba(' + (n >> 16) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
    }
    function umbral(n) { return n >= 85 ? pal.rojo : (n >= 60 ? pal.amber : pal.verde); }

    charts.forEach(function (c) {
      var cv = document.getElementById(c.id);
      if (!cv) return;
      try {
        var cfg = build(c);
        new Chart(cv.getContext('2d'), cfg);
      } catch (e) {
        if (cv.parentNode) cv.parentNode.innerHTML = '<p class="mini dash-nochart">No se pudo dibujar esta gráfica.</p>';
      }
    });

    function build(c) {
      var common = { responsive: true, maintainAspectRatio: false,
        plugins: { legend: { labels: { boxWidth: 12, color: tick } } } };
      if (c.type === 'doughnut') {
        var d0 = c.datasets[0];
        common.cutout = '62%';
        common.plugins.legend.position = 'bottom';
        return { type: 'doughnut', options: common, data: { labels: c.labels, datasets: [{
          data: d0.data, backgroundColor: d0.colors.map(col), borderColor: 'transparent', borderWidth: 0 }] } };
      }
      var sets = c.datasets.map(function (d) {
        var base = col(d.color);
        var o = { label: d.label, data: d.data, borderColor: base };
        if (c.type === 'bar') {
          o.backgroundColor = c.threshold ? d.data.map(umbral) : alpha(base, 0.85);
          o.borderRadius = 4;
        } else {
          o.backgroundColor = alpha(base, d.fill ? 0.18 : 0.1);
          o.fill = !!d.fill; o.tension = 0.3; o.borderWidth = 2;
          o.pointRadius = d.data.length > 40 ? 0 : 3;
        }
        return o;
      });
      (c.lines || []).forEach(function (l) {
        sets.push({ type: 'line', label: l.label, data: c.labels.map(function () { return l.value; }),
          borderColor: col(l.color), borderDash: [6, 4], borderWidth: 1.5, pointRadius: 0, fill: false });
      });
      var yMax = null;
      if (c.lines && c.lines.length) {
        var mx = Math.max.apply(null, c.datasets[0].data.concat(c.lines.map(function (l) { return l.value; })));
        yMax = Math.ceil(mx * 1.08);
      }
      var y = { beginAtZero: true, ticks: { color: tick, precision: 0 }, grid: { color: grid } };
      if (yMax) y.suggestedMax = yMax;
      if (c.pct) y.ticks.callback = function (x) { return x + '%'; };
      common.scales = { x: { ticks: { color: tick, maxRotation: 0, autoSkip: true }, grid: { display: false } }, y: y };
      common.interaction = { mode: 'index', intersect: false };
      if (!(c.datasets.length > 1 || (c.lines && c.lines.length))) common.plugins.legend.display = false;
      return { type: c.type, data: { labels: c.labels, datasets: sets }, options: common };
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
