<?php
/**
 * Lo que pinta el Resumen del dashboard (resumen.php): el día de UNA persona,
 * la que mira o la de «ver como». Responde «¿qué tengo que hacer hoy?»: lo que
 * vence hoy y lo atrasado, las reuniones del día, lo que espera su visto bueno
 * si es PO y lo que le llegó (correo, requerimientos, pedidos). Debajo, cómo
 * va: sus tareas por estado, lo que le cae en los próximos días y el avance de
 * sus proyectos.
 *
 * Todo sale de lo que ya guarda el panel; lo que no hay se queda vacío, nunca
 * un número inventado. Cada colección se lee una vez: de las tareas salen las
 * de hoy, las atrasadas, la semana, el anillo y lo que revisa el PO.
 */
final class ResumenPersona
{
    /** Días que mira «Tu semana»: hoy y los seis que vienen. */
    public const DIAS_SEMANA = 7;

    /** Una reunión de la agenda cuenta como «pronto» desde media hora antes. */
    public const PRONTO = 30 * 60;

    /** Una daily no guarda cuánto dura: se da por terminada a los 15 minutos. */
    public const DURA_DAILY = 15;

    private const DIAS  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
                           'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    /**
     * Todo el resumen de $yo. $ahora se puede fijar (pruebas); si no, la hora
     * del servidor, que es la del equipo (bootstrap fija la zona).
     */
    public static function datos(array $yo, bool $conCorreo = true, ?int $ahora = null): array
    {
        $ahora ??= time();
        $mid   = (int)($yo['id'] ?? 0);
        $hoy   = date('Y-m-d', $ahora);
        $finales = Catalogo::estadosFinales();
        $estados = Catalogo::estadosTarea();

        // Solo lo que puede abrir quien tiene la sesión: un colaborador de
        // solo lectura no ve en el resumen lo que tampoco ve en el panel.
        $proyectos = [];
        foreach (soloProyectosVisibles((new ProyectoRepo())->todos()) as $p) {
            $proyectos[(int)$p['id']] = $p;
        }
        $todas = array_values(array_filter((new TareaRepo())->todas(),
            fn($t) => isset($proyectos[(int)($t['proyecto_id'] ?? 0)])));
        $observadas = (new ObservacionRepo())->tareasConPendientes();
        $reuniones  = (new ReunionRepo())->todas();

        /* ---------- Mis tareas ---------- */
        $mias = array_values(array_filter($todas, fn($t) => TareaRepo::tieneAsignado($t, $mid)));
        $porEstado = array_fill_keys(array_keys($estados), 0);
        $vencidas = $deHoy = $proximas = [];
        $terminadas = $semanaHechas = 0;
        $hasta = self::masDias($hoy, self::DIAS_SEMANA - 1);
        $hace7 = self::masDias($hoy, -6);
        foreach ($mias as $t) {
            $est = (string)($t['estado'] ?? array_key_first($porEstado));
            $porEstado[$est] = ($porEstado[$est] ?? 0) + 1;
            if (in_array($est, $finales, true)) {
                // Igual que el avance del proyecto: con una observación abierta no está terminada
                if (empty($observadas[(int)$t['id']])) $terminadas++;
                if ((string)($t['completada_en'] ?? '') >= $hace7) $semanaHechas++;
                continue;
            }
            if ($est === 'revision') continue;   // ya no depende de quien la hace: la tiene el PO
            $f = trim((string)($t['fecha_limite'] ?? ''));
            if ($f === '') continue;
            if ($f < $hoy)        $vencidas[] = $t;
            elseif ($f === $hoy)  $deHoy[]    = $t;
            elseif ($f <= $hasta) $proximas[] = $t;
        }
        usort($vencidas, [self::class, 'porFecha']);
        usort($deHoy, [self::class, 'porFecha']);
        usort($proximas, [self::class, 'porFecha']);

        // Lo que le cae cada día de la semana que viene (hoy incluido)
        $semana = [];
        for ($i = 0; $i < self::DIAS_SEMANA; $i++) $semana[self::masDias($hoy, $i)] = 0;
        foreach (array_merge($deHoy, $proximas) as $t) $semana[$t['fecha_limite']]++;

        /* ---------- En qué proyectos está ---------- */
        // Como misProyectosIds(), pero para cualquier persona: con «ver como»
        // cuentan los de esa persona, no los de quien mira.
        $participo = [];
        foreach ($proyectos as $pid => $p) {
            $equipo = ProyectoRepo::miembrosDe($p);
            if (($equipo !== null && in_array($mid, $equipo, true)) || esPOde($p, $mid) || ProyectoRepo::scrumDe($p) === $mid) {
                $participo[$pid] = true;
            }
        }
        foreach ($mias as $t) $participo[(int)$t['proyecto_id']] = true;
        foreach ($reuniones as $r) {
            if (in_array($mid, array_map('intval', (array)($r['invitados'] ?? [])), true) && isset($proyectos[(int)($r['proyecto_id'] ?? 0)])) {
                $participo[(int)$r['proyecto_id']] = true;
            }
        }
        // El supervisor no participa: mira los que le asignó el administrador
        if (!$participo && MiembroRepo::tieneAcceso($yo, 'supervisor')) {
            foreach ((array)($yo['proyectos_sup'] ?? []) as $pid) {
                if (isset($proyectos[(int)$pid])) $participo[(int)$pid] = true;
            }
        }

        /* ---------- Por revisar como Product Owner ---------- */
        $misPo = array_filter($proyectos, fn($p) => esPOde($p, $mid));
        $revision = array_values(array_filter($todas,
            fn($t) => isset($misPo[(int)$t['proyecto_id']]) && ($t['estado'] ?? '') === 'revision'));
        usort($revision, [self::class, 'porFecha']);

        /* ---------- La agenda de hoy: reuniones y dailies ---------- */
        $inicioDia = strtotime($hoy . ' 00:00');
        $agenda = [];
        $siguiente = null;
        foreach ($reuniones as $r) {
            $inv = array_map('intval', (array)($r['invitados'] ?? []));
            if (!in_array($mid, $inv, true) && (int)($r['creador_id'] ?? 0) !== $mid) continue;
            $pid = (int)($r['proyecto_id'] ?? 0);
            if (!isset($proyectos[$pid])) continue;
            // La vez de hoy, aunque ya haya terminado: el día se lee entero
            $ts = self::inicioDesde($r, $inicioDia);
            if ($ts && date('Y-m-d', $ts) === $hoy) $agenda[] = self::itemReunion($r, $ts, $proyectos[$pid], $ahora);
            // Y la siguiente (en curso o por venir), para cuando hoy ya no queda ninguna
            $tsN = self::inicioDesde($r, $ahora);
            if ($tsN && $tsN - $ahora < 14 * 86400 && (!$siguiente || $tsN < $siguiente['ts'])) {
                $siguiente = self::itemReunion($r, $tsN, $proyectos[$pid], $ahora);
            }
        }
        $dow = (int)date('N', $ahora);
        foreach ((new ReunionFijaRepo())->todas() as $f) {
            $pid = (int)($f['proyecto_id'] ?? 0);
            if (!isset($participo[$pid], $proyectos[$pid])) continue;
            if (!in_array($dow, ReunionFijaRepo::diasDe($f), true) || trim((string)($f['hora'] ?? '')) === '') continue;
            $ts = strtotime($hoy . ' ' . $f['hora']);
            if (!$ts) continue;
            $fin = $ts + self::DURA_DAILY * 60;
            $agenda[] = [
                'tipo' => 'daily', 'id' => (int)$f['id'], 'titulo' => (string)($f['titulo'] ?? 'Daily'),
                'ts' => $ts, 'fin' => $fin, 'duracion' => self::DURA_DAILY, 'proyecto' => $proyectos[$pid],
                'estado' => self::estadoReunion($ts, $fin, $ahora), 'entrar' => '', 'plataforma' => '', 'invitados' => [],
            ];
        }
        usort($agenda, fn($a, $b) => $a['ts'] <=> $b['ts']);
        // La que se destaca: la que está en curso o la próxima de hoy; si hoy
        // no queda ninguna, la próxima de los días que vienen.
        $destacada = null;
        foreach ($agenda as $a) {
            if ($a['estado'] !== 'paso') { $destacada = $a; break; }
        }
        $destacada ??= $siguiente;

        /* ---------- Lo que le llegó ---------- */
        $reqs = array_values(array_filter((new RequerimientoRepo())->todos(),
            fn($r) => !RequerimientoRepo::cerrado($r) && RequerimientoRepo::tieneAsignado($r, $mid)));
        usort($reqs, fn($a, $b) => strcmp(RequerimientoRepo::fechaFin($a) ?: '9999', RequerimientoRepo::fechaFin($b) ?: '9999'));
        $pedidos = 0;
        if (is_file(__DIR__ . '/Pedidos.php')) {
            require_once __DIR__ . '/Pedidos.php';
            $pedidos = (new PedidoRepo())->pendientesPara($yo);
        }
        // El correo de otro no se asoma: con «ver como», sin correo
        $correo = null;
        if ($conCorreo && is_file(__DIR__ . '/Correo.php')) {
            require_once __DIR__ . '/Correo.php';
            try {
                $correo = Correo::activo() ? Correo::noLeidos($mid) : null;
            } catch (Throwable $e) {
                $correo = null;   // sin el número, el resto del resumen sigue
            }
        }

        /* ---------- Sus proyectos y cómo van ---------- */
        $repo = new TareaRepo();
        $avances = [];
        foreach (array_keys($participo) as $pid) {
            $susT = array_filter($mias, fn($t) => (int)$t['proyecto_id'] === $pid);
            $abiertasP = count(array_filter($susT, fn($t) => !in_array($t['estado'] ?? '', $finales, true)));
            $avances[] = [
                'p'        => $proyectos[$pid],
                'avance'   => $repo->avance($pid),
                'total'    => array_sum($repo->resumen($pid)),
                'mias'     => count($susT),
                'abiertas' => $abiertasP,
            ];
        }
        // Arriba, donde más le queda por hacer
        usort($avances, fn($a, $b) => [$b['abiertas'], $b['mias']] <=> [$a['abiertas'], $a['mias']]
            ?: strcasecmp($a['p']['nombre'], $b['p']['nombre']));

        return [
            'yo' => $yo, 'mid' => $mid, 'hoy' => $hoy, 'ahora' => $ahora,
            'proyectos' => $proyectos, 'estados' => $estados, 'finales' => $finales,
            'mias' => $mias, 'porEstado' => $porEstado, 'terminadas' => $terminadas,
            'avance' => $mias ? (int)round($terminadas * 100 / count($mias)) : 0,
            'semanaHechas' => $semanaHechas,
            'vencidas' => $vencidas, 'deHoy' => $deHoy, 'proximas' => $proximas, 'semana' => $semana,
            'esPo' => (bool)$misPo, 'misPo' => $misPo, 'revision' => $revision,
            'agenda' => $agenda, 'destacada' => $destacada,
            'reqs' => $reqs, 'pedidos' => $pedidos, 'correo' => $correo,
            'avances' => array_slice($avances, 0, 6), 'masProyectos' => max(0, count($avances) - 6),
        ];
    }

