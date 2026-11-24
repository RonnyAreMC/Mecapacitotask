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
 * Las grabaciones POR APARTE (VideoExternoRepo, en Models.php): el administrador
 * pega con «+» el link de una grabación que no salió de una reunión del panel,
 * con su tema y el proyecto o los proyectos si aplica. Solo el link, no se
 * suben videos. Van en la misma cuadrícula (en el chip de cada uno de sus
 * proyectos y en «Por aparte») y se abren en ?x=ID: YouTube y Drive
 * se ven aquí; Zoom, OneDrive u otro, con «Abrir la grabación».
 *
 * Pintado compartido: lib/meetings/reuniones_ui.php; estilos: admin.css («Reuniones ›
 * la videoteca»); las pestañas del panel: admin.js.
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/meetings/reuniones_ui.php';

if (esSupervisor()) {
    redirigir('index.php', 'Las reuniones no son parte de tu vista de supervisión.', 'error');
}

$ahora    = time();
$miembros = (new MiembroRepo())->mapa();
$proyectos = [];
foreach (soloProyectosVisibles((new ProyectoRepo())->todos()) as $p) $proyectos[(int)$p['id']] = $p;
// Las reuniones generales (agenda.php, sin proyecto) que puede ver: van como el «proyecto» 0
$proyectos[0] = Reuniones::proyectoGeneral();
$todas    = array_values(array_filter((new ReunionRepo())->todas(), fn($r) => (int)($r['proyecto_id'] ?? 0) > 0
    ? isset($proyectos[(int)$r['proyecto_id']]) : puedeVerReunion($r)));
$rastros  = ReunionesUI::rastros($todas);
$grabadas = ReunionesUI::grabadas($todas, $proyectos, $ahora, $rastros);
$ctx      = ['ahora' => $ahora, 'miembros' => $miembros, 'rastros' => $rastros];

/* ---------- Las grabaciones por aparte: solo su link (las agrega el administrador) ---------- */
$esAdm = esAdmin();
$VT_EXT = '#7C5CFF';   // el color de las que no son de ningún proyecto
$existeProy = array_flip(array_map(fn($p) => (int)$p['id'], (new ProyectoRepo())->todos()));
$idsExt = fn(array $x) => array_values(array_filter(VideoExternoRepo::proyectosDe($x), fn($id) => isset($existeProy[$id])));   // [] = no aplica
$proysExt = fn(array $x) => array_values(array_filter(array_map(fn($id) => $proyectos[$id] ?? null, $idsExt($x))));            // los que ve quien mira
// El administrador las ve todas; el resto, las que tienen link y no son de ningún
// proyecto o son de alguno que ve (como las grabaciones de las reuniones)
$externos = array_values(array_filter((new VideoExternoRepo())->todos(), fn($x) => $esAdm
    || (VideoExternoRepo::tieneEnlace($x) && (!$idsExt($x) || $proysExt($x)))));
$pExt = fn(array $x) => $proysExt($x)[0] ?? null;
$canalExt = fn(array $x) => ($ps = $proysExt($x)) ? (string)$ps[0]['nombre'] . (count($ps) > 1 ? ' +' . (count($ps) - 1) : '') : 'Por aparte';
$nombresExt = fn(array $x) => ($ps = $proysExt($x)) ? implode(', ', array_map(fn($p) => (string)$p['nombre'], $ps)) : 'No aplica';
$colorExt = fn(array $x) => ($p = $pExt($x)) ? ReunionesUI::colorProyecto($p) : $VT_EXT;
$icoExt = fn(array $x) => ($p = $pExt($x)) ? ReunionesUI::iconoProyecto($p) : UI::icono('MonitorPlay');
$tsExt = fn(array $x) => strtotime((string)($x['fecha'] ?? '')) ?: $ahora;
$textoExt = fn(array $x) => ReunionesUI::normal(implode(' ', [(string)$x['tema'], $nombresExt($x), 'por aparte grabacion',
    VideoExternoRepo::fuente((string)($x['enlace'] ?? ''))['nombre']]));
