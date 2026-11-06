<?php
/**
 * El sprint de un proyecto en su pantalla completa (sprint.php) y lo que de eso
 * se ve en la pestaña «Sprints» (lib/backlog_ui.php) y en cada historia:
 *
 *  - Planificación: el objetivo del sprint (Sprint Goal), la capacidad de cada
 *    persona (días que falta, dedicación —hay gente a medio tiempo—) frente a lo
 *    que tiene asignado, las historias del sprint con su «Lista» (Definition of
 *    Ready), las del backlog para meter y lo que quedó abierto de las retros.
 *  - Retrospectiva: si se cumplió el objetivo, qué salió bien, qué salió mal y
 *    las acciones (con responsable; cada una se puede volver tarea del proyecto).
 *  - Lista para sprint: la Definición de «Lista» (DoR) del proyecto.
 *
 * El modelo es lib/Backlog.php (colección bl_sprints); las acciones, bl_sprint_plan,
 * bl_sprint_retro, bl_sprint_accion y bl_dor_conf de actions.php; el JavaScript,
 * assets/sprint.js (y lo de la DoR en la historia y al arrastrar, al final de
 * assets/backlog.js). El estilo, el bloque «Scrum: objetivo, retro, DoR y
 * capacidad» al final de assets/ds.css.
 */
require_once __DIR__ . '/releases_ui.php';

/** El enlace a la pantalla del sprint del proyecto (v: plan, retro o dor). */
function blUrlSprint(int $pid, string $sprint, string $v = ''): string
{
    return 'sprint.php?' . http_build_query(array_filter(['p' => $pid, 's' => $sprint, 'v' => $v !== '' && $v !== 'plan' ? $v : null]));
}

/** El ícono de un estado de la colorimetría (ok, warn, crit…). */
function blsIco(string $k, int $px = 14): string
{
    return class_exists('Colorimetria') ? Colorimetria::icono($k, $px) : '';
}

/** «Lista» (cumple la DoR) o cuántas condiciones cumple, con lo que falta en la ayuda. */
function blsDorEstado(array $d): string
{
    if ($d['lista']) return '<span class="bls-dor col-s-ok" data-ds-tip="Cumple la Definición de «Lista»">' . blsIco('ok') . '<span>Lista</span></span>';
    return '<span class="bls-dor col-s-warn" data-ds-tip="' . e('No está lista. Falta: ' . implode(' · ', $d['falta'])) . '">' . blsIco('warn') . '<span class="mc-num">' . (int)$d['hechos'] . '/' . (int)$d['total'] . '</span></span>';
}

/**
 * El distintivo de una fila del backlog o de una tarjeta del tablero: «Lista» si
 * cumple la DoR; si va en un sprint sin estar lista, el aviso. Lo hecho, nada.
 */
function blDorChip(array $h): string
{
    if (($h['estado'] ?? '') === 'hecho') return '';
    $d = Backlog::dor($h);
    if ($d['lista']) return '<span class="bls-dor col-s-ok" data-ds-tip="Lista para sprint: cumple la Definición de «Lista»">' . blsIco('ok') . '<span>Lista</span></span>';
    if ((string)($h['sprint'] ?? '') === '') return '';
    return '<span class="bls-dor col-s-warn" data-ds-tip="' . e('Va en el sprint sin estar lista. Falta: ' . implode(' · ', $d['falta'])) . '">' . blsIco('warn') . '<span>No lista</span></span>';
}

/** data-dor (1 o 0) y lo que le falta: assets/backlog.js avisa al arrastrarla a un sprint. */
function blDorAttr(array $h): string
{
    $d = Backlog::dor($h);
    return ' data-dor="' . ($d['lista'] ? 1 : 0) . '" data-cod="' . e((string)($h['codigo'] ?? '')) . '"'
        . ($d['lista'] ? '' : ' data-dor-falta="' . e(implode(' · ', $d['falta'])) . '"');
}

/** En la cabecera de cada sprint del backlog: el acceso a su pantalla y, si lo tiene, su objetivo. */
function blSprintCab(int $pid, string $sprint): string
{
    $obj = trim((string)Backlog::ficha($pid, $sprint)['objetivo']);
    return '<a class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte" href="' . e(blUrlSprint($pid, $sprint)) . '" aria-label="Planificación y retrospectiva del ' . e($sprint) . '" data-ds-tip="Planificación, capacidad y retrospectiva del ' . e($sprint) . '">' . rlIco('reto', 16) . '</a>'
        . ($obj !== '' ? '<span class="bls-obj" data-ds-tip="' . e('Objetivo del ' . $sprint . ': ' . $obj) . '">' . rlIco('bandera', 14) . '<b>Objetivo</b><span>' . e($obj) . '</span></span>' : '');
}

/** En el tablero del sprint: su objetivo y el acceso a su pantalla. */
function blSprintObjetivo(int $pid, string $sprint, bool $puede = false): string
{
    $obj = trim((string)Backlog::ficha($pid, $sprint)['objetivo']);
    return '<div class="bls-objbar">' . rlIco('bandera', 20)
        . '<div class="bls-objbar__txt"><small>Objetivo del ' . e($sprint) . '</small>'
        . ($obj !== '' ? '<b>' . e($obj) . '</b>' : '<span>' . ($puede ? 'Todavía no tiene objetivo: escríbelo al planificar el sprint.' : 'Todavía no tiene objetivo.') . '</span>') . '</div>'
        . '<a class="rs-boton rs-boton--icono" href="' . e(blUrlSprint($pid, $sprint)) . '" aria-label="Planificación y retrospectiva del ' . e($sprint) . '" data-ds-tip="Planificación, capacidad y retrospectiva del ' . e($sprint) . '">' . rlIco('reto', 20) . '</a></div>';
}

/**
 * La historia (historia.php): su «Lista para sprint». Lo que el panel revisa solo
 * sale marcado o no según lo escrito (assets/backlog.js lo repasa mientras se
 * escribe); lo demás lo marca quien la gestiona.
 */
