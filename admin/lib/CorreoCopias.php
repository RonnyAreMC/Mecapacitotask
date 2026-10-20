<?php
/**
 * Los correos de Recibidos se quedan guardados en el panel, como en Gmail.
 *
 * Si otro programa saca los correos del servidor (Gmail cuando «consulta el
 * correo de otras cuentas» por POP sin dejar copia, o un celular configurado
 * con POP), en el webmail desaparecen. En el panel no: cada correo que llega a
 * Recibidos se descarga y se guarda (cron_correo.php, cada minuto, sin
 * marcarlo como leído y sin borrar nada del servidor), y también cada correo
 * que se abre. Recibidos muestra lo que hay en el servidor más lo guardado que
 * ya no está ahí, todo junto y en orden.
 *
 * El punto azul lo pone el panel: un correo guardado sigue «sin leer» hasta que
 * la persona lo abre aquí (Gmail, al bajarlo por POP, lo marca como leído en el
 * servidor aunque nadie lo haya visto).
 *
 * Cada copia es el mensaje entero (con adjuntos), comprimido y cifrado con la
 * misma llave que la contraseña del correo, en data/correo_copias/<persona>/
 * (data/ está cerrada a la web). Los datos para la lista van en la tabla
 * «correo_copias». Solo la propia persona ve las suyas; al desconectar el
 * correo se borran todas.
 *
 * Se carga con is_file (no desde bootstrap): si falta, el correo funciona como
 * antes (solo lo que hay en el servidor).
 */
final class CorreoCopias
{
    /** Más grande que esto se guarda solo el encabezado (sin cuerpo ni adjuntos). */
    public const TOPE_MENSAJE = 15728640;     // 15 MB
    /**
     * Lo que puede ocupar en disco lo guardado de cada persona. Al pasarse se
     * vacía todo lo guardado y vuelve a empezar (se queda solo el que acaba de
     * llegar). Para probar se puede bajar con PANEL_COPIAS_TOPE_MB.
     */
    public const TOPE_PERSONA_MB = 150;

    private static ?array $cache = null;

    private static function repo(): JsonStore
    {
        return new JsonStore('correo_copias');
    }

    private static function todas(): array
    {
        return self::$cache ??= self::repo()->all();
    }

    private static function dir(int $mid): string
    {
        $d = __DIR__ . '/../data/correo_copias/' . $mid;
        if (!is_dir($d)) @mkdir($d, 0700, true);
        return $d;
    }

    private static function deLaPersona(int $mid): array
    {
        return array_values(array_filter(self::todas(), fn($f) => (int)$f['miembro_id'] === $mid));
    }

    /** Las copias de una carpeta, por su UID en el servidor. */
    public static function enCarpeta(int $mid, string $carpeta): array
    {
        $out = [];
        foreach (self::deLaPersona($mid) as $f) {
            if ((string)$f['carpeta'] === $carpeta && (int)$f['uid'] > 0) $out[(int)$f['uid']] = $f;
        }
        return $out;
    }

    public static function porUid(int $mid, string $carpeta, int $uid): ?array
    {
        return self::enCarpeta($mid, $carpeta)[$uid] ?? null;
    }

    private static function fecha(string $cab): int
    {
        $f = preg_replace('/\s*\([^)]*\)\s*$/', '', trim($cab));
        return $f !== '' && ($t = strtotime($f)) !== false ? $t : time();
    }

    /* ---------------- Guardar ---------------- */

