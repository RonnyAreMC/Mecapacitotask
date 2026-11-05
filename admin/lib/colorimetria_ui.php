<?php
/**
 * El modal de la salud (colorimetría) de un proyecto o de un equipo de
 * Releases, en su propia página (proyecto.php y releases.php?v=equipo): el
 * puntaje, cada indicador con su gráfico de bala y, para quien lo gestiona,
 * los valores del mes que no calcula el panel (CPI, margen, riesgos…).
 * La fila sale de Colorimetria::delProyecto() o Colorimetria::delEquipo().
 */

/** Gráfico de bala: las zonas con los mismos umbrales de la configuración y la marca del valor. */
function colBala(array $i, ?float $val, string $st): string
{
    $pos = fn(float $x) => max(0, min(100, ($x - $i['min']) / (($i['max'] - $i['min']) ?: 1) * 100));
    $e = !empty($i['int']) ? 0.5 : 0;
    $zonas = $i['dir'] === 'up'
        ? [['crit', $i['min'], $i['a']], ['warn', $i['a'], $i['g']], ['ok', $i['g'], $i['max']]]
        : [['ok', $i['min'], $i['g'] + $e], ['warn', $i['g'] + $e, $i['a'] + $e], ['crit', $i['a'] + $e, $i['max']]];
    $h = '<span class="col-bala col-s-' . e($st) . '" role="img" aria-label="' . e($i['n'] . ': ' . Colorimetria::fmt($i, $val) . ', meta ' . Colorimetria::meta($i)) . '">';
    foreach ($zonas as [$k, $de, $a]) {
        $l = $pos((float)$de);
        $h .= '<i class="col-bala__zona col-s-' . ($st === 'nm' ? 'nm' : $k) . '" style="left:' . round($l, 2) . '%;width:' . round(max(0, $pos((float)$a) - $l), 2) . '%"></i>';
    }
    if ($val !== null) $h .= '<i class="col-bala__val" style="width:' . round($pos($val), 2) . '%"></i>';
    return $h . '</span>';
}

/** El detalle: el puntaje con sus bandas y cada indicador por dimensión. */
function colDetalle(array $fila): string
{
    $C = Colorimetria::class;
    $conf = $C::conf();
    $ev = $fila['ev'];
    $sk = $ev['puntaje'] === null ? 'nm' : $ev['salud'];
    $h = '<div class="col-det"><div class="col-det__cab col-s-' . e($sk) . '">';
    $h .= '<span class="col-det__pts"><small>Puntaje</small><b class="mc-num">' . ($ev['puntaje'] === null ? 'Sin dato' : $C::num($ev['puntaje'])) . '</b>' . ($ev['puntaje'] === null ? '' : '<em>de 100</em>') . '</span>';
    if ($ev['puntaje'] !== null) {
        $h .= '<span class="col-det__barra" role="img" aria-label="Puntaje ' . $C::num($ev['puntaje']) . ' de 100"><i style="width:' . round(max(0, min(100, $ev['puntaje']))) . '%"></i>'
            . '<em style="left:' . $conf['bandas']['warn'] . '%"></em><em style="left:' . $conf['bandas']['ok'] . '%"></em></span>';
    }
    $h .= $C::estado($fila['estado']) . '<span class="col-det__cuentas">';
    foreach (['ok', 'warn', 'crit', 'nm'] as $k) if ($ev['cuenta'][$k]) $h .= '<span class="col-s-' . $k . '"><i class="col-punto"></i><b class="mc-num">' . $ev['cuenta'][$k] . '</b> ' . e($C::nombre($k)) . '</span>';
    $h .= '</span>';
    if ($ev['claves']) $h .= '<span class="col-det__claves">' . $C::estado('crit', ($ev['escalo'] ? 'Escaló por clave: ' : 'Clave en ' . $C::nombre('crit') . ': ') . implode(', ', array_map(fn($r) => $r['i']['n'], $ev['claves']))) . '</span>';
    // Primero lo que tiene dato, por dimensión; lo que todavía no tiene, en una sola línea al final
    $tp = $fila['tipo'] === 'proyecto' ? 'p' : 'e';
    $h .= '</div><div class="col-ind"><div class="col-ind__cols"><span>Indicador</span><span>Marco</span><span>Real</span><span>Meta</span><span>Zonas</span><span>Peso</span><span>Estado</span></div>';
    $sin = [];
    foreach ($ev['dims'] as $d) {
        $conDato = array_filter($d['filas'], fn($r) => $r['v'] !== null);
        foreach (array_diff_key($d['filas'], $conDato) as $id => $r) $sin[$id] = $r['i'];
        if (!$conDato) continue;
        $h .= '<div class="col-ind__dim"><b>' . e($d['n']) . '</b><span class="col-ind__est">' . $C::estado($d['st']) . '</span></div>';
        foreach ($conDato as $id => $r) {
            $i = $r['i'];
            $como = Colorimetria::COMO[$id][$tp] ?? '';
            $anotado = ($fila['fuente'][$id] ?? '') === 'manual';
            $h .= '<div class="col-ind__fila"><span class="col-ind__nom"><span class="col-ind__txt"' . ($como !== '' ? ' data-ds-tip="' . e($como) . '"' : '') . '>' . e($i['n']) . '</span>'
                . (!empty($i['clave']) ? '<span class="col-tag col-tag--clave">Clave</span>' : '') . '</span>'
                . '<span><span class="col-tag' . ($i['m'] === 'Scrum' ? ' col-tag--scrum' : '') . '">' . $i['m'] . '</span></span>'
                . '<span class="col-ind__real"><b class="mc-num">' . e($C::fmt($i, $r['v'])) . '</b>'
                . ($anotado ? '<span class="col-ind__anot" data-ds-tip="Anotado a mano este mes">' . UI::iconoDS('editar', 12) . '</span>' : '') . '</span>'
                . '<span class="mc-num">' . e($C::meta($i)) . '</span>' . colBala($i, $r['v'], $r['st'])
                . '<span class="mc-num">' . $C::num((float)$i['w']) . '</span><span class="col-ind__est">' . $C::estado($r['st']) . '</span></div>';
        }
    }
    if ($sin) {
        $h .= '<div class="col-ind__sin">' . $C::icono('nm', 16) . '<span><b>Sin dato todavía</b> (no cuentan en el puntaje): ';
        $h .= implode(' · ', array_map(fn($id, $i) => '<span' . (($c = Colorimetria::COMO[$id][$tp] ?? '') !== '' ? ' data-ds-tip="' . e($c) . '"' : '') . '>' . e($i['n']) . '</span>', array_keys($sin), $sin));
        $h .= '</span></div>';
    }
    return $h . '</div></div>';
}

