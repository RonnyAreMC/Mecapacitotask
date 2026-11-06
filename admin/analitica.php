<?php
/**
 * Analítica (BI) de un proyecto: ?id=ID. Resume sus sprints, su release, sus
 * reuniones diarias y sus tareas en un tablero de tarjetas con gráficos de
 * ApexCharts. La ven quienes gestionan el proyecto: el administrador, su
 * Scrum Master y su PO (el botón está en la cabecera de proyecto.php).
 *
 * Aquí solo se juntan los datos, ya ordenados por fecha, en
 * <script type="application/json" id="an-datos">; assets/analitica.js los
 * filtra (fechas, release, sprint, equipo) y los agrega en el navegador, así
 * los filtros cambian todos los gráficos al instante. Estilos: assets/analitica.css.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Releases.php';
require_once __DIR__ . '/lib/Backlog.php';

$id = (int)($_GET['id'] ?? 0);
$proyecto = (new ProyectoRepo())->buscar($id);
if (!$proyecto || !puedeVerProyecto($id)) redirigir('index.php', 'Ese proyecto no está disponible.', 'error');
if (!puedeGestionarTareas($id)) {
    redirigir('proyecto.php?id=' . $id, 'La analítica del proyecto la ven el administrador, su Scrum Master y su PO.', 'error');
}

$hoy = date('Y-m-d');
$MES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sept', 'oct', 'nov', 'dic'];
$fc = fn(string $f) => $f === '' ? '—' : (int)substr($f, 8, 2) . ' ' . $MES[(int)substr($f, 5, 2) - 1];
$dia = fn($v) => substr((string)$v, 0, 10);
$fechas = [];   // todas las fechas con datos: de aquí sale el atajo «Todo»
$nota = function (string $f) use (&$fechas): void { if ($f !== '') $fechas[] = $f; };

/* ---------- Tareas (claves cortas: el JSON pesa menos) ---------- */
$tRepo = new TareaRepo();
$tareas = $tRepo->delProyecto($id);
$estados = Catalogo::estadosTarea();
$finales = Catalogo::estadosFinales();
$inicial = (string)(array_key_first($estados) ?? 'pendiente');
$mapa = (new MiembroRepo())->mapa();
$gente = [];
$jsT = [];
foreach ($tareas as $t) {
    $final = in_array($t['estado'] ?? '', $finales, true);
    // El ciclo empieza cuando salió de «por hacer» (o su fecha de inicio, o su creación), como en la colorimetría
    $ini = '';
    foreach ((array)($t['historial'] ?? []) as $h) {
        if (($h['tipo'] ?? '') === 'estado' && ($h['de'] ?? '') === $inicial) { $ini = $dia($h['t'] ?? ''); break; }
    }
    $ini = $ini ?: ($dia($t['fecha_inicio'] ?? '') ?: $dia($t['creado'] ?? ''));
    $as = TareaRepo::asignadosDe($t);
    foreach ($as as $a) $gente[$a] = true;
    $x = ['e' => (string)($t['estado'] ?? $inicial), 'p' => (string)($t['prioridad'] ?? ''), 'c' => $dia($t['creado'] ?? ''),
          'f' => $final ? $dia($t['completada_en'] ?? '') : '', 'i' => $ini, 'l' => $dia($t['fecha_limite'] ?? ''), 'a' => $as];
    $nota($x['c']);
    $nota($x['f']);
    $jsT[] = $x;
}
usort($jsT, fn($a, $b) => $a['c'] <=> $b['c']);

/* ---------- Equipos de Releases que trabajan en el proyecto y sus ítems ---------- */
$eqsProy = [];
foreach (Releases::equipos() as $nom => $e) {
    $nom = (string)$nom;
    if (in_array($id, Releases::proyectosEquipo($nom), true) || Releases::mismoNombre((string)$proyecto['nombre'], $nom)) $eqsProy[$nom] = $e;
}
$idsItems = [];
foreach (Releases::itemsDeProyecto($id) as $it) $idsItems[(int)$it['id']] = true;
$jsI = [];
foreach (Releases::items() as $it) {   // ya calculados (avance, esperado, salud) y en el orden del plan
    if (!isset($idsItems[(int)$it['id']])) continue;
    $jsI[] = ['c' => (string)$it['codigo'], 't' => (string)$it['titulo'], 'r' => (string)$it['release'], 'eq' => (string)$it['equipo'],
              'ini' => (string)($it['inicio'] ?? ''), 'fin' => (string)($it['fin'] ?? ''), 'av' => (int)$it['avance'], 'esp' => (int)$it['esperado'],
              's' => (string)$it['salud'], 'k' => Colorimetria::deSalud((string)$it['salud']), 'nt' => count((array)$it['tareas'])];
    $nota((string)($it['inicio'] ?? ''));
    $nota((string)($it['fin'] ?? ''));
}
usort($jsI, fn($a, $b) => [$a['ini'] ?: '9999', $a['c']] <=> [$b['ini'] ?: '9999', $b['c']]);

