<?php
/**
 * Reuniones › la videoteca: las GRABACIONES de las reuniones de todos los
 * proyectos que ve quien mira, al estilo de YouTube. Solo lo que ya pasó y
 * tiene su video en Drive (nada de próximas ni en vivo: eso vive en la suite
 * de cada proyecto, reuniones.php?p=ID).
 *
 *   · Arriba, el buscador al centro, ancho y redondo; debajo, los chips:
 *     «Todo», uno por proyecto y «Con transcripción» (el elegido lleva el
 *     canto de vidrio).
 *   · Una cuadrícula de tarjetas, de la más nueva a la más vieja: la portada
 *     16:9 (la miniatura de Drive y, si no carga, una generada con el color y
 *     el ícono del proyecto), la duración, el proyecto como canal, el título
 *     y la fecha. De 24 en 24 con «Ver más». Sin iframes aquí.
 *   · Al abrir una (?v=ID&dia=Y-m-d), la vista «ver»: a la izquierda el video
 *     de Drive embebido y, debajo, el título, el proyecto, la fecha y quiénes
 *     asistieron; a la derecha, un panel con «Archivos» (la grabación para
 *     abrir o bajar, la transcripción, el acta con IA y los adjuntos de las
 *     observaciones de esa reunión, con su visor) y «Pizarra». En el celular,
 *     el video arriba y el panel abajo.
 *
 * Pintado compartido: lib/reuniones_ui.php; estilos: admin.css («Reuniones ›
 * la videoteca»); las pestañas del panel: admin.js.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/reuniones_ui.php';

if (esSupervisor()) {
    redirigir('index.php', 'Las reuniones no son parte de tu vista de supervisión.', 'error');
}

$ahora    = time();
$miembros = (new MiembroRepo())->mapa();
$proyectos = [];
foreach (soloProyectosVisibles((new ProyectoRepo())->todos()) as $p) $proyectos[(int)$p['id']] = $p;
$todas    = array_values(array_filter((new ReunionRepo())->todas(), fn($r) => isset($proyectos[(int)($r['proyecto_id'] ?? 0)])));
$rastros  = ReunionesUI::rastros($todas);
$grabadas = ReunionesUI::grabadas($todas, $proyectos, $ahora, $rastros);
$ctx      = ['ahora' => $ahora, 'miembros' => $miembros, 'rastros' => $rastros];

/* ---------- Los filtros: el proyecto, «con transcripción» y lo buscado ---------- */
$fp = (int)($_GET['p'] ?? 0);
if ($fp && !isset($proyectos[$fp])) $fp = 0;
$ft = !empty($_GET['t']);
$q  = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$qN = ReunionesUI::normal($q);
$filtros = array_filter(['p' => $fp ?: '', 't' => $ft ? 1 : '', 'q' => $q], fn($x) => $x !== '');
$url = fn(array $cambios = []) => 'videoteca.php' . (($qs = http_build_query(array_filter(array_merge($filtros, $cambios), fn($x) => $x !== '' && $x !== null))) !== '' ? '?' . $qs : '');
$urlVer = fn(array $v) => $url(['v' => $v['id'], 'dia' => $v['dia'], 'pag' => '']);
$conTrans = fn(array $v) => ReunionesUI::transcripcion($v['r'], $v['serie'] ? $v['dia'] : '', $v['fin'], $rastros) === 'lista';

