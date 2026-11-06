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
 *  - Cada historia se abre en un asistente (crear, editar o, para quien no la
 *    gestiona, solo verla): la historia, sus criterios, su planificación
 *    (épica, sprint, estimación…) y sus diagramas y tareas.
 *
 * El modelo es lib/Backlog.php; las acciones (bl_*) están en actions.php y el
 * JavaScript en assets/backlog.js. Usa las ayudas de lib/releases_ui.php.
 */

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
    return '<li class="bl-fila" data-bl-id="' . (int)$h['id'] . '" data-pts="' . (int)$h['puntos'] . '" data-hecho="' . ($h['estado'] === 'hecho' ? 1 : 0) . '"' . ($x['puede'] ? ' draggable="true"' : '') . '>'
        . ($x['puede'] ? '<span class="bl-fila__asa" aria-hidden="true">' . rlIco('arrastrar', 16) . '</span>' : '')
        . '<button type="button" class="bl-fila__abrir" data-bl-abrir="' . (int)$h['id'] . '"><span class="bl-cod">' . e($h['codigo']) . '</span>'
        . '<span class="bl-fila__tit">' . e($h['titulo']) . '</span></button>'
        . '<span class="bl-fila__meta">' . blEpica($h, $x['rels'])
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
        . '<button type="button" class="bl-card__abrir" data-bl-abrir="' . (int)$h['id'] . '"><span class="bl-card__tit">' . e($h['titulo']) . '</span></button>'
        . '<div class="bl-card__meta">' . blEpica($h, $x['rels']) . ($ts ? '<span class="bl-mini" data-ds-tip="Tareas terminadas">' . rlIco('completar', 14) . $hechas . '/' . count($ts) . '</span>' : '') . '</div>'
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
    return '<svg class="bl-bd" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Burndown: de ' . (int)$bd['total'] . ' puntos, quedan ' . $restan . '">'
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
      <?php if ($puede): ?><button type="button" class="rs-boton rs-boton--icono rs-boton--primario" data-bl-nueva data-sprint="" aria-label="Nueva historia" data-ds-tip="Nueva historia"><?= UI::icono('ds-mas') ?></button><?php endif; ?>
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
        <?php if ($puede && $mom !== 'pasado'): ?><button type="button" class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte" data-bl-nueva data-sprint="<?= e($cod) ?>" aria-label="Nueva historia en el <?= e($cod) ?>" data-ds-tip="Nueva historia aquí"><?= rlIco('mas', 16) ?></button><?php endif; ?>
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
        <?php if ($puede): ?><button type="button" class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte" data-bl-nueva data-sprint="" aria-label="Nueva historia en el backlog" data-ds-tip="Nueva historia aquí"><?= rlIco('mas', 16) ?></button><?php endif; ?>
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

  <?= blDialogo($x, $todas, $epicas, $sps, $relsP, $act) ?>
</div>
    <?php
    return (string)ob_get_clean();
}

