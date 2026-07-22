/**
 * Escena animada del login: un flujo de tareas que avanza solo.
 *
 * Dibuja tarjetas de tarea en columnas (pendiente -> en curso -> revision ->
 * hecho) unidas por dependencias. Cada cierto tiempo sale un "pulso" por una
 * dependencia; cuando llega, la tarjeta destino cambia de estado y su barra de
 * avance sube. Todo con canvas 2D, sin librerias.
 *
 * Respeta prefers-reduced-motion y se pausa cuando la pestana no esta visible.
 */
(() => {
  const canvas = document.getElementById('lg-flujo');
  if (!canvas || !canvas.getContext) return;

  const ctx = canvas.getContext('2d');
  const lento = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // Paleta de la marca: azul, celeste y verde
  const COLORES = {
    pendiente: '#7f93ae',
    curso:     '#2B76F7',   // azul
    revision:  '#40CFFF',   // celeste
    hecho:     '#2BB673',   // verde
  };
  const ORDEN = ['pendiente', 'curso', 'revision', 'hecho'];

  let ancho = 0, alto = 0, dpr = 1;
  let nodos = [], enlaces = [], pulsos = [], motas = [];
  let t0 = 0, animId = 0;

  /* ---------- Construccion de la escena ---------- */

  // Cuatro columnas con 2-3 tarjetas cada una; posiciones en 0..1 para que
  // la escena se reacomode sola al cambiar el tamano de la ventana.
  const PLANO = [
    { col: 0, fila: 0.20, estado: 'hecho' },
    { col: 0, fila: 0.52, estado: 'hecho' },
    { col: 0, fila: 0.82, estado: 'curso' },
    { col: 1, fila: 0.14, estado: 'curso' },
    { col: 1, fila: 0.46, estado: 'pendiente' },
    { col: 1, fila: 0.76, estado: 'pendiente' },
    { col: 2, fila: 0.28, estado: 'pendiente' },
    { col: 2, fila: 0.62, estado: 'pendiente' },
    { col: 3, fila: 0.44, estado: 'pendiente' },
  ];
  const DEPS = [[0, 3], [1, 3], [1, 4], [2, 5], [3, 6], [4, 6], [5, 7], [6, 8], [7, 8]];

  function medir() {
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    const r = canvas.getBoundingClientRect();
    ancho = r.width;
    alto  = r.height;
    canvas.width  = Math.round(ancho * dpr);
    canvas.height = Math.round(alto * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    colocar();
  }

  function colocar() {
    const w = Math.min(Math.max(ancho * 0.17, 96), 168);   // ancho de tarjeta
    const h = w * 0.44;
    const margenX = ancho * 0.10;
    const util = ancho - margenX * 2 - w;
    // En pantallas anchas el texto ocupa la franja de abajo: el flujo se
    // queda arriba para no pelear con el.
    const finFlujo = ancho >= 700 ? 0.70 : 0.92;
    const arriba = alto * 0.10, abajo = alto * finFlujo - h;

    nodos = PLANO.map((p, i) => {
      const anterior = nodos[i];
      return {
        x: margenX + (util * p.col) / 3,
        y: arriba + (abajo - arriba) * p.fila,
        w, h,
        estado: anterior ? anterior.estado : p.estado,
        avance: anterior ? anterior.avance : (p.estado === 'hecho' ? 1 : p.estado === 'curso' ? 0.45 : 0.08),
        brillo: 0,
        fase: i * 0.9,
      };
    });

    enlaces = DEPS.map(([a, b]) => ({ a, b }));

    motas = Array.from({ length: 26 }, (_, i) => ({
      x: Math.random() * ancho,
      y: Math.random() * alto,
      r: 0.6 + Math.random() * 1.5,
      v: 0.04 + Math.random() * 0.12,
      o: 0.10 + Math.random() * 0.25,
      f: i,
    }));
  }

  /* ---------- Curva de una dependencia ---------- */

  function curva(a, b) {
    const x1 = a.x + a.w, y1 = a.y + a.h / 2;
    const x2 = b.x,       y2 = b.y + b.h / 2;
    const dx = Math.max((x2 - x1) * 0.55, 26);
    return { x1, y1, x2, y2, c1x: x1 + dx, c1y: y1, c2x: x2 - dx, c2y: y2 };
  }

  function puntoEnCurva(c, t) {
    const u = 1 - t, u2 = u * u, t2 = t * t;
    return {
      x: u2 * u * c.x1 + 3 * u2 * t * c.c1x + 3 * u * t2 * c.c2x + t2 * t * c.x2,
      y: u2 * u * c.y1 + 3 * u2 * t * c.c1y + 3 * u * t2 * c.c2y + t2 * t * c.y2,
    };
  }

  /* ---------- Dibujo ---------- */

  function rectRedondo(x, y, w, h, r) {
    ctx.beginPath();
    if (ctx.roundRect) ctx.roundRect(x, y, w, h, r);
    else {
      ctx.moveTo(x + r, y);
      ctx.arcTo(x + w, y, x + w, y + h, r);
      ctx.arcTo(x + w, y + h, x, y + h, r);
      ctx.arcTo(x, y + h, x, y, r);
      ctx.arcTo(x, y, x + w, y, r);
      ctx.closePath();
    }
  }

  function dibujarEnlaces(t) {
    enlaces.forEach((e, i) => {
      const c = curva(nodos[e.a], nodos[e.b]);
      ctx.strokeStyle = 'rgba(255,255,255,.10)';
      ctx.lineWidth = 1.2;
      ctx.setLineDash([5, 7]);
      ctx.lineDashOffset = -((t * 14 + i * 9) % 12);
      ctx.beginPath();
      ctx.moveTo(c.x1, c.y1);
      ctx.bezierCurveTo(c.c1x, c.c1y, c.c2x, c.c2y, c.x2, c.y2);
      ctx.stroke();
      ctx.setLineDash([]);
    });
  }

  function dibujarNodo(n, t) {
    const color = COLORES[n.estado];
    const flota = Math.sin(t * 0.7 + n.fase) * 2.2;
    const y = n.y + flota;
    const r = Math.min(n.h * 0.28, 13);

    // halo al activarse
    if (n.brillo > 0.01) {
      ctx.save();
      ctx.shadowColor = color;
      ctx.shadowBlur = 26 * n.brillo;
      ctx.fillStyle = 'rgba(255,255,255,.02)';
      rectRedondo(n.x, y, n.w, n.h, r);
      ctx.fill();
      ctx.restore();
    }

    // cuerpo de la tarjeta
    const g = ctx.createLinearGradient(n.x, y, n.x + n.w, y + n.h);
    g.addColorStop(0, 'rgba(255,255,255,.085)');
    g.addColorStop(1, 'rgba(255,255,255,.035)');
    ctx.fillStyle = g;
    rectRedondo(n.x, y, n.w, n.h, r);
    ctx.fill();
    ctx.strokeStyle = `rgba(255,255,255,${0.13 + n.brillo * 0.5})`;
    ctx.lineWidth = 1;
    ctx.stroke();

    const pad = n.w * 0.09;

    // punto de estado + lineas de texto simuladas
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.arc(n.x + pad + 3, y + n.h * 0.30, 3.4, 0, Math.PI * 2);
    ctx.fill();

    ctx.fillStyle = 'rgba(255,255,255,.34)';
    rectRedondo(n.x + pad + 13, y + n.h * 0.30 - 2.6, n.w * 0.46, 5, 2.5);
    ctx.fill();
    ctx.fillStyle = 'rgba(255,255,255,.16)';
    rectRedondo(n.x + pad, y + n.h * 0.50, n.w * 0.34, 4, 2);
    ctx.fill();

    // barra de avance
    const bx = n.x + pad, by = y + n.h * 0.72, bw = n.w - pad * 2;
    ctx.fillStyle = 'rgba(255,255,255,.10)';
    rectRedondo(bx, by, bw, 4.5, 2.25);
    ctx.fill();
    ctx.fillStyle = color;
    rectRedondo(bx, by, Math.max(bw * n.avance, 5), 4.5, 2.25);
    ctx.fill();

    // check cuando esta lista
    if (n.estado === 'hecho') {
      ctx.strokeStyle = COLORES.hecho;
      ctx.lineWidth = 1.9;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      const cx = n.x + n.w - pad - 7, cy = y + n.h * 0.30;
      ctx.beginPath();
      ctx.moveTo(cx - 4, cy);
      ctx.lineTo(cx - 1.2, cy + 3);
      ctx.lineTo(cx + 4.5, cy - 3.4);
      ctx.stroke();
    }
  }

  function dibujarPulsos() {
    pulsos.forEach((p) => {
      const c = curva(nodos[p.a], nodos[p.b]);
      const cola = 0.12;
      for (let k = 0; k < 5; k++) {
        const tt = Math.max(p.t - (k * cola) / 5, 0);
        const pt = puntoEnCurva(c, tt);
        ctx.fillStyle = `rgba(64,207,255,${(1 - k / 5) * 0.9})`;
        ctx.beginPath();
        ctx.arc(pt.x, pt.y, 2.6 - k * 0.4, 0, Math.PI * 2);
        ctx.fill();
      }
      const cab = puntoEnCurva(c, p.t);
      ctx.save();
      ctx.shadowColor = '#40CFFF';
      ctx.shadowBlur = 12;
      ctx.fillStyle = '#dff6ff';
      ctx.beginPath();
      ctx.arc(cab.x, cab.y, 2.9, 0, Math.PI * 2);
      ctx.fill();
      ctx.restore();
    });
  }

  function dibujarMotas(t) {
    motas.forEach((m) => {
      const y = (m.y - t * m.v * 30 + alto * 4) % alto;
      ctx.fillStyle = `rgba(255,255,255,${m.o * (0.6 + 0.4 * Math.sin(t + m.f))})`;
      ctx.beginPath();
      ctx.arc(m.x, y, m.r, 0, Math.PI * 2);
      ctx.fill();
    });
  }

  /* ---------- Logica del flujo ---------- */

  let proximoPulso = 1.2;

  function lanzarPulso() {
    // Elige una dependencia cuyo origen ya avanzo mas que el destino
    const candidatos = enlaces.filter((e) => nodos[e.a].avance > nodos[e.b].avance + 0.15);
    const e = (candidatos.length ? candidatos : enlaces)[Math.floor(Math.random() * (candidatos.length || enlaces.length))];
    pulsos.push({ a: e.a, b: e.b, t: 0 });
  }

  function avanzarNodo(i) {
    const n = nodos[i];
    n.brillo = 1;
    n.avance = Math.min(n.avance + 0.22 + Math.random() * 0.2, 1);
    const idx = ORDEN.indexOf(n.estado);
    if (n.avance >= 1) n.estado = 'hecho';
    else if (n.avance > 0.66) n.estado = 'revision';
    else if (idx < 1) n.estado = 'curso';
  }

  function reiniciarSiTodoHecho() {
    if (!nodos.every((n) => n.estado === 'hecho')) return;
    nodos.forEach((n, i) => {
      if (i > 1) { n.estado = 'pendiente'; n.avance = 0.06; }
    });
  }

  /* ---------- Bucle ---------- */

  function marco(ms) {
    animId = requestAnimationFrame(marco);
    if (!t0) t0 = ms;
    const t = (ms - t0) / 1000;
    const dt = Math.min(1 / 30, 0.05);

    ctx.clearRect(0, 0, ancho, alto);
    dibujarMotas(t);
    dibujarEnlaces(t);

    // pulsos
    if (!lento && t > proximoPulso) {
      lanzarPulso();
      proximoPulso = t + 0.9 + Math.random() * 1.4;
    }
    pulsos = pulsos.filter((p) => {
      p.t += dt * 0.62;
      if (p.t >= 1) { avanzarNodo(p.b); reiniciarSiTodoHecho(); return false; }
      return true;
    });

    nodos.forEach((n) => {
      n.brillo *= 0.94;
      dibujarNodo(n, lento ? 0 : t);
    });
    dibujarPulsos();
  }

  /* ---------- Arranque ---------- */

  medir();
  let redim;
  window.addEventListener('resize', () => {
    clearTimeout(redim);
    redim = setTimeout(medir, 150);
  });
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) { cancelAnimationFrame(animId); animId = 0; }
    else if (!animId) { t0 = 0; animId = requestAnimationFrame(marco); }
  });
  animId = requestAnimationFrame(marco);
})();
