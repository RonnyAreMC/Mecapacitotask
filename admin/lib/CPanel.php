<?php
/**
 * CPanel: las cuentas de correo del cPanel, manejadas desde el panel (solo el
 * administrador, en buzones.php): crear, cambiar la contraseña, el espacio,
 * suspender o reactivar, borrar y los reenvíos.
 *
 * Usa la API oficial de cPanel (UAPI) por HTTPS con un token de API:
 *   https://<servidor>:2083/execute/<Módulo>/<función>
 *   Authorization: cpanel <usuario>:<token>
 * El token se crea en cPanel → Seguridad → Administrar tokens de API y se guarda
 * cifrado (data/cpanel.secreto). Las contraseñas de los correos nunca se
 * guardan: se mandan a cPanel y se muestran una sola vez.
 * Cada cambio queda en la bitácora «buzones_bitacora» (quién, qué y cuándo).
 *
 * Se carga con is_file (no desde bootstrap): si falta, el resto sigue igual.
 */
final class CPanel
{
    /** Opciones de espacio para un buzón, en MB (0 = sin límite). */
    public const CUOTAS = [1024 => '1 GB', 2048 => '2 GB', 5120 => '5 GB', 10240 => '10 GB', 20480 => '20 GB', 0 => 'Sin límite'];

    public static string $ultimoError = '';

    /* ---------------- Conexión ---------------- */

    public static function conf(): array
    {
        $c = (array)(Config::get('cpanel') ?? []);
        $porDefecto = '';
        if (class_exists('Correo')) $porDefecto = (string)(Correo::conf()['imap_host'] ?? '');
        return [
            'host'          => trim((string)($c['host'] ?? '')) ?: $porDefecto,
            'puerto'        => (int)($c['puerto'] ?? 0) ?: 2083,
            'usuario'       => trim((string)($c['usuario'] ?? '')),
            'token'         => (string)($c['token'] ?? ''),
            'verificar_ssl' => !array_key_exists('verificar_ssl', $c) || !empty($c['verificar_ssl']),
        ];
    }

    public static function listo(): bool
    {
        $c = self::conf();
        return function_exists('curl_init') && $c['host'] !== '' && $c['usuario'] !== '' && $c['token'] !== '';
    }

    /** Guarda la conexión. El token solo se cambia si viene uno nuevo. */
    public static function guardar(array $d): void
    {
        $actual = (array)(Config::get('cpanel') ?? []);
        $host = strtolower(trim((string)($d['host'] ?? '')));
        $host = (string)preg_replace('#^https?://|[:/].*$#', '', $host);
        $nuevo = [
            'host'          => $host,
            'puerto'        => max(1, min(65535, (int)($d['puerto'] ?? 2083) ?: 2083)),
            'usuario'       => mb_substr(trim((string)($d['usuario'] ?? '')), 0, 64),
            'verificar_ssl' => !empty($d['verificar_ssl']),
            'token'         => (string)($actual['token'] ?? ''),
        ];
        $token = trim((string)($d['token'] ?? ''));
        if ($token !== '') $nuevo['token'] = self::cifrar($token);
        Config::guardar(['cpanel' => $nuevo]);
    }

