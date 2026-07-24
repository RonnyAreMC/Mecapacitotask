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
$proyecto = (new ProyectoRepo())->buscar($id);
if (!$proyecto || !puedeVerProyecto($id)) {
    http_response_code(403);
    echo json_encode(['error' => 'Proyecto no disponible.']);
    exit;
}

$commits = [];
foreach (ProyectoRepo::repos($proyecto) as $rp) {
    $cr = GitHub::commitsRecientes($rp['url'], 100, $rama);
    if (($cr['estado'] ?? '') !== 'ok') continue;
    foreach ($cr['commits'] as $c) {
        $c['repo'] = $rp['label'];
        $commits[] = $c;
    }
}
usort($commits, fn($a, $b) => strcmp($b['fecha'] ?? '', $a['fecha'] ?? ''));

echo json_encode(['commits' => $commits], JSON_UNESCAPED_UNICODE);
