<?php
/**
 * Reuniones: la suite de todas las reuniones de los proyectos que ve quien
 * mira, al estilo del inicio de Zoom y de Meet.
 *
 *   · Próximas: lo que está en curso o empieza pronto, arriba y destacado
 *     (cuenta regresiva y «Entrar»); el resto, por día («Hoy», «Mañana»,
 *     «Jueves 5»). En cada fila, la hora grande, el tema, el proyecto, la
 *     plataforma, los invitados y solo botones de ícono.
 *   · Historial: lo que ya pasó, por día y con cada día de las que se
 *     repiten, con sus marcas (grabación, transcripción, observaciones,
 *     quién entró). Cada fila lleva al detalle de ese día (reunion.php).
 *   · Filtros: el proyecto (pastillas) y el buscador (al vuelo en admin.js;
 *     con Enter busca también en lo que no se cargó del historial).
 *   · Arriba, «Nueva reunión»: si puede crear en un solo proyecto va directo
 *     al asistente de proyecto.php (?nueva_reunion=1); si en varios, primero
 *     elige el proyecto.
 *
 * Con «ver como» activo, solo las reuniones de esa persona (invitada o
 * quien la creó). El pintado es de lib/reuniones_ui.php, el mismo de la
 * pestaña Reuniones del proyecto.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/reuniones_ui.php';

if (esSupervisor()) {
    // El supervisor, dentro de un proyecto, solo ve el Kanban: tampoco las reuniones
    redirigir('index.php', 'Las reuniones no son parte de tu vista de supervisión.', 'error');
}

$ahora    = time();
$verComo  = verComo();
$miembros = (new MiembroRepo())->mapa();
$proyectos = [];
foreach (soloProyectosVisibles((new ProyectoRepo())->todos()) as $p) $proyectos[(int)$p['id']] = $p;

$todas = array_values(array_filter((new ReunionRepo())->todas(), function ($r) use ($proyectos, $verComo) {
    if (!isset($proyectos[(int)($r['proyecto_id'] ?? 0)])) return false;
    if (!$verComo) return true;
    $vc = (int)$verComo['id'];
    return in_array($vc, array_map('intval', (array)($r['invitados'] ?? [])), true) || (int)($r['creador_id'] ?? 0) === $vc;
}));

/* ---------- Filtros: la pestaña, el proyecto y lo buscado ---------- */
$ver = ($_GET['ver'] ?? '') === 'historial' ? 'historial' : 'proximas';
$pid = (int)($_GET['p'] ?? 0);
if ($pid && !isset($proyectos[$pid])) $pid = 0;
$q   = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$qN  = ReunionesUI::normal($q);
$pag = max(1, min(50, (int)($_GET['pag'] ?? 1)));
$url = function (array $cambios = []) use ($ver, $pid, $q): string {
    $qs = array_filter(array_merge(['ver' => $ver === 'historial' ? 'historial' : '', 'p' => $pid ?: '', 'q' => $q], $cambios), fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return 'reuniones.php' . ($qs ? '?' . http_build_query($qs) : '');
};

// Proyectos con reuniones, para las pastillas (por nombre)
$nPorProyecto = [];
foreach ($todas as $r) $nPorProyecto[(int)$r['proyecto_id']] = ($nPorProyecto[(int)$r['proyecto_id']] ?? 0) + 1;
$pastillas = array_values(array_filter($proyectos, fn($p) => isset($nPorProyecto[(int)$p['id']])));
usort($pastillas, fn($a, $b) => strcasecmp((string)$a['nombre'], (string)$b['nombre']));

$lista = $pid ? array_values(array_filter($todas, fn($r) => (int)$r['proyecto_id'] === $pid)) : $todas;
$coincide = fn(array $v) => $qN === '' || str_contains(ReunionesUI::textoBuscar($v, $miembros), $qN);

$proximas = array_values(array_filter(ReunionesUI::proximas($lista, $proyectos, $ahora), $coincide));
$ctx = ['ahora' => $ahora, 'miembros' => $miembros, 'proyecto' => !$pid, 'h' => 'h2'];
$pasadas = [];
$hayMas = false;
if ($ver === 'historial') {
    $pasadas = array_values(array_filter(ReunionesUI::pasadas($lista, $proyectos, $ahora), $coincide));
    $tope = 40 * $pag;
    $hayMas = count($pasadas) > $tope;
    $pasadas = array_slice($pasadas, 0, $tope);
    $ctx['rastros'] = ReunionesUI::rastros(array_map(fn($v) => $v['r'], $pasadas));
}

/* ---------- Nueva reunión: en qué proyectos puede crear ---------- */
$plataformas = ReunionesUI::plataformasListas();
$dondeCrear = $plataformas ? array_values(array_filter($proyectos, fn($p) => puedeReunionesDelProyecto((int)$p['id']))) : [];
usort($dondeCrear, fn($a, $b) => strcasecmp((string)$a['nombre'], (string)$b['nombre']));
$urlNueva = fn(int $id) => 'proyecto.php?id=' . $id . '&nueva_reunion=1#vista-reuniones';
$acciones = '';
if ($dondeCrear) {
    $directo = count($dondeCrear) === 1 ? (int)$dondeCrear[0]['id'] : ($pid && puedeReunionesDelProyecto($pid) ? $pid : 0);
    $tip = 'Nueva reunión' . ($directo ? ' en ' . $proyectos[$directo]['nombre'] : '');
    $acciones .= $directo
        ? '<a class="rs-boton rs-boton--icono rs-boton--primario" href="' . e($urlNueva($directo)) . '" aria-label="' . e($tip) . '" data-ds-tip="' . e($tip) . '">' . UI::icono('ds-mas') . '</a>'
        : '<button type="button" class="rs-boton rs-boton--icono rs-boton--primario" onclick="document.getElementById(\'dlg-ru-nueva\').showModal()" aria-haspopup="dialog" aria-label="Nueva reunión" data-ds-tip="Nueva reunión">' . UI::icono('ds-mas') . '</button>';
} elseif (!$plataformas && Auth::esAdmin()) {
    $acciones .= '<a class="rs-boton rs-boton--icono" href="ajustes.php#tab-reuniones" aria-label="Configurar Zoom o Google Meet para crear reuniones" data-ds-tip="Configurar Zoom o Google Meet para crear reuniones">' . UI::icono('ds-configuracion') . '</a>';
}

/* ---------- Lo que dice arriba ---------- */
$vivas = array_values(array_filter($proximas, fn($v) => $v['estado'] === 'vivo'));
$deHoy = array_values(array_filter($proximas, fn($v) => $v['dia'] === date('Y-m-d', $ahora)));
if ($vivas) {
    $sub = count($vivas) === 1 ? 'Ahora mismo está en curso «' . e(ReunionesUI::tema($vivas[0]['r'])) . '».' : 'Ahora mismo hay ' . count($vivas) . ' reuniones en curso.';
} elseif ($deHoy) {
    $sub = 'Hoy ' . (count($deHoy) === 1 ? 'queda 1 reunión' : 'quedan ' . count($deHoy) . ' reuniones') . '; la próxima, a las ' . e(ReunionesUI::hora($deHoy[0]['ts'])) . '.';
} elseif ($proximas) {
    $sub = 'Hoy no queda ninguna. La próxima: ' . e(mb_strtolower(ReunionesUI::cuando($proximas[0]['ts'], $ahora))) . '.';
} else {
    $sub = 'Las reuniones de tus proyectos, por día. El historial guarda lo que dejó cada una.';
}
if ($verComo) $sub = 'Viendo las reuniones de <b>' . e($verComo['nombre']) . '</b>. ' . $sub;

UI::inicio('Reuniones', 'reuniones');
UI::cabecera('Reuniones', $sub, $acciones);
?>

<div class="ru" data-ru>
  <div class="ru-barra">
    <nav class="ru-tabs" data-ru-tabs data-activa="<?= $ver ?>" aria-label="Reuniones">
      <span class="ru-tabs__pieza" aria-hidden="true"></span>
      <a class="ru-tabs__op" data-op="proximas" href="<?= e($url(['ver' => '', 'pag' => ''])) ?>"<?= $ver === 'proximas' ? ' aria-current="page"' : '' ?>>
        <?= UI::icono('Calendar') ?><span>Próximas</span><?php if ($proximas): ?><small class="ru-tabs__n"><?= count($proximas) ?></small><?php endif; ?>
      </a>
      <a class="ru-tabs__op" data-op="historial" href="<?= e($url(['ver' => 'historial', 'pag' => ''])) ?>"<?= $ver === 'historial' ? ' aria-current="page"' : '' ?>>
        <?= UI::icono('ds-reloj') ?><span>Historial</span>
      </a>
    </nav>
    <form class="ru-buscar" method="get" action="reuniones.php" role="search">
      <?php if ($ver === 'historial'): ?><input type="hidden" name="ver" value="historial"><?php endif; ?>
      <?php if ($pid): ?><input type="hidden" name="p" value="<?= $pid ?>"><?php endif; ?>
      <label class="ru-buscar__caja">
        <?= UI::icono('ds-buscar') ?>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar por tema, proyecto o persona" autocomplete="off" data-ru-buscar aria-label="Buscar reuniones">
      </label>
    </form>
  </div>

  <?php if (count($pastillas) > 1): ?>
  <nav class="ru-pills" aria-label="Filtrar por proyecto">
    <a class="ru-pill<?= $pid ? '' : ' is-on' ?>" href="<?= e($url(['p' => '', 'pag' => ''])) ?>"<?= $pid ? '' : ' aria-current="true"' ?>>Todos <small><?= count($todas) ?></small></a>
    <?php foreach ($pastillas as $p): $esta = (int)$p['id'] === $pid; ?>
    <a class="ru-pill<?= $esta ? ' is-on' : '' ?>" href="<?= e($url(['p' => (int)$p['id'], 'pag' => ''])) ?>" style="--pc:<?= e(ProyectoRepo::colorBase($p) === '#2D3E50' ? '#40CFFF' : ProyectoRepo::colorBase($p)) ?>"<?= $esta ? ' aria-current="true"' : '' ?>>
      <span class="ru-pill__punto" aria-hidden="true"></span><?= e($p['nombre']) ?> <small><?= (int)$nPorProyecto[(int)$p['id']] ?></small>
    </a>
    <?php endforeach; ?>
  </nav>
  <?php endif; ?>

  <?php if ($q !== ''): ?>
  <p class="ru-filtro">Buscando «<b><?= e($q) ?></b>»<?= $pid ? ' en ' . e($proyectos[$pid]['nombre']) : '' ?> · <a href="<?= e($url(['q' => '', 'pag' => ''])) ?>">Quitar la búsqueda</a></p>
  <?php endif; ?>

  <?php if ($ver === 'proximas'): ?>
    <?php $html = ReunionesUI::proximasHtml($proximas, $ctx); ?>
    <?php if ($html !== ''): ?>
      <div class="ru-lista"><?= $html ?></div>
    <?php elseif ($q !== ''): ?>
      <?= ReunionesUI::vacio('ds-buscar', 'Ninguna próxima coincide', 'Prueba con otra palabra o mira en el historial.') ?>
    <?php else: ?>
      <?= ReunionesUI::vacio('CalendarCheck', 'No hay reuniones por venir', $dondeCrear ? 'Crea una con el botón de arriba: sale aquí, en su día, con el botón para entrar.' : 'Cuando alguien agende una en tus proyectos, sale aquí en su día.') ?>
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

<?php if (count($dondeCrear) > 1): ?>
<dialog class="ds-modal ds-modal--chico ru-dlg" id="dlg-ru-nueva" aria-labelledby="dlg-ru-nueva-t">
  <header class="ds-modal__head">
    <span class="rs-ic rs-ic--celeste" aria-hidden="true"><?= UI::icono('ds-video') ?></span>
    <div><h2 class="ds-modal__title" id="dlg-ru-nueva-t">Nueva reunión</h2>
      <p class="ds-modal__sub">¿En qué proyecto? Ahí se abre el asistente, con su equipo para invitar.</p></div>
    <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
  </header>
  <ul class="ru-dlg__lista">
    <?php foreach ($dondeCrear as $p):
        $icoP = UI::icono((string)($p['icono'] ?? '') ?: 'FolderOpen');
        if (!str_starts_with($icoP, '<svg')) $icoP = UI::icono('FolderOpen');   // los íconos viejos que no son del set: la carpeta
    ?>
    <li><a class="rs-avance" href="<?= e($urlNueva((int)$p['id'])) ?>">
      <span class="rs-avance__ic" style="--pc:<?= e(ProyectoRepo::colorBase($p)) ?>" aria-hidden="true"><?= $icoP ?></span>
      <span class="rs-avance__txt"><b><?= e($p['nombre']) ?></b><small><?= ($nPorProyecto[(int)$p['id']] ?? 0) ? (int)$nPorProyecto[(int)$p['id']] . ' reunión' . ((int)$nPorProyecto[(int)$p['id']] === 1 ? '' : 'es') : 'Sin reuniones todavía' ?></small></span>
      <?= UI::icono('ds-chevron-der', 'rs-dato__ir') ?>
    </a></li>
    <?php endforeach; ?>
  </ul>
</dialog>
<?php endif; ?>

<?php UI::fin(); ?>
