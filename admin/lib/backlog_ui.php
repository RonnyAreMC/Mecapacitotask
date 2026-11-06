<?php
/**
 * La pestaña «Sprints» del proyecto (proyecto.php): el backlog estilo Jira.
 *
 *  - Arriba, el release (los del proyecto), el sprint en curso, la velocidad y
 *    lo que falta estimar; «Backlog» o «Tablero del sprint».
 *  - Backlog: cada sprint del release con sus historias (y sus puntos frente a
 *    la capacidad) y, al final, el backlog sin sprint. Se arrastran entre sí.
 *  - Tablero: las columnas Por hacer · En curso · En revisión · Hecho del sprint
 *    elegido, con su burndown. Se arrastran entre columnas.
 *  - Cada historia se abre en su pantalla completa (historia.php): crear,
 *    editar o, para quien no la gestiona, solo verla.
 *
 * El modelo es lib/Backlog.php; las acciones (bl_*) están en actions.php y el
 * JavaScript en assets/backlog.js. Usa las ayudas de lib/releases_ui.php.
 */
// El sprint del proyecto (objetivo, capacidad, retrospectiva y «Lista para sprint»): lib/sprint_ui.php
if (is_file(__DIR__ . '/sprint_ui.php')) require_once __DIR__ . '/sprint_ui.php';

/** Chip del estado de una historia. */
function blEstado(string $k): string
{
    [$lbl, $tono] = Backlog::ESTADOS[$k] ?? Backlog::ESTADOS['por_hacer'];
    return '<span class="bl-est bl-t--' . $tono . '">' . e($lbl) . '</span>';
}

/** La estimación (puntos o talla) en su pastilla; «—» si falta. */
function blPts(array $h): string
{
    $e = (string)$h['estimacion'];
    return '<span class="bl-pts' . ($e === '' || $e === '?' ? ' is-falta' : '') . '" data-ds-tip="' . ($e === '' ? 'Sin estimar' : ($e === '?' ? 'Por estimar' : e($e) . ' · ' . (int)$h['puntos'] . ' pts')) . '">'
        . ($e === '' ? '—' : e($e)) . '</span>';
}

/** La prioridad, como en Jira: flecha arriba (alta), raya (media) o flecha abajo (baja). */
function blPrio(string $p): string
{
    $ico = ['alta' => 'chevron-arriba', 'media' => 'menos', 'baja' => 'chevron-abajo'][$p] ?? 'menos';
    return '<span class="bl-prio bl-prio--' . e($p) . '" data-ds-tip="Prioridad ' . e(mb_strtolower(Backlog::PRIORIDADES[$p] ?? 'media')) . '">' . rlIco($ico, 16) . '</span>';
}

/** Quién la lleva (su avatar) o un guion. */
function blResp(array $h, array $miembros): string
{
    $m = $miembros[(int)$h['responsable']] ?? null;
    return $m ? '<span class="bl-resp" data-ds-tip="' . e($m['nombre']) . '">' . UI::avatar($m, 26) . '</span>' : '<span class="bl-resp is-nadie" data-ds-tip="Sin responsable">' . rlIco('usuario', 14) . '</span>';
}

/** La épica (ítem del release) con el color de su release. */
function blEpica(array $h, array $rels): string
{
    if (!$h['item']) return '';
    return '<span class="bl-epica" style="--rc:' . e(rlColorRel($rels, $h['item']['release'])) . '" data-ds-tip="' . e($h['item']['codigo'] . ' · ' . $h['item']['titulo']) . '">' . e($h['item']['codigo']) . '</span>';
}

/** Una fila del backlog. */
function blFila(array $h, array $x): string
{
    $nAdj = count((array)$h['adjuntos']);
    $nT = count($h['tareas']);
    return '<li class="bl-fila" data-bl-id="' . (int)$h['id'] . '" data-pts="' . (int)$h['puntos'] . '" data-hecho="' . ($h['estado'] === 'hecho' ? 1 : 0) . '"' . (function_exists('blDorAttr') ? blDorAttr($h) : '') . ($x['puede'] ? ' draggable="true"' : '') . '>'
        . ($x['puede'] ? '<span class="bl-fila__asa" aria-hidden="true">' . rlIco('arrastrar', 16) . '</span>' : '')
        . '<a class="bl-fila__abrir" href="' . e(blUrlHistoria((int)$h['proyecto_id'], (int)$h['id'])) . '" draggable="false"><span class="bl-cod">' . e($h['codigo']) . '</span>'
        . '<span class="bl-fila__tit">' . e($h['titulo']) . '</span></a>'
        . '<span class="bl-fila__meta">' . (function_exists('blDorChip') ? blDorChip($h) : '') . blEpica($h, $x['rels'])
        . ($nAdj ? '<span class="bl-mini" data-ds-tip="' . $nAdj . ' diagrama(s) o documento(s)">' . rlIco('documento', 14) . $nAdj . '</span>' : '')
        . ($nT ? '<span class="bl-mini" data-ds-tip="' . $nT . ' tarea(s)">' . rlIco('completar', 14) . $nT . '</span>' : '')
        . ((int)$h['arrastres'] ? '<span class="bl-mini is-arrastre" data-ds-tip="Pasó sola de sprint ' . (int)$h['arrastres'] . ' vez/veces por no terminarse">' . rlIco('reintentar', 14) . (int)$h['arrastres'] . '</span>' : '')
        . '</span>' . blEstado($h['estado']) . blPrio($h['prioridad']) . blPts($h) . blResp($h, $x['miembros']) . '</li>';
}

/** Una tarjeta del tablero del sprint. */
function blTarjeta(array $h, array $x): string
{
    $ts = array_values(array_filter(array_map(fn($id) => $x['tareasPorId'][(int)$id] ?? null, $h['tareas'])));
    $hechas = count(array_filter($ts, fn($t) => in_array($t['estado'] ?? '', $x['finales'], true)));
    return '<article class="bl-card" data-bl-id="' . (int)$h['id'] . '" data-pts="' . (int)$h['puntos'] . '"' . ($x['puede'] ? ' draggable="true"' : '') . '>'
        . '<a class="bl-card__abrir" href="' . e(blUrlHistoria((int)$h['proyecto_id'], (int)$h['id'])) . '" draggable="false"><span class="bl-card__tit">' . e($h['titulo']) . '</span></a>'
        . '<div class="bl-card__meta">' . (function_exists('blDorChip') ? blDorChip($h) : '') . blEpica($h, $x['rels']) . ($ts ? '<span class="bl-mini" data-ds-tip="Tareas terminadas">' . rlIco('completar', 14) . $hechas . '/' . count($ts) . '</span>' : '') . '</div>'
        . '<footer class="bl-card__pie"><span class="bl-cod">' . e($h['codigo']) . '</span>' . blPrio($h['prioridad']) . blPts($h) . blResp($h, $x['miembros']) . '</footer></article>';
}

