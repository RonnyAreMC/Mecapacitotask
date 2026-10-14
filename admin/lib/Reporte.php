<?php
/**
 * Reportería: el resumen ejecutivo del portafolio —PowerPoint, Word y PDF—
 * armado SOLO con lo que hay en el panel: proyectos, tareas, observaciones y
 * requerimientos sueltos. No se edita ni se le agrega nada a mano: lo que
 * diga el reporte es lo que dice el panel.
 *
 * Nace de tener que hacerlo a mano en cada release: exportar el panorama,
 * agrupar las tareas por línea de trabajo, contar, maquetar en tres formatos...
 * Aquí el panel hace la cuenta una sola vez (construir()) y los tres formatos
 * salen de ese mismo modelo, así que no pueden contradecirse entre sí.
 *
 * Las tareas no tienen un campo de "módulo", así que las líneas de trabajo de
 * cada proyecto son palabras clave sobre el título (lib/reporteria/semilla.json,
 * ajustadas con las tareas reales). Lo que no cae en ninguna va a "Otras
 * actividades", y un proyecto que no está en la semilla sale con una sola fila.
 */
final class Reporte
{
    private const DIR = __DIR__ . '/reporteria';

    private static ?array $semilla = null;

    /** Títulos de la portada y líneas de trabajo por proyecto (por nombre normalizado). */
    public static function semilla(): array
    {
        if (self::$semilla === null) {
            $txt = @file_get_contents(self::DIR . '/semilla.json');
            self::$semilla = (json_decode((string)$txt, true) ?: []) + [
                'titulo' => 'Resumen de Release', 'subtitulo' => '', 'pie' => '', 'lineas' => [],
            ];
        }
        return self::$semilla;
    }

    /** Líneas de trabajo de un proyecto (las de la semilla, por nombre). */
    public static function lineasDe(array $proyecto): array
    {
        return array_values((array)(self::semilla()['lineas'][self::clave((string)$proyecto['nombre'])] ?? []));
    }

    /** Nombre comparable: minúsculas, sin tildes ni signos. */
    public static function clave(string $s): string
    {
        return trim(self::normalizar($s));
    }