$tarjetaExt = function (array $x, string $id = '') use ($canalExt, $colorExt, $icoExt, $tsExt, $textoExt, $nombresExt, $ahora): string {
    $f = VideoExternoRepo::fuente((string)($x['enlace'] ?? ''));
    $sinLink = !VideoExternoRepo::tieneEnlace($x);
    $tema = (string)$x['tema'];
    $color = e($colorExt($x));
    return '<li class="vt-item" data-ru-txt="' . e($textoExt($x)) . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>'
        . '<a class="vt-card" href="videoteca.php?x=' . (int)$x['id'] . '">'
        . '<span class="vt-portada" style="--pc:' . $color . '">'
        . '<span class="vt-portada__gen" aria-hidden="true"><span class="vt-portada__ic">' . $icoExt($x) . '</span>'
        . '<b class="vt-portada__t">' . e($tema) . '</b><small class="vt-portada__p">' . e($canalExt($x)) . '</small></span>'
        . (!$sinLink && $f['miniatura'] !== '' ? '<img class="vt-portada__img" src="' . e($f['miniatura']) . '" alt="" loading="lazy" decoding="async" onerror="this.remove()">' : '')
        . '<span class="vt-dur' . ($sinLink ? ' vt-dur--falta' : '') . '">' . e($sinLink ? 'Falta el link' : $f['nombre']) . '</span></span>'
        . '<span class="vt-info"><span class="vt-canal" style="--pc:' . $color . '" aria-hidden="true">' . $icoExt($x) . '</span>'
        . '<span class="vt-info__txt"><b class="vt-t" title="' . e($tema) . '">' . e($tema) . '</b>'
        . '<small class="vt-meta" title="' . e($nombresExt($x)) . '">' . e($canalExt($x)) . '<span class="ru-sep" aria-hidden="true">·</span>' . e(ReunionesUI::cuandoFue($tsExt($x), $ahora)) . '</small></span></span>'
        . '</a></li>';
};
// Agregar o editar una (solo el administrador): tema, proyecto(s) si aplica, fecha y el link
$opcionesProyExt = array_filter(array_map(fn($p) => (string)($p['nombre'] ?? ''), $proyectos), fn($n, $id) => $id > 0 && $n !== '', ARRAY_FILTER_USE_BOTH);
asort($opcionesProyExt, SORT_NATURAL | SORT_FLAG_CASE);
$dialogoExt = function (?array $x) use ($opcionesProyExt, $idsExt): void {
    $idX = (int)($x['id'] ?? 0);
    ?>
<dialog class="ds-modal" id="dlg-vt-ext" aria-labelledby="dlg-vt-ext-t">
  <form method="post" action="actions.php" class="ds">
    <input type="hidden" name="accion" value="video_externo_guardar">
    <?php if ($idX): ?><input type="hidden" name="id" value="<?= $idX ?>"><?php endif; ?>
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('enlace', 18) ?></span>
      <div><h2 class="ds-modal__title" id="dlg-vt-ext-t"><?= $idX ? 'Editar la grabación' : 'Agregar una grabación' ?></h2>
        <p class="ds-modal__sub">Una que se hizo por aparte: pega su link. No se suben videos.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body">
      <label class="campo"><span>Tema</span>
        <input class="input-meca" name="tema" required maxlength="160" value="<?= e((string)($x['tema'] ?? '')) ?>" placeholder="Ej. Flujo de admisión UAE"></label>
      <label class="campo"><span>Equipo o proyecto <small>(si aplica · uno o varios)</small></span>
        <?= str_replace('<select ', '<select data-ph="No aplica" aria-label="Equipo o proyecto" ', UI::select('proyectos', $opcionesProyExt, $x ? $idsExt($x) : [], false, '', true)) ?>
        <small class="campo-ayuda">Sale también en el filtro de cada uno. Si no es de ninguno, déjalo en «No aplica».</small></label>
      <div class="campo-doble">
        <label class="campo"><span>Fecha de la grabación</span>
          <input class="input-meca" type="date" name="fecha" max="<?= date('Y-m-d') ?>" value="<?= e((string)($x['fecha'] ?? date('Y-m-d'))) ?>"></label>
        <span aria-hidden="true"></span>
      </div>
      <label class="campo"><span>Link de la grabación</span>
        <input class="input-meca" type="url" name="enlace" required maxlength="800" inputmode="url" value="<?= e((string)($x['enlace'] ?? '')) ?>" placeholder="https://…">
        <small class="campo-ayuda">YouTube y Google Drive se ven aquí mismo; Zoom, OneDrive u otro se abren en otra pestaña.</small></label>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label"><?= $idX ? 'Guardar' : 'Agregar' ?></span></button>
    </footer>
  </form>
</dialog>
    <?php
};