function blDorPanel(array $h, int $pid, bool $puede): string
{
    $d = Backlog::dor($h + ['proyecto_id' => $pid]);
    $sp = (string)($h['sprint'] ?? '');
    $aviso = $sp !== '' && !$d['lista'] && ($h['estado'] ?? '') !== 'hecho';
    $out = '<section class="rl-pan bls-dorpan" data-bl-dor>'
        . '<header class="rl-pan__cab"><h3>Lista para sprint</h3>'
        . '<span class="bls-dor ' . ($d['lista'] ? 'col-s-ok' : 'col-s-warn') . '" data-bl-dor-estado><span class="bls-dor__si">' . blsIco('ok') . '</span><span class="bls-dor__no">' . blsIco('warn') . '</span>'
        . '<span data-bl-dor-txt>' . ($d['lista'] ? 'Lista' : (int)$d['hechos'] . ' de ' . (int)$d['total']) . '</span></span>'
        . ($puede ? '<a class="mc-iconbtn mc-iconbtn--sm bls-cabbtn" href="' . e(blUrlSprint($pid, $sp !== '' ? $sp : Backlog::actual(), 'dor')) . '" aria-label="Cambiar la Definición de «Lista» del proyecto" data-ds-tip="Cambiar la Definición de «Lista» del proyecto">' . rlIco('configuracion', 16) . '</a>' : '')
        . '</header><div class="rl-pan__cuerpo bl-hu__cuerpo">'
        . '<input type="hidden" name="dor[]" value=""><ul class="bls-dorlista">';
    foreach ($d['items'] as $i) {
        if ($i['auto'] !== '') {
            $out .= '<li class="bls-dori is-auto' . ($i['ok'] ? ' is-ok' : '') . '" data-auto="' . e($i['auto']) . '" data-ds-tip="' . e(Backlog::DOR_AUTO[$i['auto']] ?? '') . '">'
                . '<span class="bls-dori__caja" aria-hidden="true">' . UI::checkDibujado(12) . '</span>'
                . '<span class="bls-dori__txt"><b>' . e($i['texto']) . '</b><small>La revisa el panel</small></span></li>';
        } else {
            $out .= '<li class="bls-dori" data-manual>' . UI::checkDS('dor[]', $i['ok'], $i['texto'], '', 'bls-dori__check', $i['clave']) . '</li>';
        }
    }
    return $out . '</ul><div data-bl-dor-aviso' . ($aviso ? '' : ' hidden') . '>'
        . UI::alertaDS('warning', 'Va en un sprint sin estar lista', 'Se puede, pero conviene completarla antes de empezar a trabajarla.', true) . '</div>'
        . '</div></section>';
}

/** Un KPI de la pantalla (como los de la pestaña Sprints), con atributos para que assets/sprint.js lo cambie. */
function blsKpi(string $lbl, string $val, string $k, string $pie = '', string $attrs = ''): string
{
    return '<div class="rl-kpi' . ($k === 'azul' ? ' rl-kpi--azul' : ' col-s-' . $k) . '"' . $attrs . '><small>' . e($lbl) . '</small><b class="mc-num">' . $val . '</b>' . ($pie !== '' ? '<em>' . $pie . '</em>' : '') . '</div>';
}

/** La carga de una persona (o del equipo): la barra con el tono de la colorimetría y el %. */
function blsCarga(int $asignado, int $capacidad): string
{
    $k = Backlog::tonoCarga($asignado, $capacidad);
    $pct = $capacidad > 0 ? (int)round($asignado * 100 / $capacidad) : null;
    $txt = $pct !== null ? $pct . ' %' : ($asignado > 0 ? 'Sin capacidad' : '—');
    return '<span class="bls-carga" data-bls-carga><span class="rl-pbar col-s-' . $k . '" role="img" aria-label="' . e($txt) . '"><i style="width:' . min(100, (int)$pct) . '%"></i></span>'
        . '<b class="mc-num col-s-' . $k . '">' . e($txt) . '</b></span>';
}

/** La gente del proyecto para elegir responsable (con su PO y su SM). */
function blsGente(array $p, array $miembros): array
{
    $ids = ProyectoRepo::miembrosDe($p);
    $ids = $ids === null ? array_keys($miembros) : array_merge($ids, [ProyectoRepo::poDe($p), (int)($p['po_relevo'] ?? 0), ProyectoRepo::scrumDe($p)]);
    $g = [];
    foreach (array_unique(array_map('intval', $ids)) as $mid) if (isset($miembros[$mid])) $g[(string)$mid] = [(string)$miembros[$mid]['nombre'], (string)($miembros[$mid]['rol'] ?? '')];
    uasort($g, fn($a, $b) => strnatcasecmp($a[0], $b[0]));
    return ['0' => ['Sin responsable', 'Lo toma quien pueda']] + $g;
}

/** Una historia en las listas de la pantalla del sprint: código, título, su «Lista», estado, puntos y quién la lleva. */
function blsFilaHu(array $h, array $d, array $miembros, string $extra = ''): string
{
    $falta = !$d['lista'] && $h['estado'] !== 'hecho' && $d['falta'];
    return '<li class="bls-hu">'
        . '<a class="bls-hu__abrir" href="' . e(blUrlHistoria((int)$h['proyecto_id'], (int)$h['id'])) . '"><span class="bl-cod">' . e($h['codigo']) . '</span><span class="bls-hu__tit">' . e($h['titulo']) . '</span></a>'
        . '<span class="bls-hu__meta">' . ($h['estado'] === 'hecho' ? '' : blsDorEstado($d)) . blEstado((string)$h['estado']) . blPts($h) . blResp($h, $miembros) . $extra . '</span>'
        . ($falta ? '<p class="bls-hu__falta">' . blsIco('warn', 13) . '<span>Falta: ' . e(implode(' · ', $d['falta'])) . '</span></p>' : '')
        . '</li>';
}

/** Una fila de acción de la retrospectiva para editarla ($k: su índice en el formulario). */
function blsFilaAccion(string $k, array $a, array $gente, array $tPorId, array $finales, int $pid): string
{
    $tid = (int)($a['tarea_id'] ?? 0);
    $t = $tid ? ($tPorId[$tid] ?? null) : null;
    $tarea = $tid
        ? '<a class="bls-tarea' . ($t && in_array($t['estado'] ?? '', $finales, true) ? ' is-hecha' : '') . '" href="proyecto.php?id=' . $pid . '&amp;tarea=' . $tid . '" data-ds-tip="' . e($t ? (string)$t['titulo'] : 'La tarea ya no está') . '">' . rlIco('completar', 14) . '#' . $tid
            . ($t ? ' · ' . e((string)(Catalogo::estadosTarea()[$t['estado'] ?? ''][0] ?? '')) : '') . '</a>'
        : UI::checkDS('acciones[' . $k . '][tarea]', false, 'Crear tarea', '', 'bls-accf__check');
    return '<div class="bls-accf" data-bls-accf>'
        . '<input type="hidden" name="acciones[' . $k . '][id]" value="' . e((string)($a['id'] ?? '')) . '">'
        . '<div class="mc-input mc-input--md bls-accf__txt"><input class="mc-input__control" name="acciones[' . $k . '][texto]" maxlength="300" aria-label="La acción" placeholder="Ej.: Revisar las historias con el PO antes de planificar" value="' . e((string)($a['texto'] ?? '')) . '"></div>'
        . '<div class="bls-accf__resp">' . UI::selectDS('acciones[' . $k . '][responsable]', $gente, (string)(int)($a['responsable'] ?? 0), ['tam' => 'md', 'buscar' => true, 'aria' => 'Responsable']) . '</div>'
        . '<div class="bls-accf__est">' . UI::selectDS('acciones[' . $k . '][estado]', ['abierta' => 'Abierta', 'hecha' => 'Hecha'], ($a['estado'] ?? '') === 'hecha' ? 'hecha' : 'abierta', ['tam' => 'md', 'aria' => 'Estado']) . '</div>'
        . '<div class="bls-accf__tarea">' . $tarea . '</div>'
        . '<button type="button" class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte mc-iconbtn--danger" data-bls-accf-quitar aria-label="Quitar la acción" data-ds-tip="Quitar la acción">' . rlIco('cerrar', 15) . '</button>'
        . '</div>';
}

