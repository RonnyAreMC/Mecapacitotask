<?php
/**
 * Organizar equipos: una columna por equipo (los de Ajustes → Equipos:
 * Programadores, Analistas, Diseñadores…) con su gente.
 *
 * El administrador arrastra a cada persona a la columna de su equipo y se
 * guarda al soltarla (actions.php → equipo_mover, por fetch). En el celular,
 * donde no se puede arrastrar, cada persona tiene su botón «Mover a…».
 * Después de cada cambio sale un aviso con «Deshacer».
 */
require_once __DIR__ . '/lib/bootstrap.php';
Auth::requiereAdmin();

$equipos = Catalogo::equipos();
$porEquipo = array_fill_keys(array_keys($equipos), []);
foreach ((new MiembroRepo())->todos() as $m) {
    $porEquipo[MiembroRepo::equipoDe($m)][] = $m;
}
foreach ($porEquipo as &$lista) {
    usort($lista, fn($a, $b) => strnatcasecmp((string)$a['nombre'], (string)$b['nombre']));
}
unset($lista);

// De qué equipo se llegó (para el botón «Volver» y el menú de la izquierda)
$desde = (string)($_GET['desde'] ?? '');
if (!isset($equipos[$desde])) $desde = '';

// Ícono de cada equipo (UI::iconoEquipo: la persona con su rol) en un cuadrito de color,
// uno por columna
$tonos = ['azul', 'petroleo', 'naranja', 'verde', 'cian', 'amarillo', 'rojo'];
$icoEq = fn(string $clave, string $icono, int $i): string =>
    '<span class="mc-tile mc-tile--' . $tonos[$i % count($tonos)] . ' mc-tile--sm">' . UI::iconoEquipo($clave, $icono, 'mc-icon') . '</span>';

UI::inicio('Organizar equipos', $desde !== '' ? 'equipo-' . $desde : 'equipos');
UI::cabecera(
    'Organizar <span class="text-secondary">equipos</span>',
    'Arrastra a cada persona a la columna de su equipo: se guarda al soltarla.',
    $desde !== ''
        ? '<a class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md" href="equipo.php?e=' . e($desde) . '">' . UI::iconoDS('chevron-izq', 18) . '<span class="mc-btn__label">Volver a ' . e($equipos[$desde][0]) . '</span></a>'
        : '',
    'entrada-equipo'
);
?>
<div class="ds eqo" data-eqo>
  <div class="eqo-barra">
    <div class="mc-input mc-input--md eqo-buscar"><?= UI::iconoDS('buscar', 18, 'mc-input__icon') ?>
      <input class="mc-input__control" type="search" data-eqo-buscar placeholder="Buscar a alguien por nombre o rol…" aria-label="Buscar personas" autocomplete="off">
    </div>
    <p class="eqo-ayuda"><?= UI::iconoDS('info', 16) ?> En el celular, toca <b>Mover a</b> junto a cada persona.</p>
  </div>

  <div class="eqo-tablero" style="--eqo-n: <?= count($equipos) ?>">
    <?php $i = 0; foreach ($equipos as $clave => [$etiqueta, $icono]): $gente = $porEquipo[$clave]; ?>
    <section class="eqo-col" data-eqo-col="<?= e($clave) ?>" data-eqo-etiqueta="<?= e($etiqueta) ?>" aria-labelledby="eqo-t-<?= e($clave) ?>">
      <header class="eqo-col__cab">
        <?= $icoEq($clave, $icono, $i) ?>
        <h2 class="eqo-col__t" id="eqo-t-<?= e($clave) ?>"><?= e($etiqueta) ?></h2>
        <span class="eqo-col__n" data-eqo-n aria-label="Personas en <?= e($etiqueta) ?>"><?= count($gente) ?></span>
        <a class="mc-iconbtn mc-iconbtn--sm" href="equipo.php?e=<?= e($clave) ?>" aria-label="Abrir el equipo <?= e($etiqueta) ?>" data-ds-tip="Abrir el equipo"><?= UI::iconoDS('ver', 18) ?></a>
      </header>
      <ul class="eqo-lista" data-eqo-lista>
        <?php foreach ($gente as $m): ?>
        <li class="eqo-persona" draggable="true" data-eqo-id="<?= (int)$m['id'] ?>" data-eqo-nombre="<?= e($m['nombre']) ?>"
            data-buscar="<?= e(mb_strtolower($m['nombre'] . ' ' . ($m['rol'] ?? '') . ' ' . ($m['email'] ?? ''))) ?>">
          <span class="eqo-persona__asa" aria-hidden="true"><?= UI::iconoDS('arrastrar', 16) ?></span>
          <?= UI::avatar($m, 34) ?>
          <span class="eqo-persona__txt"><b><?= e($m['nombre']) ?></b><small><?= e(($m['rol'] ?? '') !== '' ? $m['rol'] : 'Sin rol') ?></small></span>
          <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm eqo-mover" data-eqo-mover aria-haspopup="menu"
                  aria-label="Mover a <?= e($m['nombre']) ?> a otro equipo" data-ds-tip="Mover a otro equipo"><?= UI::iconoDS('transferencia', 16) ?><span class="mc-btn__label">Mover a</span></button>
        </li>
        <?php endforeach; ?>
      </ul>
      <p class="eqo-vacio" data-eqo-vacio<?= $gente ? ' hidden' : '' ?>>Suelta aquí a alguien de otro equipo</p>
    </section>
    <?php $i++; endforeach; ?>
  </div>
  <p class="eqo-nadie" data-eqo-nadie hidden>Nadie coincide con la búsqueda.</p>
