/* Mecapacito Admin - interacciones del panel */

/* =========================================================
   Feedback de carga: barra verde arriba al navegar + spinner en el
   botón que se envía. Hace que "cargar la pestaña" se vea, no un salto seco.
   ========================================================= */
(function () {
  const barra = document.createElement('div');
  barra.className = 'nav-progreso';
  document.addEventListener('DOMContentLoaded', () => document.body.appendChild(barra));
  const mostrar = () => barra.classList.add('activa');

  // Al abandonar la página (submit de formulario, clic en enlace, redirección)
  addEventListener('beforeunload', mostrar);

  // Volver con el botón "atrás" restaura la página tal cual quedó (bfcache),
  // barra encendida incluida: hay que apagarla a mano.
  addEventListener('pageshow', (e) => {
    if (!e.persisted) return;
    barra.classList.remove('activa');
    document.querySelectorAll('.btn-cargando').forEach((b) => b.classList.remove('btn-cargando'));
  });

  // Spinner en el botón que envía el formulario.
  document.addEventListener('submit', (e) => {
    // Las descargas no recargan la página: si mostráramos spinner/barra se
    // quedarían girando para siempre. Los formularios de descarga se marcan
    // con data-descarga.
    if (e.target && e.target.dataset && e.target.dataset.descarga !== undefined) return;
    const b = e.submitter;
    // Este listener va en captura, así que corre ANTES que los de burbuja: aún
    // no se sabe si alguno hará preventDefault para guardar por AJAX. Se decide
    // al final del ciclo; si nadie navega, no hay quien apague la barra y se
    // quedaría colgada arriba.
    setTimeout(() => {
      if (e.defaultPrevented) return;
      if (b && b.tagName === 'BUTTON' && !b.dataset.noSpin) {
        // Mientras guarda, el botón muestra solo el giro: sin texto ni ícono.
        // El giro toma el color que tenía el texto (blanco en los botones
        // llenos, el del borde en los de contorno) y queda centrado en el botón.
        const cs = getComputedStyle(b);
        b.style.setProperty('--spin-c', cs.color);
        if (cs.position === 'static') b.style.position = 'relative';
        b.classList.add('btn-cargando');
      }
      mostrar();
    }, 0);
  }, true);
})();

/* =========================================================
   Previsualización de los adjuntos de una observación.
   Una imagen o un PDF adjunto abre un modal con el archivo dentro, flechas
   (y ← →) para pasar por los de esa misma observación, y abajo Regresar y
   Descargar. Ctrl/Cmd+clic sigue abriéndolo en otra pestaña; los Word se
   descargan (el navegador no los muestra).

   Va al principio del archivo, con sus propias funciones y escuchando en
   captura, a propósito: así un error en otra parte de admin.js no lo apaga
   y ningún otro listener se queda con el clic. Y reconoce el tipo por la
   dirección del archivo, no por una marca del HTML, para que funcione aunque
   el obs_item.php del servidor sea uno anterior.
   ========================================================= */
(function () {
  const IMG = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'svg', 'bmp'];
  const extDe = (a) => (a.dataset.ext
    || (a.getAttribute('href') || '').split(/[?#]/)[0].split('.').pop() || '').toLowerCase();
  const esImg = (a) => a.classList.contains('obs-img') || IMG.includes(extDe(a));
  const sePuedeVer = (a) => esImg(a) || extDe(a) === 'pdf';
  const nombreDe = (a) => a.dataset.nombre || a.getAttribute('title') || a.textContent.trim()
    || decodeURIComponent((a.getAttribute('href') || '').split('/').pop());
  const escapar = (s) => String(s == null ? '' : s).replace(/[&<>"']/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  let dlg = null, lista = [], idx = 0;

  function crear() {
    dlg = document.createElement('dialog');
    dlg.className = 'dlg-meca dlg-visor-obs';
    dlg.innerHTML =
        '<div class="dlg-form">'
      +   '<header>'
      +     '<h3 class="font-display"><i class="fa-solid fa-file"></i><span class="vo-nombre"></span></h3>'
      +     '<div class="vo-acciones">'
      +       '<span class="vo-cont"></span>'
      +       '<button type="button" class="dlg-close" title="Cerrar"><i class="fa-solid fa-xmark"></i></button>'
      +     '</div>'
      +   '</header>'
      +   '<div class="vo-cuerpo">'
      +     '<div class="vo-previa"></div>'
      +     '<button type="button" class="vo-flecha vo-ant" title="Anterior (←)"><i class="fa-solid fa-chevron-left"></i></button>'
      +     '<button type="button" class="vo-flecha vo-sig" title="Siguiente (→)"><i class="fa-solid fa-chevron-right"></i></button>'
      +   '</div>'
      +   '<footer>'
      +     '<button type="button" class="btn-outline btn-meca vo-regresar"><i class="fa-solid fa-arrow-left"></i> Regresar</button>'
      +     '<a class="btn-primary btn-meca vo-bajar" download><i class="fa-solid fa-download"></i> Descargar</a>'
      +   '</footer>'
      + '</div>';
    document.body.appendChild(dlg);
    const cerrar = () => dlg.close();
    dlg.querySelector('.dlg-close').addEventListener('click', cerrar);
    dlg.querySelector('.vo-regresar').addEventListener('click', cerrar);
    dlg.querySelector('.vo-ant').addEventListener('click', () => ver(idx - 1));
    dlg.querySelector('.vo-sig').addEventListener('click', () => ver(idx + 1));
    // Un clic en el fondo oscurecido (fuera de la caja) también regresa
    dlg.addEventListener('click', (e) => { if (e.target === dlg) cerrar(); });
    dlg.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowLeft')  { e.preventDefault(); ver(idx - 1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); ver(idx + 1); }
    });
    // Al cerrar se vacía: si no, un PDF abierto se queda vivo por detrás
    dlg.addEventListener('close', () => { dlg.querySelector('.vo-previa').innerHTML = ''; lista = []; });
  }

  function ver(i) {
    if (!lista.length) return;
    idx = (i + lista.length) % lista.length;
    const a = lista[idx];
    dlg.querySelector('.vo-nombre').textContent = a.nombre;
    dlg.querySelector('header h3 > i').className = 'fa-solid ' + (a.img ? 'fa-file-image' : 'fa-file-pdf');
    dlg.querySelector('.vo-cont').textContent = lista.length > 1 ? (idx + 1) + ' / ' + lista.length : '';
    const bajar = dlg.querySelector('.vo-bajar');
    bajar.href = a.bajar || a.ruta;
    bajar.setAttribute('download', a.nombre);
    dlg.querySelectorAll('.vo-flecha').forEach((b) => { b.hidden = lista.length < 2; });
    dlg.querySelector('.vo-previa').innerHTML = a.img
      ? '<img class="adj-visor-img" src="' + escapar(a.ruta) + '" alt="' + escapar(a.nombre) + '">'
      : '<iframe class="adj-visor-frame" src="' + escapar(a.ruta) + '#view=FitH" title="' + escapar(a.nombre) + '"></iframe>';
  }

  document.addEventListener('click', (e) => {
    // Adjuntos de una observación, o de un grupo marcado (los del correo: [data-visor-grupo] a[data-visor])
    const el = e.target.closest && e.target.closest('.obs-adjuntos a[href], [data-visor-grupo] a[data-visor][href]');
    if (!el || !sePuedeVer(el) || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    const grupo = el.closest('.obs-adjuntos, [data-visor-grupo]');
    const items = [...grupo.querySelectorAll(grupo.matches('[data-visor-grupo]') ? 'a[data-visor][href]' : 'a[href]')].filter(sePuedeVer);
    lista = items.map((x) => ({ ruta: x.getAttribute('href'), bajar: x.dataset.bajar || x.getAttribute('href'), nombre: nombreDe(x), img: esImg(x) }));
    if (!dlg) crear();
    ver(items.indexOf(el));
    if (!dlg.open) dlg.showModal();
  }, true);
})();

/* =========================================================
   MC — componente de alertas Mecapacito.
   Nunca usar alert()/confirm() nativos: MC.toast y MC.confirm.
   ========================================================= */
const MC = {
  // Avisos con el Toast del design system: [tono, baldosa, trazos del ícono]
  _tonos: {
    success: ['success', 'verde', '<circle cx="12" cy="12" r="8.5"/><path d="M8.3 12.3l2.5 2.5 5-5.2"/>'],
    error: ['danger', 'rojo', '<circle cx="12" cy="12" r="8.5"/><path d="M9.3 9.3l5.4 5.4M14.7 9.3l-5.4 5.4"/>'],
    warning: ['warning', 'amarillo', '<path d="M10.3 4.6a2 2 0 0 1 3.4 0l7.3 12.6a2 2 0 0 1-1.7 3H4.7a2 2 0 0 1-1.7-3z"/><path d="M12 9.6v4"/><circle cx="12" cy="16.7" r="1.05" fill="currentColor" stroke="none"/>'],
    info: ['info', 'cian', '<circle cx="12" cy="12" r="8.5"/><path d="M12 11v5.3"/><circle cx="12" cy="7.9" r="1.05" fill="currentColor" stroke="none"/>'],
  },
  _svg(trazos, px) {
    return '<svg class="mc-icon" width="' + px + '" height="' + px + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + trazos + '</svg>';
  },

  /**
   * Sonidos cortos, sintetizados con Web Audio: no hay archivos que cargar ni
   * peticiones que esperar. Se apagan con localStorage['mc-sin-sonido'] = '1'.
   * El navegador solo deja sonar después de que la persona haya interactuado;
   * como todos salen de un clic o de Ctrl+Enter, eso ya se cumple.
   */
  _audio: null,
  _ctx() {
    try {
      if (localStorage.getItem('mc-sin-sonido') === '1') return null;
    } catch { /* modo privado: se deja sonar */ }
    const AC = window.AudioContext || window.webkitAudioContext;
    if (!AC) return null;
    if (!this._audio) this._audio = new AC();
    if (this._audio.state === 'suspended') this._audio.resume();
    return this._audio;
  },
  /** Una nota con caída suave; f2 la desliza para dar el "pop". */
  _nota(ctx, f1, f2, inicio, dur, vol, tipo = 'sine') {
    const osc = ctx.createOscillator();
    const g = ctx.createGain();
    const t = ctx.currentTime + inicio;
    osc.type = tipo;
    osc.frequency.setValueAtTime(f1, t);
    if (f2 !== f1) osc.frequency.exponentialRampToValueAtTime(f2, t + dur);
    g.gain.setValueAtTime(0.0001, t);
    g.gain.exponentialRampToValueAtTime(vol, t + 0.012);
    g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    osc.connect(g).connect(ctx.destination);
    osc.start(t);
    osc.stop(t + dur + 0.02);
  },
  /** Al enviar: dos notas que suben, cortas. */
  sonidoEnviar() {
    const ctx = this._ctx(); if (!ctx) return;
    this._nota(ctx, 620, 880, 0, 0.10, 0.055);
    this._nota(ctx, 900, 1180, 0.07, 0.12, 0.038);
  },
  /** Al abrir el cuadro de responder: un toque casi imperceptible. */
  sonidoAbrir() {
    const ctx = this._ctx(); if (!ctx) return;
    this._nota(ctx, 420, 520, 0, 0.06, 0.022);
  },
  /**
   * Estrena un elemento recién insertado: entra con su animación y se queda
   * un momento resaltado, para saber cuál es el nuevo sin buscarlo.
   */
  estrenar(el) {
    if (!el || !el.classList) return;
    el.classList.add('obs-estreno');
    el.addEventListener('animationend', () => el.classList.remove('obs-estreno'), { once: true });
  },

  /** Cuando algo sale mal: una nota que baja. */
  sonidoError() {
    const ctx = this._ctx(); if (!ctx) return;
    this._nota(ctx, 330, 190, 0, 0.16, 0.05, 'triangle');
  },

  /**
   * Aviso apilable (Toast del design system) con barra de tiempo, que se
   * detiene con el cursor encima. tipo: success | error | warning | info.
   * opciones.desc: segunda línea opcional. Máximo cuatro a la vez.
   */
  toast(mensaje, tipo = 'info', duracion = 4500, opciones = {}) {
    const vp = document.getElementById('mc-toasts');
    if (!vp) return;
    const [tono, tile, trazos] = this._tonos[tipo] || this._tonos.info;
    const slot = document.createElement('div');
    slot.className = 'mc-toast-slot';
    slot.innerHTML =
      '<div class="mc-toast-slot__inner">' +
        '<div class="mc-toast mc-toast--' + tono + '" role="' + (tono === 'danger' ? 'alert' : 'status') + '" data-duracion="' + duracion + '">' +
          '<span class="mc-toast__icon"><span class="mc-tile mc-tile--' + tile + ' mc-tile--sm">' + this._svg(trazos, 18) + '</span></span>' +
          '<div class="mc-toast__body"><p class="mc-toast__title"></p><p class="mc-toast__desc" hidden></p></div>' +
          '<button type="button" class="mc-iconbtn mc-iconbtn--ghost mc-iconbtn--sm mc-toast__close" aria-label="Cerrar aviso">' +
            this._svg('<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>', 16) + '</button>' +
          '<span class="mc-toast__bar" aria-hidden="true"><span></span></span>' +
        '</div>' +
      '</div>';
    slot.querySelector('.mc-toast__title').textContent = mensaje;
    if (opciones.desc) {
      const d = slot.querySelector('.mc-toast__desc');
      d.textContent = opciones.desc;
      d.hidden = false;
    }
    // opciones.accion = { texto, fn }: un botón dentro del aviso (p. ej. «Deshacer»); al usarlo se cierra
    if (opciones.accion && typeof opciones.accion.fn === 'function') {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'mc-btn mc-btn--ghost mc-btn--sm mc-toast__accion';
      b.innerHTML = '<span class="mc-btn__label"></span>';
      b.firstChild.textContent = opciones.accion.texto || 'Deshacer';
      b.addEventListener('click', () => { if (slot._cerrar) slot._cerrar(); opciones.accion.fn(); }, { once: true });
      slot.querySelector('.mc-toast__body').appendChild(b);
    }
    vp.prepend(slot);
    this._activarToast(slot);
    // Más de cuatro: se va el más viejo
    const slots = [...vp.querySelectorAll('.mc-toast-slot:not(.is-leaving)')];
    slots.slice(4).forEach((s) => s._cerrar && s._cerrar());
  },

  /** Cableado de un aviso (también los que imprime PHP: UI::toastDS). */
  _activarToast(slot) {
    const el = slot.querySelector('.mc-toast');
    if (!el || slot._cerrar) return;
    const vp = slot.parentElement;
    const profundidad = () => {
      if (!vp) return;
      [...vp.querySelectorAll('.mc-toast-slot:not(.is-leaving) .mc-toast-slot__inner')]
        .forEach((x, i) => x.style.setProperty('--_depth', String(i)));
    };
    const cerrar = () => {
      if (el.classList.contains('is-leaving')) return;
      el.classList.add('is-leaving');
      setTimeout(() => {
        slot.classList.add('is-leaving');
        setTimeout(() => { slot.remove(); profundidad(); }, 260);
      }, 200);
    };
    slot._cerrar = cerrar;
    el.querySelector('.mc-toast__close').addEventListener('click', cerrar);
    const barra = el.querySelector('.mc-toast__bar > span');
    barra.style.setProperty('--_dur', (parseInt(el.dataset.duracion, 10) || 4500) + 'ms');
    barra.classList.add('is-running');
    barra.addEventListener('animationend', cerrar);
    profundidad();
  },

  /** Dialogo de confirmacion. Devuelve Promise<boolean>. */
  confirm({ titulo = '¿Estás seguro?', mensaje = '', ok = 'Sí, continuar', cancelar = 'Cancelar', peligro = true } = {}) {
    return new Promise((resolver) => {
      const dlg = document.createElement('dialog');
      dlg.className = 'dlg-meca mc-confirm';
      dlg.innerHTML =
        '<div class="mc-confirm-cuerpo">' +
          '<div class="mc-confirm-icono ' + (peligro ? 'es-peligro' : '') + '">' +
            '<i class="fa-solid ' + (peligro ? 'fa-triangle-exclamation' : 'fa-circle-question') + '"></i>' +
          '</div>' +
          '<h3 class="font-display"></h3>' +
          '<p></p>' +
          '<footer>' +
            '<button type="button" class="btn-outline btn-meca btn-neutro mcc-no"></button>' +
            '<button type="button" class="btn-meca mcc-si ' + (peligro ? 'btn-peligro-solido' : 'btn-primary') + '"></button>' +
          '</footer>' +
        '</div>';
      dlg.querySelector('h3').textContent = titulo;
      dlg.querySelector('p').textContent = mensaje;
      dlg.querySelector('.mcc-no').textContent = cancelar;
      dlg.querySelector('.mcc-si').textContent = ok;
      document.body.appendChild(dlg);

      const terminar = (valor) => { dlg.close(); dlg.remove(); resolver(valor); };
      dlg.querySelector('.mcc-si').addEventListener('click', () => terminar(true));
      dlg.querySelector('.mcc-no').addEventListener('click', () => terminar(false));
      dlg.addEventListener('cancel', (e) => { e.preventDefault(); terminar(false); });
      dlg.addEventListener('click', (e) => { if (e.target === dlg) terminar(false); });
      dlg.showModal();
      dlg.querySelector('.mcc-no').focus();
    });
  },
};
// Disponible para los demás archivos (ds.js, ia.js): un «const» de arriba no
// queda en window, y sin esto ellos caían en el alert() del navegador.
window.MC = MC;

// Activar los toasts que ya vienen renderizados por PHP (mensajes flash)
document.querySelectorAll('#mc-toasts .mc-toast-slot').forEach((t) => MC._activarToast(t));

/* =========================================================
   MecaSelect — realza los <select> con buscador y multi-selección.
   Mantiene el <select> nativo oculto y sincronizado (los forms
   envían igual). Reutilizable en todo .select-meca (no .select-pill).
   ========================================================= */
const MecaSelect = {
  init(scope) {
    (scope || document).querySelectorAll('select.select-meca:not(.select-pill):not([data-ms])')
      .forEach((sel) => this.enhance(sel));
  },
  enhance(sel) {
    sel.dataset.ms = '1';
    const multi = sel.multiple;
    const wrap = document.createElement('div');
    wrap.className = 'ms' + (sel.classList.contains('select-sm') ? ' ms-sm' : '') + (multi ? ' ms-multi' : '');
    sel.parentNode.insertBefore(wrap, sel);
    wrap.appendChild(sel);

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'ms-trigger';
    trigger.innerHTML = '<span class="ms-label"></span><i class="fa-solid fa-chevron-down ms-caret"></i>';
    wrap.appendChild(trigger);

    const panel = document.createElement('div');
    panel.className = 'ms-panel';
    panel.innerHTML = '<div class="ms-search"><i class="fa-solid fa-magnifying-glass"></i>' +
                      '<input type="text" placeholder="Buscar…"></div><div class="ms-opts"></div>';
    const opts = panel.querySelector('.ms-opts');
    const search = panel.querySelector('input');
    const host = sel.closest('dialog') || document.body;
    let abierto = false;

    const buildOpts = () => {
      opts.innerHTML = '';
      [...sel.options].forEach((o) => {
        const el = document.createElement('div');
        el.className = 'ms-opt' + (o.selected ? ' sel' : '') + (o.disabled ? ' dis' : '');
        el.innerHTML = '<span>' + o.textContent + '</span><i class="fa-solid fa-check"></i>';
        el.addEventListener('click', () => {
          if (o.disabled) return;
          if (multi) {
            o.selected = !o.selected;
            el.classList.toggle('sel', o.selected);
          } else {
            [...sel.options].forEach((x) => (x.selected = false));
            o.selected = true;
          }
          sel.dispatchEvent(new Event('change', { bubbles: true }));
          renderLabel();
          if (!multi) cerrar();
        });
        opts.appendChild(el);
      });
    };
    const renderLabel = () => {
      const label = trigger.querySelector('.ms-label');
      const elegidas = [...sel.selectedOptions];
      if (multi) {
        label.innerHTML = elegidas.length
          ? elegidas.map((o) => '<span class="ms-chip">' + o.textContent +
              '<i class="fa-solid fa-xmark" data-val="' + o.value.replace(/"/g, '&quot;') + '"></i></span>').join('')
          : '<span class="ms-ph">' + (sel.dataset.ph || 'Selecciona…') + '</span>';
      } else {
        label.textContent = elegidas[0] ? elegidas[0].textContent : (sel.dataset.ph || '');
      }
    };
    const posicionar = () => {
      const r = trigger.getBoundingClientRect();
      // El panel copiaba el ancho del disparador y en los filtros estrechos los
      // nombres salían cortados ("Carlos …"). Se le da un mínimo, sin pasarse
      // del borde de la ventana.
      const ancho = Math.min(Math.max(r.width, 260), innerWidth - 20);
      panel.style.width = ancho + 'px';
      panel.style.left = Math.max(10, Math.min(r.left, innerWidth - ancho - 10)) + 'px';
      panel.style.top = (r.bottom + 6) + 'px';
      const h = panel.offsetHeight;
      if (r.bottom + 6 + h > innerHeight && r.top - 6 - h > 0) panel.style.top = (r.top - 6 - h) + 'px';
    };
    const abrir = () => {
      buildOpts(); host.appendChild(panel);
      abierto = true; wrap.classList.add('ms-open');
      posicionar(); search.value = ''; filtrar(''); search.focus();
    };
    const cerrar = () => { if (!abierto) return; abierto = false; wrap.classList.remove('ms-open'); panel.remove(); };
    const filtrar = (q) => {
      q = q.toLowerCase();
      opts.querySelectorAll('.ms-opt').forEach((el) => { el.style.display = el.textContent.toLowerCase().includes(q) ? '' : 'none'; });
    };

    trigger.addEventListener('click', (e) => {
      const x = e.target.closest('.ms-chip i');
      if (x) {
        e.stopPropagation();
        const o = [...sel.options].find((op) => op.value === x.dataset.val);
        if (o) { o.selected = false; sel.dispatchEvent(new Event('change', { bubbles: true })); renderLabel(); }
        return;
      }
      abierto ? cerrar() : abrir();
    });
    search.addEventListener('input', () => filtrar(search.value));
    search.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrar(); });
    document.addEventListener('click', (e) => { if (abierto && !panel.contains(e.target) && !wrap.contains(e.target)) cerrar(); });
    // El panel es fixed: si la página se mueve, se cierra para no quedar
    // suelto. Pero con captura llegan también los scrolls de DENTRO del panel,
    // y con ellos la lista de opciones no había manera de recorrerla.
    addEventListener('scroll', (e) => {
      if (e.target instanceof Node && panel.contains(e.target)) return;
      cerrar();
    }, true);
    addEventListener('resize', () => cerrar());
    // Sincroniza si el valor cambia por JS (p. ej. al editar en un modal)
    sel.addEventListener('ms-sync', () => { renderLabel(); });
    renderLabel();
  },
};
MecaSelect.init();

// Fija el valor de un <select> y refresca su MecaSelect
function setSelect(el, valor) {
  if (!el) return;
  if (el.multiple) {
    // Varias opciones marcadas: valor es una lista de ids
    const set = new Set((Array.isArray(valor) ? valor : [valor]).map(String));
    [...el.options].forEach((o) => { o.selected = set.has(o.value); });
  } else {
    el.value = String(valor);
  }
  el.dispatchEvent(new Event('ms-sync'));
}

/* =========================================================
   MecaDate — selector de fecha (y hora) con estilo del panel.
   Reemplaza el date picker nativo. Mantiene el input oculto con
   su name para que los forms envíen igual (YYYY-MM-DD / ...THH:MM).
   ========================================================= */
const MecaDate = {
  meses: ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'],
  dows: ['Lu','Ma','Mi','Ju','Vi','Sá','Do'],
  init(scope) {
    (scope || document).querySelectorAll('input[type="date"]:not([data-md]), input[type="datetime-local"]:not([data-md])')
      .forEach((inp) => this.enhance(inp));
  },
  enhance(inp) {
    inp.dataset.md = '1';
    const conHora = inp.type === 'datetime-local';
    inp.type = 'hidden';   // conserva el name y envía el valor
    const wrap = document.createElement('div');
    wrap.className = 'md';
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(inp);

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'md-trigger';
    trigger.innerHTML = '<span class="md-label"></span><i class="fa-regular fa-calendar md-ico"></i>';
    wrap.appendChild(trigger);

    const pop = document.createElement('div');
    pop.className = 'md-pop';
    const host = inp.closest('dialog') || document.body;
    let abierto = false;
    let ver = new Date();   // mes visible

    const parse = (v) => {
      if (!v) return null;
      // Una fecha sola "YYYY-MM-DD" hay que construirla en hora LOCAL: si se
      // pasa a new Date() se interpreta como UTC y en zonas al oeste (Ecuador
      // es UTC-5) cae al día anterior. Por eso se veía corrida.
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(v.trim());
      if (m) return new Date(+m[1], +m[2] - 1, +m[3]);
      const d = new Date(v.replace(' ', 'T'));
      return isNaN(d) ? null : d;
    };
    const dos = (n) => String(n).padStart(2, '0');
    const iso = (d) => d.getFullYear() + '-' + dos(d.getMonth() + 1) + '-' + dos(d.getDate());

    const label = () => {
      const d = parse(inp.value);
      const lbl = trigger.querySelector('.md-label');
      if (!d) { lbl.innerHTML = '<span class="md-ph">Elegir fecha' + (conHora ? ' y hora' : '') + '</span>'; return; }
      let s = dos(d.getDate()) + '/' + dos(d.getMonth() + 1) + '/' + d.getFullYear();
      if (conHora) s += ' · ' + dos(d.getHours()) + ':' + dos(d.getMinutes());
      lbl.textContent = s;
    };

    const pintar = () => {
      const sel = parse(inp.value);
      const hoy = new Date();
      const anio = ver.getFullYear(), mes = ver.getMonth();
      const primero = new Date(anio, mes, 1);
      const offset = (primero.getDay() + 6) % 7;   // lunes primero
      const dias = new Date(anio, mes + 1, 0).getDate();
      let cel = '';
      for (let i = 0; i < offset; i++) cel += '<span class="md-d md-vacia"></span>';
      for (let d = 1; d <= dias; d++) {
        const esHoy = hoy.getFullYear() === anio && hoy.getMonth() === mes && hoy.getDate() === d;
        const esSel = sel && sel.getFullYear() === anio && sel.getMonth() === mes && sel.getDate() === d;
        cel += '<button type="button" class="md-d' + (esSel ? ' sel' : '') + (esHoy ? ' hoy' : '') + '" data-d="' + d + '">' + d + '</button>';
      }
      pop.innerHTML =
        '<div class="md-head"><button type="button" class="md-nav" data-nav="-1"><i class="fa-solid fa-chevron-left"></i></button>' +
        '<b>' + this.meses[mes] + ' ' + anio + '</b>' +
        '<button type="button" class="md-nav" data-nav="1"><i class="fa-solid fa-chevron-right"></i></button></div>' +
        '<div class="md-dows">' + this.dows.map((x) => '<span>' + x + '</span>').join('') + '</div>' +
        '<div class="md-grid">' + cel + '</div>' +
        (conHora ? '<div class="md-hora"><i class="fa-regular fa-clock"></i> Hora <input type="time" class="md-time" value="' + (sel ? dos(sel.getHours()) + ':' + dos(sel.getMinutes()) : '09:00') + '"></div>' : '') +
        '<div class="md-pie"><button type="button" class="md-borrar">Borrar</button><button type="button" class="md-hoy">Hoy</button></div>';
    };

    const posicionar = () => {
      const r = trigger.getBoundingClientRect();
      pop.style.left = r.left + 'px';
      pop.style.top = (r.bottom + 6) + 'px';
      const h = pop.offsetHeight;
      if (r.bottom + 6 + h > innerHeight && r.top - 6 - h > 0) pop.style.top = (r.top - 6 - h) + 'px';
    };
    const abrir = () => {
      ver = parse(inp.value) || new Date();
      pintar(); host.appendChild(pop);
      abierto = true; wrap.classList.add('md-open'); posicionar();
    };
    const cerrar = () => { if (!abierto) return; abierto = false; wrap.classList.remove('md-open'); pop.remove(); };

    const fijar = (d) => {
      let val = iso(d);
      if (conHora) {
        const t = pop.querySelector('.md-time')?.value || '09:00';
        val += 'T' + t;
      }
      inp.value = val;
      inp.dispatchEvent(new Event('change', { bubbles: true }));
      label();
    };

    trigger.addEventListener('click', () => abierto ? cerrar() : abrir());
    pop.addEventListener('click', (e) => {
      // pintar() regenera el HTML del pop y desconecta el nodo clicado; sin
      // esto, el clic sigue burbujeando al listener de "clic fuera" (que ya no
      // encuentra el nodo dentro del pop) y cierra el calendario al navegar.
      e.stopPropagation();
      const nav = e.target.closest('[data-nav]');
      if (nav) { ver = new Date(ver.getFullYear(), ver.getMonth() + parseInt(nav.dataset.nav, 10), 1); pintar(); return; }
      const dia = e.target.closest('.md-d[data-d]');
      if (dia) {
        const d = new Date(ver.getFullYear(), ver.getMonth(), parseInt(dia.dataset.d, 10));
        fijar(d); pintar();
        if (!conHora) cerrar();
        return;
      }
      if (e.target.closest('.md-hoy')) { fijar(new Date()); pintar(); if (!conHora) cerrar(); return; }
      if (e.target.closest('.md-borrar')) { inp.value = ''; inp.dispatchEvent(new Event('change', { bubbles: true })); label(); cerrar(); return; }
    });
    pop.addEventListener('change', (e) => {
      if (e.target.classList.contains('md-time') && parse(inp.value)) fijar(parse(inp.value));
    });
    document.addEventListener('click', (e) => { if (abierto && !pop.contains(e.target) && !wrap.contains(e.target)) cerrar(); });
    addEventListener('scroll', () => cerrar(), true);
    addEventListener('resize', () => cerrar());
    inp.addEventListener('md-sync', label);
    label();
  },
};
MecaDate.init();

/* =========================================================
   MecaWizard - asistente por pasos de los modales apaisados.
   Marca el formulario con novalidate y valida panel a panel,
   asi el navegador nunca intenta enfocar un campo escondido.
   ========================================================= */
const MecaWizard = {
  init(scope) {
    (scope || document).querySelectorAll('form.wz:not([data-wz])').forEach((f) => this.montar(f));
  },

  /** Texto legible del valor de un control (para el resumen final). */
  valorDe(ctrl) {
    if (!ctrl) return '';
    if (ctrl.type === 'file') return [...ctrl.files].map((f) => f.name).join(', ');
    if (ctrl.tagName === 'SELECT') {
      const elegidas = [...ctrl.selectedOptions].filter((o) => o.value !== '0' && o.value !== '');
      return elegidas.map((o) => o.textContent.trim()).join(', ');
    }
    return (ctrl.value || '').trim();
  },

  /** Rellena el resumen del ultimo paso con lo que se lleva escrito. */
  resumir(form) {
    const caja = form.querySelector('.wz-resumen');
    if (!caja) return;
    caja.innerHTML = '';
    form.querySelectorAll('.wz-panel .campo').forEach((campo) => {
      if (campo.hasAttribute('data-sin-resumen')) return;
      // Lo que no se ve (el enlace propio con Zoom elegido, por ejemplo) no se resume
      if (campo.closest('[hidden]')) return;
      const etiqueta = campo.querySelector(':scope > span');
      if (!etiqueta) return;

      const dt = document.createElement('dt');
      dt.textContent = etiqueta.textContent.replace('*', '').trim();
      const dd = document.createElement('dd');
      const fila = document.createElement('div');

      // Un campo compuesto (día y hora, plataforma, repetición) dice él mismo qué resumir
      if (campo.dataset.resumen !== undefined) {
        dd.textContent = campo.dataset.resumen || '— sin definir —';
        if (!campo.dataset.resumen) dd.className = 'vacio';
        fila.append(dt, dd);
        caja.appendChild(fila);
        return;
      }

      // Editor de texto enriquecido: se muestra RENDERIZADO (con su tabla y
      // formato), no como HTML crudo. Es lo mismo que se va a guardar.
      const rico = campo.querySelector('[data-editor-rico]');
      if (rico) {
        const html = (rico.querySelector('.rt-fuente')?.value || '').trim();
        if (html) { dd.innerHTML = html; dd.classList.add('rt-render', 'wz-rico'); }
        else { dd.textContent = '— sin definir —'; dd.className = 'vacio'; }
        fila.classList.add('wz-resumen-bloque');
        fila.append(dt, dd);
        caja.appendChild(fila);
        return;
      }

      // Los campos de fecha quedan como input[type=hidden] tras MecaDate,
      // asi que se reconocen por su marca data-md en vez de por el tipo.
      const ctrl = [...campo.querySelectorAll('select, textarea, input')].find((c) =>
        c.dataset.md !== undefined ||
        !['radio', 'checkbox', 'color', 'hidden', 'submit', 'button'].includes(c.type));
      if (!ctrl) return;
      const valor = this.valorDe(ctrl);
      dd.textContent = valor || '— sin definir —';
      if (!valor) dd.className = 'vacio';
      fila.append(dt, dd);
      caja.appendChild(fila);
    });
  },

  montar(form) {
    form.dataset.wz = '1';
    form.setAttribute('novalidate', '');
    const pasos    = [...form.querySelectorAll('.wz-paso')];
    const paneles  = [...form.querySelectorAll('.wz-panel')];
    const btnAtras = form.querySelector('.wz-atras');
    const btnSig   = form.querySelector('.wz-siguiente');
    const btnOk    = form.querySelector('.wz-guardar');
    const contador = form.querySelector('.wz-contador');
    const tituloEl = form.querySelector('.wz-titulo-paso');
    const ayudaEl  = form.querySelector('.wz-ayuda-paso');
    if (!paneles.length) return;
    let actual = 0;
    // Avanzar y regresar se ven como chevrons: el nombre queda al pasar el mouse
    [btnAtras, btnSig].forEach((b) => { if (b && !b.title) b.title = b.textContent.trim(); });
    // En los modales, sobre el título va en qué paso estás («Paso 2/4»)
    let ceja = null;
    if (tituloEl && form.closest('.dlg-wizard')) {
      ceja = document.createElement('span');
      ceja.className = 'wz-ceja';
      tituloEl.before(ceja);
    }

    // Controles del panel que el navegador puede validar
    const controles = (i) => [...paneles[i].querySelectorAll('input, select, textarea')]
      .filter((c) => c.willValidate && !c.disabled);
    const valido  = (i) => controles(i).every((c) => c.checkValidity());

    // El error se escribe bajo el campo (no la burbuja del navegador, que no
    // sale en los selects y calendarios del panel porque esconden el nativo).
    const mensajeDe = (c) => {
      if (c.dataset.error) return c.dataset.error;
      if (c.validity.valueMissing) return c.tagName === 'SELECT' ? 'Elige una opción para continuar.' : 'Completa este campo para continuar.';
      return c.validationMessage;
    };
    const limpiarError = (c) => {
      const campo = c.closest('.campo');
      c.removeAttribute('aria-invalid');
      if (!campo) return;
      campo.classList.remove('wz-mal');
      campo.querySelector('.wz-error')?.remove();
    };
    const marcarError = (c) => {
      limpiarError(c);
      const campo = c.closest('.campo');
      c.setAttribute('aria-invalid', 'true');
      if (!campo) return;
      campo.classList.add('wz-mal');
      const msg = document.createElement('small');
      msg.className = 'wz-error';
      msg.id = 'wz-err-' + (c.name || 'campo').replace(/\W+/g, '') + '-' + Math.random().toString(36).slice(2, 7);
      msg.setAttribute('role', 'alert');
      msg.innerHTML = '<i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>';
      msg.append(' ' + mensajeDe(c));
      c.setAttribute('aria-describedby', msg.id);
      // Debajo del control visible: el select con buscador y el calendario
      // esconden el nativo dentro de su envoltura.
      (c.closest('.ms, .md, .rt') || c).insertAdjacentElement('afterend', msg);
    };
    const avisar = (i) => {
      const malos = controles(i).filter((c) => !c.checkValidity());
      malos.forEach(marcarError);
      pasos[i]?.classList.add('error');
      const malo = malos[0];
      if (!malo) return;
      const visible = malo.offsetParent ? malo : malo.closest('.campo')?.querySelector('.ms-trigger, .md-trigger, [contenteditable]');
      (visible || malo).focus?.({ preventScroll: true });
      (visible || malo).closest('.campo')?.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    };
    // Se va el aviso en cuanto el dato ya vale
    const revisar = (e) => {
      const c = e.target;
      if (!c.matches?.('input, select, textarea') || !c.closest('.campo.wz-mal')) return;
      if (!c.checkValidity()) return;
      limpiarError(c);
      const n = paneles.findIndex((p) => p.contains(c));
      if (n !== -1 && !paneles[n].querySelector('.wz-mal')) pasos[n]?.classList.remove('error');
    };
    form.addEventListener('input', revisar);
    form.addEventListener('change', revisar);

    const ir = (i) => {
      const destino = Math.max(0, Math.min(paneles.length - 1, i));
      // Hacia dónde entra el panel: adelante desde la derecha, atrás desde la izquierda
      form.dataset.dir = destino < actual ? 'atras' : 'adelante';
      actual = destino;
      const ultimo = actual === paneles.length - 1;
      paneles.forEach((p, n) => p.classList.toggle('activo', n === actual));
      pasos.forEach((p, n) => {
        p.classList.toggle('activo', n === actual);
        p.classList.toggle('hecho', n < actual);
        if (n < actual) p.classList.remove('error');
        if (n === actual) p.setAttribute('aria-current', 'step'); else p.removeAttribute('aria-current');
        const num = p.querySelector('.wz-num');
        const ico = p.dataset.icono ? '<i class="fa-solid ' + p.dataset.icono + '" aria-hidden="true"></i>' : String(n + 1);
        if (num) num.innerHTML = n < actual ? '<i class="fa-solid fa-check" aria-hidden="true"></i>' : ico;
      });
      if (contador) contador.textContent = 'Paso ' + (actual + 1) + ' de ' + paneles.length;
      if (ceja) ceja.textContent = 'Paso ' + (actual + 1) + '/' + paneles.length;
      const pasoAct = pasos[actual];
      if (tituloEl && pasoAct) tituloEl.textContent = pasoAct.dataset.titulo || '';
      if (ayudaEl && pasoAct) ayudaEl.textContent = pasoAct.dataset.ayuda || '';
      btnAtras?.classList.toggle('wz-oculto', actual === 0);
      btnSig?.classList.toggle('wz-oculto', ultimo);
      btnOk?.classList.toggle('wz-oculto', !ultimo);
      if (ultimo) this.resumir(form);
      const foco = paneles[actual].querySelector('input:not([type=hidden]):not([type=radio]):not([type=color]), textarea');
      if (foco) setTimeout(() => foco.focus({ preventScroll: true }), 60);
    };

    const avanzar = () => { if (valido(actual)) ir(actual + 1); else avisar(actual); };

    btnSig?.addEventListener('click', avanzar);
    btnAtras?.addEventListener('click', () => ir(actual - 1));
    pasos.forEach((p, n) => p.addEventListener('click', () => {
      if (n <= actual) return ir(n);
      for (let i = actual; i < n; i++) {
        if (!valido(i)) { ir(i); avisar(i); return; }
      }
      ir(n);
    }));

    form.addEventListener('submit', (e) => {
      // Enter a media asistente avanza en vez de enviar
      if (e.submitter !== btnOk) {
        e.preventDefault();
        avanzar();
        return;
      }
      const fallo = paneles.findIndex((_, i) => !valido(i));
      if (fallo !== -1) {
        e.preventDefault();
        ir(fallo);
        avisar(fallo);
      }
    });

    // Cada vez que se abre el modal, vuelve al primer paso y sin avisos viejos
    const dlg = form.closest('dialog');
    if (dlg) {
      new MutationObserver(() => {
        if (!dlg.open) return;
        form.querySelectorAll('[aria-invalid]').forEach(limpiarError);
        pasos.forEach((p) => p.classList.remove('error'));
        // data-wz-ir="2" en el modal: se abre directo en ese paso (una sola vez)
        const inicio = parseInt(dlg.dataset.wzIr || '0', 10) || 0;
        delete dlg.dataset.wzIr;
        actual = 0;
        ir(inicio);
      }).observe(dlg, { attributes: true, attributeFilter: ['open'] });
    }
    ir(0);
  },
};
MecaWizard.init();

/* =========================================================
   Lente de vidrio: al pasar el mouse por los tabs, por los pasos de
   un asistente y por las opciones del menú radial (Tareas), sale
   encima una pieza de vidrio líquido y se desliza a la de al lado.
   En los tabs cubre la pestaña; en los pasos y el menú radial, solo
   la bolita (una gota). Solo adorno: no recibe clics ni mueve nada
   (va en position:fixed, fuera del flujo).
   ========================================================= */
const LenteVidrio = {
  OPCIONES: '.tab-btn, .mc-tab, .sup-tab, .wz-paso, .rad-op',
  // La bolita sobre la que va la gota, según la opción
  BOLITAS: { 'wz-paso': '.wz-num', 'rad-op': '.rad-ico' },
  lentes: new Map(),   // una por capa: la página, cada modal abierto o el menú radial

  init() {
    if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
    document.addEventListener('pointerover', (e) => this.sobre(e.target), { passive: true });
    // Se apaga si se desplaza lo que la contiene (la página o la barra de pestañas),
    // no por cualquier lista con scroll de la página
    window.addEventListener('scroll', (e) => {
      const t = e.target;
      this.lentes.forEach((l) => { if (l._grupo && (t === document || t.contains?.(l._grupo))) this.ocultar(l); });
    }, { capture: true, passive: true });
    window.addEventListener('resize', () => this.ocultarTodas(), { passive: true });
  },

  lente(capa) {
    let l = this.lentes.get(capa);
    if (!l || !l.isConnected) {
      l = document.createElement('span');
      l.className = 'lg-lente';
      l.setAttribute('aria-hidden', 'true');
      capa.appendChild(l);
      this.lentes.set(capa, l);
    }
    return l;
  },

  sobre(el) {
    if (!el?.closest) return;
    // Se apagan las de otros grupos; en el hueco entre dos opciones del mismo
    // grupo no, así se desliza
    this.lentes.forEach((l) => { if (l._grupo && !l._grupo.contains(el)) this.ocultar(l); });
    const op = el.closest(this.OPCIONES);
    if (!op || op.disabled) return;
    // El menú radial va por encima de todo (z-index alto): la gota vive dentro
    const capa = el.closest('dialog[open]') || el.closest('.rad-menu') || document.body;
    this.mostrar(op, this.lente(capa));
  },

  mostrar(op, l) {
    const clase = Object.keys(this.BOLITAS).find((c) => op.classList.contains(c));
    const bolita = clase ? op.querySelector(this.BOLITAS[clase]) : null;
    const r = (bolita || op).getBoundingClientRect();
    if (!r.width) return;
    const grupo = op.parentElement;
    // Si ya estaba encendida en este grupo se desliza; si no, aparece en su sitio
    l.classList.toggle('lg-quieta', !(l.classList.contains('is-on') && l._grupo === grupo));
    l._grupo = grupo;
    // Gota sobre la bolita del paso; pastilla sobre la pestaña
    const [dx, dy] = bolita ? [7, 7] : [3, 2];
    l.style.left = (r.left - dx) + 'px';
    l.style.top = (r.top - dy) + 'px';
    l.style.width = (r.width + dx * 2) + 'px';
    l.style.height = (r.height + dy * 2) + 'px';
    l.style.borderRadius = bolita ? '50%' : Math.max((parseFloat(getComputedStyle(op).borderTopLeftRadius) || 0) + 2, 12) + 'px';
    l.classList.toggle('lg-pastilla', !bolita);
    l.classList.add('is-on');
  },

  ocultar(l) { l.classList.remove('is-on'); l._grupo = null; },
  ocultarTodas() { this.lentes.forEach((l) => this.ocultar(l)); },
};
LenteVidrio.init();

/* =========================================================
   Selector de dependencias (crear / editar tarea).
   Paso 1: ¿tiene dependencias? Paso 2 (si sí): se elige el equipo y sus
   tareas (buscador + casillas). Las elegidas quedan como chips con el icono y
   color de su equipo, y como <input hidden name="dependencias[]"> del form.
   Puede depender de tareas de OTRO equipo (dependencia cruzada).
   ========================================================= */
(function () {
  const fuente = document.getElementById('dep-datos');
  if (!fuente) return;
  let DATOS = [];
  try { DATOS = JSON.parse(fuente.textContent || '[]'); } catch (_) { DATOS = []; }
  if (!Array.isArray(DATOS) || !DATOS.length) return;

  // Índice global: id de tarea → su equipo (título, color, icono, actual)
  const IDX = {};
  DATOS.forEach((eq) => (eq.tareas || []).forEach((t) => {
    IDX[t.id] = { titulo: t.titulo, final: t.final, equipo: eq.nombre, color: eq.color, icono: eq.icono, actual: eq.actual };
  }));

  function montar(el) {
    const body   = el.querySelector('.dp-body');
    const ops    = [...el.querySelectorAll('.dp-op')];
    const selEq  = el.querySelector('.dp-equipo');
    const buscar = el.querySelector('.dp-buscar');
    const lista  = el.querySelector('.dp-opciones');
    const chips  = el.querySelector('.dp-chips');
    const hidden = el.querySelector('.dp-hidden');
    const sel    = new Set();      // ids elegidos
    let selfId   = 0;              // la tarea que se edita no depende de sí misma

    selEq.innerHTML = DATOS.map((eq) =>
      `<option value="${eq.id}">${eq.actual ? 'Este equipo · ' : ''}${esc(eq.nombre)}</option>`).join('');

    const equipoActivo = () => DATOS.find((eq) => String(eq.id) === String(selEq.value)) || DATOS[0];

    function pintarLista() {
      const eq = equipoActivo();
      const q  = (buscar.value || '').trim().toLowerCase();
      const items = (eq ? eq.tareas || [] : []).filter((t) =>
        t.id !== selfId && (!q || t.titulo.toLowerCase().includes(q)));
      lista.innerHTML = items.length
        ? items.map((t) =>
            `<label class="dp-op-tarea${sel.has(t.id) ? ' sel' : ''}">
               <input type="checkbox" value="${t.id}" ${sel.has(t.id) ? 'checked' : ''}>
               <span class="dp-t-tit">${esc(t.titulo)}</span>
               ${t.final ? '<span class="dp-t-fin" title="Ya completada"><i class="fa-solid fa-check"></i></span>' : ''}
             </label>`).join('')
        : '<p class="dp-vacio">No hay tareas que coincidan.</p>';
    }

    function pintarChips() {
      const arr = [...sel];
      chips.innerHTML = arr.map((id) => {
        const info = IDX[id]; if (!info) return '';
        return `<span class="dp-chip${info.actual ? '' : ' externo'}" style="--dc:${esc(info.color)}">
                  ${info.actual ? '<i class="fa-solid fa-link"></i>' : info.icono}
                  <span class="dp-chip-tit">${esc(info.titulo)}</span>
                  ${info.actual ? '' : `<em class="dp-chip-eq">${esc(info.equipo)}</em>`}
                  <button type="button" class="dp-quita" data-id="${id}" title="Quitar" aria-label="Quitar">&times;</button>
                </span>`;
      }).join('');
      hidden.innerHTML = arr.map((id) => `<input type="hidden" name="dependencias[]" value="${id}">`).join('');
    }

    function setModo(si) {
      ops.forEach((o) => o.classList.toggle('active', (o.dataset.dep === 'si') === si));
      body.hidden = !si;
      if (si) { pintarLista(); pintarChips(); }
      else { hidden.innerHTML = ''; }   // en "Sin dependencias" no se envía ninguna (la selección se conserva por si vuelve)
    }

    ops.forEach((o) => o.addEventListener('click', () => setModo(o.dataset.dep === 'si')));
    selEq.addEventListener('change', () => { buscar.value = ''; pintarLista(); });
    buscar.addEventListener('input', pintarLista);
    lista.addEventListener('change', (e) => {
      const cb = e.target.closest('input[type=checkbox]'); if (!cb) return;
      const id = parseInt(cb.value, 10);
      if (cb.checked) { if (IDX[id]) sel.add(id); } else { sel.delete(id); }
      cb.closest('.dp-op-tarea')?.classList.toggle('sel', cb.checked);
      pintarChips();
    });
    chips.addEventListener('click', (e) => {
      const b = e.target.closest('.dp-quita'); if (!b) return;
      sel.delete(parseInt(b.dataset.id, 10));
      pintarChips();
      pintarLista();
    });

    el.depAPI = {
      reset() { sel.clear(); selfId = 0; selEq.value = DATOS[0].id; buscar.value = ''; setModo(false); pintarChips(); },
      excludeSelf(id) { selfId = parseInt(id, 10) || 0; sel.delete(selfId); pintarLista(); pintarChips(); },
      setDeps(ids) {
        (ids || []).map(Number).forEach((id) => { if (IDX[id] && id !== selfId) sel.add(id); });
        pintarChips();
        if (sel.size) setModo(true);
      },
    };
    el.depAPI.reset();

    // Al cerrar su modal se limpia, para no arrastrar lo elegido a la próxima.
    const dlg = el.closest('dialog');
    if (dlg) dlg.addEventListener('close', () => el.depAPI.reset());
  }

  document.querySelectorAll('[data-dep-picker]').forEach(montar);
})();

// Fija el valor de un input de fecha y refresca su MecaDate
function setFecha(el, valor) {
  if (!el) return;
  el.value = valor || '';
  el.dispatchEvent(new Event('md-sync'));
}

/* ---------- Fechas de inicio/limite en los asistentes ---------- */

const isoDeFecha = (d) =>
  d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

/** Aviso en vivo: cuánto dura la tarea, o si las fechas están al revés. */
function actualizarDuracion(form) {
  const txt = form.querySelector('.wz-duracion');
  if (!txt) return;
  const ini = form.querySelector('input[name="fecha_inicio"]')?.value || '';
  // Las tareas la llaman fecha_limite y los requerimientos fecha_fin: es la
  // misma fecha de cierre y el aviso vale igual para las dos.
  const fin = form.querySelector('input[name="fecha_limite"], input[name="fecha_fin"]')?.value || '';
  txt.classList.remove('duracion-mal');
  if (!ini && !fin) { txt.textContent = 'Sin fechas: no aparecerá en el calendario.'; return; }
  if (!ini || !fin) {
    txt.textContent = ini ? 'Arranca el ' + ini + ', sin fecha límite.' : 'Con fecha límite el ' + fin + ', sin fecha de inicio.';
    return;
  }
  const dias = Math.round((new Date(fin + 'T12:00') - new Date(ini + 'T12:00')) / 86400000);
  if (dias < 0) {
    txt.textContent = 'El inicio es posterior a la fecha límite: corrige una de las dos.';
    txt.classList.add('duracion-mal');
    return;
  }
  txt.textContent = dias === 0
    ? 'Empieza y termina el mismo día.'
    : 'Ventana de ' + (dias + 1) + ' días (del ' + ini + ' al ' + fin + ').';
}

// Chips "Hoy / Mañana / El lunes que viene / En dos semanas"
document.addEventListener('click', (e) => {
  const chip = e.target.closest('[data-atajos-fecha] .chip-atajo');
  if (!chip) return;
  const form = chip.closest('form');
  const ini = form?.querySelector('input[name="fecha_inicio"]');
  if (!ini) return;

  if (chip.hasAttribute('data-limpiar')) {
    setFecha(ini, '');
  } else {
    const d = new Date();
    d.setHours(12, 0, 0, 0);
    if (chip.hasAttribute('data-lunes')) {
      d.setDate(d.getDate() + (((8 - d.getDay()) % 7) || 7));   // el próximo lunes
    } else {
      d.setDate(d.getDate() + parseInt(chip.dataset.dias || '0', 10));
    }
    setFecha(ini, isoDeFecha(d));
  }
  form.querySelectorAll('[data-atajos-fecha] .chip-atajo').forEach((c) => c.classList.remove('activo'));
  chip.classList.add('activo');
  actualizarDuracion(form);
});

// Recalcular el aviso cuando se toca cualquiera de las dos fechas
document.addEventListener('change', (e) => {
  const inp = e.target;
  if (!['fecha_inicio', 'fecha_limite', 'fecha_fin'].includes(inp.name)) return;
  const form = inp.closest('form');
  if (form) actualizarDuracion(form);
});

// Formularios con confirmacion propia: <form data-confirmar="mensaje">
// (delegado: funciona también con formularios agregados dinámicamente)
document.addEventListener('submit', (e) => {
  const form = e.target.closest('form[data-confirmar]');
  if (!form || form.dataset.confirmado === '1') return;
  e.preventDefault();
  MC.confirm({
    titulo: form.dataset.confirmarTitulo || '¿Estás seguro?',
    mensaje: form.dataset.confirmar,
    ok: form.dataset.confirmarOk || 'Sí, continuar',
  }).then((si) => {
    if (si) {
      form.dataset.confirmado = '1';
      form.requestSubmit ? form.requestSubmit() : form.submit();
    }
  });
}, true);

/* ---------- Barra lateral ---------- */

// La lista de navegacion scrollea sin barra visible: si desborda, se le
// difumina el borde para que se vea que hay mas arriba o abajo.
const sidebarNav = document.querySelector('.sidebar-nav');
function marcarDesborde() {
  if (!sidebarNav) return;
  const resto = sidebarNav.scrollHeight - sidebarNav.clientHeight;
  const arriba = sidebarNav.scrollTop > 4;
  const abajo = resto > 4 && sidebarNav.scrollTop < resto - 4;
  sidebarNav.classList.toggle('hay-mas-arriba', arriba);
  sidebarNav.classList.toggle('hay-mas-abajo', abajo);
}
if (sidebarNav) {
  sidebarNav.addEventListener('scroll', marcarDesborde, { passive: true });
  addEventListener('resize', marcarDesborde);
  marcarDesborde();
  // La pagina activa puede quedar fuera de vista al cargar
  const activo = sidebarNav.querySelector('.sidebar-link.active');
  if (activo) {
    activo.scrollIntoView({ block: 'nearest' });
    marcarDesborde();
  }
}

// Grupo "Proyectos": con la barra plegada, el boton abre un panel flotante
// con todos los proyectos en vez de listarlos como iconos sueltos.
document.querySelectorAll('.nav-grupo').forEach((grupo) => {
  const btn = grupo.querySelector('.nav-grupo-btn');
  const panel = grupo.querySelector('.nav-grupo-items');
  if (!btn || !panel) return;

  const colocar = () => {
    const r = btn.getBoundingClientRect();
    panel.style.top = '0px';                       // mide con la altura real
    const alto = panel.offsetHeight;
    const margen = 12;
    // Si el boton esta en la mitad de abajo (la cuenta), el panel crece
    // hacia arriba alineando su base con la del boton.
    let top = r.top + r.height / 2 > innerHeight / 2 ? r.bottom - alto : r.top - 6;
    if (top + alto > innerHeight - margen) top = innerHeight - alto - margen;
    panel.style.top = Math.max(margen, top) + 'px';
  };
  const cerrar = () => {
    if (!grupo.classList.contains('abierto')) return;
    grupo.classList.remove('abierto');
    btn.setAttribute('aria-expanded', 'false');
  };
  const abrir = () => {
    grupo.classList.add('abierto');
    btn.setAttribute('aria-expanded', 'true');
    colocar();
  };

  btn.addEventListener('click', (e) => {
    e.stopPropagation();
    grupo.classList.contains('abierto') ? cerrar() : abrir();
  });
  document.addEventListener('click', (e) => { if (!panel.contains(e.target)) cerrar(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrar(); });
  addEventListener('resize', cerrar);
});

// Con la barra desplegada, cada grupo (Proyectos, Equipos, PM…) se pliega tocando su
// título. Se recuerda en el navegador; el grupo de la página abierta siempre se ve.
(function () {
  const CLAVE = 'meca-nav-plegados';
  const leer = () => { try { return JSON.parse(localStorage.getItem(CLAVE) || '{}') || {}; } catch (e) { return {}; } };
  const guardados = leer();
  document.querySelectorAll('.sidebar .nav-grupo:not(.cuenta-menu)').forEach((g) => {
    const tit = g.querySelector(':scope > .sidebar-label');
    if (!tit) return;
    const nombre = (tit.firstChild && tit.firstChild.textContent || tit.textContent).trim();
    const plegado = g.classList.contains('con-activo') ? false : (nombre in guardados ? !!guardados[nombre] : g.dataset.plegado === '1');
    g.classList.toggle('plegado', plegado);
    tit.setAttribute('role', 'button');
    tit.tabIndex = 0;
    tit.setAttribute('aria-expanded', String(!plegado));
    const alternar = () => {
      const p = g.classList.toggle('plegado');
      tit.setAttribute('aria-expanded', String(!p));
      const m = leer();
      m[nombre] = p;
      try { localStorage.setItem(CLAVE, JSON.stringify(m)); } catch (e) { /* sin almacenamiento, solo por ahora */ }
    };
    tit.addEventListener('click', (e) => {
      if (document.documentElement.classList.contains('sb-collapsed')) return;
      if (e.target.closest('.nav-badge')) return;
      alternar();
    });
    tit.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); alternar(); } });
  });
})();

