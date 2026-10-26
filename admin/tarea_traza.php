<?php
/**
 * Trazabilidad de una tarea, en JSON, para los pasos de su ficha (assets/admin.js ·
 * abrirDetalleTarea): quién la agregó y por dónde pasó (estados, responsables,
 * fechas, intercambios), cuándo empezó y terminó de verdad, sus observaciones
 * (los impedimentos), el ítem del release al que suma y los commits con su #id.
 *
 * Lo de antes de guardar el historial se arma con lo que hay: quién la creó,
 * los intercambios aceptados, las observaciones, la fecha en que se completó y
 * los commits. Los cambios de responsable a mano anteriores no quedaron.
 */
require_once __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$id = (int)($_GET['id'] ?? 0);
$t = (new TareaRepo())->buscar($id);
if (!$t || !puedeVerProyecto((int)$t['proyecto_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Esa tarea no está disponible.']);
    exit;
}

$miembros = (new MiembroRepo())->mapa();
$nombre   = fn(int $mid) => (string)($miembros[$mid]['nombre'] ?? '');
$nombres  = fn(array $ids) => implode(', ', array_filter(array_map(fn($x) => $nombre((int)$x), $ids))) ?: 'nadie';
$estados  = Catalogo::estadosTarea();
$etiqueta = fn(string $k) => (string)($estados[$k][0] ?? $k);
$finales  = Catalogo::estadosFinales();
$prios    = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'urgente' => 'Urgente'];
$rango    = fn(array $f) => 'del ' . ($f[0] !== '' ? $f[0] : '—') . ' al ' . ($f[1] !== '' ? $f[1] : '—');

