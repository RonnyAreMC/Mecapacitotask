<?php
/**
 * Piezas compartidas de los requerimientos sueltos.
 *
 * Las usan las DOS pantallas: el modulo del administrador
 * (requerimientos.php), donde se reparten, y la bandeja del equipo
 * (bandeja.php), donde cada quien ve los suyos. Estan aqui para que una fila
 * signifique lo mismo en las dos y no haya que arreglar cada cosa dos veces.
 */
require_once __DIR__ . '/../core/Models.php';

/**
 * En que situacion esta un requerimiento. No es el estado crudo: es lo que
 * dice si alguien tiene que hacer algo con el.
 */
function situacionDe(array $r): string
{
    if (RequerimientoRepo::cerrado($r)) return 'cerrados';
    return RequerimientoRepo::asignadosDe($r) ? 'en-curso' : 'sin-asignar';
}

/**
 * El plazo en cristiano. Una fecha suelta ("2026-08-26") obliga a restar
 * mentalmente para saber si algo corre prisa; esto lo dice ya restado.
 * Devuelve [texto, nivel, detalle para el title].
 */
function plazoDe(array $r): array
{
    $ini = RequerimientoRepo::fechaInicio($r);
    $fin = RequerimientoRepo::fechaFin($r);
    $rango = match (true) {
        $ini !== '' && $fin !== '' => 'Del ' . $ini . ' al ' . $fin,
        $fin !== ''                => 'Entrega el ' . $fin,
        $ini !== ''                => 'Empieza el ' . $ini,
        default                    => 'Nadie le puso fechas',
    };
    // Cancelado: ya no hay plazo que contar
    if (($r['estado'] ?? '') === 'cancelado') {
        return ['Cancelado', 'cerrado', $rango];
    }
    if ($fin === '') {
        return [$ini !== '' ? 'Desde ' . $ini : 'Sin plazo', 'sin', $rango];
    }
    // Cerrado: el plazo ya no corre, solo se recuerda cual era
    if (RequerimientoRepo::cerrado($r)) {
        return [$fin, 'cerrado', $rango];
    }
    $dias = (int)round((strtotime($fin) - strtotime(date('Y-m-d'))) / 86400);
    return match (true) {
        $dias <   0 => [abs($dias) === 1 ? 'Vencido ayer' : 'Vencido hace ' . abs($dias) . ' días', 'vencido', $rango],
        $dias === 0 => ['Vence hoy',    'hoy',    $rango],
        $dias === 1 => ['Vence mañana', 'pronto', $rango],
        $dias <=  3 => ['Faltan ' . $dias . ' días', 'pronto', $rango],
        default     => ['Faltan ' . $dias . ' días', 'lejos',  $rango],
    };
}

/**
 * Catálogo de instituciones para pintar sus logos en las filas, sin cambiar la
 * firma de cada función: requerimientos.php lo fija una vez con reqUiInst($mapa).
 */
function reqUiInst(?array $set = null): array
{
    static $mapa = [];
    if ($set !== null) $mapa = $set;
    return $mapa;
}

/**
 * Una fila de la lista. Enseña lo justo para decidir sin abrirla: qué es,
 * quién lo hace (con nombres, no solo iniciales de colores), qué urgencia
 * tiene, cuánto queda de plazo y si tiene correcciones pendientes. El detalle,
 * las observaciones y las acciones viven en su pantalla (requerimiento.php).
 */
