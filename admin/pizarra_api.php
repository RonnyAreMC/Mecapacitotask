<?php
/**
 * API de la pizarra de una reunión (la usa assets/pizarra.js). Todo JSON.
 *
 *   GET  ?reunion=ID&accion=estado&desde=V   lo que cambió después de V (0: todo),
 *                                            quién más la mira y si puedo editar
 *   POST ?reunion=ID&accion=guardar          {cambios:[elemento | {id, borrado:true}]}
 *   POST ?reunion=ID&accion=imagen           multipart, campo «imagen»
 *   GET  ?reunion=ID&accion=tareas           las tareas del proyecto (para el kanban)
 *
 * Con ?mia=1 en vez de ?reunion=ID: «Mis notas», la pizarra personal de quien
 * tiene la sesión (y «tareas» trae sus tareas abiertas de todos sus proyectos).
 * Con ?nota=ID: la pizarra de una nota de notas.php, solo de su dueño (igual que ?mia=1).
 * Con ?t=TOKEN: la de una nota compartida por enlace (nota_ver.php). Es el único
 * camino sin sesión y solo contesta «estado»: mirar, nunca cambiar.
 *
 * Sin sesión o sin permiso: 401/403 con {ok:false, error}. Las llamadas
 * deben venir del propio panel (cabecera X-Requested-With y mismo origen):
 * un enlace o un formulario de otro sitio no puede escribir en la pizarra.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Pizarra.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$responder = function (array $d, int $codigo = 200): never {
    http_response_code($codigo);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
};

// ?t=TOKEN: la pizarra de una nota compartida, para quien tenga el enlace (sin sesión).
// Solo «estado»; sin los recordatorios ni quién hizo cada cosa. Si apagan el enlace, 403.
if (isset($_GET['t'])) {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') $responder(['ok' => false, 'error' => 'Solo desde la página de la nota.'], 400);
    $notaPub = Pizarra::notaCompartida(is_string($_GET['t']) ? $_GET['t'] : '');
    if (!$notaPub) $responder(['ok' => false, 'error' => 'Este enlace ya no está disponible.'], 403);
    if ((string)($_GET['accion'] ?? '') !== 'estado') $responder(['ok' => false, 'error' => 'Esta pizarra es solo para mirar.'], 403);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $ridPub = Pizarra::ridNota((int)$notaPub['id']);
    $elementos = array_map(function (array $el) { unset($el['aviso'], $el['aviso_por'], $el['por']); return $el; },
        Pizarra::cambios($ridPub, max(0, (int)($_GET['desde'] ?? 0))));
    $responder(['ok' => true, 'version' => Pizarra::version($ridPub), 'elementos' => $elementos, 'presentes' => [], 'editar' => false, 'yo' => 0]);
}

$yo = Auth::usuario();
if (!$yo) $responder(['ok' => false, 'error' => 'Tu sesión expiró. Entra de nuevo.'], 401);
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch') $responder(['ok' => false, 'error' => 'Solo desde el panel.'], 400);
$origen = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
if ($origen !== '' && parse_url($origen, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
    $responder(['ok' => false, 'error' => 'Solo desde el panel.'], 403);
}
// La sesión no hace falta abierta mientras se consulta: así los sondeos no se hacen fila
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

// ?mia=1: «Mis notas», la pizarra personal de quien tiene la sesión (solo la suya)
$mia = !empty($_GET['mia']);
$mid = (int)$yo['id'];
$idNota = (int)($_GET['nota'] ?? 0);
if ($idNota > 0) {
    // La pizarra de una nota: solo su dueño (como «Mis notas»: sin presentes y con sus tareas)
    if (!Pizarra::notaPropia($idNota)) $responder(['ok' => false, 'error' => 'Esa pizarra no está disponible.'], 403);
    $mia = true;
    $rid = Pizarra::ridNota($idNota);
    $reunion = null;
    $editar = true;
} elseif ($mia) {
    $rid = Pizarra::rid(true);
    $reunion = null;
    $editar = true;
} else {
    $rid = (int)($_GET['reunion'] ?? 0);
    $reunion = (new ReunionRepo())->buscar($rid);
    if ($rid <= 0 || !Pizarra::puedeVer($reunion)) $responder(['ok' => false, 'error' => 'Esa pizarra no está disponible.'], 403);
    $editar = Pizarra::puedeEditar($reunion);
}
$esPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

try {
    switch ((string)($_GET['accion'] ?? '')) {
        case 'estado':
            $desde = max(0, (int)($_GET['desde'] ?? 0));
            $responder([
                'ok'        => true,
                'version'   => Pizarra::version($rid),
                'elementos' => Pizarra::cambios($rid, $desde),
                'presentes' => $mia ? [] : Pizarra::presentes($rid, $mid),
                'editar'    => $editar,
                'yo'        => $mid,
            ]);

        case 'guardar':
            if (!$esPost) $responder(['ok' => false, 'error' => 'Usa POST.'], 405);
            if (!$editar) $responder(['ok' => false, 'error' => 'Esta pizarra es solo para mirar.'], 403);
            $cuerpo = json_decode((string)file_get_contents('php://input'), true);
            $cambios = is_array($cuerpo['cambios'] ?? null) ? $cuerpo['cambios'] : [];
            $responder(['ok' => true, 'version' => Pizarra::guardar($rid, $cambios, $mid)]);

        case 'imagen':
            if (!$esPost) $responder(['ok' => false, 'error' => 'Usa POST.'], 405);
            if (!$editar) $responder(['ok' => false, 'error' => 'Esta pizarra es solo para mirar.'], 403);
            $responder(['ok' => true] + Pizarra::subirImagen((array)($_FILES['imagen'] ?? [])));

        case 'tareas':
            $responder(['ok' => true, 'tareas' => $mia ? Pizarra::tareasDe($mid) : Pizarra::tareas((int)$reunion['proyecto_id'])]);
    }
    $responder(['ok' => false, 'error' => 'Acción desconocida.'], 400);
} catch (RuntimeException $e) {
    $responder(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (\Throwable $e) {
    error_log('Pizarra: ' . $e->getMessage());
    $responder(['ok' => false, 'error' => 'No se pudo completar. Intenta de nuevo.'], 500);
}
