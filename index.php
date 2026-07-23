<?php
/**
 * Raíz del subdominio: redirige al panel de administración.
 * El panel vive en /admin/. Los assets (logo, css) en /assets/.
 *
 * El destino se arma absoluto (a partir de la carpeta real de este index)
 * para que una cola en la URL —/index.php/algo— no enrosque el redirect.
 */
$sn  = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
if (preg_match('#^(.*?\.php)#i', $sn, $m)) $sn = $m[1];
$dir = rtrim(str_replace('\\', '/', dirname($sn)), '/');
header('Location: ' . $dir . '/admin/', true, 302);
exit;