    private static function secreto(): string
    {
        $f = __DIR__ . '/../data/cpanel.secreto';
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

    private static function descifrar(string $guardado): string
    {
        if (!str_starts_with($guardado, 'v1:')) return '';
        $b = (string)base64_decode(substr($guardado, 3));
        if (strlen($b) < 29) return '';
        $r = openssl_decrypt(substr($b, 28), 'aes-256-gcm', self::secreto(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
        return $r === false ? '' : $r;
    }

    /**
     * Llama a la UAPI. Devuelve ['ok' => bool, 'data' => mixed, 'error' => string].
     * Lo que lleva contraseñas va por POST (nunca en la dirección).
     */
    public static function llamar(string $modulo, string $funcion, array $params = [], bool $post = false): array
    {
        self::$ultimoError = '';
        $c = self::conf();
        if (!self::listo()) return self::falla('Falta la conexión con cPanel: el servidor, el usuario y el token de API.');
        $url = 'https://' . $c['host'] . ':' . $c['puerto'] . '/execute/' . rawurlencode($modulo) . '/' . rawurlencode($funcion);
        $cuerpo = http_build_query($params);
        if (!$post && $cuerpo !== '') $url .= '?' . $cuerpo;
        [$codigo, $resp, $errCurl] = self::http($url, $post ? $cuerpo : null, 'Authorization: cpanel ' . $c['usuario'] . ':' . self::descifrar($c['token']), $c['verificar_ssl']);
        if ($codigo === 0) {
            return self::falla('No se pudo conectar con ' . $c['host'] . ':' . $c['puerto'] . ($errCurl !== '' ? ' (' . $errCurl . ')' : '') . '.'
                . (stripos($errCurl, 'certificate') !== false || stripos($errCurl, 'SSL') !== false ? ' Si el certificado del servidor no es para ese nombre, prueba con el nombre del servidor de cPanel o apaga «Verificar el certificado».' : ''));
        }
        $j = json_decode($resp, true);
        if ($codigo === 401 || $codigo === 403) return self::falla('cPanel rechazó el acceso (' . $codigo . '): revisa el usuario y el token de API (que no esté vencido ni restringido sin «Correo»).');
        if (!is_array($j)) return self::falla('cPanel respondió algo inesperado (HTTP ' . $codigo . ').');
        if ((int)($j['status'] ?? 0) !== 1) {
            $errs = array_values(array_filter(array_map('strval', (array)($j['errors'] ?? []))));
            return self::falla($errs ? self::errorEnEspanol(implode(' ', $errs)) : 'cPanel no pudo hacerlo.');
        }
        return ['ok' => true, 'data' => $j['data'] ?? null, 'error' => ''];
    }

    private static function falla(string $msg): array
    {
        self::$ultimoError = $msg;
        return ['ok' => false, 'data' => null, 'error' => $msg];
    }

    /** [código, cuerpo, error de curl]. */
    private static function http(string $url, ?string $post, string $auth, bool $verificar): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [$auth, 'Accept: application/json'],
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => $verificar,
            CURLOPT_SSL_VERIFYHOST => $verificar ? 2 : 0,
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $resp = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = $resp === false ? curl_error($ch) : '';
        curl_close($ch);
        return [$resp === false ? 0 : $codigo, (string)$resp, $err];
    }

    /** Los mensajes de cPanel que más salen, en cristiano. */
    private static function errorEnEspanol(string $e): string
    {
        $mapa = [
            '/password.*strength|strength.*password|contraseña.*seguridad/i' => 'La contraseña es muy débil para cPanel: usa una más larga, con mayúsculas, números y símbolos (el botón «Generar» hace una segura).',
            '/already exists/i'               => 'Esa cuenta ya existe.',
            '/does not exist|no such/i'       => 'Esa cuenta no existe en cPanel.',
            '/quota.*exceed|exceed.*quota|disk space/i' => 'No hay espacio suficiente en la cuenta de hosting para ese tamaño de buzón.',
            '/not.*own|do not own|domain/i'   => 'Ese dominio no es de esta cuenta de cPanel.',
        ];
        foreach ($mapa as $re => $txt) if (preg_match($re, $e)) return $txt . ' (cPanel: ' . mb_strimwidth($e, 0, 160, '…') . ')';
        return 'cPanel: ' . mb_strimwidth($e, 0, 300, '…');
    }

    /* ---------------- Lo que se puede hacer ---------------- */

    public static function probar(): array
    {
        $r = self::llamar('Email', 'list_mail_domains');
        if (!$r['ok']) return $r;
        $doms = array_values(array_filter(array_map(fn($d) => (string)($d['domain'] ?? ''), (array)$r['data'])));
        return ['ok' => true, 'data' => $doms, 'error' => '', 'msg' => 'Conectado. Dominios de correo: ' . ($doms ? implode(', ', $doms) : 'ninguno') . '.'];
    }

    /** Dominios de correo de la cuenta. */
    public static function dominios(): array
    {
        $r = self::llamar('Email', 'list_mail_domains');
        return $r['ok'] ? array_values(array_filter(array_map(fn($d) => (string)($d['domain'] ?? ''), (array)$r['data']))) : [];
    }

    /**
     * Las cuentas con su espacio: [['email','usuario','dominio','usado' (bytes),
     * 'cuota' (bytes; 0 = sin límite),'pct','login_suspendido','entrada_suspendida']].
     */
    public static function cuentas(): array
    {
        $r = self::llamar('Email', 'list_pops_with_disk', ['infinityquota' => 0]);
        if (!$r['ok']) return $r;
        $out = [];
        foreach ((array)$r['data'] as $a) {
            $email = strtolower((string)($a['email'] ?? ($a['login'] ?? '')));
            if ($email === '' || !str_contains($email, '@')) continue;   // la cuenta principal del cPanel no es un buzón
            $usado = isset($a['_diskused']) ? (int)$a['_diskused'] : (int)round((float)($a['diskused'] ?? 0) * 1048576);
            $cuotaMb = (string)($a['diskquota'] ?? '');
            $cuota = isset($a['_diskquota']) ? (int)$a['_diskquota'] : (is_numeric($cuotaMb) ? (int)round((float)$cuotaMb * 1048576) : 0);
            $out[] = [
                'email' => $email, 'usuario' => strtok($email, '@'), 'dominio' => substr(strrchr($email, '@'), 1),
                'usado' => $usado, 'cuota' => $cuota, 'pct' => $cuota > 0 ? (int)min(100, round($usado * 100 / $cuota)) : null,
                'login_suspendido' => !empty($a['suspended_login']), 'entrada_suspendida' => !empty($a['suspended_incoming']),
            ];
        }
        usort($out, fn($x, $y) => strcmp($x['email'], $y['email']));
        return ['ok' => true, 'data' => $out, 'error' => ''];
    }

    public static function crear(string $usuario, string $dominio, string $clave, int $cuotaMb): array
    {
        return self::llamar('Email', 'add_pop', ['email' => $usuario, 'domain' => $dominio, 'password' => $clave,
            'quota' => max(0, $cuotaMb), 'send_welcome_email' => 0], true);
    }

    public static function clave(string $email, string $clave): array
    {
        [$u, $d] = self::partes($email);
        return self::llamar('Email', 'passwd_pop', ['email' => $u, 'domain' => $d, 'password' => $clave], true);
    }

    public static function cuota(string $email, int $cuotaMb): array
    {
        [$u, $d] = self::partes($email);
        return self::llamar('Email', 'edit_pop_quota', ['email' => $u, 'domain' => $d, 'quota' => max(0, $cuotaMb)], true);
    }

    /** $que: login (no puede entrar) o incoming (no recibe). */
    public static function suspender(string $email, string $que, bool $suspender): array
    {
        $f = ($suspender ? 'suspend_' : 'unsuspend_') . ($que === 'incoming' ? 'incoming' : 'login');
        return self::llamar('Email', $f, ['email' => $email], true);
    }

    public static function borrar(string $email): array
    {
        [$u, $d] = self::partes($email);
        return self::llamar('Email', 'delete_pop', ['email' => $u, 'domain' => $d], true);
    }

    /** Reenvíos de un dominio: [['de','a']]. */
    public static function reenvios(string $dominio): array
    {
        $r = self::llamar('Email', 'list_forwarders', ['domain' => $dominio]);
        if (!$r['ok']) return $r;
        $out = [];
        foreach ((array)$r['data'] as $f) {
            $de = strtolower((string)($f['dest'] ?? ''));
            $a = (string)($f['forward'] ?? '');
            if ($de !== '' && $a !== '') $out[] = ['de' => $de, 'a' => $a];
        }
        return ['ok' => true, 'data' => $out, 'error' => ''];
    }

    public static function crearReenvio(string $de, string $a): array
    {
        [, $d] = self::partes($de);
        return self::llamar('Email', 'add_forwarder', ['domain' => $d, 'email' => $de, 'fwdopt' => 'fwd', 'fwdemail' => $a], true);
    }

    public static function borrarReenvio(string $de, string $a): array
    {
        return self::llamar('Email', 'delete_forwarder', ['address' => $de, 'forwarder' => $a], true);
    }

    /** Espacio de la cuenta de hosting (lo que comparten todos los buzones): [usado MB, límite MB (0 = sin límite)]. */
    public static function espacioHosting(): ?array
    {
        $r = self::llamar('Quota', 'get_quota_info');
        if (!$r['ok'] || !is_array($r['data'])) return null;
        return [(float)($r['data']['megabytes_used'] ?? 0), (float)($r['data']['megabyte_limit'] ?? 0)];
    }

    /* ---------------- Ayudas ---------------- */

    public static function partes(string $email): array
    {
        $email = strtolower(trim($email));
        $at = strrpos($email, '@');
        return $at === false ? [$email, ''] : [substr($email, 0, $at), substr($email, $at + 1)];
    }

    public static function emailValido(string $email): bool
    {
        return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /** Parte local válida para una cuenta nueva: letras, números, punto, guion y guion bajo. */
    public static function usuarioValido(string $u): bool
    {
        return (bool)preg_match('/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/', $u) && !str_contains($u, '..');
    }

    /** Una contraseña segura (sin letras que se confunden: 0/O, 1/l/I). */
    public static function claveSegura(int $largo = 16): string
    {
        $grupos = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!#$%*+-=?@_'];
        $c = [];
        foreach ($grupos as $g) $c[] = $g[random_int(0, strlen($g) - 1)];
        $todo = implode('', $grupos);
        while (count($c) < $largo) $c[] = $todo[random_int(0, strlen($todo) - 1)];
        for ($i = count($c) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$c[$i], $c[$j]] = [$c[$j], $c[$i]]; }
        return implode('', $c);
    }

    /** Deja el cambio en la bitácora (nunca la contraseña). */
    public static function anotar(string $accion, string $email, string $detalle = ''): void
    {
        (new JsonStore('buzones_bitacora'))->insert(['fecha' => date('Y-m-d H:i'), 'quien' => (int)(Auth::usuario()['id'] ?? 0),
            'accion' => $accion, 'email' => $email, 'detalle' => mb_substr($detalle, 0, 300)]);
    }

    public static function bitacora(int $max = 40): array
    {
        $l = (new JsonStore('buzones_bitacora'))->all();
        usort($l, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        return array_slice($l, 0, $max);
    }

    public static function tamano(int $bytes): string
    {
        if ($bytes >= 1073741824) return round($bytes / 1073741824, $bytes >= 10737418240 ? 0 : 1) . ' GB';
        if ($bytes >= 1048576) return round($bytes / 1048576) . ' MB';
        return max(0, round($bytes / 1024)) . ' KB';
    }
}
