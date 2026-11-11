<?php
/**
 * Un requerimiento suelto, en su propia pantalla.
 *
 * Antes era una ficha en una ventana (modal) que lo apilaba todo: detalle,
 * observaciones con su editor, responsables, trazabilidad, documentos y cinco
 * botones. Aquí cada cosa tiene su sitio: arriba qué es y qué se puede hacer;
 * a la izquierda el detalle y las observaciones (lo que se lee y se conversa);
 * a la derecha los datos, quién lo tiene, los documentos y la trazabilidad.
 *
 * Las observaciones pueden llevar un plazo en días hábiles: entonces son una
 * corrección con fecha, que quien lo tiene marca «Corregida» al cumplirla.
 *
 * La abre el administrador (desde Requerimientos), quien lo tiene asignado
 * (desde su bandeja), el supervisor y quien da seguimiento (solo mirar).
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/requirements/req_ui.php';
if (is_file(__DIR__ . '/lib/requirements/ReqSeguimiento.php')) require_once __DIR__ . '/lib/requirements/ReqSeguimiento.php';

$reqRepo  = new RequerimientoRepo();
$miembros = new MiembroRepo();
$mapa     = $miembros->mapa();
$id       = (int)($_GET['id'] ?? 0);
$req      = $reqRepo->buscar($id);
$yoId     = (int)(Auth::usuario()['id'] ?? 0);
$esGestor = Auth::esAdmin();
$listaUrl = $esGestor ? 'requerimientos.php' : 'bandeja.php';
if (!$req) {
    redirigir($listaUrl, 'Ese requerimiento ya no existe.', 'error');
}

$esResp      = RequerimientoRepo::tieneAsignado($req, $yoId);
$seguimiento = class_exists('ReqSeguimiento') ? array_map('intval', (array)(ReqSeguimiento::conf()['personas'] ?? [])) : [];
$puedeVer    = $esGestor || $esResp || Auth::esSupervisor() || in_array($yoId, $seguimiento, true) || (int)($req['creado_por'] ?? 0) === $yoId;
if (!$puedeVer) {
    redirigir('bandeja.php', 'Ese requerimiento no está asignado a ti.', 'error');
}
// Escriben (observaciones, marcar corregida) quien lo gestiona y quien lo tiene; el supervisor solo mira
$puedeEscribir = !Auth::esSupervisor() && ($esGestor || $esResp);
$aqui     = 'requerimiento.php?id=' . $id;
$estado   = RequerimientoRepo::estadoValido($req['estado'] ?? '');
$cerrado  = RequerimientoRepo::cerrado($req);
$cancelado = $estado === 'cancelado';
[$etEstado] = RequerimientoRepo::ESTADOS[$estado];
$prioridades = Catalogo::prioridades();
$prio     = (string)($req['prioridad'] ?? '');
[$plazoTxt, $plazoNivel, $plazoDetalle] = plazoDe($req);
$hilos    = RequerimientoRepo::hilosDe($req);
$corr     = RequerimientoRepo::correccionesDe($req);
$gente    = array_values(array_filter(array_map(fn($i) => $mapa[$i] ?? null, RequerimientoRepo::asignadosDe($req))));
$colab    = RequerimientoRepo::colaboradoresDe($req);
$instMapa = (new InstitucionRepo())->mapa();
$insts    = array_values(array_filter(array_map(fn($i) => $instMapa[$i] ?? null, RequerimientoRepo::institucionesDe($req))));
$historial = array_reverse(RequerimientoRepo::historialDe($req));
$adjuntos = array_values(array_filter((array)($req['adjuntos'] ?? []), 'is_array'));
$nombreDe = fn(int $mid) => (string)($mapa[$mid]['nombre'] ?? 'Alguien');
$estTono  = ['pendiente' => 'warning', 'asignado' => 'info', 'hecho' => 'success', 'cancelado' => 'danger', 'descartado' => 'neutral'][$estado] ?? 'neutral';

/** El texto de una observación o del detalle: HTML ya saneado, o el texto plano de antes. */
$rico = function (string $t): string {
    return str_contains($t, '<') ? HtmlRico::limpiar($t) : nl2br(e($t));
};
/** Ícono de un documento según su extensión. */
$icoAdj = fn(string $ext) => match (strtolower($ext)) {
    'pdf' => 'fa-file-pdf', 'doc', 'docx' => 'fa-file-word', 'xls', 'xlsx', 'csv' => 'fa-file-excel',
    'png', 'jpg', 'jpeg', 'gif', 'webp' => 'fa-file-image', default => 'fa-file',
};