function filaRequerimiento(array $r, array $mapa, array $prioridades, array $estados): void
{
    $id     = (int)$r['id'];
    $estado = RequerimientoRepo::estadoValido($r['estado'] ?? '');
    [$etEstado, $icEstado] = $estados[$estado];
    $prio    = $r['prioridad'] ?? '';
    $ids     = RequerimientoRepo::asignadosDe($r);
    $gente   = array_values(array_filter(array_map(fn($i) => $mapa[$i] ?? null, $ids)));
    $ini     = RequerimientoRepo::fechaInicio($r);
    $fin     = RequerimientoRepo::fechaFin($r);
    $vencido = RequerimientoRepo::vencido($r);
    [$plazoTxt, $plazoNivel, $plazoDetalle] = plazoDe($r);
    $colab   = RequerimientoRepo::colaboradoresDe($r);

    // Nombres de pila: "Coddy, Dulce +1". Los avatares solos son iniciales de
    // colores y hay que pasar el ratón por encima para saber de quién son.
    $nombres = array_map(fn($m) => explode(' ', trim($m['nombre']))[0], $gente);
    $resumenGente = match (count($nombres)) {
        0       => '',
        1, 2    => implode(', ', $nombres),
        default => $nombres[0] . ', ' . $nombres[1] . ' +' . (count($nombres) - 2),
    };

    // Correcciones (observaciones con plazo) que siguen pendientes: se ven sin abrirlo
    $corr    = RequerimientoRepo::correccionesDe($r);
    $estTono = ['pendiente' => 'warning', 'asignado' => 'info', 'hecho' => 'success', 'cancelado' => 'danger', 'descartado' => 'neutral'][$estado] ?? 'neutral';
    $motivoCan = $estado === 'cancelado' ? trim((string)($r['motivo_cancelacion'] ?? '')) : '';
    $insts   = RequerimientoRepo::institucionesDe($r);
    $imapa   = reqUiInst();
    ?>
    <tr class="req-tr req-e-<?= e($estado) ?><?= $vencido ? ' req-tr-vencida' : '' ?>" data-req-id="<?= (int)$r['id'] ?>" tabindex="0" role="link"
        aria-label="Abrir «<?= e((string)$r['titulo']) ?>»" data-href="requerimiento.php?id=<?= (int)$r['id'] ?>">
      <td class="is-primary" data-label="Requerimiento"><div class="mc-table__cell req-c-titulo">
        <span class="req-c-ico req-ico-<?= e($estado) ?>"><i class="fa-solid <?= e($icEstado) ?>"></i></span>
        <span class="req-c-txt">
          <b class="truncate"><?= e($r['titulo']) ?></b>
          <?php if ($estado === 'cancelado'): ?>
          <small class="truncate req-c-cancelado" title="<?= e($motivoCan) ?>"><?= $motivoCan !== '' ? 'Cancelado: ' . e($motivoCan) : 'Cancelado' ?></small>
          <?php else: ?>
          <small class="truncate"><?= !empty($r['solicitante']) ? 'Lo pide ' . e($r['solicitante']) : 'Sin remitente anotado' ?></small>
          <?php endif; ?>
          <?php if ($corr['pendientes']): ?>
          <span class="rq-chip rq-chip--<?= $corr['vencidas'] ? 'mal' : 'pend' ?> req-c-corr" title="Correcciones pendientes<?= $corr['proxima'] !== '' ? ' · la próxima vence el ' . e(fechaLargaReq($corr['proxima'])) : '' ?>">
            <?= UI::icono('ds-reloj') ?> <?= $corr['pendientes'] ?> <?= $corr['pendientes'] === 1 ? 'corrección' : 'correcciones' ?><?= $corr['vencidas'] ? ' · ' . $corr['vencidas'] . ' vencida' . ($corr['vencidas'] === 1 ? '' : 's') : '' ?>
          </span>
          <?php endif; ?>
          <?php if ($insts): ?>
          <span class="req-f-inst">
            <?php foreach ($insts as $iid): if (!isset($imapa[$iid])) continue; $ii = $imapa[$iid]; ?>
            <span class="req-inst-chip" title="<?= e($ii['nombre']) ?>">
              <?php if (!empty($ii['imagen'])): ?><img src="<?= e($ii['imagen']) ?>" alt=""><?php else: ?><i class="fa-solid fa-building-columns"></i><?php endif; ?>
              <span class="truncate"><?= e($ii['nombre']) ?></span>
            </span>
            <?php endforeach; ?>
          </span>
          <?php endif; ?>
        </span>
      </div></td>
      <td data-label="Responsables"><div class="mc-table__cell req-c-gente" title="<?= $gente ? e(implode(', ', array_column($gente, 'nombre'))) : 'Todavía no tiene responsable' ?>">
        <?php if ($gente): ?>
          <?= UI::avatarStack($gente, 3, 26) ?><span class="truncate"><?= e($resumenGente) ?></span>
        <?php else: ?>
          <span class="req-libre"><i class="fa-solid fa-inbox"></i> Sin asignar</span>
        <?php endif; ?>
      </div></td>
      <td data-label="Fechas"><div class="mc-table__cell req-c-fechas" title="<?= e($plazoDetalle) ?>">
        <?= $ini !== '' ? e(fechaCortaReq($ini)) : '—' ?><span class="req-c-flecha" aria-hidden="true">→</span><?= $fin !== '' ? e(fechaCortaReq($fin)) : '—' ?>
      </div></td>
      <td data-label="Prioridad"><div class="mc-table__cell"><?= isset($prioridades[$prio]) ? UI::badgePrioridad($prio) : '—' ?></div></td>
      <td data-label="Plazo"><div class="mc-table__cell"><span class="req-plazo plazo-<?= e($plazoNivel) ?>"><?= e($plazoTxt) ?></span></div></td>
      <td data-label="Estado"><div class="mc-table__cell"><span class="mc-status mc-status--pill mc-status--<?= $estTono ?>"><span class="mc-status__content"><span class="mc-status__label"><?= e($etEstado) ?></span></span></span></div></td>
      <td class="req-c-mas" aria-hidden="true"><div class="mc-table__cell"><?= UI::iconoDS('chevron-der', 18) ?></div></td>
    </tr>
    <?php
}