/**
 * El modal completo: el detalle y, si quien entra lo gestiona, los valores del
 * mes. $volver: la página a la que se regresa al guardar (la misma).
 */
function colModal(array $fila, string $id, string $volver): string
{
    $C = Colorimetria::class;
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $per = $C::periodo();
    $mes = $meses[(int)substr($per, 5, 2) - 1];
    [$man, $perMan, $med] = $C::manuales($fila['tipo'], $fila['ref'], $per);
    // Lo que se anota: los indicadores prendidos que el panel no calcula (y lo que ya se anotó, para corregirlo)
    $anotables = [];
    foreach (Colorimetria::IND as $iid => $_) {
        $i = $C::ind($iid);
        if (!$i['act']) continue;
        $calcula = str_contains((string)($i['auto'] ?? ''), $fila['tipo'] === 'proyecto' ? 'p' : 'e');
        if (!$calcula || array_key_exists($iid, $man)) $anotables[$iid] = $i;
    }
    $puede = $anotables && $C::puedeMedir($fila['tipo'], $fila['ref']);
    $sub = 'Lo calcula el panel con sus tareas, revisiones y observaciones de QA.';
    if ($fila['periodo'] !== '') $sub .= ' Con lo anotado en ' . e($meses[(int)substr($fila['periodo'], 5, 2) - 1]) . '.';
    $h = '<dialog id="' . e($id) . '" class="dlg-meca col-dlg"><div class="dlg-form">'
        . '<header><h3 class="col-s-' . e($fila['estado']) . '">' . $C::icono($fila['estado'], 20) . ' Salud ' . ($fila['tipo'] === 'equipo' ? 'del ' : 'de ') . e($fila['nombre'])
        . '<small>' . $sub . '</small></h3>'
        . '<button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar">' . UI::iconoDS('cerrar', 18) . '</button></header>'
        . colDetalle($fila);
    if ($puede) {
        $h .= '<details class="col-mes"><summary>' . UI::iconoDS('editar', 16) . ' Anotar lo que el panel no calcula (' . e($mes) . ')<small>' . e(implode(', ', array_map(fn($i) => $i['n'], $anotables))) . '</small></summary>'
            . '<form method="post" action="actions.php" class="col-mes__form">'
            . '<input type="hidden" name="accion" value="col_medir"><input type="hidden" name="t" value="' . e($fila['tipo']) . '"><input type="hidden" name="r" value="' . e($fila['ref']) . '">'
            . '<input type="hidden" name="periodo" value="' . e($per) . '"><input type="hidden" name="volver" value="' . e($volver) . '">'
            . '<input type="hidden" name="codigo" value="' . e($fila['ficha']['codigo']) . '"><input type="hidden" name="cliente" value="' . e($fila['ficha']['cliente']) . '">';
        if ($perMan !== '' && $perMan !== $per) $h .= '<p class="col-mes__nota">' . UI::iconoDS('info', 14) . ' Vienen los valores de ' . e($meses[(int)substr($perMan, 5, 2) - 1]) . ': al guardar quedan como los de ' . e($mes) . '.</p>';
        $h .= '<div class="col-mes__grid">';
        foreach ($anotables as $iid => $i) {
            $val = array_key_exists($iid, $man) ? $C::num((float)$man[$iid], (int)$i['d']) : '';
            $h .= '<label class="col-mes__campo"><span>' . e($i['n']) . (!empty($i['clave']) ? ' <span class="col-tag col-tag--clave">Clave</span>' : '') . '<small>Meta ' . e($C::meta($i)) . '</small></span>'
                . '<span class="mc-input mc-input--sm"><input class="mc-input__control mc-num" name="vals[' . $iid . ']" inputmode="decimal" value="' . e($val) . '" placeholder="—">'
                . ($i['u'] !== '' ? '<span class="mc-input__affix">' . e($i['u']) . '</span>' : '') . '</span></label>';
        }
        $h .= '</div>'
            . '<label class="col-mes__campo col-mes__campo--ancho"><span>Fase</span>' . UI::selectDS('fase', Colorimetria::FASES, $fila['ficha']['fase'], ['aria' => 'Fase']) . '</label>'
            . '<label class="col-mes__campo col-mes__campo--ancho"><span>Nota del mes <small>(opcional)</small></span><span class="mc-input mc-input--multiline"><textarea class="mc-input__control" name="nota" rows="2" maxlength="1000" placeholder="De dónde salen los valores, qué pasó en el mes…">'
            . e((string)(($perMan === $per ? ($med['nota'] ?? '') : ''))) . '</textarea></span></label>'
            . '<footer><button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cerrar</button><button class="btn-primary btn-meca">' . UI::iconoDS('check', 16) . ' Guardar los valores de ' . e($mes) . '</button></footer>'
            . '</form></details>';
    }
    return $h . '</div></dialog>';
}