/* ---------- Historia ---------- */
$historia = [];
$ag = TareaRepo::agregadaPor($t, $miembros);
// Para quién era al crearla: lo que había antes del primer cambio de responsables
$alCrear = TareaRepo::asignadosDe($t);
foreach ((array)($t['historial'] ?? []) as $h) if (($h['tipo'] ?? '') === 'asignados') { $alCrear = array_map('intval', (array)$h['de']); break; }
$historia[] = [
    't' => (string)($t['creado'] ?? ''), 'tipo' => 'creada', 'por' => (string)($ag['miembro']['nombre'] ?? ''),
    'texto' => 'Se agregó la tarea' . ($ag['origen'] !== '' ? ' (' . $ag['origen'] . ')' : '') . ' para ' . $nombres($alCrear),
];
// Lo que dejó actualizar() desde que se guarda el historial
$colabs = TareaRepo::colaboradoresDe($t);
$inicioReal = '';
$finReal = '';
foreach ((array)($t['historial'] ?? []) as $h) {
    if (!is_array($h)) continue;
    $motivo = (string)($h['motivo'] ?? '');
    if ($motivo === 'intercambio') continue;   // lo cuenta mejor el propio intercambio, con su motivo (abajo)
    if ($motivo === 'colaborador' && ($h['tipo'] ?? '') === 'asignados') {
        $nuevos = array_diff(array_map('intval', (array)$h['a']), array_map('intval', (array)$h['de']));
        $paraQue = trim((string)($colabs[(int)(array_values($nuevos)[0] ?? 0)]['nota'] ?? ''));
        $historia[] = ['t' => (string)($h['t'] ?? ''), 'tipo' => 'colaborador', 'por' => $nombre((int)($h['por'] ?? 0)), 'motivo' => '',
                       'texto' => 'Sumó a ' . $nombres($nuevos) . ' para que le ayude' . ($paraQue !== '' ? ': «' . mb_substr($paraQue, 0, 200) . '»' : '')];
        continue;
    }
    $texto = match ($h['tipo'] ?? '') {
        'estado'    => 'Pasó de «' . $etiqueta((string)$h['de']) . '» a «' . $etiqueta((string)$h['a']) . '»',
        'asignados' => (function () use ($h, $nombres) {
            $de = array_map('intval', (array)$h['de']);
            $a = array_map('intval', (array)$h['a']);
            $salen = array_diff($de, $a);
            $entran = array_diff($a, $de);
            if ($salen && $entran) return 'Se la pasaron de ' . $nombres($salen) . ' a ' . $nombres($entran);
            if ($entran) return 'Se sumó ' . $nombres($entran);
            return 'Dejó de tenerla ' . $nombres($salen);
        })(),
        'fechas'    => 'Cambiaron las fechas: antes ' . $rango((array)$h['de']) . '; ahora ' . $rango((array)$h['a']),
        'prioridad' => 'Prioridad: ' . ($prios[(string)$h['de']] ?? $h['de']) . ' → ' . ($prios[(string)$h['a']] ?? $h['a']),
        'titulo'    => 'Cambió el título (antes: «' . mb_substr((string)$h['de'], 0, 90) . '»)',
        default     => '',
    };
    if ($texto === '') continue;
    // Lo que movió un commit no lo hizo quien tenía abierta la página en ese momento
    $historia[] = ['t' => (string)($h['t'] ?? ''), 'tipo' => (string)$h['tipo'], 'por' => str_starts_with($motivo, 'commit ') ? '' : $nombre((int)($h['por'] ?? 0)),
                   'motivo' => $motivo, 'texto' => $texto];
    if (($h['tipo'] ?? '') === 'estado') {
        if ($inicioReal === '' && (string)$h['a'] !== 'pendiente') $inicioReal = (string)$h['t'];
        if (in_array((string)$h['a'], $finales, true)) $finReal = (string)$h['t'];
        elseif (in_array((string)$h['de'], $finales, true)) $finReal = '';   // la reabrieron
    }
}
// Intercambios aceptados: la tarea pasó de una persona a otra
foreach ((new IntercambioRepo())->delProyecto((int)$t['proyecto_id']) as $x) {
    if (($x['estado'] ?? '') !== 'aceptado') continue;
    $esDe = (int)($x['tarea_de'] ?? 0) === $id;
    if (!$esDe && (int)($x['tarea_para'] ?? 0) !== $id) continue;
    [$sale, $entra] = $esDe ? [(int)$x['de_id'], (int)$x['para_id']] : [(int)$x['para_id'], (int)$x['de_id']];
    $porQue = trim((string)($x['nota'] ?? ''));
    $historia[] = ['t' => (string)(($x['resuelto_en'] ?? '') ?: ($x['creado'] ?? '')), 'tipo' => 'intercambio', 'por' => $nombre((int)$x['para_id']),
                   'motivo' => 'intercambio', 'texto' => 'Por un intercambio, pasó de ' . ($nombre($sale) ?: 'alguien') . ' a ' . ($nombre($entra) ?: 'alguien')
                   . ' (' . mb_strtolower(Catalogo::motivoIntercambio((string)($x['motivo'] ?? 'otro'))) . ')' . ($porQue !== '' ? ': «' . mb_substr($porQue, 0, 200) . '»' : '')];
}
usort($historia, fn($a, $b) => strcmp($a['t'], $b['t']));
// Terminada antes del historial: la fecha que se guardaba al completarla
if ($finReal === '' && in_array($t['estado'] ?? '', $finales, true)) $finReal = (string)($t['completada_en'] ?? '');

