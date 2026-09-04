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
        'prioridadKey' => $prio,
        'instituciones' => RequerimientoRepo::institucionesDe($r),
        'inicio'      => $ini,
        'fin'         => $fin,
        'vencido'     => $vencido,
        'creado'      => (string)($r['creado'] ?? ''),
        'cerrado'     => RequerimientoRepo::cerrado($r),
        'asignados'   => implode(',', $ids),
        'adjuntos'    => array_values((array)($r['adjuntos'] ?? [])),
        'personas'    => array_map(fn($m) => ['nombre' => $m['nombre'], 'rol' => $m['rol'] ?? ''], $gente),
        // Observación de cierre que deja el responsable al marcarlo terminado.
        'notaCierre'  => (string)($r['nota_cierre'] ?? ''),
        'cerradoPor'  => (string)($mapa[(int)($r['cerrado_por'] ?? 0)]['nombre'] ?? ''),
        'cerradoEn'   => (string)($r['cerrado_en'] ?? ''),
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
        <?php $insts = RequerimientoRepo::institucionesDe($r); $imapa = reqUiInst(); if ($insts): ?>
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

      <span class="req-f-estadob"><i class="fa-solid <?= e($icEstado) ?>"></i> <?= e($etEstado) ?></span>

      <!-- Todo lo secundario en una sola tira, para que la tarjeta tenga dos
           renglones y no seis columnas que se estrechan al ponerlas de a dos. -->
      <span class="req-f-meta">
      <!-- Quién lo hace. Es lo que más se mira: va con nombre. -->
      <span class="req-f-gente" title="<?= $gente ? e(implode(', ', array_column($gente, 'nombre'))) : 'Todavía no tiene responsable' ?>">
        <?php if ($gente): ?>
          <?= UI::avatarStack($gente, 3, 26) ?>
          <small class="truncate"><?= e($resumenGente) ?></small>
        <?php else: ?>
          <span class="req-libre"><i class="fa-solid fa-inbox"></i> Sin asignar</span>
        <?php endif; ?>
      </span>

      <!-- Arranque y entrega con banderas: la de cuadros marca el final. -->
      <?php if ($ini !== '' || $fin !== ''): ?>
      <span class="req-f-fechas" title="<?= $ini !== '' ? 'Empieza el ' . e($ini) : 'Sin fecha de inicio' ?><?= $fin !== '' ? ' · Entrega el ' . e($fin) : ' · sin fecha de entrega' ?>">
        <i class="fa-regular fa-flag"></i> <?= $ini !== '' ? e($ini) : '—' ?>
        <i class="fa-solid fa-arrow-right-long req-f-flecha"></i>
        <i class="fa-solid fa-flag-checkered"></i> <?= $fin !== '' ? e($fin) : '—' ?>
      </span>
      <?php endif; ?>

      <span class="req-f-prio"><?= isset($prioridades[$prio]) ? UI::badgePrioridad($prio) : '' ?></span>

      <!-- El plazo ya restado: "Faltan 13 días" en vez de una fecha suelta -->
      <span class="req-f-plazo plazo-<?= e($plazoNivel) ?>" title="<?= e($plazoDetalle) ?>">
        <?= e($plazoTxt) ?>
      </span>
      </span>

      <i class="fa-solid fa-chevron-right req-f-mas"></i>
    </button>
    <?php
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
function fichaRequerimiento(bool $gestor, bool $puedeTerminar = false): void
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
          <div id="fq-detalle" class="rt-render"></div>
        </div>

        <div class="fq-bloque" id="fq-docs-bloque" hidden>
          <h4>Documentos</h4>
          <div id="fq-docs" class="dt-adjuntos"></div>
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

        <!-- Observación de cierre: lo que dejó anotado quien lo terminó (rama,
             commits, dónde quedó). Se muestra si existe. -->
        <div class="fq-bloque fq-cierre" id="fq-cierre" hidden>
          <h4><i class="fa-solid fa-circle-check text-secondary"></i> Cómo se entregó</h4>
          <p id="fq-nota-cierre"></p>
          <small class="fq-cierre-meta" id="fq-cierre-meta"></small>
        </div>

        <footer>
          <?php if ($gestor): ?>
          <form method="post" action="actions.php" class="inline-form" id="fq-borrar"
                data-confirmar="Se borrará este requerimiento." data-confirmar-titulo="¿Eliminar?" data-confirmar-ok="Sí, eliminar">
            <input type="hidden" name="accion" value="req_eliminar">
            <input type="hidden" name="id" id="fq-id-borrar">
            <button class="accion-btn accion-peligro" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
          </form>
          <button type="button" class="btn-outline btn-meca btn-azul" id="fq-editar"><i class="fa-solid fa-pen"></i> Editar</button>
          <button type="button" class="btn-outline btn-meca btn-azul" id="fq-derivar"><i class="fa-solid fa-user-plus"></i> Asignar</button>
          <form method="post" action="actions.php" class="inline-form" id="fq-resolver">
            <input type="hidden" name="accion" value="req_estado">
            <input type="hidden" name="id" id="fq-id-estado">
            <input type="hidden" name="estado" value="hecho">
            <button class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Marcar resuelto</button>
          </form>
          <?php else: ?>
          <?php if ($puedeTerminar): ?>
          <!-- El responsable marca cuando lo terminó y deja constancia de cómo
               (rama, commits…). Al enviarlo, se avisa por correo a quien lo
               asignó. JS oculta el formulario si ya está cerrado. -->
          <form method="post" action="actions.php" class="fq-terminar" id="fq-terminar">
            <input type="hidden" name="accion" value="req_terminar">
            <input type="hidden" name="id" id="fq-id-terminar">
            <label class="campo">
              <span>¿Cómo lo dejaste? <small>(rama, commits, dónde quedó…)</small></span>
              <textarea class="input-meca" name="nota" rows="2" maxlength="600"
                        placeholder="Ej. Subido en la rama feature/nota-credito con los commits ab12cd y 34ef56"></textarea>
            </label>
            <button class="btn-primary btn-meca"><i class="fa-solid fa-circle-check"></i> Marcar como terminado</button>
          </form>
          <p class="ajuste-ayuda fq-nota" id="fq-ya-cerrado" hidden>
            <i class="fa-solid fa-circle-check"></i> Ya está marcado como terminado.
          </p>
          <?php else: ?>
          <p class="ajuste-ayuda fq-nota">
            <i class="fa-solid fa-circle-info"></i>
            Lo reparte el administrador: si algo no cuadra (el plazo, o que no te toque a ti), díselo.
          </p>
          <?php endif; ?>
          <button type="button" class="btn-outline btn-meca btn-neutro" onclick="this.closest('dialog').close()">Cerrar</button>
          <?php endif; ?>
        </footer>
      </div>
    </dialog>
    <?php
}