/** El burndown del sprint en SVG: la línea ideal y lo que de verdad quedaba cada día hábil. */
function blBurndown(array $bd): string
{
    $dias = $bd['dias'];
    $tot = max(1, (int)$bd['total']);
    $n = count($dias);
    if (!$n || !$bd['total']) return '<p class="bl-vacio">' . rlIco('info', 16) . ' El burndown sale cuando el sprint tenga historias estimadas.</p>';
    $W = 320; $H = 140; $pl = 28; $pb = 20; $pt = 10; $pr = 8;
    $x = fn(int $i) => $pl + ($n > 1 ? $i / ($n - 1) : 0) * ($W - $pl - $pr);
    $y = fn(float $v) => $pt + (1 - $v / $tot) * ($H - $pt - $pb);
    $real = [];
    $i = 0;
    foreach ($dias as $f => $v) { if ($v !== null) $real[] = round($x($i), 1) . ',' . round($y((float)$v), 1); $i++; }
    $ult = array_key_last($dias);
    $conValor = array_values(array_filter($dias, fn($v) => $v !== null));
    $restan = $conValor ? (int)$conValor[count($conValor) - 1] : (int)$bd['total'];
    // Lo real con el tono de la colorimetría: cuánto le falta frente a lo ideal en el último día medido
    $k = max(0, count($conValor) - 1);
    $exceso = ($restan - $tot * (1 - ($n > 1 ? $k / ($n - 1) : 1))) / $tot;
    $tono = $exceso <= 0.05 ? 'ok' : ($exceso <= 0.2 ? 'warn' : 'crit');
    return '<svg class="bl-bd col-s-' . $tono . '" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Burndown: de ' . (int)$bd['total'] . ' puntos, quedan ' . $restan . '">'
        . '<line class="bl-bd__eje" x1="' . $pl . '" y1="' . ($H - $pb) . '" x2="' . ($W - $pr) . '" y2="' . ($H - $pb) . '"/>'
        . '<line class="bl-bd__eje" x1="' . $pl . '" y1="' . $pt . '" x2="' . $pl . '" y2="' . ($H - $pb) . '"/>'
        . '<text class="bl-bd__txt" x="' . ($pl - 4) . '" y="' . ($pt + 4) . '" text-anchor="end">' . (int)$bd['total'] . '</text>'
        . '<text class="bl-bd__txt" x="' . ($pl - 4) . '" y="' . ($H - $pb) . '" text-anchor="end">0</text>'
        . '<text class="bl-bd__txt" x="' . $pl . '" y="' . ($H - 4) . '">' . e(rlF((string)array_key_first($dias))) . '</text>'
        . '<text class="bl-bd__txt" x="' . ($W - $pr) . '" y="' . ($H - 4) . '" text-anchor="end">' . e(rlF((string)$ult)) . '</text>'
        . '<line class="bl-bd__ideal" x1="' . $x(0) . '" y1="' . $y((float)$tot) . '" x2="' . $x($n - 1) . '" y2="' . $y(0) . '"/>'
        . ($real ? '<polyline class="bl-bd__real" points="' . implode(' ', $real) . '"/>' : '')
        . '</svg>';
}

/**
 * La pestaña completa. $x: proyecto, miembros (id => miembro), tareas (todas las
 * del proyecto), puede (lo gestiona: PO, SM o admin), url (fn(array) => enlace a la pestaña).
 */
