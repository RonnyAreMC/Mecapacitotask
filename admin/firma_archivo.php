<?php
/**
 * Los archivos de la firma de documentos, que viven en data/firmas/ (cerrado
 * a la web) y solo se entregan a quien los puede ver:
 *   ?mia=1                          tu firma (solo tú)
 *   ?doc=ID&pdf=original|firmado    el PDF, para verlo aquí mismo
 *   …&bajar=1                       el mismo, como descarga (queda en el historial)
 *   ?doc=ID&firma=N                 la firma que usó el firmante N en ese documento
 */
require_once __DIR__ . '/lib/core/bootstrap.php';
require_once __DIR__ . '/lib/firmas/Firmas.php';

$yo = Auth::usuario();
$no = function (int $codigo, string $txt): never {
    http_response_code($codigo);
    header('Content-Type: text/plain; charset=utf-8');
    exit($txt);
};
if (!$yo) $no(401, 'Tu sesión expiró. Entra de nuevo al panel.');

$servir = function (string $ruta, string $mime, string $nombre = '', bool $bajar = false) use ($no): never {
    if ($ruta === '' || !is_file($ruta)) $no(404, 'El archivo no está.');
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($ruta));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    if ($nombre !== '') {
        $ascii = preg_replace('/[^\w .,()-]/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nombre) ?: 'documento.pdf');
        header('Content-Disposition: ' . ($bajar ? 'attachment' : 'inline') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nombre));
    }
    readfile($ruta);
    exit;
};

if (isset($_GET['mia'])) {
    $servir(Firmas::tieneFirma((int)$yo['id']) ? Firmas::rutaMiFirma((int)$yo['id']) : '', 'image/png');
}

$doc = Firmas::buscar((int)($_GET['doc'] ?? 0));
if (!$doc || !Firmas::puedeVer($doc, $yo)) $no(404, 'No encontramos ese documento, o no participas en él.');

if (isset($_GET['firma'])) {
    $servir(Firmas::rutaFirmaUsada($doc, (int)$_GET['firma']), 'image/png');
}

$cual = ($_GET['pdf'] ?? '') === 'firmado' ? 'firmado' : 'original';
$ruta = $cual === 'firmado' ? Firmas::rutaFirmado($doc) : Firmas::rutaOriginal($doc);
$base = preg_replace('/\.pdf$/i', '', trim((string)$doc['titulo'])) ?: 'documento';
$nombre = $base . ($cual === 'firmado' ? (($doc['estado'] ?? '') === 'firmado' ? ' - firmado' : ' - hoja de firmas') : '') . '.pdf';
$bajar = !empty($_GET['bajar']);
if ($bajar && is_file($ruta)) Firmas::registrarDescarga($doc, $yo, $cual);
$servir($ruta, 'application/pdf', $nombre, $bajar);