// Lo que hace falta para Editar y Asignar (solo el administrador)
if ($esGestor && !$cerrado) {
    [$carga, $opcionesRol] = cargaParaAsignar($miembros, $reqRepo, new TareaRepo());
    $opcionesInst = [];
    foreach ((new InstitucionRepo())->todas() as $i) $opcionesInst[(int)$i['id']] = $i['nombre'];
}

UI::inicio($req['titulo'] ?? 'Requerimiento', $esGestor ? 'requerimientos' : 'bandeja');

// --- Encabezado: qué es y qué se puede hacer, con «Regresar» a la derecha ---
$sub = '<span class="mc-status mc-status--pill mc-status--' . $estTono . '"><span class="mc-status__content"><span class="mc-status__label">' . e($etEstado) . '</span></span></span>'
    . (isset($prioridades[$prio]) ? UI::badgePrioridad($prio) : '')
    . '<span class="req-plazo plazo-' . e($plazoNivel) . '" title="' . e($plazoDetalle) . '">' . e($plazoTxt) . '</span>'
    . ($corr['pendientes'] ? '<a class="rq-chip rq-chip--' . ($corr['vencidas'] ? 'mal' : 'pend') . '" href="#observaciones">' . UI::icono('ds-reloj') . ' '
        . $corr['pendientes'] . ($corr['pendientes'] === 1 ? ' corrección' : ' correcciones') . ' pendiente' . ($corr['pendientes'] === 1 ? '' : 's')
        . ($corr['vencidas'] ? ' · ' . $corr['vencidas'] . ' vencida' . ($corr['vencidas'] === 1 ? '' : 's') : '') . '</a>' : '');

$boton = fn(string $icono, string $tip, string $attrs, string $clase = '') =>
    '<button type="button" class="rs-boton rs-boton--icono' . ($clase !== '' ? ' ' . $clase : '') . '" aria-label="' . e($tip) . '" data-ds-tip="' . e($tip) . '" ' . $attrs . '>' . UI::icono($icono) . '</button>';
$acciones = '';
if ($esGestor && !Auth::esSupervisor()) {
    if (!$cerrado) {
        $acciones .= $boton('ds-editar', 'Editar', 'onclick="document.getElementById(\'dlg-req-editar\').showModal()"')
            . $boton('ds-usuarios', 'Asignar', 'onclick="document.getElementById(\'dlg-req-derivar\').showModal()"')
            . $boton('fa-ban', 'Cancelar el requerimiento', 'onclick="document.getElementById(\'dlg-rq-cancelar\').showModal()"', 'rq-peligro');
    }
    $acciones .= '<form method="post" action="actions.php" class="inline-form" data-confirmar="Se borrará este requerimiento con sus observaciones." data-confirmar-titulo="¿Eliminar?" data-confirmar-ok="Sí, eliminar">'
        . '<input type="hidden" name="accion" value="req_eliminar"><input type="hidden" name="id" value="' . $id . '">'
        . '<button class="rs-boton rs-boton--icono rq-peligro" aria-label="Eliminar" data-ds-tip="Eliminar">' . UI::icono('ds-eliminar') . '</button></form>';
    if ($cancelado) {
        $acciones .= '<form method="post" action="actions.php" class="inline-form"><input type="hidden" name="accion" value="req_reabrir"><input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="volver" value="' . e($aqui) . '"><button class="rs-boton rs-boton--icono" aria-label="Reabrir" data-ds-tip="Reabrir">' . UI::icono('RotateLeft') . '</button></form>';
    } elseif (!$cerrado) {
        $acciones .= '<form method="post" action="actions.php" class="inline-form"><input type="hidden" name="accion" value="req_estado"><input type="hidden" name="id" value="' . $id . '">'
            . '<input type="hidden" name="estado" value="hecho"><input type="hidden" name="volver" value="' . e($aqui) . '">'
            . '<button class="rs-boton rs-boton--icono rs-boton--primario" aria-label="Marcar resuelto" data-ds-tip="Marcar resuelto">' . UI::icono('ds-check') . '</button></form>';
    }
} elseif ($esResp && !$cerrado && !Auth::esSupervisor()) {
    $acciones .= $boton('ds-usuarios', 'Sumar a alguien que te ayude', 'onclick="document.getElementById(\'dlg-rq-colab\').showModal()"')
        . '<button type="button" class="rs-boton rs-boton--icono rs-boton--primario" aria-label="Marcar terminado" data-ds-tip="Marcar terminado" onclick="document.getElementById(\'dlg-rq-terminar\').showModal()">' . UI::icono('ds-check') . '</button>';
}
// «Regresar» va al final, a la derecha de todo, y como los demás: solo el ícono
$regresar = '<a class="rs-boton rs-boton--icono" href="' . e($listaUrl) . '" aria-label="Regresar" data-ds-tip="Regresar a ' . ($esGestor ? 'Requerimientos' : 'tus requerimientos') . '">'
    . '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/></svg></a>';