// Colapsar / expandir la barra lateral (persistido en localStorage)
const sidebarToggle = document.getElementById('sidebar-toggle');
if (sidebarToggle) {
  sidebarToggle.addEventListener('click', () => {
    const min = document.documentElement.classList.toggle('sb-collapsed');
    localStorage.setItem('meca-sidebar', min ? 'min' : 'full');
    setTimeout(marcarDesborde, 320);   // tras la transicion de ancho
  });
}

// Modo oscuro configurable (persistido en localStorage)
const themeToggle = document.getElementById('theme-toggle');
if (themeToggle) {
  themeToggle.addEventListener('click', () => {
    const dark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('meca-theme', dark ? 'dark' : 'light');
    // Lo que se pinta en un <canvas>/SVG no se entera de que cambió el CSS:
    // los gráficos del panel escuchan esto para repintarse con la otra paleta.
    document.dispatchEvent(new CustomEvent('meca:tema', { detail: { oscuro: dark } }));
  });
}

// Abrir modal de "nuevo proyecto" si se llego desde el sidebar
if (sessionStorage.getItem('abrirNuevo') === '1') {
  sessionStorage.removeItem('abrirNuevo');
  const dlg = document.getElementById('dlg-nuevo');
  if (dlg) dlg.showModal();
}

// Equipo maestro-detalle: seleccionar una fila muestra su card
const masterDetail = document.querySelector('.equipo-master-detail');
if (masterDetail) {
  const claveSel = 'persona-sel-' + (masterDetail.dataset.equipo || '');
  const seleccionar = (id) => {
    let hay = false;
    masterDetail.querySelectorAll('.persona-row').forEach((r) => {
      const activa = r.dataset.persona === id;
      r.classList.toggle('active', activa);
      if (activa) hay = true;
    });
    if (!hay) return false;
    masterDetail.querySelectorAll('[data-persona-card]').forEach((c) => {
      c.hidden = c.dataset.personaCard !== id;
    });
    return true;
  };
  masterDetail.querySelectorAll('.persona-row').forEach((row) => {
    row.addEventListener('click', (e) => {
      if (e.target.closest('.btn-copiar')) return;   // copiar no cambia la seleccion
      seleccionar(row.dataset.persona);
      sessionStorage.setItem(claveSel, row.dataset.persona);
      document.querySelector('.equipo-detalle')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });
  });
  const guardada = sessionStorage.getItem(claveSel);
  if (guardada) seleccionar(guardada);
}

// Copiar al portapapeles (usuarios de git, correos...).
// navigator.clipboard solo existe en contexto seguro (HTTPS o localhost),
// asi que hay un respaldo con un textarea temporal para el resto de casos.
function copiarAlPortapapeles(texto) {
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(texto);
  }
  return new Promise((resolver, rechazar) => {
    const ta = document.createElement('textarea');
    ta.value = texto;
    ta.setAttribute('readonly', '');
    ta.style.cssText = 'position:fixed;top:-1000px;opacity:0';
    document.body.appendChild(ta);
    ta.select();
    ta.setSelectionRange(0, ta.value.length);   // iOS
    const ok = document.execCommand && document.execCommand('copy');
    ta.remove();
    ok ? resolver() : rechazar(new Error('sin portapapeles'));
  });
}

document.addEventListener('click', async (e) => {
  const btn = e.target.closest('.btn-copiar');
  if (!btn) return;
  const texto = btn.dataset.copiar || '';
  if (!texto) return;
  try {
    await copiarAlPortapapeles(texto);
    MC.toast('Copiado: ' + texto, 'success', 2500);
    // Confirmacion en el propio boton: el icono pasa a un check un momento
    const icono = btn.querySelector('i');
    if (icono && !btn.classList.contains('copiado')) {
      const clasesAntes = icono.className;
      btn.classList.add('copiado');
      icono.className = 'fa-solid fa-check';
      setTimeout(() => {
        btn.classList.remove('copiado');
        icono.className = clasesAntes;
      }, 1200);
    }
  } catch {
    MC.toast('No se pudo copiar. Selecciona el texto y usa Ctrl+C.', 'error');
  }
});

// Editor de repositorios de un proyecto: agregar y quitar filas.
document.querySelectorAll('[data-repos-editor]').forEach((editor) => {
  const filas = editor.querySelector('.repos-filas');
  const tpl   = editor.querySelector('#repo-fila-tpl');

  const sincronizar = () => {
    // El nombre solo tiene sentido si hay mas de un repo del mismo tipo;
    // igual se deja siempre editable, pero al menos una fila siempre existe.
    if (!filas.querySelector('.repo-fila')) agregar();
  };
  const agregar = () => {
    // Indice fresco para que los tres campos caigan en la misma fila del POST
    const i = parseInt(editor.dataset.repoSiguiente || '0', 10);
    editor.dataset.repoSiguiente = i + 1;
    const frag = tpl.content.cloneNode(true);
    frag.querySelectorAll('[name]').forEach((el) => {
      el.name = el.name.replace('__i__', i);
    });
    filas.appendChild(frag);
    const url = filas.querySelector('.repo-fila:last-child .repo-url');
    if (url) url.focus();
  };

  // Ramas del repo: el <select> arranca con lo guardado y pide la lista real a
  // ramas.php (cacheada una hora en el servidor). Se hace por fila y solo una
  // vez por URL, para no consultar la API en cada clic.
  const cargarRamas = (fila) => {
    const sel = fila.querySelector('.repo-rama');
    const url = (fila.querySelector('.repo-url')?.value || '').trim();
    if (!sel || !url || sel.dataset.url === url) return;
    sel.dataset.url = url;
    const guardada = sel.value;
    fila.classList.add('repo-cargando');
    fetch('ramas.php?url=' + encodeURIComponent(url))
      .then((r) => r.json())
      .then((j) => {
        const ramas = (j && j.ramas) || [];
        // Sin lista (repo privado sin token, host desconocido) se deja lo que
        // ya había: no se le borra la rama al guardar.
        if (!ramas.length) { sel.dataset.url = ''; return; }
        sel.innerHTML = '';
        sel.add(new Option('Rama por defecto', ''));
        ramas.forEach((rn) => sel.add(new Option(rn, rn)));
        if (guardada && !ramas.includes(guardada)) sel.add(new Option(guardada + ' (ya no existe)', guardada));
        sel.value = guardada;
      })
      .catch(() => { sel.dataset.url = ''; })
      .finally(() => fila.classList.remove('repo-cargando'));
  };
  const cargarTodas = () => filas.querySelectorAll('.repo-fila').forEach(cargarRamas);

  // Al abrir el modal de proyecto (o al entrar en la página, si el editor no
  // vive en un diálogo) ya están las ramas listas cuando se despliega.
  const dlg = editor.closest('dialog');
  if (dlg) {
    new MutationObserver(() => { if (dlg.hasAttribute('open')) cargarTodas(); })
      .observe(dlg, { attributes: true, attributeFilter: ['open'] });
  } else {
    cargarTodas();
  }
  // Respaldo: si se pega la URL después, la lista se rehace al salir del campo
  editor.addEventListener('change', (e) => {
    const f = e.target.closest('.repo-fila');
    if (f && e.target.classList.contains('repo-url')) cargarRamas(f);
  });
  editor.addEventListener('mousedown', (e) => {
    const f = e.target.closest('.repo-fila');
    if (f && e.target.classList.contains('repo-rama')) cargarRamas(f);
  });

  editor.querySelector('.repo-agregar').addEventListener('click', agregar);
  editor.addEventListener('click', (e) => {
    const quitar = e.target.closest('.repo-quitar');
    if (!quitar) return;
    quitar.closest('.repo-fila').remove();
    sincronizar();
  });
});

/* =========================================================
   Proyecto en el celular (proyecto.php, hasta 900 px de ancho)

   En el celular la página de un proyecto tiene solo tres pestañas: Tareas,
   Observaciones y Reuniones. El calendario por día, Intercambios, Métricas y
   las vistas de tabla, Kanban y Flujo no caben en una pantalla angosta: los
   oculta ds.css (bloque «Proyecto en el celular») y las tareas se ven como
   tarjetas pequeñas (.pm-tareas). Tocar una abre su detalle con el clic
   general de [data-ver-tarea], más abajo.

   Aquí va qué vista se abre en el celular (las pestañas de abajo lo usan) y
   el buscador de las tarjetas. Son funciones con nombre propio para que las
   pestañas puedan llamarlas aunque estén escritas antes o después.
   ========================================================= */
