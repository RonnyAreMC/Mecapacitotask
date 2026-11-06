/* =========================================================
   Analítica (BI) del proyecto — analitica.php.

   Los datos llegan ya ordenados por fecha en
   <script type="application/json" id="an-datos">. Aquí se filtran
   (periodo, release, sprint y equipo), se agregan y se dibujan con
   ApexCharts, todo en el navegador: así los filtros son instantáneos.
   La URL guarda los filtros (?desde, hasta, r, s, e, rango) para
   compartir la misma vista.

   Criterios en todos los gráficos:
   - Los colores son SOLO los de la colorimetría (--col-*-solid, los que
     se eligen en Colorimetría): hecho = Cumple, en curso = Finalizado
     (azul), en revisión o en riesgo = Alerta, vencido o bloqueado =
     Crítico, por hacer = Sin iniciar y lo de referencia (plan, ideal,
     comprometido, esperado) = Sin medición; lo real, según su salud.
     Ejes, rejilla y fondo: los tokens neutros del tema,
     leídas con getComputedStyle: por eso siguen al modo oscuro. Al
     cambiar de tema (evento meca:tema) se vuelven a dibujar.
   - Fechas siempre como texto 'AAAA-MM-DD' (se comparan como texto) y,
     para los ejes de fecha, en UTC: así no se corre un día en Ecuador.
   - Cada gráfico sin datos deja su estado vacío («Todavía no hay datos…»).
   ========================================================= */