</div>

<!-- «Mover a…»: se abre junto al botón de cada persona -->
<div class="mc-pop eqo-menu" data-eqo-menu role="menu" aria-label="Mover a otro equipo" hidden>
  <p class="eqo-menu__t">Mover a</p>
  <?php $i = 0; foreach ($equipos as $clave => [$etiqueta, $icono]): ?>
  <button type="button" role="menuitem" class="eqo-menu__op" data-eqo-a="<?= e($clave) ?>">
    <?= $icoEq($clave, $icono, $i) ?><?= e($etiqueta) ?>
  </button>
  <?php $i++; endforeach; ?>
</div>

<script>
(() => {
  const raiz = document.querySelector('[data-eqo]');
  const menu = document.querySelector('[data-eqo-menu]');
  if (!raiz || !menu) return;
  const cols = [...raiz.querySelectorAll('[data-eqo-col]')];
  const colDe = (clave) => cols.find((c) => c.dataset.eqoCol === clave);
  const aviso = (t, tipo, op) => { if (window.MC && MC.toast) MC.toast(t, tipo, tipo === 'error' ? 7000 : 6500, op); };

  /** Cuenta y «suelta aquí» de cada columna */
  const pintar = () => cols.forEach((c) => {
    const n = c.querySelectorAll('[data-eqo-id]').length;
    c.querySelector('[data-eqo-n]').textContent = n;
    c.querySelector('[data-eqo-vacio]').hidden = n > 0;
  });

  /** Pone la tarjeta en su columna, en orden alfabético */
  const colocar = (card, col) => {
    const lista = col.querySelector('[data-eqo-lista]');
    const nombre = card.dataset.eqoNombre;
    const antes = [...lista.children].find((x) => x !== card && x.dataset.eqoNombre.localeCompare(nombre, 'es', { sensitivity: 'base', numeric: true }) > 0);
    lista.insertBefore(card, antes || null);
    pintar();
  };

  /** Mueve a la persona y lo guarda; si falla, vuelve a su columna */
  const mover = async (card, destino, deshaciendo = false) => {
    const origen = card.closest('[data-eqo-col]');
    const col = colDe(destino);
    if (!col || col === origen || card.classList.contains('is-guardando')) return;
    colocar(card, col);
    card.classList.add('is-guardando');
    const fd = new FormData();
    fd.append('accion', 'equipo_mover');
    fd.append('ajax', '1');
    fd.append('equipo', destino);
    fd.append('ids[]', card.dataset.eqoId);
    let d = null;
    try {
      const r = await fetch('actions.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      d = await r.json();
    } catch (e) { d = null; }
    card.classList.remove('is-guardando');
    if (!d || !d.ok) {
      colocar(card, origen);
      aviso(d && d.mensaje ? d.mensaje : 'No se pudo guardar el cambio', 'error', { desc: d ? undefined : 'Revisa tu conexión o recarga la página.' });
      return;
    }
    if (window.MC && MC.estrenar) MC.estrenar(card);
    const op = {};
    if (d.nota) op.desc = d.nota;
    if (!deshaciendo) op.accion = { texto: 'Deshacer', fn: () => mover(card, origen.dataset.eqoCol, true) };
    aviso(d.mensaje, 'success', op);
  };

  /* ---------- Arrastrar y soltar ---------- */
  let arrastrada = null;
  raiz.addEventListener('dragstart', (e) => {
    const card = e.target.closest && e.target.closest('[data-eqo-id]');
    if (!card) return;
    arrastrada = card;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.dataset.eqoNombre);
    requestAnimationFrame(() => card.classList.add('is-arrastrando'));
  });
  raiz.addEventListener('dragend', () => {
    if (arrastrada) arrastrada.classList.remove('is-arrastrando');
    arrastrada = null;
    cols.forEach((c) => c.classList.remove('is-sobre'));
  });
  cols.forEach((col) => {
    col.addEventListener('dragover', (e) => {
      if (!arrastrada) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      cols.forEach((c) => c.classList.toggle('is-sobre', c === col && c !== arrastrada.closest('[data-eqo-col]')));
    });
    col.addEventListener('dragleave', (e) => {
      if (!col.contains(e.relatedTarget)) col.classList.remove('is-sobre');
    });
    col.addEventListener('drop', (e) => {
      e.preventDefault();
      col.classList.remove('is-sobre');
      if (arrastrada) mover(arrastrada, col.dataset.eqoCol);
    });
  });

  /* ---------- «Mover a…» (celular y teclado) ---------- */
  let deQuien = null;
  const cerrarMenu = () => {
    menu.hidden = true;
    if (deQuien) deQuien.querySelector('[data-eqo-mover]').setAttribute('aria-expanded', 'false');
    deQuien = null;
  };
  raiz.addEventListener('click', (e) => {
    const b = e.target.closest('[data-eqo-mover]');
    if (!b) return;
    const card = b.closest('[data-eqo-id]');
    if (deQuien === card) { cerrarMenu(); return; }
    cerrarMenu();
    deQuien = card;
    const actual = card.closest('[data-eqo-col]').dataset.eqoCol;
    menu.querySelectorAll('[data-eqo-a]').forEach((o) => { o.hidden = o.dataset.eqoA === actual; });
    menu.hidden = false;
    b.setAttribute('aria-expanded', 'true');
    // Debajo del botón; si no cabe, encima
    const r = b.getBoundingClientRect();
    const ancho = menu.offsetWidth, alto = menu.offsetHeight;
    menu.style.left = Math.max(8, Math.min(r.right - ancho, innerWidth - ancho - 8)) + 'px';
    menu.style.top = (r.bottom + 6 + alto > innerHeight ? Math.max(8, r.top - alto - 6) : r.bottom + 6) + 'px';
    const primero = menu.querySelector('[data-eqo-a]:not([hidden])');
    if (primero) primero.focus();
  });
  menu.addEventListener('click', (e) => {
    const o = e.target.closest('[data-eqo-a]');
    if (!o || !deQuien) return;
    const card = deQuien;
    cerrarMenu();
    mover(card, o.dataset.eqoA);
  });
  document.addEventListener('click', (e) => {
    if (!menu.hidden && !menu.contains(e.target) && !e.target.closest('[data-eqo-mover]')) cerrarMenu();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !menu.hidden) {
      const b = deQuien && deQuien.querySelector('[data-eqo-mover]');
      cerrarMenu();
      if (b) b.focus();
    }
  });
  addEventListener('scroll', cerrarMenu, { passive: true });
  addEventListener('resize', cerrarMenu);

  /* ---------- Buscar ---------- */
  const buscar = raiz.querySelector('[data-eqo-buscar]');
  buscar.addEventListener('input', () => {
    const q = buscar.value.trim().toLowerCase();
    let hay = 0;
    raiz.querySelectorAll('[data-eqo-id]').forEach((c) => {
      const si = q === '' || c.dataset.buscar.includes(q);
      c.hidden = !si;
      if (si) hay++;
    });
    raiz.querySelector('[data-eqo-nadie]').hidden = hay > 0 || q === '';
  });
})();
</script>
<?php UI::fin(); ?>