    /**
     * Minúsculas, sin tildes y solo letras y números separados por un espacio,
     * con un espacio a cada lado: así " clave" encuentra la clave al inicio
     * de cualquier palabra. Es la misma normalización con la que se probaron
     * las claves de la semilla contra las tareas reales.
     */
    public static function normalizar(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'â' => 'a', 'ê' => 'e',
            'î' => 'i', 'ô' => 'o', 'û' => 'u', 'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ç' => 'c',
        ]);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        return ' ' . trim((string)$s) . ' ';
    }

    /** Claves de una línea, ya normalizadas: [['inicio' => bool, 'txt' => string], ...]. */
    private static function claves(string $texto): array
    {
        $out = [];
        foreach (explode(',', $texto) as $c) {
            $c = trim($c);
            if ($c === '') continue;
            $inicio = str_starts_with($c, '^');
            $n = rtrim(self::normalizar($inicio ? substr($c, 1) : $c));
            if (trim($n) === '') continue;
            $out[] = ['inicio' => $inicio, 'txt' => $n];
        }
        return $out;
    }

    /**
     * Índice de la línea a la que pertenece un título, o -1. Gana la PRIMERA
     * que coincida, así que el orden de las líneas importa: las más
     * específicas arriba. "^x" = el título empieza por x; si no, x tiene que
     * empezar una palabra ("reporte" cubre "reportes" y "reportería").
     */
    public static function clasificar(string $titulo, array $clavesPorLinea): int
    {
        $t = self::normalizar($titulo);
        foreach ($clavesPorLinea as $i => $claves) {
            foreach ($claves as $c) {
                if ($c['inicio'] ? str_starts_with($t, $c['txt']) : str_contains($t, $c['txt'])) {
                    return $i;
                }
            }
        }
        return -1;
    }

    /* ------------------------------------------------------------------ */

    /** El reporte con los datos de este panel. */
    public static function desdePanel(): array
    {
        $tareasRepo = new TareaRepo();
        $proyectos  = [];
        foreach ((new ProyectoRepo())->todos() as $p) {
            $p['_tareas'] = $tareasRepo->delProyecto((int)$p['id']);
            $proyectos[] = $p;
        }
        return self::construir(
            $proyectos,
            (new ObservacionRepo())->tareasConPendientes(),
            (new RequerimientoRepo())->todos(),
            array_keys(Catalogo::estadosTarea()),
            Catalogo::estadosFinales()
        );
    }

    /**
     * El modelo del reporte. Recibe los datos en crudo para poder probarlo
     * fuera del panel (con un panorama exportado, por ejemplo).
     *
     * Cómo se cuenta cada tarea —igual que el avance del panel—:
     *  - completada: en estado final y sin observaciones pendientes. Una
     *    terminada con una observación abierta va a "en curso", así
     *    completadas + en curso + por hacer suman el total y el % es el mismo
     *    que muestra el panel.
     *  - por hacer: el primer estado del catálogo (el de partida).
     *  - en curso: cualquier otro.
     */
    public static function construir(array $proyectos, array $observadas, array $requerimientos,
                                     array $ordenEstados, array $finales): array
    {
        $inicial = '';
        foreach ($ordenEstados as $k) {
            if (!in_array($k, $finales, true)) { $inicial = $k; break; }
        }

        $cubo = function (array $t) use ($finales, $inicial, $observadas): string {
            $e = (string)($t['estado'] ?? '');
            if (in_array($e, $finales, true)) {
                return !empty($observadas[(int)($t['id'] ?? 0)]) ? 'observada' : 'completada';
            }
            return $e === $inicial ? 'por_hacer' : 'en_curso';
        };
        $vacio = fn() => ['entregables' => 0, 'completados' => 0, 'en_curso' => 0, 'por_hacer' => 0, 'observadas' => 0];
        $sumar = function (array &$c, string $cubo) {
            $c['entregables']++;
            if ($cubo === 'completada')      $c['completados']++;
            elseif ($cubo === 'por_hacer')   $c['por_hacer']++;
            else {
                $c['en_curso']++;
                if ($cubo === 'observada') $c['observadas']++;
            }
        };

        $salida = [];
        $global = $vacio();
        foreach ($proyectos as $p) {
            $tareas = (array)($p['_tareas'] ?? []);
            if (!$tareas) continue;   // un proyecto sin tareas no tiene nada que contar

            $lineas = self::lineasDe($p);
            $claves = array_map(fn($l) => self::claves((string)($l['claves'] ?? '')), $lineas);
            $porLin = array_map(fn() => $vacio(), $lineas);
            $otras  = $vacio();
            $tot    = $vacio();
            foreach ($tareas as $t) {
                $c = $cubo($t);
                $sumar($tot, $c);
                $sumar($global, $c);
                $i = $lineas ? self::clasificar((string)($t['titulo'] ?? ''), $claves) : -1;
                if ($i >= 0) $sumar($porLin[$i], $c); else $sumar($otras, $c);
            }
            $filas = [];
            foreach ($lineas as $i => $l) {
                if ($porLin[$i]['entregables'] === 0) continue;   // una línea vacía solo es ruido
                $filas[] = ['nombre' => trim((string)$l['nombre'])] + $porLin[$i];
            }
            if ($otras['entregables'] > 0) {
                $filas[] = ['nombre' => $lineas ? 'Otras actividades' : 'Actividades del proyecto'] + $otras;
            }
            $salida[] = [
                'id'     => (int)$p['id'],
                'nombre' => (string)$p['nombre'],
                'avance' => self::pct($tot['completados'], $tot['entregables']),
                'lineas' => $filas,
            ] + $tot;
        }
        // Más avanzados primero; a igual avance, el más grande.
        usort($salida, fn($a, $b) => [$b['avance'], $b['entregables']] <=> [$a['avance'], $a['entregables']]);

        /* ---- Requerimientos sueltos ---- */
        $req = ['total' => 0, 'resueltos' => 0, 'vencidos' => 0, 'abiertos' => []];
        foreach ($requerimientos as $r) {
            $req['total']++;
            if (RequerimientoRepo::estadoValido($r['estado'] ?? '') === 'hecho') $req['resueltos']++;
            if (RequerimientoRepo::cerrado($r)) continue;
            $venc = RequerimientoRepo::vencido($r);
            if ($venc) $req['vencidos']++;
            $req['abiertos'][] = [
                'titulo'  => (string)($r['titulo'] ?? ''),
                'entrega' => RequerimientoRepo::fechaFin($r),
                'vencido' => $venc,
            ];
        }
        // Vencidos arriba: es lo que hay que mirar.
        usort($req['abiertos'], fn($a, $b) => [$b['vencido'], $a['entrega'] ?: '9999'] <=> [$a['vencido'], $b['entrega'] ?: '9999']);

        $global['cumplimiento'] = self::pct($global['completados'], $global['entregables']);
        $global['proyectos']    = count($salida);

        $sem = self::semilla();
        $rep = [
            'titulo'         => (string)$sem['titulo'],
            'subtitulo'      => (string)$sem['subtitulo'],
            'pie'            => (string)$sem['pie'],
            'generado'       => date('Y-m-d H:i'),
            'resumen'        => $global,
            'proyectos'      => $salida,
            'requerimientos' => $req,
        ];
        $rep['conclusiones'] = self::conclusiones($rep);
        return $rep;
    }

    private static function pct(int $parte, int $total): int
    {
        return $total > 0 ? (int)round($parte * 100 / $total) : 0;
    }

    /**
     * La lectura ejecutiva, escrita con las cifras del momento: antes se
     * redactaba a mano y quedaba vieja en cuanto cambiaba un número.
     */
    private static function conclusiones(array $rep): array
    {
        $R  = $rep['resumen'];
        $ps = $rep['proyectos'];
        if (!$ps) return [];

        $out = [sprintf('El portafolio alcanza un %d%% de cumplimiento, con %d de los %d entregables completados.',
            $R['cumplimiento'], $R['completados'], $R['entregables'])];

        $cerrados = array_values(array_map(fn($p) => $p['nombre'], array_filter($ps, fn($p) => $p['avance'] === 100)));
        if ($cerrados) {
            $n = count($cerrados);
            $num = [1 => 'Un proyecto está cerrado', 2 => 'Dos proyectos están cerrados', 3 => 'Tres proyectos están cerrados',
                    4 => 'Cuatro proyectos están cerrados', 5 => 'Cinco proyectos están cerrados',
                    6 => 'Seis proyectos están cerrados'][$n] ?? $n . ' proyectos están cerrados';
            $out[] = $num . ' al 100%: ' . self::lista($cerrados) . '.';
        }

        if (count($ps) >= 3 && $R['entregables'] > 0) {
            $grandes = $ps;
            usort($grandes, fn($a, $b) => $b['entregables'] <=> $a['entregables']);
            [$a, $b] = [$grandes[0], $grandes[1]];
            $out[] = sprintf('El esfuerzo se concentra en %s (%d entregables) y %s (%d): juntos representan el %d%% del portafolio.',
                $a['nombre'], $a['entregables'], $b['nombre'], $b['entregables'],
                self::pct($a['entregables'] + $b['entregables'], $R['entregables']));
        }

        $rezagado = $ps[count($ps) - 1];
        if ($rezagado['avance'] < 50) {
            $out[] = sprintf('%s, con un %d%% de avance, es el proyecto más rezagado y requiere atención de la dirección.',
                $rezagado['nombre'], $rezagado['avance']);
        }
        if ($R['observadas'] > 0) {
            $out[] = sprintf('%d %s con observaciones abiertas: cuentan como en curso hasta que se resuelvan.',
                $R['observadas'], $R['observadas'] === 1 ? 'entregable terminado sigue' : 'entregables terminados siguen');
        }
        $v = $rep['requerimientos']['vencidos'];
        if ($v > 0) {
            $out[] = $v === 1 ? 'Un requerimiento suelto está vencido y debe reprogramarse o cerrarse.'
                              : sprintf('%d requerimientos sueltos están vencidos y deben reprogramarse o cerrarse.', $v);
        }
        return $out;
    }

    /** "A, B y C". */
    public static function lista(array $xs): string
    {
        $xs = array_values($xs);
        if (count($xs) <= 1) return implode('', $xs);
        $ult = array_pop($xs);
        return implode(', ', $xs) . ' y ' . $ult;
    }

    /** Nombre de archivo de una descarga: INNOTECH-Resumen-de-Release-2026-10-14.pptx */
    public static function nombreArchivo(array $rep, string $ext): string
    {
        $ascii = function_exists('iconv') ? (string)@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $rep['titulo']) : '';
        if ($ascii === '') $ascii = ucwords(self::clave($rep['titulo']));
        $base = trim((string)preg_replace('/[^A-Za-z0-9]+/', '-', $ascii), '-') ?: 'Reporte';
        return 'INNOTECH-' . $base . '-' . date('Y-m-d') . '.' . $ext;
    }

    /** Pieza fija de una plantilla (logos, maestros...). */
    public static function plantilla(string $tipo): array
    {
        static $cache = [];
        if (!isset($cache[$tipo])) {
            $cache[$tipo] = json_decode((string)@file_get_contents(self::DIR . '/plantilla-' . $tipo . '.json'), true) ?: [];
        }
        return $cache[$tipo];
    }

    /** Logo como data: URI para la vista HTML (lib/ no se sirve por web). */
    public static function logoDataUri(string $cual): string
    {
        $f = self::DIR . '/logo-' . ($cual === 'blanco' ? 'blanco' : 'color') . '.png';
        return is_file($f) ? 'data:image/png;base64,' . base64_encode((string)file_get_contents($f)) : '';
    }

    /** Banda institucional del pie del formato Word, para la vista de impresión. */
    public static function pieDataUri(): string
    {
        $b64 = self::plantilla('docx')['word/media/image2.png'] ?? '';
        return $b64 !== '' ? 'data:image/png;base64,' . $b64 : '';
    }
}

