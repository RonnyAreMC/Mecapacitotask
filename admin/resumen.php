<?php
/**
 * Dashboard › Resumen: el día de quien mira, sin las tarjetas de proyecto.
 *
 * Arriba del todo, el mismo control que en index.php (Proyectos | Resumen) y,
 * a su lado, el botón de lo más urgente. Debajo, de arriba abajo:
 *   · la tira de «Hoy», con la piel de la tira de despliegues (vidrio, borde
 *     que gira, celeste y menta): cuántas vencen hoy, cuántas van atrasadas,
 *     las reuniones del día, lo que revisa como PO y lo que le llegó;
 *   · «Lo de hoy»: sus entregas (atrasadas, hoy y lo de la semana), la agenda
 *     del día con la reunión que sigue destacada (cuenta regresiva y Entrar),
 *     lo que espera su visto bueno si es PO y lo que le espera (correo,
 *     requerimientos, pedidos);
 *   · «Cómo vas»: el anillo de sus tareas terminadas con el reparto por estado,
 *     lo que le cae cada día de la semana y el avance de sus proyectos.
 *
 * La idea viene del inicio de EDCON (secciones que se pliegan y se recuerdan,
 * filas con su círculo o su fecha, cifras con su rótulo, una frase con «tu
 * siguiente paso»). Los datos: lib/ResumenPersona.php. Estilos: admin.css
 * (bloque «Resumen»); el plegado y los números que cuentan: admin.js.
 *
 * Con «ver como» activo es el resumen de esa persona (sin su correo).
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/ResumenPersona.php';
if (is_file(__DIR__ . '/lib/Cronograma.php')) require_once __DIR__ . '/lib/Cronograma.php';

$verComo  = verComo();
$yo       = $verComo ?: Auth::usuario();
$d        = ResumenPersona::datos($yo, !$verComo);
$miembros = (new MiembroRepo())->mapa();
$ahora    = $d['ahora'];
$hoy      = $d['hoy'];
$estados  = $d['estados'];
$destacada = $d['destacada'];
$nombre   = explode(' ', trim((string)($yo['nombre'] ?? '')))[0] ?? '';
$plural   = fn(int $n, string $uno, string $varios) => $n . ' ' . ($n === 1 ? $uno : $varios);
$corto    = fn(string $s, int $n = 60) => mb_strimwidth($s, 0, $n, '…');
$enlaceTarea = fn(array $t) => 'proyecto.php?id=' . (int)$t['proyecto_id'] . '&tarea=' . (int)$t['id'];
$nombreP  = fn(int $pid) => (string)($d['proyectos'][$pid]['nombre'] ?? 'Proyecto');

// La agenda que aún no pasa (para contar y para la tira)
$agendaViva = array_values(array_filter($d['agenda'], fn($a) => $a['estado'] !== 'paso'));
$destacadaHoy = $destacada && date('Y-m-d', $destacada['ts']) === $hoy;
$urgeReunion = $destacada && in_array($destacada['estado'], ['vivo', 'pronto'], true);
// Lo que le llegó: el correo solo cuenta si se pudo leer
$nReq = count($d['reqs']);
$nCorreo = (int)($d['correo'] ?? 0);
$nEspera = $nCorreo + $nReq + (int)$d['pedidos'];

/* ---------- La cabecera ---------- */
// Lo más urgente, en un solo botón de ícono con su número: entrar a la
// reunión que ya empieza, lo atrasado, lo de hoy o lo que revisa el PO.
$urgente = null;
if ($urgeReunion && $destacada['entrar']) {
    $urgente = ['href' => $destacada['entrar'], 'ic' => 'ds-video', 'tono' => 'verde', 'n' => 0,
        'tip' => ($destacada['estado'] === 'vivo' ? 'Entrar ya: ' : 'Entrar: ') . $destacada['titulo']];
} elseif ($d['vencidas']) {
    $urgente = ['href' => $enlaceTarea($d['vencidas'][0]), 'ic' => 'ds-alerta', 'tono' => 'rosa', 'n' => count($d['vencidas']),
        'tip' => 'Ir a lo atrasado: ' . $corto((string)$d['vencidas'][0]['titulo'], 50)];
} elseif ($d['deHoy']) {
    $urgente = ['href' => $enlaceTarea($d['deHoy'][0]), 'ic' => 'ds-bandera', 'tono' => 'azul', 'n' => count($d['deHoy']),
        'tip' => 'Vence hoy: ' . $corto((string)$d['deHoy'][0]['titulo'], 50)];
} elseif ($d['revision']) {
    $urgente = ['href' => $enlaceTarea($d['revision'][0]), 'ic' => 'ds-buscar', 'tono' => 'azul', 'n' => count($d['revision']),
        'tip' => 'Revisar como PO: ' . $corto((string)$d['revision'][0]['titulo'], 50)];
}
$acciones = '';
if ($urgente) {
    // El número va en data-n (lo pinta el CSS): con texto adentro, la ayuda de ds.js no saldría
    $acciones .= '<a class="rs-boton rs-boton--icono rs-boton--' . $urgente['tono'] . '" href="' . e($urgente['href']) . '"'
        . ' aria-label="' . e($urgente['tip']) . '" data-ds-tip="' . e($urgente['tip']) . '"'
        . ($urgente['n'] ? ' data-n="' . ($urgente['n'] > 99 ? '99+' : $urgente['n']) . '"' : '') . '>'
        . UI::icono($urgente['ic']) . '</a>';
}
// El mismo control que en index.php: va el último, así no se corre al cambiar de vista
$acciones .= '<nav class="rs-vista" data-rs-vista data-activa="resumen" aria-label="Vista del dashboard">'
    . '<span class="rs-vista__pieza" aria-hidden="true"></span>'
    . '<a class="rs-vista__op" href="index.php" data-vista-op="proyectos" aria-label="Proyectos" data-ds-tip="Proyectos">' . UI::icono('ds-categoria') . '</a>'
    . '<a class="rs-vista__op" href="resumen.php" data-vista-op="resumen" aria-current="page" aria-label="Resumen" data-ds-tip="Resumen: tu día en un panel">' . UI::icono('ds-reportes') . '</a>'
    . '</nav>';

