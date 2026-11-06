<?php
/**
 * Calendario general de feriados nacionales del Ecuador (calendario.php).
 *
 * Un feriado no es día hábil en todo el panel: no cuenta en los plazos ni en el
 * % esperado de Releases, no hay daily que medir, las reuniones que se repiten
 * no caen ese día y los avisos automáticos (vencimientos, atrasos, horario de
 * subidas, recordatorios de tareas) no salen.
 *
 * De dónde salen: la lista oficial de cada año que ya se conoce (OFICIALES, el
 * decreto con sus traslados), la base pública Nager.Date (datos abiertos,
 * date.nager.at) para los demás años, los del Excel de revisiones de Releases y
 * los que se agreguen a mano. Uno por fecha. Quitar uno que vino de una fuente
 * lo apaga (no se borra) para que al volver a traer el año no regrese.
 */
final class Feriados
{
    /** La base pública de feriados (Nager.Date): un año, Ecuador. */
    public const FUENTE = 'https://date.nager.at/api/v3/PublicHolidays/%d/EC';

    /**
     * Los años con el calendario oficial ya publicado, con sus traslados. La
     * base pública junta el 2 y el 3 de noviembre de 2026 en el lunes; el
     * decreto (y el Excel del proyecto) los deja en lunes 2 y martes 3.
     */
    public const OFICIALES = [
        2026 => [
            '2026-01-01' => 'Año Nuevo',
            '2026-02-16' => 'Carnaval',
            '2026-02-17' => 'Carnaval',
            '2026-04-03' => 'Viernes Santo',
            '2026-05-01' => 'Día del Trabajo',
            '2026-05-25' => 'Batalla de Pichincha',
            '2026-08-10' => 'Primer Grito de Independencia',
            '2026-10-09' => 'Independencia de Guayaquil',
            '2026-11-02' => 'Día de los Difuntos',
            '2026-11-03' => 'Independencia de Cuenca',
            '2026-12-25' => 'Navidad',
        ],
    ];

    /** Si no hay internet para la base pública: lo que trajo la última vez que se revisó. */
    private const RESPALDO = [
        2027 => [
            '2027-01-01' => 'Año Nuevo',
            '2027-02-08' => 'Carnaval',
            '2027-02-09' => 'Carnaval',
            '2027-03-26' => 'Viernes Santo',
            '2027-04-30' => 'Día del Trabajo',
            '2027-05-24' => 'Batalla de Pichincha',
            '2027-08-09' => 'Primer Grito de Independencia',
            '2027-10-08' => 'Independencia de Guayaquil',
            '2027-11-01' => 'Día de los Difuntos',
            '2027-11-05' => 'Independencia de Cuenca',
            '2027-12-25' => 'Navidad',
        ],
    ];

    public const ORIGENES = ['oficial' => 'Calendario oficial', 'base' => 'Base pública', 'excel' => 'Excel de revisiones', 'manual' => 'Agregado a mano'];

    private static ?array $mapa = null;

    private static function repo(): JsonStore { return new JsonStore('feriados'); }

    /** ['Y-m-d' => nombre] de los feriados vigentes. Sin nada guardado todavía, los que trae el código. */
    public static function mapa(): array
    {
        if (self::$mapa !== null) return self::$mapa;
        $m = [];
        try {
            foreach (self::repo()->all() as $f) if (!empty($f['activo']) && !empty($f['fecha'])) $m[(string)$f['fecha']] = (string)($f['nombre'] ?? 'Feriado');
        } catch (Throwable $e) { /* sin base todavía: los del código */ }
        if (!$m) foreach (self::OFICIALES + self::RESPALDO as $lista) $m += $lista;
        ksort($m);
        return self::$mapa = $m;
    }

    public static function es(string $fecha): bool { return $fecha !== '' && isset(self::mapa()[substr($fecha, 0, 10)]); }

    public static function nombre(string $fecha): string { return self::mapa()[substr($fecha, 0, 10)] ?? ''; }

    /** Lunes a viernes y no feriado. */
    public static function esHabil(string $fecha): bool
    {
        $t = strtotime(substr($fecha, 0, 10));
        return $t !== false && (int)date('N', $t) <= 5 && !self::es(date('Y-m-d', $t));
    }

    /** El nombre del feriado de hoy, o '' si hoy se trabaja. */
    public static function hoy(): string { return self::nombre(date('Y-m-d')); }

    /** Todos los guardados (también los apagados), por fecha, para la página del calendario. */
    public static function lista(): array
    {
        $l = self::repo()->all();
        usort($l, fn($a, $b) => strcmp((string)$a['fecha'], (string)$b['fecha']));
        return $l;
    }

