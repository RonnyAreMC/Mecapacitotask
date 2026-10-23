<?php
/**
 * Releases: la planificación estratégica (R0…R4) dentro del panel.
 *
 * Antes vivía en dos Excel («Cronograma General» y «Revisiones»): qué historias
 * tiene cada release, de qué equipo es cada una, sus fechas y, cada semana, lo
 * que se revisó con cada equipo. Aquí queda la trazabilidad completa:
 *
 *  - releases     R0…R4 con su ventana, estado y color.
 *  - equipos      Alpha, Beta… con su Scrum Master, su PO y los proyectos del
 *                 panel donde están sus tareas.
 *  - ítems        cada historia o frente (R2-A03…): equipo, fechas, estado,
 *                 % de avance, qué debe demostrarse y las tareas del panel
 *                 que lo cubren.
 *  - hitos        los puntos de control (Go/No-Go, UAT, Go-Live…).
 *  - revisiones   las sesiones semanales por equipo: por cada ítem, el estado
 *                 (Cumplido, Parcial…), el % real y las observaciones; los
 *                 acuerdos y el resumen de la IA.
 *  - análisis     lo que la IA encontró al cruzar las tareas de un equipo con
 *                 sus ítems (qué cubre cada tarea, qué falta, fechas que no cuadran).
 *
 * Las fechas de un release NO limitan las fechas de las tareas: el plan cambia
 * (hubo reajustes) y las tareas se crean como siempre. Aquí solo se avisa.
 *
 * Quién entra: el administrador, los que él ponga como editores y, para ver,
 * los Scrum Master (configurable en la pestaña Ajustes del módulo).
 */
require_once __DIR__ . '/Models.php';

final class Releases
{
    /** Estado de un ítem: etiqueta, tono del DS y el % que se le pone al elegirlo si no tenía. */
    public const ESTADOS = [
        'por_iniciar' => ['Por iniciar',   'gris',    0],
        'empezado'    => ['Empezado',      'azul',    10],
        'avanzado'    => ['Avanzado',      'cian',    50],
        'validacion'  => ['En validación', 'petroleo', 85],
        'cerrado'     => ['Terminado',     'verde',   100],
        'bloqueado'   => ['Bloqueado',     'rojo',    null],
    ];

    /** Resultado de un ítem en una revisión (como en la hoja «Calendario de Revisiones»). */
    public const RESULTADOS = [
        'pendiente'    => ['Sin revisar',  'gris'],
        'cumplido'     => ['Cumplido',     'verde'],
        'parcial'      => ['Parcial',      'amarillo'],
        'no_cumplido'  => ['No cumplido',  'rojo'],
        'reprogramado' => ['Reprogramado', 'petroleo'],
        'no_aplica'    => ['No aplica',    'gris'],
    ];

    /** Salud de un ítem frente a lo que el plan esperaba hoy. */
    public const SALUD = [
        'al_dia'    => ['Al día',        'verde'],
        'riesgo'    => ['En riesgo',     'amarillo'],
        'atrasado'  => ['Atrasado',      'rojo'],
        'vencido'   => ['Vencido',       'rojo'],
        'bloqueado' => ['Bloqueado',     'rojo'],
        'futuro'    => ['Aún no inicia', 'gris'],
        'cerrado'   => ['Terminado',     'verde'],
    ];

    public const COLORES = ['R0' => '#64748B', 'R1' => '#2970EB', 'R2' => '#7C3AED', 'R3' => '#0EA5A4', 'R4' => '#E8710A'];
    public const COLORES_EQUIPO = ['Alpha' => '#2970EB', 'Beta' => '#16A34A', 'Gamma' => '#E8710A', 'Delta' => '#9333EA', 'Todos' => '#475569', 'Transversal' => '#475569'];

    /* ============================ Configuración ============================ */

    public static function conf(): array
    {
        $c = (array)(Config::get('releases') ?? []);
        $av = (array)($c['avisos'] ?? []);
        $si = fn(string $k, bool $def = true) => array_key_exists($k, $av) ? !empty($av[$k]) : $def;
        return [
            'programa'     => trim((string)($c['programa'] ?? '')) ?: 'SIAU-UBE',
            'go_live'      => self::fecha($c['go_live'] ?? '') ?: '',
            'editores'     => array_values(array_unique(array_filter(array_map('intval', (array)($c['editores'] ?? []))))),
            'ven_sm'       => !array_key_exists('ven_sm', $c) || !empty($c['ven_sm']),
            'ven_todos'    => !empty($c['ven_todos']),
            'ia_auto'      => !array_key_exists('ia_auto', $c) || !empty($c['ia_auto']),
            'destinatarios' => array_values(array_unique(array_filter(array_map('intval', (array)($c['destinatarios'] ?? []))))),
            'avisos'       => [
                'panel'          => $si('panel'),
                'correo'         => $si('correo'),
                'cambio_fechas'  => $si('cambio_fechas'),
                'cambio_estado'  => $si('cambio_estado'),
                'cambio_equipo'  => $si('cambio_equipo'),
                'revision'       => $si('revision'),
                'revision_hoy'   => $si('revision_hoy'),
                'vence_pronto'   => $si('vence_pronto'),
                'vence_dias'     => in_array((int)($av['vence_dias'] ?? 3), [1, 2, 3, 5, 7], true) ? (int)($av['vence_dias'] ?? 3) : 3,
                'atrasados'      => $si('atrasados'),
                'resumen_semanal' => $si('resumen_semanal'),
                'al_sm'          => $si('al_sm'),
                'al_equipo'      => $si('al_equipo', false),
            ],
            'feriados'     => array_values(array_filter(array_map([self::class, 'fecha'], (array)($c['feriados'] ?? [])))),
            // Reuniones diarias: desde cuándo se miden y qué parte del equipo tiene que conectarse
            'dailies'      => [
                'desde'  => self::fecha($c['dailies']['desde'] ?? '') ?: '2026-10-23',
                'umbral' => min(100, max(10, (int)($c['dailies']['umbral'] ?? 60))),
            ],
            'guia'         => (array)($c['guia'] ?? []),
            'importado'    => (array)($c['importado'] ?? []),
        ];
    }

    public static function guardarConf(array $cambios): void
    {
        $actual = (array)(Config::get('releases') ?? []);
        Config::guardar(['releases' => array_replace($actual, $cambios)]);
    }

    /* ============================== Permisos ============================== */

    public static function puedeEditar(): bool
    {
        $u = Auth::usuario();
        if (!$u || verComo()) return false;
        return Auth::esAdmin() || in_array((int)$u['id'], self::conf()['editores'], true);
    }

    /** Todos con sesión ven Releases (en solo lectura); cambiar es de los editores y del SM de cada equipo. */
    public static function puedeVer(): bool
    {
        return (bool)Auth::usuario();
    }

    /** Lo que ve quien solo mira (no es editor ni SM de un equipo): el release y los hitos. */
    public const VISTAS_LECTOR = ['release', 'item', 'hitos'];

    /** ¿Ve todo el módulo (tablero, equipos, revisiones)? Los editores y el SM de algún equipo. */
    public static function veTodo(): bool
    {
        return self::puedeEditar() || self::equipoDeSm((int)(Auth::usuario()['id'] ?? 0)) !== null;
    }

    /** El equipo del que esta persona es Scrum Master (o null). */
    public static function equipoDeSm(int $mid): ?array
    {
        if ($mid <= 0) return null;
        foreach (self::equipos() as $e) if ((int)($e['sm_id'] ?? 0) === $mid) return $e;
        return null;
    }

    /* ============================== Datos ============================== */

    private static function repo(string $c): JsonStore { return new JsonStore($c); }

    /** [codigo => release], ordenados (R0, R1…). */
    public static function releases(): array
    {
        $l = self::repo('rel_releases')->all();
        usort($l, fn($a, $b) => [(int)($a['orden'] ?? 0), (string)$a['codigo']] <=> [(int)($b['orden'] ?? 0), (string)$b['codigo']]);
        $out = [];
        foreach ($l as $r) $out[(string)$r['codigo']] = $r + ['color' => self::COLORES[$r['codigo']] ?? '#2970EB'];
        return $out;
    }

    /** [nombre => equipo], en su orden (Alpha, Beta, Gamma, Delta, Todos…). */
    public static function equipos(): array
    {
        $l = self::repo('rel_equipos')->all();
        usort($l, fn($a, $b) => [(int)($a['orden'] ?? 99), (string)$a['nombre']] <=> [(int)($b['orden'] ?? 99), (string)$b['nombre']]);
        $out = [];
        foreach ($l as $e) {
            $e['proyectos'] = array_values(array_map('intval', (array)($e['proyectos'] ?? [])));
            $e['miembros']  = array_values(array_map('intval', (array)($e['miembros'] ?? [])));
            $e['color']   ??= self::COLORES_EQUIPO[$e['nombre']] ?? '#475569';
            $out[(string)$e['nombre']] = $e;
        }
        return $out;
    }

    public static function equipo(string $nombre): ?array { return self::equipos()[$nombre] ?? null; }

    /**
     * Ítems (historias y frentes). Filtro: release, equipo, estado, salud, q (texto), ids.
     * Cada uno sale con sus calculados: esperado, salud, avance_tareas.
     */
    public static function items(array $f = [], ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $q = isset($f['q']) ? self::normal((string)$f['q']) : '';
        $out = [];
        foreach (self::repo('rel_items')->all() as $it) {
            if (!empty($f['release']) && $it['release'] !== $f['release']) continue;
            if (!empty($f['equipo']) && $it['equipo'] !== $f['equipo']) continue;
            if (!empty($f['estado']) && ($it['estado'] ?? '') !== $f['estado']) continue;
            if ($q !== '' && !str_contains(self::normal($it['codigo'] . ' ' . $it['titulo'] . ' ' . ($it['microservicio'] ?? '') . ' ' . ($it['alcance'] ?? '')), $q)) continue;
            $it = self::calcular($it, $hoy);
            if (!empty($f['salud']) && $it['salud'] !== $f['salud']) continue;
            $out[] = $it;
        }
        usort($out, fn($a, $b) => [self::ordenRelease($a['release']), $a['inicio'] ?: '9999', $a['codigo']] <=> [self::ordenRelease($b['release']), $b['inicio'] ?: '9999', $b['codigo']]);
        return $out;
    }

    public static function item(int $id): ?array
    {
        $it = self::repo('rel_items')->find($id);
        return $it ? self::calcular($it, date('Y-m-d')) : null;
    }

    public static function itemPorCodigo(string $codigo): ?array
    {
        foreach (self::repo('rel_items')->where('codigo', $codigo) as $it) return self::calcular($it, date('Y-m-d'));
        return null;
    }

    public static function hitos(): array
    {
        $l = self::repo('rel_hitos')->all();
        usort($l, fn($a, $b) => [$a['fecha'], $a['titulo']] <=> [$b['fecha'], $b['titulo']]);
        return $l;
    }

    /** Revisiones (sesiones). Filtro: equipo, desde, hasta, estado. Ordenadas por fecha y hora. */
    public static function revisiones(array $f = []): array
    {
        $out = [];
        foreach (self::repo('rel_revisiones')->all() as $r) {
            if (!empty($f['equipo']) && $r['equipo'] !== $f['equipo']) continue;
            if (!empty($f['desde']) && $r['fecha'] < $f['desde']) continue;
            if (!empty($f['hasta']) && $r['fecha'] > $f['hasta']) continue;
            if (!empty($f['estado']) && ($r['estado'] ?? 'programada') !== $f['estado']) continue;
            $out[] = $r + ['cumplimiento' => self::cumplimiento([$r])];
        }
        usort($out, fn($a, $b) => [$a['fecha'], $a['hora'] ?? '', $a['equipo']] <=> [$b['fecha'], $b['hora'] ?? '', $b['equipo']]);
        return $out;
    }

    public static function revision(int $id): ?array
    {
        $r = self::repo('rel_revisiones')->find($id);
        return $r ? $r + ['cumplimiento' => self::cumplimiento([$r])] : null;
    }

