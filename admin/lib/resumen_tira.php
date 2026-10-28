<?php
/**
 * Tu resumen, arriba del dashboard: un carrusel con la misma piel que la tira
 * de despliegues (borde de vidrio que gira, celeste y menta). Cada
 * diapositiva es algo que se quiere saber al entrar sin ir a buscarlo:
 * cuándo suben los cambios, cómo van tus tareas, lo que tienes por revisar
 * como PO, tu próxima reunión y lo que te espera (correo, requerimientos,
 * pedidos). Solo salen las que tienen algo que decir.
 *
 * Pasa sola cada pocos segundos; se queda quieta con el mouse encima, al
 * tocarla o si quien mira prefiere menos movimiento. Se cambia con los
 * puntos, las flechas, el teclado o deslizando en el celular (admin.js).
 *
 * Espera:
 *   $rtYo      la persona que mira (o la de «ver como»)
 *   $rtTareas  las tareas que ve en el dashboard
 *   $rtSlides  diapositivas ya armadas (la de despliegues, si la hay)
 */
if (is_file(__DIR__ . '/Cronograma.php')) require_once __DIR__ . '/Cronograma.php';
$rtMid   = (int)($rtYo['id'] ?? 0);
$rtSlides ??= [];
$rtAhora = time();
$rtDia   = fn(int $rtTV) => class_exists('Cronograma') ? Cronograma::diaLargo($rtTV) : date('d/m', $rtTV);
$rtHora  = fn(int $rtTV) => class_exists('Cronograma') ? Cronograma::hora($rtTV) : date('g:i a', $rtTV);
$rtProys = [];
foreach ((new ProyectoRepo())->todos() as $rtPV) $rtProys[(int)$rtPV['id']] = $rtPV;
$rtNombreP = fn(int $rtPid) => (string)($rtProys[$rtPid]['nombre'] ?? 'Proyecto');

// Piezas: un dato (ícono, etiqueta, lo grande y una línea debajo), un número
// que cuenta al aparecer y un botón de ícono como los de la tira
$rtDato = fn(string $rtIco, string $rtTono, string $rtEtq, string $rtGrande, string $rtSub = '', string $rtMas = '') =>
    '<div class="dep-dato' . $rtMas . '"><span class="dep-pulso rt-ic--' . $rtTono . '" aria-hidden="true">' . $rtIco . '</span>'
    . '<div class="dep-dato__txt"><small>' . e($rtEtq) . '</small><b class="dep-hora">' . $rtGrande . '</b>'
    . ($rtSub !== '' ? '<span class="dep-sub">' . $rtSub . '</span>' : '') . '</div></div>';
$rtNum = fn(int $rtNV) => '<span class="rt-num" data-n="' . $rtNV . '">' . $rtNV . '</span>';
$rtBoton = fn(string $rtHref, string $rtIco, string $rtTip, string $rtMas = '') =>
    '<a class="dep-ico' . $rtMas . '" href="' . e($rtHref) . '" aria-label="' . e($rtTip) . '" data-ds-tip="' . e($rtTip) . '">' . $rtIco . '</a>';
$rtMain = fn(string $rtDatos, string $rtAcc) => '<div class="dep-tira-main">' . $rtDatos . ($rtAcc !== '' ? '<div class="dep-acc">' . $rtAcc . '</div>' : '') . '</div>';
$rtFa = fn(string $rtCV) => '<i class="fa-solid ' . $rtCV . '"></i>';
$rtS = fn(int $rtNV, string $rtUno, string $rtVarios) => $rtNV === 1 ? $rtUno : $rtVarios;

