/* Mecapacito Admin - interacciones del panel */

/* =========================================================
   MC — componente de alertas Mecapacito.
   Nunca usar alert()/confirm() nativos: MC.toast y MC.confirm.
   ========================================================= */
const MC = {
  _iconos: { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info' },

  /** Toast apilable con barra de tiempo. tipo: success | error | info */
  toast(mensaje, tipo = 'info', duracion = 4500) {
    const cont = document.getElementById('mc-toasts');
    if (!cont) return;
    if (!this._iconos[tipo]) tipo = 'info';
    cont.insertAdjacentHTML('beforeend',
      '<div class="mc-toast mc-' + tipo + '" data-duracion="' + duracion + '">' +
        '<i class="fa-solid ' + this._iconos[tipo] + '"></i>' +
        '<div class="mc-toast-txt"></div>' +
        '<button type="button" class="mc-toast-x" title="Cerrar"><i class="fa-solid fa-xmark"></i></button>' +
        '<span class="mc-toast-barra"></span>' +
      '</div>');
    const el = cont.lastElementChild;
    el.querySelector('.mc-toast-txt').textContent = mensaje;
    this._activarToast(el);
  },

  /** Cableado de un toast (tambien para los que renderiza PHP). */
  _activarToast(el) {
    const cerrar = () => {
      if (el.classList.contains('out')) return;
      el.classList.add('out');
      el.addEventListener('animationend', () => el.remove(), { once: true });
    };
    el.querySelector('.mc-toast-x').addEventListener('click', cerrar);
    const barra = el.querySelector('.mc-toast-barra');
    barra.style.animationDuration = (parseInt(el.dataset.duracion, 10) || 4500) + 'ms';
    barra.addEventListener('animationend', cerrar);
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
            '<button type="button" class="btn-outline btn-meca mcc-no"></button>' +
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

// Activar los toasts que ya vienen renderizados por PHP (mensajes flash)
document.querySelectorAll('#mc-toasts .mc-toast').forEach((t) => MC._activarToast(t));

// Formularios con confirmacion propia: <form data-confirmar="mensaje">
document.querySelectorAll('form[data-confirmar]').forEach((form) => {
  form.addEventListener('submit', (e) => {
    if (form.dataset.confirmado === '1') return;
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
  });
});

// Colapsar / expandir la barra lateral (persistido en localStorage)
const sidebarToggle = document.getElementById('sidebar-toggle');
if (sidebarToggle) {
  sidebarToggle.addEventListener('click', () => {
    const min = document.documentElement.classList.toggle('sb-collapsed');
    localStorage.setItem('meca-sidebar', min ? 'min' : 'full');
  });
}

// Modo oscuro configurable (persistido en localStorage)
const themeToggle = document.getElementById('theme-toggle');
if (themeToggle) {
  themeToggle.addEventListener('click', () => {
    const dark = document.documentElement.classList.toggle('dark');
    localStorage.setItem('meca-theme', dark ? 'dark' : 'light');
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
    row.addEventListener('click', () => {
      seleccionar(row.dataset.persona);
      sessionStorage.setItem(claveSel, row.dataset.persona);
    });
  });
  const guardada = sessionStorage.getItem(claveSel);
  if (guardada) seleccionar(guardada);
}

// Vista Tabla / Flujo en la pagina de proyecto (con memoria por URL)
const vistaToggle = document.querySelector('.vista-toggle');
if (vistaToggle) {
  const claveVista = 'vista-' + location.pathname + location.search;
  const activarVista = (v) => {
    vistaToggle.querySelectorAll('[data-vista]').forEach((b) => b.classList.toggle('active', b.dataset.vista === v));
    document.querySelectorAll('[data-vista-panel]').forEach((p) => { p.hidden = p.dataset.vistaPanel !== v; });
    if (v === 'flujo') dibujarFlujo();
  };
  vistaToggle.querySelectorAll('[data-vista]').forEach((btn) => {
    btn.addEventListener('click', () => {
      activarVista(btn.dataset.vista);
      sessionStorage.setItem(claveVista, btn.dataset.vista);
    });
  });
  const porHashVista = location.hash.startsWith('#vista-') ? location.hash.slice(7) : null;
  const vGuardada = porHashVista || sessionStorage.getItem(claveVista);
  if (vGuardada && vistaToggle.querySelector('[data-vista="' + vGuardada + '"]')) activarVista(vGuardada);
}

// Conectores SVG entre tareas dependientes (vista Flujo)
function dibujarFlujo() {
  const wrap = document.getElementById('flujo-wrap');
  const svg = document.getElementById('flujo-lineas');
  if (!wrap || !svg) return;

  // Alinear cada nodo a la altura de su dependencia (flechas casi rectas)
  wrap.querySelectorAll('.flujo-nodo').forEach((n) => { n.style.marginTop = ''; });
  wrap.querySelectorAll('.flujo-nodo').forEach((nodo) => {
    const depId = nodo.dataset.dep;
    if (!depId || depId === '0') return;
    const origen = document.getElementById('fn-' + depId);
    if (!origen) return;
    const delta = origen.getBoundingClientRect().top - nodo.getBoundingClientRect().top;
    if (delta > 0) nodo.style.marginTop = delta + 'px';
  });

  const caja = wrap.getBoundingClientRect();
  svg.setAttribute('width', wrap.scrollWidth);
  svg.setAttribute('height', wrap.scrollHeight);
  const color = getComputedStyle(wrap).getPropertyValue('--pc').trim() || '#2B76F7';
  let trazos = '<defs><marker id="flecha" viewBox="0 0 10 10" refX="8" refY="5" markerWidth="5.5" markerHeight="5.5" orient="auto-start-reverse">' +
               '<path d="M 0 1.5 L 8 5 L 0 8.5 z" fill="' + color + '"/></marker></defs>';
  wrap.querySelectorAll('.flujo-nodo').forEach((nodo) => {
    const depId = nodo.dataset.dep;
    if (!depId || depId === '0') return;
    const origen = document.getElementById('fn-' + depId);
    if (!origen) return;
    const a = origen.getBoundingClientRect();
    const b = nodo.getBoundingClientRect();
    const x1 = a.right - caja.left + wrap.scrollLeft + 2;
    const y1 = a.top + a.height / 2 - caja.top + wrap.scrollTop;
    const x2 = b.left - caja.left + wrap.scrollLeft - 8;
    const y2 = b.top + b.height / 2 - caja.top + wrap.scrollTop;
    const cx = Math.max(34, (x2 - x1) * 0.55);
    trazos += '<circle cx="' + x1 + '" cy="' + y1 + '" r="4" fill="' + color + '"/>' +
              '<path d="M ' + x1 + ' ' + y1 + ' C ' + (x1 + cx) + ' ' + y1 + ', ' + (x2 - cx) + ' ' + y2 + ', ' + x2 + ' ' + y2 + '"' +
              ' fill="none" stroke="' + color + '" stroke-width="2.5" stroke-opacity=".8" stroke-linecap="round" marker-end="url(#flecha)"/>';
  });
  svg.innerHTML = trazos;
}
window.addEventListener('resize', () => {
  const panelFlujo = document.querySelector('[data-vista-panel="flujo"]');
  if (panelFlujo && !panelFlujo.hidden) dibujarFlujo();
});

// Kanban: arrastrar tarjetas entre columnas cambia el estado
const kanban = document.querySelector('.kanban');
if (kanban) {
  let arrastrando = null;
  kanban.addEventListener('dragstart', (e) => {
    const card = e.target.closest('.kb-card');
    if (!card) return;
    arrastrando = card;
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
      if (!arrastrando) return;
      const nuevoEstado = zona.dataset.estadoDrop;
      zona.appendChild(arrastrando);
      document.getElementById('kb-id').value = arrastrando.dataset.tarea;
      document.getElementById('kb-estado').value = nuevoEstado;
      document.getElementById('frm-kanban').submit();
    });
  });
}

// Paginador de la tabla de tareas (8 por página)
const cuerpoTabla = document.querySelector('[data-vista-panel="tabla"] .tabla-meca tbody');
if (cuerpoTabla) {
  const filas = [...cuerpoTabla.rows];
  const porPagina = 8;
  if (filas.length > porPagina) {
    const totalPaginas = Math.ceil(filas.length / porPagina);
    const cont = document.createElement('div');
    cont.className = 'paginador';
    document.querySelector('[data-vista-panel="tabla"] .tabla-scroll').after(cont);
    let pagina = 1;
    const pintar = () => {
      filas.forEach((f, i) => {
        f.style.display = (i >= (pagina - 1) * porPagina && i < pagina * porPagina) ? '' : 'none';
      });
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
    pintar();
  }
}

// Abrir modales por hash (ej. equipo.php#nuevo-colaborador)
if (location.hash === '#nuevo-colaborador') {
  const dlg = document.getElementById('dlg-nuevo-miembro');
  if (dlg) dlg.showModal();
}

// Rellenar y abrir el modal de edicion de tarea
document.querySelectorAll('[data-editar-tarea]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const t = JSON.parse(btn.dataset.editarTarea);
    const dlg = document.getElementById('dlg-editar-tarea');
    dlg.querySelector('#et-id').value = t.id;
    dlg.querySelector('#et-titulo').value = t.titulo;
    dlg.querySelector('#et-descripcion').value = t.descripcion;
    dlg.querySelector('#et-fecha').value = t.fecha_limite;
    dlg.querySelector('.js-et-asignado').value = t.asignado_id;
    dlg.querySelector('.js-et-prioridad').value = t.prioridad;
    dlg.querySelector('.js-et-estado').value = t.estado;
    const dep = dlg.querySelector('.js-et-depende');
    if (dep) {
      dep.value = String(t.depende_de || 0);
      // Una tarea no puede depender de si misma
      [...dep.options].forEach((o) => { o.disabled = o.value === String(t.id); });
    }
    dlg.showModal();
  });
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
    form.querySelector('[name="email"]').value = m.email || '';
    const selEquipo = form.querySelector('[name="equipo"]');
    if (selEquipo && m.equipo) selEquipo.value = m.equipo;
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
document.querySelectorAll('input[type="file"][accept*="image"]').forEach((input) => {
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
    const btn = e.target.closest('.ig-btn');
    if (!btn) return;
    btn.classList.toggle('sel');
    sincronizar();
  });

  // Agregar un icono que no este en la galeria (por clase FA)
  const extraBtn = document.getElementById('icono-extra-btn');
  const extraInp = document.getElementById('icono-extra');
  if (extraBtn && extraInp) {
    extraBtn.addEventListener('click', () => {
      const ic = extraInp.value.trim();
      if (!/^fa-[a-z0-9-]+$/.test(ic)) {
        MC.toast('Escribe una clase válida de Font Awesome, ej. fa-rocket', 'error');
        return;
      }
      let btn = galeriaIconos.querySelector('[data-icono="' + ic + '"]');
      if (!btn) {
        galeriaIconos.insertAdjacentHTML('afterbegin',
          '<button type="button" class="ig-btn sel" data-icono="' + ic + '" title="' + ic + '"><i class="fa-solid ' + ic + '"></i></button>');
      } else {
        btn.classList.add('sel');
      }
      extraInp.value = '';
      sincronizar();
    });
  }
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