function blPestana(array $x): string
{
    $p = $x['proyecto'];
    $pid = (int)$p['id'];
    $hoy = date('Y-m-d');
    $conf = Backlog::conf();
    Backlog::arrastrar($hoy);   // lo no terminado de un sprint que ya pasó, al sprint en curso
    $sps = Backlog::sprints();
    $act = Backlog::actual($hoy);
    $rels = Releases::releases();
    $epicas = Backlog::epicas($pid);
    // Los releases del proyecto (los de sus ítems); sin ítems, todos
    $relsP = array_values(array_filter(array_keys($rels), fn($c) => in_array((string)$c, array_column($epicas, 'release'), true))) ?: array_map('strval', array_keys($rels));
    $r = (string)($_GET['sp_r'] ?? '');
    if (!in_array($r, $relsP, true)) {
        $r = '';
        foreach ($relsP as $c) if (($rels[$c]['inicio'] ?? '') <= $hoy && ($rels[$c]['fin'] ?? '') >= $hoy) { $r = $c; break; }
        if ($r === '') foreach ($relsP as $c) if (($rels[$c]['fin'] ?? '') >= $hoy) { $r = $c; break; }
        if ($r === '') $r = (string)end($relsP);
    }
    $vista = ($_GET['sp_v'] ?? '') === 'tablero' ? 'tablero' : 'backlog';
    $url = $x['url'];
    $puede = $x['puede'];
    $todas = Backlog::historias(['proyecto_id' => $pid]);
    $delRel = array_values(array_filter($todas, fn($h) => $h['release'] === $r || ($h['release'] === '' && $h['sprint'] !== '' && in_array($r, $sps[$h['sprint']]['releases'] ?? [], true))));
    $porSprint = [];
    foreach ($todas as $h) $porSprint[$h['sprint']][] = $h;
    $spsR = array_filter($sps, fn($s) => in_array($r, $s['releases'], true));
    $tareasPorId = [];
    foreach ($x['tareas'] as $t) $tareasPorId[(int)$t['id']] = $t;
    $ctx = ['puede' => $puede, 'rels' => $rels, 'miembros' => $x['miembros'], 'tareasPorId' => $tareasPorId, 'finales' => Catalogo::estadosFinales()];
    $vel = Backlog::velocidad($pid);
    $cap = $conf['capacidad'] ?: (int)($vel['promedio'] ?? 0);
    $ptsRel = array_sum(array_column($delRel, 'puntos'));
    $ptsHechos = array_sum(array_map(fn($h) => $h['estado'] === 'hecho' ? $h['puntos'] : 0, $delRel));
    $sinEst = count(array_filter($delRel, fn($h) => $h['estimacion'] === '' || $h['estimacion'] === '?'));
    $sAct = $sps[$act] ?? null;
    $dAct = $sAct ? Backlog::dias($sAct, $hoy) : ['total' => 0, 'quedan' => 0];
    $kpi = fn(string $lbl, string $val, string $k, string $pie = '') => '<div class="rl-kpi' . ($k === 'azul' ? ' rl-kpi--azul' : ' col-s-' . $k) . '"><small>' . e($lbl) . '</small><b class="mc-num">' . $val . '</b>' . ($pie !== '' ? '<em>' . $pie . '</em>' : '') . '</div>';
    $pctHecho = $ptsRel ? (int)round($ptsHechos * 100 / $ptsRel) : null;

    ob_start(); ?>
<div class="bl" data-bl data-pid="<?= $pid ?>" data-puede="<?= $puede ? '1' : '' ?>">
  <div class="bl-cab">
    <?php // El release, en una lista (no regado a lo largo): al elegir otro, la pestaña se abre en él
      $optRel = [];
      foreach ($relsP as $c) $optRel[$c] = [$c . ' · ' . (string)($rels[$c]['nombre'] ?? ''), rlF((string)($rels[$c]['inicio'] ?? ''), true) . ' → ' . rlF((string)($rels[$c]['fin'] ?? ''), true)]; ?>
    <div class="bl-rel"><span class="bl-rel__rot">Release</span>
      <?= UI::selectDS('sp_r', $optRel, $r, ['tam' => 'md', 'aria' => 'Release', 'attrs' => 'data-bl-rel data-url="' . e($url(['sp_r' => '__R__', 'sp_v' => $vista === 'tablero' ? 'tablero' : ''])) . '"']) ?></div>
    <?php /* Solo íconos, como la cabecera del dashboard: el nombre va en la ayuda */ ?>
    <div class="bl-cab__acc">
      <?php if (Releases::puedeEditar()): ?><a class="rs-boton rs-boton--icono" href="releases.php?v=ajustes&amp;t=sprints" aria-label="Calendario de sprints" data-ds-tip="Calendario de sprints (Releases → Ajustes)"><?= UI::icono('ds-agenda') ?></a><?php endif; ?>
      <?php if ($puede): ?><a class="rs-boton rs-boton--icono rs-boton--primario" href="<?= e(blUrlHistoria($pid)) ?>" aria-label="Nueva historia" data-ds-tip="Nueva historia"><?= UI::icono('ds-mas') ?></a><?php endif; ?>
      <?php if ($puede): ?><a class="rs-boton rs-boton--icono" href="historias_importar.php?p=<?= $pid ?>" aria-label="Importar historias" data-ds-tip="Importar historias desde un documento (Word o PDF)"><?= rlIco('importar', 18) ?></a><?php endif; ?>
      <nav class="rs-vista bl-vista" data-rs-vista data-activa="<?= $vista ?>" aria-label="Qué ver">
        <span class="rs-vista__pieza" aria-hidden="true"></span>
        <a class="rs-vista__op" href="<?= e($url(['sp_r' => $r])) ?>" data-vista-op="backlog" aria-label="Backlog" data-ds-tip="Backlog"<?= $vista === 'backlog' ? ' aria-current="page"' : '' ?>><?= UI::icono('ds-lista-espera') ?></a>
        <a class="rs-vista__op" href="<?= e($url(['sp_r' => $r, 'sp_v' => 'tablero'])) ?>" data-vista-op="tablero" aria-label="Tablero del sprint" data-ds-tip="Tablero del sprint"<?= $vista === 'tablero' ? ' aria-current="page"' : '' ?>><?= UI::icono('ds-dashboard') ?></a>
      </nav>
    </div>
  </div>

  <div class="rl-kpis bl-kpis">
    <?= $sAct ? $kpi('Sprint en curso', e($act), 'azul', e(rlF($sAct['inicio']) . ' → ' . rlF($sAct['fin'])) . ' · quedan ' . (int)$dAct['quedan'] . ' día(s) hábiles') : $kpi('Sprint en curso', '—', 'nm') ?>
    <?= $kpi('Velocidad', $vel['promedio'] !== null ? (int)$vel['promedio'] . '<small> pts</small>' : '—', 'azul', 'por sprint, en los últimos ' . count($vel['sprints'])) ?>
    <?= $kpi('En ' . $r, count($delRel) . '<small> historias</small>', 'azul', (int)$ptsRel . ' pts') ?>
    <?= $kpi('Hecho', $pctHecho !== null ? $pctHecho . '<small>%</small>' : '—', $pctHecho === null ? 'nm' : ($pctHecho >= 80 ? 'ok' : ($pctHecho >= 40 ? 'warn' : 'crit')), (int)$ptsHechos . ' de ' . (int)$ptsRel . ' pts') ?>
    <?= $kpi('Sin estimar', (string)$sinEst, $sinEst ? 'warn' : 'ok', $conf['escala'] === 'camisa' ? 'Talla de camisa' : 'Fibonacci') ?>
  </div>
  <p class="bl-nota"><?= rlIco('reloj', 14) ?> Sprints de <?= $conf['semanas'] === 1 ? 'una semana' : 'dos semanas' ?>, desde el <?= e(rlFL($conf['inicio'], false, true)) ?>: el calendario se calcula solo<?= $conf['arrastrar'] ? ' y lo que no se termina pasa al sprint siguiente' : '' ?>.
    <?php if (Releases::puedeEditar()): ?><a href="releases.php?v=ajustes&amp;t=sprints">Cambiarlo en Releases → Ajustes</a><?php endif; ?></p>

<?php if ($vista === 'backlog'): ?>
  <div class="bl-secs">
    <?php foreach ($spsR as $cod => $s):
      $lista = $porSprint[$cod] ?? [];
      $mom = Backlog::momento($s, $hoy);
      if ($mom === 'pasado' && !$lista) continue;
      $pts = array_sum(array_column($lista, 'puntos'));
      $hechos = array_sum(array_map(fn($h) => $h['estado'] === 'hecho' ? $h['puntos'] : 0, $lista)); ?>
    <details class="bl-sec bl-sec--<?= $mom ?>" data-bl-sec<?= $mom === 'pasado' ? '' : ' open' ?>>
      <summary class="bl-sec__cab">
        <?= rlIco('chevron-der', 16) ?><b class="bl-sec__cod"><?= e($cod) ?></b>
        <span class="bl-sec__fechas"><?= e(rlF($s['inicio']) . ' → ' . rlF($s['fin'])) ?></span>
        <?= $mom === 'actual' ? '<span class="bl-badge bl-badge--actual">En curso</span>' : ($mom === 'pasado' ? '<span class="bl-badge">Terminó</span>' : '') ?>
        <span class="bl-sec__n" data-bl-n><?= count($lista) ?> historia(s)</span>
        <span class="bl-sec__pts" data-bl-pts data-cap="<?= (int)$cap ?>"><b class="mc-num"><?= (int)$pts ?></b> pts<?= $cap ? ' de ' . (int)$cap : '' ?></span>
        <span class="bl-sec__barra<?= $cap && $pts > $cap ? ' is-pasa' : '' ?>" aria-hidden="true"><i style="width:<?= $pts ? (int)round($hechos * 100 / $pts) : 0 ?>%"></i></span>
        <?php if ($puede && $mom !== 'pasado'): ?><a class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte" href="<?= e(blUrlHistoria($pid, 0, $cod)) ?>" aria-label="Nueva historia en el <?= e($cod) ?>" data-ds-tip="Nueva historia aquí"><?= rlIco('mas', 16) ?></a><?php endif; ?>
      <?= function_exists('blSprintCab') ? blSprintCab($pid, $cod) : '' ?>
      </summary>
      <ol class="bl-lista" data-bl-lista data-sprint="<?= e($cod) ?>">
        <?php foreach ($lista as $h) echo blFila($h, $ctx); ?>
        <li class="bl-lista__vacio"<?= $lista ? ' hidden' : '' ?>><?= $puede ? 'Arrastra historias del backlog hasta aquí.' : 'Sin historias.' ?></li>
      </ol>
    </details>
    <?php endforeach; ?>
    <?php $bl = array_values(array_filter($porSprint[''] ?? [], fn($h) => $h['release'] === $r || $h['release'] === ''));
      $ptsBl = array_sum(array_column($bl, 'puntos')); ?>
    <section class="bl-sec bl-sec--backlog" data-bl-sec>
      <header class="bl-sec__cab">
        <?= rlIco('lista-espera', 16) ?><b class="bl-sec__cod">Backlog</b><span class="bl-sec__fechas">Sin sprint todavía · por prioridad</span>
        <span class="bl-sec__n" data-bl-n><?= count($bl) ?> historia(s)</span>
        <span class="bl-sec__pts" data-bl-pts data-cap="0"><b class="mc-num"><?= (int)$ptsBl ?></b> pts</span>
        <?php if ($puede): ?><a class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte" href="<?= e(blUrlHistoria($pid)) ?>" aria-label="Nueva historia en el backlog" data-ds-tip="Nueva historia aquí"><?= rlIco('mas', 16) ?></a><?php endif; ?>
      </header>
      <ol class="bl-lista" data-bl-lista data-sprint="">
        <?php foreach ($bl as $h) echo blFila($h, $ctx); ?>
        <li class="bl-lista__vacio"<?= $bl ? ' hidden' : '' ?>><?= $puede ? 'Aquí van las historias que todavía no tienen sprint. Créalas con «Nueva historia».' : 'El backlog está vacío.' ?></li>
      </ol>
    </section>
  </div>
<?php else:
    // El tablero: el sprint elegido (o el que va, o el primero del release que no pasó)
    $sTab = (string)($_GET['sp_s'] ?? '');
    if (!isset($spsR[$sTab])) {
        $sTab = isset($spsR[$act]) ? $act : '';
        if ($sTab === '') foreach ($spsR as $cod => $s) if ($s['fin'] >= $hoy) { $sTab = $cod; break; }
        if ($sTab === '') $sTab = (string)array_key_last($spsR);
    }
    $codsR = array_keys($spsR);
    $iTab = (int)array_search($sTab, $codsR, true);
    $sp = $spsR[$sTab] ?? null;
    $enSprint = $porSprint[$sTab] ?? [];
    $comp = array_sum(array_column($enSprint, 'puntos'));
    $hecho = array_sum(array_map(fn($h) => $h['estado'] === 'hecho' ? $h['puntos'] : 0, $enSprint));
    $dS = $sp ? Backlog::dias($sp, $hoy) : ['total' => 0, 'quedan' => 0];
    $pctS = $comp ? (int)round($hecho * 100 / $comp) : null; ?>
  <?php if (!$sp): ?>
  <p class="bl-vacio"><?= rlIco('info', 16) ?> Este release todavía no tiene sprints en el calendario.</p>
  <?php else: ?>
  <div class="bl-tabcab">
    <a class="mc-iconbtn mc-iconbtn--tinte<?= $iTab > 0 ? '' : ' is-off' ?>" href="<?= $iTab > 0 ? e($url(['sp_r' => $r, 'sp_v' => 'tablero', 'sp_s' => $codsR[$iTab - 1]])) : '#' ?>" aria-label="Sprint anterior" data-ds-tip="Sprint anterior"><?= rlIco('chevron-izq', 18) ?></a>
    <div class="bl-tabcab__txt"><b><?= e($sTab) ?></b><span><?= e(rlFL($sp['inicio'], false) . ' al ' . rlFL($sp['fin'], false)) ?></span>
      <?= Backlog::momento($sp, $hoy) === 'actual' ? '<span class="bl-badge bl-badge--actual">En curso</span>' : (Backlog::momento($sp, $hoy) === 'pasado' ? '<span class="bl-badge">Terminó</span>' : '<span class="bl-badge">Por venir</span>') ?></div>
    <a class="mc-iconbtn mc-iconbtn--tinte<?= $iTab < count($codsR) - 1 ? '' : ' is-off' ?>" href="<?= $iTab < count($codsR) - 1 ? e($url(['sp_r' => $r, 'sp_v' => 'tablero', 'sp_s' => $codsR[$iTab + 1]])) : '#' ?>" aria-label="Sprint siguiente" data-ds-tip="Sprint siguiente"><?= rlIco('chevron-der', 18) ?></a>
  </div>
  <?= function_exists('blSprintObjetivo') ? blSprintObjetivo($pid, $sTab, $puede) : '' ?>
  <div class="rl-tab bl-tabres">
    <section class="rl-pan rl-pan--8">
      <header class="rl-pan__cab"><h3>Resumen del <?= e($sTab) ?></h3></header>
      <div class="rl-pan__cuerpo">
        <div class="rl-kpis">
          <?= $kpi('Comprometido', (int)$comp . '<small> pts</small>', 'azul', count($enSprint) . ' historia(s)' . ($cap ? ' · capacidad ' . (int)$cap : '')) ?>
          <?= $kpi('Hecho', (int)$hecho . '<small> pts</small>', $pctS === null ? 'nm' : ($pctS >= 80 ? 'ok' : ($pctS >= 40 ? 'warn' : 'crit')), $pctS !== null ? $pctS . ' % del sprint' : 'nada estimado') ?>
          <?= $kpi('Días hábiles', (int)$dS['quedan'] . '<small>/' . (int)$dS['total'] . '</small>', $dS['quedan'] ? 'azul' : 'done', 'los que quedan') ?>
          <?= $kpi('En revisión', (string)count(array_filter($enSprint, fn($h) => $h['estado'] === 'en_revision')), 'warn', 'esperan visto bueno') ?>
        </div>
      </div>
    </section>
    <section class="rl-pan rl-pan--4">
      <header class="rl-pan__cab"><h3>Burndown</h3></header>
      <div class="rl-pan__cuerpo"><?= blBurndown(Backlog::burndown($sTab, $enSprint, $hoy)) ?>
        <p class="bl-ley"><i class="bl-ley__ideal"></i>Ideal <i class="bl-ley__real"></i>Lo que quedaba</p></div>
    </section>
  </div>
  <div class="bl-board" data-bl-board data-sprint="<?= e($sTab) ?>">
    <?php foreach (Backlog::ESTADOS as $k => [$lbl, $tono]): $col = array_values(array_filter($enSprint, fn($h) => $h['estado'] === $k)); ?>
    <section class="bl-col bl-t--<?= $tono ?>" data-bl-col data-estado="<?= $k ?>">
      <header class="bl-col__cab"><b><?= e($lbl) ?></b><span class="bl-col__n mc-num" data-bl-n><?= count($col) ?></span><span class="bl-col__pts mc-num" data-bl-pts><?= array_sum(array_column($col, 'puntos')) ?> pts</span></header>
      <div class="bl-col__cuerpo" data-bl-cards>
        <?php foreach ($col as $h) echo blTarjeta($h, $ctx); ?>
        <p class="bl-col__vacio"<?= $col ? ' hidden' : '' ?>><?= $puede ? 'Arrastra aquí' : '—' ?></p>
      </div>
    </section>
    <?php endforeach; ?>
  </div>
  <?php if (!$enSprint): ?><p class="bl-vacio"><?= rlIco('info', 16) ?> El <?= e($sTab) ?> no tiene historias. Pásalas desde el <a href="<?= e($url(['sp_r' => $r])) ?>">backlog</a>.</p><?php endif; ?>
  <?php endif; ?>
<?php endif; ?>

</div>
    <?php
    return (string)ob_get_clean();
}

