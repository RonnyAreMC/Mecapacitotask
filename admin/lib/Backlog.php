<?php
/**
 * Backlog estilo Jira de cada proyecto (pestaña «Sprints» de proyecto.php, con
 * lib/backlog_ui.php y assets/backlog.js). Lo arman su PO, su Scrum Master y el
 * administrador; los demás lo ven. Las historias de usuario son hijas de los
 * ítems del release del proyecto (que hacen de épica): «Como… quiero… para…», sus criterios de aceptación, la
 * estimación (Fibonacci o talla de camisa, según Ajustes), la prioridad, quién
 * la lleva, el sprint en que va (o el backlog), sus diagramas y documentos y
 * las tareas del panel que la cumplen.
 *
 * Los sprints son un calendario global que se calcula solo: desde el inicio del
 * S01 y con la duración de Ajustes (una o dos semanas, nunca más), S01, S02…, y
 * cada uno cae en el release (o los releases) de sus fechas. Lo que no se
 * terminó en un sprint que ya pasó pasa solo al sprint en curso, y queda dicho
 * en su historia.
 */
final class Backlog
{
    /** Estado de una historia: [nombre, tono]. El tablero del sprint tiene una columna por estado. */
    public const ESTADOS = [
        'por_hacer'   => ['Por hacer', 'gris'],
        'en_curso'    => ['En curso', 'azul'],
        'en_revision' => ['En revisión', 'ambar'],
        'hecho'       => ['Hecho', 'verde'],
    ];
    public const FIBONACCI = ['1', '2', '3', '5', '8', '13', '21'];
    /** Talla de camisa → puntos, para sumar la velocidad igual que con Fibonacci. */
    public const CAMISA = ['XS' => 1, 'S' => 2, 'M' => 3, 'L' => 5, 'XL' => 8, 'XXL' => 13];
    public const PRIORIDADES = ['alta' => 'Alta', 'media' => 'Media', 'baja' => 'Baja'];

    /* ============================== Ajustes ============================== */

    /** Inicio del S01, semanas por sprint (1 o 2), escala de estimación, capacidad por equipo y si se arrastra lo no terminado. */
    public static function conf(): array
    {
        $c = (array)(((array)(Config::get('releases') ?? []))['sprints'] ?? []);
        $primero = '';
        foreach (Releases::releases() as $r) if (($r['inicio'] ?? '') !== '' && ($primero === '' || $r['inicio'] < $primero)) $primero = $r['inicio'];
        return [
            'inicio'    => Releases::fecha($c['inicio'] ?? '') ?: ($primero ?: date('Y-m-d', strtotime('monday this week'))),
            'semanas'   => in_array((int)($c['semanas'] ?? 2), [1, 2], true) ? (int)($c['semanas'] ?? 2) : 2,
            'escala'    => ($c['escala'] ?? '') === 'camisa' ? 'camisa' : 'fibonacci',
            'capacidad' => max(0, min(500, (int)($c['capacidad'] ?? 0))),
            'arrastrar' => !array_key_exists('arrastrar', $c) || !empty($c['arrastrar']),
        ];
    }

    public static function guardarConf(array $d): array
    {
        $ini = Releases::fecha($d['inicio'] ?? '');
        if ($ini === '') throw new RuntimeException('Elige la fecha en que empieza el primer sprint.');
        $sem = (int)($d['semanas'] ?? 2);
        if (!in_array($sem, [1, 2], true)) throw new RuntimeException('Un sprint dura una o dos semanas, no más.');
        $c = ['inicio' => $ini, 'semanas' => $sem, 'escala' => ($d['escala'] ?? '') === 'camisa' ? 'camisa' : 'fibonacci',
              'capacidad' => max(0, min(500, (int)($d['capacidad'] ?? 0))), 'arrastrar' => !empty($d['arrastrar'])];
        Releases::guardarConf(['sprints' => $c]);
        return $c;
    }

    /** Lo que se puede elegir al estimar, según la escala de Ajustes. */
    public static function opcionesEstimacion(): array
    {
        return self::conf()['escala'] === 'camisa' ? array_keys(self::CAMISA) : self::FIBONACCI;
    }

