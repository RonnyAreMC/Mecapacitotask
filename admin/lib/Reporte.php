<?php
/**
 * Reportería: el resumen ejecutivo del portafolio —PowerPoint, Word y PDF—
 * armado con los datos vivos del panel.
 *
 * Nace de tener que hacerlo a mano en cada release: exportar el panorama,
 * agrupar las tareas por línea de trabajo, contar, maquetar en tres formatos...
 * Aquí el panel hace la cuenta una sola vez (construir()) y los tres formatos
 * salen de ese mismo modelo, así que no pueden contradecirse entre sí.
 *
 * Las tareas no tienen un campo de "módulo", así que las líneas de trabajo se
 * definen con palabras clave sobre el título (clasificar()). Lo que no cae en
 * ninguna va a "Otras actividades": es la señal de que hay que afinar claves.
 *
 * La configuración vive en data/reporteria.json y no en config.json: la
 * acción de Ajustes reescribe config.json entero con las claves que conoce, y
 * esto se perdería cada vez que alguien guarda los ajustes.
 */
final class Reporte
{
    private const DIR = __DIR__ . '/reporteria';

    private static ?array $semilla = null;

    public static function archivoConfig(): string
    {
        return __DIR__ . '/../data/reporteria.json';
    }

    /** Lo que trae el panel de fábrica: las líneas del primer reporte hecho a mano. */
    public static function semilla(): array
    {
        if (self::$semilla === null) {
            $txt = @file_get_contents(self::DIR . '/semilla.json');
            self::$semilla = json_decode((string)$txt, true) ?: [];
        }
        return self::$semilla + [
            'titulo' => 'Resumen de Release', 'subtitulo' => '', 'pie' => '',
            'descontar_observadas' => true, 'lineas' => [], 'secciones' => [],
            'conclusiones_extra' => [], 'excluir' => [],
        ];
    }

    /** Configuración guardada; lo que falte, de la semilla. */
    public static function config(): array
    {
        $sem = self::semilla();
        $g = [];
        if (is_file(self::archivoConfig())) {
            $g = json_decode((string)file_get_contents(self::archivoConfig()), true) ?: [];
        }
        $texto = fn(string $k) => trim((string)($g[$k] ?? '')) ?: (string)$sem[$k];
        return [
            'titulo'               => $texto('titulo'),
            'subtitulo'            => $texto('subtitulo'),
            'pie'                  => $texto('pie'),
            'descontar_observadas' => array_key_exists('descontar_observadas', $g)
                                        ? !empty($g['descontar_observadas']) : !empty($sem['descontar_observadas']),
            // Por id de proyecto. Las de la semilla van por nombre: ver lineasDe().
            'lineas'               => (array)($g['lineas'] ?? []),
            'secciones'            => array_values((array)($g['secciones'] ?? $sem['secciones'])),
            'conclusiones_extra'   => array_values((array)($g['conclusiones_extra'] ?? $sem['conclusiones_extra'])),
            'excluir'              => array_values(array_map('intval', (array)($g['excluir'] ?? []))),
            'guardada'             => $g !== [],
        ];
    }

