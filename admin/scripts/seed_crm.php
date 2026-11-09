<?php
/**
 * Seed del proyecto CRM (Microservicio CRM Comercial & IA Autónomo), desde los Excel
 * «InnoTech_CRM_Cronograma_General_y_Entregables_R1_R2_Vs.02» y «…Calendario de
 * Revisiones y Seguimiento Semanal Vs.02»:
 *
 *  - El proyecto «CRM» (si no existe), con su equipo y su PO.
 *  - Su planificación aparte «CRM»: 2 sprints de dos semanas de calendario desde el
 *    04/11/2026 (después de los feriados de Difuntos y Cuenca), con sus releases:
 *    R1 = S01 (04/11 → 15/11) y R2 = S02 (16/11 → 29/11). No se mezcla con los
 *    releases de SIAU-UBE.
 *  - Las 15 actividades del cronograma como historias de usuario en su sprint, cada
 *    una con su tarea (fechas, estado y responsables) y, en el detalle, su capa
 *    arquitectónica, su entregable y los criterios de aceptación (DoD).
 *  - Los hitos de gobernanza como tareas en su fecha, y el objetivo de cada sprint.
 *
 * Los responsables se buscan por nombre entre la gente del panel (sin tildes ni
 * mayúsculas). Quien no esté (p. ej. «Dev Refuerzo») queda sin asignar y se avisa.
 * Se puede correr otra vez: lo que ya está no se duplica.
 *
 *   php admin/scripts/seed_crm.php --seco      (dice qué haría, sin escribir)
 *   php admin/scripts/seed_crm.php             (lo crea)
 */
require_once __DIR__ . '/../lib/core/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script solo se ejecuta por terminal.\n");
}
require_once __DIR__ . '/../lib/releases/Releases.php';
require_once __DIR__ . '/../lib/backlog/Backlog.php';

$seco = in_array('--seco', array_slice($argv, 1), true);
$dice = fn(string $t) => print($t . "\n");
$dice($seco ? '— Modo de prueba (--seco): no se escribe nada.' : '— Seed del proyecto CRM');

/* ======================= Los datos (de los Excel) ======================= */

// Quién es quién: las palabras que tiene que tener su nombre en el panel
const CRM_GENTE = [
    'Freddy'  => ['freddy', 'bravo'],
    'Gabriel' => ['gabriel', 'alavera'],
    'Kevin'   => ['kevin', 'astudillo'],
    'Felipe'  => ['felipe', 'arevalo'],
    'Abel'    => ['abel', 'flores'],
];
const CRM_ALIAS = [
    'freddy bravo' => 'Freddy', 'freddy' => 'Freddy', 'gabriel' => 'Gabriel', 'gabriel alavera' => 'Gabriel',
    'kevin astudillo' => 'Kevin', 'kevin astudillo (po)' => 'Kevin', 'kevin' => 'Kevin', 'felipe arevalo' => 'Felipe',
    'abel flores' => 'Abel', 'abel flores (po comercial ube)' => 'Abel', 'abel flores (jefatura comercial)' => 'Abel',
    'felipe arevalo (subgerencia)' => 'Felipe', 'dev refuerzo' => '', 'dev ref.' => '', 'desarrollador refuerzo' => '', 'equipo tecnico' => '',
];

$releases = [
    ['codigo' => 'R1', 'nombre' => 'MVP Core Microservicio CRM, adaptadores & pipeline de leads', 'desde' => 1, 'hasta' => 1],
    ['codigo' => 'R2', 'nombre' => 'Agentes IA autónomos, integración bidireccional SIAU y suite analítica', 'desde' => 2, 'hasta' => 2],
];

