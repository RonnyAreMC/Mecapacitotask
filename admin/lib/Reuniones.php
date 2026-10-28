<?php
/**
 * Reuniones - parametrizacion del admin y reglas de repeticion.
 *
 * Concentra dos cosas que antes estaban dispersas o no existian:
 *
 *  1. La configuracion (Ajustes -> Reuniones): que plataforma se usa por
 *     defecto, si el equipo puede cambiarla, duracion, zona horaria y si la
 *     reunion se agenda en el calendario de cada invitado.
 *
 *  2. La repeticion semanal ("lunes a viernes a las 9"). Se guarda una sola
 *     reunion con sus dias y su fecha final; cada plataforma quiere ese mismo
 *     dato en su propio formato, asi que la traduccion vive aqui:
 *       - Google Calendar: RRULE (BYDAY=MO,TU,... ; UNTIL en UTC)
 *       - Zoom:            recurrence.weekly_days ("1"=domingo ... "7"=sabado)
 *
 * Los dias se manejan siempre en formato ISO (1=lunes ... 7=domingo), que es
 * lo que devuelve date('N').
 */
require_once __DIR__ . '/Models.php';

class Reuniones
{
    /** Dias de la semana en ISO: clave => [etiqueta corta, nombre]. */
    public const DIAS = [
        1 => ['L', 'Lunes'],
        2 => ['M', 'Martes'],
        3 => ['X', 'Miércoles'],
        4 => ['J', 'Jueves'],
        5 => ['V', 'Viernes'],
        6 => ['S', 'Sábado'],
        7 => ['D', 'Domingo'],
    ];

    /**
     * Los dias en cristiano: "De lunes a viernes", "Solo los jueves",
     * "L · X · V". Siete letras con cinco encendidas obligan a descifrar en
     * cada fila lo que se puede decir de una vez.
     */
    public static function diasTexto(array $dias): string
    {
        $d = self::diasValidos($dias);
        return match (true) {
            !$d                  => 'Sin días',
            $d === [1,2,3,4,5]   => 'De lunes a viernes',
            $d === [1,2,3,4,5,6,7] => 'Todos los días',
            $d === [6,7]         => 'Fines de semana',
            // "lunes" ya es plural; "sábado" y "domingo" piden la -s
            count($d) === 1      => 'Solo los ' . self::enPlural(self::DIAS[$d[0]][1]),
            default              => implode(' · ', array_map(fn($i) => self::DIAS[$i][0], $d)),
        };
    }

    private static function enPlural(string $dia): string
    {
        $d = mb_strtolower($dia);
        return mb_substr($d, -1) === 's' ? $d : $d . 's';
    }

