<?php
/**
 * ImportadorEquipo - carga colaboradores desde una hoja de calculo.
 *
 * Acepta el .xlsx de Excel tal cual y tambien .csv. Columnas (el orden da
 * igual, se guia por la cabecera): Apellidos, Nombres, Correo, Equipo y,
 * opcional, Rol.
 *
 * Es idempotente: si el correo o el nombre ya existen, ACTUALIZA esa ficha
 * en vez de crear una copia. Nunca borra a nadie ni toca contraseñas ni
 * permisos: cargar gente aqui no le da acceso al panel.
 *
 * Lo usan la pantalla de Equipo (subir archivo) y seed_equipo.php (terminal).
 */
require_once __DIR__ . '/Models.php';

final class ImportadorEquipo
{
    /** Extensiones que sabemos leer. */
    public const EXTENSIONES = ['xlsx', 'csv', 'txt'];

    /* =========================================================
       1. Leer el archivo -> filas normalizadas
       ========================================================= */

    /**
     * Devuelve [['apellidos'=>…, 'nombres'=>…, 'correo'=>…, 'equipo'=>…, 'rol'=>…], …].
     * Lanza RuntimeException con un motivo entendible si el archivo no sirve.
     */
    public static function leer(string $ruta, string $nombreOriginal = ''): array
    {
        $ext = strtolower(pathinfo($nombreOriginal !== '' ? $nombreOriginal : $ruta, PATHINFO_EXTENSION));
        $tabla = match ($ext) {
            'xlsx'        => self::tablaXlsx($ruta),
            'csv', 'txt'  => self::tablaCsv($ruta),
            'xls'         => throw new RuntimeException('El formato .xls es el viejo de Excel y no se puede leer. Ábrelo y guárdalo como .xlsx o como CSV.'),
            default       => throw new RuntimeException('Solo se admiten archivos .xlsx o .csv.'),
        };
        if (count($tabla) < 2) {
            throw new RuntimeException('La hoja está vacía o solo tiene la fila de títulos.');
        }
        return self::mapear($tabla);
    }

