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
        return isset($disp[$pref]) ? $pref : (string)array_key_first($disp);
    }

    /** Etiqueta de la plataforma para mostrar ('' -> ''). */
    public static function etiquetaPlataforma(string $clave): string
    {
        return $clave === 'meet' ? 'Google Meet' : ($clave === 'zoom' ? 'Zoom' : '');
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
        $pedida = in_array($pedida, ['zoom', 'meet'], true) ? $pedida : '';
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
}
