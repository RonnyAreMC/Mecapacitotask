/* =========================================================
   Requerimientos sueltos: reparto equitativo (requerimientos.php y
   requerimiento.php). Va después de admin.js, que ya filtra la lista de
   personas por equipo y por nombre; aquí solo se suma:
   - ocultar el título de un equipo cuando el filtro deja su grupo vacío;
   - los «Sugeridos», que marcan o desmarcan a la persona de un toque;
   - el gráfico «Reparto entre compañeros» (ApexCharts), con los tonos de la
     colorimetría: resueltos = Cumple, en curso = Finalizado, vencidos =
     Crítico, y el promedio como marca gris (Sin medición).
   ========================================================= */
(() => {
  /* ---------- Selector de responsables ---------- */
  document.querySelectorAll('[data-picker]').forEach((lista) => {
    const picker = lista.dataset.picker;
    const grupos = () => {
      lista.querySelectorAll('[data-dv-grupo]').forEach((g) => {
        const hay = [...g.querySelectorAll('.dv-persona')].some((f) => !f.classList.contains('filtrado'));
        g.classList.toggle('is-vacio', !hay);
      });
    };
    // Después del filtro de admin.js (su oyente se registró antes que este)
    const luego = () => setTimeout(grupos, 0);
    document.querySelector(`.js-${picker}-buscar`)?.addEventListener('input', luego);
    document.querySelector(`.js-${picker}-rol`)?.addEventListener('change', luego);

    const sug = document.querySelector(`[data-sugeridos="${picker}"]`);
    if (!sug) return;
    const casilla = (id) => lista.querySelector(`input[name="asignados[]"][value="${id}"]`);
    const pintar = () => sug.querySelectorAll('[data-marcar]').forEach((b) => {
      const on = !!casilla(b.dataset.marcar)?.checked;
      b.classList.toggle('is-marcado', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    sug.addEventListener('click', (e) => {
      const b = e.target.closest('[data-marcar]');
      const c = b && casilla(b.dataset.marcar);
      if (!c) return;
      c.checked = !c.checked;
      c.dispatchEvent(new Event('change', { bubbles: true }));   // el contador y el resumen de admin.js
    });
    lista.addEventListener('change', pintar);
    pintar();
  });

  /* ---------- Gráfico «Reparto entre compañeros» ---------- */
  const D = window.REQ_REPARTO;
  const el = document.getElementById('rp-grafico');
  if (!D || !el || !D.filas || !D.filas.length) return;
  let grafico = null;

  const dibujar = () => {
    if (typeof ApexCharts === 'undefined') return;
    if (grafico) { try { grafico.destroy(); } catch (e) { /* ya no estaba */ } grafico = null; }
    const css = getComputedStyle(document.documentElement);
    const t = (n, d) => (css.getPropertyValue(n).trim() || d);
    const oscuro = document.documentElement.classList.contains('dark');
    const cText = t('--c-text', oscuro ? '#e9edf6' : '#1e2430');
    const cMuted = t('--c-text-muted', oscuro ? '#b6c0d2' : '#6b7688');
    const cGrid = oscuro ? 'rgba(255,255,255,.08)' : 'rgba(30,55,100,.10)';
    const sup = t('--n-bg', oscuro ? '#262c3a' : '#e5eaf3');
    const filas = D.filas;
    const max = Math.max(D.promedio || 0, ...filas.map((f) => f.total));
    const marcas = Math.min(Math.max(1, Math.ceil(max)), 6);
    const tope = Math.ceil(Math.max(1, max) / marcas) * marcas;

    grafico = new ApexCharts(el, {
      chart: {
        type: 'bar', stacked: true, height: Math.max(200, filas.length * 34 + 70),
        fontFamily: 'inherit', foreColor: cMuted, background: 'transparent',
        toolbar: { show: false }, parentHeightOffset: 0,
        animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches, speed: 400, animateGradually: { enabled: false } },
      },
      series: [
        { name: 'Resueltos', data: filas.map((f) => f.resueltos) },
        { name: 'En curso',  data: filas.map((f) => f.curso) },
        { name: 'Vencidos',  data: filas.map((f) => f.vencidos) },
      ],
      colors: [t('--col-ok-solid', '#1E9E62'), t('--col-done-solid', '#2970EB'), t('--col-crit-solid', '#D92D20')],
      plotOptions: { bar: { horizontal: true, barHeight: '62%', borderRadius: 5, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
      stroke: { show: true, width: 2, colors: [sup] },
      dataLabels: { enabled: false },
      xaxis: {
        categories: filas.map((f) => f.nombre), min: 0, max: tope, tickAmount: marcas,
        labels: { formatter: (n) => String(Math.round(n)) },
      },
      yaxis: { labels: { maxWidth: 160, style: { colors: cText, fontSize: '12.5px', fontWeight: 600 } } },
      grid: { borderColor: cGrid, padding: { top: 0, right: 16, bottom: 0, left: 4 }, xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
      annotations: D.promedio > 0 ? {
        xaxis: [{
          x: D.promedio, borderColor: t('--col-nm-solid', '#76839A'), strokeDashArray: 0, borderWidth: 2,
          label: {
            text: 'Promedio ' + String(D.promedio).replace('.', ','), orientation: 'horizontal', position: 'top',
            borderColor: 'transparent', offsetY: -6,
            style: { background: t('--col-nm-bg', '#EEF1F5'), color: t('--col-nm-fg', '#4A5568'), fontSize: '11px', fontWeight: 700 },
          },
        }],
      } : {},
      legend: {
        position: 'bottom', horizontalAlign: 'left', fontSize: '12.5px', fontWeight: 600,
        labels: { colors: cText }, markers: { width: 9, height: 9, radius: 9 }, itemMargin: { horizontal: 9, vertical: 2 },
      },
      tooltip: {
        theme: oscuro ? 'dark' : 'light', shared: true, intersect: false,
        x: { formatter: (v, o) => { const f = filas[o.dataPointIndex]; return f ? f.completo + ' · ' + f.equipo + ' · ' + f.total + ' en total' : v; } },
        y: { formatter: (n) => String(n) },
      },
      states: { hover: { filter: { type: 'lighten', value: 0.06 } } },
    });
    grafico.render();
  };

  // apexcharts.min.js va con defer: se dibuja cuando ya está
  if (typeof ApexCharts !== 'undefined') dibujar();
  else window.addEventListener('load', dibujar, { once: true });
  document.addEventListener('meca:tema', () => setTimeout(dibujar, 30));
})();