    /** Los puntos de una estimación (una talla vale como su número de Fibonacci); 0 si no está estimada. */
    public static function puntos(string $est): int
    {
        $est = strtoupper(trim($est));
        if (isset(self::CAMISA[$est])) return self::CAMISA[$est];
        return ctype_digit($est) ? (int)$est : 0;
    }

    /* ============================== Sprints ============================== */

    /**
     * El calendario de sprints: [S01 => [codigo, n, inicio, fin, releases]], desde el
     * inicio del S01 hasta el fin del último release (y al menos doce semanas desde hoy).
     */
    public static function sprints(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $c = self::conf();
        $hasta = date('Y-m-d', strtotime('+84 days'));
        $rels = Releases::releases();
        foreach ($rels as $r) if (($r['fin'] ?? '') > $hasta) $hasta = $r['fin'];
        $out = [];
        $ini = $c['inicio'];
        for ($n = 1; $n <= 260 && $ini <= $hasta; $n++) {
            $fin = date('Y-m-d', strtotime($ini . ' +' . ($c['semanas'] * 7 - 1) . ' days'));
            $deRel = [];
            foreach ($rels as $cod => $r) if (($r['inicio'] ?? '') !== '' && $r['inicio'] <= $fin && ($r['fin'] ?? '9999') >= $ini) $deRel[] = (string)$cod;
            $cod = 'S' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
            $out[$cod] = ['codigo' => $cod, 'n' => $n, 'inicio' => $ini, 'fin' => $fin, 'releases' => $deRel];
            $ini = date('Y-m-d', strtotime($fin . ' +1 day'));
        }
        return $cache = $out;
    }

    /** El sprint de hoy (o el próximo, o el último). */
    public static function actual(?string $hoy = null): string
    {
        $hoy ??= date('Y-m-d');
        $sp = self::sprints();
        foreach ($sp as $cod => $s) if ($s['inicio'] <= $hoy && $s['fin'] >= $hoy) return $cod;
        foreach ($sp as $cod => $s) if ($s['inicio'] > $hoy) return $cod;
        return (string)(array_key_last($sp) ?? '');
    }

    /** 'pasado' | 'actual' | 'futuro' */
    public static function momento(array $s, ?string $hoy = null): string
    {
        $hoy ??= date('Y-m-d');
        return $s['fin'] < $hoy ? 'pasado' : ($s['inicio'] > $hoy ? 'futuro' : 'actual');
    }

    /** Días hábiles de un sprint (sin fines de semana ni feriados) y los que le quedan desde hoy. */
    public static function dias(array $s, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $tot = Releases::habiles($s['inicio'], $s['fin']);
        $desde = max($hoy, $s['inicio']);
        return ['total' => $tot, 'quedan' => $desde > $s['fin'] ? 0 : Releases::habiles($desde, $s['fin'])];
    }

    /** El último día hábil del sprint (para la fecha límite de sus tareas). */
    public static function ultimoHabil(array $s): string
    {
        $f = $s['fin'];
        for ($i = 0; $i < 14 && class_exists('Feriados') && !Feriados::esHabil($f); $i++) $f = date('Y-m-d', strtotime($f . ' -1 day'));
        return $f;
    }

    /* ============================== Historias ============================== */

    private static function repo(): JsonStore { return new JsonStore('bl_historias'); }

    /** Los ítems del release por id (las épicas), una vez por petición. */
    private static function items(): array
    {
        static $m = null;
        if ($m === null) { $m = []; foreach (Releases::items() as $it) $m[(int)$it['id']] = $it; }
        return $m;
    }

    /** La historia con lo calculado: puntos, su épica y su release. */
    private static function completar(array $h): array
    {
        $it = self::items()[(int)($h['item_id'] ?? 0)] ?? null;
        $h += ['codigo' => '', 'titulo' => '', 'como' => '', 'quiero' => '', 'para' => '', 'criterios' => '', 'notas' => '', 'proyecto_id' => 0, 'equipo' => '',
               'item_id' => 0, 'release' => '', 'sprint' => '', 'estimacion' => '', 'prioridad' => 'media', 'estado' => 'por_hacer',
               'responsable' => 0, 'tareas' => [], 'adjuntos' => [], 'orden' => 0, 'historial' => [], 'hecho_en' => '', 'arrastres' => 0, 'escenarios' => []];
        // Los criterios de antes (texto suelto) se ven como un escenario
        if (!$h['escenarios'] && trim((string)$h['criterios']) !== '') $h['escenarios'] = [['nombre' => '', 'dado' => '', 'cuando' => '', 'entonces' => (string)$h['criterios']]];
        $h['puntos'] = self::puntos((string)$h['estimacion']);
        $h['item'] = $it ? ['id' => (int)$it['id'], 'codigo' => (string)$it['codigo'], 'titulo' => (string)$it['titulo'], 'release' => (string)$it['release']] : null;
        if ($it) $h['release'] = (string)$it['release'];
        $h['tareas'] = array_values(array_map('intval', (array)$h['tareas']));
        return $h;
    }

