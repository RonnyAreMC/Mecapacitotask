<?php
/**
 * La suite de reuniones DE UN PROYECTO (reuniones.php?p=ID), al estilo del
 * inicio de Zoom y de Meet. Sin mezclar proyectos: se entra desde la pestaña
 * Reuniones del proyecto, y su botón de volver regresa ahí.
 *
 *   · Próximas: todo ordenado por fecha y hora; lo que está en curso o
 *     empieza pronto (que siempre es lo primero) va arriba y destacado, con
 *     su cuenta regresiva y «Entrar»; el resto, por día («Hoy», «Mañana»,
 *     «Jueves 5»). En cada fila, la hora grande, el tema, la plataforma, los
 *     invitados y solo botones de ícono.
 *   · Historial: lo que ya pasó, por día y con cada día de las que se
 *     repiten, con sus marcas (grabación, transcripción, observaciones, quién
 *     entró). Cada fila lleva al detalle de ese día (reunion.php, &desde=historial).
 *   · El buscador filtra al vuelo (admin.js); con Enter busca también en lo
 *     que no se cargó del historial.
 *   · Arriba, a la derecha: «Nueva reunión» (verde) va directo al asistente
 *     de proyecto.php (?nueva_reunion=1), solo si puedeReunionesDelProyecto.
 *
 * Sin ?p= o sin permiso, a la única que tenga si ve un solo proyecto; si no,
 * al inicio con un aviso. Las grabaciones de todos los proyectos están en
 * videoteca.php (la entrada «Reuniones» del menú). Pintado: lib/reuniones_ui.php.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/reuniones_ui.php';

if (esSupervisor()) {
    // El supervisor, dentro de un proyecto, solo ve el Kanban: tampoco las reuniones
    redirigir('index.php', 'Las reuniones no son parte de tu vista de supervisión.', 'error');
}

$ver = ($_GET['ver'] ?? '') === 'historial' ? 'historial' : 'proximas';
$pid = (int)($_GET['p'] ?? 0);
$proyecto = $pid ? (new ProyectoRepo())->buscar($pid) : null;
if (!$proyecto || !puedeVerProyecto($pid)) {
    $visibles = soloProyectosVisibles((new ProyectoRepo())->todos());
    if (count($visibles) === 1) {
        redirigir('reuniones.php?p=' . (int)$visibles[0]['id'] . ($ver === 'historial' ? '&ver=historial' : ''));
    }
    redirigir('index.php', 'Las reuniones están en cada proyecto: ábrelo y entra a su pestaña «Reuniones».', 'info');
}

$ahora    = time();
$miembros = (new MiembroRepo())->mapa();
$lista    = (new ReunionRepo())->delProyecto($pid);
$proys    = [$pid => $proyecto];
$nombre   = (string)$proyecto['nombre'];

/* ---------- La pestaña, lo buscado y la página del historial ---------- */
$q   = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$qN  = ReunionesUI::normal($q);
$pag = max(1, min(50, (int)($_GET['pag'] ?? 1)));
$url = function (array $cambios = []) use ($ver, $pid, $q): string {
    $qs = array_filter(array_merge(['p' => $pid, 'ver' => $ver === 'historial' ? 'historial' : '', 'q' => $q], $cambios), fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return 'reuniones.php?' . http_build_query($qs);
};
$coincide = fn(array $v) => $qN === '' || str_contains(ReunionesUI::textoBuscar($v, $miembros), $qN);

$proximas = array_values(array_filter(ReunionesUI::proximas($lista, $proys, $ahora), $coincide));
$ctx = ['ahora' => $ahora, 'miembros' => $miembros, 'proyecto' => false, 'h' => 'h2', 'desde' => $ver === 'historial' ? 'historial' : ''];
$pasadas = [];
$hayMas = false;
if ($ver === 'historial') {
    $pasadas = array_values(array_filter(ReunionesUI::pasadas($lista, $proys, $ahora), $coincide));
    $tope = 40 * $pag;
    $hayMas = count($pasadas) > $tope;
    $pasadas = array_slice($pasadas, 0, $tope);
    $ctx['rastros'] = ReunionesUI::rastros(array_map(fn($v) => $v['r'], $pasadas));
}

/* ---------- El encabezado: volver, el proyecto y los botones ---------- */
$plataformas = ReunionesUI::plataformasListas();
$acciones = '';
if (UI::ia('daily_resumen') && puedeGestionar($pid)) {
    // Desde el último día hábil: el lunes mira desde el viernes (lo mismo que en el proyecto)
    $wIa = (int)date('w');
    $desdeIa = date('Y-m-d', strtotime($wIa === 1 ? '-3 day' : ($wIa === 0 ? '-2 day' : '-1 day')));
    $acciones .= ReunionesUI::botonCab('ds-ia', 'Resumen para el daily con IA: qué hizo cada persona y en qué sigue', '', 'ru-cab__ia', 'data-ia-daily="' . $pid . '" data-desde="' . e($desdeIa) . '"');
}
if (is_file(__DIR__ . '/videoteca.php')) {   // las grabaciones de este proyecto, en la videoteca
    $acciones .= ReunionesUI::botonCab('MonitorPlay', 'Las grabaciones de ' . $nombre, 'videoteca.php?p=' . $pid);
}
if ($plataformas && puedeReunionesDelProyecto($pid)) {
    $acciones .= ReunionesUI::botonCab('ds-mas', 'Nueva reunión en ' . $nombre, 'proyecto.php?id=' . $pid . '&nueva_reunion=1#vista-reuniones', 'rs-boton--primario');
} elseif (!$plataformas && Auth::esAdmin()) {
    $acciones .= ReunionesUI::botonCab('ds-configuracion', 'Configurar Zoom o Google Meet para crear reuniones', 'ajustes.php#tab-reuniones');
}

$vivas = array_values(array_filter($proximas, fn($v) => $v['estado'] === 'vivo'));
$deHoy = array_values(array_filter($proximas, fn($v) => $v['dia'] === date('Y-m-d', $ahora)));
if ($vivas) {
    $frase = count($vivas) === 1 ? 'Ahora mismo está en curso «' . e(ReunionesUI::tema($vivas[0]['r'])) . '».' : 'Ahora mismo hay ' . count($vivas) . ' reuniones en curso.';
} elseif ($deHoy) {
    $frase = 'Hoy ' . (count($deHoy) === 1 ? 'queda 1 reunión' : 'quedan ' . count($deHoy) . ' reuniones') . '; la próxima, a las ' . e(ReunionesUI::hora($deHoy[0]['ts'])) . '.';
} elseif ($proximas) {
    $frase = 'Hoy no queda ninguna. La próxima: ' . e(mb_strtolower(ReunionesUI::cuando($proximas[0]['ts'], $ahora))) . '.';
} else {
    $frase = 'Las reuniones del proyecto, por día. El historial guarda lo que dejó cada una.';
}

UI::inicio('Reuniones · ' . $nombre, 'proyecto-' . $pid);
ReunionesUI::cabecera('proyecto.php?id=' . $pid . '#vista-reuniones', 'Volver a ' . $nombre, 'Reuniones',
    ReunionesUI::chipProyecto($proyecto, 'proyecto.php?id=' . $pid) . '<span class="ru-cab__frase">' . $frase . '</span>', $acciones);
?>

<div class="ru" data-ru>
  <div class="ru-barra">
    <nav class="ru-tabs" data-ru-tabs data-activa="<?= $ver ?>" aria-label="Reuniones de <?= e($nombre) ?>">
      <span class="ru-tabs__pieza" aria-hidden="true"></span>
      <a class="ru-tabs__op" data-op="proximas" href="<?= e($url(['ver' => '', 'pag' => ''])) ?>"<?= $ver === 'proximas' ? ' aria-current="page"' : '' ?>>
        <?= UI::icono('Calendar') ?><span>Próximas</span><?php if ($proximas): ?><small class="ru-tabs__n"><?= count($proximas) ?></small><?php endif; ?>
      </a>
      <a class="ru-tabs__op" data-op="historial" href="<?= e($url(['ver' => 'historial', 'pag' => ''])) ?>"<?= $ver === 'historial' ? ' aria-current="page"' : '' ?>>
        <?= UI::icono('ds-reloj') ?><span>Historial</span>
      </a>
    </nav>
    <form class="ru-buscar" method="get" action="reuniones.php" role="search">
      <input type="hidden" name="p" value="<?= $pid ?>">
      <?php if ($ver === 'historial'): ?><input type="hidden" name="ver" value="historial"><?php endif; ?>
      <label class="ru-buscar__caja">
        <?= UI::icono('ds-buscar') ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por tema o persona" autocomplete="off" data-ru-buscar aria-label="Buscar reuniones de <?= e($nombre) ?>">
      </label>
    </form>
  </div>

  <?php if ($q !== ''): ?>
  <p class="ru-filtro">Buscando «<b><?= e($q) ?></b>» · <a href="<?= e($url(['q' => '', 'pag' => ''])) ?>">Quitar la búsqueda</a></p>
  <?php endif; ?>

  <?php if ($ver === 'proximas'): ?>
    <?php $html = ReunionesUI::proximasHtml($proximas, $ctx); ?>
    <?php if ($html !== ''): ?>
      <div class="ru-lista"><?= $html ?></div>
    <?php elseif ($q !== ''): ?>
      <?= ReunionesUI::vacio('ds-buscar', 'Ninguna próxima coincide', 'Prueba con otra palabra o mira en el historial.') ?>
    <?php else: ?>
      <?= ReunionesUI::vacio('CalendarCheck', 'No hay reuniones por venir', $plataformas && puedeReunionesDelProyecto($pid) ? 'Crea una con el botón verde de arriba: sale aquí, en su día, con el botón para entrar.' : 'Cuando agenden una en este proyecto, sale aquí en su día.') ?>
    <?php endif; ?>
  <?php else: ?>
    <?php if ($pasadas): ?>
      <div class="ru-lista"><?= ReunionesUI::dias($pasadas, $ctx, true) ?></div>
      <?php if ($hayMas): ?>
      <p class="ru-mas"><a class="rs-entrar rs-entrar--suave" href="<?= e($url(['pag' => $pag + 1])) ?>"><?= UI::icono('ds-chevron-abajo') ?> Ver las anteriores</a></p>
      <?php endif; ?>
    <?php elseif ($q !== ''): ?>
      <?= ReunionesUI::vacio('ds-buscar', 'Nada en el historial coincide', 'Prueba con otra palabra.') ?>
    <?php else: ?>
      <?= ReunionesUI::vacio('ds-reloj', 'Todavía no hay reuniones realizadas', 'Cuando termine una, queda aquí con su grabación, sus observaciones y quién entró.') ?>
    <?php endif; ?>
  <?php endif; ?>

  <?php /* Lo que dice el buscador al vuelo cuando nada de lo que se ve coincide (admin.js) */ ?>
  <div data-ru-sin hidden><?= ReunionesUI::vacio('ds-buscar', 'Nada de lo que se ve coincide', 'Pulsa Enter para buscar también en lo que no se cargó.') ?></div>
</div>

<?php UI::fin(); ?>
