<?php
/**
 * API de «Mis notas» (la usa assets/notas.js). Todo JSON y siempre las notas
 * de quien tiene la sesión: ni el administrador ni «ver como» ven las de otro.
 *
 *   GET  ?accion=listar         todas mis notas (fijadas primero, luego la más reciente)
 *   POST ?accion=guardar        {id?, titulo?, html?, color?, fijada?}  sin id la crea;
 *                               solo cambia lo que llega
 *   POST ?accion=eliminar       {id}            la nota y su pizarra
 *   POST ?accion=compartir      {id, activar}   enciende (clave nueva) o apaga el enlace público
 *   POST ?accion=recordatorio   {id, cuando}    'Y-m-d H:i' (o 'Y-m-dTH:i') o '' para quitarlo
 *   POST ?accion=pizarra        {id, poner}     le pone su pizarra (o la quita, si está vacía)
 *
 * Sin sesión: 401 con {ok:false, error}; las llamadas deben venir del propio
 * panel (cabecera X-Requested-With y mismo origen), como en pizarra_api.php.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Notas.php';
if (is_file(__DIR__ . '/lib/Pizarra.php')) require_once __DIR__ . '/lib/Pizarra.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$responder = function (array $d, int $codigo = 200): never {
    http_response_code($codigo);
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
};

$yo = Auth::usuario();
if (!$yo) $responder(['ok' => false, 'error' => 'Tu sesión expiró. Entra de nuevo.'], 401);
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') $responder(['ok' => false, 'error' => 'Solo desde el panel.'], 400);
$origen = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origen !== '' && parse_url($origen, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    $responder(['ok' => false, 'error' => 'Solo desde el panel.'], 403);
}
// La sesión no hace falta abierta: así el guardado mientras se escribe no hace fila
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$mid = (int)$yo['id'];
$accion = (string)($_GET['accion'] ?? '');
$esPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$cuerpo = [];
if ($esPost) {
    $cuerpo = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($cuerpo)) $cuerpo = $_POST;
} elseif ($accion !== 'listar') {
    $responder(['ok' => false, 'error' => 'Usa POST.'], 405);
}
$id = (int)($cuerpo['id'] ?? 0);
// Lo que no es mío responde igual que lo que no existe (no se sabe si existe)
$mia = function () use ($id, $mid, $responder): array {
    $n = Notas::propia($id, $mid);
    if (!$n) $responder(['ok' => false, 'error' => 'Esa nota ya no existe.'], 404);
    return $n;
};

try {
    switch ($accion) {
        case 'listar':
            $responder(['ok' => true, 'notas' => array_map([Notas::class, 'paraJs'], Notas::de($mid))]);

        case 'guardar':
            $d = array_intersect_key($cuerpo, array_flip(['id', 'titulo', 'html', 'color', 'fijada']));
            if ($id > 0) $mia();
            $responder(['ok' => true, 'nota' => Notas::paraJs(Notas::guardar($mid, $d))]);

        case 'eliminar':
            $mia();
            Notas::eliminar($id, $mid);
            $responder(['ok' => true]);

        case 'compartir':
            $mia();
            $responder(['ok' => true, 'nota' => Notas::paraJs(Notas::compartir($id, $mid, !empty($cuerpo['activar'])))]);

        case 'recordatorio':
            $mia();
            $responder(['ok' => true, 'nota' => Notas::paraJs(Notas::recordatorio($id, $mid, (string)($cuerpo['cuando'] ?? '')))]);

        case 'pizarra':
            $mia();
            $n = Notas::ponerPizarra($id, $mid, !array_key_exists('poner', $cuerpo) || !empty($cuerpo['poner']));
            $responder(['ok' => true, 'nota' => Notas::paraJs($n), 'url' => 'pizarra.php?nota=' . $id]);
    }
    $responder(['ok' => false, 'error' => 'Acción desconocida.'], 400);
} catch (RuntimeException $e) {
    $responder(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    error_log('Mis notas: ' . $e->getMessage());
    $responder(['ok' => false, 'error' => 'No se pudo completar. Intenta de nuevo.'], 500);
}