/* ======================= La vista «ver» ======================= */
$verId = (int)($_GET['v'] ?? 0);
if ($verId) {
    $r = (new ReunionRepo())->buscar($verId);
    if (!$r || !isset($proyectos[(int)($r['proyecto_id'] ?? 0)])) {
        redirigir($url(), 'Esa grabación no está disponible.', 'error');
    }
    $pid  = (int)$r['proyecto_id'];
    $p    = $proyectos[$pid];
    $vez  = ReunionesUI::vezDelDia($r, (string)($_GET['dia'] ?? ''), $p, $ahora);
    if (!$vez) redirigir($url(), 'Esa reunión no tiene fecha.', 'error');
    $serie = $vez['serie'];
    $diaOc = $serie ? $vez['dia'] : '';
    $plat  = (string)($r['plataforma'] ?? 'zoom');
    $paso  = $vez['estado'] === 'paso';
    $grab  = $paso && $plat === 'zoom' ? ReunionesUI::grabacion($r, $diaOc, $vez['fin'], $rastros) : ['estado' => 'sin', 'ver' => ''];
    $drive = $grab['estado'] === 'lista' ? ReunionesUI::driveId($grab['ver']) : '';
    $trans = $paso && $plat === 'zoom' ? ReunionesUI::transcripcion($r, $diaOc, $vez['fin'], $rastros) : 'sin';
    $tema  = ReunionesUI::tema($r);
    $entraron = ReunionesUI::entraron($vez, $rastros);
    $puedeActa = UI::ia('acta_reunion') && puedeGestionar($pid);

    // Los adjuntos de las observaciones de esa reunión (en una serie, las anotadas ese día)
    $hilos = array_values(array_filter((new ObservacionRepo())->hilosDelProyecto($pid), fn($o) => (int)($o['reunion_id'] ?? 0) === (int)$r['id']
        && (!$serie || substr((string)($o['creado'] ?? ''), 0, 10) === $vez['dia'])));
    $adjuntos = ReunionesUI::adjuntos($hilos);
    $nArchivos = ($drive !== '' ? 1 : 0) + ($trans === 'lista' ? 1 : 0) + count($adjuntos);

    // Más grabaciones del mismo proyecto (sin esta)
    $mas = array_slice(array_values(array_filter($grabadas, fn($v) => (int)$v['p']['id'] === $pid && !($v['id'] === $vez['id'] && $v['dia'] === $vez['dia']))), 0, 4);

    $pizarraLlena = null;
    if (ReunionesUI::hayPizarra() && is_file(__DIR__ . '/lib/Pizarra.php')) {
        try {
            require_once __DIR__ . '/lib/Pizarra.php';
            if (method_exists('Pizarra', 'version')) $pizarraLlena = Pizarra::version((int)$r['id']) > 0;
        } catch (Throwable $e) { $pizarraLlena = null; }
    }
    [$diaA, $diaB] = ReunionesUI::diaTitulo($vez['ts'], $ahora);
    $cuandoTxt = (in_array(mb_strtolower($diaA), ['hoy', 'ayer', 'mañana'], true) ? $diaA . ', ' . $diaB : $diaA . ' ' . $diaB) . ' · ' . ReunionesUI::hora($vez['ts']) . ' · ' . ReunionesUI::duracion($r) . ' min';

    UI::inicio($tema . ' · Videos', 'videos');   // 'videos': la clave del menú lateral (Mi espacio › Videos)
    $volverLista = $url(['pag' => '']);
    ?>
<div class="ru vt vt--ver">
  <div class="vt-barra">
    <a class="rs-boton rs-boton--icono ru-cab__volver" href="<?= e($volverLista) ?>" aria-label="Volver a las grabaciones" data-ds-tip="Volver a las grabaciones"><?= UI::icono('ds-chevron-izq') ?></a>
    <span class="vt-barra__t">Videos</span>
    <span class="ru-sep" aria-hidden="true">·</span>
    <?= ReunionesUI::chipProyecto($p, 'reuniones.php?p=' . $pid) ?>
  </div>

  <div class="vt-ver">
    <div class="vt-ver__main">
      <div class="vt-player">
        <?php if ($drive !== ''): ?>
        <iframe src="https://drive.google.com/file/d/<?= e(rawurlencode($drive)) ?>/preview" title="Grabación: <?= e($tema) ?>"
                allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe>
        <?php else: ?>
        <?= ReunionesUI::portada($vez, '', '<span class="vt-player__aviso">' . UI::icono('MonitorPlay')
            . '<b>' . ($paso ? 'Aún no hay grabación' : 'Todavía no empieza') . '</b>'
            . '<small>' . e(!$paso ? 'Cuando termine y su video llegue a Drive, se ve aquí.' : ($grab['estado'] === 'espera' ? 'Esperando, aún no se ha guardado un respaldo en Drive.' : ($plat === 'zoom' ? 'Esta reunión no quedó grabada.' : 'Las reuniones de ' . Reuniones::etiquetaPlataforma($plat) . ' no quedan grabadas en el panel.'))) . '</small>'
            . (!$paso && ReunionesUI::enlaceEntrar($r) ? '<a class="rs-entrar" href="' . e(ReunionesUI::enlaceEntrar($r)) . '" target="_blank" rel="noopener">' . UI::icono('ds-video') . ' Entrar</a>' : '')
            . '</span>', false) ?>
        <?php endif; ?>
      </div>

      <h1 class="vt-ver__t"><?= e($tema) ?></h1>
      <div class="vt-ver__fila">
        <a class="vt-canal vt-canal--grande" href="reuniones.php?p=<?= $pid ?>" style="--pc:<?= e(ReunionesUI::colorProyecto($p)) ?>" aria-label="Las reuniones de <?= e($p['nombre']) ?>" data-ds-tip="Las reuniones de <?= e($p['nombre']) ?>"><?= ReunionesUI::iconoProyecto($p) ?></a>
        <span class="vt-ver__canal">
          <a href="reuniones.php?p=<?= $pid ?>"><?= e($p['nombre']) ?></a>
          <small><?= e($cuandoTxt) ?></small>
        </span>
        <div class="ru-acc">
          <?= ReunionesUI::ico('ds-ver', 'Ver todo el detalle de la reunión', ReunionesUI::urlDetalle($vez)) ?>
          <?php if ($drive !== ''): ?>
          <?= ReunionesUI::ico('ds-enlace', 'Abrir en Drive', $grab['ver'], '', 'target="_blank" rel="noopener"') ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="vt-asis">
        <?php $quienes = array_values(array_filter(array_map(fn($mid) => $miembros[$mid] ?? null, array_keys($entraron)))); ?>
        <?php if ($quienes): ?>
        <?= UI::avatarStack($quienes, 6, 30) ?>
        <p><b><?= count($quienes) === 1 ? 'Asistió' : 'Asistieron ' . count($quienes) ?>:</b>
          <?= e(implode(', ', array_map(fn($m) => (string)$m['nombre'], $quienes))) ?></p>
        <?php else: ?>
        <p class="vt-asis__nadie"><?= UI::icono('Users') ?> Nadie entró desde el panel<?= ($n = count(ReunionesUI::invitados($r, $miembros))) ? ' · ' . ($n === 1 ? '1 invitado' : $n . ' invitados') : '' ?>.</p>
        <?php endif; ?>
      </div>

      <?php if ($mas): ?>
      <section class="vt-mas" aria-labelledby="vt-mas-t">
        <h2 class="vt-mas__t" id="vt-mas-t">Más de <?= e($p['nombre']) ?></h2>
        <ul class="vt-grid vt-grid--mini"><?php foreach ($mas as $v) echo ReunionesUI::tarjetaVideo($v, $ctx, $urlVer($v)); ?></ul>
      </section>
      <?php endif; ?>
    </div>

    <aside class="vt-panel" aria-label="Archivos y pizarra de la reunión">
      <div class="ru-tabs vt-tabs" role="tablist" data-vt-tabs data-activa="archivos">
        <span class="ru-tabs__pieza" aria-hidden="true"></span>
        <button type="button" role="tab" class="ru-tabs__op" id="vt-tab-archivos" data-op="archivos" aria-selected="true" aria-controls="vt-archivos">
          <?= UI::icono('ds-carpeta') ?><span>Archivos</span><?php if ($nArchivos): ?><small class="ru-tabs__n"><?= $nArchivos ?></small><?php endif; ?>
        </button>
        <button type="button" role="tab" class="ru-tabs__op" id="vt-tab-pizarra" data-op="pizarra" aria-selected="false" aria-controls="vt-pizarra" tabindex="-1">
          <?= UI::icono('pz-pizarra') ?><span>Pizarra</span>
        </button>
      </div>

      <div class="vt-pane" id="vt-archivos" role="tabpanel" aria-labelledby="vt-tab-archivos" data-vt-pane="archivos">
        <?php
        $item = fn(string $icono, string $tono, string $titulo, string $txt, string $accion = '') => '<li class="ru-quedo__item"><span class="rs-ic rs-ic--' . $tono . '" aria-hidden="true">' . UI::icono($icono) . '</span>'
            . '<span class="ru-quedo__txt"><b>' . e($titulo) . '</b><small>' . e($txt) . '</small></span>' . $accion . '</li>';
        ?>
        <ul class="ru-quedo">
          <?php if ($drive !== ''): ?>
          <?= $item('MonitorPlay', 'menta', 'Grabación', 'En Drive: ábrela o bájala.', '<span class="ru-acc">'
              . ReunionesUI::ico('ds-enlace', 'Abrir la grabación en Drive', $grab['ver'], '', 'target="_blank" rel="noopener"')
              . ReunionesUI::ico('ds-descargar', 'Descargar la grabación', 'https://drive.google.com/uc?export=download&id=' . rawurlencode($drive), '', 'target="_blank" rel="noopener"') . '</span>') ?>
          <?php endif; ?>
          <?php if ($plat === 'zoom' && $paso): ?>
          <?= $item('NoteText', 'celeste', 'Transcripción', match ($trans) {
                  'lista'  => 'Lista en Drive: bájala para llevarla a Claude Code.',
                  'espera' => 'Esperando, aún no se ha guardado un respaldo.',
                  default  => 'Zoom no dejó transcripción de este día.',
              }, ReunionesUI::formTranscripcion($r, $diaOc)) ?>
          <?php endif; ?>
          <?php if ($puedeActa && $paso): ?>
          <?= $item('ds-ia', 'lila', 'Acta con IA', 'Resumen, acuerdos y tareas, desde la transcripción o el texto que pegues.', ReunionesUI::botonActa($r, $diaOc)) ?>
          <?php endif; ?>
        </ul>

        <h3 class="vt-pane__sub">De las observaciones</h3>
        <?php if ($adjuntos): ?>
        <ul class="vt-adjs" data-visor-grupo>
          <?php foreach ($adjuntos as ['a' => $a, 'o' => $o]):
              $ext = strtolower((string)($a['ext'] ?? pathinfo((string)$a['ruta'], PATHINFO_EXTENSION)));
              $seVe = ($a['tipo'] ?? '') === 'img' || in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'avif', 'pdf'], true);
              $quien = $miembros[(int)($o['creado_por'] ?? 0)]['nombre'] ?? '';
          ?>
          <li>
            <a class="vt-adj" href="<?= e((string)$a['ruta']) ?>" target="_blank" rel="noopener"<?= $seVe ? ' data-visor data-ext="' . e($ext ?: 'png') . '" data-nombre="' . e((string)($a['nombre'] ?? '')) . '"' : ' download' ?>>
              <span class="vt-adj__ic" aria-hidden="true"><?= ReunionesUI::iconoAdjunto($ext) ?></span>
              <span class="vt-adj__txt"><b><?= e((string)($a['nombre'] ?? basename((string)$a['ruta']))) ?></b>
                <small><?= $quien !== '' ? 'De ' . e(explode(' ', trim($quien))[0]) . ' · ' : '' ?><?= e(ReunionesUI::cuandoFue(strtotime((string)($o['creado'] ?? '')) ?: $ahora, $ahora)) ?></small></span>
              <span class="vt-adj__ir" aria-hidden="true"><?= UI::icono($seVe ? 'ds-ver' : 'ds-descargar') ?></span>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="rs-nota"><?= $hilos ? 'Las observaciones de esta reunión no traen archivos.' : 'Esta reunión no tiene observaciones ligadas' . ($serie ? ' ese día' : '') . '.' ?></p>
        <?php endif; ?>
      </div>

      <div class="vt-pane" id="vt-pizarra" role="tabpanel" aria-labelledby="vt-tab-pizarra" data-vt-pane="pizarra" hidden>
        <?php if (ReunionesUI::hayPizarra()): ?>
        <?php if (ReunionesUI::PIZARRA_EMBED): /* la vista previa de solo lectura (pizarra.php &embed=1); se carga al abrir la pestaña */ ?>
        <div class="vt-pizarra"><iframe data-src="pizarra.php?reunion=<?= (int)$r['id'] ?>&amp;embed=1" title="Pizarra de <?= e($tema) ?> (vista previa)"></iframe></div>
        <?php endif; ?>
        <div class="vt-pizarra-card">
          <span class="rs-ic rs-ic--azul rs-ic--grande" aria-hidden="true"><?= UI::icono('pz-pizarra') ?></span>
          <span class="ru-quedo__txt"><b>La pizarra de esta reunión</b>
            <small><?= $pizarraLlena === true ? 'Tiene notas, el Kanban o dibujos: quedan después de la reunión.' : ($pizarraLlena === false ? 'Todavía está en blanco.' : 'Notas, Kanban y dibujos entre todos, en vivo.') ?></small></span>
          <?= ReunionesUI::ico('pz-pizarra', 'Abrir la pizarra', 'pizarra.php?reunion=' . (int)$r['id'], 'ru-ico--grande') ?>
        </div>
        <?php else: ?>
        <p class="rs-nota">La pizarra no está instalada en este panel.</p>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</div>