function proyectoEnCelular() {
  return !!window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
}
/* En el celular, una vista que ahí no existe (un enlace a #vista-calendario,
   lo último que se eligió, el Calendario de entrada…) cae en Tareas. */
function proyectoVistaCelular(v) {
  return proyectoEnCelular() && !['tareas', 'observaciones', 'reuniones', 'release'].includes(v) ? 'tareas' : v;
}
/* Al girar el teléfono o cambiar el ancho de la ventana se cruza el corte de
   900 px: avisa para volver a pintar la vista (tarjetas ↔ tabla o Kanban). */
function proyectoAlCambiarAncho(fn) {
  if (!window.matchMedia) return;
  const mq = window.matchMedia('(max-width: 900px)');
  if (mq.addEventListener) mq.addEventListener('change', fn);
  else if (mq.addListener) mq.addListener(fn);
}
// Buscador de las tarjetas: oculta las que no coinciden y los estados que se quedan vacíos
document.querySelectorAll('[data-pm-buscar]').forEach((input) => {
  const panel = input.closest('.pm-tareas');
  if (!panel) return;
  const cuenta = panel.querySelector('[data-pm-cuenta]');
  const vacio = panel.querySelector('[data-pm-vacio]');
  // Sin tildes y en minúsculas, como el buscador de la tabla
  const normalizar = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  input.addEventListener('input', () => {
    const q = normalizar(input.value.trim());
    let total = 0;
    panel.querySelectorAll('[data-pm-grupo]').forEach((grupo) => {
      let n = 0;
      grupo.querySelectorAll('[data-pm-tarea]').forEach((card) => {
        const ok = !q || normalizar(card.dataset.buscar || card.textContent || '').includes(q);
        card.hidden = !ok;
        if (ok) n++;
      });
      grupo.hidden = n === 0;
      const nGrupo = grupo.querySelector('[data-pm-n]');
      if (nGrupo) nGrupo.textContent = n;
      total += n;
    });
    if (cuenta) cuenta.textContent = total;
    if (vacio) vacio.hidden = total > 0;
  });
});

// Vistas de la pagina de proyecto.
// "Tareas" es un combo: agrupa Tabla / Kanban / Flujo. Se cambia con el menú
// circular «Visualización» de la barra de cada una (opciones [data-subvista]).
// Al entrar se muestra el Calendario, o lo último elegido en ESE proyecto;
// las subvistas recuerdan su eleccion.
// En el celular, Tareas son las tarjetas (bloque «Proyecto en el celular», arriba).
const vistaToggle = document.querySelector('.vista-toggle');
if (vistaToggle) {
  const SUBVISTAS = ['tabla', 'kanban', 'flujo'];
  const claveVista = 'vista-' + location.pathname + '-' + (new URLSearchParams(location.search).get('id') || '');
  const claveSub   = 'subvista-' + location.pathname;

  let subActual = sessionStorage.getItem(claveSub) || 'tabla';
  if (!SUBVISTAS.includes(subActual)) subActual = 'tabla';
  // La pestaña que eligió la persona. En el celular puede verse otra (Tareas en
  // vez del Calendario), pero esa no se guarda: al volver a la pantalla ancha
  // (zoom, ventana a media pantalla, girar la tableta) regresa a la elegida.
  let elegida = 'calendario';

  const panelActivo = (v) => (v === 'tareas' ? (proyectoEnCelular() ? 'tarjetas' : subActual) : v);

  const pintar = (v) => {
    const panel = panelActivo(v);
    vistaToggle.querySelectorAll('[data-vista]').forEach((b) => b.classList.toggle('active', b.dataset.vista === v));
    document.querySelectorAll('[data-vista-panel]').forEach((p) => { p.hidden = p.dataset.vistaPanel !== panel; });
    // En «Visualización» la actual lleva su marca (y el menú abre en ella)
    document.querySelectorAll('[data-subvista]').forEach((b) => {
      const es = b.dataset.subvista === subActual;
      b.classList.toggle('is-actual', es);
      if (es) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
    });
    if (panel === 'flujo') dibujarFlujo();
  };

  const boton = (v) => vistaToggle.querySelector('[data-vista="' + v + '"]');

  // Elegir una vista principal (calendario, tareas, observaciones, ...)
  const activarVista = (v, guardar = true) => {
    // Una subvista suelta (por hash o guardada) equivale a Tareas + esa subvista
    if (SUBVISTAS.includes(v)) { subActual = v; sessionStorage.setItem(claveSub, v); v = 'tareas'; }
    if (!boton(v)) return;
    elegida = v;
    if (guardar) sessionStorage.setItem(claveVista, v);
    pintar(proyectoVistaCelular(v));   // en el celular solo hay Tareas, Observaciones, Release y Reuniones
  };

  vistaToggle.querySelectorAll('[data-vista]').forEach((btn) =>
    btn.addEventListener('click', () => activarVista(btn.dataset.vista)));

  // Tabla, Kanban o Flujo desde «Visualización» (también con su clic derecho)
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-subvista]');
    if (!b || !SUBVISTAS.includes(b.dataset.subvista)) return;
    subActual = b.dataset.subvista;
    sessionStorage.setItem(claveSub, subActual);
    activarVista('tareas');
  });

  // Al entrar: hash (#vista-X), luego lo ultimo elegido, si no el Calendario.
  // Lo guardado puede ser una pestaña que ya no se ve (Intercambios sin
  // solicitudes): entonces también el Calendario, nunca una barra sin pestaña.
  const porHash = location.hash.startsWith('#vista-') ? location.hash.slice(7) : null;
  const guardada = sessionStorage.getItem(claveVista);
  if (porHash && (SUBVISTAS.includes(porHash) || boton(porHash))) activarVista(porHash);
  else activarVista(guardada && boton(guardada) && !boton(guardada).hidden ? guardada : 'calendario', false);

  // Cruzar los 900 px (girar el teléfono, zoom): repintar la pestaña elegida
  proyectoAlCambiarAncho(() => pintar(proyectoVistaCelular(elegida)));
}

// Compositor de observaciones: pegar (Ctrl+V), arrastrar, adjuntar y enviar por AJAX.
// Soporta varios compositores en paralelo (botón "+ Otra nota").
function initComposer(form) {
  if (form.dataset.init === '1') return;
  form.dataset.init = '1';
  const fileInput = form.querySelector('.oc-file');
  const previews  = form.querySelector('.oc-previews');
  // El campo pasó a editor enriquecido: el HTML viaja en el textarea oculto
  // (.rt-fuente) y se escribe en el contenteditable (.rt-area). Los eventos de
  // teclado y de pegar van en el área; el valor, en la fuente.
  const area      = form.querySelector('.rt-area');
  const fuente    = form.querySelector('.rt-fuente');
  const rico      = form.querySelector('[data-editor-rico]');
  const hayTexto  = () => (area?.innerText || '').trim() !== '' || /<(img|table|li)\b/i.test(fuente?.value || '');
  const limpiar   = () => {
    if (area) area.innerHTML = '';
    if (fuente) fuente.value = '';
    rico?.classList.add('rt-vacio');
  };
  const bolsa = new DataTransfer();

  const pintar = () => {
    previews.innerHTML = '';
    [...bolsa.files].forEach((f, i) => {
      const chip = document.createElement('div');
      if (f.type.startsWith('image/')) {
        const url = URL.createObjectURL(f);
        chip.className = 'oc-prev oc-prev-img';
        chip.innerHTML = '<img src="' + url + '" alt=""><button type="button" data-i="' + i + '" title="Quitar"><i class="fa-solid fa-xmark"></i></button>';
      } else {
        chip.className = 'oc-prev oc-prev-doc';
        chip.innerHTML = '<i class="fa-solid fa-file-lines"></i><span>' + f.name + '</span>' +
                         '<button type="button" data-i="' + i + '" title="Quitar"><i class="fa-solid fa-xmark"></i></button>';
      }
      previews.appendChild(chip);
    });
    fileInput.files = bolsa.files;
  };
  const agregar = (files) => { [...files].forEach((f) => bolsa.items.add(f)); pintar(); };

  area.addEventListener('paste', (e) => {
    const imgs = [...(e.clipboardData?.items || [])].filter((it) => it.type.startsWith('image/'));
    if (!imgs.length) return;
    imgs.forEach((it, k) => {
      const blob = it.getAsFile();
      if (blob) bolsa.items.add(new File([blob], 'captura-' + Date.now() + '-' + k + '.png', { type: blob.type }));
    });
    pintar();
    MC.toast(imgs.length + ' imagen' + (imgs.length === 1 ? '' : 'es') + ' pegada' + (imgs.length === 1 ? '' : 's'), 'success', 2000);
  });
  form.addEventListener('dragover', (e) => { e.preventDefault(); form.classList.add('oc-drag'); });
  form.addEventListener('dragleave', () => form.classList.remove('oc-drag'));
  form.addEventListener('drop', (e) => {
    e.preventDefault(); form.classList.remove('oc-drag');
    if (e.dataTransfer.files.length) agregar(e.dataTransfer.files);
  });
  fileInput.addEventListener('change', () => agregar(fileInput.files));
  previews.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-i]');
    if (!b) return;
    bolsa.items.remove(parseInt(b.dataset.i, 10));
    pintar();
  });
  area.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); form.requestSubmit(); }
  });
  // Botón × para quitar el compositor (deja al menos uno)
  form.querySelector('.oc-cerrar')?.addEventListener('click', () => {
    const cont = document.getElementById('obs-composers');
    if (cont.querySelectorAll('.obs-composer').length > 1) { form.remove(); actualizarAddNota(); }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!hayTexto() && !bolsa.files.length) {
      MC.sonidoError();
      MC.toast('Escribe la observación o adjunta un archivo.', 'error');
      return;
    }
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.classList.add('btn-enviando');
    const fd = new FormData(form);
    fd.set('ajax', '1');
    try {
      const res = await fetch('actions.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
      const data = await res.json();
      if (!data.ok) { MC.sonidoError(); MC.toast(data.error || 'No se pudo guardar.', 'error'); return; }
      const lista = document.getElementById('obs-lista');
      lista.querySelector('.empty-state')?.remove();
      data.items.reverse().forEach((html) => {
        lista.insertAdjacentHTML('afterbegin', html);
        MC.estrenar(lista.firstElementChild);
      });
      limpiar();
      while (bolsa.items.length) bolsa.items.remove(0);
      pintar();
      const total = document.querySelector('.obs-card .tabla-count');
      if (total) total.textContent = data.total;
      const chipPend = document.querySelector('#obs-filtros [data-filtro="pendiente"]');
      if (chipPend) chipPend.textContent = 'Pendientes' + (data.pendientes ? ' · ' + data.pendientes : '');
      const tabBadge = document.querySelector('.vista-toggle [data-vista="observaciones"] .tab-badge');
      if (tabBadge) tabBadge.textContent = data.pendientes;
      MC.sonidoEnviar();
      MC.toast(data.items.length > 1 ? data.items.length + ' observaciones anotadas' : 'Observación anotada', 'success', 1800);
      // En el modal: se cierra al guardar; suelto, sigue listo para otra
      const dlgObs = form.closest('dialog');
      if (dlgObs) dlgObs.close(); else area.focus();
    } catch {
      MC.sonidoError();
      MC.toast('Error de red al guardar la observación.', 'error');
    } finally {
      btn.disabled = false;
      btn.classList.remove('btn-enviando');
    }
  });
}

// Habilita/deshabilita el botón "+ Otra nota" según el máximo permitido
function actualizarAddNota() {
  const cont = document.getElementById('obs-composers');
  const btn = document.getElementById('obs-add-nota');
  if (!cont || !btn) return;
  const n = cont.querySelectorAll('.obs-composer').length;
  const max = parseInt(cont.dataset.max || '3', 10);
  cont.classList.toggle('oc-solo', n <= 1);   // oculta la × cuando solo hay uno
  btn.disabled = n >= max;
  btn.title = n >= max ? 'Máximo ' + max + ' notas a la vez' : 'Abrir otro cuadro para anotar en paralelo';
}

// Inicializa los compositores existentes y el botón de agregar
document.querySelectorAll('.obs-composer').forEach(initComposer);
(() => {
  const btn = document.getElementById('obs-add-nota');
  const cont = document.getElementById('obs-composers');
  const tpl = document.getElementById('tpl-composer');
  if (!btn || !cont || !tpl) return;
  actualizarAddNota();
  btn.addEventListener('click', () => {
    if (cont.querySelectorAll('.obs-composer').length >= parseInt(cont.dataset.max || '3', 10)) return;
    const nodo = tpl.content.firstElementChild.cloneNode(true);
    cont.appendChild(nodo);
    MecaSelect.init(nodo);
    initComposer(nodo);
    actualizarAddNota();
    nodo.querySelector('.rt-area')?.focus();
  });
})();

// Filtro de observaciones (todas / pendientes / resueltas)
const obsFiltros = document.getElementById('obs-filtros');
if (obsFiltros) {
  const items = [...document.querySelectorAll('.obs-item')];
  obsFiltros.addEventListener('click', (e) => {
    const chip = e.target.closest('.chip-filtro');
    if (!chip) return;
    obsFiltros.querySelectorAll('.chip-filtro').forEach((c) => c.classList.toggle('active', c === chip));
    const f = chip.dataset.filtro;
    items.forEach((it) => { it.hidden = f !== 'todas' && it.dataset.estado !== f; });
  });
}

// Conectores SVG entre tareas dependientes (vista Flujo)
function dibujarFlujo() {
  const wrap = document.getElementById('flujo-wrap');
  const svg = document.getElementById('flujo-lineas');
  if (!wrap || !svg) return;

  const depsDe = (nodo) => (nodo.dataset.deps || nodo.dataset.dep || '').split(',').map((s) => s.trim()).filter((s) => s && s !== '0');
  // Alinear cada nodo a la altura de su PRIMERA dependencia (flechas casi rectas)
  wrap.querySelectorAll('.flujo-nodo').forEach((n) => { n.style.marginTop = ''; });
  wrap.querySelectorAll('.flujo-nodo').forEach((nodo) => {
    const deps = depsDe(nodo);
    if (!deps.length) return;
    const origen = document.getElementById('fn-' + deps[0]);
    if (!origen) return;
    const delta = origen.getBoundingClientRect().top - nodo.getBoundingClientRect().top;
    if (delta > 0) nodo.style.marginTop = delta + 'px';
  });

  const caja = wrap.getBoundingClientRect();
  svg.setAttribute('width', wrap.scrollWidth);
  svg.setAttribute('height', wrap.scrollHeight);
  const color = getComputedStyle(wrap).getPropertyValue('--pc').trim() || '#2B76F7';
  let trazos = '<defs><marker id="flecha" viewBox="0 0 10 10" refX="7.5" refY="5" markerWidth="6" markerHeight="6" orient="auto">' +
               '<path d="M 0.5 1 L 8.5 5 L 0.5 9 Q 3 5 0.5 1 z" fill="' + color + '"/></marker></defs>';
  wrap.querySelectorAll('.flujo-nodo').forEach((nodo) => {
    // Todas las dependencias que apuntan a ESTE nodo, ordenadas por la altura
    // de su origen para que las flechas no se crucen.
    const origenes = depsDe(nodo)
      .map((id) => document.getElementById('fn-' + id))
      .filter(Boolean)
      .sort((a, c) => a.getBoundingClientRect().top - c.getBoundingClientRect().top);
    if (!origenes.length) return;
    const b = nodo.getBoundingClientRect();
    const x2 = b.left - caja.left + wrap.scrollLeft - 9;
    origenes.forEach((origen, i) => {
      const a = origen.getBoundingClientRect();
      const x1 = a.right - caja.left + wrap.scrollLeft + 2;
      const y1 = a.top + a.height / 2 - caja.top + wrap.scrollTop;
      // Reparte los puntos de llegada a lo alto del nodo destino (abanico), así
      // varias dependencias no chocan en el mismo punto.
      const frac = origenes.length === 1 ? 0.5 : 0.28 + 0.44 * (i / (origenes.length - 1));
      const y2 = b.top + b.height * frac - caja.top + wrap.scrollTop;
      const cx = Math.max(42, (x2 - x1) * 0.5);
      trazos += '<circle cx="' + x1 + '" cy="' + y1 + '" r="3.5" fill="' + color + '"/>' +
                '<path d="M ' + x1 + ' ' + y1 + ' C ' + (x1 + cx) + ' ' + y1 + ', ' + (x2 - cx) + ' ' + y2 + ', ' + x2 + ' ' + y2 + '"' +
                ' fill="none" stroke="' + color + '" stroke-width="2.2" stroke-opacity=".9" stroke-linecap="round" marker-end="url(#flecha)"/>';
    });
  });
  svg.innerHTML = trazos;
}
window.addEventListener('resize', () => {
  const panelFlujo = document.querySelector('[data-vista-panel="flujo"]');
  if (panelFlujo && !panelFlujo.hidden) dibujarFlujo();
});

/* Guarda el estado de una tarea por AJAX (sin recargar → sin "brinco").
   Actualiza los contadores del kanban con lo que devuelve el servidor.
   Devuelve una promesa con {ok, ...}. */
function guardarEstadoTarea(id, estado) {
  const body = new URLSearchParams({ accion: 'tarea_estado', id, estado, ajax: '1' });
  return fetch('actions.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'fetch' },
    body,
  }).then((r) => r.json()).then((res) => {
    if (res && res.ok) pintarAvanceProyecto(res);
    return res || { ok: false };
  }).catch(() => ({ ok: false, error: 'Sin conexión' }));
}

/* Refresca lo que la recarga pintaba sola: contadores del kanban, tiles de
   resumen por estado y la barra de avance de la cabecera. Sin esto, guardar
   por AJAX deja esos números en el valor viejo. */
function pintarAvanceProyecto(res) {
  if (res.conteo) {
    // Con el filtro de fechas puesto, el tablero NO muestra todo el proyecto:
    // el conteo del servidor (que sí es del proyecto entero) dejaria columnas
    // diciendo 6 con dos tarjetas dentro. Ahi se cuentan las tarjetas reales.
    const kbFiltrado = !!document.querySelector('.kanban[data-filtrado]');
    document.querySelectorAll('.kanban .kb-cards[data-estado-drop]').forEach((z) => {
      const k = z.dataset.estadoDrop;
      const head = z.closest('.kb-col')?.querySelector('.kb-count');
      if (!head) return;
      if (kbFiltrado) head.textContent = z.querySelectorAll('.kb-card').length;
      else if (res.conteo[k] !== undefined) head.textContent = res.conteo[k];
    });
    // Los estados del encabezado del proyecto (.phk) y las tarjetas sueltas
    // que siguen usando otras pantallas (.estado-tile).
    const total = Object.values(res.conteo).reduce((a, n) => a + n, 0);
    Object.keys(res.conteo).forEach((k) => {
      const n = res.conteo[k];
      const viejo = document.querySelector('.estado-tile.estado-' + k + ' .et-datos b');
      if (viejo) viejo.textContent = n;

      const kpi = document.querySelector('.phk.estado-' + k);
      if (!kpi) return;
      const cifra = kpi.querySelector('.phk-linea b');
      if (cifra) cifra.textContent = n;
      // La barra es la parte del total: sin esto se queda con el ancho viejo.
      const relleno = kpi.querySelector('.phk-barra > span');
      if (relleno) relleno.style.width = (total > 0 ? Math.round(n / total * 100) : 0) + '%';
      const lbl = kpi.querySelector('.phk-linea small');
      kpi.title = n + ' de ' + total + (lbl ? ' · ' + lbl.textContent : '');
    });
  }

  if (typeof res.avance !== 'number') return;
  // Mismo corte de semáforo que proyecto.php
  const nivel = res.avance >= 67 ? 'verde' : (res.avance >= 34 ? 'amarillo' : 'rojo');

  const hero = document.querySelector('.proyecto-hero .ph-barra');
  if (hero) {
    hero.title = 'Avance del proyecto: ' + res.avance + '%';
    const relleno = hero.querySelector('span');
    if (relleno) relleno.style.width = res.avance + '%';
  }

  const caja = document.querySelector('.ph-avance-abajo');
  if (!caja) return;
  caja.title = res.completadas + ' de ' + res.total + ' tareas completadas';
  const texto = caja.querySelector('small');
  if (texto) texto.textContent = res.completadas + '/' + res.total + ' tareas';
  const semaforo = caja.querySelector('.barra-semaforo');
  if (semaforo) {
    semaforo.className = 'barra-semaforo sem-' + nivel;
    const relleno = semaforo.querySelector('span');
    if (relleno) relleno.style.width = res.avance + '%';
  }
  const pct = caja.querySelector('.pam-num');
  if (pct) {
    pct.className = 'pam-num sem-txt-' + nivel;
    pct.textContent = res.avance + '%';
  }
}

// Kanban: arrastrar tarjetas entre columnas cambia el estado (AJAX, sin recargar)
const kanban = document.querySelector('.kanban');
if (kanban) {
  let arrastrando = null;
  let origen = null;
  kanban.addEventListener('dragstart', (e) => {
    const card = e.target.closest('.kb-card');
    if (!card || card.getAttribute('draggable') === 'false') return;
    arrastrando = card;
    origen = card.parentElement;   // por si hay que revertir
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
  });
  kanban.addEventListener('dragend', () => {
    arrastrando?.classList.remove('dragging');
    kanban.querySelectorAll('.kb-cards').forEach((c) => c.classList.remove('drag-over'));
  });
  kanban.querySelectorAll('.kb-cards').forEach((zona) => {
    zona.addEventListener('dragover', (e) => { e.preventDefault(); zona.classList.add('drag-over'); });
    zona.addEventListener('dragleave', () => zona.classList.remove('drag-over'));
    zona.addEventListener('drop', (e) => {
      e.preventDefault();
      zona.classList.remove('drag-over');
      if (!arrastrando || zona === origen) return;
      const card = arrastrando;
      const previo = origen;
      const nuevoEstado = zona.dataset.estadoDrop;
      zona.appendChild(card);                 // movimiento optimista
      card.classList.add('kb-guardando');     // feedback (pulso), sin recargar
      guardarEstadoTarea(card.dataset.tarea, nuevoEstado).then((res) => {
        card.classList.remove('kb-guardando');
        if (res.ok) {
          MC?.toast?.('Estado actualizado', 'success', 1600);
        } else {
          previo.appendChild(card);           // revertir si falló
          MC?.toast?.(res.error || 'No se pudo mover la tarea', 'error');
        }
      });
    });
  });

  /* Mover RÁPIDO sin arrastrar: el botón "⋮" de la tarjeta abre un menú con las
     otras columnas; un clic la mueve. Imprescindible cuando una columna tiene
     muchas tarjetas y arrastrar hasta otra es un suplicio. */
  const kbEstados = (() => { try { return JSON.parse(kanban.dataset.estados || '[]'); } catch (_) { return []; } })();
  const cerrarMenus = () => kanban.querySelectorAll('.kb-menu').forEach((m) => m.remove());

  const moverTarjeta = (card, est) => {
    const destino = kanban.querySelector('.kb-cards[data-estado-drop="' + est.k + '"]');
    if (!destino) return;
    const previo = card.parentElement;
    destino.prepend(card);
    card.classList.add('kb-guardando');
    guardarEstadoTarea(card.dataset.tarea, est.k).then((res) => {
      card.classList.remove('kb-guardando');
      if (res.ok) MC?.toast?.('Movida a «' + est.label + '»', 'success', 1400);
      else { previo.appendChild(card); MC?.toast?.(res.error || 'No se pudo mover la tarea', 'error'); }
    });
  };

  kanban.addEventListener('click', (e) => {
    const btn = e.target.closest('.kb-mover');
    if (!btn) return;
    e.stopPropagation();                 // no abrir el detalle de la tarjeta
    const yaAbierto = btn.parentElement.querySelector('.kb-menu');
    cerrarMenus();
    if (yaAbierto) return;               // segundo clic: cerrar
    const card = btn.closest('.kb-card');
    const actual = card.closest('.kb-cards')?.dataset.estadoDrop;
    const menu = document.createElement('div');
    menu.className = 'kb-menu';
    menu.innerHTML = '<span class="kb-menu-tit">Mover a…</span>';
    kbEstados.filter((s) => s.k !== actual).forEach((s) => {
      const op = document.createElement('button');
      op.type = 'button';
      op.className = 'kb-menu-op';
      op.innerHTML = (s.svg || '') + ' ' + s.label;
      op.addEventListener('click', (ev) => { ev.stopPropagation(); cerrarMenus(); moverTarjeta(card, s); });
      menu.appendChild(op);
    });
    btn.parentElement.appendChild(menu);
  });
  document.addEventListener('click', cerrarMenus);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrarMenus(); });

  /* Auto-scroll al arrastrar cerca del borde superior/inferior de la ventana:
     sin esto, con muchas tarjetas no se alcanza la columna destino. */
  let autoScroll = 0, rafId = null;
  const tick = () => { if (autoScroll) window.scrollBy(0, autoScroll); rafId = requestAnimationFrame(tick); };
  const pararScroll = () => { autoScroll = 0; if (rafId) { cancelAnimationFrame(rafId); rafId = null; } };
  kanban.addEventListener('dragstart', () => { if (!rafId) rafId = requestAnimationFrame(tick); });
  kanban.addEventListener('dragover', (e) => {
    const m = 90, paso = 20;
    autoScroll = e.clientY < m ? -paso : (e.clientY > window.innerHeight - m ? paso : 0);
  });
  kanban.addEventListener('dragend', pararScroll);
  kanban.addEventListener('drop', pararScroll);
}

// Tabla: el select de estado también guarda por AJAX (no recarga la página)
document.addEventListener('submit', (e) => {
  const form = e.target;
  const acc = form.querySelector?.('input[name="accion"]');
  if (!acc || acc.value !== 'tarea_estado' || form.id === 'frm-kanban') return;
  const id = form.querySelector('input[name="id"]')?.value;
  const estado = form.querySelector('[name="estado"]')?.value;
  if (!id || !estado) return;
  e.preventDefault();
  const sel = form.querySelector('[name="estado"]');
  const previo = [...(sel?.classList || [])].find((c) => c.startsWith('estado-'));
  guardarEstadoTarea(id, estado).then((res) => {
    // Recolorear la píldora: sin recarga, la clase estado-* quedaría en el valor viejo.
    if (res.ok && sel) {
      if (previo) sel.classList.remove(previo);
      sel.classList.add('estado-' + estado);
    } else if (!res.ok && sel && previo) {
      sel.value = previo.slice('estado-'.length);   // revertir lo que no se guardó
    }
    MC?.toast?.(res.ok ? 'Estado actualizado' : (res.error || 'No se pudo cambiar'), res.ok ? 'success' : 'error', res.ok ? 1600 : 4000);
  });
});

// Paginador de la tabla de tareas (8 por página).
//
// Pagina sobre las filas que PASAN EL FILTRO, no sobre todas. Antes repartía
// por el índice en la lista completa, así que al buscar solo salían las
// coincidencias que caían en la página en la que estabas: el contador decía
// "4" y en pantalla había 2. Se recalcula en cada búsqueda (evento
// 'tabla-filtrada') y se vuelve a la página 1.
const cuerpoTabla = document.querySelector('[data-vista-panel="tabla"] .tabla-meca tbody');
if (cuerpoTabla) {
  // La fila de "ninguna tarea coincide" vive en el mismo tbody y no es una
  // tarea: no cuenta para las páginas.
  const filas = [...cuerpoTabla.rows].filter((f) => !f.hasAttribute('data-no-buscar'));
  const porPagina = 8;
  if (filas.length > porPagina) {
    const cont = document.createElement('div');
    cont.className = 'paginador';
    document.querySelector('[data-vista-panel="tabla"] .tabla-scroll').after(cont);
    let pagina = 1;
    const pintar = () => {
      const visibles = filas.filter((f) => !f.classList.contains('fila-oculta'));
      const totalPaginas = Math.max(1, Math.ceil(visibles.length / porPagina));
      if (pagina > totalPaginas) pagina = totalPaginas;

      filas.forEach((f) => { f.style.display = 'none'; });
      visibles.slice((pagina - 1) * porPagina, pagina * porPagina)
              .forEach((f) => { f.style.display = ''; });

      // Con los resultados en una sola página, el paginador estorba
      cont.hidden = visibles.length <= porPagina;
      let html = '<button type="button" class="pg-btn" data-pg="prev" ' + (pagina === 1 ? 'disabled' : '') + '><i class="fa-solid fa-chevron-left"></i></button>';
      for (let p = 1; p <= totalPaginas; p++) {
        html += '<button type="button" class="pg-btn ' + (p === pagina ? 'active' : '') + '" data-pg="' + p + '">' + p + '</button>';
      }
      html += '<button type="button" class="pg-btn" data-pg="next" ' + (pagina === totalPaginas ? 'disabled' : '') + '><i class="fa-solid fa-chevron-right"></i></button>';
      cont.innerHTML = html;
    };
    cont.addEventListener('click', (e) => {
      const btn = e.target.closest('.pg-btn');
      if (!btn || btn.disabled) return;
      if (btn.dataset.pg === 'prev') pagina--;
      else if (btn.dataset.pg === 'next') pagina++;
      else pagina = parseInt(btn.dataset.pg, 10);
      pintar();
    });
    // Al buscar, se reparte de nuevo desde la primera página
    cuerpoTabla.addEventListener('tabla-filtrada', () => { pagina = 1; pintar(); });
    pintar();
  }
}

// Abrir modales por hash (ej. equipo.php#nuevo-colaborador)
if (location.hash === '#nuevo-colaborador') {
  const dlg = document.getElementById('dlg-nuevo-miembro');
  if (dlg) dlg.showModal();
}