    /**
     * Guarda la copia de un correo (o la pone al día si ya estaba). $crudo
     * puede venir vacío si pasa del tope: se guarda solo el encabezado.
     * $leido solo sube a «leído», nunca lo baja (eso lo hace marcar()).
     */
    public static function guardar(int $mid, string $crudo, string $carpeta, int $uid, bool $leido, string $cabecera = '', int $tam = 0): ?array
    {
        if ($crudo === '' && $cabecera === '') return null;
        if ($ya = self::porUid($mid, $carpeta, $uid)) {
            $cambios = [];
            if ($leido && empty($ya['leido'])) $cambios['leido'] = true;
            // Antes solo estaba el encabezado y ahora llegó entero: se completa
            if ($crudo !== '' && ($ya['archivo'] ?? '') === '') $cambios['archivo'] = self::escribir($mid, (string)$ya['llave'], $crudo);
            if ($cambios) { self::repo()->update((int)$ya['id'], $cambios); self::$cache = null; }
            return $cambios + $ya;
        }
        $m = CorreoMime::analizar($crudo !== '' ? $crudo : $cabecera . "\r\n\r\n");
        $h = $m['cab'];
        $msgId = trim((string)($h['message-id'][0] ?? ''));
        $llave = sha1($mid . '|' . $carpeta . '|' . $uid . '|' . $msgId);
        $adj = [];
        foreach ($m['adjuntos'] as $i => $a) {
            if (!empty($a['inline']) && ($a['cid'] ?? '') !== '') continue;
            $adj[] = ['i' => $i, 'nombre' => mb_substr((string)$a['nombre'], 0, 160), 'tam' => (int)$a['tam']];
        }
        $texto = trim((string)($m['texto'] ?? '')) !== '' ? (string)$m['texto'] : CorreoHtml::aTexto((string)($m['html'] ?? ''));
        $fila = self::repo()->insert([
            'miembro_id' => $mid,
            'llave'      => $llave,
            'message_id' => mb_substr($msgId, 0, 300),
            'carpeta'    => $carpeta,
            'uid'        => $uid,
            'asunto'     => mb_substr(CorreoMime::cab($h, 'subject'), 0, 300),
            'de'         => CorreoMime::direcciones($h['from'][0] ?? '')[0] ?? ['nombre' => '', 'email' => ''],
            'para'       => array_slice(CorreoMime::direcciones($h['to'][0] ?? ''), 0, 20),
            'cc'         => array_slice(CorreoMime::direcciones($h['cc'][0] ?? ''), 0, 20),
            'fecha'      => self::fecha((string)($h['date'][0] ?? '')),
            'tam'        => $crudo !== '' ? strlen($crudo) : $tam,
            'adjuntos'   => $adj,
            // Para buscar en lo guardado (lo demás va cifrado)
            'resumen'    => mb_substr(trim((string)preg_replace('/\s+/u', ' ', $texto)), 0, 400),
            'leido'      => $leido,
            'archivo'    => $crudo !== '' ? self::escribir($mid, $llave, $crudo) : '',
            'guardado'   => time(),
        ]);
        self::$cache = null;
        self::podar($mid, (int)$fila['id']);
        return self::porUid($mid, $carpeta, $uid) ?? $fila;   // por si el tope vació lo demás
    }

    private static function escribir(int $mid, string $llave, string $crudo): string
    {
        $nombre = $llave . '.bin';
        $sellado = Correo::sellar((string)gzcompress($crudo, 6));
        if ($sellado === '' || file_put_contents(self::dir($mid) . '/' . $nombre, $sellado, LOCK_EX) === false) return '';
        @chmod(self::dir($mid) . '/' . $nombre, 0600);
        return $nombre;
    }

    private static function crudo(array $f): string
    {
        $nombre = basename((string)($f['archivo'] ?? ''));
        if ($nombre === '' || !is_file($ruta = self::dir((int)$f['miembro_id']) . '/' . $nombre)) return '';
        $z = Correo::desellar((string)file_get_contents($ruta));
        return $z === '' ? '' : (string)@gzuncompress($z);
    }

    public static function tope(): int
    {
        $mb = (int)getenv('PANEL_COPIAS_TOPE_MB');
        return ($mb > 0 ? $mb : self::TOPE_PERSONA_MB) * 1048576;
    }

    /** Lo que ocupa en disco lo guardado de una persona (bytes). */
    public static function enDisco(int $mid): int
    {
        $t = 0;
        foreach (glob(__DIR__ . '/../data/correo_copias/' . $mid . '/*.bin') ?: [] as $f) $t += (int)@filesize($f);   // sin crear la carpeta
        return $t;
    }

    /** Si se pasa del tope, se vacía todo lo guardado (menos $conservar) y vuelve a empezar. */
    private static function podar(int $mid, int $conservar = 0): void
    {
        if (self::enDisco($mid) <= self::tope()) return;
        $n = 0;
        foreach (self::deLaPersona($mid) as $f) {
            if ((int)$f['id'] === $conservar) continue;
            self::borrarFila($f);
            $n++;
        }
        self::$cache = null;
        if ($n && class_exists('Notif')) {
            Notif::crear([$mid], 'correo', 'Se vació lo guardado de tu correo',
                'Llegó a ' . self::TOPE_PERSONA_MB . ' MB y vuelve a empezar desde cero. Lo que sigue en el servidor no cambia.', 'notificaciones.php?tab=correo', 0);
        }
    }

    private static function borrarFila(array $f): void
    {
        $nombre = basename((string)($f['archivo'] ?? ''));
        if ($nombre !== '') @unlink(self::dir((int)$f['miembro_id']) . '/' . $nombre);
        self::repo()->delete((int)$f['id']);
    }

    /* ---------------- Para la lista de Recibidos ---------------- */