/** El enlace a la pantalla de una historia (nueva: con su sprint, si viene). */
function blUrlHistoria(int $pid, int $id = 0, string $sprint = ''): string
{
    return 'historia.php?' . http_build_query(array_filter(['p' => $pid, 'id' => $id ?: null, 'sprint' => $sprint !== '' ? $sprint : null]));
}

/**
 * Un escenario de los criterios (Dado · Cuando · Entonces), para pintarlo en el
 * servidor; assets/backlog.js suma, quita y renumera.
 */
function blEscenario(array $e, int $n): string
{
    $ej = ['dado' => 'que el estudiante tiene la matrícula pagada', 'cuando' => 'elige sus materias', 'entonces' => "ve solo las que puede tomar\ny le llega el comprobante al correo"];
    $campo = fn(string $k, string $lbl, string $en) => '<label class="bl-esc__fila"><span class="bl-esc__k">' . $lbl . '<small>' . $en . '</small></span>'
        . '<textarea class="bl-esc__t" rows="1" maxlength="1500" data-k="' . $k . '" name="escenarios[' . $n . '][' . $k . ']" placeholder="' . e($n === 0 ? $ej[$k] : '') . '">' . e((string)($e[$k] ?? '')) . '</textarea></label>';
    return '<div class="bl-esc" data-bl-esc><div class="bl-esc__cab"><span class="bl-esc__n">Escenario ' . ($n + 1) . '</span>'
        . '<input class="bl-esc__nom" data-k="nombre" name="escenarios[' . $n . '][nombre]" maxlength="160" placeholder="Nombre (opcional)" value="' . e((string)($e['nombre'] ?? '')) . '">'
        . '<button type="button" class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte mc-iconbtn--danger" data-bl-esc-quitar aria-label="Quitar el escenario" data-ds-tip="Quitar el escenario">' . rlIco('cerrar', 15) . '</button></div>'
        . $campo('dado', 'Dado', 'Given') . $campo('cuando', 'Cuando', 'When') . $campo('entonces', 'Entonces', 'Then') . '</div>';
}

/**
 * La historia en su pantalla completa (historia.php): crear, editar o, para quien
 * no la gestiona, solo verla. $x: proyecto, miembros (id => miembro), tareas (las
 * del proyecto), puede, historia (o null si es nueva), sprint (el de una nueva).
 */