// Nexo acompaña el día: piensa si hay algo atrasado y celebra si no queda nada
$pose = $d['vencidas'] ? 'pensando' : (($d['mias'] && !$d['deHoy'] && !$d['proximas'] && !$d['revision']) ? 'celebrando' : 'saludando');

UI::inicio('Resumen', 'dashboard');
UI::cabecera(
    $verComo
        ? 'Resumen de <span class="text-secondary">' . e($nombre) . '</span>'
        : e(ResumenPersona::saludo($ahora)) . ', <span class="text-secondary">' . e($nombre) . '</span>',
    ($verComo ? 'Viendo el día de <b>' . e($verComo['nombre']) . '</b>. ' : '') . ResumenPersona::siguientePaso($d),
    $acciones,
    'nexo:' . $pose
);

/* ---------- Piezas ---------- */
// Un ícono en su baldosa de color (la de la tira de despliegues)
$ic = fn(string $icono, string $tono, string $mas = '') => '<span class="rs-ic rs-ic--' . $tono . $mas . '" aria-hidden="true">' . UI::icono($icono) . '</span>';

// Una fila de entrega: la fecha en su baldosa, el título y el proyecto, y cuándo vence
$filaTarea = function (array $t, string $tono) use ($hoy, $estados, $enlaceTarea, $nombreP, $miembros, $d): string {
    $f = (string)$t['fecha_limite'];
    [, $mes] = ResumenPersona::diaMesCorto($f);
    $est = (string)($estados[$t['estado'] ?? ''][0] ?? ($t['estado'] ?? ''));
    $prio = ($t['prioridad'] ?? '') === 'alta' ? ' · <span class="rs-alta">Alta</span>' : '';
    // En «Por revisar» importa quién la hizo; en las mías, de qué proyecto es
    $quien = '';
    if ($tono === 'revisar') {
        $ids = TareaRepo::asignadosDe($t);
        $quien = isset($miembros[$ids[0] ?? 0]) ? e(explode(' ', trim($miembros[$ids[0]]['nombre']))[0]) . ' · ' : '';
    }
    $lead = $tono === 'revisar'
        ? UI::avatarsAsignados($t, $miembros, 36, 2)
        : '<span class="rs-dia rs-dia--' . $tono . '"><b>' . (int)date('j', strtotime($f)) . '</b><small>' . e($mes) . '</small></span>';
    $cuando = $f === '' ? 'sin fecha' : ResumenPersona::relativo($f, $hoy);
    // En las mías el grupo ya dice si está atrasada; en las del PO, no
    if ($tono === 'revisar' && $f !== '') $cuando = ($f < $hoy ? 'venció ' : 'vence ') . $cuando;
    $tagTono = $f !== '' && $f < $hoy ? 'rosa' : ($f === $hoy ? 'azul' : 'gris');
    return '<li><a class="rs-fila" href="' . e($enlaceTarea($t)) . '">' . $lead
        . '<span class="rs-fila__txt"><b title="' . e((string)$t['titulo']) . '">' . e((string)$t['titulo']) . '</b>'
        . '<small>' . $quien . e($nombreP((int)$t['proyecto_id'])) . ($tono === 'revisar' ? '' : ' · ' . e($est)) . $prio . '</small></span>'
        . '<span class="rs-tag rs-tag--' . $tagTono . '">' . e($cuando) . '</span></a></li>';
};

