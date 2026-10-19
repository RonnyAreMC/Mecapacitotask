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
 *  - Las claves del panel (VAPID) se crean solas en data/push.json.
 *  - Cada dispositivo que acepta queda en la tabla push_suscripciones.
 *  - Solo se envía a los servicios de avisos conocidos (así nadie puede
 *    registrar una dirección interna para que el servidor la llame).
 *  - Cada persona elige qué le llega: correos nuevos y/o avisos del panel.
 *
 * Se carga con is_file (no desde bootstrap): si al subir por FTP falta, el
 * panel sigue igual y solo no hay avisos nativos.
 */
final class Push
{
    /** Servicios de avisos de los navegadores (se acepta el dominio y sus subdominios). */
    private const SERVICIOS = ['fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com',
        'push.apple.com', 'notify.windows.com'];
    /** Prefijo DER de una clave pública P-256 (SubjectPublicKeyInfo) antes de los 65 bytes del punto. */
    private const DER_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';
    public const TIPOS = ['correo' => 'Correos nuevos de tu buzón InnoTech', 'avisos' => 'Avisos del panel (tareas, reuniones, pedidos…)'];

    private static ?array $claves = null;
    private static array $cola = [];
    private static bool $alCerrar = false;

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

    /** ['publica' => base64url de 65 bytes, 'privada' => PEM] o null si este PHP no puede. */
    private static function claves(): ?array
    {
        if (self::$claves !== null) return self::$claves ?: null;
        $f = __DIR__ . '/../data/push.json';
        $c = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        if (!is_array($c) || empty($c['publica']) || empty($c['privada'])) {
            $c = null;
            $k = self::nuevaClave();
            if ($k && openssl_pkey_export($k, $pem, null, self::opcionesSsl())) {
                $c = ['publica' => self::b64(self::puntoPublico($k)), 'privada' => $pem, 'creado' => date('Y-m-d H:i')];
                if (@file_put_contents($f, json_encode($c, JSON_PRETTY_PRINT)) !== false) @chmod($f, 0600);
                else $c = null;
            }
        }
        self::$claves = $c ?: [];
        return $c ?: null;
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

    /**
     * Guarda el dispositivo de la persona (lo que entrega el navegador al
     * aceptar). Devuelve '' si quedó o el motivo si no.
     */
    public static function suscribir(int $mid, string $json, string $ua): string
    {
        $s = json_decode($json, true);
        $endpoint = (string)($s['endpoint'] ?? '');
        $p256 = self::b64d((string)($s['keys']['p256dh'] ?? ''));
        $auth = self::b64d((string)($s['keys']['auth'] ?? ''));
        if (!self::endpointValido($endpoint)) return 'El navegador entregó una dirección de avisos que no reconocemos.';
        if (strlen($p256) !== 65 || $p256[0] !== "\x04" || strlen($auth) !== 16) return 'El navegador entregó claves inválidas.';
        $datos = [
            'miembro_id' => $mid, 'endpoint' => mb_substr($endpoint, 0, 1000),
            'p256dh' => self::b64($p256), 'auth' => self::b64($auth),
            'equipo' => self::equipo($ua), 'usado' => date('Y-m-d H:i'), 'fallos' => 0,
        ];
        $repo = self::repo();
        foreach ($repo->where('endpoint', $datos['endpoint']) as $previo) {
            $repo->update((int)$previo['id'], $datos);   // el mismo dispositivo (quizá ahora de otra persona)
            return '';
        }
        $repo->insert($datos + ['creado' => date('Y-m-d H:i')]);
        return '';
    }

    public static function quitar(int $mid, int $id): void
    {
        foreach (self::repo()->where('miembro_id', $mid) as $s) {
            if ((int)$s['id'] === $id) self::repo()->delete($id);
        }
    }

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
     * $msg: titulo, texto, url (relativa al panel), tag.
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
                if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
                elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
                foreach (self::$cola as [$m, $x, $t]) {
                    try { self::enviar($m, $x, $t); } catch (\Throwable $e) { error_log('Push: ' . $e->getMessage()); }
                }
                self::$cola = [];
            });
        }
    }

    /** Envía ya. Devuelve cuántos dispositivos lo recibieron. */
    public static function enviar(array $mids, array $msg, string $tipo): int
    {
        if (!self::activo()) return 0;
        $mids = array_values(array_unique(array_filter(array_map('intval', $mids))));
        $carga = json_encode([
            'titulo' => mb_substr((string)($msg['titulo'] ?? 'InnoTech Hub'), 0, 120),
            'texto'  => mb_substr((string)($msg['texto'] ?? ''), 0, 240),
            'url'    => (string)($msg['url'] ?? 'index.php'),
            'tag'    => (string)($msg['tag'] ?? ''),
        ], JSON_UNESCAPED_UNICODE);
        $ok = 0;
        foreach ($mids as $mid) {
            if ($tipo !== 'prueba' && !(self::prefs($mid)[$tipo] ?? false)) continue;
            foreach (self::dispositivos($mid) as $s) {
                $cod = self::mandar($s, (string)$carga);
                if ($cod >= 200 && $cod < 300) {
                    $ok++;
                    self::repo()->update((int)$s['id'], ['usado' => date('Y-m-d H:i'), 'fallos' => 0]);
                } elseif (in_array($cod, [404, 410], true)) {
                    self::repo()->delete((int)$s['id']);      // el navegador ya no lo quiere: se olvida
                } else {
                    $f = (int)($s['fallos'] ?? 0) + 1;
                    $f >= 30 ? self::repo()->delete((int)$s['id']) : self::repo()->update((int)$s['id'], ['fallos' => $f]);
                }
            }
        }
        return $ok;
    }

    /** POST cifrado al servicio de avisos. Devuelve el código HTTP (0 si no hubo respuesta). */
    private static function mandar(array $s, string $carga): int
    {
        $endpoint = (string)$s['endpoint'];
        if (!self::endpointValido($endpoint) && !self::endpointDePrueba($endpoint)) return 410;
        $cuerpo = self::cifrar($carga, self::b64d((string)$s['p256dh']), self::b64d((string)$s['auth']));
        if ($cuerpo === null) return 0;
        $p = parse_url($endpoint);
        $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $cab = [
            'Authorization: vapid t=' . self::jwt($aud) . ', k=' . self::clavePublica(),
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
            curl_exec($ch);
            return (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'header' => implode("\r\n", $cab) . "\r\nContent-Length: " . strlen($cuerpo),
            'content' => $cuerpo, 'timeout' => 8, 'ignore_errors' => true, 'follow_location' => 0,
        ]]);
        @file_get_contents($endpoint, false, $ctx);
        $linea = $http_response_header[0] ?? '';
        return preg_match('/\s(\d{3})\s/', $linea, $m) ? (int)$m[1] : 0;
    }

    /** Solo para las pruebas automáticas: un servicio falso en esta misma máquina. */
    private static function endpointDePrueba(string $url): bool
    {
        return getenv('PANEL_PUSH_PRUEBA') === '1' && str_starts_with($url, 'http://127.0.0.1:');
    }

    /** JWT de VAPID firmado con ES256. */
    private static function jwt(string $aud): string
    {
        $contacto = trim((string)(Config::get('correo')['admin_email'] ?? '')) ?: 'soporte@innotech-solutions.com.ec';
        $h = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $c = self::b64(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . $contacto]));
        $priv = openssl_pkey_get_private((string)self::claves()['privada']);
        openssl_sign($h . '.' . $c, $der, $priv, OPENSSL_ALGO_SHA256);
        return $h . '.' . $c . '.' . self::b64(self::derACrudo((string)$der));
    }

    /** Firma ECDSA de DER (SEQUENCE de dos INTEGER) a r||s de 64 bytes. */
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

    /** Cifra el aviso para un dispositivo (aes128gcm, RFC 8291). null si openssl no pudo. */
    private static function cifrar(string $plano, string $uaPublica, string $secretoAuth): ?string
    {
        $efimera = self::nuevaClave();
        $suya = @openssl_pkey_get_public(self::pemPublica($uaPublica));
        if (!$efimera || !$suya) return null;
        $nuestra = self::puntoPublico($efimera);
        $ecdh = openssl_pkey_derive($suya, $efimera, 32);
        if (!is_string($ecdh) || strlen($ecdh) !== 32) return null;
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $uaPublica . $nuestra, $secretoAuth);
        $sal = random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);
        $tag = '';
        $ct = openssl_encrypt($plano . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ct === false) return null;
        return $sal . pack('N', 4096) . chr(65) . $nuestra . $ct . $tag;
    }
}