// Release (sprint), código, actividad, responsables, capa, inicio, fin, estado
$actividades = [
    ['S01', 'CRM-R1-01', 'Arquitectura Microservicio: Setup Docker, FastAPI/Django, PostgreSQL multi-tenant y Gateway', 'Freddy Bravo', 'ms-crm-core / Backend', '2026-11-04', '2026-11-08', 'en_curso'],
    ['S01', 'CRM-R1-02', 'Levantamiento y Modelado BPMN de Flujos Comerciales, Pipeline y Embudo de Ventas (P00-P04)', 'Gabriel', 'Procesos / Negocio', '2026-11-04', '2026-11-08', 'en_curso'],
    ['S01', 'CRM-R1-03', 'Diseño UX/UI', 'Kevin Astudillo', 'UX/UI & Producto', '2026-11-04', '2026-11-09', 'en_curso'],
    ['S01', 'CRM-R1-04', 'Pipeline Lead Management: Estados comerciales, campos personalizados, scoring y funnel dinámico', 'Freddy Bravo', 'ms-crm-leads / Logic', '2026-11-09', '2026-11-12', 'por_hacer'],
    ['S01', 'CRM-R1-05', 'Webhook Receptor e Ingesta Automatizada de Leads Respond.io / WhatsApp API', 'Freddy Bravo', 'ms-crm-connectors', '2026-11-10', '2026-11-13', 'por_hacer'],
    ['S01', 'CRM-R1-06', 'Especificación de Contratos OpenAPI / Swagger para Interoperabilidad Externa y SIAU', 'Gabriel / Freddy', 'API Gateway / Docs', '2026-11-11', '2026-11-14', 'por_hacer'],
    ['S01', 'CRM-R1-07', 'Hito Sprint Review 1: Demostración técnica ingesta multicanal, pipeline y aprobación R1', 'Kevin Astudillo (PO)', 'Hito / Validación', '2026-11-13', '2026-11-13', 'por_hacer'],
    ['S02', 'CRM-R2-01', 'Motor IA Conversacional: Agentes autónomos para calificación, seguimiento y FAQ académica', 'Freddy / Dev Refuerzo', 'ms-crm-ai-agent', '2026-11-16', '2026-11-23', 'por_hacer'],
    ['S02', 'CRM-R2-02', 'Módulo de Asignación Inteligente de Leads (Round-robin / Carga asesores) y Disparadores', 'Gabriel / Freddy', 'ms-crm-automation', '2026-11-16', '2026-11-20', 'por_hacer'],
    ['S02', 'CRM-R2-03', 'Conectores Bidireccionales SIAU-UBE: Sync expediente aspirante, oferta académica y matrícula', 'Dev Refuerzo', 'ms-crm-integration', '2026-11-18', '2026-11-24', 'por_hacer'],
    ['S02', 'CRM-R2-04', 'Frontend CRM: Dashboard operativo, vista interactiva y panel de gestión de asesores', 'Kevin Astudillo', 'CRM WebApp / Frontend', '2026-11-17', '2026-11-25', 'por_hacer'],
    ['S02', 'CRM-R2-05', 'Dashboard Analítico & Reportes Ejecutivos: Conversión, efectividad por asesor, CAC y canal', 'Freddy Bravo', 'ms-crm-analytics', '2026-11-20', '2026-11-26', 'por_hacer'],
    ['S02', 'CRM-R2-06', 'Pruebas UAT Integrales, Simulación de Carga, Validación de Seguridad Multi-tenant y API', 'Gabriel / Abel Flores', 'QA & Negocio', '2026-11-23', '2026-11-27', 'por_hacer'],
    ['S02', 'CRM-R2-07', 'Empaquetamiento Comercial Multi-cliente (Docker/Helm) y Manual de Integración InnoTech', 'Freddy Bravo', 'DevOps / Release', '2026-11-26', '2026-11-30', 'por_hacer'],
    ['S02', 'CRM-R2-08', 'Hito Sprint Review 2: Puesta en Producción UBE, Entrega a Gerencia y Cierre de Proyecto', 'Kevin Astudillo (PO)', 'Hito / Entrega', '2026-11-30', '2026-11-30', 'por_hacer'],
];

