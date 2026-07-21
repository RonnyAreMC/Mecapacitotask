<?php
/**
 * Raíz del subdominio: redirige al panel de administración.
 * El panel vive en /admin/. Los assets (logo, css) en /assets/.
 */
header('Location: admin/');
exit;
