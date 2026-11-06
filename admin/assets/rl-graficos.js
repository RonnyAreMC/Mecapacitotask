/**
 * Gráficos de Releases con ApexCharts (assets/apexcharts.min.js).
 *
 * La tarjeta de avance de un ítem (releases.php, section[data-rl-graf]) trae sus
 * datos en JSON (rlGrafAvance en lib/releases_ui.php) y tres lugares:
 *   [data-g=bala]    lo real con la marca de lo esperado hoy (bullet)
 *   [data-g=tareas]  sus tareas: completadas · en curso · por hacer
 *   [data-g=tiempo]  el avance en el tiempo: real, plan y revisiones
 * El anillo del % de un release ([data-rl-anillo], rlAnillo) pasa a dos anillos:
 * lo real por fuera y lo esperado hoy por dentro.
 * Mientras no carga (o sin JS) se queda la barra de siempre. ApexCharts se pide
 * una sola vez y solo cuando una tarjeta se ve en pantalla (también si llega
 * después, dentro de un panel). Los colores salen de los tokens del panel, así
 * que siguen el modo oscuro.
 */
(function () {
  'use strict';
  var cargando = null;
  function apex(src) {
    if (window.ApexCharts) return Promise.resolve();
    if (!cargando) cargando = new Promise(function (ok, mal) {
      var s = document.createElement('script');
      s.src = src; s.async = true; s.onload = ok; s.onerror = mal;
      document.head.appendChild(s);
    });
    return cargando;
  }
  function token(el, n, porDefecto) { var v = getComputedStyle(el).getPropertyValue(n).trim(); return v || porDefecto; }
  function oscuro() {
    var t = document.documentElement.getAttribute('data-theme');
    return t ? t === 'dark' : window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches;
  }
  var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  var LOCALE = [{ name: 'es', options: {
    months: MESES.map(function (m) { return m[0].toUpperCase() + m.slice(1); }),
    shortMonths: ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'],
    days: ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'],
    shortDays: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'],
    toolbar: { exportToSVG: 'Descargar SVG', exportToPNG: 'Descargar PNG', exportToCSV: 'Descargar CSV', reset: 'Restablecer', zoomIn: 'Acercar', zoomOut: 'Alejar', pan: 'Mover', selection: 'Elegir', selectionZoom: 'Acercar lo elegido' } } }];
  function ts(f) { var p = f.split('-'); return Date.UTC(+p[0], +p[1] - 1, +p[2]); }
  function fecha(t) { var d = new Date(t); return d.getUTCDate() + ' de ' + MESES[d.getUTCMonth()] + ' de ' + d.getUTCFullYear(); }

  // El anillo del % (cabecera del release): lo real por fuera y lo esperado hoy por dentro
  function anillo(el) {
    if (el.__rlGraf) return;
    el.__rlGraf = true;
    var pct = +el.getAttribute('data-pct') || 0, esp = +el.getAttribute('data-esp') || 0;
    apex(el.getAttribute('data-apex')).then(function () {
      var sz = parseInt(getComputedStyle(el).getPropertyValue('--sz'), 10) || 112, alto = Math.round(sz * 1.3);
      var real = token(el, '--s', '#2970EB'), meta = token(el, '--chart-1', '#2970EB'), ink = token(el, '--ink', '#1F2A37'), track = token(el, '--progress-track', '#DCE8F0');
      el.innerHTML = '';
      el.classList.add('is-apex');
      el.style.setProperty('--sz-apex', alto + 'px');
      new ApexCharts(el, {
        chart: { type: 'radialBar', height: alto, width: alto, sparkline: { enabled: true }, fontFamily: token(document.body, '--font-sans', 'Inter, system-ui, sans-serif'),
          animations: { enabled: !matchMedia('(prefers-reduced-motion: reduce)').matches, speed: 600 } },
        series: [pct, esp], labels: ['Real', 'Esperado hoy'], colors: [real, meta],
        plotOptions: { radialBar: { startAngle: 0, endAngle: 360, hollow: { size: '52%' },
          track: { background: track, strokeWidth: '100%', margin: 3 },
          dataLabels: { name: { show: false }, value: { show: true, offsetY: 9, fontSize: Math.round(sz * .24) + 'px', fontWeight: 700, color: ink, formatter: function (v) { return Math.round(v) + '%'; } },
            total: { show: true, formatter: function () { return pct + '%'; } } } } },
        stroke: { lineCap: 'round' },
        tooltip: { enabled: false },
        states: { hover: { filter: { type: 'none' } } }
      }).render();
    }).catch(function () { el.__rlGraf = false; });
  }

  function pintar(sec) {
    if (sec.matches('[data-rl-anillo]')) return anillo(sec);
    if (sec.__rlGraf) return;
    sec.__rlGraf = true;
    var d;
    try { d = JSON.parse(sec.getAttribute('data-rl-graf')); } catch (e) { return; }
    apex(sec.getAttribute('data-apex')).then(function () {
      var c = {
        real: token(sec, '--s', '#2970EB'), ink: token(sec, '--ink', '#1F2A37'), muted: token(sec, '--ink-muted', '#64748B'),
        line: token(sec, '--line', '#E2E8F0'), track: token(sec, '--progress-track', '#DCE8F0'), surface: token(sec, '--surface', '#fff'),
        ok: token(sec, '--chart-2', '#1E9E62'), meta: token(sec, '--chart-1', '#2970EB'), azul: token(sec, '--primary', '#2970EB'), gris: token(sec, '--line-strong', '#CBD5E1'),
        fuente: token(document.body, '--font-sans', 'Inter, system-ui, sans-serif')
      };
      var base = function (alto) {
        return { height: alto, fontFamily: c.fuente, foreColor: c.muted, toolbar: { show: false }, zoom: { enabled: false },
          animations: { enabled: !matchMedia('(prefers-reduced-motion: reduce)').matches, speed: 500 }, locales: LOCALE, defaultLocale: 'es',
          parentHeightOffset: 0, background: 'transparent' };
      };
      var tema = { mode: oscuro() ? 'dark' : 'light' };

      // 1. Lo real con la marca de lo esperado hoy
      var bala = sec.querySelector('[data-g=bala]');
      if (bala) {
        bala.innerHTML = '';
        bala.classList.add('is-apex');
        new ApexCharts(bala, {
          chart: Object.assign(base(92), { type: 'bar' }), theme: tema,
          series: [{ name: 'Avance', data: [{ x: 'Avance', y: d.avance, goals: d.esperado > 0 ? [{ name: 'Esperado hoy', value: d.esperado, strokeWidth: 4, strokeHeight: 30, strokeLineCap: 'round', strokeColor: c.meta }] : [] }] }],
          plotOptions: { bar: { horizontal: true, barHeight: '62%', borderRadius: 7, borderRadiusApplication: 'end',
            colors: { backgroundBarColors: [c.track], backgroundBarRadius: 7 }, dataLabels: { position: 'center' } } },
          colors: [c.real],
          dataLabels: { enabled: d.avance >= 8, formatter: function (v) { return v + ' %'; }, style: { fontSize: '12px', fontWeight: 700, colors: ['#fff'] } },
          xaxis: { min: 0, max: 100, tickAmount: 4, labels: { formatter: function (v) { return Math.round(v) + ' %'; }, style: { fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
          yaxis: { labels: { show: false } },
          grid: { borderColor: c.line, strokeDashArray: 3, padding: { top: -14, bottom: -4, left: 6, right: 14 }, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
          legend: { show: true, showForSingleSeries: true, position: 'top', horizontalAlign: 'right', fontSize: '12px', offsetY: 4,
            customLegendItems: d.esperado > 0 ? ['Real ' + d.avance + ' %', 'Esperado hoy ' + d.esperado + ' %'] : ['Real ' + d.avance + ' %'],
            markers: { fillColors: [c.real, c.meta], size: 5, shape: 'circle' } },
          tooltip: { theme: tema.mode, y: { formatter: function (v) { return v + ' %'; } } },
          states: { hover: { filter: { type: 'none' } }, active: { filter: { type: 'none' } } }
        }).render();
      }

      // 2. Sus tareas
      var tar = sec.querySelector('[data-g=tareas]'), t = d.tareas || {};
      if (tar && (t.hechas + t.curso + t.pend) > 0) {
        tar.innerHTML = '';
        tar.classList.add('is-apex');
        new ApexCharts(tar, {
          chart: Object.assign(base(64), { type: 'bar', stacked: true, stackType: '100%' }), theme: tema,
          series: [{ name: 'Completadas', data: [t.hechas] }, { name: 'En curso', data: [t.curso] }, { name: 'Por hacer', data: [t.pend] }],
          colors: [c.ok, c.azul, c.gris],
          plotOptions: { bar: { horizontal: true, barHeight: '70%', borderRadius: 5, borderRadiusApplication: 'around', borderRadiusWhenStacked: 'all' } },
          stroke: { width: 2, colors: [c.surface] },
          dataLabels: { enabled: true, formatter: function (v, o) { var n = o.w.config.series[o.seriesIndex].data[0]; return v >= 12 ? n : ''; }, style: { fontSize: '11px', fontWeight: 700, colors: ['#fff'] }, dropShadow: { enabled: false } },
          xaxis: { categories: ['Tareas'], labels: { show: false }, axisBorder: { show: false }, axisTicks: { show: false } },
          yaxis: { labels: { show: false } },
          grid: { show: false, padding: { top: -18, bottom: -10, left: 0, right: 0 } },
          legend: { show: true, position: 'bottom', horizontalAlign: 'left', fontSize: '12px', offsetY: -6, markers: { size: 5, shape: 'circle' }, itemMargin: { horizontal: 10 } },
          tooltip: { theme: tema.mode, y: { formatter: function (v, o) { var n = o.w.config.series[o.seriesIndex].data[0]; return n + (n === 1 ? ' tarea' : ' tareas'); } } },
          states: { hover: { filter: { type: 'darken', value: .9 } } }
        }).render();
      }

      // 3. El avance en el tiempo: real (escalones), plan (línea) y revisiones (puntos)
      var tie = sec.querySelector('[data-g=tiempo]');
      if (tie && d.inicio && d.fin && (d.plan || []).length > 1) {
        tie.hidden = false;
        var serie = [
          { name: 'Real', type: 'area', data: d.real.map(function (p) { return [ts(p[0]), p[1]]; }) },
          { name: 'Plan', type: 'line', data: d.plan.map(function (p) { return [ts(p[0]), p[1]]; }) }
        ];
        if (d.revisiones.length) serie.push({ name: 'Revisiones', type: 'scatter', data: d.revisiones.map(function (p) { return { x: ts(p[0]), y: p[1], tipo: p[2] }; }) });
        var min = Math.min(ts(d.inicio), ts(d.hoy)), max = Math.max(ts(d.fin), ts(d.hoy));
        new ApexCharts(tie.querySelector('[data-g-lienzo]'), {
          chart: Object.assign(base(230), { type: 'line' }), theme: tema, series: serie,
          colors: [c.real, c.muted, c.azul],
          stroke: { curve: ['stepline', 'straight', 'straight'], width: [2.5, 2, 0], dashArray: [0, 6, 0] },
          fill: { type: ['gradient', 'solid', 'solid'], gradient: { shadeIntensity: 0, opacityFrom: .28, opacityTo: .02, stops: [0, 100] } },
          markers: { size: [0, 0, 6], strokeColors: c.surface, strokeWidth: 2, hover: { sizeOffset: 2 } },
          xaxis: { type: 'datetime', min: min, max: max, labels: { datetimeUTC: true, style: { fontSize: '11px' }, datetimeFormatter: { month: 'MMM', day: 'dd MMM' } }, axisBorder: { color: c.line }, axisTicks: { color: c.line }, tooltip: { enabled: false } },
          yaxis: { min: 0, max: 100, tickAmount: 4, labels: { formatter: function (v) { return Math.round(v) + ' %'; }, style: { fontSize: '11px' } } },
          grid: { borderColor: c.line, strokeDashArray: 3, padding: { left: 4, right: 12 } },
          annotations: { xaxis: [{ x: ts(d.hoy), borderColor: c.ink, strokeDashArray: 0, opacity: .5, label: { text: 'Hoy', orientation: 'horizontal', borderColor: c.ink, offsetY: -6, style: { background: c.ink, color: c.surface, fontSize: '11px', fontWeight: 600, padding: { left: 6, right: 6, top: 2, bottom: 2 } } } }] },
          legend: { show: true, position: 'top', horizontalAlign: 'right', fontSize: '12px', markers: { size: 5, shape: 'circle' } },
          tooltip: { theme: tema.mode, shared: false, intersect: false, x: { formatter: function (v) { return fecha(v); } },
            y: { formatter: function (v, o) { var p = o && o.w && o.w.config.series[o.seriesIndex].data[o.dataPointIndex]; return v == null ? '' : Math.round(v) + ' %' + (p && p.tipo ? ' · ' + p.tipo : ''); } } }
        }).render();
      }
    }).catch(function () { sec.__rlGraf = false; });
  }

  // Cuando la tarjeta se ve (también la de un panel que se abre después)
  var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (es) {
    es.forEach(function (e) { if (e.isIntersecting) { io.unobserve(e.target); pintar(e.target); } });
  }, { rootMargin: '120px' }) : null;
  function buscar(raiz) {
    (raiz.querySelectorAll ? raiz.querySelectorAll('section[data-rl-graf], [data-rl-anillo]') : []).forEach(function (s) {
      if (s.__rlVisto) return;
      s.__rlVisto = true;
      io ? io.observe(s) : pintar(s);
    });
  }
  function iniciar() {
    buscar(document);
    new MutationObserver(function (ms) {
      ms.forEach(function (m) { m.addedNodes.forEach(function (n) { if (n.nodeType === 1) { if (n.matches && n.matches('section[data-rl-graf], [data-rl-anillo]')) buscar(n.parentNode); else buscar(n); } }); });
    }).observe(document.body, { childList: true, subtree: true });
  }
  document.readyState === 'loading' ? document.addEventListener('DOMContentLoaded', iniciar) : iniciar();
})();