(() => {
  'use strict';
  const nodo = document.getElementById('an-datos');
  const raiz = document.querySelector('[data-an]');
  if (!nodo || !raiz) return;
  let D;
  try { D = JSON.parse(nodo.textContent || '{}'); } catch (e) { return; }
  const HOY = D.hoy;

  /* ---------- Fechas y números ---------- */
  const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
  const MESES_L = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  const DIAS = ['lun', 'mar', 'mié', 'jue', 'vie', 'sáb', 'dom'];
  const DIAS_L = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
  const DIA_MS = 864e5;
  const ms = (f) => Date.UTC(+f.slice(0, 4), +f.slice(5, 7) - 1, +f.slice(8, 10));
  const iso = (t) => new Date(t).toISOString().slice(0, 10);
  const mas = (f, n) => iso(ms(f) + n * DIA_MS);
  const dsem = (f) => (new Date(ms(f)).getUTCDay() + 6) % 7;   // 0 = lunes
  const lunes = (f) => mas(f, -dsem(f));
  const mes1 = (f) => f.slice(0, 8) + '01';
  const sigMes = (f) => { const y = +f.slice(0, 4), m = +f.slice(5, 7); return iso(Date.UTC(m === 12 ? y + 1 : y, m % 12, 1)); };
  const nDias = (a, b) => Math.round((ms(b) - ms(a)) / DIA_MS);
  const fc = (f) => (f ? +f.slice(8, 10) + ' ' + MESES[+f.slice(5, 7) - 1] : '—');
  const fl = (f) => (f ? fc(f) + ' ' + f.slice(0, 4) : '—');
  const fdia = (f) => DIAS_L[dsem(f)] + ' ' + fl(f);
  const mesL = (f) => MESES_L[+f.slice(5, 7) - 1] + ' ' + f.slice(0, 4);
  const menor = (a, b) => (a < b ? a : b);
  const mayor = (a, b) => (a > b ? a : b);
  const en = (f, a, b) => !!f && f >= a && f <= b;
  const NF = new Intl.NumberFormat('es-EC', { maximumFractionDigits: 1 });
  const n1 = (x) => NF.format(x);
  const suma = (l, f) => l.reduce((a, x) => a + (f ? f(x) : x), 0);
  const prom = (l) => (l.length ? suma(l) / l.length : null);
  const pl = (n, uno, varios) => n1(n) + ' ' + (n === 1 ? uno : varios);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const corto = (n) => String(n || '').trim().split(/\s+/).slice(0, 2).join(' ');
  const tonoPct = (p) => (p == null ? 'nm' : p >= 80 ? 'ok' : p >= 60 ? 'warn' : 'crit');

  /* ---------- Catálogos ---------- */
  const SP = new Map(D.sprints.map((s) => [s.c, s]));
  const REL = new Map(D.releases.map((r) => [r.c, r]));
  const FINALES = new Set(D.estados.filter((e) => e.fin).map((e) => e.k));
  const PRIO_HU = [['alta', 'Alta'], ['media', 'Media'], ['baja', 'Baja']];
  const DL_CUENTAN = ['cumplida', 'incompleta', 'no_hecha', 'sin_zoom', 'sin_registrar'];
  // El peor estado del día manda cuando se juntan varios equipos
  const DL_PEOR = ['no_hecha', 'sin_registrar', 'incompleta', 'sin_zoom', 'cumplida', 'no_aplica', 'pendiente'];
  const DL_COD = { cumplida: 7, incompleta: 6, no_hecha: 5, sin_registrar: 4, sin_zoom: 3, no_aplica: 2, pendiente: 1 };

  /* ---------- ApexCharts en español ---------- */
  const ES = {
    name: 'es',
    options: {
      months: MESES_L,
      shortMonths: MESES,
      days: ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'],
      shortDays: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'],
      toolbar: {
        exportToSVG: 'Descargar SVG', exportToPNG: 'Descargar PNG', exportToCSV: 'Descargar CSV', menu: 'Descargar',
        selection: 'Seleccionar', selectionZoom: 'Acercar una zona', zoomIn: 'Acercar', zoomOut: 'Alejar', pan: 'Mover', reset: 'Volver a la vista completa',
      },
    },
  };

  /** Los colores del tema, leídos de las variables CSS (claro u oscuro). */
  const tema = () => {
    const css = getComputedStyle(document.documentElement);
    const v = (n, d) => css.getPropertyValue(n).trim() || d;
    const oscuro = document.documentElement.classList.contains('dark');
    const col = {}, colBg = {}, colFg = {};
    for (const k of ['ok', 'warn', 'crit', 'done', 'none', 'nm', 'hold']) {
      col[k] = v('--col-' + k + '-solid', '#76839A');
      colBg[k] = v('--col-' + k + '-bg', '#EAECF0');
      colFg[k] = v('--col-' + k + '-fg', '#475467');
    }
    return {
      oscuro, col, colBg, colFg,
      font: v('--font-sans', 'Inter, system-ui, sans-serif'),
      ink: v('--ink', oscuro ? '#E8EEF7' : '#2D3E50'),
      muted: v('--ink-muted', oscuro ? '#B6C0D2' : '#5A6B7E'),
      line: v('--line', oscuro ? '#2A3A4F' : '#DFE9F0'),
      surface: v('--surface', oscuro ? '#16202C' : '#FFFFFF'),
      sunken: v('--surface-sunken', oscuro ? '#101924' : '#EAF3F9'),
      primary: v('--primary', '#2970EB'),
      track: v('--progress-track', oscuro ? '#223445' : '#DCE8F0'),
      grid: oscuro ? 'rgba(255,255,255,.07)' : 'rgba(30,55,100,.09)',
    };
  };

  /* ---------- Filtros ---------- */
  const F = Object.assign({ desde: HOY, hasta: HOY, rel: '', sp: '', eq: '', rango: '' }, D.filtros || {});
  const inDesde = document.getElementById('an-desde');
  const inHasta = document.getElementById('an-hasta');
  const selRel = raiz.querySelector('select[data-an-f="rel"]');
  const selSp = raiz.querySelector('select[data-an-f="sp"]');
  const selEq = raiz.querySelector('select[data-an-f="eq"]');
  const btnRango = [...raiz.querySelectorAll('[data-an-rango]')];

  const rangoDe = (k) => {
    if (k === '30') return [mas(HOY, -29), HOY];
    if (k === '90') return [mas(HOY, -89), HOY];
    if (k === 'rel') { const r = REL.get(D.relActual); return r && r.ini && r.fin ? [r.ini, r.fin] : null; }
    if (k === 'todo') return [D.todo[0], D.todo[1]];
    return null;
  };

  /** La lista de sprints sigue al release elegido. */
  const opcionesSprint = () => {
    if (!selSp) return;
    const lista = D.sprints.filter((s) => !F.rel || s.rel.includes(F.rel));
    selSp.innerHTML = '<option value="todos">Todos los sprints</option>' + lista.map((s) => '<option value="' + esc(s.c) + '">'
      + esc(s.c + ' · ' + fc(s.ini) + ' – ' + fc(s.fin) + (s.c === D.spActual ? ' (en curso)' : '')) + '</option>').join('');
    if (F.sp && !lista.some((s) => s.c === F.sp)) F.sp = '';
    selSp.value = F.sp || 'todos';
  };

  const ponFecha = (inp, v) => { if (!inp) return; inp.value = v; inp.dispatchEvent(new Event('md-sync')); };
  const ponSelect = (sel, v) => { if (!sel) return; sel.value = v || 'todos'; if (window.DS && DS.refrescar) DS.refrescar(sel); };

  /** Deja los controles y la URL como dice F. */
  const sincronizar = () => {
    ponFecha(inDesde, F.desde);
    ponFecha(inHasta, F.hasta);
    ponSelect(selRel, F.rel);
    ponSelect(selSp, F.sp);
    ponSelect(selEq, F.eq);
    btnRango.forEach((b) => {
      const on = b.dataset.anRango === F.rango;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    try {
      const u = new URL(location.href);
      const q = u.searchParams;
      q.set('id', String(D.pid));
      q.set('desde', F.desde);
      q.set('hasta', F.hasta);
      [['rango', F.rango], ['r', F.rel], ['s', F.sp], ['e', F.eq]].forEach(([k, v]) => (v ? q.set(k, v) : q.delete(k)));
      history.replaceState(null, '', u);
    } catch (e) { /* sin URL nueva: los filtros igual funcionan */ }
  };

  const aplicarRango = (k) => {
    const r = rangoDe(k);
    if (!r) return;
    [F.desde, F.hasta] = r;
    F.rango = k;
    F.rel = k === 'rel' ? D.relActual : '';
    F.sp = '';
    opcionesSprint();
    sincronizar();
    pintarTodo();
  };

  btnRango.forEach((b) => b.addEventListener('click', () => aplicarRango(b.dataset.anRango)));
  const alCambiarFecha = (inp, cual) => {
    if (!inp) return;
    inp.addEventListener('change', () => {
      const v = inp.value;
      if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) { ponFecha(inp, F[cual]); return; }   // borrada: vuelve la de antes
      F[cual] = v;
      if (F.desde > F.hasta) [F.desde, F.hasta] = [F.hasta, F.desde];
      F.rango = '';
      sincronizar();
      pintarTodo();
    });
  };
  alCambiarFecha(inDesde, 'desde');
  alCambiarFecha(inHasta, 'hasta');
  if (selRel) selRel.addEventListener('change', () => {
    F.rel = selRel.value === 'todos' ? '' : selRel.value;
    const r = REL.get(F.rel);
    if (r && r.ini && r.fin) { F.desde = r.ini; F.hasta = r.fin; F.rango = F.rel === D.relActual ? 'rel' : ''; }
    opcionesSprint();
    sincronizar();
    pintarTodo();
  });
  if (selSp) selSp.addEventListener('change', () => {
    F.sp = selSp.value === 'todos' ? '' : selSp.value;
    const s = SP.get(F.sp);
    if (s) { F.desde = s.ini; F.hasta = s.fin; F.rango = ''; }
    sincronizar();
    pintarTodo();
  });
  if (selEq) selEq.addEventListener('change', () => { F.eq = selEq.value === 'todos' ? '' : selEq.value; sincronizar(); pintarTodo(); });

  /* ---------- Dibujar ---------- */
  const vivos = new Map();
  const icoVacio = (document.getElementById('an-ico-vacio') || {}).innerHTML || '';
  const caja = (id) => document.getElementById('an-g-' + id);
  const soltar = (id) => {
    const c = vivos.get(id);
    if (c) { try { c.destroy(); } catch (e) { /* ya no estaba */ } vivos.delete(id); }
  };
  const vacio = (id, msg) => {
    const el = caja(id);
    if (!el) return;
    soltar(id);
    el.classList.add('is-vacio');
    el.innerHTML = '<div class="an-vacio">' + icoVacio + '<p>' + esc(msg) + '</p></div>';
  };
  const pintar = (id, opts, alto) => {
    const el = caja(id);
    if (!el) return;
    soltar(id);
    el.classList.remove('is-vacio');
    el.innerHTML = '';
    if (alto) el.style.setProperty('--alto', alto + 'px');
    try {
      const c = new ApexCharts(el, opts);
      c.render();
      vivos.set(id, c);
    } catch (e) {
      console.error('Analítica:', id, e);
      vacio(id, 'No se pudo dibujar este gráfico.');
    }
  };
  const sub = (clave, txt) => { const el = raiz.querySelector('[data-an-sub="' + clave + '"]'); if (el) el.textContent = txt || ''; };

  const esObj = (x) => !!x && typeof x === 'object' && !Array.isArray(x);
  const mezclar = (a, b) => {
    if (!esObj(b)) return a;
    const o = Object.assign({}, a);
    for (const k of Object.keys(b)) o[k] = esObj(a[k]) && esObj(b[k]) ? mezclar(a[k], b[k]) : b[k];
    return o;
  };

  /** Lo común a todos los gráficos; `extra` lo completa o lo cambia. */
  const base = (T, anim, extra) => mezclar({
    chart: {
      fontFamily: T.font, foreColor: T.muted, background: 'transparent', locales: [ES], defaultLocale: 'es',
      parentHeightOffset: 0, redrawOnParentResize: true, zoom: { enabled: false },
      // Solo el menú de descarga (PNG, SVG, CSV): las fechas ya se eligen en los filtros
      toolbar: { show: true, offsetY: -10, tools: { download: true, selection: false, zoom: false, zoomin: false, zoomout: false, pan: false, reset: false } },
      // Sin «animateGradually»: si no, las barras crecen en fila y por un momento miden menos de lo que valen
      animations: { enabled: anim, speed: 380, animateGradually: { enabled: false }, dynamicAnimation: { enabled: false } },
    },
    theme: { mode: T.oscuro ? 'dark' : 'light' },
    grid: { borderColor: T.grid, strokeDashArray: 0, padding: { top: 0, right: 12, bottom: 0, left: 6 } },
    dataLabels: { enabled: false },
    legend: {
      position: 'bottom', horizontalAlign: 'center', fontSize: '12px', fontWeight: 600, labels: { colors: T.ink },
      markers: { size: 5, shape: 'circle', strokeWidth: 0 }, itemMargin: { horizontal: 8, vertical: 2 },
    },
    tooltip: { theme: T.oscuro ? 'dark' : 'light', style: { fontSize: '12px', fontFamily: T.font } },
    states: { hover: { filter: { type: 'lighten' } }, active: { filter: { type: 'none' } } },
    xaxis: { axisBorder: { color: T.line }, axisTicks: { color: T.line }, labels: { style: { colors: T.muted, fontSize: '11.5px' } }, tooltip: { enabled: false } },
    yaxis: { labels: { style: { colors: T.muted, fontSize: '11.5px' } } },
    noData: { text: 'Todavía no hay datos', style: { color: T.muted, fontSize: '13px' } },
  }, extra);

  /** Eje de enteros con pasos redondos (1, 2, 5, 10, 20, 25, 50…): no se repiten etiquetas y se lee de un vistazo. */
  const ejeEntero = (T, max, extra) => {
    const alto = Math.max(1, Math.ceil(max));
    let paso = 1;
    for (const p of [1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000]) { paso = p; if (alto / p <= 5) break; }
    const marcas = Math.max(1, Math.ceil(alto / paso));
    return mezclar({
      min: 0, max: marcas * paso, tickAmount: marcas, forceNiceScale: false,
      labels: { style: { colors: T.muted, fontSize: '11.5px' }, formatter: (n) => String(Math.round(n)) },
    }, extra);
  };
  const ejePct = (T) => ({ min: 0, max: 100, tickAmount: 5, labels: { style: { colors: T.muted, fontSize: '11.5px' }, formatter: (n) => Math.round(n) + ' %' } });
  const linea = (T, y, texto, color) => ({
    y, borderColor: color, strokeDashArray: 5, borderWidth: 1.5,
    label: { text: texto, borderColor: 'transparent', position: 'left', textAnchor: 'start', offsetX: 6, style: { background: T.surface, color, fontSize: '11px', fontWeight: 600, fontFamily: T.font } },
  });

  /** Una dona con el total al centro. */
  /** Reparto por categoría en anillos concéntricos (radialBar abierto abajo): cada anillo es
      su parte del total; al centro, el total, y al pasar por un anillo, su parte. */
  const dona = (T, anim, id, filas, centro, unidad) => {
    const tot = suma(filas, (f) => f.v);
    const n = filas.length;
    pintar(id, base(T, anim, {
      chart: { type: 'radialBar', height: 310 },
      series: filas.map((f) => Math.round(f.v * 1000 / (tot || 1)) / 10),
      labels: filas.map((f) => f.n), colors: filas.map((f) => f.c),
      plotOptions: { radialBar: {
        startAngle: -135, endAngle: 135, offsetY: 6,
        hollow: { size: n <= 2 ? '46%' : n <= 4 ? '34%' : '24%', background: 'transparent' },
        track: { background: T.track, strokeWidth: '100%', margin: n > 4 ? 3 : 5 },
        dataLabels: {
          name: { show: true, fontSize: '12px', fontWeight: 600, color: T.muted, offsetY: -4 },
          value: { show: true, fontSize: '22px', fontWeight: 700, color: T.ink, offsetY: 6, formatter: (v) => Math.round(v) + ' %' },
          total: { show: true, label: centro, fontSize: '12px', fontWeight: 600, color: T.muted, formatter: () => n1(tot) },
        },
      } },
      stroke: { lineCap: 'round' },
      legend: { show: true, position: 'bottom', fontSize: '12px', fontWeight: 600, labels: { colors: T.ink }, markers: { size: 5, shape: 'circle' },
        formatter: (nom, o) => nom + ' · ' + pl(filas[o.seriesIndex].v, unidad[0], unidad[1]) },
      tooltip: { enabled: false },
    }));
  };
  const tip = (titulo, lineas) => '<div class="an-tip"><b>' + esc(titulo) + '</b>' + lineas.map((l) => '<span>' + l + '</span>').join('') + '</div>';
  const punto = (color) => '<i style="--x:' + esc(color) + '"></i>';

  /* ---------- El alcance de los filtros ---------- */
  const contexto = () => {
    const desde = F.desde, hasta = F.hasta, fin = menor(F.hasta, HOY);
    const relOk = (r) => !F.rel || r === F.rel;
    const eqOk = (e) => !F.eq || e === F.eq;
    // Los sprints del periodo (y del release) que ya empezaron
    const spRango = D.sprints.filter((s) => s.ini <= hasta && s.fin >= desde && s.ini <= HOY && (!F.rel || s.rel.includes(F.rel)));
    const spSet = new Set(spRango.map((s) => s.c));
    // Con sprint elegido, las suyas; si no, las de los sprints del periodo y las del backlog
    const hu = D.historias.filter((h) => relOk(h.r) && (F.sp ? h.vis.includes(F.sp) : (!h.vis.length || h.vis.some((c) => spSet.has(c)))));
    // Para comparar sprints: con uno elegido, él y los siete anteriores
    let spLista = spRango;
    if (F.sp && SP.has(F.sp)) {
      const s = SP.get(F.sp);
      spLista = D.sprints.filter((x) => x.n <= s.n && (!F.rel || x.rel.includes(F.rel)) && (x.ini <= HOY || x.c === F.sp)).slice(-8);
    }
    const items = D.items.filter((it) => relOk(it.r) && eqOk(it.eq));
    const itemsRango = items.filter((it) => (it.ini || '0000') <= hasta && (it.fin || '9999') >= desde);
    const dls = D.dailies.filter((d) => eqOk(d.eq));
    // Las tareas que estuvieron abiertas en algún momento del periodo
    const vivas = D.tareas.filter((t) => (t.c || desde) <= fin && (!t.f || t.f >= desde));
    return { desde, hasta, fin, relOk, eqOk, spRango, spSet, spLista, hu, items, itemsRango, dls, vivas };
  };

  /** Semanas (o meses, si el periodo es largo) entre dos fechas. */
  const cubetas = (desde, fin) => {
    const l = [];
    if (fin < desde) return { semanal: true, l };
    const semanal = nDias(desde, fin) <= 140;
    if (semanal) for (let f = lunes(desde); f <= fin; f = mas(f, 7)) l.push({ ini: f, fin: mas(f, 6) });
    else for (let f = mes1(desde); f <= fin; f = sigMes(f)) l.push({ ini: f, fin: mas(sigMes(f), -1) });
    l.forEach((b) => { b.a = mayor(b.ini, desde); b.b = menor(b.fin, fin); });
    return { semanal, l };
  };
  const etiquetaCubeta = (semanal, f) => (semanal ? 'Semana del ' + fl(f) : mesL(f)[0].toUpperCase() + mesL(f).slice(1));

  /* ---------- Resumen ---------- */
  const kpi = (clave, val, pie, tono) => {
    const el = raiz.querySelector('[data-an-kpi="' + clave + '"]');
    if (!el) return;
    if (val != null) el.querySelector('b').innerHTML = val;
    el.querySelector('em').textContent = pie || '';
    if (tono) {
      el.classList.remove('rl-kpi--azul');
      [...el.classList].filter((c) => c.startsWith('col-s-')).forEach((c) => el.classList.remove(c));
      el.classList.add(tono === 'azul' ? 'rl-kpi--azul' : 'col-s-' + tono);
    }
  };
  const diasDailies = (C) => {
    const l = [];
    C.dls.forEach((d) => d.dias.forEach((x) => { if (en(x.f, C.desde, C.fin)) l.push(Object.assign({ eq: d.eq }, x)); }));
    return l;
  };
  const resumen = (T, C) => {
    const creadas = D.tareas.filter((t) => en(t.c, C.desde, C.fin)).length;
    const hechas = D.tareas.filter((t) => en(t.f, C.desde, C.fin)).length;
    const abiertas = D.tareas.filter((t) => !FINALES.has(t.e));
    const vencidas = abiertas.filter((t) => t.l && t.l < HOY).length;
    kpi('tareas', n1(D.kpi.total), '+' + n1(creadas) + ' en el periodo');
    kpi('completadas', n1(D.kpi.completadas), n1(hechas) + ' en el periodo');
    kpi('vencidas', n1(vencidas), 'de ' + pl(abiertas.length, 'abierta', 'abiertas'), vencidas ? 'crit' : 'ok');
    kpi('avance', D.kpi.avance + '<small>%</small>', n1(D.kpi.completadas) + ' de ' + pl(D.kpi.total, 'tarea', 'tareas'));
    const sa = SP.get(D.spActual);
    const huSa = D.historias.filter((h) => h.s === D.spActual);
    if (sa && huSa.length) {
      const tot = suma(huSa, (h) => h.pt), hechosPt = suma(huSa.filter((h) => h.e === 'hecho'), (h) => h.pt);
      kpi('sprint', n1(hechosPt) + '<small>/' + n1(tot) + ' pts</small>', sa.c + ' · ' + (sa.dq ? 'quedan ' + pl(sa.dq, 'día', 'días') : 'último día'));
    } else {
      kpi('sprint', '—', sa ? sa.c + ' sin historias todavía' : 'Sin sprints en Ajustes');
    }
    kpi('velocidad', D.kpi.vel != null ? n1(D.kpi.vel) + '<small>pts</small>' : '—', D.kpi.vel != null ? 'Últimos 3 sprints' : 'Sin sprints cerrados');
    const dd = diasDailies(C);
    const cuentan = dd.filter((x) => DL_CUENTAN.includes(x.e));
    const pct = cuentan.length ? Math.round(cuentan.filter((x) => x.e === 'cumplida').length * 100 / cuentan.length) : null;
    const asis = prom(dd.filter((x) => x.as != null && (x.e === 'cumplida' || x.e === 'incompleta')).map((x) => x.as));
    kpi('dailies', pct == null ? '—' : pct + '<small>%</small>',
      asis != null ? 'Asistencia ' + Math.round(asis) + ' %' : (D.dailies.length ? 'Sin días medidos' : 'Sin equipo en Releases'), tonoPct(pct));
    const mini = (k, v) => { const el = raiz.querySelector('[data-an-mini="' + k + '"]'); if (el) el.textContent = v; };
    mini('periodo', fc(C.desde) + ' → ' + fl(C.hasta));
    mini('sprints', n1(C.spRango.length));
    mini('historias', n1(C.hu.length));
    mini('items', n1(C.itemsRango.length));
    sub('resumen', 'Hoy, ' + fdia(HOY));
    const per = raiz.querySelector('[data-an-periodo]');
    if (per) {
      const extra = [];
      if (F.rel && REL.get(F.rel)) extra.push('Release <b>' + esc(F.rel) + '</b>');
      if (F.sp) extra.push('Sprint <b>' + esc(F.sp) + '</b>');
      if (F.eq) extra.push('Equipo <b>' + esc(F.eq) + '</b>');
      per.innerHTML = 'Del <b>' + esc(fl(C.desde)) + '</b> al <b>' + esc(fl(C.hasta)) + '</b> · ' + pl(nDias(C.desde, C.hasta) + 1, 'día', 'días')
        + (extra.length ? ' · ' + extra.join(' · ') : '');
    }
  };

  /* ---------- Sprints ---------- */
  const porSprint = (C) => {
    let l = C.spLista.map((s) => ({
      s,
      comp: suma(D.historias.filter((h) => C.relOk(h.r) && h.vis.includes(s.c)), (h) => h.pt),
      hecho: suma(D.historias.filter((h) => C.relOk(h.r) && h.e === 'hecho' && en(h.h, s.ini, s.fin)), (h) => h.pt),
      pasaron: D.historias.filter((h) => C.relOk(h.r) && h.sal.includes(s.c)).length,
    }));
    // Los sprints de antes de usar el backlog no dicen nada: se recortan
    const i = l.findIndex((x) => x.comp || x.hecho || x.pasaron);
    return i < 0 ? [] : l.slice(i);
  };
  const etiquetaSprint = (s) => s.c + ' · ' + fc(s.ini) + ' – ' + fl(s.fin);

  const velocidad = (T, C, anim) => {
    const l = porSprint(C).filter((x) => x.comp || x.hecho || x.s.ini <= HOY);
    if (!l.some((x) => x.comp || x.hecho)) { sub('vel', ''); return vacio('vel', 'Todavía no hay datos de sprints en este periodo: las historias con puntos salen aquí cuando entran a un sprint.'); }
    const cerrados = l.filter((x) => x.s.fin < HOY && x.hecho);
    const media = cerrados.length ? Math.round(suma(cerrados, (x) => x.hecho) / cerrados.length) : null;
    sub('vel', (media != null ? 'Velocidad media ' + n1(media) + ' pts' : 'Sin sprints cerrados') + (F.sp ? ' · ' + F.sp + ' elegido' : ''));
    pintar('vel', base(T, anim, {
      chart: { type: 'bar', height: 300 },
      series: [{ name: 'Comprometido', data: l.map((x) => x.comp) }, { name: 'Hecho', data: l.map((x) => x.hecho) }],
      colors: [T.col.nm, T.col.ok],
      plotOptions: { bar: { columnWidth: l.length <= 3 ? '38%' : '64%', borderRadius: 4, borderRadiusApplication: 'end' } },
      stroke: { show: true, width: 2, colors: ['transparent'] },
      xaxis: { categories: l.map((x) => x.s.c) },
      yaxis: ejeEntero(T, Math.max(...l.map((x) => Math.max(x.comp, x.hecho)), media || 0)),
      annotations: { yaxis: media != null ? [linea(T, media, 'Velocidad media ' + n1(media) + ' pts', T.col.nm)] : [] },
      tooltip: { shared: true, intersect: false, x: { formatter: (v, o) => (l[o.dataPointIndex] ? etiquetaSprint(l[o.dataPointIndex].s) : v) }, y: { formatter: (v) => pl(v, 'punto', 'puntos') } },
    }));
  };

  const COLOR_HU = (T) => ({ por_hacer: T.col.none, en_curso: T.col.done, en_revision: T.col.warn, hecho: T.col.ok });
  const huEstado = (T, C, anim) => {
    const c = COLOR_HU(T);
    const filas = D.huEstados.map((e) => ({ n: e.n, v: C.hu.filter((h) => h.e === e.k).length, c: c[e.k] || T.col.nm })).filter((f) => f.v);
    sub('hu-estado', C.hu.length ? pl(C.hu.length, 'historia', 'historias') + ' · ' + n1(suma(C.hu, (h) => h.pt)) + ' pts' : '');
    if (!filas.length) return vacio('hu-estado', 'Todavía no hay datos de historias en este periodo.');
    dona(T, anim, 'hu-estado', filas, 'Historias', ['historia', 'historias']);
  };

  /** El sprint del burndown: el elegido; si no, el de hoy (o el último del periodo con historias). */
  const sprintBurndown = (C) => {
    if (F.sp) return F.sp;
    if (D.burn[D.spActual] && C.spSet.has(D.spActual)) return D.spActual;
    const con = C.spRango.filter((s) => D.burn[s.c]);
    if (con.length) return con[con.length - 1].c;
    return D.burn[D.spActual] ? D.spActual : '';
  };
  const burndown = (T, C, anim) => {
    const sp = sprintBurndown(C);
    const s = SP.get(sp);
    const b = sp && D.burn[sp];
    sub('burn', s ? sp + ' · ' + fc(s.ini) + ' – ' + fc(s.fin) + (sp === D.spActual ? ' · en curso' : '') : '');
    if (!s || !b || !b.total || !b.dias.length) {
      return vacio('burn', s ? 'Todavía no hay datos del burndown del ' + sp + ': sus historias no tienen puntos estimados.' : 'Todavía no hay datos del burndown: elige un sprint con historias.');
    }
    const n = b.dias.length;
    const ideal = b.dias.map((_, i) => (n === 1 ? b.total : Math.round(b.total * (1 - i / (n - 1)) * 10) / 10));
    const cats = b.dias.map(([f]) => DIAS[dsem(f)] + ' ' + (+f.slice(8, 10)));
    const iHoy = b.dias.findIndex(([f]) => f === HOY);
    // Lo real, con el tono de la colorimetría según cuánto le falta frente a lo ideal (en el último día medido)
    const iUlt = iHoy >= 0 ? iHoy : (b.dias[n - 1][0] < HOY ? n - 1 : 0);
    const exceso = b.total ? (b.dias[iUlt][1] - ideal[iUlt]) / b.total : 0;
    const tonoReal = exceso <= 0.05 ? T.col.ok : exceso <= 0.2 ? T.col.warn : T.col.crit;
    pintar('burn', base(T, anim, {
      chart: { type: 'line', height: 300 },
      series: [{ name: 'Real', data: b.dias.map(([, v]) => v) }, { name: 'Ideal', data: ideal }],
      colors: [tonoReal, T.col.nm],
      stroke: { width: [3, 2], dashArray: [0, 6], curve: 'straight' },
      markers: { size: [4, 0], strokeWidth: 0, hover: { sizeOffset: 2 } },
      xaxis: { categories: cats },
      yaxis: ejeEntero(T, b.total),
      annotations: { xaxis: iHoy >= 0 ? [{ x: cats[iHoy], borderColor: T.col.crit, strokeDashArray: 0, label: { text: 'Hoy', borderColor: 'transparent', orientation: 'horizontal', style: { background: T.col.crit, color: '#fff', fontSize: '11px', fontWeight: 600 } } }] : [] },
      tooltip: { shared: true, intersect: false, x: { formatter: (v, o) => (b.dias[o.dataPointIndex] ? fdia(b.dias[o.dataPointIndex][0]) : v) }, y: { formatter: (v) => (v == null ? '—' : pl(v, 'punto', 'puntos') + ' por hacer') } },
    }));
  };

  const huPrioridad = (T, C, anim) => {
    const l = PRIO_HU.map(([k, n]) => {
      const hs = C.hu.filter((h) => h.pr === k);
      return { n, hecho: suma(hs.filter((h) => h.e === 'hecho'), (h) => h.pt), falta: suma(hs.filter((h) => h.e !== 'hecho'), (h) => h.pt) };
    });
    const tot = suma(l, (x) => x.hecho + x.falta);
    sub('hu-prio', tot ? n1(tot) + ' pts' : '');
    if (!tot) return vacio('hu-prio', 'Todavía no hay datos de puntos: estima las historias para verlas por prioridad.');
    pintar('hu-prio', base(T, anim, {
      chart: { type: 'bar', height: 300, stacked: true },
      series: [{ name: 'Hecho', data: l.map((x) => x.hecho) }, { name: 'Por hacer', data: l.map((x) => x.falta) }],
      colors: [T.col.ok, T.col.none],
      plotOptions: { bar: { columnWidth: '46%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
      stroke: { show: true, width: 2, colors: [T.surface] },
      xaxis: { categories: l.map((x) => x.n) },
      yaxis: ejeEntero(T, Math.max(...l.map((x) => x.hecho + x.falta))),
      tooltip: { shared: true, intersect: false, y: { formatter: (v) => pl(v, 'punto', 'puntos') } },
    }));
  };

  const arrastres = (T, C, anim) => {
    const l = porSprint(C);
    const tot = suma(l, (x) => x.pasaron);
    sub('arr', tot ? pl(tot, 'vez', 'veces') + ' en el periodo' : '');
    if (!tot) return vacio('arr', 'Todavía no hay datos de historias que pasaran de sprint en este periodo.');
    pintar('arr', base(T, anim, {
      chart: { type: 'bar', height: 280 },
      series: [{ name: 'Pasaron al siguiente', data: l.map((x) => x.pasaron) }],
      colors: [T.col.warn],
      plotOptions: { bar: { columnWidth: l.length <= 3 ? '34%' : '56%', borderRadius: 4, borderRadiusApplication: 'end' } },
      xaxis: { categories: l.map((x) => x.s.c) },
      yaxis: ejeEntero(T, Math.max(...l.map((x) => x.pasaron))),
      legend: { show: false },
      tooltip: { x: { formatter: (v, o) => (l[o.dataPointIndex] ? etiquetaSprint(l[o.dataPointIndex].s) : v) }, y: { formatter: (v) => pl(v, 'historia', 'historias') } },
    }));
  };

  const TONO_HU = { por_hacer: 'none', en_curso: 'done', en_revision: 'warn', hecho: 'ok' };
  const tablaArrastres = (T, C) => {
    const el = document.getElementById('an-t-arr');
    if (!el) return;
    const nombreEstado = Object.fromEntries(D.huEstados.map((e) => [e.k, e.n]));
    const nSp = (c) => (SP.get(c) || { n: 999 }).n;
    const l = D.historias.filter((h) => C.relOk(h.r) && h.arr > 0
      && (F.sp ? h.vis.includes(F.sp) : (h.sal.length ? h.sal.some((c) => C.spSet.has(c)) : h.vis.some((c) => C.spSet.has(c)))))
      .sort((a, b) => (nSp(a.sal[0] || a.vis[0]) - nSp(b.sal[0] || b.vis[0])) || a.c.localeCompare(b.c, 'es'));
    sub('arr-tabla', l.length ? pl(l.length, 'historia', 'historias') : '');
    if (!l.length) {
      el.innerHTML = '<div class="an-vacio">' + icoVacio + '<p>Todavía no hay datos de historias que pasaran de sprint en este periodo.</p></div>';
      return;
    }
    const filas = l.slice(0, 8).map((h) => '<tr><td><span class="an-tabla__hu"><b>' + esc(h.c) + '</b><span>' + esc(h.t) + '</span></span></td>'
      + '<td class="an-n mc-num">' + h.arr + '</td>'
      + '<td class="an-tabla__ruta">' + esc(h.vis.join(' → ') || '—') + '</td>'
      + '<td><span class="an-chip col-s-' + (TONO_HU[h.e] || 'nm') + '">' + esc((nombreEstado[h.e] || h.e) + (h.s ? ' · ' + h.s : ' · Backlog')) + '</span></td></tr>').join('');
    el.innerHTML = '<table class="an-tabla"><thead><tr><th scope="col">Historia</th><th scope="col">Veces</th><th scope="col">Recorrido</th><th scope="col">Ahora</th></tr></thead><tbody>'
      + filas + '</tbody></table>' + (l.length > 8 ? '<p class="an-tabla-mas">Y ' + pl(l.length - 8, 'historia más', 'historias más') + '.</p>' : '');
  };

  /* ---------- Release ---------- */
  const tonoGrupo = (its) => {
    if (its.every((it) => it.s === 'cerrado')) return 'done';
    const av = prom(its.map((it) => it.av)), esp = prom(its.map((it) => it.esp));
    if (!esp) return 'none';
    return av >= esp ? 'ok' : av / esp >= 0.7 ? 'warn' : 'crit';
  };
  const relRadial = (T, C, anim) => {
    const g = D.releases.map((r) => ({ r, its: C.items.filter((it) => it.r === r.c) })).filter((x) => x.its.length);
    if (!g.length) { sub('rel', ''); return vacio('rel', D.items.length ? 'Todavía no hay datos del release con estos filtros.' : 'Todavía no hay datos del release: el proyecto no tiene ítems en Releases.'); }
    g.forEach((x) => { x.av = Math.round(prom(x.its.map((it) => it.av))); x.esp = Math.round(prom(x.its.map((it) => it.esp))); x.k = tonoGrupo(x.its); });
    const media = Math.round(prom(g.map((x) => x.av)));
    sub('rel', 'Esperado hoy ' + Math.round(prom(g.map((x) => x.esp))) + ' %');
    pintar('rel', base(T, anim, {
      chart: { type: 'radialBar', height: 300 },
      series: g.map((x) => x.av), labels: g.map((x) => x.r.c), colors: g.map((x) => T.col[x.k]),
      plotOptions: { radialBar: {
        hollow: { size: g.length > 2 ? '34%' : '52%' },
        track: { background: T.track, strokeWidth: '100%', margin: 5 },
        dataLabels: {
          name: { fontSize: '12px', fontWeight: 600, color: T.muted },
          value: { fontSize: '22px', fontWeight: 700, color: T.ink, formatter: (v) => Math.round(v) + ' %' },
          total: { show: g.length > 1, label: 'Promedio', fontSize: '12px', fontWeight: 600, color: T.muted, formatter: () => media + ' %' },
        },
      } },
      stroke: { lineCap: 'round' },
      legend: { show: true, position: 'bottom', formatter: (n, o) => n + ' · ' + g[o.seriesIndex].av + ' % (esperado ' + g[o.seriesIndex].esp + ' %)' },
    }));
  };

  const relSalud = (T, C, anim) => {
    const orden = Object.keys(D.salud);
    const filas = orden.map((s) => {
      const its = C.itemsRango.filter((it) => it.s === s);
      return { n: D.salud[s], v: its.length, c: T.col[its.length ? its[0].k : 'nm'] };
    }).filter((f) => f.v);
    sub('salud', C.itemsRango.length ? pl(C.itemsRango.length, 'ítem', 'ítems') : '');
    if (!filas.length) return vacio('salud', 'Todavía no hay datos de ítems del release en este periodo.');
    dona(T, anim, 'salud', filas, 'Ítems', ['ítem', 'ítems']);
  };

  const revisiones = (T, C, anim) => {
    const varios = D.equipos.length > 1 && !F.eq;
    const enRango = D.revisiones.filter((r) => C.eqOk(r.eq) && en(r.f, C.desde, C.hasta));
    const l = enRango.filter((r) => r.est === 'realizada' && r.pct != null && r.f <= HOY);
    if (!l.length) {
      const prog = enRango.filter((r) => r.est !== 'realizada').length;
      sub('rev', prog ? pl(prog, 'programada', 'programadas') : '');
      return vacio('rev', 'Todavía no hay datos de revisiones realizadas en este periodo.' + (prog ? ' Hay ' + pl(prog, 'programada', 'programadas') + '.' : ''));
    }
    sub('rev', 'Promedio ' + Math.round(prom(l.map((r) => r.pct))) + ' %');
    pintar('rev', base(T, anim, {
      chart: { type: 'bar', height: 300 },
      series: [{ name: 'Cumplido', data: l.map((r) => ({ x: fc(r.f) + (varios ? ' · ' + r.eq : ''), y: r.pct, fillColor: T.col[tonoPct(r.pct)] })) }],
      plotOptions: { bar: { columnWidth: l.length <= 3 ? '34%' : '58%', borderRadius: 4, borderRadiusApplication: 'end' } },
      yaxis: ejePct(T),
      annotations: { yaxis: [linea(T, 80, 'Meta 80 %', T.col.ok)] },
      legend: { show: false },
      tooltip: { custom: ({ dataPointIndex: i }) => { const r = l[i]; return r ? tip('Revisión del ' + fl(r.f) + (varios ? ' · Equipo ' + r.eq : ''), [
        punto(T.col[tonoPct(r.pct)]) + 'Cumplido: ' + r.pct + ' %',
        r.cu + ' cumplidos · ' + r.pa + ' parciales · ' + r.nc + ' no cumplidos',
        'De ' + pl(r.pr, 'ítem programado', 'ítems programados')]) : ''; } },
    }));
  };

  const tipItem = (T, it) => tip(it.c + ' · ' + it.t, [
    punto(T.col[it.k]) + 'Salud: ' + esc(D.salud[it.s] || it.s),
    'Avance ' + it.av + ' % · esperado hoy ' + it.esp + ' %',
    esc((it.ini ? fl(it.ini) : 'sin inicio') + ' → ' + (it.fin ? fl(it.fin) : 'sin fin') + ' · Equipo ' + it.eq) + (it.nt ? ' · ' + pl(it.nt, 'tarea', 'tareas') : ''),
  ]);
  const altoFilas = (n, porFila, extra, min) => Math.max(min || 220, n * porFila + extra);

  const relItems = (T, C, anim) => {
    const l = C.itemsRango;
    sub('items', l.length ? pl(l.length, 'ítem', 'ítems') + ' · ' + l.filter((it) => it.av < it.esp).length + ' por debajo de lo esperado' : '');
    if (!l.length) return vacio('items', 'Todavía no hay datos de ítems del release en este periodo.');
    const alto = altoFilas(l.length, 30, 64);
    pintar('items', base(T, anim, {
      chart: { type: 'bar', height: alto },
      series: [{ name: 'Avance', data: l.map((it) => ({
        x: it.c, y: it.av, fillColor: T.col[it.k] || T.col.nm,
        goals: [{ name: 'Esperado hoy', value: it.esp, strokeHeight: 18, strokeWidth: 4, strokeLineCap: 'round', strokeColor: T.col.nm }],
      })) }],
      plotOptions: { bar: { horizontal: true, barHeight: '56%', borderRadius: 3, borderRadiusApplication: 'end' } },
      xaxis: { min: 0, max: 100, tickAmount: 5, labels: { formatter: (v) => Math.round(v) + ' %' } },
      yaxis: { labels: { style: { colors: T.ink, fontSize: '11.5px', fontWeight: 600 }, maxWidth: 120 } },
      legend: { show: false },
      tooltip: { custom: ({ dataPointIndex: i }) => (l[i] ? tipItem(T, l[i]) : '') },
    }), alto);
  };

  const relGantt = (T, C, anim) => {
    const l = C.itemsRango.filter((it) => it.ini && it.fin);
    sub('gantt', l.length ? fc(C.desde) + ' → ' + fl(C.hasta) : '');
    if (!l.length) return vacio('gantt', 'Todavía no hay datos de la línea de tiempo: no hay ítems con fechas en este periodo.');
    const alto = altoFilas(l.length, 28, 76);
    const hoyVisible = en(HOY, C.desde, C.hasta);
    pintar('gantt', base(T, anim, {
      chart: { type: 'rangeBar', height: alto },
      series: [{ name: 'Plan', data: l.map((it) => ({ x: it.c, y: [ms(it.ini), ms(it.fin) + DIA_MS - 1], fillColor: T.col[it.k] || T.col.nm })) }],
      plotOptions: { bar: { horizontal: true, barHeight: '58%', borderRadius: 3, rangeBarGroupRows: false } },
      xaxis: { type: 'datetime', min: ms(C.desde), max: ms(C.hasta) + DIA_MS, labels: { datetimeUTC: true, datetimeFormatter: { year: 'yyyy', month: "MMM 'yy", day: 'd MMM' } } },
      yaxis: { labels: { style: { colors: T.ink, fontSize: '11.5px', fontWeight: 600 }, maxWidth: 120 } },
      annotations: { xaxis: hoyVisible ? [{ x: ms(HOY), borderColor: T.col.crit, strokeDashArray: 0, borderWidth: 2, label: { text: 'Hoy', borderColor: 'transparent', orientation: 'horizontal', offsetY: -6, style: { background: T.col.crit, color: '#fff', fontSize: '11px', fontWeight: 600 } } }] : [] },
      legend: { show: false },
      tooltip: { custom: ({ dataPointIndex: i }) => (l[i] ? tipItem(T, l[i]) : '') },
    }), alto);
  };

  /* ---------- Reuniones diarias ---------- */
  const COLOR_DL = (T) => ({
    cumplida: T.col.ok, incompleta: T.col.warn, no_hecha: T.col.crit, sin_registrar: T.colBg.crit,
    sin_zoom: T.col.nm, no_aplica: T.colBg.none, pendiente: T.colBg.none,
  });
  /** Los días de los equipos elegidos, juntos por fecha (manda el peor estado). */
  const diasJuntos = (C) => {
    const m = new Map();
    diasDailies(C).forEach((x) => { if (!m.has(x.f)) m.set(x.f, []); m.get(x.f).push(x); });
    return m;
  };
  const peor = (l) => DL_PEOR.find((e) => l.some((x) => x.e === e)) || l[0].e;

  const dlCalendario = (T, C, anim) => {
    const ley = raiz.querySelector('[data-an-ley-dl]');
    const col = COLOR_DL(T);
    if (ley) ley.innerHTML = '<span class="an-leyendas">' + ['cumplida', 'incompleta', 'no_hecha', 'sin_registrar', 'sin_zoom', 'no_aplica']
      .map((k) => '<span class="an-ley">' + punto(col[k]) + esc(D.dailyEstados[k] || k) + '</span>').join('') + '<span class="an-ley">' + punto(T.colBg.hold) + 'Feriado</span></span>'
      + '<span class="an-ley-nota">Cada barra es un día hábil: su color es cómo salió la daily y su alto, cuánta gente se conectó.</span>';
    const m = diasJuntos(C);
    const desdeDl = C.dls.length ? C.dls.map((d) => d.desde).sort()[0] : '';
    if (!m.size) {
      sub('dl-cal', '');
      return vacio('dl-cal', !D.dailies.length ? 'Todavía no hay datos de reuniones diarias: el proyecto no tiene un equipo en Releases.'
        : 'Todavía no hay datos de reuniones diarias en este periodo.' + (desdeDl ? ' Se miden desde el ' + fl(desdeDl) + '.' : ''));
    }
    const fechas = [...m.keys()].sort();
    const ini = fechas[0], fin = C.fin;
    const varios = C.dls.length > 1;
    // Sin asistencia medida, la barra tiene el alto que corresponde a su estado (y lo dice al pasar)
    const ALTO = { cumplida: 100, incompleta: 60, no_hecha: 18, sin_registrar: 12, sin_zoom: 12, no_aplica: 8, pendiente: 6 };
    const datos = fechas.map((f) => {
      const l = m.get(f), e = peor(l);
      const as = l.map((x) => x.as).filter((v) => v != null);
      const a = as.length ? Math.round(prom(as)) : null;
      return { x: ms(f), y: a != null ? Math.max(a, 4) : (ALTO[e] || 6), fillColor: col[e] || T.col.nm, f, l, e, a };
    });
    Object.keys(D.feriados).filter((f) => en(f, ini, fin) && !m.has(f) && dsem(f) <= 4)
      .forEach((f) => datos.push({ x: ms(f), y: 8, fillColor: T.colBg.hold, f, fer: D.feriados[f] }));
    datos.sort((a, b) => a.x - b.x);
    const cuentan = fechas.filter((f) => DL_CUENTAN.includes(peor(m.get(f))));
    sub('dl-cal', pl(cuentan.length, 'día hábil medido', 'días hábiles medidos') + (varios ? ' · el peor estado del día' : ''));
    pintar('dl-cal', base(T, anim, {
      chart: { type: 'bar', height: 260 },
      series: [{ name: 'Daily', data: datos }],
      plotOptions: { bar: { columnWidth: datos.length > 60 ? '88%' : datos.length > 20 ? '70%' : '46%', borderRadius: 4, borderRadiusApplication: 'end' } },
      dataLabels: { enabled: false },
      xaxis: { type: 'datetime', min: ms(ini) - DIA_MS, max: ms(fin) + DIA_MS, labels: { datetimeUTC: true, datetimeFormatter: { year: 'yyyy', month: "MMM 'yy", day: 'd MMM' } } },
      yaxis: ejePct(T),
      annotations: { yaxis: [linea(T, D.umbral, 'Mínimo ' + D.umbral + ' %', T.col.warn)] },
      legend: { show: false },
      states: { hover: { filter: { type: 'darken', value: 0.88 } } },
      tooltip: { custom: ({ dataPointIndex: i }) => {
        const p = datos[i];
        if (!p) return '';
        if (p.fer) return tip(fdia(p.f), [punto(T.colBg.hold) + esc('Feriado: ' + p.fer)]);
        return tip(fdia(p.f), p.l.map((x) => punto(col[x.e]) + esc((varios ? 'Equipo ' + x.eq + ': ' : '') + (D.dailyEstados[x.e] || x.e)
          + (x.as != null ? ' · ' + x.as + ' % conectado' : ' · sin asistencia medida'))));
      } },
    }));
  };

  const dlEstados = (T, C, anim) => {
    const col = COLOR_DL(T);
    const dd = diasDailies(C).filter((x) => x.e !== 'pendiente');
    const filas = ['cumplida', 'incompleta', 'no_hecha', 'sin_registrar', 'sin_zoom', 'no_aplica']
      .map((k) => ({ n: D.dailyEstados[k] || k, v: dd.filter((x) => x.e === k).length, c: col[k] })).filter((f) => f.v);
    const cuentan = dd.filter((x) => DL_CUENTAN.includes(x.e));
    sub('dl-est', cuentan.length ? 'Cumplimiento ' + Math.round(cuentan.filter((x) => x.e === 'cumplida').length * 100 / cuentan.length) + ' %' : '');
    if (!filas.length) return vacio('dl-est', 'Todavía no hay datos de reuniones diarias en este periodo.');
    dona(T, anim, 'dl-est', filas, 'Días', ['día', 'días']);
  };

  const dlAsistencia = (T, C, anim) => {
    // Cada equipo con su color de identidad; si no tiene, uno de la colorimetría
    const pal = [T.col.done, T.col.hold, T.col.ok, T.col.warn];
    const series = C.dls.map((d, i) => ({
      name: 'Equipo ' + d.eq, color: C.dls.length > 1 ? (d.color || pal[i % pal.length]) : T.col.done,
      data: d.dias.filter((x) => en(x.f, C.desde, C.fin) && x.as != null && (x.e === 'cumplida' || x.e === 'incompleta')).map((x) => ({ x: ms(x.f), y: x.as })),
    })).filter((s) => s.data.length);
    const todos = series.flatMap((s) => s.data.map((p) => p.y));
    sub('dl-asis', todos.length ? 'Promedio ' + Math.round(prom(todos)) + ' %' : '');
    if (!series.length) return vacio('dl-asis', 'Todavía no hay datos de asistencia en este periodo.');
    // Con un equipo, la tendencia: el promedio de los últimos cinco días medidos
    const uno = series.length === 1;
    if (uno) {
      const d = series[0].data;
      series[0].name = 'Cada día';
      series[0].color = T.col.nm;
      const tend = d.map((p, i) => ({ x: p.x, y: Math.round(prom(d.slice(Math.max(0, i - 4), i + 1).map((q) => q.y))) }));
      // La tendencia, con el tono de la colorimetría frente al mínimo de asistencia
      const ult = tend.length ? tend[tend.length - 1].y : 0;
      series.push({ name: 'Tendencia (5 días)', color: ult >= D.umbral ? T.col.ok : ult >= D.umbral - 10 ? T.col.warn : T.col.crit, data: tend });
    }
    pintar('dl-asis', base(T, anim, {
      chart: { type: 'line', height: 280 },
      series, colors: series.map((s) => s.color),
      stroke: { width: uno ? [1.5, 3] : 2.5, curve: uno ? ['straight', 'smooth'] : 'straight' },
      markers: { size: uno ? [3, 0] : 3.5, strokeWidth: 0, hover: { sizeOffset: 2 } },
      legend: { show: true },
      xaxis: { type: 'datetime', labels: { datetimeUTC: true, datetimeFormatter: { year: 'yyyy', month: "MMM 'yy", day: 'd MMM' } } },
      yaxis: ejePct(T),
      annotations: { yaxis: [linea(T, D.umbral, 'Mínimo ' + D.umbral + ' %', T.col.warn)] },
      tooltip: { shared: true, intersect: false, x: { formatter: (v) => fdia(iso(v)) }, y: { formatter: (v) => v + ' % conectado' } },
    }));
  };

  const dlPersonas = (T, C, anim) => {
    const cuenta = new Map();
    diasDailies(C).forEach((x) => {
      if (x.as == null || (x.e !== 'cumplida' && x.e !== 'incompleta')) return;
      x.esp.forEach((mid) => {
        const c = cuenta.get(mid) || { va: 0, de: 0 };
        c.de++;
        if (x.pre.includes(mid)) c.va++;
        cuenta.set(mid, c);
      });
    });
    const l = [...cuenta.entries()].map(([mid, c]) => ({ n: corto(D.miembros[mid] || 'Persona ' + mid), nl: D.miembros[mid] || 'Persona ' + mid, va: c.va, de: c.de, p: Math.round(c.va * 100 / c.de) }))
      .sort((a, b) => b.p - a.p || a.n.localeCompare(b.n, 'es'));
    sub('dl-per', l.length ? pl(l.length, 'persona', 'personas') : '');
    if (!l.length) return vacio('dl-per', 'Todavía no hay datos de asistencia por persona en este periodo.');
    const alto = altoFilas(l.length, 32, 56, 240);
    pintar('dl-per', base(T, anim, {
      chart: { type: 'bar', height: alto },
      series: [{ name: 'Asistencia', data: l.map((x) => ({ x: x.n, y: x.p, fillColor: T.col[tonoPct(x.p)] })) }],
      plotOptions: { bar: { horizontal: true, barHeight: '58%', borderRadius: 3, borderRadiusApplication: 'end' } },
      xaxis: { min: 0, max: 100, tickAmount: 5, labels: { formatter: (v) => Math.round(v) + ' %' } },
      yaxis: { labels: { style: { colors: T.ink, fontSize: '11.5px', fontWeight: 600 }, maxWidth: 140 } },
      legend: { show: false },
      tooltip: { custom: ({ dataPointIndex: i }) => { const x = l[i]; return x ? tip(x.nl, [punto(T.col[tonoPct(x.p)]) + 'Se conectó ' + x.va + ' de ' + pl(x.de, 'día', 'días') + ' · ' + x.p + ' %']) : ''; } },
    }), alto);
  };

  /* ---------- Tareas ---------- */
  const colorEstado = (T, k, i) => {
    const e = D.estados.find((x) => x.k === k);
    if (e && e.fin) return T.col.ok;   // terminada: Cumple
    if (D.estados[0] && D.estados[0].k === k) return T.col.none;
    if (/rev/i.test(k)) return T.col.warn;
    if (/bloq|deten/i.test(k)) return T.col.crit;
    if (/prog|curso/i.test(k)) return T.col.done;
    return [T.col.done, T.col.hold, T.col.nm][i % 3];
  };
  const colorPrio = (T, k) => (/alta|urg|crit/i.test(k) ? T.col.crit : /media/i.test(k) ? T.col.warn : /baja/i.test(k) ? T.col.done : T.col.nm);
  const ejeTiempo = (semanal) => ({
    type: 'datetime',
    labels: { datetimeUTC: true, datetimeFormatter: { year: 'yyyy', month: semanal ? 'd MMM' : "MMM 'yy", day: 'd MMM' } },
  });

  const tareasSemana = (T, C, anim) => {
    const { semanal, l } = cubetas(C.desde, C.fin);
    const creadas = l.map((b) => D.tareas.filter((t) => en(t.c, b.a, b.b)).length);
    const hechas = l.map((b) => D.tareas.filter((t) => en(t.f, b.a, b.b)).length);
    const tc = suma(creadas), th = suma(hechas);
    sub('t-sem', l.length ? pl(tc, 'creada', 'creadas') + ' · ' + pl(th, 'terminada', 'terminadas') + (semanal ? ' · por semana' : ' · por mes') : '');
    if (!tc && !th) return vacio('t-sem', 'Todavía no hay datos de tareas en este periodo.');
    pintar('t-sem', base(T, anim, {
      chart: { type: 'area', height: 300 },
      series: [{ name: 'Creadas', data: l.map((b, i) => ({ x: ms(b.ini), y: creadas[i] })) }, { name: 'Terminadas', data: l.map((b, i) => ({ x: ms(b.ini), y: hechas[i] })) }],
      colors: [T.col.none, T.col.ok],
      stroke: { width: 2.5, curve: 'smooth' },
      fill: { type: 'gradient', gradient: { shadeIntensity: 0, opacityFrom: 0.28, opacityTo: 0.03, stops: [0, 100] } },
      markers: { size: l.length > 30 ? 0 : 3.5, strokeWidth: 0, hover: { sizeOffset: 2 } },
      xaxis: ejeTiempo(semanal),
      yaxis: ejeEntero(T, Math.max(...creadas, ...hechas)),
      tooltip: { shared: true, intersect: false, x: { formatter: (v) => etiquetaCubeta(semanal, iso(v)) }, y: { formatter: (v) => pl(v, 'tarea', 'tareas') } },
    }));
  };

  const tareasEstado = (T, C, anim) => {
    const filas = D.estados.map((e, i) => ({ n: e.n, v: C.vivas.filter((t) => t.e === e.k).length, c: colorEstado(T, e.k, i) })).filter((f) => f.v);
    sub('t-est', C.vivas.length ? pl(C.vivas.length, 'tarea', 'tareas') : '');
    if (!filas.length) return vacio('t-est', 'Todavía no hay datos de tareas en este periodo.');
    dona(T, anim, 't-est', filas, 'Tareas', ['tarea', 'tareas']);
  };

  const tareasResponsable = (T, C, anim) => {
    const por = new Map();
    C.vivas.forEach((t) => (t.a.length ? t.a : [0]).forEach((mid) => {
      if (!por.has(mid)) por.set(mid, {});
      const o = por.get(mid);
      o[t.e] = (o[t.e] || 0) + 1;
    }));
    const l = [...por.entries()].map(([mid, o]) => ({ mid, n: mid ? corto(D.miembros[mid] || 'Persona ' + mid) : 'Sin responsable', o, tot: suma(Object.values(o)) }))
      .sort((a, b) => b.tot - a.tot || a.n.localeCompare(b.n, 'es'));
    sub('t-resp', l.length ? pl(l.filter((x) => x.mid).length, 'persona', 'personas') : '');
    if (!l.length) return vacio('t-resp', 'Todavía no hay datos de tareas por responsable en este periodo.');
    const est = D.estados.map((e, i) => Object.assign({ i }, e)).filter((e) => l.some((x) => x.o[e.k]));
    const alto = altoFilas(l.length, 34, 80, 300);
    pintar('t-resp', base(T, anim, {
      chart: { type: 'bar', height: alto, stacked: true },
      series: est.map((e) => ({ name: e.n, data: l.map((x) => x.o[e.k] || 0) })),
      colors: est.map((e) => colorEstado(T, e.k, e.i)),
      plotOptions: { bar: { horizontal: true, barHeight: '58%', borderRadius: 3, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
      stroke: { show: true, width: 2, colors: [T.surface] },
      xaxis: Object.assign({ categories: l.map((x) => x.n) }, ejeEntero(T, Math.max(...l.map((x) => x.tot)))),
      yaxis: { labels: { style: { colors: T.ink, fontSize: '11.5px', fontWeight: 600 }, maxWidth: 140 } },
      tooltip: { shared: true, intersect: false, y: { formatter: (v) => pl(v, 'tarea', 'tareas') } },
    }), alto);
  };

  const tareasPrioridad = (T, C, anim) => {
    const ks = D.prioridades.map((p) => p.k);
    const filas = D.prioridades.slice().reverse().map((p) => ({ n: p.n, v: C.vivas.filter((t) => t.p === p.k).length, c: colorPrio(T, p.k) }));
    const otras = C.vivas.filter((t) => !ks.includes(t.p)).length;
    if (otras) filas.push({ n: 'Sin prioridad', v: otras, c: T.col.nm });
    const ok = filas.filter((f) => f.v);
    const altas = C.vivas.filter((t) => /alta|urg|crit/i.test(t.p) && !FINALES.has(t.e)).length;
    sub('t-prio', C.vivas.length ? pl(altas, 'alta abierta', 'altas abiertas') : '');
    if (!ok.length) return vacio('t-prio', 'Todavía no hay datos de tareas por prioridad en este periodo.');
    dona(T, anim, 't-prio', ok, 'Tareas', ['tarea', 'tareas']);
  };

  const tareasVencidas = (T, C, anim) => {
    const conFecha = D.tareas.filter((t) => t.l);
    const { semanal, l } = cubetas(C.desde, C.fin);
    const hoyN = D.tareas.filter((t) => t.l && t.l < HOY && !FINALES.has(t.e)).length;
    sub('t-venc', conFecha.length ? 'Hoy: ' + pl(hoyN, 'vencida', 'vencidas') : '');
    if (!D.tareas.length) return vacio('t-venc', 'Todavía no hay datos de tareas en este periodo.');
    if (!conFecha.length) return vacio('t-venc', 'Todavía no hay datos de vencidas: las tareas no tienen fecha límite.');
    if (!l.length) return vacio('t-venc', 'Todavía no hay datos de vencidas en este periodo.');
    const datos = l.map((b) => {
      const e = b.b;   // al cierre de la semana (o del mes), o hoy
      return { x: ms(b.ini), y: conFecha.filter((t) => t.l < e && (t.c || '') <= e && !(t.f && t.f <= e)).length, e };
    });
    pintar('t-venc', base(T, anim, {
      chart: { type: 'line', height: 280 },
      series: [{ name: 'Vencidas', data: datos.map(({ x, y }) => ({ x, y })) }],
      colors: [T.col.crit],
      stroke: { width: 2.5, curve: 'straight' },
      markers: { size: l.length > 30 ? 0 : 3.5, strokeWidth: 0, hover: { sizeOffset: 2 } },
      xaxis: ejeTiempo(semanal),
      yaxis: ejeEntero(T, Math.max(...datos.map((d) => d.y))),
      legend: { show: false },
      tooltip: { x: { formatter: (v, o) => { const d = datos[o.dataPointIndex]; return d ? 'Al ' + fl(d.e) : fl(iso(v)); } }, y: { formatter: (v) => pl(v, 'tarea vencida', 'tareas vencidas') } },
    }));
  };

  const tiempoCiclo = (T, C, anim) => {
    const { semanal, l } = cubetas(C.desde, C.fin);
    const datos = l.map((b) => {
      const d = D.tareas.filter((t) => en(t.f, b.a, b.b) && t.i && t.i <= t.f).map((t) => nDias(t.i, t.f));
      return { x: ms(b.ini), n: d.length, y: d.length ? Math.round(prom(d) * 10) / 10 : null };
    });
    const todos = D.tareas.filter((t) => en(t.f, C.desde, C.fin) && t.i && t.i <= t.f).map((t) => nDias(t.i, t.f));
    sub('t-ciclo', todos.length ? 'Promedio ' + n1(Math.round(prom(todos) * 10) / 10) + ' días' : '');
    if (!todos.length) return vacio('t-ciclo', 'Todavía no hay datos de tiempo de ciclo: salen cuando se terminan tareas en el periodo.');
    // Los dos ejes con cuatro marcas de paso redondo: así las líneas de la rejilla sirven para los dos
    const eje4 = (max) => { const p = [1, 2, 3, 4, 5, 10, 15, 20, 25, 50, 100, 250, 500].find((x) => x * 4 >= max) || Math.ceil(max / 4); return { min: 0, max: p * 4, tickAmount: 4 }; };
    const ejeD = eje4(Math.max(D.metaCiclo, ...datos.map((d) => d.y || 0)));
    const ejeN = eje4(Math.max(1, ...datos.map((d) => d.n)));
    pintar('t-ciclo', base(T, anim, {
      chart: { type: 'line', height: 280 },
      series: [
        { name: 'Días promedio', type: 'line', data: datos.map(({ x, y }) => ({ x, y })) },
        { name: 'Terminadas', type: 'column', data: datos.map(({ x, n }) => ({ x, y: n })) },
      ],
      // Los días promedio con el tono de la colorimetría frente a la meta del ciclo
      colors: [(() => { const pr = prom(todos); return pr <= D.metaCiclo ? T.col.ok : pr <= D.metaCiclo * 1.25 ? T.col.warn : T.col.crit; })(), T.col.none],
      fill: { opacity: [1, 0.32] },
      stroke: { width: [2.5, 0], curve: 'straight' },
      markers: { size: [l.length > 30 ? 0 : 4, 0], strokeWidth: 0 },
      plotOptions: { bar: { columnWidth: '52%', borderRadius: 3, borderRadiusApplication: 'end' } },
      xaxis: ejeTiempo(semanal),
      yaxis: [
        Object.assign({ seriesName: 'Días promedio', labels: { style: { colors: T.muted, fontSize: '11.5px' }, formatter: (v) => Math.round(v) + ' d' } }, ejeD),
        Object.assign({ seriesName: 'Terminadas', opposite: true, labels: { style: { colors: T.muted, fontSize: '11.5px' }, formatter: (v) => String(Math.round(v)) } }, ejeN),
      ],
      annotations: { yaxis: [Object.assign(linea(T, D.metaCiclo, 'Meta ≤ ' + n1(D.metaCiclo) + ' días', T.col.ok), { yAxisIndex: 0 })] },
      tooltip: { shared: true, intersect: false, x: { formatter: (v) => etiquetaCubeta(semanal, iso(v)) },
        y: { formatter: (v, o) => (o.seriesIndex === 0 ? (v == null ? '—' : n1(v) + ' días') : pl(v, 'tarea', 'tareas')) } },
    }));
  };

  /* ---------- Los sprints del release, como las fases del DS ---------- */
  const IC = {
    hecha: '<svg viewBox="0 0 16 16" width="12" height="12"><path d="M3.5 8.4l3 3 6-6.8" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    actual: '<svg viewBox="0 0 16 16" width="12" height="12"><path d="M3.5 4l4 4-4 4M8.5 4l4 4-4 4" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg>',
    pendiente: '<svg viewBox="0 0 16 16" width="12" height="12"><circle cx="8" cy="8" r="2.2" fill="currentColor"/></svg>',
  };
  const pasosSprints = (T, C) => {
    const caja = raiz.querySelector('#an-pasos');
    if (!caja) return;
    const rel = F.rel || D.relActual;
    const r = REL.get(rel);
    const l = D.sprints.filter((s) => s.rel.includes(rel)).sort((a, b) => (a.ini < b.ini ? -1 : 1));
    if (!r || !l.length) { sub('pasos', ''); caja.innerHTML = '<p class="an-pasos__vacio">Elige un release para ver sus sprints.</p>'; return; }
    const estado = (ini, fin) => (fin < HOY ? 'hecha' : ini <= HOY ? 'actual' : 'pendiente');
    const pts = (s) => ({
      comp: suma(D.historias.filter((h) => C.relOk(h.r) && h.vis.includes(s.c)), (h) => h.pt),
      hecho: suma(D.historias.filter((h) => C.relOk(h.r) && h.e === 'hecho' && en(h.h, s.ini, s.fin)), (h) => h.pt),
    });
    const n = l.filter((s) => s.fin < HOY).length;
    const fin = r.fin && r.fin < HOY;
    sub('pasos', rel + ' · ' + n + ' de ' + l.length + ' sprints terminados');
    const seg = l.map((s) => {
      const e = estado(s.ini, s.fin), p = pts(s);
      return '<li class="an-paso is-' + e + (F.sp === s.c ? ' is-elegido' : '') + '"' + (e === 'actual' ? ' aria-current="step"' : '') + '>'
        + '<span class="an-paso__fase">' + IC[e] + esc(s.c) + '</span>'
        + '<span class="an-paso__det"><b>' + esc(fc(s.ini) + ' – ' + fc(s.fin)) + '</b><small>' + (p.comp ? n1(p.hecho) + ' de ' + n1(p.comp) + ' pts' : 'Sin historias') + '</small></span></li>';
    }).join('');
    caja.innerHTML = '<ol class="an-pasos__fila">' + seg + '</ol>'
      + '<div class="an-paso an-paso--fin is-' + (fin ? 'hecha' : 'pendiente') + '"><span class="an-paso__fase">' + IC[fin ? 'hecha' : 'pendiente'] + (fin ? 'Finalizado' : 'Sin finalizar') + '</span>'
      + '<span class="an-paso__det"><b>' + esc(rel) + '</b><small>' + (r.fin ? 'Termina el ' + esc(fl(r.fin)) : '') + '</small></span></div>';
  };

  /* ---------- Las secciones como fases: lo recorrido ✓, donde se está », lo que falta • ---------- */
  const fases = document.querySelector('[data-an-fases]');
  if (fases) {
    const ops = [...fases.querySelectorAll('[data-an-fase]')];
    const secs = ops.map((a) => document.getElementById(a.getAttribute('data-an-fase')));
    let marca = 0;
    const marcar = () => {
      marca = 0;
      const tope = fases.getBoundingClientRect().bottom + 24;
      let cur = 0;
      secs.forEach((s, i) => { if (s && s.getBoundingClientRect().top <= tope) cur = i; });
      if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) cur = secs.length - 1;
      ops.forEach((a, i) => {
        const e = i < cur ? 'hecha' : i === cur ? 'actual' : 'pendiente';
        if (a.getAttribute('data-estado') === e) return;
        a.setAttribute('data-estado', e);
        a.querySelector('.an-fase__ico').innerHTML = IC[e];
        e === 'actual' ? a.setAttribute('aria-current', 'location') : a.removeAttribute('aria-current');
      });
    };
    window.addEventListener('scroll', () => { if (!marca) marca = requestAnimationFrame(marcar); }, { passive: true });
    window.addEventListener('resize', () => { if (!marca) marca = requestAnimationFrame(marcar); });
    marcar();
  }

  /* ---------- Todo junto ---------- */
  const PASOS = [velocidad, huEstado, burndown, huPrioridad, arrastres, relRadial, relSalud, revisiones, relItems, relGantt,
    dlCalendario, dlEstados, dlAsistencia, dlPersonas, tareasSemana, tareasEstado, tareasResponsable, tareasPrioridad, tareasVencidas, tiempoCiclo];
  let primera = true;
  let pendiente = 0;
  const dibujar = () => {
    const T = tema();
    const anim = primera;   // la animación solo al entrar: al filtrar, el cambio es al instante
    primera = false;
    const C = contexto();
    try { resumen(T, C); } catch (e) { console.error('Analítica: resumen', e); }
    try { tablaArrastres(T, C); } catch (e) { console.error('Analítica: tabla', e); }
    try { pasosSprints(T, C); } catch (e) { console.error('Analítica: sprints del release', e); }
    if (typeof window.ApexCharts === 'undefined') {
      raiz.querySelectorAll('.an-graf').forEach((el) => vacio(el.id.slice(5), 'No se pudo cargar la librería de gráficos. Recarga la página.'));
      return;
    }
    PASOS.forEach((p) => { try { p(T, C, anim); } catch (e) { console.error('Analítica:', p.name, e); } });
  };
  function pintarTodo() {
    cancelAnimationFrame(pendiente);
    pendiente = requestAnimationFrame(dibujar);
  }

  opcionesSprint();
  sincronizar();
  dibujar();
  // Lo que se dibuja en SVG no se entera del cambio de CSS: se repinta con la otra paleta
  document.addEventListener('meca:tema', pintarTodo);
})();
