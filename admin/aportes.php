<?php
/**
 * Devuelve en JSON los commits de un proyecto (opcionalmente de una rama) para
 * el gráfico de aportes. Lo llama el panel por AJAX al cambiar de rama, así no
 * hace falta recargar toda la página.
 */
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
$rama = trim($_GET['rama'] ?? '');
// Rango visible en Métricas: se lee solo ese tramo del historial
$dias = max(7, min(400, (int)($_GET['dias'] ?? 182)));
$proyecto = (new ProyectoRepo())->buscar($id);
if (!$proyecto || !puedeVerProyecto($id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Proyecto no disponible.']);
    exit;
}

$repos = ProyectoRepo::repos($proyecto);

// Ramas disponibles: la unión (para "Todos los repos") y también por repo, para
// que al elegir un repo el selector de ramas muestre solo las suyas.
$ramas = [];
$ramasRepo = [];
$labelsRepo = [];
foreach ($repos as $rp) {
    $labelsRepo[] = $rp['label'];
    $suyas = array_values(Repos::ramas($rp['url']));
    $ramasRepo[$rp['label']] = $suyas;
    foreach ($suyas as $rn) $ramas[$rn] = true;
}
$ramas = array_keys($ramas);
if ($rama !== '' && !in_array($rama, $ramas, true)) $rama = '';   // rama pedida ya no existe

// Rama que trae cada repo cuando no se pidió ninguna: la configurada en el
// proyecto, y si está vacía la que el repositorio tenga por defecto. Se informa
// al panel para que el selector diga con cuál está mirando.
$ramaDefecto = '';
foreach ($repos as $rp) {
    if (trim($rp['rama'] ?? '') !== '') { $ramaDefecto = trim($rp['rama']); break; }
}

// Hasta 3000 commits por repo dentro del rango pedido (o lo que entre en unos
// segundos). Con una sola página, un equipo activo veía el mapa cortado sin
// saberlo.
$commits = [];
$truncado = false;
foreach ($repos as $rp) {
    $ramaRepo = $rama !== '' ? $rama : trim($rp['rama'] ?? '');
    $cr = Repos::commitsRecientes($rp['url'], 3000, $ramaRepo, $dias);
    if (($cr['estado'] ?? '') !== 'ok') continue;
    $truncado = $truncado || !empty($cr['truncado']);
    foreach ($cr['commits'] as $c) {
        $c['repo'] = $rp['label'];
        $commits[] = $c;
    }
}
usort($commits, fn($a, $b) => strcmp($b['fecha'] ?? '', $a['fecha'] ?? ''));

echo json_encode([
    'commits'      => $commits,
    'ramas'        => $ramas,
    'ramas_repo'   => $ramasRepo,
    'repos'        => $labelsRepo,
    'rama_defecto' => $ramaDefecto,
    'truncado'     => $truncado,
], JSON_UNESCAPED_UNICODE);