?>
<header class="page-head ru-cab rq-cab">
  <div class="ru-cab__main"><div class="ru-cab__txt">
    <p class="rq-cab__eyebrow">Requerimiento #<?= $id ?><?= !empty($req['solicitante']) ? ' · lo pide ' . e((string)$req['solicitante']) : '' ?></p>
    <h1 class="page-title font-display"><?= e((string)$req['titulo']) ?></h1>
    <p class="page-sub ru-cab__sub"><?= $sub ?></p>
  </div></div>
  <div class="page-actions ru-cab__acc"><?= $acciones !== '' ? $acciones . '<span class="ru-cab__sep" aria-hidden="true"></span>' : '' ?><?= $regresar ?></div>
</header>

<div class="rq-grid">
  <div class="rq-main">

    <?php if ($cancelado): ?>
    <section class="rq-card rq-aviso rq-aviso--mal">
      <h2><?= UI::icono('fa-ban') ?> Se canceló</h2>
      <p><?= e(trim((string)($req['motivo_cancelacion'] ?? '')) ?: 'No se anotó el motivo.') ?></p>
      <small><?= e(implode(' · ', array_filter([(int)($req['cancelado_por'] ?? 0) ? 'Lo canceló ' . $nombreDe((int)$req['cancelado_por']) : '', (string)($req['cancelado_en'] ?? '')]))) ?></small>
    </section>
    <?php endif; ?>

    <?php if (trim((string)($req['nota_cierre'] ?? '')) !== ''): ?>
    <section class="rq-card rq-aviso<?= $cerrado ? ' rq-aviso--ok' : '' ?>">
      <h2><?= UI::icono('ds-check') ?> <?= $cerrado ? 'Cómo se entregó' : 'La entrega anterior (volvió a abrirse)' ?></h2>
      <p><?= e((string)$req['nota_cierre']) ?></p>
      <small><?= e(implode(' · ', array_filter([(int)($req['cerrado_por'] ?? 0) ? 'por ' . $nombreDe((int)$req['cerrado_por']) : '', (string)($req['cerrado_en'] ?? '')]))) ?></small>
    </section>
    <?php endif; ?>

    <section class="rq-card">
      <h2 class="rq-card__tit">Detalle</h2>
      <?php $det = trim((string)($req['detalle'] ?? '')); ?>
      <div class="rt-render rq-detalle<?= $det === '' ? ' rq-vacio' : '' ?>"><?= $det !== '' ? $rico($det) : 'Sin detalle.' ?></div>
    </section>

    <!-- Observaciones: la conversación sobre el requerimiento. Con plazo son
         correcciones: dicen hasta cuándo y se marcan «Corregida» al cumplirlas. -->
    <section class="rq-card" id="observaciones">
      <header class="rq-card__cab">
        <h2 class="rq-card__tit">Observaciones <?= $hilos ? '<span class="tabla-count">' . count($hilos) . '</span>' : '' ?></h2>
        <?php if ($corr['total']): ?>
        <span class="rq-resumen">
          <?= $corr['pendientes']
              ? $corr['pendientes'] . ' de ' . $corr['total'] . ' correcciones pendientes' . ($corr['proxima'] !== '' ? ' · la próxima vence el ' . e(fechaLargaReq($corr['proxima'])) : '')
              : 'Las ' . $corr['total'] . ' correcciones están hechas' ?>
        </span>
        <?php endif; ?>
      </header>

      <?php if (!$hilos): ?>
      <p class="rq-vacio">Todavía no hay ninguna.<?= $puedeEscribir ? ' Escribe la primera abajo.' : '' ?></p>
      <?php else: ?>
      <ol class="rq-obs-lista">
        <?php foreach ($hilos as $o):
            $esCorr = $o['dias'] > 0; ?>
        <li class="rq-obs<?= $esCorr ? ' rq-obs--corr' . ($o['corregida'] ? ' is-ok' : '') : '' ?>" id="obs-<?= (int)$o['id'] ?>">
          <div class="rq-obs__cab">
            <?= UI::avatar($mapa[$o['autor']] ?? null, 30) ?>
            <span class="rq-obs__quien"><b><?= e($nombreDe($o['autor'])) ?></b><small><?= e($o['creado']) ?></small></span>
            <?= chipCorreccion($o) ?>
          </div>
          <div class="rt-render rq-obs__txt"><?= $rico((string)$o['texto']) ?></div>
          <?php if ($esCorr): ?>
          <p class="rq-obs__plazo">
            <?= $o['corregida']
                ? 'Corregida el ' . e($o['corregida_en']) . ($o['corregida_por'] ? ' por ' . e($nombreDe($o['corregida_por'])) : '') . '.'
                : 'Plazo: ' . (int)$o['dias'] . ' día' . ($o['dias'] === 1 ? '' : 's') . ' hábil' . ($o['dias'] === 1 ? '' : 'es') . ', hasta el ' . e(fechaLargaReq($o['vence'])) . '.' ?>
          </p>
          <?php endif; ?>
          <?php foreach ($o['respuestas'] as $rr): ?>
          <div class="rq-resp">
            <div class="rq-obs__cab"><?= UI::avatar($mapa[$rr['autor']] ?? null, 24) ?>
              <span class="rq-obs__quien"><b><?= e($nombreDe($rr['autor'])) ?></b><small><?= e($rr['creado']) ?></small></span></div>
            <div class="rt-render rq-obs__txt"><?= $rico((string)$rr['texto']) ?></div>
          </div>
          <?php endforeach; ?>
          <?php if ($puedeEscribir): ?>
          <div class="rq-obs__acc">
            <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-rq-responder="<?= (int)$o['id'] ?>" data-autor="<?= e(explode(' ', trim($nombreDe($o['autor'])))[0]) ?>">
              <?= UI::iconoDS('mensajes', 16) ?><span class="mc-btn__label">Responder</span></button>
            <?php if ($esCorr): ?>
            <form method="post" action="actions.php" class="inline-form">
              <input type="hidden" name="accion" value="req_obs_estado">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="obs_id" value="<?= (int)$o['id'] ?>">
              <input type="hidden" name="corregida" value="<?= $o['corregida'] ? '0' : '1' ?>">
              <input type="hidden" name="volver" value="<?= e($aqui) ?>">
              <?php if ($o['corregida']): ?>
              <button class="mc-btn mc-btn--ghost mc-btn--sm"><?= UI::icono('RotateLeft') ?><span class="mc-btn__label">Sigue pendiente</span></button>
              <?php else: ?>
              <button class="mc-btn mc-btn--secondary mc-btn--sm"><?= UI::iconoDS('check', 16) ?><span class="mc-btn__label">Marcar corregida</span></button>
              <?php endif; ?>
            </form>
            <?php endif; ?>
          </div>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>

      <?php if ($puedeEscribir):
          $nombresResp = array_map(fn($m) => explode(' ', trim($m['nombre']))[0], array_filter($gente, fn($m) => (int)$m['id'] !== $yoId)); ?>
      <form method="post" action="actions.php" class="rq-nueva" id="rq-nueva">
        <input type="hidden" name="accion" value="req_observar">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="volver" value="<?= e($aqui) ?>">
        <input type="hidden" name="padre_id" value="0" data-rq-padre>
        <h3 class="rq-nueva__tit" data-rq-nueva-tit>Nueva observación</h3>
        <p class="rq-respondiendo" data-rq-respondiendo hidden><span></span>
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-rq-soltar aria-label="Dejar de responder"><?= UI::iconoDS('cerrar', 16) ?></button></p>
        <?= UI::editorRico(['name' => 'texto', 'id' => 'rq-obs-editor', 'clase' => 'rt--obs', 'placeholder' => 'Escribe la observación o la corrección… (puedes pegar tablas)']) ?>
        <?php if ($esGestor): ?>
        <div data-rq-solo-hilo><?= campoPlazoCorreccion('dias') ?></div>
        <?php endif; ?>
        <div class="rq-nueva__pie">
          <small class="campo-ayuda" data-rq-para><?= $nombresResp
              ? 'Le llega a la campana y por correo a ' . e(implode(', ', $nombresResp)) . '.'
              : 'Nadie más lo tiene asignado: queda anotada, sin correo.' ?></small>
          <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('enviar', 18) ?><span class="mc-btn__label" data-rq-enviar>Enviar</span></button>
        </div>
      </form>
      <?php endif; ?>
    </section>
  </div>

  <aside class="rq-lado">
    <section class="rq-card rq-datos">
      <div><span>Lo pide</span><b><?= e(trim((string)($req['solicitante'] ?? '')) ?: '—') ?></b></div>
      <div><span>Registrado</span><b><?= e(fechaCortaReq(substr((string)($req['creado'] ?? ''), 0, 10)) ?: '—') ?></b></div>
      <div><span>Inicio</span><b><?= ($ini = RequerimientoRepo::fechaInicio($req)) !== '' ? e(fechaCortaReq($ini)) : '—' ?></b></div>
      <div><span>Entrega</span><b><?= ($fin = RequerimientoRepo::fechaFin($req)) !== '' ? e(fechaCortaReq($fin)) : '—' ?></b></div>
      <?php if ($insts): ?>
      <div class="rq-datos__ancho"><span>Institución</span>
        <span class="req-f-inst"><?php foreach ($insts as $ii): ?><span class="req-inst-chip" title="<?= e($ii['nombre']) ?>">
          <?php if (!empty($ii['imagen'])): ?><img src="<?= e($ii['imagen']) ?>" alt=""><?php else: ?><i class="fa-solid fa-building-columns"></i><?php endif; ?>
          <span class="truncate"><?= e($ii['nombre']) ?></span></span><?php endforeach; ?></span></div>
      <?php endif; ?>
    </section>

    <section class="rq-card">
      <h2 class="rq-card__tit">Responsables</h2>
      <?php if (!$gente): ?>
      <p class="rq-vacio">Todavía no está asignado a nadie.</p>
      <?php else: ?>
      <ul class="rq-gente">
        <?php foreach ($gente as $m): $c = $colab[(int)$m['id']] ?? null; ?>
        <li><?= UI::avatar($m, 32) ?><span><b><?= e($m['nombre']) ?></b>
          <small><?= $c ? 'Colaborador · lo sumó ' . e(explode(' ', trim($nombreDe($c['por'])))[0]) : e(trim((string)($m['rol'] ?? ''))) ?></small></span></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </section>

    <?php if ($adjuntos): ?>
    <section class="rq-card">
      <h2 class="rq-card__tit">Documentos</h2>
      <div class="dt-adjuntos rq-docs">
        <?php foreach ($adjuntos as $a): $ext = strtolower((string)($a['ext'] ?? pathinfo((string)($a['nombre'] ?? ''), PATHINFO_EXTENSION))); ?>
        <a class="adj-chip" href="<?= e((string)($a['ruta'] ?? '')) ?>" target="_blank" rel="noopener" title="Abrir <?= e((string)($a['nombre'] ?? '')) ?>">
          <i class="fa-solid <?= $icoAdj($ext) ?>"></i><span><?= e((string)($a['nombre'] ?? 'Documento')) ?></span></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($historial):
        // Línea de tiempo: de lo más nuevo a lo más viejo, cada paso con su ícono,
        // la fecha corta («Hoy, 16:42») y quién, con el nombre corto (el completo al pasar el ratón).
        $hoyH = date('Y-m-d');
        $ayerH = date('Y-m-d', strtotime('-1 day'));
        $cuandoH = function (string $f) use ($hoyH, $ayerH): string {
            $d = substr($f, 0, 10);
            $hora = substr($f, 11, 5);
            $dia = $d === $hoyH ? 'Hoy' : ($d === $ayerH ? 'Ayer' : fechaCortaReq($d));
            return $dia . ($hora !== '' ? ', ' . $hora : '');
        };
        $cortoH = function (string $n): string {
            $p = preg_split('/\s+/', trim($n)) ?: [];
            return match (true) { count($p) >= 4 => $p[0] . ' ' . $p[2], count($p) === 3 => $p[0] . ' ' . $p[1], default => trim($n) };
        };
        $tipoH = fn(string $t) => match (true) {
            (bool)preg_match('/reabri|volvi[oó] a abrirse/i', $t) => ['RotateLeft', 'aviso'],
            (bool)preg_match('/cancel/i', $t)                     => ['fa-ban', 'mal'],
            (bool)preg_match('/terminad|resuelt|corregida/i', $t) => ['ds-check', 'ok'],
            (bool)preg_match('/correcci|pendiente otra/i', $t)    => ['ds-reloj', 'aviso'],
            (bool)preg_match('/asign|sum[oó]|colaborador/i', $t)  => ['ds-usuarios', 'info'],
            (bool)preg_match('/registr|cre[oó]/i', $t)            => ['ds-mas', 'info'],
            default                                              => ['ds-editar', 'neutro'],
        }; ?>
    <section class="rq-card rq-hist-card">
      <details class="rq-hist"<?= count($historial) <= 5 ? ' open' : '' ?>>
        <summary>
          <span class="rq-card__tit">Trazabilidad <span class="tabla-count"><?= count($historial) ?></span></span>
          <span class="rq-hist__chev" aria-hidden="true"><?= UI::iconoDS('chevron-abajo', 18) ?></span>
        </summary>
        <ol class="rq-tl">
          <?php foreach ($historial as $h):
              [$icoH, $tonoH] = $tipoH((string)$h['texto']);
              $quienH = (int)($h['quien'] ?? 0) > 0 ? $nombreDe((int)$h['quien']) : ''; ?>
          <li class="rq-tl__paso rq-tl--<?= $tonoH ?>">
            <span class="rq-tl__ico" aria-hidden="true"><?= UI::icono($icoH) ?></span>
            <div class="rq-tl__txt">
              <p><?= e((string)$h['texto']) ?></p>
              <small><time datetime="<?= e(str_replace(' ', 'T', (string)($h['fecha'] ?? ''))) ?>" title="<?= e((string)($h['fecha'] ?? '')) ?>"><?= e($cuandoH((string)($h['fecha'] ?? ''))) ?></time>
                · <span title="<?= e($quienH ?: 'Lo hizo el panel solo') ?>"><?= e($quienH !== '' ? $cortoH($quienH) : 'El panel') ?></span></small>
            </div>
          </li>
          <?php endforeach; ?>
        </ol>
      </details>
    </section>
    <?php endif; ?>
  </aside>