// Entregable de cada actividad: código, nombre, funcionalidades clave, criterios de aceptación (DoD), evidencia
$entregables = [
    'CRM-R1-01' => ['ENT-R1-01', 'Infraestructura Base & Multi-tenancy', 'Contenedores Docker, Postgres aislado por tenant, autenticación JWT, API Gateway centralizado.', 'Despliegue automatizado en Docker compose; aislamiento lógico de BD validado para UBE y futuros clientes.', 'Repositorio Git, script docker-compose.yml, test de conexión multi-tenant.'],
    'CRM-R1-02' => ['ENT-R1-02', 'Catálogo de Procesos & BPMN Comercial', 'Diagramación formal P00 a P04 (Captación, Asignación, Cualificación, Seguimiento, Cierre).', 'Aprobación formal de jefatura comercial UBE (Abel Flores); mapeo de estados y transiciones comerciales.', 'Diagramas BPMN en PDF/Bizagi, matriz de roles y permisos.'],
    'CRM-R1-04' => ['ENT-R1-03', 'Pipeline Kanban & Ficha 360° Lead', 'Tablero Kanban dinámico, drag-and-drop de leads por etapas, vista detallada 360° de interacciones.', 'Manejo ágil de estados; actualización asíncrona de etapa; visualización de historial de mensajes y notas.', 'Demo interactiva funcional en ambiente QA; prototipo validado.'],
    'CRM-R1-05' => ['ENT-R1-04', 'Conector Webhook Respond.io / WhatsApp', 'Ingesta automática de prospectos desde WhatsApp Business vía Respond.io; deduplicación por teléfono/cédula.', "Tiempo de ingesta < 2s; creación automática del lead en etapa 'Nuevo Prospecto'; cero duplicados.", 'Postman collection ejecutada; log de webhooks procesados exitosamente.'],
    'CRM-R1-06' => ['ENT-R1-05', 'Contratos API & OpenAPI Specification', 'Endpoints RESTful documentados para ingesta de prospectos, consulta de estado y actualización.', 'Swagger UI interactivo al 100% de cobertura de endpoints de Leads; validación de esquemas JSON.', 'Documentación Swagger pública en /docs; especificación openapi.json.'],
    'CRM-R2-01' => ['ENT-R2-01', 'Agente Autónomo de IA Comercial', 'Cualificación autónoma de leads 24/7 vía WhatsApp, respuestas a FAQ de oferta académica y agendamiento.', 'Precisión en respuestas de oferta académica > 90%; clasificación correcta de interés; escalamiento humano.', 'Grabaciones de chat de prueba, prompt template versionado, logs de OpenAI/Claude API.'],
    'CRM-R2-02' => ['ENT-R2-02', 'Motor de Asignación y Enrutamiento', 'Distribución inteligente de prospectos a asesores (Round-Robin ponderado, por facultad o carga horaria).', 'Asignación instantánea según disponibilidad; alerta inmediata al asesor asignado por correo y notificación.', 'Pruebas de estrés de asignación masiva; reporte de balanceo de carga.'],
    'CRM-R2-03' => ['ENT-R2-03', 'Conector Bidireccional SIAU-UBE', 'Sincronización de aspirantes convertidos hacia el módulo de Admisión SIAU y actualización de estado de matrícula.', 'Generación automática del expediente del aspirante en SIAU con ID único institucional al ganar la oportunidad.', 'Traza de sincronización SIAU; verificación en base de datos SIAU-UBE.'],
    'CRM-R2-05' => ['ENT-R2-04', 'Dashboard Analítico & KPIs de Venta', 'Métricas en tiempo real: tasa de conversión de embudo, efectividad por asesor comercial, CAC y lead time.', 'Filtros multidimensionales (fecha, carrera, canal, asesor); exportación a Excel; tiempo de render < 1.5s.', 'Reporte consolidado generado; verificación de fórmulas de efectividad comercial.'],
    'CRM-R2-07' => ['ENT-R2-05', 'Paquete Comercial InnoTech & UAT', 'Empaquetado desacoplado para venta externa a otras instituciones, manual técnico de API y acta UAT firmada.', 'Aprobación formal de pruebas de aceptación UAT por Abel Flores; manual de integración externa InnoTech.', 'Acta UAT firmada, manual de arquitectura independiente, Docker Hub repository.'],
];

