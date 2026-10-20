<?php
/**
 * Correo InnoTech dentro del panel: cada persona sincroniza el buzón de su
 * dominio (el mismo cPanel donde vive el panel) y lo lee, responde y escribe
 * desde Notificaciones, sin entrar al webmail del hosting.
 *
 *  - CorreoImap : cliente IMAP mínimo sobre TLS (993), sin la extensión imap
 *                 de PHP (salió del núcleo en PHP 8.4 y muchos cPanel no la traen).
 *  - CorreoMime : desarma y arma mensajes (cabeceras RFC 2047/2231, multipart,
 *                 base64 / quoted-printable, juegos de caracteres).
 *  - CorreoHtml : limpia el HTML de un correo para mostrarlo en un marco
 *                 aislado (sin scripts; imágenes externas bloqueadas hasta
 *                 que la persona las pida).
 *  - CorreoSmtp : envía por el SMTP del dominio (465 SSL o 587 STARTTLS).
 *  - Correo     : configuración, cuentas y operaciones del buzón.
 *
 * La contraseña del buzón se guarda CIFRADA (AES-256-GCM, llave en
 * data/correo.secreto, que nunca sale del servidor). Nunca va al HTML, a los
 * registros ni a la exportación. Solo la usa la sesión de su dueño.
 *
 * Se carga con is_file (no desde bootstrap): si al subir por FTP falta, el
 * panel sigue igual y solo no aparece el correo.
 */

/** Error del correo. tipo: 'auth' (usuario o clave), 'red' (servidor), 'uso' (dato inválido). */
class CorreoError extends RuntimeException
{
    public string $tipo;

    public function __construct(string $mensaje, string $tipo = 'red')
    {
        parent::__construct($mensaje);
        $this->tipo = $tipo;
    }
}

/* =====================================================================
   Cliente IMAP
   ===================================================================== */
final class CorreoImap
{
    /** @var resource|null */
    private $s = null;
    private int $n = 0;
    /** @var string[] */
    public array $capacidades = [];

    public function __construct(
        private string $host,
        private int $puerto = 993,
        private bool $verificar = true,
        private int $espera = 25
    ) {}

    public function __destruct()
    {
        $this->cerrar();
    }

    public function conectar(): void
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer'       => $this->verificar,
            'verify_peer_name'  => $this->verificar,
            'allow_self_signed' => !$this->verificar,
            'SNI_enabled'       => true,
            'peer_name'         => $this->host,
        ]]);
        $s = @stream_socket_client('ssl://' . $this->host . ':' . $this->puerto, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) {
            throw new CorreoError('No pudimos conectar con el servidor de correo (' . $this->host . ').', 'red');
        }
        stream_set_timeout($s, $this->espera);
        $this->s = $s;
        $saludo = $this->linea();
        if (!str_starts_with($saludo, '* OK') && !str_starts_with($saludo, '* PREAUTH')) {
            throw new CorreoError('El servidor de correo no respondió como se esperaba.', 'red');
        }
    }

    public function login(string $usuario, string $clave): void
    {
        $r = $this->cmdLit(['LOGIN ', self::arg($usuario), ' ', self::arg($clave)]);
        if (!$r['ok']) {
            $estado = strtoupper($r['estado']);
            $deRed  = str_starts_with($estado, 'BAD') || str_contains($estado, '[UNAVAILABLE]') || str_contains($estado, '[SERVERBUG]');
            throw new CorreoError($deRed ? 'El servidor de correo no está disponible ahora mismo.'
                                         : 'El correo o la contraseña no coinciden con tu buzón.', $deRed ? 'red' : 'auth');
        }
        $c = $this->cmd('CAPABILITY');
        foreach ($c['datos'] as $d) {
            if (stripos($d, '* CAPABILITY ') === 0) {
                $this->capacidades = array_map('strtoupper', preg_split('/\s+/', trim(substr($d, 13))));
            }
        }
    }

    public function tiene(string $capacidad): bool
    {
        return in_array(strtoupper($capacidad), $this->capacidades, true);
    }

    public function cerrar(): void
    {
        if ($this->s) {
            @fwrite($this->s, 'Z' . (++$this->n) . " LOGOUT\r\n");
            @fclose($this->s);
            $this->s = null;
        }
    }

    /* ---------- Carpetas ---------- */

    /** [['nombre' => crudo (UTF-7 de IMAP), 'flags' => [...], 'sep' => '.'], ...] */
    public function listar(): array
    {
        $r = $this->cmd('LIST "" "*"');
        if (!$r['ok']) throw new CorreoError('No se pudieron leer tus carpetas.', 'red');
        $out = [];
        foreach ($r['datos'] as $d) {
            if (stripos($d, '* LIST ') !== 0) continue;
            $i = 7;
            $t = self::desarmar($d, $i);
            if (count($t) < 3) continue;
            $flags  = array_map('strtolower', array_filter((array)$t[0], 'is_string'));
            $nombre = (string)$t[2];
            if ($nombre === '') continue;
            $out[] = ['nombre' => $nombre, 'flags' => $flags, 'sep' => (string)($t[1] ?? '.')];
        }
        return $out;
    }

    /** ['mensajes' => n, 'no_leidos' => n, 'uidnext' => n, 'uidvalidity' => n] */
    public function estado(string $carpeta): array
    {
        $r = $this->cmdLit(['STATUS ', self::arg($carpeta), ' (MESSAGES UNSEEN UIDNEXT UIDVALIDITY)']);
        $out = ['mensajes' => 0, 'no_leidos' => 0, 'uidnext' => 0, 'uidvalidity' => 0];
        if (!$r['ok']) return $out;
        foreach ($r['datos'] as $d) {
            if (stripos($d, '* STATUS ') !== 0) continue;
            $i = 9;
            $t = self::desarmar($d, $i);
            $kv = is_array($t[1] ?? null) ? $t[1] : [];
            for ($k = 0; $k + 1 < count($kv); $k += 2) {
                $clave = strtoupper((string)$kv[$k]);
                if ($clave === 'MESSAGES')    $out['mensajes'] = (int)$kv[$k + 1];
                if ($clave === 'UNSEEN')      $out['no_leidos'] = (int)$kv[$k + 1];
                if ($clave === 'UIDNEXT')     $out['uidnext'] = (int)$kv[$k + 1];
                if ($clave === 'UIDVALIDITY') $out['uidvalidity'] = (int)$kv[$k + 1];
            }
        }
        return $out;
    }

    /** SELECT (para cambiar marcas o mover) o EXAMINE (solo mirar). */
    public function abrir(string $carpeta, bool $escribir = false): void
    {
        $r = $this->cmdLit([$escribir ? 'SELECT ' : 'EXAMINE ', self::arg($carpeta)]);
        if (!$r['ok']) throw new CorreoError('No se pudo abrir la carpeta.', 'red');
    }

    /** UIDs que cumplen el criterio. $texto: busca en todo el mensaje. */
    public function buscar(string $texto = '', bool $soloNoLeidos = false): array
    {
        $partes = ['UID SEARCH '];
        if ($texto !== '') {
            $partes[] = 'CHARSET UTF-8 TEXT ';
            $partes[] = self::arg($texto);
            if ($soloNoLeidos) $partes[] = ' UNSEEN';
        } else {
            $partes[] = $soloNoLeidos ? 'UNSEEN' : 'ALL';
        }
        $r = $this->cmdLit($partes);
        if (!$r['ok']) throw new CorreoError('No se pudo buscar en la carpeta.', 'red');
        $uids = [];
        foreach ($r['datos'] as $d) {
            if (stripos($d, '* SEARCH') !== 0) continue;
            foreach (preg_split('/\s+/', trim(substr($d, 8))) as $u) {
                if (ctype_digit($u)) $uids[] = (int)$u;
            }
        }
        return $uids;
    }

    /** UIDs desde $desde en adelante (IMAP siempre incluye el último: se filtra). */
    public function buscarDesde(int $desde, bool $soloNoLeidos = true): array
    {
        $r = $this->cmd('UID SEARCH UID ' . max(1, $desde) . ':*' . ($soloNoLeidos ? ' UNSEEN' : ''));
        if (!$r['ok']) throw new CorreoError('No se pudo buscar en la carpeta.', 'red');
        $uids = [];
        foreach ($r['datos'] as $d) {
            if (stripos($d, '* SEARCH') !== 0) continue;
            foreach (preg_split('/\s+/', trim(substr($d, 8))) as $u) {
                if (ctype_digit($u) && (int)$u >= $desde) $uids[] = (int)$u;
            }
        }
        sort($uids);
        return $uids;
    }

    /** UIDs (los más nuevos primero, máx. $max) cuyo asunto contiene $asunto: para armar la conversación. */
    public function buscarAsunto(string $asunto, int $max = 60): array
    {
        $r = $this->cmdLit(['UID SEARCH ', 'CHARSET UTF-8 SUBJECT ', self::arg($asunto)]);
        if (!$r['ok']) return [];
        $uids = [];
        foreach ($r['datos'] as $d) {
            if (stripos($d, '* SEARCH') !== 0) continue;
            foreach (preg_split('/\s+/', trim(substr($d, 8))) as $u) if (ctype_digit($u)) $uids[] = (int)$u;
        }
        rsort($uids);
        return array_slice($uids, 0, $max);
    }

    /** UID FETCH: uid => [ÍTEM => valor]. Las secciones BODY[...] llegan con su nombre tal cual. */
    public function traer(array $uids, string $items): array
    {
        $uids = array_values(array_filter(array_map('intval', $uids), fn($u) => $u > 0));
        if (!$uids) return [];
        $r = $this->cmd('UID FETCH ' . implode(',', $uids) . ' (' . $items . ')');
        if (!$r['ok']) throw new CorreoError('No se pudo leer el mensaje.', 'red');
        $res = [];
        foreach ($r['datos'] as $d) {
            if (!preg_match('/^\* \d+ FETCH /i', $d, $m)) continue;
            $i = strlen($m[0]);
            $t = self::desarmar($d, $i);
            $kv = is_array($t[0] ?? null) ? $t[0] : [];
            $it = [];
            for ($k = 0; $k + 1 < count($kv); $k += 2) {
                if (!is_string($kv[$k])) continue;
                $it[strtoupper($kv[$k])] = $kv[$k + 1];
            }
            if (isset($it['UID'])) $res[(int)$it['UID']] = $it;
        }
        return $res;
    }

    /** El mensaje entero, sin marcarlo como leído. '' si no existe. */
    public function crudo(int $uid): string
    {
        $r = $this->traer([$uid], 'UID BODY.PEEK[]');
        foreach ($r[$uid] ?? [] as $k => $v) {
            if (str_starts_with($k, 'BODY[')) return (string)$v;
        }
        return '';
    }

    public function marcar(int $uid, string $flag, bool $poner = true): void
    {
        $this->cmd('UID STORE ' . $uid . ' ' . ($poner ? '+' : '-') . 'FLAGS.SILENT (' . $flag . ')');
    }

    public function mover(int $uid, string $destino): void
    {
        if ($this->tiene('MOVE')) {
            $r = $this->cmdLit(['UID MOVE ' . $uid . ' ', self::arg($destino)]);
            if ($r['ok']) return;
        }
        $r = $this->cmdLit(['UID COPY ' . $uid . ' ', self::arg($destino)]);
        if (!$r['ok']) throw new CorreoError('No se pudo mover el mensaje.', 'red');
        $this->borrar($uid);
    }

    /** Borrado definitivo (marca \Deleted y lo expulsa). */
    public function borrar(int $uid): void
    {
        $this->marcar($uid, '\\Deleted');
        $this->cmd($this->tiene('UIDPLUS') ? 'UID EXPUNGE ' . $uid : 'EXPUNGE');
    }

    /** Guarda un mensaje en una carpeta (p. ej. la copia en Enviados). */
    public function guardar(string $carpeta, string $crudo, string $flags = '\\Seen'): bool
    {
        $r = $this->cmdLit(['APPEND ', self::arg($carpeta), ' (' . $flags . ') ', ['lit' => $crudo]]);
        return $r['ok'];
    }

    /* ---------- Protocolo ---------- */

    public function cmd(string $comando): array
    {
        $tag = 'A' . (++$this->n);
        $this->escribir($tag . ' ' . $comando . "\r\n");
        return $this->respuesta($tag);
    }

    /** Comando con literales: $partes alterna texto y ['lit' => bytes]. */
    public function cmdLit(array $partes): array
    {
        $tag = 'A' . (++$this->n);
        $buf = $tag . ' ';
        foreach ($partes as $p) {
            if (is_array($p)) {
                $this->escribir($buf . '{' . strlen($p['lit']) . "}\r\n");
                // Espera el «+» del servidor antes de mandar los bytes
                while (true) {
                    $l = $this->linea();
                    if ($l !== '' && $l[0] === '+') break;
                    if (str_starts_with($l, $tag . ' ')) {
                        return ['ok' => false, 'datos' => [], 'estado' => substr($l, strlen($tag) + 1)];
                    }
                }
                $buf = $p['lit'];
            } else {
                $buf .= $p;
            }
        }
        $this->escribir($buf . "\r\n");
        return $this->respuesta($tag);
    }

    /** Texto simple entre comillas; si trae saltos o bytes no ASCII, como literal. */
    public static function arg(string $v): string|array
    {
        if (preg_match('/^[\x20-\x7e]*$/', $v)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
        }
        return ['lit' => $v];
    }

    private function respuesta(string $tag): array
    {
        $datos = [];
        while (true) {
            $l = $this->leerCompleta();
            if (str_starts_with($l, $tag . ' ')) {
                $resto = substr($l, strlen($tag) + 1);
                return ['ok' => strncasecmp($resto, 'OK', 2) === 0, 'datos' => $datos, 'estado' => $resto];
            }
            if ($l !== '' && $l[0] === '+') continue;
            $datos[] = $l;
        }
    }

    /** Una respuesta entera: si trae literales {n}, los lee y los deja en su lugar. */
    private function leerCompleta(): string
    {
        $l = $this->linea();
        $todo = $l;
        while (preg_match('/\{(\d+)\}$/', $l, $m)) {
            $todo .= "\r\n" . $this->bytes((int)$m[1]);
            $l = $this->linea();
            $todo .= $l;
        }
        return $todo;
    }

    private function linea(): string
    {
        if (!$this->s) throw new CorreoError('No hay conexión con el servidor de correo.', 'red');
        $l = fgets($this->s);
        if ($l === false) {
            $meta = stream_get_meta_data($this->s);
            throw new CorreoError(!empty($meta['timed_out']) ? 'El servidor de correo tardó demasiado en responder.'
                                                             : 'Se cortó la conexión con el servidor de correo.', 'red');
        }
        return rtrim($l, "\r\n");
    }

    private function bytes(int $n): string
    {
        $d = '';
        while (strlen($d) < $n) {
            $t = fread($this->s, min(65536, $n - strlen($d)));
            if ($t === false || $t === '') {
                $meta = stream_get_meta_data($this->s);
                if (feof($this->s) || !empty($meta['timed_out'])) {
                    throw new CorreoError('Se cortó la conexión mientras llegaba el mensaje.', 'red');
                }
                continue;
            }
            $d .= $t;
        }
        return $d;
    }

    private function escribir(string $d): void
    {
        if (!$this->s) throw new CorreoError('No hay conexión con el servidor de correo.', 'red');
        $total = strlen($d);
        $hecho = 0;
        while ($hecho < $total) {
            $w = @fwrite($this->s, substr($d, $hecho, 65536));
            if ($w === false || $w === 0) throw new CorreoError('Se cortó la conexión con el servidor de correo.', 'red');
            $hecho += $w;
        }
    }

    /**
     * Desarma una respuesta IMAP en listas anidadas: átomos, cadenas entre
     * comillas, literales {n} y NIL (null). Las secciones BODY[...] quedan
     * dentro de su átomo aunque lleven espacios o paréntesis.
     */
    public static function desarmar(string $s, int &$i = 0, bool $sub = false): array
    {
        $out = [];
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if ($c === ' ' || $c === "\r" || $c === "\n") { $i++; continue; }
            if ($c === '(') { $i++; $out[] = self::desarmar($s, $i, true); continue; }
            if ($c === ')') { $i++; if ($sub) return $out; continue; }
            if ($c === '"') {
                $i++;
                $v = '';
                while ($i < $n && $s[$i] !== '"') {
                    if ($s[$i] === '\\' && $i + 1 < $n) $i++;
                    $v .= $s[$i];
                    $i++;
                }
                $i++;
                $out[] = $v;
                continue;
            }
            if ($c === '{' && preg_match('/\G\{(\d+)\}\r\n/', $s, $m, 0, $i)) {
                $i += strlen($m[0]);
                $out[] = substr($s, $i, (int)$m[1]);
                $i += (int)$m[1];
                continue;
            }
            $v = '';
            while ($i < $n) {
                $c = $s[$i];
                if ($c === '[') {
                    $fin = strpos($s, ']', $i);
                    if ($fin === false) $fin = $n - 1;
                    $v .= substr($s, $i, $fin - $i + 1);
                    $i = $fin + 1;
                    continue;
                }
                if ($c === ' ' || $c === '(' || $c === ')' || $c === "\r" || $c === "\n") break;
                $v .= $c;
                $i++;
            }
            if ($v === '') { $i++; continue; }
            $out[] = strtoupper($v) === 'NIL' ? null : $v;
        }
        return $out;
    }

    /** Nombre de carpeta para mostrar (de UTF-7 de IMAP a UTF-8). */
    public static function nombreVisible(string $crudo): string
    {
        if (!str_contains($crudo, '&')) return $crudo;
        try {
            $r = @mb_convert_encoding($crudo, 'UTF-8', 'UTF7-IMAP');
            if (is_string($r) && $r !== '') return $r;
        } catch (\ValueError $e) {
        }
        return preg_replace_callback('/&([^-]*)-/', function ($m) {
            if ($m[1] === '') return '&';
            $b = base64_decode(strtr($m[1], ',', '/') . str_repeat('=', (4 - strlen($m[1]) % 4) % 4));
            return (string)@mb_convert_encoding((string)$b, 'UTF-8', 'UTF-16BE');
        }, $crudo);
    }
}

