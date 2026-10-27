<?php
/**
 * El contexto para registrar el trabajo como tareas, de TODOS los equipos y
 * releases: el formato de Planificar → Importar (y de la API), sus reglas, el
 * estándar de commits, el horario de subidas, los releases, los equipos con el
 * nombre de su proyecto en el panel y cada ítem con su «usar_para». Se arma en
 * vivo con lo que hay hoy en Releases, así el asistente de cada dev (Claude
 * Code, Codex, Copilot) sabe a qué ítem amarrar cada tarea.
 *
 * Se descarga desde Mi perfil → Tu asistente de código → Instrucciones.
 */
require_once __DIR__ . '/Models.php';
if (is_file(__DIR__ . '/Releases.php')) require_once __DIR__ . '/Releases.php';
if (is_file(__DIR__ . '/Cronograma.php')) require_once __DIR__ . '/Cronograma.php';

final class ContextoTareas
{
    private const DIAS = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];

    public static function generar(): array
    {
        $hayReleases = class_exists('Releases');
        $nombres = [];
        foreach ((new ProyectoRepo())->todos() as $p) $nombres[(int)$p['id']] = (string)$p['nombre'];

        // Equipos con el nombre exacto de su proyecto en el panel (el que va en «proyecto»)
        $equipos = [];
        $proyectoDe = [];
        if ($hayReleases) {
            foreach (Releases::equipos() as $nom => $e) {
                if ($nom === 'Todos') continue;
                $proys = array_values(array_filter(array_map(fn($id) => $nombres[$id] ?? '', Releases::proyectosEquipo($nom))));
                $proyectoDe[$nom] = $proys[0] ?? '';
                $equipos[$nom] = [
                    'proyecto_en_panel' => $proys[0] ?? '(sin proyecto vinculado: pídelo en Releases → Ajustes → Equipos)',
                    'otros_proyectos'   => array_slice($proys, 1),
                    'scrum_master'      => (string)($e['sm_nombre'] ?? ''),
                    'product_owner'     => (string)($e['po_nombre'] ?? ''),
                    'dominio'           => (string)($e['dominio'] ?? ''),
                ];
            }
        }

        $releases = [];
        if ($hayReleases) {
            foreach (Releases::releases() as $cod => $r) {
                $releases[$cod] = ['nombre' => (string)($r['nombre'] ?? ''), 'inicio' => (string)($r['inicio'] ?? ''), 'fin' => (string)($r['fin'] ?? ''),
                                   'estado' => (string)($r['estado'] ?? ''), 'sprints' => (string)($r['sprints'] ?? ''), 'modulos' => (string)($r['modulos'] ?? '')];
            }
        }

        $items = [];
        if ($hayReleases) {
            foreach (Releases::items() as $it) {
                $eq = (string)($it['equipo'] ?? '');
                $fila = [
                    'codigo'   => (string)$it['codigo'],
                    'release'  => (string)$it['release'],
                    'equipo'   => $eq,
                    'proyecto' => isset($proyectoDe[$eq])
                        ? ($proyectoDe[$eq] ?: '(sin proyecto vinculado: pídelo en Releases → Ajustes → Equipos)')
                        : '(ítem de plataforma: el proyecto de tu equipo)',
                    'titulo'   => (string)$it['titulo'],
                    'fechas'   => trim(($it['inicio'] ?? '') . ' → ' . ($it['fin'] ?? ''), ' →'),
                    'estado'   => (string)($it['estado'] ?? ''),
                    'avance'   => (int)($it['avance'] ?? 0),
                ];
                foreach (['microservicio', 'alcance', 'entregable', 'actividades', 'dependencias'] as $k) {
                    if (trim((string)($it[$k] ?? '')) !== '') $fila[$k] = (string)$it[$k];
                }
                $fila['usar_para'] = self::usarPara($it);
                $items[] = $fila;
            }
        }

        $ejemploProyecto = (string)(array_values(array_filter($proyectoDe))[0] ?? 'Equipo Beta');
        $ejemploItem = $items[0]['codigo'] ?? 'R2-B01';
        // De ejemplo, un ítem de un equipo en el release que está en curso
        $enCurso = (string)(array_key_first(array_filter($releases, fn($r) => $r['estado'] === 'en_curso')) ?? '');
        foreach ($items as $i) {
            if ($i['release'] === $enCurso && isset($proyectoDe[$i['equipo']])) {
                $ejemploItem = $i['codigo'];
                $ejemploProyecto = $proyectoDe[$i['equipo']] ?: $ejemploProyecto;
                break;
            }
        }
        $crono = function_exists('configDeploys') ? (configDeploys()['cronograma'] ?? []) : [];
        $dias = array_map(fn($d) => self::DIAS[$d] ?? (string)$d, (array)($crono['dias'] ?? [1, 4]));

        return [
            'para' => 'El asistente de código (Claude Code, Codex, Copilot) de cualquier equipo: cómo registrar el trabajo como tareas en InnoTech Hub y amarrarlas a su ítem del release. Todos los equipos y todos los releases.',
            'actualizado' => date('Y-m-d H:i'),
            'importante' => 'Los releases, los equipos y los ítems son los que hay hoy en el panel (Releases). Si un ítem no aparece aquí, no inventes el código: deja la tarea sin item_release. El nombre del proyecto va EXACTO como en «equipos» → proyecto_en_panel.',
            'como_subir_tareas' => [
                'donde' => 'InnoTech Hub → Planificar → Importar. Pega el JSON, pulsa «Validar» (no crea nada) y, si dice «Todo en orden», pulsa «Importar».',
                'formato' => ['tareas' => [[
                    'proyecto'     => 'Obligatorio. El NOMBRE exacto del proyecto de tu equipo en el panel (ver «equipos»).',
                    'titulo'       => 'Obligatorio. Corto y en lenguaje del usuario: qué cambió, no cómo.',
                    'descripcion'  => 'Qué se hizo y por qué, en 2-4 frases. Puede llevar el commit al final («Commit abc1234.»).',
                    'prioridad'    => 'baja | media | alta',
                    'estado'       => 'pendiente | progreso | revision | hecho  (o Por hacer | En progreso | En revision | Completada)',
                    'fecha_inicio' => 'AAAA-MM-DD',
                    'fecha_limite' => 'AAAA-MM-DD',
                    'asignados'    => ['Nombre como está en el panel (completo o el primer nombre si es único)'],
                    'ref'          => 'id temporal dentro del lote, para que otra tarea dependa de esta',
                    'depende_de'   => 'ref (o título) de otra tarea del mismo lote',
                    'item_release' => 'Código del ítem del release (p. ej. ' . $ejemploItem . ') o una lista',
                ]]],
                'reglas' => [
                    'Todo o nada: un error grave (proyecto que no existe, tarea sin título) cancela el lote entero. Siempre «Validar» primero.',
                    'Una tarea por cambio que el usuario pueda ver o probar; no una por commit pequeño ni una gigante por semana.',
                    'Lo ya hecho va con estado «hecho» y las fechas del día en que se hizo. Lo hecho pero aún no subido al servidor de pruebas va «revision».',
                    'Fechas en días hábiles. El equipo trabaja medio tiempo: lo nuevo se planifica en 2-5 días hábiles por tarea; un módulo grande, 1-2 semanas.',
                    'item_release: el ítem que esa tarea hace avanzar (ver «items»). Si no encaja en ninguno (plataforma, CI, librerías, design system), quita la línea.',
                    'Un ítem de otro equipo se puede usar si el trabajo es de ese frente: el panel avisa, pero la amarra igual.',
                    'El número #id de cada tarea lo pone el panel al importar; no va en el JSON. Después úsalo en los commits.',
                ],
                'ejemplo' => ['tareas' => [[
                    'proyecto' => $ejemploProyecto, 'titulo' => 'Formulario de inscripción: valida la cédula antes de guardar',
                    'descripcion' => 'La cédula se valida al escribirla y el formulario ya no se envía con una inválida.',
                    'prioridad' => 'media', 'estado' => 'revision', 'fecha_inicio' => date('Y-m-d'), 'fecha_limite' => date('Y-m-d'),
                    'asignados' => ['Tu nombre'], 'ref' => 'validar-cedula', 'item_release' => $ejemploItem,
                ]]],
            ],
            'por_la_api' => [
                'que_es' => 'En vez de pegar el JSON, tu asistente puede crear tareas, asociar commits y cambiar estados con tu token personal (Mi perfil → Tu asistente de código → Conectar). Allí mismo están las instrucciones listas para pegar en CLAUDE.md, AGENTS.md o .github/copilot-instructions.md.',
                'crear_tareas' => 'POST api.php?r=tareas con este mismo JSON ({"tareas":[…]}); primero con "validar": true.',
                'ver_items' => 'GET api.php?r=items&equipo=Beta (o &release=R2): los ítems tal como están en el panel.',
            ],
            'commits' => [
                'formato' => '<tipo>(<área>): <descripción en presente, corta>  #<id>',
                'tipos' => ['feat', 'fix', 'refactor', 'docs', 'test', 'style', 'chore'],
                'ejemplos' => ['feat(inscripcion): valida la cédula antes de guardar  #812', 'fix(resoluciones): el filtro por tipo vuelve a filtrar  #809'],
                'mueven_la_tarea' => [
                    '#42' => 'la enlaza y la pasa a En progreso',
                    'wip #42' => 'la deja En progreso',
                    'closes #42 | fixes #42 | cierra #42 | resuelve #42' => 'la manda a En revisión (o Completada si no tiene observaciones pendientes)',
                ],
                'ojo' => 'La palabra va pegada al #id: «fixes #42» cierra; «fix(area): … #42» no (ahí fix es el tipo). Solo avanza, nunca regresa una tarea. Un commit puede llevar varias: «… #42 #43».',
                'ramas' => '<tipo>/<id>-<slug-corto>, p. ej. feat/812-validar-cedula',
                'para_que_sirve' => 'La ficha de cada tarea en el panel muestra los commits que llevan su #id (paso «Commits»).',
            ],
            'horario_de_subidas' => [
                'dias' => $dias,
                'hora_limite' => (string)($crono['hora'] ?? '22:00'),
                'que_es' => 'Hasta esa hora se dejan subidos los cambios al servidor de pruebas. Los deploys que terminan bien en GitLab se registran solos; el panel marca cada día como «Hecho» si hubo deploy ese día.',
                'consecuencia_en_tareas' => 'Lo terminado pero aún no subido queda «En revision»; tras el deploy pasa a «Completada».',
            ],
            'releases' => $releases,
            'equipos' => $equipos,
            'items' => $items,
            'como_elegir_el_item' => [
                'Busca en «usar_para» la pantalla o el proceso que tocaste; el primero que encaje es el ítem.',
                'Si el cambio sirve a dos ítems, usa una lista: "item_release": ["R2-B01", "R2-B05"].',
                'Arreglos de rendimiento, estilos o componentes de una pantalla van al ítem de esa pantalla.',
                'Plataforma, CI/CD, librerías compartidas, design system o cosas de todo el sistema: sin item_release.',
                'Un ítem de otro equipo se puede usar si el trabajo es de ese frente; el panel solo avisa.',
            ],
        ];
    }

    /** Frases cortas para reconocer el ítem: su tema y las partes de su alcance. */
    private static function usarPara(array $it): array
    {
        $tema = trim((string)preg_replace('/^.*—\s*/u', '', (string)$it['titulo']));
        $txt = (string)(($it['alcance'] ?? '') !== '' ? $it['alcance'] : ($it['entregable'] ?? ''));
        $partes = preg_split('/\s*[,;·]\s*|\s+y\s+(?=\p{Ll})/u', $txt) ?: [];
        $out = [];
        foreach (array_merge([$tema], $partes) as $p) {
            $p = trim($p, " .\t\n");
            if (mb_strlen($p) < 4) continue;
            $p = mb_strtolower(mb_substr($p, 0, 1)) . mb_substr($p, 1);
            if (!in_array($p, $out, true)) $out[] = $p;
        }
        return array_slice($out, 0, 8);
    }
}