/* ---------- Detalle de tarea (solo lectura, para cualquiera) ---------- */
function abrirDetalleTarea(t) {
  const esc = (x) => String(x == null ? '' : x).replace(/[&<>"]/g,
    (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const dlg = document.getElementById('dlg-detalle-tarea');
  if (!dlg) return;
  const set = (sel, val) => { const el = dlg.querySelector(sel); if (el) el.textContent = val; };

  set('.dt-proyecto', t.proyecto || '');
  set('.dt-titulo', t.titulo || '');

  const desc = dlg.querySelector('.dt-desc');
  // La descripción es HTML ya saneado en el servidor (tablas, formato): innerHTML.
  if (desc) { desc.innerHTML = t.descripcion || ''; desc.hidden = !t.descripcion; }

  const chips = dlg.querySelector('.dt-chips');
  if (chips) {
    // Reutiliza los badges de color del tablero (estado y prioridad)
    chips.innerHTML = (t.estado_badge || '') + (t.prio_badge || '');
  }

  dtPintarResponsables(dlg, t.responsables || (t.asignados || []).map((n) => ({ nombre: n, colabora: false })));
  dtPrepararColab(dlg, t);

  let fechas = '';
  if (t.fecha_inicio && t.fecha_limite) {
    const d = Math.round((new Date(t.fecha_limite + 'T12:00') - new Date(t.fecha_inicio + 'T12:00')) / 86400000);
    fechas = 'Del ' + t.fecha_inicio + ' al ' + t.fecha_limite + (d >= 0 ? ' · ' + (d + 1) + ' días' : '');
  } else if (t.fecha_limite) { fechas = 'Límite: ' + t.fecha_limite; }
  else if (t.fecha_inicio) { fechas = 'Arranca: ' + t.fecha_inicio; }
  else { fechas = 'Sin fechas'; }
  set('.dt-fechas', fechas);

  // Carga cruzada: requerimientos sueltos que le cayeron a la misma persona en
  // esas fechas. Explica por qué la tarea puede ir lenta sin tener que
  // preguntar; el servidor ya cruzó los rangos.
  const carga = dlg.querySelector('.dt-carga');
  if (carga) {
    const lista = t.carga_extra || [];
    carga.hidden = !lista.length;
    carga.innerHTML = lista.length
      ? '<b>También tiene trabajo en paralelo en estas fechas</b><ul>' + lista.map((c) =>
          '<li>' + esc(c.persona) + ' — <i>' + esc(c.origen || '') + '</i>: «' + esc(c.titulo) +
          '» del ' + esc(c.ini) + ' al ' + esc(c.fin) + '</li>'
        ).join('') + '</ul>'
      : '';
  }

  // Cuánto queda (o cuánto lleva vencida): es lo primero que uno mira
  const restante = dlg.querySelector('.dt-restante');
  if (restante) {
    const lim = t.fecha_limite && !t.final ? new Date(t.fecha_limite + 'T12:00') : null;
    const hoy = new Date(); hoy.setHours(12, 0, 0, 0);
    const dd = lim ? Math.round((lim - hoy) / 86400000) : null;
    restante.hidden = dd === null;
    if (dd !== null) {
      restante.textContent = dd > 1 ? 'Faltan ' + dd + ' días'
        : dd === 1 ? 'Falta 1 día'
        : dd === 0 ? 'Vence hoy'
        : dd === -1 ? 'Venció ayer' : 'Venció hace ' + (-dd) + ' días';
      restante.classList.toggle('urgente', dd <= 0);
    }
  }

  const filaCreada = dlg.querySelector('.dt-fila-creada');
  if (filaCreada) {
    // Quién la anotó y cuándo; las tareas de
    // antes de guardar el autor solo traen la fecha.
    const ag = t.agrego;
    const cuando = t.creado_txt || t.creado || '';
    filaCreada.hidden = !ag && !cuando;
    dlg.querySelector('.dt-creada').innerHTML = ag
      ? '<span class="dt-autor">' + (ag.avatar || '') + '<span class="dt-autor__txt"><b>' + esc(ag.nombre) + '</b>'
        + '<small>' + esc(cuando) + (ag.origen ? ' · ' + esc(ag.origen) : '') + '</small></span></span>'
      : esc(cuando);
  }

  const filaDep = dlg.querySelector('.dt-fila-dep');
  if (filaDep) {
    const deps = t.deps || [];
    filaDep.hidden = deps.length === 0;
    dlg.querySelector('.dt-dep').innerHTML = deps.map((d) => {
      const urg = d.urgencia === 'vencida' ? '<span class="dt-dep-urg venc"><i class="fa-solid fa-triangle-exclamation"></i> vencida</span>'
                : d.urgencia === 'proxima' ? '<span class="dt-dep-urg prox"><i class="fa-regular fa-clock"></i> próxima a vencer</span>' : '';
      const eq = d.externa
        ? '<span class="dt-dep-eq" style="--dc:' + esc(d.color) + '">' + (d.icono || '') + ' ' + esc(d.equipo) + '</span>'
        : '<span class="dt-dep-eq propia"><i class="fa-solid fa-link"></i> este tablero</span>';
      const est = d.final ? '<i class="fa-solid fa-check dt-dep-ok" title="Ya completada"></i>' : '';
      const btn = d.externa
        ? '<button type="button" class="dt-dep-recordar" data-tarea-id="' + t.id + '" data-dep-id="' + d.id + '"' +
          ' data-dep-titulo="' + esc(d.titulo) + '" data-dep-eq="' + esc(d.equipo) + '"' +
          ' data-dep-avisar="' + esc((d.avisar || []).join(', ')) + '">' +
          '<i class="fa-solid fa-bell"></i> Recordar</button>'
        : '';
      return '<div class="dt-dep-item' + (d.externa ? ' externo' : '') + '" style="--dc:' + esc(d.color || '#64748b') + '">' +
               '<div class="dt-dep-l">' + eq + ' <span class="dt-dep-t">' + esc(d.titulo) + '</span> ' + est + ' ' + urg + '</div>' +
               btn +
             '</div>';
    }).join('');
  }
  const filaObs = dlg.querySelector('.dt-fila-obs');
  if (filaObs) {
    filaObs.hidden = !t.obs;
    if (t.obs) set('.dt-obs', t.obs + ' observación' + (t.obs === 1 ? '' : 'es') + ' pendiente' + (t.obs === 1 ? '' : 's'));
  }
  // Documentos de respaldo: se listan a la izquierda y el primero ya se
  // previsualiza a la derecha, sin tener que pulsar nada.
  const filaAdj = dlg.querySelector('.dt-fila-adj');
  if (filaAdj) {
    dtAdjuntos = t.adjuntos || [];
    filaAdj.hidden = dtAdjuntos.length === 0;
    const cajaAdj = dlg.querySelector('.dt-adjuntos');
    cajaAdj.innerHTML = dtAdjuntos.map((a, i) => {
      const ver = previsualizable(a.ext);
      // El que no se puede previsualizar es un enlace de descarga normal
      return '<a class="adj-chip' + (ver ? '' : ' adj-bajar') + '" href="' + esc(a.ruta) + '"' +
        (ver ? ' data-i="' + i + '"' : ' download') + ' target="_blank" rel="noopener"' +
        ' title="' + (ver ? 'Ver ' : 'Descargar ') + esc(a.nombre) + '">' +
        '<i class="fa-solid ' + iconoAdjunto(a.ext) + '"></i><span>' + esc(a.nombre) + '</span>' +
        '<i class="fa-solid ' + (ver ? 'fa-eye' : 'fa-download') + ' adj-chip-acc"></i></a>';
    }).join('');

    // La previsualización solo aparece si hay algo que enseñar
    const verIdx = dtAdjuntos.findIndex((a) => previsualizable(a.ext));
    dlg.classList.toggle('dt-con-docs', verIdx >= 0);
    dlg.querySelector('.dt-previa').hidden = verIdx < 0;
    dlg.querySelector('.dt-previa-cuerpo').innerHTML = '';
    if (verIdx >= 0) dtVerDoc(verIdx);
  }
  // Los pasos de la trazabilidad (historia, impedimentos, release, commits) se llenan al llegar
  const marca = dlg.querySelector('.wz-marca h3');
  if (marca) marca.textContent = 'Tarea #' + t.id;
  dtTraza(dlg, t.id);
  dlg.showModal();
}

/* ---------- Responsables y «Pedir ayuda» en la ficha de la tarea ---------- */
function dtPintarResponsables(dlg, lista) {
  const dd = dlg.querySelector('.dt-asignados');
  if (!dd) return;
  dd.innerHTML = lista.length
    ? lista.map((r) => esc(r.nombre) + (r.colabora ? ' <span class="dt-colabora" title="Se sumó para ayudar">ayuda</span>' : '')).join(', ')
    : 'Sin asignar';
}

// Quien tiene la tarea (o gestiona el proyecto) suma a alguien de la gente del proyecto
function dtPrepararColab(dlg, t) {
  const fila = dlg.querySelector('.dt-fila-colab');
  if (!fila) return;
  let gente = [];
  try { gente = JSON.parse(document.getElementById('dt-gente')?.textContent || '[]'); } catch (_) { gente = []; }
  const ya = new Set((t.asignados_ids || []).map(Number));
  const libres = gente.filter((g) => !ya.has(+g.id));
  fila.hidden = !t.puede_colab || !libres.length;
  fila.dataset.tarea = t.id;
  fila.querySelector('[data-dt-colab]').hidden = true;
  fila.querySelector('[data-dt-colab-abrir]').hidden = false;
  fila.querySelector('[data-dt-colab-quien]').innerHTML = '<option value="">Elige a la persona…</option>'
    + libres.map((g) => '<option value="' + (+g.id) + '">' + esc(g.nombre) + '</option>').join('');
  fila.querySelector('[data-dt-colab-nota]').value = '';
}

document.addEventListener('click', async (e) => {
  const fila = e.target.closest('.dt-fila-colab');
  if (!fila) return;
  const caja = fila.querySelector('[data-dt-colab]');
  const abrir = fila.querySelector('[data-dt-colab-abrir]');
  if (e.target.closest('[data-dt-colab-abrir]')) {
    caja.hidden = false; abrir.hidden = true;
    fila.querySelector('[data-dt-colab-quien]').focus();
    return;
  }
  if (e.target.closest('[data-dt-colab-cerrar]')) { caja.hidden = true; abrir.hidden = false; return; }
  const btn = e.target.closest('[data-dt-colab-sumar]');
  if (!btn) return;
  const quien = fila.querySelector('[data-dt-colab-quien]');
  if (!quien.value) { quien.focus(); if (window.MC && MC.toast) MC.toast('Elige a la persona.', 'warning'); return; }
  const fd = new FormData();
  fd.set('accion', 'tarea_colaborador');
  fd.set('ajax', '1');
  fd.set('id', fila.dataset.tarea);
  fd.set('colaborador', quien.value);
  fd.set('nota', fila.querySelector('[data-dt-colab-nota]').value);
  btn.disabled = true;
  let j = null;
  try { j = await (await fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin' })).json(); } catch (_) { j = null; }
  btn.disabled = false;
  if (!j || !j.ok) { if (window.MC && MC.toast) MC.toast(j ? j.mensaje : 'No se pudo: revisa tu conexión.', 'error'); return; }
  if (window.MC && MC.toast) MC.toast(j.mensaje, 'success');
  const dlg = fila.closest('dialog');
  const colabs = new Set((j.colaboradores || []).map(Number));
  dtPintarResponsables(dlg, (j.asignados || []).map((n, i) => ({ nombre: n, colabora: colabs.has(+(j.asignados_ids || [])[i]) })));
  dtPrepararColab(dlg, { id: fila.dataset.tarea, asignados_ids: j.asignados_ids || [], puede_colab: true });
  dtTraza(dlg, fila.dataset.tarea);
  dlg.dataset.recargar = '1';   // al cerrar, el tablero se pone al día
});
document.getElementById('dlg-detalle-tarea')?.addEventListener('close', (e) => {
  if (e.target.dataset.recargar === '1') location.reload();
});

/* ---------- Trazabilidad de la ficha (pasos 2 a 5): la trae tarea_traza.php ---------- */
let dtTrazaPedido = 0;
const DT_MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
// «21 oct 2026, 4:34 pm»: las fechas con zona (los commits) se pasan a la hora local
function dtFecha(s) {
  if (!s) return '';
  let d = null;
  if (/(Z|[+-]\d\d:?\d\d)$/.test(s)) d = new Date(s);
  else {
    const m = /^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/.exec(s);
    if (!m) return s;
    d = new Date(+m[1], +m[2] - 1, +m[3], m[4] ? +m[4] : 0, m[5] ? +m[5] : 0);
    if (!m[4]) return d.getDate() + ' ' + DT_MESES[d.getMonth()] + ' ' + d.getFullYear();
  }
  if (isNaN(d)) return s;
  const h = d.getHours() % 12 || 12;
  return d.getDate() + ' ' + DT_MESES[d.getMonth()] + ' ' + d.getFullYear() + ', ' + h + ':' + String(d.getMinutes()).padStart(2, '0') + ' ' + (d.getHours() >= 12 ? 'pm' : 'am');
}

function dtTraza(dlg, id) {
  const cajas = {};
  dlg.querySelectorAll('[data-dt-traza]').forEach((c) => {
    cajas[c.dataset.dtTraza] = c;
    c.innerHTML = '<p class="dt-traza-vacio"><i class="fa-solid fa-spinner fa-spin"></i> Cargando…</p>';
  });
  dlg.querySelectorAll('.wz-paso .dt-cuenta').forEach((x) => x.remove());
  const pedido = ++dtTrazaPedido;
  fetch('tarea_traza.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })
    .then((r) => r.json())
    .then((d) => { if (pedido === dtTrazaPedido) dtPintarTraza(dlg, cajas, d, id); })
    .catch(() => {
      if (pedido !== dtTrazaPedido) return;
      Object.values(cajas).forEach((c) => { c.innerHTML = '<p class="dt-traza-vacio">No se pudo cargar. Cierra la tarea y vuelve a abrirla.</p>'; });
    });
}

function dtPintarTraza(dlg, cajas, d, id) {
  if (d.error) { Object.values(cajas).forEach((c) => { c.innerHTML = '<p class="dt-traza-vacio">' + esc(d.error) + '</p>'; }); return; }
  const vacio = (txt) => '<p class="dt-traza-vacio">' + txt + '</p>';
  // Un número junto al nombre del paso, en el riel
  const cuenta = (i, n, alerta) => {
    const b = dlg.querySelectorAll('.wz-paso')[i]?.querySelector('b');
    if (b && n) b.insertAdjacentHTML('beforeend', ' <em class="dt-cuenta' + (alerta ? ' is-alerta' : '') + '">' + n + '</em>');
  };

  // 2. Historia: plazos (lo planificado y lo real) y la línea de tiempo
  const plazo = (rot, val, nota) => '<div><span>' + rot + '</span><b>' + (val ? esc(val) : '<i>' + nota + '</i>') + '</b></div>';
  const plan = d.inicio_plan || d.fin_plan ? (d.inicio_plan ? dtFecha(d.inicio_plan) : '—') + ' → ' + (d.fin_plan ? dtFecha(d.fin_plan) : '—') : '';
  const motivo = (m) => m === 'intercambio' ? 'por un intercambio' : m.startsWith('commit ') ? 'por el ' + m : m;
  const ICONOS = { creada: 'fa-plus', estado: 'fa-arrow-right', asignados: 'fa-user-group', intercambio: 'fa-right-left', colaborador: 'fa-user-plus',
                   fechas: 'fa-calendar', prioridad: 'fa-flag', titulo: 'fa-pen' };
  cajas.historia.innerHTML = '<div class="dt-plazos">' + plazo('Planificada', plan, 'sin fechas') + plazo('Empezó', dtFecha(d.inicio_real), 'todavía no')
    + plazo('Terminó', dtFecha(d.fin_real), 'todavía no') + '</div>'
    + '<ol class="dt-linea">' + (d.historia || []).map((h) =>
      '<li class="dt-ev dt-ev--' + esc(h.tipo) + '"><span class="dt-ev__ico"><i class="fa-solid ' + (ICONOS[h.tipo] || 'fa-circle') + '"></i></span>'
      + '<div><b>' + esc(h.texto) + '</b><small>' + [dtFecha(h.t), h.por ? 'por ' + esc(h.por) : '', h.motivo ? motivo(esc(h.motivo)) : ''].filter(Boolean).join(' · ') + '</small></div></li>'
    ).join('') + '</ol>';

  // 3. Impedimentos: las observaciones de la tarea
  const imp = d.impedimentos || [];
  const pend = imp.filter((o) => !o.resuelta).length;
  cuenta(2, pend, true);
  cajas.impedimentos.innerHTML = imp.length ? '<ul class="dt-imp">' + imp.map((o) =>
    '<li class="dt-imp__item' + (o.resuelta ? ' is-resuelta' : '') + '">'
    + '<span class="dt-imp__estado">' + (o.resuelta ? '<i class="fa-solid fa-check"></i> Resuelta' : '<i class="fa-solid fa-triangle-exclamation"></i> Pendiente') + '</span>'
    + '<p>' + esc(o.texto) + '</p>'
    + '<small>' + [o.por ? 'De ' + esc(o.por) : '', o.para && o.para !== 'nadie' ? 'para ' + esc(o.para) : '', dtFecha(o.creado),
        o.resuelta && o.resuelto_en ? 'resuelta el ' + dtFecha(o.resuelto_en) : '', o.respuestas ? o.respuestas + ' respuesta' + (o.respuestas === 1 ? '' : 's') : ''].filter(Boolean).join(' · ') + '</small></li>'
  ).join('') + '</ul>' : vacio('Sin observaciones: nadie le puso impedimentos.');

  // 4. Release: el ítem al que suma
  const rel = d.release || [];
  cajas.release.innerHTML = rel.length ? rel.map((r) =>
    '<article class="dt-rel">'
    + '<div class="dt-rel__cab"><span class="dt-rel__cod">' + esc(r.codigo) + '</span><b>' + esc(r.titulo) + '</b></div>'
    + '<p class="dt-rel__meta">' + esc(r.release) + ' · equipo ' + esc(r.equipo) + (r.inicio || r.fin ? ' · ' + dtFecha(r.inicio) + ' → ' + dtFecha(r.fin) : '') + '</p>'
    + '<div class="dt-rel__av"><span class="dt-rel__barra"><i style="width:' + (+r.avance) + '%"></i></span><b>' + (+r.avance) + ' %</b><small>' + esc(r.estado) + (r.salud ? ' · ' + esc(r.salud) : '') + '</small></div>'
    + (r.url ? '<a class="btn-outline btn-meca btn-sm btn-azul" href="' + esc(r.url) + '"><i class="fa-solid fa-route"></i> Abrir en Releases</a>' : '')
    + '</article>'
  ).join('') : vacio('No está amarrada a ningún ítem del release. Se amarra al editarla, en «Ítem del release».');

  // 5. Commits con su #id
  const cm = d.commits || [];
  cuenta(4, cm.length, false);
  cajas.commits.innerHTML = cm.length ? '<ul class="dt-commits">' + cm.map((c) =>
    '<li><a class="dt-commit" href="' + esc(c.url) + '" target="_blank" rel="noopener"><code>' + esc(c.sha) + '</code>'
    + '<span class="dt-commit__msg">' + esc(c.msg) + '</span><small>' + [esc(c.autor), esc(c.repo), dtFecha(c.fecha)].filter(Boolean).join(' · ') + '</small></a></li>'
  ).join('') + '</ul>' : vacio('Ningún commit menciona #' + id + ' en los últimos seis meses. Para enlazarlos, el mensaje del commit termina con <code>#' + id + '</code>.');
}
// Enlace directo a una tarea (proyecto.php?id=…&tarea=…, p. ej. desde Releases): se abre su ficha
// y el número sale de la dirección, así recargar no la vuelve a abrir. Espera a que
// cargue todo el archivo: la ficha usa cosas que se declaran más abajo.
function abrirTareaEnlazada() {
  const el = document.getElementById('tarea-abrir');
  if (!el) return;
  try { abrirDetalleTarea(JSON.parse(el.dataset.verTarea)); } catch (_) { return; }
  const u = new URL(location.href);
  u.searchParams.delete('tarea');
  history.replaceState(null, '', u.pathname + u.search + u.hash);
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', abrirTareaEnlazada);
else setTimeout(abrirTareaEnlazada, 0);

// Escapa para texto Y para atributos. Ojo con las comillas: sin ellas, un
// nombre de archivo o un mensaje de commit que las lleve rompe el atributo
// donde se interpola (que es como se perdían los datos de los adjuntos).
function esc(s) {
  return String(s ?? '')
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

// Icono por extensión (mismo criterio que iconoAdjunto() en PHP)
function iconoAdjunto(ext) {
  const e = String(ext || '').toLowerCase();
  if (e === 'pdf') return 'fa-file-pdf';
  if (e === 'doc' || e === 'docx') return 'fa-file-word';
  if (e === 'xls' || e === 'xlsx' || e === 'csv') return 'fa-file-excel';
  if (e === 'ppt' || e === 'pptx') return 'fa-file-powerpoint';
  if (e === 'txt') return 'fa-file-lines';
  if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(e)) return 'fa-file-image';
  return 'fa-paperclip';
}

/* ---------- Avisar al equipo: a quién y con qué tareas ---------- */
document.querySelectorAll('[data-avisar]').forEach((caja) => {
  const form = caja.closest('form');
  const resumen = form.querySelector('.av-resumen');
  const enviar = form.querySelector('button[type="submit"], .btn-primary');

  const contar = () => {
    let personas = 0, tareas = 0;
    caja.querySelectorAll('.av-persona').forEach((p) => {
      const marcadas = p.querySelectorAll('.av-tareas input:checked').length;
      if (marcadas) { personas++; tareas += marcadas; }
      // El "todas" de la persona refleja el estado real de sus tareas
      const todo = p.querySelector('.av-todo');
      const total = p.querySelectorAll('.av-tareas input:not(:disabled)').length;
      if (todo && !todo.disabled) {
        todo.checked = marcadas > 0;
        todo.indeterminate = marcadas > 0 && marcadas < total;
      }
      p.classList.toggle('av-fuera', total > 0 && marcadas === 0);
    });
    resumen.textContent = personas === 0
      ? 'Nada marcado: no se enviará ningún correo'
      : personas + (personas === 1 ? ' correo' : ' correos') + ' · ' + tareas + (tareas === 1 ? ' tarea' : ' tareas');
    resumen.classList.toggle('av-nada', personas === 0);
    if (enviar) enviar.disabled = personas === 0;
  };

  caja.addEventListener('change', (e) => {
    // La casilla de la persona marca o desmarca todas las suyas
    if (e.target.classList.contains('av-todo')) {
      const on = e.target.checked;
      e.target.closest('.av-persona').querySelectorAll('.av-tareas input:not(:disabled)')
        .forEach((c) => { c.checked = on; });
    }
    contar();
  });

  // Las tareas van plegadas: con diez por persona, la lista entera abierta era
  // un muro. Se despliega solo a quien quieras afinar.
  caja.addEventListener('click', (e) => {
    const btn = e.target.closest('.av-abrir');
    if (!btn) return;
    const p = btn.closest('.av-persona');
    const abierto = p.classList.toggle('av-abierta');
    p.querySelector('.av-tareas').hidden = !abierto;
    btn.title = abierto ? 'Ocultar sus tareas' : 'Ver y elegir sus tareas';
  });

  // Marcar todo / ninguno
  form.querySelectorAll('[data-marcar]').forEach((b) => {
    b.addEventListener('click', () => {
      const on = b.dataset.marcar === '1';
      caja.querySelectorAll('input[type="checkbox"]:not(:disabled)').forEach((c) => { c.checked = on; });
      contar();
    });
  });

  contar();
});

/* ---------- Previsualización de un documento ---------- */
// Imágenes, PDF y texto se ven en el panel. Word/Excel/PowerPoint no se pueden
// mostrar sin mandar el archivo a un servicio de terceros, así que en esos se
// ofrece la descarga y se dice por qué.
// Solo el PDF se previsualiza. Todo lo demás —imágenes incluidas— se descarga
// desde su chip: menos elementos moviéndose en la ficha y una regla sola.
/* Qué se puede ver sin descargar: el PDF en un iframe y las imágenes tal
   cual. Lo demás (doc, docx) no lo pinta el navegador, así que se baja. */
const IMAGENES_VISIBLES = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'svg'];
const esImagen = (ext) => IMAGENES_VISIBLES.includes(String(ext || '').toLowerCase());
const previsualizable = (ext) => String(ext || '').toLowerCase() === 'pdf' || esImagen(ext);

function pintarPrevia(cont, a) {
  if (!cont || !a || !previsualizable(a.ext)) return;
  cont.innerHTML = esImagen(a.ext)
    ? '<img class="adj-visor-img" src="' + esc(a.ruta) + '" alt="' + esc(a.nombre || '') + '">'
    : '<iframe class="adj-visor-frame" src="' + esc(a.ruta) + '#view=FitH" title="' + esc(a.nombre || '') + '"></iframe>';
}


/* ---------- Previsualización dentro del detalle de la tarea ---------- */
let dtAdjuntos = [], dtIdx = 0;

function dtVerDoc(i) {
  const dlg = document.getElementById('dlg-detalle-tarea');
  const a = dtAdjuntos[i];
  if (!dlg || !a || !previsualizable(a.ext)) return;
  dtIdx = i;
  dlg.querySelectorAll('.dt-adjuntos .adj-chip').forEach((el) =>
    el.classList.toggle('activo', parseInt(el.dataset.i, 10) === i));
  dlg.querySelector('.dt-previa-nom b').textContent = a.nombre || '';
  dlg.querySelector('.dt-previa-nom i').className = 'fa-solid ' + iconoAdjunto(a.ext);
  // Sin botón de descarga aquí: el visor de PDF ya trae el suyo
  pintarPrevia(dlg.querySelector('.dt-previa-cuerpo'), a);
}

const dlgDetalle = document.getElementById('dlg-detalle-tarea');
if (dlgDetalle) {
  // Al cerrar se descarga el visor: si no, el PDF se queda vivo por detrás
  dlgDetalle.addEventListener('close', () => {
    dlgDetalle.querySelector('.dt-previa-cuerpo').innerHTML = '';
    dtAdjuntos = [];
  });
  dlgDetalle.addEventListener('click', (e) => {
    // Pulsar un documento cambia el de la derecha; con Ctrl/Cmd, pestaña nueva
    const chip = e.target.closest('.dt-adjuntos .adj-chip[data-i]');
    if (!chip || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
    e.preventDefault();
    dtVerDoc(parseInt(chip.dataset.i, 10));
  });
}

/* ---------- Documentos de respaldo de una tarea (campo de los modales) ---------- */
document.querySelectorAll('[data-adjuntos-tarea]').forEach((campo) => {
  const lista  = campo.querySelector('.adj-lista');
  const nuevos = campo.querySelector('.adj-nuevos');
  const input  = campo.querySelector('input[type="file"]');

  // Los que ya tiene la tarea: se marcan para quitar, y solo se borran al
  // guardar. Aquí NO se abre el visor (esto es el formulario de edición): el
  // nombre abre el archivo en una pestaña y ya está.
  campo.pintarAdjuntos = (adj) => {
    const items = adj || [];
    lista.hidden = items.length === 0;
    lista.innerHTML = items.map((a) =>
      '<li class="adj-item"><i class="fa-solid ' + iconoAdjunto(a.ext) + '"></i>' +
      '<a href="' + esc(a.ruta) + '" target="_blank" rel="noopener"' +
      ' title="Abrir ' + esc(a.nombre) + ' en una pestaña">' + esc(a.nombre) + '</a>' +
      '<input type="checkbox" name="quitar_adjunto[]" value="' + esc(a.ruta) + '" hidden>' +
      '<button type="button" class="adj-quitar" title="Quitar al guardar">' +
      '<i class="fa-solid fa-xmark"></i></button></li>').join('');
  };
  campo.pintarAdjuntos([]);

  lista.addEventListener('click', (e) => {
    const btn = e.target.closest('.adj-quitar');
    if (!btn) return;
    const li = btn.closest('.adj-item');
    const chk = li.querySelector('input[type="checkbox"]');
    chk.checked = !chk.checked;
    li.classList.toggle('adj-fuera', chk.checked);
    btn.title = chk.checked ? 'Recuperar' : 'Quitar al guardar';
    btn.innerHTML = '<i class="fa-solid ' + (chk.checked ? 'fa-rotate-left' : 'fa-xmark') + '"></i>';
  });

  // El input nativo REEMPLAZA lo elegido en cada pasada, así que eligiendo de
  // uno en uno solo sobrevivía el último. Se acumulan aparte y se le devuelven
  // al input, que es lo que se envía.
  const cola = new DataTransfer();
  const peso = (n) => (n < 1024 * 1024 ? Math.max(1, Math.round(n / 1024)) + ' KB'
                                       : (n / 1048576).toFixed(1) + ' MB');

  const pintarNuevos = () => {
    // Se pinta lo que el input lleva de verdad, no la cola: si algún navegador
    // no dejara reescribir input.files, la lista no promete lo que no se envía.
    const arch = [...input.files];
    nuevos.hidden = arch.length === 0;
    nuevos.innerHTML = arch.map((f, i) =>
      '<li class="adj-item adj-nuevo"><i class="fa-solid ' + iconoAdjunto(f.name.split('.').pop()) + '"></i>' +
      '<span>' + esc(f.name) + '</span><small class="adj-peso">' + peso(f.size) + '</small>' +
      '<button type="button" class="adj-quitar" data-i="' + i + '" title="Quitar de la lista">' +
      '<i class="fa-solid fa-xmark"></i></button></li>').join('');
  };

  campo.limpiarNuevos = () => {
    while (cola.items.length) cola.items.remove(0);
    input.value = '';
    pintarNuevos();
  };

  const sumar = (archivos) => {
    archivos.forEach((f) => {
      // Mismo nombre y tamaño = el mismo archivo elegido dos veces
      const repetido = [...cola.files].some((x) => x.name === f.name && x.size === f.size);
      if (!repetido) cola.items.add(f);
    });
    input.files = cola.files;
    pintarNuevos();
  };
  input.addEventListener('change', () => sumar([...input.files]));

  // Arrastrar y soltar sobre la zona: entra en la misma cola que lo elegido a
  // mano. Solo los tipos que acepta el input (el servidor vuelve a mirarlo).
  const zona = campo.querySelector('.adj-zona');
  const tipos = (input.accept || '').split(',').map((t) => t.trim().toLowerCase()).filter(Boolean);
  const sirve = (f) => !tipos.length || tipos.includes('.' + f.name.split('.').pop().toLowerCase());
  if (zona) {
    zona.addEventListener('dragover', (e) => { e.preventDefault(); zona.classList.add('is-sobre'); });
    zona.addEventListener('dragleave', (e) => { if (!zona.contains(e.relatedTarget)) zona.classList.remove('is-sobre'); });
    zona.addEventListener('drop', (e) => {
      e.preventDefault();
      zona.classList.remove('is-sobre');
      sumar([...(e.dataTransfer?.files || [])].filter(sirve));
    });
  }

  nuevos.addEventListener('click', (e) => {
    const btn = e.target.closest('.adj-quitar');
    if (!btn) return;
    cola.items.remove(parseInt(btn.dataset.i, 10));
    input.files = cola.files;
    pintarNuevos();
  });
});

// "Recordar" en el detalle de una dependencia de otro equipo → abre el modal
// de recordatorio con el contexto y a quién le llegará.
document.addEventListener('click', (e) => {
  const b = e.target.closest('.dt-dep-recordar');
  if (!b) return;
  const dlg = document.getElementById('dlg-dep-recordar');
  if (!dlg) return;
  dlg.querySelector('#dr-tarea').value = b.dataset.tareaId || '';
  dlg.querySelector('#dr-dep').value = b.dataset.depId || '';
  dlg.querySelector('.dr-contexto').textContent =
    'Tu tarea espera por «' + (b.dataset.depTitulo || '') + '» del equipo ' + (b.dataset.depEq || 'otro equipo') + '.';
  const avisar = (b.dataset.depAvisar || '').trim();
  dlg.querySelector('.dr-para').innerHTML = '<i class="fa-solid fa-paper-plane"></i> Se avisará a: <b>' +
    (avisar ? esc(avisar) : 'los responsables y el Scrum Master de ese equipo') + '</b>';
  const detalle = document.getElementById('dlg-detalle-tarea');
  if (detalle && detalle.open) detalle.close();
  dlg.showModal();
});

// Clic en una tarea (fila, kanban, flujo, calendario) → detalle de solo lectura
document.addEventListener('click', (e) => {
  const el = e.target.closest('[data-ver-tarea]');
  if (!el) return;
  // No abrir si se clicó un control interno (estado, editar, borrar, enlaces),
  // salvo que ese control sea la tarjeta misma (el evento del calendario es button).
  const ctrl = e.target.closest('button, select, a, input, textarea, form, .ms, .md, .btn-copiar');
  if (ctrl && ctrl !== el && el.contains(ctrl)) return;
  try { abrirDetalleTarea(JSON.parse(el.dataset.verTarea)); } catch (_) {}
});

/* El flujo del estándar movió tareas según los commits (lo hace el servidor al
   traer los aportes). Refleja el nuevo estado en la tabla —sin re-guardar, porque
   fijar .value no dispara el onchange— y avisa con un toast. */
function aplicarMovidasEnTabla(movidas) {
  let n = 0;
  movidas.forEach((mv) => {
    const idi = [...document.querySelectorAll('form.inline-form input[name="id"]')]
      .find((el) => el.value == mv.tarea && el.form && el.form.querySelector('[name="accion"]') &&
                    el.form.querySelector('[name="accion"]').value === 'tarea_estado');
    if (idi) {
      const sel = idi.form.querySelector('select[name="estado"]');
      if (sel && sel.value !== mv.a) {
        sel.value = mv.a;
        sel.className = sel.className.replace(/\bestado-[a-z0-9_]+/gi, '').replace(/\s+/g, ' ').trim() + ' estado-' + mv.a;
      }
      const fila = idi.closest('.fila-tarea');
      if (fila) fila.classList.toggle('fila-hecha', mv.a === 'hecho');
    }
    // Kanban: mover la tarjeta a la columna del nuevo estado
    const card = document.querySelector('.kb-card[data-tarea="' + mv.tarea + '"]');
    const destino = document.querySelector('.kb-cards[data-estado-drop="' + mv.a + '"]');
    if (card && destino && card.parentElement !== destino) destino.appendChild(card);
    n++;
  });
  // Recontar las columnas del kanban tras mover
  document.querySelectorAll('.kb-cards[data-estado-drop]').forEach((z) => {
    const head = z.closest('.kb-col') ? z.closest('.kb-col').querySelector('.kb-count') : null;
    if (head) head.textContent = z.querySelectorAll('.kb-card').length;
  });
  if (n && window.MC && MC.toast) {
    MC.toast(n === 1 ? 'Una tarea avanzó según los commits.'
                     : n + ' tareas avanzaron según los commits.', 'success');
  }
}

/* ---------- Aportes del equipo: commits por persona (Métricas) ---------- */
document.querySelectorAll('[data-aportes]').forEach((caja) => {
  const dataEl = caja.querySelector('[data-aportes-data]');
  if (!dataEl) return;
  let data;
  try { data = JSON.parse(dataEl.textContent); } catch { return; }
  let commits = data.commits || [];
  const miembros = data.miembros || [];
  const tareas = data.tareas || {};
  const repos = data.repos || [];

  // A cada commit le asigna el miembro del panel cruzando por CUALQUIERA de sus
  // identidades de Git: usuario(s), correo, parte local del correo o nombre del
  // autor. Así una persona con varios usuarios (misma cuenta) igual suma sus
  // commits, y GitLab (cuyo login es la parte local del correo) también cuadra.
  const norm = (s) => (s || '').toLowerCase().trim().replace(/\s+/g, ' ');
  const porGit = {};
  miembros.forEach((m) => { (m.gits || (m.git ? [m.git] : [])).forEach((g) => { const k = norm(g); if (k) porGit[k] = m; }); });
  const mapear = (cs) => { cs.forEach((c) => {
    const local = (c.email || '').split('@')[0];
    c.miembro = porGit[norm(c.login)] || porGit[norm(c.email)] || porGit[norm(local)] || porGit[norm(c.nombre)] || null;
  }); };
  mapear(commits);

  const sel = caja.querySelector('.ap-persona');
  const selRama = caja.querySelector('.ap-rama');
  const lb = caja.querySelector('.ap-leaderboard');
  const mapas = caja.querySelector('.ap-mapas');
  const vacio = caja.querySelector('.ap-vacio');
  const totalEl = caja.querySelector('.ap-total');
  const cargandoEl = caja.querySelector('.ap-cargando');
  const skel = caja.querySelector('.ap-skeleton');
  const lazy = caja.dataset.aportesLazy === '1';
  const proyecto = caja.dataset.proyecto;
  const ramaWrap = selRama ? selRama.closest('.ms') : null;
  let cargado = false;
  let dias = 182;        // rango de fechas activo
  let ramaDefecto = '';  // la fijada en Editar proyecto → Repos (vacía = la del repo)
  let truncado = false;  // ¿se alcanzó el tope de commits que se leen del repo?
  let diasCargados = 0;  // rango que se pidió al servidor la última vez
  const selRepo = caja.querySelector('.ap-repo');   // filtro de repo (si hay varios)
  let ramasRepo = {}, ramasUnion = [];
  const repoSel = () => (selRepo ? selRepo.value : '');

  const esc = (s) => { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  const avatarHtml = (m, sz) => {
    if (!m) return '<span class="ap-av ap-av-x" style="--sz:' + sz + 'px">?</span>';
    if (m.foto) return '<span class="ap-av" style="--sz:' + sz + 'px"><img src="' + esc(m.foto) + '" alt=""></span>';
    return '<span class="ap-av" style="--sz:' + sz + 'px;--c:' + m.c + '">' + esc(m.ini) + '</span>';
  };
  const conTareas = (msg) => esc(msg).replace(/#(\d+)/g, (m, id) =>
    tareas[id] ? '<span class="ap-tarea" title="Tarea: ' + esc(tareas[id]) + '">#' + id + '</span>' : m);

  const dos = (n) => String(n).padStart(2, '0');
  const iso = (d) => d.getFullYear() + '-' + dos(d.getMonth() + 1) + '-' + dos(d.getDate());
  const hoy0 = () => { const d = new Date(); d.setHours(12, 0, 0, 0); return d; };
  // Fecha límite del rango (los commits anteriores no cuentan)
  const desde = () => { const d = hoy0(); d.setDate(d.getDate() - dias + 1); return iso(d); };

  // Commits dentro del filtro (persona + rango de fechas + repo)
  const filtrar = () => {
    const pid = parseInt(sel.value, 10) || 0;
    const min = desde();
    const rp = repoSel();
    return commits.filter((c) => (!pid || (c.miembro && c.miembro.id === pid))
      && (c.fecha || '') >= min && (!rp || (c.repo || '') === rp));
  };

  const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

  // Ancho por semana segun el rango: con pocas columnas las celdas crecen, y en
  // un año se quedan pequeñas para que el mapa entre sin scroll.
  const pasoRango = () => (dias <= 30 ? 34 : dias <= 90 ? 26 : 20);

  // Heatmap tipo GitHub (verde): una celda por día, una columna por semana, con
  // el mes rotulado arriba para saber qué se está mirando. 'paso' es el ancho
  // máximo por semana: las celdas no pasan de ahí porque un mapa de calor se
  // lee por la mancha, no por el tamaño del cuadro.
  const heatmap = (visibles, paso) => {
    const cont = {};
    visibles.forEach((c) => { if (c.fecha) cont[c.fecha] = (cont[c.fecha] || 0) + 1; });
    const hoy = hoy0();
    const semanas = Math.ceil(dias / 7);
    const ini = new Date(hoy); ini.setDate(ini.getDate() - ((semanas - 1) * 7 + hoy.getDay()));
    let max = 1; Object.values(cont).forEach((v) => { if (v > max) max = v; });
    let celdas = '', meses = '', col = 0, mesPrev = -1, colRotulo = -9;
    for (let d = new Date(ini); d <= hoy; d.setDate(d.getDate() + 1)) {
      if (d.getDay() === 0) {
        col++;
        // Un rótulo al empezar mes, y solo si cabe desde el anterior
        if (d.getMonth() !== mesPrev && col - colRotulo >= 3) {
          meses += '<span style="grid-column:' + col + '">' + MESES[d.getMonth()] + '</span>';
          colRotulo = col;
        }
        mesPrev = d.getMonth();
      }
      const k = iso(d), n = cont[k] || 0;
      const nivel = n === 0 ? 0 : Math.ceil(n / max * 4);
      celdas += '<span class="hm-celda hm-' + nivel + '" title="' + n + ' commit' + (n === 1 ? '' : 's') + ' · ' + k + '"></span>';
    }
    // Ancho exacto (no solo maximo) para que la columna del mapa se ajuste a el
    // y las cifras de al lado se queden con el resto del ancho.
    return '<div class="hm-envoltura" style="width:' + (semanas * (paso || 30)) + 'px">' +
      '<div class="hm-meses" style="grid-template-columns:repeat(' + semanas + ',1fr)">' + meses + '</div>' +
      '<div class="hm-grid hm-verde hm-grande">' + celdas + '</div></div>';
  };

  // Un mes en rejilla de 7 filas queda diminuto, y en tira de cuadros todos los
  // días parecen iguales. Para «30 d» van barras: la altura es el número de
  // commits, que se entiende de un vistazo. Los rangos largos siguen con mapa.
  const tira = (visibles) => {
    const cont = {};
    visibles.forEach((c) => { if (c.fecha) cont[c.fecha] = (cont[c.fecha] || 0) + 1; });
    let max = 1; Object.values(cont).forEach((v) => { if (v > max) max = v; });
    const hoy = hoy0();
    let barras = '', dias30 = '';
    for (let i = dias - 1; i >= 0; i--) {
      const d = new Date(hoy); d.setDate(d.getDate() - i);
      const k = iso(d), n = cont[k] || 0;
      const titulo = n + ' commit' + (n === 1 ? '' : 's') + ' · ' + k;
      barras += '<span class="ap-barra' + (n === 0 ? ' cero' : '') + '"' +
                ' style="--h:' + Math.round(n / max * 100) + '%" title="' + titulo + '">' +
                '<b>' + (n || '') + '</b></span>';
      // Un número cada cinco días para ubicarse, y siempre el de hoy
      dias30 += '<span>' + (i % 5 === 0 ? d.getDate() : '') + '</span>';
    }
    const cols = 'grid-template-columns:repeat(' + dias + ',1fr)';
    return '<div class="ap-tira">' +
      '<div class="ap-tira-eje"><small>' + max + '</small><small>0</small></div>' +
      '<div class="ap-tira-datos">' +
        '<div class="ap-barras" style="' + cols + '">' + barras + '</div>' +
        '<div class="ap-tira-dias" style="' + cols + '">' + dias30 + '</div>' +
      '</div></div>';
  };

  // Con un solo repo sobra ancho al lado del mapa: se llena con las cifras que
  // uno querría saber, en vez de estirar las celdas hasta parecer un tablero.
  const resumen = (cs) => {
    const porDia = {};
    cs.forEach((c) => { if (c.fecha) porDia[c.fecha] = (porDia[c.fecha] || 0) + 1; });
    const d7 = hoy0(); d7.setDate(d7.getDate() - 6);
    const ultimos = cs.filter((c) => (c.fecha || '') >= iso(d7)).length;
    let pico = 0, diaPico = '';
    Object.keys(porDia).forEach((f) => { if (porDia[f] > pico) { pico = porDia[f]; diaPico = f; } });
    const gente = new Set(cs.map((c) => (c.miembro ? c.miembro.n : c.nombre) || '?')).size;
    const dato = (k, v, sub) => '<div class="ap-dato"><small>' + k + '</small><b>' + v + '</b>' +
      (sub ? '<span>' + esc(sub) + '</span>' : '') + '</div>';

    // Solo las cifras: el detalle de quién hizo qué está a un clic en "Ver
    // commits", y meterlo aquí siempre alargaba la tarjeta de más.
    return '<div class="ap-resumen">' +
      dato('Últimos 7 días', ultimos, '') +
      dato('Días con commits', Object.keys(porDia).length, '') +
      dato('Mejor día', pico || '—', diaPico) +
      dato('Personas', gente, '') +
      '</div>';
  };

  const render = () => {
    if (skel) skel.hidden = true;
    const pid = parseInt(sel.value, 10) || 0;

    const vis = filtrar();
    totalEl.textContent = vis.length + ' commits';
    const rp = repoSel();
    // Leaderboard según el rango de fechas y el repo (no según la persona filtrada)
    const enRango = commits.filter((c) => (c.fecha || '') >= desde() && (!rp || (c.repo || '') === rp));
    const cuenta = {};
    enRango.forEach((c) => { if (c.miembro) cuenta[c.miembro.id] = (cuenta[c.miembro.id] || 0) + 1; });
    const rank = miembros.map((m) => ({ m, n: cuenta[m.id] || 0 })).filter((x) => x.n > 0).sort((a, b) => b.n - a.n);
    const maxN = Math.max(1, ...rank.map((x) => x.n));
    const rankHtml = rank.map((x) =>
      '<button type="button" class="ap-lb-fila' + (pid === x.m.id ? ' activo' : '') + '" data-persona="' + x.m.id + '">' +
      avatarHtml(x.m, 30) + '<span class="ap-lb-n" title="' + esc(x.m.n) + '">' + esc(x.m.n) + '</span>' +
      '<span class="ap-lb-barra"><span style="width:' + Math.round(x.n * 100 / maxN) + '%"></span></span>' +
      '<b class="ap-lb-num">' + x.n + '</b></button>').join('');

    // "Sin asignar": commits que no calzan con ninguna ficha, agrupados por su
    // identidad real (correo / usuario) para saber QUÉ registrar. Es la clave
    // para cuadrar a quien no sale: se copia ese correo/usuario a su ficha.
    const grupos = {};
    enRango.forEach((c) => {
      if (c.miembro) return;
      const key = (c.email || c.login || c.nombre || '?').toLowerCase();
      if (!grupos[key]) grupos[key] = { nombre: c.nombre || '', email: c.email || '', login: c.login || '', prov: c.prov || '', n: 0 };
      grupos[key].n++;
    });
    const sinList = Object.values(grupos).sort((a, b) => b.n - a.n);
    const sinTotal = sinList.reduce((s, g) => s + g.n, 0);
    let sinHtml = '';
    if (sinList.length) {
      const filas = sinList.slice(0, 15).map((g) =>
        '<div class="ap-sin-fila">' +
          '<span class="ap-sin-id">' + esc(g.nombre || '(sin nombre)') +
            (g.email ? ' · <b>' + esc(g.email) + '</b>'
                     : (g.login ? ' · <b>@' + esc(g.login) + '</b>' : '')) +
            (g.prov ? ' <span class="ap-sin-prov">' + esc(g.prov) + '</span>' : '') +
          '</span>' +
          '<b class="ap-sin-num">' + g.n + '</b>' +
        '</div>').join('');
      sinHtml = '<details class="ap-sin">' +
        '<summary><i class="fa-solid fa-user-slash"></i> Sin asignar: ' + sinTotal +
        ' commit' + (sinTotal === 1 ? '' : 's') + ' de ' + sinList.length + ' identidad' + (sinList.length === 1 ? '' : 'es') + '</summary>' +
        '<p class="ap-sin-ayuda">No calzan con ninguna ficha. Copia el <b>correo</b> (o el usuario) tal cual sale aquí a "Correos de Git" / "Usuario(s) de Git" de esa persona y volverán a contarse.</p>' +
        '<div class="ap-sin-lista">' + filas + '</div></details>';
    }

    lb.innerHTML = rankHtml + sinHtml;
    // Se esconde solo si no hay nada que mostrar (ni ranking ni sin-asignar).
    lb.hidden = rank.length === 0 && sinList.length === 0;

    // Un mapa por repositorio, con lo filtrado por persona y fechas
    vacio.hidden = vis.length > 0;
    mapas.hidden = vis.length === 0;
    const ramaActiva = (selRama && selRama.value) || ramaDefecto;
    const todosRepos = repos.length ? repos : [...new Set(vis.map((c) => c.repo))];
    const lista = rp ? [rp] : todosRepos;   // con un repo elegido, solo su mapa
    // Con uno o dos repos la rejilla automática los dejaba en una columna
    // estrecha con medio panel vacío: se reparten el ancho completo.
    const uno = lista.length === 1;
    const corto = uno && dias <= 30;      // un mes: tira de días, no rejilla
    mapas.classList.toggle('ap-uno', uno);
    mapas.classList.toggle('ap-corto', corto);
    mapas.classList.toggle('ap-dos', lista.length === 2);
    mapas.innerHTML = lista.map((repo) => {
      const cs = vis.filter((c) => (c.repo || '') === repo);
      return '<div class="ap-repo">' +
        '<div class="ap-repo-cab"><span class="ap-repo-nom"><i class="fa-solid fa-code-branch"></i> ' + esc(repo) +
        (ramaActiva ? '<span class="ap-repo-rama">' + esc(ramaActiva) + '</span>' : '') + '</span>' +
        '<b class="ap-repo-n">' + cs.length + '</b></div>' +
        (corto ? tira(cs) : heatmap(cs, uno ? pasoRango() : 30)) +
        (uno ? resumen(cs) : '') + '</div>';
    }).join('') +
      '<div class="hm-leyenda"><small>Menos</small>' +
      '<span class="hm-celda hm-0 hm-verde"></span><span class="hm-celda hm-1 hm-verde"></span>' +
      '<span class="hm-celda hm-2 hm-verde"></span><span class="hm-celda hm-3 hm-verde"></span>' +
      '<span class="hm-celda hm-4 hm-verde"></span><small>Más</small></div>' +
      // Si se alcanzó el tope de lectura, decirlo: si no, el mapa aparenta que
      // eso es todo lo que hay en el rango.
      (truncado ? '<p class="ap-tope"><i class="fa-solid fa-circle-info"></i> ' +
        'El repositorio tiene más commits de los que se leen de una vez en este rango: se trajeron ' +
        'los más recientes, así que el tramo más antiguo puede quedar corto. Acorta el rango para verlo completo.</p>' : '');
  };

  sel.addEventListener('change', render);
  lb.addEventListener('click', (e) => {
    const f = e.target.closest('.ap-lb-fila'); if (!f) return;
    const pid = f.dataset.persona;
    sel.value = (sel.value === pid) ? '0' : pid;
    sel.dispatchEvent(new Event('ms-sync'));
    render();
  });
  caja.querySelector('.ap-rango').addEventListener('click', (e) => {
    const b = e.target.closest('[data-dias]'); if (!b) return;
    dias = parseInt(b.dataset.dias, 10);
    caja.querySelectorAll('.ap-rango [data-dias]').forEach((x) => x.classList.toggle('active', x === b));
    // Si el rango nuevo cabe en lo ya traído, se filtra en el navegador; solo
    // se vuelve a pedir cuando hace falta más historial del que hay.
    if (dias <= diasCargados) render();
    else cargar(selRama ? selRama.value : '');
  });

  // Rellena el selector de ramas una vez que el proveedor las devuelve (solo si
  // hay >1). La opción vacía es "la del proyecto": si hay una rama fijada en
  // Editar → Repos, se dice cuál, para no dejar dudas de qué se está mirando.
  const actualizarRamas = () => {
    if (!selRama) return;
    const rp = repoSel();
    const lista = rp ? (ramasRepo[rp] || []) : ramasUnion;
    // Deja solo la opción vacía ("por defecto") y re-agrega el resto
    [...selRama.querySelectorAll('option')].forEach((o) => { if (o.value !== '') o.remove(); });
    const op0 = selRama.querySelector('option[value=""]');
    if (op0) op0.textContent = (!rp && ramaDefecto) ? ramaDefecto + ' (del proyecto)' : 'Rama por defecto';
    lista.forEach((rn) => {
      if (!rn || (!rp && rn === ramaDefecto)) return;   // la del proyecto ya es la de arriba
      const o = document.createElement('option');
      o.value = rn; o.textContent = rn;
      selRama.appendChild(o);
    });
    selRama.value = '';
    selRama.dispatchEvent(new Event('ms-sync'));   // MecaSelect relee las opciones
    if (ramaWrap) ramaWrap.hidden = lista.length < 2;   // sin ramas que elegir, se oculta
  };

  // Trae por AJAX los commits (y ramas) del repo/rama, sin recargar la página
  const cargar = (rama) => {
    cargandoEl.hidden = false;
    const pedidos = dias;
    return fetch('aportes.php?id=' + proyecto + '&rama=' + encodeURIComponent(rama || '') + '&dias=' + pedidos)
      .then((r) => r.json())
      .then((j) => {
        if (j) {
          commits = j.commits || [];
          mapear(commits);
          diasCargados = pedidos;
          ramaDefecto = j.rama_defecto || '';
          truncado = !!j.truncado;
          ramasUnion = j.ramas || [];
          ramasRepo = j.ramas_repo || {};
          actualizarRamas();
          cargado = true;
          render();
          if (Array.isArray(j.movidas) && j.movidas.length) aplicarMovidasEnTabla(j.movidas);
        }
      })
      .catch(() => { if (skel) skel.hidden = true; lb.hidden = false; vacio.hidden = false; })
      .finally(() => { cargandoEl.hidden = true; });
  };

  if (selRama) selRama.addEventListener('change', () => cargar(selRama.value));
  // Cambiar de repo: reconstruye las ramas de ese repo y re-filtra (sin recargar)
  if (selRepo) selRepo.addEventListener('change', () => { actualizarRamas(); render(); });

  /* ----- Commits a pantalla completa, paginados de 25 en 25 ----- */
  const dlg = caja.querySelector('.ap-dialogo');
  const apcLista = dlg.querySelector('.apc-lista');
  const apcSub = dlg.querySelector('.apc-sub');
  const apcPag = dlg.querySelector('.apc-pag');
  const POR_PAG = 25;
  let pagina = 0, visCommits = [];

  const pintarPagina = () => {
    const total = visCommits.length;
    const paginas = Math.max(1, Math.ceil(total / POR_PAG));
    pagina = Math.max(0, Math.min(pagina, paginas - 1));
    const trozo = visCommits.slice(pagina * POR_PAG, pagina * POR_PAG + POR_PAG);
    apcLista.innerHTML = trozo.map((c) =>
      '<li class="ap-commit">' + avatarHtml(c.miembro, 28) +
      '<div class="ap-commit-txt"><span class="ap-msg">' + conTareas(c.msg) + '</span>' +
      '<small>' + esc(c.miembro ? c.miembro.n : (c.nombre || '?')) + ' · ' + esc(c.repo || '') + ' · ' + esc(c.fecha || '') +
      ' · <a href="' + esc(c.url) + '" target="_blank" rel="noopener">' + esc(c.sha) + '</a></small></div></li>').join('');
    apcPag.textContent = 'Página ' + (pagina + 1) + ' de ' + paginas + ' · ' + total + ' commits';
    dlg.querySelector('.apc-prev').disabled = pagina === 0;
    dlg.querySelector('.apc-next').disabled = pagina >= paginas - 1;
  };
  caja.querySelector('.ap-ver-commits').addEventListener('click', () => {
    visCommits = filtrar();
    pagina = 0;
    const pid = parseInt(sel.value, 10) || 0;
    const quien = pid ? (miembros.find((m) => m.id === pid) || {}).n : 'Todo el equipo';
    apcSub.textContent = '· ' + quien;
    pintarPagina();
    dlg.showModal();
  });
  dlg.querySelector('.apc-prev').addEventListener('click', () => { pagina--; pintarPagina(); });
  dlg.querySelector('.apc-next').addEventListener('click', () => { pagina++; pintarPagina(); });

  if (ramaWrap) ramaWrap.hidden = true;   // se muestra solo si hay varias ramas
  if (lazy) {
    // Se cargan los commits al abrir el PROYECTO (en segundo plano, tras pintar la
    // página), no solo al ver Métricas: así el flujo del estándar mueve las tareas
    // aunque nadie abra esa pestaña. El fetch usa caché, y el render va a una
    // sección oculta hasta que se elige Métricas. Los movimientos se reflejan en
    // la tabla y el kanban (aplicarMovidasEnTabla).
    const arranque = () => { if (!cargado) cargar(''); };
    if ('requestIdleCallback' in window) requestIdleCallback(arranque, { timeout: 2500 });
    else setTimeout(arranque, 1200);
  } else {
    render();
  }
});

// Rellenar y abrir el modal de edicion de tarea
document.querySelectorAll('[data-editar-tarea]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const t = JSON.parse(btn.dataset.editarTarea);
    const dlg = document.getElementById('dlg-editar-tarea');
    dlg.querySelector('#et-id').value = t.id;
    dlg.querySelector('#et-titulo').value = t.titulo;
    window.MecaRT.set('et-descripcion', t.descripcion || '');
    setFecha(dlg.querySelector('#et-inicio'), t.fecha_inicio);
    setFecha(dlg.querySelector('#et-fecha'), t.fecha_limite);
    dlg.querySelectorAll('[data-atajos-fecha] .chip-atajo').forEach((c) => c.classList.remove('activo'));
    setSelect(dlg.querySelector('.js-et-asignado'), t.asignados || (t.asignado_id ? [t.asignado_id] : []));
    setSelect(dlg.querySelector('.js-et-prioridad'), t.prioridad);
    setSelect(dlg.querySelector('.js-et-estado'), t.estado);
    // El ítem del release al que está amarrada (Releases)
    const relSel = dlg.querySelector('.js-et-rel');
    if (relSel) {
      let mapa = {};
      try { mapa = JSON.parse(document.getElementById('rel-tareas-item').textContent || '{}'); } catch (e) { mapa = {}; }
      setSelect(relSel, mapa[t.id] || '');
    }
    const picker = dlg.querySelector('[data-dep-picker]');
    if (picker && picker.depAPI) {
      // El selector se rellena vía su API (excluye la propia tarea de la lista).
      picker.depAPI.reset();
      picker.depAPI.excludeSelf(t.id);
      picker.depAPI.setDeps(t.dependencias || (t.depende_de ? [t.depende_de] : []));
    }
    // Documentos que ya tiene: se listan para poder abrirlos o quitarlos
    const campoAdj = dlg.querySelector('[data-adjuntos-tarea]');
    if (campoAdj && campoAdj.pintarAdjuntos) {
      campoAdj.pintarAdjuntos(t.adjuntos || []);
      campoAdj.limpiarNuevos();          // sin arrastrar lo elegido para otra tarea
    }
    actualizarDuracion(dlg.querySelector('form'));
    dlg.showModal();
  });
});

// Calendario: chip "+N" que abre el resto de eventos del día en un desplegable
document.addEventListener('click', (e) => {
  const chip = e.target.closest('[data-cal-mas]');
  // Cierra los abiertos (menos el que corresponde al chip pulsado)
  document.querySelectorAll('.cal-mas-lista:not([hidden])').forEach((l) => {
    if (!chip || l !== chip.nextElementSibling) l.hidden = true;
  });
  if (chip) {
    const lista = chip.nextElementSibling;
    if (lista && lista.classList.contains('cal-mas-lista')) lista.hidden = !lista.hidden;
  }
});

// Toggle de plataforma (Zoom / Meet / enlace propio) en "Nueva reunión"
document.querySelectorAll('.nr-plat').forEach((tg) => {
  const campo = tg.closest('.campo');
  const hidden = campo?.querySelector('input[name="plataforma"]');
  const hint = campo?.querySelector('.nr-meet-hint');
  // El campo del enlace es hermano del .campo de la plataforma, no hijo.
  const enlace = campo?.parentElement?.querySelector('.nr-enlace');
  const urlInp = enlace?.querySelector('input[name="join_url"]');
  tg.addEventListener('click', (e) => {
    const b = e.target.closest('[data-plat]');
    if (!b) return;
    const plat = b.dataset.plat;
    tg.querySelectorAll('[data-plat]').forEach((x) => x.classList.toggle('active', x === b));
    if (hidden) hidden.value = plat;
    if (campo && campo.dataset.resumen !== undefined) campo.dataset.resumen = (b.querySelector('b') || b).textContent.trim();
    if (hint) hint.hidden = plat !== 'meet';
    if (enlace) enlace.hidden = plat !== 'enlace';
    // Obligatorio solo cuando es la opción elegida: si no, el navegador
    // bloquearía el envío de una reunión de Zoom por un campo oculto.
    if (urlInp) urlInp.required = plat === 'enlace';
  });
});

// Rellenar y abrir el modal de edicion de reunion (editar / invitar a mas gente)
document.querySelectorAll('.js-editar-reunion').forEach((btn) => {
  btn.addEventListener('click', () => {
    const r = JSON.parse(btn.dataset.editarReunion);
    const dlg = document.getElementById('dlg-editar-reunion');
    if (!dlg) return;
    dlg.querySelector('#er-id').value = r.id;
    dlg.querySelector('#er-topic').value = r.topic || '';
    // datetime-local exige "YYYY-MM-DDTHH:MM". Aceptamos espacio o T y con o
    // sin segundos, para que la hora guardada de la reunión SIEMPRE se cargue.
    const m = String(r.inicio || '').match(/(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})/);
    dlg.querySelector('#er-inicio').value = m ? (m[1] + 'T' + m[2]) : '';
    setSelect(dlg.querySelector('.js-er-duracion'), r.duracion || 60);
    setSelect(dlg.querySelector('.js-er-invitados'), r.invitados || []);

    // Repetición semanal: marcar los días guardados y abrir el bloque si aplica
    const rec = dlg.querySelector('.js-er-recurrente');
    if (rec) {
      const dias = (r.dias || []).map(String);
      dlg.querySelectorAll('.js-er-dias input[type="checkbox"]').forEach((c) => {
        c.checked = dias.includes(c.value);
      });
      const hasta = dlg.querySelector('.js-er-hasta');
      if (hasta) hasta.value = r.hasta || '';
      rec.checked = !!r.recurrente;
      rec.dispatchEvent(new Event('change', { bubbles: true }));
    }
    dlg.showModal();
  });
});

// Bloque "Repetir todas las semanas": muestra u oculta días y fecha final
document.querySelectorAll('.js-repetir').forEach((chk) => {
  const caja = chk.closest('.repetir-caja');
  const detalle = caja?.querySelector('.repetir-detalle');
  if (!detalle) return;
  const pintar = () => {
    detalle.hidden = !chk.checked;
    // En los asistentes, lo que dirá la confirmación
    if (caja.dataset.resumen === undefined) return;
    const dias = [...caja.querySelectorAll('.dia-chip input:checked')].map((c) => c.closest('.dia-chip').textContent.trim());
    const hasta = caja.querySelector('input[name="hasta"]')?.value || '';
    caja.dataset.resumen = chk.checked ? 'Cada ' + (dias.join(', ') || '—') + (hasta ? ' hasta el ' + hasta.split('-').reverse().join('/') : '') : 'No se repite';
  };
  chk.addEventListener('change', pintar);
  caja.addEventListener('change', pintar);
  pintar();
});

/* =========================================================
   Selector de día y hora de las reuniones (UI::selectorHora): el día con
   el calendario y la hora en cuartos (mañana, tarde y noche). Pregunta al
   panel qué tiene Zoom ese día (reunion_agenda) y pinta en gris lo que no
   cabe con la duración elegida; lo que ya pasó tampoco se elige. Lo que
   se envía es el oculto «inicio» (Y-m-dTH:i).
   ========================================================= */
const SelectorHora = {
  TURNOS: { m: [7 * 60, 12 * 60], t: [12 * 60, 18 * 60], n: [18 * 60, 22 * 60] },
  DIAS: ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'],
  dos: (n) => String(n).padStart(2, '0'),
  hhmm(min) { return this.dos(Math.floor(min / 60)) + ':' + this.dos(min % 60); },
  doce(min) {
    const h = Math.floor(min / 60) % 24;
    return ((h % 12) || 12) + ':' + this.dos(min % 60) + ' ' + (h < 12 ? 'am' : 'pm');
  },
  turnoDe(min) { return min < 12 * 60 ? 'm' : (min < 18 * 60 ? 't' : 'n'); },
  hoy() {
    const d = new Date();
    return { f: d.getFullYear() + '-' + this.dos(d.getMonth() + 1) + '-' + this.dos(d.getDate()), min: d.getHours() * 60 + d.getMinutes() };
  },
  init() { document.querySelectorAll('[data-hora-picker]').forEach((hp) => this.montar(hp)); },

  montar(hp) {
    const form = hp.closest('form');
    const oculto = hp.querySelector('input[name="inicio"]');
    const fecha = hp.querySelector('.hp-fecha');
    const grid = hp.querySelector('.hp-grid');
    const selTxt = hp.querySelector('.hp-sel');
    const tabs = [...hp.querySelectorAll('[data-turno]')];
    if (!form || !oculto || !fecha || !grid) return;
    const cache = new Map();
    let turno = 'm';
    let ocupado = [];
    let pedido = 0;

    const dur = () => parseInt(form.querySelector('[name="duracion"]')?.value || '60', 10) || 60;
    const valor = () => {
      const m = (oculto.value || '').match(/(\d{4}-\d{2}-\d{2})[T ](\d{2}):(\d{2})/);
      return m ? { f: m[1], min: +m[2] * 60 + +m[3] } : null;
    };
    const choca = (s) => ocupado.find((o) => s < o.fin && o.ini < s + dur());
    const pasada = (f, s) => { const h = this.hoy(); return f < h.f || (f === h.f && s < h.min - 10); };
    const nombreDia = (f) => { const d = new Date(f + 'T12:00'); return this.DIAS[d.getDay()] + ' ' + d.getDate(); };

    const pintar = () => {
      const [a, b] = this.TURNOS[turno];
      const v = valor();
      const f = fecha.value;
      tabs.forEach((t) => { const on = t.dataset.turno === turno; t.classList.toggle('active', on); t.setAttribute('aria-selected', on ? 'true' : 'false'); });
      grid.innerHTML = '';
      for (let s = a; s < b; s += 15) {
        const op = document.createElement('button');
        op.type = 'button';
        op.className = 'hp-op';
        op.setAttribute('role', 'option');
        op.dataset.min = s;
        // Hoy, lo que ya pasó ni se muestra: arriba quedan las horas próximas
        if (pasada(f, s)) continue;
        const c = choca(s);
        op.innerHTML = '<b>' + this.doce(s) + '</b>' + (c ? '<small>ocupado</small>' : '');
        // aria-disabled y no disabled: así la ayuda (con qué choca) sale al pasar el mouse
        if (c) {
          op.classList.add('is-ocupado');
          op.setAttribute('aria-disabled', 'true');
          op.title = 'Ocupado: «' + c.tema + '»' + (c.proyecto ? ' (' + c.proyecto + ')' : '') + ', de ' + this.doce(c.ini) + ' a ' + this.doce(c.fin);
        }
        if (v && v.f === f && v.min === s) { op.classList.add('is-sel'); op.setAttribute('aria-selected', 'true'); }
        grid.appendChild(op);
      }
      if (!grid.children.length) grid.innerHTML = '<p class="hp-vacio">Esta parte del día ya pasó: elige la siguiente.</p>';
      // Lo elegido, en palabras (también es lo que dice la confirmación)
      const texto = v ? nombreDia(v.f) + ' · ' + this.doce(v.min) + ' – ' + this.doce(v.min + dur()) : '';
      if (selTxt) {
        selTxt.textContent = v ? texto + (choca(v.min) && v.f === f ? ' · choca con otra reunión' : '') : 'Elige una hora';
        selTxt.classList.toggle('is-mal', !!(v && v.f === f && choca(v.min)));
      }
      hp.dataset.resumen = texto;
    };

    // Lo elegido (o la primera hora libre) queda al centro de lo que se ve
    const centrar = () => {
      const op = grid.querySelector('.is-sel') || grid.querySelector('.hp-op:not([aria-disabled])');
      if (op && op.offsetParent) op.scrollIntoView({ block: 'center', behavior: 'smooth' });
    };
    // El turno que tiene horas por delante: si en el de lo elegido ya no queda nada, el siguiente
    const turnoUtil = (pref) => {
      const f = fecha.value;
      const orden = ['m', 't', 'n'];
      for (const k of orden.slice(orden.indexOf(pref)).concat(orden)) {
        const [a, b] = this.TURNOS[k];
        for (let s = a; s < b; s += 15) if (!pasada(f, s)) return k;
      }
      return pref;
    };

    const cargar = async () => {
      const f = fecha.value;
      if (!f) { ocupado = []; pintar(); return; }
      const plat = form.querySelector('input[name="plataforma"]')?.value || '';
      const clave = f + '|' + plat;
      if (cache.has(clave)) { ocupado = cache.get(clave); pintar(); return; }
      const n = ++pedido;
      grid.classList.add('is-cargando');
      const datos = new FormData();
      datos.set('accion', 'reunion_agenda');
      datos.set('fecha', f);
      ['proyecto_id', 'id', 'plataforma'].forEach((k) => { const x = form.querySelector('[name="' + k + '"]'); if (x) datos.set(k, x.value); });
      try {
        const r = await fetch('actions.php', { method: 'POST', body: datos, credentials: 'same-origin' });
        const j = await r.json();
        if (n !== pedido) return;
        ocupado = Array.isArray(j.ocupado) ? j.ocupado : [];
        cache.set(clave, ocupado);
      } catch (e) {
        if (n !== pedido) return;
        ocupado = [];
      }
      grid.classList.remove('is-cargando');
      pintar();
      centrar();
    };

    const elegir = (f, min) => {
      oculto.value = f + 'T' + this.hhmm(min);
      oculto.dispatchEvent(new Event('change', { bubbles: true }));
      pintar();
    };

    grid.addEventListener('click', (e) => {
      const op = e.target.closest('.hp-op');
      if (!op || op.getAttribute('aria-disabled') === 'true') return;
      elegir(fecha.value, +op.dataset.min);
    });
    tabs.forEach((t) => t.addEventListener('click', () => { turno = t.dataset.turno; pintar(); centrar(); }));
    // Otro día: se queda la misma hora, en el día nuevo
    fecha.addEventListener('change', () => {
      const v = valor();
      if (v && fecha.value) { oculto.value = fecha.value + 'T' + this.hhmm(v.min); oculto.dispatchEvent(new Event('change', { bubbles: true })); }
      cargar();
    });
    form.addEventListener('change', (e) => { if (e.target.name === 'duracion') pintar(); });
    form.querySelectorAll('[data-plat]').forEach((b) => b.addEventListener('click', () => setTimeout(cargar, 0)));

    // Al abrir el modal (al editar, ya trae la hora de la reunión)
    const abrir = () => {
      cache.clear();
      const v = valor();
      fecha.value = v ? v.f : this.hoy().f;
      fecha.dispatchEvent(new Event('md-sync'));
      turno = turnoUtil(v ? this.turnoDe(v.min) : 'm');
      cargar();
    };
    const dlg = form.closest('dialog');
    if (dlg) new MutationObserver(() => { if (dlg.open) abrir(); }).observe(dlg, { attributes: true, attributeFilter: ['open'] });
    // En el asistente, el paso del día y la hora se ve después: al aparecer, se centra en lo próximo
    const panel = hp.closest('.wz-panel');
    if (panel) new MutationObserver(() => { if (panel.classList.contains('activo')) requestAnimationFrame(centrar); })
      .observe(panel, { attributes: true, attributeFilter: ['class'] });
    abrir();
  },
};
SelectorHora.init();

/* ---------- Reuniones: Zoom es una sola cuenta ----------
   Al elegir la fecha, la duración o la repetición se pregunta si Zoom ya tiene
   otra reunión a esa hora (en cualquier proyecto). Si choca, sale el aviso con
   desde qué hora está libre y «Crear» / «Guardar» se bloquea: así no se pierde
   lo escrito con un rechazo del servidor (que igual lo comprueba). */
['dlg-nueva-reunion', 'dlg-editar-reunion'].forEach((idDlg) => {
  const form = document.querySelector('#' + idDlg + ' form');
  if (!form) return;
  const enviar = form.querySelector('button[type="submit"]');
  // Bajo el día y la hora, y otra vez en la confirmación (donde está «Crear»)
  const avisos = [form.querySelector('.hp, .campo-doble'), form.querySelector('.wz-resumen')].filter(Boolean).map((ancla) => {
    const aviso = document.createElement('p');
    aviso.className = 'reu-choque';
    aviso.setAttribute('role', 'alert');
    aviso.hidden = true;
    ancla.insertAdjacentElement(ancla.matches('.wz-resumen') ? 'beforebegin' : 'afterend', aviso);
    return aviso;
  });
  let espera = 0;
  let pedido = 0;
  const marcar = (texto) => {
    avisos.forEach((aviso) => {
      aviso.hidden = !texto;
      aviso.innerHTML = texto ? '<i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i><span></span>' : '';
      if (texto) aviso.querySelector('span').textContent = texto;
    });
    if (enviar) { enviar.disabled = !!texto; enviar.title = texto ? 'Zoom está ocupado a esa hora' : ''; }
  };
  const revisar = () => {
    clearTimeout(espera);
    espera = setTimeout(async () => {
      // Meet o un enlace propio no comparten la cuenta de Zoom
      const plat = form.querySelector('input[name="plataforma"]')?.value || '';
      if (plat && plat !== 'zoom') { marcar(''); return; }
      const datos = new FormData(form);
      datos.set('accion', 'reunion_choque');
      const n = ++pedido;
      try {
        const r = await fetch('actions.php', { method: 'POST', body: datos, credentials: 'same-origin' });
        const j = await r.json();
        if (n === pedido) marcar(j.libre ? '' : (j.texto || ''));
      } catch (e) {
        if (n === pedido) marcar('');
      }
    }, 250);
  };
  form.addEventListener('change', revisar);
  form.addEventListener('input', (e) => { if (e.target.name === 'inicio') revisar(); });
  form.querySelectorAll('[data-plat]').forEach((b) => b.addEventListener('click', () => setTimeout(revisar, 0)));
  // Al abrir el modal (también al editar otra reunión: ya trae sus valores)
  const dlg = form.closest('dialog');
  if (dlg) {
    new MutationObserver(() => { if (dlg.open) { marcar(''); revisar(); } })
      .observe(dlg, { attributes: true, attributeFilter: ['open'] });
  }
});

// Formularios de persona: vista previa en vivo (avatar, nombre, rol, git, color, foto)
document.querySelectorAll('.form-persona').forEach((form) => {
  const campo = (n) => form.querySelector('[name="' + n + '"]');
  const av = form.querySelector('.pp-avatar-circle');
  const img = form.querySelector('.pp-img');
  const iniEl = form.querySelector('.pp-iniciales');

  const iniciales = (texto) => {
    const partes = texto.trim().split(/\s+/).filter(Boolean);
    if (!partes.length) return '?';
    let ini = partes[0][0].toUpperCase();
    if (partes.length > 1) ini += partes[partes.length - 1][0].toUpperCase();
    return ini;
  };

  const refrescar = () => {
    const nombre = campo('nombre').value.trim();
    form.querySelector('.pp-nombre').textContent = nombre || 'Nuevo colaborador';
    iniEl.textContent = iniciales(nombre);
    form.querySelector('.pp-rol-texto').textContent = campo('rol').value.trim() || 'Rol del equipo';
    form.querySelector('.pp-git-user').textContent =
      campo('git_user').value.trim().replace(/^@/, '') || 'usuario';
    const radio = form.querySelector('.color-picker input[type="radio"]:checked');
    const hex = radio && radio.value === 'custom'
      ? form.querySelector('.color-picker input[type="color"]').value
      : (radio ? radio.dataset.hex : null);
    if (hex) av.style.setProperty('--av-c1', hex);
  };
  form._refrescarPersona = refrescar;
  form._fotoPreview = (src) => {
    img.src = src || '';
    img.hidden = !src;
    iniEl.hidden = !!src;
  };

  ['nombre', 'rol', 'git_user'].forEach((n) => campo(n).addEventListener('input', refrescar));
  form.querySelectorAll('.color-picker input[type="radio"]').forEach((r) => r.addEventListener('change', refrescar));
  form.querySelector('.color-picker input[type="color"]').addEventListener('input', refrescar);
  form.querySelector('.pp-file').addEventListener('change', () => {
    const file = form.querySelector('.pp-file').files[0];
    if (file) form._fotoPreview(URL.createObjectURL(file));
  });
  refrescar();
});

// Componente único de subida (UI::archivo): drag & drop, estado "con archivo"
// (nombre + peso + quitar) y validación de tipo/tamaño (error). Envuelve un
// <input type="file"> nativo, así el formulario se envía igual que siempre.
(() => {
  const humano = (b) => b < 1024 ? b + ' B'
    : b < 1048576 ? Math.round(b / 1024) + ' KB'
    : (b / 1048576).toFixed(1) + ' MB';
  const iconoDe = (nombre) => {
    const ext = (nombre.split('.').pop() || '').toLowerCase();
    if (ext === 'pdf') return 'fa-file-pdf';
    if (['doc', 'docx'].includes(ext)) return 'fa-file-word';
    if (['xls', 'xlsx', 'csv'].includes(ext)) return 'fa-file-excel';
    if (['ppt', 'pptx'].includes(ext)) return 'fa-file-powerpoint';
    if (['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'].includes(ext)) return 'fa-file-image';
    if (['zip', 'rar', '7z'].includes(ext)) return 'fa-file-zipper';
    return 'fa-file';
  };
  const aceptado = (file, accept) => {
    if (!accept) return true;
    const nom = file.name.toLowerCase(), tipo = (file.type || '').toLowerCase();
    return accept.split(',').map((s) => s.trim().toLowerCase()).some((a) => {
      if (!a) return false;
      if (a.startsWith('.')) return nom.endsWith(a);
      if (a.endsWith('/*')) return tipo.startsWith(a.slice(0, -1));
      return tipo === a;
    });
  };

  document.querySelectorAll('[data-archivo]').forEach((fx) => {
    const input = fx.querySelector('.fx-input');
    if (!input || fx.dataset.fxListo) return;
    fx.dataset.fxListo = '1';
    const disparo = fx.querySelector('.fx-disparo');
    const cont    = fx.querySelector('.fx-files');
    const errEl   = fx.querySelector('.fx-error');
    const maxMB   = parseFloat(fx.dataset.max || '0');
    const multi   = fx.dataset.multi === '1';
    const off     = fx.classList.contains('fx-off') || input.disabled;

    const error = (msg) => { fx.classList.toggle('fx-err', !!msg); errEl.textContent = msg || ''; errEl.hidden = !msg; };
    const rebuild = (files) => { const dt = new DataTransfer(); files.forEach((f) => dt.items.add(f)); input.files = dt.files; };

    const pintar = () => {
      const files = [...input.files];
      cont.innerHTML = '';
      fx.classList.toggle('fx-lleno', files.length > 0);
      files.forEach((f, i) => {
        const chip = document.createElement('div');
        chip.className = 'fx-file';
        chip.innerHTML = '<span class="fx-file-ic"><i class="fa-solid ' + iconoDe(f.name) + '"></i></span>'
          + '<span class="fx-file-info"><b class="truncate">' + f.name.replace(/[<>&]/g, '') + '</b>'
          + '<small>' + humano(f.size) + '</small></span>'
          + '<button type="button" class="fx-file-x" title="Quitar"><i class="fa-solid fa-xmark"></i></button>';
        chip.querySelector('.fx-file-x').addEventListener('click', (e) => {
          e.stopPropagation();
          rebuild([...input.files].filter((_, j) => j !== i));
          error(''); pintar();
        });
        cont.appendChild(chip);
      });
    };

    const validar = (files) => {
      for (const f of files) {
        if (!aceptado(f, input.accept)) return 'Ese tipo de archivo no se admite aquí.';
        if (maxMB > 0 && f.size > maxMB * 1048576) return f.name + ' pesa ' + humano(f.size) + ': el máximo es ' + maxMB + ' MB.';
      }
      return '';
    };

    const tomar = (lista) => {
      let files = [...lista];
      if (!multi) files = files.slice(0, 1);
      const msg = validar(files);
      if (msg) { error(msg); rebuild([]); pintar(); return; }
      error(''); rebuild(files); pintar();
    };

    if (!off) {
      disparo.addEventListener('click', () => input.click());
      input.addEventListener('change', () => tomar(input.files));
      ['dragenter', 'dragover'].forEach((ev) => fx.addEventListener(ev, (e) => { e.preventDefault(); fx.classList.add('fx-drag'); }));
      ['dragleave', 'dragend'].forEach((ev) => fx.addEventListener(ev, (e) => {
        if (e.target === fx || !fx.contains(e.relatedTarget)) fx.classList.remove('fx-drag');
      }));
      fx.addEventListener('drop', (e) => {
        e.preventDefault(); fx.classList.remove('fx-drag');
        if (e.dataTransfer && e.dataTransfer.files.length) tomar(e.dataTransfer.files);
      });
    }
  });
})();

// Correos de Git: campo repetible (un input por cuenta) con agregar / quitar
(() => {
  const MAX = 5;
  document.addEventListener('click', (e) => {
    const add = e.target.closest('.git-email-agregar');
    if (add) {
      const cont = add.parentElement.querySelector('[data-git-emails]');
      if (!cont) return;
      if (cont.querySelectorAll('.git-email-fila').length >= MAX) return;
      const f = cont.querySelector('.git-email-fila').cloneNode(true);
      f.querySelector('input').value = '';
      cont.appendChild(f);
      f.querySelector('input').focus();
      cont.dispatchEvent(new Event('input', { bubbles: true }));   // que se note el cambio
      return;
    }
    const quitar = e.target.closest('.git-email-quitar');
    if (quitar) {
      const cont = quitar.closest('[data-git-emails]');
      const filas = cont.querySelectorAll('.git-email-fila');
      if (filas.length > 1) quitar.closest('.git-email-fila').remove();
      else quitar.closest('.git-email-fila').querySelector('input').value = '';   // la última solo se vacía
      cont.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });
  // Para el modal de editar: rellena las filas con una lista de correos
  window.setGitEmails = (cont, lista) => {
    if (!cont) return;
    const filas = [...cont.querySelectorAll('.git-email-fila')];
    filas.slice(1).forEach((f) => f.remove());
    const arr = (lista || []).filter(Boolean);
    cont.querySelector('.git-email-fila input').value = arr[0] || '';
    arr.slice(1, MAX).forEach((v) => {
      const f = filas[0].cloneNode(true);
      f.querySelector('input').value = v;
      cont.appendChild(f);
    });
  };
})();

// Rellenar y abrir el modal de edicion de miembro
document.querySelectorAll('[data-editar-miembro]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const m = JSON.parse(btn.dataset.editarMiembro);
    const dlg = document.getElementById('dlg-editar-miembro');
    const form = dlg.querySelector('form');
    dlg.querySelector('#em-id').value = m.id;
    form.querySelector('[name="nombre"]').value = m.nombre;
    form.querySelector('[name="rol"]').value = m.rol;
    form.querySelector('[name="git_user"]').value = m.git_user;
    if (window.setGitEmails) {
      window.setGitEmails(form.querySelector('[data-git-emails]'),
        (m.git_emails || '').split(',').map((s) => s.trim()).filter(Boolean));
    }
    form.querySelector('[name="email"]').value = m.email || '';
    const telEl = form.querySelector('[name="telefono"]');
    if (telEl) telEl.value = String(m.telefono || '').replace(/\D/g, '');
    const selEquipo = form.querySelector('[name="equipo"]');
    if (selEquipo && m.equipo) setSelect(selEquipo, m.equipo);
    const selAcceso = form.querySelector('[name="acceso"]');
    if (selAcceso && m.acceso) setSelect(selAcceso, m.acceso);   // sin esto el admin editado se degradaba a lector
    form.querySelector('.pp-file').value = '';
    if (String(m.color).startsWith('#')) {
      form.querySelector('.color-picker input[value="custom"]').checked = true;
      form.querySelector('.color-picker input[type="color"]').value = m.color;
    } else {
      const radio = form.querySelector('.color-picker input[value="' + m.color + '"]');
      if (radio) {
        radio.checked = true;
        const mas = radio.closest('details');
        if (mas) mas.open = true;   // abrir "Más colores" si el color vive ahí
      }
    }
    form._fotoPreview(m.foto || '');
    form._refrescarPersona();
    dlg.showModal();
  });
});

// Supervisor: el admin elige qué proyectos ve (rellena el modal con los suyos)
document.querySelectorAll('[data-config-super]').forEach((btn) => {
  btn.addEventListener('click', () => {
    let d;
    try { d = JSON.parse(btn.dataset.configSuper); } catch (_) { return; }
    const dlg = document.getElementById('dlg-config-super');
    if (!dlg) return;
    dlg.querySelector('#cs-id').value = d.id;
    dlg.querySelector('#cs-nombre').textContent = d.nombre || 'supervisor';
    const marcados = new Set((d.proyectos || []).map(Number));
    dlg.querySelectorAll('input[name="proyectos[]"]').forEach((c) => {
      c.checked = marcados.has(Number(c.value));
    });
    dlg.showModal();
  });
});

// Supervisor: alterna entre Kanban y Flujo (sus únicas dos vistas, solo lectura)
document.querySelectorAll('.sup-toggle').forEach((tog) => {
  const paneles = {
    kanban: document.querySelector('[data-vista-panel="kanban"]'),
    flujo:  document.querySelector('[data-vista-panel="flujo"]'),
  };
  tog.querySelectorAll('[data-sup-vista]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const v = btn.dataset.supVista;
      tog.querySelectorAll('[data-sup-vista]').forEach((b) => b.classList.toggle('active', b === btn));
      Object.keys(paneles).forEach((k) => { if (paneles[k]) paneles[k].hidden = k !== v; });
      if (v === 'flujo' && typeof dibujarFlujo === 'function') dibujarFlujo();
    });
  });
});