    /** Codigo de dia para la RRULE de Google, por dia ISO. */
    private const RRULE_DIAS = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];

    /* ---------------- Configuracion ---------------- */

    public static function conf(): array
    {
        return array_merge([
            'plataforma'      => 'zoom',
            'permitir_elegir' => true,
            'duracion'        => 60,
            'zona'            => '',
            'agendar'         => true,
        ], (array)Config::get('reuniones'));
    }

    /** Plataformas realmente disponibles: clave => etiqueta. */
    public static function disponibles(): array
    {
        $out = [];
        if (Zoom::listo())           $out['zoom'] = 'Zoom';
        if (GoogleCalendar::listo()) $out['meet'] = 'Google Meet';
        // El enlace propio no necesita configuración: siempre está. Sirve
        // cuando la sala de Zoom está ocupada y hay que hacer una revisión
        // igual — a cambio, esa reunión no queda grabada.
        $out['enlace'] = 'Enlace propio';
        return $out;
    }

    /**
     * Plataforma que debe usarse. Manda la del proyecto si tiene una propia;
     * si no, la del panel; y si esa no esta configurada, la otra que si lo este
     * (o '' cuando no hay ninguna).
     */
    public static function plataformaDefecto(?array $proyecto = null): string
    {
        $disp = self::disponibles();
        if (!$disp) return '';
        $delProyecto = ProyectoRepo::plataformaEntrada($proyecto['plataforma'] ?? '');
        $pref = $delProyecto !== ''
            ? $delProyecto
            : (self::conf()['plataforma'] === 'meet' ? 'meet' : 'zoom');
        if (isset($disp[$pref])) return $pref;
        // 'enlace' siempre está, pero no debe ganar por descarte: solo queda si
        // de verdad no hay ninguna plataforma conectada.
        foreach (['zoom', 'meet'] as $k) {
            if (isset($disp[$k])) return $k;
        }
        return 'enlace';
    }

    /** Etiqueta de la plataforma para mostrar ('' -> ''). */
    public static function etiquetaPlataforma(string $clave): string
    {
        return match ($clave) {
            'meet'   => 'Google Meet',
            'zoom'   => 'Zoom',
            'enlace' => 'Enlace propio',
            default  => '',
        };
    }

    /** ¿Se le ofrece el selector de plataforma a quien crea la reunion? */
    public static function puedeElegir(): bool
    {
        return !empty(self::conf()['permitir_elegir']) && count(self::disponibles()) > 1;
    }

    /**
     * Plataforma final de una reunion que se esta creando: lo que pidio el
     * formulario solo cuenta si el admin permite elegir y esa plataforma
     * esta lista. Si no, manda la configurada.
     */
    public static function resolverPlataforma(?string $pedida, ?array $proyecto = null): string
    {
        $pedida = in_array($pedida, ['zoom', 'meet', 'enlace'], true) ? $pedida : '';
        // El enlace propio se permite SIEMPRE, aunque el panel fuerce una
        // plataforma: es la salida para cuando la sala está ocupada y hay que
        // hacer la revisión igual. No necesita nada configurado y quien la crea
        // ya sabe que no quedará grabada.
        if ($pedida === 'enlace') {
            return 'enlace';
        }
        if ($pedida !== '' && self::puedeElegir() && isset(self::disponibles()[$pedida])) {
            return $pedida;
        }
        return self::plataformaDefecto($proyecto);
    }

    /** ¿Hay que copiar la reunion al calendario de cada invitado? */
    public static function agendaEnCalendarios(): bool
    {
        return !empty(self::conf()['agendar']) && GoogleCalendar::listo();
    }

    /** Zona horaria de las reuniones (la propia, o la de Zoom como respaldo). */
    public static function zona(): string
    {
        $z = trim((string)self::conf()['zona']);
        if ($z !== '') return $z;
        $zoom = (array)Config::get('zoom');
        return trim((string)($zoom['zona'] ?? '')) ?: 'America/Guayaquil';
    }

    /** Duraciones ofrecidas en los formularios. */
    public static function duraciones(): array
    {
        return [15 => '15 min', 30 => '30 min', 45 => '45 min', 60 => '1 hora', 90 => '1h 30m', 120 => '2 horas', 180 => '3 horas'];
    }

    /** Duracion por defecto, siempre una de las ofrecidas. */
    public static function duracionDefecto(): int
    {
        $d = (int)self::conf()['duracion'];
        return isset(self::duraciones()[$d]) ? $d : 60;
    }

    /* ---------------- Repeticion ---------------- */

    /** Limpia la lista de dias: enteros 1..7, sin repetidos y ordenados. */
    public static function diasValidos(mixed $dias): array
    {
        $out = [];
        foreach ((array)$dias as $d) {
            $d = (int)$d;
            if ($d >= 1 && $d <= 7 && !in_array($d, $out, true)) $out[] = $d;
        }
        sort($out);
        return $out;
    }

    /** ¿Esta reunion se repite? (tiene dias marcados y fecha de fin) */
    public static function esRecurrente(array $reunion): bool
    {
        return !empty($reunion['recurrente'])
            && self::diasValidos($reunion['dias'] ?? []) !== []
            && trim((string)($reunion['hasta'] ?? '')) !== '';
    }

    /**
     * Adelanta el inicio al primer dia que cumpla la regla. Si alguien pone
     * "repetir L-V" con fecha de un sabado, la serie empezaria en el sabado y
     * Google/Zoom sumarian una ocurrencia fuera de los dias elegidos.
     * $inicio: "Y-m-d H:i". Devuelve el inicio corregido.
     */
    public static function primerInicio(string $inicio, array $dias): string
    {
        $dias = self::diasValidos($dias);
        if (!$dias) return $inicio;
        $ts = strtotime($inicio);
        if ($ts === false) return $inicio;
        for ($i = 0; $i < 7; $i++) {
            if (in_array((int)date('N', $ts), $dias, true)) break;
            $ts = strtotime('+1 day', $ts);
        }
        return date('Y-m-d H:i', $ts);
    }

    /**
     * Cuántas veces caería la reunión entre $inicio y $hasta. Zoom no admite
     * series de más de 60, así que conviene avisar antes de que lo rechace él.
     */
    public static function ocurrencias(string $inicio, array $dias, string $hasta): int
    {
        $dias = self::diasValidos($dias);
        $ts   = strtotime(substr($inicio, 0, 10));
        $fin  = strtotime($hasta);
        if (!$dias || $ts === false || $fin === false || $fin < $ts) return 0;
        $n = 0;
        while ($ts <= $fin && $n <= 500) {              // tope de seguridad
            if (in_array((int)date('N', $ts), $dias, true)) $n++;
            $ts = strtotime('+1 day', $ts);
        }
        return $n;
    }

    /**
     * Las FECHAS de cada ocurrencia de una serie, "Y-m-d H:i" (misma hora que
     * el inicio). Es lo que permite tratar una reunión recurrente como lo que
     * el equipo espera: una reunión POR DÍA, no una sola fila. Sirve para el
     * historial ("¿qué días hubo reunión?") y para pedir la grabación de un día.
     */
    public static function fechasOcurrencias(string $inicio, array $dias, string $hasta): array
    {
        $dias = self::diasValidos($dias);
        $hora = substr($inicio, 11, 5) ?: '00:00';
        $ts   = strtotime(substr($inicio, 0, 10));
        $fin  = strtotime($hasta);
        if (!$dias || $ts === false || $fin === false || $fin < $ts) return [];
        $out = [];
        while ($ts <= $fin && count($out) <= 500) {          // tope de seguridad
            if (in_array((int)date('N', $ts), $dias, true)) {
                $out[] = date('Y-m-d', $ts) . ' ' . $hora;
            }
            $ts = strtotime('+1 day', $ts);
        }
        return $out;
    }

    /**
     * RRULE semanal para Google Calendar. $hasta es "Y-m-d" (inclusive) y se
     * convierte a UTC porque UNTIL con 'Z' debe ir en tiempo universal.
     * Devuelve '' si la regla no aplica.
     */
    public static function rrule(array $dias, string $hasta): string
    {
        $dias = self::diasValidos($dias);
        $hasta = trim($hasta);
        if (!$dias || $hasta === '') return '';
        $codigos = array_map(fn($d) => self::RRULE_DIAS[$d], $dias);
        return 'RRULE:FREQ=WEEKLY;BYDAY=' . implode(',', $codigos) . ';UNTIL=' . self::finUtc($hasta);
    }

    /** Fin del dia $hasta (hora local del equipo) en UTC, con el formato pedido. */
    private static function finEnUtc(string $hasta, string $formato): string
    {
        try {
            $dt = new DateTime($hasta . ' 23:59:59', new DateTimeZone(self::zona()));
            $dt->setTimezone(new DateTimeZone('UTC'));
            return $dt->format($formato);
        } catch (Exception $e) {
            // Zona invalida o fecha rara: se cae al UTC del servidor
            return gmdate($formato, strtotime($hasta . ' 23:59:59') ?: time());
        }
    }

    /** UNTIL de la RRULE de Google: "20261219T235959Z". */
    public static function finUtc(string $hasta): string
    {
        return self::finEnUtc($hasta, 'Ymd\THis\Z');
    }

    /** end_date_time de Zoom: "2026-12-19T23:59:59Z". */
    public static function finUtcZoom(string $hasta): string
    {
        return self::finEnUtc($hasta, 'Y-m-d\TH:i:s\Z');
    }

    /**
     * Dias para Zoom: su semana empieza en domingo (1=domingo ... 7=sabado),
     * mientras que la nuestra empieza en lunes. Devuelve "2,3,4,5,6" para L-V.
     */
    public static function zoomDias(array $dias): string
    {
        $out = [];
        foreach (self::diasValidos($dias) as $d) {
            $out[] = ($d % 7) + 1;      // ISO 7 (domingo) -> 1; ISO 1 (lunes) -> 2
        }
        sort($out);
        return implode(',', $out);
    }

    /**
     * Texto corto de la repeticion: "Lunes a viernes", "L, X, V", "Todos los días".
     * Sirve para la tarjeta de la reunion y para los correos.
     */
    public static function etiqueta(array $dias): string
    {
        $dias = self::diasValidos($dias);
        if (!$dias) return '';
        if ($dias === [1, 2, 3, 4, 5, 6, 7]) return 'Todos los días';
        if ($dias === [1, 2, 3, 4, 5])       return 'Lunes a viernes';
        if ($dias === [6, 7])                return 'Fines de semana';
        if (count($dias) === 1)              return self::DIAS[$dias[0]][1];
        return implode(', ', array_map(fn($d) => self::DIAS[$d][0], $dias));
    }

    /** Resumen completo para mostrar: "Lunes a viernes · 09:00 · hasta 2026-12-19". */
    public static function resumen(array $reunion): string
    {
        if (!self::esRecurrente($reunion)) return '';
        $hora = substr((string)($reunion['inicio'] ?? ''), 11, 5);
        return self::etiqueta((array)$reunion['dias']) . ($hora ? ' · ' . $hora : '')
             . ' · hasta ' . (string)$reunion['hasta'];
    }

    /* ---------- Choques en Zoom ---------- */

    /**
     * Zoom es UNA sola cuenta para todo el panel: dos reuniones a la misma hora
     * se pisan (la gente entra y se encuentra con otra). Devuelve la primera
     * reunión de Zoom que se cruza con la que se quiere crear o mover, en
     * cualquier proyecto y con sus repeticiones, o null si la hora está libre.
     * Pegadas (una acaba a las 10:00 y la otra empieza a las 10:00) no chocan.
     *
     * @return array{reunion: array, inicio: int, fin: int}|null
     */
    public static function choqueZoom(string $inicio, int $duracion, array $dias = [], string $hasta = '', int $excluirId = 0): ?array
    {
        $dur = max(1, $duracion) * 60;
        $propias = self::inicios($inicio, $dias, $hasta);
        if (!$propias) return null;
        $min = min($propias);
        $max = max($propias) + $dur;
        foreach ((new ReunionRepo())->todas() as $r) {
            if ((int)($r['id'] ?? 0) === $excluirId || ($r['plataforma'] ?? 'zoom') !== 'zoom') continue;
            $durR = max(1, (int)($r['duracion'] ?? 60)) * 60;
            $serie = !empty($r['recurrente']);
            foreach (self::inicios((string)($r['inicio'] ?? ''), $serie ? (array)($r['dias'] ?? []) : [], $serie ? (string)($r['hasta'] ?? '') : '') as $t) {
                if ($t + $durR <= $min || $t >= $max) continue;
                foreach ($propias as $p) {
                    if ($p < $t + $durR && $t < $p + $dur) return ['reunion' => $r, 'inicio' => $t, 'fin' => $t + $durR];
                }
            }
        }
        return null;
    }

    /**
     * Lo que Zoom ya tiene un día (todas las reuniones de Zoom del panel, con sus
     * repeticiones), en minutos desde la medianoche: [['ini','fin','tema','proyecto']].
     * Es lo que el selector de hora pinta en gris.
     */
    public static function ocupadoZoom(string $fecha, int $excluirId = 0): array
    {
        $d0 = strtotime($fecha . ' 00:00');
        if ($d0 === false) return [];
        $d1 = $d0 + 86400;
        $nombres = [];
        $out = [];
        foreach ((new ReunionRepo())->todas() as $r) {
            if ((int)($r['id'] ?? 0) === $excluirId || ($r['plataforma'] ?? 'zoom') !== 'zoom') continue;
            $dur = max(1, (int)($r['duracion'] ?? 60)) * 60;
            $serie = !empty($r['recurrente']);
            foreach (self::inicios((string)($r['inicio'] ?? ''), $serie ? (array)($r['dias'] ?? []) : [], $serie ? (string)($r['hasta'] ?? '') : '') as $t) {
                if ($t + $dur <= $d0 || $t >= $d1) continue;
                $pid = (int)($r['proyecto_id'] ?? 0);
                $nombres[$pid] ??= (string)((new ProyectoRepo())->buscar($pid)['nombre'] ?? '');
                $out[] = ['ini' => (int)((max($t, $d0) - $d0) / 60), 'fin' => (int)((min($t + $dur, $d1) - $d0) / 60),
                          'tema' => (string)($r['topic'] ?? 'Reunión'), 'proyecto' => $nombres[$pid]];
            }
        }
        usort($out, fn($a, $b) => $a['ini'] <=> $b['ini']);
        return $out;
    }

    /**
     * Cuándo empieza la vez que toca de una reunión: la que está en curso
     * ahora o la siguiente (timestamp). 0 si ya no queda ninguna.
     */
    public static function proximoInicio(array $reunion, ?int $ahora = null): int
    {
        $ahora ??= time();
        $dur = max(1, (int)($reunion['duracion'] ?? 60)) * 60;
        $dias = self::esRecurrente($reunion) ? (array)($reunion['dias'] ?? []) : [];
        foreach (self::inicios((string)($reunion['inicio'] ?? ''), $dias, (string)($reunion['hasta'] ?? '')) as $t) {
            if ($t + $dur > $ahora) return $t;
        }
        return 0;
    }

    /** Los inicios (timestamps) de una reunión: el suyo, o el de cada día si se repite. */
    private static function inicios(string $inicio, array $dias, string $hasta): array
    {
        if (trim($inicio) === '') return [];
        $dias = self::diasValidos($dias);
        $fechas = $dias && $hasta !== '' ? self::fechasOcurrencias($inicio, $dias, $hasta) : [substr($inicio, 0, 16)];
        return array_values(array_filter(array_map(fn($f) => strtotime($f) ?: 0, $fechas)));
    }

    /**
     * El aviso para quien la quería a esa hora: con qué choca y, si es una
     * reunión suelta, desde qué hora está libre (con la misma duración).
     */
    public static function textoChoque(array $c, int $duracion, bool $serie = false): string
    {
        $r = $c['reunion'];
        $p = (new ProyectoRepo())->buscar((int)($r['proyecto_id'] ?? 0));
        $txt = 'Zoom ya está ocupado a esa hora: «' . ($r['topic'] ?? 'Reunión') . '»' . ($p ? ' (' . $p['nombre'] . ')' : '')
            . ' el ' . date('d/m', $c['inicio']) . ' de ' . date('H:i', $c['inicio']) . ' a ' . date('H:i', $c['fin']) . '.';
        if (!$serie && ($libre = self::libreDesde($c['fin'], $duracion))) {
            $txt .= ' Está libre desde las ' . date('H:i', $libre) . (date('Y-m-d', $libre) !== date('Y-m-d', $c['inicio']) ? ' del ' . date('d/m', $libre) : '') . '.';
        }
        return $txt . ' Elige otra hora o usa un enlace propio.';
    }

    /** La primera hora, desde $ts, en que cabe una reunión de $duracion minutos sin chocar. */
    private static function libreDesde(int $ts, int $duracion): int
    {
        for ($i = 0; $i < 12; $i++) {
            $c = self::choqueZoom(date('Y-m-d H:i', $ts), $duracion);
            if (!$c) return $ts;
            $ts = $c['fin'];
        }
        return 0;
    }
}