/* ---------- Mis tareas ---------- */
$rtFinales = Catalogo::estadosFinales();
$rtHoy = date('Y-m-d');
$rtMias = 0;
$rtAbiertas = [];
foreach ($rtTareas as $rtTV) {
    if (!TareaRepo::tieneAsignado($rtTV, $rtMid)) continue;
    $rtMias++;
    if (!in_array($rtTV['estado'] ?? '', $rtFinales, true)) $rtAbiertas[] = $rtTV;
}
if ($rtMias) {
    $rtPor = ['pendiente' => 0, 'progreso' => 0, 'revision' => 0];
    $rtVencidas = [];
    $rtProxima = null;
    foreach ($rtAbiertas as $rtTV) {
        $rtEst = (string)($rtTV['estado'] ?? '');
        if (isset($rtPor[$rtEst])) $rtPor[$rtEst]++;
        $rtFV = trim((string)($rtTV['fecha_limite'] ?? ''));
        if ($rtFV === '' || $rtEst === 'revision') continue;        // en revisión ya no depende de quien la hace
        if ($rtFV < $rtHoy) $rtVencidas[] = $rtTV;
        elseif (!$rtProxima || $rtFV < $rtProxima['fecha_limite']) $rtProxima = $rtTV;
    }
    $rtN = count($rtAbiertas);
    $rtPartes = [];
    foreach (['pendiente' => 'por hacer', 'progreso' => 'en curso', 'revision' => 'en revisión'] as $rtKV => $rtTxt) {
        if ($rtPor[$rtKV]) $rtPartes[] = '<b>' . $rtPor[$rtKV] . '</b> ' . $rtTxt;
    }
    $rtDatos = $rtDato($rtFa('fa-list-check'), 'celeste', 'Tus tareas abiertas',
        $rtN ? $rtNum($rtN) : 'Al día',
        $rtN ? implode(' · ', $rtPartes) : 'No tienes nada pendiente');
    if ($rtVencidas) {
        $rtNV = count($rtVencidas);
        $rtDatos .= $rtDato($rtFa('fa-triangle-exclamation'), 'rosa', 'Pasadas de fecha',
            $rtNum($rtNV) . ' ' . $rtS($rtNV, 'tarea', 'tareas'),
            e(mb_strimwidth((string)$rtVencidas[0]['titulo'], 0, 46, '…')), ' rt-alerta');
    }
    if ($rtProxima) {
        $rtTsP = strtotime($rtProxima['fecha_limite']) ?: $rtAhora;
        $rtDatos .= $rtDato($rtFa('fa-calendar-day'), 'menta', 'Tu próxima entrega', e($rtDia($rtTsP)),
            e(mb_strimwidth((string)$rtProxima['titulo'], 0, 46, '…')) . ' · ' . e($rtNombreP((int)$rtProxima['proyecto_id'])));
    }
    $rtIr = $rtVencidas[0] ?? $rtProxima ?? $rtAbiertas[0] ?? null;
    $rtAcc = $rtIr ? $rtBoton('proyecto.php?id=' . (int)$rtIr['proyecto_id'] . '#vista-kanban', $rtFa('fa-table-columns'),
        'Ir al tablero de ' . $rtNombreP((int)$rtIr['proyecto_id'])) : '';
    $rtSlides[] = ['clave' => 'tareas', 'nombre' => 'Mis tareas', 'main' => $rtMain($rtDatos, $rtAcc)];
}

/* ---------- Por revisar como Product Owner ---------- */
$rtMisPo = array_filter($rtProys, fn($rtPV) => esPOde($rtPV, $rtMid));
if ($rtMisPo) {
    $rtRev = [];
    foreach ((new TareaRepo())->todas() as $rtTV) {
        $rtPid = (int)($rtTV['proyecto_id'] ?? 0);
        if (isset($rtMisPo[$rtPid]) && ($rtTV['estado'] ?? '') === 'revision') $rtRev[$rtPid] = ($rtRev[$rtPid] ?? 0) + 1;
    }
    if ($rtRev) {
        arsort($rtRev);
        $rtNR = array_sum($rtRev);
        $rtDatos = $rtDato($rtFa('fa-magnifying-glass'), 'lila', 'Por revisar como PO',
            $rtNum($rtNR) . ' ' . $rtS($rtNR, 'tarea', 'tareas'),
            'Esperan tu visto bueno en ' . count($rtRev) . ' ' . $rtS(count($rtRev), 'proyecto', 'proyectos'));
        $rtChips = '';
        foreach (array_slice($rtRev, 0, 3, true) as $rtPid => $rtNV) {
            $rtChips .= '<a class="rt-chip" href="proyecto.php?id=' . $rtPid . '#vista-kanban">'
                . '<span class="truncate">' . e($rtNombreP($rtPid)) . '</span><b>' . $rtNV . '</b></a>';
        }
        $rtDatos .= '<div class="dep-dato rt-chips">' . $rtChips . '</div>';
        $rtPidR = (int)array_key_first($rtRev);
        $rtAcc = $rtBoton('proyecto.php?id=' . $rtPidR . '#vista-kanban', $rtFa('fa-table-columns'), 'Revisar en ' . $rtNombreP($rtPidR));
        $rtSlides[] = ['clave' => 'revisar', 'nombre' => 'Por revisar', 'main' => $rtMain($rtDatos, $rtAcc)];
    }
}