/**
 * ZIP mínimo (solo escritura). Un .pptx y un .docx son ZIPs de XML, y el
 * hosting no trae ZipArchive: con zlib (gzdeflate) basta para armarlos.
 */
final class ZipSimple
{
    private array $entradas = [];
    private string $datos = '';

    public function agregar(string $nombre, string $contenido): void
    {
        $crc   = crc32($contenido);
        // Sin zlib se guarda tal cual (método 0): pesa más, pero Office lo abre igual.
        $zlib  = function_exists('gzdeflate');
        $comp  = $zlib ? gzdeflate($contenido, 6) : $contenido;
        $met   = $zlib ? 8 : 0;
        [$hora, $fecha] = self::dos();
        $off   = strlen($this->datos);
        $this->datos .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $met, $hora, $fecha,
                             $crc, strlen($comp), strlen($contenido), strlen($nombre), 0)
                      . $nombre . $comp;
        $this->entradas[] = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $met, $hora, $fecha,
                                 $crc, strlen($comp), strlen($contenido), strlen($nombre), 0, 0, 0, 0, 0, $off)
                          . $nombre;
    }

    public function binario(): string
    {
        $dir = implode('', $this->entradas);
        return $this->datos . $dir
             . pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->entradas), count($this->entradas),
                    strlen($dir), strlen($this->datos), 0);
    }

    private static function dos(): array
    {
        $t = getdate();
        return [
            ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2),
            (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'],
        ];
    }
}