// Un estado vacío corto: no deja un hueco blanco en la tarjeta
$vacio = fn(string $icono, string $titulo, string $texto) => '<div class="rs-vacio"><span class="rs-vacio__ic" aria-hidden="true">' . UI::icono($icono) . '</span>'
    . '<p><b>' . e($titulo) . '</b><small>' . e($texto) . '</small></p></div>';

/* ---------- La tira de «Hoy» ---------- */
$kpis = [];
$nHoy = count($d['deHoy']);
$kpis[] = ['href' => '#rs-entregas', 'ic' => 'ds-bandera', 'tono' => 'celeste', 'eti' => 'Vencen hoy', 'n' => $nHoy,
    'sub' => $nHoy ? $corto((string)$d['deHoy'][0]['titulo'], 34) : 'Nada vence hoy'];
$nAtr = count($d['vencidas']);
$kpis[] = ['href' => '#rs-entregas', 'ic' => $nAtr ? 'ds-alerta' : 'CircleCheck', 'tono' => $nAtr ? 'rosa' : 'menta', 'eti' => 'Atrasadas', 'n' => $nAtr,
    'sub' => $nAtr ? 'La más vieja, ' . ResumenPersona::relativo((string)$d['vencidas'][0]['fecha_limite'], $hoy) : 'Vas al día'];
$nReu = count($d['agenda']);
$kpis[] = ['href' => '#rs-agenda', 'ic' => 'ds-video', 'tono' => 'lila', 'eti' => 'Reuniones hoy', 'n' => $nReu,
    'sub' => $agendaViva ? ($agendaViva[0]['estado'] === 'vivo' ? 'Una está en curso' : 'La próxima, ' . ResumenPersona::hora($agendaViva[0]['ts']))
        : ($nReu ? 'Ya pasaron todas' : 'Hoy no tienes')];
if ($d['esPo']) {
    $nRev = count($d['revision']);
    $kpis[] = ['href' => '#rs-revisar', 'ic' => 'ds-buscar', 'tono' => 'azul', 'eti' => 'Por revisar', 'n' => $nRev,
        'sub' => $nRev ? 'Como PO de ' . $corto(implode(', ', array_unique(array_map(fn($t) => $nombreP((int)$t['proyecto_id']), $d['revision']))), 30) : 'Nada espera tu visto bueno'];
}
$partesEspera = array_filter([
    $nCorreo ? $plural($nCorreo, 'correo', 'correos') : '',
    $nReq ? $plural($nReq, 'requerimiento', 'requerimientos') : '',
    $d['pedidos'] ? $plural((int)$d['pedidos'], 'pedido', 'pedidos') : '',
]);
$kpis[] = ['href' => '#rs-espera', 'ic' => 'ds-notificaciones', 'tono' => 'celeste', 'eti' => 'Te espera', 'n' => $nEspera,
    'sub' => $partesEspera ? implode(' · ', $partesEspera) : 'Nada nuevo'];
$kpis[] = ['href' => '#rs-progreso', 'ic' => 'ds-check-doble', 'tono' => 'menta', 'eti' => 'Terminadas · 7 días', 'n' => (int)$d['semanaHechas'],
    'sub' => $d['mias'] ? 'Llevas el ' . $d['avance'] . ' % de las tuyas' : 'Sin tareas todavía'];
?>

<div class="rs" data-rs>
<section class="rs-hoy" aria-labelledby="rs-hoy-t">
  <div class="rs-hoy__cab">
    <?= $ic('Calendar', 'celeste', ' rs-ic--grande') ?>
    <div class="rs-hoy__txt">
      <small id="rs-hoy-t">Hoy</small>
      <b class="rs-hoy__fecha"><?= e(ResumenPersona::fechaLarga($ahora)) ?></b>
    </div>
  </div>
  <ul class="rs-kpis">
    <?php foreach ($kpis as $i => $k): ?>
    <li class="rs-kpi" style="--i:<?= $i ?>">
      <a href="<?= e($k['href']) ?>">
        <span class="rs-kpi__eti"><?= $ic($k['ic'], $k['tono'], ' rs-ic--chico') ?><?= e($k['eti']) ?></span>
        <b class="rs-kpi__v" data-rs-n="<?= (int)$k['n'] ?>"><?= (int)$k['n'] ?></b>
        <span class="rs-kpi__sub" title="<?= e($k['sub']) ?>"><?= e($k['sub']) ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
</section>

<?php
/* ---------- Lo de hoy ---------- */
$cards = [];