    public static function guardarConfig(array $cfg): void
    {
        unset($cfg['guardada']);
        file_put_contents(
            self::archivoConfig(),
            json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * Líneas de un proyecto: las guardadas para su id o, si nunca se guardaron,
     * las de la semilla por nombre (los ids de la semilla no serían los de
     * esta instalación).
     */
    public static function lineasDe(array $cfg, array $proyecto): array
    {
        $id = (int)$proyecto['id'];
        if (array_key_exists($id, $cfg['lineas'])) {
            return array_values((array)$cfg['lineas'][$id]);
        }
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

    /** Claves de una línea, ya normalizadas: [['prefijo' => bool, 'txt' => string], ...]. */
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
    public static function desdePanel(?array $cfg = null): array
    {
        $cfg ??= self::config();
        $tareasRepo = new TareaRepo();
        $proyectos  = [];
        foreach ((new ProyectoRepo())->todos() as $p) {
            $p['_tareas'] = $tareasRepo->delProyecto((int)$p['id']);
            $proyectos[] = $p;
        }
        $estados = [];
        foreach (Catalogo::estadosTarea() as $k => $v) {
            $estados[$k] = $v[0];
        }
        return self::construir(
            $proyectos,
            (new ObservacionRepo())->tareasConPendientes(),
            (new RequerimientoRepo())->todos(),
            array_keys($estados),
            Catalogo::estadosFinales(),
            $cfg
        );
    }

    /**
     * El modelo del reporte. Recibe los datos en crudo para poder probarlo
     * fuera del panel (con un panorama exportado, por ejemplo).
     *
     * Cómo se cuenta cada tarea:
     *  - completada: en estado final; si "descontar observadas" está activo
     *    (por defecto, como el avance del panel), además sin observaciones
     *    pendientes. Una terminada con una observación abierta va a "en curso":
     *    así completadas + en curso + por hacer suman el total y el % cuadra.
     *  - por hacer: el primer estado del catálogo (el de partida).
     *  - en curso: cualquier otro.
     */
    public static function construir(array $proyectos, array $observadas, array $requerimientos,
                                     array $ordenEstados, array $finales, array $cfg): array
    {
        $inicial  = '';
        foreach ($ordenEstados as $k) {
            if (!in_array($k, $finales, true)) { $inicial = $k; break; }
        }
        $descontar = !empty($cfg['descontar_observadas']);

        $cubo = function (array $t) use ($finales, $inicial, $observadas, $descontar): string {
            $e = (string)($t['estado'] ?? '');
            if (in_array($e, $finales, true)) {
                return ($descontar && !empty($observadas[(int)($t['id'] ?? 0)])) ? 'observada' : 'completada';
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

        $secciones = array_map([self::class, 'seccionLimpia'], (array)($cfg['secciones'] ?? []));
        $excluir   = array_map('intval', (array)($cfg['excluir'] ?? []));

        $salida = [];
        $global = $vacio();
        foreach ($proyectos as $p) {
            $pid    = (int)$p['id'];
            $tareas = (array)($p['_tareas'] ?? []);
            if (in_array($pid, $excluir, true) || !$tareas) continue;

            $lineas  = self::lineasDe($cfg, $p);
            $claves  = array_map(fn($l) => self::claves((string)($l['claves'] ?? '')), $lineas);
            $porLin  = array_map(fn() => $vacio(), $lineas);
            $otras   = $vacio();
            $sueltas = [];
            $tot     = $vacio();
            foreach ($tareas as $t) {
                $c = $cubo($t);
                $sumar($tot, $c);
                $sumar($global, $c);
                $i = $lineas ? self::clasificar((string)($t['titulo'] ?? ''), $claves) : -1;
                if ($i >= 0) {
                    $sumar($porLin[$i], $c);
                } else {
                    $sumar($otras, $c);
                    if ($lineas) $sueltas[] = (string)($t['titulo'] ?? '');
                }
            }
            $filas = [];
            foreach ($lineas as $i => $l) {
                if ($porLin[$i]['entregables'] === 0) continue;   // una línea vacía solo es ruido
                $filas[] = ['nombre' => trim((string)$l['nombre'])] + $porLin[$i];
            }
            if ($otras['entregables'] > 0) {
                $filas[] = ['nombre' => $lineas ? 'Otras actividades' : 'Actividades del proyecto', 'otras' => true] + $otras;
            }
            $salida[] = [
                'id'        => $pid,
                'nombre'    => (string)$p['nombre'],
                'avance'    => self::pct($tot['completados'], $tot['entregables']),
                'lineas'    => $filas,
                'otras'     => $lineas ? $otras['entregables'] : 0,
                // Para afinar las claves en la configuración: qué quedó fuera.
                'otras_titulos' => array_slice($sueltas, 0, 40),
                'lineas_conf'   => array_map(fn($l, $c) => ['nombre' => (string)$l['nombre'], 'claves' => (string)($l['claves'] ?? ''),
                                                            'entregables' => $c['entregables']], $lineas, $porLin),
                'secciones' => array_values(array_filter($secciones, fn($s) =>
                    $s['ubicacion'] === 'proyecto' && self::esDelProyecto($s, $p))),
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
        $req['secciones'] = array_values(array_filter($secciones, fn($s) => $s['ubicacion'] === 'requerimientos'));

        $global['cumplimiento'] = self::pct($global['completados'], $global['entregables']);
        $global['proyectos']    = count($salida);

        $rep = [
            'titulo'      => (string)$cfg['titulo'],
            'subtitulo'   => (string)$cfg['subtitulo'],
            'pie'         => (string)$cfg['pie'],
            'generado'    => date('Y-m-d H:i'),
            'descontar'   => $descontar,
            'resumen'     => $global,
            'proyectos'   => $salida,
            'requerimientos' => $req,
            // Las de un proyecto que ya no está en el reporte van al final:
            // mejor que perderlas en silencio.
            'secciones_finales' => array_values(array_filter($secciones, function ($s) use ($salida) {
                if ($s['ubicacion'] === 'final') return true;
                if ($s['ubicacion'] !== 'proyecto') return false;
                foreach ($salida as $p) {
                    if (self::esDelProyecto($s, ['id' => $p['id'], 'nombre' => $p['nombre']])) return false;
                }
                return true;
            })),
        ];
        $rep['conclusiones'] = self::conclusiones($rep, (array)($cfg['conclusiones_extra'] ?? []));
        return $rep;
    }

    private static function pct(int $parte, int $total): int
    {
        return $total > 0 ? (int)round($parte * 100 / $total) : 0;
    }

    /** ¿La sección va dentro de este proyecto? Por id si se guardó; si no, por nombre (semilla). */
    private static function esDelProyecto(array $s, array $p): bool
    {
        if ((int)($s['proyecto_id'] ?? 0) > 0) return (int)$s['proyecto_id'] === (int)$p['id'];
        return ($s['proyecto'] ?? '') !== '' && self::clave((string)$s['proyecto']) === self::clave((string)$p['nombre']);
    }

    /** Una sección con todas sus claves y sin filas vacías. */
    public static function seccionLimpia(array $s): array
    {
        $ubic = in_array($s['ubicacion'] ?? '', ['proyecto', 'requerimientos', 'final'], true) ? $s['ubicacion'] : 'final';
        $cols = array_values(array_filter(array_map('trim', array_map('strval', (array)($s['columnas'] ?? [])))));
        $filas = [];
        foreach ((array)($s['filas'] ?? []) as $f) {
            $f = array_map(fn($v) => trim((string)$v), (array)$f);
            if (implode('', $f) === '') continue;
            $filas[] = $cols ? array_slice(array_pad($f, count($cols), ''), 0, count($cols)) : $f;
        }
        if (!$cols && $filas) {
            $cols = array_fill(0, max(array_map('count', $filas)), '');
        }
        $kpis = [];
        foreach ((array)($s['kpis'] ?? []) as $k) {
            $k = array_values((array)$k);
            if (trim((string)($k[0] ?? '')) === '') continue;
            $kpis[] = [trim((string)$k[0]), trim((string)($k[1] ?? ''))];
        }
        return [
            'id'          => (string)($s['id'] ?? '') ?: substr(md5((string)($s['titulo'] ?? '')), 0, 8),
            'ubicacion'   => $ubic,
            'proyecto'    => (string)($s['proyecto'] ?? ''),
            'proyecto_id' => (int)($s['proyecto_id'] ?? 0),
            'kicker'      => trim((string)($s['kicker'] ?? '')),
            'titulo'      => trim((string)($s['titulo'] ?? '')) ?: 'Sección',
            'intro'       => trim((string)($s['intro'] ?? '')),
            'kpis'        => array_slice($kpis, 0, 5),
            'columnas'    => $cols,
            'filas'       => $filas,
            'nota'        => trim((string)($s['nota'] ?? '')),
        ];
    }

    /**
     * La lectura ejecutiva. Se escribe sola con las cifras del momento —antes
     * se redactaba a mano y se quedaba vieja en cuanto cambiaba un número—, y
     * al final van las que el administrador agregue a mano.
     */
    private static function conclusiones(array $rep, array $extra): array
    {
        $R  = $rep['resumen'];
        $ps = $rep['proyectos'];
        $out = [];
        if (!$ps) return array_values(array_filter(array_map('trim', $extra)));

        $out[] = sprintf('El portafolio alcanza un %d%% de cumplimiento, con %d de los %d entregables completados.',
            $R['cumplimiento'], $R['completados'], $R['entregables']);

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
        if ($rep['descontar'] && $R['observadas'] > 0) {
            $out[] = sprintf('%d %s con observaciones abiertas: cuentan como en curso hasta que se resuelvan.',
                $R['observadas'], $R['observadas'] === 1 ? 'entregable terminado sigue' : 'entregables terminados siguen');
        }
        $v = $rep['requerimientos']['vencidos'];
        if ($v > 0) {
            $out[] = $v === 1 ? 'Un requerimiento suelto está vencido y debe reprogramarse o cerrarse.'
                              : sprintf('%d requerimientos sueltos están vencidos y deben reprogramarse o cerrarse.', $v);
        }
        foreach ($extra as $x) {
            if (trim((string)$x) !== '') $out[] = trim((string)$x);
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

    /** Nombre de archivo de una descarga: INNOTECH-Resumen-Release-2026-10-14.pptx */
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