    /**
     * Historias. Filtro: proyecto_id, release, item_id, sprint ('' = las del backlog,
     * sin sprint; null = todas), estado, q (texto). Por su orden (el del backlog).
     */
    public static function historias(array $f = []): array
    {
        $q = isset($f['q']) ? mb_strtolower(trim((string)$f['q'])) : '';
        $out = [];
        foreach (self::repo()->all() as $h) {
            $h = self::completar($h);
            if (!empty($f['proyecto_id']) && (int)$h['proyecto_id'] !== (int)$f['proyecto_id']) continue;
            if (!empty($f['release']) && $h['release'] !== $f['release']) continue;
            if (!empty($f['item_id']) && (int)$h['item_id'] !== (int)$f['item_id']) continue;
            if (array_key_exists('sprint', $f) && $f['sprint'] !== null && $h['sprint'] !== $f['sprint']) continue;
            if (!empty($f['estado']) && $h['estado'] !== $f['estado']) continue;
            if ($q !== '' && !str_contains(mb_strtolower($h['codigo'] . ' ' . $h['titulo'] . ' ' . $h['quiero'] . ' ' . ($h['item']['codigo'] ?? '')), $q)) continue;
            $out[] = $h;
        }
        usort($out, fn($a, $b) => [(float)$a['orden'], (int)$a['id']] <=> [(float)$b['orden'], (int)$b['id']]);
        return $out;
    }

    public static function historia(int $id): ?array
    {
        $h = self::repo()->find($id);
        return $h ? self::completar($h) : null;
    }

    /** ¿Quién arma el backlog del proyecto? Su PO, su Scrum Master y el administrador (los demás lo ven). */
    public static function puede(int $proyectoId): bool
    {
        if ((function_exists('verComo') && verComo()) || Auth::esSupervisor()) return false;
        return $proyectoId > 0 && function_exists('puedeGestionarTareas') && puedeGestionarTareas($proyectoId);
    }

    /** Los ítems del release (épicas) que tocan a este proyecto: los de los equipos que trabajan en él. */
    public static function epicas(int $proyectoId): array
    {
        $out = [];
        foreach (Releases::itemsDeProyecto($proyectoId) as $it) $out[(int)$it['id']] = $it;
        return $out;
    }

    private static function evento(int $quien, string $texto): array
    {
        return ['fecha' => date('Y-m-d H:i'), 'quien' => $quien, 'texto' => mb_substr($texto, 0, 400)];
    }