function blFormHistoria(array $x): string
{
    $p = $x['proyecto'];
    $pid = (int)$p['id'];
    $puede = $x['puede'];
    $miembros = $x['miembros'];
    $h = $x['historia'];
    $nueva = !$h;
    $conf = Backlog::conf();
    $hoy = date('Y-m-d');
    $sps = Backlog::sprints();
    $act = Backlog::actual($hoy);
    $epicas = Backlog::epicas($pid);
    $rels = Releases::releases();
    $relsP = array_values(array_unique(array_column($epicas, 'release'))) ?: array_map('strval', array_keys($rels));
    $h ??= ['id' => 0, 'codigo' => '', 'titulo' => '', 'como' => '', 'quiero' => '', 'para' => '', 'escenarios' => [], 'notas' => '', 'item_id' => 0,
            'sprint' => (string)($x['sprint'] ?? ''), 'estimacion' => '', 'prioridad' => 'media', 'estado' => 'por_hacer', 'responsable' => 0, 'tareas' => [], 'adjuntos' => [], 'historial' => []];
    $notas = str_contains((string)$h['notas'], '<') ? (string)$h['notas'] : (trim((string)$h['notas']) !== '' ? '<p>' . nl2br(e((string)$h['notas'])) . '</p>' : '');
    // La gente del proyecto (con su PO y su SM)
    $ids = ProyectoRepo::miembrosDe($p);
    $ids = $ids === null ? array_keys($miembros) : array_merge($ids, [ProyectoRepo::poDe($p), (int)($p['po_relevo'] ?? 0), ProyectoRepo::scrumDe($p)]);
    $gente = [];
    foreach (array_unique(array_map('intval', $ids)) as $mid) if (isset($miembros[$mid])) $gente[(string)$mid] = [(string)$miembros[$mid]['nombre'], (string)($miembros[$mid]['rol'] ?? '')];
    uasort($gente, fn($a, $b) => strnatcasecmp($a[0], $b[0]));
    $gente = ['0' => ['Sin responsable', 'La lleva quien la tome']] + $gente;
    $optEpica = ['0' => ['Sin épica', 'No suma a ningún ítem del release']];
    foreach ($epicas as $it) $optEpica[(string)(int)$it['id']] = [$it['codigo'] . ' · ' . (string)$it['titulo'], $it['release'] . ' · equipo ' . $it['equipo']];
    $optSprint = ['' => ['Backlog', 'Sin sprint todavía']];
    foreach ($sps as $cod => $s) {
        if (($s['fin'] < $hoy && $cod !== $h['sprint']) || !array_intersect($s['releases'], $relsP)) continue;
        $optSprint[$cod] = [$cod . ' · ' . rlF($s['inicio']) . ' → ' . rlF($s['fin']), ($cod === $act ? 'En curso · ' : '') . implode(', ', $s['releases'])];
    }
    if ($h['sprint'] !== '' && !isset($optSprint[$h['sprint']])) $optSprint[$h['sprint']] = [$h['sprint'] . ' (ya pasó)', ''];
    $finales = Catalogo::estadosFinales();
    $ts = $x['tareas'];
    usort($ts, fn($a, $b) => [in_array($a['estado'] ?? '', $finales, true), -(int)$a['id']] <=> [in_array($b['estado'] ?? '', $finales, true), -(int)$b['id']]);
    // El número de la tarea (#id) es para los desarrolladores (sus commits lo usan): al PO no se le muestra
    $yoId = (int)(Auth::usuario()['id'] ?? 0);
    $verIds = !esPOde($p, $yoId);
    $tareasOps = [];
    foreach (array_slice($ts, 0, 400) as $t) $tareasOps[(int)$t['id']] = [mb_strimwidth((string)$t['titulo'], 0, 90, '…'), ($verIds ? '#' . (int)$t['id'] . ' · ' : '') . (string)(Catalogo::estadosTarea()[$t['estado'] ?? ''][0] ?? '')];
    $est = Backlog::opcionesEstimacion();
    $urlProy = 'proyecto.php?id=' . $pid . '#vista-sprints';
    // Las tareas de la historia: la IA las propone (van al Scrum Master) y el SM las reparte a su equipo
    $conIA = false;
    if ($puede && !$nueva && is_file(__DIR__ . '/IA.php')) {
        require_once __DIR__ . '/IA.php';
        $conIA = IA::lista() && !empty(IA::config()['asignaciones']['bl_tareas_hu']['activa']);
    }
    $smP = ProyectoRepo::scrumDe($p);
    $smNom = $smP && isset($miembros[$smP]) ? (string)$miembros[$smP]['nombre'] : '';
    $porId = [];
    foreach ($ts as $t) $porId[(int)$t['id']] = $t;
    $abiertasHu = array_values(array_filter(array_map(fn($tid) => $porId[(int)$tid] ?? null, (array)$h['tareas']), fn($t) => $t && !in_array($t['estado'] ?? '', $finales, true)));
    $volver = $nueva ? blUrlHistoria($pid) . '&id=__ID__' : blUrlHistoria($pid, (int)$h['id']);
    ob_start(); ?>
<form class="bl-hu" method="post" action="actions.php" enctype="multipart/form-data" data-bl-form data-puede="<?= $puede ? '1' : '' ?>">
  <input type="hidden" name="accion" value="bl_historia_guardar"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>"><input type="hidden" name="proyecto_id" value="<?= $pid ?>">
  <input type="hidden" name="volver" value="<?= e($volver) ?>"><input type="hidden" name="tareas[]" value="">
  <header class="bl-hu__cab">
    <a class="rs-boton rs-boton--icono" href="<?= e($urlProy) ?>" aria-label="Volver a los sprints" data-ds-tip="Volver a los sprints"><?= UI::icono('ds-chevron-izq') ?></a>
    <div class="bl-hu__tit"><span class="bl-dlg__cod"><?= $nueva ? 'Nueva historia' : e($h['codigo']) ?></span>
      <span class="bl-hu__proy"><?= e((string)$p['nombre']) ?><?= !$nueva ? ' · ' . blEstado((string)$h['estado']) : '' ?></span></div>
    <?php /* Cada acción una sola vez, aquí arriba (la cabecera queda fija al bajar): diagrama, borrar y guardar */ ?>
    <?php if ($puede && !$nueva): ?><button type="button" class="rs-boton rs-boton--icono bl-borrar" data-bl-borrar-hu="<?= (int)$h['id'] ?>" data-volver="<?= e($urlProy) ?>" data-cod="<?= e($h['codigo']) ?>" aria-label="Borrar la historia" data-ds-tip="Borrar la historia"><?= rlIco('eliminar', 18) ?></button><?php endif; ?>
    <?php if ($puede): ?><button type="submit" class="rs-boton rs-boton--primario"><?= UI::icono('ds-check') ?><span><?= $nueva ? 'Crear historia' : 'Guardar' ?></span></button><?php endif; ?>
  </header>
  <fieldset class="bl-fs bl-hu__grid"<?= $puede ? '' : ' disabled' ?>>
    <div class="bl-hu__col">
      <section class="rl-pan">
        <header class="rl-pan__cab"><h3>Historia</h3></header>
        <div class="rl-pan__cuerpo bl-hu__cuerpo">
          <?= rlCampo('Título', rlInput('name="titulo" maxlength="200" required placeholder="Ej.: Matricularse en línea" value="' . e((string)$h['titulo']) . '"')) ?>
          <div class="rl-form-grid rl-form-grid--2">
            <?= rlCampo('Como…', rlInput('name="como" maxlength="160" placeholder="estudiante de grado" value="' . e((string)$h['como']) . '"'), 'Quién la necesita: el rol, no una persona.') ?>
            <?= rlCampo('Para…', rlTexto('para', (string)$h['para'], 2, 'no tener que ir a la secretaría')) ?>
          </div>
          <?= rlCampo('Quiero…', rlTexto('quiero', (string)$h['quiero'], 2, 'elegir mis materias y pagar la matrícula desde el portal')) ?>
        </div>
      </section>
      <section class="rl-pan">
        <header class="rl-pan__cab"><h3>Criterios de aceptación <small class="bl-hu__sub">Dado · Cuando · Entonces (Given · When · Then)</small></h3></header>
        <div class="rl-pan__cuerpo bl-hu__cuerpo">
          <div class="bl-escs" data-bl-escenarios>
            <?php foreach (($h['escenarios'] ?: [[]]) as $n => $e) echo blEscenario((array)$e, (int)$n); ?>
          </div>
          <?php if ($puede): ?><button type="button" class="bl-escs__mas" data-bl-esc-mas><?= rlIco('mas', 16) ?> Agregar escenario</button><?php endif; ?>
          <p class="bl-hu__ayuda">Cuando todos los escenarios se cumplen, la historia está hecha. Para un «y…», sigue en otra línea del mismo campo.</p>
        </div>
      </section>
      <section class="rl-pan">
        <header class="rl-pan__cab"><h3>Detalle <small class="bl-hu__sub">reglas del negocio, ejemplos, tablas, enlaces</small></h3></header>
        <div class="rl-pan__cuerpo bl-hu__cuerpo"><?= UI::editorRico(['name' => 'notas', 'id' => 'bl-notas', 'valor' => $notas, 'placeholder' => 'Escribe o pega aquí: reglas, ejemplos, tablas…']) ?></div>
      </section>
    </div>
    <div class="bl-hu__col">
      <section class="rl-pan">
        <header class="rl-pan__cab"><h3>Planificación</h3></header>
        <div class="rl-pan__cuerpo bl-hu__cuerpo">
          <?= rlCampo('Épica (ítem del release)', UI::selectDS('item_id', $optEpica, (string)(int)$h['item_id'], ['tam' => 'md', 'buscar' => true])) ?>
          <div class="rl-form-grid rl-form-grid--2">
            <?= rlCampo('Sprint', UI::selectDS('sprint', $optSprint, (string)$h['sprint'], ['tam' => 'md'])) ?>
            <?= rlCampo('Estado', UI::selectDS('estado', array_map(fn($v) => $v[0], Backlog::ESTADOS), (string)$h['estado'], ['tam' => 'md'])) ?>
          </div>
          <div class="mc-field"><span class="mc-field__label">Estimación <small>(<?= $conf['escala'] === 'camisa' ? 'talla de camisa' : 'puntos de Fibonacci' ?>)</small></span>
            <div class="bl-est-ops" role="radiogroup" aria-label="Estimación">
              <?php foreach (array_merge([''], $est, ['?']) as $o): ?>
              <label class="bl-est-op"<?= $o === '?' ? ' data-ds-tip="Hay que conversarla antes de estimar"' : '' ?>><input type="radio" name="estimacion" value="<?= e($o) ?>"<?= (string)$h['estimacion'] === $o ? ' checked' : '' ?>><span><?= $o === '' ? '—' : e($o) ?></span></label>
              <?php endforeach; ?>
            </div></div>
          <div class="mc-field"><span class="mc-field__label">Prioridad</span>
            <div class="bl-est-ops bl-prio-ops" role="radiogroup" aria-label="Prioridad">
              <?php foreach (Backlog::PRIORIDADES as $k => $lbl): ?>
              <label class="bl-est-op"><input type="radio" name="prioridad" value="<?= $k ?>"<?= $h['prioridad'] === $k ? ' checked' : '' ?>><span><?= blPrio($k) ?><?= e($lbl) ?></span></label>
              <?php endforeach; ?>
            </div></div>
          <?= rlCampo('Responsable', UI::selectDS('responsable', $gente, (string)(int)$h['responsable'], ['tam' => 'md', 'buscar' => true])) ?>
        </div>
      </section>
      <?= function_exists('blDorPanel') ? blDorPanel($h, $pid, $puede) : '' ?>
      <section class="rl-pan">
        <header class="rl-pan__cab"><h3>Diagramas y documentos</h3></header>
        <div class="rl-pan__cuerpo bl-hu__cuerpo">
          <ul class="bl-adj">
            <?php foreach ((array)$h['adjuntos'] as $a): $img = ($a['tipo'] ?? '') === 'img'; ?>
            <li class="bl-adj__item"><a href="<?= e((string)$a['ruta']) ?>" target="_blank" rel="noopener">
              <?= $img ? '<img src="' . e((string)$a['ruta']) . '" alt="" loading="lazy">' : '<span class="bl-adj__doc">' . e(strtoupper(pathinfo((string)$a['nombre'], PATHINFO_EXTENSION) ?: 'doc')) . '</span>' ?>
              <span><?= e((string)$a['nombre']) ?></span></a>
              <?php if ($puede): ?><label class="bl-adj__quitar"><input type="checkbox" name="quitar_adjuntos[]" value="<?= e((string)$a['ruta']) ?>"> Quitar</label><?php endif; ?></li>
            <?php endforeach; ?>
            <?php if (!$h['adjuntos']): ?><li class="bl-adj__vacio"><?= $puede ? 'Todavía no tiene diagramas ni documentos.' : 'Sin diagramas ni documentos.' ?></li><?php endif; ?>
          </ul>
          <?php if ($puede): ?><?= UI::archivo(['name' => 'adjuntos[]', 'multiple' => true, 'variante' => 'zona', 'accept' => '.png,.jpg,.jpeg,.webp,.gif,.pdf,.docx,.xlsx,.pptx,.doc,.xls,.ppt,.csv,.txt',
            'ayuda' => 'Imágenes (BPMN, flujos), PDF, Word, Excel o PowerPoint · máx 15 MB cada uno', 'maxMB' => 15]) ?><?php endif; ?>
        </div>
      </section>
      <section class="rl-pan">
        <header class="rl-pan__cab"><h3>Tareas que la cumplen<?= $h['tareas'] ? ' <small class="bl-hu__sub">' . count((array)$h['tareas']) . ' · ' . count($abiertasHu) . ' abiertas</small>' : '' ?></h3>
          <?php if ($conIA): ?><button type="button" class="rl-pan__btn" data-bl-ia-tareas aria-label="Generar tareas con IA" data-ds-tip="Generar tareas con IA<?= $smNom !== '' ? ' (van a ' . e(explode(' ', $smNom)[0]) . ', el SM)' : '' ?>"><?= UI::icono('ds-ia') ?></button><?php endif; ?>
          <?php if ($puede && $abiertasHu): ?><button type="button" class="rl-pan__btn" data-bl-repartir aria-label="Repartir las tareas al equipo" data-ds-tip="Repartir al equipo"><?= rlIco('usuarios', 16) ?></button><?php endif; ?>
        </header>
        <div class="rl-pan__cuerpo bl-hu__cuerpo">
          <?php if ($puede && !$nueva): ?><input type="hidden" name="ia_tareas" value="" data-bl-ia-campo><?php endif; ?>
          <?php $deHu = array_values(array_filter(array_map(fn($tid) => $porId[(int)$tid] ?? null, (array)$h['tareas'])));
            // Primero las abiertas (por fecha límite), al final las terminadas; 5 por página (assets/backlog.js)
            usort($deHu, fn($a, $b) => [in_array($a['estado'] ?? '', $finales, true), ($a['fecha_limite'] ?? '') ?: '9999', (int)$a['id']]
                                      <=> [in_array($b['estado'] ?? '', $finales, true), ($b['fecha_limite'] ?? '') ?: '9999', (int)$b['id']]); ?>
          <?php if ($deHu): ?>
          <ul class="bl-ht" data-bl-paginar="5">
            <?php foreach ($deHu as $t):
                $fin = in_array($t['estado'] ?? '', $finales, true);
                $lim = (string)($t['fecha_limite'] ?? '');
                $k = $fin ? 'ok' : ($lim !== '' && $lim < $hoy ? 'crit' : (preg_match('/rev/i', (string)$t['estado']) ? 'warn' : (preg_match('/prog|curso/i', (string)$t['estado']) ? 'done' : 'none')));
                $estN = (string)(Catalogo::estadosTarea()[$t['estado'] ?? ''][0] ?? ''); ?>
            <li><a class="bl-ht__a" href="proyecto.php?id=<?= $pid ?>&amp;tarea=<?= (int)$t['id'] ?>" data-ds-tip="Ver el detalle de la tarea">
              <span class="bl-ht__est col-s-<?= $k ?>"><?= e($k === 'crit' ? 'Vencida' : $estN) ?></span>
              <span class="bl-ht__txt"><b><?= e((string)$t['titulo']) ?></b>
                <small><?= $verIds ? '#' . (int)$t['id'] . ' · ' : '' ?><?= $lim !== '' ? 'para el ' . e(rlF($lim)) : 'sin fecha' ?><?= ($t['prioridad'] ?? '') !== '' ? ' · prioridad ' . e(mb_strtolower((string)(((array)(Catalogo::prioridades()[$t['prioridad']] ?? [$t['prioridad']]))[0] ?? $t['prioridad']))) : '' ?></small></span>
              <span class="bl-ht__quien"><?= TareaRepo::asignadosDe($t) ? UI::avatarsAsignados($t, $miembros, 26, 3) : '<small>Sin asignar</small>' ?></span>
              <span class="bl-ht__ir" aria-hidden="true"><?= rlIco('chevron-der', 16) ?></span></a></li>
            <?php endforeach; ?>
          </ul>
          <?php elseif (!$puede): ?>
          <p class="bl-vacio bl-hu__sintareas"><?= rlIco('info', 16) ?> Esta historia todavía no tiene tareas.</p>
          <?php endif; ?>
          <?php if ($puede && $tareasOps): ?>
          <details class="bl-ht__vinc"<?= $deHu ? '' : ' open' ?>><summary><?= rlIco('relacionar', 16) ?> Vincular o quitar tareas del proyecto</summary>
            <?= rlMulti('tareas[]', $tareasOps, (array)$h['tareas'], 'Buscar tarea…', false) ?></details>
          <?php elseif ($puede): ?>
          <p class="bl-vacio bl-hu__sintareas"><?= rlIco('info', 16) ?> El proyecto todavía no tiene tareas.<?= $conIA ? ' Genéralas con la IA desde la historia (botón de arriba) o escríbelas aquí abajo.' : ($puede ? ' Escríbelas aquí abajo, una por línea.' : '') ?></p>
          <?php endif; ?>
          <?php if ($puede): ?><?= rlCampo('Crear tareas nuevas', rlTexto('tareas_nuevas', '', 3, "Una por línea. Ej.:\nPantalla de selección de materias\nIntegrar el pago con el banco"),
            'Se crean en este proyecto para el responsable de la historia, con las fechas de su sprint.') ?><?php endif; ?>
        </div>
      </section>
      <?php if (!$nueva && $h['historial']): ?>
      <section class="rl-pan">
        <header class="rl-pan__cab"><h3>Historial</h3></header>
        <div class="rl-pan__cuerpo bl-hu__cuerpo"><ol class="bl-hu__hist">
          <?php foreach (array_reverse((array)$h['historial']) as $ev): ?>
          <li><b><?= e((string)$ev['texto']) ?></b><small><?= e(trim((string)$ev['fecha'] . ' · ' . (string)($miembros[(int)($ev['quien'] ?? 0)]['nombre'] ?? ''), ' ·')) ?></small></li>
          <?php endforeach; ?>
        </ol></div>
      </section>
      <?php endif; ?>
    </div>
  </fieldset>
  <?php if (!$nueva):
      $dg = (array)($h['diagrama'] ?? []);
      $conImg = ($dg['ruta'] ?? '') !== '' && is_file(__DIR__ . '/../' . $dg['ruta']);
      $conTexto = trim((string)($dg['texto'] ?? '')) !== '';
      // El diagrama vivo, solo para ver (▶ y escenarios; nadie lo mueve): lo arma lib/PizarraHU.php
      $vistaDg = class_exists('PizarraHU') && method_exists('PizarraHU', 'vista') ? PizarraHU::vista($h) : '';
      $conDg = $conTexto || $conImg; ?>
  <?php /* El diagrama de la historia: se ajusta en la pizarra y aquí se ve vivo, solo para ver */ ?>
  <section class="rl-pan bl-dg">
    <header class="rl-pan__cab"><h3><?= rlIco('ruta', 18) ?>Diagrama de la historia<?= $conDg ? ' <small class="bl-hu__sub">actualizado el ' . e(rlF(substr((string)$dg['fecha'], 0, 10))) . (isset($miembros[(int)($dg['por'] ?? 0)]) ? ' por ' . e(explode(' ', (string)$miembros[(int)$dg['por']]['nombre'])[0]) : '') . '</small>' : '' ?></h3>
      <?php if ($puede): ?><a class="rl-pan__btn" href="pizarra.php?mia=1&amp;hu=<?= (int)$h['id'] ?>" aria-label="Editar el diagrama en la pizarra" data-ds-tip="Editar en la pizarra"><?= rlIco('editar', 16) ?></a><?php endif; ?>
      <?php if ($conImg): ?><a class="rl-pan__btn" href="<?= e((string)$dg['ruta']) ?>" target="_blank" rel="noopener" aria-label="Ver la imagen del diagrama" data-ds-tip="Ver como imagen"><?= rlIco('ver', 16) ?></a><?php endif; ?>
    </header>
    <div class="rl-pan__cuerpo bl-dg__cuerpo">
      <?php if ($vistaDg !== ''): ?>
      <?= $vistaDg ?>
      <?php if (!$conTexto): ?><p class="bl-hu__ayuda"><?= rlIco('info', 14) ?> Se armó solo desde la historia<?= $puede ? ': ajústalo en la pizarra y pulsa «Guardar en la historia» para dejarlo así.' : '; el PO todavía no lo ajustó.' ?></p><?php endif; ?>
      <?php elseif ($conImg): ?>
      <a class="bl-dg__img" href="<?= e((string)$dg['ruta']) ?>" target="_blank" rel="noopener"><img src="<?= e((string)$dg['ruta']) ?>?v=<?= e(preg_replace('/\D/', '', (string)$dg['fecha'])) ?>" alt="Diagrama de <?= e($h['codigo']) ?>: quién entra al sistema, qué hace y qué obtiene" loading="lazy"></a>
      <?php elseif ($puede): ?>
      <div class="bl-dg__vacio"><?= rlIco('ruta', 28) ?><p><b>Todavía no tiene diagrama.</b> Ábrelo en la pizarra: se arma solo desde la historia. Ajústalo y pulsa «Guardar en la historia»: aquí lo ve el equipo vivo (con ▶), solo para mirar.</p>
        <a class="mc-btn mc-btn--primary mc-btn--md" href="pizarra.php?mia=1&amp;hu=<?= (int)$h['id'] ?>"><?= rlIco('ruta', 18) ?><span class="mc-btn__label">Generar diagrama</span></a></div>
      <?php else: ?>
      <div class="bl-dg__vacio"><?= rlIco('ruta', 28) ?><p>El PO todavía no dibujó el diagrama de esta historia.</p></div>
      <?php endif; ?>
    </div>
  </section>
  <?php endif; ?>
</form>
<?php if ($conIA): ?>
<dialog class="ds-modal bl-iat" data-bl-ia-dlg aria-labelledby="bl-iat-t">
  <div class="ds">
    <header class="ds-modal__head"><span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::icono('ds-ia') ?></span>
      <div><h2 class="ds-modal__title" id="bl-iat-t">Tareas de <?= e($h['codigo']) ?></h2>
        <p class="ds-modal__sub">La IA las arma desde el Como, el Quiero, los escenarios y el detalle. <?= $smNom !== '' ? 'Van a <b>' . e($smNom) . '</b>, el Scrum Master, para que las reparta.' : 'El proyecto no tiene Scrum Master: van a quien lleva la historia.' ?></p></div></header>
    <div class="ds-modal__body">
      <div class="bl-iat__cargando" data-bl-iat-cargando><span class="bl-iat__spin" aria-hidden="true"></span><b>La IA está leyendo la historia…</b><span class="bl-iat__esq" aria-hidden="true"><i></i><i></i><i></i></span></div>
      <p class="bl-iat__error" data-bl-iat-error hidden></p>
      <ol class="bl-iat__lista" data-bl-iat-lista hidden></ol>
    </div>
    <footer class="ds-modal__foot"><button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-dlg-cerrar><span class="mc-btn__label">Cancelar</span></button>
      <button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-bl-iat-crear disabled><?= rlIco('check', 18) ?><span class="mc-btn__label" data-bl-iat-n>Crear tareas</span></button></footer>
  </div>
</dialog>
<?php endif; ?>
<?php if ($puede && $abiertasHu): $genteRep = array_filter($gente, fn($k) => (int)$k > 0, ARRAY_FILTER_USE_KEY); ?>
<dialog class="ds-modal bl-rep" data-bl-rep-dlg aria-labelledby="bl-rep-t">
  <form method="post" action="actions.php" class="ds">
    <input type="hidden" name="accion" value="bl_tareas_repartir"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>">
    <header class="ds-modal__head"><span class="mc-tile mc-tile--sm mc-tile--azul"><?= rlIco('usuarios', 18) ?></span>
      <div><h2 class="ds-modal__title" id="bl-rep-t">Repartir al equipo</h2><p class="ds-modal__sub">Elige quién hace cada tarea de <?= e($h['codigo']) ?>. A cada uno le llega el aviso.</p></div></header>
    <div class="ds-modal__body bl-rep__lista">
      <?php foreach ($abiertasHu as $t): $ya = TareaRepo::asignadosDe($t)[0] ?? 0; ?>
      <div class="bl-rep__fila">
        <div class="bl-rep__tarea"><b><?= $verIds ? '#' . (int)$t['id'] . ' ' : '' ?><?= e(mb_strimwidth((string)$t['titulo'], 0, 90, '…')) ?></b>
          <small><?= e((string)(Catalogo::estadosTarea()[$t['estado'] ?? ''][0] ?? '')) ?><?= !empty($t['fecha_limite']) ? ' · para el ' . e(rlF((string)$t['fecha_limite'])) : '' ?><?= $ya && isset($miembros[$ya]) ? ' · ahora: ' . e(explode(' ', (string)$miembros[$ya]['nombre'])[0]) : ' · sin asignar' ?></small></div>
        <div class="bl-rep__gente" role="radiogroup" aria-label="Quién hace «<?= e(mb_strimwidth((string)$t['titulo'], 0, 60, '…')) ?>»">
          <?php foreach ($genteRep as $mid => [$nomG]): ?><label class="bl-rep__op" data-ds-tip="<?= e($nomG) ?>"><input type="radio" name="rep[<?= (int)$t['id'] ?>]" value="<?= (int)$mid ?>"<?= (int)$mid === (int)$ya ? ' checked' : '' ?>><span><?= UI::avatar($miembros[(int)$mid] ?? null, 32) ?></span><span class="mc-sr-only"><?= e($nomG) ?></span></label><?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <footer class="ds-modal__foot"><button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-dlg-cerrar><span class="mc-btn__label">Cancelar</span></button>
      <button type="submit" class="mc-btn mc-btn--primary mc-btn--md"><?= rlIco('check', 18) ?><span class="mc-btn__label">Repartir</span></button></footer>
  </form>
</dialog>
<?php endif; ?>
    <?php
    return (string)ob_get_clean();
}