    /**
     * Los de un año según la fuente: la lista oficial si ya se conoce; si no, la
     * base pública (y sin internet, el respaldo). Devuelve [lista, fuente].
     */
    public static function delAnio(int $anio): array
    {
        if (isset(self::OFICIALES[$anio])) return [self::OFICIALES[$anio], 'oficial'];
        $crudo = self::bajar(sprintf(self::FUENTE, $anio));
        $lista = [];
        foreach ((array)(json_decode((string)$crudo, true) ?: []) as $f) {
            $fecha = (string)($f['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || (isset($f['global']) && !$f['global'])) continue;
            // «Día de los Difuntos, Día de Muertos» → «Día de los Difuntos»; dos el mismo día, juntos
            $nombre = trim(explode(',', (string)($f['localName'] ?? $f['name'] ?? 'Feriado'))[0]);
            $lista[$fecha] = isset($lista[$fecha]) && !str_contains($lista[$fecha], $nombre) ? $lista[$fecha] . ' · ' . $nombre : $nombre;
        }
        if ($lista) return [$lista, 'base'];
        return isset(self::RESPALDO[$anio]) ? [self::RESPALDO[$anio], 'respaldo'] : [[], 'ninguna'];
    }

    private static function bajar(string $url): string
    {
        if (function_exists('curl_init')) {
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'MCHub-feriados']);
            $r = curl_exec($c);
            $ok = (int)curl_getinfo($c, CURLINFO_HTTP_CODE) === 200;
            curl_close($c);
            return $ok && is_string($r) ? $r : '';
        }
        return (string)@file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 8, 'header' => "Accept: application/json\r\n"]]));
    }

    /** Trae los de un año y agrega los que falten (no toca los que ya están ni los apagados). */
    public static function sincronizar(int $anio, bool $seco = false): array
    {
        [$lista, $fuente] = self::delAnio($anio);
        $nuevos = [];
        foreach ($lista as $fecha => $nombre) {
            if (self::buscar($fecha)) continue;
            $nuevos[$fecha] = $nombre;
            if (!$seco) self::insertar($fecha, $nombre, $fuente === 'oficial' ? 'oficial' : 'base');
        }
        return ['nuevos' => $nuevos, 'fuente' => $fuente, 'total' => count($lista)];
    }

    /** Agrega (o vuelve a encender, con el nombre nuevo) el feriado de una fecha. */
    public static function agregar(string $fecha, string $nombre, string $origen = 'manual'): array
    {
        $fecha = self::fecha($fecha);
        if ($fecha === '') throw new RuntimeException('Esa fecha no es válida.');
        $nombre = mb_substr(trim($nombre), 0, 120) ?: 'Feriado';
        $ya = self::buscar($fecha);
        self::$mapa = null;
        if ($ya) {
            self::repo()->update((int)$ya['id'], ['nombre' => $nombre, 'activo' => true]);
            return self::repo()->find((int)$ya['id']);
        }
        return self::insertar($fecha, $nombre, isset(self::ORIGENES[$origen]) ? $origen : 'manual');
    }

    /** Ese día vuelve a ser hábil: el agregado a mano se borra; el que vino de una fuente se apaga. */
    public static function quitar(int $id): void
    {
        $f = self::repo()->find($id);
        if (!$f) return;
        self::$mapa = null;
        if (($f['origen'] ?? '') === 'manual') self::repo()->delete($id);
        else self::repo()->update($id, ['activo' => false]);
    }

    /** La primera vez: este año y el siguiente, más los feriados del Excel de Releases. Devuelve cuántos agregó. */
    public static function sembrar(array $delExcel = [], bool $seco = false): int
    {
        $n = 0;
        $anio = (int)date('Y');
        foreach ([$anio, $anio + 1] as $a) $n += count(self::sincronizar($a, $seco)['nuevos']);
        // ['Y-m-d' => nombre] o solo las fechas
        foreach ($delExcel as $k => $v) {
            [$fecha, $nombre] = is_int($k) ? [(string)$v, ''] : [(string)$k, trim((string)$v)];
            $fecha = self::fecha($fecha);
            if ($fecha === '' || self::buscar($fecha)) continue;
            $n++;
            if (!$seco) self::insertar($fecha, $nombre !== '' ? $nombre : 'Feriado (Excel de revisiones)', 'excel');
        }
        return $n;
    }

    private static function buscar(string $fecha): ?array
    {
        foreach (self::repo()->all() as $f) if (($f['fecha'] ?? '') === $fecha) return $f;
        return null;
    }

    private static function insertar(string $fecha, string $nombre, string $origen): array
    {
        self::$mapa = null;
        return self::repo()->insert(['fecha' => $fecha, 'nombre' => $nombre, 'origen' => $origen, 'activo' => true, 'creado' => date('Y-m-d H:i')]);
    }

    private static function fecha(string $f): string
    {
        $t = strtotime(trim($f));
        return $t ? date('Y-m-d', $t) : '';
    }
}
