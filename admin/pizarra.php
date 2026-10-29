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
 *
 * También es la pizarra de una nota (?nota=ID, solo su dueño) y, desde
 * nota_ver.php, la de una nota compartida por enlace (solo lectura, sin
 * sesión). Con &embed=1 es la vista previa que va dentro de la nota.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Pizarra.php';

$yo  = Auth::usuario();
// «Mis notas» (?mia=1): la pizarra personal de quien tiene la sesión, no atada
// a una reunión. Siempre la propia —nunca la de otro, ni con «ver como»—: el
// permiso es solo tener sesión, y la API (Pizarra::rid(true)) la busca por
// esa misma sesión.
$mia = !empty($_GET['mia']);
// La pizarra de una nota de notas.php (?nota=ID): solo la de su dueño, como «Mis notas».
// $pzPublica lo pone nota_ver.php: la de una nota compartida por enlace, para mirarla sin
// sesión (pizarra.php sola sigue pidiendo login). &embed=1: la vista previa dentro de la
// nota, solo el lienzo y para mirar (la página que la inserta la abre entera al tocarla).
$idNota  = (int)($_GET['nota'] ?? 0);
$publica = isset($pzPublica) && is_array($pzPublica);
$embed   = !empty($_GET['embed']);   // también la de una reunión (la vista previa de la videoteca)
if ($publica || $idNota > 0) {
    $nota = $publica ? $pzPublica : Pizarra::notaPropia($idNota);
    if (!$nota) redirigir('notas.php', 'Esa nota ya no está disponible.', 'error');
    $idNota   = (int)$nota['id'];
    $mia      = true;                      // como «Mis notas»: sin presentes; el kanban trae mis tareas
    $idReu    = Pizarra::ridNota($idNota);
    $editar   = !$publica && !$embed;
    $pid      = 0;
    $proyecto = null;
    $titulo   = class_exists('Notas') ? Notas::nombre($nota) : 'Nota';
    $subtitulo = $publica ? 'Pizarra compartida · solo para mirar' : 'Pizarra de tu nota';
    $tk       = rawurlencode((string)($nota['compartir'] ?? ''));
    $volver   = $publica ? 'nota_ver.php?t=' . $tk : 'notas.php?id=' . $idNota;
    $volverTip = $publica ? 'Volver a la nota' : 'Volver a tu nota';
    $api      = $publica ? 'pizarra_api.php?t=' . $tk : 'pizarra_api.php?nota=' . $idNota;
} elseif ($mia) {
    $idReu    = (int)Pizarra::rid(true);   // -id del miembro
    $editar   = !$embed;
    $pid      = 0;
    $proyecto = null;
    $titulo   = 'Mis notas';
    $subtitulo = 'Solo tú la ves';
    $volver   = 'index.php';
    $api      = 'pizarra_api.php?mia=1';
} else {
    $idReu = (int)($_GET['reunion'] ?? 0);
    $reu   = $idReu > 0 ? (new ReunionRepo())->buscar($idReu) : null;
    if (!$reu || !Pizarra::puedeVer($reu)) {
        redirigir('index.php', 'Esa pizarra no está disponible: la reunión no existe o no es de tus proyectos.', 'error');
    }
    $editar   = Pizarra::puedeEditar($reu) && !$embed;   // la vista previa es solo para mirar
    $pid      = (int)($reu['proyecto_id'] ?? 0);
    $proyecto = (new ProyectoRepo())->buscar($pid);
    $titulo   = trim((string)($reu['topic'] ?? '')) ?: 'Reunión';
    $volver   = 'proyecto.php?id=' . $pid . '#vista-reuniones';
    $api      = 'pizarra_api.php?reunion=' . $idReu;

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
    $subtitulo = (string)($proyecto['nombre'] ?? '') . ($fecha !== '' ? ' · ' . $fecha : '');
}

// Lo que el JS necesita para arrancar (el resto lo trae de la API).
// «reunion» es la clave de la pizarra en el navegador (vista y pendientes): en «Mis notas», el -id.
$datos = [
    'reunion'  => $embed ? 'previa' . $idReu : $idReu,   // la vista previa no pisa la vista guardada de la pizarra entera
    'mia'      => $mia,
    'proyecto' => $pid,
    'editar'   => $editar,
    'yo'       => (int)($yo['id'] ?? 0),
    'titulo'   => $titulo,
    'api'      => $api,
];