    /** Quita tildes y mayusculas: para comparar cabeceras, equipos y nombres. */
    public static function normalizar(string $t): string
    {
        $t = mb_strtolower(trim($t), 'UTF-8');
        $t = strtr($t, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n','à'=>'a','è'=>'e','ç'=>'c']);
        return (string)preg_replace('/\s+/', ' ', $t);
    }

    /** La cabecera manda: da igual el orden y si dice "Correo personal". */
    private static function mapear(array $tabla): array
    {
        $cabecera = array_map(fn($c) => self::normalizar((string)$c), array_shift($tabla));
        $col = [];
        foreach ($cabecera as $i => $titulo) {
            $clave = match (true) {
                str_contains($titulo, 'apellido') => 'apellidos',
                str_contains($titulo, 'nombre')   => 'nombres',
                str_contains($titulo, 'correo') || str_contains($titulo, 'mail') => 'correo',
                str_contains($titulo, 'proyecto') || str_contains($titulo, 'project') => 'proyecto',
                str_contains($titulo, 'equipo') || str_contains($titulo, 'area') => 'equipo',
                str_contains($titulo, 'rol') || str_contains($titulo, 'cargo')   => 'rol',
                default => '',
            };
            if ($clave !== '' && !isset($col[$clave])) $col[$clave] = $i;
        }
        if (!isset($col['nombres']) && !isset($col['apellidos'])) {
            throw new RuntimeException(
                'No encuentro las columnas de nombres y apellidos. La primera fila tiene que ser la de títulos '
                . '(leí: ' . (implode(' | ', array_filter($cabecera)) ?: 'nada') . ').'
            );
        }

        $filas = [];
        foreach ($tabla as $n => $f) {
            $dato = fn(string $k) => isset($col[$k]) ? trim((string)($f[$col[$k]] ?? '')) : '';
            $fila = [
                'linea'     => $n + 2,          // +2: la 1 es la cabecera y las hojas empiezan en 1
                'apellidos' => $dato('apellidos'),
                'nombres'   => $dato('nombres'),
                'correo'    => mb_strtolower($dato('correo')),
                'equipo'    => $dato('equipo'),
                'proyecto'  => $dato('proyecto'),
                'rol'       => $dato('rol'),
            ];
            if ($fila['apellidos'] === '' && $fila['nombres'] === '') continue;   // fila vacía
            $filas[] = $fila;
        }
        if (!$filas) {
            throw new RuntimeException('No hay ninguna fila con nombre debajo de los títulos.');
        }
        return $filas;
    }

    /* ---------- CSV ---------- */

    private static function tablaCsv(string $ruta): array
    {
        $texto = (string)file_get_contents($ruta);
        // Excel guarda con BOM o en ANSI segun la version; sin esto los
        // apellidos con tilde ("Ordoñez") entran rotos.
        $texto = (string)preg_replace('/^\xEF\xBB\xBF/', '', $texto);
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        $lineas = preg_split('/\r\n|\r|\n/', trim($texto)) ?: [];
        if (!$lineas || $lineas[0] === '') return [];

        // Excel en español separa con ';' y en inglés con ','
        $sep = substr_count($lineas[0], ';') >= substr_count($lineas[0], ',') ? ';' : ',';
        return array_map(fn($l) => str_getcsv($l, $sep, '"', ''), $lineas);
    }

    /* ---------- XLSX ----------
       Un .xlsx es un ZIP con XML dentro. Se leen dos piezas: la tabla de
       textos compartidos y la primera hoja. */

    private static function tablaXlsx(string $ruta): array
    {
        $hoja = self::delZip($ruta, 'xl/worksheets/sheet1.xml');
        if ($hoja === null) {
            throw new RuntimeException('El archivo no parece un Excel válido (no encuentro la primera hoja).');
        }
        $compartidos = self::textosCompartidos(self::delZip($ruta, 'xl/sharedStrings.xml'));

        $xml = @simplexml_load_string($hoja);
        if (!$xml) {
            throw new RuntimeException('No se pudo leer la hoja del Excel.');
        }

        $tabla = [];
        foreach ($xml->sheetData->row as $fila) {
            $celdas = [];
            foreach ($fila->c as $c) {
                // La referencia ("C7") dice la columna real: sin esto, una
                // celda vacía correría las demás una posición a la izquierda.
                $i = self::indiceColumna((string)$c['r']);
                $tipo = (string)$c['t'];
                $valor = match ($tipo) {
                    's'         => $compartidos[(int)$c->v] ?? '',
                    'inlineStr' => trim((string)($c->is->t ?? '')),
                    default     => trim((string)($c->v ?? '')),
                };
                $celdas[$i >= 0 ? $i : count($celdas)] = $valor;
            }
            if (!$celdas) { $tabla[] = []; continue; }
            // Rellena los huecos para que las posiciones cuadren con la cabecera
            $tabla[] = array_replace(array_fill(0, max(array_keys($celdas)) + 1, ''), $celdas);
        }
        return $tabla;
    }

    /** "C7" -> 2 (índice de columna, base 0). */
    private static function indiceColumna(string $ref): int
    {
        if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) return -1;
        $n = 0;
        foreach (str_split($m[1]) as $letra) {
            $n = $n * 26 + (ord($letra) - 64);
        }
        return $n - 1;
    }

    /** Tabla de textos compartidos de un xlsx (los strings no van en la hoja). */
    private static function textosCompartidos(?string $xmlTexto): array
    {
        if ($xmlTexto === null) return [];
        $xml = @simplexml_load_string($xmlTexto);
        if (!$xml) return [];
        $out = [];
        foreach ($xml->si as $si) {
            // Un texto con formato viene troceado en varios <r><t>
            $out[] = isset($si->t) && count($si->r ?? []) === 0
                ? (string)$si->t
                : implode('', array_map(fn($r) => (string)$r->t, iterator_to_array($si->r ?? [])));
        }
        return $out;
    }

