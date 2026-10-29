<?php
/**
 * El detalle de una reunión: todo lo que hay de ella (reunion.php?id=X[&dia=Y-m-d]).
 *
 *   · Arriba, el botón de volver (a la suite del proyecto; con &desde=historial
 *     al historial y con &desde=proyecto a la pestaña del proyecto), el tema,
 *     el proyecto como chip y, a la derecha, botones redondos de vidrio:
 *     eliminar (rojo suave, aparte), la pizarra, copiar, iniciar como
 *     anfitrión, editar y Entrar (verde). Editar abre el asistente de
 *     proyecto.php (?editar_reunion=ID&volver=…) y, al guardar, actions.php
 *     vuelve aquí; eliminar (reunion_eliminar de siempre) vuelve a la suite.
 *   · La tarjeta de «cuándo»: la fecha y la hora grandes, su estado (en
 *     curso, empieza pronto, próxima o realizada) con su cuenta regresiva,
 *     la repetición y los datos para entrar (plataforma, código,
 *     contraseña, enlace), con «Entrar» y copiar.
 *   · Las que se repiten: la tira de sus días; al elegir uno, todo lo de
 *     abajo es de ese día.
 *   · Los invitados con su asistencia (quién pulsó «Entrar» en el panel:
 *     reunion_ingresos) y lo que quedó: la grabación (de Drive, la lógica de
 *     siempre), la transcripción (el formulario de descarga data-descarga),
 *     el acta con IA (los mismos data-* que maneja assets/ia.js) y la pizarra.
 *   · Las observaciones ligadas a la reunión (en una serie, las de ese día).
 *
 * El pintado compartido está en lib/reuniones_ui.php; los estilos, en
 * admin.css («Reuniones: la suite…»).
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/reuniones_ui.php';

$r = (new ReunionRepo())->buscar((int)($_GET['id'] ?? 0));
if (!$r || !puedeVerProyecto((int)($r['proyecto_id'] ?? 0))) {
    redirigir('index.php', 'Esa reunión no está disponible.', 'error');
}
if (esSupervisor()) {
    redirigir('index.php', 'Las reuniones no son parte de tu vista de supervisión.', 'error');
}

$ahora    = time();
$pid      = (int)$r['proyecto_id'];
$proyecto = (new ProyectoRepo())->buscar($pid) ?? ['id' => $pid, 'nombre' => 'Proyecto'];
$miembros = (new MiembroRepo())->mapa();
$serie    = Reuniones::esRecurrente($r);
$plat     = in_array($r['plataforma'] ?? 'zoom', ['zoom', 'meet', 'enlace'], true) ? (string)($r['plataforma'] ?? 'zoom') : 'zoom';
$tema     = ReunionesUI::tema($r);
$vez      = ReunionesUI::vezDelDia($r, (string)($_GET['dia'] ?? ''), $proyecto, $ahora);
$rastros  = ReunionesUI::rastros([$r]);
$estado   = $vez['estado'] ?? 'paso';
$paso     = $estado === 'paso';
$diaOc    = $vez && $serie ? $vez['dia'] : '';            // el día, solo en las que se repiten (como en proyecto.php)
$puedeReuniones = puedeReunionesDelProyecto($pid);
$puedeEditar    = $puedeReuniones && ReunionesUI::plataformasListas();   // sin Zoom ni Meet, proyecto.php no trae el asistente
$puedeActa      = UI::ia('acta_reunion') && puedeGestionar($pid);
$plural = fn(int $n, string $uno, string $varios) => $n . ' ' . ($n === 1 ? $uno : $varios);
$entrar = ReunionesUI::enlaceEntrar($r);
$nombreP = (string)$proyecto['nombre'];

/* ---------- De dónde se llegó: a dónde vuelve el botón y adónde lleva editar o eliminar ---------- */
$desde = in_array($_GET['desde'] ?? '', ['historial', 'proyecto'], true) ? (string)$_GET['desde'] : '';
[$volver, $volverTip] = match ($desde) {
    'proyecto'  => ['proyecto.php?id=' . $pid . '#vista-reuniones', 'Volver a ' . $nombreP],
    'historial' => ['reuniones.php?p=' . $pid . '&ver=historial', 'Volver al historial de ' . $nombreP],
    default     => ['reuniones.php?p=' . $pid, 'Volver a las reuniones de ' . $nombreP],
};
$deDia = fn(string $dia) => 'reunion.php?id=' . (int)$r['id'] . '&dia=' . $dia . ($desde !== '' ? '&desde=' . $desde : '');
// Tras editar se vuelve a este mismo detalle; tras eliminar, a la suite (o al historial). actions.php lo valida (volverAqui).
$aqui = $vez && $serie ? $deDia($vez['dia']) : 'reunion.php?id=' . (int)$r['id'] . ($desde !== '' ? '&desde=' . $desde : '');
$trasEliminar = $desde === 'proyecto' ? '' : $volver;