    /** ¿La copia coincide con lo que se busca? (asunto, remitente, destinatarios y el comienzo del texto) */
    public static function coincide(array $f, string $q): bool
    {
        $q = mb_strtolower(trim($q));
        if ($q === '') return true;
        $txt = mb_strtolower($f['asunto'] . ' ' . ($f['de']['nombre'] ?? '') . ' ' . ($f['de']['email'] ?? '') . ' '
            . implode(' ', array_map(fn($d) => $d['nombre'] . ' ' . $d['email'], (array)$f['para'])) . ' ' . ($f['resumen'] ?? ''));
        return str_contains($txt, $q);
    }

    /** Un correo guardado que ya no está en el servidor, como fila de la lista. */
    public static function item(array $f): array
    {
        return ['uid' => (int)$f['uid'], 'de' => (array)$f['de'], 'para' => (array)$f['para'], 'asunto' => (string)$f['asunto'],
                'fecha' => (int)$f['fecha'], 'leido' => !empty($f['leido']), 'marcado' => false,
                'adjunto' => !empty($f['adjuntos']), 'tam' => (int)$f['tam'], 'guardado' => true];
    }

    /**
     * Correos sin leer de Recibidos como los ve el panel: los que tienen copia
     * cuentan según el panel; los demás, según el servidor.
     */
    public static function noLeidos(CorreoImap $imap, int $mid, string $bandeja): int
    {
        $copias = self::enCarpeta($mid, $bandeja);
        $imap->abrir($bandeja);
        $n = 0;
        foreach ($imap->buscar('', true) as $u) if (!isset($copias[$u])) $n++;
        foreach ($copias as $f) if (empty($f['leido'])) $n++;
        return $n;
    }

    /* ---------------- Leer ---------------- */

    /** El correo guardado, con la misma forma que Correo::mensaje() (y 'copia' => true). Lo marca como leído. */
    public static function mensaje(int $mid, string $carpeta, int $uid): ?array
    {
        $f = self::porUid($mid, $carpeta, $uid);
        if (!$f) return null;
        if (empty($f['leido'])) {
            self::repo()->update((int)$f['id'], ['leido' => true]);
            self::$cache = null;
        }
        $crudo = self::crudo($f);
        if ($crudo !== '') {
            $m = CorreoMime::analizar($crudo);
            $h = $m['cab'];
            $m['responder_a'] = CorreoMime::direcciones($h['reply-to'][0] ?? '');
            $m['referencias'] = trim((string)($h['references'][0] ?? ''));
            $m['texto_cita'] = trim((string)($m['texto'] ?? '')) !== '' ? (string)$m['texto'] : CorreoHtml::aTexto((string)($m['html'] ?? ''));
        } else {
            $aviso = 'Este correo pesaba ' . round((int)$f['tam'] / 1048576, 1) . ' MB: el panel guardó solo sus datos (de quién, para quién, el asunto y la fecha), sin el contenido ni los adjuntos.';
            $m = ['texto' => $aviso, 'html' => null, 'adjuntos' => [], 'responder_a' => [], 'referencias' => '', 'texto_cita' => ''];
        }
        return [
            'uid' => $uid, 'asunto' => (string)$f['asunto'], 'de' => [(array)$f['de']], 'para' => (array)$f['para'], 'cc' => (array)$f['cc'],
            'fecha' => (int)$f['fecha'], 'message_id' => (string)$f['message_id'], 'guardado' => (int)$f['guardado'],
            'copia' => true, 'solo_cabecera' => $crudo === '',
        ] + $m;
    }

    /** Los adjuntos de la copia (con sus datos). */
    public static function adjuntos(int $mid, string $carpeta, int $uid): array
    {
        $f = self::porUid($mid, $carpeta, $uid);
        $crudo = $f ? self::crudo($f) : '';
        return $crudo !== '' ? CorreoMime::analizar($crudo)['adjuntos'] : [];
    }

    /* ---------------- Cambiar ---------------- */

    public static function marcar(int $mid, string $carpeta, int $uid, bool $leido): void
    {
        if ($f = self::porUid($mid, $carpeta, $uid)) {
            self::repo()->update((int)$f['id'], ['leido' => $leido]);
            self::$cache = null;
        }
    }

    /** Lo que la persona borra en el panel se va también de lo guardado (si no, volvería a salir). */
    public static function quitar(int $mid, string $carpeta, int $uid): bool
    {
        $f = self::porUid($mid, $carpeta, $uid);
        if (!$f) return false;
        self::borrarFila($f);
        self::$cache = null;
        return true;
    }