/* ---------- Releases (para el filtro) y el que está en curso ---------- */
$rels = Releases::releases();
$jsR = [];
foreach ($rels as $cod => $r) {
    $jsR[] = ['c' => (string)$cod, 'n' => (string)($r['nombre'] ?? ''), 'ini' => (string)($r['inicio'] ?? ''), 'fin' => (string)($r['fin'] ?? ''), 'color' => (string)$r['color']];
}
$conItems = array_flip(array_column($jsI, 'r'));
$relActual = '';
foreach ([true, false] as $soloConItems) {   // primero uno en curso donde el proyecto tenga ítems
    foreach ($rels as $cod => $r) {
        if (($r['inicio'] ?? '') !== '' && $r['inicio'] <= $hoy && ($r['fin'] ?? '') >= $hoy && (!$soloConItems || isset($conItems[$cod]))) { $relActual = (string)$cod; break 2; }
    }
}

/* ---------- Sprints e historias del backlog ---------- */
$sprints = Backlog::sprints();
$spActual = Backlog::actual($hoy);
$hist = Backlog::historias(['proyecto_id' => $id]);
$jsH = [];
$spConDatos = [];
foreach ($hist as $h) {
    // En qué sprints estuvo (el de ahora y los que nombra su historial) y de cuáles salió sin terminar
    $vis = [];
    $sal = [];
    if ($h['sprint'] !== '') $vis[$h['sprint']] = true;
    foreach ((array)$h['historial'] as $ev) {
        $tx = (string)($ev['texto'] ?? '');
        if (preg_match_all('/\bS\d{2,3}\b/', $tx, $m)) foreach ($m[0] as $s) if (isset($sprints[$s])) $vis[$s] = true;
        if (preg_match_all('/No se terminó en el (S\d{2,3})/u', $tx, $m)) foreach ($m[1] as $s) if (isset($sprints[$s])) $sal[] = $s;
    }
    $vis = array_keys($vis);
    usort($vis, fn($a, $b) => $sprints[$a]['n'] <=> $sprints[$b]['n']);
    foreach ($vis as $s) $spConDatos[$s] = true;
    $jsH[] = ['c' => (string)$h['codigo'], 't' => (string)$h['titulo'], 's' => (string)$h['sprint'], 'e' => (string)$h['estado'], 'pt' => (int)$h['puntos'],
              'h' => (string)$h['hecho_en'], 'pr' => (string)$h['prioridad'], 'r' => (string)$h['release'], 'it' => (string)($h['item']['codigo'] ?? ''),
              'arr' => (int)$h['arrastres'], 'vis' => $vis, 'sal' => array_values(array_unique($sal)), 'cr' => $dia($h['creado'] ?? '')];
    $nota($dia($h['creado'] ?? ''));
    $nota((string)$h['hecho_en']);
    if ((int)($h['responsable'] ?? 0) > 0) $gente[(int)$h['responsable']] = true;
}
$spConDatos[$spActual] = true;
$jsS = [];
foreach ($sprints as $cod => $s) {
    $d = Backlog::dias($s, $hoy);
    $jsS[] = ['c' => (string)$cod, 'n' => (int)$s['n'], 'ini' => $s['inicio'], 'fin' => $s['fin'], 'rel' => array_values($s['releases']), 'dt' => $d['total'], 'dq' => $d['quedan']];
    if (isset($spConDatos[$cod]) && $cod !== $spActual) { $nota($s['inicio']); $nota($s['fin']); }
}
// Burndown de cada sprint con historias: las que estuvieron en él (las que pasaron al siguiente siguen contando como pendientes)
$burn = [];
foreach (array_keys($spConDatos) as $cod) {
    if (!isset($sprints[$cod])) continue;
    $delSp = array_values(array_filter($hist, function ($h) use ($cod) {
        if ($h['sprint'] === $cod) return true;
        foreach ((array)$h['historial'] as $ev) if (preg_match('/\b' . preg_quote($cod, '/') . '\b/', (string)($ev['texto'] ?? ''))) return true;
        return false;
    }));
    if (!$delSp) continue;
    $b = Backlog::burndown($cod, $delSp, $hoy);
    $burn[$cod] = ['total' => (int)$b['total'], 'dias' => array_map(fn($f, $v) => [$f, $v], array_keys($b['dias']), array_values($b['dias']))];
}
$vel = Backlog::velocidad($id, 3, $hoy);

