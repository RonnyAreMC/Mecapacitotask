<?php
/**
 * Piezas compartidas de los requerimientos sueltos.
 *
 * Las usan las DOS pantallas: el modulo del administrador
 * (requerimientos.php), donde se reparten, y la bandeja del equipo
 * (bandeja.php), donde cada quien ve los suyos. Estan aqui para que una fila
 * signifique lo mismo en las dos y no haya que arreglar cada cosa dos veces.
 */
require_once __DIR__ . '/Models.php';

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
 * Una fila de la lista. Enseña lo justo para decidir sin abrirla: qué es,
 * quién lo hace (con nombres, no solo iniciales de colores), qué urgencia
 * tiene y cuánto queda de plazo. El detalle largo y las acciones viven en la
 * ficha que se abre al pulsarla.
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

    // Nombres de pila: "Coddy, Dulce +1". Los avatares solos son iniciales de
    // colores y hay que pasar el ratón por encima para saber de quién son.
    $nombres = array_map(fn($m) => explode(' ', trim($m['nombre']))[0], $gente);
    $resumenGente = match (count($nombres)) {
        0       => '',
        1, 2    => implode(', ', $nombres),
        default => $nombres[0] . ', ' . $nombres[1] . ' +' . (count($nombres) - 2),
    };

    // La ficha se rellena en el navegador con estos datos: asi no hay que
    // repetir el detalle completo de cada requerimiento en el HTML.
    $datos = [
        'id'          => $id,
        'titulo'      => (string)$r['titulo'],
        'detalle'     => (string)($r['detalle'] ?? ''),
        'solicitante' => (string)($r['solicitante'] ?? ''),
        'estado'      => $estado,
        'estadoTxt'   => $etEstado,
        'prioridad'   => isset($prioridades[$prio]) ? $prioridades[$prio][0] : '',
        'inicio'      => $ini,
        'fin'         => $fin,
        'vencido'     => $vencido,
        'creado'      => (string)($r['creado'] ?? ''),
        'cerrado'     => RequerimientoRepo::cerrado($r),
        'asignados'   => implode(',', $ids),
        'personas'    => array_map(fn($m) => ['nombre' => $m['nombre'], 'rol' => $m['rol'] ?? ''], $gente),
    ];
    ?>
    <button type="button" class="req-fila req-e-<?= e($estado) ?><?= $vencido ? ' req-fila-vencida' : '' ?>"
            data-req="<?= e(json_encode($datos, JSON_UNESCAPED_UNICODE)) ?>">
      <span class="req-f-estado" title="<?= e($etEstado) ?>"><i class="fa-solid <?= e($icEstado) ?>"></i></span>

      <span class="req-f-txt">
        <strong class="truncate"><?= e($r['titulo']) ?></strong>
        <small class="truncate">
          <?= !empty($r['solicitante']) ? 'Lo pide ' . e($r['solicitante']) : 'Sin remitente anotado' ?>
        </small>
      </span>

      <!-- Quién lo hace. Es la columna que más se mira: va con nombre. -->
      <span class="req-f-gente" title="<?= $gente ? e(implode(', ', array_column($gente, 'nombre'))) : 'Todavía no tiene responsable' ?>">
        <?php if ($gente): ?>
          <?= UI::avatarStack($gente, 3, 26) ?>
          <small class="truncate"><?= e($resumenGente) ?></small>
        <?php else: ?>
          <span class="req-libre"><i class="fa-solid fa-inbox"></i> Sin asignar</span>
        <?php endif; ?>
      </span>

      <!-- Las celdas van siempre, aunque estén vacías: es una rejilla y sin
           ellas las columnas de la fila se corren una posición. -->
      <span class="req-f-prio"><?= isset($prioridades[$prio]) ? UI::badgePrioridad($prio) : '' ?></span>

      <!-- El plazo ya restado: "Faltan 13 días" en vez de una fecha suelta -->
      <span class="req-f-plazo plazo-<?= e($plazoNivel) ?>" title="<?= e($plazoDetalle) ?>">
        <?= e($plazoTxt) ?>
      </span>

      <i class="fa-solid fa-chevron-right req-f-mas"></i>
    </button>
    <?php
}

/** Cabecera de un bloque de la lista, con su cuenta. */
function bloqueRequerimientos(string $titulo, string $icono, array $items, array $mapa, array $prioridades, array $estados, string $ayuda = ''): void
{
    if (!$items) return;
    ?>
    <section class="req-bloque">
      <h2 class="req-titulo">
        <i class="fa-solid <?= e($icono) ?> text-secondary"></i> <?= e($titulo) ?>
        <span class="tabla-count"><?= count($items) ?></span>
        <?php if ($ayuda !== ''): ?><small><?= e($ayuda) ?></small><?php endif; ?>
      </h2>
      <div class="req-lista">
        <?php foreach ($items as $r) filaRequerimiento($r, $mapa, $prioridades, $estados); ?>
      </div>
    </section>
    <?php
}

/**
 * Ficha completa, que se abre al pulsar una fila. La rellena admin.js con los
 * datos que lleva la fila encima.
 *
 * $gestor = el administrador en su modulo: ademas de mirar, asigna, cierra y
 * borra. En la bandeja del equipo la ficha es solo de lectura.
 */
function fichaRequerimiento(bool $gestor): void
{
    ?>
    <dialog id="dlg-req-ficha" class="dlg-meca dlg-ficha">
      <div class="dlg-form">
        <header>
          <h3 class="font-display"><i class="fa-solid fa-inbox text-secondary"></i> <span id="fq-titulo"></span></h3>
          <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
        </header>

        <div class="fq-chips" id="fq-chips"></div>

        <div class="fq-bloque">
          <h4>Detalle</h4>
          <p id="fq-detalle"></p>
        </div>

        <div class="fq-datos">
          <div><span>Lo pide</span><b id="fq-solicitante"></b></div>
          <div><span>Inicio</span><b id="fq-inicio"></b></div>
          <div><span>Entrega</span><b id="fq-fin"></b></div>
          <div><span>Registrado</span><b id="fq-creado"></b></div>
        </div>

        <div class="fq-bloque">
          <h4>Responsables</h4>
          <ul class="fq-personas" id="fq-personas"></ul>
        </div>

        <footer>
          <?php if ($gestor): ?>
          <form method="post" action="actions.php" class="inline-form" id="fq-borrar"
                data-confirmar="Se borrará este requerimiento." data-confirmar-titulo="¿Eliminar?" data-confirmar-ok="Sí, eliminar">
            <input type="hidden" name="accion" value="req_eliminar">
            <input type="hidden" name="id" id="fq-id-borrar">
            <button class="accion-btn accion-peligro" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
          </form>
          <button type="button" class="btn-outline btn-meca" id="fq-derivar"><i class="fa-solid fa-user-plus"></i> Asignar</button>
          <form method="post" action="actions.php" class="inline-form" id="fq-resolver">
            <input type="hidden" name="accion" value="req_estado">
            <input type="hidden" name="id" id="fq-id-estado">
            <input type="hidden" name="estado" value="hecho">
            <button class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Marcar resuelto</button>
          </form>
          <?php else: ?>
          <p class="ajuste-ayuda fq-nota">
            <i class="fa-solid fa-circle-info"></i>
            Lo reparte el administrador: si algo no cuadra (el plazo, o que no te toque a ti), díselo.
          </p>
          <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cerrar</button>
          <?php endif; ?>
        </footer>
      </div>
    </dialog>
    <?php
}