// Al usar el picker de color personalizado, marcar su radio automaticamente
document.querySelectorAll('.color-picker .cp-custom input[type="color"]').forEach((inp) => {
  const marcar = () => {
    const radio = inp.closest('.cp-custom').querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
  };
  inp.addEventListener('input', marcar);
  inp.addEventListener('click', marcar);
});

// Cerrar dialogs al hacer click en el fondo
document.querySelectorAll('dialog.dlg-meca').forEach((dlg) => {
  dlg.addEventListener('click', (e) => {
    if (e.target === dlg) dlg.close();
  });
});

// Comprimir fotos en el navegador antes de subirlas (evita el limite de PHP).
// Redimensiona a max 900px y convierte a JPEG; si algo falla, valida el tamano.
const LIMITE_SUBIDA = parseInt(document.body.dataset.limiteSubida || '2097152', 10);
document.querySelectorAll('.pp-file').forEach((input) => {
  input.addEventListener('change', async () => {
    const file = input.files && input.files[0];
    if (!file) return;
    try {
      const comprimida = await comprimirImagen(file, 900, 0.85);
      const dt = new DataTransfer();
      dt.items.add(new File([comprimida], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }));
      input.files = dt.files;
    } catch (e) {
      if (file.size > LIMITE_SUBIDA) {
        MC.toast('Esa foto pesa ' + (file.size / 1048576).toFixed(1) + ' MB y el límite es ' +
                 (LIMITE_SUBIDA / 1048576).toFixed(1) + ' MB. Usa una imagen más liviana.', 'error', 7000);
        input.value = '';
      }
    }
  });
});