/** Una condición de la DoR para editarla ($k: su índice en el formulario). */
function blsFilaDor(string $k, array $i): string
{
    $auto = (string)($i['auto'] ?? '');
    return '<div class="bls-dorc" data-bls-dorc>'
        . '<span class="bls-dorc__tipo' . ($auto !== '' ? ' is-auto' : '') . '"' . ($auto !== '' ? ' data-ds-tip="' . e(Backlog::DOR_AUTO[$auto] ?? '') . '"' : '') . '>' . ($auto !== '' ? rlIco('automatizacion', 14) . 'La revisa el panel' : rlIco('check', 14) . 'Se marca a mano') . '</span>'
        . '<input type="hidden" name="dor[' . $k . '][clave]" value="' . e((string)($i['clave'] ?? '')) . '"><input type="hidden" name="dor[' . $k . '][auto]" value="' . e($auto) . '">'
        . '<div class="mc-input mc-input--md bls-dorc__txt"><input class="mc-input__control" name="dor[' . $k . '][texto]" maxlength="160" aria-label="La condición" placeholder="Ej.: Tiene el diseño de la pantalla aprobado" value="' . e((string)($i['texto'] ?? '')) . '"></div>'
        . '<button type="button" class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte mc-iconbtn--danger" data-bls-dorc-quitar aria-label="Quitar la condición" data-ds-tip="Quitar la condición">' . rlIco('cerrar', 15) . '</button>'
        . '</div>';
}

/**
 * La pantalla del sprint (sprint.php). $x: proyecto, miembros (id => miembro),
 * tareas (las del proyecto), puede (lo gestiona: PO, SM o admin), sprint (S22) y
 * vista (plan | retro | dor).
 */