// Tus entregas: lo atrasado y lo de hoy enteros (hasta 4 de cada uno) y lo de la semana hasta llenar 7
ob_start();
$vencidasV = array_slice($d['vencidas'], 0, 4);
$deHoyV    = array_slice($d['deHoy'], 0, 4);
$proxV     = array_slice($d['proximas'], 0, max(2, 7 - count($vencidasV) - count($deHoyV)));
$restan    = count($d['vencidas']) + count($d['deHoy']) + count($d['proximas']) - count($vencidasV) - count($deHoyV) - count($proxV);
?>
<article class="rs-card rs-card--entregas" id="rs-entregas">
  <header class="rs-card__cab">
    <h3 class="rs-card__t">Tus entregas</h3>
    <?php if ($d['vencidas'] || $d['deHoy'] || $d['proximas']): ?>
    <span class="rs-card__extra"><?= $plural(count($d['vencidas']) + count($d['deHoy']) + count($d['proximas']), 'en la semana', 'en la semana') ?></span>
    <?php endif; ?>
  </header>
  <?php if (!$vencidasV && !$deHoyV && !$proxV): ?>
    <?= $vacio('Task', $d['mias'] ? 'Nada que entregar esta semana' : 'Sin tareas asignadas', $d['mias'] ? 'Cuando una tarea tuya tenga fecha en los próximos días, sale aquí.' : 'Cuando te asignen una tarea con fecha, aquí verás cuándo vence.') ?>
  <?php else: ?>
    <?php if ($vencidasV): ?>
    <p class="rs-grupo rs-grupo--rosa"><?= UI::icono('ds-alerta') ?> Atrasadas</p>
    <ul class="rs-filas"><?php foreach ($vencidasV as $t) echo $filaTarea($t, 'rosa'); ?></ul>
    <?php endif; ?>
    <?php if ($deHoyV): ?>
    <p class="rs-grupo">Vencen hoy</p>
    <ul class="rs-filas"><?php foreach ($deHoyV as $t) echo $filaTarea($t, 'azul'); ?></ul>
    <?php endif; ?>
    <?php if ($proxV): ?>
    <p class="rs-grupo">Lo que viene</p>
    <ul class="rs-filas"><?php foreach ($proxV as $t) echo $filaTarea($t, 'gris'); ?></ul>
    <?php endif; ?>
    <?php if ($restan > 0): ?>
    <p class="rs-nota">Y <?= $plural($restan, 'entrega más', 'entregas más') ?> en tus tableros.</p>
    <?php endif; ?>
  <?php endif; ?>
</article>
<?php
$cards['entregas'] = ob_get_clean();

