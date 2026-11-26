<?php
/**
 * Firma de documentos en el Hub: firmas.php (la lista), firma.php (un
 * documento) y «Mi firma» en el perfil.
 *
 * Cómo funciona:
 *  - Cada persona guarda su firma una vez (PNG con fondo transparente, subida
 *    o dibujada) y solo ella la usa, al pulsar «Firmar».
 *  - Quien crea un documento sube el PDF y elige a los firmantes en orden,
 *    cada uno con su rol (Elaborado por, Revisado por, Aprobado por u otro) y
 *    el cargo que sale en el cuadro.
 *  - Firma solo quien está en turno. Al firmar, se le avisa al siguiente en la
 *    campana y por correo. Si alguien rechaza, con su motivo, vuelve a quien
 *    lo creó, que lo corrige y lo vuelve a enviar.
 *  - Estados: borrador → en_firma (paso n de m) → firmado, o rechazado.
 *  - El historial guarda quién hizo qué, cuándo y desde qué IP: creado,
 *    enviado, notificado, firmado, rechazado, descargado…
 *
 * El PDF firmado es el original con la hoja «Firmas de responsabilidad»
 * agregada al final (PdfFirmas + HojaFirmas). Es una firma gráfica: no
 * reemplaza la firma electrónica con certificado (FirmaEC).
 *
 * Colección 'firma_docs': titulo, archivo (nombre original), paginas,
 * hash_original, hash_final, estado, creado_por, ronda, motivo,
 * firmantes [uid, rol, cargo, estado, fecha, ip, png, nombre, motivo] y
 * eventos [t, uid, accion, texto, ip]. Los archivos van en data/firmas/,
 * que no se puede abrir desde la web: se sirven con firma_archivo.php.
 */
require_once __DIR__ . '/PdfFirmas.php';
require_once __DIR__ . '/HojaFirmas.php';

final class Firmas
{
    /** estado => [rótulo, tono de .mc-status] */
    public const ESTADOS = [
        'borrador'  => ['Borrador', 'neutral'],
        'en_firma'  => ['En firma', 'warning'],
        'firmado'   => ['Firmado', 'success'],
        'rechazado' => ['Observado', 'danger'],
    ];
    /** La cadena de siempre (se propone al crear; se puede cambiar). */
    public const ROLES = ['Elaborado por', 'Revisado por', 'Aprobado por'];
    public const MAX_FIRMANTES = 8;
    public const MAX_MB = 25;

    private static ?array $mapa = null;

    private static function repo(): JsonStore
    {
        return new JsonStore('firma_docs');
    }

    private static function miembros(): array
    {
        return self::$mapa ??= (new MiembroRepo())->mapa();
    }

    public static function nombre(int $uid): string
    {
        if ($uid === 0) return 'El Hub';
        return (string)(self::miembros()[$uid]['nombre'] ?? 'Alguien');
    }

    /* ================= Archivos ================= */

