<?php
/**
 * Notificaciones nativas (Web Push): el aviso llega al teléfono o a la
 * computadora —Windows, Mac, Android, iPhone— aunque el panel esté cerrado.
 *
 * Es el estándar abierto de los navegadores (VAPID, RFC 8292, y contenido
 * cifrado aes128gcm, RFC 8291): sin servicios de pago ni librerías; lo hace
 * openssl. El texto va cifrado de punta a punta: el servicio de avisos del
 * navegador (Google, Apple, Mozilla, Microsoft) no puede leerlo.
 *
 *  - Las claves del panel (VAPID) se crean UNA sola vez y no cambian nunca:
 *    viven en data/push.json y, de respaldo, en la base (tabla push_claves).
 *    Si una de las dos se pierde o se pisa, vuelve la otra. Si cambiaran,
 *    todos los dispositivos dejarían de recibir avisos.
 *  - Cada dispositivo que acepta queda en la tabla push_suscripciones y se
 *    queda ahí mientras el navegador lo tenga: solo se borra si la persona lo
 *    quita a propósito o si el servicio de avisos dice que ya no existe
 *    (404/410). Un error pasajero nunca lo borra.
 *  - push_bajas recuerda lo que se quitó a propósito (para no volver a
 *    anotarlo solo) y lo que venció (para que el navegador lo renueve).
 *  - Solo se envía a los servicios de avisos conocidos (así nadie puede
 *    registrar una dirección interna para que el servidor la llame).
 *  - Cada persona elige qué le llega: correos nuevos y/o avisos del panel.
 *
 * Se carga con is_file (no desde bootstrap): si al subir por FTP falta, el
 * panel sigue igual y solo no hay avisos nativos.
 */
final class Push
{
    /**
     * Servicios de avisos de los navegadores (se acepta el dominio y sus subdominios).
     * google.com va por si Chrome entrega una dirección de Google distinta de fcm.googleapis.com
     * (son todos de Google, así que no abre la puerta a direcciones internas).
     */
    private const SERVICIOS = ['fcm.googleapis.com', 'android.googleapis.com', 'google.com', 'push.services.mozilla.com',
        'push.apple.com', 'notify.windows.com'];
    /** Prefijo DER de una clave pública P-256 (SubjectPublicKeyInfo) antes de los 65 bytes del punto. */
    private const DER_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    public const TIPOS = ['correo' => 'Correos nuevos de tu buzón InnoTech', 'avisos' => 'Avisos del panel (tareas, reuniones, pedidos…)'];
    /** Lo que responde suscribir() cuando el servicio de avisos ya dio por vencida esa suscripción (assets/ds.js pide una nueva). */
    public const VENCIDA = 'El servicio de avisos dio por vencida la suscripción de este dispositivo: hay que renovarla.';

    private static ?array $claves = null;
    private static array $cola = [];
    private static bool $alCerrar = false;
    /** JWT ya firmados por servicio (aud => [token, vence]) para no firmar uno por dispositivo. */
    private static array $firmas = [];

    /* ---------------------------------------------------------------- claves */

    public static function activo(): bool
    {
        return function_exists('openssl_pkey_derive') && defined('OPENSSL_KEYTYPE_EC') && function_exists('hash_hkdf')
            && self::claves() !== null;
    }

    /** La clave pública del panel (para que el navegador se suscriba). */
    public static function clavePublica(): string
    {
        return (string)(self::claves()['publica'] ?? '');
    }