/** «14 ago» (y el año si no es el actual). */
function fechaCortaReq(string $f): string
{
    $t = strtotime($f);
    if (!$t) return $f;
    $m = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'][(int)date('n', $t) - 1];
    return (int)date('j', $t) . ' ' . $m . (date('Y', $t) !== date('Y') ? ' ' . date('Y', $t) : '');
}

/** Cabecera de un bloque de la lista, con su cuenta. */
function bloqueRequerimientos(string $titulo, string $icono, array $items, array $mapa, array $prioridades, array $estados, string $ayuda = '', string $clase = ''): void
{
    if (!$items) return;
    ?>
    <section class="req-bloque <?= e($clase) ?>">
      <h2 class="req-titulo">
        <?= UI::icono($icono, "text-secondary") ?> <?= e($titulo) ?>
        <span class="tabla-count"><?= count($items) ?></span>
        <?php if ($ayuda !== ''): ?><small><?= e($ayuda) ?></small><?php endif; ?>
      </h2>
      <div class="mc-table req-tabla"><div class="mc-table__scroll">
        <table class="mc-table__table">
          <thead><tr><th scope="col">Requerimiento</th><th scope="col">Responsables</th><th scope="col">Inicio → entrega</th>
            <th scope="col">Prioridad</th><th scope="col">Plazo</th><th scope="col">Estado</th><th scope="col"><span class="mc-sr-only">Abrir</span></th></tr></thead>
          <tbody>
            <?php foreach ($items as $r) filaRequerimiento($r, $mapa, $prioridades, $estados); ?>
          </tbody>
        </table>
      </div></div>
    </section>
    <?php
}

/**
 * Orden de los equipos al repartir un requerimiento suelto: primero quienes
 * programan; diseño y procesos (analistas) también aparecen, pero abajo, para
 * cuando el pedido es de lo suyo. Un equipo creado en Ajustes que no esté aquí
 * va al final, con su nombre del catálogo.
 */
const REQ_GRUPOS = [
    'programacion' => [0, 'Programadores'],
    'disenadores'  => [1, 'Diseño'],
    'analistas'    => [2, 'Procesos y análisis'],
];

/** [orden, etiqueta] del equipo con el que se reparte a una persona (el de más prioridad de los suyos). */
function reqGrupoDe(array $m): array
{
    $cat = Catalogo::equipos();
    $mejor = [9, 'Sin equipo'];
    foreach (MiembroRepo::equiposDe($m) as $eq) {
        $g = REQ_GRUPOS[$eq] ?? [3, $cat[$eq][0] ?? ucfirst((string)$eq)];
        if ($g[0] < $mejor[0]) $mejor = $g;
    }
    return $mejor;
}

/**
 * Lo que se le ha asignado de requerimientos sueltos a cada persona, en total y
 * desde $desde ('Y-m-d'; '' = desde siempre), separado en abiertos, vencidos y
 * resueltos. La fecha que cuenta es la de la asignación (o la de alta, en los
 * viejos que no la guardaban). Es la base del reparto equitativo.
 */
function reqRepartoPorPersona(array $requerimientos, string $desde = ''): array
{
    $out = [];
    foreach ($requerimientos as $r) {
        $cuando = substr((string)(($r['asignado_en'] ?? '') ?: ($r['creado'] ?? '')), 0, 10);
        if ($desde !== '' && $cuando !== '' && $cuando < $desde) continue;
        $cerrado = RequerimientoRepo::cerrado($r);
        $resuelto = ($r['estado'] ?? '') === 'hecho';
        $vencido = !$cerrado && RequerimientoRepo::vencido($r);
        foreach (RequerimientoRepo::asignadosDe($r) as $mid) {
            $o = &$out[$mid];
            $o ??= ['total' => 0, 'abiertos' => 0, 'vencidos' => 0, 'resueltos' => 0];
            $o['total']++;
            if ($resuelto)      $o['resueltos']++;
            elseif ($vencido)   $o['vencidos']++;
            elseif (!$cerrado)  $o['abiertos']++;
            unset($o);
        }
    }
    return $out;
}

/**
 * Carga de cada persona y a quién conviene darle el siguiente requerimiento.
 *
 * El orden, de arriba abajo:
 *  1. El equipo: primero Programadores; Diseño y Procesos después. Antes salía
 *     arriba gente que no programa y nunca tiene tareas (marca 0), así que el
 *     «más libre» era justo quien no lo iba a hacer.
 *  2. Lo que tiene abierto AHORA (tareas de proyecto + requerimientos).
 *  3. A igual carga, a quien MENOS requerimientos sueltos se le asignaron en
 *     los últimos 90 días: así el reparto se empareja.
 *  4. Y luego quien lleva más tiempo sin entregar nada (sin esto, entre dos
 *     ceros mandaba el alfabeto y Aaron Bayas se llevaba siempre el siguiente).
 *
 * Cada persona sale con su recomendación: «Recomendado» (los 3 primeros de
 * Programadores con poca carga), «Disponible», «Carga media» o «Mucha carga».
 *
 * Devuelve [$carga, $opcionesEquipo]: la lista ordenada y los equipos para filtrar.
 */