function comprimirImagen(file, maxLado, calidad) {
  return new Promise((resolve, reject) => {
    const url = URL.createObjectURL(file);
    const img = new Image();
    img.onload = () => {
      URL.revokeObjectURL(url);
      const escala = Math.min(1, maxLado / Math.max(img.width, img.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(img.width * escala);
      canvas.height = Math.round(img.height * escala);
      canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
      canvas.toBlob((blob) => blob ? resolve(blob) : reject(new Error('sin blob')), 'image/jpeg', calidad);
    };
    img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('no es imagen')); };
    img.src = url;
  });
}

// Tabs genericos (.tabs-meca + .tab-panel), con memoria de la pestana activa
document.querySelectorAll('.tabs-meca').forEach((tabs) => {
  const clave = 'tab-' + (tabs.dataset.clave || location.pathname);
  const activar = (id) => {
    tabs.querySelectorAll('.tab-btn').forEach((b) => b.classList.toggle('active', b.dataset.tab === id));
    document.querySelectorAll('.tab-panel').forEach((p) => { p.hidden = p.dataset.panel !== id; });
  };
  tabs.querySelectorAll('.tab-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      activar(btn.dataset.tab);
      sessionStorage.setItem(clave, btn.dataset.tab);
    });
  });
  const porHash = location.hash.startsWith('#tab-') ? location.hash.slice(5) : null;
  const guardado = porHash || sessionStorage.getItem(clave);
  if (guardado && tabs.querySelector('[data-tab="' + guardado + '"]')) activar(guardado);
});

// "Más íconos": el selector de proyecto enseña primero los de Ajustes y
// despliega el resto del set aquí mismo, sin salir del formulario.
document.querySelectorAll('.icon-mas').forEach((btn) => {
  const picker = btn.previousElementSibling;
  if (!picker || !picker.classList.contains('icon-picker')) return;
  const txt = btn.querySelector('.icon-mas-txt');
  btn.addEventListener('click', () => {
    const abierto = btn.classList.toggle('abierto');
    picker.querySelectorAll('.icono-extra').forEach((l) => { l.hidden = !abierto; });
    if (txt) txt.textContent = abierto ? btn.dataset.menos : btn.dataset.mas;
  });
});

// Galeria de iconos: clic para elegir; el valor se arma solo en el hidden
const galeriaIconos = document.querySelector('.icon-galeria');
if (galeriaIconos) {
  const valor = document.getElementById('iconos-valor');
  const conteo = document.getElementById('iconos-conteo');
  const sincronizar = () => {
    const sel = [...galeriaIconos.querySelectorAll('.ig-btn.sel')].map((b) => b.dataset.icono);
    valor.value = sel.join('\n');
    if (conteo) conteo.textContent = sel.length;
  };
  galeriaIconos.addEventListener('click', (e) => {
    // closest() desde el <path> del SVG: el clic casi nunca cae en el <button>.
    const btn = e.target.closest('.ig-btn');
    if (!btn) return;
    btn.classList.toggle('sel');
    sincronizar();
  });
}

// Stepper de catalogos: un paso a la vez con navegacion
const stepper = document.querySelector('.stepper');
if (stepper) {
  const pasos = [...stepper.querySelectorAll('.paso')];
  const paneles = [...document.querySelectorAll('[data-paso-panel]')];
  const btnPrev = document.getElementById('paso-prev');
  const btnNext = document.getElementById('paso-next');
  const indicador = document.getElementById('paso-indicador');
  const total = pasos.length;
  const clave = 'paso-' + (stepper.dataset.clave || '');

  const ir = (n) => {
    n = Math.min(total, Math.max(1, n));
    pasos.forEach((p) => {
      const num = parseInt(p.dataset.paso, 10);
      p.classList.toggle('active', num === n);
      p.classList.toggle('hecho', num < n);
    });
    paneles.forEach((panel) => { panel.hidden = panel.dataset.pasoPanel !== String(n); });
    if (btnPrev) btnPrev.disabled = n === 1;
    if (btnNext) btnNext.disabled = n === total;
    if (indicador) indicador.textContent = 'Paso ' + n + ' de ' + total;
    sessionStorage.setItem(clave, n);
    return n;
  };

  let actual = parseInt(sessionStorage.getItem(clave), 10) || 1;
  actual = ir(actual);
  pasos.forEach((p) => p.addEventListener('click', () => { actual = ir(parseInt(p.dataset.paso, 10)); }));
  btnPrev?.addEventListener('click', () => { actual = ir(actual - 1); });
  btnNext?.addEventListener('click', () => { actual = ir(actual + 1); });
}

// Tarjetas de colores de marca: vista previa en vivo y contraste del texto
document.querySelectorAll('.tarjeta-color').forEach((card) => {
  const inp = card.querySelector('input[type="color"]');
  const hex = card.querySelector('.tc-hex');
  const aplicar = () => {
    const c = inp.value;
    card.style.setProperty('--tc', c);
    hex.textContent = c.toUpperCase();
    const r = parseInt(c.slice(1, 3), 16), g = parseInt(c.slice(3, 5), 16), b = parseInt(c.slice(5, 7), 16);
    card.classList.toggle('claro', (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.62);
  };
  inp.addEventListener('input', aplicar);
  aplicar();
});

// Ajustes: agregar y quitar filas de catalogos (estados, prioridades...)
let filaContador = 1000;
document.querySelectorAll('.btn-agregar-fila').forEach((btn) => {
  btn.addEventListener('click', () => {
    const tpl = document.getElementById(btn.dataset.plantilla);
    const lista = document.getElementById(btn.dataset.lista);
    if (!tpl || !lista) return;
    const alInicio = btn.dataset.insertar === 'inicio';
    const html = tpl.innerHTML.replaceAll('__i__', String(filaContador++));
    lista.insertAdjacentHTML(alInicio ? 'afterbegin' : 'beforeend', html);
    const fila = alInicio ? lista.firstElementChild : lista.lastElementChild;
    fila.querySelector('input:not([type="hidden"]):not([type="color"]):not(.input-icono)')?.focus();
  });
});

// mc-tabla: edicion en linea (lapiz <-> check); Enter confirma sin enviar el form
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.btn-editar-fila');
  if (!btn) return;
  const fila = btn.closest('.mc-fila');
  const dato = fila.querySelector('.mc-fila-dato');
  if (dato.readOnly) {
    dato.readOnly = false;
    fila.classList.add('editando');
    btn.innerHTML = '<i class="fa-solid fa-check"></i>';
    btn.title = 'Listo';
    dato.focus();
    dato.select();
  } else {
    dato.readOnly = true;
    fila.classList.remove('editando');
    btn.innerHTML = '<i class="fa-solid fa-pen"></i>';
    btn.title = 'Editar';
  }
});
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Enter' || !e.target.classList?.contains('mc-fila-dato')) return;
  e.preventDefault();   // que no envie todo el formulario
  e.target.closest('.mc-fila').querySelector('.btn-editar-fila').click();
});
document.addEventListener('click', (e) => {
  const btn = e.target.closest('.btn-quitar-fila');
  if (!btn) return;
  const lista = btn.closest('.ajuste-lista');
  if (lista && lista.children.length <= 1) {
    MC.toast('Debe quedar al menos una opción en el catálogo.', 'error');
    return;
  }
  btn.closest('.ajuste-fila').remove();
});

// Quitar toasts del DOM cuando termina su animacion de salida
document.querySelectorAll('.toast-float').forEach((t) => {
  t.addEventListener('animationend', (e) => {
    if (e.animationName === 'toast-out') t.remove();
  });
});

// Abrir el modal "ver como" por hash (para enlaces directos)
if (location.hash === '#abrir-ver-como') {
  document.getElementById('dlg-ver-como')?.showModal();
}

/* Respaldo de configuracion: muestra el nombre del .json elegido */
document.addEventListener('change', (e) => {
  const input = e.target.closest('.respaldo-archivo input[type="file"]');
  if (!input) return;
  const label = input.closest('.respaldo-archivo');
  const txt   = label.querySelector('span');
  const f     = input.files && input.files[0];
  label.classList.toggle('tiene-archivo', !!f);
  // El texto de "sin archivo" lo pone cada pantalla: este control lo usan el
  // respaldo (.json) y la carga del equipo (.xlsx / .csv).
  txt.innerHTML = f
    ? '<i class="fa-solid fa-file-circle-check"></i> ' + f.name
    : '<i class="fa-solid fa-file-arrow-up"></i> ' + (label.dataset.vacio || 'Elegir archivo .json');
});

/* =========================================================
   MecaTip — tooltip estilo Apple SOLO para botones de ícono
   (controles sin texto visible). Convierte su title en un
   tooltip propio y quita el nativo para que no salga doble.
   Funciona por delegación: cubre lo que se crea después.
   ========================================================= */
(() => {
  let tip = null, actual = null, timer = 0;
  const SEL = 'button, a, label, [role="button"], .accion-btn';

  const iconoSolo = (el) => el.textContent.replace(/\s+/g, '') === '';
  const textoDe = (el) => el.getAttribute('data-tip') || el.getAttribute('title') || '';

  const crear = () => {
    if (tip) return tip;
    tip = document.createElement('div');
    tip.className = 'meca-tip';
    document.body.appendChild(tip);
    return tip;
  };

  const colocar = (el) => {
    const r = el.getBoundingClientRect();
    const t = tip.getBoundingClientRect();
    const sep = 9;
    let arriba = true;
    let top = r.top - t.height - sep;
    if (top < 6) { top = r.bottom + sep; arriba = false; }   // no cabe arriba → abajo
    let left = r.left + r.width / 2 - t.width / 2;
    left = Math.max(6, Math.min(left, innerWidth - t.width - 6));
    tip.style.left = Math.round(left) + 'px';
    tip.style.top = Math.round(top) + 'px';
    tip.classList.toggle('abajo', !arriba);
    // posición de la flechita respecto al centro del botón
    const cx = r.left + r.width / 2 - left;
    tip.style.setProperty('--tip-x', Math.max(12, Math.min(cx, t.width - 12)) + 'px');
  };

  const mostrar = (el) => {
    const texto = textoDe(el);
    if (!texto) return;
    // pasa el title nativo a data-tip para que no aparezca el tooltip del navegador
    if (el.hasAttribute('title')) { el.setAttribute('data-tip', el.getAttribute('title')); el.removeAttribute('title'); }
    crear().textContent = texto;
    tip.classList.remove('visible');
    colocar(el);
    requestAnimationFrame(() => { colocar(el); tip.classList.add('visible'); });
  };

  const ocultar = () => { if (tip) tip.classList.remove('visible'); actual = null; clearTimeout(timer); };

  const candidato = (target) => {
    const el = target.closest?.(SEL);
    if (!el) return null;
    // Un data-tip explícito muestra el tooltip. Pero si el botón lleva su
    // etiqueta (.tab-txt) VISIBLE, no hace falta: el tooltip solo sale cuando el
    // texto está oculto (p. ej. pestañas en pantallas pequeñas).
    if (el.hasAttribute('data-tip')) {
      const etq = el.querySelector('.tab-txt');
      if (etq && etq.offsetParent !== null) return null;
      return el;
    }
    if (el.hasAttribute('title') && iconoSolo(el)) return el;
    return null;
  };

  document.addEventListener('pointerover', (e) => {
    const el = candidato(e.target);
    if (!el || el === actual) return;
    // quita el title de una vez para matar el tooltip nativo aunque no se muestre el nuestro
    if (el.hasAttribute('title')) { el.setAttribute('data-tip', el.getAttribute('title')); el.removeAttribute('title'); }
    actual = el;
    clearTimeout(timer);
    timer = setTimeout(() => mostrar(el), 340);
  });
  document.addEventListener('pointerout', (e) => {
    if (actual && (e.target.closest?.(SEL) === actual)) ocultar();
  });
  document.addEventListener('focusin', (e) => {
    const el = candidato(e.target);
    if (el) { actual = el; mostrar(el); }
  });
  document.addEventListener('focusout', ocultar);
  document.addEventListener('click', ocultar);      // al accionar, se cierra
  addEventListener('scroll', ocultar, true);
})();

/* Menú flotante en móvil: las tres rayitas abren/cierran el menú */
(() => {
  const burger = document.getElementById('sidebar-burger');
  const sidebar = document.querySelector('.sidebar');
  const menu = document.getElementById('sidebar-menu');
  if (!burger || !sidebar || !menu) return;

  const abrir = () => { sidebar.classList.add('menu-abierto'); burger.setAttribute('aria-expanded', 'true'); };
  const cerrar = () => { sidebar.classList.remove('menu-abierto'); burger.setAttribute('aria-expanded', 'false'); };

  burger.addEventListener('click', (e) => {
    e.stopPropagation();
    sidebar.classList.contains('menu-abierto') ? cerrar() : abrir();
  });
  // Cerrar al tocar fuera, al navegar o con Escape
  document.addEventListener('click', (e) => {
    if (!sidebar.classList.contains('menu-abierto')) return;
    if (!menu.contains(e.target) && e.target !== burger) cerrar();
  });
  menu.addEventListener('click', (e) => { if (e.target.closest('a')) cerrar(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') cerrar(); });
  addEventListener('resize', () => { if (innerWidth > 900) cerrar(); });
})();

/* Equipo: rechazar una solicitud de acceso (rellena el modal con su nombre) */
(() => {
  const dlg = document.getElementById('dlg-rechazar');
  if (!dlg) return;
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-rechazar]');
    if (!btn) return;
    dlg.querySelector('#rc-id').value = btn.dataset.rechazar;
    dlg.querySelector('#rc-nombre').textContent = btn.dataset.nombre || '';
    dlg.showModal();
  });
})();