    /**
     * Saca un archivo del ZIP. Usa ZipArchive si el servidor la tiene y, si
     * no, lee el directorio central a mano: hay hostings con la extension zip
     * desactivada y no vamos a pedirle al usuario que convierta el archivo.
     */
    private static function delZip(string $ruta, string $interno): ?string
    {
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            if ($zip->open($ruta) === true) {
                $datos = $zip->getFromName($interno);
                $zip->close();
                return $datos === false ? null : $datos;
            }
            return null;
        }
        return self::delZipManual($ruta, $interno);
    }

    private static function delZipManual(string $ruta, string $interno): ?string
    {
        $bin = (string)file_get_contents($ruta);
        // Fin del directorio central (EOCD), buscando desde el final
        $fin = strrpos($bin, "PK\x05\x06");
        if ($fin === false) return null;
        $eocd = unpack('vdisco/vdiscoCd/ventradasDisco/ventradas/Vtam/Vinicio', substr($bin, $fin + 4, 18));
        $p = $eocd['inicio'];

        for ($i = 0; $i < $eocd['entradas']; $i++) {
            if (substr($bin, $p, 4) !== "PK\x01\x02") return null;
            $c = unpack('vverHecho/vverNec/vflags/vmetodo/vhora/vfecha/Vcrc/Vcomp/Vsin/vnombre/vextra/vcoment/vdisco/vattrInt/VattrExt/Vlocal', substr($bin, $p + 4, 42));
            $nombre = substr($bin, $p + 46, $c['nombre']);
            if ($nombre === $interno) {
                // El encabezado local repite nombre y extra con otro tamaño
                $l = unpack('vver/vflags/vmetodo/vhora/vfecha/Vcrc/Vcomp/Vsin/vnombre/vextra', substr($bin, $c['local'] + 4, 26));
                $ini = $c['local'] + 30 + $l['nombre'] + $l['extra'];
                $datos = substr($bin, $ini, $c['comp']);
                return $c['metodo'] === 0 ? $datos : (@gzinflate($datos) ?: null);
            }
            $p += 46 + $c['nombre'] + $c['extra'] + $c['coment'];
        }
        return null;
    }

    /* =========================================================
       2. Aplicar las filas sobre el equipo
       ========================================================= */

    /**
     * Con $seco = true solo calcula qué pasaría (para la previsualización).
     * Devuelve ['detalle' => [...], 'nuevos', 'actualizados', 'iguales',
     *           'avisos' => [...], 'sinEquipo' => n].
     */
    public static function aplicar(array $filas, bool $seco = true): array
    {
        $repo     = new MiembroRepo();
        $actuales = $repo->todos();
        $equipos  = Catalogo::equipos();
        $defecto  = array_key_first($equipos);

        $porEmail = [];
        $porNombre = [];
        foreach ($actuales as $m) {
            if (!empty($m['email'])) $porEmail[mb_strtolower($m['email'])] = $m;
            $porNombre[self::normalizar($m['nombre'] ?? '')] = $m;
        }

        // Proyectos por nombre, para la columna que los vincula al cargar
        $proyectoRepo = new ProyectoRepo();
        $proyectos = [];
        foreach ($proyectoRepo->todos() as $p) {
            $proyectos[self::normalizar($p['nombre'] ?? '')] = $p;
        }

        $r = ['detalle' => [], 'nuevos' => 0, 'actualizados' => 0, 'iguales' => 0,
              'avisos' => [], 'sinEquipo' => 0, 'proyectos' => [],
              'sinProyecto' => 0, 'desconocidos' => []];
        $color = count($actuales);
        $sumar = [];          // proyecto id => [ids de miembros a añadir]

        foreach ($filas as $f) {
            // El panel guarda un solo campo "nombre": nombres primero
            $nombre = trim($f['nombres'] . ' ' . $f['apellidos']);
            $correo = trim($f['correo']);
            if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $r['avisos'][] = 'fila ' . $f['linea'] . ': «' . $correo . '» no es un correo válido, entra sin correo';
                $correo = '';
            }

            // La columna puede traer un equipo del panel ("Programadores") o el
            // nombre de un proyecto ("Delta"): se prueba primero como equipo y,
            // si no calza, se busca entre los proyectos. Así vale la hoja tal
            // como la tienen, con una sola columna.
            $textoEquipo   = trim($f['equipo']);
            $textoProyecto = trim($f['proyecto']);
            $equipo = self::equipoDe($textoEquipo, $equipos);
            if ($equipo === null && $textoEquipo !== '' && $textoProyecto === '') {
                $textoProyecto = $textoEquipo;
                $textoEquipo   = '';
            }
            if ($equipo === null) {
                $equipo = $defecto;
                if ($textoEquipo !== '') {
                    $r['avisos'][] = 'fila ' . $f['linea'] . ': el equipo «' . $textoEquipo . '» no existe, va a ' . $equipos[$defecto][0];
                } elseif ($textoProyecto === '') {
                    $r['sinEquipo']++;
                }
            }

            // Proyecto al que se le vincula (tiene que existir ya en el panel)
            $proyecto = null;
            if ($textoProyecto !== '') {
                $clave = self::normalizar($textoProyecto);
                $proyecto = $proyectos[$clave] ?? null;
                if (!$proyecto) {
                    foreach ($proyectos as $k => $p) {
                        if (str_starts_with($k, $clave) || str_starts_with($clave, $k)) { $proyecto = $p; break; }
                    }
                }
                if (!$proyecto) {
                    // Que no exista no bloquea la carga: la persona entra igual
                    // como colaboradora y el admin la mete al proyecto cuando
                    // toque. Se cuentan por nombre para avisar una sola vez.
                    $r['desconocidos'][$textoProyecto] = ($r['desconocidos'][$textoProyecto] ?? 0) + 1;
                }
            }
            $etiquetaProy = $proyecto ? $proyecto['nombre'] : '';
            if ($proyecto) {
                $r['proyectos'][$proyecto['nombre']] = ($r['proyectos'][$proyecto['nombre']] ?? 0) + 1;
            } else {
                $r['sinProyecto']++;
            }

            // ¿Ya está? Por correo (que es único de verdad), por nombre exacto
            // y, si no, por nombre contenido: en el panel hay fichas creadas a
            // mano con el nombre corto ("Kevin") y duplicarlas sería peor.
            $existente = ($correo !== '' && isset($porEmail[$correo]))
                ? $porEmail[$correo]
                : ($porNombre[self::normalizar($nombre)] ?? null);
            $nota = '';

            if (!$existente) {
                $palabras = explode(' ', self::normalizar($nombre));
                $candidatos = [];
                foreach ($actuales as $m) {
                    $suyas = array_filter(explode(' ', self::normalizar($m['nombre'] ?? '')));
                    if ($suyas && !array_diff($suyas, $palabras)) $candidatos[] = $m;
                }
                if (count($candidatos) === 1) {
                    $existente = $candidatos[0];
                    $nota = 'completa la ficha «' . $candidatos[0]['nombre'] . '»';
                } elseif (count($candidatos) > 1) {
                    $r['avisos'][] = 'fila ' . $f['linea'] . ': ' . $nombre . ' se parece a ' . count($candidatos) . ' fichas; creo una nueva';
                }
            }

            if ($existente) {
                $cambios = [];
                if ($correo !== '' && strcasecmp($existente['email'] ?? '', $correo) !== 0) $cambios['email'] = $correo;
                if (MiembroRepo::equipoDe($existente) !== $equipo)                          $cambios['equipo'] = $equipo;
                if (($existente['nombre'] ?? '') !== $nombre)                               $cambios['nombre'] = $nombre;
                if ($f['rol'] !== '' && ($existente['rol'] ?? '') !== $f['rol'])            $cambios['rol'] = $f['rol'];

                if ($proyecto) $sumar[(int)$proyecto['id']][] = (int)$existente['id'];

                if (!$cambios) {
                    $r['iguales']++;
                    $r['detalle'][] = ['accion' => 'igual', 'nombre' => $nombre, 'correo' => $correo,
                                       'equipo' => $equipos[$equipo][0], 'proyecto' => $etiquetaProy, 'nota' => $nota];
                    continue;
                }
                if (!$seco) $repo->actualizar((int)$existente['id'], $cambios);
                $r['actualizados']++;
                $r['detalle'][] = ['accion' => 'actualiza', 'nombre' => $nombre, 'correo' => $correo,
                                   'equipo' => $equipos[$equipo][0], 'proyecto' => $etiquetaProy,
                                   'nota' => $nota ?: 'cambia ' . implode(', ', array_keys($cambios))];
                continue;
            }

            if (!$seco) {
                $creado = $repo->crear([
                    'nombre' => $nombre,
                    'email'  => $correo,
                    'equipo' => $equipo,
                    'rol'    => $f['rol'] ?: 'Developer',
                    // Colores rotando, para que los avatares no salgan iguales
                    'color'  => $color % count(Catalogo::COLORES),
                ]);
                if ($proyecto) $sumar[(int)$proyecto['id']][] = (int)$creado['id'];
            }
            $color++;
            $r['nuevos']++;
            $r['detalle'][] = ['accion' => 'nuevo', 'nombre' => $nombre, 'correo' => $correo,
                               'equipo' => $equipos[$equipo][0], 'proyecto' => $etiquetaProy, 'nota' => ''];
        }

        // Un guardado por proyecto y no uno por persona: se suman a los que ya
        // participaban, sin sacar a nadie ni repetir ids.
        if (!$seco) {
            foreach ($sumar as $pid => $ids) {
                $p = $proyectoRepo->buscar($pid);
                if (!$p) continue;
                $proyectoRepo->actualizar($pid, [
                    'miembros' => ProyectoRepo::miembrosEntrada(array_merge((array)($p['miembros'] ?? []), $ids)),
                ]);
            }
        }
        return $r;
    }

    /** Clave de equipo a partir de lo que venga escrito ("Analistas", "analistas"). */
    private static function equipoDe(string $valor, array $equipos): ?string
    {
        $v = self::normalizar($valor);
        if ($v === '') return null;
        foreach ($equipos as $clave => [$etiqueta]) {
            if ($v === self::normalizar($clave) || $v === self::normalizar($etiqueta)) return $clave;
            // "programación" o "programador" también valen para "Programadores"
            if (mb_strlen($v) >= 5 && str_starts_with(self::normalizar($etiqueta), mb_substr($v, 0, 5))) return $clave;
        }
        return null;
    }
}
