<?php
/**
 * Ramas de un repositorio en JSON, para el selector del editor de proyectos.
 *
 * Solo responde a administradores, que son quienes editan proyectos, y solo
 * para servidores que el panel reconoce: Repos::ramas() ignora cualquier URL
 * que no sea de un proveedor conocido, asi que esto no sirve para sondear
 * maquinas de la red desde el servidor.
 *
 * La lista viene de la cache del proveedor (una hora), asi que abrir el
 * desplegable varias veces no gasta cuota de la API.
 */
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!esAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Solo un administrador puede consultar las ramas.']);
    exit;
}

$url = trim($_GET['url'] ?? '');
if (!Repos::soportado($url)) {
    echo json_encode([
        'ramas'     => [],
        'proveedor' => '',
        'aviso'     => 'No reconozco ese servidor. Si es un GitLab propio, declara su dominio en Ajustes.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ramas'     => Repos::ramas($url),
    'proveedor' => Repos::proveedor($url),
], JSON_UNESCAPED_UNICODE);