/* =====================================================================
   Mensajes: desarmar y armar
   ===================================================================== */
final class CorreoMime
{
    /** [cabeceras (nombre en minúsculas => [valores]), cuerpo]. */
    public static function partir(string $crudo): array
    {
        $crudo = str_replace("\r\n", "\n", $crudo);
        $pos = strpos($crudo, "\n\n");
        if (str_starts_with($crudo, "\n")) $pos = 0;
        $cab = $pos === false ? $crudo : substr($crudo, 0, $pos);
        $cuerpo = $pos === false ? '' : substr($crudo, $pos + ($pos === 0 ? 1 : 2));
        $h = [];
        $cab = preg_replace("/\n[ \t]+/", ' ', $cab);
        foreach (explode("\n", (string)$cab) as $l) {
            $p = strpos($l, ':');
            if ($p === false || $p === 0) continue;
            $h[strtolower(trim(substr($l, 0, $p)))][] = trim(substr($l, $p + 1));
        }
        return [$h, $cuerpo];
    }

    /** Una cabecera ya decodificada ('' si no está). */
    public static function cab(array $h, string $nombre): string
    {
        return isset($h[$nombre][0]) ? self::decodificar($h[$nombre][0]) : '';
    }

    /** Palabras codificadas (=?UTF-8?B?...?=) a texto UTF-8. */
    public static function decodificar(string $v): string
    {
        $patron = '=\?[^?\s]+\?[BbQq]\?[^?\s]*\?=';
        $v = (string)preg_replace('/(' . $patron . ')\s+(?=' . $patron . ')/', '$1', $v);
        // Cada tramo por su lado: las palabras codificadas traen su juego de
        // caracteres; el texto suelto puede venir en UTF-8 o en Latin-1.
        $out = '';
        foreach (preg_split('/(' . $patron . ')/', $v, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $trozo) {
            if (preg_match('/^=\?([^?*\s]+)(?:\*[^?\s]*)?\?([BbQq])\?([^?\s]*)\?=$/', $trozo, $m)) {
                $txt = strtoupper($m[2]) === 'B' ? base64_decode($m[3]) : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                $out .= self::utf8((string)$txt, $m[1]);
            } else {
                $out .= self::utf8($trozo, '');
            }
        }
        return $out;
    }

    /** Texto en cualquier juego de caracteres a UTF-8 (sin romperse con uno desconocido). */
    public static function utf8(string $s, string $charset): string
    {
        $cs = strtolower(trim($charset, " \t\"'"));
        if ($cs === '' || in_array($cs, ['utf-8', 'utf8', 'us-ascii', 'ascii'], true)) {
            return mb_check_encoding($s, 'UTF-8') ? $s : (string)mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
        }
        $alias = ['iso-8859-1' => 'Windows-1252', 'latin1' => 'Windows-1252', 'ks_c_5601-1987' => 'UHC', 'gb2312' => 'GB18030', 'gbk' => 'GB18030'];
        $cs = $alias[$cs] ?? $cs;
        try {
            $r = @mb_convert_encoding($s, 'UTF-8', $cs);
            if (is_string($r) && $r !== '') return $r;
        } catch (\ValueError $e) {
        }
        $r = function_exists('iconv') ? @iconv($cs, 'UTF-8//IGNORE', $s) : false;
        return is_string($r) ? $r : mb_scrub($s, 'UTF-8');
    }

    /** "text/plain; charset=utf-8; name=…" → ['valor' => 'text/plain', 'charset' => …] (RFC 2231 incluido). */
    public static function parametros(string $v): array
    {
        $partes = [];
        $actual = '';
        $comillas = false;
        $n = strlen($v);
        for ($i = 0; $i < $n; $i++) {
            $c = $v[$i];
            if ($c === '\\' && $comillas && $i + 1 < $n) { $actual .= $c . $v[++$i]; continue; }
            if ($c === '"') $comillas = !$comillas;
            if ($c === ';' && !$comillas) { $partes[] = $actual; $actual = ''; continue; }
            $actual .= $c;
        }
        $partes[] = $actual;
        $out = ['valor' => strtolower(trim((string)array_shift($partes)))];
        $trozos = [];
        foreach ($partes as $p) {
            $eq = strpos($p, '=');
            if ($eq === false) continue;
            $k = strtolower(trim(substr($p, 0, $eq)));
            $val = trim(substr($p, $eq + 1));
            if (strlen($val) >= 2 && $val[0] === '"' && str_ends_with($val, '"')) {
                $val = str_replace(['\\"', '\\\\'], ['"', '\\'], substr($val, 1, -1));
            }
            if (preg_match('/^([a-z0-9_.-]+)\*(\d+)?(\*)?$/', $k, $m)) {
                $num = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
                $codificado = !empty($m[3]) || !isset($m[2]) || $m[2] === '';
                $trozos[$m[1]][$num] = [$val, $codificado];
                continue;
            }
            $out[$k] = $val;
        }
        foreach ($trozos as $k => $lista) {
            ksort($lista);
            $txt = '';
            $cs = '';
            foreach ($lista as $num => [$val, $cod]) {
                if ($cod) {
                    if ($num === 0 && preg_match("/^([^']*)'[^']*'(.*)$/s", $val, $mm)) { $cs = $mm[1]; $val = $mm[2]; }
                    $val = rawurldecode($val);
                }
                $txt .= $val;
            }
            $out[$k] = self::utf8($txt, $cs);
        }
        return $out;
    }

    /**
     * Desarma un mensaje: ['cab' => cabeceras, 'texto' => ?string, 'html' => ?string,
     * 'adjuntos' => [['nombre','tipo','tam','cid','inline','datos'], ...]].
     */
    public static function analizar(string $crudo): array
    {
        $res = ['texto' => null, 'html' => null, 'adjuntos' => []];
        [$h, $cuerpo] = self::partir($crudo);
        self::parte($h, $cuerpo, $res, 0);
        $res['cab'] = $h;
        return $res;
    }

    private static function parte(array $h, string $cuerpo, array &$res, int $nivel): void
    {
        if ($nivel > 15) return;
        $ct   = self::parametros($h['content-type'][0] ?? 'text/plain');
        $tipo = $ct['valor'] !== '' ? $ct['valor'] : 'text/plain';
        $cd   = self::parametros($h['content-disposition'][0] ?? '');
        if (str_starts_with($tipo, 'multipart/')) {
            $limite = (string)($ct['boundary'] ?? '');
            if ($limite === '') {
                $res['texto'] ??= self::utf8($cuerpo, '');
                return;
            }
            foreach (self::trozos($cuerpo, $limite) as $t) {
                [$hh, $cc] = self::partir($t);
                self::parte($hh, $cc, $res, $nivel + 1);
            }
            return;
        }
        $datos  = self::cuerpo($cuerpo, strtolower(trim($h['content-transfer-encoding'][0] ?? '')));
        $nombre = (string)($cd['filename'] ?? $ct['name'] ?? '');
        $nombre = $nombre !== '' ? self::decodificar($nombre) : '';
        $esTexto = in_array($tipo, ['text/plain', 'text/html'], true);
        if ($esTexto && $cd['valor'] !== 'attachment' && $nombre === '') {
            $txt = self::utf8($datos, (string)($ct['charset'] ?? ''));
            if ($tipo === 'text/html') {
                $res['html'] = $res['html'] === null ? $txt : $res['html'] . $txt;
            } else {
                $res['texto'] = $res['texto'] === null ? $txt : $res['texto'] . "\n\n" . $txt;
            }
            return;
        }
        if ($tipo === 'message/rfc822' && $nombre === '') {
            [$hm] = self::partir($datos);
            $nombre = (self::cab($hm, 'subject') ?: 'mensaje') . '.eml';
        }
        if ($nombre === '') {
            $ext = ['application/pdf' => 'pdf', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'text/calendar' => 'ics'][$tipo] ?? 'bin';
            $nombre = 'adjunto-' . (count($res['adjuntos']) + 1) . '.' . $ext;
        }
        $res['adjuntos'][] = [
            'nombre' => self::nombreArchivo($nombre),
            'tipo'   => $tipo,
            'tam'    => strlen($datos),
            'cid'    => trim((string)($h['content-id'][0] ?? ''), " <>"),
            'inline' => $cd['valor'] === 'inline',
            'datos'  => $datos,
        ];
    }

    /** Las partes de un multipart (sin el preámbulo ni el epílogo). */
    private static function trozos(string $cuerpo, string $limite): array
    {
        $sep = "\n--" . $limite;
        $texto = "\n" . $cuerpo;
        $out = [];
        $pos = strpos($texto, $sep);
        while ($pos !== false) {
            $ini = $pos + strlen($sep);
            $sigue = $texto[$ini] ?? "\n";
            if (!in_array($sigue, ["\n", "\r", ' ', "\t", '-'], true)) {   // era otro límite que empieza igual
                $pos = strpos($texto, $sep, $ini);
                continue;
            }
            if (substr($texto, $ini, 2) === '--') break;
            $eol = strpos($texto, "\n", $ini);
            if ($eol === false) break;
            $sig = $eol;
            do {
                $sig = strpos($texto, $sep, $sig);
                $car = $sig === false ? '' : ($texto[$sig + strlen($sep)] ?? "\n");
            } while ($sig !== false && !in_array($car, ["\n", "\r", ' ', "\t", '-'], true) && ++$sig);
            $out[] = substr($texto, $eol + 1, ($sig === false ? strlen($texto) : $sig) - $eol - 1);
            $pos = $sig;
        }
        return $out;
    }

    private static function cuerpo(string $c, string $codif): string
    {
        if ($codif === 'base64') return (string)base64_decode((string)preg_replace('~[^A-Za-z0-9+/=]~', '', $c));
        if ($codif === 'quoted-printable') return quoted_printable_decode($c);
        return $c;
    }

    /** Nombre de archivo seguro para descargar (sin rutas ni caracteres raros). */
    public static function nombreArchivo(string $n): string
    {
        $n = (string)preg_replace('/[\x00-\x1f\x7f\/\\\\:*?"<>|]+/u', '_', $n);
        $n = trim($n, " .");
        return mb_substr($n !== '' ? $n : 'adjunto', 0, 120);
    }

    /** Direcciones de una cabecera: [['nombre' => , 'email' => ], ...]. */
    public static function direcciones(string $v): array
    {
        $out = [];
        foreach (self::separarComas(self::decodificar($v)) as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (preg_match('/^(.*)<([^<>\s]+@[^<>\s]+)>\s*$/', $p, $m)) {
                $out[] = ['nombre' => trim($m[1], " \t\"'"), 'email' => strtolower(trim($m[2]))];
            } elseif (preg_match('/[^\s<>"]+@[^\s<>"]+/', $p, $m)) {
                $out[] = ['nombre' => '', 'email' => strtolower(trim($m[0], '<>'))];
            }
        }
        return $out;
    }

    private static function separarComas(string $v): array
    {
        $out = [];
        $actual = '';
        $comillas = false;
        $angulo = 0;
        foreach (preg_split('//u', $v, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
            if ($c === '"') $comillas = !$comillas;
            if ($c === '<') $angulo++;
            if ($c === '>') $angulo = max(0, $angulo - 1);
            if (($c === ',' || $c === ';') && !$comillas && $angulo === 0) { $out[] = $actual; $actual = ''; continue; }
            $actual .= $c;
        }
        $out[] = $actual;
        return $out;
    }

    /* ---------- Armar un mensaje para enviar ---------- */

    public static function cabeceraCodificada(string $v): string
    {
        $v = str_replace(["\r", "\n"], ' ', $v);
        if (preg_match('/^[\x20-\x7e]*$/', $v) && strlen($v) < 900) return $v;
        return mb_encode_mimeheader($v, 'UTF-8', 'B', "\r\n");
    }

    public static function direccion(string $nombre, string $email): string
    {
        $nombre = trim(str_replace(["\r", "\n", '"'], ' ', $nombre));
        if ($nombre === '') return $email;
        if (preg_match('/^[\x20-\x7e]*$/', $nombre)) return '"' . $nombre . '" <' . $email . '>';
        return mb_encode_mimeheader($nombre, 'UTF-8', 'B', "\r\n") . ' <' . $email . '>';
    }

    /**
     * Arma el mensaje (CRLF). $m: de_nombre, de_email, para[], cc[], asunto,
     * texto, html, adjuntos [['nombre','tipo','datos']], message_id,
     * en_respuesta_a, referencias.
     */
    public static function armar(array $m): string
    {
        $dominio = substr(strrchr((string)$m['de_email'], '@') ?: '@panel', 1);
        $mid = $m['message_id'] ?? ('<' . bin2hex(random_bytes(10)) . '.' . time() . '@' . $dominio . '>');
        $h = [
            'Date: ' . date('r'),
            'From: ' . self::direccion((string)($m['de_nombre'] ?? ''), (string)$m['de_email']),
            'To: ' . implode(', ', (array)$m['para']),
        ];
        if (!empty($m['cc'])) $h[] = 'Cc: ' . implode(', ', (array)$m['cc']);
        $h[] = 'Subject: ' . self::cabeceraCodificada((string)$m['asunto']);
        $h[] = 'Message-ID: ' . $mid;
        if (!empty($m['en_respuesta_a'])) $h[] = 'In-Reply-To: ' . $m['en_respuesta_a'];
        if (!empty($m['referencias']))    $h[] = 'References: ' . $m['referencias'];
        // Prioridad (1 más alta … 5 más baja; 3 normal no se escribe), como Outlook y Roundcube
        $prio = (int)($m['prioridad'] ?? 3);
        if ($prio >= 1 && $prio <= 5 && $prio !== 3) {
            $h[] = 'X-Priority: ' . $prio . ' (' . [1 => 'Highest', 2 => 'High', 4 => 'Low', 5 => 'Lowest'][$prio] . ')';
            $h[] = 'Importance: ' . ($prio < 3 ? 'High' : 'Low');
        }
        // Confirmación de lectura: el programa de quien lo abre le pregunta si avisar (RFC 8098)
        if (!empty($m['acuse'])) $h[] = 'Disposition-Notification-To: ' . self::direccion((string)($m['de_nombre'] ?? ''), (string)$m['de_email']);
        $h[] = 'MIME-Version: 1.0';

        $b1 = '=_alt_' . bin2hex(random_bytes(8));
        $alternativa = '--' . $b1 . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode(str_replace(["\r\n", "\n"], ["\n", "\r\n"], (string)$m['texto'])) . "\r\n"
            . '--' . $b1 . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n"
            . quoted_printable_encode((string)$m['html']) . "\r\n"
            . '--' . $b1 . "--\r\n";

        if (empty($m['adjuntos'])) {
            $h[] = 'Content-Type: multipart/alternative; boundary="' . $b1 . '"';
            return implode("\r\n", $h) . "\r\n\r\n" . $alternativa;
        }
        $b2 = '=_mix_' . bin2hex(random_bytes(8));
        $h[] = 'Content-Type: multipart/mixed; boundary="' . $b2 . '"';
        $cuerpo = '--' . $b2 . "\r\nContent-Type: multipart/alternative; boundary=\"" . $b1 . "\"\r\n\r\n" . $alternativa;
        foreach ($m['adjuntos'] as $a) {
            $nombre = self::nombreArchivo((string)$a['nombre']);
            $ascii  = preg_replace('/[^\x20-\x7e]|"/', '_', $nombre);
            $tipo   = preg_match('~^[a-z0-9.+-]+/[a-z0-9.+-]+$~i', (string)$a['tipo']) ? $a['tipo'] : 'application/octet-stream';
            $cuerpo .= '--' . $b2 . "\r\n"
                . 'Content-Type: ' . $tipo . '; name="' . $ascii . "\"\r\n"
                . 'Content-Disposition: attachment; filename="' . $ascii . "\"; filename*=UTF-8''" . rawurlencode($nombre) . "\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode((string)$a['datos']), 76, "\r\n");
        }
        return implode("\r\n", $h) . "\r\n\r\n" . $cuerpo . '--' . $b2 . "--\r\n";
    }
}

/* =====================================================================
   HTML de un correo: limpio y aislado
   ===================================================================== */
final class CorreoHtml
{
    private const FUERA = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input', 'button',
        'textarea', 'select', 'option', 'link', 'meta', 'base', 'noscript', 'template', 'svg', 'math', 'audio', 'video',
        'source', 'track', 'portal', 'canvas', 'dialog', 'title'];
    /** Un píxel transparente en lugar de las imágenes externas bloqueadas. */
    private const PIXEL = 'data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==';

    /**
     * [cuerpo, estilos, hayRemotas] o null si el servidor no tiene DOM (se
     * muestra entonces el texto). $cids: content-id => data URI.
     */
    public static function limpiar(string $html, array $cids, bool $remotas): ?array
    {
        if (!class_exists('DOMDocument')) return null;
        $doc = new DOMDocument();
        $antes = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($antes);

        foreach (self::FUERA as $tag) {
            $lista = $doc->getElementsByTagName($tag);
            for ($i = $lista->length - 1; $i >= 0; $i--) {
                $nodo = $lista->item($i);
                if ($nodo && $nodo->parentNode) $nodo->parentNode->removeChild($nodo);
            }
        }
        $xp = new DOMXPath($doc);
        foreach ($xp->query('//processing-instruction()') ?: [] as $pi) {
            $pi->parentNode?->removeChild($pi);
        }
        $hayRemotas = false;
        foreach ($xp->query('//*') ?: [] as $el) {
            if (!$el instanceof DOMElement) continue;
            $quitar = [];
            foreach ($el->attributes as $a) {
                $n = strtolower($a->nodeName);
                $v = trim((string)$a->nodeValue);
                if (str_starts_with($n, 'on') || in_array($n, ['formaction', 'action', 'srcdoc', 'srcset', 'ping', 'xmlns', 'http-equiv'], true)) {
                    $quitar[] = $a->nodeName;
                } elseif (in_array($n, ['href', 'xlink:href'], true) && !preg_match('~^(https?:|mailto:|tel:|#)~i', $v)) {
                    $quitar[] = $a->nodeName;
                } elseif ($n === 'style' && preg_match('/expression\s*\(|javascript:|behavior\s*:|-moz-binding/i', $v)) {
                    $quitar[] = $a->nodeName;
                } elseif ($n === 'background' && preg_match('~^https?://~i', $v)) {
                    $hayRemotas = true;
                    if (!$remotas) $quitar[] = $a->nodeName;
                }
                if ($n === 'style' && preg_match('~url\(\s*[\'"]?https?://~i', $v)) $hayRemotas = true;
            }
            foreach ($quitar as $q) $el->removeAttribute($q);
        }
        foreach ($doc->getElementsByTagName('a') as $a) {
            if (!$a instanceof DOMElement) continue;
            $a->setAttribute('target', '_blank');
            $a->setAttribute('rel', 'noopener noreferrer');
        }
        foreach ($doc->getElementsByTagName('img') as $img) {
            if (!$img instanceof DOMElement) continue;
            $src = trim($img->getAttribute('src'));
            if (stripos($src, 'cid:') === 0) {
                $img->setAttribute('src', $cids[rawurldecode(substr($src, 4))] ?? self::PIXEL);
            } elseif (preg_match('~^(https?:)?//~i', $src)) {
                $hayRemotas = true;
                if (!$remotas) $img->setAttribute('src', self::PIXEL);
            } elseif (!preg_match('~^data:image/(png|jpe?g|gif|webp|bmp);base64,~i', $src)) {
                $img->removeAttribute('src');
            }
        }
        $estilos = '';
        foreach ($doc->getElementsByTagName('style') as $st) {
            if (preg_match('~url\(\s*[\'"]?https?://~i', $st->textContent)) $hayRemotas = true;
        }
        $head = $doc->getElementsByTagName('head')->item(0);
        if ($head) {
            foreach ($head->childNodes as $c) {
                if ($c->nodeName === 'style') $estilos .= $doc->saveHTML($c);
            }
        }
        $cuerpo = '';
        $body = $doc->getElementsByTagName('body')->item(0);
        if ($body) {
            foreach ($body->childNodes as $c) $cuerpo .= $doc->saveHTML($c);
        }
        return [$cuerpo, $estilos, $hayRemotas];
    }

    /** El documento que va en el marco aislado: con su política de seguridad propia. */
    public static function documento(string $cuerpo, string $estilos, bool $remotas): string
    {
        $csp = "default-src 'none'; img-src data:" . ($remotas ? ' https: http:' : '') . "; style-src 'unsafe-inline'; font-src data:; form-action 'none'; base-uri 'none'";
        return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . $csp . '">'
            . '<base target="_blank"><meta name="referrer" content="no-referrer">'
            . '<style>html,body{margin:0;padding:0;background:#fff;color:#1f2937}body{padding:18px 22px;font:14px/1.6 -apple-system,"Segoe UI",Roboto,Arial,sans-serif;overflow-wrap:anywhere}'
            . 'img{max-width:100%;height:auto}table{max-width:100%}pre{white-space:pre-wrap}a{color:#1F4E9C}'
            . 'blockquote{margin:0 0 0 6px;padding-left:12px;border-left:3px solid #d0d7e2;color:#4b5563}</style>'
            . $estilos . '</head><body>' . $cuerpo . '</body></html>';
    }

    /** HTML de un correo a texto (para citarlo al responder o reenviar). */
    public static function aTexto(string $html): string
    {
        $t = (string)preg_replace('~<(script|style|head|title)\b[^>]*>.*?</\1\s*>~is', '', $html);
        $t = (string)preg_replace('~<br\s*/?>~i', "\n", $t);
        $t = (string)preg_replace('~</(p|div|li|tr|h[1-6]|blockquote|table)\s*>~i', "\n", $t);
        $t = (string)preg_replace('~<li\b[^>]*>~i', '- ', $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = (string)preg_replace('~[ \t\x{00A0}]+~u', ' ', $t);
        $t = (string)preg_replace('~ *\n *~', "\n", $t);
        return trim((string)preg_replace('~\n{3,}~', "\n\n", $t));
    }

    /** Texto plano a HTML seguro (con enlaces). */
    public static function texto(string $t): string
    {
        $h = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return (string)preg_replace('~\bhttps?://[^\s<>"\']+~i', '<a href="$0" target="_blank" rel="noopener noreferrer">$0</a>', $h);
    }
}

/* =====================================================================
   Envío por SMTP
   ===================================================================== */
final class CorreoSmtp
{
    /** @var resource|null */
    private $s = null;

    /**
     * Envía el mensaje. Con $dsn pide al servidor el aviso de entrega (DSN,
     * RFC 3461) si lo admite: devuelve true si lo pudo pedir, false si no.
     */
    public function enviar(string $host, int $puerto, bool $verificar, string $usuario, string $clave, string $de, array $destinos, string $crudo, bool $dsn = false): bool
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => $verificar, 'verify_peer_name' => $verificar, 'allow_self_signed' => !$verificar,
            'SNI_enabled' => true, 'peer_name' => $host,
        ]]);
        // TLS desde el saludo, salvo en los puertos de STARTTLS (587 y 25)
        $ssl = !in_array($puerto, [587, 25], true);
        $s = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $puerto, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$s) throw new CorreoError('No pudimos conectar con el servidor de envío (' . $host . ').', 'red');
        stream_set_timeout($s, 20);
        $this->s = $s;
        try {
            $this->esperar([220]);
            $yo = 'panel.' . (substr(strrchr($de, '@') ?: '@localhost', 1));
            [, $ehlo] = $this->cmd('EHLO ' . $yo, [250]);
            if (!$ssl) {
                $this->cmd('STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($s, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new CorreoError('No se pudo cifrar la conexión con el servidor de envío.', 'red');
                }
                [, $ehlo] = $this->cmd('EHLO ' . $yo, [250]);
            }
            [$cod] = $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $usuario . "\0" . $clave), [235, 535, 534, 454], false);
            if ($cod !== 235) throw new CorreoError('El servidor de envío no aceptó tu contraseña.', $cod === 454 ? 'red' : 'auth');
            // Aviso de entrega: solo si el servidor anuncia DSN; si no, el correo sale igual, sin aviso
            $conDsn = $dsn && preg_match('/^250[ -]DSN\b/mi', (string)$ehlo) === 1;
            $this->cmd('MAIL FROM:<' . $de . '>' . ($conDsn ? ' RET=HDRS' : ''), [250]);
            foreach ($destinos as $d) $this->cmd('RCPT TO:<' . $d . '>' . ($conDsn ? ' NOTIFY=SUCCESS,FAILURE,DELAY ORCPT=rfc822;' . $d : ''), [250, 251]);
            $this->cmd('DATA', [354]);
            $datos = (string)preg_replace('/^\./m', '..', $crudo);
            if (!str_ends_with($datos, "\r\n")) $datos .= "\r\n";
            $this->escribir($datos . ".\r\n");
            $this->esperar([250]);
            $this->escribir("QUIT\r\n");
            return $conDsn;
        } finally {
            @fclose($s);
            $this->s = null;
        }
    }

    private function cmd(string $c, array $codigos, bool $exigir = true): array
    {
        $this->escribir($c . "\r\n");
        return $this->esperar($codigos, $exigir);
    }

    private function esperar(array $codigos, bool $exigir = true): array
    {
        $texto = '';
        do {
            $l = fgets($this->s);
            if ($l === false) {
                $meta = stream_get_meta_data($this->s);
                throw new CorreoError(!empty($meta['timed_out']) ? 'El servidor de envío tardó demasiado en responder.'
                                                                 : 'Se cortó la conexión con el servidor de envío.', 'red');
            }
            $texto .= $l;
        } while (isset($l[3]) && $l[3] === '-');
        $cod = (int)substr($texto, 0, 3);
        if ($exigir && !in_array($cod, $codigos, true)) {
            $msg = trim((string)preg_replace('/^\d{3}[ -]/m', '', $texto));
            throw new CorreoError('El servidor de envío respondió: ' . mb_substr($msg, 0, 160), 'red');
        }
        return [$cod, $texto];
    }

    private function escribir(string $d): void
    {
        $total = strlen($d);
        $hecho = 0;
        while ($hecho < $total) {
            $w = @fwrite($this->s, substr($d, $hecho, 65536));
            if ($w === false || $w === 0) throw new CorreoError('Se cortó la conexión con el servidor de envío.', 'red');
            $hecho += $w;
        }
    }
}