    /* ---------- Piezas de la agenda ---------- */

    /** Cuándo empieza la vez en curso o la siguiente desde $desde (0 si no queda ninguna). */
    private static function inicioDesde(array $r, int $desde): int
    {
        if (method_exists('Reuniones', 'proximoInicio')) return Reuniones::proximoInicio($r, $desde);
        // Sin el ayudante (un Reuniones.php viejo), al menos la reunión suelta
        $ts = strtotime((string)($r['inicio'] ?? '')) ?: 0;
        return $ts && $ts + max(1, (int)($r['duracion'] ?? 60)) * 60 > $desde ? $ts : 0;
    }

    private static function itemReunion(array $r, int $ts, array $p, int $ahora): array
    {
        $dur = max(1, (int)($r['duracion'] ?? 60));
        $fin = $ts + $dur * 60;
        return [
            'tipo' => 'reunion', 'id' => (int)$r['id'], 'titulo' => trim((string)($r['topic'] ?? '')) ?: 'Reunión',
            'ts' => $ts, 'fin' => $fin, 'duracion' => $dur, 'proyecto' => $p,
            'estado' => self::estadoReunion($ts, $fin, $ahora),
            // Solo se ofrece «Entrar» si hay a dónde (reunion_entrar.php redirige al enlace)
            'entrar' => preg_match('#^https?://#i', trim((string)($r['join_url'] ?? ''))) ? 'reunion_entrar.php?id=' . (int)$r['id'] : '',
            'plataforma' => Reuniones::etiquetaPlataforma((string)($r['plataforma'] ?? '')),
            'invitados' => array_values(array_unique(array_map('intval', (array)($r['invitados'] ?? [])))),
        ];
    }