/* ---------- Reuniones diarias y revisiones de los equipos del proyecto ---------- */
$confDl = Releases::conf()['dailies'];
$jsD = [];
$jsRev = [];
foreach ($eqsProy as $nom => $e) {
    try {
        $r = Releases::resumenDailies($nom);
        if ($r['aplica']) {
            $dias = [];
            foreach ($r['dias'] as $f => $x) {
                $dias[] = ['f' => (string)$f, 'e' => (string)$x['estado'], 'as' => isset($x['asistencia']) ? (int)$x['asistencia'] : null,
                           'esp' => array_values(array_map('intval', (array)($x['esperados'] ?? []))), 'pre' => array_values(array_map('intval', (array)($x['presentes'] ?? [])))];
                foreach ((array)($x['esperados'] ?? []) as $mid) $gente[(int)$mid] = true;
            }
            $jsD[] = ['eq' => $nom, 'color' => (string)$e['color'], 'desde' => (string)$r['desde'], 'dias' => $dias];
            if ($dias) $nota($dias[0]['f']);
        }
    } catch (Throwable $ex) { /* sin dailies de ese equipo: su tarjeta sale vacía */ }
    foreach (Releases::revisiones(['equipo' => $nom]) as $rv) {
        $c = (array)$rv['cumplimiento'];
        $jsRev[] = ['eq' => $nom, 'f' => (string)$rv['fecha'], 'est' => (string)($rv['estado'] ?? 'programada'), 'pct' => $c['pct'] ?? null,
                    'cu' => (int)($c['cumplido'] ?? 0), 'pa' => (int)($c['parcial'] ?? 0), 'nc' => (int)($c['no_cumplido'] ?? 0), 'pr' => (int)($c['programados'] ?? 0)];
    }
}
usort($jsRev, fn($a, $b) => [$a['f'], $a['eq']] <=> [$b['f'], $b['eq']]);

/* ---------- Lo que ya trae el resumen: avance, completadas y salud (como la cabecera del proyecto) ---------- */
$colP = Colorimetria::delProyecto($proyecto);
$puntaje = $colP['ev']['puntaje'] ?? null;
$metaCiclo = (float)(Colorimetria::ind('ciclo')['g'] ?? 5);
$kpi = ['total' => count($tareas), 'completadas' => $tRepo->completadas($id), 'avance' => $tRepo->avance($id),
        'col' => (string)$colP['estado'], 'vel' => $vel['promedio']];

$personas = [];
foreach (array_keys($gente) as $mid) if (isset($mapa[$mid])) $personas[$mid] = (string)$mapa[$mid]['nombre'];

$feriados = [];
$fechas = array_values(array_filter($fechas));
$todoDesde = $fechas ? min($fechas) : date('Y-m-d', strtotime('-89 days'));
$todoHasta = max($hoy, $fechas ? max($fechas) : $hoy);
foreach (Feriados::mapa() as $f => $nomF) if ($f >= $todoDesde && $f <= $todoHasta) $feriados[$f] = $nomF;

/* ---------- Filtros iniciales (la URL los guarda: ?desde, hasta, r, s, e, rango) ---------- */
$relIni = (string)($_GET['r'] ?? '');
if ($relIni !== '' && !isset($rels[$relIni])) $relIni = '';
$spIni = (string)($_GET['s'] ?? '');
if ($spIni !== '' && !isset($sprints[$spIni])) $spIni = '';
$eqIni = (string)($_GET['e'] ?? '');
if ($eqIni !== '' && !isset($eqsProy[$eqIni])) $eqIni = '';
$rango = (string)($_GET['rango'] ?? '');
$desde = Releases::fecha($_GET['desde'] ?? '');
$hasta = Releases::fecha($_GET['hasta'] ?? '');
if ($desde === '' || $hasta === '') {
    // Sin fechas en la URL: el release en curso (o los últimos 90 días si no hay uno)
    $rango = in_array($rango, ['30', '90', 'rel', 'todo'], true) ? $rango : ($relActual !== '' ? 'rel' : '90');
    if ($rango === 'rel' && $relActual === '') $rango = '90';
    [$desde, $hasta] = match ($rango) {
        '30'   => [date('Y-m-d', strtotime($hoy . ' -29 days')), $hoy],
        'rel'  => [$rels[$relActual]['inicio'], $rels[$relActual]['fin']],
        'todo' => [$todoDesde, $todoHasta],
        default => [date('Y-m-d', strtotime($hoy . ' -89 days')), $hoy],
    };
    if ($rango === 'rel' && $relIni === '') $relIni = $relActual;
}
if ($desde > $hasta) [$desde, $hasta] = [$hasta, $desde];

