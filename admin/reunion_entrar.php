<?php
/**
 * «Entrar» de una reunión: anota quién lo pulsó y a qué hora (reunion_ingresos)
 * y lo manda al enlace de la reunión (Zoom, Meet o el propio).
 *
 * Con eso se mide la asistencia a las dailies (Releases::sincronizarDailies):
 * cuenta quien entró desde el panel en la hora de la reunión, además de lo que
 * diga Zoom cuando deja ver a los participantes.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$reu = (new ReunionRepo())->buscar((int)($_GET['id'] ?? 0));
if (!$reu || !puedeVerProyecto((int)$reu['proyecto_id'])) {
    redirigir('index.php', 'Esa reunión no está disponible.', 'error');
}
$url = trim((string)($reu['join_url'] ?? ''));
if (!preg_match('#^https?://#i', $url)) {
    redirigir('proyecto.php?id=' . (int)$reu['proyecto_id'] . '#vista-reuniones', 'Esa reunión no tiene enlace para entrar.', 'error');
}

$yo = (int)(Auth::usuario()['id'] ?? 0);
if ($yo > 0) {
    (new JsonStore('reunion_ingresos'))->insert([
        'reunion_id'  => (int)$reu['id'],
        'proyecto_id' => (int)$reu['proyecto_id'],
        'miembro_id'  => $yo,
        't'           => date('Y-m-d H:i:s'),
    ]);
}
header('Location: ' . $url);
exit;