    public static function carpeta(string $sub = ''): string
    {
        $d = __DIR__ . '/../../data/firmas' . ($sub !== '' ? '/' . $sub : '');
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    public static function rutaMiFirma(int $uid): string
    {
        return self::carpeta('usuarios') . '/u' . $uid . '.png';
    }

    public static function tieneFirma(int $uid): bool
    {
        return $uid > 0 && is_file(self::rutaMiFirma($uid));
    }

    private static function carpetaDoc(int $id): string
    {
        return self::carpeta('docs/' . $id);
    }

    public static function rutaOriginal(array $doc): string
    {
        return self::carpetaDoc((int)$doc['id']) . '/original.pdf';
    }

    public static function rutaFirmado(array $doc): string
    {
        return self::carpetaDoc((int)$doc['id']) . '/firmado.pdf';
    }

    /** La copia de la firma que se usó en ese documento ('' si no firmó). */
    public static function rutaFirmaUsada(array $doc, int $i): string
    {
        $png = (string)($doc['firmantes'][$i]['png'] ?? '');
        return $png !== '' && preg_match('/^[\w.-]+\.png$/', $png) ? self::carpetaDoc((int)$doc['id']) . '/' . $png : '';
    }

    /* ================= Mi firma ================= */

    /**
     * Guarda la firma de una persona con lo que subió o dibujó: el fondo claro
     * (papel, escáner, foto) se vuelve transparente, se recorta al trazo y
     * queda como PNG de 600 × 200 como máximo.
     */
    public static function guardarMiFirma(int $uid, string $bytes): true|string
    {
        if ($bytes === '') return 'No llegó ninguna imagen. Dibuja tu firma o elige una foto.';
        if (strlen($bytes) > 6 * 1048576) return 'La imagen pesa más de 6 MB. Usa una más liviana.';
        if (!function_exists('imagecreatefromstring')) return 'El servidor no puede procesar imágenes.';
        $src = @imagecreatefromstring($bytes);
        if (!$src) return 'No se pudo leer la imagen. Usa PNG o JPG.';
        $im = self::limpiarFirma($src);
        if (is_string($im)) return $im;
        $ruta = self::rutaMiFirma($uid);
        if (!@imagepng($im, $ruta . '.tmp', 9) || !@rename($ruta . '.tmp', $ruta)) return 'No se pudo guardar la firma.';
        return true;
    }

    public static function quitarMiFirma(int $uid): void
    {
        @unlink(self::rutaMiFirma($uid));
    }

    private static function limpiarFirma(\GdImage $src): \GdImage|string
    {
        $w = imagesx($src);
        $h = imagesy($src);
        if ($w < 20 || $h < 10) return 'La imagen es demasiado chica.';
        $esc = min(1, 900 / $w, 360 / $h);
        $nw = max(1, (int)round($w * $esc));
        $nh = max(1, (int)round($h * $esc));
        $im = imagecreatetruecolor($nw, $nh);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 255, 255, 255, 127));
        imagecopyresampled($im, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        // ¿Ya viene con fondo transparente (dibujada en el lienzo)? Entonces no se toca el color.
        $hist = array_fill(0, 256, 0);
        $transp = 0;
        $vistos = 0;
        for ($y = 0; $y < $nh; $y += 2) {
            for ($x = 0; $x < $nw; $x += 2) {
                $c = imagecolorat($im, $x, $y);
                $vistos++;
                if ((($c >> 24) & 0x7F) > 100) { $transp++; continue; }
                $hist[(int)(((($c >> 16) & 0xFF) * 299 + (($c >> 8) & 0xFF) * 587 + ($c & 0xFF) * 114) / 1000)]++;
            }
        }
        $quitarFondo = $transp < $vistos * 0.3;
        $corte = 255;
        $suave = 255;
        if ($quitarFondo) {
            // El fondo es lo claro que domina: el percentil 85 de la luminosidad
            $total = array_sum($hist);
            $acum = 0;
            $fondo = 255;
            for ($l = 0; $l < 256; $l++) {
                $acum += $hist[$l];
                if ($acum >= $total * 0.85) { $fondo = $l; break; }
            }
            $corte = max(110, min(235, $fondo - 38));
            $suave = $corte - 55;
        }

        $minx = $nw; $miny = $nh; $maxx = -1; $maxy = -1;
        for ($y = 0; $y < $nh; $y++) {
            for ($x = 0; $x < $nw; $x++) {
                $c = imagecolorat($im, $x, $y);
                $a = ($c >> 24) & 0x7F;
                if ($quitarFondo && $a < 127) {
                    $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                    $l = ($r * 299 + $g * 587 + $b * 114) / 1000;
                    if ($l >= $corte) {
                        $a = 127;
                    } elseif ($l > $suave) {
                        $a = max($a, (int)round(127 * ($l - $suave) / ($corte - $suave)));
                    }
                    imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, $r, $g, $b, $a));
                }
                if ($a < 100) {
                    if ($x < $minx) $minx = $x;
                    if ($x > $maxx) $maxx = $x;
                    if ($y < $miny) $miny = $y;
                    if ($y > $maxy) $maxy = $y;
                }
            }
        }
        if ($maxx < 0) return 'La firma está en blanco. Dibújala o sube una foto donde se vea bien.';
        $m = 6;
        $x0 = max(0, $minx - $m);
        $y0 = max(0, $miny - $m);
        $cw = min($nw, $maxx + $m + 1) - $x0;
        $ch = min($nh, $maxy + $m + 1) - $y0;
        if ($cw < 40 || $ch < 14) return 'La firma quedó muy pequeña. Dibújala más grande.';
        $esc = min(1, 600 / $cw, 200 / $ch);
        $fw = max(1, (int)round($cw * $esc));
        $fh = max(1, (int)round($ch * $esc));
        $out = imagecreatetruecolor($fw, $fh);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagefill($out, 0, 0, imagecolorallocatealpha($out, 255, 255, 255, 127));
        imagecopyresampled($out, $im, 0, 0, $x0, $y0, $fw, $fh, $cw, $ch);
        return $out;
    }

    /* ================= Lectura ================= */

    public static function buscar(int $id): ?array
    {
        return $id > 0 ? self::repo()->find($id) : null;
    }

    public static function todos(): array
    {
        return self::repo()->all();
    }

    public static function participa(array $doc, int $uid): bool
    {
        if ((int)($doc['creado_por'] ?? 0) === $uid) return true;
        foreach ($doc['firmantes'] ?? [] as $f) {
            if ((int)($f['uid'] ?? 0) === $uid) return true;
        }
        return false;
    }

    /** Lo ven quien lo creó, sus firmantes, a quienes se avisa al final (observadores) y los administradores. */
    public static function puedeVer(array $doc, array $yo): bool
    {
        return self::participa($doc, (int)$yo['id']) || in_array((int)$yo['id'], array_map('intval', (array)($doc['observadores'] ?? [])), true) || Auth::esAdmin();
    }

    /** Guarda campos sueltos del documento (el contenido del informe, por ejemplo). */
    public static function guardarCampos(int $id, array $campos): void
    {
        unset($campos['id']);
        self::repo()->update($id, $campos);
    }

    /** Cambiar firmantes, enviar, borrar: quien lo creó o un administrador. */
    public static function puedeGestionar(array $doc, array $yo): bool
    {
        return (int)($doc['creado_por'] ?? 0) === (int)$yo['id'] || Auth::esAdmin();
    }

    /** Índice del firmante en turno (null si no está en firma). */
    public static function turno(array $doc): ?int
    {
        if (($doc['estado'] ?? '') !== 'en_firma') return null;
        foreach ($doc['firmantes'] ?? [] as $i => $f) {
            if (($f['estado'] ?? 'pendiente') !== 'firmado') return (int)$i;
        }
        return null;
    }

    public static function esMiTurno(array $doc, int $uid): bool
    {
        $t = self::turno($doc);
        return $t !== null && (int)($doc['firmantes'][$t]['uid'] ?? 0) === $uid;
    }

    public static function firmados(array $doc): int
    {
        return count(array_filter($doc['firmantes'] ?? [], fn($f) => ($f['estado'] ?? '') === 'firmado'));
    }

    /** «En firma · paso 2 de 3», «Firmado», «Rechazado»… */
    public static function rotulo(array $doc): string
    {
        $e = (string)($doc['estado'] ?? 'borrador');
        if ($e === 'en_firma') return 'En firma · paso ' . min(self::firmados($doc) + 1, count($doc['firmantes'] ?? [])) . ' de ' . count($doc['firmantes'] ?? []);
        return self::ESTADOS[$e][0] ?? 'Borrador';
    }

    public static function tono(array $doc): string
    {
        return self::ESTADOS[$doc['estado'] ?? 'borrador'][1] ?? 'neutral';
    }

    /** Cuántos documentos esperan la firma de esta persona (el número del menú). */
    public static function porFirmar(int $uid): int
    {
        $n = 0;
        foreach (self::todos() as $d) {
            if (self::esMiTurno($d, $uid)) $n++;
        }
        return $n;
    }

    /* ================= Parámetros: coordinaciones y cargos ================= */

    /**
     * Las coordinaciones (Desarrollo, Procesos…): sus personas y su cadena de
     * revisores en orden. Config 'firmas_coord': [[id, nombre, personas [uid],
     * revisores [[uid, rol, cargo], …]], …]. El cargo vacío usa el de la persona.
     */
    public static function coordinaciones(): array
    {
        $out = [];
        foreach ((array)(Config::get('firmas_coord') ?? []) as $c) {
            if (!is_array($c) || trim((string)($c['nombre'] ?? '')) === '') continue;
            $rev = [];
            foreach ((array)($c['revisores'] ?? []) as $rv) {
                if ((int)($rv['uid'] ?? 0) > 0) $rev[] = ['uid' => (int)$rv['uid'], 'rol' => trim((string)($rv['rol'] ?? '')) ?: 'Revisado por', 'cargo' => trim((string)($rv['cargo'] ?? ''))];
            }
            $out[] = [
                'id' => (string)($c['id'] ?? ''), 'nombre' => trim((string)$c['nombre']),
                'personas' => array_values(array_unique(array_map('intval', (array)($c['personas'] ?? [])))), 'revisores' => $rev,
            ];
        }
        return $out;
    }

    public static function guardarCoordinaciones(array $lista): void
    {
        Config::guardar(['firmas_coord' => array_values($lista)]);
    }

    /** La coordinación de esa persona (la primera en la que está), o null. */
    public static function coordinacionDe(int $uid): ?array
    {
        foreach (self::coordinaciones() as $c) if (in_array($uid, $c['personas'], true)) return $c;
        return null;
    }

    /** El cargo con el que firma: el de Parámetros → Cargos o, si no tiene, su rol del Hub. */
    public static function cargoDe(int|array $m): string
    {
        if (is_int($m)) $m = self::miembros()[$m] ?? [];
        return trim((string)($m['cargo_firma'] ?? '')) ?: trim((string)($m['rol'] ?? ''));
    }

    /** Los revisores de una coordinación como firmantes, con el cargo de cada uno resuelto. */
    public static function revisoresDe(array $coord, int $sin = 0): array
    {
        $out = [];
        foreach ($coord['revisores'] as $rv) {
            if ($rv['uid'] === $sin) continue;
            $out[] = ['uid' => $rv['uid'], 'rol' => $rv['rol'], 'cargo' => $rv['cargo'] !== '' ? $rv['cargo'] : self::cargoDe($rv['uid'])];
        }
        return $out;
    }

    /**
     * La cadena que se propone al crear un documento: la de su coordinación si
     * está en una; si no, la última que usó; si no, la de siempre.
     */
    public static function cadenaPropuesta(array $yo): array
    {
        if ($coord = self::coordinacionDe((int)$yo['id'])) {
            return array_merge([['uid' => (int)$yo['id'], 'rol' => self::ROLES[0], 'cargo' => self::cargoDe($yo)]], self::revisoresDe($coord, (int)$yo['id']));
        }
        $ultimo = null;
        foreach (self::todos() as $d) {
            if ((int)($d['creado_por'] ?? 0) === (int)$yo['id'] && (!$ultimo || (int)$d['id'] > (int)$ultimo['id'])) $ultimo = $d;
        }
        if ($ultimo && !empty($ultimo['firmantes'])) {
            return array_map(fn($f) => ['uid' => (int)$f['uid'], 'rol' => (string)$f['rol'], 'cargo' => (string)$f['cargo']], $ultimo['firmantes']);
        }
        return [
            ['uid' => (int)$yo['id'], 'rol' => self::ROLES[0], 'cargo' => self::cargoDe($yo)],
            ['uid' => 0, 'rol' => self::ROLES[1], 'cargo' => ''],
            ['uid' => 0, 'rol' => self::ROLES[2], 'cargo' => ''],
        ];
    }

    public static function ip(): string
    {
        return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    }

    /** «26 nov 2026, 15:04» */
    public static function fecha(string $f, bool $conHora = true): string
    {
        $t = strtotime($f);
        if (!$t) return '';
        $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        return (int)date('j', $t) . ' ' . $meses[(int)date('n', $t) - 1] . ' ' . date('Y', $t) . ($conHora ? ', ' . date('H:i', $t) : '');
    }

    /* ================= Lo que llega de los formularios ================= */

    /**
     * Los firmantes del formulario: firmantes[i][rol|rol_otro|uid|cargo], en el
     * orden en que firman. Devuelve la lista limpia o el motivo por el que no vale.
     */
    public static function firmantesDe(array $raw): array|string
    {
        $m = self::miembros();
        $out = [];
        foreach (array_values($raw) as $f) {
            if (!is_array($f)) continue;
            $uid = (int)($f['uid'] ?? 0);
            $rol = trim((string)($f['rol'] ?? ''));
            if ($rol === '__otro') $rol = trim((string)($f['rol_otro'] ?? ''));
            $cargo = trim(preg_replace('/\s+/u', ' ', (string)($f['cargo'] ?? '')));
            if ($uid === 0 && $cargo === '' && ($rol === '' || in_array($rol, self::ROLES, true))) continue;   // fila sin llenar
            if (!isset($m[$uid])) return 'Elige quién firma como «' . ($rol !== '' ? $rol : 'firmante') . '».';
            if ($rol === '') return 'Falta el rol de ' . $m[$uid]['nombre'] . ' (por ejemplo, «Revisado por»).';
            $out[] = ['uid' => $uid, 'rol' => mb_substr($rol, 0, 60), 'cargo' => mb_substr($cargo, 0, 120),
                      'estado' => 'pendiente', 'fecha' => '', 'ip' => '', 'png' => '', 'nombre' => '', 'motivo' => ''];
        }
        if (!$out) return 'Agrega al menos a una persona que firme.';
        if (count($out) > self::MAX_FIRMANTES) return 'Como máximo ' . self::MAX_FIRMANTES . ' firmantes por documento.';
        return $out;
    }

    /**
     * El PDF subido, ya comprobado: que es PDF, que no está protegido y que de
     * verdad se le puede agregar la hoja. [bytes, páginas, nombre] o el error.
     */
    public static function leerSubida(array $f): array|string
    {
        $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) return 'Elige el PDF que se va a firmar.';
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return 'El PDF supera el límite del servidor (' . ini_get('upload_max_filesize') . ').';
        if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string)($f['tmp_name'] ?? ''))) return 'No se pudo subir el PDF. Inténtalo de nuevo.';
        if ((int)($f['size'] ?? 0) > self::MAX_MB * 1048576) return 'El PDF pesa más de ' . self::MAX_MB . ' MB.';
        $bytes = (string)file_get_contents((string)$f['tmp_name']);
        if (!str_contains(substr($bytes, 0, 1024), '%PDF-')) return 'El archivo no es un PDF. Si es un Word, guárdalo como PDF y súbelo.';
        try {
            $pdf = new PdfFirmas($bytes);
            $pags = $pdf->paginas();
            [$w, $h] = $pdf->tamano();
            [$pp, $pi] = HojaFirmas::armar(['titulo' => 'Prueba', 'firmantes' => [], 'hash' => ''], $w, $h);
            $prueba = $pdf->agregar($pp, $pi);
            if ((new PdfFirmas($prueba))->paginas() !== $pags + 1) throw new RuntimeException('no se pudo agregar la hoja.');
        } catch (\Throwable $e) {
            return 'Este PDF no se puede firmar aquí: ' . lcfirst(rtrim($e->getMessage(), '.')) . '. Ábrelo y vuelve a exportarlo como PDF.';
        }
        $nombre = mb_substr(trim(basename(str_replace('\\', '/', (string)($f['name'] ?? 'documento.pdf')))), 0, 160);
        return [$bytes, $pags, $nombre !== '' ? $nombre : 'documento.pdf'];
    }

    /* ================= El flujo ================= */

    /**
     * $yo: quien lo crea (y lo gestiona). $extra: campos más (los del informe
     * semanal). $actor: quién aparece en el historial, si no es $yo (['id' => 0]: el Hub).
     */
    public static function crear(array $yo, string $titulo, array $subida, array $firmantes, bool $enviar, array $extra = [], ?array $actor = null): array
    {
        [$bytes, $pags, $nombre] = $subida;
        $doc = self::repo()->insert($extra + [
            'titulo'        => $titulo,
            'archivo'       => $nombre,
            'paginas'       => $pags,
            'hash_original' => hash('sha256', $bytes),
            'hash_final'    => '',
            'estado'        => 'borrador',
            'creado_por'    => (int)$yo['id'],
            'ronda'         => 1,
            'motivo'        => '',
            'firmantes'     => $firmantes,
            'eventos'       => [],
            'actualizado'   => date('Y-m-d H:i'),
        ]);
        file_put_contents(self::rutaOriginal($doc), $bytes);
        if (($extra['tipo'] ?? '') === 'informe') {
            self::evento($doc, 'creado', $actor ?? $yo, 'armó el informe semanal de ' . $yo['nombre'] . ' (' . $pags . ($pags === 1 ? ' página' : ' páginas') . ').');
        } else {
            self::evento($doc, 'creado', $actor ?? $yo, 'creó el documento a partir de «' . $nombre . '» (' . $pags . ($pags === 1 ? ' página' : ' páginas') . ').');
        }
        self::guardar($doc);
        if ($enviar) self::enviar($doc, $yo);
        return $doc;
    }

    public static function enviar(array $doc, array $yo): true|string
    {
        $estado = (string)($doc['estado'] ?? 'borrador');
        if (!in_array($estado, ['borrador', 'rechazado'], true)) return 'Este documento ya está en firma.';
        if (empty($doc['firmantes'])) return 'Agrega a quién firma antes de enviarlo.';
        $reenvio = $estado === 'rechazado';
        self::limpiarFirmas($doc);
        if ($reenvio) $doc['ronda'] = (int)($doc['ronda'] ?? 1) + 1;
        $doc['estado'] = 'en_firma';
        $doc['motivo'] = '';
        $doc['rechazado_por'] = 0;
        self::evento($doc, $reenvio ? 'reenviado' : 'enviado', $yo, $reenvio ? 'lo volvió a enviar a firmar.' : 'lo envió a firmar.');
        self::avisarTurno($doc, $yo);
        self::regenerar($doc);
        self::guardar($doc);
        return true;
    }

    public static function firmar(array $doc, array $yo): true|string
    {
        $i = self::turno($doc);
        if ($i === null || (int)$doc['firmantes'][$i]['uid'] !== (int)$yo['id']) return 'No es tu turno de firmar este documento.';
        if (!self::tieneFirma((int)$yo['id'])) return 'Primero guarda tu firma en «Mi firma».';
        $png = 'firma-' . $i . '-r' . (int)($doc['ronda'] ?? 1) . '.png';
        if (!@copy(self::rutaMiFirma((int)$yo['id']), self::carpetaDoc((int)$doc['id']) . '/' . $png)) return 'No se pudo guardar la firma. Inténtalo de nuevo.';
        $rol = (string)$doc['firmantes'][$i]['rol'];
        $doc['firmantes'][$i] = array_merge($doc['firmantes'][$i], [
            'estado' => 'firmado', 'fecha' => date('Y-m-d H:i:s'), 'ip' => self::ip(), 'png' => $png, 'nombre' => (string)$yo['nombre'],
        ]);
        $doc['firmantes'][$i]['tipo'] = 'grafica';
        self::evento($doc, 'firmado', $yo, 'firmó como «' . $rol . '» con su firma gráfica.');
        $completo = self::turno($doc) === null;
        if ($completo) {
            $doc['estado'] = 'firmado';
            $doc['firmado_en'] = date('Y-m-d H:i');
        }
        self::regenerar($doc);
        self::despuesDeFirmar($doc, $yo, $rol, $completo);
        return true;
    }

    /**
     * Firma electrónica con el certificado .p12/.pfx de la persona: primero se
     * redibuja la hoja con su fila («Firmado electrónicamente») y después el
     * servidor firma el PDF (PAdES) con el campo sobre esa fila. Si algo falla,
     * el PDF y el documento quedan como estaban.
     */
    public static function firmarElectronica(array $doc, array $yo, string $p12, string $clave): true|string
    {
        $i = self::turno($doc);
        if ($i === null || (int)$doc['firmantes'][$i]['uid'] !== (int)$yo['id']) return 'No es tu turno de firmar este documento.';
        try {
            $c = PdfFirmas::abrirCertificado($p12, $clave);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
        $rol = (string)$doc['firmantes'][$i]['rol'];
        $ruta = self::rutaFirmado($doc);
        $respaldo = is_file($ruta) ? (string)file_get_contents($ruta) : null;
        $doc['firmantes'][$i] = array_merge($doc['firmantes'][$i], [
            'estado' => 'firmado', 'tipo' => 'electronica', 'fecha' => date('Y-m-d H:i:s'), 'ip' => self::ip(), 'png' => '',
            'nombre' => (string)$yo['nombre'], 'cert' => $c['cn'], 'emisor' => $c['emisor'], 'serie' => $c['serie'],
            'vence' => $c['hasta'] ? date('Y-m-d', $c['hasta']) : '',
        ]);
        $completo = self::turno($doc) === null;
        if ($completo) {
            $doc['estado'] = 'firmado';
            $doc['firmado_en'] = date('Y-m-d H:i');
        }
        try {
            if (!self::regenerar($doc) || empty($doc['hoja_rects'][$i])) throw new RuntimeException('no se pudo preparar la hoja de firmas.');
            [$pIdx, $x1, $y1, $x2, $y2] = $doc['hoja_rects'][$i];
            $pdf = new PdfFirmas((string)file_get_contents($ruta));
            $firmado = $pdf->firmarDigital($p12, $clave, [
                'pagina' => (int)$doc['hoja_obj'][$pIdx], 'rect' => [$x1, $y1, $x2, $y2], 'nombre' => $c['cn'],
                'razon' => 'Firma como ' . $rol . ' en «' . $doc['titulo'] . '»', 'lugar' => 'Ecuador',
            ]);
            file_put_contents($ruta . '.tmp', $firmado);
            if (!rename($ruta . '.tmp', $ruta)) throw new RuntimeException('no se pudo guardar el PDF firmado.');
        } catch (\Throwable $e) {
            if ($respaldo !== null) file_put_contents($ruta, $respaldo);
            error_log('Firmas: firma electrónica del documento ' . $doc['id'] . ': ' . $e->getMessage());
            return 'No se pudo firmar con el certificado: ' . lcfirst(rtrim($e->getMessage(), '.')) . '.';
        }
        $doc['con_certificado'] = true;
        self::evento($doc, 'firmado', $yo, 'firmó electrónicamente como «' . $rol . '» con el certificado de ' . $c['cn']
            . ($c['emisor'] !== '' ? ', emitido por ' . $c['emisor'] : '') . '.');
        self::despuesDeFirmar($doc, $yo, $rol, $completo);
        return true;
    }

    /** Lo que pasa después de cada firma: avisar al siguiente o, si era la última, a todos. */
    private static function despuesDeFirmar(array $doc, array $yo, string $rol, bool $completo): void
    {
        if ($completo) {
            self::evento($doc, 'completado', ['id' => 0], 'cerró el documento: quedó firmado por todos.');
            $doc['hash_final'] = is_file(self::rutaFirmado($doc)) ? hash_file('sha256', self::rutaFirmado($doc)) : '';
            self::avisarCompleto($doc, $yo);
        } else {
            self::avisarTurno($doc, $yo);
            if (class_exists('Notif')) {
                Notif::crear([(int)$doc['creado_por']], 'firma', 'Firmaron tu documento',
                    '«' . $doc['titulo'] . '» · ' . $yo['nombre'] . ' firmó como ' . $rol, 'firma.php?id=' . $doc['id'], (int)$yo['id']);
            }
        }
        self::guardar($doc);
    }

    /* ---------- El certificado recordado («Recordar» / «Olvidar») ----------
       Se guarda el archivo cifrado (AES-256-GCM, la clave del panel). La
       contraseña nunca se guarda: se escribe cada vez que se firma. */

    private static function rutaCert(int $uid): string
    {
        return self::carpeta('certs') . '/u' . $uid . '.p12.enc';
    }

    private static function cifrador(): bool
    {
        if (!class_exists('IA') && is_file(__DIR__ . '/../ai/IA.php')) require_once __DIR__ . '/../ai/IA.php';
        return class_exists('IA');
    }

    /** ['archivo', 'cn', 'guardado'] del certificado recordado, o null. */
    public static function certGuardado(int $uid): ?array
    {
        $meta = self::carpeta('certs') . '/u' . $uid . '.json';
        if (!is_file(self::rutaCert($uid)) || !is_file($meta)) return null;
        $d = json_decode((string)file_get_contents($meta), true);
        return is_array($d) ? $d : null;
    }

    public static function recordarCert(int $uid, string $p12, string $archivo, string $cn, string $emisor = '', string $vence = ''): bool
    {
        if (!self::cifrador()) return false;
        $ok = file_put_contents(self::rutaCert($uid), IA::cifrar($p12)) !== false;
        if ($ok) {
            @chmod(self::rutaCert($uid), 0600);
            file_put_contents(self::carpeta('certs') . '/u' . $uid . '.json', json_encode(['archivo' => $archivo, 'cn' => $cn, 'emisor' => $emisor, 'vence' => $vence, 'guardado' => date('Y-m-d H:i')], JSON_UNESCAPED_UNICODE));
        }
        return $ok;
    }

    public static function leerCert(int $uid): string
    {
        if (!is_file(self::rutaCert($uid)) || !self::cifrador()) return '';
        return IA::descifrar((string)file_get_contents(self::rutaCert($uid)));
    }

    public static function olvidarCert(int $uid): void
    {
        @unlink(self::rutaCert($uid));
        @unlink(self::carpeta('certs') . '/u' . $uid . '.json');
    }

    public static function rechazar(array $doc, array $yo, string $motivo): true|string
    {
        $motivo = trim($motivo);
        $i = self::turno($doc);
        if ($i === null || (int)$doc['firmantes'][$i]['uid'] !== (int)$yo['id']) return 'No es tu turno en este documento.';
        if (mb_strlen($motivo) < 5) return 'Escribe las observaciones: le llegan a quien lo elaboró para que lo corrija.';
        $motivo = mb_substr($motivo, 0, 4000);
        $rol = (string)$doc['firmantes'][$i]['rol'];
        $doc['firmantes'][$i] = array_merge($doc['firmantes'][$i], [
            'estado' => 'rechazado', 'fecha' => date('Y-m-d H:i:s'), 'ip' => self::ip(), 'nombre' => (string)$yo['nombre'], 'motivo' => $motivo,
        ]);
        $doc['estado'] = 'rechazado';
        $doc['motivo'] = $motivo;
        $doc['rechazado_por'] = (int)$yo['id'];
        self::evento($doc, 'rechazado', $yo, 'lo observó como «' . $rol . '» y lo devolvió para corregirlo.');
        $doc['eventos'][count($doc['eventos']) - 1]['motivo'] = $motivo;
        self::regenerar($doc);
        self::avisarRechazo($doc, $yo, $rol, $motivo);
        self::guardar($doc);
        return true;
    }

    /**
     * Cambia el título, los firmantes o el PDF (una versión corregida). Si ya
     * había firmas, se anulan y el flujo empieza de nuevo: queda en el historial.
     */
    public static function editar(array $doc, array $yo, string $titulo, array $firmantes, ?array $subida): true|string
    {
        if (($doc['estado'] ?? '') === 'firmado') return 'Ya lo firmaron todos: no se puede cambiar.';
        $antesTurno = ($t = self::turno($doc)) !== null ? (int)$doc['firmantes'][$t]['uid'] : 0;
        $habiaFirmas = self::firmados($doc) > 0 && ($doc['estado'] ?? '') === 'en_firma';
        $clave = fn(array $l) => array_map(fn($f) => [(int)$f['uid'], (string)$f['rol'], (string)$f['cargo']], $l);
        $cambios = [];
        if ($titulo !== (string)$doc['titulo']) { $cambios[] = 'el título'; $doc['titulo'] = $titulo; }
        $nuevosFirmantes = $clave($firmantes) !== $clave($doc['firmantes'] ?? []);
        if ($nuevosFirmantes) $cambios[] = 'los firmantes';
        if ($subida) {
            [$bytes, $pags, $nombre] = $subida;
            file_put_contents(self::rutaOriginal($doc), $bytes);
            $doc['hash_original'] = hash('sha256', $bytes);
            $doc['paginas'] = $pags;
            $doc['archivo'] = $nombre;
            $cambios[] = 'el PDF (subió «' . $nombre . '»)';
        }
        if (!$cambios) return true;

        $reinicia = ($doc['estado'] ?? '') === 'en_firma' && ($habiaFirmas || $subida) && ($nuevosFirmantes || $subida);
        if ($nuevosFirmantes) {
            $doc['firmantes'] = $firmantes;
        } elseif ($reinicia) {
            self::limpiarFirmas($doc);
        }
        if ($nuevosFirmantes || $subida || $reinicia) {
            // Con otro PDF u otros firmantes la hoja se arma de cero (las firmas electrónicas de antes ya no valen)
            $doc['con_certificado'] = false;
            $doc['hoja_obj'] = [];
        }
        $lista = count($cambios) > 1 ? implode(', ', array_slice($cambios, 0, -1)) . ' y ' . end($cambios) : $cambios[0];
        if ($reinicia) {
            $doc['ronda'] = (int)($doc['ronda'] ?? 1) + 1;
            self::evento($doc, 'reiniciado', $yo, 'cambió ' . $lista . '. ' . ($habiaFirmas ? 'Las firmas que ya había se anularon y ' : '') . 'el flujo empezó de nuevo.');
        } else {
            self::evento($doc, 'editado', $yo, 'cambió ' . $lista . '.');
        }
        if (($doc['estado'] ?? '') === 'en_firma') {
            $ahora = ($t = self::turno($doc)) !== null ? (int)$doc['firmantes'][$t]['uid'] : 0;
            if ($reinicia || $ahora !== $antesTurno) self::avisarTurno($doc, $yo);
        }
        if (($doc['estado'] ?? '') !== 'borrador') self::regenerar($doc);
        self::guardar($doc);
        return true;
    }

    public static function eliminar(array $doc, array $yo): true|string
    {
        if (!self::puedeGestionar($doc, $yo)) return 'Solo quien lo creó o un administrador puede borrarlo.';
        if (($doc['estado'] ?? '') === 'firmado' && !Auth::esAdmin()) return 'Un documento firmado solo lo puede borrar un administrador.';
        // A quien estaba esperando para firmar, que ya no hace falta
        if (($t = self::turno($doc)) !== null && class_exists('Notif')) {
            Notif::crear([(int)$doc['firmantes'][$t]['uid']], 'firma', 'Ya no hace falta que firmes',
                '«' . $doc['titulo'] . '» · ' . $yo['nombre'] . ' lo retiró', 'firmas.php', (int)$yo['id']);
        }
        $dir = self::carpetaDoc((int)$doc['id']);
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
        self::repo()->delete((int)$doc['id']);
        return true;
    }

    public static function registrarDescarga(array $doc, array $yo, string $cual): void
    {
        self::evento($doc, 'descargado', $yo, $cual === 'firmado'
            ? (($doc['estado'] ?? '') === 'firmado' ? 'descargó el PDF firmado.' : 'descargó el PDF con la hoja de firmas (sin terminar).')
            : 'descargó el PDF original.');
        self::guardar($doc, false);
    }

    /* ================= Internas ================= */

    private static function limpiarFirmas(array &$doc): void
    {
        foreach ($doc['firmantes'] as &$f) {
            $f = array_merge($f, ['estado' => 'pendiente', 'tipo' => '', 'fecha' => '', 'ip' => '', 'png' => '', 'nombre' => '', 'motivo' => '',
                                  'cert' => '', 'emisor' => '', 'serie' => '', 'vence' => '']);
        }
        unset($f);
        $doc['con_certificado'] = false;
        $doc['hoja_obj'] = [];
    }

    private static function evento(array &$doc, string $accion, array $quien, string $texto): void
    {
        $doc['eventos'][] = ['t' => date('Y-m-d H:i:s'), 'uid' => (int)($quien['id'] ?? 0), 'accion' => $accion, 'texto' => $texto, 'ip' => self::ip()];
    }

    private static function guardar(array $doc, bool $tocar = true): void
    {
        if ($tocar) $doc['actualizado'] = date('Y-m-d H:i');
        $id = (int)$doc['id'];
        unset($doc['id']);
        self::repo()->update($id, $doc);
    }

    /**
     * Vuelve a armar el PDF con la hoja de firmas. Mientras no haya firmas
     * electrónicas, desde el original; si ya las hay, se redibuja la hoja
     * sobre la versión firmada (actualización incremental), para no romperlas.
     */
    private static function regenerar(array &$doc): bool
    {
        try {
            $ruta = self::rutaFirmado($doc);
            $sobreFirmado = !empty($doc['con_certificado']) && !empty($doc['hoja_obj']) && is_file($ruta);
            $base = (string)@file_get_contents($sobreFirmado ? $ruta : self::rutaOriginal($doc));
            if ($base === '') return false;
            $pdf = new PdfFirmas($base);
            [$w, $h] = $sobreFirmado && !empty($doc['hoja_tam']) ? $doc['hoja_tam'] : $pdf->tamano();
            [$pags, $imgs, $rects] = HojaFirmas::armar(self::datosHoja($doc), (float)$w, (float)$h);
            if ($sobreFirmado) {
                $out = $pdf->reemplazarPaginas($doc['hoja_obj'], $pags, $imgs);
            } else {
                $out = $pdf->agregar($pags, $imgs);
                $doc['hoja_obj'] = $pdf->paginasNuevas;
                $doc['hoja_tam'] = [$w, $h];
            }
            $doc['hoja_rects'] = $rects;
            file_put_contents($ruta . '.tmp', $out);
            return rename($ruta . '.tmp', $ruta);
        } catch (\Throwable $e) {
            error_log('Firmas: no se pudo armar la hoja del documento ' . ($doc['id'] ?? '?') . ': ' . $e->getMessage());
            return false;
        }
    }

    private static function datosHoja(array $doc): array
    {
        $firmantes = [];
        foreach ($doc['firmantes'] ?? [] as $i => $f) {
            $t = strtotime((string)($f['fecha'] ?? ''));
            $firmantes[] = [
                'rol'    => (string)$f['rol'],
                'nombre' => (string)(($f['nombre'] ?? '') !== '' ? $f['nombre'] : self::nombre((int)$f['uid'])),
                'cargo'  => (string)$f['cargo'],
                'estado' => (string)($f['estado'] ?? 'pendiente'),
                'fecha'  => $t ? date('d/m/Y', $t) . ' a las ' . date('H:i', $t) : '',
                'png'    => self::rutaFirmaUsada($doc, (int)$i),
                'tipo'   => (string)($f['tipo'] ?? ''),
                'cert'   => (string)($f['cert'] ?? ''),
                'emisor' => (string)($f['emisor'] ?? ''),
            ];
        }
        $n = count($doc['firmantes'] ?? []);
        $estado = match ($doc['estado'] ?? '') {
            'firmado'   => 'Firmado por todos',
            'en_firma'  => 'En firma: ' . self::firmados($doc) . ' de ' . $n . ($n === 1 ? ' firma' : ' firmas'),
            'rechazado' => 'Observado',
            default     => 'Borrador',
        };
        return [
            'titulo'    => (string)$doc['titulo'],
            'codigo'    => 'Documento N.º ' . $doc['id'],
            'estado'    => $estado,
            'firmantes' => $firmantes,
            'hash'      => (string)($doc['hash_original'] ?? ''),
            'generado'  => date('d/m/Y H:i'),
        ];
    }

    /** A quien le toca: en la campana y por correo. Queda en el historial. */
    private static function avisarTurno(array &$doc, array $yo): void
    {
        $i = self::turno($doc);
        if ($i === null) return;
        $f = $doc['firmantes'][$i];
        $m = self::miembros()[(int)$f['uid']] ?? null;
        if (!$m || (int)$m['id'] === (int)$yo['id']) return;   // si me toca a mí, ya lo estoy viendo
        $n = count($doc['firmantes']);
        $url = 'firma.php?id=' . $doc['id'];
        if (class_exists('Notif')) {
            Notif::crear([(int)$m['id']], 'firma', 'Te toca firmar', '«' . $doc['titulo'] . '» · ' . $f['rol'] . ' · paso ' . ($i + 1) . ' de ' . $n, $url, (int)$yo['id']);
        }
        $correo = class_exists('Mailer') ? Mailer::avisoFirma($m, 'Por firmar: ' . $doc['titulo'], 'Te toca firmar',
            'te toca firmar un documento en el Hub. Revísalo y, si está bien, fírmalo; si algo no cuadra, puedes rechazarlo con el motivo.',
            ['Documento' => $doc['titulo'], 'Firmas como' => $f['rol'], 'Cargo' => $f['cargo'], 'Paso' => ($i + 1) . ' de ' . $n, 'Lo envió' => self::nombre((int)$doc['creado_por'])],
            '', $url, '#2B76F7', 'Revisar y firmar') : null;
        self::evento($doc, 'notificado', ['id' => 0], 'avisó a ' . $m['nombre'] . ' que le toca firmar' . ($correo === true ? ' (en la campana y por correo).' : ' (en la campana).'));
    }

    private static function avisarCompleto(array &$doc, array $yo): void
    {
        // Quien lo creó, los firmantes y a quienes se avisa al final (el informe semanal: Felipe, por ejemplo)
        $para = array_values(array_unique(array_merge([(int)$doc['creado_por']], array_map(fn($f) => (int)$f['uid'], $doc['firmantes']),
            array_map('intval', (array)($doc['observadores'] ?? [])))));
        $url = 'firma.php?id=' . $doc['id'];
        if (class_exists('Notif')) Notif::crear($para, 'firma', 'Documento firmado', '«' . $doc['titulo'] . '» ya tiene todas las firmas', $url, (int)$yo['id']);
        $filas = ['Documento' => $doc['titulo'], 'Firmas' => implode(', ', array_map(fn($f) => $f['rol'] . ': ' . ($f['nombre'] ?: self::nombre((int)$f['uid'])), $doc['firmantes']))];
        $enviados = 0;
        foreach ($para as $uid) {
            $m = self::miembros()[$uid] ?? null;
            if (!$m || $uid === (int)$yo['id'] || !class_exists('Mailer')) continue;
            if (Mailer::avisoFirma($m, 'Firmado: ' . $doc['titulo'], 'Documento firmado',
                'el documento ya tiene todas las firmas. Puedes descargar el PDF firmado desde el Hub.', $filas, '', $url, '#2BB673', 'Ver y descargar') === true) $enviados++;
        }
        if ($enviados) self::evento($doc, 'notificado', ['id' => 0], 'avisó por correo a ' . $enviados . ($enviados === 1 ? ' persona' : ' personas') . ' que quedó firmado.');
    }

    private static function avisarRechazo(array &$doc, array $yo, string $rol, string $motivo): void
    {
        $url = 'firma.php?id=' . $doc['id'];
        $creador = (int)$doc['creado_por'];
        $previos = array_map(fn($f) => (int)$f['uid'], array_filter($doc['firmantes'], fn($f) => ($f['estado'] ?? '') === 'firmado'));
        if (class_exists('Notif')) {
            Notif::crear([$creador], 'firma', 'Observaron tu documento', '«' . $doc['titulo'] . '» · ' . $yo['nombre'] . ' (' . $rol . ') te dejó observaciones', $url, (int)$yo['id']);
            if ($previos) Notif::crear(array_diff($previos, [$creador]), 'firma', 'Observaron un documento que firmaste',
                '«' . $doc['titulo'] . '» vuelve a quien lo elaboró; cuando lo reenvíe tendrás que firmarlo otra vez', $url, (int)$yo['id']);
        }
        $m = self::miembros()[$creador] ?? null;
        if ($m && $creador !== (int)$yo['id'] && class_exists('Mailer')) {
            $r = Mailer::avisoFirma($m, 'Observaciones: ' . $doc['titulo'], 'Observaron tu documento',
                '<b>' . e((string)$yo['nombre']) . '</b> revisó el documento y te deja estas observaciones. Corrígelo y vuelve a enviarlo a firmar.',
                ['Documento' => $doc['titulo'], 'Lo observó' => $yo['nombre'] . ' (' . $rol . ')'], $motivo, $url, '#D64545', 'Ver el documento');
            if ($r === true) self::evento($doc, 'notificado', ['id' => 0], 'le envió a ' . $m['nombre'] . ' las observaciones por correo.');
        }
    }
}