    /**
     * ['publica' => base64url de 65 bytes, 'privada' => PEM] o null si este PHP no puede.
     *
     * Las claves no cambian nunca una vez creadas: manda la copia de la base (las
     * primeras que se crearon, las que usan los dispositivos ya anotados) y
     * data/push.json se rehace desde ella si falta, está dañado o lo pisaron (por
     * ejemplo, al subir por FTP un data/ de otra máquina). Solo se crean claves
     * nuevas si no hay ninguna de las dos, y aunque dos peticiones lo intenten a la
     * vez, todas se quedan con la misma (la primera fila de push_claves).
     */
    private static function claves(): ?array
    {
        if (self::$claves !== null) return self::$claves ?: null;
        self::$claves = [];
        $f = __DIR__ . '/../data/push.json';
        $archivo = self::leerClaves($f);
        if ($archivo === false) {
            // Existe pero no se pudo leer (permisos, disco): esta vez no hay avisos, y NO se crean otras
            error_log('Push: no se pudo leer data/push.json; no se envían avisos en esta petición.');
            return null;
        }
        try {
            $copia = self::copiaClaves();
            $c = $copia ?? ($archivo !== null ? self::guardarCopia($archivo) : self::crearClaves());
        } catch (\Throwable $e) {
            // Sin base de datos no hay dispositivos anotados: se usa el archivo si está, sin crear nada
            error_log('Push: ' . $e->getMessage());
            $c = $archivo;
        }
        if ($c === null) return null;
        if ($archivo === null || $archivo['publica'] !== $c['publica'] || $archivo['privada'] !== $c['privada']) {
            if (is_file($f)) {
                // Se guarda aparte lo que había (dañado o de otra máquina) por si alguien lo necesita
                @copy($f, dirname($f) . '/push-anterior-' . date('YmdHis') . '.json');
                error_log('Push: data/push.json ' . ($archivo === null ? 'estaba dañado' : 'no coincidía con las claves originales') . '; se rehízo desde la copia de la base.');
            }
            self::escribirClaves($f, $c);
        }
        return self::$claves = $c;
    }

    /** Lo que hay en data/push.json: las claves, null si no está o está dañado, false si no se pudo leer. */
    private static function leerClaves(string $f): array|null|false
    {
        if (!is_file($f)) return null;
        $txt = @file_get_contents($f);
        if ($txt === false) return false;
        $c = json_decode($txt, true);
        return self::clavesValidas($c) ? ['publica' => $c['publica'], 'privada' => $c['privada'], 'creado' => (string)($c['creado'] ?? '')] : null;
    }

    /** ¿Es un par de claves P-256 completo y que corresponde entre sí? */
    private static function clavesValidas($c): bool
    {
        if (!is_array($c) || !is_string($c['publica'] ?? null) || !is_string($c['privada'] ?? null)) return false;
        $pub = self::b64d($c['publica']);
        if (strlen($pub) !== 65 || $pub[0] !== "\x04") return false;
        $k = @openssl_pkey_get_private($c['privada']);
        return $k && hash_equals($pub, self::puntoPublico($k));
    }

    private static function tablaClaves(): JsonStore
    {
        return new JsonStore('push_claves');
    }

    /** Las claves originales guardadas en la base (la primera fila válida), o null. */
    private static function copiaClaves(): ?array
    {
        foreach (self::tablaClaves()->all() as $fila) {
            if (self::clavesValidas($fila)) return ['publica' => $fila['publica'], 'privada' => $fila['privada'], 'creado' => (string)($fila['creado'] ?? '')];
        }
        return null;
    }

    /**
     * Guarda unas claves en la base y devuelve las que quedaron como originales.
     * Si otra petición guardó otras justo antes, gana la primera fila y la nuestra se borra.
     */
    private static function guardarCopia(array $c): ?array
    {
        $t = self::tablaClaves();
        $fila = $t->insert(['publica' => $c['publica'], 'privada' => $c['privada'], 'creado' => $c['creado'] ?: date('Y-m-d H:i')]);
        $ganadora = null;
        foreach ($t->all() as $r) {
            if (self::clavesValidas($r)) { $ganadora = $r; break; }
        }
        if ($ganadora && (int)$ganadora['id'] !== (int)$fila['id']) $t->delete((int)$fila['id']);
        return $ganadora ? ['publica' => $ganadora['publica'], 'privada' => $ganadora['privada'], 'creado' => (string)($ganadora['creado'] ?? '')] : null;
    }

    /** Claves nuevas (solo la primera vez que el panel las necesita). */
    private static function crearClaves(): ?array
    {
        $k = self::nuevaClave();
        if (!$k || !openssl_pkey_export($k, $pem, null, self::opcionesSsl())) return null;
        return self::guardarCopia(['publica' => self::b64(self::puntoPublico($k)), 'privada' => $pem, 'creado' => date('Y-m-d H:i')]);
    }

