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
            <?= UI::icono('ds-reloj') ?> <?= $corr['pendientes'] ?> corrección<?= $corr['pendientes'] === 1 ? '' : 'es' ?><?= $corr['vencidas'] ? ' · ' . $corr['vencidas'] . ' vencida' . ($corr['vencidas'] === 1 ? '' : 's') : '' ?>
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
 * Carga de cada persona: tareas abiertas + requerimientos abiertos. Es el dato
 * con el que se decide a quien darle el siguiente.
 *
 * Y, a igualdad de carga, CUANDO entrego algo por ultima vez. Ordenar solo por
 * lo abierto repartia mal: quien acababa de cerrar diez cosas marcaba 0 igual
 * que quien no recibe trabajo desde hace un mes, y entre dos ceros mandaba el
 * alfabeto — o sea que Aaron Bayas se llevaba siempre el siguiente. Con la
 * fecha de la ultima entrega, arriba queda quien lleva mas tiempo sin que le
 * toque nada.
 *
 * Devuelve [$carga, $opcionesRol]: la lista ordenada y los roles para filtrar.
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
    foreach ($reqRepo->todos() as $rq) {
        if (!RequerimientoRepo::cerrado($rq)) continue;
        $cuando = (string)($rq['cerrado_en'] ?? '');
        // Lo cuenta quien lo marco terminado; si lo cerro el administrador desde
        // el modulo no queda 'cerrado_por', y entonces cuenta para sus responsables.
        $deQuien = (int)($rq['cerrado_por'] ?? 0)
            ? [(int)$rq['cerrado_por']]
            : RequerimientoRepo::asignadosDe($rq);
        foreach ($deQuien as $mid) $apuntar($mid, $cuando);
    }

    $hoyCarga = new DateTimeImmutable('today');
    $carga = [];
    foreach ($miembros->todos() as $m) {
        $mid    = (int)$m['id'];
        $tareas = (int)($abiertas[$mid] ?? 0);
        $reqs   = $reqRepo->abiertosDe($mid);
        $suUltima = $ultima[$mid] ?? '';
        $carga[] = [
            'miembro' => $m,
            'rol'     => trim($m['rol'] ?? '') ?: 'Sin rol',
            'tareas'  => $tareas,
            'reqs'    => $reqs,
            'total'   => $tareas + $reqs,
            'ultima'  => $suUltima,
            // Dias desde la ultima entrega; null si no consta ninguna.
            'dias'    => $suUltima !== ''
                ? (int)$hoyCarga->diff(new DateTimeImmutable($suUltima))->days
                : null,
        ];
    }
    // Del mas libre al mas cargado y, a igual carga, de quien lleva mas tiempo sin
    // entregar a quien acaba de hacerlo. La cadena vacia ordena antes que cualquier
    // fecha, asi que quien no tiene ninguna entrega registrada sale primero.
    usort($carga, fn($a, $b) => $a['total'] <=> $b['total']
        ?: strcmp($a['ultima'], $b['ultima'])
        ?: strcasecmp($a['miembro']['nombre'], $b['miembro']['nombre']));

    // Roles presentes, con cuanta gente hay en cada uno: son los filtros del
    // selector. Se sacan del equipo real y no del catalogo, para no ofrecer
    // filtros que no seleccionan a nadie.
    $rolesCarga = [];
    foreach ($carga as $c) {
        $rolesCarga[$c['rol']] = ($rolesCarga[$c['rol']] ?? 0) + 1;
    }
    ksort($rolesCarga, SORT_NATURAL | SORT_FLAG_CASE);

    $opcionesRol = ['' => 'Todos (' . count($carga) . ')'];
    foreach ($rolesCarga as $rolNombre => $cuantos) {
        $opcionesRol[$rolNombre] = $rolNombre . ' (' . $cuantos . ')';
    }
    return [$carga, $opcionesRol];
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
    $tope = $carga ? max(array_column($carga, 'total')) : 0;
    ?>
    <section class="wz-panel">
      <div class="campo">
        <span>Responsables</span>
        <!-- Espejo de lo marcado, solo para que el paso de Revisión lo liste:
             el asistente resume campos, y una lista de casillas no lo es.
             Sin name, así que no se envía; los ids van en asignados[]. -->
        <input type="hidden" data-md="1" data-picker-resumen="<?= e($prefijo) ?>">

        <div class="dv-controles">
          <label class="dv-buscar">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="search" class="input-meca js-<?= e($prefijo) ?>-buscar" placeholder="Buscar por nombre…" autocomplete="off">
          </label>
          <label class="carga-filtro dv-filtro">
            <span>Filtrar por rol</span>
            <?= UI::select($prefijo . '_rol', $opcionesRol, '', false, 'js-' . $prefijo . '-rol') ?>
          </label>
        </div>

        <p class="dv-sinresultados" data-picker-vacio="<?= e($prefijo) ?>" hidden>Nadie coincide con la búsqueda.</p>

        <div class="dv-lista" data-picker="<?= e($prefijo) ?>">
          <?php foreach ($carga as $c):
              $m = $c['miembro'];
              $pct = $tope > 0 ? round($c['total'] / $tope * 100) : 0;
              $nivel = $pct >= 66 ? 'alta' : ($pct >= 33 ? 'media' : 'baja');
          ?>
          <label class="dv-persona carga-<?= $nivel ?>" data-id="<?= (int)$m['id'] ?>"
                 data-rol="<?= e($c['rol']) ?>" data-nombre="<?= e($m['nombre']) ?>">
            <input type="checkbox" name="asignados[]" value="<?= (int)$m['id'] ?>"<?= in_array((int)$m['id'], $marcados, true) ? ' checked' : '' ?>>
            <span class="dv-check"><i class="fa-solid fa-check"></i></span>
            <?= UI::avatar($m, 34) ?>
            <span class="dv-datos">
              <strong class="truncate"><?= e($m['nombre']) ?></strong>
              <small class="truncate"><?= e($c['rol']) ?></small>
            </span>
            <span class="dv-medida">
              <span class="dv-barra"><span style="width:<?= max($pct, $c['total'] > 0 ? 8 : 0) ?>%"></span></span>
              <small><?= $c['tareas'] ?> tareas · <?= $c['reqs'] ?> req.</small>
              <?php
              // Por que esta donde esta: entre varios con la misma carga, arriba
              // van los que llevan mas tiempo sin entregar. Sin esto, el orden
              // parece arbitrario cuando todos marcan 0.
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
                     <?= $c['ultima'] !== '' ? 'title="Última entrega: ' . e($c['ultima']) . '"' : '' ?>>
                <?= e($etUlt) ?>
              </small>
            </span>
            <span class="dv-carga" title="<?= $c['total'] ?> pendientes en total: <?= $c['tareas'] ?> tareas de proyecto y <?= $c['reqs'] ?> requerimientos"><?= $c['total'] ?></span>
          </label>
          <?php endforeach; ?>
        </div>

        <small class="campo-ayuda">
          <b data-picker-n="<?= e($prefijo) ?>">0</b> seleccionados · el número de cada quien es lo que ya tiene
          abierto (tareas de proyecto + requerimientos). Arriba van los más libres y, entre los que
          empatan, quien lleva más tiempo sin que le toque nada. Sin marcar a nadie, queda «sin asignar».
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
