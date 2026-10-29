/* =========================================================
   Pizarra de la reunión (pizarra.php?reunion=ID)

   Un lienzo infinito para usar EN la reunión, entre varios a la vez. JS sin
   librerías: cada elemento es un <div> con position absolute dentro de un
   «mundo» que se mueve y escala con un solo transform; las flechas y el lápiz
   van en una capa SVG encima. Lo elegido, las asas y las guías, en otra capa
   arriba de todo (su grosor no cambia con el zoom: --pz-inv = 1 / zoom).

   Guardado y en vivo (pizarra_api.php, datos en lib/Pizarra.php):
   - Cada elemento lleva su id (lo genera el cliente) y el servidor le pone la
     versión v al guardarlo. Gana la última escritura por elemento.
   - Lo que se cambia se junta y se manda en tandas cortas (~400 ms). Cada
     ~2,5 s, con la pestaña a la vista, se pregunta qué cambió desde la última
     versión. Una sola petición a la vez: mandar y luego preguntar.
   - Cada cambio propio lleva un sello «e» (cliente + contador). Cuando vuelve
     en el sondeo con el mismo sello, es el eco de lo mío y no se toca; si el
     sello es otro, lo cambió alguien más y entra (salvo que yo tenga algo
     pendiente de ese elemento: lo mío sale después y gana).
   - Sin red no se pierde nada: lo pendiente queda también en el navegador y
     se reintenta cada vez más espaciado; al volver la red, sale solo.
   ========================================================= */