// Tu agenda: la reunión que sigue, destacada; las demás de hoy, en filas
ob_start();
$otras = array_values(array_filter($d['agenda'], fn($a) => !$destacada || $a['tipo'] !== $destacada['tipo'] || $a['id'] !== $destacada['id'] || $a['ts'] !== $destacada['ts']));
$invitadosDe = fn(array $a) => array_values(array_filter(array_map(fn($id) => $miembros[$id] ?? null, $a['invitados'])));
?>
<article class="rs-card rs-card--agenda" id="rs-agenda">
  <header class="rs-card__cab">
    <h3 class="rs-card__t"><?= $d['agenda'] ? 'Tus reuniones de hoy' : 'Tus reuniones' ?></h3>
    <?php if ($destacada): ?>
    <a class="rs-card__ver" href="proyecto.php?id=<?= (int)$destacada['proyecto']['id'] ?>#vista-reuniones">Ver reuniones</a>
    <?php endif; ?>
  </header>
  <?php if ($destacada):
      $vivo = $destacada['estado'] === 'vivo';
      $etiqueta = $vivo ? 'En curso' : ($destacada['estado'] === 'pronto' ? 'Empieza pronto' : ($destacadaHoy ? 'La siguiente' : 'Tu próxima reunión'));
      $conBorde = in_array($destacada['estado'], ['vivo', 'pronto'], true);
  ?>
  <div class="rs-reu<?= $conBorde ? ' rs-reu--' . $destacada['estado'] : '' ?>">
    <div class="rs-reu__top">
      <span class="rs-reu__eti"><?php if ($vivo): ?><span class="rs-punto-vivo" aria-hidden="true"></span><?php endif; ?><?= e($etiqueta) ?></span>
      <span class="rs-cuenta"><?= UI::icono('ds-reloj') ?>
        <?= $vivo ? 'termina' : 'empieza' ?>
        <b data-cuenta-regresiva data-corto data-ya="<?= $vivo ? 'ya terminó' : 'ya empezó' ?>" data-hasta="<?= $vivo ? $destacada['fin'] : $destacada['ts'] ?>" data-ahora="<?= $ahora ?>">&nbsp;</b>
      </span>
    </div>
    <b class="rs-reu__cuando"><?= e(ResumenPersona::dia($destacada['ts'], $ahora)) ?> · <?= e(ResumenPersona::hora($destacada['ts'])) ?></b>
    <p class="rs-reu__t" title="<?= e($destacada['titulo']) ?>"><?= e($destacada['titulo']) ?></p>
    <p class="rs-reu__meta"><?= e($destacada['proyecto']['nombre']) ?><?= $destacada['tipo'] === 'daily' ? ' · Reunión fija' : ($destacada['plataforma'] !== '' ? ' · ' . e($destacada['plataforma']) : '') ?> · <?= (int)$destacada['duracion'] ?> min</p>
    <div class="rs-reu__pie">
      <?= $invitadosDe($destacada) ? UI::avatarStack($invitadosDe($destacada), 5, 30) : '<span></span>' ?>
      <?php if ($destacada['entrar'] && ($destacadaHoy || $vivo)): /* «Entrar» el mismo día; la de otro día se mira en el proyecto */ ?>
      <a class="rs-entrar<?= $conBorde ? ' is-ya' : '' ?>" href="<?= e($destacada['entrar']) ?>"><?= UI::icono('ds-video') ?> Entrar</a>
      <?php else: ?>
      <a class="rs-entrar rs-entrar--suave" href="proyecto.php?id=<?= (int)$destacada['proyecto']['id'] ?>#vista-reuniones"><?= UI::icono('ds-chevron-der') ?> Ver en el proyecto</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($otras): ?>
  <ul class="rs-filas">
    <?php foreach ($otras as $a): [$hh, $ampm] = array_pad(explode(' ', ResumenPersona::hora($a['ts'])), 2, ''); ?>
    <li>
      <div class="rs-fila<?= $a['estado'] === 'paso' ? ' is-paso' : '' ?>">
        <span class="rs-dia rs-dia--lila"><b><?= e($hh) ?></b><small><?= e($ampm) ?></small></span>
        <span class="rs-fila__txt"><b title="<?= e($a['titulo']) ?>"><?= e($a['titulo']) ?></b>
          <small><?= e($a['proyecto']['nombre']) ?> · <?= $a['tipo'] === 'daily' ? 'Reunión fija' : (int)$a['duracion'] . ' min' ?></small></span>
        <?php if ($a['estado'] === 'paso'): ?>
        <span class="rs-tag rs-tag--gris">terminó</span>
        <?php elseif ($a['entrar']): ?>
        <a class="rs-ico-btn" href="<?= e($a['entrar']) ?>" aria-label="Entrar a <?= e($a['titulo']) ?>" data-ds-tip="Entrar a la reunión"><?= UI::icono('ds-video') ?></a>
        <?php else: ?>
        <span class="rs-tag rs-tag--lila"><?= $a['estado'] === 'vivo' ? 'en curso' : e(ResumenPersona::hora($a['ts'])) ?></span>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <?php if (!$destacada && !$otras): ?>
    <?= $vacio('CalendarCheck', 'Hoy no tienes reuniones', 'Cuando te inviten a una, aquí sale con su cuenta regresiva y el botón para entrar.') ?>
  <?php endif; ?>
</article>
<?php
$cards['agenda'] = ob_get_clean();

// Por revisar como PO: solo si lleva algún proyecto
if ($d['esPo']) {
    ob_start();
    $porProy = array_count_values(array_map(fn($t) => (int)$t['proyecto_id'], $d['revision']));
    arsort($porProy);
    $pidMas = (int)(array_key_first($porProy) ?: array_key_first($d['misPo']));
    ?>
<article class="rs-card rs-card--revisar" id="rs-revisar">
  <header class="rs-card__cab">
    <h3 class="rs-card__t">Por revisar como PO</h3>
    <a class="rs-card__ver" href="proyecto.php?id=<?= $pidMas ?>#vista-kanban">Abrir el tablero</a>
  </header>
  <?php if ($d['revision']): ?>
  <ul class="rs-filas"><?php foreach (array_slice($d['revision'], 0, 5) as $t) echo $filaTarea($t, 'revisar'); ?></ul>
  <?php if (count($d['revision']) > 5): ?><p class="rs-nota">Y <?= $plural(count($d['revision']) - 5, 'más', 'más') ?> en revisión.</p><?php endif; ?>
  <?php else: ?>
    <?= $vacio('CircleCheck', 'Nada espera tu visto bueno', 'Cuando alguien pase una tarea a «En revisión» en tus proyectos, aparece aquí.') ?>
  <?php endif; ?>
</article>
    <?php
    $cards['revisar'] = ob_get_clean();
}