    /**
     * Crea o cambia una historia. $d: proyecto_id (al crearla), titulo, como, quiero,
     * para, criterios, notas, item_id, release, sprint, estimacion, prioridad, estado, responsable,
     * tareas[] (las del panel que la cumplen), adjuntos_nuevos (ya subidos) y
     * quitar_adjuntos[] (sus rutas).
     */
    public static function guardar(array $d, int $id, int $quien): array
    {
        $repo = self::repo();
        $antes = $id > 0 ? $repo->find($id) : null;
        if ($id > 0 && !$antes) throw new RuntimeException('Esa historia ya no existe.');
        $txt = fn(string $k, int $max) => mb_substr(trim((string)($d[$k] ?? '')), 0, $max);
        $c = [];
        foreach (['titulo' => 200, 'como' => 160, 'quiero' => 800, 'para' => 800, 'criterios' => 4000] as $k => $max) {
            if (array_key_exists($k, $d)) $c[$k] = $txt($k, $max);
        }
        // El detalle, con formato (el editor del panel): se sanea siempre aquí
        if (array_key_exists('notas', $d)) $c['notas'] = class_exists('HtmlRico') ? mb_substr(HtmlRico::limpiar((string)$d['notas']), 0, 30000) : $txt('notas', 4000);
        // Criterios de aceptación como escenarios: Dado · Cuando · Entonces (Given · When · Then)
        if (array_key_exists('escenarios', $d)) {
            $esc = [];
            foreach (array_slice(array_values((array)$d['escenarios']), 0, 20) as $e) {
                $e = ['nombre' => mb_substr(trim((string)($e['nombre'] ?? '')), 0, 160), 'dado' => mb_substr(trim((string)($e['dado'] ?? '')), 0, 1500),
                      'cuando' => mb_substr(trim((string)($e['cuando'] ?? '')), 0, 1500), 'entonces' => mb_substr(trim((string)($e['entonces'] ?? '')), 0, 1500)];
                if ($e['dado'] !== '' || $e['cuando'] !== '' || $e['entonces'] !== '') $esc[] = $e;
            }
            $c['escenarios'] = $esc;
            // Y en texto, para buscar y exportar
            $c['criterios'] = mb_substr(implode("\n\n", array_map(fn($e) => trim(($e['nombre'] !== '' ? 'Escenario: ' . $e['nombre'] . "\n" : '')
                . ($e['dado'] !== '' ? 'Dado ' . $e['dado'] . "\n" : '') . ($e['cuando'] !== '' ? 'Cuando ' . $e['cuando'] . "\n" : '') . ($e['entonces'] !== '' ? 'Entonces ' . $e['entonces'] : '')), $esc)), 0, 8000);
        }
        if (($c['titulo'] ?? ($antes['titulo'] ?? '')) === '') throw new RuntimeException('Ponle un título a la historia.');
        $pid = (int)($antes['proyecto_id'] ?? ($d['proyecto_id'] ?? 0));
        if ($pid <= 0 || !(new ProyectoRepo())->buscar($pid)) throw new RuntimeException('Ese proyecto ya no existe.');
        if (!$antes) $c['proyecto_id'] = $pid;
        // La épica (ítem del release del proyecto) manda el release y el equipo
        if (array_key_exists('item_id', $d)) {
            $iid = (int)$d['item_id'];
            $it = $iid > 0 ? (self::epicas($pid)[$iid] ?? null) : null;
            if ($iid > 0 && !$it) throw new RuntimeException('Ese ítem no es del release de este proyecto.');
            $c['item_id'] = $it ? $iid : 0;
            if ($it) { $c['release'] = (string)$it['release']; $c['equipo'] = (string)$it['equipo']; }
        }
        if (empty($c['item_id']) && array_key_exists('release', $d)) {
            $rel = (string)$d['release'];
            $c['release'] = isset(Releases::releases()[$rel]) ? $rel : '';
        }
        if (array_key_exists('sprint', $d)) {
            $sp = (string)$d['sprint'];
            if ($sp !== '' && !isset(self::sprints()[$sp])) throw new RuntimeException('Ese sprint no existe.');
            $c['sprint'] = $sp;
        }
        if (array_key_exists('estimacion', $d)) {
            $est = strtoupper(trim((string)$d['estimacion']));
            $c['estimacion'] = in_array($est, self::opcionesEstimacion(), true) || $est === '?' ? $est : '';
        }
        if (array_key_exists('prioridad', $d)) $c['prioridad'] = isset(self::PRIORIDADES[$d['prioridad']]) ? (string)$d['prioridad'] : 'media';
        if (array_key_exists('estado', $d)) $c['estado'] = isset(self::ESTADOS[$d['estado']]) ? (string)$d['estado'] : 'por_hacer';
        if (array_key_exists('responsable', $d)) $c['responsable'] = max(0, (int)$d['responsable']);
        if (array_key_exists('tareas', $d) || !empty($d['tareas_creadas'])) {
            $ts = array_merge(array_map('intval', (array)($d['tareas'] ?? ($antes['tareas'] ?? []))), array_map('intval', (array)($d['tareas_creadas'] ?? [])));
            $c['tareas'] = array_values(array_unique(array_filter($ts, fn($x) => $x > 0)));
        }
        // Archivos: los que se suben se suman; los que se quitan se borran del disco
        $adj = (array)($antes['adjuntos'] ?? []);
        $quitar = array_flip(array_map('strval', (array)($d['quitar_adjuntos'] ?? [])));
        if ($quitar) {
            $fuera = array_values(array_filter($adj, fn($a) => isset($quitar[(string)($a['ruta'] ?? '')])));
            $adj = array_values(array_filter($adj, fn($a) => !isset($quitar[(string)($a['ruta'] ?? '')])));
            if (function_exists('borrarAdjuntos')) borrarAdjuntos($fuera);
        }
        foreach ((array)($d['adjuntos_nuevos'] ?? []) as $a) $adj[] = $a;
        if ($quitar || !empty($d['adjuntos_nuevos'])) $c['adjuntos'] = array_slice($adj, 0, 30);

        // Cuándo se terminó (para el burndown y la velocidad)
        $estAntes = (string)($antes['estado'] ?? 'por_hacer');
        $estAhora = (string)($c['estado'] ?? $estAntes);
        if ($estAhora === 'hecho' && $estAntes !== 'hecho') $c['hecho_en'] = date('Y-m-d');
        if ($estAhora !== 'hecho' && $estAntes === 'hecho') $c['hecho_en'] = '';

        if (!$antes) {
            $n = 0;
            foreach ($repo->all() as $h) if (preg_match('/^HU-(\d+)$/', (string)($h['codigo'] ?? ''), $m)) $n = max($n, (int)$m[1]);
            $c['codigo'] = 'HU-' . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
            $c['orden'] = self::ordenFinal((string)($c['sprint'] ?? ''), $pid);
            $c += ['estado' => 'por_hacer', 'prioridad' => 'media', 'sprint' => '', 'estimacion' => '', 'tareas' => [], 'adjuntos' => []];
            $c['historial'] = [self::evento($quien, 'Creó la historia' . ($c['sprint'] !== '' ? ' en el ' . $c['sprint'] : ' en el backlog'))];
            $c['creado'] = date('Y-m-d H:i');
            $c['creado_por'] = $quien;
            $h = $repo->insert($c);
            return self::historia((int)$h['id']);
        }
        $cambios = [];
        if (isset($c['sprint']) && $c['sprint'] !== (string)($antes['sprint'] ?? '')) {
            $cambios[] = $c['sprint'] === '' ? 'La devolvió al backlog' : 'La pasó al ' . $c['sprint'];
            $c['orden'] = self::ordenFinal($c['sprint'], $pid);
        }
        if (isset($c['estado']) && $c['estado'] !== $estAntes) $cambios[] = 'Estado: ' . self::ESTADOS[$estAntes][0] . ' → ' . self::ESTADOS[$c['estado']][0];
        if (isset($c['estimacion']) && $c['estimacion'] !== (string)($antes['estimacion'] ?? '')) $cambios[] = 'Estimación: ' . ((string)($antes['estimacion'] ?? '') ?: 'sin estimar') . ' → ' . ($c['estimacion'] ?: 'sin estimar');
        if ($cambios || array_diff_key($c, array_flip(['orden']))) {
            $hist = (array)($antes['historial'] ?? []);
            $hist[] = self::evento($quien, $cambios ? implode(' · ', $cambios) : 'Editó la historia');
            $c['historial'] = array_slice($hist, -60);
        }
        $c['actualizado'] = date('Y-m-d H:i');
        $repo->update($id, $c);
        return self::historia($id);
    }