// Íconos que el JS pinta (barra contextual, tablero, tarjetas): SVG del set,
// guardados una vez en un <template> para clonarlos sin pedir nada más.
$iconosJs = ['ds-eliminar', 'ds-duplicar', 'ds-enlace', 'pz-traer', 'ds-mas', 'ds-check', 'ds-cerrar', 'ds-descargar',
             'pz-frente', 'pz-columna', 'pz-grosor', 'pz-punta', 'pz-tamano', 'pz-nube', 'pz-sin-red', 'pz-imagen', 'ds-alerta',
             'ds-notificaciones'];

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
        ['enlace',    'ds-enlace',     'Enlace (o pégalo con Ctrl+V)', 'L'],
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
<?php if ($publica): /* quien abre un enlace compartido no tiene el tema del panel: el de su equipo */ ?>
    else if (!localStorage.getItem('meca-theme') && matchMedia('(prefers-color-scheme: dark)').matches) document.documentElement.classList.add('dark');
<?php endif; ?>
  } catch (e) { /* sin almacenamiento: tema claro */ }
})();
</script>
<?php if ($embed): ?>
<style>
  /* Vista previa (&embed=1): solo el lienzo, quieto. Tocarla la abre entera (lo hace la página que la inserta). */
  .pz-body--embed :is(.pz-arriba, .pz-barra, .pz-sub, .pz-zoom, .pz-ctx, .pz-vacio, .pz-ayuda, .pz-pista, .pz-pop, .pz-calc, .pz-toast, .pz-tip) { display: none !important; }
  .pz-body--embed .pz-lienzo { pointer-events: none; }