    /** 'paso' (ya terminó), 'vivo' (en curso), 'pronto' (en media hora) o 'luego'. */
    private static function estadoReunion(int $ts, int $fin, int $ahora): string
    {
        if ($fin <= $ahora) return 'paso';
        if ($ts <= $ahora)  return 'vivo';
        return $ts - $ahora <= self::PRONTO ? 'pronto' : 'luego';
    }

    /* ---------- Fechas para leer ---------- */

    /** Por fecha de entrega (las sin fecha al final) y, a igual fecha, la de más prioridad. */
    public static function porFecha(array $a, array $b): int
    {
        static $prio = null;
        $prio ??= array_flip(array_reverse(array_keys(Catalogo::prioridades())));
        return strcmp(trim((string)($a['fecha_limite'] ?? '')) ?: '9999', trim((string)($b['fecha_limite'] ?? '')) ?: '9999')
            ?: (($prio[$a['prioridad'] ?? ''] ?? 99) <=> ($prio[$b['prioridad'] ?? ''] ?? 99));
    }

    /** 'Y-m-d' más (o menos) $n días. */
    public static function masDias(string $fecha, int $n): string
    {
        return (new DateTimeImmutable($fecha))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    }

    /** Días de $desde a $hasta ('Y-m-d'): negativo si $hasta ya pasó. */
    public static function diasEntre(string $desde, string $hasta): int
    {
        return (int)(new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->format('%r%a');
    }

    /** «miércoles 28 de octubre». */
    public static function fechaLarga(int $ts): string
    {
        return self::DIAS[(int)date('w', $ts)] . ' ' . (int)date('j', $ts) . ' de ' . self::MESES[(int)date('n', $ts) - 1];
    }

    /** «hoy», «mañana», «ayer», «hace 3 días», «en 4 días» de una fecha 'Y-m-d'. */
    public static function relativo(string $fecha, string $hoy): string
    {
        $n = self::diasEntre($hoy, $fecha);
        return match (true) {
            $n === 0  => 'hoy',
            $n === 1  => 'mañana',
            $n === -1 => 'ayer',
            $n < 0    => 'hace ' . -$n . ' días',
            default   => 'en ' . $n . ' días',
        };
    }

    /** Nombre corto del día y del mes: ['mié', 'oct']. */
    public static function diaMesCorto(string $fecha): array
    {
        $ts = strtotime($fecha) ?: time();
        return [mb_substr(self::DIAS[(int)date('w', $ts)], 0, 3), mb_substr(self::MESES[(int)date('n', $ts) - 1], 0, 3)];
    }

    /** «10:38 am» (la del cronograma si está, para que todo el panel diga la hora igual). */
    public static function hora(int $ts): string
    {
        return class_exists('Cronograma') ? Cronograma::hora($ts) : date('g:i', $ts) . ' ' . date('a', $ts);
    }

    /** «hoy», «mañana», «viernes»… (Cronograma::diaLargo si está). */
    public static function dia(int $ts, int $ahora): string
    {
        if (class_exists('Cronograma')) return Cronograma::diaLargo($ts, $ahora);
        $d = date('Y-m-d', $ts);
        return $d === date('Y-m-d', $ahora) ? 'hoy' : self::DIAS[(int)date('w', $ts)];
    }

    /* ---------- Lo que se dice arriba ---------- */

    /** «Buenos días», «Buenas tardes» o «Buenas noches» según la hora. */
    public static function saludo(int $ahora): string
    {
        $h = (int)date('G', $ahora);
        return $h < 12 ? 'Buenos días' : ($h < 19 ? 'Buenas tardes' : 'Buenas noches');
    }

    /**
     * Tu siguiente paso, en una frase (HTML ya escapado): lo más urgente
     * primero —la reunión en curso, lo atrasado, lo de hoy— y como mucho dos
     * cosas. Si no hay nada, lo dice y adelanta lo próximo.
     */
    public static function siguientePaso(array $d): string
    {
        $partes = [];
        $r = $d['destacada'];
        $hoyR = $r && date('Y-m-d', $r['ts']) === $d['hoy'];
        if ($r && $r['estado'] === 'vivo') {
            $partes[] = 'tu reunión <b>«' . e($r['titulo']) . '»</b> está en curso' . ($r['entrar'] ? ': entra ya' : '');
        }
        if ($n = count($d['vencidas'])) {
            $partes[] = $n === 1
                ? 'tienes <b>1 tarea atrasada</b>: «' . e(mb_strimwidth((string)$d['vencidas'][0]['titulo'], 0, 48, '…')) . '»'
                : 'tienes <b>' . $n . ' tareas atrasadas</b> (empieza por «' . e(mb_strimwidth((string)$d['vencidas'][0]['titulo'], 0, 40, '…')) . '»)';
        }
        if ($r && $hoyR && in_array($r['estado'], ['pronto', 'luego'], true) && count($partes) < 2) {
            $partes[] = '«' . e($r['titulo']) . '» empieza a las <b>' . e(self::hora($r['ts'])) . '</b>';
        }
        if (($n = count($d['deHoy'])) && count($partes) < 2) {
            $partes[] = $n === 1
                ? 'hoy vence <b>«' . e(mb_strimwidth((string)$d['deHoy'][0]['titulo'], 0, 48, '…')) . '»</b>'
                : 'hoy vencen <b>' . $n . ' tareas</b>';
        }
        if (($n = count($d['revision'])) && count($partes) < 2) {
            $partes[] = '<b>' . $n . ' ' . ($n === 1 ? 'tarea espera' : 'tareas esperan') . '</b> tu revisión como PO';
        }
        if ($partes) {
            $frase = implode('; y ', array_slice($partes, 0, 2));
            return mb_strtoupper(mb_substr($frase, 0, 1)) . mb_substr($frase, 1) . '.';
        }
        if ($d['proximas']) {
            $t = $d['proximas'][0];
            return 'Vas al día: nada vence hoy. Lo próximo es «' . e(mb_strimwidth((string)$t['titulo'], 0, 48, '…'))
                . '», ' . e(self::dia(strtotime($t['fecha_limite']), $d['ahora'])) . '.';
        }
        return $d['mias'] ? 'Vas al día: no tienes nada que entregar esta semana.' : 'Todavía no tienes tareas asignadas. Cuando te asignen una, aquí verás qué hacer cada día.';
    }
}