    /** Escribe data/push.json de una vez (archivo temporal + renombrar): nadie lo lee a medias. */
    private static function escribirClaves(string $f, array $c): void
    {
        $tmp = dirname($f) . '/push-nuevo-' . bin2hex(random_bytes(6)) . '.json';
        if (@file_put_contents($tmp, json_encode($c, JSON_PRETTY_PRINT)) === false) return;   // carpeta sin permiso: queda la copia de la base
        @chmod($tmp, 0600);
        if (!@rename($tmp, $f)) @unlink($tmp);
    }

    /** Una clave EC P-256 nueva. En Windows openssl pide un archivo de configuración: se le da uno mínimo. */
    private static function nuevaClave()
    {
        $op = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $k = @openssl_pkey_new($op);
        if ($k) return $k;
        $k = @openssl_pkey_new($op + self::opcionesSsl());
        return $k ?: null;
    }

    private static function opcionesSsl(): array
    {
        if (DIRECTORY_SEPARATOR !== '\\') return [];
        $cnf = __DIR__ . '/../data/openssl-min.cnf';
        if (!is_file($cnf)) @file_put_contents($cnf, "[req]\ndistinguished_name = dn\n[dn]\n");
        return is_file($cnf) ? ['config' => $cnf] : [];
    }

    private static function puntoPublico($k): string
    {
        $ec = openssl_pkey_get_details($k)['ec'];
        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function pemPublica(string $punto): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::DER_P256) . $punto), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function b64(string $b): string
    {
        return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
    }

    public static function b64d(string $s): string
    {
        return (string)base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /* ---------------------------------------------------------------- dispositivos */

    private static function repo(): JsonStore
    {
        return new JsonStore('push_suscripciones');
    }

    private static function bajas(): JsonStore
    {
        return new JsonStore('push_bajas');
    }

    /** ¿Esta dirección es de un servicio de avisos de verdad? */
    public static function endpointValido(string $url): bool
    {
        $p = parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['port'])) return false;
        $host = strtolower($p['host']);
        foreach (self::SERVICIOS as $s) {
            if ($host === $s || str_ends_with($host, '.' . $s)) return true;
        }
        return false;
    }

    /** Huella corta de una dirección de avisos (la página la compara con la de su navegador sin ver la dirección). */
    public static function huella(string $endpoint): string
    {
        return substr(hash('sha256', $endpoint), 0, 16);
    }

    /**
     * Guarda el dispositivo de la persona (lo que entrega el navegador al
     * aceptar, o al volver a entrar). Devuelve '' si quedó o el motivo si no.
     * Siempre una sola fila por dispositivo: recargar no lo duplica.
     */
    public static function suscribir(int $mid, string $json, string $ua): string
    {
        $s = json_decode($json, true);
        $endpoint = (string)($s['endpoint'] ?? '');
        $p256 = self::b64d((string)($s['keys']['p256dh'] ?? ''));
        $auth = self::b64d((string)($s['keys']['auth'] ?? ''));
        if (!self::endpointValido($endpoint)) return 'El navegador entregó una dirección de avisos que no reconocemos.';
        if (strlen($p256) !== 65 || $p256[0] !== "\x04" || strlen($auth) !== 16) return 'El navegador entregó claves inválidas.';
        $endpoint = mb_substr($endpoint, 0, 1000);
        $baja = self::baja($endpoint, $mid);
        // La persona lo quitó a propósito: no vuelve a anotarse solo. Al pulsar «Activar»,
        // assets/ds.js pide una suscripción nueva (otra dirección) y esa sí queda.
        if ($baja && ($baja['motivo'] ?? '') === 'quitado') return '';
        if ($baja && ($baja['motivo'] ?? '') === 'muerto') return self::VENCIDA;
        $datos = [
            'miembro_id' => $mid, 'endpoint' => $endpoint,
            'p256dh' => self::b64($p256), 'auth' => self::b64($auth),
            'equipo' => self::equipo($ua), 'visto' => date('Y-m-d H:i'),
            // Solo en iPhone/iPad (y Safari de Mac con «Agregar al Dock») cada app instalada tiene
            // su propia suscripción: assets/ds.js dice cuál es. En los demás va vacío (comparten una).
            'app' => in_array($s['app'] ?? '', ['panel', 'correo'], true) ? $s['app'] : '',
        ];
        $repo = self::repo();
        $previos = $repo->where('endpoint', $endpoint);
        if ($previos) {
            $p = array_shift($previos);
            $cambio = ($p['p256dh'] ?? '') !== $datos['p256dh'] || ($p['auth'] ?? '') !== $datos['auth'] || (int)$p['miembro_id'] !== $mid;
            // El mismo dispositivo (quizá ahora de otra persona). Si cambiaron sus claves, se olvida el último error.
            $repo->update((int)$p['id'], $datos + ($cambio ? ['ultimo' => null, 'fallos' => 0] : []));
            foreach ($previos as $dup) $repo->delete((int)$dup['id']);
            return '';
        }
        $repo->insert($datos + ['creado' => date('Y-m-d H:i'), 'usado' => '', 'fallos' => 0]);
        return '';
    }

    /** «Quitar» en Tus dispositivos: se borra y se recuerda que fue a propósito (no vuelve solo). */
    public static function quitar(int $mid, int $id): void
    {
        foreach (self::repo()->where('miembro_id', $mid) as $s) {
            if ((int)$s['id'] !== $id) continue;
            self::repo()->delete($id);
            self::anotarBaja((string)$s['endpoint'], $mid, 'quitado');
        }
    }

    /** El navegador cambió de dirección (renovó la suscripción): se borra la vieja de esa persona. */
    public static function quitarEndpoint(int $mid, string $endpoint): void
    {
        foreach (self::repo()->where('endpoint', $endpoint) as $s) {
            if ((int)$s['miembro_id'] === $mid) self::repo()->delete((int)$s['id']);
        }
    }

    public static function dispositivos(int $mid): array
    {
        return array_values(self::repo()->where('miembro_id', $mid));
    }

    private static function baja(string $endpoint, int $mid): ?array
    {
        foreach (self::bajas()->where('endpoint', $endpoint) as $b) {
            if ((int)($b['miembro_id'] ?? 0) === $mid) return $b;
        }
        return null;
    }

    /** $motivo: 'quitado' (la persona lo quitó) o 'muerto' (el servicio dijo que ya no existe). */
    private static function anotarBaja(string $endpoint, int $mid, string $motivo): void
    {
        $repo = self::bajas();
        foreach ($repo->where('endpoint', $endpoint) as $b) {
            if ((int)($b['miembro_id'] ?? 0) === $mid) $repo->delete((int)$b['id']);
        }
        $repo->insert(['endpoint' => $endpoint, 'miembro_id' => $mid, 'motivo' => $motivo, 'fecha' => date('Y-m-d H:i')]);
    }

    /**
     * Lo que la página necesita saber de esta persona (UI::barraSuperior lo
     * deja en la barra de arriba): huellas de sus dispositivos que reciben bien,
     * de los que quitó a propósito, qué avisos quiere y si ya tiene iPhone/iPad.
     */
    public static function estadoPagina(int $mid): array
    {
        $mios = [];
        $ios = false;
        foreach (self::dispositivos($mid) as $s) {
            if (!self::conFalla($s)) $mios[] = self::huella((string)$s['endpoint']);
            if (preg_match('/iPhone|iPad/', (string)($s['equipo'] ?? ''))) $ios = true;
        }
        $quitados = [];
        foreach (self::bajas()->where('miembro_id', $mid) as $b) {
            if (($b['motivo'] ?? '') === 'quitado') $quitados[] = self::huella((string)$b['endpoint']);
        }
        return ['mios' => implode(' ', $mios), 'quitados' => implode(' ', $quitados),
            'prefs' => implode(' ', array_keys(array_filter(self::prefs($mid)))), 'ios' => $ios];
    }

    /**
     * Los atributos que assets/ds.js necesita para los avisos nativos, listos para ponerlos en
     * un elemento de la página: ' data-push="…" data-push-mios="…" …' ('' si no hay avisos).
     * La barra de arriba (UI::barraSuperior) los lleva; una página sin barra, como la app del
     * correo, los pone en cualquier elemento (p. ej. <body>) y window.MCPush funciona igual.
     */
    public static function atributosPagina(int $mid): string
    {
        if (!self::activo() || $mid <= 0) return '';
        $h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $pe = self::estadoPagina($mid);
        return ' data-push="' . $h(self::clavePublica()) . '" data-push-mios="' . $h($pe['mios']) . '"'
            . ' data-push-quitados="' . $h($pe['quitados']) . '" data-push-prefs="' . $h($pe['prefs']) . '"'
            . ($pe['ios'] ? ' data-push-ios="1"' : '');
    }

    /** ¿El último envío a este dispositivo falló? (429 no cuenta: solo pidió esperar) */
    private static function conFalla(array $s): bool
    {
        $u = (array)($s['ultimo'] ?? []);
        if (!$u) return (int)($s['fallos'] ?? 0) > 0;     // anotado por la versión anterior, que solo contaba las fallas
        $cod = (int)($u['codigo'] ?? 0);
        return !($cod >= 200 && $cod < 300) && $cod !== 429;
    }

    /**
     * Cómo le fue al último aviso, para Tus dispositivos:
     * ['tono' => 'ok'|'nuevo'|'espera'|'error', 'texto' => …, 'cuando' => fecha o ''].
     */
    public static function estado(array $s): array
    {
        $u = (array)($s['ultimo'] ?? []);
        $servicio = self::servicio((string)$s['endpoint']);
        if (!$u) {
            $f = (int)($s['fallos'] ?? 0);
            return $f
                ? ['tono' => 'error', 'texto' => ($f === 1 ? 'El último aviso no le llegó' : 'Los últimos ' . $f . ' avisos no le llegaron') . '. Pulsa «Enviar un aviso de prueba» para ver el motivo.', 'cuando' => '']
                : ['tono' => 'nuevo', 'texto' => 'Activado. Pulsa «Enviar un aviso de prueba» para comprobar que le llega.', 'cuando' => ''];
        }
        $cod = (int)($u['codigo'] ?? 0);
        $cuando = (string)($u['cuando'] ?? '');
        if ($cod >= 200 && $cod < 300) {
            return ['tono' => 'ok', 'texto' => 'El servicio de avisos de ' . $servicio . ' recibió el último aviso y se lo entrega a este dispositivo.', 'cuando' => $cuando];
        }
        if ($cod === 429) return ['tono' => 'espera', 'texto' => 'El servicio de avisos de ' . $servicio . ' pidió esperar un poco (muchos avisos seguidos). Se reintenta en el próximo.', 'cuando' => $cuando];
        return ['tono' => 'error', 'texto' => self::motivo($cod, (string)($u['error'] ?? ''), $servicio), 'cuando' => $cuando];
    }

    private static function servicio(string $endpoint): string
    {
        $h = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
        return str_contains($h, 'google') ? 'Google' : (str_contains($h, 'mozilla') ? 'Mozilla' : (str_contains($h, 'apple') ? 'Apple'
            : (str_contains($h, 'windows') ? 'Microsoft' : 'el navegador')));
    }

    /** El motivo de un rechazo en palabras simples (más lo que respondió el servicio, recortado). */
    private static function motivo(int $cod, string $resp, string $servicio): string
    {
        $t = match (true) {
            $cod === 0 => 'El servidor del panel no pudo conectarse con el servicio de avisos de ' . $servicio . ' (red o cortafuegos del hosting).',
            $cod === 401 || $cod === 403 => 'El servicio de avisos de ' . $servicio . ' no aceptó la firma del panel (' . $cod . '). Se arregla solo al volver a entrar al panel desde ese dispositivo.',
            $cod === 413 => 'El aviso era demasiado largo para el servicio de avisos (413).',
            $cod >= 500 => 'El servicio de avisos de ' . $servicio . ' tuvo un problema (' . $cod . '). Se reintenta en el próximo aviso.',
            default => 'El servicio de avisos de ' . $servicio . ' rechazó el aviso (' . $cod . ').',
        };
        return $t . ($resp !== '' ? ' Respuesta: «' . $resp . '»' : '');
    }

    /** "Chrome en Windows", "Safari en iPhone"… */
    public static function equipo(string $ua): string
    {
        $nav = str_contains($ua, 'Edg/') ? 'Edge' : (str_contains($ua, 'OPR/') ? 'Opera' : (str_contains($ua, 'Firefox/') ? 'Firefox'
            : (str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') ? 'Chrome' : (str_contains($ua, 'Safari/') ? 'Safari' : 'Navegador'))));
        $so = str_contains($ua, 'iPhone') ? 'iPhone' : (str_contains($ua, 'iPad') ? 'iPad' : (str_contains($ua, 'Android') ? 'Android'
            : (str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Mac OS X') ? 'Mac' : (str_contains($ua, 'Linux') ? 'Linux' : '')))));
        return $nav . ($so !== '' ? ' en ' . $so : '');
    }

    /* ---------------------------------------------------------------- preferencias */

    /** ['correo' => bool, 'avisos' => bool] (por defecto, todo). */
    public static function prefs(int $mid): array
    {
        $m = (new MiembroRepo())->buscar($mid);
        $p = (array)($m['push_pref'] ?? []);
        return ['correo' => (bool)($p['correo'] ?? true), 'avisos' => (bool)($p['avisos'] ?? true)];
    }

    public static function guardarPrefs(int $mid, array $p): void
    {
        (new MiembroRepo())->actualizar($mid, ['push_pref' => ['correo' => !empty($p['correo']), 'avisos' => !empty($p['avisos'])]]);
    }

    /** Personas con algún dispositivo que quiere este tipo de aviso. */
    public static function interesados(string $tipo): array
    {
        $ids = [];
        foreach (self::repo()->all() as $s) $ids[(int)$s['miembro_id']] = true;
        return array_values(array_filter(array_keys($ids), fn($m) => self::prefs($m)[$tipo] ?? false));
    }

    /* ---------------------------------------------------------------- enviar */

    /**
     * Encola un aviso y lo manda al terminar la petición (la persona que hizo
     * la acción no espera). Desde la terminal (cron) sale de inmediato.
     * $msg: titulo, texto, url (relativa al panel), tag y n (cuántos sin leer: el número del ícono).
     */
    public static function avisar(array $mids, array $msg, string $tipo): void
    {
        if (!self::activo()) return;
        if (PHP_SAPI === 'cli') {
            self::enviar($mids, $msg, $tipo);
            return;
        }
        self::$cola[] = [$mids, $msg, $tipo];
        if (!self::$alCerrar) {
            self::$alCerrar = true;
            register_shutdown_function(function () {
                // Primero se suelta la sesión: la página siguiente (la de la redirección) no espera a que salgan los avisos
                if (session_status() === PHP_SESSION_ACTIVE) @session_write_close();
                @ignore_user_abort(true);
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
                foreach (self::$cola as [$m, $x, $t]) {
                    try { self::enviar($m, $x, $t); } catch (\Throwable $e) { error_log('Push: ' . $e->getMessage()); }
                }
                self::$cola = [];
            });
        }
    }

    /** El contenido del aviso (JSON). $corto: versión recortada para cuando el servicio dice 413. */
    private static function carga(array $msg, bool $corto = false): string
    {
        $d = [
            'titulo' => mb_substr((string)($msg['titulo'] ?? 'InnoTech Hub'), 0, $corto ? 60 : 120),
            'texto'  => mb_substr((string)($msg['texto'] ?? ''), 0, $corto ? 80 : 240),
            'url'    => (string)($msg['url'] ?? 'index.php'),
            'tag'    => (string)($msg['tag'] ?? ''),
        ];
        if (isset($msg['n'])) $d['n'] = max(0, (int)$msg['n']);
        return (string)json_encode($d, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Envía ya. Devuelve cuántos dispositivos lo recibieron.
     * Por cada dispositivo anota cómo le fue (se ve en Tus dispositivos):
     *  - 2xx: recibido.
     *  - 404/410: el servicio dice que esa suscripción ya no existe: se borra
     *    (el navegador pide una nueva sola la próxima vez que se abre el panel).
     *  - 413: demasiado largo: se reintenta una vez más corto.
     *  - 429: el servicio pidió esperar: no se insiste con ese servicio en esta vuelta.
     *  - cualquier otro: se anota el motivo y se reintenta en el próximo aviso (nunca se borra).
     */
    public static function enviar(array $mids, array $msg, string $tipo): int
    {
        if (!self::activo()) return 0;
        $mids = array_values(array_unique(array_filter(array_map('intval', $mids))));
        $carga = self::carga($msg);
        $ok = 0;
        $esperar = [];
        foreach ($mids as $mid) {
            if ($tipo !== 'prueba' && !(self::prefs($mid)[$tipo] ?? false)) continue;
            $vistos = [];
            $todos = self::dispositivos($mid);
            // iPhone con las dos apps instaladas (cada una con su suscripción): el correo va a la app
            // del correo y los avisos a la del panel, para que no lleguen dos veces
            $conAppCorreo = [];
            foreach ($todos as $s) if (($s['app'] ?? '') === 'correo') $conAppCorreo[(string)$s['equipo']] = true;
            foreach ($todos as $s) {
                $endpoint = (string)$s['endpoint'];
                if (isset($vistos[$endpoint])) continue;              // una sola vez por dispositivo
                $vistos[$endpoint] = true;
                $app = (string)($s['app'] ?? '');
                if ($tipo === 'avisos' && $app === 'correo') continue;
                if ($tipo === 'correo' && $app === 'panel' && isset($conAppCorreo[(string)$s['equipo']])) continue;
                $id = (int)$s['id'];
                $host = strtolower((string)parse_url($endpoint, PHP_URL_HOST));
                $ahora = date('Y-m-d H:i');
                if (isset($esperar[$host])) {
                    self::repo()->update($id, ['ultimo' => ['codigo' => 429, 'cuando' => $ahora, 'error' => '']]);
                    continue;
                }
                [$cod, $resp] = self::mandar($s, $carga);
                if ($cod === 413) [$cod, $resp] = self::mandar($s, self::carga($msg, true));
                if ($cod >= 200 && $cod < 300) {
                    $ok++;
                    self::repo()->update($id, ['usado' => $ahora, 'fallos' => 0, 'ultimo' => ['codigo' => $cod, 'cuando' => $ahora, 'error' => '']]);
                } elseif ($cod === 404 || $cod === 410) {
                    self::repo()->delete($id);
                    self::anotarBaja($endpoint, $mid, 'muerto');
                } elseif ($cod === 429) {
                    $esperar[$host] = true;
                    self::repo()->update($id, ['ultimo' => ['codigo' => 429, 'cuando' => $ahora, 'error' => $resp]]);
                } else {
                    self::repo()->update($id, ['fallos' => (int)($s['fallos'] ?? 0) + 1, 'ultimo' => ['codigo' => $cod, 'cuando' => $ahora, 'error' => $resp]]);
                    error_log('Push: ' . self::servicio($endpoint) . ' respondió ' . $cod . ($resp !== '' ? ' · ' . $resp : ''));
                }
            }
        }
        return $ok;
    }

    /** POST cifrado al servicio de avisos. Devuelve [código HTTP (0 si no hubo respuesta), lo que respondió]. */
    private static function mandar(array $s, string $carga): array
    {
        $endpoint = (string)$s['endpoint'];
        if (!self::endpointValido($endpoint) && !self::endpointDePrueba($endpoint)) return [410, 'Dirección de avisos no reconocida'];
        $cuerpo = self::cifrar($carga, self::b64d((string)$s['p256dh']), self::b64d((string)$s['auth']));
        if ($cuerpo === null) return [0, 'No se pudo cifrar el aviso para este dispositivo (openssl).'];
        $p = parse_url($endpoint);
        $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $jwt = self::jwt($aud);
        if ($jwt === '') return [0, 'No se pudo firmar el aviso con la clave del panel (openssl).'];
        $cab = [
            'Authorization: vapid t=' . $jwt . ', k=' . self::clavePublica(),
            'Content-Encoding: aes128gcm',
            'Content-Type: application/octet-stream',
            'TTL: 86400',
            'Urgency: high',
        ];
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $cuerpo, CURLOPT_HTTPHEADER => $cab,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $resp = curl_exec($ch);
            $cod = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            if ($resp === false) $resp = curl_error($ch);
            return [$cod, self::recortar((string)$resp)];
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $cab) . "\r\nContent-Length: " . strlen($cuerpo),
            'content' => $cuerpo, 'timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        $resp = @file_get_contents($endpoint, false, $ctx);
        $linea = $http_response_header[0] ?? '';
        return [preg_match('/\s(\d{3})\s/', $linea, $m) ? (int)$m[1] : 0, self::recortar((string)$resp)];
    }

    /** Lo que respondió el servicio, corto y en una línea (para mostrarlo y para el registro de errores). */
    private static function recortar(string $t): string
    {
        $t = trim((string)preg_replace('/\s+/u', ' ', strip_tags($t)));
        if ($t !== '' && !mb_check_encoding($t, 'UTF-8')) $t = '(respuesta ilegible)';
        return mb_substr($t, 0, 240);
    }

    /** Solo para las pruebas automáticas: un servicio falso en esta misma máquina. */
    private static function endpointDePrueba(string $url): bool
    {
        return getenv('PANEL_PUSH_PRUEBA') === '1' && str_starts_with($url, 'http://127.0.0.1:');
    }

    /** JWT de VAPID firmado con ES256 (válido 12 h; se reusa mientras le quede más de 1 h). '' si openssl no pudo. */
    private static function jwt(string $aud): string
    {
        $ahora = time();
        if (isset(self::$firmas[$aud]) && self::$firmas[$aud][1] > $ahora + 3600) return self::$firmas[$aud][0];
        $contacto = trim((string)(Config::get('correo')['admin_email'] ?? ''));
        // Apple y Mozilla exigen un contacto válido en «sub»
        if (!filter_var($contacto, FILTER_VALIDATE_EMAIL)) $contacto = 'soporte@innotech-solutions.com.ec';
        $exp = $ahora + 12 * 3600;
        $h = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $c = self::b64(json_encode(['aud' => $aud, 'exp' => $exp, 'sub' => 'mailto:' . $contacto]));
        $priv = @openssl_pkey_get_private((string)(self::claves()['privada'] ?? ''));
        if (!$priv || !openssl_sign($h . '.' . $c, $der, $priv, OPENSSL_ALGO_SHA256)) return '';
        $t = $h . '.' . $c . '.' . self::b64(self::derACrudo((string)$der));
        self::$firmas[$aud] = [$t, $exp];
        return $t;
    }

    /** Firma ECDSA de DER (SEQUENCE de dos INTEGER) a r||s de 64 bytes (lo que pide JWT ES256). */
    private static function derACrudo(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) $pos = 2 + (ord($der[1]) & 0x7f);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $pos++;                                   // 0x02 (INTEGER)
            $len = ord($der[$pos++]);
            $v = ltrim(substr($der, $pos, $len), "\0");
            $pos += $len;
            $out .= str_pad($v, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /**
     * Cifra el aviso para un dispositivo (aes128gcm, RFC 8291). null si openssl no pudo.
     * $efimera y $sal solo se pasan en la prueba con el ejemplo del RFC; en el uso real
     * salen nuevas en cada aviso.
     */
    private static function cifrar(string $plano, string $uaPublica, string $secretoAuth, $efimera = null, ?string $sal = null): ?string
    {
        $efimera = $efimera ?: self::nuevaClave();
        $suya = @openssl_pkey_get_public(self::pemPublica($uaPublica));
        if (!$efimera || !$suya) return null;
        $nuestra = self::puntoPublico($efimera);
        $ecdh = openssl_pkey_derive($suya, $efimera);   // 32 bytes en P-256 (el largo ya no se pasa: PHP 8.5 lo marca obsoleto)
        if (!is_string($ecdh) || strlen($ecdh) !== 32) return null;
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublica . $nuestra, $secretoAuth);
        $sal = $sal ?? random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);
        $tag = '';
        $ct = openssl_encrypt($plano . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ct === false) return null;
        return $sal . pack('N', 4096) . chr(65) . $nuestra . $ct . $tag;
    }
}
