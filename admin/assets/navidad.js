/* =========================================================
   Temporada navideña: los adornos que necesitan marcado. Lo carga
   UI::inicio solo entre las fechas de la temporada (Ajustes → Identidad).
   Todo es adorno: va con aria-hidden, no se puede pulsar y, si algo falla
   aquí, la página sigue igual. Con «reducir movimiento» nada se mueve, no
   caen copos y Papá Noel no aparece (navidad.css).
   ========================================================= */
(function () {
  'use strict';
  var html = document.documentElement;
  if (!html.classList.contains('is-navidad')) return;
  var NS = 'http://www.w3.org/2000/svg';
  var uid = 0;
  var quieto = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  // Carpeta de este archivo: de ahí sale Papá Noel (navidad/santa.js), que se carga solo cuando aparece
  var yo = document.currentScript && document.currentScript.src;
  var base = yo ? yo.replace(/[^/]*(\?.*)?$/, '') : 'assets/';
  var version = yo && yo.indexOf('?') > -1 ? yo.slice(yo.indexOf('?')) : '';

  /* -- La guirnalda bajo la cabecera: hilo en ondas y focos que brillan (halo, reflejo y titileo) -- */
  var COLORES = { oro: ['#FFE7A3', '#F1D27A'], rojo: ['#FF8A8F', '#E25A5F'], verde: ['#8BE3B0', '#3FA36B'], azul: ['#A9C8FF', '#5B95F5'] };
  function guirnalda() {
    var cab = document.getElementById('ds-topbar');
    if (!cab) return;
    var caja = cab.querySelector('.nv-luces');
    if (!caja) {
      caja = document.createElement('div');
      caja.className = 'nv-luces';
      caja.setAttribute('aria-hidden', 'true');
      cab.appendChild(caja);
    }
    var ancho = Math.max(200, Math.round(cab.getBoundingClientRect().width));
    var paso = ancho < 700 ? 56 : 68;
    var n = Math.ceil(ancho / paso);
    var orden = ['oro', 'rojo', 'verde', 'azul'];
    var defs = '';
    Object.keys(COLORES).forEach(function (k) {
      defs += '<radialGradient id="nvH-' + k + '"><stop offset="0" stop-color="' + COLORES[k][1] + '" stop-opacity=".95"/>'
        + '<stop offset=".45" stop-color="' + COLORES[k][1] + '" stop-opacity=".35"/><stop offset="1" stop-color="' + COLORES[k][1] + '" stop-opacity="0"/></radialGradient>'
        + '<radialGradient id="nvF-' + k + '" cx="38%" cy="32%" r="75%"><stop offset="0" stop-color="#fff"/>'
        + '<stop offset=".35" stop-color="' + COLORES[k][0] + '"/><stop offset="1" stop-color="' + COLORES[k][1] + '"/></radialGradient>';
    });
    var d = 'M0 2', focos = '';
    for (var i = 0; i < n; i++) {
      var x = i * paso, cx = x + paso / 2, c = orden[i % 4];
      d += ' Q' + cx + ' 10 ' + (x + paso) + ' 2';
      // Cada foco titila a su ritmo: así la guirnalda parece viva y no un letrero
      var dur = (2.2 + ((i * 7) % 11) / 5).toFixed(2), ret = (-((i * 13) % 17) / 5).toFixed(2);
      focos += '<g class="nv-foco" style="--nv-d:' + dur + 's;--nv-r:' + ret + 's">'
        + '<circle class="nv-halo" cx="' + cx + '" cy="11.4" r="9" fill="url(#nvH-' + c + ')"/>'
        + '<rect class="nv-casquillo" x="' + (cx - 1.7) + '" y="5.2" width="3.4" height="2.8" rx=".7"/>'
        + '<ellipse class="nv-bombilla" cx="' + cx + '" cy="11.4" rx="2.9" ry="3.8" fill="url(#nvF-' + c + ')"/>'
        + '<ellipse cx="' + (cx - 0.9) + '" cy="10.1" rx=".8" ry="1.2" fill="#fff" opacity=".85"/></g>';
    }
    caja.innerHTML = '<svg xmlns="' + NS + '" width="' + ancho + '" height="22" viewBox="0 0 ' + ancho + ' 22" focusable="false"><defs>' + defs + '</defs>'
      + '<path class="nv-hilo" d="' + d + '"/>' + focos + '</svg>';
  }

  /* -- Tres esferas colgando del título de la pantalla: roja, estrella dorada y verde -- */
  function esfera(color, claro, hilo) {
    var id = 'nvE' + (++uid);
    return '<span class="nv-adorno"><svg xmlns="' + NS + '" width="14" height="' + (hilo + 16) + '" viewBox="0 0 14 ' + (hilo + 16) + '" focusable="false">'
      + '<defs><radialGradient id="' + id + '" cx="35%" cy="35%" r="70%"><stop offset="0" stop-color="#fff" stop-opacity=".85"/>'
      + '<stop offset=".25" stop-color="' + claro + '"/><stop offset="1" stop-color="' + color + '"/></radialGradient></defs>'
      + '<line x1="7" y1="0" x2="7" y2="' + hilo + '" stroke="var(--nv-hilo)" stroke-width="1"/>'
      + '<rect x="5" y="' + hilo + '" width="4" height="3" rx=".8" fill="var(--nv-oro)"/>'
      + '<circle cx="7" cy="' + (hilo + 9) + '" r="6" fill="url(#' + id + ')"/>'
      + '<path d="M3.2 ' + (hilo + 9) + 'h7.6" stroke="var(--nv-oro-claro)" stroke-width=".8" opacity=".8"/></svg></span>';
  }
  function estrella(hilo) {
    var id = 'nvS' + (++uid), cy = hilo + 9;
    return '<span class="nv-adorno"><svg xmlns="' + NS + '" width="16" height="' + (hilo + 18) + '" viewBox="0 0 16 ' + (hilo + 18) + '" focusable="false">'
      + '<defs><linearGradient id="' + id + '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFF1C2"/>'
      + '<stop offset=".5" stop-color="#F1D27A"/><stop offset="1" stop-color="#C9A227"/></linearGradient></defs>'
      + '<line x1="8" y1="0" x2="8" y2="' + (hilo + 1) + '" stroke="var(--nv-hilo)" stroke-width="1"/>'
      + '<path fill="url(#' + id + ')" stroke="#9C7A12" stroke-width=".5" d="M8 ' + (cy - 7) + 'l2 4.6 5 .5-3.8 3.3 1.1 4.9L8 ' + (cy + 3.2) + 'l-4.3 2.6 1.1-4.9L1 ' + (cy - 1.9) + 'l5-.5z"/></svg></span>';
  }
  function colgantes() {
    var h1 = document.querySelector('.page-title') || document.querySelector('.proyecto-hero h1, main h1, .bl-hu__h, .ru-cab h1');
    if (!h1 || h1.querySelector('.nv-adornos') || h1.closest('dialog, .sidebar, #ds-topbar')) return;
    var caja = document.createElement('span');
    caja.className = 'nv-adornos';
    caja.setAttribute('aria-hidden', 'true');
    caja.innerHTML = esfera('#A3232A', '#E25A5F', 10) + estrella(20) + esfera('#17603C', '#3FA36B', 14);
    h1.appendChild(caja);
  }

  /* -- El logo del menú con su gorrito (si no hay, una ramita de acebo) -- */
  function logo() {
    var marca = document.querySelector('.sidebar .sidebar-brand');
    if (!marca) return;
    var img = marca.querySelector('img.sidebar-logo');
    var conGorro = html.getAttribute('data-nv-logo');
    if (img && conGorro) {
      if (img.dataset.nvLogo) return;
      img.dataset.nvLogo = img.getAttribute('src');
      var prueba = new Image();
      prueba.onload = function () { img.src = conGorro; img.classList.add('nv-logo-gorro'); };
      prueba.onerror = acebo;
      prueba.src = conGorro;
      return;
    }
    acebo();
  }
  function acebo() {
    var marca = document.querySelector('.sidebar .sidebar-brand');
    if (!marca || marca.querySelector('.nv-acebo')) return;
    var s = document.createElementNS(NS, 'svg');
    s.setAttribute('class', 'nv-acebo');
    s.setAttribute('viewBox', '0 0 24 24');
    s.setAttribute('aria-hidden', 'true');
    s.setAttribute('focusable', 'false');
    s.innerHTML = '<path d="M12 13c-2-4-6-5-9-3 1 1 1.3 2.2.6 3.2 1.4-.2 2.4.6 2.6 1.9 1-.9 2.3-.9 3.3 0 .3-1.3 1.4-2 2.5-2.1z" fill="#1F7A4D" stroke="#C9A227" stroke-width=".6" stroke-linejoin="round"/>'
      + '<path d="M12 13c2-4 6-5 9-3-1 1-1.3 2.2-.6 3.2-1.4-.2-2.4.6-2.6 1.9-1-.9-2.3-.9-3.3 0-.3-1.3-1.4-2-2.5-2.1z" fill="#27925C" stroke="#C9A227" stroke-width=".6" stroke-linejoin="round"/>'
      + '<circle cx="10.4" cy="13.6" r="2" fill="#C2333A"/><circle cx="13.6" cy="13.6" r="2" fill="#D9404A"/><circle cx="12" cy="11.4" r="2" fill="#B22A31"/>';
    marca.appendChild(s);
  }

  /* -- Gorrito de Papá Noel sobre tu foto (cabecera, menú de la cuenta y Mi perfil), en cualquier tamaño -- */
  var GORRO = '<svg xmlns="' + NS + '" viewBox="0 0 64 48" focusable="false" aria-hidden="true">'
    + '<defs><linearGradient id="nvG1" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#E5484F"/><stop offset="1" stop-color="#A3232A"/></linearGradient>'
    + '<radialGradient id="nvG2" cx="40%" cy="35%" r="70%"><stop offset="0" stop-color="#fff"/><stop offset="1" stop-color="#E9E6DF"/></radialGradient></defs>'
    + '<path d="M9 34C11 18 22 6 37 5c9-.6 15 4 18 11 1.6 3.6 0 6.4-3 5.2-3-1.2-5.2-4.6-8.6-5C35.6 15.4 33 24 32 34z" fill="url(#nvG1)"/>'
    + '<path d="M33 9c6-1 12 1.5 15.5 6.5" fill="none" stroke="#F1D27A" stroke-width="1.6" stroke-linecap="round" opacity=".9"/>'
    + '<circle cx="54" cy="21" r="5.6" fill="url(#nvG2)"/>'
    + '<rect x="4" y="31" width="38" height="11" rx="5.5" fill="url(#nvG2)"/>'
    + '<path d="M8 36.5h30" stroke="#F1D27A" stroke-width="1" stroke-dasharray="1.2 3" stroke-linecap="round" opacity=".85"/></svg>';
  function gorritos() {
    document.querySelectorAll('.ds-cuenta__btn .avatar, .ds-cuenta__cab .avatar, .pf-hero-avatar').forEach(function (av) {
      if (av.parentElement && av.parentElement.classList.contains('nv-av')) return;
      var envoltura = document.createElement('span');
      envoltura.className = 'nv-av';
      av.parentNode.insertBefore(envoltura, av);
      envoltura.appendChild(av);
      var g = document.createElement('span');
      g.className = 'nv-gorro';
      g.innerHTML = GORRO;
      envoltura.appendChild(g);
    });
  }

  /* -- La cabecera del proyecto: nieve sobre el borde y unos regalos al pie -- */
  function proyecto() {
    var heroe = document.querySelector('.proyecto-hero');
    if (!heroe || heroe.querySelector('.nv-nevado')) return;
    var nieve = document.createElement('span');
    nieve.className = 'nv-nevado';
    nieve.setAttribute('aria-hidden', 'true');
    nieve.innerHTML = '<svg xmlns="' + NS + '" viewBox="0 0 400 22" preserveAspectRatio="none" focusable="false">'
      + '<path d="M0 0h400v6c-8 0-10 5-16 5s-8-4-14-4-9 9-15 9-6-9-13-9-8 5-15 5-9-5-16-5-8 12-15 12-7-12-14-12-9 6-16 6-8-4-15-4-8 8-14 8-8-8-15-8-9 4-16 4-7-6-14-6-8 10-14 10-7-10-14-10-9 5-16 5-8-5-15-5-8 7-15 7-7-7-14-7-9 4-16 4-7-3-13-3-7 8-13 8-8-8-14-8-9 5-15 5-6-5-12-5-7 6-13 6-6-6-12-6-8 4-14 4-5-2-9-2z" fill="#fff"/></svg>';
    heroe.appendChild(nieve);
    var regalos = document.createElement('span');
    regalos.className = 'nv-regalos';
    regalos.setAttribute('aria-hidden', 'true');
    regalos.innerHTML = '<svg xmlns="' + NS + '" viewBox="0 0 120 64" focusable="false">'
      + '<ellipse cx="60" cy="61" rx="52" ry="3" fill="rgb(15 23 42 / .12)"/>'
      // Uno azul InnoTech con lazo dorado, uno rojo con cinta verde y uno verde pequeño
      + '<rect x="10" y="24" width="40" height="36" rx="4" fill="#2B76F7"/><rect x="10" y="24" width="40" height="8" rx="3" fill="#1F5FD0"/>'
      + '<rect x="27" y="24" width="6" height="36" fill="#F1D27A"/><path d="M30 24c-6-8-14-6-12-1 1.5 3.5 8 2 12 1zm0 0c6-8 14-6 12-1-1.5 3.5-8 2-12 1z" fill="#E5C25A" stroke="#C9A227" stroke-width=".8"/>'
      + '<rect x="56" y="32" width="32" height="28" rx="4" fill="#C2333A"/><rect x="69" y="32" width="5" height="28" fill="#1F7A4D"/><rect x="56" y="43" width="32" height="5" fill="#1F7A4D"/>'
      + '<path d="M71.5 32c-4-6-10-5-8.5-1 1 2.6 6 1.6 8.5 1zm0 0c4-6 10-5 8.5-1-1 2.6-6 1.6-8.5 1z" fill="#27925C"/>'
      + '<rect x="92" y="42" width="20" height="18" rx="3" fill="#1F7A4D"/><rect x="100" y="42" width="4" height="18" fill="#F1D27A"/>'
      + '<circle cx="102" cy="41" r="3" fill="#E5C25A"/></svg>';
    heroe.appendChild(regalos);
  }

  /* -- Copos en el menú (si el administrador los dejó encendidos) -- */
  function copos() {
    if (!html.hasAttribute('data-nv-copos') || quieto) return;
    var menu = document.querySelector('.sidebar');
    if (!menu || menu.querySelector('.nv-copos')) return;
    var caja = document.createElement('span');
    caja.className = 'nv-copos';
    caja.setAttribute('aria-hidden', 'true');
    var h = '';
    for (var i = 0; i < 16; i++) {
      var oro = i % 4 === 0;
      h += '<i class="nv-copo' + (oro ? ' nv-copo--oro' : '') + '" style="left:' + (Math.random() * 96).toFixed(1) + '%;'
        + '--t:' + (3 + Math.random() * 3.5).toFixed(1) + 'px;--d:' + (13 + Math.random() * 11).toFixed(1) + 's;'
        + '--r:-' + (Math.random() * 20).toFixed(1) + 's;--x:' + (Math.random() * 24 - 12).toFixed(0) + 'px;'
        + '--o:' + (oro ? .75 : .45 + Math.random() * .3).toFixed(2) + '"></i>';
    }
    caja.innerHTML = h;
    menu.insertBefore(caja, menu.firstChild);
  }

  /* -- Al hacer clic en un botón: un estallido pequeño de destellos dorados, esferas y algún copo -- */
  var capa = null;
  function estallido(x, y, grande) {
    if (!capa) {
      capa = document.createElement('div');
      capa.className = 'nv-chispas';
      capa.setAttribute('aria-hidden', 'true');
      document.body.appendChild(capa);
    }
    var tipos = ['oro', 'oro', 'rojo', 'verde', 'oro', 'copo', 'rojo', 'verde', 'oro'];
    if (grande) tipos = tipos.concat(['oro', 'copo', 'oro', 'rojo', 'verde', 'oro']);
    var n = tipos.length;
    for (var i = 0; i < n; i++) {
      var ang = (Math.PI * 2 * i) / n + (Math.random() - .5) * .5;
      var dist = (grande ? 40 : 22) + Math.random() * (grande ? 34 : 20);
      var c = document.createElement('i');
      c.className = 'nv-chispa nv-chispa--' + tipos[i];
      c.style.left = x + 'px';
      c.style.top = y + 'px';
      c.style.setProperty('--dx', (Math.cos(ang) * dist).toFixed(1) + 'px');
      c.style.setProperty('--dy', (Math.sin(ang) * dist - 6).toFixed(1) + 'px');
      c.style.setProperty('--g', (Math.random() * 240 - 120).toFixed(0) + 'deg');
      capa.appendChild(c);
      setTimeout(function (el) { return function () { el.remove(); }; }(c), 800);
    }
  }
  if (!quieto) {
    document.addEventListener('pointerdown', function (e) {
      if (e.button !== 0) return;
      var b = e.target.closest && e.target.closest('button, [role="button"], .rs-boton, .mc-btn, .btn-meca, .tab-btn, .subvista-btn, .rs-vista__op, .ru-tabs__op, .mc-iconbtn');
      if (!b || b.disabled || b.closest('.sidebar')) return;
      estallido(e.clientX, e.clientY);
    }, { passive: true });
  }

  /* -- Papá Noel en 3D: de vez en cuando aparece de la nada en una esquina de abajo, saluda y se va.
        El módulo (y three.js) se carga recién la primera vez; solo dibuja mientras está en pantalla. -- */
  // Una vez por hora: la última visita se anota en el navegador, así no se repite al cambiar de
  // página ni con varias pestañas abiertas (sin localStorage, cuenta solo en esta página)
  var HORA = 3600000, CLAVE = 'nv-santa-ultima';
  function ultima() { try { return +localStorage.getItem(CLAVE) || 0; } catch (e) { return 0; } }
  function anotar() { try { localStorage.setItem(CLAVE, String(Date.now())); } catch (e) { /* sin almacenamiento */ } }
  var santa = null, enEscena = false, sinSanta = false;
  function visitaDeSanta() {
    if (sinSanta) return;
    var falta = ultima() + HORA - Date.now();
    if (falta > 1000) return programar(falta);   // ya vino hace menos de una hora (quizá en otra pestaña)
    if (enEscena || document.hidden || document.querySelector('dialog[open]')) return programar(20000);
    enEscena = true;
    anotar();
    (santa || (santa = import(base + 'navidad/santa.js' + version)))
      .then(function (m) { return m.mostrar({ lado: Math.random() < .7 ? 'der' : 'izq', onDestello: function (x, y) { estallido(x, y, true); } }); })
      .then(function () { enEscena = false; programar(HORA); },
            function () { enEscena = false; sinSanta = true; /* sin WebGL o sin el módulo: ya no se intenta */ });
  }
  function programar(ms) { setTimeout(visitaDeSanta, ms); }

  function poner() {
    try { guirnalda(); } catch (e) { /* adorno */ }
    try { colgantes(); } catch (e) { /* adorno */ }
    try { logo(); } catch (e) { /* adorno */ }
    try { gorritos(); } catch (e) { /* adorno */ }
    try { proyecto(); } catch (e) { /* adorno */ }
    try { copos(); } catch (e) { /* adorno */ }
    if (!quieto && html.hasAttribute('data-nv-santa') && 'noModule' in HTMLScriptElement.prototype) {
      // La primera vez, a los pocos segundos; después, cuando se cumpla la hora desde la última visita
      programar(Math.max(ultima() + HORA - Date.now(), 10000 + Math.random() * 8000));
    }
  }
  var reloj = 0;
  window.addEventListener('resize', function () { clearTimeout(reloj); reloj = setTimeout(function () { try { guirnalda(); } catch (e) { /* adorno */ } }, 200); });
  // Para probarlo desde la consola: nvSantaYa()
  window.nvSantaYa = function () { try { localStorage.removeItem(CLAVE); } catch (e) { /* nada */ } visitaDeSanta(); };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', poner); else poner();
})();