/** El asistente de la historia (crear, editar o solo verla) y sus datos para assets/backlog.js. */
function blDialogo(array $x, array $todas, array $epicas, array $sps, array $relsP, string $act): string
{
    $p = $x['proyecto'];
    $pid = (int)$p['id'];
    $puede = $x['puede'];
    $miembros = $x['miembros'];
    $conf = Backlog::conf();
    $hoy = date('Y-m-d');
    // La gente del proyecto (con su PO y su SM)
    $ids = ProyectoRepo::miembrosDe($p);
    $ids = $ids === null ? array_keys($miembros) : array_merge($ids, [ProyectoRepo::poDe($p), (int)($p['po_relevo'] ?? 0), ProyectoRepo::scrumDe($p)]);
    $gente = ['0' => ['Sin responsable', 'La lleva quien la tome']];
    foreach (array_unique(array_map('intval', $ids)) as $mid) if (isset($miembros[$mid])) $gente[(string)$mid] = [(string)$miembros[$mid]['nombre'], (string)($miembros[$mid]['rol'] ?? '')];
    uasort($gente, fn($a, $b) => $a[0] === 'Sin responsable' ? -1 : ($b[0] === 'Sin responsable' ? 1 : strnatcasecmp($a[0], $b[0])));
    $optEpica = ['0' => ['Sin épica', 'No suma a ningún ítem del release']];
    foreach ($epicas as $it) $optEpica[(string)(int)$it['id']] = [$it['codigo'] . ' · ' . mb_strimwidth((string)$it['titulo'], 0, 60, '…'), $it['release'] . ' · equipo ' . $it['equipo']];
    // Sprints: el backlog y los de los releases del proyecto que no pasaron (y el de la historia, si es viejo)
    $optSprint = ['' => ['Backlog', 'Sin sprint todavía']];
    foreach ($sps as $cod => $s) {
        if ($s['fin'] < $hoy || !array_intersect($s['releases'], $relsP)) continue;
        $optSprint[$cod] = [$cod . ' · ' . rlF($s['inicio']) . ' → ' . rlF($s['fin']), ($cod === $act ? 'En curso · ' : '') . implode(', ', $s['releases'])];
    }
    $optEstado = array_map(fn($v) => $v[0], Backlog::ESTADOS);
    $tareasOps = [];
    $finales = Catalogo::estadosFinales();
    $ts = $x['tareas'];
    usort($ts, fn($a, $b) => [in_array($a['estado'] ?? '', $finales, true), -(int)$a['id']] <=> [in_array($b['estado'] ?? '', $finales, true), -(int)$b['id']]);
    foreach (array_slice($ts, 0, 400) as $t) $tareasOps[(int)$t['id']] = ['#' . (int)$t['id'] . ' ' . mb_strimwidth((string)$t['titulo'], 0, 80, '…'), (string)(Catalogo::estadosTarea()[$t['estado'] ?? ''][0] ?? '')];
    // Lo de cada historia, para llenar el asistente al abrirla
    $datos = [];
    foreach ($todas as $h) {
        $datos[(int)$h['id']] = ['id' => (int)$h['id'], 'codigo' => $h['codigo'], 'titulo' => $h['titulo'], 'como' => $h['como'], 'quiero' => $h['quiero'], 'para' => $h['para'],
            'escenarios' => array_values((array)$h['escenarios']),
            // El detalle con formato; el de antes (texto suelto) se pasa a párrafos
            'notas' => str_contains((string)$h['notas'], '<') ? (string)$h['notas'] : (trim((string)$h['notas']) !== '' ? '<p>' . nl2br(e((string)$h['notas'])) . '</p>' : ''),
            'item_id' => (int)$h['item_id'], 'sprint' => $h['sprint'], 'estimacion' => $h['estimacion'],
            'prioridad' => $h['prioridad'], 'estado' => $h['estado'], 'responsable' => (int)$h['responsable'], 'tareas' => $h['tareas'],
            'adjuntos' => array_map(fn($a) => ['ruta' => (string)$a['ruta'], 'nombre' => (string)$a['nombre'], 'tipo' => (string)($a['tipo'] ?? 'doc')], (array)$h['adjuntos']),
            'historial' => array_map(fn($ev) => ['fecha' => (string)$ev['fecha'], 'quien' => (string)($miembros[(int)($ev['quien'] ?? 0)]['nombre'] ?? ''), 'texto' => (string)$ev['texto']], array_reverse((array)$h['historial']))];
    }
    $pasos = [
        ['Historia', 'Qué se quiere', 'El título y la historia: como quién, qué quiere y para qué.', 'ds-requerimiento'],
        ['Criterios', 'Cuándo está lista', 'Los escenarios en Dado · Cuando · Entonces y el detalle con formato.', 'ds-check-doble'],
        ['Planificación', 'Cuándo y cuánto', 'Su épica, el sprint, la estimación, la prioridad y quién la lleva.', 'ds-reloj'],
        ['Diagramas y tareas', 'Con qué se hace', 'Sus diagramas de proceso y documentos, y las tareas del proyecto que la cumplen.', 'ds-documento'],
    ];
    $est = Backlog::opcionesEstimacion();
    ob_start(); ?>
<dialog id="bl-dlg" class="dlg-meca dlg-wizard bl-dlg">
  <form id="bl-form" class="dlg-form wz" method="post" action="actions.php" enctype="multipart/form-data" data-puede="<?= $puede ? '1' : '' ?>">
    <input type="hidden" name="accion" value="bl_historia_guardar"><input type="hidden" name="id" value="0"><input type="hidden" name="proyecto_id" value="<?= $pid ?>">
    <input type="hidden" name="volver" value="<?= e(($x['url'])([])) ?>"><input type="hidden" name="tareas[]" value="">
    <?= UI::wizardRiel('ds-requerimiento', 'Historia de usuario', 'Del backlog de ' . (string)$p['nombre'], $pasos) ?>
    <div class="wz-cuerpo">
      <header><div><p class="bl-dlg__kick"><span class="bl-dlg__cod" data-bl-ceja></span></p><h4 class="wz-titulo-paso"></h4><p class="wz-ayuda-paso"></p></div>
        <button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><?= rlIco('cerrar', 18) ?></button></header>
      <section class="wz-panel"><fieldset class="bl-fs">
        <?= rlCampo('Título', rlInput('name="titulo" maxlength="200" required placeholder="Ej.: Matricularse en línea"')) ?>
        <?= rlCampo('Como…', rlInput('name="como" maxlength="160" placeholder="estudiante de grado"'), 'Quién la necesita: el rol, no una persona.') ?>
        <?= rlCampo('Quiero…', rlTexto('quiero', '', 2, 'elegir mis materias y pagar la matrícula desde el portal')) ?>
        <?= rlCampo('Para…', rlTexto('para', '', 2, 'no tener que ir a la secretaría')) ?>
      </fieldset></section>
      <section class="wz-panel"><fieldset class="bl-fs">
        <div class="mc-field"><span class="mc-field__label">Criterios de aceptación <small>Dado · Cuando · Entonces (Given · When · Then)</small></span>
          <div class="bl-escs" data-bl-escenarios></div>
          <button type="button" class="bl-escs__mas" data-bl-esc-mas><?= rlIco('mas', 16) ?> Agregar escenario</button>
          <div class="mc-field__foot"><span class="mc-field__hint">Cuando todos los escenarios se cumplen, la historia está hecha. Para un «y…», sigue en otra línea del mismo campo.</span></div></div>
        <div class="mc-field"><span class="mc-field__label">Detalle <small>(reglas del negocio, ejemplos, tablas, enlaces)</small></span>
          <?= UI::editorRico(['name' => 'notas', 'id' => 'bl-notas', 'placeholder' => 'Escribe o pega aquí: reglas, ejemplos, tablas…']) ?></div>
      </fieldset></section>
      <section class="wz-panel"><fieldset class="bl-fs">
        <?php /* La épica en su fila: su nombre se lee entero */ ?>
        <?= rlCampo('Épica (ítem del release)', UI::selectDS('item_id', $optEpica, '0', ['tam' => 'md', 'buscar' => true, 'attrs' => 'data-bl-campo="item_id"'])) ?>
        <div class="mc-field"><span class="mc-field__label">Estimación <small>(<?= $conf['escala'] === 'camisa' ? 'talla de camisa' : 'puntos de Fibonacci' ?>)</small></span>
          <div class="bl-est-ops" role="radiogroup" aria-label="Estimación">
            <label class="bl-est-op"><input type="radio" name="estimacion" value="" checked><span>—</span></label>
            <?php foreach ($est as $o): ?><label class="bl-est-op"><input type="radio" name="estimacion" value="<?= e($o) ?>"><span><?= e($o) ?></span></label><?php endforeach; ?>
            <label class="bl-est-op" data-ds-tip="Hay que conversarla antes de estimar"><input type="radio" name="estimacion" value="?"><span>?</span></label>
          </div></div>
        <div class="mc-field"><span class="mc-field__label">Prioridad</span>
          <div class="bl-est-ops bl-prio-ops" role="radiogroup" aria-label="Prioridad">
            <?php foreach (Backlog::PRIORIDADES as $k => $lbl): ?>
            <label class="bl-est-op"><input type="radio" name="prioridad" value="<?= $k ?>"<?= $k === 'media' ? ' checked' : '' ?>><span><?= blPrio($k) ?><?= e($lbl) ?></span></label>
            <?php endforeach; ?>
          </div></div>
        <div class="rl-form-grid rl-form-grid--2">
          <?= rlCampo('Sprint', UI::selectDS('sprint', $optSprint, '', ['tam' => 'md', 'attrs' => 'data-bl-campo="sprint"'])) ?>
          <?= rlCampo('Estado', UI::selectDS('estado', $optEstado, 'por_hacer', ['tam' => 'md', 'attrs' => 'data-bl-campo="estado"'])) ?>
        </div>
        <?= rlCampo('Responsable', UI::selectDS('responsable', $gente, '0', ['tam' => 'md', 'buscar' => true, 'attrs' => 'data-bl-campo="responsable"'])) ?>
      </fieldset></section>
      <section class="wz-panel"><fieldset class="bl-fs">
        <div class="mc-field"><span class="mc-field__label">Diagramas de proceso y documentos</span>
          <ul class="bl-adj" data-bl-adj></ul>
          <div class="bl-solo-edita"><?= UI::archivo(['name' => 'adjuntos[]', 'multiple' => true, 'variante' => 'zona', 'accept' => '.png,.jpg,.jpeg,.webp,.gif,.pdf,.docx,.xlsx,.pptx,.doc,.xls,.ppt,.csv,.txt',
            'ayuda' => 'Imágenes (BPMN, flujos), PDF, Word, Excel o PowerPoint · máx 15 MB cada uno', 'maxMB' => 15]) ?></div></div>
        <?= rlCampo('Tareas del proyecto que la cumplen', rlMulti('tareas[]', $tareasOps, [], 'Buscar tarea…')) ?>
        <div class="bl-solo-edita"><?= rlCampo('Crear tareas nuevas', rlTexto('tareas_nuevas', '', 3, "Una por línea. Ej.:\nPantalla de selección de materias\nIntegrar el pago con el banco"),
            'Se crean en este proyecto para el responsable de la historia, con las fechas de su sprint, y quedan en esta lista.') ?></div>
        <details class="bl-hist" data-bl-hist-caja hidden><summary><?= rlIco('reloj', 16) ?> Lo que pasó con ella</summary><ol data-bl-hist></ol></details>
      </fieldset>
        <?php if ($puede): ?><button type="button" class="bl-borrar" data-bl-borrar hidden><?= rlIco('eliminar', 16) ?> Borrar esta historia</button><?php endif; ?>
      </section>
      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><?= rlIco('chevron-izq', 16) ?> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <?= rlIco('chevron-der', 16) ?></button>
          <?php if ($puede): ?><button type="submit" class="btn-primary btn-meca wz-guardar"><?= rlIco('check', 16) ?> Guardar historia</button>
          <?php else: ?><button type="button" class="btn-outline btn-meca btn-neutro wz-guardar" data-cerrar-dlg>Cerrar</button><?php endif; ?>
        </div>
      </div>
    </div>
  </form>
  <script type="application/json" id="bl-datos"><?= json_encode(['historias' => $datos], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</dialog>
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