</style>
<?php endif; ?>
<link rel="preload" href="<?= asset('assets/fonts/Inter-Variable.woff2') ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= asset('assets/pizarra.css') ?>">
</head>
<body class="pz-body<?= $embed ? ' pz-body--embed' : '' ?>">
<div class="pz<?= $editar ? ' pz--editar' : ' pz--lector' ?>" id="pz">

  <!-- El lienzo: el «mundo» se mueve y se escala con un solo transform -->
  <div class="pz-lienzo" id="pz-lienzo" tabindex="0" aria-label="<?= $idNota ? 'Pizarra de la nota ' . e($titulo) : ($mia ? 'Mis notas: tu pizarra personal' : 'Pizarra de la reunión ' . e($titulo)) ?>">
    <div class="pz-mundo" id="pz-mundo">
      <div class="pz-capa pz-capa--marcos" id="pz-marcos"></div>
      <div class="pz-capa pz-capa--els" id="pz-els"></div>
      <svg class="pz-svg" id="pz-svg" aria-hidden="true"></svg>
      <div class="pz-capa pz-capa--sel" id="pz-sel"></div>
    </div>
  </div>

  <!-- Arriba: volver, la reunión, quién está y si ya se guardó -->
  <header class="pz-arriba pz-vidrio">
    <a class="pz-boton pz-boton--icono" href="<?= e($volver) ?>" data-tip="<?= $idNota ? e($volverTip) : ($mia ? 'Volver al panel' : 'Volver a las reuniones de ' . e((string)($proyecto['nombre'] ?? 'el proyecto'))) ?>" aria-label="<?= $idNota ? e($volverTip) : ($mia ? 'Volver al panel' : 'Volver al proyecto') ?>">
      <?= UI::icono('ds-chevron-izq') ?>
    </a>
    <div class="pz-arriba__titulo">
      <b class="pz-trunca" title="<?= e($titulo) ?>"><?= e($titulo) ?></b>
      <small class="pz-trunca"><?php if ($mia): ?><?= UI::icono($publica ? 'ds-ver' : 'ds-candado') ?> <?php endif; ?><?= e($subtitulo) ?></small>
    </div>
    <?php if (!$mia): /* «Mis notas» es solo de quien la tiene: no hay a quién mostrar */ ?>
    <div class="pz-presentes" id="pz-presentes" aria-label="Quién está mirando">
      <span class="pz-presentes__yo" data-tip="Tú"><?= UI::avatar($yo, 28) ?></span>
      <span class="pz-presentes__otros" id="pz-otros"></span>
    </div>
    <?php endif; ?>
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
    <?php /* La calculadora no es una herramienta del lienzo: se abre al lado y es de cada quien */ ?>
    <span class="pz-barra__sep" aria-hidden="true"></span>
    <button type="button" class="pz-herr pz-herr--calc" data-calc data-tip="Calculadora (solo para ti)" data-tecla="C" aria-label="Calculadora (C)" aria-pressed="false"><?= UI::icono('pz-calculadora') ?></button>
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
    <?php if ($mia && $editar): ?>
    <b>Tus notas, solo para ti</b>
    <span>Pega con <kbd data-mod>Ctrl</kbd>+<kbd>V</kbd> una imagen, un texto o un enlace, o elige abajo: <kbd>N</kbd> nota, <kbd>L</kbd> enlace, <kbd>K</kbd> tablero con tus tareas.</span>
    <?php elseif ($editar): ?>
    <b>La pizarra está lista</b>
    <span>Pega con <kbd data-mod>Ctrl</kbd>+<kbd>V</kbd> una imagen, un texto o un enlace, o elige abajo: <kbd>N</kbd> nota, <kbd>L</kbd> enlace, <kbd>K</kbd> tablero Kanban.</span>
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
      <dt>L</dt><dd>Enlace (o pégalo con Ctrl V)</dd>
      <dt>Doble clic</dt><dd>Escribir en lo elegido (o Enter)</dd>
      <dt>Ctrl V</dt><dd>Pegar imagen, texto o enlace</dd>
      <dt>Campana</dt><dd>Recordatorio en una nota (en lo elegido)</dd>
      <dt>Ctrl D</dt><dd>Duplicar</dd>
      <dt>Supr</dt><dd>Borrar</dd>
      <dt>Ctrl Z</dt><dd>Deshacer (Ctrl Shift Z rehace)</dd>
      <dt>Alt + arrastrar</dt><dd>Arrastrar una copia</dd>
      <dt>Flechas</dt><dd>Mover 1 px (Shift: 10 px)</dd>
      <?php endif; ?>
      <dt>C</dt><dd>Calculadora (solo para ti)</dd>
      <dt>Ctrl rueda</dt><dd>Zoom (o pellizca el trackpad)</dd>
      <dt>Shift 1</dt><dd>Ver todo</dd>
    </dl>
  </div>

  <?php if ($editar): ?>
  <!-- La primera vez, en una pizarra vacía: cómo se pega (se recuerda en el navegador) -->
  <div class="pz-pista pz-vidrio" id="pz-pista" role="note" hidden>
    <span>Pega con <kbd data-mod>Ctrl</kbd>+<kbd>V</kbd> una imagen, un texto o un enlace</span>
    <button type="button" class="pz-pista__ok" data-pista-ok>Entendido</button>
  </div>

  <!-- Enlace: la dirección en un cuadro de vidrio (sin prompt). Un clic en el lienzo elige dónde va. -->
  <div class="pz-pop pz-vidrio" id="pz-pop-enlace" role="dialog" aria-label="Agregar un enlace" hidden>
    <b class="pz-pop__t"><?= UI::icono('ds-enlace') ?> Agregar un enlace</b>
    <label class="pz-pop__campo"><span>Dirección</span>
      <input type="url" id="pz-enlace-url" inputmode="url" placeholder="edcon.ec/guia o https://…" autocomplete="off" spellcheck="false">
    </label>
    <small class="pz-pop__error" id="pz-enlace-error" hidden>Escribe una dirección como edcon.ec o https://…</small>
    <small class="pz-pop__nota">Haz clic en la pizarra para elegir dónde va. También puedes copiar un enlace y pegarlo con <kbd data-mod>Ctrl</kbd>+<kbd>V</kbd>.</small>
    <div class="pz-pop__pie">
      <button type="button" class="pz-pop__b" data-enlace="cancelar">Cancelar</button>
      <button type="button" class="pz-pop__b pz-pop__b--si" data-enlace="crear">Agregar</button>
    </div>
  </div>

  <!-- Recordatorio de una nota: a su hora le avisa a quien lo puso (campana, navegador y correo) -->
  <div class="pz-pop pz-vidrio" id="pz-pop-aviso" role="dialog" aria-label="Recordatorio" hidden>
    <b class="pz-pop__t"><?= UI::icono('ds-notificaciones') ?> Recordatorio</b>
    <small class="pz-pop__nota" id="pz-aviso-actual"></small>
    <div class="pz-pop__chips">
      <button type="button" class="pz-chip" data-aviso="hora">En 1 h</button>
      <button type="button" class="pz-chip" data-aviso="manana">Mañana 9:00</button>
      <button type="button" class="pz-chip pz-chip--quitar" data-aviso="quitar">Quitar</button>
    </div>
    <label class="pz-pop__campo"><span>Fecha y hora</span>
      <input type="datetime-local" id="pz-aviso-cuando" step="60">
    </label>
    <small class="pz-pop__error" id="pz-aviso-error" hidden></small>
    <div class="pz-pop__pie">
      <button type="button" class="pz-pop__b" data-aviso="cancelar">Cancelar</button>
      <button type="button" class="pz-pop__b pz-pop__b--si" data-aviso="guardar">Guardar</button>
    </div>
  </div>
  <?php endif; ?>

  <!-- Mini calculadora: de cada quien (no se guarda en la pizarra); se arrastra por su cabecera -->
  <section class="pz-calc pz-vidrio" id="pz-calc" tabindex="-1" aria-label="Calculadora" hidden>
    <header class="pz-calc__cab" data-calc-agarre>
      <span class="pz-calc__titulo"><b>Calculadora</b><small>Solo para ti · también con el teclado</small></span>
      <button type="button" class="pz-boton pz-boton--icono" data-calc-accion="cerrar" data-tip="Cerrar" data-tecla="Esc" aria-label="Cerrar la calculadora"><?= UI::icono('ds-cerrar') ?></button>
    </header>
    <div class="pz-calc__pantalla" aria-live="polite">
      <span class="pz-calc__expr" id="pz-calc-expr"></span>
      <b class="pz-calc__res" id="pz-calc-res">0</b>
    </div>
    <div class="pz-calc__teclas">
      <?php
      // [tecla interna, lo que se ve, nombre, tipo]
      $teclasCalc = [
          ['C', 'C', 'Borrar todo', 'fn'], ['(', '(', 'Abrir paréntesis', 'fn'], [')', ')', 'Cerrar paréntesis', 'fn'], ['atras', '', 'Borrar el último', 'fn'],
          ['7', '7', '7', ''], ['8', '8', '8', ''], ['9', '9', '9', ''], ['/', '÷', 'Dividir', 'op'],
          ['4', '4', '4', ''], ['5', '5', '5', ''], ['6', '6', '6', ''], ['*', '×', 'Multiplicar', 'op'],
          ['1', '1', '1', ''], ['2', '2', '2', ''], ['3', '3', '3', ''], ['-', '−', 'Restar', 'op'],
          ['0', '0', '0', ''], ['.', '.', 'Punto decimal', ''], ['%', '%', 'Por ciento', 'op'], ['+', '+', 'Sumar', 'op'],
          ['=', '=', 'Igual', 'igual'],
      ];
      foreach ($teclasCalc as [$k, $ver, $nombre, $tipo]): ?>
      <button type="button" class="pz-calc__k<?= $tipo !== '' ? ' pz-calc__k--' . $tipo : '' ?>" data-k="<?= e($k) ?>" aria-label="<?= e($nombre) ?>"><?= $k === 'atras' ? UI::icono('pz-atras') : e($ver) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="pz-calc__cinta" id="pz-calc-cinta"></div>
    <?php if ($editar): ?>
    <button type="button" class="pz-calc__pegar" data-calc-accion="pegar"><?= UI::icono('pz-nota') ?> Pegar en la pizarra</button>
    <?php endif; ?>
  </section>

  <div class="pz-toast" id="pz-toast" role="status" aria-live="polite"></div>
  <div class="pz-tip" id="pz-tip" role="tooltip"></div>
  <input type="file" id="pz-archivo" accept="image/png,image/jpeg,image/webp,image/gif" multiple hidden>

  <template id="pz-iconos">
    <?php foreach ($iconosJs as $ic): ?><span data-ico="<?= e($ic) ?>"><?= UI::icono($ic) ?></span><?php endforeach; ?>
  </template>
  <script type="application/json" id="pz-datos"><?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</div>