// Hitos de gobernanza: código, nombre, fecha, participantes, objetivo, criterio. H-03 y H-07 ya son las historias de Sprint Review.
$hitos = [
    ['H-01', 'Kick-off & Definición de Arquitectura Microservicio', '2026-11-04', 'Felipe Arévalo, Kevin Astudillo, Gabriel, Freddy Bravo', 'Alineación formal del mandato de Subgerencia: arquitectura desacoplada y plazo estricto de 4 semanas post-feriado.', 'Arquitectura técnica aprobada y repositorio Docker base inicializado.', ''],
    ['H-02', 'Cierre de Levantamiento BPMN y Contratos de Ingesta', '2026-11-08', 'Kevin Astudillo, Gabriel, Abel Flores', 'Validación de estados comerciales de ventas y reglas de cualificación para el conector Respond.io.', 'Acta de aprobación de procesos P00 a P04 por el área comercial.', ''],
    ['H-03', 'Sprint Review 1 & Demo Funcional R1', '2026-11-13', 'Felipe Arévalo, Kevin Astudillo, Freddy Bravo, Gabriel', 'Demostración técnica en vivo: entrada de lead por WhatsApp, visualización en pipeline Kanban y OpenAPI.', 'Aprobación formal de Release 1 por Subgerencia; autorización para incorporar Dev Refuerzo en R2.', 'CRM-R1-07'],
    ['H-04', 'Incorporación de Dev Refuerzo & Mid-Term Check', '2026-11-16', 'Kevin Astudillo, Freddy Bravo, Dev Refuerzo', 'Onboarding técnico inmediato en conectores SIAU y soporte en módulo de IA.', 'Ambiente local configurado y asignación de historias de integración SIAU.', ''],
    ['H-05', 'Validación Funcional de Agentes IA & Enrutamiento', '2026-11-23', 'Kevin Astudillo, Gabriel, Freddy Bravo, Abel Flores', 'Pruebas de diálogo simulado con agente IA autónomo y derivación por round-robin a asesores.', 'Tasa de acierto de respuestas académicas > 90% y escalamiento correcto.', ''],
    ['H-06', 'Comité de Aceptación UAT & Pruebas Integrales', '2026-11-27', 'Abel Flores, Gabriel, Kevin Astudillo', 'Pruebas de punta a punta: lead en WhatsApp → IA cualifica → asesor gestiona → sync con SIAU para matrícula.', 'Firma de Acta de Aceptación Funcional UAT sin errores bloqueantes.', ''],
    ['H-07', 'Go-Live en Producción UBE & Cierre de Proyecto', '2026-11-30', 'Felipe Arévalo, Kevin Astudillo, Equipo Técnico', 'Puesta en producción del microservicio para UBE y entrega de documentación comercial para InnoTech-Solutions.', 'Sistema 100% operativo en producción; repositorio empaquetado para terceros clientes.', 'CRM-R2-08'],
];

// El objetivo de cada sprint (agenda semanal: lunes planificación, miércoles sincronización técnica, viernes demo y cierre)
$objetivos = [
    'S01' => 'Release 1 · MVP Core: setup multi-tenant, BPMN comercial (P00-P04), UX/UI Kanban, pipeline de leads, conector Respond.io y contratos OpenAPI. Cierra con el Sprint Review 1 (vie 13/11) ante Subgerencia.',
    'S02' => 'Release 2 · Agentes IA autónomos, asignación inteligente, conectores SIAU-UBE, frontend y analítica; UAT integral y Go-Live en producción UBE (30/11).',
];

/* ======================= La gente del panel ======================= */

$plano = fn(string $s) => strtolower(trim(strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'ñ' => 'n', 'Ñ' => 'n'])));
$miembros = (new MiembroRepo())->todos();
$quien = [];       // 'Freddy' => miembro
foreach (CRM_GENTE as $clave => $palabras) {
    $hallados = array_values(array_filter($miembros, function ($m) use ($palabras, $plano) {
        $n = $plano((string)($m['nombre'] ?? ''));
        foreach ($palabras as $p) if (!preg_match('/\b' . preg_quote($p, '/') . '\b/u', $n)) return false;
        return !isset($m['activo']) || $m['activo'];
    }));
    $quien[$clave] = count($hallados) === 1 ? $hallados[0] : null;
    $dice(sprintf('  %-8s → %s', $clave, count($hallados) === 1 ? $hallados[0]['nombre'] . ' (#' . (int)$hallados[0]['id'] . ')'
        : (count($hallados) > 1 ? 'hay ' . count($hallados) . ' con ese nombre: queda sin asignar' : 'no está en el panel: queda sin asignar')));
}
// «Gabriel / Freddy», «Kevin Astudillo (PO)»… → los ids de los que están
$ids = function (string $texto) use ($plano, $quien): array {
    $out = [];
    foreach (preg_split('/[\/,]| y /u', $texto) as $parte) {
        $k = CRM_ALIAS[$plano($parte)] ?? '';
        if ($k !== '' && !empty($quien[$k])) $out[] = (int)$quien[$k]['id'];
    }
    return array_values(array_unique($out));
};

/* ======================= El proyecto ======================= */