    /** Al final de su lista (el sprint o el backlog del proyecto). */
    private static function ordenFinal(string $sprint, int $pid): float
    {
        $max = 0.0;
        foreach (self::repo()->all() as $h) if ((string)($h['sprint'] ?? '') === $sprint && (int)($h['proyecto_id'] ?? 0) === $pid) $max = max($max, (float)($h['orden'] ?? 0));
        return $max + 1;
    }

    /**
     * Mover (arrastrar en el backlog o en el tablero): a otro sprint o al backlog,
     * a otra posición (entre la de arriba y la de abajo) o a otro estado.
     */
    public static function mover(int $id, array $d, int $quien): array
    {
        $h = self::repo()->find($id);
        if (!$h) throw new RuntimeException('Esa historia ya no existe.');
        $c = [];
        if (array_key_exists('sprint', $d)) $c['sprint'] = (string)$d['sprint'];
        if (array_key_exists('estado', $d)) $c['estado'] = (string)$d['estado'];
        $out = $c ? self::guardar($c, $id, $quien) : self::historia($id);
        // La posición: entre sus vecinas (si no llega, al final de su lista)
        if (array_key_exists('antes_de', $d) || array_key_exists('despues_de', $d)) {
            $arriba = (int)($d['despues_de'] ?? 0) ? self::repo()->find((int)$d['despues_de']) : null;
            $abajo = (int)($d['antes_de'] ?? 0) ? self::repo()->find((int)$d['antes_de']) : null;
            $o = match (true) {
                $arriba && $abajo => ((float)$arriba['orden'] + (float)$abajo['orden']) / 2,
                (bool)$arriba     => (float)$arriba['orden'] + 1,
                (bool)$abajo      => (float)$abajo['orden'] - 1,
                default           => (float)($out['orden'] ?? 0),
            };
            self::repo()->update($id, ['orden' => $o]);
            $out = self::historia($id);
        }
        return $out;
    }

