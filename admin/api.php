<?php
/**
 * API del panel para Claude Code (o cualquier script): consultar tareas, cambiar
 * su estado, asociarles commits y crear tareas, sin entrar al panel ni pasar
 * JSON a mano. Entra con el token personal de Mi perfil → Claude Code y actúa
 * como esa persona, con sus mismos permisos. Responde JSON.
 *
 *   Cabecera: Authorization: Bearer mch_…   (o X-MCHub-Token: mch_…)
 *
 *   GET  ?r=yo                                   quién soy y qué proyectos veo
 *   GET  ?r=tareas[&todas=1][&proyecto=ID][&estado=…][&q=…][&terminadas=1]
 *                                                mis tareas abiertas (todas=1: las de mis proyectos)
 *   GET  ?r=tarea&id=N                           una tarea con su detalle, historia y commits
 *   GET  ?r=items[&equipo=Beta][&release=R2]     ítems del release (para item_release)
 *   POST ?r=estado   {"id":N, "estado":"revision"}
 *   POST ?r=commit   {"id":N, "sha":"…", "mensaje":"…", "url":"…", "repo":"…", "estado":"…"}
 *   POST ?r=tareas   {"tareas":[…], "validar":true}   el mismo JSON de Planificar
 */
define('MCH_API', true);
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/ApiToken.php';
if (is_file(__DIR__ . '/lib/Releases.php')) require_once __DIR__ . '/lib/Releases.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function apiFin(array $d, int $codigo = 200): never
{
    http_response_code($codigo);
    exit(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

function apiError(string $msg, int $codigo = 400): never
{
    apiFin(['ok' => false, 'error' => $msg], $codigo);
}

/* ---------- Quién llama: el token (nunca la cookie del navegador) ---------- */
$cabecera = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($cabecera === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) if (strcasecmp((string)$k, 'Authorization') === 0) $cabecera = (string)$v;
}
$token = preg_match('/^Bearer\s+(\S+)$/i', trim($cabecera), $m) ? $m[1] : trim((string)($_SERVER['HTTP_X_MCHUB_TOKEN'] ?? ''));
$yo = $token !== '' ? ApiToken::miembro($token) : null;
if (!$yo) apiError('Falta el token o ya no sirve. Créalo en el panel: Mi perfil → Claude Code.', 401);
$_SESSION = ['uid' => (int)$yo['id']];   // permisos, autor de lo que se cree: igual que si estuviera en el panel
ApiToken::anotarUso($yo);
$yoId = (int)$yo['id'];

$ruta = (string)($_GET['r'] ?? '');
$metodo = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$entrada = [];
if ($metodo === 'POST') {
    $crudo = (string)file_get_contents('php://input');
    $entrada = $crudo !== '' ? (json_decode($crudo, true) ?? null) : $_POST;
    if (!is_array($entrada)) apiError('El cuerpo no es un JSON válido.');
}
$exigir = function (string $m) use ($metodo): void {
    if ($metodo !== $m) apiError('Esta ruta va con ' . $m . '.', 405);
};

/* ---------- Lo que se repite ---------- */
$tareasRepo = new TareaRepo();
$miembros = (new MiembroRepo())->mapa();
$nombre = fn(int $mid) => (string)($miembros[$mid]['nombre'] ?? '');
$estados = Catalogo::estadosTarea();
$finales = Catalogo::estadosFinales();
$proyMapa = [];
foreach ((new ProyectoRepo())->todos() as $p) $proyMapa[(int)$p['id']] = $p;
// La clave del estado a partir de lo que llegue: «revision», «En revision», «en revisión»…
$claveEstado = function (string $v) use ($estados): string {
    $n = ImportadorTareas::norm($v);
    foreach ($estados as $k => $e) if ($n === ImportadorTareas::norm((string)$k) || $n === ImportadorTareas::norm((string)($e[0] ?? $e['label'] ?? ''))) return (string)$k;
    return '';
};
$etiqueta = fn(string $k) => (string)($estados[$k][0] ?? $estados[$k]['label'] ?? $k);
// Ítems del release de cada tarea (están en el ítem, no en la tarea)
$itemsDeTarea = [];
if (class_exists('Releases')) {
    foreach ((new JsonStore('rel_items'))->all() as $it) {
        foreach ((array)($it['tareas'] ?? []) as $tid) $itemsDeTarea[(int)$tid][] = (string)$it['codigo'];
    }
}
$base = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/api.php'))), '/') . '/';

$tareaApi = function (array $t, bool $detalle = false) use ($nombre, $etiqueta, $proyMapa, $itemsDeTarea, $base): array {
    $pid = (int)$t['proyecto_id'];
    $out = [
        'id'            => (int)$t['id'],
        'titulo'        => (string)($t['titulo'] ?? ''),
        'estado'        => (string)($t['estado'] ?? 'pendiente'),
        'estado_nombre' => $etiqueta((string)($t['estado'] ?? 'pendiente')),
        'prioridad'     => (string)($t['prioridad'] ?? ''),
        'proyecto'      => ['id' => $pid, 'nombre' => (string)($proyMapa[$pid]['nombre'] ?? '')],
        'asignados'     => array_values(array_filter(array_map($nombre, TareaRepo::asignadosDe($t)))),
        'colaboradores' => array_values(array_filter(array_map($nombre, array_keys(TareaRepo::colaboradoresDe($t))))),
        'fecha_inicio'  => (string)($t['fecha_inicio'] ?? ''),
        'fecha_limite'  => (string)($t['fecha_limite'] ?? ''),
        'completada_en' => (string)($t['completada_en'] ?? ''),
        'item_release'  => $itemsDeTarea[(int)$t['id']] ?? [],
        'url'           => $base . 'proyecto.php?id=' . $pid . '&tarea=' . (int)$t['id'],
    ];
    if (!$detalle) return $out;
    $desc = (string)($t['descripcion'] ?? '');
    $out['descripcion'] = class_exists('HtmlRico') ? HtmlRico::texto($desc) : trim(html_entity_decode(strip_tags($desc), ENT_QUOTES, 'UTF-8'));
    $out['observaciones_pendientes'] = (int)(((new ObservacionRepo())->pendientesPorTarea($pid))[(int)$t['id']] ?? 0);
    $out['commits'] = array_values((array)($t['commits_api'] ?? []));
    $out['historial'] = array_map(fn($h) => [
        'cuando' => (string)($h['t'] ?? ''), 'tipo' => (string)($h['tipo'] ?? ''), 'de' => $h['de'] ?? null, 'a' => $h['a'] ?? null,
        'por' => $nombre((int)($h['por'] ?? 0)), 'motivo' => (string)($h['motivo'] ?? ''),
    ], array_slice(array_values((array)($t['historial'] ?? [])), -20));
    return $out;
};
// ¿Puede mover esta tarea? Como en el tablero: la suya, o administrador. El supervisor, nunca.
$puedeMover = fn(array $t) => !Auth::esSupervisor() && (Auth::esAdmin() || TareaRepo::tieneAsignado($t, $yoId));
$tareaVisible = function (int $id) use ($tareasRepo): array {
    $t = $id > 0 ? $tareasRepo->buscar($id) : null;
    if (!$t || !puedeVerProyecto((int)$t['proyecto_id'])) apiError('No existe la tarea #' . $id . ' o no es de tus proyectos.', 404);
    return $t;
};
// Cambia el estado como el tablero: fecha de completada, aviso a quien lleva el proyecto, Releases al día
$cambiarEstado = function (array $t, string $nuevo) use ($tareasRepo, $yoId): string {
    $antes = (string)($t['estado'] ?? 'pendiente');
    if ($nuevo === $antes) return '';
    TareaRepo::$motivo = 'Claude Code';
    $tareasRepo->actualizar((int)$t['id'], ['estado' => $nuevo] + completadaEn($nuevo, $antes));
    TareaRepo::$motivo = '';
    $aviso = avisarTareaTerminada($t, $antes, $nuevo, $yoId);
    relAlDia([(int)$t['id']]);
    chequearEntrega((int)$t['proyecto_id'], new ProyectoRepo(), $tareasRepo);
    return trim($aviso);
};

/* ---------- Rutas ---------- */
switch ($ruta) {
    case 'yo':
        $exigir('GET');
        apiFin(['ok' => true,
            'yo' => ['id' => $yoId, 'nombre' => (string)$yo['nombre'], 'email' => (string)($yo['email'] ?? ''), 'perfiles' => MiembroRepo::accesosDe($yo)],
            'proyectos' => array_values(array_map(fn($p) => ['id' => (int)$p['id'], 'nombre' => (string)$p['nombre']], soloProyectosVisibles(array_values($proyMapa)))),
            'estados' => array_map(fn($k) => ['clave' => (string)$k, 'nombre' => $etiqueta((string)$k)], array_keys($estados)),
        ]);

    case 'tareas':
        if ($metodo === 'POST') {
            // Crear tareas con el JSON de Planificar (lo mismo que Planificar → Importar)
            if (!Auth::esGestor()) apiError('Crear tareas es de un administrador o del Scrum Master del proyecto (como en Planificar).', 403);
            $imp = new ImportadorTareas(new ProyectoRepo(), new MiembroRepo(), $tareasRepo);
            $imp->origen = 'api';
            $soloValidar = !empty($entrada['validar']);
            $rep = $imp->procesar($entrada, $soloValidar, !empty($entrada['actualizar']), Auth::esAdmin() ? null : array_keys(alcanceProyectos() ?? []));
            apiFin(['ok' => (bool)$rep['ok'], 'validado' => $soloValidar, 'creadas' => (int)($rep['creadas'] ?? 0), 'actualizadas' => (int)($rep['actualizadas'] ?? 0),
                'amarradas' => (int)($rep['amarradas'] ?? 0), 'tareas' => $rep['tareas'] ?? [], 'errores' => $rep['errores'] ?? [],
                'avisos' => array_values(array_filter(array_map(fn($f) => $f['avisos'] ? ['titulo' => $f['titulo'], 'avisos' => $f['avisos']] : null, (array)($rep['filas'] ?? []))))],
                $rep['ok'] ? 200 : 422);
        }
        $exigir('GET');
        $todas = !empty($_GET['todas']);
        $fProy = (int)($_GET['proyecto'] ?? 0);
        $fEst = isset($_GET['estado']) ? $claveEstado((string)$_GET['estado']) : '';
        if (isset($_GET['estado']) && $fEst === '') apiError('Estado desconocido. Usa: ' . implode(', ', array_keys($estados)) . '.');
        $q = ImportadorTareas::norm((string)($_GET['q'] ?? ''));
        $conTerminadas = !empty($_GET['terminadas']) || ($fEst !== '' && in_array($fEst, $finales, true));
        $lista = [];
        foreach ($tareasRepo->todas() as $t) {
            $pid = (int)$t['proyecto_id'];
            if ($fProy && $pid !== $fProy) continue;
            if (!$todas && !TareaRepo::tieneAsignado($t, $yoId)) continue;
            if ($fEst !== '' && ($t['estado'] ?? '') !== $fEst) continue;
            if (!$conTerminadas && in_array($t['estado'] ?? '', $finales, true)) continue;
            if ($q !== '' && !str_contains(ImportadorTareas::norm('#' . $t['id'] . ' ' . ($t['titulo'] ?? '') . ' ' . strip_tags((string)($t['descripcion'] ?? ''))), $q)) continue;
            if (!puedeVerProyecto($pid)) continue;
            $lista[] = $t;
        }
        usort($lista, fn($a, $b) => [($a['fecha_limite'] ?? '') ?: '9999', (int)$a['id']] <=> [($b['fecha_limite'] ?? '') ?: '9999', (int)$b['id']]);
        apiFin(['ok' => true, 'total' => count($lista), 'tareas' => array_map($tareaApi, array_slice($lista, 0, 200))]);

    case 'tarea':
        $exigir('GET');
        apiFin(['ok' => true, 'tarea' => $tareaApi($tareaVisible((int)($_GET['id'] ?? 0)), true)]);

    case 'items':
        $exigir('GET');
        if (!class_exists('Releases')) apiError('El módulo de Releases no está en este panel.', 404);
        $fEq = ImportadorTareas::norm((string)($_GET['equipo'] ?? ''));
        $fRel = strtoupper(trim((string)($_GET['release'] ?? '')));
        $items = [];
        foreach (Releases::items() as $it) {
            if ($fEq !== '' && ImportadorTareas::norm((string)$it['equipo']) !== $fEq) continue;
            if ($fRel !== '' && strtoupper((string)$it['release']) !== $fRel) continue;
            $items[] = ['codigo' => (string)$it['codigo'], 'titulo' => (string)$it['titulo'], 'release' => (string)$it['release'], 'equipo' => (string)$it['equipo'],
                        'inicio' => (string)($it['inicio'] ?? ''), 'fin' => (string)($it['fin'] ?? ''), 'estado' => (string)($it['estado'] ?? ''), 'avance' => (int)($it['avance'] ?? 0),
                        'alcance' => (string)($it['alcance'] ?? '')];
        }
        apiFin(['ok' => true, 'total' => count($items), 'items' => $items]);

    case 'estado':
        $exigir('POST');
        $t = $tareaVisible((int)($entrada['id'] ?? 0));
        if (!$puedeMover($t)) apiError('Solo puedes cambiar el estado de tus tareas.', 403);
        $nuevo = $claveEstado((string)($entrada['estado'] ?? ''));
        if ($nuevo === '') apiError('Estado desconocido. Usa: ' . implode(', ', array_keys($estados)) . '.');
        $aviso = $cambiarEstado($t, $nuevo);
        apiFin(['ok' => true, 'mensaje' => 'La tarea #' . (int)$t['id'] . ' quedó en «' . $etiqueta($nuevo) . '».' . ($aviso !== '' ? ' ' . $aviso : ''),
                'tarea' => $tareaApi($tareasRepo->buscar((int)$t['id']) ?? $t)]);

    case 'commit':
        $exigir('POST');
        $t = $tareaVisible((int)($entrada['id'] ?? 0));
        if (!$puedeMover($t) && !puedeGestionar((int)$t['proyecto_id'])) apiError('Solo puedes asociar commits a tus tareas.', 403);
        $sha = strtolower(trim((string)($entrada['sha'] ?? '')));
        if (!preg_match('/^[0-9a-f]{7,40}$/', $sha)) apiError('Falta el sha del commit (7 a 40 caracteres hexadecimales).');
        $url = trim((string)($entrada['url'] ?? ''));
        if ($url !== '' && !preg_match('#^https?://#i', $url)) $url = '';
        $mensaje = mb_substr(trim(strtok((string)($entrada['mensaje'] ?? ''), "\n") ?: ''), 0, 300);
        $commits = array_values(array_filter((array)($t['commits_api'] ?? []), fn($c) => !str_starts_with((string)($c['sha'] ?? ''), substr($sha, 0, 7))));
        $commits[] = ['sha' => substr($sha, 0, 40), 'mensaje' => $mensaje, 'url' => $url, 'repo' => mb_substr(trim((string)($entrada['repo'] ?? '')), 0, 80),
                      'rama' => mb_substr(trim((string)($entrada['rama'] ?? '')), 0, 120), 'autor' => (string)$yo['nombre'], 'fecha' => date('Y-m-d H:i')];
        $tareasRepo->actualizar((int)$t['id'], ['commits_api' => array_slice($commits, -100)]);
        $msg = 'Commit ' . substr($sha, 0, 7) . ' asociado a la tarea #' . (int)$t['id'] . '.';
        if (trim((string)($entrada['estado'] ?? '')) !== '') {
            // Con estado: lo pide quien llama (si puede mover la tarea)
            $nuevo = $claveEstado((string)$entrada['estado']);
            if ($nuevo === '') apiError('Commit asociado, pero el estado «' . $entrada['estado'] . '» no existe.');
            if (!$puedeMover($t)) apiError('Commit asociado, pero solo puedes cambiar el estado de tus tareas.', 403);
            $aviso = $cambiarEstado($tareasRepo->buscar((int)$t['id']) ?? $t, $nuevo);
            $msg .= ' Quedó en «' . $etiqueta($nuevo) . '».' . ($aviso !== '' ? ' ' . $aviso : '');
        } elseif ($mensaje !== '') {
            // Sin estado: las palabras del estándar (#id, closes #id…), solo hacia adelante, como al leer el repo
            $movidas = AutoEstado::aplicar((int)$t['proyecto_id'], [['sha' => $sha, 'msg' => $mensaje]]);
            foreach ($movidas as $mv) if ((int)$mv['tarea'] === (int)$t['id']) $msg .= ' Pasó a «' . $etiqueta((string)$mv['a']) . '».';
            if ($movidas) relAlDia(array_map(fn($mv) => (int)$mv['tarea'], $movidas));
        }
        apiFin(['ok' => true, 'mensaje' => $msg, 'tarea' => $tareaApi($tareasRepo->buscar((int)$t['id']) ?? $t, true)]);

    default:
        apiFin(['ok' => false, 'error' => 'Ruta desconocida.', 'rutas' => [
            'GET ?r=yo', 'GET ?r=tareas[&todas=1][&proyecto=ID][&estado=…][&q=…][&terminadas=1]', 'GET ?r=tarea&id=N',
            'GET ?r=items[&equipo=…][&release=…]', 'POST ?r=estado {id, estado}', 'POST ?r=commit {id, sha, mensaje, url, repo, rama, estado}',
            'POST ?r=tareas {tareas:[…], validar}',
        ]], 404);
}