/* ---------- Observaciones (impedimentos) ---------- */
$todasObs = array_values(array_filter((new ObservacionRepo())->delProyecto((int)$t['proyecto_id']), fn($o) => (int)($o['tarea_id'] ?? 0) === $id));
$respuestas = [];
foreach ($todasObs as $o) if ((int)($o['padre_id'] ?? 0) > 0) $respuestas[(int)$o['padre_id']] = ($respuestas[(int)$o['padre_id']] ?? 0) + 1;
$impedimentos = [];
foreach ($todasObs as $o) {
    if ((int)($o['padre_id'] ?? 0) > 0) continue;
    $impedimentos[] = [
        'texto'      => mb_substr(trim(html_entity_decode(strip_tags((string)($o['texto'] ?? '')), ENT_QUOTES, 'UTF-8')), 0, 500),
        'por'        => $nombre((int)($o['creado_por'] ?? 0)),
        'para'       => $nombres(ObservacionRepo::destinatariosDe($o) ?: [(int)($o['autor_id'] ?? 0)]),
        'creado'     => (string)($o['creado'] ?? ''),
        'resuelta'   => ($o['estado'] ?? 'pendiente') !== 'pendiente',
        'resuelto_en'=> (string)($o['resuelto_en'] ?? ''),
        'respuestas' => (int)($respuestas[(int)$o['id']] ?? 0),
        'tipo'       => (string)($o['tipo'] ?? 'nota'),
    ];
}
usort($impedimentos, fn($a, $b) => strcmp($b['creado'], $a['creado']));

/* ---------- Release ---------- */
$release = [];
if (is_file(__DIR__ . '/lib/Releases.php')) {
    require_once __DIR__ . '/lib/Releases.php';
    $ver = Releases::veTodo();
    foreach (Releases::items() as $it) {
        if (!in_array($id, array_map('intval', (array)($it['tareas'] ?? [])), true)) continue;
        $release[] = [
            'codigo' => (string)$it['codigo'], 'titulo' => (string)$it['titulo'], 'release' => (string)$it['release'], 'equipo' => (string)$it['equipo'],
            'inicio' => (string)($it['inicio'] ?? ''), 'fin' => (string)($it['fin'] ?? ''), 'avance' => (int)($it['avance'] ?? 0),
            'estado' => (string)(Releases::ESTADOS[$it['estado']][0] ?? $it['estado']), 'salud' => (string)(Releases::SALUD[$it['salud']][0] ?? ''),
            'url' => $ver ? 'releases.php?v=item&id=' . (int)$it['id'] : '',
        ];
    }
}

/* ---------- Commits con su #id ---------- */
// Los mismos que lee Métricas (últimos 182 días, en caché una hora): abrir la ficha no gasta más llamadas
$commits = [];
$proyecto = (new ProyectoRepo())->buscar((int)$t['proyecto_id']);
foreach ($proyecto ? ProyectoRepo::repos($proyecto) : [] as $rp) {
    $cr = Repos::commitsRecientes($rp['url'], 3000, trim((string)($rp['rama'] ?? '')), 182);
    if (($cr['estado'] ?? '') !== 'ok') continue;
    foreach ((array)$cr['commits'] as $c) {
        $msg = (string)($c['msg'] ?? '');
        if (!preg_match('/#' . $id . '(?!\d)/', $msg)) continue;
        $commits[] = ['sha' => substr((string)($c['sha'] ?? ''), 0, 7), 'msg' => trim(strtok($msg, "\n")), 'autor' => (string)(($c['nombre'] ?? '') ?: ($c['login'] ?? '')),
                      'fecha' => (string)($c['fecha'] ?? ''), 'url' => (string)($c['url'] ?? ''), 'repo' => (string)($rp['label'] ?? '')];
    }
}
usort($commits, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
$commits = array_slice($commits, 0, 60);
// Sin historial de estados: empezó con su primer commit
if ($inicioReal === '' && $commits) $inicioReal = substr((string)end($commits)['fecha'], 0, 16);

echo json_encode([
    'historia'     => $historia,
    'inicio_plan'  => (string)($t['fecha_inicio'] ?? ''),
    'fin_plan'     => (string)($t['fecha_limite'] ?? ''),
    'inicio_real'  => $inicioReal,
    'fin_real'     => $finReal,
    'impedimentos' => $impedimentos,
    'release'      => $release,
    'commits'      => $commits,
], JSON_UNESCAPED_UNICODE);