/* ---------- Tu próxima reunión ---------- */
// Las que te invitaron o creaste (en un proyecto que ves), en las dos semanas que vienen
$rtReu = null;
$rtReuTs = 0;
foreach ((new ReunionRepo())->todas() as $rtRV) {
    $rtInv = array_map('intval', (array)($rtRV['invitados'] ?? []));
    if (!in_array($rtMid, $rtInv, true) && (int)($rtRV['creador_id'] ?? 0) !== $rtMid) continue;
    if (!puedeVerProyecto((int)($rtRV['proyecto_id'] ?? 0))) continue;
    $rtTs = Reuniones::proximoInicio($rtRV, $rtAhora);
    if ($rtTs && $rtTs - $rtAhora < 14 * 86400 && (!$rtReuTs || $rtTs < $rtReuTs)) { $rtReu = $rtRV; $rtReuTs = $rtTs; }
}
$rtReuUrgente = false;
if ($rtReu) {
    $rtFin    = $rtReuTs + max(1, (int)($rtReu['duracion'] ?? 60)) * 60;
    $rtEnVivo = $rtReuTs <= $rtAhora;
    $rtPronto = !$rtEnVivo && $rtReuTs - $rtAhora <= 30 * 60;
    $rtReuUrgente = $rtEnVivo || $rtReuTs - $rtAhora <= 60 * 60;
    $rtPidM   = (int)$rtReu['proyecto_id'];
    $rtPlat   = Reuniones::etiquetaPlataforma((string)($rtReu['plataforma'] ?? 'zoom'));
    $rtDatos = $rtDato($rtFa('fa-video'), 'lila', $rtEnVivo ? 'Tu reunión está en curso' : 'Tu próxima reunión',
        $rtEnVivo ? '<span class="rt-vivo-txt">En vivo</span>' : e($rtDia($rtReuTs)) . ' · ' . e($rtHora($rtReuTs)),
        e(mb_strimwidth((string)($rtReu['topic'] ?? 'Reunión'), 0, 46, '…')) . ' · ' . e($rtPlat),
        ($rtEnVivo || $rtPronto) ? ' rt-radar' : '');
    $rtDatos .= $rtDato($rtFa('fa-hourglass-half'), 'menta', $rtEnVivo ? 'Termina' : 'Empieza',
        '<span data-cuenta-regresiva data-corto data-ya="' . ($rtEnVivo ? 'ya terminó' : 'ya empezó') . '" data-hasta="' . ($rtEnVivo ? $rtFin : $rtReuTs)
        . '" data-ahora="' . $rtAhora . '">&nbsp;</span>',
        e($rtNombreP($rtPidM)) . ' · ' . (int)($rtReu['duracion'] ?? 60) . ' min');
    $rtAcc = '';
    if (preg_match('#^https?://#i', trim((string)($rtReu['join_url'] ?? '')))) {
        $rtAcc .= $rtBoton('reunion_entrar.php?id=' . (int)$rtReu['id'], $rtFa('fa-right-to-bracket'), 'Entrar a la reunión',
            ($rtEnVivo || $rtPronto) ? ' dep-ico--subir rt-entrar' : '');
    }
    $rtAcc .= $rtBoton('proyecto.php?id=' . $rtPidM . '#vista-reuniones', $rtFa('fa-calendar-days'), 'Reuniones de ' . $rtNombreP($rtPidM));
    $rtSlides[] = ['clave' => 'reunion', 'nombre' => 'Próxima reunión', 'main' => $rtMain($rtDatos, $rtAcc), 'urgente' => $rtReuUrgente];
}