/* Requerimientos: el selector de personas de los dos asistentes (el de alta,
   'nr', y el de asignar, 'dv'). Cada uno filtra SOLO su propia lista, lleva su
   contador y mantiene un espejo con los nombres marcados para que el paso de
   Revisión los liste: el asistente resume campos, y un montón de casillas no
   lo es. */
(() => {
  const sincronizar = (picker) => {
    const lista = document.querySelector(`[data-picker="${picker}"]`);
    if (!lista) return;
    const marcados = [...lista.querySelectorAll('input:checked')]
      .map(c => c.closest('.dv-persona')?.dataset.nombre || '');
    const n = document.querySelector(`[data-picker-n="${picker}"]`);
    if (n) n.textContent = marcados.length;
    const espejo = document.querySelector(`[data-picker-resumen="${picker}"]`);
    if (espejo) espejo.value = marcados.join(', ');
  };

  ['nr', 'dv', 're'].forEach(picker => {
    const lista = document.querySelector(`[data-picker="${picker}"]`);
    if (!lista) return;
    lista.addEventListener('change', () => sincronizar(picker));

    const rolSel = document.querySelector(`.js-${picker}-rol`);
    const buscar = document.querySelector(`.js-${picker}-buscar`);
    const vacio  = document.querySelector(`[data-picker-vacio="${picker}"]`);
    // Filtro combinado: por rol Y por nombre. Una fila se oculta (.filtrado) si
    // no cumple ambos.
    const aplicar = () => {
      const rol = rolSel ? rolSel.value : '';
      const q = (buscar ? buscar.value : '').trim().toLowerCase();
      let visibles = 0;
      lista.querySelectorAll('.dv-persona').forEach(fila => {
        const okRol = !rol || fila.dataset.rol === rol;
        const okQ = !q || (fila.dataset.nombre || '').toLowerCase().includes(q);
        const oculto = !(okRol && okQ);
        fila.classList.toggle('filtrado', oculto);
        if (!oculto) visibles++;
      });
      if (vacio) vacio.hidden = visibles > 0;
    };
    rolSel?.addEventListener('change', aplicar);
    buscar?.addEventListener('input', aplicar);
    sincronizar(picker);
  });

  /* El asistente de asignar se rellena con el requerimiento pulsado: id,
     título, quién lo tiene ahora y su plazo actual. Las fechas van rellenas
     porque asignar y mover el plazo son la misma operación: si salieran
     vacías, guardar borraría el plazo que ya había. */
  const dlg = document.getElementById('dlg-req-derivar');
  if (!dlg) return;
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-derivar]');
    if (!btn) return;
    dlg.querySelector('#dv-id').value = btn.dataset.derivar;
    dlg.querySelector('#dv-titulo').textContent = btn.dataset.titulo || '';
    if (window.MecaRT) window.MecaRT.set('dv-obs', '');   // la observación es de cada asignación
    // setFecha (y no .value) porque MecaDate sustituye el input por su propio
    // selector: sin avisarle, la fecha se guardaría pero no se vería.
    setFecha(dlg.querySelector('[data-req-fecha="dv-inicio"]'), btn.dataset.inicio);
    setFecha(dlg.querySelector('[data-req-fecha="dv-fin"]'), btn.dataset.fin);
    // Deja marcados a los que ya lo tienen (data-actual = "3,7")
    const actuales = (btn.dataset.actual || '').split(',').filter(Boolean);
    dlg.querySelectorAll('input[name="asignados[]"]').forEach(c => {
      c.checked = actuales.includes(c.value);
    });
    sincronizar('dv');
    actualizarDuracion(dlg.querySelector('form'));
    dlg.showModal();
  });
})();

/* Requerimientos: la ficha completa se abre al pulsar una fila. La lista solo
   muestra lo justo; aquí se ve todo y están las acciones. */
(() => {
  const dlg = document.getElementById('dlg-req-ficha');
  if (!dlg) return;
  const $ = (id) => dlg.querySelector('#' + id);

  document.addEventListener('click', (e) => {
    const fila = e.target.closest('.req-fila, .req-tr');
    if (!fila) return;
    const r = JSON.parse(fila.dataset.req);

    $('fq-titulo').textContent = r.titulo;
    // El detalle es HTML ya saneado en el servidor: se inyecta tal cual para
    // que se vean las tablas y el formato. Vacío → nota en gris.
    const detEl = $('fq-detalle');
    if (r.detalle) { detEl.innerHTML = r.detalle; detEl.classList.remove('vacio'); }
    else { detEl.textContent = 'Sin detalle.'; detEl.classList.add('vacio'); }
    // Documentos: los mismos chips que en el detalle de la tarea, con el ojo
    // para lo que el navegador sabe pintar y la flecha para lo que hay que
    // bajarse. El bloque se esconde si no hay ninguno.
    const docs = Array.isArray(r.adjuntos) ? r.adjuntos : [];
    const bloqueDocs = $('fq-docs-bloque');
    if (bloqueDocs) {
      bloqueDocs.hidden = docs.length === 0;
      $('fq-docs').innerHTML = docs.map((a) => {
        const ver = previsualizable(a.ext);
        return '<a class="adj-chip' + (ver ? '' : ' adj-bajar') + '" href="' + esc(a.ruta) + '"' +
          (ver ? '' : ' download') + ' target="_blank" rel="noopener"' +
          ' title="' + (ver ? 'Ver ' : 'Descargar ') + esc(a.nombre) + '">' +
          '<i class="fa-solid ' + iconoAdjunto(a.ext) + '"></i><span>' + esc(a.nombre) + '</span>' +
          '<i class="fa-solid ' + (ver ? 'fa-eye' : 'fa-download') + ' adj-chip-acc"></i></a>';
      }).join('');
    }
    $('fq-solicitante').textContent = r.solicitante || '—';
    $('fq-inicio').textContent = r.inicio || '—';
    $('fq-fin').textContent = r.fin || '—';
    $('fq-creado').textContent = r.creado || '—';

    $('fq-chips').innerHTML =
      `<span class="fq-chip fq-${r.estado}">${r.estadoTxt}</span>` +
      (r.prioridad ? `<span class="fq-chip">Prioridad ${r.prioridad}</span>` : '') +
      (r.vencido ? '<span class="fq-chip fq-vencido">Pasado de fecha</span>' : '');

    // Los nombres se pintan con textContent: vienen del equipo, pero un
    // nombre con "<" no tiene por qué romper la ficha.
    const personas = $('fq-personas');
    personas.textContent = '';
    if (r.personas.length) {
      r.personas.forEach(p => {
        const li = document.createElement('li');
        const b = document.createElement('b');
        b.textContent = p.nombre;
        const s = document.createElement('small');
        s.textContent = p.rol || '';
        li.append(b, s);
        // Colaborador: lo sumó quien ya lo tenía
        if (p.colab) {
          const c = document.createElement('small');
          c.className = 'fq-colab-tag';
          c.textContent = 'Colaborador · lo sumó ' + p.colab.split(' ')[0];
          li.append(c);
        }
        personas.append(li);
      });
    } else {
      const li = document.createElement('li');
      li.className = 'vacio';
      li.textContent = 'Todavía no está asignado a nadie.';
      personas.append(li);
    }

    ['fq-id-borrar', 'fq-id-estado', 'fq-id-terminar', 'fq-id-colab'].forEach(id => { const el = $(id); if (el) el.value = r.id; });
    // Sumar colaborador (bandeja): mientras siga abierto, y sin ofrecer a quien ya está
    const fCol = $('fq-colab');
    if (fCol) {
      fCol.hidden = r.cerrado;
      // Empieza plegado: un botón; al pulsarlo sale la lista y el «Sumar»
      const abrirCol = $('fq-colab-abrir');
      const formCol = $('fq-colab-form');
      const plegar = (abierto) => { formCol.hidden = !abierto; abrirCol.hidden = abierto; };
      plegar(false);
      abrirCol.onclick = () => { plegar(true); $('fq-colab-sel').focus(); };
      $('fq-colab-cancelar').onclick = () => plegar(false);
      const ya = new Set(String(r.asignados || '').split(',').filter(Boolean));
      const sel = $('fq-colab-sel');
      [...sel.options].forEach((o) => { if (o.value) { o.disabled = ya.has(o.value); o.hidden = ya.has(o.value); } });
      sel.value = '';
      sel.dispatchEvent(new Event('ms-sync'));
    }
    // Trazabilidad: de lo más nuevo a lo más viejo (textContent: lo escribe gente)
    const hist = $('fq-hist');
    if (hist) {
      const lista = Array.isArray(r.historial) ? r.historial.slice().reverse() : [];
      $('fq-hist-bloque').hidden = lista.length === 0;
      hist.textContent = '';
      lista.forEach((h) => {
        const li = document.createElement('li');
        const meta = document.createElement('small');
        meta.textContent = (h.fecha || '') + ' · ' + (h.quien || '');
        const t = document.createElement('span');
        t.textContent = h.texto || '';
        li.append(t, meta);
        hist.append(li);
      });
    }
    const idObs = $('fq-id-observar');
    if (idObs) idObs.value = r.id;

    // Observaciones: la conversación sobre este requerimiento. Se pintan con
    // textContent y no con innerHTML — las escribe gente y llevan lo que
    // Observaciones, en hilo: cada una con sus respuestas debajo y con
    // sangría. Se pintan con textContent y no con innerHTML — las escribe
    // gente y llevan dentro lo que quieran.
    const lista = $('fq-obs');
    if (lista) {
      const hilos = Array.isArray(r.observaciones) ? r.observaciones : [];
      const padre = $('fq-obs-padre');
      const tira = $('fq-obs-respondiendo');
      const enviar = $('fq-obs-enviar');
      // La caja es el editor con formato (o el textarea de antes, si no está)
      const caja = $('fq-obs-form') && ($('fq-obs-form').querySelector('.rt-area') || $('fq-obs-form').querySelector('textarea'));
      const ponPh = (t) => { if (!caja) return; if (caja.classList.contains('rt-area')) caja.dataset.ph = t; else caja.placeholder = t; };
      if (window.MecaRT && $('fq-obs-editor')) window.MecaRT.set('fq-obs-editor', '');

      // Deja de responder: la siguiente vuelve a abrir hilo.
      const soltar = () => {
        if (padre) padre.value = '0';
        if (tira) tira.hidden = true;
        if (enviar) enviar.textContent = 'Notificar';
        ponPh('Estimado, ¿qué pasó con este requerimiento? (puedes pegar tablas)');
      };
      const responderA = (o) => {
        if (padre) padre.value = o.id;
        if (tira) {
          tira.querySelector('span').textContent = 'Respondiendo a ' + o.autor;
          tira.hidden = false;
        }
        if (enviar) enviar.textContent = 'Responder';
        ponPh('Tu respuesta para ' + o.autor + '…');
        if (caja) caja.focus();
      };
      if (tira && !tira.dataset.listo) {
        tira.dataset.listo = '1';
        tira.querySelector('.fq-obs-cancelar').addEventListener('click', soltar);
      }

      const pinta = (o, esRespuesta) => {
        const li = document.createElement('li');
        if (esRespuesta) li.className = 'fq-obs-r';
        // Con formato: HTML ya saneado en el servidor (viñetas, tablas). Las de antes, texto plano
        const p = document.createElement(o.html ? 'div' : 'p');
        if (o.html) { p.className = 'rt-render fq-obs-rico'; p.innerHTML = o.texto; } else p.textContent = o.texto;
        const meta = document.createElement('small');
        meta.textContent = [o.autor, o.creado].filter(Boolean).join(' · ');
        li.append(p, meta);
        // Se responde AL HILO, no a la respuesta: un solo nivel de sangría.
        // El correo va igual a quien escribió lo que se está respondiendo.
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'fq-obs-responder';
        btn.innerHTML = '<i class="fa-solid fa-reply"></i> Responder';
        btn.addEventListener('click', () => responderA(o));
        li.append(btn);
        return li;
      };

      lista.textContent = '';
      let total = 0;
      hilos.forEach((o) => {
        total++;
        lista.append(pinta(o, false));
        (o.respuestas || []).forEach((rr) => { total++; lista.append(pinta(rr, true)); });
      });
      soltar();
      lista.hidden = total === 0;
      const vacio = $('fq-obs-vacio');
      if (vacio) vacio.hidden = total > 0;
      const cuantas = $('fq-obs-n');
      if (cuantas) {
        cuantas.textContent = total;
        cuantas.hidden = total === 0;
      }
      // A quién le va a llegar: se dice ANTES de escribir, que es cuando
      // importa. Una que abre hilo va a los responsables; una respuesta, a
      // quien escribió aquello que se responde (lo dice la tira de arriba).
      const para = $('fq-obs-para');
      if (para) {
        const nombres = (r.personas || []).map((p) => p.nombre.split(' ')[0]);
        para.textContent = nombres.length
          ? 'Al enviarla le llega por correo a ' + nombres.join(', ') + '. Al responder, a quien escribió esa observación.'
          : 'Nadie la tiene asignada, así que no saldrá ningún correo: queda solo anotada.';
      }
    }
    // Cerrarlo solo tiene sentido mientras siga abierto
    const resolver = $('fq-resolver');
    if (resolver) resolver.hidden = r.cerrado;

    // Observación de cierre (cómo se entregó): se muestra si existe
    const cierre = $('fq-cierre');
    if (cierre) {
      if (r.notaCierre) {
        $('fq-nota-cierre').textContent = r.notaCierre;
        const meta = [r.cerradoPor && ('por ' + r.cerradoPor), r.cerradoEn].filter(Boolean).join(' · ');
        $('fq-cierre-meta').textContent = meta;
        cierre.hidden = false;
      } else {
        cierre.hidden = true;
      }
    }
    // Formulario de "marcar terminado" (bandeja del responsable): solo mientras
    // siga abierto; si ya está cerrado, se muestra el aviso en su lugar.
    const fTerm = $('fq-terminar');
    if (fTerm) fTerm.hidden = r.cerrado;
    // Abierto, «Cerrar» va junto a «Marcar como terminado» (mitad y mitad); cerrado, va solo
    const cerrarPie = $('fq-cerrar-pie');
    if (cerrarPie && fTerm) cerrarPie.hidden = !r.cerrado;
    const yaCerr = $('fq-ya-cerrado');
    if (yaCerr) yaCerr.hidden = !r.cerrado;

    // "Asignar" reutiliza el modal de siempre, con lo que ya tiene marcado
    // y con su plazo actual en las fechas
    const derivar = $('fq-derivar');
    if (derivar) {
      derivar.dataset.derivar = r.id;
      derivar.dataset.titulo = r.titulo;
      derivar.dataset.actual = r.asignados;
      derivar.dataset.inicio = r.inicio || '';
      derivar.dataset.fin = r.fin || '';
      derivar.onclick = () => { dlg.close(); };
    }

    // "Editar": abre EL MISMO asistente que "Nuevo requerimiento", relleno con
    // este requerimiento (contenido + responsables + plazo actuales).
    const editar = $('fq-editar');
    if (editar) {
      editar.onclick = () => {
        const em = document.getElementById('dlg-req-editar');
        if (!em) return;
        em.querySelector('#re-id').value = r.id;
        em.querySelector('#re-titulo').value = r.titulo || '';
        em.querySelector('#re-solicitante').value = r.solicitante || '';
        setSelect(em.querySelector('.js-re-prioridad'), r.prioridadKey || 'media');
        setSelect(em.querySelector('.js-re-inst'), r.instituciones || []);
        window.MecaRT.set('re-detalle', r.detalle || '');
        window.MecaRT.set('re-obs', '');
        // Plazo actual (setFecha, no .value: MecaDate sustituye el input)
        setFecha(em.querySelector('[data-req-fecha="re-inicio"]'), r.inicio || '');
        setFecha(em.querySelector('[data-req-fecha="re-fin"]'), r.fin || '');
        // Deja marcados a los responsables actuales (r.asignados = "3,7")
        const actuales = (r.asignados || '').split(',').filter(Boolean);
        em.querySelectorAll('input[name="asignados[]"]').forEach(c => { c.checked = actuales.includes(c.value); });
        const lista = em.querySelector('[data-picker="re"]');
        if (lista) lista.dispatchEvent(new Event('change'));
        const fEm = em.querySelector('form');
        if (typeof actualizarDuracion === 'function') actualizarDuracion(fEm);
        dlg.close();
        em.showModal();
      };
    }
    dlg.showModal();
  });
})();

/* Horario de reuniones fijas: el mismo formulario sirve para añadir y para
   editar. Al pulsar el lápiz de una fila se rellena con sus datos y cambia la
   acción; "Cancelar" lo devuelve a modo alta. Tener dos formularios para lo
   mismo solo daría dos sitios donde arreglar cada cosa. */
(() => {
  const form = document.getElementById('form-rfija');
  if (!form) return;
  const $ = (id) => document.getElementById(id);

  const modoAlta = () => {
    form.classList.remove('editando');
    $('rf-accion').value = 'rfija_crear';
    $('rf-id').value = '';
    $('rf-titulo-form').textContent = 'Añadir al horario';
    $('rf-guardar').textContent = 'Añadir';
    $('rf-cancelar').hidden = true;
    form.reset();
  };

  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-rfija-editar]');
    if (!btn) return;
    const r = JSON.parse(btn.dataset.rfijaEditar);
    form.classList.add('editando');
    $('rf-accion').value = 'rfija_editar';
    $('rf-id').value = r.id;
    $('rf-titulo').value = r.titulo;
    $('rf-hora').value = r.hora;
    $('rf-titulo-form').textContent = 'Cambiando «' + r.titulo + '»';
    $('rf-guardar').textContent = 'Guardar';
    $('rf-cancelar').hidden = false;
    // El select de proyecto es el personalizado del panel: se le avisa
    const sel = form.querySelector('select[name="proyecto_id"]');
    if (sel) {
      sel.value = String(r.pid);
      sel.dispatchEvent(new Event('change', { bubbles: true }));
    }
    form.querySelectorAll('#rf-dias input').forEach((c) => {
      c.checked = r.dias.includes(parseInt(c.value, 10));
    });
    // Desplegar el formulario, que arranca cerrado
    document.getElementById('det-rfija')?.setAttribute('open', '');
    form.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  });

  $('rf-cancelar')?.addEventListener('click', modoAlta);
})();

/* =========================================================
   Editor de texto enriquecido (data-editor-rico). Un contenteditable con
   barra mínima y un <textarea> oculto que lleva el HTML en el formulario.
   Sirve para PEGAR contenido con formato y TABLAS (p. ej. un correo). Al
   pegar se limpia a una lista blanca para que la edición se vea limpia; el
   servidor vuelve a sanear siempre antes de guardar.
   ========================================================= */
(() => {
  const OK = new Set(['P', 'BR', 'HR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'STRIKE',
    'SUB', 'SUP', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'UL', 'OL', 'LI', 'BLOCKQUOTE',
    'A', 'TABLE', 'THEAD', 'TBODY', 'TFOOT', 'TR', 'TD', 'TH', 'COL', 'COLGROUP',
    'CODE', 'PRE', 'SPAN', 'DIV']);
  const ATTRS = { A: ['href', 'title'], TD: ['colspan', 'rowspan'], TH: ['colspan', 'rowspan'], COL: ['span'], COLGROUP: ['span'] };
  const FUERA = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'FORM', 'INPUT',
    'TEXTAREA', 'BUTTON', 'SELECT', 'META', 'LINK', 'BASE', 'NOSCRIPT', 'SVG', 'MATH', 'TITLE', 'O:P']);

  // Recorta un HTML pegado a la lista blanca (mismo criterio que el servidor).
  function limpiarPegado(html) {
    const tpl = document.createElement('template');
    tpl.innerHTML = html;
    const paso = (nodo) => {
      [...nodo.childNodes].forEach((n) => {
        if (n.nodeType === 8) { n.remove(); return; }          // comentario
        if (n.nodeType !== 1) return;                          // texto: se queda
        const tag = n.nodeName.toUpperCase();
        if (FUERA.has(tag)) { n.remove(); return; }
        paso(n);
        if (!OK.has(tag)) {                                    // desconocida: desenvolver
          const p = n.parentNode;
          while (n.firstChild) p.insertBefore(n.firstChild, n);
          p.removeChild(n);
          return;
        }
        const keep = ATTRS[tag] || [];
        [...n.attributes].forEach((a) => {
          if (!keep.includes(a.name.toLowerCase())) n.removeAttribute(a.name);
        });
      });
    };
    paso(tpl.content);
    return tpl.innerHTML;
  }

  const vacio = (area) => area.textContent.trim() === '' && !area.querySelector('table, img, hr');

  function init(rt) {
    const area = rt.querySelector('.rt-area');
    const fuente = rt.querySelector('.rt-fuente');
    if (!area || !fuente) return;

    const sync = () => {
      const vac = vacio(area);
      fuente.value = vac ? '' : area.innerHTML;
      rt.classList.toggle('rt-vacio', vac);
    };

    area.addEventListener('input', sync);
    area.addEventListener('blur', sync);

    // Pegado: se inserta el HTML ya limpio (conserva tablas y formato básico).
    area.addEventListener('paste', (e) => {
      const cb = e.clipboardData;
      if (!cb) return;
      const html = cb.getData('text/html');
      if (html) {
        e.preventDefault();
        document.execCommand('insertHTML', false, limpiarPegado(html));
        sync();
      }
      // Sin HTML en el portapapeles: se deja el pegado de texto normal.
    });

    // Barra de formato. mousedown + preventDefault para no perder la selección.
    rt.querySelectorAll('.rt-b[data-cmd]').forEach((b) => {
      b.addEventListener('mousedown', (e) => {
        e.preventDefault();
        const cmd = b.dataset.cmd;
        area.focus();
        if (cmd === 'createLink') {
          const url = prompt('URL del enlace (https://…):');
          if (url) document.execCommand('createLink', false, url);
        } else if (cmd === 'insertTable') {
          // Tabla de 3 columnas: encabezado y dos filas (Tab avanza; en la última celda, agrega una fila)
          const fila = (tag) => '<tr>' + [1, 2, 3].map((n) => '<' + tag + '>' + (tag === 'th' ? 'Columna ' + n : '<br>') + '</' + tag + '>').join('') + '</tr>';
          document.execCommand('insertHTML', false, '<table><thead>' + fila('th') + '</thead><tbody>' + fila('td') + fila('td') + '</tbody></table><p><br></p>');
        } else if (cmd === 'formatBlock') {
          // Alternar: si ya es ese bloque, se vuelve a párrafo.
          const val = (b.dataset.val || 'p').toUpperCase();
          const actual = (document.queryCommandValue('formatBlock') || '').toUpperCase();
          document.execCommand('formatBlock', false, actual === val ? 'P' : val);
        } else {
          document.execCommand(cmd, false, null);
        }
        sync();
      });
    });

    // En una tabla, Tab pasa a la siguiente celda (Mayús+Tab, a la anterior); al final, una fila nueva
    area.addEventListener('keydown', (e) => {
      if (e.key !== 'Tab') return;
      const sel = getSelection();
      const celda = sel && sel.anchorNode && (sel.anchorNode.nodeType === 1 ? sel.anchorNode : sel.anchorNode.parentElement)?.closest('td, th');
      if (!celda || !area.contains(celda)) return;
      e.preventDefault();
      const tabla = celda.closest('table');
      const celdas = [...tabla.querySelectorAll('td, th')];
      let i = celdas.indexOf(celda) + (e.shiftKey ? -1 : 1);
      if (i >= celdas.length) {
        const ultima = tabla.querySelector('tr:last-child');
        const nueva = document.createElement('tr');
        [...ultima.children].forEach(() => { const td = document.createElement('td'); td.innerHTML = '<br>'; nueva.appendChild(td); });
        (tabla.tBodies[0] || tabla).appendChild(nueva);
        i = celdas.length;
      }
      const destino = [...tabla.querySelectorAll('td, th')][Math.max(0, i)];
      if (destino) {
        const r = document.createRange();
        r.selectNodeContents(destino);
        r.collapse(true);
        sel.removeAllRanges();
        sel.addRange(r);
      }
      sync();
    });

    const form = rt.closest('form');
    if (form) form.addEventListener('submit', sync);
    sync();
  }

  document.querySelectorAll('[data-editor-rico]').forEach(init);

  // API para rellenar un editor por JS (formularios de edición que se llenan
  // en el navegador): MecaRT.set('id-del-contenedor', htmlSaneado).
  window.MecaRT = {
    set(id, html) {
      const rt = document.getElementById(id);
      if (!rt) return;
      const area = rt.querySelector('.rt-area');
      const fuente = rt.querySelector('.rt-fuente');
      if (!area || !fuente) return;
      area.innerHTML = html || '';
      fuente.value = html || '';
      rt.classList.toggle('rt-vacio', vacio(area));
    },
  };
})();

/* =========================================================
   Panel de requerimientos (ApexCharts 6). Los datos llegan en window.REQ_DASH
   desde req_dashboard.php; la librería se carga antes que este archivo.

   Criterios que se aplican igual en todos los gráficos:
   - Colores propios de datos, no los tokens de interfaz. Los del CSS sirven
     para texto y bordes, pero puestos uno al lado del otro dentro de un
     gráfico no se distinguen. Los de aquí están medidos contra la superficie
     real de cada tema (#e5eaf3 claro, #262c3a oscuro) y siguen separándose
     con daltonismo.
   - El color rojo/naranja de una institución identifica a la institución, no
     dice "va mal": por eso solo se usa donde la institución ES el dato (el
     reparto). Cumplido/pendiente va en verde contra gris.
   - Ejes de enteros calculados desde el máximo. Con 3 requerimientos, Apex
     repartía 6 marcas y al redondear salían etiquetas repetidas (0,0,1,1).
   - Marcas finas, punta redondeada, rejilla continua de 1px y 2px de hueco
     del color del fondo entre porciones (hueco, nunca un borde dibujado).
   - Se redibujan al cambiar de tema (evento meca:tema).
   ========================================================= */