/* ---------- El encabezado: volver, el tema y, a la derecha, los botones redondos ---------- */
// Eliminar va primero y separado (en rojo suave); el principal, Entrar, al final y en verde.
$acciones = '';
if ($puedeReuniones) {
    $acciones .= '<form method="post" action="actions.php" class="inline-form"'
        . ' data-confirmar="Se eliminará la reunión «' . e($tema) . '»' . ($serie ? ' con todos sus días' : '') . ' del panel y de ' . ($plat === 'meet' ? 'Google Calendar' : 'Zoom') . '."'
        . ' data-confirmar-titulo="¿Eliminar reunión?" data-confirmar-ok="Sí, eliminar">'
        . '<input type="hidden" name="accion" value="reunion_eliminar"><input type="hidden" name="id" value="' . (int)$r['id'] . '">'
        . ($trasEliminar !== '' ? '<input type="hidden" name="volver" value="' . e($trasEliminar) . '">' : '')
        . ReunionesUI::botonCab('ds-eliminar', 'Eliminar la reunión', '', 'ru-borrar', '', 'submit') . '</form>'
        . '<span class="ru-cab__sep" aria-hidden="true"></span>';
}
if (ReunionesUI::hayPizarra()) {
    $acciones .= ReunionesUI::botonCab('pz-pizarra', 'Pizarra de la reunión: notas, Kanban y dibujos entre todos', 'pizarra.php?reunion=' . (int)$r['id']);
}
if (trim((string)($r['join_url'] ?? '')) !== '') {
    $acciones .= ReunionesUI::botonCab('ds-copiar', 'Copiar el enlace de la reunión', '', 'btn-copiar', 'data-copiar="' . e(trim((string)$r['join_url'])) . '"');
}
if (!$paso && !empty($r['start_url'])) {
    $acciones .= ReunionesUI::botonCab('ds-play', 'Iniciar como anfitrión', (string)$r['start_url'], '', 'target="_blank" rel="noopener"');
}
if ($puedeEditar) {
    $acciones .= ReunionesUI::botonCab('ds-editar', 'Editar o invitar a más gente' . ($serie ? ' (toda la serie)' : ''),
        'proyecto.php?id=' . $pid . '&editar_reunion=' . (int)$r['id'] . '&volver=' . rawurlencode($aqui) . '#vista-reuniones');
}
if (!$paso && $entrar) {
    $ya = in_array($estado, ['vivo', 'pronto'], true);
    $acciones .= ReunionesUI::botonCab('ds-video', $ya ? 'Entrar a la reunión (queda anotado para la asistencia)' : 'Entrar a la reunión (todavía no empieza)',
        $entrar, $ya ? 'rs-boton--verde' : 'rs-boton--primario', 'target="_blank" rel="noopener"');
}
$sub = ReunionesUI::chipProyecto($proyecto, 'proyecto.php?id=' . $pid) . ReunionesUI::plataforma($r);

UI::inicio($tema . ' · Reuniones', 'proyecto-' . $pid);
ReunionesUI::cabecera($volver, $volverTip, e($tema), $sub, $acciones);

if (!$vez): ?>
<div class="ru ru-det">
  <?= ReunionesUI::vacio('CalendarRemove', 'Esta reunión no tiene fecha', 'Edítala para ponerle el día y la hora.') ?>