/* =====================================================================
   El buzón de cada persona
   ===================================================================== */
final class Correo
{
    public const DEFECTO = [
        'activo'        => true,
        'imap_host'     => 'mail.innotech-solutions.com.ec',
        'imap_puerto'   => 993,
        'smtp_host'     => 'mail.innotech-solutions.com.ec',
        'smtp_puerto'   => 465,
        'dominio'       => 'innotech-solutions.com.ec',
        'verificar_ssl' => true,
    ];
    /** Intentos fallidos de sincronizar permitidos cada 15 minutos (protege al servidor de bloqueos por fuerza bruta). */
    private const MAX_FALLOS = 5;
    /** Cada cuánto se vuelve a contar lo no leído para la campana (segundos). */
    private const REFRESCO = 150;
    public const TOPE_ADJUNTOS = 20 * 1024 * 1024;

    public static function conf(): array
    {
        $g = (array)(Config::get('correo_buzon') ?? []);
        $c = array_merge(self::DEFECTO, array_intersect_key($g, self::DEFECTO));
        $c['imap_puerto'] = (int)$c['imap_puerto'] ?: 993;
        $c['smtp_puerto'] = (int)$c['smtp_puerto'] ?: 465;
        $c['activo'] = !empty($c['activo']);
        $c['verificar_ssl'] = !empty($c['verificar_ssl']);
        return $c;
    }

