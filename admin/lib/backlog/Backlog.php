<?php
/**
 * Backlog estilo Jira de cada proyecto (pestaña «Sprints» de proyecto.php, con
 * lib/backlog/backlog_ui.php y assets/backlog.js). Lo arman su PO, su Scrum Master y el
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

    /**
     * Inicio del S01, semanas por sprint (1 o 2), escala de estimación, capacidad por equipo y si
     * se arrastra lo no terminado. Si el proyecto en pantalla (usarProyecto) está en una
     * planificación aparte, el inicio y las semanas son los de ella; 'plan' dice cuál (o null).
     */
    public static function conf(): array
    {
        $c = self::confGeneral();
        $plan = self::planDe();
        if ($plan) { $c['inicio'] = $plan['inicio']; $c['semanas'] = $plan['semanas']; }
        return $c + ['plan' => $plan];
    }

    /** Lo de Releases → Ajustes → Sprints: el calendario general (el de los releases). */
    public static function confGeneral(): array
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

    /* ============================== Planificaciones aparte ============================== */

    /** El proyecto en pantalla: si está en una planificación aparte, el calendario es el de ella. */
    private static int $ctx = 0;

    /** Tope de sprints de una planificación aparte (un año de sprints de una semana). */
    public const MAX_SPRINTS_PLAN = 52;

    /** Colores de los releases de una planificación (en orden). */
    private const COLORES_REL_PLAN = ['#2563EB', '#059669', '#D97706', '#7C3AED', '#DC2626', '#0891B2'];

    /** Los releases de una planificación, limpios: [['codigo', 'nombre', 'desde', 'hasta']] (desde/hasta: número de sprint). */
    private static function releasesValidos(array $lista): array
    {
        $out = [];
        $usados = [];
        foreach ($lista as $r) {
            $cod = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string)($r['codigo'] ?? '')));
            $desde = max(1, (int)($r['desde'] ?? 1));
            $hasta = max($desde, (int)($r['hasta'] ?? $desde));
            if ($cod === '' || isset($usados[$cod])) continue;
            $usados[$cod] = true;
            $out[] = ['codigo' => mb_substr($cod, 0, 12), 'nombre' => mb_substr(trim((string)($r['nombre'] ?? '')), 0, 160), 'desde' => $desde, 'hasta' => $hasta];
        }
        return $out;
    }

    /**
     * Los releases escritos en la ventana, uno por línea: «R1 | MVP del CRM | 1-2»
     * (código, nombre y los sprints que abarca; «2» solo es el sprint 2, «S01-S02» también vale).
     */
    public static function releasesDeTexto(string $txt): array
    {
        $out = [];
        foreach (preg_split('/\R/', $txt) ?: [] as $i => $linea) {
            $linea = trim($linea);
            if ($linea === '') continue;
            $partes = array_map('trim', explode('|', $linea));
            if (count($partes) < 3) throw new RuntimeException('Línea ' . ($i + 1) . ' de los releases: va «código | nombre | sprints», por ejemplo «R1 | MVP del CRM | 1-2».');
            if (!preg_match('/^S?0*(\d+)\s*(?:-\s*S?0*(\d+))?$/i', $partes[2], $m)) throw new RuntimeException('Línea ' . ($i + 1) . ' de los releases: los sprints van como «1-2» o «3».');
            $out[] = ['codigo' => $partes[0], 'nombre' => $partes[1], 'desde' => (int)$m[1], 'hasta' => (int)($m[2] ?? $m[1])];
        }
        return self::releasesValidos($out);
    }

    /** Los releases de una planificación como los generales: [código => ['codigo', 'nombre', 'inicio', 'fin', 'color']]. */
    public static function releasesPlan(?array $plan): array
    {
        if (!$plan || empty($plan['releases'])) return [];
        $sps = array_values(self::calendario($plan));
        $out = [];
        foreach ($plan['releases'] as $i => $r) {
            $a = $sps[$r['desde'] - 1] ?? null;
            $b = $sps[min($r['hasta'], count($sps)) - 1] ?? null;
            $out[$r['codigo']] = ['codigo' => $r['codigo'], 'nombre' => $r['nombre'], 'inicio' => $a['inicio'] ?? '', 'fin' => $b['fin'] ?? '',
                                  'color' => self::COLORES_REL_PLAN[$i % count(self::COLORES_REL_PLAN)], 'desde' => $r['desde'], 'hasta' => $r['hasta']];
        }
        return $out;
    }

    /**
     * Las fechas de una planificación aparte, por días hábiles: cada sprint tiene los
     * suyos completos (5 por semana); si cae un feriado o un fin de semana, se corre al
     * siguiente día hábil, y el inicio también. Los sprints quedan pegados: cada uno va
     * hasta la víspera del siguiente. $cantidad = 0: los que caben hasta doce semanas
     * desde hoy. [S01 => [codigo, n, inicio, fin, ultimo (último día hábil), habiles, feriados => [fecha => nombre], releases => []]]
     */
    public static function calcularPlan(string $inicio, int $semanas, int $cantidad = 0, string $modo = 'habiles', array $releases = []): array
    {
        if ($modo === 'semanas') return self::conReleases(self::calcularPlanSemanas($inicio, $semanas, $cantidad), $releases);
        $ini = Releases::fecha($inicio);
        if ($ini === '') return [];
        $habil = fn(string $f) => class_exists('Feriados') ? Feriados::esHabil($f) : (int)date('N', strtotime($f)) <= 5;
        $mas1 = fn(string $f) => date('Y-m-d', strtotime($f . ' +1 day'));
        $sigHabil = function (string $f) use ($habil, $mas1): string { for ($i = 0; $i < 60 && !$habil($f); $i++) $f = $mas1($f); return $f; };
        $dias = (in_array($semanas, [1, 2], true) ? $semanas : 2) * 5;
        $cantidad = max(0, min(self::MAX_SPRINTS_PLAN, $cantidad));
        $hasta = date('Y-m-d', strtotime('+84 days'));
        $mapaFer = class_exists('Feriados') ? Feriados::mapa() : [];
        $out = [];
        $ini = $sigHabil($ini);
        for ($n = 1; $n <= ($cantidad ?: 260); $n++) {
            if (!$cantidad && $ini > $hasta) break;
            $f = $ini; $cuenta = 1; $fer = [];
            for ($i = 0; $cuenta < $dias && $i < 400; $i++) {
                $f = $mas1($f);
                if ($habil($f)) $cuenta++;
                elseif (isset($mapaFer[$f]) && (int)date('N', strtotime($f)) <= 5) $fer[$f] = (string)$mapaFer[$f];
            }
            $sig = $sigHabil($mas1($f));
            // Los feriados entre el último día hábil y el siguiente sprint también se cuentan aquí
            for ($g = $mas1($f); $g < $sig; $g = $mas1($g)) if (isset($mapaFer[$g]) && (int)date('N', strtotime($g)) <= 5) $fer[$g] = (string)$mapaFer[$g];
            $cod = 'S' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
            $out[$cod] = ['codigo' => $cod, 'n' => $n, 'inicio' => $ini, 'fin' => date('Y-m-d', strtotime($sig . ' -1 day')), 'ultimo' => $f,
                          'habiles' => $dias, 'feriados' => $fer, 'releases' => []];
            $ini = $sig;
        }
        return self::conReleases($out, $releases);
    }

    /**
     * Por semanas de calendario (como un cronograma «Semana 1, Semana 2…»): cada sprint
     * va de lunes a domingo; el primero arranca el día de inicio (o el siguiente día
     * hábil, si es feriado o fin de semana) y termina el domingo de su última semana.
     * Los feriados no lo alargan: se descuentan de sus días hábiles.
     */
    private static function calcularPlanSemanas(string $inicio, int $semanas, int $cantidad): array
    {
        $ini = Releases::fecha($inicio);
        if ($ini === '') return [];
        $habil = fn(string $f) => class_exists('Feriados') ? Feriados::esHabil($f) : (int)date('N', strtotime($f)) <= 5;
        $mas1 = fn(string $f) => date('Y-m-d', strtotime($f . ' +1 day'));
        $sigHabil = function (string $f) use ($habil, $mas1): string { for ($i = 0; $i < 60 && !$habil($f); $i++) $f = $mas1($f); return $f; };
        $semanas = in_array($semanas, [1, 2], true) ? $semanas : 2;
        $cantidad = max(0, min(self::MAX_SPRINTS_PLAN, $cantidad));
        $hasta = date('Y-m-d', strtotime('+84 days'));
        $mapaFer = class_exists('Feriados') ? Feriados::mapa() : [];
        $lunes = date('Y-m-d', strtotime($ini . ' -' . ((int)date('N', strtotime($ini)) - 1) . ' days'));
        $out = [];
        for ($n = 1; $n <= ($cantidad ?: 260); $n++) {
            if (!$cantidad && $lunes > $hasta) break;
            $fin = date('Y-m-d', strtotime($lunes . ' +' . ($semanas * 7 - 1) . ' days'));
            $desde = $sigHabil(max($ini, $lunes));
            $ult = $fin;
            for ($i = 0; $i < 14 && !$habil($ult); $i++) $ult = date('Y-m-d', strtotime($ult . ' -1 day'));
            $fer = [];
            for ($g = max($ini, $lunes); $g <= $fin; $g = $mas1($g)) if (isset($mapaFer[$g]) && (int)date('N', strtotime($g)) <= 5) $fer[$g] = (string)$mapaFer[$g];
            $cod = 'S' . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
            $out[$cod] = ['codigo' => $cod, 'n' => $n, 'inicio' => $desde, 'fin' => $fin, 'ultimo' => $ult,
                          'habiles' => Releases::habiles($desde, $fin), 'feriados' => $fer, 'releases' => []];
            $lunes = $mas1($fin);
        }
        return $out;
    }

    /** A cada sprint, los releases de la planificación que lo abarcan. */
    private static function conReleases(array $sps, array $releases): array
    {
        if (!$releases) return $sps;
        foreach ($sps as $cod => $s) {
            foreach ($releases as $r) if ($s['n'] >= (int)$r['desde'] && $s['n'] <= (int)$r['hasta']) $sps[$cod]['releases'][] = (string)$r['codigo'];
        }
        return $sps;
    }

    /** Lo llama cada pantalla o acción de un proyecto antes de mirar sus sprints. */
    public static function usarProyecto(int $pid): void
    {
        self::$ctx = max(0, $pid);
    }

    /**
     * Planificaciones aparte: proyectos que no siguen los releases (y su calendario de
     * sprints) llevan el suyo, con su propio inicio y duración. Una vale para uno o
     * varios proyectos; un proyecto está en una sola. Se guardan en la configuración
     * de Releases. [id => ['id', 'nombre', 'inicio', 'semanas', 'proyectos']]
     */
    public static function planificaciones(): array
    {
        $out = [];
        foreach ((array)(((array)(Config::get('releases') ?? []))['planificaciones'] ?? []) as $p) {
            $id = (string)($p['id'] ?? '');
            $ini = Releases::fecha((string)($p['inicio'] ?? ''));
            if ($id === '' || $ini === '') continue;
            $out[$id] = ['id' => $id, 'nombre' => trim((string)($p['nombre'] ?? '')) ?: 'Planificación', 'inicio' => $ini,
                         'semanas' => in_array((int)($p['semanas'] ?? 2), [1, 2], true) ? (int)$p['semanas'] : 2,
                         // Cuántos sprints tiene (0 = sin tope: los que caben hasta doce semanas desde hoy)
                         'cantidad' => max(0, min(self::MAX_SPRINTS_PLAN, (int)($p['cantidad'] ?? 0))),
                         // 'habiles': cada sprint con sus días hábiles completos · 'semanas': de lunes a domingo
                         'modo' => ($p['modo'] ?? '') === 'semanas' ? 'semanas' : 'habiles',
                         'releases' => self::releasesValidos((array)($p['releases'] ?? [])),
                         'proyectos' => array_values(array_unique(array_filter(array_map('intval', (array)($p['proyectos'] ?? [])))))];
        }
        uasort($out, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
        return $out;
    }

    /** La planificación aparte de un proyecto, o null si sigue el calendario general (-1: el proyecto en pantalla). */
    public static function planDe(int $pid = -1): ?array
    {
        $pid = $pid < 0 ? self::$ctx : $pid;
        if ($pid <= 0) return null;
        foreach (self::planificaciones() as $p) if (in_array($pid, $p['proyectos'], true)) return $p;
        return null;
    }

    /**
     * Crea o cambia una planificación. Un proyecto que estaba en otra sale de ahí.
     * Las historias de los proyectos a los que les cambia el calendario pasan al
     * sprint del calendario nuevo que cae en las fechas de su sprint de antes.
     */
    public static function guardarPlanificacion(array $d, int $quien = 0): array
    {
        $nombre = mb_substr(trim((string)($d['nombre'] ?? '')), 0, 80);
        if ($nombre === '') throw new RuntimeException('Ponle un nombre a la planificación (el del proyecto o del cliente, por ejemplo).');
        $ini = Releases::fecha((string)($d['inicio'] ?? ''));
        if ($ini === '') throw new RuntimeException('Elige la fecha en que empieza su primer sprint.');
        $sem = (int)($d['semanas'] ?? 2);
        if (!in_array($sem, [1, 2], true)) throw new RuntimeException('Un sprint dura una o dos semanas, no más.');
        $cant = (int)($d['cantidad'] ?? 0);
        if ($cant < 0 || $cant > self::MAX_SPRINTS_PLAN) throw new RuntimeException('Pon entre 1 y ' . self::MAX_SPRINTS_PLAN . ' sprints (o déjalo vacío para que no tenga tope).');
        $modo = ($d['modo'] ?? '') === 'semanas' ? 'semanas' : 'habiles';
        $rels = isset($d['releases_txt']) ? self::releasesDeTexto((string)$d['releases_txt']) : self::releasesValidos((array)($d['releases'] ?? []));
        foreach ($rels as $r) if ($cant > 0 && $r['hasta'] > $cant) throw new RuntimeException('El release ' . $r['codigo'] . ' llega al sprint ' . $r['hasta'] . ', pero la planificación tiene ' . $cant . '.');
        $existen = [];
        foreach ((new ProyectoRepo())->todos() as $pr) $existen[(int)$pr['id']] = true;
        $proys = array_values(array_unique(array_filter(array_map('intval', (array)($d['proyectos'] ?? [])), fn($i) => isset($existen[$i]))));
        if (!$proys) throw new RuntimeException('Elige al menos un proyecto que lleve esta planificación.');
        $lista = self::planificaciones();
        $id = (string)($d['id'] ?? '');
        if ($id !== '' && !isset($lista[$id])) throw new RuntimeException('Esa planificación ya no existe.');
        $tocados = array_merge($proys, $id !== '' ? $lista[$id]['proyectos'] : []);
        if ($id === '') $id = 'p' . bin2hex(random_bytes(4));
        $viejos = self::calendariosDe($tocados);
        foreach ($lista as $k => $pl) if ($k !== $id) $lista[$k]['proyectos'] = array_values(array_diff($pl['proyectos'], $proys));
        $lista[$id] = ['id' => $id, 'nombre' => $nombre, 'inicio' => $ini, 'semanas' => $sem, 'cantidad' => $cant, 'modo' => $modo, 'releases' => $rels, 'proyectos' => $proys];
        Releases::guardarConf(['planificaciones' => array_values($lista)]);
        self::reubicar($viejos, $quien);
        return $lista[$id];
    }

    /** Quita una planificación: sus proyectos vuelven al calendario general. */
    public static function borrarPlanificacion(string $id, int $quien = 0): void
    {
        $lista = self::planificaciones();
        if (!isset($lista[$id])) throw new RuntimeException('Esa planificación ya no existe.');
        $viejos = self::calendariosDe($lista[$id]['proyectos']);
        unset($lista[$id]);
        Releases::guardarConf(['planificaciones' => array_values($lista)]);
        self::reubicar($viejos, $quien);
    }

    /** El calendario que lleva cada uno de esos proyectos ahora. [pid => sprints] */
    private static function calendariosDe(array $pids): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $pids)) as $pid) if ($pid > 0) $out[$pid] = self::calendario(self::planDe($pid));
        return $out;
    }

    /** Las historias con sprint de esos proyectos, al sprint del calendario nuevo que cae en las fechas del de antes (o al backlog). */
    private static function reubicar(array $viejos, int $quien): int
    {
        $nuevos = self::calendariosDe(array_keys($viejos));
        $n = 0;
        foreach (self::repo()->all() as $h) {
            $pid = (int)($h['proyecto_id'] ?? 0);
            $s = (string)($h['sprint'] ?? '');
            if ($s === '' || !isset($viejos[$pid])) continue;
            // Por las fechas de su sprint; si ese sprint es el que va hoy, al que va hoy en el calendario nuevo
            $spV = $viejos[$pid][$s] ?? null;
            $hoyR = date('Y-m-d');
            $ref = $spV ? ($spV['inicio'] <= $hoyR && $spV['fin'] >= $hoyR ? $hoyR : $spV['inicio']) : '';
            $nuevo = '';
            if ($ref !== '') foreach ($nuevos[$pid] as $cod => $sp) if ($sp['inicio'] <= $ref && $sp['fin'] >= $ref) { $nuevo = (string)$cod; break; }
            // Lo terminado antes de que empiece el calendario nuevo queda en su primer sprint
            if ($nuevo === '' && ($h['estado'] ?? '') === 'hecho' && $nuevos[$pid]) $nuevo = (string)array_key_first($nuevos[$pid]);
            if ($nuevo === $s) continue;
            $hist = (array)($h['historial'] ?? []);
            $hist[] = self::evento($quien, 'Cambió el calendario de sprints del proyecto: ' . ($nuevo !== '' ? 'pasó del ' . $s . ' al ' . $nuevo : 'del ' . $s . ' volvió al backlog'));
            self::repo()->update((int)$h['id'], ['sprint' => $nuevo, 'historial' => array_slice($hist, -60)]);
            $n++;
        }
        return $n;
    }

    /* ============================== Sprints ============================== */

    /**
     * El calendario de sprints del proyecto en pantalla (usarProyecto): el general o el
     * de su planificación aparte. [S01 => [codigo, n, inicio, fin, releases]]
     */
    public static function sprints(): array
    {
        return self::calendario(self::planDe());
    }

    /**
     * Un calendario: el general, desde el inicio del S01 hasta el fin del último release
     * (y al menos doce semanas desde hoy), cada sprint con los releases de sus fechas;
     * o el de una planificación aparte, que no va con los releases.
     */
    public static function calendario(?array $plan): array
    {
        static $cache = [];
        $c = $plan ?: self::confGeneral();
        $k = ($plan['id'] ?? '') . '|' . $c['inicio'] . '|' . $c['semanas'] . '|' . (int)($plan['cantidad'] ?? 0) . '|' . ($plan['modo'] ?? '') . '|' . json_encode($plan['releases'] ?? []);
        if (isset($cache[$k])) return $cache[$k];
        // Planificación aparte: por días hábiles o por semanas, con su cantidad de sprints y sus releases
        if ($plan) return $cache[$k] = self::calcularPlan($plan['inicio'], (int)$plan['semanas'], (int)($plan['cantidad'] ?? 0), (string)($plan['modo'] ?? 'habiles'), (array)($plan['releases'] ?? []));
        $hasta = date('Y-m-d', strtotime('+84 days'));
        $rels = $plan ? [] : Releases::releases();
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
        return $cache[$k] = $out;
    }

    /** El sprint de hoy (o el próximo, o el último). */
    public static function actual(?string $hoy = null): string
    {
        return self::actualEn(self::sprints(), $hoy);
    }

    /** El sprint de hoy (o el próximo, o el último) en un calendario. */
    public static function actualEn(array $sp, ?string $hoy = null): string
    {
        $hoy ??= date('Y-m-d');
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
               'responsable' => 0, 'tareas' => [], 'adjuntos' => [], 'orden' => 0, 'historial' => [], 'hecho_en' => '', 'arrastres' => 0, 'escenarios' => [], 'diagrama' => []];
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
        // «Lista para sprint» (DoR): las condiciones que se marcan a mano en historia.php (las automáticas se calculan)
        if (array_key_exists('dor', $d)) $c['dor'] = array_values(array_unique(array_filter(array_map(fn($k) => preg_replace('/[^a-z0-9_]/', '', strtolower((string)$k)), (array)$d['dor']))));
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
            if ($sp !== '' && !isset(self::calendario(self::planDe($pid))[$sp])) throw new RuntimeException('Ese sprint no existe.');
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
        // El diagrama de la historia como imagen (lo guarda la pizarra; aquí solo se ve): uploads/hu_dg_*.png
        if (array_key_exists('diagrama', $d)) {
            // Su texto (de donde la pizarra arma el diagrama: la historia lo muestra vivo, solo para ver)
            // y, si viene, su imagen. Lo que no venga se conserva de antes.
            $dg = (array)$d['diagrama'];
            $prev = (array)($antes['diagrama'] ?? []);
            $ruta = array_key_exists('ruta', $dg) ? (string)$dg['ruta'] : (string)($prev['ruta'] ?? '');
            if (!preg_match('#^uploads/hu_dg_[A-Za-z0-9_.-]+\.png$#', $ruta) || !is_file(__DIR__ . '/../../' . $ruta)) $ruta = '';
            $texto = array_key_exists('texto', $dg) ? mb_substr(str_replace("\r", '', (string)$dg['texto']), 0, 20000) : (string)($prev['texto'] ?? '');
            $c['diagrama'] = ($ruta !== '' || trim($texto) !== '')
                ? ['texto' => $texto, 'ruta' => $ruta, 'fecha' => date('Y-m-d H:i'), 'por' => $quien, 'origen' => mb_substr((string)($dg['origen'] ?? ($prev['origen'] ?? '')), 0, 60)] : [];
            // La imagen anterior ya no sirve
            $vieja = (string)($antes['diagrama']['ruta'] ?? '');
            if ($vieja !== '' && $vieja !== ($c['diagrama']['ruta'] ?? '') && preg_match('#^uploads/hu_dg_[A-Za-z0-9_.-]+\.png$#', $vieja)) @unlink(__DIR__ . '/../../' . $vieja);
        }
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
            // El código del documento (R2-D01-HU01) si viene de una importación y no está usado en el proyecto; si no, HU-001…
            $codDoc = strtoupper(trim((string)($d['codigo'] ?? '')));
            $usado = $codDoc !== '' && (bool)array_filter($repo->all(), fn($x) => (int)($x['proyecto_id'] ?? 0) === $pid && strtoupper((string)($x['codigo'] ?? '')) === $codDoc);
            $n = 0;
            foreach ($repo->all() as $h) if (preg_match('/^HU-(\d+)$/', (string)($h['codigo'] ?? ''), $m)) $n = max($n, (int)$m[1]);
            $c['codigo'] = preg_match('/^[A-Z0-9][A-Z0-9-]{2,39}$/', $codDoc) && !$usado ? $codDoc : 'HU-' . str_pad((string)($n + 1), 3, '0', STR_PAD_LEFT);
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
        if (!self::confGeneral()['arrastrar']) return 0;
        $hoy ??= date('Y-m-d');
        $n = 0;
        $cals = [];   // cada historia con el calendario de su proyecto (el general o el de su planificación)
        foreach (self::repo()->all() as $h) {
            $s = (string)($h['sprint'] ?? '');
            if ($s === '' || ($h['estado'] ?? '') === 'hecho') continue;
            $plan = self::planDe((int)($h['proyecto_id'] ?? 0));
            $kc = $plan['id'] ?? '';
            if (!isset($cals[$kc])) { $spC = self::calendario($plan); $cals[$kc] = [$spC, self::actualEn($spC, $hoy)]; }
            [$sp, $act] = $cals[$kc];
            if ($act === '' || ($sp[$act]['inicio'] ?? '') > $hoy || ($sp[$act]['fin'] ?? '') < $hoy) continue;
            if ($s === $act || !isset($sp[$s]) || $sp[$s]['fin'] >= $hoy) continue;
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
        $pasados = array_filter(self::calendario(self::planDe($proyectoId)), fn($s) => $s['fin'] < $hoy);
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

    /* ========= El sprint del proyecto (sprint.php): objetivo, capacidad, retrospectiva y «Lista para sprint» ========= */

    /**
     * La Definición de «Lista» (Definition of Ready) que trae cada proyecto mientras
     * no la cambie. auto: la revisa el panel con los datos de la historia; '' = se
     * marca a mano en la pantalla de la historia.
     */
    public const DOR_DEFECTO = [
        ['clave' => 'historia',     'texto' => 'Tiene «Como… quiero… para…»',             'auto' => 'historia'],
        ['clave' => 'criterios',    'texto' => 'Tiene criterios Dado · Cuando · Entonces', 'auto' => 'criterios'],
        ['clave' => 'estimada',     'texto' => 'Está estimada',                            'auto' => 'estimada'],
        ['clave' => 'dependencias', 'texto' => 'No tiene dependencias que la bloqueen',    'auto' => ''],
        ['clave' => 'refinada',     'texto' => 'El equipo la conversó y la entiende',      'auto' => ''],
        ['clave' => 'po',           'texto' => 'El PO la aprobó',                          'auto' => ''],
    ];
    /** Lo que el panel sabe revisar solo (el resto se marca a mano). */
    public const DOR_AUTO = ['historia' => 'Revisa que tenga Como, Quiero y Para', 'criterios' => 'Revisa que un escenario tenga Dado, Cuando y Entonces', 'estimada' => 'Revisa que tenga estimación'];
    /** La dedicación de cada persona al proyecto en el sprint (hay gente a medio tiempo). */
    public const DEDICACIONES = [100, 80, 75, 60, 50, 40, 25, 20, 10];
    /** Horas de un día completo, para pasar los días a horas. */
    public const HORAS_DIA = 8;
    /** ¿Se cumplió el objetivo del sprint? [nombre, estado de la colorimetría] */
    public const LOGRADO = ['si' => ['Sí', 'ok'], 'parcial' => ['En parte', 'warn'], 'no' => ['No', 'crit']];

    /** Los registros de 'bl_sprints' de cada proyecto (una vez por petición): [sprint => registro]; '' es el del proyecto (su DoR). */
    private static array $regs = [];
    private static array $dorCache = [];

    private static function repoSp(): JsonStore { return new JsonStore('bl_sprints'); }

    private static function registro(int $pid, string $sprint): ?array
    {
        if (!isset(self::$regs[$pid])) {
            self::$regs[$pid] = [];
            foreach (self::repoSp()->where('proyecto_id', $pid) as $r) self::$regs[$pid][(string)($r['sprint'] ?? '')] ??= $r;
        }
        return self::$regs[$pid][$sprint] ?? null;
    }

    private static function guardarRegistro(int $pid, string $sprint, array $c, int $quien, string $que): void
    {
        $r = self::registro($pid, $sprint);
        $hist = (array)($r['historial'] ?? []);
        $hist[] = self::evento($quien, $que);
        $c += ['actualizado' => date('Y-m-d H:i'), 'actualizado_por' => $quien, 'historial' => array_slice($hist, -40)];
        if ($r) self::repoSp()->update((int)$r['id'], $c);
        else self::repoSp()->insert(['proyecto_id' => $pid, 'sprint' => $sprint] + $c);
        unset(self::$regs[$pid], self::$dorCache[$pid]);
    }

    /** El número del sprint (S22 → 22), para compararlos. */
    private static function nSprint(string $cod): int { return (int)substr($cod, 1); }

    /**
     * La ficha del sprint en el proyecto: el objetivo (Sprint Goal) y si se logró,
     * la capacidad de la gente y la retrospectiva (bien, mal y acciones).
     */
    public static function ficha(int $pid, string $sprint): array
    {
        return (self::registro($pid, $sprint) ?? []) + ['id' => 0, 'proyecto_id' => $pid, 'sprint' => $sprint, 'objetivo' => '', 'logrado' => '',
            'pts_dia' => null, 'gente' => [], 'bien' => [], 'mal' => [], 'acciones' => [], 'retro_en' => '', 'historial' => []];
    }

    /** La ficha más reciente de un sprint anterior que ya tenga la gente anotada (de ahí se hereda la dedicación). */
    private static function fichaAnterior(int $pid, string $sprint): ?array
    {
        self::registro($pid, $sprint);
        $mejor = null;
        foreach (self::$regs[$pid] ?? [] as $cod => $r) {
            if ($cod === '' || self::nSprint((string)$cod) >= self::nSprint($sprint) || empty($r['gente'])) continue;
            if (!$mejor || self::nSprint((string)$cod) > self::nSprint((string)$mejor['sprint'])) $mejor = $r;
        }
        return $mejor;
    }

    /**
     * Guarda lo que se planifica: el objetivo, los puntos por día y, por persona,
     * si cuenta, los días que falta, su dedicación (%) y sus puntos a mano ('' = calculados).
     */
    public static function guardarPlan(int $pid, string $sprint, array $d, int $quien): array
    {
        if (!isset(self::sprints()[$sprint])) throw new RuntimeException('Ese sprint no existe.');
        $num = fn($v, float $max) => max(0.0, min($max, (float)str_replace(',', '.', trim((string)$v))));
        $antes = self::ficha($pid, $sprint);
        $c = [];
        if (array_key_exists('objetivo', $d)) $c['objetivo'] = mb_substr(trim((string)preg_replace('/[ \t]+/', ' ', (string)$d['objetivo'])), 0, 600);
        if (array_key_exists('pts_dia', $d)) $c['pts_dia'] = round($num($d['pts_dia'], 20), 2);
        if (isset($d['gente']) && is_array($d['gente'])) {
            $g = [];
            foreach (array_slice($d['gente'], 0, 80, true) as $mid => $x) {
                if ((int)$mid <= 0 || !is_array($x)) continue;
                $pts = trim((string)($x['puntos'] ?? ''));
                $ded = (int)($x['dedicacion'] ?? 100);
                $g[] = ['miembro' => (int)$mid, 'cuenta' => !empty($x['cuenta']), 'ausencias' => round($num($x['ausencias'] ?? 0, 30) * 2) / 2,
                        'dedicacion' => in_array($ded, self::DEDICACIONES, true) ? $ded : 100, 'puntos' => $pts === '' ? null : (int)round($num($pts, 300))];
            }
            $c['gente'] = $g;
        }
        $obj = $c['objetivo'] ?? $antes['objetivo'];
        $que = $obj !== $antes['objetivo'] ? ($obj === '' ? 'Quitó el objetivo del sprint' : 'Objetivo del sprint: ' . $obj) : 'Planificó la capacidad del sprint';
        self::guardarRegistro($pid, $sprint, $c, $quien, $que);
        return self::ficha($pid, $sprint);
    }

    /**
     * La capacidad del equipo en el sprint frente a lo comprometido. Por persona:
     * si cuenta, los días que falta, su dedicación, los días y horas que le quedan,
     * su capacidad en puntos (días × dedicación × puntos por día, o la que se puso
     * a mano) y lo asignado (los puntos de las historias del sprint que lleva).
     * Sin nada guardado sale la gente del proyecto (sin su PO) con la dedicación
     * del sprint anterior; quien lleva una historia del sprint sale siempre.
     */
    public static function capacidad(array $p, string $sprint, array $miembros, ?array $historias = null): array
    {
        $pid = (int)$p['id'];
        $s = self::sprints()[$sprint] ?? null;
        $dias = $s ? (int)self::dias($s, '0000-00-00')['total'] : 0;
        $f = self::ficha($pid, $sprint);
        $ant = self::fichaAnterior($pid, $sprint);
        $ptsDia = $f['pts_dia'] !== null ? (float)$f['pts_dia'] : (float)($ant['pts_dia'] ?? 1);
        $historias ??= self::historias(['proyecto_id' => $pid, 'sprint' => $sprint]);
        $asig = []; $nHu = []; $sinResp = 0; $comp = 0;
        foreach ($historias as $h) {
            $comp += (int)$h['puntos'];
            $r = (int)$h['responsable'];
            if ($r > 0) { $asig[$r] = ($asig[$r] ?? 0) + (int)$h['puntos']; $nHu[$r] = ($nHu[$r] ?? 0) + 1; }
            else $sinResp += (int)$h['puntos'];
        }
        $guard = []; foreach ((array)$f['gente'] as $g) if ((int)($g['miembro'] ?? 0) > 0) $guard[(int)$g['miembro']] = $g;
        $prev = []; foreach ((array)($ant['gente'] ?? []) as $g) if ((int)($g['miembro'] ?? 0) > 0) $prev[(int)$g['miembro']] = $g;
        $ids = array_values(array_unique(array_merge(array_keys($guard), array_keys($prev), self::equipoBase($p, $miembros), array_keys($asig))));
        $gente = [];
        foreach ($ids as $mid) {
            if (!isset($miembros[$mid])) continue;
            $g = $guard[$mid] ?? ['ausencias' => 0, 'dedicacion' => (int)($prev[$mid]['dedicacion'] ?? 100), 'puntos' => null];
            // Lo guardado manda; quien no estaba en lo guardado no cuenta; sin nada guardado, lo del sprint anterior (o sí)
            $cuenta = isset($guard[$mid]) ? !empty($g['cuenta']) : ($guard ? false : (isset($prev[$mid]) ? !empty($prev[$mid]['cuenta']) : true));
            $aus = min((float)$dias, max(0.0, (float)($g['ausencias'] ?? 0)));
            $ded = max(0, min(100, (int)($g['dedicacion'] ?? 100)));
            $diasEf = $cuenta ? round(($dias - $aus) * $ded / 100, 1) : 0.0;
            $calc = (int)round($diasEf * $ptsDia);
            $man = ($g['puntos'] ?? null) === null || ($g['puntos'] ?? '') === '' ? null : max(0, (int)$g['puntos']);
            $cap = $cuenta ? ($man ?? $calc) : 0;
            $a = (int)($asig[$mid] ?? 0);
            $gente[] = ['miembro' => $mid, 'nombre' => (string)($miembros[$mid]['nombre'] ?? ''), 'cuenta' => $cuenta, 'ausencias' => $aus, 'dedicacion' => $ded,
                'dias' => $diasEf, 'horas' => round($diasEf * self::HORAS_DIA, 1), 'calculado' => $calc, 'manual' => $man, 'capacidad' => $cap,
                'asignado' => $a, 'historias' => (int)($nHu[$mid] ?? 0), 'pct' => $cap > 0 ? (int)round($a * 100 / $cap) : null, 'tono' => self::tonoCarga($a, $cap)];
        }
        usort($gente, fn($x, $y) => strnatcasecmp($x['nombre'], $y['nombre']));
        $total = (int)array_sum(array_column($gente, 'capacidad'));
        return ['dias' => $dias, 'pts_dia' => $ptsDia, 'gente' => $gente, 'total' => $total, 'comprometido' => $comp, 'sin_resp' => $sinResp,
                'asignado' => (int)array_sum(array_column($gente, 'asignado')), 'dias_persona' => round(array_sum(array_column($gente, 'dias')), 1),
                'personas' => count(array_filter($gente, fn($g) => $g['cuenta'])), 'pct' => $total > 0 ? (int)round($comp * 100 / $total) : null,
                'tono' => self::tonoCarga($comp, $total), 'guardada' => (bool)$guard, 'heredada' => !$guard && $ant ? (string)$ant['sprint'] : ''];
    }

    /** El tono (colorimetría) de lo asignado frente a la capacidad: hasta el 100 % cumple, hasta el 115 % alerta y más es crítico. */
    public static function tonoCarga(int $asignado, int $capacidad): string
    {
        if ($capacidad <= 0) return $asignado > 0 ? 'crit' : 'nm';
        $p = $asignado * 100 / $capacidad;
        return $p <= 100 ? 'ok' : ($p <= 115 ? 'warn' : 'crit');
    }

    /** La gente que suele contar para la capacidad: los miembros del proyecto (o todos), sin su PO ni quien solo supervisa. */
    public static function equipoBase(array $p, array $miembros): array
    {
        $ids = ProyectoRepo::miembrosDe($p) ?? array_keys($miembros);
        $fuera = array_filter([ProyectoRepo::poDe($p), (int)($p['po_relevo'] ?? 0)]);
        return array_values(array_filter(array_unique(array_map('intval', $ids)), fn($id) => isset($miembros[$id]) && !in_array($id, $fuera, true)
            && MiembroRepo::accesosDe($miembros[$id]) !== ['supervisor']));
    }

    /**
     * Guarda la retrospectiva: qué salió bien y qué salió mal (una idea por línea),
     * si se logró el objetivo y las acciones (texto, responsable, abierta o hecha).
     * $crearTarea(acción, sprint) devuelve el id de la tarea nueva de las que se
     * marcaron «crear tarea» (una sola vez por acción).
     */
    public static function guardarRetro(int $pid, string $sprint, array $d, int $quien, ?callable $crearTarea = null): array
    {
        if (!isset(self::sprints()[$sprint])) throw new RuntimeException('Ese sprint no existe.');
        $f = self::ficha($pid, $sprint);
        $lineas = fn(string $k) => array_slice(array_values(array_filter(array_map(fn($l) => mb_substr(trim((string)$l), 0, 300), preg_split('/\R/', (string)($d[$k] ?? '')) ?: []), fn($l) => $l !== '')), 0, 40);
        $c = ['bien' => $lineas('bien'), 'mal' => $lineas('mal')];
        if (array_key_exists('logrado', $d)) $c['logrado'] = isset(self::LOGRADO[(string)$d['logrado']]) ? (string)$d['logrado'] : '';
        $antes = [];
        foreach ((array)$f['acciones'] as $a) $antes[(string)($a['id'] ?? '')] = $a;
        $acc = [];
        $nuevasT = 0;
        foreach (array_slice(array_values((array)($d['acciones'] ?? [])), 0, 40) as $x) {
            if (!is_array($x)) continue;
            $txt = mb_substr(trim((string)($x['texto'] ?? '')), 0, 300);
            if ($txt === '') continue;
            $id = (string)preg_replace('/[^a-z0-9]/', '', (string)($x['id'] ?? ''));
            $prev = $antes[$id] ?? null;
            if (!$prev) $id = 'a' . bin2hex(random_bytes(4));
            $est = ($x['estado'] ?? '') === 'hecha' ? 'hecha' : 'abierta';
            $a = ['id' => $id, 'texto' => $txt, 'responsable' => max(0, (int)($x['responsable'] ?? 0)), 'estado' => $est, 'tarea_id' => (int)($prev['tarea_id'] ?? 0),
                  'creada' => (string)($prev['creada'] ?? date('Y-m-d')), 'cerrada' => $est === 'hecha' ? (((string)($prev['cerrada'] ?? '')) ?: date('Y-m-d')) : ''];
            if (!empty($x['tarea']) && !$a['tarea_id'] && $crearTarea) { $a['tarea_id'] = (int)$crearTarea($a, $sprint); $nuevasT++; }
            $acc[] = $a;
        }
        $c['acciones'] = $acc;
        if ((string)$f['retro_en'] === '') $c['retro_en'] = date('Y-m-d');
        self::guardarRegistro($pid, $sprint, $c, $quien, 'Anotó la retrospectiva' . ($nuevasT ? ' (' . $nuevasT . ' tarea(s) nueva(s))' : ''));
        return self::ficha($pid, $sprint) + ['tareas_nuevas' => $nuevasT];
    }

    /** Una acción de la retrospectiva de un sprint por su id (con su sprint), o null. */
    public static function accion(int $pid, string $sprint, string $aid): ?array
    {
        foreach ((array)self::ficha($pid, $sprint)['acciones'] as $a) if ((string)($a['id'] ?? '') === $aid) return $a + ['sprint' => $sprint];
        return null;
    }

    /** Cambia una acción de la retrospectiva: su estado ('abierta' | 'hecha') o su tarea (tarea_id). */
    public static function cambiarAccion(int $pid, string $sprint, string $aid, array $cambios, int $quien): array
    {
        $acc = (array)self::ficha($pid, $sprint)['acciones'];
        foreach ($acc as $i => $a) {
            if ((string)($a['id'] ?? '') !== $aid) continue;
            if (isset($cambios['estado'])) {
                $acc[$i]['estado'] = $cambios['estado'] === 'hecha' ? 'hecha' : 'abierta';
                $acc[$i]['cerrada'] = $acc[$i]['estado'] === 'hecha' ? date('Y-m-d') : '';
            }
            if (isset($cambios['tarea_id'])) $acc[$i]['tarea_id'] = (int)$cambios['tarea_id'];
            $que = isset($cambios['tarea_id']) ? 'Creó la tarea de la acción: ' : ($acc[$i]['estado'] === 'hecha' ? 'Cerró la acción: ' : 'Reabrió la acción: ');
            self::guardarRegistro($pid, $sprint, ['acciones' => array_values($acc)], $quien, $que . (string)($a['texto'] ?? ''));
            return $acc[$i] + ['sprint' => $sprint];
        }
        throw new RuntimeException('Esa acción ya no existe.');
    }

    /** Las acciones que siguen abiertas de las retrospectivas de los sprints anteriores a este (la más reciente primero). */
    public static function accionesAbiertas(int $pid, string $sprint): array
    {
        self::registro($pid, $sprint);
        $out = [];
        foreach (self::$regs[$pid] ?? [] as $cod => $r) {
            if ($cod === '' || self::nSprint((string)$cod) >= self::nSprint($sprint)) continue;
            foreach ((array)($r['acciones'] ?? []) as $a) if (($a['estado'] ?? '') !== 'hecha') $out[] = $a + ['sprint' => (string)$cod];
        }
        usort($out, fn($a, $b) => self::nSprint($b['sprint']) <=> self::nSprint($a['sprint']));
        return $out;
    }

    /** La Definición de «Lista» del proyecto: [[clave, texto, auto]] (la suya o la de siempre). */
    public static function dorLista(int $pid): array
    {
        if (isset(self::$dorCache[$pid])) return self::$dorCache[$pid];
        $r = self::registro($pid, '');
        $out = [];
        foreach (is_array($r['dor'] ?? null) && $r['dor'] ? $r['dor'] : self::DOR_DEFECTO as $i) {
            $i = ['clave' => (string)($i['clave'] ?? ''), 'texto' => (string)($i['texto'] ?? ''), 'auto' => (string)($i['auto'] ?? '')];
            if (!isset(self::DOR_AUTO[$i['auto']])) $i['auto'] = '';
            if ($i['clave'] !== '' && $i['texto'] !== '') $out[] = $i;
        }
        return self::$dorCache[$pid] = $out;
    }

    /** ¿El proyecto cambió su DoR? (si no, usa la de siempre) */
    public static function dorPropia(int $pid): bool
    {
        return !empty(self::registro($pid, '')['dor']);
    }

    /** Guarda la DoR del proyecto. $items: [[clave, texto, auto]] en su orden (a las nuevas se les pone clave). */
    public static function guardarDor(int $pid, array $items, int $quien): array
    {
        $out = [];
        $vistas = [];
        foreach (array_slice(array_values($items), 0, 20) as $i) {
            if (!is_array($i)) continue;
            $txt = mb_substr(trim((string)($i['texto'] ?? '')), 0, 160);
            if ($txt === '') continue;
            $auto = isset(self::DOR_AUTO[(string)($i['auto'] ?? '')]) ? (string)$i['auto'] : '';
            $clave = $auto !== '' ? $auto : (string)preg_replace('/[^a-z0-9_]/', '', strtolower((string)($i['clave'] ?? '')));
            if ($clave === '' || isset($vistas[$clave]) || ($auto === '' && isset(self::DOR_AUTO[$clave]))) $clave = 'c' . substr(md5($txt . random_bytes(4)), 0, 8);
            $vistas[$clave] = true;
            $out[] = ['clave' => $clave, 'texto' => $txt, 'auto' => $auto];
        }
        if (!$out) throw new RuntimeException('Deja al menos una condición en la lista.');
        self::guardarRegistro($pid, '', ['dor' => $out], $quien, 'Cambió la Definición de «Lista»');
        return self::dorLista($pid);
    }

    /**
     * ¿La historia cumple la Definición de «Lista» de su proyecto? Sus condiciones
     * (con ok), cuántas cumple, si está «Lista» y lo que le falta.
     */
    public static function dor(array $h, ?array $lista = null): array
    {
        $lista ??= self::dorLista((int)($h['proyecto_id'] ?? 0));
        $marcadas = array_flip(array_map('strval', (array)($h['dor'] ?? [])));
        $items = [];
        $falta = [];
        foreach ($lista as $i) {
            $ok = $i['auto'] !== '' ? self::dorAuto($i['auto'], $h) : isset($marcadas[$i['clave']]);
            $items[] = $i + ['ok' => $ok];
            if (!$ok) $falta[] = $i['texto'];
        }
        $n = count($items);
        return ['items' => $items, 'hechos' => $n - count($falta), 'total' => $n, 'lista' => $n > 0 && !$falta, 'falta' => $falta];
    }

    private static function dorAuto(string $k, array $h): bool
    {
        $t = fn($x) => trim((string)$x) !== '';
        return match ($k) {
            'historia'  => $t($h['como'] ?? '') && $t($h['quiero'] ?? '') && $t($h['para'] ?? ''),
            'criterios' => (bool)array_filter((array)($h['escenarios'] ?? []), fn($e) => $t($e['dado'] ?? '') && $t($e['cuando'] ?? '') && $t($e['entonces'] ?? '')),
            'estimada'  => !in_array(trim((string)($h['estimacion'] ?? '')), ['', '?'], true),
            default     => false,
        };
    }
}