    /** El último análisis de IA de un equipo (o null). */
    public static function analisis(string $equipo): ?array
    {
        $l = self::repo('rel_analisis')->where('equipo', $equipo);
        usort($l, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        return $l[0] ?? null;
    }

    /* ============================== Cálculos ============================== */

    /** Días hábiles (lunes a viernes, sin feriados) entre dos fechas, ambas incluidas. */
    public static function habiles(string $desde, string $hasta): int
    {
        if ($desde === '' || $hasta === '' || $hasta < $desde) return 0;
        static $feriados = null;
        $feriados ??= array_flip(self::conf()['feriados']);
        $n = 0;
        $d = new DateTimeImmutable($desde);
        $fin = new DateTimeImmutable($hasta);
        for ($i = 0; $d <= $fin && $i < 2000; $d = $d->modify('+1 day'), $i++) {
            if ((int)$d->format('N') <= 5 && !isset($feriados[$d->format('Y-m-d')])) $n++;
        }
        return $n;
    }

    /** % que el plan espera hoy (días hábiles transcurridos hasta ayer, como la hoja de revisiones). */
    public static function esperado(array $it, ?string $hoy = null): int
    {
        $hoy ??= date('Y-m-d');
        $ini = (string)($it['inicio'] ?? '');
        $fin = (string)($it['fin'] ?? '');
        if ($ini === '' || $fin === '' || $hoy <= $ini) return 0;
        if ($hoy > $fin) return 100;
        $total = self::habiles($ini, $fin);
        if ($total <= 0) return 0;
        $ayer = date('Y-m-d', strtotime($hoy . ' -1 day'));
        return (int)round(self::habiles($ini, $ayer) / $total * 100);
    }

    /** Lo que pesa cada estado de tarea en el avance: completada 1, en revisión 0,8, en progreso 0,5, por hacer 0. */
    public static function pesoTarea(string $estado): float
    {
        static $pesos = null;
        if ($pesos === null) {
            $pesos = [];
            $finales = Catalogo::estadosFinales();
            $abiertos = array_values(array_filter(array_keys(Catalogo::estadosTarea()), fn($k) => !in_array($k, $finales, true)));
            $n = count($abiertos);
            foreach ($abiertos as $i => $k) {
                $pesos[$k] = match ($k) { 'pendiente' => 0.0, 'progreso' => 0.5, 'revision' => 0.8, default => $n > 1 ? round($i / $n, 2) : 0.0 };
            }
            foreach ($finales as $k) $pesos[$k] = 1.0;
        }
        return $pesos[$estado] ?? 0.0;
    }

    /** Cómo van las tareas vinculadas: total, completadas, en curso y el % (cada una pesa según su estado). */
    public static function detalleTareas(array $it, ?array $tareasPorId = null): array
    {
        $ids = array_map('intval', (array)($it['tareas'] ?? []));
        $d = ['total' => 0, 'hechas' => 0, 'en_curso' => 0, 'pct' => 0];
        if (!$ids) return $d;
        $tareasPorId ??= self::mapaTareas();
        $suma = 0.0;
        foreach ($ids as $id) {
            if (!isset($tareasPorId[$id])) continue;
            $peso = self::pesoTarea((string)($tareasPorId[$id]['estado'] ?? ''));
            $d['total']++;
            $suma += $peso;
            if ($peso >= 1) $d['hechas']++;
            elseif ($peso > 0) $d['en_curso']++;
        }
        $d['pct'] = $d['total'] ? (int)round($suma / $d['total'] * 100) : 0;
        return $d;
    }

    /** % según las tareas vinculadas (null si no tiene tareas). */
    public static function avanceTareas(array $it, ?array $tareasPorId = null): ?int
    {
        $d = self::detalleTareas($it, $tareasPorId);
        return $d['total'] ? $d['pct'] : null;
    }

    /**
     * Estado que corresponde según las tareas. Con todas completadas queda «En
     * validación»: «Terminado» lo pone la revisión (Cumplido en un cierre) o un editor.
     */
    public static function estadoSegunTareas(array $d, int $pct): string
    {
        if ($d['total'] && $d['hechas'] === $d['total']) return 'validacion';
        if ($pct <= 0 && !$d['en_curso'] && !$d['hechas']) return 'por_iniciar';
        return $pct >= 40 ? 'avanzado' : 'empezado';
    }

    public static function salud(array $it, ?string $hoy = null): string
    {
        $hoy ??= date('Y-m-d');
        $estado = (string)($it['estado'] ?? 'por_iniciar');
        if ($estado === 'cerrado') return 'cerrado';
        if ($estado === 'bloqueado') return 'bloqueado';
        $ini = (string)($it['inicio'] ?? '');
        $fin = (string)($it['fin'] ?? '');
        if ($ini !== '' && $hoy < $ini) return 'futuro';
        if ($fin !== '' && $hoy > $fin) return 'vencido';
        $esp = self::esperado($it, $hoy);
        if ($esp <= 0) return 'al_dia';
        $ratio = (int)($it['avance'] ?? 0) / $esp;
        return $ratio >= 1 ? 'al_dia' : ($ratio >= 0.7 ? 'riesgo' : 'atrasado');
    }

    private static function calcular(array $it, string $hoy): array
    {
        static $tareas = null;
        $tareas ??= self::mapaTareas();
        $it['tareas']   = array_values(array_map('intval', (array)($it['tareas'] ?? [])));
        $it['tareas_ia'] = array_values(array_intersect(array_map('intval', (array)($it['tareas_ia'] ?? [])), $it['tareas']));
        $it['estado']   = isset(self::ESTADOS[$it['estado'] ?? '']) ? $it['estado'] : 'por_iniciar';
        $it['avance']   = $it['estado'] === 'cerrado' ? 100 : max(0, min(100, (int)($it['avance'] ?? 0)));
        $dt = self::detalleTareas($it, $tareas);
        $it['tareas_resumen'] = $dt;
        $it['avance_tareas'] = $dt['total'] ? $dt['pct'] : null;
        // De dónde sale el avance: de las tareas (lo normal si tiene) o a mano (si un editor lo dejó así)
        $it['fuente'] = ($it['avance_fuente'] ?? 'tareas') !== 'manual' && $dt['total'] ? 'tareas' : 'manual';
        $it['avance_base'] = isset($it['avance_base']) ? (int)$it['avance_base'] : $it['avance'];
        if ($it['fuente'] === 'tareas' && !in_array($it['estado'], ['cerrado', 'bloqueado'], true)) {
            // Lo ya verificado (Excel o revisión) no se pierde porque se sumen tareas nuevas
            $it['avance'] = max($it['avance_base'], $dt['pct']);
            $it['estado'] = self::estadoSegunTareas($dt, $it['avance']);
        }
        $it['esperado'] = self::esperado($it, $hoy);
        $it['salud']    = self::salud($it, $hoy);
        $it['dias_restantes'] = ($it['fin'] ?? '') !== '' ? (int)floor((strtotime($it['fin']) - strtotime($hoy)) / 86400) : null;
        return $it;
    }

    /** Totales de una lista de ítems ya calculados. */
    public static function resumen(array $items): array
    {
        $r = ['total' => count($items), 'cerrados' => 0, 'en_curso' => 0, 'por_iniciar' => 0, 'bloqueados' => 0,
              'atrasados' => 0, 'riesgo' => 0, 'pct' => 0, 'esperado' => 0, 'inicio' => '', 'fin' => ''];
        if (!$items) return $r;
        $suma = $sumaEsp = 0;
        foreach ($items as $it) {
            $suma += (int)$it['avance'];
            $sumaEsp += (int)$it['esperado'];
            match ($it['estado']) {
                'cerrado' => $r['cerrados']++,
                'por_iniciar' => $r['por_iniciar']++,
                'bloqueado' => $r['bloqueados']++,
                default => $r['en_curso']++,
            };
            if (in_array($it['salud'], ['atrasado', 'vencido', 'bloqueado'], true)) $r['atrasados']++;
            if ($it['salud'] === 'riesgo') $r['riesgo']++;
            if (($it['inicio'] ?? '') !== '' && ($r['inicio'] === '' || $it['inicio'] < $r['inicio'])) $r['inicio'] = $it['inicio'];
            if (($it['fin'] ?? '') !== '' && $it['fin'] > $r['fin']) $r['fin'] = $it['fin'];
        }
        $r['pct'] = (int)round($suma / count($items));
        $r['esperado'] = (int)round($sumaEsp / count($items));
        return $r;
    }

    /**
     * El avance general: por release y, dentro, por equipo.
     * ['releases' => [codigo => resumen + ['equipos' => [nombre => resumen]]], 'equipos' => [nombre => resumen], 'total' => resumen]
     */
    public static function tablero(?string $hoy = null): array
    {
        $items = self::items([], $hoy);
        $porRel = $porEq = $porRelEq = [];
        foreach ($items as $it) {
            $porRel[$it['release']][] = $it;
            $porEq[$it['equipo']][] = $it;
            $porRelEq[$it['release']][$it['equipo']][] = $it;
        }
        $rels = [];
        foreach (self::releases() as $cod => $r) {
            $rels[$cod] = self::resumen($porRel[$cod] ?? []) + ['release' => $r, 'equipos' => []];
            foreach (self::equipos() as $nom => $e) {
                if (isset($porRelEq[$cod][$nom])) $rels[$cod]['equipos'][$nom] = self::resumen($porRelEq[$cod][$nom]);
            }
        }
        $eqs = [];
        foreach (self::equipos() as $nom => $e) $eqs[$nom] = self::resumen($porEq[$nom] ?? []) + ['equipo' => $e];
        return ['releases' => $rels, 'equipos' => $eqs, 'total' => self::resumen($items)];
    }

    /** Cumplimiento de una o varias revisiones (como la hoja «Cumplimiento»). */
    public static function cumplimiento(array $revisiones): array
    {
        $c = ['programados' => 0, 'revisados' => 0, 'pct' => null] + array_fill_keys(array_keys(self::RESULTADOS), 0);
        foreach ($revisiones as $r) {
            foreach ((array)($r['filas'] ?? []) as $f) {
                $c['programados']++;
                $e = isset(self::RESULTADOS[$f['estado'] ?? '']) ? $f['estado'] : 'pendiente';
                $c[$e]++;
                if ($e !== 'pendiente') $c['revisados']++;
            }
        }
        $base = $c['cumplido'] + $c['parcial'] + $c['no_cumplido'];
        $c['pct'] = $base ? (int)round($c['cumplido'] / $base * 100) : null;
        return $c;
    }

    /* ============================ Tareas del panel ============================ */

    private static function mapaTareas(): array
    {
        $m = [];
        foreach ((new TareaRepo())->todas() as $t) $m[(int)$t['id']] = $t;
        return $m;
    }

    /** Las tareas vinculadas a un ítem, con su proyecto. */
    public static function tareasDe(array $it): array
    {
        $todas = self::mapaTareas();
        $out = [];
        foreach ((array)($it['tareas'] ?? []) as $id) if (isset($todas[(int)$id])) $out[] = $todas[(int)$id];
        return $out;
    }

    /**
     * Los proyectos del panel de un equipo: los que se le asignaron (al importar
     * se vinculan solos los que se llaman como el equipo). Si no tiene ninguno,
     * los que tienen como alias de Despliegues el microservicio de sus ítems.
     */
    public static function proyectosEquipo(string $equipo): array
    {
        $e = self::equipo($equipo);
        // Con proyectos propios («Equipo Alpha»…), solo esos: el microservicio lo comparten
        // varios equipos (ms-academico es de Beta y de Delta) y se cruzarían sus tareas.
        if (!empty($e['proyectos'])) return array_values(array_map('intval', $e['proyectos']));
        $ids = [];
        $alias = array_map(fn($a) => strtolower(trim($a)), aliasDeploys(configDeploys()['alias'] ?? []));
        $micro = [];
        foreach (self::repo('rel_items')->where('equipo', $equipo) as $it) {
            if (($it['microservicio'] ?? '') !== '') $micro[strtolower($it['microservicio'])] = true;
        }
        foreach ($alias as $pid => $a) {
            foreach (preg_split('/\s*[,;\/]\s*/', $a) as $parte) if (isset($micro[$parte])) $ids[$pid] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    /** Las tareas de los proyectos del equipo (para cruzarlas con sus ítems). */
    public static function tareasEquipo(string $equipo, bool $soloAbiertas = false): array
    {
        $pids = array_flip(self::proyectosEquipo($equipo));
        if (!$pids) return [];
        $finales = Catalogo::estadosFinales();
        $out = [];
        foreach ((new TareaRepo())->todas() as $t) {
            if (!isset($pids[(int)$t['proyecto_id']])) continue;
            if ($soloAbiertas && in_array($t['estado'] ?? '', $finales, true)) continue;
            $out[] = $t;
        }
        return $out;
    }

    /** [tarea_id => [códigos de ítems]]: para marcar en el panel qué tareas ya están vinculadas. */
    public static function vinculos(): array
    {
        $m = [];
        foreach (self::repo('rel_items')->all() as $it) {
            foreach ((array)($it['tareas'] ?? []) as $tid) $m[(int)$tid][] = $it['codigo'];
        }
        return $m;
    }

    /** [tarea_id => [[id, codigo, titulo]]]: el ítem del release de cada tarea, para mostrarlo en el proyecto. */
    public static function vinculosDetalle(): array
    {
        $m = [];
        foreach (self::repo('rel_items')->all() as $it) {
            foreach ((array)($it['tareas'] ?? []) as $tid) $m[(int)$tid][] = ['id' => (int)$it['id'], 'codigo' => (string)$it['codigo'], 'titulo' => (string)$it['titulo']];
        }
        return $m;
    }

    /* ============================== Edición ============================== */

    /** Campos editables de un ítem y su forma. */
    private static function limpiarItem(array $d, ?array $antes = null): array
    {
        $c = [];
        foreach (['codigo' => 40, 'titulo' => 200, 'microservicio' => 80, 'sprints' => 30, 'alcance' => 3000, 'demostrar' => 3000,
                  'proceso' => 300, 'actividades' => 600, 'criterio' => 2000, 'entregable' => 2000, 'responsable' => 300, 'nota' => 2000] as $k => $max) {
            if (array_key_exists($k, $d)) $c[$k] = mb_substr(trim((string)$d[$k]), 0, $max);
        }
        if (array_key_exists('release', $d) && isset(self::releases()[(string)$d['release']])) $c['release'] = (string)$d['release'];
        if (array_key_exists('equipo', $d) && isset(self::equipos()[(string)$d['equipo']])) $c['equipo'] = (string)$d['equipo'];
        foreach (['inicio', 'fin'] as $k) if (array_key_exists($k, $d)) $c[$k] = self::fecha($d[$k]);
        if (array_key_exists('estado', $d) && isset(self::ESTADOS[(string)$d['estado']])) $c['estado'] = (string)$d['estado'];
        if (array_key_exists('avance', $d) && $d['avance'] !== '') $c['avance'] = max(0, min(100, (int)$d['avance']));
        if (array_key_exists('avance_fuente', $d)) $c['avance_fuente'] = $d['avance_fuente'] === 'manual' ? 'manual' : 'tareas';
        // Un % puesto a mano es lo verificado: con tareas, el avance no baja de ahí
        if (isset($c['avance'])) $c['avance_base'] = $c['avance'];
        // Al cambiar el estado sin tocar el %: el % típico de ese estado (si el que tenía era menor)
        if (isset($c['estado']) && !array_key_exists('avance', $c)) {
            $tipico = self::ESTADOS[$c['estado']][2];
            if ($tipico !== null && (int)($antes['avance'] ?? 0) < $tipico) $c['avance'] = $tipico;
        }
        if (($c['estado'] ?? '') === 'cerrado') $c['avance'] = 100;
        if (isset($c['avance'], $antes) && !isset($c['estado']) && ($antes['estado'] ?? '') !== 'bloqueado') {
            // El % manda el estado si no lo eligieron: 0 → por iniciar, 100 → terminado…
            $c['estado'] = match (true) {
                $c['avance'] >= 100 => 'cerrado',
                $c['avance'] >= 80 && ($antes['estado'] ?? '') === 'validacion' => 'validacion',
                $c['avance'] >= 40 => 'avanzado',
                $c['avance'] > 0 => 'empezado',
                default => ($antes['estado'] ?? 'por_iniciar') === 'por_iniciar' ? 'por_iniciar' : 'empezado',
            };
        }
        return $c;
    }

    /**
     * Guarda los cambios de un ítem, deja el historial (qué cambió, quién y
     * cuándo) y avisa según la configuración. Devuelve el ítem calculado.
     */
    public static function guardarItem(int $id, array $datos, int $quien = -1): array
    {
        if ($quien < 0) $quien = (int)(Auth::usuario()['id'] ?? 0);
        $repo = self::repo('rel_items');
        $antes = $id > 0 ? $repo->find($id) : null;
        if ($id > 0 && !$antes) throw new RuntimeException('Ese ítem ya no existe.');
        $c = self::limpiarItem($datos, $antes);
        if (!$antes) {
            if (($c['codigo'] ?? '') === '' || ($c['titulo'] ?? '') === '') throw new RuntimeException('El ítem necesita código y título.');
            if (self::itemPorCodigo($c['codigo'])) throw new RuntimeException('Ya hay un ítem con el código ' . $c['codigo'] . '.');
            if (empty($c['release']) || empty($c['equipo'])) throw new RuntimeException('Elige el release y el equipo.');
            $c += ['estado' => 'por_iniciar', 'avance' => 0, 'tareas' => [], 'inicio_original' => $c['inicio'] ?? '', 'fin_original' => $c['fin'] ?? ''];
            $c['historial'] = [self::evento($quien, 'Creó el ítem')];
            $nuevo = $repo->insert($c + ['creado' => date('Y-m-d H:i')]);
            return self::item((int)$nuevo['id']);
        }
        $cambios = self::describirCambios($antes, $c);
        if (!$cambios) return self::item($id);
        $hist = (array)($antes['historial'] ?? []);
        $hist[] = self::evento($quien, implode(' · ', $cambios['texto']), (string)($datos['motivo'] ?? ''));
        $c['historial'] = array_slice($hist, -80);
        $c['actualizado'] = date('Y-m-d H:i');
        $c['actualizado_por'] = $quien;
        $repo->update($id, $c);
        self::sincronizar([$id]);   // con tareas, el avance y el estado se recalculan
        $it = self::item($id);
        self::avisarCambio($antes, $it, $cambios, $quien, (string)($datos['motivo'] ?? ''));
        return $it;
    }

    private static function evento(int $quien, string $texto, string $motivo = ''): array
    {
        return ['fecha' => date('Y-m-d H:i'), 'quien' => $quien, 'texto' => mb_substr($texto, 0, 600), 'motivo' => mb_substr(trim($motivo), 0, 400)];
    }

    /** ['texto' => ['Fin: 15/11 → 22/11', …], 'tipos' => ['fechas' => true, …]] */
    private static function describirCambios(array $a, array $c): array
    {
        $t = $tipos = [];
        $fmt = fn($f) => $f ? date('d/m/Y', strtotime($f)) : '—';
        foreach (['inicio' => 'Inicio', 'fin' => 'Fin'] as $k => $lbl) {
            if (isset($c[$k]) && $c[$k] !== (string)($a[$k] ?? '')) { $t[] = "$lbl: " . $fmt($a[$k] ?? '') . ' → ' . $fmt($c[$k]); $tipos['fechas'] = true; }
        }
        if (isset($c['estado']) && $c['estado'] !== ($a['estado'] ?? 'por_iniciar')) {
            $t[] = 'Estado: ' . (self::ESTADOS[$a['estado'] ?? 'por_iniciar'][0] ?? '—') . ' → ' . self::ESTADOS[$c['estado']][0];
            $tipos['estado'] = true;
        }
        if (isset($c['avance']) && $c['avance'] !== (int)($a['avance'] ?? 0)) { $t[] = 'Avance: ' . (int)($a['avance'] ?? 0) . ' % → ' . $c['avance'] . ' %'; $tipos['avance'] = true; }
        if (isset($c['equipo']) && $c['equipo'] !== ($a['equipo'] ?? '')) { $t[] = 'Equipo: ' . ($a['equipo'] ?? '—') . ' → ' . $c['equipo']; $tipos['equipo'] = true; }
        if (isset($c['release']) && $c['release'] !== ($a['release'] ?? '')) { $t[] = 'Release: ' . ($a['release'] ?? '—') . ' → ' . $c['release']; $tipos['equipo'] = true; }
        foreach (['titulo' => 'Título', 'microservicio' => 'Microservicio', 'alcance' => 'Alcance', 'demostrar' => 'Qué debe demostrarse', 'nota' => 'Nota', 'sprints' => 'Sprints', 'codigo' => 'Código'] as $k => $lbl) {
            if (isset($c[$k]) && $c[$k] !== (string)($a[$k] ?? '')) { $t[] = "$lbl actualizado"; $tipos['otros'] = true; }
        }
        return $t ? ['texto' => $t, 'tipos' => $tipos] : [];
    }

    /**
     * Vincula (reemplaza, o suma con $sumar) las tareas del panel que cubren un ítem.
     * $porIA: las marca como vinculadas por la IA. Lo que una persona quita queda
     * anotado para que la IA no lo vuelva a poner. Después recalcula el ítem.
     */
    public static function vincularTareas(int $id, array $tareaIds, int $quien = -1, bool $sumar = false, bool $porIA = false): array
    {
        if ($quien < 0) $quien = (int)(Auth::usuario()['id'] ?? 0);
        $repo = self::repo('rel_items');
        $it = $repo->find($id);
        if (!$it) throw new RuntimeException('Ese ítem ya no existe.');
        $existen = self::mapaTareas();
        $quitadas = array_map('intval', (array)($it['tareas_quitadas'] ?? []));
        $pedidas = array_values(array_unique(array_filter(array_map('intval', $tareaIds), fn($t) => isset($existen[$t]))));
        if ($porIA) $pedidas = array_values(array_diff($pedidas, $quitadas));
        $antes = array_map('intval', (array)($it['tareas'] ?? []));
        $nuevas = $sumar ? array_values(array_unique(array_merge($antes, $pedidas))) : $pedidas;
        $mas = array_values(array_diff($nuevas, $antes));
        $menos = array_values(array_diff($antes, $nuevas));
        if ($mas || $menos) {
            $ia = array_map('intval', (array)($it['tareas_ia'] ?? []));
            $ia = $porIA ? array_merge($ia, $mas) : $ia;
            $ia = array_values(array_unique(array_diff($ia, $menos)));
            // Una persona que vincula a mano «confirma»; una que quita, que la IA no insista
            if (!$porIA) {
                $quitadas = array_values(array_unique(array_merge(array_diff($quitadas, $mas), $menos)));
            }
            $txt = [];
            if ($mas) $txt[] = ($porIA ? 'La IA vinculó ' : 'Vinculó ') . implode(', ', array_map(fn($t) => '#' . $t, $mas));
            if ($menos) $txt[] = 'Desvinculó ' . implode(', ', array_map(fn($t) => '#' . $t, $menos));
            $hist = (array)($it['historial'] ?? []);
            $hist[] = self::evento($porIA ? 0 : $quien, implode(' · ', $txt));
            $repo->update($id, ['tareas' => $nuevas, 'tareas_ia' => $ia, 'tareas_quitadas' => $quitadas,
                                'historial' => array_slice($hist, -80), 'actualizado' => date('Y-m-d H:i'), 'actualizado_por' => $quien]);
            self::sincronizar([$id]);
        }
        return self::item($id);
    }

    /**
     * Pasa a los ítems lo que dicen sus tareas: avance y estado (según lo que el
     * equipo marcó en el tablero). Deja el historial y, si cambió el estado, avisa.
     * $ids: solo esos ítems (null = todos). Devuelve cuántos cambiaron.
     */
    public static function sincronizar(?array $ids = null): int
    {
        $repo = self::repo('rel_items');
        $hoy = date('Y-m-d');
        $n = 0;
        $filas = $ids === null ? $repo->all() : array_filter(array_map(fn($i) => $repo->find((int)$i), $ids));
        foreach ($filas as $crudo) {
            $it = self::calcular($crudo, $hoy);
            if ($it['fuente'] !== 'tareas') continue;
            $estAntes = isset(self::ESTADOS[$crudo['estado'] ?? '']) ? $crudo['estado'] : 'por_iniciar';
            $pctAntes = (int)($crudo['avance'] ?? 0);
            if ($estAntes === $it['estado'] && $pctAntes === $it['avance']) continue;
            $d = $it['tareas_resumen'];
            $txt = sprintf('Según sus tareas (%d de %d completadas%s): ', $d['hechas'], $d['total'], $d['en_curso'] ? ', ' . $d['en_curso'] . ' en curso' : '')
                . ($estAntes !== $it['estado'] ? self::ESTADOS[$estAntes][0] . ' → ' . self::ESTADOS[$it['estado']][0] . ' · ' : '')
                . $pctAntes . ' % → ' . $it['avance'] . ' %';
            $hist = (array)($crudo['historial'] ?? []);
            $hist[] = self::evento(0, $txt);
            $cambio = ['avance' => $it['avance'], 'estado' => $it['estado'], 'historial' => array_slice($hist, -80), 'actualizado' => date('Y-m-d H:i')];
            if (!isset($crudo['avance_base'])) $cambio['avance_base'] = $pctAntes;
            $repo->update((int)$crudo['id'], $cambio);
            $n++;
            if ($estAntes !== $it['estado'] && self::conf()['avisos']['cambio_estado']) {
                self::avisar(self::destinatarios($it['equipo']), $it['codigo'] . ' · ' . self::ESTADOS[$it['estado']][0] . ' según sus tareas', $txt,
                    'releases.php?v=item&id=' . (int)$crudo['id'],
                    ['Ítem' => $it['codigo'] . ' ' . $it['titulo'], 'Equipo' => $it['equipo'], 'Avance' => $it['avance'] . ' % (esperado ' . $it['esperado'] . ' %)'], 0);
            }
        }
        return $n;
    }

    /** Los ítems que tienen alguna de esas tareas, al día (lo llama actions.php al mover una tarea). */
    public static function sincronizarTareas(array $tareaIds): int
    {
        $tareaIds = array_flip(array_map('intval', $tareaIds));
        $ids = [];
        foreach (self::repo('rel_items')->all() as $it) {
            foreach ((array)($it['tareas'] ?? []) as $t) if (isset($tareaIds[(int)$t])) { $ids[] = (int)$it['id']; break; }
        }
        return $ids ? self::sincronizar($ids) : 0;
    }

    /**
     * La IA revisa las tareas del equipo y las asocia sola a sus ítems
     * (lo que una persona quitó a mano no lo vuelve a poner). Guarda el análisis
     * con sus alertas y sugerencias. → ['analisis' => fila, 'vinculadas' => n, 'items' => n]
     */
    public static function asociarIA(string $equipo, int $quien = -1): array
    {
        if ($quien < 0) $quien = (int)(Auth::usuario()['id'] ?? 0);
        $eq = self::equipo($equipo);
        if (!$eq) throw new RuntimeException('Ese equipo no existe.');
        require_once __DIR__ . '/IA.php';
        require_once __DIR__ . '/IAFunciones.php';
        $finales = Catalogo::estadosFinales();
        $tareas = self::tareasEquipo($equipo);
        usort($tareas, fn($a, $b) => [in_array($a['estado'] ?? '', $finales, true), -(int)$a['id']] <=> [in_array($b['estado'] ?? '', $finales, true), -(int)$b['id']]);
        $proyectos = [];
        foreach ((new ProyectoRepo())->todos() as $p) $proyectos[(int)$p['id']] = $p;
        $res = IAFunciones::releaseTareas(array_replace($eq, ['proyectos' => self::proyectosEquipo($equipo)]), self::items(['equipo' => $equipo]), $tareas, $proyectos);
        $vinc = $items = 0;
        foreach ($res['items'] as $x) {
            if (!$x['tareas'] || !($it = self::itemPorCodigo($x['codigo']))) continue;
            $antes = count($it['tareas']);
            $it = self::vincularTareas((int)$it['id'], $x['tareas'], $quien, true, true);
            if (count($it['tareas']) > $antes) { $vinc += count($it['tareas']) - $antes; $items++; }
        }
        $a = self::guardarAnalisis($equipo, $res, $quien);
        self::marcarAplicada((int)$a['id'], 'auto');
        self::repo('rel_analisis')->update((int)$a['id'], ['vinculadas' => $vinc]);
        return ['analisis' => self::repo('rel_analisis')->find((int)$a['id']), 'vinculadas' => $vinc, 'items' => $items];
    }

    /**
     * Cada pocos minutos (cron): los ítems al día con sus tareas. Una vez al día,
     * si la IA está lista y un equipo tiene tareas nuevas o cambiadas, la IA las asocia.
     */
    public static function pasoRapido(): int
    {
        $marca = __DIR__ . '/../data/releases_sinc.json';
        $m = json_decode((string)@file_get_contents($marca), true) ?: [];
        if (time() - (int)($m['t'] ?? 0) < 300) return 0;
        $m['t'] = time();
        @file_put_contents($marca, json_encode($m));
        try { self::dailiesDelCron(); } catch (Throwable $e) { /* Zoom no respondió: se mide en la próxima vuelta */ }
        return self::repo('rel_items')->all() ? self::sincronizar() : 0;
    }

    /** La IA asocia sola, una vez al día por equipo, solo si cambió algo en sus tareas (para no gastar de más). */
    private static function asociarDelDia(): int
    {
        if (!self::conf()['ia_auto']) return 0;
        require_once __DIR__ . '/IA.php';
        if (!IA::lista()) return 0;
        $marca = __DIR__ . '/../data/releases_ia.json';
        $m = json_decode((string)@file_get_contents($marca), true) ?: [];
        $vinc = self::vinculos();
        $n = 0;
        foreach (self::equipos() as $nom => $e) {
            if (!self::proyectosEquipo($nom)) continue;
            // La huella: las tareas abiertas que todavía no están en ningún ítem
            $sueltas = array_filter(self::tareasEquipo($nom, true), fn($t) => !isset($vinc[(int)$t['id']]));
            if (!$sueltas) continue;
            $huella = md5(implode('|', array_map(fn($t) => $t['id'] . ':' . $t['titulo'], $sueltas)));
            if (($m[$nom] ?? '') === $huella) continue;
            try {
                $r = self::asociarIA($nom, 0);
                $n += $r['vinculadas'];
                $m[$nom] = $huella;
            } catch (Throwable $e) {
                // Sin saldo, sin clave o la IA caída: se reintenta mañana
            }
        }
        @file_put_contents($marca, json_encode($m));
        if ($n) self::sincronizar();
        return $n;
    }

    public static function borrarItem(int $id): bool
    {
        return self::repo('rel_items')->delete($id);
    }

    public static function guardarEquipo(int $id, array $d): array
    {
        $repo = self::repo('rel_equipos');
        $c = [];
        foreach (['nombre' => 40, 'sm_nombre' => 120, 'po_nombre' => 120, 'dominio' => 600, 'dia_revision' => 20, 'hora_revision' => 20] as $k => $max) {
            if (array_key_exists($k, $d)) $c[$k] = mb_substr(trim((string)$d[$k]), 0, $max);
        }
        if (array_key_exists('color', $d) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$d['color'])) $c['color'] = (string)$d['color'];
        // daily_reunion: la reunión del panel que es su daily (0 = la que se llame «Daily…»)
        foreach (['sm_id', 'po_id', 'orden', 'daily_reunion'] as $k) if (array_key_exists($k, $d)) $c[$k] = max(0, (int)$d[$k]);
        foreach (['proyectos', 'miembros'] as $k) if (array_key_exists($k, $d)) $c[$k] = array_values(array_unique(array_filter(array_map('intval', (array)$d[$k]))));
        if ($id > 0) {
            $antes = $repo->find($id);
            if (!$antes) throw new RuntimeException('Ese equipo ya no existe.');
            // Renombrar el equipo arrastra a sus ítems y sus revisiones
            if (isset($c['nombre']) && $c['nombre'] !== '' && $c['nombre'] !== $antes['nombre']) {
                foreach (['rel_items', 'rel_revisiones', 'rel_analisis'] as $col) {
                    $r = self::repo($col);
                    foreach ($r->where('equipo', $antes['nombre']) as $f) $r->update((int)$f['id'], ['equipo' => $c['nombre']]);
                }
            }
            $repo->update($id, $c);
            return $repo->find($id);
        }
        if (($c['nombre'] ?? '') === '') throw new RuntimeException('Ponle nombre al equipo.');
        if (self::equipo($c['nombre'])) throw new RuntimeException('Ya existe el equipo ' . $c['nombre'] . '.');
        return $repo->insert($c + ['proyectos' => [], 'miembros' => [], 'orden' => count(self::equipos()) + 1]);
    }

    public static function guardarRelease(int $id, array $d): array
    {
        $repo = self::repo('rel_releases');
        $c = [];
        foreach (['codigo' => 10, 'nombre' => 160, 'sprints' => 30, 'descripcion' => 2000, 'modulos' => 2000] as $k => $max) {
            if (array_key_exists($k, $d)) $c[$k] = mb_substr(trim((string)$d[$k]), 0, $max);
        }
        foreach (['inicio', 'fin'] as $k) if (array_key_exists($k, $d)) $c[$k] = self::fecha($d[$k]);
        if (array_key_exists('estado', $d)) $c['estado'] = in_array($d['estado'], ['por_iniciar', 'en_curso', 'cerrado'], true) ? $d['estado'] : 'por_iniciar';
        if (array_key_exists('color', $d) && preg_match('/^#[0-9a-fA-F]{6}$/', (string)$d['color'])) $c['color'] = (string)$d['color'];
        if ($id > 0) {
            if (!$repo->find($id)) throw new RuntimeException('Ese release ya no existe.');
            $repo->update($id, $c);
            return $repo->find($id);
        }
        if (($c['codigo'] ?? '') === '') throw new RuntimeException('El release necesita un código (por ejemplo R5).');
        return $repo->insert($c + ['orden' => count(self::releases())]);
    }

    public static function guardarHito(int $id, array $d): array
    {
        $repo = self::repo('rel_hitos');
        $c = [];
        foreach (['titulo' => 200, 'descripcion' => 1500, 'release' => 20, 'sprint' => 10] as $k => $max) {
            if (array_key_exists($k, $d)) $c[$k] = mb_substr(trim((string)$d[$k]), 0, $max);
        }
        if (array_key_exists('fecha', $d)) $c['fecha'] = self::fecha($d['fecha']);
        if (array_key_exists('hecho', $d)) $c['hecho'] = !empty($d['hecho']);
        if ($id > 0) { $repo->update($id, $c); return $repo->find($id); }
        if (($c['titulo'] ?? '') === '' || ($c['fecha'] ?? '') === '') throw new RuntimeException('El hito necesita título y fecha.');
        return $repo->insert($c + ['origen' => 'Panel']);
    }

    /* ============================== Revisiones ============================== */

    /**
     * Guarda una revisión: datos de la sesión y, por cada fila, el resultado.
     * $d['filas'][i] = [estado, pct, obs] (por índice de la fila). Con
     * 'realizada' => true queda cerrada y los resultados mueven los ítems: el
     * % real pasa al avance del ítem y «Cumplido» en un cierre lo termina.
     */
    public static function guardarRevision(int $id, array $d, int $quien = -1): array
    {
        if ($quien < 0) $quien = (int)(Auth::usuario()['id'] ?? 0);
        $repo = self::repo('rel_revisiones');
        $antes = $id > 0 ? $repo->find($id) : null;
        if ($id > 0 && !$antes) throw new RuntimeException('Esa revisión ya no existe.');
        $c = [];
        foreach (['titulo' => 300, 'hora' => 20, 'responsable' => 200, 'acuerdos' => 6000, 'sprint' => 10, 'lugar' => 300] as $k => $max) {
            // (lugar: dónde, si es presencial)
            if (array_key_exists($k, $d)) $c[$k] = mb_substr(trim((string)$d[$k]), 0, $max);
        }
        if (array_key_exists('fecha', $d)) $c['fecha'] = self::fecha($d['fecha']);
        if (array_key_exists('equipo', $d)) $c['equipo'] = mb_substr(trim((string)$d['equipo']), 0, 40);
        if (array_key_exists('asistentes', $d)) $c['asistentes'] = array_values(array_unique(array_filter(array_map('intval', (array)$d['asistentes']))));
        // Presencial (con su lugar) o virtual (la reunión de Zoom/Meet del panel, en el proyecto del equipo)
        if (array_key_exists('modalidad', $d)) $c['modalidad'] = $d['modalidad'] === 'virtual' ? 'virtual' : 'presencial';
        if (array_key_exists('duracion', $d) && (int)$d['duracion'] > 0) $c['duracion'] = min(480, max(15, (int)$d['duracion']));
        $filas = (array)($antes['filas'] ?? []);
        // Ítems nuevos en la sesión (por código o id)
        foreach ((array)($d['agregar'] ?? []) as $ref) {
            $it = is_numeric($ref) ? self::item((int)$ref) : self::itemPorCodigo((string)$ref);
            if (!$it) continue;
            $filas[] = ['item_id' => (int)$it['id'], 'codigo' => $it['codigo'], 'titulo' => $it['titulo'], 'tipo' => 'Avance',
                        'entregado' => '', 'evidencia' => '', 'integraciones' => '', 'esperado' => $it['esperado'],
                        'estado' => 'pendiente', 'pct' => null, 'obs' => ''];
        }
        foreach ((array)($d['filas'] ?? []) as $i => $f) {
            if (!isset($filas[(int)$i])) continue;
            $f = (array)$f;
            if (array_key_exists('estado', $f)) $filas[(int)$i]['estado'] = isset(self::RESULTADOS[$f['estado']]) ? $f['estado'] : 'pendiente';
            if (array_key_exists('pct', $f)) $filas[(int)$i]['pct'] = $f['pct'] === '' || $f['pct'] === null ? null : max(0, min(100, (int)$f['pct']));
            if (array_key_exists('obs', $f)) $filas[(int)$i]['obs'] = mb_substr(trim((string)$f['obs']), 0, 3000);
        }
        foreach ((array)($d['quitar'] ?? []) as $i) unset($filas[(int)$i]);
        $c['filas'] = array_values($filas);
        $realizada = !empty($d['realizada']);
        if ($realizada) {
            $c['estado'] = 'realizada';
            $c['registrada_por'] = $quien;
            $c['registrada'] = date('Y-m-d H:i');
        } elseif (array_key_exists('estado', $d) && in_array($d['estado'], ['programada', 'realizada', 'cancelada'], true)) {
            $c['estado'] = $d['estado'];
        }
        if ($antes) {
            $repo->update($id, $c);
        } else {
            if (($c['fecha'] ?? '') === '' || ($c['equipo'] ?? '') === '') throw new RuntimeException('La revisión necesita fecha y equipo.');
            $antes = $repo->insert($c + ['estado' => 'programada', 'filas' => [], 'creada' => date('Y-m-d H:i'), 'creada_por' => $quien]);
            $id = (int)$antes['id'];
        }
        $rev = self::revision($id);
        if ($realizada && ($antes['estado'] ?? '') !== 'realizada') {
            self::aplicarResultados($rev, $quien);
            self::avisarRevision($rev, $quien);
        }
        return $rev;
    }

    /** Lo revisado mueve los ítems: % real → avance; Cumplido en un cierre → Terminado; Reprogramado se anota. */
    private static function aplicarResultados(array $rev, int $quien): void
    {
        foreach ((array)$rev['filas'] as $f) {
            $id = (int)($f['item_id'] ?? 0);
            if ($id <= 0) continue;
            $it = self::repo('rel_items')->find($id);
            if (!$it) continue;
            $cambio = [];
            // El % verificado en la revisión es la base: con tareas, el avance no baja de ahí
            if ($f['pct'] !== null && (int)$f['pct'] !== (int)($it['avance_base'] ?? $it['avance'] ?? 0)) $cambio['avance'] = (int)$f['pct'];
            $esCierre = stripos((string)($f['tipo'] ?? ''), 'cierre') !== false;
            if ($f['estado'] === 'cumplido' && $esCierre) $cambio['estado'] = 'cerrado';
            if ($f['estado'] === 'no_cumplido' && ($it['estado'] ?? '') === 'por_iniciar') $cambio['estado'] = 'empezado';
            if (!$cambio) continue;
            $cambio['motivo'] = 'Revisión del ' . date('d/m/Y', strtotime($rev['fecha'])) . ' (' . self::RESULTADOS[$f['estado']][0] . ')'
                . ($f['obs'] !== '' ? ': ' . mb_substr($f['obs'], 0, 200) : '');
            try { self::guardarItem($id, $cambio, $quien); } catch (Throwable $e) { /* un ítem raro no frena la revisión */ }
        }
    }

    /** «09:00–10:30» → ['09:00', 90]. Sin hora: las 09:00 y la duración que se indique. */
    public static function horario(string $hora, int $defecto = 60): array
    {
        if (preg_match('/(\d{1,2}):(\d{2})(?:\s*[–-]\s*(\d{1,2}):(\d{2}))?/u', $hora, $m)) {
            $ini = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            $dur = !empty($m[3]) ? ((int)$m[3] * 60 + (int)$m[4]) - ((int)$m[1] * 60 + (int)$m[2]) : $defecto;
            return [$ini, $dur > 0 ? $dur : $defecto];
        }
        return ['09:00', $defecto];
    }

    /**
     * Las reuniones del panel (Zoom, Meet o enlace) de los proyectos del equipo
     * para ese día (también las que se repiten y caen ese día), o las próximas
     * si no hay fecha. Para enlazar una revisión a una reunión que ya existe.
     */
    public static function reunionesEquipo(string $equipo, string $fecha = ''): array
    {
        $out = [];
        $repo = new ReunionRepo();
        $hoy = date('Y-m-d');
        foreach (self::proyectosEquipo($equipo) as $pid) {
            foreach ($repo->delProyecto($pid) as $r) {
                $dia = substr((string)($r['inicio'] ?? ''), 0, 10);
                $repite = !empty($r['recurrente']) && $fecha !== '' && $dia <= $fecha && (string)($r['hasta'] ?? '') >= $fecha
                    && in_array((int)date('N', strtotime($fecha)), array_map('intval', (array)($r['dias'] ?? [])), true);
                if ($fecha !== '' ? ($dia !== $fecha && !$repite) : $dia < $hoy) continue;
                $out[] = ['id' => (int)$r['id'], 'topic' => (string)($r['topic'] ?? ''), 'inicio' => (string)($r['inicio'] ?? ''),
                          'duracion' => (int)($r['duracion'] ?? 60), 'plataforma' => (string)($r['plataforma'] ?? 'zoom'),
                          'join_url' => (string)($r['join_url'] ?? ''), 'recurrente' => !empty($r['recurrente']), 'proyecto_id' => $pid];
            }
        }
        usort($out, fn($a, $b) => strcmp($a['inicio'], $b['inicio']));
        return array_slice($out, 0, 40);
    }

    /** Deja la revisión enlazada a una reunión del panel (con su enlace para entrar). */
    public static function enlazarReunion(int $revId, array $reu): ?array
    {
        self::repo('rel_revisiones')->update($revId, ['reunion_id' => (int)$reu['id'], 'modalidad' => 'virtual',
            'plataforma' => (string)($reu['plataforma'] ?? 'zoom'), 'join_url' => (string)($reu['join_url'] ?? ''),
            'reunion_proyecto' => (int)($reu['proyecto_id'] ?? 0)]);
        return self::revision($revId);
    }

    /** A quiénes se invita a la revisión: la gente de los proyectos del equipo, su SM y los destinatarios de Releases. */
    public static function invitadosSugeridos(string $equipo): array
    {
        $ids = self::destinatarios($equipo);
        $e = self::equipo($equipo);
        $ids = array_merge($ids, $e['miembros'] ?? []);
        foreach (self::proyectosEquipo($equipo) as $pid) {
            $p = (new ProyectoRepo())->buscar($pid);
            if (!$p) continue;
            $ids = array_merge($ids, ProyectoRepo::miembrosDe($p) ?? [], array_filter([(int)($p['scrum'] ?? 0), ProyectoRepo::poDe($p), (int)($p['po_relevo'] ?? 0)]));
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    public static function borrarRevision(int $id): bool
    {
        return self::repo('rel_revisiones')->delete($id);
    }

    public static function guardarResumenRevision(int $id, array $resumen): void
    {
        self::repo('rel_revisiones')->update($id, ['resumen_ia' => $resumen + ['fecha' => date('Y-m-d H:i')]]);
    }

    public static function guardarAnalisis(string $equipo, array $resultado, int $quien = -1): array
    {
        if ($quien < 0) $quien = (int)(Auth::usuario()['id'] ?? 0);
        return self::repo('rel_analisis')->insert(['equipo' => $equipo, 'fecha' => date('Y-m-d H:i'), 'quien' => $quien, 'resultado' => $resultado, 'aplicadas' => []]);
    }

    public static function marcarAplicada(int $analisisId, string $clave): void
    {
        $repo = self::repo('rel_analisis');
        $a = $repo->find($analisisId);
        if (!$a) return;
        $ap = (array)($a['aplicadas'] ?? []);
        $ap[] = $clave;
        $repo->update($analisisId, ['aplicadas' => array_values(array_unique($ap))]);
    }

    /* ============================== Reuniones diarias ============================== */

    /**
     * Cada día hábil, desde la fecha de Ajustes, cada equipo tiene que tener su
     * daily registrada en el panel (una reunión de sus proyectos con «Daily» en
     * el nombre, o la que se eligió para el equipo) y su gente tiene que
     * conectarse. Zoom dice si la reunión ocurrió y quién entró; el SM puede
     * registrarla a mano (presencial, por Meet) o justificar un día.
     * Una fila por equipo y día en rel_dailies.
     */
    public const DAILY = [
        'cumplida'      => ['Cumplida',         'azul'],
        'incompleta'    => ['Faltó gente',      'cian'],
        'no_hecha'      => ['No se conectaron', 'rojo'],
        'sin_zoom'      => ['Sin verificar',    'gris'],
        'sin_registrar' => ['Sin registrar',    'gris'],
        'no_aplica'     => ['No aplica',        'gris'],
        'pendiente'     => ['Por medir',        'gris'],
    ];
    /** Los que cuentan para el cumplimiento (pendiente y no aplica, no). */
    private const DAILY_CUENTAN = ['cumplida', 'incompleta', 'no_hecha', 'sin_zoom', 'sin_registrar'];
    private const DAILY_RE = '/\b(dail(y|ies)|diari[ao]s?|stand ?-?up)\b/iu';

    public static function esHabil(string $fecha): bool
    {
        static $feriados = null;
        $feriados ??= array_flip(self::conf()['feriados']);
        return $fecha !== '' && (int)date('N', strtotime($fecha)) <= 5 && !isset($feriados[$fecha]);
    }

    /** Las reuniones del panel que son la daily del equipo: la elegida o las que se llaman «Daily…». */
    public static function dailyReuniones(string $equipo): array
    {
        $elegida = (int)(self::equipo($equipo)['daily_reunion'] ?? 0);
        $todas = [];
        $repo = new ReunionRepo();
        foreach (self::proyectosEquipo($equipo) as $pid) foreach ($repo->delProyecto($pid) as $r) $todas[] = $r + ['proyecto_id' => $pid];
        $suya = array_values(array_filter($todas, fn($r) => (int)$r['id'] === $elegida));
        return $suya ?: array_values(array_filter($todas, fn($r) => preg_match(self::DAILY_RE, (string)($r['topic'] ?? ''))));
    }

    /** Para elegir cuál es la daily: las reuniones de los proyectos del equipo, primero las que se repiten. */
    public static function dailyCandidatas(string $equipo): array
    {
        $out = [];
        $repo = new ReunionRepo();
        foreach (self::proyectosEquipo($equipo) as $pid) foreach ($repo->delProyecto($pid) as $r) {
            if (empty($r['recurrente']) && substr((string)($r['inicio'] ?? ''), 0, 10) < date('Y-m-d', strtotime('-7 days'))) continue;
            $out[] = ['id' => (int)$r['id'], 'topic' => (string)($r['topic'] ?? ''), 'recurrente' => !empty($r['recurrente']),
                      'texto' => (string)($r['topic'] ?? '') . ' · ' . (!empty($r['recurrente']) ? Reuniones::resumen($r) : date('d/m H:i', strtotime((string)$r['inicio'])))];
        }
        usort($out, fn($a, $b) => [!$a['recurrente'], $a['topic']] <=> [!$b['recurrente'], $b['topic']]);
        return array_slice($out, 0, 40);
    }

    /** La reunión que cae ese día (la repetida que lo incluye o la suelta de esa fecha). */
    public static function dailyDelDia(array $reuniones, string $fecha): ?array
    {
        $n = (int)date('N', strtotime($fecha));
        foreach ($reuniones as $r) {
            $dia = substr((string)($r['inicio'] ?? ''), 0, 10);
            if (!empty($r['recurrente'])) {
                $dias = array_map('intval', (array)($r['dias'] ?? [])) ?: [1, 2, 3, 4, 5];
                if ($dia <= $fecha && ((string)($r['hasta'] ?? '') === '' || $r['hasta'] >= $fecha) && in_array($n, $dias, true)) return $r;
            } elseif ($dia === $fecha) {
                return $r;
            }
        }
        return null;
    }

    /** Quiénes tienen que estar: los miembros del equipo y su SM (o, sin miembros, la gente de sus proyectos). */
    public static function dailyEsperados(string $equipo): array
    {
        $e = self::equipo($equipo);
        if (!$e) return [];
        $ids = (array)($e['miembros'] ?? []);
        if (!$ids) foreach (self::proyectosEquipo($equipo) as $pid) {
            $p = (new ProyectoRepo())->buscar($pid);
            if ($p) $ids = array_merge($ids, ProyectoRepo::miembrosDe($p) ?? [], [(int)($p['scrum'] ?? 0)]);
        }
        $ids[] = (int)($e['sm_id'] ?? 0);
        $mapa = (new MiembroRepo())->mapa();
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn($id) => isset($mapa[$id]))));
    }

    /**
     * Junta a los que entraron en Zoom con la gente del panel: por correo (el del
     * panel o los de GitHub) y, si no, por nombre y apellido. Lo que no cuadra
     * queda como invitado. Devuelve [ids => minutos, nombres de invitados].
     */
    private static function emparejar(array $zoom, array $esperados, array $mapa): array
    {
        $plano = function (string $s): string {
            $s = mb_strtolower(class_exists('Normalizer') ? (string)Normalizer::normalize($s, Normalizer::FORM_C) : $s);
            $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
                            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ç' => 'c']);
            return trim(preg_replace('/[^a-z0-9 ]+/', ' ', $s));
        };
        $palabras = fn(string $s) => array_values(array_filter(explode(' ', $plano($s)), fn($w) => strlen($w) >= 3));
        $porCorreo = [];
        foreach ($mapa as $id => $m) {
            foreach (array_merge([(string)($m['email'] ?? '')], preg_split('/[\s,;]+/', (string)($m['git_emails'] ?? '')) ?: []) as $c) {
                if (($c = strtolower(trim($c))) !== '') $porCorreo[$c] = (int)$id;
            }
        }
        // Primero la gente del equipo, luego el resto del panel
        $orden = array_values(array_unique(array_merge($esperados, array_map('intval', array_keys($mapa)))));
        $ids = [];
        $invitados = [];
        foreach ($zoom as $z) {
            $id = $porCorreo[(string)($z['email'] ?? '')] ?? 0;
            if (!$id) {
                $pz = $palabras((string)($z['nombre'] ?? ''));
                $candidatos = [];
                foreach ($orden as $mid) {
                    $pm = $palabras((string)($mapa[$mid]['nombre'] ?? ''));
                    if (!$pm || !$pz) continue;
                    $comunes = count(array_intersect($pz, $pm));
                    // Nombre y apellido; o solo el nombre, si es el único que se llama así
                    if ($comunes >= 2 || ($comunes === 1 && count($pz) === 1 && $pz[0] === $pm[0])) $candidatos[$mid] = $comunes;
                }
                arsort($candidatos);
                $top = array_keys($candidatos);
                if ($top && ($candidatos[$top[0]] >= 2 || count($top) === 1)) $id = (int)$top[0];
            }
            if ($id) $ids[$id] = ($ids[$id] ?? 0) + (int)($z['minutos'] ?? 0);
            elseif (trim((string)($z['nombre'] ?? '')) !== '') $invitados[] = trim((string)$z['nombre']);
        }
        return [$ids, array_values(array_unique($invitados))];
    }

    /** Lo medido de un día: cuántos del equipo estuvieron y si llega al mínimo de Ajustes. */
    private static function dailyEstado(array $presentes, array $esperados): array
    {
        $esp = array_values(array_map('intval', $esperados));
        $van = array_values(array_intersect(array_map('intval', $presentes), $esp));
        $pct = $esp ? (int)round(count($van) * 100 / count($esp)) : null;
        $estado = $pct === null ? ($presentes ? 'cumplida' : 'incompleta') : ($pct >= self::conf()['dailies']['umbral'] ? 'cumplida' : 'incompleta');
        return ['estado' => $estado, 'asistencia' => $pct, 'presentes' => $van];
    }

    /**
     * Mira en Zoom las dailies que ya terminaron (las de los últimos $dias días
     * hábiles desde la fecha de Ajustes) y deja anotado cada día. Lo que el SM
     * registró a mano no se toca. Devuelve ['dias' => n, 'error' => texto].
     */
    public static function sincronizarDailies(?string $soloEquipo = null, int $dias = 10): array
    {
        $c = self::conf()['dailies'];
        $hoy = date('Y-m-d');
        $desde = max($c['desde'], date('Y-m-d', strtotime('-' . max(1, $dias) . ' days')));
        if ($desde > $hoy) return ['dias' => 0, 'error' => ''];
        $zoomOk = Zoom::listo();
        $repo = self::repo('rel_dailies');
        $filas = [];
        foreach ($repo->all() as $f) $filas[$f['equipo'] . '|' . $f['fecha']] = $f;
        $mapa = (new MiembroRepo())->mapa();
        $inst = [];
        $n = 0;
        $error = '';
        foreach (self::equipos() as $nom => $e) {
            if ($soloEquipo !== null && $nom !== $soloEquipo) continue;
            $reus = self::dailyReuniones($nom);
            if (!$reus) continue;
            $esperados = self::dailyEsperados($nom);
            for ($d = $desde; $d <= $hoy; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                if (!self::esHabil($d)) continue;
                $ya = $filas[$nom . '|' . $d] ?? null;
                if ($ya && (($ya['fuente'] ?? '') === 'manual' || !empty($ya['final']))) continue;
                $r = self::dailyDelDia($reus, $d);
                if (!$r) continue;
                [$hIni, $dur] = self::horario(substr((string)$r['inicio'], 11, 5), (int)($r['duracion'] ?? 30));
                $termina = strtotime($d . ' ' . $hIni) + max(15, (int)($r['duracion'] ?? $dur)) * 60;
                if ($termina + 600 > time()) continue;        // todavía no termina
                $fila = ['equipo' => $nom, 'fecha' => $d, 'fuente' => 'zoom', 'reunion_id' => (int)$r['id'], 'reunion' => (string)($r['topic'] ?? ''),
                         'hora' => $hIni, 'esperados' => $esperados, 'presentes' => [], 'minutos' => [], 'invitados' => [], 'asistencia' => null,
                         'nota' => '', 'por' => 0, 'actualizado' => date('Y-m-d H:i'), 'msg' => '',
                         // Pasadas dos horas del final, lo que diga Zoom ya no cambia
                         'final' => time() > $termina + 7200];
                if (($r['plataforma'] ?? 'zoom') !== 'zoom' || empty($r['zoom_id']) || !$zoomOk) {
                    $fila['estado'] = 'sin_zoom';
                    $fila['msg'] = ($r['plataforma'] ?? 'zoom') !== 'zoom' ? 'La daily es de ' . Reuniones::etiquetaPlataforma((string)$r['plataforma']) . ': el SM la registra a mano.' : 'Zoom no está conectado en Ajustes.';
                } else {
                    $zid = (string)$r['zoom_id'];
                    $inst[$zid] ??= Zoom::instancias($zid);
                    if (($inst[$zid]['estado'] ?? '') === 'error') {
                        // Un fallo de Zoom no se le cobra al equipo: queda por medir
                        $error = (string)$inst[$zid]['msg'];
                        $fila['estado'] = 'pendiente';
                        $fila['msg'] = $error;
                        $fila['final'] = false;
                    } else {
                        $uuids = array_values(array_map(fn($i) => (string)$i['uuid'], array_filter((array)($inst[$zid]['items'] ?? []), fn($i) => ($i['fecha'] ?? '') === $d)));
                        if (!$uuids) {
                            $fila['estado'] = 'no_hecha';
                        } else {
                            $gente = [];
                            foreach ($uuids as $u) {
                                $p = Zoom::participantes($u);
                                if ($p['estado'] !== 'ok') { $fila['msg'] = (string)$p['msg']; $error = $fila['msg']; continue; }
                                $gente = array_merge($gente, $p['items']);
                            }
                            [$ids, $fila['invitados']] = self::emparejar($gente, $esperados, $mapa);
                            $fila['minutos'] = array_map('intval', $ids);
                            if ($fila['msg'] !== '' && !$gente) {
                                // Se hizo, pero Zoom no deja ver quién entró
                                $fila['estado'] = 'cumplida';
                                $fila['final'] = false;
                            } else {
                                $fila = array_replace($fila, self::dailyEstado(array_keys($ids), $esperados));
                            }
                        }
                    }
                }
                if ($ya) $repo->update((int)$ya['id'], $fila);
                else $repo->insert($fila);
                $n++;
            }
        }
        return ['dias' => $n, 'error' => $error];
    }

    /** Desde el cron: cada media hora, si hay Zoom. */
    private static function dailiesDelCron(): void
    {
        if (!Zoom::listo() || self::conf()['dailies']['desde'] > date('Y-m-d')) return;
        $marca = __DIR__ . '/../data/releases_dailies.json';
        $m = json_decode((string)@file_get_contents($marca), true) ?: [];
        if (time() - (int)($m['t'] ?? 0) < 1800) return;
        @file_put_contents($marca, json_encode(['t' => time()]));
        self::sincronizarDailies();
    }

    /**
     * El SM registra el día a mano: se hizo (con quiénes estuvieron), no se hizo,
     * no aplica (con el motivo) o «auto» (borra lo suyo y vuelve a mandar Zoom).
     */
    public static function registrarDaily(string $equipo, string $fecha, array $d, int $quien): ?array
    {
        $fecha = self::fecha($fecha);
        if (!self::equipo($equipo)) throw new RuntimeException('Ese equipo no existe.');
        if ($fecha === '' || $fecha > date('Y-m-d')) throw new RuntimeException('Elige un día que ya pasó (u hoy).');
        $repo = self::repo('rel_dailies');
        $ya = null;
        foreach ($repo->where('equipo', $equipo) as $f) if ($f['fecha'] === $fecha) { $ya = $f; break; }
        $que = (string)($d['estado'] ?? '');
        if ($que === 'auto') {
            if ($ya) $repo->delete((int)$ya['id']);
            self::sincronizarDailies($equipo, (int)ceil((time() - strtotime($fecha)) / 86400) + 1);
            return null;
        }
        if (!in_array($que, ['hecha', 'no_hecha', 'no_aplica'], true)) throw new RuntimeException('Elige cómo fue la daily.');
        $nota = mb_substr(trim((string)($d['nota'] ?? '')), 0, 600);
        if ($que === 'no_aplica' && $nota === '') throw new RuntimeException('Escribe por qué ese día no aplica (feriado, revisión del release…).');
        $esperados = self::dailyEsperados($equipo);
        $presentes = array_values(array_unique(array_filter(array_map('intval', (array)($d['presentes'] ?? [])))));
        $fila = ['equipo' => $equipo, 'fecha' => $fecha, 'fuente' => 'manual', 'esperados' => $esperados, 'nota' => $nota,
                 'modalidad' => ($d['modalidad'] ?? '') === 'virtual' ? 'virtual' : 'presencial', 'por' => $quien, 'actualizado' => date('Y-m-d H:i'),
                 'final' => true, 'presentes' => [], 'asistencia' => null, 'msg' => ''];
        if ($que === 'hecha') $fila = array_replace($fila, self::dailyEstado($presentes, $esperados));
        else $fila['estado'] = $que;
        // Lo que ya trajo Zoom (minutos, invitados) se queda como referencia
        if ($ya) { $repo->update((int)$ya['id'], $fila); return $repo->find((int)$ya['id']); }
        return $repo->insert($fila + ['minutos' => [], 'invitados' => []]);
    }

    /**
     * El cumplimiento de un equipo desde la fecha de Ajustes: cada día hábil con
     * su estado, el % de días cumplidos, la asistencia media y la de cada persona.
     */
    public static function resumenDailies(string $equipo, ?string $hasta = null): array
    {
        $c = self::conf()['dailies'];
        $hoy = date('Y-m-d');
        $hasta = min($hasta ?? $hoy, $hoy);
        $filas = [];
        foreach (self::repo('rel_dailies')->where('equipo', $equipo) as $f) $filas[$f['fecha']] = $f;
        $reus = self::dailyReuniones($equipo);
        $esperados = self::dailyEsperados($equipo);
        $dias = [];
        $cuenta = array_fill_keys(array_keys(self::DAILY), 0);
        // Un equipo sin gente ni proyectos (el «Todos» de los ítems transversales) no tiene daily que medir
        $aplica = $esperados || self::proyectosEquipo($equipo);
        if (!$aplica) $hasta = '';
        $asis = [];
        $persona = array_fill_keys($esperados, ['va' => 0, 'de' => 0]);
        for ($d = $c['desde']; $d !== '' && $d <= $hasta; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            if (!self::esHabil($d)) continue;
            $f = $filas[$d] ?? null;
            $r = self::dailyDelDia($reus, $d);
            if (!$f) {
                // Sin fila: sin reunión registrada ese día cuenta como no cumplida. Con reunión,
                // falta medirla en Zoom; si no es de Zoom (o no hay Zoom), la tiene que registrar el SM
                $zoomR = $r && ($r['plataforma'] ?? 'zoom') === 'zoom' && !empty($r['zoom_id']) && Zoom::listo();
                $estado = $d >= $hoy ? 'pendiente' : (!$r ? 'sin_registrar' : ($zoomR ? 'pendiente' : 'sin_zoom'));
                $f = ['fecha' => $d, 'estado' => $estado, 'fuente' => '', 'hora' => $r ? substr((string)$r['inicio'], 11, 5) : '', 'reunion' => (string)($r['topic'] ?? '')];
            }
            $dias[$d] = $f;
            $cuenta[$f['estado']] = ($cuenta[$f['estado']] ?? 0) + 1;
            if (in_array($f['estado'], ['cumplida', 'incompleta'], true) && ($f['asistencia'] ?? null) !== null) {
                $asis[] = (int)$f['asistencia'];
                foreach ((array)($f['esperados'] ?? []) as $mid) {
                    if (!isset($persona[(int)$mid])) continue;
                    $persona[(int)$mid]['de']++;
                    if (in_array((int)$mid, array_map('intval', (array)($f['presentes'] ?? [])), true)) $persona[(int)$mid]['va']++;
                }
            }
        }
        $cuentan = array_sum(array_intersect_key($cuenta, array_flip(self::DAILY_CUENTAN)));
        return [
            'aplica' => (bool)$aplica, 'desde' => $c['desde'], 'dias' => $dias, 'cuenta' => $cuenta, 'cuentan' => $cuentan,
            'pct' => $cuentan ? (int)round($cuenta['cumplida'] * 100 / $cuentan) : null,
            'asistencia' => $asis ? (int)round(array_sum($asis) / count($asis)) : null,
            'personas' => $persona, 'reuniones' => $reus, 'esperados' => $esperados,
        ];
    }

    /* ============================== Avisos ============================== */

    /** A quiénes se avisa de algo de un equipo: los destinatarios, los editores, su SM y (si se eligió) su gente. */
    public static function destinatarios(string $equipo = ''): array
    {
        $c = self::conf();
        $ids = array_merge($c['destinatarios'], $c['editores']);
        if ($equipo !== '' && ($e = self::equipo($equipo))) {
            if ($c['avisos']['al_sm'] && (int)($e['sm_id'] ?? 0) > 0) $ids[] = (int)$e['sm_id'];
            if ($c['avisos']['al_equipo']) $ids = array_merge($ids, $e['miembros']);
        }
        if (!$ids) {
            // Sin nadie configurado: los administradores
            foreach ((new MiembroRepo())->todos() as $m) if (in_array('admin', MiembroRepo::accesosDe($m), true)) $ids[] = (int)$m['id'];
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * Manda un aviso por los canales encendidos. $filas: datos para el correo.
     * Devuelve cuántas personas lo recibieron por algún canal.
     */
    public static function avisar(array $para, string $titulo, string $texto, string $url, array $filas = [], int $de = -1, string $detalle = ''): int
    {
        $c = self::conf()['avisos'];
        $para = array_values(array_unique(array_filter(array_map('intval', $para))));
        if (!$para) return 0;
        $n = 0;
        if ($c['panel'] && class_exists('Notif')) {
            try { $n = Notif::crear($para, 'release', $titulo, $texto, $url, $de); } catch (Throwable $e) { /* sin aviso, sigue */ }
        }
        if ($c['correo'] && class_exists('Mailer') && Mailer::listo()) {
            $miembros = (new MiembroRepo())->mapa();
            $yo = $de < 0 ? (int)(Auth::usuario()['id'] ?? 0) : $de;
            foreach ($para as $mid) {
                if ($mid === $yo || empty($miembros[$mid]['email'])) continue;
                try {
                    if (Mailer::avisoRelease($miembros[$mid], $titulo, $texto, $filas, $detalle, $url) === true) $n = max($n, 1);
                } catch (Throwable $e) { /* el correo que falle no frena el resto */ }
            }
        }
        return $n;
    }

    private static function avisarCambio(array $antes, array $it, array $cambios, int $quien, string $motivo): void
    {
        $c = self::conf()['avisos'];
        $t = $cambios['tipos'];
        $quiere = (!empty($t['fechas']) && $c['cambio_fechas']) || (!empty($t['estado']) && $c['cambio_estado']) || (!empty($t['equipo']) && $c['cambio_equipo']);
        if (!$quiere) return;
        $para = self::destinatarios($it['equipo']);
        if (!empty($t['equipo']) && ($antes['equipo'] ?? '') !== $it['equipo']) $para = array_merge($para, self::destinatarios((string)$antes['equipo']));
        $quienNom = (new MiembroRepo())->mapa()[$quien]['nombre'] ?? 'Alguien';
        $titulo = $it['codigo'] . ' · ' . (!empty($t['fechas']) ? 'cambió de fechas' : (!empty($t['equipo']) ? 'cambió de equipo' : 'cambió de estado'));
        self::avisar($para, $titulo, $quienNom . ': ' . implode(' · ', $cambios['texto']) . ($motivo !== '' ? ' — ' . $motivo : ''),
            'releases.php?v=item&id=' . (int)$it['id'],
            ['Ítem' => $it['codigo'] . ' ' . $it['titulo'], 'Equipo' => $it['equipo'], 'Release' => $it['release'],
             'Fechas' => self::rango($it['inicio'], $it['fin']), 'Estado' => self::ESTADOS[$it['estado']][0] . ' · ' . $it['avance'] . ' %'],
            $quien, implode("\n", $cambios['texto']) . ($motivo !== '' ? "\nMotivo: $motivo" : ''));
    }

    private static function avisarRevision(array $rev, int $quien): void
    {
        if (!self::conf()['avisos']['revision']) return;
        $cu = $rev['cumplimiento'];
        $txt = sprintf('%d ítems: %d cumplidos, %d parciales, %d no cumplidos', $cu['programados'], $cu['cumplido'], $cu['parcial'], $cu['no_cumplido']);
        $pend = array_filter((array)$rev['filas'], fn($f) => in_array($f['estado'], ['parcial', 'no_cumplido'], true));
        $detalle = implode("\n", array_map(fn($f) => '• ' . $f['codigo'] . ' — ' . self::RESULTADOS[$f['estado']][0] . ($f['obs'] !== '' ? ': ' . $f['obs'] : ''), $pend));
        self::avisar(self::destinatarios($rev['equipo']), 'Revisión ' . $rev['equipo'] . ' del ' . date('d/m', strtotime($rev['fecha'])) . ' registrada',
            $txt . ($cu['pct'] !== null ? ' · cumplimiento ' . $cu['pct'] . ' %' : ''), 'releases.php?v=revision&id=' . (int)$rev['id'],
            ['Equipo' => $rev['equipo'], 'Fecha' => date('d/m/Y', strtotime($rev['fecha'])) . ' ' . ($rev['hora'] ?? ''), 'Resultado' => $txt],
            $quien, ($detalle !== '' ? "Compromisos para la próxima sesión:\n" . $detalle : '') . (trim((string)($rev['acuerdos'] ?? '')) !== '' ? "\n\nAcuerdos:\n" . $rev['acuerdos'] : ''));
    }

    /**
     * Lo que corre solo una vez al día (lo llama el cron): ítems que vencen en
     * N días, atrasados, las revisiones de hoy (al SM, con lo que debe traer)
     * y, los lunes, el resumen de la semana. Devuelve cuántos avisos mandó.
     */
    public static function paso(?string $hoy = null): int
    {
        $hoy ??= date('Y-m-d');
        if (date('H:i') < '07:00' && $hoy === date('Y-m-d')) return 0;
        $marca = __DIR__ . '/../data/releases_avisos.json';
        $ultimo = json_decode((string)@file_get_contents($marca), true);
        if (($ultimo['dia'] ?? '') === $hoy) return 0;
        @file_put_contents($marca, json_encode(['dia' => $hoy, 't' => time()]));
        if (!self::repo('rel_items')->all()) return 0;
        $c = self::conf()['avisos'];
        $n = 0;
        try { self::asociarDelDia(); } catch (Throwable $e) { /* sin IA, sigue con los avisos */ }
        $porEquipo = [];
        foreach (self::items([], $hoy) as $it) {
            if ($it['estado'] === 'cerrado') continue;
            $dias = $it['dias_restantes'];
            if ($c['vence_pronto'] && $dias !== null && $dias === $c['vence_dias']) $porEquipo[$it['equipo']]['vence'][] = $it;
            if ($c['atrasados'] && in_array($it['salud'], ['atrasado', 'vencido'], true) && ((int)date('N', strtotime($hoy)) === 1 || $dias === -1)) $porEquipo[$it['equipo']]['atrasado'][] = $it;
        }
        foreach ($porEquipo as $eq => $g) {
            foreach (['vence' => 'vencen en ' . $c['vence_dias'] . ' día(s)', 'atrasado' => 'van atrasados'] as $k => $frase) {
                if (empty($g[$k])) continue;
                $l = $g[$k];
                $n += self::avisar(self::destinatarios($eq), $eq . ': ' . count($l) . ' ítem(s) ' . $frase,
                    implode(' · ', array_map(fn($it) => $it['codigo'], array_slice($l, 0, 8))), 'releases.php?v=equipo&e=' . rawurlencode($eq),
                    ['Equipo' => $eq, 'Ítems' => (string)count($l)], 0,
                    implode("\n", array_map(fn($it) => '• ' . $it['codigo'] . ' ' . $it['titulo'] . ' — fin ' . date('d/m', strtotime($it['fin'])) . ' · ' . $it['avance'] . ' % (esperado ' . $it['esperado'] . ' %)', $l)));
            }
        }
        if ($c['revision_hoy']) {
            foreach (self::revisiones(['desde' => $hoy, 'hasta' => $hoy, 'estado' => 'programada']) as $r) {
                $n += self::avisar(self::destinatarios($r['equipo']), 'Hoy: revisión ' . $r['equipo'] . ($r['hora'] ? ' a las ' . substr($r['hora'], 0, 5) : ''),
                    count($r['filas']) . ' ítem(s) a revisar' . ($r['titulo'] ? ' · ' . $r['titulo'] : ''), 'releases.php?v=revision&id=' . (int)$r['id'],
                    ['Equipo' => $r['equipo'], 'Hora' => (string)$r['hora']], 0,
                    implode("\n", array_map(fn($f) => '• ' . $f['codigo'] . ' (' . $f['tipo'] . ')' . ($f['entregado'] !== '' ? ': ' . mb_substr($f['entregado'], 0, 160) : ''), (array)$r['filas'])));
            }
        }
        if ($c['resumen_semanal'] && (int)date('N', strtotime($hoy)) === 1) {
            $tab = self::tablero($hoy);
            $lineas = [];
            foreach ($tab['equipos'] as $nom => $e) {
                if (!$e['total']) continue;
                $dl = self::resumenDailies($nom);
                $lineas[] = sprintf('• %s: %d %% (esperado %d %%) · %d atrasado(s)', $nom, $e['pct'], $e['esperado'], $e['atrasados'])
                    . ($dl['pct'] !== null ? ' · dailies ' . $dl['pct'] . ' % (' . $dl['cuenta']['cumplida'] . ' de ' . $dl['cuentan'] . ' días)' : '');
            }
            $n += self::avisar(self::destinatarios(), 'Releases: resumen de la semana', 'Avance general ' . $tab['total']['pct'] . ' % · esperado ' . $tab['total']['esperado'] . ' %',
                'releases.php', ['Avance' => $tab['total']['pct'] . ' %', 'Atrasados' => (string)$tab['total']['atrasados']], 0, implode("\n", $lineas));
        }
        return $n;
    }

    /* ============================== Importar los Excel ============================== */

    /**
     * Lee el «Cronograma General» y/o las «Revisiones» y los carga.
     * $modo: 'agregar' (solo lo nuevo y lo vacío: no pisa lo que ya se cambió
     * en el panel) o 'actualizar' (las fechas y el estado del Excel mandan).
     * Con $seco no escribe: devuelve lo que haría.
     */
    public static function importar(?string $rutaCronograma, ?string $rutaRevisiones, string $modo = 'agregar', bool $seco = false): array
    {
        return self::aplicar(self::leerArchivos($rutaCronograma, $rutaRevisiones), $modo, $seco,
            array_values(array_filter([$rutaCronograma ? 'cronograma' : '', $rutaRevisiones ? 'revisiones' : ''])));
    }

    /** Lo que traen los Excel, ya ordenado (releases, equipos, ítems, hitos, revisiones, feriados, guía). */
    public static function leerArchivos(?string $rutaCronograma, ?string $rutaRevisiones): array
    {
        $datos = ['releases' => [], 'equipos' => [], 'items' => [], 'hitos' => [], 'revisiones' => [], 'feriados' => [], 'guia' => []];
        if ($rutaCronograma) self::leerCronograma(self::leerXlsx($rutaCronograma), $datos);
        if ($rutaRevisiones) self::leerRevisiones(self::leerXlsx($rutaRevisiones), $datos);
        if (!$datos['items'] && !$datos['revisiones']) throw new RuntimeException('No encontré ítems ni revisiones en los archivos: ¿son los Excel del cronograma?');
        return $datos;
    }

    /** La semilla del repo (admin/semillas/releases-*.json): el plan listo para cargar sin subir los Excel. */
    public static function semilla(): ?string
    {
        $l = glob(__DIR__ . '/../semillas/releases-*.json') ?: [];
        sort($l);
        return $l ? end($l) : null;
    }

    /** Carga la semilla (o el JSON que se indique). Mismo $modo que importar(). */
    public static function sembrar(?string $ruta = null, string $modo = 'agregar', bool $seco = false): array
    {
        $ruta ??= self::semilla();
        if (!$ruta || !is_file($ruta)) throw new RuntimeException('No hay semilla de Releases en admin/semillas/.');
        $datos = json_decode((string)file_get_contents($ruta), true);
        if (!is_array($datos) || empty($datos['items'])) throw new RuntimeException('La semilla ' . basename($ruta) . ' no trae ítems.');
        $datos += ['releases' => [], 'equipos' => [], 'hitos' => [], 'revisiones' => [], 'feriados' => [], 'guia' => []];
        return self::aplicar($datos, $modo, $seco, ['semilla ' . basename($ruta)]);
    }

    /** Escribe en el panel lo leído de los Excel o de la semilla. */
    private static function aplicar(array $datos, string $modo, bool $seco, array $origen): array
    {
        $r = ['releases' => 0, 'equipos' => 0, 'items_nuevos' => 0, 'items_actualizados' => 0, 'hitos' => 0,
              'revisiones' => 0, 'filas_revision' => 0, 'feriados' => 0, 'avisos' => []];
        $r['vista'] = ['releases' => array_values($datos['releases']), 'equipos' => array_values($datos['equipos']),
                       'items' => count($datos['items']), 'revisiones' => count($datos['revisiones']), 'hitos' => count($datos['hitos'])];
        if ($seco) {
            $existentes = array_column(self::repo('rel_items')->all(), null, 'codigo');
            foreach ($datos['items'] as $cod => $it) isset($existentes[$cod]) ? $r['items_actualizados']++ : $r['items_nuevos']++;
            $r['releases'] = count($datos['releases']); $r['equipos'] = count($datos['equipos']); $r['hitos'] = count($datos['hitos']);
            $r['revisiones'] = count($datos['revisiones']); $r['feriados'] = count($datos['feriados']);
            $r['filas_revision'] = array_sum(array_map(fn($x) => count($x['filas']), $datos['revisiones']));
            return $r;
        }
        $pisar = $modo === 'actualizar';
        // Releases
        $repo = self::repo('rel_releases');
        $actuales = array_column($repo->all(), null, 'codigo');
        $orden = 0;
        foreach ($datos['releases'] as $cod => $rel) {
            $rel['orden'] = $orden++;
            if (isset($actuales[$cod])) {
                $cambio = $pisar ? $rel : array_filter($rel, fn($v, $k) => ($actuales[$cod][$k] ?? '') === '' || $k === 'orden', ARRAY_FILTER_USE_BOTH);
                if ($cambio) $repo->update((int)$actuales[$cod]['id'], $cambio);
            } else {
                $repo->insert($rel);
            }
            $r['releases']++;
        }
        // Equipos (con su SM y PO por nombre; se buscan en el panel para vincularlos)
        $repo = self::repo('rel_equipos');
        $actuales = array_column($repo->all(), null, 'nombre');
        $miembros = (new MiembroRepo())->todos();
        $orden = 1;
        foreach ($datos['equipos'] as $nom => $e) {
            $e['orden'] = $orden++;
            if (($e['sm_nombre'] ?? '') !== '' && ($m = self::miembroPorNombre($e['sm_nombre'], $miembros))) $e['sm_id'] = (int)$m['id'];
            if (($e['po_nombre'] ?? '') !== '' && ($m = self::miembroPorNombre($e['po_nombre'], $miembros))) $e['po_id'] = (int)$m['id'];
            // Los proyectos del panel se llaman como el equipo («Equipo Alpha»): se vinculan solos
            if (empty($actuales[$nom]['proyectos'])) {
                $pids = [];
                foreach ((new ProyectoRepo())->todos() as $p) {
                    if (preg_match('/\b' . preg_quote(self::normal($nom), '/') . '\b/u', self::normal((string)$p['nombre']))) $pids[] = (int)$p['id'];
                }
                if ($pids) $e['proyectos'] = $pids;
            }
            if (isset($actuales[$nom])) {
                $cambio = array_filter($e, fn($v, $k) => $pisar || empty($actuales[$nom][$k]), ARRAY_FILTER_USE_BOTH);
                if ($cambio) $repo->update((int)$actuales[$nom]['id'], $cambio);
            } else {
                $repo->insert($e + ['proyectos' => [], 'miembros' => [], 'color' => self::COLORES_EQUIPO[$nom] ?? '#475569']);
            }
            $r['equipos']++;
        }
        // Ítems
        $repo = self::repo('rel_items');
        $actuales = array_column($repo->all(), null, 'codigo');
        foreach ($datos['items'] as $cod => $it) {
            if (isset($actuales[$cod])) {
                $a = $actuales[$cod];
                $cambio = [];
                foreach ($it as $k => $v) {
                    if (in_array($k, ['estado', 'avance', 'inicio', 'fin'], true)) {
                        if ($pisar || ($a[$k] ?? '') === '' || ($k === 'avance' && !isset($a['avance']))) $cambio[$k] = $v;
                    } elseif (($a[$k] ?? '') === '' || ($pisar && $v !== '')) {
                        $cambio[$k] = $v;
                    }
                }
                unset($cambio['historial']);
                if (isset($cambio['avance'])) $cambio['avance_base'] = (int)$cambio['avance'];
                if ($cambio) {
                    $hist = (array)($a['historial'] ?? []);
                    $hist[] = self::evento((int)(Auth::usuario()['id'] ?? 0), 'Actualizado desde el Excel (' . ($pisar ? 'el Excel manda' : 'solo lo vacío') . ')');
                    $repo->update((int)$a['id'], $cambio + ['historial' => array_slice($hist, -80)]);
                    $r['items_actualizados']++;
                }
            } else {
                $repo->insert($it + ['tareas' => [], 'avance_base' => (int)($it['avance'] ?? 0), 'creado' => date('Y-m-d H:i'),
                    'historial' => [self::evento((int)(Auth::usuario()['id'] ?? 0), 'Importado del Excel')]]);
                $r['items_nuevos']++;
            }
        }
        // Hitos (por fecha + título)
        $repo = self::repo('rel_hitos');
        $ya = [];
        foreach ($repo->all() as $h) $ya[$h['fecha'] . '|' . self::normal($h['titulo'])] = (int)$h['id'];
        foreach ($datos['hitos'] as $h) {
            $k = $h['fecha'] . '|' . self::normal($h['titulo']);
            if (isset($ya[$k])) { if ($pisar) $repo->update($ya[$k], $h); continue; }
            $repo->insert($h + ['hecho' => $h['fecha'] < date('Y-m-d')]);
            $r['hitos']++;
        }
        // Revisiones (una sesión por fecha + equipo). Las ya realizadas en el panel no se tocan.
        $repo = self::repo('rel_revisiones');
        $ya = [];
        foreach ($repo->all() as $rv) $ya[$rv['fecha'] . '|' . $rv['equipo']] = $rv;
        $itemsId = array_column(self::repo('rel_items')->all(), 'id', 'codigo');
        foreach ($datos['revisiones'] as $k => $rv) {
            foreach ($rv['filas'] as &$f) $f['item_id'] = (int)($itemsId[$f['codigo']] ?? 0);
            unset($f);
            if (isset($ya[$k])) {
                if (($ya[$k]['estado'] ?? '') === 'realizada') continue;
                // Conserva lo que ya se anotó en el panel en las filas que coinciden
                $previas = [];
                foreach ((array)$ya[$k]['filas'] as $pf) $previas[$pf['codigo'] . '|' . $pf['tipo']] = $pf;
                foreach ($rv['filas'] as &$f) {
                    $p = $previas[$f['codigo'] . '|' . $f['tipo']] ?? null;
                    if ($p && ($p['estado'] !== 'pendiente' || $p['obs'] !== '' || $p['pct'] !== null)) { $f['estado'] = $p['estado']; $f['pct'] = $p['pct']; $f['obs'] = $p['obs']; }
                }
                unset($f);
                $repo->update((int)$ya[$k]['id'], ['filas' => $rv['filas'], 'hora' => $rv['hora'], 'titulo' => $rv['titulo'], 'responsable' => $rv['responsable'], 'sprint' => $rv['sprint']]);
            } else {
                $repo->insert($rv + ['estado' => 'programada', 'creada' => date('Y-m-d H:i'), 'acuerdos' => '', 'asistentes' => []]);
                $r['revisiones']++;
            }
            $r['filas_revision'] += count($rv['filas']);
        }
        // Feriados y guía (DoD, DoR, estados) a la configuración
        $conf = (array)(Config::get('releases') ?? []);
        if ($datos['feriados']) {
            $conf['feriados'] = array_values(array_unique(array_merge((array)($conf['feriados'] ?? []), $datos['feriados'])));
            sort($conf['feriados']);
            $r['feriados'] = count($datos['feriados']);
        }
        if ($datos['guia']) $conf['guia'] = $datos['guia'];
        if (!empty($datos['programa']) && empty($conf['programa'])) $conf['programa'] = $datos['programa'];
        if (empty($conf['go_live'])) foreach ($datos['hitos'] as $h) if (stripos($h['titulo'], 'go-live') !== false) $conf['go_live'] = $h['fecha'];
        $conf['importado'] = ['fecha' => date('Y-m-d H:i'), 'quien' => (int)(Auth::usuario()['id'] ?? 0), 'modo' => $modo, 'archivos' => $origen];
        Config::guardar(['releases' => $conf]);
        return $r;
    }

    /** Hojas del libro → [nombre => [fila => [col => valor]]]. Fechas como AAAA-MM-DD. */
    public static function leerXlsx(string $ruta): array
    {
        $bin = (string)@file_get_contents($ruta);
        if ($bin === '' || !str_starts_with($bin, 'PK')) throw new RuntimeException('El archivo no es un Excel (.xlsx) válido.');
        require_once __DIR__ . '/IA.php';
        try {
            $zip = IA::zip($bin);
        } catch (Throwable $e) {
            throw new RuntimeException('No pude abrir el Excel: ' . $e->getMessage());
        }
        $leer = fn(string $n) => $zip[$n] ?? null;
        $shared = [];
        if ($x = $leer('xl/sharedStrings.xml')) {
            $sx = @simplexml_load_string($x);
            if ($sx) foreach ($sx->si as $si) {
                $t = '';
                if (isset($si->t)) $t = (string)$si->t;
                foreach ($si->r as $run) $t .= (string)$run->t;
                $shared[] = $t;
            }
        }
        // Estilos con formato de fecha
        $fechas = [];
        if ($x = $leer('xl/styles.xml')) {
            $st = @simplexml_load_string($x);
            if ($st) {
                $fmts = [];
                foreach ($st->numFmts->numFmt ?? [] as $nf) $fmts[(int)$nf['numFmtId']] = (string)$nf['formatCode'];
                $i = 0;
                foreach ($st->cellXfs->xf ?? [] as $xf) {
                    $fid = (int)$xf['numFmtId'];
                    $code = strtolower($fmts[$fid] ?? '');
                    if (($fid >= 14 && $fid <= 22) || ($fid >= 45 && $fid <= 47) || ($code !== '' && preg_match('/[dmy]/', preg_replace('/\[[^\]]*\]|"[^"]*"/', '', $code)) && !preg_match('/0\.0|#/', $code))) $fechas[$i] = true;
                    $i++;
                }
            }
        }
        $wb = @simplexml_load_string((string)$leer('xl/workbook.xml'));
        $rels = @simplexml_load_string((string)$leer('xl/_rels/workbook.xml.rels'));
        if (!$wb || !$rels) throw new RuntimeException('El Excel no trae hojas legibles.');
        $destinos = [];
        foreach ($rels->Relationship as $rl) $destinos[(string)$rl['Id']] = (string)$rl['Target'];
        $hojas = [];
        foreach ($wb->sheets->sheet as $s) {
            $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $dest = ltrim($destinos[$rid] ?? '', '/');
            if (!str_starts_with($dest, 'xl/')) $dest = 'xl/' . $dest;
            $xml = $leer($dest);
            if (!$xml) continue;
            $sx = @simplexml_load_string($xml);
            if (!$sx) continue;
            $filas = [];
            foreach ($sx->sheetData->row as $row) {
                foreach ($row->c as $c) {
                    $ref = (string)$c['r'];
                    $t = (string)$c['t'];
                    $estilo = (int)$c['s'];
                    if ($t === 's') $v = $shared[(int)$c->v] ?? '';
                    elseif ($t === 'inlineStr') $v = (string)($c->is->t ?? '');
                    elseif (!isset($c->v)) continue;
                    else {
                        $v = (string)$c->v;
                        if ($t === '' || $t === 'n') {
                            $num = (float)$v;
                            if (isset($fechas[$estilo]) && $num > 20000 && $num < 60000) $v = gmdate('Y-m-d', (int)round(($num - 25569) * 86400));
                            elseif (is_numeric($v)) $v = $num == (int)$num ? (int)$num : round($num, 4);
                        }
                    }
                    if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) continue;
                    $col = 0;
                    foreach (str_split($m[1]) as $ch) $col = $col * 26 + ord($ch) - 64;
                    $filas[(int)$m[2]][$col - 1] = is_string($v) ? trim($v) : $v;
                }
            }
            ksort($filas);
            $hojas[(string)$s['name']] = $filas;
        }
        return $hojas;
    }

    /** Busca la fila de títulos de una hoja: la primera que trae todas esas palabras. → [fila, [clave => columna]] */
    private static function cabecera(array $filas, array $buscar, int $hasta = 12): array
    {
        foreach (array_slice($filas, 0, $hasta, true) as $n => $f) {
            $col = [];
            foreach ($f as $i => $v) {
                $t = self::normal((string)$v);
                foreach ($buscar as $clave => $patron) {
                    if (!isset($col[$clave]) && preg_match('/' . $patron . '/u', $t)) { $col[$clave] = $i; break; }
                }
            }
            $obligatorias = array_filter(array_keys($buscar), fn($k) => !str_starts_with($k, '?'));
            if (count(array_intersect($obligatorias, array_keys($col))) === count($obligatorias)) return [$n, $col];
        }
        return [0, []];
    }

    private static function val(array $f, array $col, string $k): string
    {
        $i = $col[$k] ?? $col['?' . $k] ?? null;
        return $i === null ? '' : trim((string)($f[$i] ?? ''));
    }

    private static function estadoExcel(string $t): array
    {
        $n = self::normal($t);
        return match (true) {
            str_starts_with($n, 'cerrad'), str_starts_with($n, 'terminad'), str_starts_with($n, 'cumplid') => ['cerrado', 100],
            str_contains($n, 'curso'), str_contains($n, 'progreso') => ['avanzado', 40],
            str_contains($n, 'bloque') => ['bloqueado', 0],
            str_contains($n, 'valid') => ['validacion', 85],
            default => ['por_iniciar', 0],
        };
    }

    private static function leerCronograma(array $hojas, array &$d): void
    {
        foreach ($hojas as $nombre => $filas) {
            $hn = self::normal($nombre);
            if (empty($d['programa'])) {
                $tit = (string)(reset($filas)[0] ?? '');
                if (preg_match('/^([A-Z0-9\-]{3,})\s+[—-]/u', $tit, $m)) $d['programa'] = $m[1];
            }
            if (str_contains($hn, 'resumen') && str_contains($hn, 'release')) {
                [$fc, $col] = self::cabecera($filas, ['release' => '^release$', 'nombre' => '^nombre', 'inicio' => 'inicio', 'fin' => '^fecha fin|^fin$',
                    '?sprints' => '^sprints', '?equipos' => '^equipos', '?modulos' => 'modulos', '?fin_anterior' => 'fin v\d', '?estado' => '^estado']);
                if (!$col) continue;
                foreach ($filas as $n => $f) {
                    if ($n <= $fc) continue;
                    $cod = self::val($f, $col, 'release');
                    if (!preg_match('/^R\d+$/', $cod)) { if ($cod !== '' && !str_starts_with(self::normal($cod), 'total')) continue; if (str_starts_with(self::normal($cod), 'total')) break; continue; }
                    [$est] = self::estadoExcel(self::val($f, $col, 'estado'));
                    $d['releases'][$cod] = ['codigo' => $cod, 'nombre' => self::val($f, $col, 'nombre'), 'sprints' => self::val($f, $col, 'sprints'),
                        'inicio' => self::fecha(self::val($f, $col, 'inicio')), 'fin' => self::fecha(self::val($f, $col, 'fin')),
                        'fin_anterior' => self::fecha(self::val($f, $col, 'fin_anterior')), 'equipos_txt' => self::val($f, $col, 'equipos'),
                        'modulos' => self::val($f, $col, 'modulos'),
                        'estado' => $est === 'cerrado' ? 'cerrado' : ($est === 'por_iniciar' ? 'por_iniciar' : 'en_curso'),
                        'color' => self::COLORES[$cod] ?? '#2970EB'];
                }
            } elseif (str_contains($hn, 'cronograma general')) {
                [$fc, $col] = self::cabecera($filas, ['release' => '^release$', 'historia' => 'componente|historia', 'equipo' => '^equipo',
                    'inicio' => '^inicio', 'fin' => '^fin', '?micro' => 'microservicio', '?sprints' => '^sprints', '?estado' => '^estado']);
                if (!$col) continue;
                foreach ($filas as $n => $f) {
                    if ($n <= $fc) continue;
                    $rel = self::val($f, $col, 'release');
                    $hist = self::val($f, $col, 'historia');
                    if (!preg_match('/^R\d+$/', $rel) || !preg_match('/^(R\d+-[A-Z0-9]+)\s+(.+)$/su', $hist, $m)) continue;
                    [$est, $pct] = self::estadoExcel(self::val($f, $col, 'estado'));
                    $equipo = self::val($f, $col, 'equipo') ?: 'Todos';
                    $d['items'][$m[1]] = array_replace($d['items'][$m[1]] ?? [], [
                        'codigo' => $m[1], 'release' => $rel, 'titulo' => trim($m[2]), 'equipo' => $equipo,
                        'microservicio' => self::val($f, $col, 'micro'), 'sprints' => self::val($f, $col, 'sprints'),
                        'inicio' => self::fecha(self::val($f, $col, 'inicio')), 'fin' => self::fecha(self::val($f, $col, 'fin')),
                        'inicio_original' => self::fecha(self::val($f, $col, 'inicio')), 'fin_original' => self::fecha(self::val($f, $col, 'fin')),
                        'estado' => $est, 'avance' => $d['items'][$m[1]]['avance'] ?? $pct,
                        'verificar' => str_contains(self::normal(self::val($f, $col, 'estado')), 'verificar'),
                    ]);
                    if (!isset($d['equipos'][$equipo])) $d['equipos'][$equipo] = ['nombre' => $equipo];
                }
            } elseif (preg_match('/^r\d+ detallado/', $hn)) {
                [$fc, $col] = self::cabecera($filas, ['codigo' => '^codigo', '?historia' => 'modulo|historia|^frente',
                    '?alcance' => 'alcance', '?proceso' => 'proceso bpm', '?actividades' => 'actividades', '?equipo' => '^equipo$',
                    '?sm' => 'scrum master', '?po' => 'product owner', '?demostrar' => 'demostrarse', '?estado' => '^estado',
                    '?fases' => 'fases', '?responsable' => 'responsable', '?criterio' => 'criterio', '?entregable' => 'entregable',
                    '?inicio' => '^inicio$', '?fin' => '^fin$', '?inicio_v' => 'inicio v\d', '?fin_v' => 'fin v\d', '?dependencias' => 'dependencias']);
                if (!$col) continue;
                foreach ($filas as $n => $f) {
                    if ($n <= $fc) continue;
                    $primera = trim((string)($f[0] ?? ''));
                    // «  Equipo Alpha  |  SM: René Cardona  |  PO: Walter Vélez  |  8 historias»
                    if (preg_match('/equipo\s+(\w+).*?sm:\s*([^|]+)\|\s*po:\s*([^|]+)/iu', $primera, $m)) {
                        $eq = ucfirst(mb_strtolower(trim($m[1])));
                        $d['equipos'][$eq] = array_replace($d['equipos'][$eq] ?? ['nombre' => $eq], ['sm_nombre' => trim($m[2]), 'po_nombre' => trim($m[3])]);
                        continue;
                    }
                    $cod = self::val($f, $col, 'codigo');
                    if (preg_match('/^(alpha|beta|gamma|delta)$/i', $cod)) {
                        // Tabla de responsabilidades R4 por equipo: Equipo | Dominio | Datos legacy | SM / PO
                        $eq = ucfirst(mb_strtolower($cod));
                        $smpo = array_map('trim', explode('/', (string)end($f)));
                        $d['equipos'][$eq] = array_replace($d['equipos'][$eq] ?? ['nombre' => $eq], array_filter(['dominio' => trim((string)($f[1] ?? '')),
                            'sm_nombre' => $d['equipos'][$eq]['sm_nombre'] ?? ($smpo[0] ?? ''), 'po_nombre' => $d['equipos'][$eq]['po_nombre'] ?? ($smpo[1] ?? '')]));
                        continue;
                    }
                    if (!preg_match('/^R\d+-[A-Z0-9]+$/', $cod)) continue;
                    $extra = array_filter([
                        'alcance' => self::val($f, $col, 'alcance'), 'proceso' => self::val($f, $col, 'proceso'),
                        'actividades' => self::val($f, $col, 'actividades'), 'demostrar' => self::val($f, $col, 'demostrar'),
                        'fases' => self::val($f, $col, 'fases'), 'responsable' => self::val($f, $col, 'responsable'),
                        'criterio' => self::val($f, $col, 'criterio'), 'entregable' => self::val($f, $col, 'entregable'),
                        'dependencias' => self::val($f, $col, 'dependencias'),
                        'sm_nombre' => self::val($f, $col, 'sm'), 'po_nombre' => self::val($f, $col, 'po'),
                        'fin_anterior' => self::fecha(self::val($f, $col, 'fin_v')), 'inicio_anterior' => self::fecha(self::val($f, $col, 'inicio_v')),
                    ], fn($v) => $v !== '' && $v !== '—');
                    if (($t = self::val($f, $col, 'historia')) !== '' && empty($d['items'][$cod]['titulo'])) $extra['titulo'] = $t;
                    $d['items'][$cod] = array_replace($d['items'][$cod] ?? ['codigo' => $cod, 'release' => explode('-', $cod)[0]], $extra);
                }
            } elseif (str_contains($hn, 'hito')) {
                [$fc, $col] = self::cabecera($filas, ['hito' => '^hito', 'fecha' => '^fecha', '?release' => '^release', '?sprint' => '^sprint', '?desc' => 'descripcion', '?origen' => 'origen']);
                if (!$col) continue;
                foreach ($filas as $n => $f) {
                    if ($n <= $fc) continue;
                    $fe = self::fecha(self::val($f, $col, 'fecha'));
                    $ti = self::val($f, $col, 'hito');
                    if ($fe === '' || $ti === '') continue;
                    $d['hitos'][] = ['fecha' => $fe, 'titulo' => $ti, 'release' => self::val($f, $col, 'release'), 'sprint' => self::val($f, $col, 'sprint'),
                                     'descripcion' => self::val($f, $col, 'desc'), 'origen' => self::val($f, $col, 'origen')];
                }
            }
        }
        // Equipos que solo aparecen como «Todos»: van al final
        if (isset($d['equipos']['Todos'])) { $t = $d['equipos']['Todos']; unset($d['equipos']['Todos']); $d['equipos']['Todos'] = $t; }
    }

    private static function leerRevisiones(array $hojas, array &$d): void
    {
        foreach ($hojas as $nombre => $filas) {
            $hn = self::normal($nombre);
            if (str_contains($hn, 'calendario de revision')) {
                [$fc, $col] = self::cabecera($filas, ['fecha' => '^fecha$', 'equipo' => '^equipo', 'tipo' => 'tipo de revision', 'codigo' => '^codigo',
                    '?sem' => '^sem', '?sprint' => '^sprint', '?hora' => '^hora', '?resp' => 'responsable', '?historia' => 'historia|tema',
                    '?entregado' => 'entregado', '?foco' => 'foco', '?evidencia' => 'evidencia', '?integra' => 'integraciones',
                    '?inicio' => '^inicio$', '?fin' => '^fin$', '?base' => '^base', '?esperado' => 'esperado', '?estado' => '^estado$', '?real' => 'real', '?obs' => 'observaciones']);
                if (!$col) continue;
                foreach ($filas as $n => $f) {
                    if ($n <= $fc) continue;
                    $fe = self::fecha(self::val($f, $col, 'fecha'));
                    $eq = self::val($f, $col, 'equipo');
                    if ($fe === '' || $eq === '') continue;
                    $k = $fe . '|' . $eq;
                    $d['revisiones'][$k] ??= ['fecha' => $fe, 'equipo' => $eq, 'hora' => self::val($f, $col, 'hora'), 'sprint' => self::val($f, $col, 'sprint'),
                        'semana' => (int)self::val($f, $col, 'sem'), 'responsable' => self::val($f, $col, 'resp'), 'titulo' => '', 'filas' => []];
                    $foco = self::val($f, $col, 'foco');
                    $esp = self::val($f, $col, 'esperado');
                    $real = self::val($f, $col, 'real');
                    $estado = self::normal(self::val($f, $col, 'estado'));
                    $d['revisiones'][$k]['filas'][] = [
                        'item_id' => 0, 'codigo' => self::val($f, $col, 'codigo'), 'titulo' => self::val($f, $col, 'historia'),
                        'tipo' => self::val($f, $col, 'tipo'), 'entregado' => self::val($f, $col, 'entregado'),
                        'foco' => $foco === '—' ? '' : $foco, 'evidencia' => self::val($f, $col, 'evidencia'),
                        'integraciones' => self::val($f, $col, 'integra') === '—' ? '' : self::val($f, $col, 'integra'),
                        'esperado' => is_numeric($esp) ? (int)round((float)$esp * ((float)$esp <= 1 ? 100 : 1)) : null,
                        'estado' => match (true) {
                            str_starts_with($estado, 'cumplido') => 'cumplido', str_starts_with($estado, 'parcial') => 'parcial',
                            str_starts_with($estado, 'no cumplido') => 'no_cumplido', str_starts_with($estado, 'reprogram') => 'reprogramado',
                            str_starts_with($estado, 'no aplica') => 'no_aplica', default => 'pendiente' },
                        'pct' => is_numeric($real) ? (int)round((float)$real * ((float)$real <= 1 ? 100 : 1)) : null,
                        'obs' => self::val($f, $col, 'obs'),
                    ];
                    // Base del 25/10 → avance de partida del ítem (si el cronograma no lo trajo)
                    $cod = self::val($f, $col, 'codigo');
                    $base = self::val($f, $col, 'base');
                    if (isset($d['items'][$cod]) && is_numeric($base) && (float)$base > 0 && (float)$base < 1 && $d['items'][$cod]['estado'] !== 'cerrado') {
                        $pct = (int)round((float)$base * 100);
                        if ($pct > (int)($d['items'][$cod]['avance'] ?? 0) || ($d['items'][$cod]['avance'] ?? 0) == 40) {
                            $d['items'][$cod]['avance'] = $pct;
                            $d['items'][$cod]['estado'] = $pct >= 40 ? 'avanzado' : 'empezado';
                        }
                    }
                }
            } elseif (str_contains($hn, 'agenda semanal')) {
                // El foco de cada semana se usa como título de las sesiones de esa semana
                [$fc, $col] = self::cabecera($filas, ['sem' => '^sem', 'foco' => 'foco']);
                if (!$col) continue;
                foreach ($filas as $n => $f) {
                    if ($n <= $fc) continue;
                    $sem = (int)self::val($f, $col, 'sem');
                    if ($sem > 0) $d['focos'][$sem] = self::val($f, $col, 'foco');
                }
            } elseif (str_contains($hn, 'feriado')) {
                foreach ($filas as $f) {
                    $fe = self::fecha((string)($f[1] ?? ''));
                    $tipo = self::normal((string)($f[3] ?? ''));
                    if ($fe !== '' && is_numeric($f[0] ?? null) && !str_contains($tipo, 'jornada') && !str_contains($tipo, 'pasa a') && !isset($f[4])) $d['feriados'][] = $fe;
                }
                $d['feriados'] = array_values(array_unique($d['feriados']));
            } elseif (str_contains($hn, 'guia')) {
                $sec = '';
                foreach ($filas as $f) {
                    $a = trim((string)($f[0] ?? ''));
                    if (preg_match('/^\s*\d+\.\s+(.+)$/u', $a, $m) && !isset($f[1]) && !isset($f[2])) { $sec = self::normal($m[1]); continue; }
                    $texto = trim((string)($f[2] ?? ''));
                    $tit = trim((string)($f[1] ?? ''));
                    if ($texto === '') continue;
                    if (str_contains($sec, 'definicion de terminado')) $d['guia']['dod'][] = $texto;
                    elseif (str_contains($sec, 'definicion de listo')) $d['guia']['dor'][] = $texto;
                    elseif (str_contains($sec, 'estados')) $d['guia']['estados'][] = ['titulo' => $tit, 'texto' => $texto];
                    elseif (str_contains($sec, 'agenda tipo')) $d['guia']['agenda'][] = ['titulo' => $tit, 'texto' => $texto];
                    elseif (str_contains($sec, 'participantes')) $d['guia']['participantes'][] = ['titulo' => $tit, 'texto' => $texto];
                }
            }
        }
        foreach ($d['revisiones'] as &$rv) if (!empty($d['focos'][$rv['semana'] ?? 0])) $rv['titulo'] = mb_substr($d['focos'][$rv['semana']], 0, 300);
        unset($rv);
    }

    /* ============================== Utilidades ============================== */

    public static function fecha($v): string
    {
        $v = trim((string)$v);
        if ($v === '') return '';
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : '';
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : '';
        if (is_numeric($v) && (float)$v > 20000 && (float)$v < 60000) return gmdate('Y-m-d', (int)round(((float)$v - 25569) * 86400));
        return '';
    }

    public static function rango(string $ini, string $fin): string
    {
        $f = fn($x) => $x ? date('d/m/Y', strtotime($x)) : '—';
        return $f($ini) . ' → ' . $f($fin);
    }

    public static function normal(string $t): string
    {
        $t = mb_strtolower(trim($t), 'UTF-8');
        $t = strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return (string)preg_replace('/\s+/', ' ', $t);
    }

    private static function ordenRelease(string $cod): int
    {
        return (int)preg_replace('/\D/', '', $cod);
    }

    private static function miembroPorNombre(string $nombre, array $miembros): ?array
    {
        $n = self::normal($nombre);
        if ($n === '') return null;
        $partes = explode(' ', $n);
        $mejor = null;
        foreach ($miembros as $m) {
            $mn = self::normal((string)($m['nombre'] ?? ''));
            if ($mn === $n) return $m;
            // «Jorddy Pincay» ≈ «Jordy Pincay»: mismo apellido y el nombre parecido
            $mp = explode(' ', $mn);
            if (count($partes) >= 2 && count($mp) >= 2 && end($partes) === end($mp) && levenshtein($partes[0], $mp[0]) <= 2) $mejor = $m;
        }
        return $mejor;
    }
}
