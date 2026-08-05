<?php
/**
 * Logo del panel servido por HTTP, para los correos.
 *
 * Los clientes de correo cargan las imagenes de forma anonima, asi que esta
 * pagina NO exige sesion (bootstrap la deja fuera de la guarda). No expone
 * nada sensible: es la misma imagen de marca que ya se ve en el login.
 *
 * Existe para no tener que adivinar donde queda la carpeta assets respecto a
 * la URL del panel: desde el correo se enlaza siempre a <panel>/logo.php.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$ruta = Mailer::logoArchivo();
if ($ruta === '' || !is_file($ruta)) {
    http_response_code(404);
    exit;
}

$tipos = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
          'gif' => 'image/gif', 'webp' => 'image/webp'];
$ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));

header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($ruta));
// Un logo cambia poco y el correo puede abrirse semanas despues
header('Cache-Control: public, max-age=604800');
readfile($ruta);
