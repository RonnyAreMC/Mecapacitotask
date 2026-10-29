<?php
/**
 * Pizarra de una reunión (pizarra.php?reunion=ID): un lienzo infinito para
 * usarlo EN la reunión, entre varios a la vez. Notas adhesivas, texto, formas,
 * flechas que se pegan a lo que unen, lápiz, imágenes (Ctrl+V o arrastrando
 * desde el escritorio) y tableros Kanban que pueden traer las tareas reales
 * del proyecto. Menos herramientas, mejor hechas.
 *
 * Esta página solo comprueba que la reunión exista y que quien entra pueda
 * verla, y pinta el armazón: la barra de arriba, la de herramientas y el
 * lienzo vacío. Todo lo demás lo hace assets/pizarra.js contra la API
 * (pizarra_api.php: estado, guardar, imagen y tareas; datos y permisos en
 * lib/Pizarra.php). Estilos: assets/pizarra.css.
 *
 * Es una página a pantalla completa, sin el menú lateral ni la cabecera del
 * panel: la pizarra necesita todo el ancho. Por eso arma su propio <head> y
 * no carga admin.css ni ds.css (son grandes y aquí casi nada los usa): los
 * tokens que hacen falta (superficie, tinta, el canto de lo elegido) están
 * repetidos en pizarra.css con los mismos valores.
 *
 * La ve quien puede ver el proyecto de la reunión; la editan los invitados,
 * quien la creó, quien gestiona el proyecto y el administrador
 * (Pizarra::puedeEditar). Los demás, solo lectura: miran en vivo y se mueven
 * por el lienzo, pero no cambian nada.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Pizarra.php';

$idReu = (int)($_GET['reunion'] ?? 0);
$reu   = $idReu > 0 ? (new ReunionRepo())->buscar($idReu) : null;
if (!$reu || !Pizarra::puedeVer($reu)) {
    redirigir('index.php', 'Esa pizarra no está disponible: la reunión no existe o no es de tus proyectos.', 'error');
}
$editar   = Pizarra::puedeEditar($reu);
$pid      = (int)($reu['proyecto_id'] ?? 0);
$proyecto = (new ProyectoRepo())->buscar($pid);
$yo       = Auth::usuario();
$titulo   = trim((string)($reu['topic'] ?? '')) ?: 'Reunión';
$volver   = 'proyecto.php?id=' . $pid . '#vista-reuniones';

// «Jueves 29 oct · 10:00» o, si se repite, «De lunes a viernes · 09:00»
$mesCorto = [1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic'];
$tsIni = strtotime((string)($reu['inicio'] ?? '')) ?: 0;
if ($tsIni && Reuniones::esRecurrente($reu)) {
    $fecha = Reuniones::diasTexto((array)$reu['dias']) . ' · ' . date('H:i', $tsIni);
} elseif ($tsIni) {
    $fecha = Reuniones::DIAS[(int)date('N', $tsIni)][1] . ' ' . (int)date('j', $tsIni) . ' ' . $mesCorto[(int)date('n', $tsIni)] . ' · ' . date('H:i', $tsIni);
} else {
    $fecha = '';
}

// Lo que el JS necesita para arrancar (el resto lo trae de la API)
$datos = [
    'reunion'  => $idReu,
    'proyecto' => $pid,
    'editar'   => $editar,
    'yo'       => (int)($yo['id'] ?? 0),
    'titulo'   => $titulo,
    'api'      => 'pizarra_api.php?reunion=' . $idReu,
    'tareaUrl' => 'proyecto.php?id=' . $pid . '&tarea=',
];

// Íconos que el JS pinta (barra contextual, tablero, tarjetas): SVG del set,
// guardados una vez en un <template> para clonarlos sin pedir nada más.
$iconosJs = ['ds-eliminar', 'ds-duplicar', 'ds-enlace', 'pz-traer', 'ds-mas', 'ds-check', 'ds-cerrar', 'ds-descargar',
             'pz-frente', 'pz-columna', 'pz-grosor', 'pz-punta', 'pz-tamano', 'pz-nube', 'pz-sin-red', 'pz-imagen', 'ds-alerta'];

// Las herramientas: [clave, ícono, nombre, atajo]. El lector solo mira y se mueve.
$herramientas = [
    ['seleccionar', 'pz-seleccionar', 'Seleccionar', 'V'],
    ['mano',        'pz-mano',        'Mano: mover el lienzo', 'H'],
];
if ($editar) {
    $herramientas = array_merge($herramientas, [
        '|',
        ['nota',      'pz-nota',       'Nota adhesiva', 'N'],
        ['texto',     'pz-texto',      'Texto', 'T'],
        ['rect',      'pz-rectangulo', 'Rectángulo', 'R'],
        ['elipse',    'pz-elipse',     'Elipse', 'O'],
        ['flecha',    'pz-flecha',     'Flecha: se pega a lo que une', 'A'],
        ['lapiz',     'pz-lapiz',      'Lápiz', 'P'],
        '|',
        ['imagen',    'pz-imagen',     'Imagen (o pégala con Ctrl+V)', 'I'],
        ['kanban',    'pz-kanban',     'Tablero Kanban', 'K'],
    ]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= e($titulo) ?> · Pizarra · <?= e(Config::get('titulo')) ?></title>
<link rel="icon" type="<?= logoMime(faviconPanel()) ?>" href="<?= e(faviconPanel()) ?>">
<meta name="theme-color" content="#10285A">
<script>
// El mismo tema que el resto del panel (lo guarda el interruptor del menú)
(function () {
  var q = new URLSearchParams(location.search).get('theme');
  try {
    if (q === 'dark' || q === 'light') localStorage.setItem('meca-theme', q);
    if (localStorage.getItem('meca-theme') === 'dark') document.documentElement.classList.add('dark');
  } catch (e) { /* sin almacenamiento: tema claro */ }
})();
</script>
<link rel="preload" href="<?= asset('assets/fonts/Inter-Variable.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= asset('assets/pizarra.css') ?>">
</head>
<body class="pz-body">
<div class="pz<?= $editar ? ' pz--editar' : ' pz--lector' ?>" id="pz">

  <!-- El lienzo: el «mundo» se mueve y se escala con un solo transform -->
  <div class="pz-lienzo" id="pz-lienzo" tabindex="0" aria-label="Pizarra de la reunión <?= e($titulo) ?>">
    <div class="pz-mundo" id="pz-mundo">
      <div class="pz-capa pz-capa--marcos" id="pz-marcos"></div>
      <div class="pz-capa pz-capa--els" id="pz-els"></div>
      <svg class="pz-svg" id="pz-svg" aria-hidden="true"></svg>
      <div class="pz-capa pz-capa--sel" id="pz-sel"></div>
    </div>
  </div>

  <!-- Arriba: volver, la reunión, quién está y si ya se guardó -->
  <header class="pz-arriba pz-vidrio">
    <a class="pz-boton pz-boton--icono" href="<?= e($volver) ?>" data-tip="Volver a las reuniones de <?= e((string)($proyecto['nombre'] ?? 'el proyecto')) ?>" aria-label="Volver al proyecto">
      <?= UI::icono('ds-chevron-izq') ?>
    </a>
    <div class="pz-arriba__titulo">
      <b class="pz-trunca" title="<?= e($titulo) ?>"><?= e($titulo) ?></b>
      <small class="pz-trunca"><?= e((string)($proyecto['nombre'] ?? '')) ?><?= $fecha !== '' ? ' · ' . e($fecha) : '' ?></small>
    </div>
    <div class="pz-presentes" id="pz-presentes" aria-label="Quién está mirando">
      <span class="pz-presentes__yo" data-tip="Tú"><?= UI::avatar($yo, 28) ?></span>
      <span class="pz-presentes__otros" id="pz-otros"></span>
    </div>
    <?php if ($editar): ?>
    <span class="pz-estado" id="pz-estado">
      <span class="pz-estado__ico" aria-hidden="true"></span><span class="pz-estado__txt">Cargando…</span>
    </span>
    <?php else: ?>
    <span class="pz-estado pz-estado--lector" id="pz-estado" data-estado="lector">
      <?= UI::icono('ds-ver') ?><span class="pz-estado__txt">Solo lectura</span>
    </span>
    <?php endif; ?>
    <button type="button" class="pz-boton pz-boton--icono pz-solo-ancho" id="pz-atajos" data-tip="Atajos de teclado" aria-label="Atajos de teclado"><?= UI::icono('pz-teclado') ?></button>
    <button type="button" class="pz-boton pz-boton--icono" id="pz-exportar" data-tip="Exportar como imagen PNG" aria-label="Exportar PNG"><?= UI::icono('ds-descargar') ?></button>
  </header>

  <!-- Herramientas: abajo al centro; la pieza celeste se desliza a la elegida -->
  <nav class="pz-barra pz-vidrio" id="pz-barra" role="toolbar" aria-label="Herramientas">
    <span class="pz-barra__lente" aria-hidden="true"></span>
    <span class="pz-barra__pieza" aria-hidden="true"></span>
    <?php foreach ($herramientas as $h): ?>
      <?php if ($h === '|'): ?><span class="pz-barra__sep" aria-hidden="true"></span><?php continue; endif; ?>
      <button type="button" class="pz-herr<?= $h[0] === 'seleccionar' ? ' is-activa' : '' ?>" data-herr="<?= $h[0] ?>"
              data-tip="<?= e($h[2]) ?>" data-tecla="<?= $h[3] ?>" aria-label="<?= e($h[2]) ?> (<?= $h[3] ?>)"
              aria-pressed="<?= $h[0] === 'seleccionar' ? 'true' : 'false' ?>"><?= UI::icono($h[1]) ?></button>
    <?php endforeach; ?>
  </nav>

  <!-- Opciones del lápiz (color y grosor): sale encima de la barra al elegirlo -->
  <div class="pz-sub pz-vidrio" id="pz-sub" hidden></div>

  <!-- Abajo a la derecha: deshacer, rehacer y el zoom -->
  <div class="pz-zoom pz-vidrio" id="pz-zoom">
    <?php if ($editar): ?>
    <button type="button" class="pz-boton pz-boton--icono" data-accion="deshacer" data-tip="Deshacer" data-tecla="Ctrl Z" aria-label="Deshacer"><?= UI::icono('pz-deshacer') ?></button>
    <button type="button" class="pz-boton pz-boton--icono pz-solo-ancho" data-accion="rehacer" data-tip="Rehacer" data-tecla="Ctrl Shift Z" aria-label="Rehacer"><?= UI::icono('pz-rehacer') ?></button>
    <span class="pz-zoom__sep pz-solo-ancho" aria-hidden="true"></span>
    <?php endif; ?>
    <button type="button" class="pz-boton pz-boton--icono" data-accion="alejar" data-tip="Alejar" data-tecla="Ctrl −" aria-label="Alejar"><?= UI::icono('ds-menos') ?></button>
    <button type="button" class="pz-zoom__pct" data-accion="cien" data-tip="Volver al 100 %" aria-label="Zoom actual"><span id="pz-pct">100 %</span></button>
    <button type="button" class="pz-boton pz-boton--icono" data-accion="acercar" data-tip="Acercar" data-tecla="Ctrl +" aria-label="Acercar"><?= UI::icono('ds-mas') ?></button>
    <button type="button" class="pz-boton pz-boton--icono" data-accion="ajustar" data-tip="Ver todo" data-tecla="Shift 1" aria-label="Ver todo"><?= UI::icono('pz-ajustar') ?></button>
  </div>

  <!-- Lo que se puede hacer con lo elegido (colores, frente, duplicar, borrar) -->
  <div class="pz-ctx pz-vidrio" id="pz-ctx" role="toolbar" aria-label="Opciones de lo elegido" hidden></div>

  <!-- La pizarra vacía: una pista suave, sin estorbar -->
  <div class="pz-vacio" id="pz-vacio" hidden>
    <?= UI::icono('pz-pizarra') ?>
    <?php if ($editar): ?>
    <b>La pizarra está lista</b>
    <span>Pulsa <kbd>N</kbd> para una nota, <kbd>K</kbd> para un tablero Kanban o pega una imagen con <kbd>Ctrl</kbd>+<kbd>V</kbd>.</span>
    <?php else: ?>
    <b>Todavía no hay nada en la pizarra</b>
    <span>Lo que dibujen los invitados aparece aquí solo, en vivo.</span>
    <?php endif; ?>
  </div>

  <!-- Atajos -->
  <div class="pz-ayuda pz-vidrio" id="pz-ayuda" hidden>
    <b class="pz-ayuda__t">Atajos</b>
    <dl>
      <dt>V · H</dt><dd>Seleccionar · Mano (o barra espaciadora)</dd>
      <?php if ($editar): ?>
      <dt>N · T</dt><dd>Nota · Texto</dd>
      <dt>R · O · A</dt><dd>Rectángulo · Elipse · Flecha</dd>
      <dt>P · K · I</dt><dd>Lápiz · Kanban · Imagen</dd>
      <dt>Doble clic</dt><dd>Escribir en lo elegido (o Enter)</dd>
      <dt>Ctrl V</dt><dd>Pegar imagen, texto o enlace</dd>
      <dt>Ctrl D</dt><dd>Duplicar</dd>
      <dt>Supr</dt><dd>Borrar</dd>
      <dt>Ctrl Z</dt><dd>Deshacer (Ctrl Shift Z rehace)</dd>
      <dt>Alt + arrastrar</dt><dd>Arrastrar una copia</dd>
      <dt>Flechas</dt><dd>Mover 1 px (Shift: 10 px)</dd>
      <?php endif; ?>
      <dt>Ctrl rueda</dt><dd>Zoom (o pellizca el trackpad)</dd>
      <dt>Shift 1</dt><dd>Ver todo</dd>
    </dl>
  </div>

  <div class="pz-toast" id="pz-toast" role="status" aria-live="polite"></div>
  <div class="pz-tip" id="pz-tip" role="tooltip"></div>
  <input type="file" id="pz-archivo" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden>

  <template id="pz-iconos">
    <?php foreach ($iconosJs as $ic): ?><span data-ico="<?= e($ic) ?>"><?= UI::icono($ic) ?></span><?php endforeach; ?>
  </template>
  <script type="application/json" id="pz-datos"><?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</div>
<script src="<?= asset('assets/pizarra.js') ?>" defer></script>
</body>
</html>