$optRel = ['todos' => 'Todos los releases'];
foreach ($rels as $cod => $r) $optRel[(string)$cod] = $cod . ' · ' . ($r['nombre'] ?? '');
$optSp = ['todos' => 'Todos los sprints'];
foreach ($sprints as $cod => $s) {
    if ($relIni !== '' && !in_array($relIni, $s['releases'], true)) continue;
    $optSp[(string)$cod] = $cod . ' · ' . $fc($s['inicio']) . ' – ' . $fc($s['fin']) . ($cod === $spActual ? ' (en curso)' : '');
}
if ($spIni !== '' && !isset($optSp[$spIni])) $spIni = '';

$datos = [
    'hoy' => $hoy, 'pid' => $id, 'todo' => [$todoDesde, $todoHasta],
    'estados' => array_map(fn($k, $v) => ['k' => (string)$k, 'n' => (string)$v[0], 'fin' => in_array($k, $finales, true)], array_keys($estados), $estados),
    'prioridades' => array_map(fn($k, $v) => ['k' => (string)$k, 'n' => (string)$v[0]], array_keys(Catalogo::prioridades()), Catalogo::prioridades()),
    'huEstados' => array_map(fn($k, $v) => ['k' => $k, 'n' => $v[0]], array_keys(Backlog::ESTADOS), Backlog::ESTADOS),
    'salud' => array_map(fn($v) => $v[0], Releases::SALUD),
    'dailyEstados' => array_map(fn($v) => $v[0], Releases::DAILY),
    'miembros' => $personas, 'tareas' => $jsT, 'historias' => $jsH, 'sprints' => $jsS, 'spActual' => $spActual, 'burn' => $burn,
    'releases' => $jsR, 'relActual' => $relActual, 'items' => $jsI, 'equipos' => array_keys($eqsProy),
    'dailies' => $jsD, 'umbral' => (int)$confDl['umbral'], 'revisiones' => $jsRev, 'feriados' => $feriados,
    'kpi' => $kpi, 'metaCiclo' => $metaCiclo,
    'filtros' => ['desde' => $desde, 'hasta' => $hasta, 'rel' => $relIni, 'sp' => $spIni, 'eq' => $eqIni, 'rango' => in_array($rango, ['30', '90', 'rel', 'todo'], true) ? $rango : ''],
];

// Una tarjeta del tablero con su gráfico (lo dibuja assets/analitica.js en #an-g-<clave>)
$pan = function (int $ancho, string $titulo, string $clave, int $alto = 280, string $nota = ''): string {
    return '<section class="rl-pan rl-pan--' . $ancho . '"><header class="rl-pan__cab"><h3>' . e($titulo) . '</h3>'
        . '<small class="an-pan__sub" data-an-sub="' . e($clave) . '"></small></header><div class="rl-pan__cuerpo">'
        . '<div class="an-graf" id="an-g-' . e($clave) . '" style="--alto:' . $alto . 'px"></div>'
        . ($nota !== '' ? '<p class="rl-pan__nota">' . $nota . '</p>' : '') . '</div></section>';
};
$kpiHtml = fn(string $clave, string $lbl, string $val, string $tono, string $pie = '') => '<div class="rl-kpi ' . ($tono === 'azul' ? 'rl-kpi--azul' : 'col-s-' . e($tono)) . '" data-an-kpi="' . e($clave) . '">'
    . '<small>' . e($lbl) . '</small><b class="mc-num">' . $val . '</b><em>' . $pie . '</em></div>';