(() => {
  'use strict';

  const raiz = document.getElementById('pz');
  if (!raiz) return;
  const $ = (s, r = document) => r.querySelector(s);
  const CFG = JSON.parse($('#pz-datos')?.textContent || '{}');
  const EDITAR = !!CFG.editar;
  const YO = Number(CFG.yo) || 0;
  const lienzo = $('#pz-lienzo'), mundo = $('#pz-mundo');
  const capaMarcos = $('#pz-marcos'), capaEls = $('#pz-els'), svg = $('#pz-svg'), capaSel = $('#pz-sel');
  const barra = $('#pz-barra'), sub = $('#pz-sub'), ctxBar = $('#pz-ctx'), tip = $('#pz-tip');
  const toastEl = $('#pz-toast'), estadoEl = $('#pz-estado'), otrosEl = $('#pz-otros'), vacioEl = $('#pz-vacio');
  const archivo = $('#pz-archivo'), ayuda = $('#pz-ayuda'), pctEl = $('#pz-pct');
  const SVGNS = 'http://www.w3.org/2000/svg';
  const movReducido = window.matchMedia('(prefers-reduced-motion: reduce)');
  const MAC = /Mac|iPhone|iPad/i.test(navigator.platform || navigator.userAgent || '');

  // Los íconos del set: pizarra.php los deja en un <template> (UI::icono)
  const ICONOS = {};
  $('#pz-iconos')?.content.querySelectorAll('[data-ico]').forEach((s) => { ICONOS[s.dataset.ico] = s.innerHTML; });
  const ico = (n) => ICONOS[n] || '';

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const limitar = (v, a, b) => Math.min(b, Math.max(a, v));
  const num = (v, d = 0) => (Number.isFinite(+v) ? +v : d);

  // Almacenamiento del navegador: puede no estar (ventana privada). Nunca rompe la pizarra.
  const local = {
    leer(k, d = null) { try { const v = localStorage.getItem(k); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
    poner(k, v) { try { if (v == null) localStorage.removeItem(k); else localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* sin almacenamiento */ } },
  };

  /* ---------- Colores y tipos ---------- */
  // Papel (relleno) y tinta (trazo y texto) de cada color; en pantalla salen de
  // pizarra.css ([data-color]) y cambian con el tema. Aquí, para exportar PNG.
  const COLORES = ['celeste', 'menta', 'lila', 'rosa', 'durazno', 'vainilla', 'gris'];
  const PALETA = {
    celeste: ['#DCEFFD', '#1D6FB8'], menta: ['#D4F3E3', '#15803D'], lila: ['#E9E1FC', '#6D28D9'], rosa: ['#FCE1EC', '#BE185D'],
    durazno: ['#FFE6D5', '#C2410C'], vainilla: ['#FFF3C9', '#8A6100'], gris: ['#EBEFF5', '#2D3E50'],
  };
  const NOMBRE_COLOR = { celeste: 'Celeste', menta: 'Menta', lila: 'Lila', rosa: 'Rosa', durazno: 'Durazno', vainilla: 'Vainilla', gris: 'Gris' };
  // Las tarjetas que vienen de una tarea, con el color de su estado
  const COLOR_ESTADO = { pendiente: 'gris', progreso: 'celeste', revision: 'lila', hecho: 'menta' };
  const CON_TEXTO = new Set(['nota', 'texto', 'rect', 'elipse']);
  const CON_COLOR = new Set(['nota', 'texto', 'rect', 'elipse', 'flecha', 'trazo']);
  const TINTA = new Set(['texto', 'flecha', 'trazo']);   // su color es el de la tinta, no el del papel
  const SVG_TIPOS = new Set(['flecha', 'trazo']);
  const TIPOS = new Set(['nota', 'texto', 'rect', 'elipse', 'flecha', 'trazo', 'imagen', 'enlace', 'kanban']);
  const TAMANOS = [16, 20, 28, 40, 56, 80];
  const GROSORES = [2, 3, 5, 8];
  const PUNTAS = ['fin', 'ambas', 'nada'];
  // Medidas del tablero Kanban (en unidades del mundo)
  const K = { cab: 56, colCab: 44, margen: 12, pad: 10, gap: 10, mas: 44, colMin: 200 };
  const Z_MIN = 0.1, Z_MAX = 4;
  const FUENTE = 'Inter, system-ui, -apple-system, "Segoe UI", sans-serif';

  // Lo que cada quien prefiere (color de la próxima nota, del lápiz…): solo en su navegador
  const pref = Object.assign({ notaColor: 'celeste', formaColor: 'celeste', flechaColor: 'gris', lapizColor: 'gris', lapizGrosor: 4 }, local.leer('pz-prefs', {}) || {});
  const guardarPref = () => local.poner('pz-prefs', pref);

  /* ---------- Estado ---------- */
  const els = new Map();     // id → datos del elemento (lo que se guarda) + campos locales con «_»
  const nodos = new Map();   // id → su nodo en el DOM (div o <g>)
  const sel = new Set();     // lo elegido
  const vista = { x: window.innerWidth / 2, y: window.innerHeight / 2, z: 1 };
  let maxZ = 0;
  let herramienta = 'seleccionar';
  let gesto = null;          // lo que hace el puntero ahora (mover, recuadro, lápiz…)
  let editando = null;       // el texto que se está escribiendo
  let hueco = null;          // dónde caería la tarjeta que se arrastra sobre un tablero
  let espacio = false;       // barra espaciadora: mano temporal
  let puntero = null;        // último punto del mouse sobre el lienzo (pantalla)
  let pellizco = null;
  const toques = new Map();

  const nuevoId = () => (window.crypto?.randomUUID ? crypto.randomUUID() : 'p' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12));
  const idCorto = () => 'c' + Math.random().toString(36).slice(2, 9);
  const CLIENTE = nuevoId().replace(/-/g, '').slice(0, 8);
  let contadorSello = 0;
  // El sello de lo que cambio yo: así reconozco mi eco cuando vuelve en el sondeo
  function marcar(el) { el.e = CLIENTE + '.' + (++contadorSello).toString(36); el.por = YO; }

  // Un tipo que esta versión no conoce (de una más nueva) se guarda tal cual, pero no se pinta ni se toca
  const vivo = (id) => { const el = id ? els.get(id) : null; return el && !el.borrado && TIPOS.has(el.tipo) ? el : null; };
  const vivos = () => [...els.values()].filter((e) => !e.borrado && TIPOS.has(e.tipo));
  const aMundo = (cx, cy) => ({ x: (cx - vista.x) / vista.z, y: (cy - vista.y) / vista.z });
  const aPantalla = (x, y) => ({ x: x * vista.z + vista.x, y: y * vista.z + vista.y });
  // Lo nuevo sale de un tamaño cómodo para el zoom en que se está
  const factor = () => limitar(1 / vista.z, 0.5, 4);
  const enTablero = (el) => el.tipo === 'nota' && !el._suelta && !!el.marco && vivo(el.marco)?.tipo === 'kanban';
  const dentro = (p, g) => p.x >= g.x && p.x <= g.x + g.w && p.y >= g.y && p.y <= g.y + g.h;
  const cruza = (a, b) => a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
  const contiene = (a, b) => b.x >= a.x && b.y >= a.y && b.x + b.w <= a.x + a.w && b.y + b.h <= a.y + a.h;

  /** La caja de un elemento en el mundo (las tarjetas del tablero, donde las acomodó el tablero). */
  function geo(el) {
    switch (el.tipo) {
      case 'nota':
        if (enTablero(el) && el._lx != null) return { x: el._lx, y: el._ly, w: el._lw, h: el._lh || 64 };
        if (el._suelta) return { x: el.x, y: el.y, w: el._lw || el.w, h: el._lh || el.h };
        break;
      case 'texto': return { x: num(el.x), y: num(el.y), w: num(el.w, 300), h: el._h || num(el.h, 40) };
      case 'kanban': return { x: num(el.x), y: num(el.y), w: num(el.w, 900), h: Math.max(num(el.h, 400), el._hc || 0) };
      case 'flecha': {
        const [a, b] = extremos(el);
        const x = Math.min(a.x, b.x), y = Math.min(a.y, b.y);
        return { x: x - 4, y: y - 4, w: Math.abs(a.x - b.x) + 8, h: Math.abs(a.y - b.y) + 8 };
      }
    }
    return { x: num(el.x), y: num(el.y), w: num(el.w, 100), h: num(el.h, 100) };
  }
  function union(gs) {
    if (!gs.length) return { x: 0, y: 0, w: 0, h: 0 };
    let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
    for (const g of gs) { x1 = Math.min(x1, g.x); y1 = Math.min(y1, g.y); x2 = Math.max(x2, g.x + g.w); y2 = Math.max(y2, g.y + g.h); }
    return { x: x1, y: y1, w: x2 - x1, h: y2 - y1 };
  }

  /** Copia para el historial: sin lo local («_»), sin la versión ni el sello. */
  function copia(el) {
    if (!el || el.borrado) return null;
    const o = {};
    for (const k in el) if (k[0] !== '_' && k !== 'v' && k !== 'e' && k !== 'por') o[k] = el[k];
    return JSON.parse(JSON.stringify(o));
  }
  /** Lo que se manda al servidor (el servidor pone la versión). */
  function paraEnviar(el) {
    const o = {};
    for (const k in el) if (k[0] !== '_' && k !== 'v') o[k] = el[k];
    return o;
  }

  const BASE = {
    nota: () => ({ w: 200, h: 200, color: pref.notaColor, texto: '' }),
    texto: () => ({ w: 320, h: 40, color: 'gris', texto: '', fs: 28 }),
    rect: () => ({ w: 200, h: 120, color: pref.formaColor, texto: '' }),
    elipse: () => ({ w: 190, h: 130, color: pref.formaColor, texto: '' }),
    flecha: () => ({ color: pref.flechaColor, grosor: 3, punta: 'fin' }),
    trazo: () => ({ color: pref.lapizColor, grosor: pref.lapizGrosor }),
    imagen: () => ({ w: 320, h: 200, src: '' }),
    enlace: () => ({ w: 360, h: 84, url: '', titulo: '' }),
    kanban: () => ({
      w: 960, h: 560, titulo: 'Tablero',
      columnas: [
        { id: idCorto(), titulo: 'Por hacer', estado: 'pendiente' },
        { id: idCorto(), titulo: 'En curso', estado: 'progreso' },
        { id: idCorto(), titulo: 'Hecho', estado: 'hecho' },
      ],
    }),
  };
  const nuevoEl = (tipo, datos = {}) => Object.assign({ id: nuevoId(), tipo, z: ++maxZ }, BASE[tipo](), datos);

  /** Lo que llega del servidor (o de otra versión) con lo que le falte, para pintarlo y medirlo igual. */
  function normalizar(el) {
    const base = BASE[el.tipo] ? BASE[el.tipo]() : null;
    if (!base) return el;
    for (const k of ['x', 'y', 'z', 'w', 'h', 'fs', 'grosor']) if (el[k] != null && typeof el[k] !== 'number') el[k] = num(el[k]);
    for (const k of ['w', 'h', 'fs', 'color', 'grosor', 'punta', 'titulo']) if (k in base && (el[k] == null || el[k] === '')) el[k] = base[k];
    if (el.x == null) el.x = 0;
    if (el.y == null) el.y = 0;
    if (el.tipo === 'kanban' && !(Array.isArray(el.columnas) && el.columnas.length)) el.columnas = base.columnas;
    if (el.tipo === 'flecha') { el.a = el.a || { x: el.x, y: el.y }; el.b = el.b || { x: el.x + 100, y: el.y }; }
    if (el.tipo === 'trazo' && !Array.isArray(el.puntos)) el.puntos = [];
    return el;
  }

  /* =========================================================
     Pintar
     ========================================================= */
  function nodoDe(el) {
    let n = nodos.get(el.id);
    if (n && n._tipo === el.tipo) return n;
    if (n) n.remove();
    n = SVG_TIPOS.has(el.tipo) ? nodoSvg(el) : nodoHtml(el);
    n._tipo = el.tipo;
    n.dataset.id = el.id;
    nodos.set(el.id, n);
    return n;
  }

  function nodoHtml(el) {
    const n = document.createElement('div');
    const forma = el.tipo === 'rect' || el.tipo === 'elipse';
    n.className = 'pz-el ' + (forma ? 'pz-forma pz-' + el.tipo : 'pz-' + el.tipo);
    if (el.tipo === 'nota') n.innerHTML = '<div class="pz-txt"></div><div class="pz-nota__tarea" hidden></div>';
    else if (CON_TEXTO.has(el.tipo)) n.innerHTML = '<div class="pz-txt"></div>';
    else if (el.tipo === 'imagen') n.innerHTML = '<img alt="" draggable="false" decoding="async">';
    else if (el.tipo === 'enlace') {
      n.innerHTML = `<span class="pz-enlace__ico">${ico('ds-enlace')}</span><span class="pz-enlace__txt"><b></b><small></small></span>`
        + '<a class="pz-enlace__abrir" target="_blank" rel="noopener noreferrer" data-abrir>Abrir</a>';
    } else if (el.tipo === 'kanban') {
      n.innerHTML = '<div class="pz-kanban__cab" data-agarre><span class="pz-kanban__titulo" data-campo="titulo"></span><span class="pz-kanban__n"></span>'
        + `<button type="button" class="pz-kanban__traer" data-kanban-traer data-solo-editar>${ico('pz-traer')} ${CFG.mia ? 'Traer mis tareas' : 'Traer tareas del proyecto'}</button></div>`;
      n._cols = new Map();
    }
    (el.tipo === 'kanban' ? capaMarcos : capaEls).appendChild(n);
    return n;
  }

  function nodoSvg(el) {
    const g = document.createElementNS(SVGNS, 'g');
    g.setAttribute('class', 'pz-' + el.tipo);
    g.innerHTML = el.tipo === 'flecha'
      ? '<path class="pz-golpe"/><path class="pz-linea"/><path class="pz-cabeza"/><path class="pz-cabeza"/>'
      : '<path class="pz-golpe" vector-effect="non-scaling-stroke"/><path class="pz-linea" vector-effect="non-scaling-stroke"/>';
    svg.appendChild(g);
    return g;
  }

  function quitarNodo(id) {
    const n = nodos.get(id);
    if (n) { n.remove(); nodos.delete(id); }
  }

  function pintar(el) {
    if (!el) return;
    if (el.borrado || !TIPOS.has(el.tipo)) { quitarNodo(el.id); return; }
    const n = nodoDe(el);
    if (el.tipo === 'flecha') pintarFlecha(el, n);
    else if (el.tipo === 'trazo') pintarTrazo(el, n);
    else pintarHtml(el, n);
  }

  const mover = (n, x, y) => { n.style.transform = `translate(${x}px,${y}px)`; };
  const colorDe = (el) => (COLORES.includes(el.color) ? el.color : (TINTA.has(el.tipo) ? 'gris' : 'celeste'));

  function pintarHtml(el, n) {
    const color = colorDe(el);
    if (n.dataset.color !== color) n.dataset.color = color;
    n.style.zIndex = String(Math.round(num(el.z)));
    const escribiendo = editando?.id === el.id;
    const txt = CON_TEXTO.has(el.tipo) ? n.firstElementChild : null;
    if (txt && !escribiendo && txt.textContent !== (el.texto || '')) { txt.textContent = el.texto || ''; n._fit = null; }

    if (el.tipo === 'nota') {
      const tablero = !!el.marco && (el._suelta || enTablero(el));
      n.classList.toggle('en-tablero', tablero);
      if (tablero) {
        if (el._suelta) mover(n, el.x, el.y);
        else if (el._lx != null) mover(n, el._lx, el._ly);
        if (el._lw && n._w !== el._lw) { n.style.width = el._lw + 'px'; n._w = el._lw; }
        n.style.height = '';
        txt.style.fontSize = '';
        n._fit = null;
        programarRender({ acomodar: true });
      } else {
        mover(n, num(el.x), num(el.y));
        n.style.width = num(el.w, 200) + 'px';
        n.style.height = num(el.h, 200) + 'px';
        n._w = null;
        ajustarFuente(el, n);
      }
      pintarPieTarea(el, n);
    } else if (el.tipo === 'texto') {
      mover(n, num(el.x), num(el.y));
      n.style.width = num(el.w, 320) + 'px';
      txt.style.fontSize = limitar(num(el.fs, 28), 8, 400) + 'px';
      pend.medir.add(el.id);
      programarRender({});
      pintarAvisoTexto(el, n);
    } else if (el.tipo === 'rect' || el.tipo === 'elipse') {
      mover(n, num(el.x), num(el.y));
      n.style.width = num(el.w) + 'px';
      n.style.height = num(el.h) + 'px';
      ajustarFuente(el, n);
    } else if (el.tipo === 'imagen') {
      mover(n, num(el.x), num(el.y));
      n.style.width = num(el.w) + 'px';
      n.style.height = num(el.h) + 'px';
      const img = n.firstElementChild;
      const src = el._blob || (/^uploads\/[\w.-]+$/.test(el.src || '') ? el.src : '');
      if (src && img.getAttribute('src') !== src) img.setAttribute('src', src);
      n.classList.toggle('is-subiendo', !!el._subiendo);
    } else if (el.tipo === 'enlace') {
      mover(n, num(el.x), num(el.y));
      n.style.width = num(el.w) + 'px';
      n.style.height = num(el.h) + 'px';
      const url = /^https?:\/\//i.test(el.url || '') ? el.url : '';
      if (!escribiendo) n.querySelector('b').textContent = el.titulo || hostDe(url) || 'Enlace';
      n.querySelector('small').textContent = url.replace(/^https?:\/\//i, '');
      const a = n.querySelector('a');
      if (url) a.href = url; else a.removeAttribute('href');
    } else if (el.tipo === 'kanban') {
      mover(n, num(el.x), num(el.y));
      n.style.width = num(el.w) + 'px';
      if (!escribiendo) {
        n.querySelector('.pz-kanban__titulo').textContent = el.titulo || 'Tablero';
        columnasKanban(el, n);
      }
      programarRender({ acomodar: true });
    }
  }

  /**
   * El pie de la nota: la tarea de la que viene (#id y quién la tiene; en «Mis
   * notas», de qué proyecto es) y el recordatorio, si tiene.
   */
  function pintarPieTarea(el, n) {
    const pie = n.querySelector('.pz-nota__tarea');
    const t = el.tarea && el.tarea.id ? el.tarea : null;
    const aviso = etiquetaAviso(el.aviso);
    const clave = (t ? `${t.id}|${t.quien}|${t.estado}|${t.proyecto || ''}` : '') + '|' + aviso + '|' + (el.aviso || '');
    if (pie._clave === clave) return;
    pie._clave = clave;
    pie.hidden = !t && !aviso;
    let html = '';
    if (t) {
      // El enlace va al proyecto de la tarea (en «Mis notas» cada una es de un proyecto distinto)
      const pid = Number(t.proyecto_id) || Number(CFG.proyecto) || 0;
      html += pid
        ? `<a href="proyecto.php?id=${pid}&amp;tarea=${Number(t.id)}" target="_blank" rel="noopener" data-abrir data-tip="Abrir la tarea">#${Number(t.id)}</a>`
        : `<span>#${Number(t.id)}</span>`;
      const quien = CFG.mia ? (t.proyecto || t.quien) : t.quien;
      if (quien) html += `<span class="pz-trunca">${esc(quien)}</span>`;
      if (t.estado === 'revision') html += '<span class="pz-nota__chip">En revisión</span>';
    }
    if (aviso) html += avisoHtml(el, aviso);
    pie.innerHTML = html;
  }
  /** Los textos sueltos llevan la insignia del recordatorio debajo, sin cambiar su alto. */
  function pintarAvisoTexto(el, n) {
    const aviso = etiquetaAviso(el.aviso);
    let b = n.querySelector(':scope > .pz-aviso-fuera');
    if (!aviso) { b?.remove(); return; }
    if (!b) { b = document.createElement('span'); b.className = 'pz-aviso-fuera'; n.appendChild(b); }
    const clave = aviso + '|' + el.aviso;
    if (b._clave !== clave) { b._clave = clave; b.innerHTML = avisoHtml(el, aviso); }
  }

  function columnasKanban(el, n) {
    const cols = Array.isArray(el.columnas) && el.columnas.length ? el.columnas : [{ id: 'unica', titulo: 'Tareas' }];
    const firma = JSON.stringify(cols);
    if (n._firma === firma) return;
    n._firma = firma;
    n._cols.forEach((c) => c.remove());
    n._cols.clear();
    for (const c of cols) {
      const cn = document.createElement('div');
      cn.className = 'pz-col';
      cn.dataset.col = c.id;
      cn.innerHTML = `<div class="pz-col__cab" data-agarre><span class="pz-col__punto" data-estado="${esc(c.estado || '')}"></span>`
        + `<span class="pz-col__t" data-campo="col" data-col-id="${esc(c.id)}"></span><span class="pz-col__n">0</span>`
        + `<button type="button" class="pz-col__quitar" data-col-quitar="${esc(c.id)}" data-solo-editar data-tip="Quitar esta columna" aria-label="Quitar esta columna">${ico('ds-cerrar')}</button></div>`
        + `<button type="button" class="pz-col__mas" data-col-mas="${esc(c.id)}" data-solo-editar>${ico('ds-mas')} Añadir tarjeta</button>`;
      cn.querySelector('.pz-col__t').textContent = c.titulo || '';
      cn._mas = cn.querySelector('.pz-col__mas');
      cn._n = cn.querySelector('.pz-col__n');
      n.appendChild(cn);
      n._cols.set(c.id, cn);
    }
  }

  /* ----- Texto que se ajusta solo (notas y formas) ----- */
  let medidor = null;
  function medirFuente(texto, w, h, peso, lh, max) {
    if (!medidor) { medidor = document.createElement('div'); medidor.className = 'pz-medidor'; document.body.appendChild(medidor); }
    if (w < 8 || h < 8) return 9;
    medidor.style.width = w + 'px';
    medidor.textContent = texto || 'M';
    const cabe = (fs) => {
      medidor.style.font = `${peso} ${fs}px/${lh} ${FUENTE}`;
      return medidor.scrollHeight <= h + 0.5 && medidor.scrollWidth <= w + 1;
    };
    let lo = 9, hi = Math.max(9, Math.min(max, Math.floor(h)));
    while (lo < hi) { const m = Math.ceil((lo + hi) / 2); if (cabe(m)) lo = m; else hi = m - 1; }
    return lo;
  }
  function ajustarFuente(el, n) {
    const txt = n.firstElementChild;
    let w, h, max;
    if (el.tipo === 'nota') { w = num(el.w, 200) - 36; h = num(el.h, 200) - 32 - (el.tarea?.id || el.aviso ? 28 : 0); max = 40; }
    else if (el.tipo === 'rect') { w = num(el.w) - 36; h = num(el.h) - 28; max = 34; }
    else { w = num(el.w) * 0.68 - 4; h = num(el.h) * 0.72 - 4; max = 34; }
    const clave = `${el.texto || ''}|${Math.round(w)}|${Math.round(h)}|${el.tipo}`;
    if (n._fit === clave) return;
    n._fit = clave;
    const fs = medirFuente(el.texto || '', w, h, el.tipo === 'nota' ? 500 : 600, el.tipo === 'nota' ? 1.28 : 1.25, Math.min(max, h * 0.5));
    txt.style.fontSize = fs + 'px';
    n._fs = fs;
  }

  /* ----- Flechas: se pegan al borde de lo que unen ----- */
  function extremos(el) {
    const A = el.a || { x: 0, y: 0 }, B = el.b || { x: 0, y: 0 };
    const ea = A.el && A.el !== el.id ? vivo(A.el) : null;
    const eb = B.el && B.el !== el.id ? vivo(B.el) : null;
    const ga = ea && !SVG_TIPOS.has(ea.tipo) ? geo(ea) : null;
    const gb = eb && !SVG_TIPOS.has(eb.tipo) ? geo(eb) : null;
    const ca = ga ? { x: ga.x + ga.w / 2, y: ga.y + ga.h / 2 } : { x: num(A.x), y: num(A.y) };
    const cb = gb ? { x: gb.x + gb.w / 2, y: gb.y + gb.h / 2 } : { x: num(B.x), y: num(B.y) };
    return [ga ? borde(ga, ea.tipo, cb) : ca, gb ? borde(gb, eb.tipo, ca) : cb];
  }
  /** Punto del borde de la caja g (o de la elipse) en la dirección de «hacia», con un respiro. */
  function borde(g, tipo, hacia) {
    const cx = g.x + g.w / 2, cy = g.y + g.h / 2, dx = hacia.x - cx, dy = hacia.y - cy;
    if (!dx && !dy) return { x: cx, y: cy };
    const hw = g.w / 2 || 1, hh = g.h / 2 || 1;
    const t = tipo === 'elipse'
      ? 1 / Math.sqrt((dx * dx) / (hw * hw) + (dy * dy) / (hh * hh))
      : Math.min(dx ? hw / Math.abs(dx) : Infinity, dy ? hh / Math.abs(dy) : Infinity);
    if (t >= 1) return { x: cx, y: cy };
    const L = Math.hypot(dx, dy), sep = 7;
    return { x: cx + dx * t + (dx / L) * sep, y: cy + dy * t + (dy / L) * sep };
  }
  const r1 = (v) => Math.round(v * 10) / 10;
  function cabeza(p, ux, uy, s) {
    const bx = p.x - ux * s, by = p.y - uy * s, nx = -uy * s * 0.55, ny = ux * s * 0.55;
    return `M${r1(p.x)} ${r1(p.y)}L${r1(bx + nx)} ${r1(by + ny)}L${r1(bx - nx)} ${r1(by - ny)}Z`;
  }
  function pintarFlecha(el, g) {
    g.dataset.color = colorDe(el);
    const [a, b] = extremos(el);
    const gro = limitar(num(el.grosor, 3), 1, 16);
    const dx = b.x - a.x, dy = b.y - a.y, L = Math.hypot(dx, dy) || 1, ux = dx / L, uy = dy / L;
    const s = 7 + gro * 2.2;
    const fin = el.punta !== 'nada', ini = el.punta === 'ambas';
    const a2 = ini ? { x: a.x + ux * s * 0.7, y: a.y + uy * s * 0.7 } : a;
    const b2 = fin ? { x: b.x - ux * s * 0.7, y: b.y - uy * s * 0.7 } : b;
    const [golpe, linea, cb, ca] = g.children;
    golpe.setAttribute('d', `M${r1(a.x)} ${r1(a.y)}L${r1(b.x)} ${r1(b.y)}`);
    golpe.setAttribute('stroke-width', String(Math.max(16, gro + 12)));
    linea.setAttribute('d', `M${r1(a2.x)} ${r1(a2.y)}L${r1(b2.x)} ${r1(b2.y)}`);
    linea.setAttribute('stroke-width', String(gro));
    cb.setAttribute('d', fin ? cabeza(b, ux, uy, s) : '');
    ca.setAttribute('d', ini ? cabeza(a, -ux, -uy, s) : '');
    cb.setAttribute('stroke-width', String(Math.max(1, gro * 0.5)));
    ca.setAttribute('stroke-width', String(Math.max(1, gro * 0.5)));
  }

  /* ----- Lápiz: trazo suavizado (curvas por los puntos medios) ----- */
  function camino(p) {
    if (!p.length) return '';
    if (p.length < 3) { const u = p[p.length - 1]; return `M${p[0][0]} ${p[0][1]}L${u[0] + (p.length === 1 ? 0.01 : 0)} ${u[1]}`; }
    let d = `M${p[0][0]} ${p[0][1]}`;
    for (let i = 1; i < p.length - 1; i++) {
      d += `Q${p[i][0]} ${p[i][1]} ${r1((p[i][0] + p[i + 1][0]) / 2)} ${r1((p[i][1] + p[i + 1][1]) / 2)}`;
    }
    const u = p[p.length - 1];
    return d + `L${u[0]} ${u[1]}`;
  }
  function pintarTrazo(el, g) {
    g.dataset.color = colorDe(el);
    const pts = Array.isArray(el.puntos) ? el.puntos : [];
    if (g._pts !== pts) { g._pts = pts; const d = camino(pts); g.children[0].setAttribute('d', d); g.children[1].setAttribute('d', d); }
    const pw = Math.max(1, num(el.pw, el.w)), ph = Math.max(1, num(el.ph, el.h));
    const sx = Math.max(1, num(el.w)) / pw, sy = Math.max(1, num(el.h)) / ph;
    g.setAttribute('transform', `translate(${num(el.x)} ${num(el.y)}) scale(${sx} ${sy})`);
    const gro = limitar(num(el.grosor, 4), 1, 40);
    g.children[1].setAttribute('stroke-width', String(gro));
    g.children[0].setAttribute('stroke-width', String(gro + 12));
  }

  /* ----- Tablero Kanban: las tarjetas se acomodan solas en su columna ----- */
  function notasDe(kid, colId, cols) {
    const ids = new Set((cols || vivo(kid)?.columnas || []).map((c) => c.id));
    const primera = (cols || vivo(kid)?.columnas || [])[0]?.id;
    return vivos().filter((n) => n.tipo === 'nota' && n.marco === kid && !n._suelta && (n.col === colId || (!ids.has(n.col) && colId === primera)))
      .sort((a, b) => num(a.orden) - num(b.orden));
  }
  /** Acomoda las tarjetas de un tablero. medir: vuelve a leer el alto de cada tarjeta. */
  function acomodar(k, medir = true) {
    const n = nodos.get(k.id);
    if (!n) return;
    const cols = Array.isArray(k.columnas) && k.columnas.length ? k.columnas : [{ id: 'unica' }];
    const paso = (num(k.w, 900) - K.margen) / cols.length;
    const anchoCol = paso - K.margen;
    const anchoNota = Math.max(60, anchoCol - K.pad * 2);
    const porCol = cols.map((c) => notasDe(k.id, c.id, cols));
    if (medir) {
      // primero se escribe el ancho de todas, luego se leen los altos: un solo cálculo de página
      for (const lista of porCol) for (const e of lista) {
        const nn = nodos.get(e.id);
        if (nn && !nn.classList.contains('en-tablero')) pintarHtml(e, nn);   // llegó antes que su tablero
        if (nn && nn._w !== anchoNota) { nn.style.width = anchoNota + 'px'; nn._w = anchoNota; }
      }
      for (const lista of porCol) for (const e of lista) { const nn = nodos.get(e.id); e._lh = nn ? nn.offsetHeight : 64; }
    }
    let alto = 0, total = 0;
    cols.forEach((c, i) => {
      const x = num(k.x) + K.margen + i * paso + K.pad;
      let y = num(k.y) + K.cab + K.colCab;
      const lista = porCol[i];
      const meteHueco = (idx) => {
        if (hueco && hueco.k === k.id && hueco.col === c.id && hueco.i === idx) { hueco.x = x; hueco.y = y; hueco.w = anchoNota; y += hueco.h + K.gap; }
      };
      lista.forEach((e, idx) => {
        meteHueco(idx);
        e._lx = x; e._ly = y; e._lw = anchoNota;
        const nn = nodos.get(e.id);
        if (nn) mover(nn, x, y);
        y += (e._lh || 64) + K.gap;
      });
      meteHueco(lista.length);
      total += lista.length;
      const cn = n._cols?.get(c.id);
      if (cn) { cn._y = y - num(k.y); cn._n.textContent = String(lista.length); }
      alto = Math.max(alto, y - num(k.y) + K.mas + K.margen);
    });
    k._hc = alto;
    const H = Math.max(num(k.h, 400), alto);
    n.style.height = H + 'px';
    cols.forEach((c, i) => {
      const cn = n._cols?.get(c.id);
      if (!cn) return;
      cn.style.transform = `translate(${K.margen + i * paso}px,${K.cab}px)`;
      cn.style.width = anchoCol + 'px';
      cn.style.height = (H - K.cab - K.margen) + 'px';
      cn._mas.style.top = (cn._y - K.cab) + 'px';
    });
    const nTxt = n.querySelector('.pz-kanban__n');
    if (nTxt) nTxt.textContent = total === 1 ? '1 tarjeta' : total + ' tarjetas';
  }

  /* ----- Render diferido: medir, acomodar, flechas y lo elegido en un solo cuadro ----- */
  const pend = { acomodar: false, flechas: false, sel: false, ctx: false, medir: new Set(), raf: 0 };
  function programarRender(q) {
    if (q.acomodar) pend.acomodar = true;
    if (q.flechas) pend.flechas = true;
    if (q.sel) pend.sel = true;
    if (q.ctx) pend.ctx = true;
    if (!pend.raf) pend.raf = requestAnimationFrame(render);
  }
  function render() {
    pend.raf = 0;
    if (pend.medir.size) {
      for (const id of pend.medir) { const el = vivo(id), n = nodos.get(id); if (el && n) el._h = n.offsetHeight; }
      pend.medir.clear();
      pend.flechas = pend.sel = true;
    }
    if (pend.acomodar) {
      pend.acomodar = false;
      for (const k of vivos()) if (k.tipo === 'kanban') acomodar(k, true);
      pend.flechas = pend.sel = true;
    }
    if (pend.flechas) { pend.flechas = false; repintarFlechas(); }
    if (pend.sel) { pend.sel = false; pintarSel(); }
    if (pend.ctx) { pend.ctx = false; pintarCtx(); }
  }
  function repintarFlechas() {
    for (const el of els.values()) if (!el.borrado && el.tipo === 'flecha') pintar(el);
  }

  /* =========================================================
     Lo elegido: anillos, caja, asas, guías
     ========================================================= */
  const sUI = { anillos: new Map(), asas: [], caja: null, hover: null, hoverId: null, guias: [], svgSel: new Set() };
  function radioDe(el) {
    if (el.tipo === 'elipse') return '50%';
    if (el.tipo === 'nota') return enTablero(el) || el._suelta ? '12px' : '8px';
    return { kanban: '20px', rect: '16px', imagen: '12px', enlace: '16px', trazo: '10px' }[el.tipo] || '8px';
  }
  function pintarSel() {
    for (const id of [...sel]) if (!vivo(id)) sel.delete(id);
    const inv = 1 / vista.z, o = 3 * inv;
    // Flechas y trazos: se marcan en su propio <g>
    for (const id of sUI.svgSel) if (!sel.has(id)) nodos.get(id)?.classList.remove('is-sel');
    sUI.svgSel.clear();
    for (const [id, r] of sUI.anillos) if (!sel.has(id) || vivo(id)?.tipo === 'flecha') { r.remove(); sUI.anillos.delete(id); }
    for (const id of sel) {
      const el = vivo(id);
      if (SVG_TIPOS.has(el.tipo)) { nodos.get(id)?.classList.add('is-sel'); sUI.svgSel.add(id); }
      if (el.tipo === 'flecha') continue;
      let r = sUI.anillos.get(id);
      if (!r) { r = document.createElement('div'); r.className = 'pz-anillo'; capaSel.appendChild(r); sUI.anillos.set(id, r); }
      const g = geo(el);
      r.style.transform = `translate(${g.x - o}px,${g.y - o}px)`;
      r.style.width = (g.w + 2 * o) + 'px';
      r.style.height = (g.h + 2 * o) + 'px';
      r.style.borderRadius = radioDe(el);
    }
    // Varios: una caja punteada alrededor de todo
    if (sel.size > 1) {
      if (!sUI.caja) { sUI.caja = document.createElement('div'); sUI.caja.className = 'pz-caja'; capaSel.appendChild(sUI.caja); }
      const c = union([...sel].map((id) => geo(vivo(id)))), m = 9 * inv;
      sUI.caja.hidden = false;
      sUI.caja.style.transform = `translate(${c.x - m}px,${c.y - m}px)`;
      sUI.caja.style.width = (c.w + 2 * m) + 'px';
      sUI.caja.style.height = (c.h + 2 * m) + 'px';
    } else if (sUI.caja) sUI.caja.hidden = true;
    // Asas: con uno solo elegido, y no mientras se arrastra
    const unico = sel.size === 1 ? vivo([...sel][0]) : null;
    const conAsas = EDITAR && unico && !editando && (!gesto || !gesto.movido || gesto.tipo === 'asa' || gesto.tipo === 'extremo');
    const asas = conAsas ? asasDe(unico) : [];
    while (sUI.asas.length < asas.length) { const a = document.createElement('div'); a.className = 'pz-asa'; capaSel.appendChild(a); sUI.asas.push(a); }
    sUI.asas.forEach((a, i) => {
      const d = asas[i];
      if (!d) { a.hidden = true; return; }
      a.hidden = false;
      a.dataset.asa = d[0];
      a.style.transform = `translate(${d[1]}px,${d[2]}px)`;
    });
    if (sUI.hoverId && (sel.has(sUI.hoverId) || !vivo(sUI.hoverId))) pintarHover(null);
  }
  function asasDe(el) {
    if (el.tipo === 'flecha') { const [a, b] = extremos(el); return [['a', a.x, a.y], ['b', b.x, b.y]]; }
    if (el.tipo === 'nota' && enTablero(el)) return [];
    const g = geo(el), x1 = g.x, y1 = g.y, x2 = g.x + g.w, y2 = g.y + g.h;
    const l = [['nw', x1, y1], ['ne', x2, y1], ['sw', x1, y2], ['se', x2, y2]];
    if (el.tipo === 'texto') l.push(['w', x1, y1 + g.h / 2], ['e', x2, y1 + g.h / 2]);
    return l;
  }
  function pintarHover(id) {
    if (sUI.hoverId === id) { if (id) posHover(); return; }
    if (sUI.hoverId) nodos.get(sUI.hoverId)?.classList.remove('is-hover');
    sUI.hoverId = id;
    if (!id) { if (sUI.hover) sUI.hover.hidden = true; return; }
    const el = vivo(id);
    if (SVG_TIPOS.has(el.tipo)) { nodos.get(id)?.classList.add('is-hover'); if (sUI.hover) sUI.hover.hidden = true; return; }
    if (!sUI.hover) { sUI.hover = document.createElement('div'); sUI.hover.className = 'pz-hover'; capaSel.appendChild(sUI.hover); }
    sUI.hover.hidden = false;
    sUI.hover.style.borderRadius = radioDe(el);
    posHover();
  }
  function posHover() {
    const el = vivo(sUI.hoverId);
    if (!el || !sUI.hover || SVG_TIPOS.has(el.tipo)) return;
    const g = geo(el), o = 2 / vista.z;
    sUI.hover.style.transform = `translate(${g.x - o}px,${g.y - o}px)`;
    sUI.hover.style.width = (g.w + 2 * o) + 'px';
    sUI.hover.style.height = (g.h + 2 * o) + 'px';
  }
  function pintarGuias(lineas) {
    const inv = 1 / vista.z;
    while (sUI.guias.length < lineas.length) { const d = document.createElement('div'); d.className = 'pz-guia'; capaSel.appendChild(d); sUI.guias.push(d); }
    sUI.guias.forEach((d, i) => {
      const l = lineas[i];
      if (!l) { d.hidden = true; return; }
      d.hidden = false;
      d.style.transform = `translate(${l.x - (l.w ? 0 : inv / 2)}px,${l.y - (l.h ? 0 : inv / 2)}px)`;
      d.style.width = (l.w || inv) + 'px';
      d.style.height = (l.h || inv) + 'px';
    });
  }
  let blancoDiv = null;
  function pintarBlanco(el) {
    if (!el) { if (blancoDiv) blancoDiv.hidden = true; return; }
    if (!blancoDiv) { blancoDiv = document.createElement('div'); blancoDiv.className = 'pz-blanco'; capaSel.appendChild(blancoDiv); }
    const g = geo(el), o = 4 / vista.z;
    blancoDiv.hidden = false;
    blancoDiv.style.borderRadius = radioDe(el);
    blancoDiv.style.transform = `translate(${g.x - o}px,${g.y - o}px)`;
    blancoDiv.style.width = (g.w + 2 * o) + 'px';
    blancoDiv.style.height = (g.h + 2 * o) + 'px';
  }
  let huecoDiv = null;
  function pintarHueco() {
    if (!hueco || hueco.x == null) { if (huecoDiv) huecoDiv.hidden = true; return; }
    if (!huecoDiv) { huecoDiv = document.createElement('div'); huecoDiv.className = 'pz-hueco'; capaSel.appendChild(huecoDiv); }
    huecoDiv.hidden = false;
    huecoDiv.style.transform = `translate(${hueco.x}px,${hueco.y}px)`;
    huecoDiv.style.width = hueco.w + 'px';
    huecoDiv.style.height = hueco.h + 'px';
  }

  /** Guías de alineación: bordes y centros de lo que se ve cerca. */
  function candidatosGuia(excluir) {
    const a = aMundo(-200, -200), b = aMundo(window.innerWidth + 200, window.innerHeight + 200);
    const vis = { x: a.x, y: a.y, w: b.x - a.x, h: b.y - a.y };
    const l = [];
    for (const el of els.values()) {
      if (!vivo(el.id) || excluir.has(el.id) || SVG_TIPOS.has(el.tipo) || el._suelta) continue;
      if (el.tipo === 'nota' && el.marco && excluir.has(el.marco)) continue;
      const g = geo(el);
      if (cruza(g, vis)) l.push(g);
      if (l.length > 400) break;
    }
    return l;
  }
  function guias(c, cands) {
    const u = 6 / vista.z;
    const xs = [c.x, c.x + c.w / 2, c.x + c.w], ys = [c.y, c.y + c.h / 2, c.y + c.h];
    let bx = null, by = null;
    for (const g of cands) {
      const gx = [g.x, g.x + g.w / 2, g.x + g.w], gy = [g.y, g.y + g.h / 2, g.y + g.h];
      for (const p of xs) for (const q of gx) { const d = q - p; if (Math.abs(d) <= u && (!bx || Math.abs(d) < Math.abs(bx.d))) bx = { d, v: q, g }; }
      for (const p of ys) for (const q of gy) { const d = q - p; if (Math.abs(d) <= u && (!by || Math.abs(d) < Math.abs(by.d))) by = { d, v: q, g }; }
    }
    const dx = bx ? bx.d : 0, dy = by ? by.d : 0, lineas = [];
    if (bx) { const y1 = Math.min(c.y + dy, bx.g.y), y2 = Math.max(c.y + dy + c.h, bx.g.y + bx.g.h); lineas.push({ x: bx.v, y: y1, w: 0, h: y2 - y1 }); }
    if (by) { const x1 = Math.min(c.x + dx, by.g.x), x2 = Math.max(c.x + dx + c.w, by.g.x + by.g.w); lineas.push({ x: x1, y: by.v, w: x2 - x1, h: 0 }); }
    return { dx, dy, lineas };
  }

  /** Lo de más arriba bajo un punto (sin flechas ni trazos; los tableros, al final). */
  function elementoEn(p, excluir) {
    let mejor = null, tablero = null;
    for (const el of els.values()) {
      if (!vivo(el.id) || excluir?.has(el.id) || SVG_TIPOS.has(el.tipo) || el._suelta) continue;
      if (!dentro(p, geo(el))) continue;
      if (el.tipo === 'kanban') { if (!tablero || num(el.z) >= num(tablero.z)) tablero = el; continue; }
      if (!mejor || num(el.z) >= num(mejor.z)) mejor = el;
    }
    return mejor || tablero;
  }
  function kanbanEn(p, excluir) {
    let k = null;
    for (const el of els.values()) {
      if (el.borrado || el.tipo !== 'kanban' || excluir?.has(el.id)) continue;
      if (dentro(p, geo(el)) && (!k || num(el.z) >= num(k.z))) k = el;
    }
    return k;
  }

  /* =========================================================
     Historial (deshacer / rehacer) y cambios
     ========================================================= */
  const pasado = [], futuro = [];
  /** Un cambio: tocar(id) guarda cómo estaba; cerrar() deja la entrada en el historial y lo manda. */
  function cambio() {
    const antes = new Map();
    return {
      antes,
      tocar(id) { if (!antes.has(id)) antes.set(id, copia(vivo(id))); },
      cerrar(op = {}) {
        const pasos = [];
        for (const [id, a] of antes) { const d = copia(vivo(id)); if (JSON.stringify(a) !== JSON.stringify(d)) pasos.push([id, a, d]); }
        if (!pasos.length) return false;
        for (const [id, , d] of pasos) { const el = els.get(id); if (d && el) marcar(el); marcarSucio(id); }
        registrar(pasos, op.unir);
        return true;
      },
      revertir() {
        for (const [id, a] of antes) {
          const cur = els.get(id);
          if (!a) { els.delete(id); quitarNodo(id); sel.delete(id); continue; }
          const el = Object.assign(a, { id, v: cur?.v, e: cur?.e, por: cur?.por });
          els.set(id, el);
          pintar(el);
        }
        programarRender({ acomodar: true, flechas: true, sel: true });
      },
    };
  }
  function registrar(pasos, unir) {
    const ult = pasado[pasado.length - 1];
    if (unir && ult && ult.unir === unir && Date.now() - ult.t < 900 && ult.length === pasos.length && pasos.every((p, i) => p[0] === ult[i][0])) {
      pasos.forEach((p, i) => { ult[i][2] = p[2]; });
      ult.t = Date.now();
    } else {
      pasos.unir = unir;
      pasos.t = Date.now();
      pasado.push(pasos);
      if (pasado.length > 150) pasado.shift();
    }
    futuro.length = 0;
    pintarHistoria();
  }
  function borrarLocal(id) {
    const prev = els.get(id);
    els.set(id, { id, borrado: true, v: prev?.v });
    quitarNodo(id);
    sel.delete(id);
  }
  function aplicarPasos(pasos, i) {
    const ids = [];
    for (const p of pasos) {
      const id = p[0], snap = p[i];
      if (!snap) { if (vivo(id)) borrarLocal(id); }
      else {
        const prev = els.get(id);
        const el = JSON.parse(JSON.stringify(snap));
        el.id = id;
        if (prev?.v) el.v = prev.v;
        marcar(el);
        els.set(id, el);
        pintar(el);
        if (num(el.z) > maxZ) maxZ = num(el.z);
        ids.push(id);
      }
      marcarSucio(id);
    }
    sel.clear();
    ids.forEach((id) => { const el = vivo(id); if (el && !(el.tipo === 'nota' && el.marco && ids.includes(el.marco))) sel.add(id); });
    programarRender({ acomodar: true, flechas: true, sel: true, ctx: true });
    pintarVacio();
  }
  function deshacer() {
    if (!EDITAR) return;
    if (editando) terminarEdicion();
    const p = pasado.pop();
    if (!p) return;
    aplicarPasos(p, 1);
    futuro.push(p);
    pintarHistoria();
  }
  function rehacer() {
    if (!EDITAR) return;
    if (editando) terminarEdicion();
    const p = futuro.pop();
    if (!p) return;
    aplicarPasos(p, 2);
    pasado.push(p);
    pintarHistoria();
  }
  function pintarHistoria() {
    const d = $('[data-accion="deshacer"]'), r = $('[data-accion="rehacer"]');
    if (d) d.disabled = !pasado.length;
    if (r) r.disabled = !futuro.length;
  }

  /** Crea elementos de una vez (una sola entrada en el historial) y los deja elegidos. */
  function crear(lista, elegir = true) {
    const t = cambio();
    for (const el of lista) { t.tocar(el.id); els.set(el.id, el); pintar(el); }
    t.cerrar();
    if (elegir) { sel.clear(); lista.forEach((el) => { if (!(el.tipo === 'nota' && el.marco)) sel.add(el.id); }); }
    programarRender({ acomodar: true, flechas: true, sel: true, ctx: true });
    pintarVacio();
  }

  function borrarSeleccion() {
    if (!EDITAR || !sel.size) return;
    if (editando) terminarEdicion();
    const ids = new Set(sel);
    // El tablero se lleva sus tarjetas
    for (const id of sel) if (vivo(id)?.tipo === 'kanban') for (const n of vivos()) if (n.tipo === 'nota' && n.marco === id) ids.add(n.id);
    const t = cambio();
    ids.forEach((id) => t.tocar(id));
    // Las flechas pegadas a lo que se borra se quedan donde estaban
    for (const f of vivos()) {
      if (f.tipo !== 'flecha' || ids.has(f.id)) continue;
      const [pa, pb] = extremos(f);
      if (f.a?.el && ids.has(f.a.el)) { t.tocar(f.id); f.a = { x: r1(pa.x), y: r1(pa.y) }; }
      if (f.b?.el && ids.has(f.b.el)) { t.tocar(f.id); f.b = { x: r1(pb.x), y: r1(pb.y) }; }
    }
    ids.forEach(borrarLocal);
    t.cerrar();
    sel.clear();
    programarRender({ acomodar: true, flechas: true, sel: true, ctx: true });
    pintarVacio();
  }

  /* ----- Copiar, pegar y duplicar elementos ----- */
  function copiarSeleccion() {
    const ids = new Set(sel);
    for (const id of sel) if (vivo(id)?.tipo === 'kanban') for (const n of vivos()) if (n.tipo === 'nota' && n.marco === id) ids.add(n.id);
    // Las flechas que unen dos cosas copiadas viajan con ellas
    for (const f of vivos()) if (f.tipo === 'flecha' && f.a?.el && f.b?.el && ids.has(f.a.el) && ids.has(f.b.el)) ids.add(f.id);
    const lista = [];
    for (const id of ids) {
      const el = vivo(id);
      if (!el) continue;
      const c = copia(el);
      if (c.tipo === 'nota' && c.marco && !ids.has(c.marco)) { const g = geo(el); c.x = g.x; c.y = g.y; c.marco = null; c.col = null; delete c.orden; }
      if (c.tipo === 'flecha') {
        const [pa, pb] = extremos(el);
        if (c.a?.el && !ids.has(c.a.el)) c.a = { x: r1(pa.x), y: r1(pa.y) };
        if (c.b?.el && !ids.has(c.b.el)) c.b = { x: r1(pb.x), y: r1(pb.y) };
      }
      lista.push(c);
    }
    return lista;
  }
  function cajaCopia(c) {
    if (c.tipo === 'flecha') { const x = Math.min(c.a.x, c.b.x), y = Math.min(c.a.y, c.b.y); return { x, y, w: Math.abs(c.a.x - c.b.x), h: Math.abs(c.a.y - c.b.y) }; }
    return { x: num(c.x), y: num(c.y), w: num(c.w, 100), h: num(c.h, 40) };
  }
  /** Pega una lista de copias: con destino, centradas ahí; si no, corridas (dx, dy). */
  function pegarElementos(lista, destino, dx = 0, dy = 0, t = null) {
    if (!lista?.length) return [];
    const mapa = new Map(lista.map((e) => [e.id, nuevoId()]));
    const sueltos = lista.filter((e) => !(e.tipo === 'nota' && e.marco && mapa.has(e.marco)));
    if (destino) { const c = union(sueltos.map(cajaCopia)); dx = destino.x - (c.x + c.w / 2); dy = destino.y - (c.y + c.h / 2); }
    const propio = !t;
    t = t || cambio();
    const nuevos = [];
    for (const c of [...lista].sort((a, b) => num(a.z) - num(b.z))) {
      const el = JSON.parse(JSON.stringify(c));
      el.id = mapa.get(c.id);
      el.z = ++maxZ;
      delete el.aviso; delete el.aviso_por;   // la copia no repite el recordatorio
      if (el.marco) el.marco = mapa.get(el.marco) || null;
      if (el.tipo === 'flecha') {
        for (const k of ['a', 'b']) {
          if (el[k].el) { if (mapa.has(el[k].el)) el[k].el = mapa.get(el[k].el); else delete el[k].el; }
          el[k].x = r1(num(el[k].x) + dx); el[k].y = r1(num(el[k].y) + dy);
        }
      } else if (!(el.tipo === 'nota' && el.marco)) { el.x = r1(num(el.x) + dx); el.y = r1(num(el.y) + dy); }
      t.tocar(el.id);
      els.set(el.id, el);
      pintar(el);
      nuevos.push(el);
    }
    if (propio) t.cerrar();
    sel.clear();
    nuevos.forEach((el) => { if (!(el.tipo === 'nota' && el.marco) && !(el.tipo === 'flecha' && el.a.el && el.b.el)) sel.add(el.id); });
    programarRender({ acomodar: true, flechas: true, sel: true, ctx: true });
    pintarVacio();
    return nuevos.map((e) => e.id);
  }
  function duplicar() {
    if (!EDITAR || !sel.size) return;
    if (editando) terminarEdicion();
    const elegidos = [...sel].map(vivo).filter(Boolean);
    // Tarjetas de un tablero (sin el tablero): la copia va justo debajo, en la misma columna
    if (elegidos.every((el) => enTablero(el) && !sel.has(el.marco))) {
      const t = cambio(), nuevos = [];
      for (const n of elegidos) {
        const lista = notasDe(n.marco, n.col);
        const i = lista.indexOf(n), sig = lista[i + 1];
        const c = Object.assign(copia(n), { id: nuevoId(), z: ++maxZ, orden: sig ? (num(n.orden) + num(sig.orden)) / 2 : num(n.orden) + 1 });
        delete c.tarea; delete c.aviso; delete c.aviso_por;
        t.tocar(c.id); els.set(c.id, c); pintar(c); nuevos.push(c.id);
      }
      t.cerrar();
      sel.clear(); nuevos.forEach((id) => sel.add(id));
      programarRender({ acomodar: true, sel: true, ctx: true });
      return;
    }
    pegarElementos(copiarSeleccion(), null, 24, 24);
  }

  // El portapapeles propio: lo copiado de la pizarra (sirve también entre pizarras)
  function textoDeCopia(lista) {
    const t = lista.map((e) => e.texto || e.titulo || e.url || '').filter(Boolean).join('\n').trim();
    return t || `Pizarra · ${lista.length} elemento${lista.length === 1 ? '' : 's'}`;
  }

  /* =========================================================
     Escribir (doble clic, Enter o al crear una nota)
     ========================================================= */
  const PLANO = (() => { try { const d = document.createElement('div'); d.contentEditable = 'plaintext-only'; return d.contentEditable === 'plaintext-only'; } catch (e) { return false; } })();

  function editar(id, op = {}) {
    if (!EDITAR) return;
    const el = vivo(id), n = nodos.get(id);
    if (!el || !n) return;
    let txt = null, unaLinea = false;
    if (op.campo === 'col') { txt = n.querySelector(`[data-col-id="${CSS.escape(op.col)}"]`); unaLinea = true; }
    else if (op.campo === 'titulo' || el.tipo === 'kanban') { txt = n.querySelector('.pz-kanban__titulo'); unaLinea = true; op.campo = 'titulo'; }
    else if (CON_TEXTO.has(el.tipo)) txt = n.querySelector('.pz-txt');
    else if (el.tipo === 'enlace') { txt = n.querySelector('.pz-enlace__txt b'); unaLinea = true; op.campo = 'enlace'; }
    if (!txt) return;
    if (editando) terminarEdicion();
    sel.clear();
    sel.add(id);
    editando = { id, el, nodo: n, txt, op, unaLinea, antes: copia(el), entrada: op.nuevo ? pasado[pasado.length - 1] : null, ultimo: null };
    if (el.tipo === 'nota' || el.tipo === 'texto') txt.dataset.vacio = 'Escribe aquí…';
    txt.contentEditable = PLANO ? 'plaintext-only' : 'true';
    txt.spellcheck = true;
    raiz.classList.add('is-editando');
    txt.focus({ preventScroll: true });
    colocarCursor(txt, op);
    pintarSel();
    pintarCtx();
  }
  function colocarCursor(txt, op) {
    const s = window.getSelection();
    if (!s) return;
    if (op.x != null) {
      let r = null;
      if (document.caretPositionFromPoint) { const p = document.caretPositionFromPoint(op.x, op.y); if (p) { r = document.createRange(); r.setStart(p.offsetNode, p.offset); } }
      else if (document.caretRangeFromPoint) r = document.caretRangeFromPoint(op.x, op.y);
      if (r && txt.contains(r.startContainer)) { r.collapse(true); s.removeAllRanges(); s.addRange(r); return; }
    }
    const r = document.createRange();
    r.selectNodeContents(txt);
    if (!op.todo) r.collapse(false);
    s.removeAllRanges();
    s.addRange(r);
  }
  function leerTexto(txt, unaLinea) {
    let v = (txt.innerText || '').replace(/ /g, ' ').replace(/\r/g, '');
    if (v.endsWith('\n')) v = v.slice(0, -1);
    if (unaLinea) v = v.replace(/\s*\n\s*/g, ' ');
    return v.slice(0, 4000);
  }
  function ponerCampo(ed, v) {
    const el = ed.el;
    if (ed.op.campo === 'titulo') el.titulo = v;
    else if (ed.op.campo === 'col') { const c = (el.columnas || []).find((x) => x.id === ed.op.col); if (c) c.titulo = v; }
    else if (ed.op.campo === 'enlace') el.titulo = v;
    else el.texto = v;
  }
  // Mientras se escribe, los demás lo ven aparecer (sin entrar al historial hasta terminar)
  lienzo.addEventListener('input', (e) => {
    if (!editando || !editando.txt.contains(e.target)) return;
    const ed = editando, el = ed.el;
    ponerCampo(ed, leerTexto(ed.txt, ed.unaLinea));
    if (el.tipo === 'nota' && enTablero(el)) programarRender({ acomodar: true });
    else if (el.tipo === 'nota' || el.tipo === 'rect' || el.tipo === 'elipse') ajustarFuente(el, ed.nodo);
    else if (el.tipo === 'texto') { pend.medir.add(el.id); programarRender({}); }
    marcar(el);
    marcarSucio(el.id, true);
  });
  function terminarEdicion() {
    const ed = editando;
    if (!ed) return;
    editando = null;
    ponerCampo(ed, leerTexto(ed.txt, ed.unaLinea));
    ed.txt.removeAttribute('contenteditable');
    delete ed.txt.dataset.vacio;
    try { window.getSelection()?.removeAllRanges(); } catch (e) { /* nada */ }
    raiz.classList.remove('is-editando');
    const el = vivo(ed.id);
    if (!el) return;
    // Títulos vacíos: vuelven al que tenían
    if (ed.op.campo === 'titulo' && !el.titulo.trim()) el.titulo = ed.antes?.titulo || 'Tablero';
    if (ed.op.campo === 'col') { const c = el.columnas.find((x) => x.id === ed.op.col); if (c && !c.titulo.trim()) c.titulo = ed.antes?.columnas?.find((x) => x.id === ed.op.col)?.titulo || 'Columna'; }
    const ultimaEsCreacion = ed.entrada && pasado[pasado.length - 1] === ed.entrada;
    if (el.tipo === 'texto' && !(el.texto || '').trim()) {
      // Un texto que quedó vacío no se guarda
      if (ultimaEsCreacion) { pasado.pop(); pintarHistoria(); borrarLocal(el.id); marcarSucio(el.id); }
      else { const t = cambio(); t.tocar(el.id); borrarLocal(el.id); t.cerrar(); }
      programarRender({ sel: true, ctx: true, flechas: true });
      pintarVacio();
      return;
    }
    const d = copia(el);
    if (ultimaEsCreacion) { ed.entrada.forEach((p) => { if (p[0] === el.id) p[2] = d; }); }
    else if (JSON.stringify(ed.antes) !== JSON.stringify(d)) registrar([[el.id, ed.antes, d]]);
    marcar(el);
    marcarSucio(el.id);
    pintar(el);
    programarRender({ acomodar: true, flechas: true, sel: true, ctx: true });
    retomarSaltados();
  }

  /** Tab al escribir una nota: otra nota al lado (o debajo, en el tablero). */
  function notaAlLado(id) {
    const n = vivo(id);
    if (!n || n.tipo !== 'nota') return;
    let el;
    if (enTablero(n)) {
      const lista = notasDe(n.marco, n.col), i = lista.indexOf(n), sig = lista[i + 1];
      el = nuevoEl('nota', { color: n.color, marco: n.marco, col: n.col, orden: sig ? (num(n.orden) + num(sig.orden)) / 2 : num(n.orden) + 1, x: n.x, y: n.y });
    } else {
      el = nuevoEl('nota', { color: n.color, w: n.w, h: n.h, x: r1(num(n.x) + num(n.w) + 24), y: n.y });
    }
    crear([el]);
    render();
    editar(el.id, { nuevo: true });
  }

  /* =========================================================
     El puntero: elegir, mover, cambiar de tamaño, dibujar
     ========================================================= */
  lienzo.addEventListener('pointerdown', alBajar);
  // El botón del medio arrastra el lienzo (sin el desplazamiento automático del navegador)
  lienzo.addEventListener('mousedown', (e) => { if (e.button === 1) e.preventDefault(); });
  window.addEventListener('pointermove', alMover, { passive: true });
  window.addEventListener('pointerup', alSoltar);
  window.addEventListener('pointercancel', (e) => { toques.delete(e.pointerId); if (gesto && gesto.id === e.pointerId) cancelarGesto(); if (toques.size < 2) pellizco = null; });

  function alBajar(e) {
    ocultarTip();
    if (e.pointerType === 'touch') {
      toques.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (toques.size === 2) { cancelarGesto(); iniciarPellizco(); return; }
      if (toques.size > 2) return;
    }
    if (e.button === 2) return;
    if (editando) {
      if (editando.txt.contains(e.target)) return;   // mover el cursor dentro del texto
      terminarEdicion();
    }
    if (e.target.closest('[data-abrir], button')) return;   // enlaces y botones del tablero
    if (!ayuda.hidden) ayuda.hidden = true;
    lienzo.focus({ preventScroll: true });
    const p = aMundo(e.clientX, e.clientY);
    const base = { id: e.pointerId, sx: e.clientX, sy: e.clientY, p0: p, movido: false, ult: e, tactil: e.pointerType === 'touch' };
    if (e.button === 1 || espacio || herramienta === 'mano' || !EDITAR) { e.preventDefault(); empezarPan(base); return; }
    e.preventDefault();
    const nodo = e.target.closest('[data-id]');
    let el = nodo ? vivo(nodo.dataset.id) : null;
    if (el?.tipo === 'kanban' && !e.target.closest('[data-agarre]')) el = null;   // el cuerpo del tablero es lienzo
    switch (herramienta) {
      case 'seleccionar': {
        const asa = e.target.closest('.pz-asa');
        if (asa) { empezarAsa(base, asa.dataset.asa); return; }
        if (el) { empezarElemento(base, el, e); return; }
        if (base.tactil) { empezarPan(base); return; }
        gesto = { ...base, tipo: 'recuadro', shift: e.shiftKey };
        return;
      }
      case 'nota': {
        const f = factor(), w = Math.round(200 * f);
        const n = nuevoEl('nota', { x: r1(p.x - w / 2), y: r1(p.y - w / 2), w, h: w });
        const t = cambio(); t.tocar(n.id); els.set(n.id, n); pintar(n);
        gesto = { ...base, tipo: 'colocar', el: n, t, ids: new Set([n.id]), dx: w / 2, dy: w / 2 };
        return;
      }
      case 'rect': case 'elipse': gesto = { ...base, tipo: 'forma', herr: herramienta }; return;
      case 'flecha': {
        const origen = elementoEn(p);
        const f = nuevoEl('flecha', { a: origen ? { x: r1(p.x), y: r1(p.y), el: origen.id } : { x: r1(p.x), y: r1(p.y) }, b: { x: r1(p.x), y: r1(p.y) }, grosor: Math.round(3 * Math.min(2, factor())) });
        const t = cambio(); t.tocar(f.id); els.set(f.id, f);
        gesto = { ...base, tipo: 'flecha', el: f, t, origen: origen?.id };
        return;
      }
      case 'lapiz': {
        const g = document.createElementNS(SVGNS, 'g');
        g.dataset.color = pref.lapizColor;
        g.innerHTML = '<path class="pz-linea pz-previa"/>';
        svg.appendChild(g);
        gesto = { ...base, tipo: 'trazo', pts: [[p.x, p.y]], g, grosor: Math.max(1, Math.round(pref.lapizGrosor * factor())) };
        g.firstChild.setAttribute('stroke-width', String(gesto.grosor));
        return;
      }
      case 'texto': case 'kanban': case 'enlace': gesto = { ...base, tipo: 'clic', herr: herramienta }; return;
    }
  }

  function empezarPan(base) {
    gesto = { ...base, tipo: 'pan', vx: vista.x, vy: vista.y };
    raiz.classList.add('is-paneando');
  }
  function empezarElemento(base, el, e) {
    if (e.shiftKey) {
      if (sel.has(el.id)) { sel.delete(el.id); pintarSel(); pintarCtx(); return; }
      sel.add(el.id);
    } else if (!sel.has(el.id)) { sel.clear(); sel.add(el.id); }
    pintarSel();
    gesto = { ...base, tipo: 'mover', el, alt: e.altKey, soloEste: !e.shiftKey && sel.size > 1 };
  }
  function empezarAsa(base, asa) {
    const el = vivo([...sel][0]);
    if (!el) return;
    const t = cambio();
    t.tocar(el.id);
    if (asa === 'a' || asa === 'b') { gesto = { ...base, tipo: 'extremo', el, cual: asa, t }; return; }
    gesto = { ...base, tipo: 'asa', el, asa, t, g0: geo(el), el0: copia(el) };
  }

  function alMover(e) {
    if (e.pointerType === 'touch' && toques.has(e.pointerId)) {
      toques.set(e.pointerId, { x: e.clientX, y: e.clientY });
      if (pellizco) { moverPellizco(); return; }
    }
    if (!gesto || gesto.id !== e.pointerId) {
      if (e.pointerType === 'mouse' && !e.buttons && lienzo.contains(e.target)) { puntero = { x: e.clientX, y: e.clientY }; hover(e); }
      return;
    }
    const g = gesto;
    g.ult = e;
    if (!g.movido) {
      const umbral = g.tipo === 'trazo' ? 0 : (g.tactil ? 6 : 3);
      if (Math.hypot(e.clientX - g.sx, e.clientY - g.sy) < umbral) return;
      g.movido = true;
      arrancar(g);
    }
    if (g.tipo === 'trazo') sumarPuntos(g, e);
    if (!g.raf) g.raf = requestAnimationFrame(() => { g.raf = 0; if (gesto === g) paso(g); });
  }

  function alSoltar(e) {
    if (e.pointerType === 'touch') { toques.delete(e.pointerId); if (pellizco && toques.size < 2) { pellizco = null; return; } }
    const g = gesto;
    if (!g || g.id !== e.pointerId) return;
    if (g.raf) { cancelAnimationFrame(g.raf); g.raf = 0; }
    g.ult = e;
    if (g.movido) paso(g);
    gesto = null;
    terminar(g, e);
    retomarSaltados();
  }

  function hover(e) {
    if (!EDITAR || herramienta !== 'seleccionar' || espacio) { pintarHover(null); return; }
    if (e.target.closest('.pz-asa')) { pintarHover(null); return; }
    const n = e.target.closest('[data-id]');
    let el = n ? vivo(n.dataset.id) : null;
    if (el?.tipo === 'kanban' && !e.target.closest('[data-agarre]')) el = null;
    pintarHover(el && !sel.has(el.id) ? el.id : null);
  }

  function arrancar(g) {
    pintarHover(null);
    ocultarTip();
    switch (g.tipo) {
      case 'mover': arrancarMover(g); break;
      case 'recuadro': {
        g.base = g.shift ? new Set(sel) : new Set();
        if (!g.shift) sel.clear();
        g.div = document.createElement('div');
        g.div.className = 'pz-recuadro';
        capaSel.appendChild(g.div);
        ocultarCtx();
        break;
      }
      case 'forma': {
        g.t = cambio();
        g.el = nuevoEl(g.herr, { x: r1(g.p0.x), y: r1(g.p0.y), w: 1, h: 1 });
        g.t.tocar(g.el.id);
        els.set(g.el.id, g.el);
        pintar(g.el);
        break;
      }
      case 'colocar': ocultarCtx(); break;
      case 'asa': case 'extremo': ocultarCtx(); break;
    }
  }

  function arrancarMover(g) {
    ocultarCtx();
    g.t = cambio();
    if (g.alt) {
      // Alt + arrastrar: se arrastra una copia y el original se queda
      const ids = pegarElementos(copiarSeleccion(), null, 0, 0, g.t);
      sel.clear(); ids.forEach((id) => sel.add(id));
    }
    const ids = new Set();
    for (const id of sel) {
      const el = vivo(id);
      if (!el) continue;
      if (el.tipo === 'nota' && el.marco && sel.has(el.marco)) continue;   // va con su tablero
      ids.add(id);
    }
    g.ids = ids;
    g.ini = new Map();
    g.tableros = new Set();
    g.soloNotas = ids.size > 0 && [...ids].every((id) => els.get(id).tipo === 'nota');
    for (const id of ids) {
      const el = els.get(id);
      g.t.tocar(id);
      if (enTablero(el)) { el.x = el._lx; el.y = el._ly; el._suelta = true; g.tableros.add(el.marco); }
      if (el.tipo === 'kanban') g.tableros.add(el.id);
      g.ini.set(id, el.tipo === 'flecha' ? { a: { ...el.a }, b: { ...el.b } } : { x: num(el.x), y: num(el.y) });
      nodos.get(id)?.classList.add('is-arrastrando');
    }
    g.caja0 = union([...ids].map((id) => geo(els.get(id))));
    g.candidatos = candidatosGuia(ids);
    for (const kid of g.tableros) { const k = vivo(kid); if (k) acomodar(k, false); }
  }

  function paso(g) {
    const e = g.ult;
    const p = aMundo(e.clientX, e.clientY);
    switch (g.tipo) {
      case 'pan':
        vista.x = g.vx + (e.clientX - g.sx);
        vista.y = g.vy + (e.clientY - g.sy);
        aplicarVista();
        break;
      case 'mover': pasoMover(g, p, e); break;
      case 'colocar': {
        g.el.x = r1(p.x - g.dx); g.el.y = r1(p.y - g.dy);
        pintar(g.el);
        actualizarHueco(p, g);
        break;
      }
      case 'recuadro': {
        const c = { x: Math.min(g.p0.x, p.x), y: Math.min(g.p0.y, p.y), w: Math.abs(p.x - g.p0.x), h: Math.abs(p.y - g.p0.y) };
        g.div.style.transform = `translate(${c.x}px,${c.y}px)`;
        g.div.style.width = c.w + 'px';
        g.div.style.height = c.h + 'px';
        sel.clear();
        g.base.forEach((id) => sel.add(id));
        for (const el of vivos()) {
          const ge = geo(el);
          if (el.tipo === 'kanban' ? contiene(c, ge) : cruza(c, ge)) sel.add(el.id);
        }
        pintarSel();
        break;
      }
      case 'forma': {
        let w = Math.abs(p.x - g.p0.x), h = Math.abs(p.y - g.p0.y);
        if (e.shiftKey) w = h = Math.max(w, h);
        g.el.x = r1(p.x < g.p0.x ? g.p0.x - w : g.p0.x);
        g.el.y = r1(p.y < g.p0.y ? g.p0.y - h : g.p0.y);
        g.el.w = r1(Math.max(1, w)); g.el.h = r1(Math.max(1, h));
        pintar(g.el);
        break;
      }
      case 'flecha': {
        const f = g.el;
        const otro = elementoEn(p, new Set([f.id, g.origen].filter(Boolean)));
        f.b = otro ? { x: r1(p.x), y: r1(p.y), el: otro.id } : { x: r1(p.x), y: r1(p.y) };
        pintarBlanco(otro);
        pintar(f);
        break;
      }
      case 'extremo': {
        const f = g.el, otroFin = g.cual === 'a' ? f.b : f.a;
        let blanco = elementoEn(p, new Set([f.id]));
        if (blanco && blanco.id === otroFin?.el) blanco = null;
        f[g.cual] = blanco ? { x: r1(p.x), y: r1(p.y), el: blanco.id } : { x: r1(p.x), y: r1(p.y) };
        pintarBlanco(blanco);
        pintar(f);
        pintarSel();
        break;
      }
      case 'asa': pasoAsa(g, p, e); break;
      case 'trazo': g.g.firstChild.setAttribute('d', camino(g.pts.map(([x, y]) => [r1(x), r1(y)]))); break;
    }
  }

  function pasoMover(g, p, e) {
    let dx = p.x - g.p0.x, dy = p.y - g.p0.y;
    let lineas = [];
    if (g.soloNotas) actualizarHueco(p, g);
    if (!(e.ctrlKey || e.metaKey) && !hueco && g.candidatos.length) {
      const s = guias({ x: g.caja0.x + dx, y: g.caja0.y + dy, w: g.caja0.w, h: g.caja0.h }, g.candidatos);
      dx += s.dx; dy += s.dy; lineas = s.lineas;
    }
    pintarGuias(lineas);
    for (const id of g.ids) {
      const el = els.get(id), i = g.ini.get(id);
      if (el.tipo === 'flecha') {
        if (!el.a.el) { el.a.x = r1(i.a.x + dx); el.a.y = r1(i.a.y + dy); }
        if (!el.b.el) { el.b.x = r1(i.b.x + dx); el.b.y = r1(i.b.y + dy); }
      } else { el.x = r1(i.x + dx); el.y = r1(i.y + dy); }
      const n = nodos.get(id);
      if (el.tipo === 'trazo' || el.tipo === 'flecha') pintar(el);
      else if (n) mover(n, el.x, el.y);
      if (el.tipo === 'kanban') acomodar(el, false);
    }
    repintarFlechas();
    pintarSel();
  }

  function pasoAsa(g, p, e) {
    const el = g.el, g0 = g.g0, a = g.asa;
    const izq = a.includes('w'), der = a.includes('e'), arr = a.includes('n'), aba = a.includes('s');
    const ax = izq ? g0.x + g0.w : g0.x, ay = arr ? g0.y + g0.h : g0.y;
    const ncols = (el.columnas || []).length || 1;
    const minW = { kanban: K.colMin * ncols + K.margen, texto: 40, nota: 60, imagen: 24, enlace: 200 }[el.tipo] || 16;
    const minH = { kanban: 220, nota: 60, enlace: 64 }[el.tipo] || 12;
    let w = izq || der ? Math.max(minW, izq ? ax - p.x : p.x - ax) : g0.w;
    let h = arr || aba ? Math.max(minH, arr ? ay - p.y : p.y - ay) : g0.h;
    const esquina = (izq || der) && (arr || aba);
    if (el.tipo === 'texto') {
      if (esquina) {
        // Esquina: crece todo el texto (letra y ancho a la vez)
        const s = Math.max(0.15, w / g0.w);
        el.fs = r1(limitar(num(g.el0.fs, 28) * s, 8, 400));
        el.w = r1(g0.w * s);
        el.x = r1(izq ? ax - el.w : ax);
        el.y = r1(arr ? ay - g0.h * s : ay);
      } else { el.w = r1(w); el.x = r1(izq ? ax - w : ax); }
    } else {
      const proporcion = (el.tipo === 'imagen') !== e.shiftKey;
      if (proporcion && esquina) { const r = g0.w / g0.h || 1; if (w / g0.w > h / g0.h) h = w / r; else w = h * r; }
      el.w = r1(w); el.h = r1(h);
      el.x = r1(izq ? ax - w : (der ? ax : g0.x));
      el.y = r1(arr ? ay - h : (aba ? ay : g0.y));
    }
    pintar(el);
    if (el.tipo === 'kanban') acomodar(el, true);
    repintarFlechas();
    pintarSel();
  }

  function sumarPuntos(g, e) {
    const lista = e.getCoalescedEvents ? e.getCoalescedEvents() : [];
    for (const c of lista.length ? lista : [e]) {
      const p = aMundo(c.clientX, c.clientY), u = g.pts[g.pts.length - 1];
      if (Math.hypot(p.x - u[0], p.y - u[1]) * vista.z >= 1.5) g.pts.push([p.x, p.y]);
    }
  }

  /** Dónde caería en un tablero lo que se arrastra (solo tarjetas). */
  function actualizarHueco(p, g) {
    const k = kanbanEn(p, g.ids);
    let nuevo = null;
    if (k) {
      const cols = k.columnas || [];
      const pasoCol = (num(k.w) - K.margen) / Math.max(1, cols.length);
      const i = limitar(Math.floor((p.x - num(k.x) - K.margen / 2) / pasoCol), 0, cols.length - 1);
      const col = cols[i];
      if (col) {
        const lista = notasDe(k.id, col.id).filter((n) => !g.ids.has(n.id));
        let idx = lista.length;
        for (let j = 0; j < lista.length; j++) if (p.y < num(lista[j]._ly) + (lista[j]._lh || 64) / 2) { idx = j; break; }
        const alto = [...g.ids].reduce((s, id) => s + (els.get(id)._suelta ? (els.get(id)._lh || 64) : 72) + K.gap, -K.gap);
        nuevo = { k: k.id, col: col.id, i: idx, h: Math.max(48, alto) };
      }
    }
    const igual = (a, b) => (!a && !b) || (a && b && a.k === b.k && a.col === b.col && a.i === b.i);
    if (igual(hueco, nuevo)) return;
    const antes = hueco?.k;
    hueco = nuevo;
    for (const kid of new Set([antes, nuevo?.k].filter(Boolean))) { const kk = vivo(kid); if (kk) acomodar(kk, false); }
    pintarHueco();
  }

  /** Mete tarjetas en una columna, en la posición idx (orden fraccionario: no reescribe las demás). */
  function insertarEnColumna(notas, kid, colId, idx) {
    const lista = notasDe(kid, colId).filter((n) => !notas.includes(n));
    const prev = lista[idx - 1], next = lista[idx];
    const a = prev ? num(prev.orden) : (next ? num(next.orden) - notas.length - 1 : 0);
    const b = next ? num(next.orden) : a + notas.length + 1;
    notas.forEach((n, j) => { n.marco = kid; n.col = colId; n.orden = a + ((b - a) * (j + 1)) / (notas.length + 1); });
  }

  function terminar(g, e) {
    raiz.classList.remove('is-paneando');
    pintarGuias([]);
    pintarBlanco(null);
    switch (g.tipo) {
      case 'pan':
        if (!g.movido && herramienta === 'seleccionar' && EDITAR) { sel.clear(); pintarSel(); pintarCtx(); }
        break;
      case 'mover': terminarMover(g); break;
      case 'colocar': {
        if (!g.movido) actualizarHueco(g.p0, g);
        const n = g.el;
        if (hueco) insertarEnColumna([n], hueco.k, hueco.col, hueco.i);
        hueco = null; pintarHueco();
        g.t.cerrar();
        sel.clear(); sel.add(n.id);
        elegirHerr('seleccionar');
        pintar(n);
        render();
        if (enTablero(n)) acomodar(vivo(n.marco), true);
        editar(n.id, { nuevo: true });
        pintarVacio();
        break;
      }
      case 'recuadro':
        g.div?.remove();
        if (!g.movido && !g.shift) sel.clear();
        pintarSel(); pintarCtx();
        break;
      case 'forma': {
        const f = factor();
        if (!g.movido || (g.el.w * vista.z < 8 && g.el.h * vista.z < 8)) {
          if (!g.t) { g.t = cambio(); g.el = nuevoEl(g.herr, {}); g.t.tocar(g.el.id); els.set(g.el.id, g.el); }
          const w = (g.herr === 'rect' ? 200 : 190) * f, h = (g.herr === 'rect' ? 120 : 130) * f;
          Object.assign(g.el, { x: r1(g.p0.x - w / 2), y: r1(g.p0.y - h / 2), w: r1(w), h: r1(h) });
        }
        pintar(g.el);
        g.t.cerrar();
        sel.clear(); sel.add(g.el.id);
        elegirHerr('seleccionar');
        programarRender({ sel: true, ctx: true });
        pintarVacio();
        break;
      }
      case 'flecha': {
        const f = g.el;
        const [a, b] = extremos(f);
        if (!g.movido || Math.hypot(b.x - a.x, b.y - a.y) * vista.z < 10) { els.delete(f.id); quitarNodo(f.id); break; }
        g.t.cerrar();
        sel.clear(); sel.add(f.id);
        elegirHerr('seleccionar');
        programarRender({ sel: true, ctx: true });
        pintarVacio();
        break;
      }
      case 'extremo': case 'asa':
        g.t.cerrar();
        programarRender({ acomodar: true, flechas: true, sel: true, ctx: true });
        break;
      case 'trazo': terminarTrazo(g); break;
      case 'clic': {
        const f = factor();
        if (g.herr === 'enlace') { abrirEnlace(g.p0); break; }   // aquí va la tarjeta
        if (g.herr === 'texto') {
          const fs = Math.round(28 * f);
          const el = nuevoEl('texto', { x: r1(g.p0.x - 4), y: r1(g.p0.y - fs * 0.65), w: Math.round(320 * f), fs });
          crear([el]);
          elegirHerr('seleccionar');
          render();
          editar(el.id, { nuevo: true });
        } else if (g.herr === 'kanban') {
          const w = Math.round(960 * Math.min(f, 2)), h = Math.round(560 * Math.min(f, 2));
          const k = nuevoEl('kanban', { x: r1(g.p0.x - w / 2), y: r1(g.p0.y - 28 * f), w, h });
          crear([k]);
          elegirHerr('seleccionar');
          toast(CFG.mia ? 'Tablero listo. «Traer mis tareas» lo llena con tus tareas abiertas.' : 'Tablero listo. «Traer tareas del proyecto» lo llena con las tareas reales.');
        }
        break;
      }
    }
  }

  function terminarMover(g) {
    if (!g.movido) {
      if (g.soloEste) { sel.clear(); sel.add(g.el.id); }
      pintarSel(); pintarCtx();
      return;
    }
    for (const id of g.ids) nodos.get(id)?.classList.remove('is-arrastrando');
    const notas = [...g.ids].map((id) => els.get(id)).filter((el) => el.tipo === 'nota').sort((a, b) => num(a.y) - num(b.y));
    if (hueco && g.soloNotas) {
      insertarEnColumna(notas, hueco.k, hueco.col, hueco.i);
    } else {
      // Fuera del tablero: la tarjeta queda suelta donde se soltó, con su tamaño de nota
      for (const n of notas) if (n._suelta) { n.marco = null; n.col = null; delete n.orden; }
    }
    for (const n of notas) delete n._suelta;
    hueco = null;
    pintarHueco();
    g.t.cerrar();
    for (const id of g.ids) pintar(els.get(id));
    programarRender({ acomodar: true, flechas: true, sel: true, ctx: true });
  }

  /** Ramer–Douglas–Peucker: deja los puntos que dan la forma. */
  function simplificar(pts, eps) {
    if (pts.length < 3) return pts;
    const d2 = (p, a, b) => {
      const dx = b[0] - a[0], dy = b[1] - a[1], l = dx * dx + dy * dy;
      const t = l ? limitar(((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / l, 0, 1) : 0;
      const x = a[0] + t * dx - p[0], y = a[1] + t * dy - p[1];
      return x * x + y * y;
    };
    const guardar = new Uint8Array(pts.length);
    guardar[0] = guardar[pts.length - 1] = 1;
    const pila = [[0, pts.length - 1]];
    while (pila.length) {
      const [i, j] = pila.pop();
      let max = 0, k = -1;
      for (let m = i + 1; m < j; m++) { const d = d2(pts[m], pts[i], pts[j]); if (d > max) { max = d; k = m; } }
      if (k > 0 && max > eps * eps) { guardar[k] = 1; pila.push([i, k], [k, j]); }
    }
    return pts.filter((_, i) => guardar[i]);
  }
  function terminarTrazo(g) {
    g.g.remove();
    let pts = g.pts;
    // Suavizado: media de cada punto con sus vecinos (los extremos quedan)
    if (pts.length > 4) pts = pts.map((p, i) => (i === 0 || i === pts.length - 1) ? p : [(pts[i - 1][0] + p[0] * 2 + pts[i + 1][0]) / 4, (pts[i - 1][1] + p[1] * 2 + pts[i + 1][1]) / 4]);
    pts = simplificar(pts, 0.8 / vista.z);
    let x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
    for (const [x, y] of pts) { x1 = Math.min(x1, x); y1 = Math.min(y1, y); x2 = Math.max(x2, x); y2 = Math.max(y2, y); }
    x1 = Math.floor(x1); y1 = Math.floor(y1);
    const rel = [];
    for (const [x, y] of pts) { const q = [Math.round(x - x1), Math.round(y - y1)]; const u = rel[rel.length - 1]; if (!u || u[0] !== q[0] || u[1] !== q[1]) rel.push(q); }
    if (rel.length === 1) rel.push([rel[0][0], rel[0][1]]);
    const w = Math.max(1, Math.ceil(x2 - x1)), h = Math.max(1, Math.ceil(y2 - y1));
    const el = nuevoEl('trazo', { x: x1, y: y1, w, h, pw: w, ph: h, puntos: rel, grosor: g.grosor });
    const t = cambio(); t.tocar(el.id); els.set(el.id, el); pintar(el); t.cerrar();
    pintarVacio();
  }

  function cancelarGesto() {
    const g = gesto;
    if (!g) return;
    gesto = null;
    if (g.raf) cancelAnimationFrame(g.raf);
    raiz.classList.remove('is-paneando');
    pintarGuias([]); pintarBlanco(null);
    if (g.div) g.div.remove();
    if (g.g && g.tipo === 'trazo') g.g.remove();
    if (g.ids) for (const id of g.ids) { const el = els.get(id); if (el) delete el._suelta; nodos.get(id)?.classList.remove('is-arrastrando'); }
    hueco = null; pintarHueco();
    if (g.t) g.t.revertir();
    programarRender({ acomodar: true, flechas: true, sel: true, ctx: true });
    retomarSaltados();
  }

  /* ----- Dos dedos: zoom y desplazamiento ----- */
  function iniciarPellizco() {
    const [a, b] = [...toques.values()];
    pellizco = { d0: Math.hypot(a.x - b.x, a.y - b.y) || 1, m0: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }, z0: vista.z, x0: vista.x, y0: vista.y };
  }
  function moverPellizco() {
    const [a, b] = [...toques.values()];
    if (!a || !b) return;
    const d = Math.hypot(a.x - b.x, a.y - b.y), m = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
    const z = limitar(pellizco.z0 * d / pellizco.d0, Z_MIN, Z_MAX);
    const wx = (pellizco.m0.x - pellizco.x0) / pellizco.z0, wy = (pellizco.m0.y - pellizco.y0) / pellizco.z0;
    vista.z = z; vista.x = m.x - wx * z; vista.y = m.y - wy * z;
    aplicarVista();
  }

  /* ----- Doble clic: escribir en lo elegido (o una nota nueva en lo vacío) ----- */
  lienzo.addEventListener('dblclick', (e) => {
    if (!EDITAR || herramienta !== 'seleccionar' || espacio) return;
    if (editando && editando.txt.contains(e.target)) return;
    if (e.target.closest('button, [data-abrir], .pz-asa')) return;
    const n = e.target.closest('[data-id]');
    const el = n ? vivo(n.dataset.id) : null;
    const campo = e.target.closest('[data-campo]');
    if (el && campo) { editar(el.id, { campo: campo.dataset.campo, col: campo.dataset.colId, x: e.clientX, y: e.clientY }); return; }
    if (el && el.tipo !== 'kanban') {
      if (CON_TEXTO.has(el.tipo) || el.tipo === 'enlace') editar(el.id, { x: e.clientX, y: e.clientY });
      return;
    }
    // En lo vacío (o en el cuerpo de un tablero): una nota nueva, y a escribir
    const p = aMundo(e.clientX, e.clientY), f = factor(), w = Math.round(200 * f);
    const nota = nuevoEl('nota', { x: r1(p.x - w / 2), y: r1(p.y - w / 2), w, h: w });
    const k = kanbanEn(p);
    if (k) {
      gesto = null;
      const tmp = { ids: new Set([nota.id]) };
      els.set(nota.id, nota);
      actualizarHueco(p, tmp);
      els.delete(nota.id);
      if (hueco) insertarEnColumna([nota], hueco.k, hueco.col, hueco.i);
      hueco = null; pintarHueco();
    }
    crear([nota]);
    render();
    editar(nota.id, { nuevo: true });
  });

  /* ----- Botones del tablero (traer tareas, añadir tarjeta, quitar columna) ----- */
  lienzo.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b || !EDITAR) return;
    const kid = b.closest('[data-id]')?.dataset.id;
    if (!kid) return;
    if (b.hasAttribute('data-kanban-traer')) traerTareas(kid);
    else if (b.dataset.colMas) nuevaTarjeta(kid, b.dataset.colMas);
    else if (b.dataset.colQuitar) quitarColumna(kid, b.dataset.colQuitar);
  });

  /* =========================================================
     Tablero Kanban
     ========================================================= */
  function nuevaTarjeta(kid, colId) {
    const k = vivo(kid);
    if (!k) return;
    if (editando) terminarEdicion();
    const lista = notasDe(kid, colId);
    const el = nuevoEl('nota', { x: k.x, y: k.y, marco: kid, col: colId, orden: lista.length ? num(lista[lista.length - 1].orden) + 1 : 1 });
    crear([el]);
    render();
    editar(el.id, { nuevo: true });
  }
  function agregarColumna(kid) {
    const k = vivo(kid);
    if (!k) return;
    const t = cambio();
    t.tocar(kid);
    const n = Math.max(1, k.columnas.length), ancho = (num(k.w) - K.margen) / n;
    const col = { id: idCorto(), titulo: 'Nueva columna' };
    k.columnas.push(col);
    k.w = r1(num(k.w) + ancho);
    t.cerrar();
    pintar(k);
    render();
    editar(kid, { campo: 'col', col: col.id, todo: true });
  }
  function quitarColumna(kid, colId) {
    const k = vivo(kid);
    if (!k) return;
    const i = k.columnas.findIndex((c) => c.id === colId);
    if (i < 0) return;
    if (k.columnas.length < 2) { toast('Un tablero necesita al menos una columna.'); return; }
    const destino = k.columnas[i - 1] || k.columnas[i + 1];
    const notas = notasDe(kid, colId);
    const t = cambio();
    t.tocar(kid);
    const ancho = (num(k.w) - K.margen) / k.columnas.length;
    const ultima = notasDe(kid, destino.id).pop();
    notas.forEach((n, j) => { t.tocar(n.id); n.col = destino.id; n.orden = (ultima ? num(ultima.orden) : 0) + j + 1; });
    const quitada = k.columnas.splice(i, 1)[0];
    k.w = r1(Math.max(K.colMin * k.columnas.length + K.margen, num(k.w) - ancho));
    t.cerrar();
    pintar(k);
    notas.forEach(pintar);
    programarRender({ acomodar: true, flechas: true, sel: true });
    toast(notas.length
      ? `Columna «${quitada.titulo}» quitada: sus ${notas.length === 1 ? 'tarjeta pasó' : notas.length + ' tarjetas pasaron'} a «${destino.titulo}».`
      : `Columna «${quitada.titulo}» quitada.`);
  }

  /**
   * «Traer tareas del proyecto»: una tarjeta por tarea, en la columna de su
   * estado. Solo crea (o pone al día) tarjetas en la pizarra: las tareas no
   * cambian. Si la tarea ya tenía su tarjeta, se le pone el título y la
   * columna de ahora, sin duplicarla.
   */
  async function traerTareas(kid) {
    if (!vivo(kid)) return;
    if (editando) terminarEdicion();
    const boton = nodos.get(kid)?.querySelector('[data-kanban-traer]');
    boton?.classList.add('is-cargando');
    let j;
    try { j = await pedir('tareas'); }
    catch (err) { toast(err.red ? 'Sin conexión: no se pudieron traer las tareas. Prueba cuando vuelva la red.' : (err.message || 'No se pudieron traer las tareas.'), true); return; }
    finally { boton?.classList.remove('is-cargando'); }
    const k = vivo(kid);
    if (!k) return;
    let tareas = Array.isArray(j.tareas) ? j.tareas : [];
    if (!tareas.length) { toast(CFG.mia ? 'No tienes tareas abiertas: todo al día.' : 'Este proyecto todavía no tiene tareas.'); return; }
    // De las terminadas, solo las 20 más recientes: el tablero es para la reunión, no el archivo
    const hechas = tareas.filter((t) => t.estado === 'hecho').sort((a, b) => b.id - a.id);
    const fuera = new Set(hechas.slice(20).map((t) => t.id));
    tareas = tareas.filter((t) => !fuera.has(t.id));
    const cols = k.columnas;
    const colDe = (estado) => (cols.find((c) => c.estado === estado)
      || (estado === 'revision' && cols.find((c) => c.estado === 'progreso'))
      || cols[estado === 'hecho' ? cols.length - 1 : (estado === 'pendiente' ? 0 : Math.min(1, cols.length - 1))]).id;
    const existentes = new Map();
    for (const n of vivos()) if (n.tipo === 'nota' && n.marco === kid && n.tarea?.id) existentes.set(Number(n.tarea.id), n);
    const ultimo = new Map();
    const siguienteOrden = (col) => {
      if (!ultimo.has(col)) { const l = notasDe(kid, col); ultimo.set(col, l.length ? num(l[l.length - 1].orden) : 0); }
      const o = ultimo.get(col) + 1;
      ultimo.set(col, o);
      return o;
    };
    const t = cambio();
    let nuevas = 0, movidas = 0;
    for (const ta of tareas) {
      const col = colDe(ta.estado);
      const meta = { id: Number(ta.id), estado: String(ta.estado || ''), quien: String(ta.quien || '') };
      // En «Mis notas» las tareas son de varios proyectos: cada tarjeta dice de cuál y enlaza a él
      if (ta.proyecto_id) { meta.proyecto_id = Number(ta.proyecto_id); meta.proyecto = String(ta.proyecto || ''); }
      const ya = existentes.get(Number(ta.id));
      if (ya) {
        t.tocar(ya.id);
        ya.texto = String(ta.titulo || '');
        ya.tarea = meta;
        ya.color = COLOR_ESTADO[ta.estado] || ya.color;
        if (ya.col !== col) { ya.col = col; ya.orden = siguienteOrden(col); movidas++; }
        pintar(ya);
      } else {
        const el = nuevoEl('nota', { x: k.x, y: k.y, color: COLOR_ESTADO[ta.estado] || 'celeste', texto: String(ta.titulo || ''), marco: kid, col, orden: siguienteOrden(col), tarea: meta });
        t.tocar(el.id);
        els.set(el.id, el);
        pintar(el);
        nuevas++;
      }
    }
    t.cerrar();
    programarRender({ acomodar: true, flechas: true, sel: true });
    const extra = fuera.size ? ' De las terminadas, van las 20 últimas.' : '';
    if (!nuevas && !movidas) toast('El tablero ya estaba al día con las tareas.' + extra);
    else if (!movidas) toast(`Se trajeron ${nuevas} tarea${nuevas === 1 ? '' : 's'}${CFG.mia ? ' tuya' + (nuevas === 1 ? '' : 's') : ''}. Solo están en la pizarra: las tareas no cambian.${extra}`);
    else toast(`${nuevas} tarea${nuevas === 1 ? ' nueva' : 's nuevas'} y ${movidas} que cambiaron de columna.${extra}`);
  }

  /* =========================================================
     Herramientas, barra contextual y opciones del lápiz
     ========================================================= */
  function elegirHerr(h) {
    if (!EDITAR && h !== 'seleccionar' && h !== 'mano') return;
    if (editando) terminarEdicion();
    if (h === 'imagen') { archivo.click(); return; }
    if (h !== 'enlace' && popEnlace && !popEnlace.hidden) cerrarEnlace(false);
    herramienta = h;
    raiz.dataset.herr = h;
    barra.querySelectorAll('.pz-herr').forEach((b) => {
      const on = b.dataset.herr === h;
      b.classList.toggle('is-activa', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    colocarPieza();
    if (h !== 'seleccionar') { sel.clear(); pintarSel(); ocultarCtx(); }
    pintarHover(null);
    pintarSub();
    // Enlace: el cuadro sale enseguida; un clic en el lienzo elige dónde va la tarjeta
    if (h === 'enlace') abrirEnlace(null);
  }
  function colocarPieza() {
    const b = barra.querySelector(`.pz-herr[data-herr="${herramienta}"]`);
    if (b) barra.style.setProperty('--px', b.offsetLeft + 'px');
  }
  barra.addEventListener('pointerdown', (e) => { if (e.target.closest('button')) e.preventDefault(); });
  barra.addEventListener('click', (e) => {
    const b = e.target.closest('.pz-herr');
    if (!b) return;
    if (b.hasAttribute('data-calc')) alternarCalc();   // la calculadora no es una herramienta: se abre al lado
    else elegirHerr(b.dataset.herr);
  });
  // La lente de vidrio: sigue al mouse por la barra (solo con transform)
  barra.addEventListener('pointerover', (e) => {
    const b = e.target.closest('.pz-herr');
    if (!b || e.pointerType !== 'mouse') return;
    barra.style.setProperty('--lx', b.offsetLeft + 'px');
    barra.classList.add('is-lente');
  }, { passive: true });
  barra.addEventListener('pointerleave', () => barra.classList.remove('is-lente'), { passive: true });

  function pintarSub() {
    if (herramienta !== 'lapiz') { sub.hidden = true; raiz.classList.remove('con-sub'); return; }
    sub.innerHTML = COLORES.map((c) => `<button type="button" class="pz-color pz-color--tinta${c === pref.lapizColor ? ' is-activa' : ''}" data-color="${c}" data-sub="color" data-valor="${c}" data-tip="${NOMBRE_COLOR[c]}" aria-label="Lápiz ${NOMBRE_COLOR[c]}"></button>`).join('')
      + '<span class="pz-sub__sep"></span>'
      + [[2, 'Fino'], [4, 'Medio'], [8, 'Grueso']].map(([g, n]) => `<button type="button" class="pz-grosor${g === pref.lapizGrosor ? ' is-activa' : ''}" data-sub="grosor" data-valor="${g}" data-tip="${n}" aria-label="Trazo ${n.toLowerCase()}"><i style="height:${Math.max(2, Math.round(g / 1.3))}px"></i></button>`).join('');
    sub.hidden = false;
    raiz.classList.add('con-sub');
  }
  sub.addEventListener('pointerdown', (e) => { if (e.target.closest('button')) e.preventDefault(); });
  sub.addEventListener('click', (e) => {
    const b = e.target.closest('[data-sub]');
    if (!b) return;
    if (b.dataset.sub === 'color') pref.lapizColor = b.dataset.valor;
    else pref.lapizGrosor = Number(b.dataset.valor) || 4;
    guardarPref();
    pintarSub();
  });

  function ocultarCtx() { ctxBar.hidden = true; ctxBar._firma = ''; if (popAviso && !popAviso.hidden) cerrarAviso(); }
  const botonCtx = (accion, icono, nombre, tecla = '') => `<button type="button" class="pz-boton pz-boton--icono" data-ctx="${accion}" data-tip="${esc(nombre)}"${tecla ? ` data-tecla="${tecla}"` : ''} aria-label="${esc(nombre)}">${ico(icono)}</button>`;
  function pintarCtx() {
    if (!EDITAR || (gesto && gesto.movido) || !sel.size) { ocultarCtx(); return; }
    const lista = [...sel].map(vivo).filter(Boolean);
    if (!lista.length) { ocultarCtx(); return; }
    const firma = [...sel].join(',') + '|' + lista.map((e) => `${e.color}${e.grosor}${e.punta}${e.fs}${e.aviso || ''}`).join(',');
    if (ctxBar._firma !== firma) {
      ctxBar._firma = firma;
      const tipos = new Set(lista.map((e) => e.tipo));
      const partes = [];
      const conColor = lista.filter((e) => CON_COLOR.has(e.tipo));
      if (conColor.length) {
        const tinta = conColor.every((e) => TINTA.has(e.tipo));
        const actual = conColor.every((e) => e.color === conColor[0].color) ? colorDe(conColor[0]) : '';
        partes.push(COLORES.map((c) => `<button type="button" class="pz-color${tinta ? ' pz-color--tinta' : ''}${c === actual ? ' is-activa' : ''}" data-color="${c}" data-ctx="color" data-valor="${c}" data-tip="${NOMBRE_COLOR[c]}" aria-label="Color ${NOMBRE_COLOR[c]}"></button>`).join(''));
        partes.push('<span class="pz-ctx__sep"></span>');
      }
      let extra = '';
      if (tipos.has('texto')) extra += botonCtx('tamano', 'pz-tamano', 'Tamaño del texto');
      if (tipos.has('flecha')) extra += botonCtx('punta', 'pz-punta', 'Puntas de la flecha');
      if (tipos.has('flecha') || tipos.has('trazo')) extra += botonCtx('grosor', 'pz-grosor', 'Grosor de la línea');
      if (lista.length === 1 && tipos.has('kanban')) extra += botonCtx('traer', 'pz-traer', 'Traer tareas del proyecto') + botonCtx('columna', 'pz-columna', 'Añadir columna');
      if (lista.length === 1 && tipos.has('enlace')) extra += botonCtx('abrir', 'ds-enlace', 'Abrir el enlace');
      if (lista.length === 1 && (tipos.has('nota') || tipos.has('texto'))) {
        // El recordatorio: a su hora le llega un aviso (campana, notificación y correo) a quien lo puso
        const av = lista[0].aviso ? etiquetaAviso(lista[0].aviso) : '';
        const nombre = av ? `Recordatorio: ${av}` : 'Recordármelo a una hora';
        extra += `<button type="button" class="pz-boton pz-boton--icono${av ? ' is-activa' : ''}" data-ctx="aviso" data-tip="${esc(nombre)}" aria-label="${esc(nombre)}">${ico('ds-notificaciones')}</button>`;
      }
      if (extra) partes.push(extra, '<span class="pz-ctx__sep"></span>');
      partes.push(botonCtx('frente', 'pz-frente', 'Traer al frente'), botonCtx('duplicar', 'ds-duplicar', 'Duplicar', 'Ctrl D'), botonCtx('borrar', 'ds-eliminar', 'Borrar', 'Supr'));
      ctxBar.innerHTML = partes.join('');
      ctxBar._w = 0;
    }
    ctxBar.hidden = false;
    if (!ctxBar._w) { ctxBar._w = ctxBar.offsetWidth; ctxBar._h = ctxBar.offsetHeight; }
    posicionarCtx();
  }
  function posicionarCtx() {
    if (ctxBar.hidden || !sel.size) return;
    const c = union([...sel].map(vivo).filter(Boolean).map(geo));
    const a = aPantalla(c.x, c.y), b = aPantalla(c.x + c.w, c.y + c.h);
    const w = ctxBar._w || ctxBar.offsetWidth, h = ctxBar._h || ctxBar.offsetHeight;
    let x = (a.x + b.x) / 2 - w / 2;
    let y = a.y - h - 16;
    if (y < 80) y = b.y + 16;
    x = limitar(x, 8, window.innerWidth - w - 8);
    y = limitar(y, 76, window.innerHeight - h - 90);
    ctxBar.style.setProperty('--cx', Math.round(x) + 'px');
    ctxBar.style.setProperty('--cy', Math.round(y) + 'px');
  }
  ctxBar.addEventListener('pointerdown', (e) => { if (e.target.closest('button')) e.preventDefault(); });
  ctxBar.addEventListener('click', (e) => {
    const b = e.target.closest('[data-ctx]');
    if (!b) return;
    const lista = [...sel].map(vivo).filter(Boolean);
    const siguiente = (lista, v) => lista[(lista.indexOf(v) + 1) % lista.length] ?? lista[0];
    switch (b.dataset.ctx) {
      case 'color': {
        const c = b.dataset.valor;
        cambiarTodos(lista.filter((el) => CON_COLOR.has(el.tipo)), (el) => { el.color = c; });
        if (lista.some((el) => el.tipo === 'nota')) pref.notaColor = c;
        if (lista.some((el) => el.tipo === 'rect' || el.tipo === 'elipse')) pref.formaColor = c;
        if (lista.some((el) => el.tipo === 'flecha')) pref.flechaColor = c;
        guardarPref();
        break;
      }
      case 'tamano': cambiarTodos(lista.filter((el) => el.tipo === 'texto'), (el) => { el.fs = TAMANOS.find((t) => t > num(el.fs, 28) + 0.5) || TAMANOS[0]; }); break;
      case 'punta': cambiarTodos(lista.filter((el) => el.tipo === 'flecha'), (el) => { el.punta = siguiente(PUNTAS, el.punta || 'fin'); }); break;
      case 'grosor': cambiarTodos(lista.filter((el) => SVG_TIPOS.has(el.tipo)), (el) => { el.grosor = GROSORES.find((g) => g > num(el.grosor, 3) + 0.1) || GROSORES[0]; }); break;
      case 'frente': cambiarTodos([...lista].sort((a, c) => num(a.z) - num(c.z)), (el) => { el.z = ++maxZ; }); break;
      case 'duplicar': duplicar(); break;
      case 'borrar': borrarSeleccion(); break;
      case 'traer': traerTareas(lista[0].id); break;
      case 'columna': agregarColumna(lista[0].id); break;
      case 'abrir': if (/^https?:\/\//i.test(lista[0].url || '')) window.open(lista[0].url, '_blank', 'noopener'); break;
      case 'aviso': abrirAviso(lista[0].id); return;
    }
    ctxBar._firma = '';
    pintarCtx();
  });
  function cambiarTodos(lista, fn) {
    if (!lista.length) return;
    const t = cambio();
    lista.forEach((el) => { t.tocar(el.id); fn(el); pintar(el); });
    t.cerrar();
    programarRender({ acomodar: true, flechas: true, sel: true });
  }

  /* =========================================================
     Vista: zoom, desplazamiento, ver todo
     ========================================================= */
  const CLAVE_VISTA = 'pz-vista-' + CFG.reunion;
  let relojVista = 0, relojMov = 0, anim = 0, zPintado = 0;
  function aplicarVista() {
    mundo.style.transform = `translate(${vista.x}px,${vista.y}px) scale(${vista.z})`;
    let paso = 24 * vista.z;
    while (paso < 12) paso *= 2;
    lienzo.style.backgroundSize = `${paso}px ${paso}px`;
    lienzo.style.backgroundPosition = `${vista.x % paso}px ${vista.y % paso}px`;
    capaSel.style.setProperty('--pz-inv', String(1 / vista.z));
    if (pctEl) pctEl.textContent = Math.round(vista.z * 100) + ' %';
    // will-change solo mientras se mueve; al quedarse quieto se vuelve a dibujar nítido
    mundo.classList.add('is-moviendo');
    clearTimeout(relojMov);
    relojMov = setTimeout(() => mundo.classList.remove('is-moviendo'), 180);
    // Los anillos y las asas viven en el mundo: al desplazar se mueven solos; con el zoom cambia su grosor
    if (zPintado !== vista.z) { zPintado = vista.z; programarRender({ sel: true }); }
    posicionarCtx();
    clearTimeout(relojVista);
    relojVista = setTimeout(() => local.poner(CLAVE_VISTA, { x: Math.round(vista.x), y: Math.round(vista.y), z: vista.z }), 500);
  }
  function zoomEn(f, cx, cy) {
    const z = limitar(vista.z * f, Z_MIN, Z_MAX);
    const w = aMundo(cx, cy);
    vista.z = z; vista.x = cx - w.x * z; vista.y = cy - w.y * z;
    aplicarVista();
  }
  function animarVista(dest) {
    cancelAnimationFrame(anim);
    if (movReducido.matches) { Object.assign(vista, dest); aplicarVista(); return; }
    const ini = { ...vista }, t0 = performance.now(), ms = 220;
    const cuadro = (t) => {
      const k = Math.min(1, (t - t0) / ms), e = 1 - Math.pow(1 - k, 3);
      vista.z = ini.z + (dest.z - ini.z) * e;
      vista.x = ini.x + (dest.x - ini.x) * e;
      vista.y = ini.y + (dest.y - ini.y) * e;
      aplicarVista();
      if (k < 1) anim = requestAnimationFrame(cuadro);
    };
    anim = requestAnimationFrame(cuadro);
  }
  function zoomA(z, cx = window.innerWidth / 2, cy = window.innerHeight / 2) {
    z = limitar(z, Z_MIN, Z_MAX);
    const w = aMundo(cx, cy);
    animarVista({ z, x: cx - w.x * z, y: cy - w.y * z });
  }
  function ajustar(animado = true) {
    const lista = vivos().filter((e) => !enTablero(e));
    let dest;
    if (!lista.length) dest = { x: window.innerWidth / 2, y: window.innerHeight / 2, z: 1 };
    else {
      const c = union(lista.map(geo));
      const arriba = 84, abajo = 96, lado = 40;
      const aw = Math.max(100, window.innerWidth - lado * 2), ah = Math.max(100, window.innerHeight - arriba - abajo);
      const z = limitar(Math.min(aw / Math.max(1, c.w), ah / Math.max(1, c.h)), Z_MIN, 1.25);
      dest = { z, x: window.innerWidth / 2 - (c.x + c.w / 2) * z, y: arriba + ah / 2 - (c.y + c.h / 2) * z };
    }
    if (animado) animarVista(dest);
    else { Object.assign(vista, dest); aplicarVista(); }
  }

  // Rueda: Ctrl (o pellizco del trackpad, que llega con Ctrl) acerca; sola, desplaza
  document.addEventListener('wheel', (e) => {
    if (e.ctrlKey || e.metaKey) {
      e.preventDefault();   // que el navegador no haga zoom de la página
      const dy = e.deltaMode === 1 ? e.deltaY * 16 : e.deltaY;
      // el pellizco llega en pasos chicos; una muesca del mouse, en uno grande (~19 % como mucho)
      zoomEn(Math.exp(-limitar(dy, -25, 25) * 0.0085), e.clientX, e.clientY);
      return;
    }
    if (!lienzo.contains(e.target) && !e.target.closest?.('.pz-barra, .pz-zoom, .pz-arriba, .pz-ctx')) return;
    e.preventDefault();
    let dx = e.deltaX, dy = e.deltaY;
    if (e.deltaMode === 1) { dx *= 16; dy *= 16; }
    if (e.shiftKey && !dx) { dx = dy; dy = 0; }
    vista.x -= dx; vista.y -= dy;
    aplicarVista();
    if (editando) posicionarCtx();
  }, { passive: false });
  // Safari: el pellizco llega como «gesture»
  let gestoSafari = null;
  document.addEventListener('gesturestart', (e) => { e.preventDefault(); gestoSafari = { z: vista.z }; });
  document.addEventListener('gesturechange', (e) => {
    e.preventDefault();
    if (!gestoSafari) return;
    zoomEn((gestoSafari.z * e.scale) / vista.z, e.clientX || window.innerWidth / 2, e.clientY || window.innerHeight / 2);
  });
  document.addEventListener('gestureend', (e) => { e.preventDefault(); gestoSafari = null; });

  $('#pz-zoom').addEventListener('pointerdown', (e) => { if (e.target.closest('button')) e.preventDefault(); });
  $('#pz-zoom').addEventListener('click', (e) => {
    const b = e.target.closest('[data-accion]');
    if (!b) return;
    const acciones = { deshacer, rehacer, alejar: () => zoomA(vista.z / 1.25), acercar: () => zoomA(vista.z * 1.25), cien: () => zoomA(1), ajustar: () => ajustar() };
    acciones[b.dataset.accion]?.();
  });

  /* =========================================================
     Teclado
     ========================================================= */
  const ATAJOS = { v: 'seleccionar', h: 'mano', n: 'nota', t: 'texto', r: 'rect', o: 'elipse', a: 'flecha', p: 'lapiz', k: 'kanban', i: 'imagen', l: 'enlace' };
  document.addEventListener('keydown', (e) => {
    if (editando) {
      if (e.key === 'Escape' || (e.key === 'Enter' && (e.ctrlKey || e.metaKey))) { e.preventDefault(); terminarEdicion(); return; }
      if (e.key === 'Enter' && editando.unaLinea) { e.preventDefault(); terminarEdicion(); return; }
      if (e.key === 'Tab' && editando.el.tipo === 'nota') { e.preventDefault(); const id = editando.id; terminarEdicion(); notaAlLado(id); }
      return;
    }
    const t = e.target;
    if (t instanceof HTMLInputElement || t instanceof HTMLTextAreaElement || t?.isContentEditable) return;
    if (t?.closest?.('#pz-calc, .pz-pop')) return;   // la calculadora y los cuadros tienen su propio teclado
    const mod = e.ctrlKey || e.metaKey;
    if (e.code === 'Space' && !mod) {
      e.preventDefault();
      if (!espacio) { espacio = true; raiz.classList.add('is-espacio'); pintarHover(null); }
      return;
    }
    if (mod) {
      const k = e.key.toLowerCase();
      if (k === 'z') { e.preventDefault(); if (e.shiftKey) rehacer(); else deshacer(); }
      else if (k === 'y') { e.preventDefault(); rehacer(); }
      else if (k === 'd') { e.preventDefault(); duplicar(); }
      else if (k === 'a') { e.preventDefault(); if (EDITAR) { sel.clear(); vivos().forEach((el) => { if (!enTablero(el)) sel.add(el.id); }); pintarSel(); pintarCtx(); } }
      else if (k === '=' || k === '+') { e.preventDefault(); zoomA(vista.z * 1.25); }
      else if (k === '-') { e.preventDefault(); zoomA(vista.z / 1.25); }
      else if (k === '0') { e.preventDefault(); zoomA(1); }
      else if (k === 's') { e.preventDefault(); if (EDITAR) { ciclo(); toast('La pizarra se guarda sola: ya va en camino.'); } }
      return;   // Ctrl+C / X / V: van por los eventos copy / cut / paste
    }
    if (e.altKey) return;
    if (e.shiftKey && e.code === 'Digit1') { e.preventDefault(); ajustar(); return; }
    if (e.shiftKey && e.code === 'Digit2' && sel.size) { e.preventDefault(); ajustarA([...sel]); return; }
    switch (e.key) {
      case 'Escape':
        if (!ayuda.hidden) { ayuda.hidden = true; return; }
        if (popAviso && !popAviso.hidden) { cerrarAviso(); return; }
        if (popEnlace && !popEnlace.hidden) { cerrarEnlace(); return; }
        if (gesto) { cancelarGesto(); return; }
        if (herramienta !== 'seleccionar') { elegirHerr('seleccionar'); return; }
        if (sel.size) { sel.clear(); pintarSel(); pintarCtx(); }
        return;
      case 'Delete': case 'Backspace':
        if (EDITAR && sel.size) { e.preventDefault(); borrarSeleccion(); }
        return;
      case 'Enter': {
        const el = sel.size === 1 ? vivo([...sel][0]) : null;
        if (EDITAR && el && (CON_TEXTO.has(el.tipo) || el.tipo === 'enlace' || el.tipo === 'kanban')) { e.preventDefault(); editar(el.id, {}); }
        return;
      }
      case 'ArrowLeft': case 'ArrowRight': case 'ArrowUp': case 'ArrowDown': {
        if (!EDITAR || !sel.size) return;
        e.preventDefault();
        const d = e.shiftKey ? 10 : 1;
        const dx = e.key === 'ArrowLeft' ? -d : e.key === 'ArrowRight' ? d : 0, dy = e.key === 'ArrowUp' ? -d : e.key === 'ArrowDown' ? d : 0;
        const tr = cambio();
        for (const id of sel) {
          const el = vivo(id);
          if (!el || enTablero(el)) continue;
          tr.tocar(id);
          if (el.tipo === 'flecha') { if (!el.a.el) { el.a.x += dx; el.a.y += dy; } if (!el.b.el) { el.b.x += dx; el.b.y += dy; } }
          else { el.x = r1(num(el.x) + dx); el.y = r1(num(el.y) + dy); }
          pintar(el);
        }
        tr.cerrar({ unir: 'empujar' });
        programarRender({ acomodar: true, flechas: true, sel: true, ctx: true });
        return;
      }
      case '?': ayuda.hidden = !ayuda.hidden; return;
    }
    if (e.key.toLowerCase() === 'c') { e.preventDefault(); alternarCalc(); return; }
    const herr = ATAJOS[e.key.toLowerCase()];
    if (herr && (EDITAR || herr === 'seleccionar' || herr === 'mano')) { e.preventDefault(); elegirHerr(herr); }
  });
  document.addEventListener('keyup', (e) => { if (e.code === 'Space') { espacio = false; raiz.classList.remove('is-espacio'); } });
  window.addEventListener('blur', () => { espacio = false; raiz.classList.remove('is-espacio'); });
  function ajustarA(ids) {
    const c = union(ids.map(vivo).filter(Boolean).map(geo));
    if (!c.w) return;
    const ah = window.innerHeight - 180, aw = window.innerWidth - 80;
    const z = limitar(Math.min(aw / c.w, ah / c.h), Z_MIN, 2);
    animarVista({ z, x: window.innerWidth / 2 - (c.x + c.w / 2) * z, y: 84 + ah / 2 - (c.y + c.h / 2) * z });
  }

  /* =========================================================
     Copiar, pegar y soltar (imágenes, texto, enlaces y elementos)
     ========================================================= */
  const CLAVE_COPIA = 'pz-copia';
  let ultimoPegado = null;
  function puntoPegado() {
    const base = puntero ? aMundo(puntero.x, puntero.y) : aMundo(window.innerWidth / 2, window.innerHeight / 2);
    // Pegar varias veces seguidas en el mismo lugar: cada una un poco corrida
    if (ultimoPegado && Math.hypot(ultimoPegado.x - base.x, ultimoPegado.y - base.y) < 1 && Date.now() - ultimoPegado.t < 8000) ultimoPegado.n++;
    else ultimoPegado = { x: base.x, y: base.y, n: 0 };
    ultimoPegado.t = Date.now();
    const d = (ultimoPegado.n * 28) / vista.z;
    return { x: base.x + d, y: base.y + d };
  }
  const enCampo = (t) => t instanceof HTMLInputElement || t instanceof HTMLTextAreaElement || (t?.isContentEditable && !lienzo.contains(t));
  document.addEventListener('copy', (e) => {
    // Con la calculadora enfocada, Ctrl+C copia su resultado
    if (e.target?.closest?.('#pz-calc')) { e.preventDefault(); e.clipboardData.setData('text/plain', calcRes.textContent.trim()); toast('Resultado copiado.'); return; }
    if (editando || enCampo(e.target) || !sel.size) return;
    const lista = copiarSeleccion();
    if (!lista.length) return;
    const texto = textoDeCopia(lista);
    e.preventDefault();
    e.clipboardData.setData('text/plain', texto);
    local.poner(CLAVE_COPIA, { texto, elementos: lista });
    toast(lista.length === 1 ? 'Copiado.' : `Copiados ${lista.length} elementos.`);
  });
  document.addEventListener('cut', (e) => {
    if (!EDITAR || editando || enCampo(e.target) || !sel.size) return;
    const lista = copiarSeleccion();
    const texto = textoDeCopia(lista);
    e.preventDefault();
    e.clipboardData.setData('text/plain', texto);
    local.poner(CLAVE_COPIA, { texto, elementos: lista });
    borrarSeleccion();
  });
  document.addEventListener('paste', (e) => {
    if (editando) {
      // Al escribir, solo texto plano (sin el formato de donde venga)
      if (!PLANO) { e.preventDefault(); document.execCommand('insertText', false, e.clipboardData.getData('text/plain')); }
      return;
    }
    if (e.target?.closest?.('#pz-calc')) { e.preventDefault(); calcPegar(e.clipboardData?.getData('text/plain') || ''); return; }
    if (!EDITAR || enCampo(e.target)) return;
    const dt = e.clipboardData;
    if (!dt) return;
    ocultarPista(true);
    const imagenes = [...dt.files].filter((f) => /^image\//.test(f.type));
    e.preventDefault();
    const p = puntoPegado();
    if (imagenes.length) { subirImagenes(imagenes, p); return; }
    const texto = (dt.getData('text/plain') || '').replace(/\r\n?/g, '\n').trim();
    const propio = local.leer(CLAVE_COPIA);
    if (propio && texto && texto === String(propio.texto || '').replace(/\r\n?/g, '\n').trim() && Array.isArray(propio.elementos)) { pegarElementos(propio.elementos, p); return; }
    if (!texto) { const html = dt.getData('text/html'); if (!html) toast('No hay nada que pegar: copia una imagen, un texto o un enlace.'); return; }
    pegarTexto(texto, p);
  });
  function pegarTexto(texto, p) {
    texto = String(texto || '').trim();
    if (!texto) return;
    const f = factor();
    if (/^https?:\/\/\S+$/i.test(texto)) {
      crear([nuevoEl('enlace', { x: r1(p.x - 180 * f), y: r1(p.y - 42 * f), w: Math.round(360 * f), h: Math.round(84 * f), url: texto.slice(0, 2000) })]);
      return;
    }
    const largo = texto.length > 240 || texto.split('\n').length > 6;
    const el = largo
      ? nuevoEl('texto', { x: r1(p.x - 260 * f), y: r1(p.y - 20 * f), w: Math.round(520 * f), fs: Math.round(18 * f), texto: texto.slice(0, 4000) })
      : nuevoEl('nota', { x: r1(p.x - 100 * f), y: r1(p.y - 100 * f), w: Math.round(200 * f), h: Math.round(200 * f), texto });
    crear([el]);
  }
  const hostDe = (url) => { try { return new URL(url).hostname.replace(/^www\./, ''); } catch (e) { return ''; } };

  // Soltar archivos de imagen desde el escritorio (o un enlace o texto desde otra pestaña)
  window.addEventListener('dragover', (e) => e.preventDefault());
  window.addEventListener('drop', (e) => e.preventDefault());
  lienzo.addEventListener('dragover', (e) => {
    if (!EDITAR) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
    raiz.classList.add('is-soltando');
  });
  lienzo.addEventListener('dragleave', (e) => { if (!lienzo.contains(e.relatedTarget)) raiz.classList.remove('is-soltando'); });
  lienzo.addEventListener('drop', (e) => {
    raiz.classList.remove('is-soltando');
    if (!EDITAR) return;
    e.preventDefault();
    ocultarPista(true);
    const p = aMundo(e.clientX, e.clientY);
    const archivos = [...(e.dataTransfer.files || [])];
    const imagenes = archivos.filter((f) => /^image\//.test(f.type));
    if (imagenes.length) { subirImagenes(imagenes, p); return; }
    if (archivos.length) { toast('Solo se pueden soltar imágenes (PNG, JPG, WebP o GIF).', true); return; }
    const uri = (e.dataTransfer.getData('text/uri-list') || '').split('\n').find((l) => l && !l.startsWith('#'));
    pegarTexto(uri || e.dataTransfer.getData('text/plain'), p);
  });
  archivo.addEventListener('change', () => {
    const lista = [...archivo.files];
    archivo.value = '';
    if (lista.length) subirImagenes(lista, aMundo(window.innerWidth / 2, window.innerHeight / 2));
  });

  /* ----- Imágenes: se ven al instante y se suben detrás ----- */
  function subirImagenes(lista, p) {
    lista.slice(0, 12).forEach((f, i) => subirImagen(f, { x: p.x + (i * 28) / vista.z, y: p.y + (i * 28) / vista.z }));
  }
  const medidas = (url) => new Promise((ok, mal) => { const i = new Image(); i.onload = () => ok({ w: i.naturalWidth || 1, h: i.naturalHeight || 1 }); i.onerror = mal; i.src = url; });
  async function achicar(file) {
    const bmp = await createImageBitmap(file);
    const s = Math.min(1, 2560 / Math.max(bmp.width, bmp.height));
    const c = document.createElement('canvas');
    c.width = Math.round(bmp.width * s); c.height = Math.round(bmp.height * s);
    c.getContext('2d').drawImage(bmp, 0, 0, c.width, c.height);
    const tipo = file.type === 'image/jpeg' ? 'image/jpeg' : 'image/webp';
    let b = await new Promise((r) => c.toBlob(r, tipo, 0.88));
    if (!b || b.type !== tipo) b = await new Promise((r) => c.toBlob(r, 'image/jpeg', 0.86));
    return b || file;
  }
  async function subirImagen(file, p) {
    if (!EDITAR) return;
    if (!/^image\/(png|jpeg|webp|gif)$/.test(file.type)) { toast('Solo imágenes PNG, JPG, WebP o GIF.', true); return; }
    let blob = file;
    if (file.size > 7.5 * 1024 * 1024 && file.type !== 'image/gif') { try { blob = await achicar(file); } catch (e) { /* va tal cual */ } }
    if (blob.size > 8 * 1024 * 1024) { toast('La imagen pasa de 8 MB: prueba con una más liviana.', true); return; }
    const urlLocal = URL.createObjectURL(blob);
    let dim;
    try { dim = await medidas(urlLocal); } catch (e) { URL.revokeObjectURL(urlLocal); toast('No se pudo leer esa imagen.', true); return; }
    const f = factor(), max = 520 * f;
    const s = Math.min(f, max / Math.max(dim.w, dim.h));
    const w = Math.max(24, Math.round(dim.w * s)), h = Math.max(24, Math.round(dim.h * s));
    const el = nuevoEl('imagen', { x: r1(p.x - w / 2), y: r1(p.y - h / 2), w, h, _blob: urlLocal, _subiendo: true });
    els.set(el.id, el);
    pintar(el);
    sel.clear(); sel.add(el.id);
    programarRender({ sel: true, ctx: true });
    pintarVacio();
    subirArchivo(el, blob, file.name || 'imagen.png', urlLocal);
  }
  // Las imágenes que no subieron por falta de red esperan aquí (solo en esta pestaña) y salen solas al volver
  const enEspera = new Map();
  function reintentarSubidas() {
    for (const [id, s] of enEspera) {
      if (s.subiendo) continue;
      if (!vivo(id)) { enEspera.delete(id); URL.revokeObjectURL(s.urlLocal); continue; }
      subirArchivo(els.get(id), s.blob, s.nombre, s.urlLocal);
    }
  }
  async function subirArchivo(el, blob, nombre, urlLocal) {
    const espera = enEspera.get(el.id);
    if (espera) espera.subiendo = true;
    const fd = new FormData();
    fd.append('imagen', blob, nombre);
    try {
      const j = await pedir('imagen', { metodo: 'POST', cuerpo: fd });
      enEspera.delete(el.id);
      const actual = els.get(el.id);
      if (!actual || actual.borrado) { URL.revokeObjectURL(urlLocal); return; }   // la borraron mientras subía
      actual.src = String(j.url || '');
      delete actual._subiendo;
      // Si la movieron mientras subía, su historial tenía la imagen sin dirección
      for (const lista of [pasado, futuro]) for (const pasos of lista) for (const q of pasos) if (q[0] === el.id) { if (q[1]) q[1].src = actual.src; if (q[2]) q[2].src = actual.src; }
      registrar([[el.id, null, copia(actual)]]);
      marcar(actual);
      marcarSucio(el.id);
      pintar(actual);
      // Cambia a la del servidor cuando ya está en caché (sin parpadeo)
      medidas(actual.src).then(() => { if (vivo(el.id)) { delete actual._blob; pintar(actual); } URL.revokeObjectURL(urlLocal); }, () => {});
    } catch (err) {
      if (err.red && vivo(el.id)) {
        // Sin red: la imagen se queda en la pizarra y se reintenta cuando el panel vuelva a contestar
        if (!espera) toast('Sin conexión: la imagen se queda aquí y se sube sola cuando vuelva la red.');
        enEspera.set(el.id, { blob, nombre, urlLocal, subiendo: false });
        return;
      }
      enEspera.delete(el.id);
      els.delete(el.id); quitarNodo(el.id); sel.delete(el.id);
      URL.revokeObjectURL(urlLocal);
      programarRender({ sel: true, ctx: true });
      pintarVacio();
      toast(err.message || 'No se pudo subir la imagen.', true);
    }
  }

  /* =========================================================
     Guardado y en vivo
     ========================================================= */
  const sync = { version: 0, sucios: new Set(), corriendo: false, otra: false, fallos: 0, timer: 0, parado: false, cargada: false, desde: 0, motivo: '' };
  const CLAVE_PEND = 'pz-pend-' + CFG.reunion;

  async function pedir(accion, { metodo = 'GET', cuerpo = null, params = '' } = {}) {
    const json = cuerpo && !(cuerpo instanceof FormData);
    let r;
    try {
      r = await fetch(`${CFG.api}&accion=${accion}${params}`, {
        method: metodo,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: Object.assign({ 'X-Requested-With': 'fetch' }, json ? { 'Content-Type': 'application/json' } : {}),
        body: cuerpo ? (json ? JSON.stringify(cuerpo) : cuerpo) : undefined,
      });
    } catch (e) {
      // Sin red o el panel no contesta: el navegador solo dice «Failed to fetch»
      throw Object.assign(new Error('No hay conexión con el panel. Lo que hiciste queda guardado aquí y se manda solo al volver.'), { red: true });
    }
    let j = null;
    try { j = await r.json(); } catch (e) { /* no es JSON */ }
    if (r.status === 401) throw Object.assign(new Error(j?.error || 'Tu sesión se cerró.'), { sesion: true });
    if (r.status === 403) throw Object.assign(new Error(j?.error || 'Sin permiso.'), { permiso: true });
    if (!r.ok || !j || !j.ok) throw Object.assign(new Error(j?.error || 'El servidor no respondió bien (' + r.status + ').'), { status: r.status });
    return j;
  }

  function marcarSucio(id, escribiendo = false) {
    if (!EDITAR) return;
    sync.sucios.add(id);
    if (!sync.desde) sync.desde = Date.now();
    pintarEstado();
    guardarPendientes(escribiendo);
    if (sync.corriendo) { sync.otra = true; return; }
    clearTimeout(sync.timer);
    // Tandas cortas: espera ~400 ms a que se junten cambios, pero nunca más de ~1,2 s
    sync.timer = setTimeout(ciclo, Date.now() - sync.desde > 1200 ? 0 : 400);
  }

  /** Una vuelta: manda lo pendiente y pregunta qué cambió. Nunca dos a la vez. */
  async function ciclo() {
    clearTimeout(sync.timer);
    if (sync.parado) return;
    if (sync.corriendo) { sync.otra = true; return; }
    sync.corriendo = true;
    try {
      if (EDITAR && sync.sucios.size) await enviar();
      await consultar();
      sync.fallos = 0;
      if (enEspera.size) reintentarSubidas();
    } catch (err) {
      if (err.sesion) { sync.parado = true; sync.motivo = 'Tu sesión se cerró · recarga'; }
      else if (err.permiso) { sync.parado = true; sync.motivo = 'Sin permiso · recarga'; toast(err.message, true); }
      else sync.fallos++;
    } finally {
      sync.corriendo = false;
      pintarEstado();
      programarCiclo();
    }
  }
  function programarCiclo() {
    clearTimeout(sync.timer);
    if (sync.parado) return;
    let espera;
    if (sync.fallos) espera = Math.min(15000, 1500 * 2 ** (sync.fallos - 1));
    else if (sync.otra || sync.sucios.size) espera = 350;
    else if (document.hidden) return;   // en segundo plano no se sondea
    else espera = 2500;
    sync.otra = false;
    sync.timer = setTimeout(ciclo, espera);
  }

  async function enviar() {
    const ids = [], cambios = [];
    let tam = 0;
    for (const id of sync.sucios) {
      const el = els.get(id);
      if (el?._subiendo) continue;   // la imagen sale cuando termine de subir
      const c = !el || el.borrado ? { id, borrado: true } : paraEnviar(el);
      const largo = JSON.stringify(c).length;
      if (ids.length && (tam + largo > 1500000 || ids.length >= 400)) break;
      ids.push(id); cambios.push(c); tam += largo;
    }
    if (!ids.length) return;
    ids.forEach((id) => sync.sucios.delete(id));
    pintarEstado(true);
    try {
      await pedir('guardar', { metodo: 'POST', cuerpo: { cambios } });
    } catch (err) {
      ids.forEach((id) => sync.sucios.add(id));   // vuelve a la cola: se reintenta
      throw err;
    }
    if (!sync.sucios.size) sync.desde = 0;
    else sync.otra = true;
    guardarPendientes(false);
  }

  async function consultar() {
    const primera = !sync.cargada;
    const j = await pedir('estado', { params: '&desde=' + sync.version });
    // Primero quién está: así lo que cambió sale con el nombre de quien lo cambió
    pintarPresentes(Array.isArray(j.presentes) ? j.presentes : []);
    aplicarRemotos(Array.isArray(j.elementos) ? j.elementos : [], primera);
    sync.version = Math.max(sync.version, Number(j.version) || 0);
    if (EDITAR && j.editar === false) { sync.parado = true; sync.motivo = 'Ya no puedes editar · recarga'; toast('Ya no puedes editar esta pizarra. Recarga la página.', true); }
    if (primera) {
      sync.cargada = true;
      recuperarPendientes();
      if (!vistaGuardada) ajustar(false);
      pintarVacio();
      if (!vivos().length) mostrarPista();   // la primera vez, en una pizarra vacía: cómo se pega
    }
  }

  /** Lo que cambiaron los demás. Lo mío pendiente (o lo que tengo en la mano) gana. */
  function aplicarRemotos(lista, primera) {
    let hubo = false;
    for (const r of lista) {
      const id = String(r?.id || '');
      if (!id) continue;
      const loc = els.get(id);
      // Lo tengo pendiente o en la mano: se guarda aparte y se mira al soltarlo
      if (sync.sucios.has(id) || ocupado(id)) { saltados.set(id, { r, e: loc?.e }); continue; }
      saltados.delete(id);
      if (r.borrado) {
        if (loc) { els.delete(id); quitarNodo(id); sel.delete(id); hubo = true; }
        continue;
      }
      if (loc && !loc.borrado && loc.e && loc.e === r.e) { loc.v = r.v; continue; }   // el eco de lo mío
      const el = normalizar(Object.assign({}, r));
      els.set(id, el);
      if (num(el.z) > maxZ) maxZ = num(el.z);
      pintar(el);
      hubo = true;
      if (!primera && r.por && Number(r.por) !== YO) avisarQuien(el, Number(r.por));
    }
    if (hubo) {
      // Las tarjetas cuyo tablero se fue (o llegó después) se vuelven a pintar con su forma correcta
      for (const n of vivos()) if (n.tipo === 'nota' && n.marco && nodos.get(n.id)?.classList.contains('en-tablero') !== enTablero(n)) pintar(n);
      programarRender({ acomodar: true, flechas: true, sel: true, ctx: true });
      pintarVacio();
    }
  }
  /**
   * Lo que llegó mientras yo tenía ese elemento en la mano. Si al soltarlo no
   * lo cambié (cancelé, o solo lo toqué), entra lo de los demás; si lo cambié,
   * lo mío sale después y gana, y lo guardado se descarta.
   */
  const saltados = new Map();
  function retomarSaltados() {
    if (!saltados.size) return;
    const listos = [];
    for (const [id, s] of saltados) {
      if (sync.sucios.has(id) || ocupado(id)) continue;
      saltados.delete(id);
      if ((els.get(id)?.e) === s.e) listos.push(s.r);
    }
    if (listos.length) aplicarRemotos(listos, false);
  }
  function ocupado(id) {
    if (editando?.id === id) return true;
    const g = gesto;
    if (!g) return false;
    return !!(g.ids?.has(id) || g.el?.id === id);
  }

  // Lo pendiente también queda en el navegador: si se cierra sin red, se recupera al volver
  let relojPend = 0;
  function guardarPendientes(conCalma) {
    clearTimeout(relojPend);
    const escribir = () => {
      const o = {};
      for (const id of sync.sucios) {
        const el = els.get(id);
        if (el?._subiendo) continue;
        o[id] = { el: !el || el.borrado ? { id, borrado: true } : paraEnviar(el), base: el?.v ?? null };
      }
      local.poner(CLAVE_PEND, Object.keys(o).length ? o : null);
    };
    if (conCalma) relojPend = setTimeout(escribir, 600); else escribir();
  }
  function recuperarPendientes() {
    if (!EDITAR) return;
    const o = local.leer(CLAVE_PEND);
    if (!o || typeof o !== 'object') return;
    let n = 0;
    for (const [id, p] of Object.entries(o)) {
      const srv = els.get(id);
      // Solo si nadie lo cambió después: si en el servidor ya es otro, gana el del servidor
      if (srv && p.base != null && Number(srv.v) !== Number(p.base) && srv.e !== p.el?.e) continue;
      if (!srv && p.base != null) continue;   // lo borró alguien
      if (srv && srv.e && srv.e === p.el?.e) continue;   // ya estaba guardado
      if (p.el?.borrado) { if (srv) { borrarLocal(id); sync.sucios.add(id); n++; } continue; }
      const el = normalizar(Object.assign({}, p.el, { id }));
      els.set(id, el);
      pintar(el);
      if (num(el.z) > maxZ) maxZ = num(el.z);
      sync.sucios.add(id);
      n++;
    }
    if (n) {
      toast(`Se recuperaron ${n} cambio${n === 1 ? '' : 's'} que no se habían guardado.`);
      programarRender({ acomodar: true, flechas: true, sel: true });
      programarCiclo();
    } else local.poner(CLAVE_PEND, null);
  }

  function pintarEstado(enviando) {
    if (!estadoEl) return;
    let e, txt;
    if (sync.parado) { e = 'sesion'; txt = sync.motivo || 'Recarga la página'; }
    else if (!EDITAR) return;
    else if (sync.fallos) { e = 'sin-red'; txt = 'Sin conexión · reintentando'; }
    else if (enviando === true || sync.sucios.size) { e = 'guardando'; txt = 'Guardando…'; }
    else if (!sync.cargada) { e = 'guardando'; txt = 'Cargando…'; }
    else { e = 'guardado'; txt = 'Guardado'; }
    if (estadoEl.dataset.estado === e && estadoEl._txt === txt) return;
    estadoEl.dataset.estado = e;
    estadoEl._txt = txt;
    estadoEl.classList.remove('pz-estado--lector');
    const icono = { guardado: ico('pz-nube'), 'sin-red': ico('pz-sin-red'), sesion: ico('ds-alerta') }[e] || '';
    estadoEl.innerHTML = `<span class="pz-estado__ico" aria-hidden="true">${icono}</span><span class="pz-estado__txt">${esc(txt)}</span>`;
    estadoEl.title = txt;
  }
  estadoEl?.addEventListener('click', () => { if (sync.parado) location.reload(); else if (sync.fallos) { sync.fallos = 0; ciclo(); } });

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) { if (!sync.parado) ciclo(); }
    else if (sync.sucios.size) ciclo();
  });
  window.addEventListener('online', () => { if (!sync.parado) { sync.fallos = 0; ciclo(); } });
  // Al cerrar la pestaña con algo pendiente: un último envío que sobrevive al cierre
  window.addEventListener('pagehide', () => {
    if (!EDITAR || !sync.sucios.size || sync.parado) return;
    const cambios = [...sync.sucios].map((id) => { const el = els.get(id); return !el || el.borrado ? { id, borrado: true } : (el._subiendo ? null : paraEnviar(el)); }).filter(Boolean);
    const cuerpo = JSON.stringify({ cambios });
    if (cuerpo.length > 60000) return;   // lo que no entra queda en el navegador para la próxima
    try { fetch(`${CFG.api}&accion=guardar`, { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'Content-Type': 'application/json' }, body: cuerpo }).catch(() => {}); } catch (e) { /* nada */ }
  });
  window.addEventListener('beforeunload', (e) => { if (EDITAR && sync.sucios.size && sync.fallos) { e.preventDefault(); e.returnValue = ''; } });

  /* ----- Quién está mirando ahora ----- */
  const nombres = new Map();
  function avatarHtml(p) {
    const color = /^#[0-9a-f]{3,8}$/i.test(p.color || '') ? p.color : '#1A4B99';
    const dentroAv = p.foto ? `<img src="${esc(p.foto)}" alt="">` : `<span>${esc(p.iniciales || '?')}</span>`;
    return `<span class="avatar" style="--sz:28px;--av-c1:${color}">${dentroAv}</span>`;
  }
  function pintarPresentes(lista) {
    if (CFG.mia) return;   // «Mis notas»: solo la ve quien la tiene
    for (const p of lista) nombres.set(Number(p.id), { nombre: String(p.nombre || ''), color: p.color });
    const firma = lista.map((p) => p.id + (p.foto || '')).join(',');
    if (!otrosEl || otrosEl._firma === firma) return;
    otrosEl._firma = firma;
    const max = window.innerWidth < 760 ? 2 : 5;
    otrosEl.innerHTML = lista.slice(0, max).map((p) => `<span data-tip="${esc(p.nombre)} está mirando">${avatarHtml(p)}</span>`).join('')
      + (lista.length > max ? `<span class="pz-presentes__mas" data-tip="${esc(lista.slice(max).map((p) => p.nombre).join(', '))}">+${lista.length - max}</span>` : '');
  }
  const quienes = new Map();
  function avisarQuien(el, por) {
    const p = nombres.get(por);
    if (!p || !p.nombre) return;
    const g = geo(el);
    quienes.get(el.id)?.remove();
    const et = document.createElement('span');
    et.className = 'pz-quien';
    if (/^#[0-9a-f]{6}$/i.test(p.color || '')) {
      et.style.setProperty('--av-c1', p.color);
      // Sobre un color claro (amarillo, menta…) el nombre va en tinta oscura: blanco no se lee
      const [r, g2, b] = [1, 3, 5].map((i) => parseInt(p.color.slice(i, i + 2), 16) / 255);
      if (0.2126 * r + 0.7152 * g2 + 0.0722 * b > 0.6) et.style.color = '#1F2A37';
    }
    et.textContent = p.nombre.split(' ')[0];
    et.style.transform = `translate(${g.x}px,${g.y}px) translateY(calc(-100% - 6px * var(--pz-inv, 1)))`;
    capaSel.appendChild(et);
    quienes.set(el.id, et);
    setTimeout(() => { et.remove(); if (quienes.get(el.id) === et) quienes.delete(el.id); }, 2500);
  }

  /* =========================================================
     Exportar como imagen PNG (se dibuja en un canvas, en tema claro)
     ========================================================= */
  function rr(x, X, Y, W, H, R) {
    x.beginPath();
    if (x.roundRect) { x.roundRect(X, Y, W, H, R); return; }
    R = Math.min(R, W / 2, H / 2);
    x.moveTo(X + R, Y); x.arcTo(X + W, Y, X + W, Y + H, R); x.arcTo(X + W, Y + H, X, Y + H, R); x.arcTo(X, Y + H, X, Y, R); x.arcTo(X, Y, X + W, Y, R); x.closePath();
  }
  function envolver(x, texto, ancho) {
    const out = [];
    for (const par of String(texto ?? '').split('\n')) {
      let linea = '';
      for (const pal of par.split(' ')) {
        const prueba = linea ? linea + ' ' + pal : pal;
        if (x.measureText(prueba).width <= ancho) { linea = prueba; continue; }
        if (linea) out.push(linea);
        let p = pal;
        while (p.length > 1 && x.measureText(p).width > ancho) {
          let n = p.length;
          while (n > 1 && x.measureText(p.slice(0, n)).width > ancho) n--;
          out.push(p.slice(0, n));
          p = p.slice(n);
        }
        linea = p;
      }
      out.push(linea);
    }
    return out;
  }
  function textoEn(x, texto, c, o) {
    const fs = o.fs, lh = o.lh || 1.28;
    x.font = `${o.peso || 500} ${fs}px ${o.fam || FUENTE}`;
    x.fillStyle = o.color || '#1F2A37';
    x.textAlign = o.alinear || 'center';
    x.textBaseline = 'middle';
    const ls = envolver(x, texto, c.w);
    const alto = ls.length * fs * lh;
    const y0 = o.arriba ? c.y : c.y + (c.h - alto) / 2;
    const xx = x.textAlign === 'center' ? c.x + c.w / 2 : c.x;
    ls.forEach((l, i) => x.fillText(l, xx, y0 + fs * lh * (i + 0.5)));
  }
  async function exportarPNG() {
    const lista = vivos();
    if (!lista.length) { toast('La pizarra está vacía: todavía no hay nada que exportar.'); return; }
    try { await document.fonts?.ready; } catch (e) { /* sin fuentes, igual */ }
    const caja = union(lista.map(geo)), pad = 48;
    const X = caja.x - pad, Y = caja.y - pad, W = caja.w + pad * 2, H = caja.h + pad * 2;
    let s = Math.min(2, 8000 / W, 8000 / H);
    if (W * H * s * s > 40e6) s = Math.sqrt(40e6 / (W * H));
    const c = document.createElement('canvas');
    c.width = Math.max(1, Math.round(W * s)); c.height = Math.max(1, Math.round(H * s));
    const x = c.getContext('2d');
    x.scale(s, s); x.translate(-X, -Y);
    x.fillStyle = '#F4F8FB'; x.fillRect(X, Y, W, H);
    const porZ = (a, b) => num(a.z) - num(b.z);
    lista.filter((e) => e.tipo === 'kanban').sort(porZ).forEach((k) => dibujarKanban(x, k));
    lista.filter((e) => !SVG_TIPOS.has(e.tipo) && e.tipo !== 'kanban').sort(porZ).forEach((e) => dibujar(x, e));
    [...svg.children].forEach((g) => { const e = vivo(g.dataset.id); if (e) dibujarLinea(x, e); });
    const blob = await new Promise((r) => c.toBlob(r, 'image/png'));
    if (!blob) { toast('No se pudo crear la imagen.', true); return; }
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'pizarra-' + (String(CFG.titulo || 'reunion').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'reunion') + '.png';
    document.body.appendChild(a); a.click(); a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
    toast('Listo: se descargó la imagen de la pizarra.');
  }
  function dibujar(x, e) {
    const g = geo(e), [papel, tinta] = PALETA[colorDe(e)] || PALETA.celeste, n = nodos.get(e.id);
    x.save();
    if (e.tipo === 'nota') {
      const tablero = enTablero(e);
      x.shadowColor = 'rgba(15,23,42,.16)'; x.shadowBlur = 14; x.shadowOffsetY = 5;
      rr(x, g.x, g.y, g.w, g.h, tablero ? 10 : 6); x.fillStyle = papel; x.fill();
      x.shadowColor = 'transparent';
      const pie = e.tarea?.id ? 26 : 0;
      if (tablero) textoEn(x, e.texto, { x: g.x + 13, y: g.y + 11, w: g.w - 26, h: g.h - 22 - pie }, { fs: 15, lh: 1.35, alinear: 'left', arriba: true });
      else textoEn(x, e.texto, { x: g.x + 18, y: g.y + 16, w: g.w - 36, h: g.h - 32 - pie }, { fs: n?._fs || 20 });
      if (pie) { x.font = `600 12px ${FUENTE}`; x.fillStyle = 'rgba(31,42,55,.62)'; x.textAlign = 'left'; x.textBaseline = 'middle'; x.fillText(`#${e.tarea.id}${e.tarea.quien ? '  ·  ' + e.tarea.quien : ''}`, g.x + 13, g.y + g.h - 16); }
    } else if (e.tipo === 'texto') {
      textoEn(x, e.texto, { x: g.x, y: g.y, w: g.w, h: g.h }, { fs: num(e.fs, 28), peso: 600, lh: 1.25, fam: '"Plus Jakarta Sans", ' + FUENTE, color: tinta, alinear: 'left', arriba: true });
    } else if (e.tipo === 'rect' || e.tipo === 'elipse') {
      if (e.tipo === 'rect') rr(x, g.x + 1, g.y + 1, g.w - 2, g.h - 2, 14);
      else { x.beginPath(); x.ellipse(g.x + g.w / 2, g.y + g.h / 2, g.w / 2 - 1, g.h / 2 - 1, 0, 0, Math.PI * 2); }
      x.fillStyle = papel; x.fill(); x.lineWidth = 2; x.strokeStyle = tinta; x.stroke();
      const dentroF = e.tipo === 'rect' ? { x: g.x + 18, y: g.y + 14, w: g.w - 36, h: g.h - 28 } : { x: g.x + g.w * 0.16, y: g.y + g.h * 0.14, w: g.w * 0.68, h: g.h * 0.72 };
      textoEn(x, e.texto, dentroF, { fs: n?._fs || 18, peso: 600, lh: 1.25 });
    } else if (e.tipo === 'imagen') {
      const img = n?.querySelector('img');
      rr(x, g.x, g.y, g.w, g.h, 10); x.clip();
      if (img?.complete && img.naturalWidth) x.drawImage(img, g.x, g.y, g.w, g.h);
      else { x.fillStyle = '#E2E8F0'; x.fillRect(g.x, g.y, g.w, g.h); }
    } else if (e.tipo === 'enlace') {
      rr(x, g.x, g.y, g.w, g.h, 14); x.fillStyle = '#FFFFFF'; x.fill(); x.lineWidth = 1; x.strokeStyle = '#DFE9F0'; x.stroke();
      rr(x, g.x + 16, g.y + (g.h - 44) / 2, 44, 44, 12); x.fillStyle = '#E0F2FE'; x.fill();
      x.textAlign = 'left'; x.textBaseline = 'middle';
      x.font = `700 16px "Plus Jakarta Sans", ${FUENTE}`; x.fillStyle = '#2D3E50';
      x.fillText(String(e.titulo || hostDe(e.url) || 'Enlace').slice(0, 60), g.x + 74, g.y + g.h / 2 - 9);
      x.font = `400 12.5px ${FUENTE}`; x.fillStyle = '#5A6B7E';
      x.fillText(String(e.url || '').replace(/^https?:\/\//i, '').slice(0, 64), g.x + 74, g.y + g.h / 2 + 11);
    }
    x.restore();
  }
  function dibujarKanban(x, k) {
    const g = geo(k);
    x.save();
    rr(x, g.x, g.y, g.w, g.h, 18); x.fillStyle = '#FBFDFE'; x.fill(); x.lineWidth = 1; x.strokeStyle = '#DFE9F0'; x.stroke();
    x.textAlign = 'left'; x.textBaseline = 'middle';
    x.font = `750 19px "Plus Jakarta Sans", ${FUENTE}`; x.fillStyle = '#2D3E50';
    x.fillText(String(k.titulo || 'Tablero'), g.x + 20, g.y + K.cab / 2);
    const cols = k.columnas || [], paso = (num(k.w) - K.margen) / Math.max(1, cols.length);
    const puntos = { progreso: '#2563EB', revision: '#7C3AED', hecho: '#16A34A' };
    cols.forEach((c, i) => {
      const cx = g.x + K.margen + i * paso, cy = g.y + K.cab;
      rr(x, cx, cy, paso - K.margen, g.h - K.cab - K.margen, 14); x.fillStyle = 'rgba(45,62,80,.045)'; x.fill();
      x.beginPath(); x.arc(cx + 18, cy + K.colCab / 2, 4.5, 0, Math.PI * 2); x.fillStyle = puntos[c.estado] || '#94A3B8'; x.fill();
      x.font = `700 14px ${FUENTE}`; x.fillStyle = '#2D3E50';
      x.fillText(String(c.titulo || ''), cx + 30, cy + K.colCab / 2);
    });
    x.restore();
  }
  function dibujarLinea(x, e) {
    const tinta = (PALETA[colorDe(e)] || PALETA.gris)[1];
    x.save();
    x.strokeStyle = tinta; x.fillStyle = tinta; x.lineCap = 'round'; x.lineJoin = 'round';
    if (e.tipo === 'flecha') {
      const [a, b] = extremos(e);
      const gro = limitar(num(e.grosor, 3), 1, 16), dx = b.x - a.x, dy = b.y - a.y, L = Math.hypot(dx, dy) || 1, ux = dx / L, uy = dy / L, s = 7 + gro * 2.2;
      const fin = e.punta !== 'nada', ini = e.punta === 'ambas';
      x.lineWidth = gro;
      x.beginPath();
      x.moveTo(ini ? a.x + ux * s * 0.7 : a.x, ini ? a.y + uy * s * 0.7 : a.y);
      x.lineTo(fin ? b.x - ux * s * 0.7 : b.x, fin ? b.y - uy * s * 0.7 : b.y);
      x.stroke();
      x.lineWidth = Math.max(1, gro * 0.5);
      if (fin) { const p = new Path2D(cabeza(b, ux, uy, s)); x.fill(p); x.stroke(p); }
      if (ini) { const p = new Path2D(cabeza(a, -ux, -uy, s)); x.fill(p); x.stroke(p); }
    } else if (e.tipo === 'trazo') {
      const pw = Math.max(1, num(e.pw, e.w)), ph = Math.max(1, num(e.ph, e.h));
      const sx = Math.max(1, num(e.w)) / pw, sy = Math.max(1, num(e.h)) / ph;
      const pts = (e.puntos || []).map(([px, py]) => [r1(num(e.x) + px * sx), r1(num(e.y) + py * sy)]);
      x.lineWidth = limitar(num(e.grosor, 4), 1, 40);
      x.stroke(new Path2D(camino(pts)));
    }
    x.restore();
  }

  /* =========================================================
     Recordatorio en una nota (o un texto)
     Se guarda en el elemento como aviso: 'YYYY-MM-DD HH:MM' (hora local, 24 h)
     y aviso_por: quien lo puso. Viaja con el guardado como cualquier campo; el
     servidor lo indexa y a esa hora le avisa a quien lo puso (campana,
     notificación del navegador y correo). Quitar el campo borra el aviso.
     ========================================================= */
  const DIAS_C = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
  const MESES_C = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  const dosD = (n) => String(n).padStart(2, '0');
  const textoFecha = (d) => `${d.getFullYear()}-${dosD(d.getMonth() + 1)}-${dosD(d.getDate())} ${dosD(d.getHours())}:${dosD(d.getMinutes())}`;
  function fechaAviso(s) {
    const m = /^(\d{4})-(\d\d)-(\d\d)[ T](\d\d):(\d\d)$/.exec(String(s || ''));
    return m ? new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]) : null;
  }
  /** «hoy 15:30», «mañana 9:00», «vie 9:00», «12 nov 9:00»; si ya pasó, «Avisado». */
  function etiquetaAviso(s) {
    const d = fechaAviso(s);
    if (!d) return '';
    const ahora = new Date();
    if (d <= ahora) return 'Avisado';
    const hora = d.getHours() + ':' + dosD(d.getMinutes());
    const dias = Math.round((new Date(d.getFullYear(), d.getMonth(), d.getDate()) - new Date(ahora.getFullYear(), ahora.getMonth(), ahora.getDate())) / 86400000);
    if (dias === 0) return 'hoy ' + hora;
    if (dias === 1) return 'mañana ' + hora;
    if (dias < 7) return DIAS_C[d.getDay()] + ' ' + hora;
    return d.getDate() + ' ' + MESES_C[d.getMonth()] + ' ' + hora;
  }
  function avisoHtml(el, etiqueta) {
    const pasado = etiqueta === 'Avisado';
    const d = fechaAviso(el.aviso);
    const larga = d ? `${DIAS_C[d.getDay()]} ${d.getDate()} ${MESES_C[d.getMonth()]} · ${d.getHours()}:${dosD(d.getMinutes())}` : '';
    const por = Number(el.aviso_por) || 0;
    const quien = por && por !== YO ? (nombres.get(por)?.nombre || '').split(' ')[0] : '';
    const tip = (pasado ? 'Ya se avisó' : 'Recordatorio') + (quien ? ` de ${quien}` : '') + (larga ? ': ' + larga : '');
    return `<span class="pz-aviso${pasado ? ' is-pasado' : ''}" data-tip="${esc(tip)}">${ico('ds-notificaciones')}<span>${esc(etiqueta)}</span></span>`;
  }
  // Cada medio minuto la insignia se pone al día («mañana» pasa a «hoy» y luego a «Avisado»)
  setInterval(() => {
    if (document.hidden) return;
    for (const el of vivos()) {
      if (!el.aviso) continue;
      const n = nodos.get(el.id);
      if (n && el.tipo === 'nota') pintarPieTarea(el, n);
      else if (n && el.tipo === 'texto') pintarAvisoTexto(el, n);
    }
  }, 30000);

  /* ----- Cuadros de vidrio (enlace y recordatorio): junto a lo que los abrió ----- */
  function abrirPop(pop, ancla, foco) {
    pop.hidden = false;
    const w = pop.offsetWidth, h = pop.offsetHeight, W = window.innerWidth, H = window.innerHeight;
    let x, y;
    if (ancla && ancla.width != null) { x = ancla.left + ancla.width / 2 - w / 2; y = ancla.bottom + 10; if (y + h > H - 90) y = ancla.top - h - 10; }
    else if (ancla) { x = ancla.x - w / 2; y = ancla.y + 22; if (y + h > H - 90) y = ancla.y - h - 22; }
    else { x = W / 2 - w / 2; y = H - h - 100; }   // encima de la barra de herramientas
    pop.style.setProperty('--cx', Math.round(limitar(x, 8, W - w - 8)) + 'px');
    pop.style.setProperty('--cy', Math.round(limitar(y, 76, H - h - 8)) + 'px');
    if (foco) requestAnimationFrame(() => foco.focus({ preventScroll: true }));
  }

  /* ----- Enlace: la dirección en su cuadro (sin prompt); un clic en el lienzo elige dónde va ----- */
  const popEnlace = $('#pz-pop-enlace'), inEnlace = $('#pz-enlace-url'), errEnlace = $('#pz-enlace-error');
  let destinoEnlace = null, marcaEnlace = null;
  function abrirEnlace(p) {
    if (!EDITAR || !popEnlace) return;
    destinoEnlace = p;
    errEnlace.hidden = true;
    inEnlace.removeAttribute('aria-invalid');
    abrirPop(popEnlace, p ? aPantalla(p.x, p.y) : null, inEnlace);
    if (p) {
      if (!marcaEnlace) { marcaEnlace = document.createElement('div'); marcaEnlace.className = 'pz-destino'; capaSel.appendChild(marcaEnlace); }
      marcaEnlace.hidden = false;
      marcaEnlace.style.transform = `translate(${p.x}px,${p.y}px)`;
    } else if (marcaEnlace) marcaEnlace.hidden = true;
  }
  function cerrarEnlace(volver = true) {
    if (!popEnlace) return;
    popEnlace.hidden = true;
    destinoEnlace = null;
    inEnlace.value = '';
    if (marcaEnlace) marcaEnlace.hidden = true;
    if (volver && herramienta === 'enlace') elegirHerr('seleccionar');
  }
  /** «edcon.ec/guia» → «https://edcon.ec/guia». Solo http(s) y con un dominio de verdad. */
  function urlDe(v) {
    v = String(v || '').trim();
    if (!v) return '';
    if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(v)) v = 'https://' + v.replace(/^\/+/, '');
    try {
      const u = new URL(v);
      if (!/^https?:$/.test(u.protocol) || !(u.hostname.includes('.') || u.hostname === 'localhost')) return '';
      return u.href;
    } catch (e) { return ''; }
  }
  function crearEnlaceDelCuadro() {
    const url = urlDe(inEnlace.value);
    if (!url) { errEnlace.hidden = false; inEnlace.setAttribute('aria-invalid', 'true'); inEnlace.focus(); return; }
    const p = destinoEnlace || aMundo(window.innerWidth / 2, window.innerHeight / 2);
    cerrarEnlace(false);
    elegirHerr('seleccionar');
    pegarTexto(url, p);
    ocultarPista(true);
  }
  if (popEnlace) {
    popEnlace.addEventListener('click', (e) => {
      const b = e.target.closest('[data-enlace]');
      if (b) { if (b.dataset.enlace === 'crear') crearEnlaceDelCuadro(); else cerrarEnlace(); }
    });
    popEnlace.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); crearEnlaceDelCuadro(); }
      else if (e.key === 'Escape') { e.preventDefault(); cerrarEnlace(); }
    });
    inEnlace.addEventListener('input', () => { errEnlace.hidden = true; inEnlace.removeAttribute('aria-invalid'); });
  }

  /* ----- El cuadro del recordatorio ----- */
  const popAviso = $('#pz-pop-aviso'), inAviso = $('#pz-aviso-cuando'), errAviso = $('#pz-aviso-error'), actualAviso = $('#pz-aviso-actual');
  let avisoDe = null;
  function abrirAviso(id) {
    const el = vivo(id);
    if (!el || !EDITAR || !popAviso) return;
    avisoDe = id;
    const d = fechaAviso(el.aviso);
    const base = d && d > new Date() ? d : new Date(Date.now() + 3600e3);
    inAviso.value = textoFecha(base).replace(' ', 'T');
    inAviso.min = textoFecha(new Date()).replace(' ', 'T');
    const et = etiquetaAviso(el.aviso);
    actualAviso.textContent = !el.aviso ? 'A esa hora te llega un aviso en la campana, en el navegador y por correo.'
      : et === 'Avisado' ? 'Este recordatorio ya se avisó. Pon otra hora si quieres que vuelva a sonar.' : `Te avisamos ${et}.`;
    errAviso.hidden = true;
    popAviso.querySelector('[data-aviso="quitar"]').hidden = !el.aviso;
    abrirPop(popAviso, ctxBar.hidden ? null : ctxBar.getBoundingClientRect(), inAviso);
  }
  function cerrarAviso() { if (popAviso) popAviso.hidden = true; avisoDe = null; }
  function ponerAviso(valor) {
    const el = vivo(avisoDe);
    if (!el) { cerrarAviso(); return; }
    if (valor) {
      const d = fechaAviso(valor);
      if (!d || d <= new Date()) { errAviso.textContent = 'Esa hora ya pasó: elige una más adelante.'; errAviso.hidden = false; return; }
    }
    cerrarAviso();
    cambiarTodos([el], (x) => {
      if (valor) { x.aviso = valor; x.aviso_por = YO; }
      else { delete x.aviso; delete x.aviso_por; }
    });
    toast(valor ? `Listo: te avisamos ${etiquetaAviso(valor)}.` : 'Recordatorio quitado.');
    ctxBar._firma = '';
    pintarCtx();
  }
  function guardarAviso() {
    const v = String(inAviso.value || '').replace('T', ' ').slice(0, 16);
    if (!fechaAviso(v)) { errAviso.textContent = 'Elige la fecha y la hora.'; errAviso.hidden = false; return; }
    ponerAviso(v);
  }
  if (popAviso) {
    popAviso.addEventListener('click', (e) => {
      const b = e.target.closest('[data-aviso]');
      if (!b) return;
      const a = b.dataset.aviso;
      if (a === 'hora') ponerAviso(textoFecha(new Date(Date.now() + 3600e3)));
      else if (a === 'manana') { const d = new Date(); d.setDate(d.getDate() + 1); d.setHours(9, 0, 0, 0); ponerAviso(textoFecha(d)); }
      else if (a === 'quitar') ponerAviso(null);
      else if (a === 'guardar') guardarAviso();
      else cerrarAviso();
    });
    popAviso.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { e.preventDefault(); guardarAviso(); }
      else if (e.key === 'Escape') { e.preventDefault(); cerrarAviso(); }
    });
    inAviso.addEventListener('input', () => { errAviso.hidden = true; });
    // Un clic fuera lo cierra (salvo en la propia campana, que lo abre)
    document.addEventListener('pointerdown', (e) => {
      if (!popAviso.hidden && !popAviso.contains(e.target) && !e.target.closest?.('[data-ctx="aviso"]')) cerrarAviso();
    }, { capture: true, passive: true });
  }

  /* ----- La primera vez, en una pizarra vacía: cómo se pega ----- */
  const pista = $('#pz-pista'), CLAVE_PISTA = 'pz-pista-pegar';
  function mostrarPista() { if (pista && EDITAR && !local.leer(CLAVE_PISTA)) pista.hidden = false; }
  function ocultarPista(recordar) {
    if (pista) pista.hidden = true;
    if (recordar) local.poner(CLAVE_PISTA, 1);
  }
  pista?.addEventListener('click', (e) => { if (e.target.closest('[data-pista-ok]')) ocultarPista(true); });

  /* =========================================================
     Mini calculadora: personal (no se sincroniza), se arrastra por su
     cabecera y se usa también con el teclado mientras está enfocada. La cinta
     con las últimas cuentas queda en el navegador de cada quien.
     ========================================================= */
  const calc = $('#pz-calc');
  const calcExpr = $('#pz-calc-expr'), calcRes = $('#pz-calc-res'), calcCinta = $('#pz-calc-cinta');
  const botonCalc = barra.querySelector('[data-calc]');
  const CLAVE_CINTA = 'pz-calc-cinta', CLAVE_CALC_POS = 'pz-calc-pos';
  let calcTxt = '', calcRecien = false, cintaElegida = 0;
  let cinta = (local.leer(CLAVE_CINTA, []) || []).filter((c) => c && c.expr != null && c.res != null).slice(0, 8);

  /**
   * Evalúa la cuenta sin eval(): números, + − × ÷, paréntesis (los que falten
   * al final se cierran solos) y %. El % va como en las calculadoras de
   * bolsillo: «200 + 10%» es 220 (el 10 % de 200); suelto, «50%» es 0,5.
   */
  function evaluar(s) {
    const toks = [];
    for (let i = 0; i < s.length;) {
      const c = s[i];
      if (c === ' ') { i++; continue; }
      if (/[0-9.]/.test(c)) {
        let j = i;
        while (j < s.length && /[0-9.]/.test(s[j])) j++;
        const n = s.slice(i, j);
        if (n === '.' || (n.match(/\./g) || []).length > 1) throw new Error('numero');
        toks.push({ n: parseFloat(n) });
        i = j;
        continue;
      }
      if ('+-*/%()'.includes(c)) { toks.push({ o: c }); i++; continue; }
      throw new Error('simbolo');
    }
    if (!toks.length) throw new Error('vacio');
    let k = 0;
    const es = (o) => toks[k] && toks[k].o === o;
    const suma = () => {
      let v = producto().v;
      while (es('+') || es('-')) {
        const op = toks[k++].o, d = producto();
        const x = d.pct ? (v * d.base) / 100 : d.v;
        v = op === '+' ? v + x : v - x;
      }
      return v;
    };
    const producto = () => {
      const f = porciento();
      let v = f.v, solo = true;
      while (es('*') || es('/')) {
        const op = toks[k++].o, g = porciento();
        solo = false;
        if (op === '/') { if (g.v === 0) throw new Error('cero'); v /= g.v; } else v *= g.v;
      }
      return solo && f.pct ? f : { v };
    };
    const porciento = () => {
      let v = unario(), base = v, pct = false;
      while (es('%')) { k++; base = v; v /= 100; pct = true; }
      return { v, base, pct };
    };
    const unario = () => {
      if (es('-')) { k++; return -unario(); }
      if (es('+')) { k++; return unario(); }
      const t = toks[k++];
      if (!t) throw new Error('fin');
      if (t.n != null) return t.n;
      if (t.o === '(') { const v = suma(); if (es(')')) k++; return v; }
      throw new Error('falta');
    };
    const v = suma();
    if (k < toks.length) throw new Error('sobra');
    if (!Number.isFinite(v)) throw new Error('infinito');
    return v;
  }
  const fmtCalc = (v) => {
    if (Object.is(v, -0)) v = 0;
    const r = parseFloat(v.toPrecision(12));
    return Math.abs(r) >= 1e15 || (r !== 0 && Math.abs(r) < 1e-9) ? r.toExponential(6) : String(r);
  };
  /** «12*3-2» → «12 × 3 − 2» (el menos de un número negativo queda pegado). */
  function bonito(s) {
    let out = '';
    for (const c of s) {
      const prev = out.trimEnd().slice(-1);
      if (c === '*') out += ' × ';
      else if (c === '/') out += ' ÷ ';
      else if (c === '+') out += ' + ';
      else if (c === '-') out += /[0-9.)%]/.test(prev) ? ' − ' : '−';
      else out += c;
    }
    return out.replace(/\s+/g, ' ').trim();
  }
  function calcTecla(k) {
    if (k === 'C') { calcTxt = ''; calcRecien = false; }
    else if (k === 'atras') { calcTxt = calcRecien ? '' : calcTxt.slice(0, -1); calcRecien = false; }
    else if (k === '=') { calcular(); return; }
    else {
      // Tras «=»: un número empieza otra cuenta; un operador sigue con el resultado
      if (calcRecien) { if (/[0-9.(]/.test(k)) calcTxt = ''; calcRecien = false; }
      const ultimo = calcTxt.slice(-1);
      if (/[0-9]/.test(k)) { if (ultimo === ')' || ultimo === '%') calcTxt += '*'; calcTxt += k; }
      else if (k === '.') {
        const num = /[0-9.]*$/.exec(calcTxt)[0];
        if (num.includes('.')) return;
        if (ultimo === ')' || ultimo === '%') calcTxt += '*';
        calcTxt += num ? '.' : '0.';
      } else if ('+*/'.includes(k)) {
        if (!calcTxt || ultimo === '(') return;
        if (/[+\-*/]/.test(ultimo)) calcTxt = calcTxt.slice(0, -1).replace(/[+\-*/]$/, '');
        if (calcTxt) calcTxt += k;
      } else if (k === '-') {
        if (ultimo === '-' || ultimo === '+') calcTxt = calcTxt.slice(0, -1);
        calcTxt += '-';
      } else if (k === '%') { if (/[0-9)]/.test(ultimo)) calcTxt += '%'; }
      else if (k === '(') { if (/[0-9)%]/.test(ultimo)) calcTxt += '*'; calcTxt += '('; }
      else if (k === ')') {
        const abiertos = (calcTxt.match(/\(/g) || []).length - (calcTxt.match(/\)/g) || []).length;
        if (abiertos > 0 && /[0-9)%]/.test(ultimo)) calcTxt += ')';
      }
      if (calcTxt.length > 120) calcTxt = calcTxt.slice(0, 120);
    }
    pintarCalc();
  }
  function calcular() {
    if (!calcTxt) return;
    const limpio = calcTxt.replace(/[+\-*/(]+$/, '');
    let v;
    try { v = evaluar(limpio); }
    catch (e) {
      calcRes.textContent = e.message === 'cero' ? 'No se puede dividir entre 0' : 'Revisa la cuenta';
      calc.classList.add('is-error');
      return;
    }
    cinta.unshift({ expr: bonito(limpio), res: fmtCalc(v).replace(/^-/, '−') });   // el menos, igual que en la cuenta
    cinta = cinta.slice(0, 8);
    cintaElegida = 0;
    local.poner(CLAVE_CINTA, cinta);
    calcTxt = fmtCalc(v);
    calcRecien = true;
    pintarCalc();
  }
  function pintarCalc() {
    calc.classList.remove('is-error');
    if (calcRecien && cinta[0]) {
      calcExpr.textContent = cinta[0].expr + ' =';
      calcRes.textContent = cinta[0].res;
    } else {
      calcExpr.textContent = bonito(calcTxt);
      let vista = '';
      const limpio = calcTxt.replace(/[+\-*/(]+$/, '');
      if (limpio) { try { vista = fmtCalc(evaluar(limpio)).replace(/^-/, '−'); } catch (e) { vista = ''; } }
      calcRes.textContent = vista || (calcTxt ? '…' : '0');
    }
    calcCinta.innerHTML = cinta.length
      ? cinta.map((c, i) => `<button type="button" class="pz-calc__op${i === cintaElegida ? ' is-elegida' : ''}" data-calc-op="${i}" data-tip="Usar este resultado"><span>${esc(c.expr)} =</span><b>${esc(c.res)}</b></button>`).join('')
      : '<p class="pz-calc__vacia">Aquí quedan tus últimas cuentas.</p>';
    const pegar = calc.querySelector('[data-calc-accion="pegar"]');
    if (pegar) pegar.disabled = !cinta.length;
  }
  function calcPegar(texto) {
    const t = String(texto || '').replace(/×|x|X/g, '*').replace(/÷/g, '/').replace(/−/g, '-').replace(/,/g, '.').replace(/[^0-9.+\-*/%()]/g, '');
    for (const c of t) calcTecla(c);
  }
  function pegarCuenta() {
    if (!EDITAR) return;
    const c = cinta[cintaElegida] || cinta[0];
    if (!c) { toast('Primero haz una cuenta y pulsa «=».'); return; }
    const p = puntoPegado(), f = factor();
    crear([nuevoEl('nota', { x: r1(p.x - 100 * f), y: r1(p.y - 100 * f), w: Math.round(200 * f), h: Math.round(200 * f), texto: `${c.expr} = ${c.res}` })]);
    toast('Cuenta pegada en la pizarra.');
  }
  function colocarCalc(pos) {
    const w = calc.offsetWidth, h = calc.offsetHeight;
    const x = limitar(pos && Number.isFinite(pos.x) ? pos.x : window.innerWidth - w - 16, 8, Math.max(8, window.innerWidth - w - 8));
    const y = limitar(pos && Number.isFinite(pos.y) ? pos.y : window.innerHeight - h - 132, 76, Math.max(76, window.innerHeight - h - 8));
    calc._x = x; calc._y = y;
    calc.style.setProperty('--cx', Math.round(x) + 'px');
    calc.style.setProperty('--cy', Math.round(y) + 'px');
  }
  function alternarCalc(abrir) {
    if (!calc) return;
    if (abrir === undefined) abrir = calc.hidden;
    calc.hidden = !abrir;
    botonCalc?.classList.toggle('is-abierta', abrir);
    botonCalc?.setAttribute('aria-pressed', abrir ? 'true' : 'false');
    if (abrir) {
      pintarCalc();
      colocarCalc(local.leer(CLAVE_CALC_POS));
      calc.focus({ preventScroll: true });
    } else if (calc.contains(document.activeElement)) lienzo.focus({ preventScroll: true });
  }
  if (calc) {
    const TECLAS = { Enter: '=', '=': '=', Backspace: 'atras', Delete: 'C', c: 'C', C: 'C', x: '*', X: '*', ',': '.' };
    calc.addEventListener('keydown', (e) => {
      if (e.ctrlKey || e.metaKey || e.altKey) return;   // Ctrl+C / Ctrl+V: los eventos copy / paste
      let k = TECLAS[e.key] ?? e.key;
      if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); if (calcTxt) calcTecla('C'); else alternarCalc(false); return; }
      if (e.key === ' ') { e.preventDefault(); return; }   // que la barra no repita el último botón
      if (!/^[0-9.+\-*/%()=C]$/.test(k) && k !== 'atras') return;
      e.preventDefault();
      e.stopPropagation();
      calcTecla(k);
      const b = calc.querySelector(`[data-k="${CSS.escape(k)}"]`);
      if (b) { b.classList.add('is-pulsada'); setTimeout(() => b.classList.remove('is-pulsada'), 120); }
    });
    calc.addEventListener('click', (e) => {
      const t = e.target.closest('[data-k]');
      if (t) { calcTecla(t.dataset.k); return; }
      const op = e.target.closest('[data-calc-op]');
      if (op) {
        cintaElegida = Number(op.dataset.calcOp) || 0;
        const c = cinta[cintaElegida];
        if (c) { calcTxt = c.res.replace(/^−/, '-'); calcRecien = false; }
        pintarCalc();
        return;
      }
      const a = e.target.closest('[data-calc-accion]')?.dataset.calcAccion;
      if (a === 'cerrar') alternarCalc(false);
      else if (a === 'pegar') pegarCuenta();
    });
    // Se arrastra por la cabecera (solo con transform)
    const agarre = calc.querySelector('[data-calc-agarre]');
    agarre.addEventListener('pointerdown', (e) => {
      if (e.button !== 0 || e.target.closest('button')) return;
      e.preventDefault();
      agarre.setPointerCapture(e.pointerId);
      const dx = e.clientX - calc._x, dy = e.clientY - calc._y;
      calc.classList.add('is-moviendo');
      const mueve = (ev) => colocarCalc({ x: ev.clientX - dx, y: ev.clientY - dy });
      const suelta = () => {
        agarre.removeEventListener('pointermove', mueve);
        agarre.removeEventListener('pointerup', suelta);
        agarre.removeEventListener('pointercancel', suelta);
        calc.classList.remove('is-moviendo');
        local.poner(CLAVE_CALC_POS, { x: Math.round(calc._x), y: Math.round(calc._y) });
        calc.focus({ preventScroll: true });
      };
      agarre.addEventListener('pointermove', mueve);
      agarre.addEventListener('pointerup', suelta);
      agarre.addEventListener('pointercancel', suelta);
    });
    window.addEventListener('resize', () => { if (!calc.hidden) colocarCalc({ x: calc._x, y: calc._y }); }, { passive: true });
  }

  /* =========================================================
     Avisos y ayudas
     ========================================================= */
  let relojToast = 0;
  function toast(msg, error = false) {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.toggle('is-error', !!error);
    toastEl.classList.add('is-visible');
    clearTimeout(relojToast);
    relojToast = setTimeout(() => toastEl.classList.remove('is-visible'), error ? 5200 : 3200);
  }
  function pintarVacio() {
    if (!vacioEl) return;
    vacioEl.hidden = !(sync.cargada && !vivos().length);
  }

  // La ayuda de vidrio con el nombre y el atajo (en vez del title del navegador)
  let tipReloj = 0, tipEl = null;
  function ocultarTip() { clearTimeout(tipReloj); tipEl = null; tip.classList.remove('is-visible'); }
  document.addEventListener('pointerover', (e) => {
    if (e.pointerType !== 'mouse') return;
    const el = e.target.closest?.('[data-tip]');
    if (el === tipEl) return;
    ocultarTip();
    if (!el || gesto) return;
    tipEl = el;
    tipReloj = setTimeout(() => {
      if (!el.isConnected) return;
      tip.innerHTML = esc(el.dataset.tip) + (el.dataset.tecla ? ' ' + el.dataset.tecla.split(' ').map((k) => `<kbd>${esc(MAC && k === 'Ctrl' ? '⌘' : k)}</kbd>`).join('') : '');
      const r = el.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
      const x = limitar(r.left + r.width / 2 - w / 2, 8, window.innerWidth - w - 8);
      const y = r.top < 140 ? r.bottom + 8 : r.top - h - 8;
      tip.style.setProperty('--tx', Math.round(x) + 'px');
      tip.style.setProperty('--ty', Math.round(y) + 'px');
      tip.classList.add('is-visible');
    }, 320);
  }, { passive: true });
  document.addEventListener('pointerdown', ocultarTip, { passive: true, capture: true });

  $('#pz-atajos')?.addEventListener('click', () => { ayuda.hidden = !ayuda.hidden; });
  $('#pz-exportar')?.addEventListener('click', exportarPNG);
  document.addEventListener('pointerdown', (e) => {
    if (!ayuda.hidden && !ayuda.contains(e.target) && !e.target.closest('#pz-atajos')) ayuda.hidden = true;
  }, { passive: true });
  window.addEventListener('resize', () => { colocarPieza(); posicionarCtx(); }, { passive: true });

  /* =========================================================
     Arranque
     ========================================================= */
  const vistaGuardada = local.leer(CLAVE_VISTA);
  if (vistaGuardada && Number.isFinite(vistaGuardada.x) && Number.isFinite(vistaGuardada.z)) {
    vista.x = vistaGuardada.x; vista.y = vistaGuardada.y; vista.z = limitar(vistaGuardada.z, Z_MIN, Z_MAX);
  }
  // En Mac, las teclas dicen ⌘ (la ayuda, las pistas y los atajos)
  if (MAC) {
    document.querySelectorAll('#pz kbd[data-mod]').forEach((k) => { k.textContent = '⌘'; });
    document.querySelectorAll('#pz-ayuda dt').forEach((dt) => { dt.textContent = dt.textContent.replace(/\bCtrl\b/g, '⌘'); });
  }
  raiz.dataset.herr = herramienta;
  aplicarVista();
  colocarPieza();
  pintarHistoria();
  pintarEstado();
  ciclo();
  // Con la fuente ya cargada, el texto de las notas se vuelve a ajustar
  document.fonts?.ready.then(() => {
    nodos.forEach((n) => { n._fit = null; });
    vivos().forEach(pintar);
    colocarPieza();
    programarRender({ acomodar: true, flechas: true, sel: true });
  });

  // Para las pruebas: una mirada al estado (no cambia nada)
  window.__pizarra = {
    els, sel, vista, sync, exportarPNG, ajustar,
    get herramienta() { return herramienta; },
    get editando() { return editando && editando.id; },
    fijarVista(x, y, z) { vista.x = x; vista.y = y; vista.z = z; aplicarVista(); },
    elegir(ids) { sel.clear(); ids.forEach((id) => sel.add(id)); pintarSel(); pintarCtx(); },
  };
})();