/* ---------- Los filtros: el proyecto, «con transcripción» y lo buscado ---------- */
$fp = (int)($_GET['p'] ?? 0);
if ($fp && !isset($proyectos[$fp])) $fp = 0;
$ft = !empty($_GET['t']);
$fo = !empty($_GET['o']) && $externos;   // solo las de por aparte
$q  = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 80));
$qN = ReunionesUI::normal($q);
$filtros = array_filter(['p' => $fp ?: '', 't' => $ft ? 1 : '', 'o' => $fo ? 1 : '', 'q' => $q], fn($x) => $x !== '');
$url = fn(array $cambios = []) => 'videoteca.php' . (($qs = http_build_query(array_filter(array_merge($filtros, $cambios), fn($x) => $x !== '' && $x !== null))) !== '' ? '?' . $qs : '');
$urlVer = fn(array $v) => $url(['v' => $v['id'], 'dia' => $v['dia'], 'pag' => '']);
$conTrans = fn(array $v) => ReunionesUI::transcripcion($v['r'], $v['serie'] ? $v['dia'] : '', $v['fin'], $rastros) === 'lista';

/* ======================= La vista «ver» de una grabación por aparte ======================= */
$verX = (int)($_GET['x'] ?? 0);
if ($verX) {
    $x = (new VideoExternoRepo())->buscar($verX);
    // La misma regla que la cuadrícula: con link y de ningún proyecto o de uno que ve (el admin, todas)
    if (!$x || !in_array((int)$x['id'], array_map(fn($o) => (int)$o['id'], $externos), true)) redirigir($url(), 'Esa grabación no está disponible.', 'error');
    $f = VideoExternoRepo::fuente((string)($x['enlace'] ?? ''));
    $conLink = VideoExternoRepo::tieneEnlace($x);
    $tema = (string)$x['tema'];
    $psX = $proysExt($x);
    // Más por aparte: primero las que comparten proyecto
    $idsX = $idsExt($x);
    $masX = array_values(array_filter($externos, fn($o) => (int)$o['id'] !== $verX));
    usort($masX, fn($a, $b) => (count(array_intersect($idsExt($b), $idsX)) > 0) <=> (count(array_intersect($idsExt($a), $idsX)) > 0));
    $masX = array_slice($masX, 0, 4);
    $autorX = trim((string)($miembros[(int)($x['creado_por'] ?? 0)]['nombre'] ?? ''));
    $fechaX = (int)date('j', $tsExt($x)) . ' ' . ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][(int)date('n', $tsExt($x)) - 1] . ' ' . date('Y', $tsExt($x));
    $dondeX = $f['nombre'] === 'Enlace' ? 'otra página' : $f['nombre'];
    $itemX = fn(string $icono, string $tono, string $titulo, string $txt, string $accion = '') => '<li class="ru-quedo__item"><span class="rs-ic rs-ic--' . $tono . '" aria-hidden="true">' . UI::icono($icono) . '</span>'
        . '<span class="ru-quedo__txt"><b>' . e($titulo) . '</b><small>' . e($txt) . '</small></span>' . $accion . '</li>';
    $abrirX = $conLink ? ReunionesUI::ico('ds-enlace', 'Abrir en ' . $dondeX, (string)$x['enlace'], '', 'target="_blank" rel="noopener"')
        . '<button type="button" class="ru-ico btn-copiar" data-copiar="' . e((string)$x['enlace']) . '" aria-label="Copiar el link" data-ds-tip="Copiar el link">' . UI::icono('ds-copiar') . '</button>' : '';

    UI::inicio($tema . ' · Videos', 'videos');
    ?>