require_once __DIR__ . '/lib/colorimetria_ui.php';
UI::inicio('Analítica · ' . $proyecto['nombre'], 'proyecto-' . $id);
?>
<link rel="stylesheet" href="<?= asset('assets/analitica.css') ?>">
<div class="ds an-pag" data-an>
  <nav class="mc-crumbs rl-migas" aria-label="Estás en">
    <a class="mc-crumbs__link" href="proyecto.php?id=<?= $id ?>"><?= e((string)$proyecto['nombre']) ?></a><span class="mc-crumbs__sep">/</span>
    <span class="mc-crumbs__cur">Analítica</span>
  </nav>

  <header class="an-cab">
    <span class="mc-tile mc-tile--azul" aria-hidden="true"><?= UI::iconoDS('reportes', 24) ?></span>
    <div class="an-cab__txt">
      <h1 class="font-display">Analítica de <?= e((string)$proyecto['nombre']) ?></h1>
      <p>Sprints, release, reuniones diarias y tareas en un solo lugar, ordenados por fecha. Los filtros cambian todos los gráficos al instante.</p>
    </div>
    <div class="an-cab__acc">
      <?= UI::volver('proyecto.php?id=' . $id, 'Volver al proyecto') ?>
    </div>
  </header>

  <section class="an-filtros" aria-label="Filtros de la analítica">
    <div class="an-filtros__arriba">
      <div class="mc-tabs mc-tabs--segmented an-f-rango" role="group" aria-label="Atajos del periodo">
        <?php foreach (['30' => 'Últimos 30 días', '90' => 'Últimos 90 días', 'rel' => 'Release en curso', 'todo' => 'Todo'] as $k => $lbl): ?>
        <button type="button" class="mc-tab<?= $datos['filtros']['rango'] === (string)$k ? ' is-active' : '' ?>" data-an-rango="<?= e((string)$k) ?>" aria-pressed="<?= $datos['filtros']['rango'] === (string)$k ? 'true' : 'false' ?>"<?= $k === 'rel' && $relActual === '' ? ' disabled data-ds-tip="Hoy no hay un release en curso"' : '' ?>><?= e($lbl) ?></button>
        <?php endforeach; ?>
      </div>
      <p class="an-filtros__resumen"><?= UI::iconoDS('agenda', 16) ?><span data-an-periodo>Del <?= e($fc($desde)) ?> al <?= e($fc($hasta)) ?></span></p>
    </div>
    <div class="an-filtros__fila<?= count($eqsProy) > 1 ? ' an-filtros__fila--5' : '' ?>">
      <div class="mc-field an-f-fecha"><span class="mc-field__label">Desde</span><input class="input-meca" type="date" id="an-desde" name="desde" value="<?= e($desde) ?>" aria-label="Desde"></div>
      <div class="mc-field an-f-fecha"><span class="mc-field__label">Hasta</span><input class="input-meca" type="date" id="an-hasta" name="hasta" value="<?= e($hasta) ?>" aria-label="Hasta"></div>
      <div class="mc-field"><span class="mc-field__label">Release</span><?= UI::selectDS('r', $optRel, $relIni !== '' ? $relIni : 'todos', ['tam' => 'md', 'aria' => 'Release', 'attrs' => 'data-an-f="rel"']) ?></div>
      <div class="mc-field"><span class="mc-field__label">Sprint</span><?= UI::selectDS('s', $optSp, $spIni !== '' ? $spIni : 'todos', ['tam' => 'md', 'aria' => 'Sprint', 'buscar' => true, 'attrs' => 'data-an-f="sp"']) ?></div>
      <?php if (count($eqsProy) > 1): /* con un solo equipo no hay nada que elegir */ ?>
      <div class="mc-field"><span class="mc-field__label">Equipo</span><?= UI::selectDS('e', ['todos' => 'Todos los equipos'] + array_combine(array_keys($eqsProy), array_map(fn($n) => 'Equipo ' . $n, array_keys($eqsProy))), $eqIni !== '' ? $eqIni : 'todos', ['tam' => 'md', 'aria' => 'Equipo', 'attrs' => 'data-an-f="eq"']) ?></div>
      <?php endif; ?>
    </div>
  </section>

  <?php /* Las secciones como las fases del DS: lo ya recorrido ✓, donde se está », lo que falta •
           (assets/analitica.js las marca al bajar por la página) */ ?>
  <nav class="an-fases" aria-label="Secciones de la analítica" data-an-fases>
    <?php foreach (['an-resumen' => 'Resumen', 'an-salud' => 'Salud', 'an-sprints' => 'Sprints', 'an-release' => 'Release', 'an-dailies' => 'Reuniones diarias', 'an-tareas' => 'Tareas'] as $ancla => $txt): ?>
    <a class="an-fase" href="#<?= $ancla ?>" data-an-fase="<?= $ancla ?>"><span class="an-fase__ico" aria-hidden="true"></span><span><?= $txt ?></span></a>
    <?php endforeach; ?>
  </nav>

  <!-- Resumen: los indicadores primero; el detalle de cada tema, abajo -->
  <div class="rl-tab" id="an-resumen">
    <section class="rl-pan rl-pan--12">
      <header class="rl-pan__cab"><h3>Resumen</h3><small class="an-pan__sub" data-an-sub="resumen"></small></header>
      <div class="rl-pan__cuerpo">
        <dl class="rl-mini">
          <div><dt>Periodo</dt><dd class="mc-num" data-an-mini="periodo">—</dd></div>
          <div><dt>Sprints</dt><dd class="mc-num" data-an-mini="sprints">—</dd></div>
          <div><dt>Historias</dt><dd class="mc-num" data-an-mini="historias">—</dd></div>
          <div><dt>Ítems del release</dt><dd class="mc-num" data-an-mini="items">—</dd></div>
          <div><dt><?= count($eqsProy) === 1 ? 'Equipo' : 'Equipos' ?></dt><dd><?= $eqsProy ? e(implode(', ', array_keys($eqsProy))) : 'Sin equipo en Releases' ?></dd></div>
        </dl>
        <div class="rl-kpis an-kpis">
          <?= $kpiHtml('tareas', 'Tareas', (string)$kpi['total'], 'azul') ?>
          <?= $kpiHtml('completadas', 'Completadas', (string)$kpi['completadas'], 'done') ?>
          <?= $kpiHtml('vencidas', 'Vencidas', '—', 'nm') ?>
          <?= $kpiHtml('avance', 'Avance', $kpi['avance'] . '<small>%</small>', $kpi['col']) ?>
          <div class="rl-kpi col-s-<?= e($kpi['col']) ?>" data-an-kpi="salud">
            <small>Salud</small><b><?= Colorimetria::icono($kpi['col'], 20) ?><?= e(Colorimetria::nombre($kpi['col'])) ?></b>
            <em><?= $puntaje !== null && in_array($kpi['col'], ['ok', 'warn', 'crit'], true) ? 'Puntaje ' . e(Colorimetria::num((float)$puntaje)) . ' de 100' : 'Según la colorimetría' ?></em></div>
          <?= $kpiHtml('sprint', 'Sprint en curso', '—', 'azul') ?>
          <?= $kpiHtml('velocidad', 'Velocidad', '—', 'azul') ?>
          <?= $kpiHtml('dailies', 'Dailies', '—', 'nm') ?>
        </div>
      </div>
    </section>
  </div>

  <?php /* La salud del proyecto con la colorimetría (la que antes salía en el modal del proyecto): el puntaje,
           cada indicador con su meta y sus zonas, lo que no tiene dato y los valores del mes */ ?>
  <h2 class="an-sec" id="an-salud"><?= UI::iconoDS('info', 20) ?>Salud<small>Lo calcula el panel con sus tareas, revisiones y observaciones de QA</small></h2>
  <div class="rl-tab">
    <section class="rl-pan rl-pan--12">
      <header class="rl-pan__cab"><h3><?= Colorimetria::icono($colP['estado'], 18) ?> Salud de <?= e((string)$proyecto['nombre']) ?></h3><small class="an-pan__sub"><?= e(Colorimetria::nombre($colP['estado'])) ?></small></header>
      <div class="rl-pan__cuerpo an-salud"><?= colPagina($colP, 'analitica.php?id=' . $id . '#an-salud') ?></div>
    </section>
  </div>

  <h2 class="an-sec" id="an-sprints"><?= UI::iconoDS('ruta', 20) ?>Sprints<small>Velocidad, burndown y lo que pasó de un sprint a otro</small></h2>
  <div class="rl-tab">
    <section class="rl-pan rl-pan--12">
      <header class="rl-pan__cab"><h3>Sprints del release</h3><small class="an-pan__sub" data-an-sub="pasos"></small></header>
      <div class="rl-pan__cuerpo"><div class="an-pasos" id="an-pasos"></div></div>
    </section>
    <?= $pan(8, 'Comprometido vs. hecho por sprint', 'vel', 300, 'Comprometido: los puntos de las historias que estuvieron en el sprint. Hecho: los que se terminaron en sus fechas (la velocidad).') ?>
    <?= $pan(4, 'Historias por estado', 'hu-estado', 300, 'Con un sprint elegido, solo sus historias; si no, las de los sprints del periodo y las del backlog.') ?>
    <?= $pan(8, 'Burndown del sprint', 'burn', 300, 'Los puntos que faltaban cada día hábil frente a la línea ideal.') ?>
    <?= $pan(4, 'Puntos por prioridad', 'hu-prio', 300) ?>
    <?= $pan(6, 'Historias que pasaron de sprint', 'arr', 280, 'Cuántas historias no se terminaron en cada sprint y pasaron al siguiente.') ?>
    <section class="rl-pan rl-pan--6">
      <header class="rl-pan__cab"><h3>Las que más pasaron</h3><small class="an-pan__sub" data-an-sub="arr-tabla"></small></header>
      <div class="rl-pan__cuerpo"><div class="an-tabla-caja" id="an-t-arr"></div></div>
    </section>
  </div>

  <h2 class="an-sec" id="an-release"><?= UI::iconoDS('bandera', 20) ?>Release<small>Avance real frente al esperado y la línea de tiempo de los ítems</small></h2>
  <div class="rl-tab">
    <?= $pan(4, 'Avance por release', 'rel', 300, 'El color es la salud según la colorimetría.') ?>
    <?= $pan(4, 'Ítems por salud', 'salud', 300) ?>
    <?= $pan(4, 'Cumplimiento de las revisiones', 'rev', 300, 'De lo revisado en cada sesión, lo cumplido.') ?>
    <?= $pan(12, 'Avance real vs. esperado por ítem', 'items', 320, '<span class="an-ley"><i class="an-ley__barra"></i>El color es la salud según la colorimetría</span><span class="an-ley"><i class="an-ley__marca"></i>La marca es lo que el plan espera hoy</span>') ?>
    <?= $pan(12, 'Línea de tiempo de los ítems', 'gantt', 320, 'Cada barra va del inicio al fin del plan; la línea vertical es hoy.') ?>
  </div>

  <h2 class="an-sec" id="an-dailies"><?= UI::iconoDS('video', 20) ?>Reuniones diarias<small>Cumplimiento y asistencia <?= count($eqsProy) === 1 ? 'del equipo' : 'de los equipos' ?> del proyecto</small></h2>
  <div class="rl-tab">
    <?= $pan(8, 'Cumplimiento por día', 'dl-cal', 260, '<span data-an-ley-dl></span>') ?>
    <?= $pan(4, 'Días por estado', 'dl-est', 260) ?>
    <?= $pan(6, 'Asistencia en el tiempo', 'dl-asis', 280, 'El porcentaje del equipo que se conectó cada día; la línea es el mínimo de Ajustes.') ?>
    <?= $pan(6, 'Asistencia por persona', 'dl-per', 280) ?>
  </div>

  <h2 class="an-sec" id="an-tareas"><?= UI::iconoDS('completar', 20) ?>Tareas<small>El tablero del proyecto en el tiempo</small></h2>
  <div class="rl-tab">
    <?= $pan(8, 'Creadas vs. terminadas', 't-sem', 300) ?>
    <?= $pan(4, 'Tareas por estado', 't-est', 300, 'Las que estuvieron abiertas en algún momento del periodo, con su estado de hoy.') ?>
    <?= $pan(8, 'Tareas por responsable', 't-resp', 300) ?>
    <?= $pan(4, 'Tareas por prioridad', 't-prio', 300) ?>
    <?= $pan(6, 'Vencidas en el tiempo', 't-venc', 280, 'Tareas abiertas con la fecha límite ya pasada, al cierre de cada semana.') ?>
    <?= $pan(6, 'Tiempo de ciclo', 't-ciclo', 280, 'Días promedio entre empezar y terminar una tarea; la línea es la meta de la colorimetría.') ?>
  </div>
</div>
<template id="an-ico-vacio"><?= UI::iconoDS('reportes', 28) ?></template>
<script type="application/json" id="an-datos"><?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="<?= asset('assets/apexcharts.min.js') ?>" defer></script>
<script src="<?= asset('assets/analitica.js') ?>" defer></script>
<?php UI::fin(); ?>
