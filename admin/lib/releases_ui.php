<?php
/**
 * Pintado de Releases que comparten releases.php y la pestaña «Release» del
 * proyecto (proyecto.php): las ayudas (chips, barras, anillos, fechas…) y la
 * vista de un release (rlVistaRelease): su cabecera con el avance, los
 * filtros y el Gantt o la lista de ítems. Al pulsar un ítem, assets/releases.js
 * abre su panel (releases.php?v=item&parcial=1).
 */
require_once __DIR__ . '/Releases.php';

/* ============================== Ayudas de pintado ============================== */

const RL_MES  = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
const RL_MESL = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
const RL_DIA  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

/** Tono del DS (gris, azul…) → variante de mc-status. */
function rlTono(string $t): string
{
    return ['gris' => 'neutral', 'azul' => 'azul', 'cian' => 'cian', 'petroleo' => 'petroleo', 'verde' => 'success', 'rojo' => 'danger', 'amarillo' => 'warning'][$t] ?? 'neutral';
}
function rlChip(string $texto, string $tono, string $extra = ''): string
{
    return '<span class="mc-status mc-status--sm mc-status--' . rlTono($tono) . ($extra !== '' ? ' ' . $extra : '') . '"><span class="mc-status__dot"></span>' . e($texto) . '</span>';
}
function rlEstado(string $k): string { [$l, $t] = Releases::ESTADOS[$k] ?? Releases::ESTADOS['por_iniciar']; return rlChip($l, $t); }
function rlSalud(string $k): string { [$l, $t] = Releases::SALUD[$k] ?? ['Sin datos', 'gris']; return rlChip($l, $t); }
function rlResultado(string $k): string { [$l, $t] = Releases::RESULTADOS[$k] ?? Releases::RESULTADOS['pendiente']; return rlChip($l, $t); }
/** 15/11 o 15/11/2026 */
function rlF(?string $f, bool $anio = false): string
{
    if (!$f) return '—';
    $t = strtotime($f);
    return $t ? date($anio ? 'd/m/Y' : 'd/m', $t) : '—';
}
/** «lunes 26 de octubre» (con el año si no es este, o si se pide) */
function rlFL(string $f, bool $dia = true, bool $anio = false): string
{
    $t = strtotime($f);
    if (!$t) return '—';
    $s = ($dia ? RL_DIA[(int)date('w', $t)] . ' ' : '') . (int)date('j', $t) . ' de ' . RL_MESL[(int)date('n', $t) - 1];
    return $s . ($anio || date('Y', $t) !== date('Y') ? ' de ' . date('Y', $t) : '');
}
/** «Vence en 3 días», «Venció hace 2 días»… */
function rlDias(?int $d, string $estado = ''): string
{
    if ($estado === 'cerrado') return 'Terminado';
    if ($d === null) return 'Sin fecha de fin';
    return match (true) {
        $d === 0 => 'Vence hoy',
        $d === 1 => 'Vence mañana',
        $d > 1 => 'Vence en ' . $d . ' días',
        $d === -1 => 'Venció ayer',
        default => 'Venció hace ' . abs($d) . ' días',
    };
}
/** Barra del % real con la marca del % esperado; el color, el de la salud. */
function rlBarra(int $pct, int $esp, string $salud, string $cls = ''): string
{
    $pct = max(0, min(100, $pct));
    $esp = max(0, min(100, $esp));
    return '<span class="rl-barra rl-s--' . e($salud) . ($cls !== '' ? ' ' . $cls : '') . '" role="img" aria-label="' . $pct . ' % de avance; esperado hoy ' . $esp . ' %">'
        . '<span class="rl-barra__fill" style="--p:' . $pct . '%"></span>'
        . ($esp > 0 ? '<span class="rl-barra__esp" style="--e:' . $esp . '%"></span>' : '') . '</span>';
}
/** Anillo del % con la marca del esperado (un trazo fino sobre el anillo). */
function rlAnillo(int $pct, int $esp, string $salud, int $px = 72): string
{
    $pct = max(0, min(100, $pct));
    $esp = max(0, min(100, $esp));
    return '<span class="rl-anillo rl-s--' . e($salud) . '" style="--sz:' . $px . 'px" role="img" aria-label="' . $pct . ' %; esperado ' . $esp . ' %">'
        . '<svg viewBox="0 0 36 36" aria-hidden="true"><circle class="rl-anillo__pista" cx="18" cy="18" r="15.9155"/>'
        . '<circle class="rl-anillo__fill" cx="18" cy="18" r="15.9155" stroke-dasharray="' . $pct . ' 100" transform="rotate(-90 18 18)"/>'
        . ($esp > 0 && $esp < 100 ? '<line class="rl-anillo__esp" x1="18" y1="0.2" x2="18" y2="5.4" transform="rotate(' . round($esp * 3.6, 1) . ' 18 18)"/>' : '')
        . '</svg><b class="mc-num">' . $pct . '<small>%</small></b></span>';
}
/** Salud de un grupo de ítems (una celda de la matriz, un release, un equipo). */
function rlSaludGrupo(array $r, string $hoy): string
{
    if (empty($r['total'])) return 'vacio';
    if ($r['cerrados'] >= $r['total']) return 'cerrado';
    if (($r['inicio'] ?? '') !== '' && $r['inicio'] > $hoy) return 'futuro';
    // Un grupo va según su % real frente a lo esperado; si alguno de sus ítems
    // está atrasado o bloqueado, el grupo queda al menos «en riesgo»
    $ratio = $r['esperado'] > 0 ? $r['pct'] / $r['esperado'] : 1;
    if ($ratio < 0.8) return 'atrasado';
    if ($ratio < 1 || $r['atrasados'] > 0 || $r['riesgo'] > 0) return 'riesgo';
    return 'al_dia';
}
function rlUrl(array $q): string
{
    $q = array_filter($q, fn($x) => $x !== '' && $x !== null && $x !== 0);
    return 'releases.php' . ($q ? '?' . http_build_query($q) : '');
}
function rlColorEq(array $eqs, string $n): string
{
    return (string)($eqs[$n]['color'] ?? Releases::COLORES_EQUIPO[$n] ?? '#475569');
}
function rlColorRel(array $rels, string $c): string
{
    if (isset($rels[$c])) return (string)$rels[$c]['color'];
    if (preg_match('/R\d+/', $c, $m) && isset($rels[$m[0]])) return (string)$rels[$m[0]]['color'];
    return '#64748B';
}
function rlTip(array $d): string
{
    return ' data-rl-tip="' . e(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"';
}
function rlTipItem(array $it): string
{
    $orig = (($it['inicio_original'] ?? '') !== '' && (($it['inicio_original'] ?? '') !== $it['inicio'] || ($it['fin_original'] ?? '') !== $it['fin']))
        ? 'Plan original: ' . rlF($it['inicio_original']) . ' → ' . rlF($it['fin_original']) : '';
    return rlTip(['c' => $it['codigo'], 't' => $it['titulo'], 'eq' => $it['equipo'], 'f' => rlF($it['inicio']) . ' → ' . rlF($it['fin'], true),
        'a' => (int)$it['avance'], 'e' => (int)$it['esperado'], 'es' => Releases::ESTADOS[$it['estado']][0], 'sa' => Releases::SALUD[$it['salud']][0],
        's' => $it['salud'], 'd' => rlDias($it['dias_restantes'], $it['estado']), 'n' => count($it['tareas']), 'o' => $orig]);
}
/** Código del ítem (o del release) con el color del release. */
function rlCode(string $codigo, string $color, string $cls = ''): string
{
    return '<span class="rl-code' . ($cls !== '' ? ' ' . $cls : '') . '" style="--rc:' . e($color) . '">' . e($codigo) . '</span>';
}
function rlEqChip(array $eqs, string $n, string $extra = ''): string
{
    return '<span class="rl-eq" style="--ec:' . e(rlColorEq($eqs, $n)) . '">' . e($n) . $extra . '</span>';
}
/** Una fila de ítem (alertas, equipo, cobertura): abre su detalle en el panel lateral. */
function rlFilaItem(array $it, array $eqs, array $rels, string $meta = '', string $chips = ''): string
{
    $meta = $meta !== '' ? $meta : rlDias($it['dias_restantes'], $it['estado']) . ' · fin ' . rlF($it['fin']);
    return '<a class="rl-fila" href="' . e(rlUrl(['v' => 'item', 'id' => (int)$it['id']])) . '" data-rl-item="' . (int)$it['id'] . '"' . rlTipItem($it) . '>'
        . rlCode($it['codigo'], rlColorRel($rels, $it['release']))
        . '<span class="rl-fila__txt"><b>' . e($it['titulo']) . '</b><small>' . rlEqChip($eqs, $it['equipo']) . '<span>' . e($meta) . '</span>' . $chips . '</small></span>'
        . '<span class="rl-fila__av"><span class="mc-num">' . (int)$it['avance'] . ' %</span>' . rlBarra((int)$it['avance'], (int)$it['esperado'], $it['salud']) . '</span>'
        . '</a>';
}
function rlIco(string $n, int $px = 18): string { return UI::iconoDS($n, $px); }
/**
 * Las dependencias de un ítem («R3-A03 producción · R1-G07 certificaciones ·
 * Flujo I-19»): cada parte con su ítem si el código existe, y los equipos de
 * otros ítems de los que depende (para el chip «lo entrega Beta»).
 */
function rlDependencias(string $txt, array $porCodigo, string $equipo): array
{
    $partes = [];
    $otros = [];
    foreach (preg_split('/\s*[·;\n]\s*/u', trim($txt)) ?: [] as $p) {
        if ($p === '' || $p === '—') continue;
        $item = null;
        $resto = $p;
        if (preg_match('/\b(R\d+-[A-Z0-9]+)\b/u', $p, $m) && isset($porCodigo[$m[1]])) {
            $item = $porCodigo[$m[1]];
            $resto = trim(str_replace($m[1], '', $p), " \t-–—:");
        }
        $otro = $item && $item['equipo'] !== $equipo && $item['equipo'] !== 'Todos';
        if ($otro) $otros[$item['equipo']] = true;
        $partes[] = ['texto' => $p, 'item' => $item, 'resto' => $resto, 'otro' => $otro];
    }
    // También cuando el texto nombra al equipo («lo entrega Beta»)
    foreach (['Alpha', 'Beta', 'Gamma', 'Delta'] as $n) if ($n !== $equipo && preg_match('/\b' . $n . '\b/u', $txt)) $otros[$n] = true;
    return ['partes' => $partes, 'equipos' => array_keys($otros)];
}
/** Interruptor del DS con su casilla real (la refleja assets/releases.js). */
function rlSwitch(string $name, bool $on, string $etiqueta, string $desc = '', string $valor = '1'): string
{
    static $n = 0;
    $id = 'rl-sw-' . (++$n);
    return '<div class="mc-switch mc-switch--sm' . ($on ? ' is-on' : '') . '" data-ds-switch>'
        . '<input type="checkbox" class="ds-switch-casilla" name="' . e($name) . '" value="' . e($valor) . '"' . ($on ? ' checked' : '') . ' tabindex="-1" aria-hidden="true">'
        . '<button type="button" role="switch" id="' . $id . '" aria-checked="' . ($on ? 'true' : 'false') . '" class="mc-switch__track"><span class="mc-switch__thumb">' . UI::checkDibujado(10) . '</span></button>'
        . '<span class="mc-switch__text"><label class="mc-switch__label" for="' . $id . '">' . e($etiqueta) . '</label>'
        . ($desc !== '' ? '<span class="mc-switch__desc">' . e($desc) . '</span>' : '') . '</span></div>';
}
/** Lista de casillas con buscador (proyectos, miembros, destinatarios…). $ops: [id => [texto, detalle]] */
function rlMulti(string $name, array $ops, array $sel, string $buscar = 'Buscar…', bool $deshabilitado = false): string
{
    $sel = array_flip(array_map('intval', $sel));
    $h = '<div class="rl-multi' . ($deshabilitado ? ' is-disabled' : '') . '" data-rl-multi>';
    if (count($ops) > 6) {
        $h .= '<div class="mc-input mc-input--sm rl-multi__buscar">' . UI::iconoDS('buscar', 16, 'mc-input__icon')
            . '<input class="mc-input__control" type="search" placeholder="' . e($buscar) . '" data-rl-multi-q aria-label="' . e($buscar) . '"' . ($deshabilitado ? ' disabled' : '') . '></div>';
    }
    $h .= '<div class="rl-multi__lista">';
    foreach ($ops as $id => [$txt, $det]) {
        $on = isset($sel[(int)$id]);
        $h .= '<label class="rl-multi__op' . ($on ? ' is-on' : '') . '" data-buscar="' . e(mb_strtolower($txt . ' ' . $det)) . '">'
            . '<input type="checkbox" name="' . e($name) . '" value="' . (int)$id . '"' . ($on ? ' checked' : '') . ($deshabilitado ? ' disabled' : '') . '>'
            . '<span class="rl-multi__caja" aria-hidden="true">' . UI::checkDibujado(11) . '</span>'
            . '<span class="rl-multi__txt"><b>' . e($txt) . '</b>' . ($det !== '' ? '<small>' . e($det) . '</small>' : '') . '</span></label>';
    }
    if (!$ops) $h .= '<p class="rl-multi__vacio">No hay opciones.</p>';
    return $h . '</div><p class="rl-multi__cuenta" data-rl-multi-n>' . count($sel) . ' elegido(s)</p></div>';
}
/**
 * Modalidad de una revisión: presencial (con su lugar) o virtual. Virtual: crear
 * la reunión de Zoom (o Meet, o un enlace, según el proyecto) o usar una que ya
 * existe. Lo de la reunión lo llena assets/releases.js con rel_reuniones_equipo.
 */
function rlModalidad(string $modo = 'presencial', string $lugar = '', string $equipo = '', string $fecha = '', bool $conReunion = false): string
{
    $v = $modo === 'virtual';
    return '<div class="rl-modalidad" data-rl-modalidad data-equipo="' . e($equipo) . '" data-fecha="' . e($fecha) . '">'
        . '<span class="mc-field__label">Modalidad</span>'
        . '<div class="rl-seg rl-seg--modo" role="radiogroup" aria-label="Modalidad">'
        . '<label class="rl-seg__op rl-t--petroleo"><input type="radio" name="modalidad" value="presencial"' . ($v ? '' : ' checked') . '><span><i class="fa-solid fa-location-dot" aria-hidden="true"></i>Presencial</span></label>'
        . '<label class="rl-seg__op rl-t--azul"><input type="radio" name="modalidad" value="virtual"' . ($v ? ' checked' : '') . '><span>' . rlIco('video', 15) . 'Virtual</span></label></div>'
        . '<div class="rl-modalidad__panel" data-rl-mod="presencial"' . ($v ? ' hidden' : '') . '>'
        . rlCampo('Lugar', rlInput('name="lugar" maxlength="300" placeholder="Sala de reuniones, edificio, piso…" value="' . e($lugar) . '"')) . '</div>'
        . '<div class="rl-modalidad__panel" data-rl-mod="virtual"' . ($v ? '' : ' hidden') . '>'
        . ($conReunion ? '<p class="rl-dlg__nota">' . rlIco('info', 15) . ' <span>Ya tiene una reunión enlazada. Elige otra solo si quieres cambiarla.</span></p>' : '')
        . '<div data-rl-reu-aviso></div>'
        . '<div class="rl-seg rl-seg--reu" role="radiogroup" aria-label="Qué reunión">'
        . '<label class="rl-seg__op rl-t--azul"><input type="radio" name="rl_reu" value="crear"' . ($conReunion ? '' : ' checked') . '><span>' . rlIco('mas', 15) . '<b data-rl-reu-crear-lbl>Crear la reunión de Zoom</b></span></label>'
        . '<label class="rl-seg__op rl-t--azul"><input type="radio" name="rl_reu" value="existente"' . ($conReunion ? ' checked' : '') . '><span>' . rlIco('relacionar', 15) . 'Usar una reunión que ya existe</span></label></div>'
        . '<div class="rl-modalidad__sub" data-rl-reu="crear"' . ($conReunion ? ' hidden' : '') . '>'
        . '<input type="hidden" name="crear_reunion" value="1">'
        . '<div class="rl-form-grid rl-form-grid--2">'
        . rlCampo('Proyecto donde se crea', '<select name="proyecto_id" data-ds-select data-rl-reu-proy><option value="">Cargando…</option></select>')
        . rlCampo('Duración', '<div class="mc-input"><input class="mc-input__control" name="duracion" type="number" min="15" max="480" step="5" inputmode="numeric" placeholder="Según la hora"><span class="mc-input__affix">min</span></div>')
        . '</div>'
        . '<div data-rl-reu-enlace hidden>' . rlCampo('Enlace de la reunión', rlInput('name="join_url" type="url" placeholder="https://…"')) . '</div>'
        . rlCampo('Invitados', '<div data-rl-reu-inv><p class="rl-multi__vacio">Cargando…</p></div>', 'Les llega la invitación con el enlace y queda en su calendario.')
        . '</div>'
        . '<div class="rl-modalidad__sub" data-rl-reu="existente"' . ($conReunion ? '' : ' hidden') . '>'
        . rlCampo('Reunión de ese día', '<select name="reunion_existente" data-ds-select data-rl-reu-lista><option value="">Cargando…</option></select>') . '</div>'
        . '</div></div>';
}
function rlCampo(string $etiqueta, string $control, string $ayuda = '', string $cls = ''): string
{
    return '<div class="mc-field' . ($cls !== '' ? ' ' . $cls : '') . '"><label class="mc-field__label">' . e($etiqueta) . '</label>' . $control
        . ($ayuda !== '' ? '<div class="mc-field__foot"><span class="mc-field__hint">' . $ayuda . '</span></div>' : '') . '</div>';
}
function rlInput(string $attrs, string $tam = 'md'): string
{
    return '<div class="mc-input mc-input--' . $tam . '"><input class="mc-input__control" ' . $attrs . '></div>';
}
function rlTexto(string $name, string $valor, int $filas = 3, string $ph = ''): string
{
    return '<div class="mc-input mc-input--multiline"><textarea class="mc-input__control rl-textarea" name="' . e($name) . '" rows="' . $filas . '"'
        . ($ph !== '' ? ' placeholder="' . e($ph) . '"' : '') . '>' . e($valor) . '</textarea></div>';
}

/**
 * La vista de un release: el avance (anillo), los filtros por equipo, estado y
 * salud, y los ítems en Gantt o en lista. La usan releases.php (todos los
 * equipos) y la pestaña «Release» del proyecto (fijo en su equipo).
 * $c: rels, eqs, todos, miembros, hoy, rc (código del release), equipo,
 * estado, salud, q, url (fn(array $p): string; p = r, e, estado, salud, q),
 * buscar ([acción, fn($base): ocultos, nombre del campo]), fijo, clicable.
 */
function rlVistaRelease(array $c): void
{

    ['rels' => $rels, 'eqs' => $eqs, 'todos' => $todos, 'miembros' => $miembros, 'hoy' => $hoy, 'rc' => $rc, 'url' => $url] = $c;
    $fEq = (string)($c['equipo'] ?? '');
    $fEst = (string)($c['estado'] ?? '');
    $fSal = (string)($c['salud'] ?? '');
    $q = (string)($c['q'] ?? '');
    $fijo = !empty($c['fijo']);          // dentro del proyecto: solo su equipo, sin elegir otro
    $clicable = $c['clicable'] ?? true;  // al pulsar un ítem se abre su panel
    if ($fijo) $todos = array_values(array_filter($todos, fn($it) => $it['equipo'] === $fEq));
    $R = $rels[$rc];
    $delRel = array_values(array_filter($todos, fn($it) => $it['release'] === $rc));
    $res = Releases::resumen($delRel);
    $salR = rlSaludGrupo($res, $hoy);
    $lista = Releases::items(array_filter(['release' => $rc, 'equipo' => $fEq, 'estado' => $fEst, 'salud' => $fSal, 'q' => $q]));
    $base = ['r' => $rc, 'e' => $fEq, 'estado' => $fEst, 'salud' => $fSal, 'q' => $q];
    $eqsRel = [];
    foreach ($delRel as $it) $eqsRel[$it['equipo']][] = $it;
    uksort($eqsRel, fn($a, $b) => ((int)($eqs[$a]['orden'] ?? 99)) <=> ((int)($eqs[$b]['orden'] ?? 99)));
    // El avance de cada release (en el proyecto, el de su equipo; si no, el de todos)
    $tabRel = [];
    foreach ($rels as $cod => $r) {
        $deCod = array_filter($todos, fn($it) => $it['release'] === $cod);
        if ($fijo && !$deCod) continue;
        $tabRel[$cod] = Releases::resumen($deCod);
    }
    $ant = ($R['fin_anterior'] ?? '') !== '' && $R['fin_anterior'] !== $R['fin'];
    $hayFiltro = (!$fijo && $fEq !== '') || $fEst !== '' || $fSal !== '' || $q !== '';
?>
  <nav class="rl-relnav" aria-label="Elegir el release">
    <?php foreach ($rels as $cod => $r): if (!isset($tabRel[$cod])) continue; $x = $tabRel[$cod]; ?>
    <a class="rl-relpill<?= $cod === $rc ? ' is-active' : '' ?>" href="<?= e($url(['r' => $cod, 'e' => $fEq])) ?>" style="--rc:<?= e($r['color']) ?>;--p:<?= (int)$x['pct'] ?>%"<?= $cod === $rc ? ' aria-current="page"' : '' ?>>
      <b><?= e($cod) ?></b><small class="mc-num"><?= (int)$x['pct'] ?> %</small><i aria-hidden="true"></i>
    </a>
    <?php endforeach; ?>
  </nav>

  <section class="mc-card rl-hero" style="--rc:<?= e($R['color']) ?>">
    <div class="rl-hero__main">
      <div class="rl-hero__fila">
        <?= rlCode($rc, $R['color'], 'rl-code--lg') ?>
        <?= rlChip(['por_iniciar' => 'Por iniciar', 'en_curso' => 'En curso', 'cerrado' => 'Cerrado'][$R['estado'] ?? 'por_iniciar'] ?? 'Por iniciar',
                   ['por_iniciar' => 'gris', 'en_curso' => 'azul', 'cerrado' => 'verde'][$R['estado'] ?? 'por_iniciar'] ?? 'gris') ?>
        <?php if ($res['total']) echo rlSalud($salR === 'vacio' ? 'futuro' : $salR); ?>
      </div>
      <h2 class="rl-hero__tit font-display"><?= e($R['nombre'] ?? $rc) ?></h2>
      <p class="rl-hero__meta">
        <span><?= rlIco('agenda', 16) ?> <?= e(rlF($R['inicio'] ?? '', true)) ?> → <?= e(rlF($R['fin'] ?? '', true)) ?></span>
        <?php if (($R['sprints'] ?? '') !== ''): ?><span><?= rlIco('racha', 16) ?> <?= e($R['sprints']) ?></span><?php endif; ?>
        <?php if ($ant): ?><span class="rl-hero__ant" data-ds-tip="Antes del reajuste terminaba el <?= e(rlF($R['fin_anterior'], true)) ?>"><?= rlIco('reloj', 16) ?> Reajustado (antes <?= e(rlF($R['fin_anterior'], true)) ?>)</span><?php endif; ?>
      </p>
      <?php if (($R['modulos'] ?? '') !== '' || ($R['descripcion'] ?? '') !== ''): ?>
      <p class="rl-hero__desc"><?= e(($R['descripcion'] ?? '') !== '' ? $R['descripcion'] : $R['modulos']) ?></p>
      <?php endif; ?>
      <?php if (!$fijo): ?>
      <div class="rl-hero__eqs" aria-label="Equipos del release">
        <?php foreach ($eqsRel as $nom => $l): $rq = Releases::resumen($l); $s = rlSaludGrupo($rq, $hoy); ?>
        <a class="rl-eqpill rl-s--<?= e($s) ?><?= $fEq === $nom ? ' is-active' : '' ?>" style="--ec:<?= e(rlColorEq($eqs, $nom)) ?>" href="<?= e($url(array_replace($base, ['e' => $fEq === $nom ? '' : $nom]))) ?>">
          <span class="rl-eqpill__dot"></span><b><?= e($nom) ?></b><span class="mc-num"><?= (int)$rq['pct'] ?> %</span><small><?= count($l) ?> ítems</small>
        </a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="rl-hero__lado">
      <?= rlAnillo((int)$res['pct'], (int)$res['esperado'], $salR === 'vacio' ? 'futuro' : $salR, 112) ?>
      <p class="rl-hero__esp">esperado hoy <b class="mc-num"><?= (int)$res['esperado'] ?> %</b></p>
      <dl class="rl-hero__nums">
        <div><dt>Ítems</dt><dd class="mc-num"><?= (int)$res['total'] ?></dd></div>
        <div><dt>Terminados</dt><dd class="mc-num"><?= (int)$res['cerrados'] ?></dd></div>
        <div class="is-mal"><dt>Atrasados</dt><dd class="mc-num"><?= (int)$res['atrasados'] ?></dd></div>
        <div class="is-riesgo"><dt>En riesgo</dt><dd class="mc-num"><?= (int)$res['riesgo'] ?></dd></div>
      </dl>
    </div>
  </section>

  <div class="rl-ia-slot" data-rl-ia-slot hidden></div>

  <section class="rl-filtros" aria-label="Filtros">
    <?php if (!$fijo): ?>
    <div class="rl-filtros__fila">
      <span class="rl-filtros__rot">Equipo</span>
      <div class="rl-chips">
        <a class="rl-chip<?= $fEq === '' ? ' is-on' : '' ?>" href="<?= e($url(array_replace($base, ['e' => '']))) ?>">Todos</a>
        <?php foreach ($eqsRel as $nom => $l): ?>
        <a class="rl-chip<?= $fEq === $nom ? ' is-on' : '' ?>" style="--ec:<?= e(rlColorEq($eqs, $nom)) ?>" href="<?= e($url(array_replace($base, ['e' => $fEq === $nom ? '' : $nom]))) ?>"><i class="rl-chip__dot"></i><?= e($nom) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <div class="rl-filtros__fila">
      <span class="rl-filtros__rot">Estado</span>
      <div class="rl-chips">
        <?php foreach (Releases::ESTADOS as $k => [$lbl, $tono]): ?>
        <a class="rl-chip rl-t--<?= e($tono) ?><?= $fEst === $k ? ' is-on' : '' ?>" href="<?= e($url(array_replace($base, ['estado' => $fEst === $k ? '' : $k]))) ?>"><i class="rl-chip__dot"></i><?= e($lbl) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="rl-filtros__fila">
      <span class="rl-filtros__rot">Salud</span>
      <div class="rl-chips">
        <?php foreach (Releases::SALUD as $k => [$lbl, $tono]): ?>
        <a class="rl-chip rl-t--<?= e($tono) ?><?= $fSal === $k ? ' is-on' : '' ?>" href="<?= e($url(array_replace($base, ['salud' => $fSal === $k ? '' : $k]))) ?>"><i class="rl-chip__dot"></i><?= e($lbl) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="rl-filtros__fila rl-filtros__fila--fin">
      <?php [$fAccion, $fOcultos, $fCampo] = $c['buscar']; ?>
      <form method="get" action="<?= e($fAccion) ?>" class="rl-buscar">
        <?php foreach (array_filter($fOcultos($base)) as $k => $x): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($x) ?>"><?php endforeach; ?>
        <div class="mc-input mc-input--sm"><?= UI::iconoDS('buscar', 16, 'mc-input__icon') ?>
          <input class="mc-input__control" type="search" name="<?= e($fCampo) ?>" value="<?= e($q) ?>" placeholder="Buscar por código, título o microservicio…" aria-label="Buscar ítems"></div>
      </form>
      <?php if ($hayFiltro): ?><a class="mc-btn mc-btn--ghost mc-btn--sm" href="<?= e($url(['r' => $rc, 'e' => $fijo ? $fEq : ''])) ?>"><?= rlIco('cerrar', 16) ?><span class="mc-btn__label">Quitar filtros</span></a><?php endif; ?>
      <span class="rl-filtros__n"><?= count($lista) ?> de <?= count($delRel) ?> ítems</span>
      <div class="mc-tabs mc-tabs--segmented mc-tabs--sm rl-modo" role="tablist" aria-label="Cómo verlos">
        <button type="button" class="mc-tab is-active" role="tab" aria-selected="true" data-rl-modo="gantt"><?= rlIco('ruta', 16) ?><span>Gantt</span></button>
        <button type="button" class="mc-tab" role="tab" aria-selected="false" data-rl-modo="lista"><?= rlIco('menu', 16) ?><span>Lista</span></button>
      </div>
    </div>
  </section>

<?php
    if (!$lista):
        echo UI::vacioDS('nexo:pensando', 'Ningún ítem con esos filtros', 'Prueba con otro equipo, estado o búsqueda.',
            '<a class="mc-btn mc-btn--secondary" href="' . e($url(['r' => $rc, 'e' => $fijo ? $fEq : ''])) . '"><span class="mc-btn__label">Quitar filtros</span></a>');
    else:
        // Ventana del Gantt: la del release y la de todos sus ítems, con un respiro
        $gi = $R['inicio'] ?? ''; $gf = $R['fin'] ?? '';
        foreach ($delRel as $it) {
            if (($it['inicio'] ?? '') !== '' && ($gi === '' || $it['inicio'] < $gi)) $gi = $it['inicio'];
            if (($it['fin'] ?? '') !== '' && $it['fin'] > $gf) $gf = $it['fin'];
            if (($it['inicio_original'] ?? '') !== '' && $it['inicio_original'] < $gi) $gi = $it['inicio_original'];
            if (($it['fin_original'] ?? '') !== '' && $it['fin_original'] > $gf) $gf = $it['fin_original'];
        }
        $g0 = strtotime($gi . ' -4 days');
        $g1 = strtotime($gf . ' +12 days');
        $gpos = fn(string $f) => round((strtotime($f) - $g0) / max(1, $g1 - $g0) * 100, 3);
        $semanas = [];
        $t = strtotime('monday this week', $g0);
        for (; $t < $g1; $t = strtotime('+7 days', $t)) if ($t > $g0) $semanas[] = $t;
        $mesesG = [];
        for ($t = strtotime(date('Y-m-01', $g0)); $t < $g1; $t = strtotime('+1 month', $t)) $mesesG[] = $t;
        $porEq = [];
        foreach ($lista as $it) $porEq[$it['equipo']][] = $it;
        uksort($porEq, fn($a, $b) => ((int)($eqs[$a]['orden'] ?? 99)) <=> ((int)($eqs[$b]['orden'] ?? 99)));
?>
  <section class="mc-card rl-card rl-card--flush" data-rl-modo-panel="gantt">
    <div class="rl-gantt-scroll">
      <div class="rl-gantt">
        <div class="rl-gantt__eje">
          <span class="rl-gantt__lbl rl-gantt__lbl--eje">Ítem</span>
          <span class="rl-gantt__pista">
            <?php foreach ($mesesG as $t): $x = max(0, $gpos(date('Y-m-d', $t))); ?>
            <span class="rl-gantt__mes" style="left:<?= $x ?>%"><?= e(RL_MESL[(int)date('n', $t) - 1]) ?></span>
            <?php endforeach; ?>
            <?php foreach ($semanas as $t): ?><span class="rl-gantt__sem mc-num" style="left:<?= $gpos(date('Y-m-d', $t)) ?>%"><?= (int)date('j', $t) ?></span><?php endforeach; ?>
          </span>
        </div>
        <div class="rl-gantt__cuerpo">
          <div class="rl-gantt__grid" aria-hidden="true">
            <?php foreach ($semanas as $t): ?><span style="left:<?= $gpos(date('Y-m-d', $t)) ?>%"></span><?php endforeach; ?>
            <?php if (($R['inicio'] ?? '') !== ''): ?><span class="is-rel" style="left:<?= $gpos($R['inicio']) ?>%"></span><?php endif; ?>
            <?php if (($R['fin'] ?? '') !== ''): ?><span class="is-rel" style="left:<?= $gpos(date('Y-m-d', strtotime($R['fin'] . ' +1 day'))) ?>%"></span><?php endif; ?>
            <?php $xh = $gpos($hoy); if ($xh >= 0 && $xh <= 100): ?><i class="rl-hoy" style="left:<?= $xh ?>%"><b>Hoy</b></i><?php endif; ?>
          </div>
          <?php foreach ($porEq as $nom => $l): $rq = Releases::resumen($eqsRel[$nom] ?? $l); $s = rlSaludGrupo($rq, $hoy); $sm = $miembros[(int)($eqs[$nom]['sm_id'] ?? 0)] ?? null; ?>
          <div class="rl-gantt__grupo" style="--ec:<?= e(rlColorEq($eqs, $nom)) ?>">
            <<?= $fijo ? 'span' : 'a href="' . e(rlUrl(['v' => 'equipo', 'e' => $nom])) . '"' ?> class="rl-gantt__lbl rl-gantt__eq">
              <span class="rl-matriz__dot"></span><b><?= e($nom) ?></b><small><?= e($sm['nombre'] ?? ($eqs[$nom]['sm_nombre'] ?? '')) ?></small>
            </<?= $fijo ? 'span' : 'a' ?>>
            <span class="rl-gantt__pista rl-gantt__eqres"><span class="mc-num"><?= (int)$rq['pct'] ?> %</span><?= rlBarra((int)$rq['pct'], (int)$rq['esperado'], $s) ?><small><?= (int)$rq['cerrados'] ?> de <?= (int)$rq['total'] ?> terminados</small></span>
          </div>
            <?php foreach ($l as $it):
                $x0 = $gpos($it['inicio'] ?: ($R['inicio'] ?? $gi));
                $x1 = $gpos(date('Y-m-d', strtotime(($it['fin'] ?: ($R['fin'] ?? $gf)) . ' +1 day')));
                $w = max(1.4, $x1 - $x0);
                $orig = ($it['inicio_original'] ?? '') !== '' && (($it['inicio_original'] ?? '') !== $it['inicio'] || ($it['fin_original'] ?? '') !== $it['fin']);
                $o0 = $orig ? $gpos($it['inicio_original']) : 0;
                $ow = $orig ? max(1.4, $gpos(date('Y-m-d', strtotime(($it['fin_original'] ?: $it['fin']) . ' +1 day'))) - $o0) : 0; ?>
          <<?= $clicable ? 'a href="' . e(rlUrl(['v' => 'item', 'id' => (int)$it['id']])) . '" data-rl-item="' . (int)$it['id'] . '"' : 'div' ?> class="rl-gfila<?= $clicable ? '' : ' is-fija' ?>"<?= rlTipItem($it) ?>>
            <span class="rl-gantt__lbl rl-gfila__lbl"><?= rlCode($it['codigo'], $R['color']) ?><span class="rl-gfila__tit"><?= e($it['titulo']) ?></span></span>
            <span class="rl-gantt__pista">
              <?php if ($orig): ?><span class="rl-gbar__orig" style="left:<?= $o0 ?>%;width:<?= $ow ?>%"></span><?php endif; ?>
              <span class="rl-gbar rl-s--<?= e($it['salud']) ?>" style="left:<?= $x0 ?>%;width:<?= $w ?>%">
                <span class="rl-gbar__fill" style="width:<?= (int)$it['avance'] ?>%"></span>
                <?php if ($it['esperado'] > 0 && $it['esperado'] < 100 && $it['estado'] !== 'cerrado'): ?><span class="rl-gbar__esp" style="left:<?= (int)$it['esperado'] ?>%"></span><?php endif; ?>
                <span class="rl-gbar__txt mc-num"><?= $it['estado'] === 'cerrado' ? '✓' : ($it['estado'] === 'bloqueado' ? rlIco('candado', 13) . ' ' : '') . (int)$it['avance'] . ' %' ?></span>
              </span>
            </span>
          </<?= $clicable ? 'a' : 'div' ?>>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <ul class="rl-leyenda rl-leyenda--pie">
      <li><i class="rl-ley rl-ley--fill"></i>Avance real</li>
      <li><i class="rl-ley rl-ley--esp"></i>Esperado hoy</li>
      <li><i class="rl-ley rl-ley--ant"></i>Fechas originales (antes del reajuste)</li>
      <?php foreach (['al_dia' => 'Al día', 'riesgo' => 'En riesgo', 'atrasado' => 'Atrasado o vencido', 'futuro' => 'Aún no inicia'] as $k => $lbl): ?>
      <li><i class="rl-ley rl-ley--s rl-s--<?= $k ?>"></i><?= e($lbl) ?></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <section class="mc-table rl-tabla" data-rl-modo-panel="lista" hidden>
    <div class="mc-table__scroll">
      <table class="mc-table__table">
        <thead><tr><th>Ítem</th><th>Equipo</th><th>Fechas</th><th>Estado</th><th>Avance</th><th>Salud</th><th class="is-center">Tareas</th></tr></thead>
        <tbody>
        <?php foreach ($lista as $i => $it): ?>
          <tr class="mc-table__row-in<?= $clicable ? ' is-clickable' : '' ?>" style="--i:<?= min($i, 12) ?>"<?= $clicable ? ' data-rl-item="' . (int)$it['id'] . '" tabindex="0"' : '' ?>>
            <td class="is-primary" data-label="Ítem"><div class="mc-table__cell rl-tabla__item"><?= rlCode($it['codigo'], $R['color']) ?>
              <?php if ($clicable): ?><a href="<?= e(rlUrl(['v' => 'item', 'id' => (int)$it['id']])) ?>"><?= e($it['titulo']) ?></a><?php else: ?><span><?= e($it['titulo']) ?></span><?php endif; ?></div></td>
            <td data-label="Equipo"><div class="mc-table__cell"><?= rlEqChip($eqs, $it['equipo']) ?></div></td>
            <td data-label="Fechas"><div class="mc-table__cell rl-tabla__fechas"><span class="mc-num"><?= e(rlF($it['inicio'])) ?> → <?= e(rlF($it['fin'])) ?></span><small><?= e(rlDias($it['dias_restantes'], $it['estado'])) ?></small></div></td>
            <td data-label="Estado"><div class="mc-table__cell"><?= rlEstado($it['estado']) ?></div></td>
            <td data-label="Avance"><div class="mc-table__cell rl-tabla__av"><b class="mc-num"><?= (int)$it['avance'] ?> %</b><?= rlBarra((int)$it['avance'], (int)$it['esperado'], $it['salud']) ?></div></td>
            <td data-label="Salud"><div class="mc-table__cell"><?= rlSalud($it['salud']) ?></div></td>
            <td data-label="Tareas" class="is-center"><div class="mc-table__cell"><span class="rl-n<?= !$it['tareas'] && $it['estado'] !== 'cerrado' ? ' is-cero' : '' ?>"><?= count($it['tareas']) ?></span></div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endif; ?>
<?php
}

/**
 * Lo que assets/releases.js necesita en la página: sus datos (#rl-datos), los
 * íconos que pinta (#rl-iconos) y el propio script. En releases.php y en la
 * pestaña «Release» del proyecto.
 */
function rlRecursosJs(string $vista, bool $editor, array $miembros): string
{
    $datos = json_encode([
        'hoy' => date('Y-m-d'), 'vista' => $vista, 'editor' => $editor,
        'estados' => Releases::ESTADOS, 'resultados' => Releases::RESULTADOS, 'salud' => Releases::SALUD,
        'miembros' => array_values(array_map(fn($m) => ['id' => (int)$m['id'], 'nombre' => (string)$m['nombre']], $miembros)),
        'prioridades' => array_map(fn($p) => $p[0], Catalogo::prioridades()),
        'ia' => UI::ia(), 'admin' => Auth::esAdmin(),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    $iconos = '';
    foreach (['ia', 'check', 'cerrar', 'alerta', 'info', 'exito', 'error', 'relacionar', 'mas', 'agenda', 'reloj', 'editar', 'completar', 'ver', 'documento', 'usuarios', 'ruta', 'bandera', 'copiar', 'reintentar', 'importar', 'datos', 'chevron-der', 'buscar', 'video'] as $i) {
        $iconos .= '<span data-i="' . $i . '">' . rlIco($i, 18) . '</span>';
    }
    return '<script type="application/json" id="rl-datos">' . $datos . '</script>'
        . '<template id="rl-iconos">' . $iconos . '</template>'
        . '<script src="' . asset('assets/releases.js') . '" defer></script>';
}