<div class="ru vt vt--ver vt--ext">
  <div class="vt-barra">
    <?= UI::volver($url(['pag' => '']), 'Volver a las grabaciones', 'ru-cab__volver') ?>
    <span class="vt-barra__t">Videos</span>
    <span class="ru-sep" aria-hidden="true">·</span>
    <?php if ($psX): ?>
    <?php foreach ($psX as $pX) echo ReunionesUI::chipProyecto($pX, 'videoteca.php?p=' . (int)$pX['id']); ?>
    <?php else: ?>
    <a class="ru-chip-proy" href="videoteca.php?o=1" style="--pc:<?= $VT_EXT ?>"><span class="ru-chip-proy__ic" aria-hidden="true"><?= $icoExt($x) ?></span>Por aparte</a>
    <?php endif; ?>
  </div>

  <div class="vt-ver">
    <div class="vt-ver__main">
      <div class="vt-player">
        <?php if ($conLink && $f['insertar'] !== ''): ?>
        <iframe src="<?= e($f['insertar']) ?>" title="Grabación: <?= e($tema) ?>" referrerpolicy="strict-origin-when-cross-origin"
                allow="autoplay; encrypted-media; fullscreen; picture-in-picture" allowfullscreen></iframe>
        <?php else: ?>
        <span class="vt-portada" style="--pc:<?= e($colorExt($x)) ?>">
          <span class="vt-portada__gen" aria-hidden="true"><span class="vt-portada__ic"><?= $icoExt($x) ?></span><b class="vt-portada__t"><?= e($tema) ?></b><small class="vt-portada__p"><?= e($canalExt($x)) ?></small></span>
          <span class="vt-player__aviso"><?= UI::icono('MonitorPlay') ?>
            <?php if ($conLink): ?>
            <b>La grabación está en <?= e($dondeX) ?></b>
            <small>Se abre en otra pestaña.</small>
            <a class="rs-entrar" href="<?= e((string)$x['enlace']) ?>" target="_blank" rel="noopener"><?= UI::icono('ds-enlace') ?> Abrir la grabación</a>
            <?php else: ?>
            <b>Falta el link</b>
            <small>Pónselo con el lápiz (Editar).</small>
            <?php endif; ?>
          </span>
        </span>
        <?php endif; ?>
      </div>

      <h1 class="vt-ver__t"><?= e($tema) ?></h1>
      <div class="vt-ver__fila">
        <?php $urlCanalX = $psX ? 'videoteca.php?p=' . (int)$psX[0]['id'] : 'videoteca.php?o=1'; $tipCanalX = $psX ? 'Las grabaciones de ' . $psX[0]['nombre'] : 'Las grabaciones por aparte'; ?>
        <a class="vt-canal vt-canal--grande" href="<?= e($urlCanalX) ?>" style="--pc:<?= e($colorExt($x)) ?>" aria-label="<?= e($tipCanalX) ?>" data-ds-tip="<?= e($tipCanalX) ?>"><?= $icoExt($x) ?></a>
        <span class="vt-ver__canal">
          <a href="<?= e($urlCanalX) ?>" title="<?= e($nombresExt($x)) ?>"><?= e($canalExt($x)) ?></a>
          <small><?= e($fechaX) ?><?= $conLink ? ' · ' . e($f['nombre']) : '' ?></small>
        </span>
        <div class="ru-acc">
          <?= $abrirX ?>
          <?php if ($esAdm): ?>
          <?= ReunionesUI::ico('ds-editar', 'Editar el tema, el proyecto o el link', '', '', 'onclick="document.getElementById(\'dlg-vt-ext\').showModal()"') ?>
          <form method="post" action="actions.php" class="inline-form" data-confirmar="Se quita «<?= e($tema) ?>» de Videos. La grabación sigue donde está: solo se quita su link del panel."
                data-confirmar-titulo="¿Quitar esta grabación?" data-confirmar-ok="Sí, quitar">
            <input type="hidden" name="accion" value="video_externo_quitar">
            <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
            <?= ReunionesUI::ico('ds-eliminar', 'Quitar de Videos', '', '', '', 'submit') ?>
          </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($masX): ?>
      <section class="vt-mas" aria-labelledby="vt-mas-t">
        <h2 class="vt-mas__t" id="vt-mas-t">Más grabaciones por aparte</h2>
        <ul class="vt-grid vt-grid--mini"><?php foreach ($masX as $o) echo $tarjetaExt($o); ?></ul>
      </section>
      <?php endif; ?>
    </div>

    <aside class="vt-panel" aria-label="Datos de la grabación">
      <ul class="ru-quedo">
        <?= $itemX('MonitorPlay', 'menta', 'Grabación', $conLink ? ($f['insertar'] !== '' ? 'En ' . $f['nombre'] . ': se ve aquí, o ábrela allá.' : 'En ' . $dondeX . ': se abre en otra pestaña.') : 'Todavía no tiene link.', $abrirX !== '' ? '<span class="ru-acc">' . $abrirX . '</span>' : '') ?>
        <?= $itemX('FolderOpen', 'celeste', count($psX) > 1 ? 'Equipos o proyectos' : 'Equipo o proyecto', $nombresExt($x)) ?>
        <?php if ($autorX !== '' || !empty($x['creado'])): ?>
        <?= $itemX('ds-usuario', 'lila', 'La agregó', trim($autorX . ($autorX !== '' && !empty($x['creado']) ? ' · ' : '') . (!empty($x['creado']) ? ReunionesUI::cuandoFue(strtotime((string)$x['creado']) ?: $ahora, $ahora) : ''))) ?>
        <?php endif; ?>
      </ul>
    </aside>
  </div>
