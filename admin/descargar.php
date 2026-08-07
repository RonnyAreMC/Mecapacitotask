<?php
/**
 * Descarga forzada de los documentos del equipo.
 *
 * Sirve el PDF/MD con Content-Disposition: attachment desde PHP, así la
 * descarga funciona en cualquier navegador (incluido móvil, que ignora el
 * atributo download) y sin depender del MIME que configure el servidor.
 */
require_once __DIR__ . '/lib/bootstrap.php';

// Lista blanca: nada de rutas que vengan del usuario.
$archivos = [
    'estandar-pdf'    => ['assets/docs/innotech-estandar.pdf',    'application/pdf'],
    'estandar-md'     => ['assets/docs/innotech-estandar.md',     'text/markdown'],
    'guia-claude-pdf' => ['assets/docs/innotech-guia-claude.pdf', 'application/pdf'],
    'guia-claude-md'  => ['assets/docs/innotech-guia-claude.md',  'text/markdown'],
];

$clave = preg_replace('/[^a-z0-9\-]/', '', strtolower($_GET['d'] ?? ''));
if (!isset($archivos[$clave])) {
    http_response_code(404);
    exit('Documento no encontrado.');
}

[$rel, $mime] = $archivos[$clave];
$abs = __DIR__ . '/' . $rel;
if (!is_file($abs)) {
    http_response_code(404);
    exit('El archivo aún no está en el servidor. Pídele al administrador que actualice el sitio (git pull).');
}

// Limpia cualquier buffer para no corromper el archivo.
while (ob_get_level()) { ob_end_clean(); }

header('Content-Type: ' . $mime . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . basename($rel) . '"');
header('Content-Length: ' . filesize($abs));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($abs);
exit;