</div>
<?php UI::fin(); return; endif;

/* ---------- Cuándo ---------- */
$etiqueta = ['vivo' => 'En curso', 'pronto' => 'Empieza pronto', 'luego' => 'Próxima', 'paso' => 'Realizada'][$estado];
$creador = $miembros[(int)($r['creador_id'] ?? 0)] ?? null;
$codigo = ReunionesUI::codigo($r);
$enlace = trim((string)($r['join_url'] ?? ''));
$enlaceVer = preg_replace('#^https?://(www\.)?#i', '', $enlace);
[$diaA, $diaB] = ReunionesUI::diaTitulo($vez['ts'], $ahora);
$esRelativo = in_array(mb_strtolower($diaA), ['hoy', 'mañana', 'ayer'], true);
?>

<div class="ru ru-det">
  <section class="ru-hero is-<?= $estado ?>" aria-label="Cuándo">
    <div class="ru-hero__main">
      <div class="ru-hero__top">
        <span class="ru-tag ru-tag--<?= $estado ?>"><?php if ($estado === 'vivo'): ?><span class="rs-punto-vivo" aria-hidden="true"></span><?php endif; ?><?= e($etiqueta) ?></span>
        <?php if (!$paso): ?>
        <span class="rs-cuenta"><?= UI::icono('ds-reloj') ?> <?= $estado === 'vivo' ? 'termina' : 'empieza' ?>
          <b data-cuenta-regresiva data-corto data-ya="<?= $estado === 'vivo' ? 'ya terminó' : 'ya empezó' ?>" data-hasta="<?= $estado === 'vivo' ? $vez['fin'] : $vez['ts'] ?>" data-ahora="<?= $ahora ?>">&nbsp;</b></span>
        <?php endif; ?>
      </div>
      <b class="ru-hero__cuando"><?= e(ReunionesUI::cuando($vez['ts'], $ahora)) ?></b>
      <p class="ru-hero__sub">
        <?php if ($esRelativo): ?><span><?= e($diaB) ?></span><span class="ru-sep" aria-hidden="true">·</span><?php endif; ?>
        <span>De <?= e(ReunionesUI::hora($vez['ts'])) ?> a <?= e(ReunionesUI::hora($vez['fin'])) ?></span><span class="ru-sep" aria-hidden="true">·</span>
        <span><?= ReunionesUI::duracion($r) ?> min</span>
      </p>
      <?php if ($serie): ?>
      <p class="ru-hero__sub ru-repite"><?= UI::icono('RepeatCircle') ?> Se repite: <?= e(ReunionesUI::repeticion($r)) ?></p>
      <?php endif; ?>
    </div>
    <?php /* Entrar, copiar e iniciar como anfitrión están arriba, en el encabezado */ ?>
    <dl class="ru-datos">
      <div><dt>Plataforma</dt><dd><?= e(Reuniones::etiquetaPlataforma($plat)) ?><?php if ($plat === 'enlace'): ?> <small>no queda grabada</small><?php endif; ?></dd></div>
      <?php if ($codigo !== ''): ?><div><dt><?= $plat === 'meet' ? 'Código' : 'ID de la reunión' ?></dt><dd class="ru-datos__num"><?= e($codigo) ?></dd></div><?php endif; ?>
      <?php if (trim((string)($r['password'] ?? '')) !== ''): ?><div><dt>Contraseña</dt><dd class="ru-datos__num"><?= e((string)$r['password']) ?></dd></div><?php endif; ?>
      <?php if ($enlace !== ''): ?><div class="ru-datos__ancho"><dt>Enlace</dt><dd><a class="ru-enlace" href="<?= e($enlace) ?>" target="_blank" rel="noopener" title="<?= e($enlace) ?>"><?= e($enlaceVer) ?></a></dd></div><?php endif; ?>
      <?php if ($creador): ?><div><dt>La creó</dt><dd class="ru-datos__quien"><?= UI::avatar($creador, 22) ?> <?= e((string)$creador['nombre']) ?></dd></div><?php endif; ?>
    </dl>
  </section>

  <?php if ($serie):
      // La tira de días: cada vez de la serie; el elegido lleva el canto de vidrio
      $veces = array_map(fn($t) => ReunionesUI::vez($r, $t, $proyecto, $ahora), ReunionesUI::inicios($r));
      $nPasaron = count(array_filter($veces, fn($v) => $v['estado'] === 'paso'));
      $mesesC = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
      $diasC  = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
  ?>
  <section class="ru-card ru-serie-card" aria-labelledby="ru-serie-t">
    <header class="ru-card__cab">
      <h2 class="ru-card__t" id="ru-serie-t">Días de la reunión</h2>
      <span class="rs-card__extra"><?= $nPasaron ?> de <?= count($veces) ?> ya pasaron</span>
    </header>
    <div class="ru-serie" data-ru-serie>
      <?php $mesAnt = ''; foreach ($veces as $v):
          $mes = date('Y-n', $v['ts']);
          if ($mes !== $mesAnt): $mesAnt = $mes; ?>
      <span class="ru-serie__mes" aria-hidden="true"><?= e($mesesC[(int)date('n', $v['ts']) - 1]) ?></span>
      <?php endif;
          $ok = $v['estado'] === 'paso' && (ReunionesUI::entraron($v, $rastros) || ReunionesUI::grabacion($r, $v['dia'], $v['fin'], $rastros)['estado'] === 'lista');
          $elegido = $v['dia'] === $vez['dia'];
          $txt = ReunionesUI::cuando($v['ts'], $ahora) . ' · ' . ['vivo' => 'en curso', 'pronto' => 'empieza pronto', 'luego' => 'por venir', 'paso' => 'ya pasó'][$v['estado']];
      ?>
      <a class="ru-chip is-<?= $v['estado'] ?><?= $v['dia'] === date('Y-m-d', $ahora) ? ' is-hoy' : '' ?><?= $ok ? ' is-ok' : '' ?>" href="<?= e($deDia($v['dia'])) ?>"
         title="<?= e($txt) ?>"<?= $elegido ? ' aria-current="date"' : '' ?>>
        <small><?= e($diasC[(int)date('w', $v['ts'])]) ?></small><b><?= (int)date('j', $v['ts']) ?></b>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <div class="ru-det__grid">
    <?php
    /* ---------- Invitados y asistencia ---------- */
    $inv = ReunionesUI::invitados($r, $miembros);
    $entraron = ReunionesUI::entraron($vez, $rastros);
    $mirarAsistencia = in_array($estado, ['paso', 'vivo'], true);
    $idsInv = array_map(fn($m) => (int)$m['id'], $inv);
    // Primero quien entró (en el orden en que llegó), luego el resto por nombre
    usort($inv, function ($a, $b) use ($entraron) {
        $ea = $entraron[(int)$a['id']] ?? PHP_INT_MAX;
        $eb = $entraron[(int)$b['id']] ?? PHP_INT_MAX;
        return $ea <=> $eb ?: strcasecmp((string)$a['nombre'], (string)$b['nombre']);
    });
    $nEntraronInv = count(array_intersect($idsInv, array_keys($entraron)));
    $otros = array_values(array_filter(array_map(fn($mid) => $miembros[$mid] ?? null, array_diff(array_keys($entraron), $idsInv))));
    $pct = $inv ? (int)round($nEntraronInv * 100 / count($inv)) : 0;
    ?>
    <section class="ru-card" aria-labelledby="ru-gente-t">
      <header class="ru-card__cab">
        <h2 class="ru-card__t" id="ru-gente-t">Invitados</h2>
        <?php if ($inv): ?><span class="rs-card__extra"><?= $mirarAsistencia ? $nEntraronInv . ' de ' . count($inv) . ' entraron' : $plural(count($inv), 'invitado', 'invitados') ?></span><?php endif; ?>
      </header>
      <?php if ($inv && $mirarAsistencia): ?>
      <span class="rs-avance__pista ru-asist" role="img" aria-label="<?= $pct ?> % de los invitados entró"><span class="rs-avance__barra" style="--p:<?= $pct ?>%"></span></span>
      <?php endif; ?>
      <?php if ($inv): ?>
      <ul class="ru-personas">
        <?php foreach ($inv as $m): $mid = (int)$m['id']; $t = $entraron[$mid] ?? 0; ?>
        <li>
          <?= UI::avatar($m, 34) ?>
          <span class="ru-personas__txt"><b><?= e((string)$m['nombre']) ?></b><small><?= e((string)($m['rol'] ?? '')) ?><?= $creador && (int)$creador['id'] === $mid ? ' · la creó' : '' ?></small></span>
          <?php if ($t): ?>
          <span class="ru-tag ru-tag--ok">Entró <?= e(ReunionesUI::hora($t)) ?></span>
          <?php elseif ($mirarAsistencia): ?>
          <span class="ru-tag ru-tag--gris"><?= $estado === 'vivo' ? 'Todavía no' : 'No entró' ?></span>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
        <?= ReunionesUI::vacio('Users', 'Sin invitados', $puedeEditar ? 'Invita al equipo desde «Editar».' : 'Nadie quedó invitado a esta reunión.') ?>
      <?php endif; ?>
      <?php if ($otros): ?>
      <p class="rs-nota">También entró: <?= e(implode(', ', array_map(fn($m) => (string)$m['nombre'], $otros))) ?>.</p>
      <?php endif; ?>
      <?php if ($mirarAsistencia): ?>
      <p class="rs-nota">Cuenta a quien pulsó «Entrar» en el panel, de 15 minutos antes a 15 después.</p>
      <?php endif; ?>
    </section>

    <?php
    /* ---------- Lo que quedó: grabación, transcripción, acta y pizarra ---------- */
    $grab = $paso && $plat === 'zoom' ? ReunionesUI::grabacion($r, $diaOc, $vez['fin'], $rastros) : null;
    $trans = $paso && $plat === 'zoom' ? ReunionesUI::transcripcion($r, $diaOc, $vez['fin'], $rastros) : '';
    $pizarraLlena = null;
    if (ReunionesUI::hayPizarra() && is_file(__DIR__ . '/lib/Pizarra.php')) {
        try {
            require_once __DIR__ . '/lib/Pizarra.php';
            if (method_exists('Pizarra', 'version')) $pizarraLlena = Pizarra::version((int)$r['id']) > 0;
        } catch (Throwable $e) { $pizarraLlena = null; }   // sin saberlo, la pizarra igual se abre
    }
    $item = function (string $icono, string $tono, string $titulo, string $estadoTxt, string $accion = '') {
        return '<li class="ru-quedo__item"><span class="rs-ic rs-ic--' . $tono . '" aria-hidden="true">' . UI::icono($icono) . '</span>'
            . '<span class="ru-quedo__txt"><b>' . e($titulo) . '</b><small>' . e($estadoTxt) . '</small></span>' . $accion . '</li>';
    };
    ?>
    <section class="ru-card" aria-labelledby="ru-quedo-t">
      <header class="ru-card__cab">
        <h2 class="ru-card__t" id="ru-quedo-t"><?= $paso ? 'Lo que quedó' : 'Durante y después' ?></h2>
        <?php if ($serie): ?><span class="rs-card__extra"><?= e(mb_strtolower(ReunionesUI::diaTitulo($vez['ts'], $ahora)[0])) ?></span><?php endif; ?>
      </header>
      <ul class="ru-quedo">
        <?php
        // Grabación y transcripción: solo Zoom las trae (de Drive, lib/ZoomDrive.php)
        if ($plat === 'zoom') {
            if (!$paso) {
                echo $item('MonitorPlay', 'menta', 'Grabación y transcripción', 'Llegan aquí solas cuando termine (Drive las guarda cada hora).');
            } else {
                echo $item('MonitorPlay', 'menta', 'Grabación', match ($grab['estado']) {
                        'lista' => 'Guardada en Drive.',
                        'espera' => 'Esperando, aún no se ha guardado un respaldo.',
                        default => 'Sin grabación.',
                    },
                    $grab['estado'] === 'lista'
                        ? '<span class="ru-acc">' . ReunionesUI::ico('ds-enlace', 'Abrir la grabación en Drive', $grab['ver'], '', 'target="_blank" rel="noopener"')
                          . (is_file(__DIR__ . '/videoteca.php') ? ReunionesUI::ico('MonitorPlay', 'Verla aquí, en Reuniones', 'videoteca.php?v=' . (int)$r['id'] . '&dia=' . $vez['dia'], 'ru-ico--grab') : '') . '</span>'
                        : '');
                // La transcripción para Claude Code: la misma descarga de siempre (actions.php la lee de Drive)
                echo $item('NoteText', 'celeste', 'Transcripción', match ($trans) {
                        'lista' => 'Lista en Drive: bájala para llevarla a Claude Code.',
                        'espera' => 'Esperando, aún no se ha guardado un respaldo.',
                        default => 'Zoom no dejó transcripción de este día.',
                    }, ReunionesUI::formTranscripcion($r, $diaOc));
            }
        } else {
            echo $item('MonitorPlay', 'gris', 'Grabación', $plat === 'meet' ? 'Las de Google Meet no quedan grabadas en el panel.' : 'Con un enlace propio la reunión no queda grabada.');
        }
        // El acta con IA: los mismos data-* que el botón de siempre (assets/ia.js)
        if ($puedeActa) {
            echo $item('ds-ia', 'lila', 'Acta con IA', $paso ? 'Resumen, acuerdos y tareas, desde la transcripción o el texto que pegues.' : 'Cuando termine: resumen, acuerdos y tareas con IA.',
                $paso ? ReunionesUI::botonActa($r, $diaOc) : '');
        }
        if (ReunionesUI::hayPizarra()) {
            echo $item('pz-pizarra', 'azul', 'Pizarra', $pizarraLlena === true ? 'Tiene notas: queda después de la reunión.' : 'Notas, Kanban y dibujos entre todos, en vivo.',
                ReunionesUI::ico('pz-pizarra', 'Abrir la pizarra de la reunión', 'pizarra.php?reunion=' . (int)$r['id']));
        }
        ?>
      </ul>
    </section>
  </div>

  <?php
  /* ---------- Observaciones ligadas a la reunión ---------- */
  require_once __DIR__ . '/lib/obs_item.php';
  $todasObs = !empty($_GET['obs']);
  $hilos = array_values(array_filter((new ObservacionRepo())->hilosDelProyecto($pid), fn($o) => (int)($o['reunion_id'] ?? 0) === (int)$r['id']));
  $obsDia = $serie && !$todasObs ? array_values(array_filter($hilos, fn($o) => substr((string)($o['creado'] ?? ''), 0, 10) === $vez['dia'])) : $hilos;
  ?>
  <section class="ru-card" id="ru-obs" aria-labelledby="ru-obs-t">
    <header class="ru-card__cab">
      <h2 class="ru-card__t" id="ru-obs-t">Observaciones</h2>
      <span class="ru-card__der">
        <?php if ($obsDia): ?><span class="rs-card__extra"><?= count($obsDia) ?></span><?php endif; ?>
        <?= ReunionesUI::ico('ds-mas', 'Anotar una observación (en el proyecto, eligiendo esta reunión)', 'proyecto.php?id=' . $pid . '#vista-observaciones') ?>
      </span>
    </header>
    <?php if ($serie && count($hilos) !== count($obsDia)): ?>
    <p class="rs-nota"><?= $todasObs ? 'Las de todos los días.' : 'Las anotadas ese día.' ?>
      <a href="<?= e($deDia($vez['dia']) . ($todasObs ? '' : '&obs=todas')) ?>#ru-obs"><?= $todasObs ? 'Ver solo las de ese día' : 'Ver las de todos los días (' . count($hilos) . ')' ?></a></p>
    <?php endif; ?>
    <?php if ($obsDia): ?>
    <div class="obs-lista ru-obs"><?php foreach ($obsDia as $o) echo obsItemHtml($o); ?></div>
    <?php else: ?>
      <?= ReunionesUI::vacio('ds-mensajes', 'Sin observaciones', 'Al anotar una en el proyecto, elige esta reunión en «De la reunión» y sale aquí.') ?>
    <?php endif; ?>
  </section>
</div>

<?php UI::fin(); ?>