// Te espera: lo que le llegó, en filas teñidas (cada una lleva a su pantalla)
ob_start();
$datosEspera = [];
if ($d['correo'] !== null && $nCorreo) {
    $datosEspera[] = ['href' => 'correo.php', 'ic' => 'EmailEnvelope', 'tono' => 'azul',
        'txt' => '<b>' . e($plural($nCorreo, 'correo', 'correos')) . '</b> sin leer', 'sub' => 'En tu bandeja InnoTech'];
}
if ($nReq) {
    $r0 = $d['reqs'][0];
    $fin0 = RequerimientoRepo::fechaFin($r0);
    $datosEspera[] = ['href' => 'bandeja.php', 'ic' => 'ds-requerimiento', 'tono' => RequerimientoRepo::vencido($r0) ? 'rosa' : 'celeste',
        'txt' => '<b>' . e($plural($nReq, 'requerimiento', 'requerimientos')) . '</b> ' . ($nReq === 1 ? 'abierto' : 'abiertos'),
        'sub' => '«' . $corto((string)($r0['titulo'] ?? ''), 40) . '»' . ($fin0 !== '' ? ' · entrega ' . ResumenPersona::relativo($fin0, $hoy) : '')];
}
if ($d['pedidos']) {
    $datosEspera[] = ['href' => 'pedidos.php', 'ic' => 'ds-transferencia', 'tono' => 'menta',
        'txt' => '<b>' . e($plural((int)$d['pedidos'], 'pedido', 'pedidos')) . '</b> para ti o tu equipo', 'sub' => 'Pedidos entre equipos'];
}
?>
<article class="rs-card rs-card--espera" id="rs-espera">
  <header class="rs-card__cab"><h3 class="rs-card__t">Te espera</h3></header>
  <?php if ($datosEspera): ?>
  <ul class="rs-datos">
    <?php foreach ($datosEspera as $x): ?>
    <li><a class="rs-dato rs-dato--<?= $x['tono'] ?>" href="<?= e($x['href']) ?>">
      <span class="rs-circulo" aria-hidden="true"><?= UI::icono($x['ic']) ?></span>
      <p><?= $x['txt'] ?><small><?= e($x['sub']) ?></small></p>
      <?= UI::icono('ds-chevron-der', 'rs-dato__ir') ?>
    </a></li>
    <?php endforeach; ?>
  </ul>
  <?php else: ?>
    <?= $vacio('EmailEnvelopeOpen', 'Nada nuevo', $verComo ? 'Sin requerimientos ni pedidos abiertos (el correo de otra persona no se muestra).' : 'Ni correo sin leer, ni requerimientos, ni pedidos abiertos.') ?>
  <?php endif; ?>
</article>
<?php
$cards['espera'] = ob_get_clean();

// Primero lo más importante: si la reunión ya empieza, va delante de las entregas
if ($urgeReunion) $cards = ['agenda' => $cards['agenda']] + $cards;
$nParaHoy = count($d['vencidas']) + count($d['deHoy']) + count($agendaViva) + count($d['revision']);
?>

<section class="rs-sec" data-rs-sec="hoy" aria-labelledby="rs-sec-hoy">
  <header class="rs-sec__cab">
    <h2 class="rs-sec__t" id="rs-sec-hoy">Lo de hoy<?php if ($nParaHoy): ?> <span class="rs-sec__n" title="<?= e($plural($nParaHoy, 'cosa para hoy', 'cosas para hoy')) ?>"><?= $nParaHoy ?></span><?php endif; ?></h2>
    <button type="button" class="rs-sec__plegar" data-rs-plegar aria-expanded="true" aria-controls="rs-sec-c-hoy" aria-label="Plegar Lo de hoy" data-ds-tip="Plegar"><?= UI::icono('ds-chevron-arriba') ?></button>
  </header>
  <div class="rs-sec__cuerpo" id="rs-sec-c-hoy">
    <div class="rs-grid rs-grid--hoy"><?= implode('', $cards) ?></div>
  </div>
</section>