function cargaParaAsignar(MiembroRepo $miembros, RequerimientoRepo $reqRepo, TareaRepo $tareasRepo): array
{
    $finales  = Catalogo::estadosFinales();
    $abiertas = [];
    $ultima   = [];      // [miembro_id => 'Y-m-d' de lo ultimo que entrego]
    $apuntar  = function (int $mid, string $fecha) use (&$ultima) {
        $fecha = substr(trim($fecha), 0, 10);
        if ($mid <= 0 || $fecha === '') return;
        if (!isset($ultima[$mid]) || $fecha > $ultima[$mid]) $ultima[$mid] = $fecha;
    };
    foreach ($tareasRepo->todas() as $t) {
        $cerrada = in_array($t['estado'] ?? '', $finales, true);
        foreach (TareaRepo::asignadosDe($t) as $mid) {
            if ($cerrada) $apuntar($mid, (string)($t['completada_en'] ?? ''));
            else          $abiertas[$mid] = ($abiertas[$mid] ?? 0) + 1;
        }
    }
    $todosReq = $reqRepo->todos();
    foreach ($todosReq as $rq) {
        if (!RequerimientoRepo::cerrado($rq)) continue;
        $cuando = (string)($rq['cerrado_en'] ?? '');
        // Lo cuenta quien lo marco terminado; si lo cerro el administrador desde
        // el modulo no queda 'cerrado_por', y entonces cuenta para sus responsables.
        $deQuien = (int)($rq['cerrado_por'] ?? 0)
            ? [(int)$rq['cerrado_por']]
            : RequerimientoRepo::asignadosDe($rq);
        foreach ($deQuien as $mid) $apuntar($mid, $cuando);
    }
    $recibidos = reqRepartoPorPersona($todosReq, date('Y-m-d', strtotime('-90 days')));

    $hoyCarga = new DateTimeImmutable('today');
    $carga = [];
    foreach ($miembros->todos() as $m) {
        $mid    = (int)$m['id'];
        $tareas = (int)($abiertas[$mid] ?? 0);
        $reqs   = $reqRepo->abiertosDe($mid);
        $suUltima = $ultima[$mid] ?? '';
        [$grupo, $equipo] = reqGrupoDe($m);
        $carga[] = [
            'miembro'   => $m,
            'rol'       => trim($m['rol'] ?? '') ?: 'Sin rol',
            'grupo'     => $grupo,
            'equipo'    => $equipo,
            'tareas'    => $tareas,
            'reqs'      => $reqs,
            'total'     => $tareas + $reqs,
            'recibidos' => (int)($recibidos[$mid]['total'] ?? 0),   // req. sueltos asignados en 90 días
            'ultima'    => $suUltima,
            // Dias desde la ultima entrega; null si no consta ninguna.
            'dias'      => $suUltima !== ''
                ? (int)$hoyCarga->diff(new DateTimeImmutable($suUltima))->days
                : null,
        ];
    }
    // La cadena vacía ordena antes que cualquier fecha: quien no tiene ninguna
    // entrega registrada sale primero entre los que empatan.
    usort($carga, fn($a, $b) => $a['grupo'] <=> $b['grupo']
        ?: $a['total'] <=> $b['total']
        ?: $a['recibidos'] <=> $b['recibidos']
        ?: strcmp($a['ultima'], $b['ultima'])
        ?: strcasecmp($a['miembro']['nombre'], $b['miembro']['nombre']));

    // Nivel de carga: relativo al más cargado, pero sin llamar «mucha carga» a
    // tener dos cosas cuando nadie tiene más de dos.
    $tope = $carga ? max(array_column($carga, 'total')) : 0;
    $primerGrupo = $carga ? $carga[0]['grupo'] : 0;
    $recomendados = 0;
    foreach ($carga as &$c) {
        $pct = $tope > 0 ? $c['total'] / $tope * 100 : 0;
        $c['pct']   = (int)round($pct);
        $c['nivel'] = ($pct >= 66 && $c['total'] >= 4) ? 'alta' : (($pct >= 33 && $c['total'] >= 2) ? 'media' : 'baja');
        if ($c['nivel'] === 'alta') {
            $c['reco'] = ['alta', 'Mucha carga'];
        } elseif ($c['nivel'] === 'media') {
            $c['reco'] = ['media', 'Carga media'];
        } elseif ($c['grupo'] === $primerGrupo && $recomendados < 3) {
            $c['reco'] = ['recomendado', 'Recomendado'];
            $recomendados++;
        } else {
            $c['reco'] = ['disponible', 'Disponible'];
        }
    }
    unset($c);

    // Equipos presentes, con cuánta gente hay en cada uno: son los filtros del
    // selector (del equipo real, para no ofrecer filtros que no seleccionan a nadie).
    $porEquipo = [];
    foreach ($carga as $c) {
        $porEquipo[$c['equipo']] = ($porEquipo[$c['equipo']] ?? 0) + 1;
    }
    $opciones = ['' => 'Todos (' . count($carga) . ')'];
    foreach ($porEquipo as $eq => $cuantos) {
        $opciones[$eq] = $eq . ' (' . $cuantos . ')';
    }
    return [$carga, $opciones];
}

