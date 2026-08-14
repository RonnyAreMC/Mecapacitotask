<?php
/**
 * Panel (dashboard) de requerimientos sueltos. Solo administrador.
 *
 * Vista de rendimiento con gráficos (ApexCharts): cumplimiento por institución,
 * situación de la carga, quién resuelve, prioridad, entrada por mes y carga
 * abierta por persona. Los datos se calculan aquí y se dibujan en admin.js.
 *
 * Aquí solo se preparan los números; la forma de cada gráfico y la paleta
 * están en el bloque "Panel de requerimientos" de admin.js.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/req_ui.php';
Auth::requiereAdmin();

if ($verComo = verComo()) {
    redirigir('index.php',
        'Estás viendo el panel como ' . explode(' ', trim($verComo['nombre']))[0]
        . '. El panel de requerimientos solo se ve desde tu propia vista.', 'info');
}

$reqRepo  = new RequerimientoRepo();
$miembros = new MiembroRepo();
$instRepo = new InstitucionRepo();

$requerimientos = $reqRepo->todos();
$instMapa       = $instRepo->mapa();
$mapa           = $miembros->mapa();

// --- KPIs y agregados ---
$total = count($requerimientos);
$resueltos = $descartados = $vencidos = 0;
$sit          = ['sin-asignar' => 0, 'en-curso' => 0, 'cerrados' => 0];
$porPrioridad = ['alta' => 0, 'media' => 0, 'baja' => 0];
$porInst      = [];      // iid => ['total','cumplidos']
$cumplidoPor  = [];      // nombre => n (resueltos)
$abiertoPor   = [];      // nombre => n (abiertos = carga)
$porMes       = [];      // 'Y-m' => n (creados)

foreach ($requerimientos as $r) {
    $estado = RequerimientoRepo::estadoValido($r['estado'] ?? '');
    $cerrado = RequerimientoRepo::cerrado($r);
    if ($estado === 'hecho')      $resueltos++;
    if ($estado === 'descartado') $descartados++;
    if (RequerimientoRepo::vencido($r)) $vencidos++;

    $sit[situacionDe($r)]++;

    $prio = (string)($r['prioridad'] ?? '');
    if (isset($porPrioridad[$prio])) $porPrioridad[$prio]++;

    foreach (RequerimientoRepo::institucionesDe($r) as $iid) {
        if (!isset($instMapa[$iid])) continue;
        if (!isset($porInst[$iid])) $porInst[$iid] = ['total' => 0, 'cumplidos' => 0];
        $porInst[$iid]['total']++;
        if ($estado === 'hecho') $porInst[$iid]['cumplidos']++;
    }

    foreach (RequerimientoRepo::asignadosDe($r) as $mid) {
        $nom = $mapa[$mid]['nombre'] ?? null;
        if ($nom === null) continue;
        if ($estado === 'hecho') $cumplidoPor[$nom] = ($cumplidoPor[$nom] ?? 0) + 1;
        if (!$cerrado)           $abiertoPor[$nom]  = ($abiertoPor[$nom] ?? 0) + 1;
    }

    $mesClave = substr((string)($r['creado'] ?? ''), 0, 7);   // Y-m
    if (preg_match('/^\d{4}-\d{2}$/', $mesClave)) {
        $porMes[$mesClave] = ($porMes[$mesClave] ?? 0) + 1;
    }
}

$pendientes = $total - $resueltos - $descartados;   // abiertos
$pct        = $total > 0 ? round($resueltos / $total * 100) : 0;

// Institución: ya ordenada por total desc, en la forma que espera el JS
uasort($porInst, fn($a, $b) => $b['total'] <=> $a['total']);
$dashInst = [];
foreach ($porInst as $iid => $c) {
    $dashInst[] = [
        'nombre'    => $instMapa[$iid]['nombre'],
        'total'     => $c['total'],
        'cumplidos' => $c['cumplidos'],
        'color'     => InstitucionRepo::colorBase($instMapa[$iid]),   // su color propio
    ];
}

arsort($cumplidoPor);
arsort($abiertoPor);
$abiertoPor = array_slice($abiertoPor, 0, 10, true);

// Meses: rango continuo del primero al último con datos (para que el área no
// tenga saltos), etiquetados en corto ("ago 26").
$mesCorto = [1 => 'ene', 2 => 'feb', 3 => 'mar', 4 => 'abr', 5 => 'may', 6 => 'jun', 7 => 'jul', 8 => 'ago', 9 => 'sep', 10 => 'oct', 11 => 'nov', 12 => 'dic'];
$serieMeses = [];
if ($porMes) {
    ksort($porMes);
    $claves = array_keys($porMes);
    $ini = strtotime(reset($claves) . '-01');
    $finM = strtotime(end($claves) . '-01');
    for ($t = $ini; $t <= $finM; $t = strtotime('+1 month', $t)) {
        $k = date('Y-m', $t);
        $serieMeses[] = [$mesCorto[(int)date('n', $t)] . ' ' . date('y', $t), (int)($porMes[$k] ?? 0)];
    }
}

// Heatmap institución × prioridad: cuántos requerimientos de cada prioridad
// toca cada institución. Las filas son las prioridades; las columnas, las
// instituciones (en el mismo orden que el resto de gráficos).
$prioOrden = ['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'];
$heatCount = [];
foreach ($requerimientos as $r) {
    $prio = (string)($r['prioridad'] ?? '');
    if (!isset($prioOrden[$prio])) continue;
    foreach (RequerimientoRepo::institucionesDe($r) as $iid) {
        if (!isset($instMapa[$iid])) continue;
        $heatCount[$iid][$prio] = ($heatCount[$iid][$prio] ?? 0) + 1;
    }
}
$heatInsts  = array_keys($porInst);   // mismo orden (por total desc)
$heatSeries = [];
foreach ($prioOrden as $pk => $plabel) {
    $data = [];
    foreach ($heatInsts as $iid) {
        $data[] = ['x' => $instMapa[$iid]['nombre'], 'y' => (int)($heatCount[$iid][$pk] ?? 0)];
    }
    $heatSeries[] = ['name' => $plabel, 'data' => $data];
}

$dashData = [
    'inst'  => $dashInst,
    'sit'   => [['Sin asignar', $sit['sin-asignar']], ['En curso', $sit['en-curso']], ['Cerrados', $sit['cerrados']]],
    'quien' => array_map(fn($n, $v) => [$n, $v], array_keys($cumplidoPor), array_values($cumplidoPor)),
    'prio'  => [['Alta', $porPrioridad['alta']], ['Media', $porPrioridad['media']], ['Baja', $porPrioridad['baja']]],
    'meses' => $serieMeses,
    'carga' => array_map(fn($n, $v) => [$n, $v], array_keys($abiertoPor), array_values($abiertoPor)),
    'heat'  => $heatInsts ? $heatSeries : [],
    'pct'   => $pct,
];

UI::inicio('Panel de requerimientos', 'requerimientos');
UI::cabecera(
    'Panel de <span class="text-secondary">requerimientos</span>',
    'Rendimiento de los requerimientos sueltos: cumplimiento por institución, quién resuelve y cómo evoluciona la carga.',
    '<a class="btn-outline btn-meca btn-azul" href="requerimientos.php"><i class="fa-solid fa-arrow-left"></i> Volver a la lista</a>'
);
?>

<?php if (!$total): ?>
  <?= UI::vacio('fa-chart-column', 'Todavía no hay datos',
        'Cuando registres y repartas requerimientos, aquí verás los gráficos de cumplimiento y carga.') ?>
<?php else: ?>

<!-- KPIs -->
<section class="rd-kpis">
  <?php
  $kpis = [
      ['fa-inbox',        'Total',       $total,       'kpi-neutro'],
      ['fa-circle-check', 'Resueltos',   $resueltos,   'kpi-ok'],
      ['fa-hourglass-half','Abiertos',   max(0, $pendientes), 'kpi-info'],
      ['fa-triangle-exclamation', 'Vencidos', $vencidos, 'kpi-alerta'],
      ['fa-percent',      'Cumplimiento', $pct . '%',  'kpi-ok'],
  ];
  foreach ($kpis as [$ico, $lab, $val, $cls]): ?>
  <div class="rd-kpi <?= $cls ?>">
    <span class="rd-kpi-ico"><i class="fa-solid <?= $ico ?>"></i></span>
    <span class="rd-kpi-datos"><b><?= e((string)$val) ?></b><small><?= e($lab) ?></small></span>
  </div>
  <?php endforeach; ?>
</section>

<!-- Gráficos -->
<section class="req-dash-grid rd-grid-pagina">
  <div class="req-dash-card rd-ancho-2">
    <h3><i class="fa-solid fa-chart-column"></i> Cumplidos por institución</h3>
    <div class="req-dash-chart" id="rd-inst"></div>
  </div>
  <div class="req-dash-card">
    <h3><i class="fa-solid fa-gauge-high"></i> Cumplimiento global</h3>
    <div class="req-dash-chart" id="rd-pct"></div>
  </div>
  <div class="req-dash-card">
    <h3><i class="fa-solid fa-bullseye"></i> Reparto por institución</h3>
    <div class="req-dash-chart" id="rd-inst-dona"></div>
  </div>
  <div class="req-dash-card">
    <h3><i class="fa-solid fa-chart-pie"></i> Situación de la carga</h3>
    <div class="req-dash-chart" id="rd-sit"></div>
  </div>
  <div class="req-dash-card">
    <h3><i class="fa-solid fa-flag"></i> Por prioridad</h3>
    <div class="req-dash-chart" id="rd-prio"></div>
  </div>
  <div class="req-dash-card rd-ancho-2">
    <h3><i class="fa-solid fa-arrow-trend-up"></i> Requerimientos recibidos por mes</h3>
    <div class="req-dash-chart" id="rd-meses"></div>
  </div>
  <div class="req-dash-card">
    <h3><i class="fa-solid fa-user-check"></i> Quién los cumplió</h3>
    <div class="req-dash-chart" id="rd-quien"></div>
  </div>
  <div class="req-dash-card rd-ancho-2">
    <h3><i class="fa-solid fa-layer-group"></i> Prioridad por institución</h3>
    <div class="req-dash-chart" id="rd-heat"></div>
  </div>
  <div class="req-dash-card">
    <h3><i class="fa-solid fa-chart-simple"></i> Carga abierta por persona</h3>
    <div class="req-dash-chart" id="rd-carga"></div>
  </div>
</section>

<script src="<?= asset('assets/apexcharts.min.js') ?>"></script>
<script>window.REQ_DASH = <?= json_encode($dashData, JSON_UNESCAPED_UNICODE) ?>;</script>
<?php endif; ?>

<?php UI::fin(); ?>