    /** ['n' => copias, 'bytes' => lo que ocupan en disco, 'tope' => bytes] */
    public static function uso(int $mid): array
    {
        return ['n' => count(self::deLaPersona($mid)), 'bytes' => self::enDisco($mid), 'tope' => self::tope()];
    }

    /** Al desconectar el correo: se va todo lo guardado de esa persona. */
    public static function borrarTodo(int $mid): int
    {
        $n = 0;
        foreach (self::deLaPersona($mid) as $f) { self::borrarFila($f); $n++; }
        $d = __DIR__ . '/../data/correo_copias/' . $mid;
        foreach (glob($d . '/*.bin') ?: [] as $resto) @unlink($resto);
        @rmdir($d);
        self::$cache = null;
        return $n;
    }

    /* ---------------- El cron: descargar lo que llega ---------------- */

    /**
     * Descarga y guarda lo de Recibidos que aún no tiene copia: primero lo
     * que acaba de llegar y, con lo que sobre de la vuelta, lo que ya estaba
     * (de lo más nuevo a lo más viejo). Como mucho $max por vuelta y hasta la
     * hora $hasta. No marca nada como leído ni borra nada del servidor.
     * Devuelve cuántos guardó.
     */
    public static function copiarNuevos(int $mid, int $max = 10, int $hasta = 0): int
    {
        $c = Correo::cuenta($mid);
        if (!$c || ($c['estado'] ?? '') !== 'ok') return 0;
        $imap = Correo::abrir($mid);
        $n = 0;
        try {
            $bandeja = Correo::carpetaDeTipo(Correo::carpetas($imap, $mid), 'inbox') ?? 'INBOX';
            $st = $imap->estado($bandeja);
            $mismo = (int)($c['copia_uidvalidity'] ?? -1) === (int)$st['uidvalidity'];
            $ultimo = $mismo && isset($c['copia_uid']) ? (int)$c['copia_uid'] : null;
            $atras = $mismo && isset($c['copia_atras']) ? (int)$c['copia_atras'] : null;
            $imap->abrir($bandeja);
            $todos = $imap->buscar('');
            sort($todos);
            $tope = $todos ? (int)end($todos) : 0;
            if ($ultimo === null) { $ultimo = $tope; $atras = $tope; }      // primera vez: lo nuevo desde ahora y lo de antes hacia atrás
            $ya = self::enCarpeta($mid, $bandeja);
            $nuevos = array_values(array_filter($todos, fn($u) => $u > $ultimo));
            $viejos = array_reverse(array_values(array_filter($todos, fn($u) => $u <= (int)$atras && !isset($ya[$u]))));
            $cola = array_slice(array_merge($nuevos, $viejos), 0, $max);
            $hechos = [];
            if ($cola) {
                $info = $imap->traer($cola, 'UID FLAGS RFC822.SIZE');
                foreach ($cola as $uid) {
                    if ($hasta > 0 && time() >= $hasta) break;          // lo que falte, en la próxima vuelta
                    if (!isset($info[$uid])) { $hechos[] = $uid; continue; }
                    $flags = array_map('strtolower', array_filter((array)($info[$uid]['FLAGS'] ?? []), 'is_string'));
                    $leido = in_array('\\seen', $flags, true);
                    $tam = (int)($info[$uid]['RFC822.SIZE'] ?? 0);
                    if (!isset($ya[$uid])) {
                        if ($tam > self::TOPE_MENSAJE) {
                            $cab = '';
                            foreach ($imap->traer([$uid], 'UID BODY.PEEK[HEADER]')[$uid] ?? [] as $k => $v) if (str_starts_with($k, 'BODY[')) $cab = (string)$v;
                            self::guardar($mid, '', $bandeja, $uid, $leido, $cab, $tam);
                        } else {
                            self::guardar($mid, $imap->crudo($uid), $bandeja, $uid, $leido);
                        }
                        $n++;
                    }
                    $hechos[] = $uid;
                }
            }
            // Punteros: lo nuevo avanza hasta lo último guardado; lo viejo baja hasta lo último revisado
            $nuevoUlt = $ultimo;
            foreach ($hechos as $u) if ($u > $ultimo && $u > $nuevoUlt) $nuevoUlt = $u;
            $hechosViejos = array_filter($hechos, fn($u) => $u <= (int)$atras);
            $nuevoAtras = $hechosViejos ? min($hechosViejos) - 1 : ($viejos ? (int)$atras : 0);
            Correo::guardarDatos($mid, ['copia_uid' => $nuevoUlt, 'copia_atras' => max(0, $nuevoAtras), 'copia_uidvalidity' => (int)$st['uidvalidity']]);
        } finally {
            $imap->cerrar();
        }
        return $n;
    }
}