</div>

<?php if ($esGestor && !$cerrado && !Auth::esSupervisor()):
    dialogoAsignarReq($req, $carga, $opcionesRol, $mapa, $aqui);
    dialogoEditarReq($req, $carga, $opcionesRol, $prioridades, $opcionesInst, $mapa, $aqui); ?>
<dialog class="ds-modal" id="dlg-rq-cancelar" aria-labelledby="dlg-rq-can-t">
  <form method="post" action="actions.php" class="ds">
    <input type="hidden" name="accion" value="req_cancelar">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="volver" value="<?= e($aqui) ?>">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--rojo"><?= UI::icono('fa-ban') ?></span>
      <div><h2 class="ds-modal__title" id="dlg-rq-can-t">Cancelar el requerimiento</h2>
        <p class="ds-modal__sub">No se borra: queda en «Cerrados» con el motivo, y les llega a quienes lo tenían.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body">
      <label class="campo"><span>¿Por qué se cancela?</span>
        <textarea class="input-meca" name="motivo" rows="3" maxlength="600" required placeholder="Ej. El cliente ya no lo necesita: lo resolvieron por su cuenta"></textarea></label>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Volver</span></button>
      <button class="mc-btn mc-btn--danger mc-btn--md"><?= UI::icono('fa-ban') ?><span class="mc-btn__label">Cancelar requerimiento</span></button>
    </footer>
  </form>