/**
 * Paso "Responsables": el selector de personas con su carga delante. La misma
 * pieza en "nuevo requerimiento" y en "asignar" uno que ya existe, porque es
 * la misma decisión. El numero de cada quien es lo que ya tiene abierto y el
 * color dice quien esta libre, sin ir a comprobarlo a otra pantalla.
 *
 * $prefijo distingue los dos usos ('nr' y 'dv') para que el filtro de rol de
 * cada asistente no toque la lista del otro.
 */
function panelResponsables(array $carga, array $opcionesRol, string $prefijo, array $marcados = []): void
{
    // Los grupos por equipo, en el orden de la lista (ya viene ordenada)
    $grupos = [];
    foreach ($carga as $c) {
        $grupos[$c['equipo']][] = $c;
    }
    $sugeridos = array_values(array_filter($carga, fn($c) => ($c['reco'][0] ?? '') === 'recomendado'));
    $notas = [
        'Programadores'       => 'los recomendados primero',
        'Diseño'              => 'si el pedido es de diseño',
        'Procesos y análisis' => 'si el pedido es de procesos o análisis',
    ];
    ?>
    <section class="wz-panel">
      <div class="campo">
        <span>Responsables</span>
        <!-- Espejo de lo marcado, solo para que el paso de Revisión lo liste:
             el asistente resume campos, y una lista de casillas no lo es.
             Sin name, así que no se envía; los ids van en asignados[]. -->
        <input type="hidden" data-md="1" data-picker-resumen="<?= e($prefijo) ?>">

        <?php if ($sugeridos): ?>
        <div class="dv-sugeridos" data-sugeridos="<?= e($prefijo) ?>">
          <span class="dv-sugeridos__tit"><?= UI::iconoDS('estado-cumple', 16) ?> Sugeridos</span>
          <?php foreach ($sugeridos as $c): $m = $c['miembro']; ?>
          <button type="button" class="dv-sugerido" data-marcar="<?= (int)$m['id'] ?>"
                  title="<?= e($m['nombre']) ?>: <?= (int)$c['total'] ?> pendiente<?= $c['total'] === 1 ? '' : 's' ?>, <?= (int)$c['recibidos'] ?> requerimiento<?= $c['recibidos'] === 1 ? '' : 's' ?> en 90 días">
            <?= UI::avatar($m, 22) ?><span class="truncate"><?= e(explode(' ', trim($m['nombre']))[0]) ?></span>
          </button>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="dv-controles">
          <label class="dv-buscar">
            <?= UI::iconoDS('buscar', 16) ?>
            <input type="search" class="input-meca js-<?= e($prefijo) ?>-buscar" placeholder="Buscar por nombre…" autocomplete="off">
          </label>
          <label class="carga-filtro dv-filtro">
            <span>Equipo</span>
            <?= UI::select($prefijo . '_rol', $opcionesRol, '', false, 'js-' . $prefijo . '-rol') ?>
          </label>
        </div>

        <p class="dv-sinresultados" data-picker-vacio="<?= e($prefijo) ?>" hidden>Nadie coincide con la búsqueda.</p>

        <div class="dv-lista" data-picker="<?= e($prefijo) ?>">
          <?php foreach ($grupos as $equipo => $gente): ?>
          <div class="dv-grupo" data-dv-grupo>
            <p class="dv-grupo__tit"><b><?= e($equipo) ?></b><?php if (isset($notas[$equipo])): ?><small><?= e($notas[$equipo]) ?></small><?php endif; ?></p>
          <?php foreach ($gente as $c):
              $m = $c['miembro'];
              [$recoK, $recoT] = $c['reco'];
          ?>
          <label class="dv-persona carga-<?= e($c['nivel']) ?> dv-reco-<?= e($recoK) ?>" data-id="<?= (int)$m['id'] ?>"
                 data-rol="<?= e($c['equipo']) ?>" data-nombre="<?= e($m['nombre']) ?>">
            <input type="checkbox" name="asignados[]" value="<?= (int)$m['id'] ?>"<?= in_array((int)$m['id'], $marcados, true) ? ' checked' : '' ?>>
            <span class="dv-check"><?= UI::iconoDS('check', 12) ?></span>
            <?= UI::avatar($m, 34) ?>
            <span class="dv-datos">
              <strong class="truncate"><?= e($m['nombre']) ?></strong>
              <small class="truncate"><?= e($c['rol']) ?></small>
            </span>
            <span class="dv-medida">
              <span class="dv-barra" title="Carga: <?= (int)$c['pct'] ?>% de la de quien más tiene"><span style="width:<?= max($c['pct'], $c['total'] > 0 ? 8 : 0) ?>%"></span></span>
              <small><?= $c['tareas'] ?> tareas · <?= $c['reqs'] ?> req.</small>
              <?php
              // Por qué está donde está: a igual carga, arriba van a quienes menos
              // se les asignó últimamente y quienes llevan más tiempo sin entregar.
              $etUlt = $c['dias'] === null
                  ? 'sin entregas registradas'
                  : match (true) {
                      $c['dias'] === 0 => 'entregó hoy',
                      $c['dias'] === 1 => 'entregó ayer',
                      $c['dias'] < 30  => 'entregó hace ' . $c['dias'] . ' días',
                      default          => 'sin entregar hace ' . intdiv($c['dias'], 30) . ' mes'
                                          . (intdiv($c['dias'], 30) === 1 ? '' : 'es'),
                  };
              ?>
              <small class="dv-ultima<?= $c['dias'] !== null && $c['dias'] < 7 ? ' dv-ultima-reciente' : '' ?>"
                     title="<?= (int)$c['recibidos'] ?> requerimiento(s) suelto(s) asignado(s) en los últimos 90 días<?= $c['ultima'] !== '' ? ' · Última entrega: ' . e($c['ultima']) : '' ?>">
                <?= (int)$c['recibidos'] ?> en 90 días · <?= e($etUlt) ?>
              </small>
            </span>
            <span class="dv-reco dv-reco--<?= e($recoK) ?>"><?= e($recoT) ?></span>
            <span class="dv-carga" title="<?= $c['total'] ?> pendientes en total: <?= $c['tareas'] ?> tareas de proyecto y <?= $c['reqs'] ?> requerimientos"><?= $c['total'] ?></span>
          </label>
          <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <small class="campo-ayuda">
          <b data-picker-n="<?= e($prefijo) ?>">0</b> seleccionados · primero Programadores; Diseño y Procesos,
          abajo. En cada equipo, arriba quien menos tiene abierto (tareas + requerimientos) y, entre los que
          empatan, a quien menos se le asignó en 90 días. Sin marcar a nadie, queda «sin asignar».
        </small>
      </div>
    </section>
    <?php
}