function blSprintPagina(array $x): string
{
    $p = $x['proyecto'];
    $pid = (int)$p['id'];
    $puede = (bool)$x['puede'];
    $miembros = $x['miembros'];
    $cod = (string)$x['sprint'];
    $v = in_array($x['vista'] ?? '', ['plan', 'retro', 'dor'], true) ? (string)$x['vista'] : 'plan';
    $hoy = date('Y-m-d');
    $sps = Backlog::sprints();
    $s = $sps[$cod];
    $mom = Backlog::momento($s, $hoy);
    $cods = array_keys($sps);
    $iS = (int)array_search($cod, $cods, true);
    $antCod = $iS > 0 ? (string)$cods[$iS - 1] : '';
    $sigCod = (string)($cods[$iS + 1] ?? '');
    $f = Backlog::ficha($pid, $cod);
    $hus = Backlog::historias(['proyecto_id' => $pid, 'sprint' => $cod]);
    $dorL = Backlog::dorLista($pid);
    $relsP = array_values(array_unique(array_column(Backlog::epicas($pid), 'release')));
    $relS = (string)(array_values(array_intersect($s['releases'], $relsP))[0] ?? ($s['releases'][0] ?? ''));
    $urlProy = 'proyecto.php?' . http_build_query(['id' => $pid, 'sp_r' => $relS !== '' ? $relS : null]) . '#vista-sprints';
    $urlTablero = 'proyecto.php?' . http_build_query(['id' => $pid, 'sp_r' => $relS !== '' ? $relS : null, 'sp_v' => 'tablero', 'sp_s' => $cod]) . '#vista-sprints';
    $finales = Catalogo::estadosFinales();
    $tPorId = [];
    foreach ($x['tareas'] as $t) $tPorId[(int)$t['id']] = $t;
    $tareaHecha = fn(array $a) => ($tid = (int)($a['tarea_id'] ?? 0)) && isset($tPorId[$tid]) && in_array($tPorId[$tid]['estado'] ?? '', $finales, true);
    // Lo que quedó abierto de las retros anteriores (si su tarea ya se terminó, cuenta como hecha)
    $pendRetro = array_values(array_filter(Backlog::accionesAbiertas($pid, $cod), fn($a) => !$tareaHecha($a)));
    $abiertasAqui = count(array_filter((array)$f['acciones'], fn($a) => ($a['estado'] ?? '') !== 'hecha' && !$tareaHecha($a)));
    $badge = $mom === 'actual' ? '<span class="bl-badge bl-badge--actual">En curso</span>' : ($mom === 'pasado' ? '<span class="bl-badge">Terminó</span>' : '<span class="bl-badge">Por venir</span>');
    $vistas = ['plan' => ['Planificación', 'reto', 0], 'retro' => ['Retrospectiva', 'reintentar', $abiertasAqui], 'dor' => ['Lista para sprint', 'progreso', 0]];
    $gente = blsGente($p, $miembros);
    $volver = blUrlSprint($pid, $cod, $v);

    ob_start(); ?>
<div class="bls" data-bls data-pid="<?= $pid ?>" data-sprint="<?= e($cod) ?>" data-puede="<?= $puede ? '1' : '' ?>">
  <?php /* La cabecera como la de la historia: el título grande, de qué es y las acciones en contorno */ ?>
  <header class="bl-hu__cab bls-cab">
    <div class="bl-hu__tit"><h1 class="bl-hu__h"><?= e($cod) ?> · <?= e(rlFL($s['inicio'], false) . ' al ' . rlFL($s['fin'], false)) ?></h1>
      <p class="bl-hu__proy"><span class="bl-hu__lbl">Proyecto:</span> <b><?= e((string)$p['nombre']) ?></b><?php if ($s['releases']): ?> <span class="bl-hu__sep">·</span> <span class="bl-hu__lbl">Release:</span> <b><?= e(implode(', ', $s['releases'])) ?></b><?php endif; ?> <?= $badge ?></p></div>
    <div class="bl-hu__acc">
      <a class="bl-cbtn<?= $antCod !== '' ? '' : ' is-off' ?>" href="<?= $antCod !== '' ? e(blUrlSprint($pid, $antCod, $v)) : '#' ?>" aria-label="Sprint anterior" data-ds-tip="<?= $antCod !== '' ? 'Sprint anterior (' . e($antCod) . ')' : 'Sprint anterior' ?>"><?= rlIco('chevron-izq', 18) ?></a>
      <a class="bl-cbtn<?= $sigCod !== '' ? '' : ' is-off' ?>" href="<?= $sigCod !== '' ? e(blUrlSprint($pid, $sigCod, $v)) : '#' ?>" aria-label="Sprint siguiente" data-ds-tip="<?= $sigCod !== '' ? 'Sprint siguiente (' . e($sigCod) . ')' : 'Sprint siguiente' ?>"><?= rlIco('chevron-der', 18) ?></a>
      <a class="bl-cbtn" href="<?= e($urlTablero) ?>" aria-label="Tablero del sprint" data-ds-tip="Tablero del <?= e($cod) ?>"><?= rlIco('dashboard', 18) ?></a>
      <?php /* Cada acción una sola vez, aquí arriba (fija al bajar) */ ?>
      <?php if ($puede && $v === 'dor' && Backlog::dorPropia($pid)): ?><button type="submit" form="bls-form" name="restaurar" value="1" class="bl-cbtn" formnovalidate data-bls-restaurar aria-label="Volver a la lista de siempre" data-ds-tip="Volver a la lista de siempre"><?= rlIco('reintentar', 18) ?></button><?php endif; ?>
      <?php if ($puede): ?><button type="submit" form="bls-form" class="bl-cbtn bl-cbtn--txt"><?= rlIco('check', 18) ?><span><?= ['plan' => 'Guardar la planificación', 'retro' => 'Guardar la retrospectiva', 'dor' => 'Guardar la lista'][$v] ?></span></button><?php endif; ?>
      <a class="bl-cbtn" href="<?= e($urlProy) ?>" aria-label="Volver a los sprints" data-ds-tip="Volver a los sprints"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg></a>
    </div>
  </header>
  <nav class="rl-subnav" aria-label="Qué ver del sprint">
    <?php foreach ($vistas as $kV => [$lblV, $icoV, $nV]): ?>
    <a class="rl-subnav__op<?= $v === $kV ? ' is-active' : '' ?>" href="<?= e(blUrlSprint($pid, $cod, $kV)) ?>"<?= $v === $kV ? ' aria-current="page"' : '' ?>><?= rlIco($icoV, 16) ?><?= e($lblV) ?><?= $nV ? '<span class="rl-subnav__n mc-num" data-ds-tip="Acciones abiertas">' . (int)$nV . '</span>' : '' ?></a>
    <?php endforeach; ?>
  </nav>

<?php if ($v === 'plan'):
    $cap = Backlog::capacidad($p, $cod, $miembros, $hus);
    $vel = Backlog::velocidad($pid);
    $dS = Backlog::dias($s, $hoy);
    $dors = [];
    foreach ($hus as $h) $dors[(int)$h['id']] = Backlog::dor($h, $dorL);
    $pend = array_values(array_filter($hus, fn($h) => $h['estado'] !== 'hecho'));
    $noListas = array_values(array_filter($pend, fn($h) => !$dors[(int)$h['id']]['lista']));
    // El backlog del proyecto (sin sprint) de los releases de este sprint: las listas primero
    $bl = array_values(array_filter(Backlog::historias(['proyecto_id' => $pid, 'sprint' => '']), fn($h) => $h['release'] === '' || in_array($h['release'], $s['releases'], true)));
    $dorBl = [];
    foreach ($bl as $h) $dorBl[(int)$h['id']] = Backlog::dor($h, $dorL);
    usort($bl, fn($a, $b) => [!$dorBl[(int)$a['id']]['lista'], (float)$a['orden']] <=> [!$dorBl[(int)$b['id']]['lista'], (float)$b['orden']]);
    $listasBl = count(array_filter($dorBl, fn($d) => $d['lista']));
    $opDed = [];
    foreach (Backlog::DEDICACIONES as $dd) $opDed[(string)$dd] = [$dd . ' %', $dd === 100 ? 'Tiempo completo' : ($dd === 50 ? 'Medio tiempo' : '')];
    $kComp = $cap['total'] || $cap['comprometido'] ? $cap['tono'] : 'nm'; ?>
  <div class="rl-kpis bls-kpis">
    <?= blsKpi('Capacidad', '<span data-bls-kpi-cap>' . (int)$cap['total'] . '</span><small> pts</small>', 'azul', '<span data-bls-kpi-gente>' . (int)$cap['personas'] . ' persona(s) · ' . e(str_replace('.', ',', (string)$cap['dias_persona'])) . ' días-persona</span>') ?>
    <?= blsKpi('Comprometido', (int)$cap['comprometido'] . '<small> pts</small>', $kComp, count($hus) . ' historia(s) · <span data-bls-kpi-pct>' . ($cap['pct'] !== null ? (int)$cap['pct'] . ' % de la capacidad' : 'sin capacidad') . '</span>', ' data-bls-kpi-comp data-comp="' . (int)$cap['comprometido'] . '"') ?>
    <?= blsKpi('Listas para sprint', ($pend ? (count($pend) - count($noListas)) . '<small>/' . count($pend) . '</small>' : '—'), !$pend ? 'nm' : ($noListas ? 'warn' : 'ok'), 'cumplen la DoR (sin contar las hechas)') ?>
    <?= blsKpi('Velocidad', $vel['promedio'] !== null ? (int)$vel['promedio'] . '<small> pts</small>' : '—', 'azul', 'por sprint, en los últimos ' . count($vel['sprints'])) ?>
    <?= blsKpi('Días hábiles', (int)$dS['quedan'] . '<small>/' . (int)$dS['total'] . '</small>', $dS['quedan'] ? 'azul' : 'done', $mom === 'futuro' ? 'todavía no empieza' : 'los que quedan') ?>
  </div>
  <form id="bls-form" class="rl-tab bls-tab" method="post" action="actions.php" data-bls-plan>
    <input type="hidden" name="accion" value="bl_sprint_plan"><input type="hidden" name="proyecto_id" value="<?= $pid ?>"><input type="hidden" name="sprint" value="<?= e($cod) ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
    <section class="rl-pan rl-pan--8">
      <header class="rl-pan__cab"><h3><?= rlIco('bandera', 18) ?>Objetivo del sprint <small class="bl-hu__sub">Sprint Goal</small></h3></header>
      <div class="rl-pan__cuerpo bls-cuerpo">
        <?php if ($puede): ?>
        <?= rlCampo('Qué valor entrega este sprint', rlTexto('objetivo', (string)$f['objetivo'], 3, 'Ej.: Que un estudiante pueda matricularse y pagar en línea de principio a fin.'),
            'Una o dos frases que el equipo pueda repetir. Se ve en la cabecera del sprint en el backlog y en el tablero.') ?>
        <?php elseif (trim((string)$f['objetivo']) !== ''): ?>
        <p class="bls-objtxt"><?= nl2br(e((string)$f['objetivo'])) ?></p>
        <?php else: ?>
        <p class="bl-vacio"><?= rlIco('info', 16) ?> Este sprint todavía no tiene objetivo.</p>
        <?php endif; ?>
      </div>
    </section>
    <section class="rl-pan rl-pan--4">
      <header class="rl-pan__cab"><h3><?= rlIco('reintentar', 18) ?>Pendiente de retros anteriores<?= $pendRetro ? '<span class="rl-pan__n">' . count($pendRetro) . '</span>' : '' ?></h3></header>
      <div class="rl-pan__cuerpo bls-cuerpo">
        <?php if ($pendRetro): ?>
        <ul class="bls-acc">
          <?php foreach ($pendRetro as $a): $mA = $miembros[(int)($a['responsable'] ?? 0)] ?? null; $tid = (int)($a['tarea_id'] ?? 0); ?>
          <li class="bls-acc__item" data-bls-acc="<?= e((string)$a['id']) ?>" data-sprint="<?= e((string)$a['sprint']) ?>">
            <div class="bls-acc__txt"><b><?= e((string)$a['texto']) ?></b>
              <small><a href="<?= e(blUrlSprint($pid, (string)$a['sprint'], 'retro')) ?>">Retro del <?= e((string)$a['sprint']) ?></a> · <?= $mA ? e((string)$mA['nombre']) : 'sin responsable' ?>
                <?= $tid ? ' · <a href="proyecto.php?id=' . $pid . '&amp;tarea=' . $tid . '">tarea #' . $tid . '</a>' : '' ?></small></div>
            <?php if ($puede): ?>
            <div class="bls-acc__btns">
              <?php if (!$tid): ?><button type="button" class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte" data-bls-acc-que="tarea" aria-label="Crear una tarea con esta acción" data-ds-tip="Crear una tarea con esta acción"><?= rlIco('completar', 16) ?></button><?php endif; ?>
              <button type="button" class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte" data-bls-acc-que="hecha" aria-label="Marcarla hecha" data-ds-tip="Marcarla hecha"><?= rlIco('check', 16) ?></button>
            </div>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="bl-vacio"><?= rlIco('check', 16) ?> No quedó nada abierto de las retrospectivas anteriores.</p>
        <?php endif; ?>
      </div>
    </section>

    <?php /* La capacidad del equipo: se lee aquí y se edita en un modal (por persona y los puntos por día).
             Lo que se edita va en los campos ocultos de cada fila y se guarda con el formulario. */
      $nf = fn($v) => str_replace('.', ',', rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'));
      $ptsDia = rtrim(rtrim(number_format((float)$cap['pts_dia'], 2, '.', ''), '0'), '.'); ?>
    <section class="rl-pan rl-pan--12 bls-cap-pan">
      <header class="rl-pan__cab"><h3><?= rlIco('usuarios', 18) ?>Capacidad del equipo <small class="bl-hu__sub"><?= (int)$cap['dias'] ?> días hábiles · <span data-bls-ptsdia-txt><?= e($nf($cap['pts_dia'])) ?></span> pts por día</small></h3>
        <?php if ($puede): ?><button type="button" class="rl-pan__btn" data-bls-abrir-pd aria-label="Puntos por día" data-ds-tip="Puntos por día"><?= rlIco('configuracion', 16) ?></button><?php endif; ?>
      </header>
      <div class="rl-pan__cuerpo bls-cuerpo">
        <?php if ($puede): ?><input type="hidden" name="pts_dia" value="<?= e($ptsDia) ?>" data-bls-ptsdia><?php endif; ?>
        <div class="bls-cap" data-bls-cap data-dias="<?= (int)$cap['dias'] ?>" data-horas="<?= (int)Backlog::HORAS_DIA ?>" role="table" aria-label="Capacidad del equipo">
          <div class="bls-cap__fila bls-cap__cab" role="row"><span role="columnheader">Persona</span><span role="columnheader">Disponible</span><span role="columnheader">Capacidad</span><span role="columnheader">Asignado</span><span role="columnheader">Carga</span><span role="columnheader" class="mc-sr-only">Editar</span></div>
          <?php foreach ($cap['gente'] as $g): $mG = $miembros[$g['miembro']] ?? null; $n = 'gente[' . (int)$g['miembro'] . ']'; ?>
          <div class="bls-cap__fila<?= $g['cuenta'] ? '' : ' is-fuera' ?>" role="row" data-bls-fila data-asig="<?= (int)$g['asignado'] ?>" data-nombre="<?= e($g['nombre']) ?>">
            <?php if ($puede): ?>
            <input type="hidden" name="<?= $n ?>[cuenta]" value="<?= $g['cuenta'] ? '1' : '' ?>" data-bls-in="cuenta">
            <input type="hidden" name="<?= $n ?>[ausencias]" value="<?= e(str_replace('.0', '', (string)$g['ausencias'])) ?>" data-bls-in="ausencias">
            <input type="hidden" name="<?= $n ?>[dedicacion]" value="<?= (int)$g['dedicacion'] ?>" data-bls-in="dedicacion">
            <input type="hidden" name="<?= $n ?>[puntos]" value="<?= $g['manual'] !== null ? (int)$g['manual'] : '' ?>" data-bls-in="puntos">
            <?php endif; ?>
            <span class="bls-persona" role="cell"><?= UI::avatar($mG, 30) ?><span><b><?= e($g['nombre']) ?></b><small data-bls-est><?= $g['cuenta'] ? ($g['historias'] ? (int)$g['historias'] . ' historia(s)' : 'Sin historias') : 'No cuenta en este sprint' ?></small></span></span>
            <span class="bls-cap__dato" role="cell"><b data-bls-dias><?= $g['cuenta'] ? e($nf($g['dias'])) . ' días · ' . e($nf($g['horas'])) . ' h' : '—' ?></b>
              <small data-bls-det><?= (int)$g['dedicacion'] ?> % · <?= (float)$g['ausencias'] > 0 ? 'falta ' . e($nf($g['ausencias'])) . ' día(s)' : 'sin ausencias' ?></small></span>
            <span class="bls-cap__dato" role="cell"><b><span data-bls-pts><?= (int)$g['capacidad'] ?></span> pts</b><small data-bls-modo><?= $g['manual'] !== null ? 'Puesta a mano' : 'Calculada' ?></small></span>
            <span class="bls-cap__dato" role="cell"><b><?= (int)$g['asignado'] ?> pts</b><small><?= $g['historias'] ? (int)$g['historias'] . ' historia(s)' : 'Nada asignado' ?></small></span>
            <span class="bls-cap__carga" role="cell"><?= blsCarga((int)$g['asignado'], (int)$g['capacidad']) ?></span>
            <span class="bls-cap__acc" role="cell"><?php if ($puede): ?><button type="button" class="rs-boton rs-boton--icono" data-bls-editar aria-label="Editar la capacidad de <?= e($g['nombre']) ?>" data-ds-tip="Editar"><?= rlIco('editar', 16) ?></button><?php endif; ?></span>
          </div>
          <?php endforeach; ?>
          <?php if (!$cap['gente']): ?><p class="bls-tabla__vacio">El proyecto todavía no tiene gente.</p><?php endif; ?>
          <?php if ($cap['sin_resp']): ?>
          <div class="bls-cap__fila bls-cap__sinresp" role="row"><span role="cell"><b>Sin responsable</b><small>Historias del sprint que nadie lleva todavía</small></span><span role="cell"></span><span role="cell"></span>
            <span class="bls-cap__dato" role="cell"><b><?= (int)$cap['sin_resp'] ?> pts</b></span><span role="cell"></span><span role="cell"></span></div>
          <?php endif; ?>
          <div class="bls-cap__fila bls-cap__total" role="row"><span role="cell"><b>Equipo</b><small data-bls-tot-dias><?= e($nf($cap['dias_persona'])) ?> días-persona</small></span><span role="cell"></span>
            <span class="bls-cap__dato" role="cell"><b><span data-bls-tot-cap><?= (int)$cap['total'] ?></span> pts</b><small>de capacidad</small></span>
            <span class="bls-cap__dato" role="cell"><b><?= (int)$cap['comprometido'] ?> pts</b><small>comprometidos</small></span>
            <span class="bls-cap__carga" role="cell" data-bls-tot-carga data-comp="<?= (int)$cap['comprometido'] ?>"><?= blsCarga((int)$cap['comprometido'], (int)$cap['total']) ?></span><span role="cell"></span></div>
        </div>
        <p class="bl-hu__ayuda">La capacidad de cada uno es (días hábiles − días que falta) × su dedicación × los puntos por día, o la que se ponga a mano. Lo asignado son los puntos de las historias del sprint que lleva.</p>
      </div>
    </section>

    <section class="rl-pan rl-pan--8">
      <header class="rl-pan__cab"><h3><?= rlIco('lista-espera', 18) ?>Historias del sprint<?= $hus ? ' <small class="bl-hu__sub">' . count($hus) . ' · ' . (int)$cap['comprometido'] . ' pts</small>' : '' ?></h3>
        <?php if ($puede && $mom !== 'pasado'): ?><a class="mc-iconbtn mc-iconbtn--sm bls-cabbtn" href="<?= e(blUrlHistoria($pid, 0, $cod)) ?>" aria-label="Nueva historia en el <?= e($cod) ?>" data-ds-tip="Nueva historia en el <?= e($cod) ?>"><?= rlIco('mas', 16) ?></a><?php endif; ?></header>
      <div class="rl-pan__cuerpo bls-cuerpo">
        <?php if ($noListas): ?><?= UI::alertaDS('warning', count($noListas) === 1 ? 'Una historia no está lista para sprint' : count($noListas) . ' historias no están listas para sprint',
            'Se pueden trabajar, pero conviene completarlas antes: abre cada una y revisa su «Lista para sprint».', true) ?><?php endif; ?>
        <?php if ($hus): ?>
        <ul class="bls-hus"><?php foreach ($hus as $h) echo blsFilaHu($h, $dors[(int)$h['id']], $miembros); ?></ul>
        <?php else: ?>
        <p class="bl-vacio"><?= rlIco('info', 16) ?> El <?= e($cod) ?> todavía no tiene historias<?= $puede ? ': pásalas desde el backlog (aquí al lado o arrastrándolas en la pestaña Sprints).' : '.' ?></p>
        <?php endif; ?>
      </div>
    </section>
    <section class="rl-pan rl-pan--4">
      <header class="rl-pan__cab"><h3><?= rlIco('lista-espera', 18) ?>Del backlog <small class="bl-hu__sub"><?= $listasBl ?> lista(s) de <?= count($bl) ?></small></h3></header>
      <div class="rl-pan__cuerpo bls-cuerpo">
        <?php if ($bl): ?>
        <ul class="bls-hus bls-hus--mini">
          <?php foreach (array_slice($bl, 0, 15) as $h): $d = $dorBl[(int)$h['id']];
            $btn = $puede && $mom !== 'pasado' ? '<button type="button" class="mc-iconbtn mc-iconbtn--sm mc-iconbtn--tinte" data-bls-pasar="' . (int)$h['id'] . '" data-dor="' . ($d['lista'] ? 1 : 0) . '" data-cod="' . e($h['codigo']) . '" data-falta="' . e(implode(' · ', $d['falta'])) . '" aria-label="Pasar al ' . e($cod) . '" data-ds-tip="' . ($d['lista'] ? 'Pasar al ' . e($cod) : 'Pasar al ' . e($cod) . ' (no está lista)') . '">' . rlIco('mas', 16) . '</button>' : ''; ?>
          <li class="bls-hu">
            <a class="bls-hu__abrir" href="<?= e(blUrlHistoria($pid, (int)$h['id'])) ?>"><span class="bl-cod"><?= e($h['codigo']) ?></span><span class="bls-hu__tit"><?= e($h['titulo']) ?></span></a>
            <span class="bls-hu__meta"><?= blsDorEstado($d) ?><?= blPts($h) ?><?= $btn ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php if (count($bl) > 15): ?><p class="bl-hu__ayuda">Y <?= count($bl) - 15 ?> más en el <a href="<?= e($urlProy) ?>">backlog</a>.</p><?php endif; ?>
        <?php else: ?>
        <p class="bl-vacio"><?= rlIco('info', 16) ?> No hay historias sin sprint en este release.</p>
        <?php endif; ?>
      </div>
    </section>
    <?php if ($puede): ?>
    <dialog class="ds-modal ds-modal--chico bls-dlg" data-bls-dlg-cap aria-labelledby="bls-dlg-cap-t">
      <div class="ds">
        <header class="ds-modal__head"><span class="mc-tile mc-tile--sm mc-tile--azul"><?= rlIco('usuarios', 18) ?></span>
          <div><h2 class="ds-modal__title" id="bls-dlg-cap-t" data-dlg-tit>Capacidad</h2><p class="ds-modal__sub"><?= e($cod) ?> · <?= (int)$cap['dias'] ?> días hábiles</p></div></header>
        <div class="ds-modal__body bls-dlg__cuerpo">
          <?= UI::checkDS('dlg_cuenta', true, 'Cuenta en este sprint', 'Si no está (vacaciones, otro proyecto), no suma capacidad.', 'bls-dlg__cuenta') ?>
          <div class="mc-field"><label class="mc-field__label" for="bls-dlg-aus">Días que falta</label>
            <div class="mc-input mc-input--md"><input class="mc-input__control" id="bls-dlg-aus" type="number" min="0" max="<?= (int)$cap['dias'] ?>" step="0.5" inputmode="decimal" data-dlg-aus><span class="mc-input__affix">días</span></div></div>
          <div class="mc-field"><span class="mc-field__label">Dedicación</span>
            <div class="bl-est-ops bls-dlg__ded" role="radiogroup" aria-label="Dedicación">
              <?php foreach (Backlog::DEDICACIONES as $dd): ?><label class="bl-est-op"><input type="radio" name="dlg_ded" value="<?= $dd ?>"><span><?= $dd ?> %</span></label><?php endforeach; ?></div>
            <div class="mc-field__foot"><span class="mc-field__hint">50 % es medio tiempo.</span></div></div>
          <div class="mc-field"><label class="mc-field__label" for="bls-dlg-pts">Capacidad a mano</label>
            <div class="mc-input mc-input--md"><input class="mc-input__control" id="bls-dlg-pts" type="number" min="0" max="300" step="1" inputmode="numeric" data-dlg-pts><span class="mc-input__affix">pts</span></div>
            <div class="mc-field__foot"><span class="mc-field__hint" data-dlg-pts-ayuda>Vacío: la calculada.</span></div></div>
          <div class="bls-dlg__res" data-dlg-res></div>
        </div>
        <footer class="ds-modal__foot"><button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-dlg-cerrar><span class="mc-btn__label">Cancelar</span></button>
          <button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-dlg-guardar><?= rlIco('check', 18) ?><span class="mc-btn__label">Guardar</span></button></footer>
      </div>
    </dialog>
    <dialog class="ds-modal ds-modal--chico bls-dlg" data-bls-dlg-pd aria-labelledby="bls-dlg-pd-t">
      <div class="ds">
        <header class="ds-modal__head"><span class="mc-tile mc-tile--sm mc-tile--azul"><?= rlIco('configuracion', 18) ?></span>
          <div><h2 class="ds-modal__title" id="bls-dlg-pd-t">Puntos por día</h2><p class="ds-modal__sub">Lo que rinde un día completo de una persona del equipo.</p></div></header>
        <div class="ds-modal__body bls-dlg__cuerpo">
          <div class="mc-field"><label class="mc-field__label" for="bls-dlg-pd">Puntos por día</label>
            <div class="mc-input mc-input--md"><input class="mc-input__control" id="bls-dlg-pd" type="number" min="0" max="20" step="0.25" inputmode="decimal" data-dlg-pd><span class="mc-input__affix">pts</span></div>
            <div class="mc-field__foot"><span class="mc-field__hint">Con 1 punto por día, una persona a tiempo completo da <?= (int)$cap['dias'] ?> pts en este sprint.</span></div></div>
          <div class="bls-dlg__res" data-dlg-pd-res></div>
        </div>
        <footer class="ds-modal__foot"><button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-dlg-cerrar><span class="mc-btn__label">Cancelar</span></button>
          <button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-dlg-pd-guardar><?= rlIco('check', 18) ?><span class="mc-btn__label">Guardar</span></button></footer>
      </div>
    </dialog>
    <?php endif; ?>
  </form>

<?php elseif ($v === 'retro'):
    $comp = array_sum(array_column($hus, 'puntos'));
    $hechoPts = array_sum(array_map(fn($h) => $h['estado'] === 'hecho' ? $h['puntos'] : 0, $hus));
    $nHechas = count(array_filter($hus, fn($h) => $h['estado'] === 'hecho'));
    $pct = $comp ? (int)round($hechoPts * 100 / $comp) : null;
    $logr = (string)$f['logrado'];
    $acciones = (array)$f['acciones']; ?>
  <div class="rl-kpis bls-kpis">
    <?= blsKpi('Comprometido', (int)$comp . '<small> pts</small>', 'azul', count($hus) . ' historia(s)') ?>
    <?= blsKpi('Hecho', (int)$hechoPts . '<small> pts</small>', $pct === null ? 'nm' : ($pct >= 80 ? 'ok' : ($pct >= 40 ? 'warn' : 'crit')), $pct !== null ? $pct . ' % de lo comprometido' : 'nada estimado') ?>
    <?= blsKpi('Terminadas', $nHechas . '<small>/' . count($hus) . '</small>', !$hus ? 'nm' : ($nHechas === count($hus) ? 'ok' : 'warn'), count($hus) - $nHechas ? (count($hus) - $nHechas) . ' sin terminar' : 'todas') ?>
    <?= blsKpi('Objetivo', $logr !== '' ? e(Backlog::LOGRADO[$logr][0]) : '—', $logr !== '' ? Backlog::LOGRADO[$logr][1] : 'nm', $logr !== '' ? '¿se cumplió?' : 'sin responder') ?>
    <?= blsKpi('Acciones abiertas', (string)$abiertasAqui, $abiertasAqui ? 'warn' : ($acciones ? 'ok' : 'nm'), count($acciones) . ' en total') ?>
  </div>
  <?php if ($mom === 'futuro'): ?><p class="bl-vacio"><?= rlIco('info', 16) ?> El <?= e($cod) ?> todavía no empieza: su retrospectiva se hace al terminarlo, el <?= e(rlFL($s['fin'], true)) ?>.</p><?php endif; ?>
  <form id="bls-form" class="rl-tab bls-tab" method="post" action="actions.php" data-bls-retro>
    <input type="hidden" name="accion" value="bl_sprint_retro"><input type="hidden" name="proyecto_id" value="<?= $pid ?>"><input type="hidden" name="sprint" value="<?= e($cod) ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
    <section class="rl-pan rl-pan--12">
      <header class="rl-pan__cab"><h3><?= rlIco('bandera', 18) ?>Objetivo del sprint</h3></header>
      <div class="rl-pan__cuerpo bls-cuerpo bls-retro-obj">
        <?= trim((string)$f['objetivo']) !== '' ? '<p class="bls-objtxt">' . nl2br(e((string)$f['objetivo'])) . '</p>' : '<p class="bl-vacio">' . rlIco('info', 16) . ' No se escribió objetivo para este sprint' . ($puede ? ': <a href="' . e(blUrlSprint($pid, $cod)) . '">ponlo en la planificación</a>.' : '.') . '</p>' ?>
        <div class="mc-field"><span class="mc-field__label">¿Se cumplió?</span>
          <?php if ($puede): ?>
          <div class="bl-est-ops bls-logrado" role="radiogroup" aria-label="¿Se cumplió el objetivo?">
            <label class="bl-est-op"><input type="radio" name="logrado" value=""<?= $logr === '' ? ' checked' : '' ?>><span>Sin responder</span></label>
            <?php foreach (Backlog::LOGRADO as $kL => [$lblL, $tonoL]): ?>
            <label class="bl-est-op col-s-<?= $tonoL ?>"><input type="radio" name="logrado" value="<?= $kL ?>"<?= $logr === $kL ? ' checked' : '' ?>><span><?= blsIco($tonoL) ?><?= e($lblL) ?></span></label>
            <?php endforeach; ?>
          </div>
          <?php else: ?>
          <p><?= $logr !== '' ? (class_exists('Colorimetria') ? Colorimetria::estado(Backlog::LOGRADO[$logr][1], Backlog::LOGRADO[$logr][0]) : e(Backlog::LOGRADO[$logr][0])) : 'Sin responder' ?></p>
          <?php endif; ?>
        </div>
      </div>
    </section>
    <?php foreach (['bien' => ['Qué salió bien', 'exito', 'Ej.: Las dailies fueron cortas y a la hora'], 'mal' => ['Qué salió mal o se puede mejorar', 'alerta', "Ej.: Las historias llegaron sin criterios\nEl ambiente de pruebas se cayó dos días"]] as $kR => [$tR, $icoR, $phR]): ?>
    <section class="rl-pan rl-pan--6 bls-ideas-pan">
      <header class="rl-pan__cab"><h3><?= rlIco($icoR, 18) ?><?= e($tR) ?><?= $f[$kR] ? ' <small class="bl-hu__sub">' . count($f[$kR]) . '</small>' : '' ?></h3></header>
      <div class="rl-pan__cuerpo bls-cuerpo">
        <?php if ($puede): ?>
        <?= rlCampo('Una idea por línea', rlTexto($kR, implode("\n", (array)$f[$kR]), 6, $phR)) ?>
        <?php elseif ($f[$kR]): ?>
        <ul class="bls-ideas bls-ideas--<?= $kR ?>"><?php foreach ((array)$f[$kR] as $idea): ?><li><?= e((string)$idea) ?></li><?php endforeach; ?></ul>
        <?php else: ?>
        <p class="bl-vacio"><?= rlIco('info', 16) ?> Nada anotado.</p>
        <?php endif; ?>
      </div>
    </section>
    <?php endforeach; ?>
    <section class="rl-pan rl-pan--12">
      <header class="rl-pan__cab"><h3><?= rlIco('reto', 18) ?>Acciones <small class="bl-hu__sub">lo que el equipo se compromete a cambiar</small></h3></header>
      <div class="rl-pan__cuerpo bls-cuerpo">
        <?php if ($puede): ?>
        <div class="bls-accs" data-bls-accs>
          <div class="bls-accf bls-accf--cab" aria-hidden="true"><span>Acción</span><span>Responsable</span><span>Estado</span><span>Tarea</span><span></span></div>
          <?php foreach ($acciones as $n => $a) echo blsFilaAccion((string)$n, (array)$a, $gente, $tPorId, $finales, $pid); ?>
          <?php if (!$acciones) echo blsFilaAccion('n0', [], $gente, $tPorId, $finales, $pid); ?>
        </div>
        <template data-bls-accf-tpl><?= blsFilaAccion('__K__', [], $gente, $tPorId, $finales, $pid) ?></template>
        <button type="button" class="bl-escs__mas" data-bls-accf-mas><?= rlIco('mas', 16) ?> Agregar acción</button>
        <p class="bl-hu__ayuda">«Crear tarea» la crea en el proyecto al guardar, para su responsable y con las fechas del sprint que sigue. Las que queden abiertas se ven al planificar el próximo sprint.</p>
        <?php elseif ($acciones): ?>
        <ul class="bls-acc bls-acc--retro">
          <?php foreach ($acciones as $a): $mA = $miembros[(int)($a['responsable'] ?? 0)] ?? null; $tid = (int)($a['tarea_id'] ?? 0); $hecha = ($a['estado'] ?? '') === 'hecha' || $tareaHecha($a); ?>
          <li class="bls-acc__item">
            <?= $mA ? UI::avatar($mA, 28) : '<span class="bl-resp is-nadie">' . rlIco('usuario', 14) . '</span>' ?>
            <div class="bls-acc__txt"><b><?= e((string)$a['texto']) ?></b><small><?= $mA ? e((string)$mA['nombre']) : 'Sin responsable' ?><?= $tid ? ' · <a href="proyecto.php?id=' . $pid . '&amp;tarea=' . $tid . '">tarea #' . $tid . '</a>' : '' ?></small></div>
            <?= class_exists('Colorimetria') ? Colorimetria::estado($hecha ? 'ok' : 'warn', $hecha ? 'Hecha' : 'Abierta') : '' ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="bl-vacio"><?= rlIco('info', 16) ?> Sin acciones anotadas.</p>
        <?php endif; ?>
      </div>
    </section>
  </form>

<?php else:
    // La Definición de «Lista» del proyecto y cómo van sus historias frente a ella
    $vivas = array_values(array_filter(Backlog::historias(['proyecto_id' => $pid]), fn($h) => $h['estado'] !== 'hecho'));
    $faltas = array_fill_keys(array_column($dorL, 'clave'), 0);
    $nListas = 0;
    foreach ($vivas as $h) {
        $d = Backlog::dor($h, $dorL);
        if ($d['lista']) $nListas++;
        foreach ($d['items'] as $it) if (!$it['ok']) $faltas[$it['clave']]++;
    }
    $propia = Backlog::dorPropia($pid); ?>
  <form id="bls-form" class="rl-tab bls-tab" method="post" action="actions.php" data-bls-dor>
    <input type="hidden" name="accion" value="bl_dor_conf"><input type="hidden" name="proyecto_id" value="<?= $pid ?>"><input type="hidden" name="volver" value="<?= e($volver) ?>">
    <section class="rl-pan rl-pan--8">
      <header class="rl-pan__cab"><h3><?= rlIco('progreso', 18) ?>Definición de «Lista» <small class="bl-hu__sub"><?= e((string)$p['nombre']) ?><?= $propia ? '' : ' · la de siempre' ?></small></h3></header>
      <div class="rl-pan__cuerpo bls-cuerpo">
        <p class="bl-hu__ayuda">Lo que una historia tiene que cumplir para entrar a un sprint (Definition of Ready). Cuando cumple todo, en el backlog sale «Lista». Si se mete en un sprint sin estar lista, el panel avisa pero no lo impide. Vale para todo el proyecto.</p>
        <?php if ($puede): ?>
        <div class="bls-dorconf" data-bls-dorconf>
          <?php foreach ($dorL as $n => $i) echo blsFilaDor((string)$n, $i); ?>
        </div>
        <template data-bls-dorc-tpl><?= blsFilaDor('__K__', ['clave' => '', 'texto' => '', 'auto' => '']) ?></template>
        <button type="button" class="bl-escs__mas" data-bls-dorc-mas><?= rlIco('mas', 16) ?> Agregar condición</button>
        <?php else: ?>
        <ul class="bls-dorlista">
          <?php foreach ($dorL as $i): ?><li class="bls-dori<?= $i['auto'] !== '' ? ' is-auto' : '' ?>"><span class="bls-dori__txt"><b><?= e($i['texto']) ?></b><small><?= $i['auto'] !== '' ? 'La revisa el panel' : 'Se marca a mano en la historia' ?></small></span></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </section>
    <section class="rl-pan rl-pan--4">
      <header class="rl-pan__cab"><h3><?= rlIco('reportes', 18) ?>Cómo van las historias</h3></header>
      <div class="rl-pan__cuerpo bls-cuerpo">
        <div class="rl-kpis rl-kpis--2">
          <?= blsKpi('Listas', $nListas . '<small>/' . count($vivas) . '</small>', !$vivas ? 'nm' : ($nListas === count($vivas) ? 'ok' : 'warn'), 'sin contar las hechas') ?>
          <?= blsKpi('Por completar', (string)(count($vivas) - $nListas), count($vivas) - $nListas ? 'warn' : 'ok', 'les falta algo') ?>
        </div>
        <?php if ($vivas): ?>
        <p class="bls-sub">Lo que más falta</p>
        <ul class="bls-faltas">
          <?php foreach ($dorL as $i): $nF = (int)($faltas[$i['clave']] ?? 0); $pF = (int)round($nF * 100 / max(1, count($vivas))); ?>
          <li><span class="bls-faltas__txt"><?= e($i['texto']) ?></span><b class="mc-num"><?= $nF ?></b>
            <span class="rl-pbar col-s-<?= $nF ? 'warn' : 'ok' ?>" role="img" aria-label="<?= $nF ?> de <?= count($vivas) ?> historias no lo cumplen"><i style="width:<?= $nF ? $pF : 100 ?>%"></i></span></li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </section>
  </form>
<?php endif; ?>

</div>
    <?php
    return (string)ob_get_clean();
}