$proyRepo = new ProyectoRepo();
$crm = null;
foreach ($proyRepo->todos() as $p) if ($plano((string)$p['nombre']) === 'crm') { $crm = $p; break; }
$equipo = array_values(array_unique(array_filter(array_map(fn($k) => (int)($quien[$k]['id'] ?? 0), ['Kevin', 'Freddy', 'Gabriel', 'Felipe']))));
if ($crm) {
    $dice('Proyecto: ya existe «' . $crm['nombre'] . '» (#' . (int)$crm['id'] . ').');
    $faltan = array_values(array_diff($equipo, array_map('intval', (array)($crm['miembros'] ?? []))));
    if ($faltan && !$seco) $proyRepo->actualizar((int)$crm['id'], ['miembros' => array_values(array_unique(array_merge(array_map('intval', (array)($crm['miembros'] ?? [])), $faltan)))]);
    if ($faltan) $dice('  + al equipo: ' . count($faltan) . ' persona(s).');
} else {
    $dice('Proyecto: se crea «CRM» con ' . count($equipo) . ' persona(s) en el equipo' . (!empty($quien['Kevin']) ? ' y Kevin Astudillo de PO' : '') . '.');
    if (!$seco) $crm = $proyRepo->crear([
        'nombre' => 'CRM',
        'descripcion' => 'Microservicio CRM Comercial & IA Autónomo: arquitectura desacoplada multi-tenant, integración SIAU-UBE y Respond.io. 2 releases en 4 semanas (04/11/2026 – 30/11/2026).',
        'miembros' => $equipo,
        'po' => (int)($quien['Kevin']['id'] ?? 0),
    ]);
}
$pid = (int)($crm['id'] ?? 0);

/* ======================= La planificación ======================= */

$plan = null;
foreach (Backlog::planificaciones() as $pl) if ($plano($pl['nombre']) === 'crm') { $plan = $pl; break; }
$dice(($plan ? 'Planificación «CRM»: se pone al día' : 'Planificación «CRM»: se crea') . ' — 2 sprints de dos semanas de calendario desde el 04/11/2026; R1 = S01, R2 = S02.');
if (!$seco) {
    $plan = Backlog::guardarPlanificacion([
        'id' => $plan['id'] ?? '', 'nombre' => 'CRM', 'inicio' => '2026-11-04', 'semanas' => 2, 'cantidad' => 2, 'modo' => 'semanas',
        'releases' => $releases, 'proyectos' => array_values(array_unique(array_merge($plan['proyectos'] ?? [], $pid ? [$pid] : []))),
    ]);
}
{
    foreach (Backlog::calcularPlan('2026-11-04', 2, 2, 'semanas', $releases) as $cod => $s) {
        $dice(sprintf('  %s (%s): %s → %s · %d días hábiles', $cod, implode(', ', $s['releases']), $s['inicio'], $s['fin'], $s['habiles']));
    }
}

/* ======================= Las actividades ======================= */

Backlog::usarProyecto($pid);
$tareas = new TareaRepo();
$yaHu = [];
if ($pid) foreach (Backlog::historias(['proyecto_id' => $pid]) as $h) $yaHu[strtoupper($h['codigo'])] = $h;
$yaTareas = [];
if ($pid) foreach ($tareas->delProyecto($pid) as $t) $yaTareas[(string)$t['titulo']] = $t;
if ($seco) {
    // Solo contar lo que falta y quién quedaría sin responsable
    $faltanHu = count(array_filter($actividades, fn($a) => !isset($yaHu[$a[1]])));
    $faltanH = count(array_filter($hitos, fn($h) => $h[6] === '' && !isset($yaTareas['Hito ' . $h[0] . ' · ' . $h[1]])));
    $sinR = array_map(fn($a) => $a[1] . ' (' . $a[3] . ')', array_filter($actividades, fn($a) => !$ids($a[3])));
    $dice('Actividades: se crearían ' . $faltanHu . ' historia(s) con su tarea y ' . $faltanH . ' hito(s) como tareas.');
    if ($sinR) $dice('Quedarían sin responsable: ' . implode('; ', $sinR) . '.');
    exit("Listo (prueba). Córrelo sin --seco para crearlo.\n");
}
$hitoDe = [];
foreach ($hitos as $hi) if ($hi[6] !== '') $hitoDe[$hi[6]] = $hi;
$estadoTarea = ['en_curso' => 'progreso', 'por_hacer' => 'pendiente', 'hecho' => 'hecho'];
$sinResp = [];
$nHu = $nT = 0;