</dialog>
<?php endif; ?>

<?php if ($esResp && !$esGestor && !$cerrado && !Auth::esSupervisor()): ?>
<dialog class="ds-modal" id="dlg-rq-terminar" aria-labelledby="dlg-rq-ter-t">
  <form method="post" action="actions.php" class="ds">
    <input type="hidden" name="accion" value="req_terminar">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="volver" value="<?= e($aqui) ?>">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--verde"><?= UI::iconoDS('check', 18) ?></span>
      <div><h2 class="ds-modal__title" id="dlg-rq-ter-t">Marcar como terminado</h2>
        <p class="ds-modal__sub">Le avisamos a quien te lo asignó.<?= $corr['pendientes'] ? ' Ojo: tiene ' . $corr['pendientes'] . ($corr['pendientes'] === 1 ? ' corrección' : ' correcciones') . ' sin marcar como corregida.' : '' ?></p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body">
      <label class="campo"><span>¿Cómo lo dejaste? <small>(rama, commits, dónde quedó…)</small></span>
        <textarea class="input-meca" name="nota" rows="3" maxlength="600" placeholder="Ej. Subido en la rama feature/nota-credito con los commits ab12cd y 34ef56"></textarea></label>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Marcar terminado</span></button>
    </footer>
  </form>
</dialog>