    public static function borrar(int $id): void
    {
        $h = self::repo()->find($id);
        if (!$h) return;
        if (function_exists('borrarAdjuntos')) borrarAdjuntos((array)($h['adjuntos'] ?? []));
        self::repo()->delete($id);
    }

    /**
     * Lo que no se terminó en un sprint que ya pasó, al sprint en curso (como al
     * cerrar un sprint en Jira). Queda dicho en la historia y cuántas veces pasó.
     */
    public static function arrastrar(?string $hoy = null): int
    {
        if (!self::conf()['arrastrar']) return 0;
        $hoy ??= date('Y-m-d');
        $sp = self::sprints();
        $act = self::actual($hoy);
        if ($act === '' || ($sp[$act]['inicio'] ?? '') > $hoy) return 0;
        $n = 0;
        foreach (self::repo()->all() as $h) {
            $s = (string)($h['sprint'] ?? '');
            if ($s === '' || ($h['estado'] ?? '') === 'hecho' || !isset($sp[$s]) || $sp[$s]['fin'] >= $hoy) continue;
            $hist = (array)($h['historial'] ?? []);
            $hist[] = self::evento(0, 'No se terminó en el ' . $s . ': pasó sola al ' . $act);
            self::repo()->update((int)$h['id'], ['sprint' => $act, 'arrastres' => (int)($h['arrastres'] ?? 0) + 1, 'historial' => array_slice($hist, -60)]);
            $n++;
        }
        return $n;
    }

    /** Velocidad: los puntos que el proyecto terminó en cada uno de sus últimos sprints ya cerrados, y el promedio. */
    public static function velocidad(int $proyectoId, int $cuantos = 3, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $pasados = array_filter(self::sprints(), fn($s) => $s['fin'] < $hoy);
        $pasados = array_slice($pasados, -$cuantos, null, true);
        $por = array_fill_keys(array_keys($pasados), 0);
        foreach (self::historias(['proyecto_id' => $proyectoId]) as $h) {
            if ($h['estado'] !== 'hecho' || $h['hecho_en'] === '') continue;
            foreach ($pasados as $cod => $s) if ($h['hecho_en'] >= $s['inicio'] && $h['hecho_en'] <= $s['fin']) $por[$cod] += $h['puntos'];
        }
        $con = array_filter($por);
        return ['sprints' => $por, 'promedio' => $con ? (int)round(array_sum($con) / count($con)) : null];
    }

    /**
     * Burndown de un sprint: cuántos puntos quedaban cada día hábil (los de las
     * historias del sprint menos los que se fueron terminando) y la línea ideal.
     */
    public static function burndown(string $sprint, array $historias, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $s = self::sprints()[$sprint] ?? null;
        if (!$s) return ['dias' => [], 'total' => 0];
        $total = array_sum(array_column($historias, 'puntos'));
        $dias = [];
        for ($f = $s['inicio']; $f <= $s['fin']; $f = date('Y-m-d', strtotime($f . ' +1 day'))) {
            if (class_exists('Feriados') && !Feriados::esHabil($f)) continue;
            $hechos = 0;
            foreach ($historias as $h) if ($h['estado'] === 'hecho' && $h['hecho_en'] !== '' && $h['hecho_en'] <= $f) $hechos += $h['puntos'];
            $dias[$f] = $f <= $hoy ? $total - $hechos : null;
        }
        return ['dias' => $dias, 'total' => $total];
    }
}