/**
 * Releases → Ajustes → Sprints (editores): cuándo empieza el S01, cuánto dura
 * cada sprint (una o dos semanas), la escala de estimación, la capacidad y el
 * calendario que sale de eso.
 */
function blAjustesSprints(): string
{
    $c = Backlog::conf();
    $sps = Backlog::sprints();
    $hoy = date('Y-m-d');
    $act = Backlog::actual($hoy);
    ob_start(); ?>
  <p class="ds-ayuda">Los sprints son uno solo para todos los proyectos: se calculan desde el inicio del S01 con la duración que elijas (dos semanas como máximo) y cada uno cae en el release de sus fechas. Si cambias algo, el calendario se rehace solo. Los PO, Scrum Masters y el administrador arman el backlog en la pestaña «Sprints» de cada proyecto.</p>
  <div class="rl-ajustes-grid">
    <form class="mc-card rl-card" method="post" action="actions.php" data-rl-form="bl_sprints_conf" data-rl-tras="recargar">
      <input type="hidden" name="accion" value="bl_sprints_conf"><input type="hidden" name="volver" value="releases.php?v=ajustes&amp;t=sprints">
      <header class="rl-card__cab"><span class="mc-tile mc-tile--sm mc-tile--azul"><?= rlIco('reloj', 20) ?></span><div><h2 class="rl-card__tit">Calendario de sprints</h2><p class="rl-card__sub">Para todos los proyectos.</p></div></header>
      <div class="rl-form-grid rl-form-grid--2">
        <?= rlCampo('Empieza el S01', '<input class="input-meca" type="date" name="inicio" value="' . e($c['inicio']) . '" required>') ?>
        <div class="mc-field"><span class="mc-field__label">Cada sprint dura</span>
          <div class="rl-seg" role="radiogroup" aria-label="Duración">
            <label class="rl-seg__op rl-t--azul"><input type="radio" name="semanas" value="1"<?= $c['semanas'] === 1 ? ' checked' : '' ?>><span>1 semana</span></label>
            <label class="rl-seg__op rl-t--azul"><input type="radio" name="semanas" value="2"<?= $c['semanas'] === 2 ? ' checked' : '' ?>><span>2 semanas</span></label>
          </div></div>
        <div class="mc-field"><span class="mc-field__label">Se estima con</span>
          <div class="rl-seg" role="radiogroup" aria-label="Escala">
            <label class="rl-seg__op rl-t--azul"><input type="radio" name="escala" value="fibonacci"<?= $c['escala'] === 'fibonacci' ? ' checked' : '' ?>><span>Fibonacci (1, 2, 3, 5, 8…)</span></label>
            <label class="rl-seg__op rl-t--azul"><input type="radio" name="escala" value="camisa"<?= $c['escala'] === 'camisa' ? ' checked' : '' ?>><span>Talla de camisa (XS…XXL)</span></label>
          </div></div>
        <?= rlCampo('Capacidad por sprint', '<div class="mc-input"><input class="mc-input__control" type="number" name="capacidad" min="0" max="500" value="' . (int)$c['capacidad'] . '"><span class="mc-input__affix">pts</span></div>', 'En 0, se compara con la velocidad de cada proyecto.') ?>
      </div>
      <?= rlSwitch('arrastrar', $c['arrastrar'], 'Pasar lo no terminado al sprint siguiente', 'Como al cerrar un sprint en Jira: queda dicho en cada historia.') ?>
      <footer class="rl-card__pie"><button class="mc-btn mc-btn--primary" type="submit"><?= rlIco('check', 18) ?><span class="mc-btn__label">Guardar</span></button></footer>
    </form>
    <section class="mc-card rl-card">
      <header class="rl-card__cab"><span class="mc-tile mc-tile--sm"><?= rlIco('agenda', 20) ?></span><div><h2 class="rl-card__tit">Así quedan</h2><p class="rl-card__sub"><?= count($sps) ?> sprints · los días hábiles ya descuentan los feriados.</p></div></header>
      <div class="bl-cal-caja"><table class="bl-cal">
        <thead><tr><th scope="col">Sprint</th><th scope="col">Del</th><th scope="col">Al</th><th scope="col">Días hábiles</th><th scope="col">Release</th></tr></thead>
        <tbody>
        <?php foreach ($sps as $cod => $s): $m = Backlog::momento($s, $hoy); ?>
          <tr class="is-<?= $m ?>"><th scope="row"><?= e($cod) ?><?= $cod === $act ? ' <span class="bl-badge bl-badge--actual">En curso</span>' : '' ?></th>
            <td class="mc-num"><?= e(rlF($s['inicio'], true)) ?></td><td class="mc-num"><?= e(rlF($s['fin'], true)) ?></td>
            <td class="mc-num"><?= (int)Backlog::dias($s, '0000-00-00')['total'] ?></td><td><?= e(implode(' · ', $s['releases']) ?: '—') ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </section>
  </div>
    <?php
    return (string)ob_get_clean();
}