/**
 * Paso "Plazo": las dos fechas del encargo, con los mismos atajos y el mismo
 * aviso de duración que el asistente de tareas.
 */
function panelPlazo(string $prefijo, string $inicioPorDefecto = '', string $finPorDefecto = ''): void
{
    ?>
    <section class="wz-panel">
      <div class="campo-doble">
        <label class="campo"><span>Fecha de inicio</span>
          <input class="input-meca" type="date" name="fecha_inicio"
                 data-req-fecha="<?= e($prefijo) ?>-inicio" value="<?= e($inicioPorDefecto) ?>">
        </label>
        <label class="campo"><span>Fecha de entrega</span>
          <input class="input-meca" type="date" name="fecha_fin"
                 data-req-fecha="<?= e($prefijo) ?>-fin" value="<?= e($finPorDefecto) ?>">
        </label>
      </div>
      <?= UI::atajosFecha() ?>
    </section>
    <?php
}

/**
 * Paso «Observaciones» de los asistentes: indicaciones o correcciones para
 * quien lo hace, con el editor de formato (viñetas, tablas). Opcional: si se
 * escribe, queda en las observaciones y va en el correo a los responsables.
 *
 * Con $req (Editar, Asignar) enseña antes las que ya tiene, con su plazo y si
 * se corrigieron: así no se pide dos veces lo mismo. Con días de plazo, la
 * observación pasa a ser una corrección con fecha (días hábiles).
 */
function panelObservacion(string $prefijo, ?array $req = null, array $mapa = []): void
{
    $hilos = $req ? RequerimientoRepo::hilosDe($req) : [];
    ?>
    <section class="wz-panel">
      <?php if ($hilos): ?>
      <div class="campo rq-previas">
        <span>Las que ya tiene <small>(<?= count($hilos) ?>)</small></span>
        <ul class="rq-previas__lista">
          <?php foreach ($hilos as $o): ?>
          <li>
            <span class="rq-previas__txt"><?= e(HtmlRico::texto((string)$o['texto'], 170)) ?></span>
            <small><?= e((string)($mapa[$o['autor']]['nombre'] ?? 'Alguien')) ?> · <?= e(fechaCortaReq(substr((string)$o['creado'], 0, 10))) ?>
              <?= count($o['respuestas']) ? ' · ' . count($o['respuestas']) . ' respuesta' . (count($o['respuestas']) === 1 ? '' : 's') : '' ?></small>
            <?= chipCorreccion($o) ?>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>
      <div class="campo">
        <span><?= $hilos ? 'Nueva observación' : 'Observaciones' ?> <small>(opcional)</small></span>
        <?= UI::editorRico(['name' => 'observacion', 'id' => $prefijo . '-obs', 'clase' => 'rt--obs',
              'placeholder' => 'Ej. Coordina con Diseño; la referencia está en el documento adjunto… (puedes pegar tablas)']) ?>
        <small class="campo-ayuda">Queda en las observaciones del requerimiento y les llega por correo a los responsables.</small>
      </div>
      <?= campoPlazoCorreccion('obs_dias') ?>
    </section>
    <?php
}