/* ---------- Lo que te espera: correo, requerimientos y pedidos ---------- */
$rtDatos = '';
$rtAcc = '';
$rtHay = 0;
if (!verComo() && is_file(__DIR__ . '/Correo.php')) {   // el correo de otro no se asoma
    require_once __DIR__ . '/Correo.php';
    $rtNC = Correo::activo() ? Correo::noLeidos($rtMid) : null;
    if ($rtNC) {
        $rtHay += $rtNC;
        $rtDatos .= $rtDato($rtFa('fa-envelope'), 'azul', 'Correos sin leer', $rtNum($rtNC), 'En tu bandeja InnoTech');
        $rtAcc .= $rtBoton('correo.php', $rtFa('fa-envelope-open-text'), 'Abrir el correo');
    }
}
$rtNQ = (new RequerimientoRepo())->abiertosDe($rtMid);
if ($rtNQ) {
    $rtHay += $rtNQ;
    $rtDatos .= $rtDato(UI::icono('ds-requerimiento'), 'celeste', 'Requerimientos abiertos', $rtNum($rtNQ), $rtS($rtNQ, 'Te lo asignaron', 'Te los asignaron'));
    $rtAcc .= $rtBoton('bandeja.php', UI::icono('ds-requerimiento'), 'Tus requerimientos');
}
if (is_file(__DIR__ . '/Pedidos.php')) {
    require_once __DIR__ . '/Pedidos.php';
    $rtNP = (new PedidoRepo())->pendientesPara($rtYo);
    if ($rtNP) {
        $rtHay += $rtNP;
        $rtDatos .= $rtDato(UI::icono('ds-transferencia'), 'menta', 'Pedidos abiertos', $rtNum($rtNP), 'Para ti o tu equipo');
        $rtAcc .= $rtBoton('pedidos.php', UI::icono('ds-transferencia'), 'Pedidos entre equipos');
    }
}
if ($rtHay) $rtSlides[] = ['clave' => 'pendientes', 'nombre' => 'Te espera', 'main' => $rtMain($rtDatos, $rtAcc)];

// Una reunión que ya empezó o empieza en menos de una hora va primero
usort($rtSlides, fn($a, $b) => (int)!empty($b['urgente']) <=> (int)!empty($a['urgente']));

if (!$rtSlides) return;
$rtVarias = count($rtSlides) > 1;
?>
<section class="dep-tira card-base rt<?= $rtVarias ? ' rt-varias' : '' ?>" data-rt aria-roledescription="carrusel" aria-label="Tu resumen">
  <div class="rt-pista" data-rt-pista aria-live="off">
    <?php foreach ($rtSlides as $rtIV => $rtSl): ?>
    <div class="rt-slide<?= $rtIV === 0 ? ' is-activa' : '' ?>" data-rt-slide data-clave="<?= e($rtSl['clave']) ?>" role="group"
         aria-roledescription="diapositiva" aria-label="<?= ($rtIV + 1) . ' de ' . count($rtSlides) . ': ' . e($rtSl['nombre']) ?>"<?= $rtIV === 0 ? '' : ' inert' ?>>
      <?= $rtSl['main'] ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($rtVarias): ?>
  <div class="rt-pie">
    <button type="button" class="rt-flecha" data-rt-mover="-1" aria-label="Anterior"><i class="fa-solid fa-chevron-left"></i></button>
    <div class="rt-puntos">
      <?php foreach ($rtSlides as $rtIV => $rtSl): ?>
      <button type="button" class="rt-punto<?= $rtIV === 0 ? ' is-activo' : '' ?>" data-rt-ir="<?= $rtIV ?>" aria-label="<?= e($rtSl['nombre']) ?>"<?= $rtIV === 0 ? ' aria-current="true"' : '' ?>><span></span></button>
      <?php endforeach; ?>
    </div>
    <button type="button" class="rt-flecha" data-rt-mover="1" aria-label="Siguiente"><i class="fa-solid fa-chevron-right"></i></button>
    <span class="rt-nombre" data-rt-nombre><?= e($rtSlides[0]['nombre']) ?></span>
  </div>
  <?php endif; ?>
</section>