<script src="<?= asset('assets/pizarra.js') ?>" defer></script>
<?php if ($embed): ?>
<script>
// Vista previa: en cuanto carga, encuadra todo con poco margen y deja de preguntar
// (la página que la inserta la vuelve a cargar cuando hace falta).
(function () {
  function encuadrar() {
    var P = window.__pizarra, v = P && P.vista, x1 = Infinity, y1 = Infinity, x2 = -Infinity, y2 = -Infinity;
    if (!v) return;
    document.querySelectorAll('#pz-els > *, #pz-svg > *').forEach(function (n) {
      var r = n.getBoundingClientRect();
      if (!r.width && !r.height) return;
      x1 = Math.min(x1, r.left); y1 = Math.min(y1, r.top); x2 = Math.max(x2, r.right); y2 = Math.max(y2, r.bottom);
    });
    if (x1 === Infinity) return;
    var a = { x: (x1 - v.x) / v.z, y: (y1 - v.y) / v.z }, b = { x: (x2 - v.x) / v.z, y: (y2 - v.y) / v.z };
    var m = 16, z = Math.min((innerWidth - m * 2) / Math.max(1, b.x - a.x), (innerHeight - m * 2) / Math.max(1, b.y - a.y), 1);
    z = Math.max(0.04, z);
    P.fijarVista(innerWidth / 2 - (a.x + b.x) / 2 * z, innerHeight / 2 - (a.y + b.y) / 2 * z, z);
  }
  var vueltas = 0, reloj = setInterval(function () {
    var P = window.__pizarra;
    if (++vueltas > 150) { clearInterval(reloj); return; }
    if (!P || !P.sync.cargada) return;
    clearInterval(reloj);
    P.sync.parado = true;
    requestAnimationFrame(function () { requestAnimationFrame(encuadrar); });
    if (document.fonts) document.fonts.ready.then(function () { setTimeout(encuadrar, 60); });
  }, 100);
  addEventListener('resize', function () { requestAnimationFrame(encuadrar); });
})();
</script>
<?php endif; ?>
</body>
</html>