/**
 * «¿En cuántos días hay que corregirlo?»: días hábiles (sin fines de semana ni
 * feriados), con atajos y la fecha en que vence ya calculada. 0 = sin plazo,
 * queda como comentario. La lista de fechas va en el HTML para que admin.js
 * diga la fecha al momento, con los feriados del panel.
 */
function campoPlazoCorreccion(string $nombre): string
{
    $fechas = [];
    $t = strtotime(date('Y-m-d'));
    for ($n = 1, $i = 0; $n <= RequerimientoRepo::TOPE_DIAS_CORRECCION && $i < 400; $i++) {
        $t = strtotime('+1 day', $t);
        $d = date('Y-m-d', $t);
        if (class_exists('Feriados') ? Feriados::esHabil($d) : (int)date('N', $t) <= 5) $fechas[$n++] = fechaLargaReq($d);
    }
    $chips = '';
    foreach ([1, 2, 3, 5, 10] as $n) {
        $chips .= '<button type="button" class="rq-plazo__chip" data-rq-dias="' . $n . '">' . $n . ' día' . ($n === 1 ? '' : 's') . '</button>';
    }
    return '<div class="campo rq-plazo" data-rq-plazo="' . e(json_encode($fechas, JSON_UNESCAPED_UNICODE)) . '">'
        . '<span>¿En cuántos días hay que corregirlo? <small>(días hábiles)</small></span>'
        . '<div class="rq-plazo__fila">'
        . '<input class="input-meca rq-plazo__n" type="number" name="' . e($nombre) . '" min="0" max="' . RequerimientoRepo::TOPE_DIAS_CORRECCION . '" value="0" inputmode="numeric" aria-label="Días hábiles para corregirlo">'
        . '<div class="rq-plazo__chips">' . $chips . '<button type="button" class="rq-plazo__chip" data-rq-dias="0">Sin plazo</button></div>'
        . '</div>'
        . '<small class="campo-ayuda rq-plazo__vence" data-rq-vence>Sin plazo: queda como comentario, sin fecha.</small>'
        . '</div>';
}

/** «jueves 13 de noviembre» (y el año si no es el actual). */
function fechaLargaReq(string $f): string
{
    $t = strtotime($f);
    if (!$t) return $f;
    $dias = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    return $dias[(int)date('N', $t) - 1] . ' ' . (int)date('j', $t) . ' de ' . $meses[(int)date('n', $t) - 1] . (date('Y', $t) !== date('Y') ? ' de ' . date('Y', $t) : '');
}

/**
 * La etiqueta de una corrección: «Corregida», «Vence hoy», «Vence en 3 días»
 * o «Vencida hace 2 días». Vacía si la observación no tiene plazo.
 */
function chipCorreccion(array $o): string
{
    if ((int)($o['dias'] ?? 0) <= 0 || !empty($o['padre'])) return '';
    if (!empty($o['corregida'])) {
        return '<span class="rq-chip rq-chip--ok" title="Corregida el ' . e((string)$o['corregida_en']) . '">' . UI::icono('ds-check') . ' Corregida</span>';
    }
    $vence = (string)($o['vence'] ?? '');
    $dias = $vence !== '' ? (int)round((strtotime($vence) - strtotime(date('Y-m-d'))) / 86400) : 0;
    [$txt, $tono] = match (true) {
        $vence === ''  => ['Pendiente', 'pend'],
        $dias < 0      => [abs($dias) === 1 ? 'Vencida ayer' : 'Vencida hace ' . abs($dias) . ' días', 'mal'],
        $dias === 0    => ['Vence hoy', 'aviso'],
        $dias === 1    => ['Vence mañana', 'aviso'],
        default        => ['Vence en ' . $dias . ' días', 'pend'],
    };
    return '<span class="rq-chip rq-chip--' . $tono . '" title="' . (int)$o['dias'] . ' día' . ((int)$o['dias'] === 1 ? '' : 's') . ' hábil' . ((int)$o['dias'] === 1 ? '' : 'es') . ' · hasta el ' . e(fechaLargaReq($vence)) . '">'
        . UI::icono('ds-reloj') . ' ' . e($txt) . '</span>';
}