<?php
/* ---------- Cómo vas ---------- */
// El reparto por estado, en la rampa azul (del más claro, por hacer, al más hondo, completada)
$totalMias = count($d['mias']);
$tonosEstado = [];
$k = 0;
foreach ($estados as $clave => $_) $tonosEstado[$clave] = min(4, ++$k);
$repartoTxt = [];
foreach ($d['porEstado'] as $clave => $n) {
    if ($n) $repartoTxt[] = $n . ' ' . mb_strtolower((string)($estados[$clave][0] ?? $clave));
}
// La semana: lo atrasado aparte (rojo, con su ícono) y un día por columna
$maxSemana = max(1, count($d['vencidas']), ...array_values($d['semana']));
$nSemana = array_sum($d['semana']);
$diaPico = $nSemana ? array_search(max($d['semana']), $d['semana'], true) : null;
?>
<section class="rs-sec" data-rs-sec="progreso" aria-labelledby="rs-sec-progreso">
  <header class="rs-sec__cab">
    <h2 class="rs-sec__t" id="rs-sec-progreso">Cómo vas</h2>
    <button type="button" class="rs-sec__plegar" data-rs-plegar aria-expanded="true" aria-controls="rs-sec-c-progreso" aria-label="Plegar Cómo vas" data-ds-tip="Plegar"><?= UI::icono('ds-chevron-arriba') ?></button>
  </header>
  <div class="rs-sec__cuerpo" id="rs-sec-c-progreso">
    <div class="rs-grid rs-grid--3">

      <article class="rs-card rs-card--anillo" id="rs-progreso">
        <header class="rs-card__cab">
          <h3 class="rs-card__t">Tus tareas</h3>
          <?php if ($totalMias): ?><span class="rs-card__extra"><?= $plural($totalMias, 'en total', 'en total') ?></span><?php endif; ?>
        </header>
        <?php if ($totalMias): ?>
        <div class="rs-anillo-fila">
          <div class="rs-anillo" role="img" aria-label="<?= $d['avance'] ?> % de tus tareas terminadas: <?= $d['terminadas'] ?> de <?= $totalMias ?>">
            <svg viewBox="0 0 120 120" aria-hidden="true">
              <circle class="rs-anillo__pista" cx="60" cy="60" r="50"></circle>
              <circle class="rs-anillo__valor" cx="60" cy="60" r="50" pathLength="100" style="--p:<?= $d['avance'] ?>"></circle>
            </svg>
            <span class="rs-anillo__centro"><b><span data-rs-n="<?= $d['avance'] ?>"><?= $d['avance'] ?></span>%</b><small>terminadas</small></span>
          </div>
          <div class="rs-estados">
            <div class="rs-pila" role="img" aria-label="Por estado: <?= e(implode(', ', $repartoTxt)) ?>">
              <?php foreach ($d['porEstado'] as $clave => $n): if (!$n) continue; ?>
              <span class="rs-pila__seg rs-e<?= $tonosEstado[$clave] ?? 4 ?>" style="flex-grow:<?= $n ?>" data-ds-tip="<?= e(($estados[$clave][0] ?? $clave) . ': ' . $n) ?>"></span>
              <?php endforeach; ?>
            </div>
            <ul class="rs-leyenda">
              <?php foreach ($d['porEstado'] as $clave => $n): ?>
              <li><span class="rs-sw rs-e<?= $tonosEstado[$clave] ?? 4 ?>" aria-hidden="true"></span><span><?= e((string)($estados[$clave][0] ?? $clave)) ?></span><b><?= (int)$n ?></b></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
        <p class="rs-nota"><?= $d['semanaHechas'] ? 'Terminaste <b>' . $plural((int)$d['semanaHechas'], 'tarea', 'tareas') . '</b> en los últimos 7 días.' : 'En los últimos 7 días no cerraste ninguna: la próxima suma aquí.' ?></p>
        <?php else: ?>
          <?= $vacio('Task', 'Sin tareas asignadas', 'Cuando tengas tareas, aquí ves cuántas terminaste y en qué estado están las demás.') ?>
        <?php endif; ?>
      </article>

      <article class="rs-card rs-card--semana" id="rs-semana">
        <header class="rs-card__cab">
          <h3 class="rs-card__t">Tu semana</h3>
          <?php if ($nSemana): ?><span class="rs-card__extra"><?= $plural($nSemana, 'entrega', 'entregas') ?></span><?php endif; ?>
        </header>
        <?php if ($nSemana || $d['vencidas']): ?>
        <ol class="rs-cols" aria-label="Entregas por día, de hoy a los próximos seis días">
          <?php if ($d['vencidas']): $nA = count($d['vencidas']); ?>
          <li class="rs-col rs-col--atraso">
            <span class="rs-col__area" aria-hidden="true"><span class="rs-col__n"><?= $nA ?></span><span class="rs-col__barra" style="--h:<?= round($nA * 100 / $maxSemana) ?>"></span></span>
            <span class="rs-col__dia" aria-hidden="true"><?= UI::icono('ds-alerta') ?><small>atraso</small></span>
            <span class="rs-col__hit" tabindex="0" role="img" data-ds-tip="<?= e($plural($nA, 'tarea atrasada', 'tareas atrasadas')) ?>" aria-label="<?= e($plural($nA, 'tarea atrasada', 'tareas atrasadas')) ?>"></span>
          </li>
          <?php endif; ?>
          <?php $j = 0; foreach ($d['semana'] as $fecha => $n):
              [$dn] = ResumenPersona::diaMesCorto($fecha);
              $esHoy = $fecha === $hoy;
              $tip = ($esHoy ? 'Hoy' : ResumenPersona::dia(strtotime($fecha), $ahora)) . ': ' . ($n ? $plural($n, 'entrega', 'entregas') : 'nada que entregar');
              $tip = mb_strtoupper(mb_substr($tip, 0, 1)) . mb_substr($tip, 1);
          ?>
          <li class="rs-col<?= $esHoy ? ' is-hoy' : '' ?><?= $n ? '' : ' is-cero' ?>" style="--i:<?= $j++ ?>">
            <span class="rs-col__area" aria-hidden="true"><?php if ($n): ?><span class="rs-col__n"><?= $n ?></span><?php endif; ?><span class="rs-col__barra" style="--h:<?= round($n * 100 / $maxSemana) ?>"></span></span>
            <span class="rs-col__dia" aria-hidden="true"><?= $esHoy ? 'hoy' : e($dn) ?><small><?= (int)date('j', strtotime($fecha)) ?></small></span>
            <?php /* La columna entera es el blanco del mouse y del teclado (sin texto, para que salga la ayuda de ds.js) */ ?>
            <span class="rs-col__hit" tabindex="0" role="img" data-ds-tip="<?= e($tip) ?>" aria-label="<?= e($tip) ?>"></span>
          </li>
          <?php endforeach; ?>
        </ol>
        <p class="rs-nota"><?php if ($diaPico): ?>El día más cargado: <b><?= e(ResumenPersona::dia(strtotime($diaPico), $ahora)) ?></b>, con <?= $plural((int)$d['semana'][$diaPico], 'entrega', 'entregas') ?>.<?php else: ?>Nada te vence en los próximos 7 días.<?php endif; ?></p>
        <?php else: /* siete columnas en cero no dicen nada: una línea */ ?>
          <?= $vacio('CalendarCheck', 'Semana libre', $d['mias'] ? 'Nada te vence en los próximos 7 días.' : 'Cuando tengas tareas con fecha, aquí ves cuántas te caen cada día.') ?>
        <?php endif; ?>
      </article>

      <article class="rs-card rs-card--proyectos" id="rs-proyectos">
        <header class="rs-card__cab">
          <h3 class="rs-card__t">Tus proyectos</h3>
          <a class="rs-card__ver" href="index.php">Ver todos</a>
        </header>
        <?php if ($d['avances']): ?>
        <ul class="rs-avances">
          <?php foreach ($d['avances'] as $i => $a): $p = $a['p']; ?>
          <li style="--i:<?= $i ?>"><a class="rs-avance" href="proyecto.php?id=<?= (int)$p['id'] ?>">
            <?php
            // Los proyectos viejos guardan un ícono que no es del set: aquí, solo SVG (si no hay, la carpeta)
            $icoP = UI::icono((string)($p['icono'] ?? '') ?: 'FolderOpen');
            if (!str_starts_with($icoP, '<svg')) $icoP = UI::icono('FolderOpen');
            ?>
            <span class="rs-avance__ic" style="--pc:<?= e(ProyectoRepo::colorBase($p)) ?>" aria-hidden="true"><?= $icoP ?></span>
            <span class="rs-avance__txt">
              <b title="<?= e($p['nombre']) ?>"><?= e($p['nombre']) ?></b>
              <span class="rs-avance__pista" role="img" aria-label="<?= $a['avance'] ?> % de avance"><span class="rs-avance__barra" style="--p:<?= $a['avance'] ?>%"></span></span>
              <small><?= $a['mias'] ? ($a['abiertas'] ? 'Tienes ' . $plural($a['abiertas'], 'abierta', 'abiertas') : 'Las tuyas, al día') . ' · ' : '' ?><?= $plural($a['total'], 'tarea', 'tareas') ?></small>
            </span>
            <span class="rs-avance__pct"><?= $a['avance'] ?> %</span>
          </a></li>
          <?php endforeach; ?>
        </ul>
        <?php if ($d['masProyectos']): ?><p class="rs-nota">Y <?= $plural($d['masProyectos'], 'proyecto más', 'proyectos más') ?> en la vista Proyectos.</p><?php endif; ?>
        <?php else: ?>
          <?= $vacio('FolderOpen', 'Todavía no estás en un proyecto', 'Cuando te asignen una tarea o te inviten a una reunión, el proyecto sale aquí con su avance.') ?>
        <?php endif; ?>
      </article>

    </div>
  </div>
</section>
</div>
<script>
// Las secciones plegadas se recuerdan en este dispositivo: se aplican antes de
// pintar, para que no se abran y se cierren al cargar (admin.js las maneja después)
try {
  document.querySelectorAll('[data-rs-sec]').forEach(function (s) {
    if (localStorage.getItem('mchub.rs.plegada.' + s.dataset.rsSec) === '1') s.classList.add('is-plegada');
  });
} catch (e) { /* sin almacenamiento: todo abierto */ }
</script>

<?php UI::fin(); ?>