(() => {
  const D = window.REQ_DASH;
  if (!D || typeof ApexCharts === 'undefined' || !document.getElementById('rd-inst')) return;

  const PALETAS = {
    claro: {
      sup: '#e5eaf3',
      hecho: '#129251',
      pendiente: '#8B9CB8',
      tinta: ['#ffffff', '#16233a'],          // texto sobre hecho / sobre pendiente
      serie: '#129251',
      situacion: ['#3DB878', '#129251', '#0B6234'],   // sin asignar → en curso → cerrados
      prioridad: { Alta: '#8C4711', Media: '#BE6A18', Baja: '#DD9436' },
      tintaPrio: ['#ffffff', '#ffffff', '#3a2408'],   // Alta / Media / Baja
      estado: { bien: '#0E8E4E', ojo: '#BE6A18', mal: '#B23A2E' },
      pista: '#cfd8e6',                       // canal vacío del medidor
    },
    oscuro: {
      sup: '#2E3A57',
      hecho: '#28AC69',
      pendiente: '#7E8FAC',
      tinta: ['#08301c', '#101827'],
      serie: '#28AC69',
      situacion: ['#7FE3AE', '#35C078', '#178A4E'],
      prioridad: { Alta: '#B0641A', Media: '#DE8A2E', Baja: '#F5B860' },
      tintaPrio: ['#ffffff', '#2a1a06', '#2a1a06'],
      estado: { bien: '#28AC69', ojo: '#DE8A2E', mal: '#E06A5C' },
      pista: '#333c4e',
    },
  };

  const vivos = [];

  const dibujar = () => {
    // Al repintar por cambio de tema hay que soltar los anteriores: si no,
    // Apex deja el SVG viejo debajo del nuevo.
    while (vivos.length) { try { vivos.pop().destroy(); } catch (e) { /* ya no estaba */ } }

    const oscuro = document.documentElement.classList.contains('dark');
    const P = oscuro ? PALETAS.oscuro : PALETAS.claro;
    const css = getComputedStyle(document.documentElement);
    const v = (n, d) => (css.getPropertyValue(n).trim() || d);
    const cText  = v('--c-text', oscuro ? '#e9edf6' : '#1e2430');
    const cMuted = v('--c-text-muted', oscuro ? '#b6c0d2' : '#6b7688');
    const cGrid  = oscuro ? 'rgba(255,255,255,.08)' : 'rgba(30,55,100,.10)';

    const base = {
      chart: {
        fontFamily: 'inherit', foreColor: cMuted, background: 'transparent',
        toolbar: { show: false },
        // Sin "animateGradually": si no, las barras crecen en fila india y
        // durante medio segundo unas miden menos de lo que valen.
        animations: { enabled: true, speed: 420, animateGradually: { enabled: false } },
        parentHeightOffset: 0,
      },
      grid: { borderColor: cGrid, strokeDashArray: 0, padding: { top: 0, right: 14, bottom: 0, left: 6 } },
      dataLabels: { enabled: false },
      tooltip: { theme: oscuro ? 'dark' : 'light' },
      legend: {
        position: 'bottom', horizontalAlign: 'center', fontSize: '12.5px', fontWeight: 600,
        labels: { colors: cText }, markers: { width: 9, height: 9, radius: 9 },
        itemMargin: { horizontal: 9, vertical: 3 },
      },
      noData: { text: 'Sin datos todavía', style: { color: cMuted, fontSize: '13px' } },
      states: { hover: { filter: { type: 'lighten', value: 0.08 } } },
    };

    // Eje de enteros: fija el tope y el número de marcas para que cada una
    // caiga justo en un entero y no se repitan las etiquetas.
    const ejeEntero = (max) => {
      const alto = Math.max(1, Math.ceil(max));
      const marcas = alto <= 5 ? alto : 5;
      return {
        min: 0, max: Math.ceil(alto / marcas) * marcas, tickAmount: marcas,
        forceNiceScale: false,
        labels: { formatter: (n) => String(Math.round(n)) },
      };
    };

    const req = (n) => n + (n === 1 ? ' requerimiento' : ' requerimientos');
    // Altura a partir del número de barras, para que cada una salga de ~22px
    // en vez de estirarse hasta llenar la tarjeta cuando hay dos o tres.
    const altoBarras = (n, extra) => Math.max(170, n * 48 + (extra || 70));
    const vacio = (id, msg) => {
      const el = document.getElementById(id);
      if (el) el.innerHTML = '<p class="rd-vacio">' + msg + '</p>';
    };

    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));

    /* Cuando solo hay UNA categoría no hay nada que comparar: una dona de una
       porción es un aro y una barra de una categoría es una raya de lado a
       lado. En ese caso el número es el gráfico, así que se escribe y ya.
       Cuando entren más datos, cada uno vuelve solo a su forma. */
    const cifra = (id, valor, etiqueta, color) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.innerHTML =
        '<div class="rd-cifra">' +
          '<b>' + esc(valor) + '</b>' +
          '<span>' +
            (color ? '<i class="rd-punto" style="background:' + esc(color) + '"></i>' : '') +
            esc(etiqueta) +
          '</span>' +
        '</div>';
    };
    const pintar = (id, opts) => {
      const el = document.getElementById(id);
      if (!el) return;
      el.innerHTML = '';
      try {
        const c = new ApexCharts(el, opts);
        c.render();
        vivos.push(c);
      } catch (e) {
        el.innerHTML = '<p class="rd-vacio">No se pudo dibujar el gráfico.</p>';
      }
    };

    /* 1) Cumplidos por institución. Barras apiladas: lo cumplido y lo que
       falta, en horizontal porque los nombres de institución son largos. La
       barra entera mide el total, así que ya no hace falta la etiqueta "1/3"
       encima de una barra que medía otra cosa. */
    const inst = D.inst || [];
    if (inst.length === 1) {
      cifra('rd-inst', inst[0].cumplidos + '/' + inst[0].total, 'cumplidos en ' + inst[0].nombre, inst[0].color);
    } else if (inst.length) {
      pintar('rd-inst', {
        ...base,
        chart: { ...base.chart, type: 'bar', stacked: true, height: altoBarras(inst.length, 104) },
        series: [
          { name: 'Cumplidos',  data: inst.map((i) => i.cumplidos) },
          { name: 'Pendientes', data: inst.map((i) => Math.max(0, i.total - i.cumplidos)) },
        ],
        colors: [P.hecho, P.pendiente],
        plotOptions: { bar: { horizontal: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '42%' } },
        stroke: { show: true, width: 2, colors: [P.sup] },
        xaxis: { categories: inst.map((i) => i.nombre), ...ejeEntero(Math.max(...inst.map((i) => i.total))) },
        yaxis: { labels: { style: { colors: cText, fontSize: '12.5px', fontWeight: 600 } } },
        dataLabels: {
          enabled: true,
          // Solo dentro del trozo que tenga sitio; si no, lo cuenta el eje.
          formatter: (val) => (val >= 1 ? val : ''),
          style: { fontSize: '12px', fontWeight: 700, colors: P.tinta },
          dropShadow: { enabled: false },
        },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-inst', 'Sin instituciones asignadas todavía.');
    }

    /* 2) Reparto por institución. Único gráfico donde manda el color propio
       de cada institución, porque aquí el dato ES la institución. */
    if (inst.length === 1) {
      cifra('rd-inst-dona', inst[0].total, 'todos de ' + inst[0].nombre, inst[0].color);
    } else if (inst.length) {
      pintar('rd-inst-dona', {
        ...base,
        chart: { ...base.chart, type: 'donut', height: 260 },
        series: inst.map((i) => i.total),
        labels: inst.map((i) => i.nombre),
        colors: inst.map((i) => i.color || P.serie),
        stroke: { width: 2, colors: [P.sup] },
        plotOptions: { pie: { donut: { size: '68%', labels: {
          show: true, name: { fontSize: '13px', color: cMuted },
          value: { fontSize: '24px', fontWeight: 700, color: cText },
          total: { show: true, label: 'Total', color: cMuted, formatter: () => inst.reduce((a, i) => a + i.total, 0) },
        } } } },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-inst-dona', 'Sin instituciones asignadas.');
    }

    /* 3) Situación de la carga. Las tres situaciones están ordenadas (sin
       asignar → en curso → cerrado), así que van en una rampa de un solo
       tono: el orden se lee por lo oscuro, no por el tono. */
    const sit = (D.sit || []).filter((s) => s[1] > 0);
    const ordenSit = ['Sin asignar', 'En curso', 'Cerrados'];
    if (sit.length === 1) {
      cifra('rd-sit', sit[0][1], 'todos en "' + sit[0][0] + '"',
            P.situacion[Math.max(0, ordenSit.indexOf(sit[0][0]))]);
    } else if (sit.length) {
      const orden = ordenSit;
      pintar('rd-sit', {
        ...base,
        chart: { ...base.chart, type: 'donut', height: 260 },
        series: sit.map((s) => s[1]),
        labels: sit.map((s) => s[0]),
        colors: sit.map((s) => P.situacion[Math.max(0, orden.indexOf(s[0]))]),
        stroke: { width: 2, colors: [P.sup] },
        plotOptions: { pie: { donut: { size: '68%', labels: {
          show: true, name: { fontSize: '13px', color: cMuted },
          value: { fontSize: '24px', fontWeight: 700, color: cText },
          total: { show: true, label: 'Total', color: cMuted, formatter: () => sit.reduce((a, s) => a + s[1], 0) },
        } } } },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-sit', 'Sin requerimientos.');
    }

    /* 4) Por prioridad. Era un radial de tres anillos que no se podía
       comparar: tres barras sobre la misma línea se leen de un vistazo. */
    const prio = (D.prio || []).filter((p) => p[1] > 0);
    if (prio.length === 1) {
      cifra('rd-prio', prio[0][1], 'todos de prioridad ' + prio[0][0].toLowerCase(),
            P.prioridad[prio[0][0]] || P.serie);
    } else if (prio.length) {
      pintar('rd-prio', {
        ...base,
        chart: { ...base.chart, type: 'bar', height: altoBarras(prio.length) },
        series: [{ name: 'Requerimientos', data: prio.map((p) => p[1]) }],
        colors: prio.map((p) => P.prioridad[p[0]] || P.serie),
        plotOptions: { bar: { horizontal: true, distributed: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '46%' } },
        xaxis: { categories: prio.map((p) => p[0]), ...ejeEntero(Math.max(...prio.map((p) => p[1]))) },
        yaxis: { labels: { style: { colors: cText, fontSize: '12.5px', fontWeight: 600 } } },
        legend: { show: false },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-prio', 'Sin prioridades registradas.');
    }

    /* 5) Recibidos por mes. Un área necesita recorrido: con uno o dos meses
       no hay tendencia que dibujar y quedaba un punto suelto en medio de una
       tarjeta vacía, así que hasta el tercer mes se cuentan en columnas. */
    const meses = D.meses || [];
    const hayTendencia = meses.length >= 3;
    if (meses.length === 1) {
      cifra('rd-meses', meses[0][1], 'recibidos en ' + meses[0][0], P.serie);
    } else if (meses.length) {
      pintar('rd-meses', {
        ...base,
        chart: { ...base.chart, type: hayTendencia ? 'area' : 'bar', height: 280, zoom: { enabled: false } },
        series: [{ name: 'Recibidos', data: meses.map((m) => m[1]) }],
        colors: [P.serie],
        plotOptions: { bar: {
          columnWidth: meses.length === 1 ? '56px' : '38%',
          borderRadius: 4, borderRadiusApplication: 'end',
          dataLabels: { position: 'top' },     // el número va sobre la columna
        } },
        dataLabels: hayTendencia ? { enabled: false } : {
          enabled: true, offsetY: -20,
          style: { fontSize: '12.5px', fontWeight: 700, colors: [cText] },
          background: { enabled: false }, dropShadow: { enabled: false },
        },
        stroke: hayTendencia ? { curve: 'smooth', width: 2, lineCap: 'round' } : { width: 0 },
        fill: { type: 'solid', opacity: hayTendencia ? 0.1 : 1 },
        markers: { size: 0, strokeColors: P.sup, strokeWidth: 2, hover: { size: 6 } },
        xaxis: {
          categories: meses.map((m) => m[0]),
          // 'on' pone cada mes justo debajo de su punto; por defecto Apex los
          // reparte entre marcas y el primero y el último quedan descolgados.
          tickPlacement: 'on',
          axisBorder: { show: false }, axisTicks: { show: false },
          labels: { style: { fontSize: '12px' }, hideOverlappingLabels: true },
        },
        yaxis: ejeEntero(Math.max(...meses.map((m) => m[1]))),
        legend: { show: false },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-meses', 'Sin histórico todavía.');
    }

    /* 6) Quién los cumplió. Una sola serie, un solo color: pintar cada
       persona de un color distinto no añadía información. */
    const quien = D.quien || [];
    if (quien.length === 1) {
      cifra('rd-quien', quien[0][1], 'cumplidos por ' + quien[0][0], P.serie);
    } else if (quien.length) {
      pintar('rd-quien', {
        ...base,
        chart: { ...base.chart, type: 'bar', height: altoBarras(quien.length) },
        series: [{ name: 'Cumplidos', data: quien.map((q) => q[1]) }],
        colors: [P.serie],
        plotOptions: { bar: { horizontal: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '46%' } },
        xaxis: { categories: quien.map((q) => q[0]), ...ejeEntero(Math.max(...quien.map((q) => q[1]))) },
        yaxis: { labels: { maxWidth: 170, style: { colors: cText, fontSize: '12.5px', fontWeight: 600 } } },
        legend: { show: false },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-quien', 'Nadie ha resuelto requerimientos todavía.');
    }

    /* 7) Prioridad por institución. Era un mapa de calor donde las filas en
       cero salían en blanco y parecía roto. Apilado por prioridad se ve el
       total de cada institución y su mezcla, con la misma rampa que el
       gráfico de prioridad. */
    const heat = (D.heat || []).filter((s) => (s.data || []).length);
    const hayHeat = heat.length && heat.some((s) => s.data.some((d) => d.y > 0));
    if (hayHeat && heat[0].data.length === 1) {
      // Una sola institución: cruzarla contra la prioridad no cruza nada.
      const mezcla = heat.filter((s) => s.data[0].y > 0)
                         .map((s) => s.data[0].y + ' ' + s.name.toLowerCase());
      cifra('rd-heat', heat.reduce((a, s) => a + s.data[0].y, 0),
            heat[0].data[0].x + ' · ' + mezcla.join(', '));
    } else if (hayHeat) {
      const cats = heat[0].data.map((d) => d.x);
      const totales = cats.map((_, i) => heat.reduce((a, s) => a + (s.data[i] ? s.data[i].y : 0), 0));
      pintar('rd-heat', {
        ...base,
        chart: { ...base.chart, type: 'bar', stacked: true, height: altoBarras(cats.length, 104) },
        series: heat.map((s) => ({ name: s.name, data: s.data.map((d) => d.y) })),
        colors: heat.map((s) => P.prioridad[s.name] || P.serie),
        plotOptions: { bar: { horizontal: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '42%' } },
        stroke: { show: true, width: 2, colors: [P.sup] },
        xaxis: { categories: cats, ...ejeEntero(Math.max(...totales)) },
        yaxis: { labels: { style: { colors: cText, fontSize: '12.5px', fontWeight: 600 } } },
        dataLabels: {
          enabled: true,
          formatter: (val) => (val >= 1 ? val : ''),
          // El naranja claro de "Baja" no aguanta texto blanco: cada
          // prioridad lleva la tinta que contrasta con su relleno.
          style: { fontSize: '12px', fontWeight: 700, colors: P.tintaPrio },
          dropShadow: { enabled: false },
        },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-heat', 'Sin datos por institución y prioridad.');
    }

    /* 8) Carga abierta por persona. Era un treemap: con dos personas eran dos
       bloques enormes de colores que parecían un semáforo. En barras se
       compara de verdad quién lleva más. */
    const carga = D.carga || [];
    if (carga.length === 1) {
      cifra('rd-carga', carga[0][1], 'abiertos, todos de ' + carga[0][0], P.pendiente);
    } else if (carga.length) {
      pintar('rd-carga', {
        ...base,
        chart: { ...base.chart, type: 'bar', height: altoBarras(carga.length) },
        series: [{ name: 'Abiertos', data: carga.map((c) => c[1]) }],
        colors: [P.pendiente],
        plotOptions: { bar: { horizontal: true, borderRadius: 4, borderRadiusApplication: 'end', barHeight: '46%' } },
        xaxis: { categories: carga.map((c) => c[0]), ...ejeEntero(Math.max(...carga.map((c) => c[1]))) },
        yaxis: { labels: { maxWidth: 170, style: { colors: cText, fontSize: '12.5px', fontWeight: 600 } } },
        legend: { show: false },
        tooltip: { ...base.tooltip, y: { formatter: (val) => req(val) } },
      });
    } else {
      vacio('rd-carga', 'Nadie tiene requerimientos abiertos.');
    }

    /* 9) Cumplimiento global. El número grande del panel: medidor limpio, sin
       degradado, y el color lo pone el tramo en el que cae. */
    if (typeof D.pct === 'number') {
      const col = D.pct >= 66 ? P.estado.bien : (D.pct >= 33 ? P.estado.ojo : P.estado.mal);
      pintar('rd-pct', {
        ...base,
        chart: { ...base.chart, type: 'radialBar', height: 270 },
        series: [D.pct],
        labels: ['Cumplimiento'],
        colors: [col],
        fill: { type: 'solid' },
        // A 0% la punta redondeada deja igualmente su media caña dibujada, y
        // se ve una pastilla suelta al inicio del canal que no significa nada.
        stroke: { lineCap: D.pct > 0 ? 'round' : 'butt' },
        plotOptions: {
          radialBar: {
            startAngle: -135, endAngle: 135,
            // Aro fino: el protagonista es el número del centro, no la rosca.
            hollow: { size: '76%' },
            track: { background: P.pista, strokeWidth: '100%', margin: 0 },
            dataLabels: {
              name: { color: cMuted, fontSize: '13px', fontWeight: 600, offsetY: 26 },
              value: { color: cText, fontSize: '38px', fontWeight: 700, offsetY: -6, formatter: (n) => Math.round(n) + '%' },
            },
          },
        },
      });
    }
  };

  dibujar();
  document.addEventListener('meca:tema', dibujar);
})();

/* =========================================================
   Buscador de tabla reutilizable (data-tabla-buscar). Filtra las filas del
   <tbody> de su tarjeta por el texto tecleado, comparando contra data-buscar
   (o el texto de la fila). Marca las filas que no coinciden con .fila-oculta
   y muestra el aviso [data-buscar-vacio] si no queda ninguna.
   ========================================================= */
document.querySelectorAll('[data-tabla-buscar]').forEach((input) => {
  const card = input.closest('.tabla-card') || document;
  // Filas de una tabla o tarjetas de una lista ([data-buscar-lista], p. ej. el equipo)
  const tbody = card.querySelector('table tbody') || card.querySelector('[data-buscar-lista]');
  if (!tbody) return;
  const vacio = card.querySelector('[data-buscar-vacio]');
  const contador = card.querySelector('[data-buscar-count]');
  // Sin tildes y en minúsculas por los dos lados: nadie escribe "planificación"
  // con tilde en un buscador, y sin esto "planificacion" no encontraba nada.
  // La ñ también se descompone, así que "ordonez" encuentra a "Ordoñez".
  const normalizar = (s) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
  const filtrar = () => {
    const q = normalizar(input.value.trim());
    let n = 0;
    Array.from(tbody.children).forEach((tr) => {
      if (tr.hasAttribute('data-no-buscar')) return;
      const hay = normalizar(tr.dataset.buscar || tr.textContent || '');
      const ok = !q || hay.includes(q);
      tr.classList.toggle('fila-oculta', !ok);
      if (ok) n++;
    });
    if (vacio) vacio.hidden = n > 0;
    if (contador) contador.textContent = n;
    // La tabla puede estar paginada: se le avisa para que reparta las filas
    // que SI coinciden, no las de la pagina en la que estabas.
    tbody.dispatchEvent(new CustomEvent('tabla-filtrada'));
  };
  input.addEventListener('input', filtrar);
});

/* =========================================================
   Observaciones en hilo: el botón "Responder" abre el cuadro del propio hilo
   y la respuesta se manda por AJAX, así la conversación no recarga la página.
   ========================================================= */
document.addEventListener('click', (e) => {
  const abrir = e.target.closest('.obs-responder');
  if (abrir) {
    const caja = abrir.closest('.obs-item')?.querySelector('.obs-responder-caja');
    if (!caja) return;
    caja.hidden = !caja.hidden;
    if (!caja.hidden) { MC.sonidoAbrir(); caja.querySelector('textarea')?.focus(); }
    return;
  }
  const cancelar = e.target.closest('.obs-responder-cancelar');
  if (cancelar) {
    const caja = cancelar.closest('.obs-responder-caja');
    if (caja) { caja.hidden = true; caja.querySelector('textarea').value = ''; }
  }
});

document.addEventListener('submit', async (e) => {
  const form = e.target.closest('.obs-responder-caja');
  if (!form) return;
  e.preventDefault();
  const txt = form.querySelector('textarea');
  if (!txt.value.trim()) { MC.sonidoError(); MC.toast('Escribe la respuesta.', 'error'); return; }
  const btn = form.querySelector('button[type="submit"], .btn-primary');
  btn.disabled = true;
  btn.classList.add('btn-enviando');   // el avión despega mientras va
  try {
    const fd = new FormData(form);
    fd.set('ajax', '1');
    const res = await fetch('actions.php', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'fetch' } });
    const data = await res.json();
    if (!data.ok) { MC.sonidoError(); MC.toast(data.error || 'No se pudo responder.', 'error'); return; }
    // La respuesta se mete ANTES del cuadro, al final de las que ya había.
    data.items.forEach((html) => {
      form.insertAdjacentHTML('beforebegin', html);
      MC.estrenar(form.previousElementSibling);
    });
    txt.value = '';
    form.hidden = true;
    MC.sonidoEnviar();
    MC.toast('Respuesta publicada', 'success', 1600);
  } catch {
    MC.sonidoError();
    MC.toast('Error de red al responder.', 'error');
  } finally {
    btn.disabled = false;
    btn.classList.remove('btn-enviando');
  }
});

/* Deploys: el botón "Cambios subidos" se marca en cuanto se pulsa. El envío es
   un POST normal y la página recarga en un parpadeo; sin esa señal el
   encargado no sabe si registró o si le falló el clic, y vuelve a pulsar.
   No se deshabilita el botón: un botón deshabilitado en pleno submit no manda
   su valor. Se marca el formulario y el segundo envío se descarta. */
document.addEventListener('submit', (e) => {
  const form = e.target;
  const btn = form.querySelector?.('[data-dep-btn]');
  if (!btn) return;
  if (form.dataset.depEnviado) { e.preventDefault(); return; }
  form.dataset.depEnviado = '1';
  btn.classList.add('dep-enviando');
});


/* Ajustes → Despliegues: los campos de alias siguen a "Proyectos a los que
   afecta cada subida". Salen todos en el HTML y aquí se deja ver solo lo
   marcado, al vuelo: si hubiera que guardar para verlos, marcar un proyecto y
   no encontrar su casilla parece que la pantalla está rota.

   Sin nada marcado valen todos, igual que en el resto del módulo.

   Los ocultos NO se deshabilitan: siguen enviando su alias, así que desmarcar
   un proyecto un rato no borra lo que ya se había escrito. */
document.querySelectorAll('[data-alias-de]').forEach((caja) => {
  const sel = document.querySelector('select[name="' + caja.dataset.aliasDe + '"]');
  if (!sel) return;
  const sincronizar = () => {
    const marcados = new Set([...sel.selectedOptions].map((o) => o.value));
    caja.querySelectorAll('[data-proy]').forEach((fila) => {
      fila.hidden = marcados.size > 0 && !marcados.has(fila.dataset.proy);
    });
  };
  sel.addEventListener('change', sincronizar);
  sincronizar();
});

/* =========================================================
   Cuenta regresiva a la próxima subida (Despliegues y la tira del
   dashboard, lib/Cronograma.php). [data-cuenta-regresiva] lleva data-hasta y
   data-ahora en segundos del SERVIDOR: así no importa si el reloj de
   la computadora está corrido. Con [data-corto]: «en 2 h 14 min»;
   sin él, el reloj «02:14:33». Al llegar a cero: «Subiendo…», y a
   los 90 s se recarga para ver la subida ya registrada.
   ========================================================= */
(function () {
  if (!document.querySelector('[data-cuenta-regresiva]')) return;
  const dos = (n) => String(n).padStart(2, '0');
  const pintar = () => {
    document.querySelectorAll('[data-cuenta-regresiva]').forEach((el) => {
      if (el._desfase === undefined) el._desfase = Number(el.dataset.ahora) * 1000 - Date.now();
      const falta = Math.max(0, Math.round((Number(el.dataset.hasta) * 1000 - (Date.now() + el._desfase)) / 1000));
      const d = Math.floor(falta / 86400);
      const h = Math.floor((falta % 86400) / 3600);
      const m = Math.floor((falta % 3600) / 60);
      const s = falta % 60;
      const reloj = el.querySelector('[data-cuenta-reloj]') || el;
      let txt;
      if (el.hasAttribute('data-corto')) {
        txt = falta <= 0 ? (el.dataset.ya || 'subiendo…')
          : falta < 60 ? 'en ' + s + ' s'
          : 'en ' + (d ? d + ' d ' : '') + (h ? h + ' h ' : '') + (d ? '' : m + ' min');
      } else {
        txt = falta <= 0 ? 'Subiendo…' : (d ? d + ' d ' : '') + dos(h) + ':' + dos(m) + ':' + dos(s);
      }
      if (reloj.textContent !== txt) reloj.textContent = txt.trim();
      el.classList.toggle('is-ya', falta <= 0);
      el.classList.toggle('is-cerca', falta > 0 && falta <= 1800);
      // Con data-ya (una reunión) no hay subida que esperar: no se recarga
      if (falta <= 0 && !el.dataset.ya && !window.__cuentaRecarga) window.__cuentaRecarga = setTimeout(() => location.reload(), 90000);
    });
  };
  pintar();
  setInterval(pintar, 1000);
})();

/* =========================================================
   Tu resumen (lib/resumen_tira.php): un carrusel en la tira del dashboard.
   Las diapositivas están apiladas en la misma celda; la activa entra con
   sus datos uno tras otro y los números cuentan desde cero. Pasa sola: la
   barrita del punto activo se llena y, al llenarse (animationend), toca la
   siguiente; así pausar es solo pausar la animación (mouse encima, foco
   dentro, pestaña oculta). Con «menos movimiento» no pasa sola.
   Puntos, flechas, ← → con el foco dentro y deslizar en el celular.
   ========================================================= */
(function () {
  const rt = document.querySelector('[data-rt]');
  if (!rt) return;
  const slides = [...rt.querySelectorAll('[data-rt-slide]')];
  const puntos = [...rt.querySelectorAll('[data-rt-ir]')];
  const nombre = rt.querySelector('[data-rt-nombre]');
  const pista = rt.querySelector('[data-rt-pista]');
  const quieto = matchMedia('(prefers-reduced-motion: reduce)').matches;
  let i = 0;

  const contar = (slide) => {
    slide.querySelectorAll('.rt-num').forEach((el) => {
      const n = Number(el.dataset.n) || 0;
      if (quieto || n < 2) { el.textContent = n; return; }
      const t0 = performance.now();
      const paso = (t) => {
        const k = Math.min(1, (t - t0) / 700);
        el.textContent = Math.round(n * (1 - Math.pow(1 - k, 3)));
        if (k < 1) requestAnimationFrame(paso);
      };
      requestAnimationFrame(paso);
    });
  };

  const ir = (j, manual) => {
    if (slides.length < 2) return;
    j = (j + slides.length) % slides.length;
    if (j === i && !manual) return;
    rt.dataset.dir = j > i || (i === slides.length - 1 && j === 0) ? 'sig' : 'ant';
    slides[i].classList.remove('is-activa');
    slides[i].inert = true;
    i = j;
    slides[i].inert = false;
    slides[i].classList.add('is-activa');
    puntos.forEach((p, k) => {
      p.classList.toggle('is-activo', k === i);
      if (k === i) p.setAttribute('aria-current', 'true'); else p.removeAttribute('aria-current');
    });
    // Reinicia la barrita del punto activo (la animación se vuelve a correr)
    const barra = puntos[i] && puntos[i].firstElementChild;
    if (barra) { barra.style.animation = 'none'; void barra.offsetWidth; barra.style.animation = ''; }
    if (nombre) nombre.textContent = puntos[i] ? puntos[i].getAttribute('aria-label') : '';
    // Tras tocarlo, quien usa lector de pantalla oye la diapositiva nueva
    if (manual) pista.setAttribute('aria-live', 'polite');
    contar(slides[i]);
  };

  rt.addEventListener('click', (e) => {
    const p = e.target.closest('[data-rt-ir]');
    const m = e.target.closest('[data-rt-mover]');
    if (p) ir(Number(p.dataset.rtIr), true);
    else if (m) ir(i + Number(m.dataset.rtMover), true);
  });
  rt.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
      if (e.target.closest('input, textarea, select')) return;
      e.preventDefault();
      ir(i + (e.key === 'ArrowRight' ? 1 : -1), true);
    }
  });
  rt.addEventListener('animationend', (e) => {
    if (e.animationName === 'rt-llenar' && e.target.parentElement === puntos[i]) ir(i + 1);
  });

  // Deslizar en el celular: un gesto horizontal claro cambia de diapositiva
  let x0 = null, y0 = 0;
  pista.addEventListener('pointerdown', (e) => { if (e.pointerType !== 'mouse') { x0 = e.clientX; y0 = e.clientY; } });
  pista.addEventListener('pointerup', (e) => {
    if (x0 === null) return;
    const dx = e.clientX - x0, dy = e.clientY - y0;
    x0 = null;
    if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy) * 1.5) ir(i + (dx < 0 ? 1 : -1), true);
  });
  pista.addEventListener('pointercancel', () => { x0 = null; });

  // Pausa: mouse encima, foco dentro o pestaña oculta
  const pausa = () => rt.classList.toggle('is-pausa', rt.matches(':hover') || rt.contains(document.activeElement) || document.hidden);
  ['pointerenter', 'pointerleave', 'focusin', 'focusout'].forEach((ev) => rt.addEventListener(ev, () => setTimeout(pausa)));
  document.addEventListener('visibilitychange', pausa);

  contar(slides[0]);
})();

/* =========================================================
   Menú circular de acciones rápidas (UI::menuRadial): el botón abre las
   opciones alrededor de un centro, cada una con su nombre por fuera.
   Como una rueda: la rueda del ratón (o las flechas) pasa de una opción a
   otra y la marcada se pinta de verde, con la aguja del centro apuntándola;
   el clic derecho abre la marcada y el clic izquierdo cierra, también encima
   de una opción. Sin ratón sigue igual: tocar una opción en el celular o
   Enter con el teclado la abren.
   Esc también cierra. El menú se mueve a <body> al abrirse para que nada lo recorte.
   ========================================================= */
(() => {
  const RADIO = 76;
  let abierto = null;
  const partes = (rad) => ({ btn: rad.querySelector('[data-rad-btn]'), menu: rad._menu || rad.querySelector('[data-rad-menu]'), fondo: rad._fondo || rad.querySelector('[data-rad-fondo]') });

  function abrir(rad) {
    const { btn, menu, fondo } = partes(rad);
    if (!rad._menu) { rad._menu = menu; rad._fondo = fondo; menu._rad = rad; fondo._rad = rad; document.body.append(fondo, menu); }
    const ops = [...menu.querySelectorAll('.rad-op')];
    const r = btn.getBoundingClientRect();
    // Cada opción en su ángulo, con su nombre hacia afuera
    ops.forEach((o, i) => {
      const ang = -Math.PI / 2 + (i * 2 * Math.PI) / ops.length;
      const x = Math.cos(ang) * RADIO;
      const y = Math.sin(ang) * RADIO;
      o.style.setProperty('--x', x.toFixed(1) + 'px');
      o.style.setProperty('--y', y.toFixed(1) + 'px');
      o.style.transitionDelay = (i * 30) + 'ms';
      o.classList.toggle('is-der', x > 12);
      o.classList.toggle('is-izq', x < -12);
      o.classList.toggle('is-arriba', Math.abs(x) <= 12 && y < 0);
      o.classList.toggle('is-abajo', Math.abs(x) <= 12 && y > 0);
    });
    // Se mide lo que ocupan los nombres de cada lado para que ninguno se salga de la pantalla
    menu.style.visibility = 'hidden';
    menu.hidden = false;
    const ancho = (lado) => Math.max(0, ...ops.filter((o) => o.classList.contains(lado)).map((o) => o.querySelector('.rad-txt').offsetWidth));
    const izq = Math.max(160, RADIO + 27 + ancho('is-izq') + 12);
    const der = Math.max(160, RADIO + 27 + ancho('is-der') + 12);
    const margenY = RADIO + 50;
    const margenAbajo = RADIO + 96;   // debajo del disco va la ayuda (rueda / clic derecho / clic)
    const cx = innerWidth < izq + der ? innerWidth / 2 : Math.min(Math.max(r.left + r.width / 2, izq), innerWidth - der);
    const cy = Math.min(Math.max(r.bottom + RADIO + 16, margenY), innerHeight - margenAbajo);
    menu.style.left = cx + 'px';
    menu.style.top = cy + 'px';
    menu.style.visibility = '';
    fondo.hidden = false;
    requestAnimationFrame(() => menu.classList.add('is-abierto'));
    btn.setAttribute('aria-expanded', 'true');
    abierto = rad;
    menu._ang = null;
    marcar(Math.max(0, ops.findIndex((o) => o.classList.contains('is-actual'))), false);
    setTimeout(() => (ops[menu._sel || 0] || menu.querySelector('[data-rad-cerrar]')).focus(), 60);
  }

  // Marca la opción i (la que abre el clic derecho): verde, con la aguja girando
  // por el camino corto hacia ella
  function marcar(i, enfocar = true) {
    if (!abierto) return;
    const { menu } = partes(abierto);
    const ops = [...menu.querySelectorAll('.rad-op')];
    if (!ops.length) return;
    const k = ((i % ops.length) + ops.length) % ops.length;
    ops.forEach((o, j) => o.classList.toggle('is-sel', j === k));
    const destino = -90 + (k * 360) / ops.length;
    const antes = menu._ang == null ? destino : menu._ang;
    menu._ang = antes + ((((destino - antes) % 360) + 540) % 360 - 180);
    menu.style.setProperty('--ang', menu._ang + 'deg');
    menu._sel = k;
    if (enfocar) ops[k].focus({ preventScroll: true });
  }
  const indice = (op) => [...partes(abierto).menu.querySelectorAll('.rad-op')].indexOf(op);

  function cerrar(devolverFoco = true) {
    if (!abierto) return;
    const rad = abierto;
    const { btn, menu, fondo } = partes(rad);
    abierto = null;
    menu.classList.remove('is-abierto');
    fondo.hidden = true;
    setTimeout(() => { if (!menu.classList.contains('is-abierto')) menu.hidden = true; }, 220);
    btn.setAttribute('aria-expanded', 'false');
    if (devolverFoco) btn.focus();
  }

  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-rad-btn]');
    if (b) {
      const rad = b.closest('[data-rad]');
      if (abierto === rad) cerrar(); else { cerrar(false); abrir(rad); }
      return;
    }
    if (!abierto) return;
    const op = e.target.closest('.rad-op');
    // Clic izquierdo fuera de una opción (fondo, disco, centro): se cierra
    if (!op) { cerrar(); return; }
    // Abre su ventana o su pestaña; los enlaces y las descargas siguen su camino
    const dlg = op.dataset.radDlg ? document.getElementById(op.dataset.radDlg) : null;
    const vista = op.dataset.radVista ? document.querySelector('[data-vista="' + op.dataset.radVista + '"]') : null;
    cerrar(false);
    if (dlg && dlg.showModal) { e.preventDefault(); dlg.showModal(); }
    if (vista) { e.preventDefault(); vista.click(); window.scrollTo({ top: vista.closest('.vista-fila').getBoundingClientRect().top + scrollY - 80, behavior: 'smooth' }); }
  });

  document.addEventListener('keydown', (e) => {
    if (!abierto) return;
    const { menu } = partes(abierto);
    if (e.key === 'Escape') { e.preventDefault(); cerrar(); return; }
    if (['ArrowRight', 'ArrowDown'].includes(e.key)) { e.preventDefault(); marcar((menu._sel || 0) + 1); }
    if (['ArrowLeft', 'ArrowUp'].includes(e.key)) { e.preventDefault(); marcar((menu._sel || 0) - 1); }
    if (e.key === 'Tab') {
      // El foco se queda dentro del menú mientras está abierto
      const todos = [...menu.querySelectorAll('.rad-op, [data-rad-cerrar]')];
      const j = todos.indexOf(document.activeElement);
      e.preventDefault();
      todos[(j + (e.shiftKey ? -1 : 1) + todos.length) % todos.length].focus();
    }
  });
  // Clic izquierdo con ratón sobre una opción: cierra sin abrirla. Va en captura
  // para que ni el enlace, ni el formulario, ni la vista ([data-subvista]) se
  // enteren. El clic del teclado y el que dispara el clic derecho (op.click())
  // llegan con detail 0 y sí abren.
  let tipoPuntero = '';
  document.addEventListener('pointerdown', (e) => { tipoPuntero = e.pointerType; }, true);
  document.addEventListener('click', (e) => {
    if (!abierto || e.detail === 0 || tipoPuntero !== 'mouse' || !e.target.closest('.rad-op')) return;
    e.preventDefault();
    e.stopImmediatePropagation();
    cerrar();
  }, true);

  // La rueda del ratón pasa a la opción siguiente o a la anterior (y la página
  // no se mueve). Se juntan los toques cortos del trackpad para no saltar varias.
  let acum = 0, ultimoGiro = 0, ultimoPaso = 0;
  addEventListener('wheel', (e) => {
    if (!abierto) return;
    e.preventDefault();
    const d = (Math.abs(e.deltaY) >= Math.abs(e.deltaX) ? e.deltaY : e.deltaX) * (e.deltaMode === 1 ? 33 : e.deltaMode === 2 ? 400 : 1);
    const ahora = performance.now();
    if (ahora - ultimoGiro > 250) acum = 0;
    ultimoGiro = ahora;
    acum += d;
    if (Math.abs(acum) < 40 || ahora - ultimoPaso < 110) return;
    ultimoPaso = ahora;
    marcar((partes(abierto).menu._sel || 0) + Math.sign(acum));
    acum = 0;
  }, { passive: false });

  // Clic derecho: abre la opción marcada (como pulsarla)
  document.addEventListener('contextmenu', (e) => {
    if (!abierto) return;
    e.preventDefault();
    const { menu } = partes(abierto);
    const op = menu.querySelectorAll('.rad-op')[menu._sel || 0];
    if (op) op.click();
  });

  // Pasar el ratón por una opción o llegar a ella con Tab también la marca
  document.addEventListener('pointerover', (e) => {
    const op = abierto && e.pointerType === 'mouse' && e.target.closest('.rad-op');
    if (op && indice(op) >= 0) marcar(indice(op), false);
  });
  document.addEventListener('focusin', (e) => {
    const op = abierto && e.target.closest && e.target.closest('.rad-op');
    if (op && indice(op) >= 0 && !op.classList.contains('is-sel')) marcar(indice(op), false);
  });

  addEventListener('resize', () => cerrar(false));
})();