    public static function activo(): bool
    {
        return self::conf()['activo'] && function_exists('openssl_encrypt') && function_exists('stream_socket_client');
    }

    /** Dominios permitidos (en minúsculas). */
    public static function dominios(): array
    {
        return array_values(array_filter(array_map(fn($d) => ltrim(strtolower(trim($d)), '@'), preg_split('/[\s,;]+/', (string)self::conf()['dominio']))));
    }

    public static function correoPermitido(string $email): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
        $dom = substr(strrchr($email, '@'), 1);
        $doms = self::dominios();
        return !$doms || in_array($dom, $doms, true);
    }

    /* ---------- Cuentas ---------- */

    private static function repo(): JsonStore
    {
        return new JsonStore('correo_cuentas');
    }

    public static function cuenta(int $mid): ?array
    {
        if ($mid <= 0) return null;
        foreach (self::repo()->where('miembro_id', $mid) as $c) return $c;
        return null;
    }

    public static function conectado(int $mid): bool
    {
        $c = self::cuenta($mid);
        return $c !== null && ($c['estado'] ?? '') === 'ok';
    }

    /** Cuántas cuentas están sincronizadas (para el resumen de actualizar.php). */
    public static function totalCuentas(): array
    {
        $ok = $rec = 0;
        foreach (self::repo()->all() as $c) (($c['estado'] ?? '') === 'ok') ? $ok++ : $rec++;
        return ['ok' => $ok, 'reconectar' => $rec];
    }

    /**
     * Sincroniza el buzón: comprueba usuario y contraseña con el servidor y,
     * si entran, guarda la contraseña cifrada. Devuelve '' si salió bien o el
     * mensaje de error.
     */
    public static function sincronizar(int $mid, string $email, string $clave): string
    {
        $email = strtolower(trim($email));
        if (!self::correoPermitido($email)) {
            $d = self::dominios();
            return 'Usa tu correo' . ($d ? ' @' . implode(' o @', $d) : '') . '.';
        }
        if ($clave === '') return 'Escribe la contraseña de tu correo.';
        $c = self::cuenta($mid);
        $fallos = array_values(array_filter((array)($c['fallos'] ?? []), fn($t) => (int)$t > time() - 900));
        if (count($fallos) >= self::MAX_FALLOS) {
            return 'Demasiados intentos seguidos. Espera unos minutos antes de probar de nuevo.';
        }
        $conf = self::conf();
        $imap = new CorreoImap($conf['imap_host'], $conf['imap_puerto'], $conf['verificar_ssl']);
        try {
            $imap->conectar();
            $imap->login($email, $clave);
            $est = $imap->estado('INBOX');
        } catch (CorreoError $e) {
            if ($e->tipo === 'auth') {
                $fallos[] = time();
                self::guardarCuenta($mid, ['email' => $email, 'fallos' => $fallos] + ($c ? [] : ['estado' => 'nuevo', 'clave' => '']));
            }
            return $e->getMessage();
        } finally {
            $imap->cerrar();
        }
        self::guardarCuenta($mid, [
            'email'     => $email,
            'clave'     => self::cifrar($clave),
            'estado'    => 'ok',
            'error'     => '',
            'fallos'    => [],
            'conectado' => date('Y-m-d H:i'),
            'no_leidos' => (int)$est['no_leidos'],
            'revisado'  => time(),
            // Desde aquí se avisa de lo nuevo (lo que ya estaba no genera avisos)
            'ultimo_uid'  => max(0, (int)$est['uidnext'] - 1),
            'uidvalidity' => (int)$est['uidvalidity'],
        ]);
        unset($_SESSION['correo_carpetas']);
        return '';
    }

    public static function desconectar(int $mid): void
    {
        foreach (self::repo()->where('miembro_id', $mid) as $c) self::repo()->delete((int)$c['id']);
        unset($_SESSION['correo_carpetas']);
    }

    private static function guardarCuenta(int $mid, array $cambios): void
    {
        $repo = self::repo();
        $c = self::cuenta($mid);
        if ($c) {
            $repo->update((int)$c['id'], $cambios);
        } else {
            $repo->insert(['miembro_id' => $mid] + $cambios + ['estado' => 'ok', 'creado' => date('Y-m-d H:i')]);
        }
    }

    /**
     * Abre el buzón de la persona (ya con la sesión IMAP iniciada). Si la
     * contraseña dejó de servir, la cuenta queda «por reconectar» y no se
     * vuelve a intentar sola (así el servidor no bloquea al panel).
     */
    public static function abrir(int $mid): CorreoImap
    {
        $c = self::cuenta($mid);
        if (!$c || ($c['estado'] ?? '') !== 'ok') throw new CorreoError('Tu correo no está sincronizado.', 'uso');
        $clave = self::descifrar((string)($c['clave'] ?? ''));
        if ($clave === '') {
            self::guardarCuenta($mid, ['estado' => 'reconectar', 'error' => 'No se pudo leer la contraseña guardada.']);
            throw new CorreoError('Vuelve a escribir la contraseña de tu correo.', 'auth');
        }
        $conf = self::conf();
        $imap = new CorreoImap($conf['imap_host'], $conf['imap_puerto'], $conf['verificar_ssl']);
        try {
            $imap->conectar();
            $imap->login((string)$c['email'], $clave);
        } catch (CorreoError $e) {
            if ($e->tipo === 'auth') {
                self::guardarCuenta($mid, ['estado' => 'reconectar', 'error' => 'La contraseña del correo cambió o ya no es válida.', 'clave' => '']);
            }
            throw $e;
        }
        return $imap;
    }

    /**
     * Correos sin leer en Recibidos, para la campana. Usa el último conteo y
     * solo pregunta al servidor cada REFRESCO segundos. null = no sincronizado.
     */
    public static function noLeidos(int $mid, bool $refrescar = false): ?int
    {
        $c = self::cuenta($mid);
        if (!$c || ($c['estado'] ?? '') !== 'ok') return null;
        if ($refrescar && (int)($c['revisado'] ?? 0) < time() - self::REFRESCO) {
            self::guardarCuenta($mid, ['revisado' => time()]);   // antes de preguntar: si falla, espera igual
            try {
                $imap = self::abrir($mid);
                $n = class_exists('CorreoCopias') ? CorreoCopias::noLeidos($imap, $mid, 'INBOX') : (int)$imap->estado('INBOX')['no_leidos'];
                $imap->cerrar();
                self::guardarCuenta($mid, ['no_leidos' => $n]);
                return $n;
            } catch (CorreoError $e) {
                return $e->tipo === 'auth' ? null : (int)($c['no_leidos'] ?? 0);
            }
        }
        return (int)($c['no_leidos'] ?? 0);
    }

    /**
     * Correos que llegaron a Recibidos desde la última revisión (sin leer).
     * La primera vez solo marca dónde va (no avisa de lo viejo). Si el buzón
     * se reinició (cambia UIDVALIDITY), vuelve a empezar sin avisar.
     * Devuelve [['uid', 'de' => ['nombre','email'], 'asunto'], ...] (máx. 10).
     */
    public static function revisarNuevos(int $mid): array
    {
        $c = self::cuenta($mid);
        if (!$c || ($c['estado'] ?? '') !== 'ok') return [];
        $imap = self::abrir($mid);
        $nuevos = [];
        try {
            $st = $imap->estado('INBOX');
            $cambios = ['no_leidos' => (int)$st['no_leidos'], 'revisado' => time()];
            $ultimo = (int)($c['ultimo_uid'] ?? 0);
            $tope = max(0, (int)$st['uidnext'] - 1);
            if ($st['uidnext'] <= 0) {
                // El servidor no dio UIDNEXT: no hay forma segura de saber qué es nuevo
            } elseif ((int)($c['uidvalidity'] ?? -1) !== (int)$st['uidvalidity'] || !isset($c['ultimo_uid'])) {
                $cambios['ultimo_uid'] = $tope;
                $cambios['uidvalidity'] = (int)$st['uidvalidity'];
            } elseif ($tope > $ultimo) {
                $imap->abrir('INBOX');
                // Todo lo nuevo, aunque ya esté «leído»: Gmail (POP) lo marca leído al bajarlo y si no se avisaría
                $uids = array_slice(array_values(array_filter($imap->buscarDesde($ultimo + 1, false), fn($u) => $u > $ultimo)), -10);
                foreach ($imap->traer($uids, 'UID BODY.PEEK[HEADER.FIELDS (FROM SUBJECT)]') as $uid => $d) {
                    $cruda = '';
                    foreach ($d as $k => $v) if (str_starts_with($k, 'BODY[')) $cruda = (string)$v;
                    [$h] = CorreoMime::partir($cruda);
                    $nuevos[] = [
                        'uid'    => $uid,
                        'de'     => CorreoMime::direcciones($h['from'][0] ?? '')[0] ?? ['nombre' => '', 'email' => ''],
                        'asunto' => CorreoMime::cab($h, 'subject'),
                    ];
                }
                usort($nuevos, fn($a, $b) => $a['uid'] <=> $b['uid']);
                $cambios['ultimo_uid'] = $tope;
            }
            if (class_exists('CorreoCopias')) $cambios['no_leidos'] = CorreoCopias::noLeidos($imap, $mid, 'INBOX');
            self::guardarCuenta($mid, $cambios);
        } finally {
            $imap->cerrar();
        }
        return $nuevos;
    }

    public static function anotarNoLeidos(int $mid, int $n): void
    {
        if (self::cuenta($mid)) self::guardarCuenta($mid, ['no_leidos' => $n, 'revisado' => time()]);
    }

    /* ---------- Carpetas ---------- */

    private const NOMBRES = [
        'inbox'   => 'Recibidos',
        'drafts'  => 'Borradores',
        'sent'    => 'Enviados',
        'junk'    => 'Spam',
        'trash'   => 'Papelera',
        'archive' => 'Archivo',
    ];
    public const ICONOS = [
        'inbox' => 'correo', 'drafts' => 'documento', 'sent' => 'enviar', 'junk' => 'alerta',
        'trash' => 'eliminar', 'archive' => 'archivar', 'otra' => 'carpeta',
    ];

    /** Carpetas del buzón, con su tipo y su nombre en español (guardadas 10 min en la sesión). */
    public static function carpetas(CorreoImap $imap, int $mid): array
    {
        $cache = $_SESSION['correo_carpetas'] ?? null;
        if (is_array($cache) && ($cache['mid'] ?? 0) === $mid && ($cache['t'] ?? 0) > time() - 600) return $cache['lista'];
        $lista = [];
        $usados = [];
        foreach ($imap->listar() as $f) {
            if (in_array('\\noselect', $f['flags'], true) || in_array('\\nonexistent', $f['flags'], true)) continue;
            $crudo = $f['nombre'];
            $sep = $f['sep'] !== '' ? $f['sep'] : '.';
            $ultimo = strtolower((string)substr(strrchr($sep . $crudo, $sep), 1));
            $tipo = 'otra';
            if (strtoupper($crudo) === 'INBOX') $tipo = 'inbox';
            else foreach (['sent' => '\\sent', 'trash' => '\\trash', 'drafts' => '\\drafts', 'junk' => '\\junk', 'archive' => '\\archive'] as $t => $flag) {
                if (in_array($flag, $f['flags'], true)) { $tipo = $t; break; }
            }
            if ($tipo === 'otra') {
                $porNombre = [
                    'sent' => ['sent', 'sent items', 'sent messages', 'enviados', 'elementos enviados'],
                    'trash' => ['trash', 'deleted', 'deleted items', 'deleted messages', 'papelera', 'eliminados'],
                    'drafts' => ['drafts', 'draft', 'borradores'],
                    'junk' => ['junk', 'spam', 'correo no deseado'],
                    'archive' => ['archive', 'archivo', 'archives'],
                ];
                foreach ($porNombre as $t => $nombres) {
                    if (in_array(CorreoImap::nombreVisible($ultimo), $nombres, true)) { $tipo = $t; break; }
                }
            }
            if ($tipo !== 'otra' && isset($usados[$tipo])) $tipo = 'otra';   // solo una de cada tipo especial
            if ($tipo !== 'otra') $usados[$tipo] = true;
            $visible = CorreoImap::nombreVisible($crudo);
            if (stripos($visible, 'INBOX' . $sep) === 0) $visible = substr($visible, 6);
            $lista[] = [
                'nombre'   => $crudo,
                'tipo'     => $tipo,
                'etiqueta' => self::NOMBRES[$tipo] ?? str_replace($sep, ' / ', $visible),
            ];
        }
        $orden = ['inbox' => 0, 'drafts' => 1, 'sent' => 2, 'archive' => 3, 'junk' => 4, 'trash' => 5, 'otra' => 6];
        usort($lista, fn($a, $b) => [$orden[$a['tipo']], $a['etiqueta']] <=> [$orden[$b['tipo']], $b['etiqueta']]);
        $_SESSION['correo_carpetas'] = ['mid' => $mid, 't' => time(), 'lista' => $lista];
        return $lista;
    }

    public static function carpetaDeTipo(array $carpetas, string $tipo): ?string
    {
        foreach ($carpetas as $c) if ($c['tipo'] === $tipo) return $c['nombre'];
        return null;
    }

    /** La carpeta pedida, solo si existe en el buzón (si no, Recibidos). */
    public static function carpetaValida(array $carpetas, string $pedida): array
    {
        foreach ($carpetas as $c) if ($c['nombre'] === $pedida) return $c;
        foreach ($carpetas as $c) if ($c['tipo'] === 'inbox') return $c;
        return $carpetas[0] ?? ['nombre' => 'INBOX', 'tipo' => 'inbox', 'etiqueta' => 'Recibidos'];
    }

    /* ---------- Lista y mensajes ---------- */

    /** Una página de la carpeta (los más nuevos primero). */
    /**
     * Una página de la carpeta. Con $mid (Recibidos y lib/CorreoCopias.php), se
     * suman los correos guardados en el panel que ya no están en el servidor, y
     * el punto azul de los guardados lo pone el panel (como en Gmail).
     */
    public static function lista(CorreoImap $imap, string $carpeta, int $pagina, string $q, int $porPagina = 30, int $mid = 0): array
    {
        $imap->abrir($carpeta);
        $q = mb_substr(trim($q), 0, 100);
        $uids = $imap->buscar($q);
        $guardadas = $faltan = [];
        if ($mid > 0 && class_exists('CorreoCopias')) {
            $enServidor = array_flip($q === '' ? $uids : $imap->buscar(''));
            foreach (CorreoCopias::enCarpeta($mid, $carpeta) as $u => $f) {
                if (isset($enServidor[$u])) $guardadas[$u] = $f;
                elseif (CorreoCopias::coincide($f, $q)) $faltan[$u] = $f;
            }
            $uids = array_values(array_unique(array_merge($uids, array_keys($faltan))));
        }
        rsort($uids);
        $total = count($uids);
        $paginas = max(1, (int)ceil($total / $porPagina));
        $pagina = min(max(1, $pagina), $paginas);
        $tramo = array_slice($uids, ($pagina - 1) * $porPagina, $porPagina);
        $items = [];
        $delServidor = array_values(array_filter($tramo, fn($u) => !isset($faltan[$u])));
        // BODYSTRUCTURE: qué adjuntos trae cada uno (PDF, Excel, imagen…) sin bajar el correo
        $datos = $delServidor ? $imap->traer($delServidor, 'UID FLAGS INTERNALDATE RFC822.SIZE BODYSTRUCTURE BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE CONTENT-TYPE)]') : [];
        foreach ($tramo as $uid) {
            if (isset($faltan[$uid])) { $items[] = CorreoCopias::item($faltan[$uid]); continue; }
            if (!isset($datos[$uid])) continue;
            $d = $datos[$uid];
            $cabCruda = '';
            foreach ($d as $k => $v) if (str_starts_with($k, 'BODY[')) $cabCruda = (string)$v;
            [$h] = CorreoMime::partir($cabCruda);
            $flags = array_map('strtolower', array_filter((array)($d['FLAGS'] ?? []), 'is_string'));
            $de = CorreoMime::direcciones($h['from'][0] ?? '')[0] ?? ['nombre' => '', 'email' => ''];
            $para = CorreoMime::direcciones($h['to'][0] ?? '');
            $ct = strtolower($h['content-type'][0] ?? '');
            $adjs = isset($d['BODYSTRUCTURE']) ? self::adjuntosDe($d['BODYSTRUCTURE']) : [];
            $items[] = [
                'adjuntos' => $adjs,
                'uid'     => $uid,
                'de'      => $de,
                'para'    => $para,
                'asunto'  => CorreoMime::cab($h, 'subject'),
                'fecha'   => self::fecha((string)($d['INTERNALDATE'] ?? ''), $h['date'][0] ?? ''),
                'leido'   => isset($guardadas[$uid]) ? !empty($guardadas[$uid]['leido']) : in_array('\\seen', $flags, true),
                'marcado' => in_array('\\flagged', $flags, true),
                'adjunto' => $adjs || (!isset($d['BODYSTRUCTURE']) && (str_contains($ct, 'multipart/mixed') || str_contains($ct, 'multipart/report'))),
                'tam'     => (int)($d['RFC822.SIZE'] ?? 0),
            ];
        }
        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'paginas' => $paginas, 'por' => $porPagina];
    }

    /** El mensaje completo (lo marca leído). null si no existe. */
    public static function mensaje(CorreoImap $imap, string $carpeta, int $uid): ?array
    {
        $imap->abrir($carpeta, true);
        $crudo = $imap->crudo($uid);
        if ($crudo === '') return null;
        $m = CorreoMime::analizar($crudo);
        $h = $m['cab'];
        $imap->marcar($uid, '\\Seen');
        $m['uid'] = $uid;
        $m['asunto'] = CorreoMime::cab($h, 'subject');
        $m['de'] = CorreoMime::direcciones($h['from'][0] ?? '');
        $m['para'] = CorreoMime::direcciones($h['to'][0] ?? '');
        $m['cc'] = CorreoMime::direcciones($h['cc'][0] ?? '');
        $m['responder_a'] = CorreoMime::direcciones($h['reply-to'][0] ?? '');
        $m['fecha'] = self::fecha('', $h['date'][0] ?? '');
        $m['message_id'] = trim((string)($h['message-id'][0] ?? ''));
        $m['referencias'] = trim((string)($h['references'][0] ?? ''));
        // Texto para citar: el plano si vino; si no, el HTML pasado a texto
        $m['texto_cita'] = trim((string)($m['texto'] ?? '')) !== '' ? (string)$m['texto'] : CorreoHtml::aTexto((string)($m['html'] ?? ''));
        $m['crudo'] = $crudo;   // para guardar la copia en el panel (lib/CorreoCopias.php)
        return $m;
    }

    /* ---------- Los adjuntos, para mostrarlos en la lista ---------- */

    /** Clase de un archivo por su nombre y su tipo: pdf, excel, word, ppt, imagen u otro (el ícono de la lista). */
    public static function claseArchivo(string $nombre, string $mime = ''): string
    {
        $ext = strtolower((string)pathinfo($nombre, PATHINFO_EXTENSION));
        $mime = strtolower($mime);
        return match (true) {
            $ext === 'pdf' || $mime === 'application/pdf' => 'pdf',
            in_array($ext, ['xls', 'xlsx', 'xlsm', 'csv', 'ods'], true) || str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel') || $mime === 'text/csv' => 'excel',
            in_array($ext, ['doc', 'docx', 'odt', 'rtf'], true) || str_contains($mime, 'wordprocessing') || $mime === 'application/msword' => 'word',
            in_array($ext, ['ppt', 'pptx', 'odp'], true) || str_contains($mime, 'presentation') || str_contains($mime, 'powerpoint') => 'ppt',
            in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'heic', 'bmp', 'svg', 'tif', 'tiff'], true) || str_starts_with($mime, 'image/') => 'imagen',
            default => 'otro',
        };
    }

    /**
     * Los adjuntos de un BODYSTRUCTURE ya desarmado: [['nombre', 'clase']].
     * Cuenta lo que lleva nombre de archivo o va como «attachment»; no las
     * imágenes incrustadas en el cuerpo (con Content-ID y sin «attachment»).
     */
    public static function adjuntosDe(mixed $bs): array
    {
        $out = [];
        $nombreEn = function ($l): string {
            if (!is_array($l)) return '';
            for ($i = 0; $i + 1 < count($l); $i += 2) {
                if (!is_string($l[$i]) || !is_string($l[$i + 1])) continue;
                $k = strtoupper($l[$i]);
                if (in_array($k, ['FILENAME', 'NAME'], true)) return CorreoMime::decodificar($l[$i + 1]);
                if (in_array($k, ['FILENAME*', 'NAME*'], true)) return rawurldecode((string)preg_replace("/^[^']*'[^']*'/", '', $l[$i + 1]));
            }
            return '';
        };
        $recorrer = function ($p, int $hondo = 0, string $padre = '') use (&$recorrer, &$out, $nombreEn): void {
            if (!is_array($p) || !$p || $hondo > 12 || count($out) >= 20) return;
            if (is_array($p[0])) {
                // multipart: (parte)(parte)… "SUBTIPO" y lo extra (BOUNDARY…), que no son partes
                $partes = [];
                $subM = '';
                foreach ($p as $x) {
                    if (!is_array($x)) { $subM = strtolower((string)$x); break; }
                    $partes[] = $x;
                }
                foreach ($partes as $x) $recorrer($x, $hondo + 1, $subM);
                return;
            }
            $tipo = strtolower((string)$p[0]);
            $sub = strtolower((string)($p[1] ?? ''));
            $disp = '';
            $nombre = $nombreEn($p[2] ?? null);
            for ($i = 7; $i < count($p); $i++) {
                if (is_array($p[$i]) && is_string($p[$i][0] ?? null) && in_array(strtolower($p[$i][0]), ['attachment', 'inline'], true)) {
                    $disp = strtolower($p[$i][0]);
                    $nombre = $nombreEn($p[$i][1] ?? null) ?: $nombre;
                    break;
                }
            }
            if ($nombre === '' && $disp !== 'attachment') return;                               // el cuerpo (texto, HTML)
            // Imagen incrustada en el cuerpo (el logo de la firma): con Content-ID, y «inline» o dentro de multipart/related
            if ($tipo === 'image' && is_string($p[3] ?? null) && $p[3] !== '' && ($disp !== 'attachment' || $padre === 'related')) return;
            $out[] = ['nombre' => mb_substr($nombre !== '' ? $nombre : 'adjunto', 0, 160), 'clase' => self::claseArchivo($nombre, $tipo . '/' . $sub)];
        };
        $recorrer($bs);
        return $out;
    }

    /* ---------- La conversación: lo que enviaste y lo que te respondieron ---------- */

    /** Asunto sin «Re:», «RV:», «Fwd:»… (repetidos), para juntar una conversación */
    public static function asuntoBase(string $s): string
    {
        $s = trim($s);
        while (preg_match('/^(re|rv|res|fw|fwd|reenv|tr|aw|wg)\s*(\[\d+\])?\s*:\s*/iu', $s, $mm)) $s = trim(mb_substr($s, mb_strlen($mm[0])));
        return $s;
    }

    /** Los <id> de una cabecera (Message-ID, In-Reply-To, References), en minúsculas */
    private static function idsDe(string $v): array
    {
        preg_match_all('/<[^<>\s]{3,250}>/', $v, $mm);
        return array_map('strtolower', $mm[0]);
    }

    /**
     * La conversación del mensaje $m (el de Correo::mensaje): los correos de
     * Recibidos, Enviados y la carpeta abierta (y las copias que guardó el
     * panel) que siguen su cadena de respuestas (Message-ID, In-Reply-To,
     * References) o, si algún programa perdió esas cabeceras, tienen el mismo
     * asunto y alguien en común. Del más viejo al más nuevo, con el propio
     * marcado 'este' => true. [] si está solo.
     * Cada uno: carpeta, tipo, uid, de, para, fecha, asunto, mio, este.
     */
    public static function hilo(CorreoImap $imap, int $mid, array $carpetas, string $carpeta, array $m, int $max = 30): array
    {
        $base = self::asuntoBase((string)$m['asunto']);
        $propio = strtolower(trim((string)$m['message_id']));
        $cadena = array_flip(array_filter(array_merge([$propio], self::idsDe((string)($m['cab']['in-reply-to'][0] ?? '')), self::idsDe((string)$m['referencias']))));
        if ($base === '' && count($cadena) < 2) return [];
        $yo = strtolower((string)(self::cuenta($mid)['email'] ?? ''));
        $correos = fn(array $l) => array_values(array_filter(array_map(fn($d) => strtolower((string)($d['email'] ?? '')), $l), fn($e) => $e !== '' && $e !== $yo));
        $gente = array_flip(array_merge($correos($m['de']), $correos($m['para']), $correos($m['cc'])));
        $tipos = array_column($carpetas, 'tipo', 'nombre');

        // Candidatos: el mismo asunto (sin «Re:») en cada carpeta; el servidor no busca por References
        $cand = [];
        $dondeBuscar = array_values(array_unique(array_filter([self::carpetaDeTipo($carpetas, 'inbox'), self::carpetaDeTipo($carpetas, 'sent'), $carpeta])));
        foreach ($dondeBuscar as $c) {
            if ($base === '') break;
            try {
                $imap->abrir($c);
                $uids = $imap->buscarAsunto(mb_substr($base, 0, 120));
                $datos = $uids ? $imap->traer($uids, 'UID INTERNALDATE BODY.PEEK[HEADER.FIELDS (FROM TO CC SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES)]') : [];
            } catch (CorreoError $e) {
                continue;
            }
            foreach ($datos as $uid => $d) {
                $cabCruda = '';
                foreach ($d as $k => $v) if (str_starts_with($k, 'BODY[')) $cabCruda = (string)$v;
                [$h] = CorreoMime::partir($cabCruda);
                $cand[] = [
                    'carpeta' => $c, 'tipo' => (string)($tipos[$c] ?? 'otra'), 'uid' => (int)$uid,
                    'de' => CorreoMime::direcciones($h['from'][0] ?? '')[0] ?? ['nombre' => '', 'email' => ''],
                    'para' => array_merge(CorreoMime::direcciones($h['to'][0] ?? ''), CorreoMime::direcciones($h['cc'][0] ?? '')),
                    'fecha' => self::fecha((string)($d['INTERNALDATE'] ?? ''), $h['date'][0] ?? ''),
                    'asunto' => CorreoMime::cab($h, 'subject'),
                    'id' => strtolower(trim((string)($h['message-id'][0] ?? ''))),
                    'refs' => array_merge(self::idsDe((string)($h['in-reply-to'][0] ?? '')), self::idsDe((string)($h['references'][0] ?? ''))),
                ];
            }
        }
        // Las que ya no están en el servidor (las guardó el panel): por su id o por asunto y gente
        if ($mid > 0 && class_exists('CorreoCopias')) {
            foreach ([self::carpetaDeTipo($carpetas, 'inbox'), $carpeta] as $c) {
                if (!$c) continue;
                foreach (CorreoCopias::enCarpeta($mid, $c) as $u => $f) {
                    if (self::asuntoBase((string)$f['asunto']) !== $base && !isset($cadena[strtolower((string)$f['message_id'])])) continue;
                    $cand[] = ['carpeta' => $c, 'tipo' => (string)($tipos[$c] ?? 'otra'), 'uid' => (int)$u, 'de' => (array)$f['de'],
                               'para' => array_merge((array)$f['para'], (array)$f['cc']), 'fecha' => (int)$f['fecha'], 'asunto' => (string)$f['asunto'],
                               'id' => strtolower((string)$f['message_id']), 'refs' => []];
                }
            }
        }

        // Se juntan las que comparten la cadena (y la cadena crece con cada una), o asunto igual + alguien en común
        $dentro = [];
        for ($vuelta = 0, $cambio = true; $cambio && $vuelta < 4; $vuelta++) {
            $cambio = false;
            foreach ($cand as $i => $x) {
                if (isset($dentro[$i])) continue;
                $porCadena = ($x['id'] !== '' && isset($cadena[$x['id']])) || array_intersect_key(array_flip($x['refs']), $cadena);
                $porAsunto = !$porCadena && self::asuntoBase($x['asunto']) === $base && $base !== ''
                    && array_intersect_key(array_flip(array_merge($correos([$x['de']]), $correos($x['para']))), $gente);
                if (!$porCadena && !$porAsunto) continue;
                $dentro[$i] = true;
                $cambio = true;
                foreach (array_merge([$x['id']], $x['refs']) as $id) if ($id !== '' && !isset($cadena[$id])) $cadena[$id] = true;
            }
        }
        // Sin repetir (un correo a ti mismo está en Recibidos y en Enviados), y el propio marcado
        $out = [];
        $vistos = [];
        foreach (array_keys($dentro) as $i) {
            $x = $cand[$i];
            $llave = $x['id'] !== '' ? $x['id'] : $x['carpeta'] . '#' . $x['uid'];
            $esEste = ($x['carpeta'] === $carpeta && $x['uid'] === (int)$m['uid']) || ($propio !== '' && $x['id'] === $propio);
            if (isset($vistos[$llave])) {
                if ($esEste) $out[$vistos[$llave]] = ['este' => true] + $out[$vistos[$llave]];
                continue;
            }
            $vistos[$llave] = count($out);
            $out[] = ['este' => $esEste, 'mio' => strtolower((string)($x['de']['email'] ?? '')) === $yo] + $x;
        }
        if (!array_filter($out, fn($x) => $x['este'])) {
            $out[] = ['este' => true, 'mio' => strtolower((string)($m['de'][0]['email'] ?? '')) === $yo, 'carpeta' => $carpeta,
                      'tipo' => (string)($tipos[$carpeta] ?? 'otra'), 'uid' => (int)$m['uid'], 'de' => $m['de'][0] ?? ['nombre' => '', 'email' => ''],
                      'para' => array_merge($m['para'], $m['cc']), 'fecha' => (int)$m['fecha'], 'asunto' => (string)$m['asunto'], 'id' => $propio, 'refs' => []];
        }
        if (count($out) < 2) return [];
        usort($out, fn($a, $b) => $a['fecha'] <=> $b['fecha']);
        return array_slice($out, -$max);
    }

    /** Fecha del mensaje como timestamp (la del servidor si la de la cabecera no sirve). */
    private static function fecha(string $interna, string $cabecera): int
    {
        foreach ([$cabecera, $interna] as $f) {
            $f = preg_replace('/\s*\([^)]*\)\s*$/', '', trim($f));
            if ($f !== '' && ($t = strtotime($f)) !== false) return $t;
        }
        return time();
    }

    /** "10:24" hoy, "12 oct" este año, "12/10/2025" antes. */
    public static function fechaCorta(int $t): string
    {
        if (date('Y-m-d', $t) === date('Y-m-d')) return date('H:i', $t);
        $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        if (date('Y', $t) === date('Y')) return (int)date('j', $t) . ' ' . $meses[(int)date('n', $t) - 1];
        return date('d/m/Y', $t);
    }

    /** "lun 19 oct 2026, 18:30" */
    public static function fechaLarga(int $t): string
    {
        $dias = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
        $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        return $dias[(int)date('w', $t)] . ' ' . (int)date('j', $t) . ' ' . $meses[(int)date('n', $t) - 1] . ' ' . date('Y, H:i', $t);
    }

    /* ---------- Enviar ---------- */

    /**
     * Envía un correo desde el buzón de la persona y guarda la copia en
     * Enviados. $d: para, cc, asunto, texto, en_respuesta_a, referencias,
     * adjuntos [['nombre','tipo','datos']] y las opciones de envío:
     * prioridad (1–5), acuse (confirmación de lectura), dsn (aviso de entrega)
     * y guardar_en (carpeta para la copia; '-' = no guardar).
     * Devuelve ['dsn' => true|false|null] (null = no se pidió).
     */
    public static function enviar(int $mid, string $nombre, array $d): array
    {
        $c = self::cuenta($mid);
        if (!$c || ($c['estado'] ?? '') !== 'ok') throw new CorreoError('Tu correo no está sincronizado.', 'uso');
        $para = self::listaCorreos((string)($d['para'] ?? ''));
        $cc = self::listaCorreos((string)($d['cc'] ?? ''));
        $cco = array_values(array_diff(self::listaCorreos((string)($d['cco'] ?? '')), $para, $cc));
        if (!$para) throw new CorreoError('Escribe al menos un destinatario válido en «Para».', 'uso');
        if (count($para) + count($cc) + count($cco) > 50) throw new CorreoError('Son demasiados destinatarios (máximo 50).', 'uso');
        $asunto = trim((string)($d['asunto'] ?? ''));
        $texto = str_replace("\r\n", "\n", (string)($d['texto'] ?? ''));
        $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#1f2937">'
            . nl2br(CorreoHtml::texto($texto)) . '</div>';
        $crudo = CorreoMime::armar([
            'de_nombre' => $nombre, 'de_email' => (string)$c['email'],
            'para' => $para, 'cc' => $cc, 'asunto' => $asunto !== '' ? $asunto : '(sin asunto)',
            'texto' => $texto, 'html' => $html,
            'adjuntos' => (array)($d['adjuntos'] ?? []),
            'en_respuesta_a' => self::idMensaje((string)($d['en_respuesta_a'] ?? '')),
            'referencias' => self::referencias((string)($d['referencias'] ?? '')),
            'prioridad' => max(1, min(5, (int)($d['prioridad'] ?? 3) ?: 3)),
            'acuse' => !empty($d['acuse']),
        ]);
        $clave = self::descifrar((string)($c['clave'] ?? ''));
        $conf = self::conf();
        $pideDsn = !empty($d['dsn']);
        try {
            $dsnOk = (new CorreoSmtp())->enviar($conf['smtp_host'], $conf['smtp_puerto'], $conf['verificar_ssl'],
                (string)$c['email'], $clave, (string)$c['email'], array_merge($para, $cc, $cco), $crudo, $pideDsn);
        } catch (CorreoError $e) {
            if ($e->tipo === 'auth') {
                self::guardarCuenta($mid, ['estado' => 'reconectar', 'error' => 'La contraseña del correo cambió o ya no es válida.', 'clave' => '']);
            }
            throw $e;
        }
        self::anotarRecientes($mid, $d, array_merge($para, $cc, $cco));
        // La copia en Enviados o en la carpeta que eligió; «-» = sin copia (si falla, el correo igual salió)
        $guardarEn = (string)($d['guardar_en'] ?? '');
        if ($guardarEn !== '-') {
            try {
                $imap = self::abrir($mid);
                $lista = self::carpetas($imap, $mid);
                $destino = null;
                foreach ($lista as $f) if ($guardarEn !== '' && $f['nombre'] === $guardarEn) $destino = $f['nombre'];
                $destino ??= self::carpetaDeTipo($lista, 'sent');
                // La copia propia sí dice a quién fue en copia oculta (el que salió, no)
                $copia = $cco ? (string)preg_replace('/\r\nSubject: /', "\r\nBcc: " . implode(', ', $cco) . "\r\nSubject: ", $crudo, 1) : $crudo;
                if ($destino !== null) $imap->guardar($destino, $copia);
                $imap->cerrar();
            } catch (CorreoError $e) {
            }
        }
        return ['dsn' => $pideDsn ? $dsnOk : null];
    }

    /** Guarda datos propios de la cuenta (reglas, punteros…). No crea cuentas. */
    public static function guardarDatos(int $mid, array $cambios): void
    {
        if (self::cuenta($mid)) self::guardarCuenta($mid, $cambios);
    }

    /** Las últimas direcciones a las que la persona escribió (la más reciente primero). */
    public static function recientes(int $mid): array
    {
        return array_values(array_filter((array)(self::cuenta($mid)['recientes'] ?? []), fn($r) => is_array($r) && !empty($r['e'])));
    }

    /** Guarda a quién se escribió (máximo 40), con el nombre si venía «Nombre <correo>». */
    private static function anotarRecientes(int $mid, array $d, array $enviados): void
    {
        $nombres = [];
        foreach (['para', 'cc', 'cco'] as $k) {
            foreach (CorreoMime::direcciones((string)($d[$k] ?? '')) as $x) {
                if ($x['nombre'] !== '') $nombres[$x['email']] = mb_substr($x['nombre'], 0, 80);
            }
        }
        $lista = [];
        foreach (self::recientes($mid) as $r) $lista[strtolower((string)$r['e'])] = $r;
        foreach (array_reverse($enviados) as $e) {
            $e = strtolower($e);
            $antes = $lista[$e] ?? [];
            unset($lista[$e]);
            $lista = [$e => ['e' => $e, 'n' => $nombres[$e] ?? (string)($antes['n'] ?? ''), 't' => time()]] + $lista;
        }
        self::guardarCuenta($mid, ['recientes' => array_values(array_slice($lista, 0, 40))]);
    }

    /** Correo InnoTech sincronizado de cada persona: [id del miembro => correo]. */
    public static function correosSincronizados(): array
    {
        $out = [];
        foreach (self::repo()->all() as $c) {
            if (!empty($c['email']) && in_array($c['estado'] ?? '', ['ok', 'reconectar'], true)) $out[(int)$c['miembro_id']] = strtolower((string)$c['email']);
        }
        return $out;
    }

    /** "a@x.com, B <b@y.com>" → ['a@x.com', 'b@y.com'] (solo los válidos, sin repetir). */
    public static function listaCorreos(string $v): array
    {
        $out = [];
        foreach (CorreoMime::direcciones($v) as $d) {
            if (filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $out[$d['email']] = true;
        }
        return array_keys($out);
    }

    private static function idMensaje(string $v): string
    {
        return preg_match('/^<[^<>\s]{3,250}>$/', trim($v)) ? trim($v) : '';
    }

    private static function referencias(string $v): string
    {
        preg_match_all('/<[^<>\s]{3,250}>/', $v, $m);
        return implode(' ', array_slice($m[0], -20));
    }

    /* ---------- Cifrado de la contraseña ---------- */

    private static function secreto(): string
    {
        $f = __DIR__ . '/../data/correo.secreto';
        if (!is_file($f)) {
            file_put_contents($f, base64_encode(random_bytes(32)));
            @chmod($f, 0600);
        }
        return (string)base64_decode(trim((string)file_get_contents($f)));
    }

    private static function cifrar(string $plano): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plano, 'aes-256-gcm', self::secreto(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    /** Cifra datos binarios (las copias de los correos): iv(12) + tag(16) + cifrado. */
    public static function sellar(string $plano): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plano, 'aes-256-gcm', self::secreto(), OPENSSL_RAW_DATA, $iv, $tag);
        return $ct === false ? '' : $iv . $tag . $ct;
    }

    /** Lo contrario de sellar(); '' si no se puede abrir. */
    public static function desellar(string $b): string
    {
        if (strlen($b) < 29) return '';
        $r = openssl_decrypt(substr($b, 28), 'aes-256-gcm', self::secreto(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
        return $r === false ? '' : $r;
    }

    private static function descifrar(string $guardado): string
    {
        if (!str_starts_with($guardado, 'v1:')) return '';
        $b = (string)base64_decode(substr($guardado, 3));
        if (strlen($b) < 29) return '';
        $r = openssl_decrypt(substr($b, 28), 'aes-256-gcm', self::secreto(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
        return $r === false ? '' : $r;
    }
}
