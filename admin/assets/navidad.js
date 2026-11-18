/* =========================================================
   Temporada navideña: los adornos que necesitan marcado. Lo carga
   UI::inicio solo entre las fechas de la temporada (Ajustes → Identidad).
   Todo es adorno: va con aria-hidden, no se puede pulsar y, si algo falla
   aquí, la página sigue igual. Con «reducir movimiento» nada se mueve y no
   caen copos (navidad.css).
   ========================================================= */
(function () {
  'use strict';
  var html = document.documentElement;
  if (!html.classList.contains('is-navidad')) return;
  var NS = 'http://www.w3.org/2000/svg';
  var uid = 0;

  /* -- La guirnalda de luces bajo la cabecera: hilo en ondas y un foco en cada una -- */
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
    var colores = ['oro', 'rojo', 'verde', 'azul'];
    var d = 'M0 2';
    var focos = '';
    for (var i = 0; i < n; i++) {
      var x = i * paso;
      d += ' Q' + (x + paso / 2) + ' 10 ' + (x + paso) + ' 2';
      var cx = x + paso / 2;
      focos += '<rect class="nv-casquillo" x="' + (cx - 1.6) + '" y="5.4" width="3.2" height="2.6" rx=".6"/>'
        + '<ellipse class="nv-foco nv-foco--' + colores[i % 4] + '" cx="' + cx + '" cy="11.2" rx="2.7" ry="3.5"/>';
    }
    caja.innerHTML = '<svg xmlns="' + NS + '" width="' + ancho + '" height="16" viewBox="0 0 ' + ancho + ' 16" focusable="false">'
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
    var id = 'nvS' + (++uid);
    var cy = hilo + 9;
    return '<span class="nv-adorno"><svg xmlns="' + NS + '" width="16" height="' + (hilo + 18) + '" viewBox="0 0 16 ' + (hilo + 18) + '" focusable="false">'
      + '<defs><linearGradient id="' + id + '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#FFF1C2"/>'
      + '<stop offset=".5" stop-color="#F1D27A"/><stop offset="1" stop-color="#C9A227"/></linearGradient></defs>'
      + '<line x1="8" y1="0" x2="8" y2="' + (hilo + 1) + '" stroke="var(--nv-hilo)" stroke-width="1"/>'
      + '<path fill="url(#' + id + ')" stroke="#9C7A12" stroke-width=".5" d="M8 ' + (cy - 7) + 'l2 4.6 5 .5-3.8 3.3 1.1 4.9L8 ' + (cy + 3.2) + 'l-4.3 2.6 1.1-4.9L1 ' + (cy - 1.9) + 'l5-.5z"/></svg></span>';
  }
  function colgantes() {
    var h1 = document.querySelector('.page-title') || document.querySelector('main h1, .bl-hu__h, .ru-cab h1');
    if (!h1 || h1.querySelector('.nv-adornos') || h1.closest('dialog, .sidebar, #ds-topbar')) return;
    var caja = document.createElement('span');
    caja.className = 'nv-adornos';
    caja.setAttribute('aria-hidden', 'true');
    caja.innerHTML = esfera('#A3232A', '#E25A5F', 10) + estrella(20) + esfera('#17603C', '#3FA36B', 14);
    h1.appendChild(caja);
  }

  /* -- Una ramita de acebo junto al logo del menú -- */
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
      + '<circle cx="10.4" cy="13.6" r="2" fill="#C2333A"/><circle cx="13.6" cy="13.6" r="2" fill="#D9404A"/><circle cx="12" cy="11.4" r="2" fill="#B22A31"/>'
      + '<circle cx="9.8" cy="13" r=".55" fill="#fff" opacity=".7"/><circle cx="13" cy="13" r=".55" fill="#fff" opacity=".7"/><circle cx="11.4" cy="10.8" r=".55" fill="#fff" opacity=".7"/>';
    marca.appendChild(s);
  }

  /* -- Copos en el menú (si el administrador los dejó encendidos) -- */
  function copos() {
    if (!html.hasAttribute('data-nv-copos')) return;
    if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
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
  var quieto = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var capa = null;
  function estallido(x, y) {
    if (!capa) {
      capa = document.createElement('div');
      capa.className = 'nv-chispas';
      capa.setAttribute('aria-hidden', 'true');
      document.body.appendChild(capa);
    }
    var tipos = ['oro', 'oro', 'rojo', 'verde', 'oro', 'copo', 'rojo', 'verde', 'oro'];
    var n = tipos.length;
    for (var i = 0; i < n; i++) {
      var ang = (Math.PI * 2 * i) / n + (Math.random() - .5) * .5;
      var dist = 22 + Math.random() * 20;
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

  function poner() {
    try { guirnalda(); } catch (e) { /* adorno */ }
    try { colgantes(); } catch (e) { /* adorno */ }
    try { acebo(); } catch (e) { /* adorno */ }
    try { copos(); } catch (e) { /* adorno */ }
  }
  var reloj = 0;
  window.addEventListener('resize', function () { clearTimeout(reloj); reloj = setTimeout(function () { try { guirnalda(); } catch (e) { /* adorno */ } }, 200); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', poner); else poner();
})();