<?php
    UI::fin();
    return;
}

/* ======================= La cuadrícula ======================= */
$nPorProyecto = [];
foreach ($grabadas as $v) $nPorProyecto[(int)$v['p']['id']] = ($nPorProyecto[(int)$v['p']['id']] ?? 0) + 1;
$chipsProy = array_values(array_filter($proyectos, fn($p) => isset($nPorProyecto[(int)$p['id']])));
usort($chipsProy, fn($a, $b) => strcasecmp((string)$a['nombre'], (string)$b['nombre']));

$lista = array_values(array_filter($grabadas, fn($v) => (!$fp || (int)$v['p']['id'] === $fp) && (!$ft || $conTrans($v))
    && ($qN === '' || str_contains(ReunionesUI::textoBuscar($v, $miembros), $qN))));
$pag  = max(1, min(50, (int)($_GET['pag'] ?? 1)));
$tope = 24 * $pag;
$hayMas = count($lista) > $tope;
$ver = array_slice($lista, 0, $tope);

UI::inicio('Videos de las reuniones', 'videos');
ReunionesUI::cabecera('', '', 'Videos', 'Las grabaciones de las reuniones de tus proyectos, de la más nueva a la más vieja.');
?>
<div class="ru vt" data-ru>
  <form class="vt-buscar" method="get" action="videoteca.php" role="search">
    <?php if ($fp): ?><input type="hidden" name="p" value="<?= $fp ?>"><?php endif; ?>
    <?php if ($ft): ?><input type="hidden" name="t" value="1"><?php endif; ?>
    <label class="vt-buscar__caja">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar grabaciones por tema, proyecto o persona" autocomplete="off" data-ru-buscar aria-label="Buscar grabaciones">
    </label>
    <button class="vt-buscar__ir" aria-label="Buscar" data-ds-tip="Buscar"><?= UI::icono('ds-buscar') ?></button>
  </form>

  <?php if ($grabadas): ?>
  <nav class="vt-chips" aria-label="Filtrar las grabaciones">
    <a class="vt-chip<?= !$fp && !$ft ? ' is-on' : '' ?>" href="<?= e($url(['p' => '', 't' => '', 'pag' => ''])) ?>"<?= !$fp && !$ft ? ' aria-current="true"' : '' ?>>Todo</a>
    <?php foreach ($chipsProy as $p): $on = (int)$p['id'] === $fp; ?>
    <a class="vt-chip<?= $on ? ' is-on' : '' ?>" href="<?= e($url(['p' => $on ? '' : (int)$p['id'], 'pag' => ''])) ?>" style="--pc:<?= e(ReunionesUI::colorProyecto($p)) ?>"<?= $on ? ' aria-current="true"' : '' ?>>
      <span class="ru-pill__punto" aria-hidden="true"></span><?= e($p['nombre']) ?></a>
    <?php endforeach; ?>
    <a class="vt-chip<?= $ft ? ' is-on' : '' ?>" href="<?= e($url(['t' => $ft ? '' : 1, 'pag' => ''])) ?>"<?= $ft ? ' aria-current="true"' : '' ?>><?= UI::icono('NoteText') ?>Con transcripción</a>
  </nav>
  <?php endif; ?>

  <?php if ($q !== ''): ?>
  <p class="ru-filtro">Buscando «<b><?= e($q) ?></b>» · <a href="<?= e($url(['q' => '', 'pag' => ''])) ?>">Quitar la búsqueda</a></p>
  <?php endif; ?>

  <?php if ($ver): ?>
  <ul class="vt-grid" data-ru-dia>
    <?php foreach ($ver as $i => $v) echo ReunionesUI::tarjetaVideo($v, $ctx, $urlVer($v), $i === $tope - 24 && $pag > 1 ? 'vt-desde-' . ($i + 1) : ''); ?>
  </ul>
  <?php if ($hayMas): ?>
  <p class="ru-mas"><a class="rs-entrar rs-entrar--suave" href="<?= e($url(['pag' => $pag + 1])) ?>#vt-desde-<?= $tope + 1 ?>"><?= UI::icono('ds-chevron-abajo') ?> Ver más</a></p>
  <?php endif; ?>
  <?php elseif (!$grabadas): ?>
    <?= ReunionesUI::vacio('MonitorPlay', 'Todavía no hay grabaciones', 'Cuando una reunión de Zoom termine y su video llegue a Drive, sale aquí. Las próximas están en la pestaña Reuniones de cada proyecto.') ?>
  <?php else: ?>
    <?= ReunionesUI::vacio('ds-buscar', 'Ninguna grabación coincide', 'Prueba con otra palabra o quita un filtro.') ?>
  <?php endif; ?>

  <div data-ru-sin hidden><?= ReunionesUI::vacio('ds-buscar', 'Nada de lo que se ve coincide', 'Pulsa Enter para buscar también en las que no se cargaron.') ?></div>
</div>
<?php UI::fin(); ?>