foreach ($actividades as [$sp, $cod, $titulo, $resp, $capa, $ini, $fin, $est]) {
    $asig = $ids($resp);
    if (!$asig) $sinResp[] = $cod . ' (' . $resp . ')';
    $ent = $entregables[$cod] ?? null;
    $hi = $hitoDe[$cod] ?? null;
    $notas = '<p><b>Capa arquitectónica:</b> ' . e($capa) . '<br><b>Responsable en el cronograma:</b> ' . e($resp) . '<br><b>Fechas:</b> ' . e(date('d/m/Y', strtotime($ini))) . ' → ' . e(date('d/m/Y', strtotime($fin))) . '</p>'
        . ($ent ? '<p><b>Entregable ' . e($ent[0]) . ' · ' . e($ent[1]) . '</b></p><ul><li><b>Funcionalidades clave:</b> ' . e($ent[2]) . '</li><li><b>Criterios de aceptación (DoD):</b> ' . e($ent[3]) . '</li><li><b>Evidencia verificable:</b> ' . e($ent[4]) . '</li></ul>' : '')
        . ($hi ? '<p><b>Hito ' . e($hi[0]) . ' · ' . e($hi[1]) . '</b> (participan: ' . e($hi[3]) . ')</p><ul><li><b>Objetivo:</b> ' . e($hi[4]) . '</li><li><b>Criterio de aprobación:</b> ' . e($hi[5]) . '</li></ul>' : '');
    $tituloTarea = $cod . ' · ' . $titulo;
    // La tarea: en el tablero del proyecto, con sus fechas y responsables
    $tid = (int)($yaTareas[$tituloTarea]['id'] ?? 0);
    if (!$tid) {
        $t = $tareas->crear(['proyecto_id' => $pid, 'titulo' => mb_substr($tituloTarea, 0, 250), 'descripcion' => HtmlRico::limpiar($notas),
            'prioridad' => str_starts_with($titulo, 'Hito') ? 'alta' : 'media', 'estado' => $estadoTarea[$est] ?? 'pendiente',
            'fecha_inicio' => $ini, 'fecha_limite' => $fin, 'asignados' => $asig, 'origen' => 'plan']);
        $tid = (int)$t['id'];
        $nT++;
    }
    // La historia: en su sprint, con su responsable y la tarea
    if (!isset($yaHu[$cod])) {
        Backlog::guardar(['proyecto_id' => $pid, 'codigo' => $cod, 'titulo' => mb_substr($titulo, 0, 200), 'notas' => $notas,
            'sprint' => $sp, 'estado' => $est, 'prioridad' => str_starts_with($titulo, 'Hito') ? 'alta' : 'media',
            'responsable' => $asig[0] ?? 0, 'tareas' => [$tid]], 0, 0);
        $nHu++;
    }
}
// Los hitos que no son una historia: tareas en su fecha
foreach ($hitos as [$cod, $nom, $fecha, $part, $obj, $crit, $esHu]) {
    if ($esHu !== '') continue;
    $tituloH = 'Hito ' . $cod . ' · ' . $nom;
    if (isset($yaTareas[$tituloH])) continue;
    $asig = $ids(str_replace(',', '/', $part));
    $tareas->crear(['proyecto_id' => $pid, 'titulo' => $tituloH,
        'descripcion' => HtmlRico::limpiar('<p><b>Participan:</b> ' . e($part) . '</p><ul><li><b>Objetivo / decisión:</b> ' . e($obj) . '</li><li><b>Criterio de aprobación:</b> ' . e($crit) . '</li></ul>'),
        'prioridad' => 'alta', 'estado' => 'pendiente', 'fecha_inicio' => $fecha, 'fecha_limite' => $fecha, 'asignados' => $asig, 'origen' => 'plan']);
    $nT++;
}
// El objetivo de cada sprint
foreach ($objetivos as $sp => $obj) {
    if (trim((string)(Backlog::ficha($pid, $sp)['objetivo'] ?? '')) === '') Backlog::guardarPlan($pid, $sp, ['objetivo' => $obj], 0);
}

$dice('Actividades: ' . $nHu . ' historia(s) y ' . $nT . ' tarea(s) nuevas (lo que ya estaba no se tocó).');
if ($sinResp) $dice('Sin responsable en el panel (asígnalas a mano): ' . implode('; ', $sinResp) . '.');
$dice('Listo: proyecto.php?id=' . $pid . '#vista-sprints');