</div>
<?php
    if ($esAdm) $dialogoExt($x);
    UI::fin();
    return;
}

/* ======================= La vista «ver» ======================= */
$verId = (int)($_GET['v'] ?? 0);
if ($verId) {
    $r = (new ReunionRepo())->buscar($verId);
    if (!$r || !((int)($r['proyecto_id'] ?? 0) > 0 ? isset($proyectos[(int)$r['proyecto_id']]) : puedeVerReunion($r))) {
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
    $puedeActa = $pid > 0 && UI::ia('acta_reunion') && puedeGestionar($pid);   // el acta crea tareas: la general no tiene proyecto
    $urlCanal  = $pid > 0 ? 'reuniones.php?p=' . $pid : 'agenda.php';

    // Los adjuntos de las observaciones de esa reunión (en una serie, las anotadas ese día)
    $hilos = array_values(array_filter((new ObservacionRepo())->hilosDelProyecto($pid), fn($o) => (int)($o['reunion_id'] ?? 0) === (int)$r['id']
        && (!$serie || substr((string)($o['creado'] ?? ''), 0, 10) === $vez['dia'])));
    $adjuntos = ReunionesUI::adjuntos($hilos);
    $nArchivos = ($drive !== '' ? 1 : 0) + ($trans === 'lista' ? 1 : 0) + count($adjuntos);

    // Más grabaciones del mismo proyecto (sin esta)
    $mas = array_slice(array_values(array_filter($grabadas, fn($v) => (int)$v['p']['id'] === $pid && !($v['id'] === $vez['id'] && $v['dia'] === $vez['dia']))), 0, 4);

    $pizarraLlena = null;
    if (ReunionesUI::hayPizarra() && is_file(__DIR__ . '/lib/whiteboard/Pizarra.php')) {
        try {
            require_once __DIR__ . '/lib/whiteboard/Pizarra.php';
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
    <?= UI::volver($volverLista, 'Volver a las grabaciones', 'ru-cab__volver') ?>
    <span class="vt-barra__t">Videos</span>
    <span class="ru-sep" aria-hidden="true">·</span>
    <?= ReunionesUI::chipProyecto($p, $urlCanal) ?>
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
        <a class="vt-canal vt-canal--grande" href="<?= e($urlCanal) ?>" style="--pc:<?= e(ReunionesUI::colorProyecto($p)) ?>" aria-label="Las reuniones de <?= e($p['nombre']) ?>" data-ds-tip="Las reuniones de <?= e($p['nombre']) ?>"><?= ReunionesUI::iconoProyecto($p) ?></a>
        <span class="vt-ver__canal">
          <a href="<?= e($urlCanal) ?>"><?= e($p['nombre']) ?></a>
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
foreach ($externos as $x) foreach ($proysExt($x) as $pX) $nPorProyecto[(int)$pX['id']] = ($nPorProyecto[(int)$pX['id']] ?? 0) + 1;
$chipsProy = array_values(array_filter($proyectos, fn($p) => (int)$p['id'] > 0 && isset($nPorProyecto[(int)$p['id']])));   // la general no es un filtro
usort($chipsProy, fn($a, $b) => strcasecmp((string)$a['nombre'], (string)$b['nombre']));

$lista = $fo ? [] : array_values(array_filter($grabadas, fn($v) => (!$fp || (int)$v['p']['id'] === $fp) && (!$ft || $conTrans($v))
    && ($qN === '' || str_contains(ReunionesUI::textoBuscar($v, $miembros), $qN))));
// Las de por aparte van mezcladas por fecha (con el filtro de un proyecto, las de ese proyecto; no traen transcripción)
if (!$ft) {
    foreach ($externos as $x) {
        if ($fp && !in_array($fp, $idsExt($x), true)) continue;
        if ($qN === '' || str_contains($textoExt($x), $qN)) $lista[] = ['ext' => $x, 'ts' => $tsExt($x)];
    }
    usort($lista, fn($a, $b) => (int)$b['ts'] <=> (int)$a['ts']);
}
$pag  = max(1, min(50, (int)($_GET['pag'] ?? 1)));
$tope = 24 * $pag;
$hayMas = count($lista) > $tope;
$ver = array_slice($lista, 0, $tope);

UI::inicio('Videos de las reuniones', 'videos');
ReunionesUI::cabecera('', '', 'Videos', 'Las grabaciones de las reuniones de tus proyectos, de la más nueva a la más vieja.',
    $esAdm ? ReunionesUI::botonCab('ds-mas', 'Agregar una grabación hecha por aparte (solo el link)', '', '', 'onclick="document.getElementById(\'dlg-vt-ext\').showModal()"') : '');
?>
<div class="ru vt" data-ru>
  <form class="vt-buscar" method="get" action="videoteca.php" role="search">
    <?php if ($fp): ?><input type="hidden" name="p" value="<?= $fp ?>"><?php endif; ?>
    <?php if ($ft): ?><input type="hidden" name="t" value="1"><?php endif; ?>
    <?php if ($fo): ?><input type="hidden" name="o" value="1"><?php endif; ?>
    <label class="vt-buscar__caja">
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Buscar grabaciones por tema, proyecto o persona" autocomplete="off" data-ru-buscar aria-label="Buscar grabaciones">
    </label>
    <button class="vt-buscar__ir" aria-label="Buscar" data-ds-tip="Buscar"><?= UI::icono('ds-buscar') ?></button>
  </form>

  <?php if ($grabadas || $externos): ?>
  <nav class="vt-chips" aria-label="Filtrar las grabaciones">
    <a class="vt-chip<?= !$fp && !$ft && !$fo ? ' is-on' : '' ?>" href="<?= e($url(['p' => '', 't' => '', 'o' => '', 'pag' => ''])) ?>"<?= !$fp && !$ft && !$fo ? ' aria-current="true"' : '' ?>>Todo</a>
    <?php foreach ($chipsProy as $p): $on = (int)$p['id'] === $fp; ?>
    <a class="vt-chip<?= $on ? ' is-on' : '' ?>" href="<?= e($url(['p' => $on ? '' : (int)$p['id'], 'o' => '', 'pag' => ''])) ?>" style="--pc:<?= e(ReunionesUI::colorProyecto($p)) ?>"<?= $on ? ' aria-current="true"' : '' ?>>
      <span class="ru-pill__punto" aria-hidden="true"></span><?= e($p['nombre']) ?></a>
    <?php endforeach; ?>
    <?php if ($externos): ?>
    <a class="vt-chip<?= $fo ? ' is-on' : '' ?>" href="<?= e($url(['o' => $fo ? '' : 1, 'p' => '', 't' => '', 'pag' => ''])) ?>" style="--pc:<?= $VT_EXT ?>"<?= $fo ? ' aria-current="true"' : '' ?>>
      <span class="ru-pill__punto" aria-hidden="true"></span>Por aparte</a>
    <?php endif; ?>
    <?php if ($grabadas): ?>
    <a class="vt-chip<?= $ft ? ' is-on' : '' ?>" href="<?= e($url(['t' => $ft ? '' : 1, 'o' => '', 'pag' => ''])) ?>"<?= $ft ? ' aria-current="true"' : '' ?>><?= UI::icono('NoteText') ?>Con transcripción</a>
    <?php endif; ?>
  </nav>
  <?php endif; ?>

  <?php if ($q !== ''): ?>
  <p class="ru-filtro">Buscando «<b><?= e($q) ?></b>» · <a href="<?= e($url(['q' => '', 'pag' => ''])) ?>">Quitar la búsqueda</a></p>
  <?php endif; ?>

  <?php if ($ver): ?>
  <ul class="vt-grid" data-ru-dia>
    <?php foreach ($ver as $i => $v) {
        $ancla = $i === $tope - 24 && $pag > 1 ? 'vt-desde-' . ($i + 1) : '';
        echo isset($v['ext']) ? $tarjetaExt($v['ext'], $ancla) : ReunionesUI::tarjetaVideo($v, $ctx, $urlVer($v), $ancla);
    } ?>
  </ul>
  <?php if ($hayMas): ?>
  <p class="ru-mas"><a class="rs-entrar rs-entrar--suave" href="<?= e($url(['pag' => $pag + 1])) ?>#vt-desde-<?= $tope + 1 ?>"><?= UI::icono('ds-chevron-abajo') ?> Ver más</a></p>
  <?php endif; ?>
  <?php elseif (!$grabadas && !$externos): ?>
    <?= ReunionesUI::vacio('MonitorPlay', 'Todavía no hay grabaciones', 'Cuando una reunión de Zoom termine y su video llegue a Drive, sale aquí. Las próximas están en la pestaña Reuniones de cada proyecto.'
        . ($esAdm ? ' Una hecha por aparte la agregas con «+», pegando su link.' : '')) ?>
  <?php else: ?>
    <?= ReunionesUI::vacio('ds-buscar', 'Ninguna grabación coincide', 'Prueba con otra palabra o quita un filtro.') ?>
  <?php endif; ?>

  <div data-ru-sin hidden><?= ReunionesUI::vacio('ds-buscar', 'Nada de lo que se ve coincide', 'Pulsa Enter para buscar también en las que no se cargaron.') ?></div>
</div>
<?php if ($esAdm) $dialogoExt(null); ?>
<?php UI::fin(); ?>