/**
 * Asistente «Asignar» de un requerimiento (su pantalla, requerimiento.php):
 * quién lo hace, con qué plazo y, si hace falta, una observación o corrección.
 * Ya viene relleno con lo que tiene ahora: guardar sin tocar nada no borra nada.
 */
function dialogoAsignarReq(array $req, array $carga, array $opcionesRol, array $mapa, string $volver): void
{
    ?>
<dialog id="dlg-req-derivar" class="dlg-meca dlg-wizard dlg-req-wz">
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="req_asignar">
    <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
    <input type="hidden" name="volver" value="<?= e($volver) ?>">
    <?= UI::wizardRiel('fa-user-plus', 'Asignar', 'A una o varias personas, con su plazo', UI::PASOS_ASIGNAR) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
          <p class="wz-sobre">«<b><?= e((string)$req['titulo']) ?></b>»</p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <?php panelResponsables($carga, $opcionesRol, 'dv', RequerimientoRepo::asignadosDe($req)); ?>
      <?php panelPlazo('dv', RequerimientoRepo::fechaInicio($req), RequerimientoRepo::fechaFin($req)); ?>
      <?php panelObservacion('dv', $req, $mapa); ?>

      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca wz-guardar"><i class="fa-solid fa-paper-plane"></i> Asignar</button>
        </div>
      </div>
    </div>
  </form>
</dialog>
    <?php
}

/**
 * Asistente «Editar»: el mismo que «Nuevo requerimiento» (mismos pasos), ya
 * relleno con este requerimiento. Los responsables nuevos reciben aviso; a los
 * que ya estaban no se les reenvía. En «Observaciones» se ven las que ya tiene.
 */
function dialogoEditarReq(array $req, array $carga, array $opcionesRol, array $prioridades, array $opcionesInst, array $mapa, string $volver): void
{
    ?>
<dialog id="dlg-req-editar" class="dlg-meca dlg-wizard dlg-req-wz">
  <form method="post" action="actions.php" class="dlg-form wz">
    <input type="hidden" name="accion" value="req_editar">
    <input type="hidden" name="id" value="<?= (int)$req['id'] ?>">
    <input type="hidden" name="volver" value="<?= e($volver) ?>">
    <?= UI::wizardRiel('fa-pen', 'Editar requerimiento', 'Corrige lo que haga falta', UI::PASOS_REQUERIMIENTO) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <section class="wz-panel">
        <label class="campo">
          <span>¿Qué piden?</span>
          <input class="input-meca" name="titulo" required maxlength="120" placeholder="Ej. Reporte de matrículas para el rectorado" value="<?= e((string)$req['titulo']) ?>">
        </label>
        <div class="campo">
          <span>Detalle</span>
          <?= UI::editorRico(['name' => 'detalle', 'id' => 're-detalle', 'valor' => HtmlRico::limpiar((string)($req['detalle'] ?? '')),
                'placeholder' => 'Contexto, con quién hablar, qué se espera… (puedes pegar un correo con su tabla)']) ?>
        </div>
        <div class="campo-doble">
          <label class="campo"><span>¿Quién lo pide?</span>
            <input class="input-meca" name="solicitante" maxlength="80" placeholder="Ej. Secretaría académica" value="<?= e((string)($req['solicitante'] ?? '')) ?>">
          </label>
          <label class="campo"><span>Prioridad</span>
            <?= UI::select('prioridad', array_map(fn($v) => $v[0], $prioridades), Catalogo::prioridadValida($req['prioridad'] ?? '')) ?>
          </label>
        </div>
        <label class="campo">
          <span><i class="fa-solid fa-building-columns"></i> Institución(es)</span>
          <?php if ($opcionesInst): ?>
          <?= UI::select('instituciones', $opcionesInst, RequerimientoRepo::institucionesDe($req), false, '', true) ?>
          <small class="campo-ayuda">¿Para qué institución es? Puedes elegir <b>varias</b>.</small>
          <?php else: ?>
          <small class="campo-ayuda">Aún no hay instituciones en el catálogo. <a href="instituciones.php">Créalas aquí</a>.</small>
          <?php endif; ?>
        </label>
      </section>

      <?php panelResponsables($carga, $opcionesRol, 're', RequerimientoRepo::asignadosDe($req)); ?>
      <?php panelPlazo('re', RequerimientoRepo::fechaInicio($req), RequerimientoRepo::fechaFin($req)); ?>
      <?php panelObservacion('re', $req, $mapa); ?>

      <section class="wz-panel">
        <dl class="wz-resumen"></dl>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="submit" class="btn-primary btn-meca btn-agregar wz-guardar"><i class="fa-solid fa-check"></i> Guardar cambios</button>
        </div>
      </div>
    </div>
  </form>
</dialog>
    <?php
}