<dialog class="ds-modal" id="dlg-rq-colab" aria-labelledby="dlg-rq-col-t">
  <form method="post" action="actions.php" class="ds">
    <input type="hidden" name="accion" value="req_colaborador">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="volver" value="<?= e($aqui) ?>">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('usuarios', 18) ?></span>
      <div><h2 class="ds-modal__title" id="dlg-rq-col-t">Sumar a alguien que te ayude</h2>
        <p class="ds-modal__sub">Le llega como a ti y queda anotado que lo sumaste. Las fechas las cambia el administrador.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body">
      <?php $opsCol = [];
        foreach ($miembros->todos() as $mC) {
            if (RequerimientoRepo::tieneAsignado($req, (int)$mC['id'])) continue;
            $opsCol[(int)$mC['id']] = $mC['nombre'] . (trim((string)($mC['rol'] ?? '')) !== '' ? ' · ' . $mC['rol'] : '');
        } ?>
      <label class="campo"><span>¿Quién te ayuda?</span>
        <?= UI::selectDS('colaborador', $opsCol, '', ['vacio' => 'Elige a alguien del equipo…', 'buscar' => true, 'aria' => 'Quién te ayuda']) ?></label>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Sumar</span></button>
    </footer>
  </form>
</dialog>
<?php endif; ?>

<link rel="stylesheet" href="<?= asset('assets/req-reparto.css') ?>">
<script src="<?= asset('assets/req-reparto.js') ?>" defer></script>
<?php UI::fin(); ?>
