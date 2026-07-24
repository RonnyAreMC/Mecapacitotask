<?php
/**
 * GoogleLogin - "Iniciar sesión con Google" (OAuth 2.0, flujo de código).
 *
 * Solo deja entrar a correos que YA existen como colaboradores del panel:
 * nunca crea usuarios nuevos. Reutiliza las credenciales de Google Cloud
 * de la configuración de correo si no se definen unas propias.
 */
require_once __DIR__ . '/Models.php';

class GoogleLogin
{
    public static function conf(): array
    {
        $g      = (array)(Config::get('google_login') ?? []);
        $correo = (array)(Config::get('correo') ?? []);
        return [
            'activo'        => !empty($g['activo']),
            'client_id'     => trim($g['client_id'] ?? '')     ?: trim($correo['client_id'] ?? ''),
            'client_secret' => trim($g['client_secret'] ?? '') ?: trim($correo['client_secret'] ?? ''),
            'vincular_por_nombre' => !empty($g['vincular_por_nombre']),
            'calendario'    => !empty($g['calendario']),
        ];
    }

    /** Quita tildes, mayusculas y todo lo que no sea letra: "Jaione Cherres" -> "jaionecherres". */
    private static function normalizar(string $texto): string
    {
        $t = mb_strtolower(trim($texto), 'UTF-8');
        $t = strtr($t, [
            'á'=>'a','à'=>'a','ä'=>'a','â'=>'a','ã'=>'a',
            'é'=>'e','è'=>'e','ë'=>'e','ê'=>'e',
            'í'=>'i','ì'=>'i','ï'=>'i','î'=>'i',
            'ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','õ'=>'o',
            'ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u',
            'ñ'=>'n','ç'=>'c',
        ]);
        return (string)preg_replace('/[^a-z0-9]/', '', $t);
    }

    /** Palabras normalizadas de un nombre, ignorando partículas y letras sueltas. */
    private static function palabras(string $texto): array
    {
        $ignorar = ['de', 'del', 'la', 'las', 'los', 'y', 'da', 'do'];
        $out = [];
        foreach (preg_split('/[\s._-]+/', trim($texto)) as $p) {
            $n = self::normalizar($p);
            if (mb_strlen($n) < 2 || in_array($n, $ignorar, true)) continue;
            $out[] = $n;
        }
        return $out;
    }

    /**
     * Colaboradores que se parecen a la cuenta de Google.
     *
     * Coincide si el nombre completo es el mismo, si el usuario de Git calza,
     * o si TODAS las palabras del nombre del colaborador aparecen en el nombre
     * de Google (o en la parte local del correo). Así "Jaione Cherres" entra
     * con jaionecherres@gmail.com y "Kevin" con "Kevin Sánchez".
     */
    public static function coincidenciasPorNombre(array $equipo, string $nombreGoogle, string $correo): array
    {
        $local     = self::normalizar(strtok($correo, '@') ?: '');
        $nombreN   = self::normalizar($nombreGoogle);
        $palabrasG = array_merge(self::palabras($nombreGoogle), self::palabras(strtok($correo, '@') ?: ''));
        if ($nombreN === '' && $local === '') return [];

        $out = [];
        foreach ($equipo as $m) {
            $miembroN = self::normalizar($m['nombre'] ?? '');
            if ($miembroN === '') continue;

            $gitN = self::normalizar($m['git_user'] ?? '');
            $iguales = ($miembroN !== '' && ($miembroN === $nombreN || $miembroN === $local))
                || ($gitN !== '' && ($gitN === $local || $gitN === $nombreN));

            if (!$iguales) {
                $palabrasM = self::palabras($m['nombre'] ?? '');
                // Todas las palabras del colaborador tienen que estar en la
                // cuenta de Google: "Ronny Arellano" no calza con "Ronny Pérez".
                $iguales = $palabrasM !== [] && !array_diff($palabrasM, $palabrasG);
                // Un solo nombre suelto ("Kevin") además debe aparecer completo
                // en la parte local del correo o como primera palabra.
                if ($iguales && count($palabrasM) === 1) {
                    $iguales = str_contains($local, $palabrasM[0])
                        || ($palabrasG !== [] && $palabrasG[0] === $palabrasM[0]);
                }
            }
            if ($iguales) $out[] = $m;
        }
        return $out;
    }

    public static function listo(): bool
    {
        $c = self::conf();
        return $c['activo'] && $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    /** URI de retorno que hay que registrar en Google Cloud Console. */
    public static function redirectUri(): string
    {
        $https   = !empty($_SERVER['HTTPS'])
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $esquema = $https ? 'https' : 'http';
        $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir     = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/login.php'));
        $dir     = rtrim($dir === '.' ? '' : $dir, '/');
        return $esquema . '://' . $host . $dir . '/oauth_google.php';
    }

    /**
     * URL para INICIAR SESIÓN. Pide solo lo básico (openid/email/profile), que
     * no requiere verificación de Google. El permiso de calendario NO va aquí:
     * es un permiso "sensible" que en modo prueba bloquea el acceso a todo el
     * equipo. Se pide aparte y de forma opcional con urlCalendario().
     */
    public static function urlAutorizacion(): string
    {
        $c = self::conf();
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        unset($_SESSION['oauth_calendario']);   // este flujo es solo de acceso
        $params = [
            'client_id'     => $c['client_id'],
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $_SESSION['oauth_state'],
            'prompt'        => 'select_account',
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * URL para CONECTAR EL CALENDARIO (permiso aparte del acceso). El usuario ya
     * está dentro; aquí pide el permiso de escritura y acceso offline para
     * obtener el refresh token. Marca la sesión para que el retorno lo distinga.
     */
    public static function urlCalendario(): string
    {
        $c = self::conf();
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        $_SESSION['oauth_calendario'] = true;
        $params = [
            'client_id'     => $c['client_id'],
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile https://www.googleapis.com/auth/calendar.events',
            'state'         => $_SESSION['oauth_state'],
            'access_type'   => 'offline',
            'prompt'        => 'consent',              // fuerza el refresh token
            'include_granted_scopes' => 'true',        // autorización incremental
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Procesa el retorno de Google. Devuelve ['ok'=>true,'email'=>...]
     * o ['ok'=>false,'error'=>'...'].
     */
    public static function procesar(string $code, string $state): array
    {
        if ($code === '' || !hash_equals((string)($_SESSION['oauth_state'] ?? ''), $state)) {
            return ['ok' => false, 'error' => 'La respuesta de Google no es válida. Intenta de nuevo.'];
        }
        unset($_SESSION['oauth_state']);
        $c = self::conf();

        [$codigo, $cuerpo] = self::http('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'redirect_uri'  => self::redirectUri(),
            'grant_type'    => 'authorization_code',
        ]);
        $tok = json_decode($cuerpo, true) ?: [];
        if ($codigo !== 200 || empty($tok['access_token'])) {
            return ['ok' => false, 'error' => 'Google no autorizó el acceso: ' . ($tok['error_description'] ?? $tok['error'] ?? ('HTTP ' . $codigo))];
        }

        // Datos del usuario autenticado
        $ctx = stream_context_create(['http' => [
            'method'        => 'GET',
            'header'        => 'Authorization: Bearer ' . $tok['access_token'],
            'timeout'       => 15,
            'ignore_errors' => true,
        ]]);
        $info = json_decode((string)@file_get_contents('https://www.googleapis.com/oauth2/v3/userinfo', false, $ctx), true) ?: [];
        if (empty($info['email'])) {
            return ['ok' => false, 'error' => 'No se pudo leer el correo de tu cuenta de Google.'];
        }
        if (isset($info['email_verified']) && !$info['email_verified']) {
            return ['ok' => false, 'error' => 'Tu correo de Google no está verificado.'];
        }
        return [
            'ok'            => true,
            'email'         => strtolower($info['email']),
            'nombre'        => $info['name'] ?? '',
            // Solo llega la primera vez que consiente (o con prompt=consent)
            'refresh_token' => $tok['refresh_token'] ?? '',
        ];
    }

    private static function http(string $url, array $datos): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => 'Content-Type: application/x-www-form-urlencoded',
            'content'       => http_build_query($datos),
            'timeout'       => 20,
            'ignore_errors' => true,
        ]]);
        $cuerpo = @file_get_contents($url, false, $ctx);
        $codigo = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $codigo = (int)$m[1];
        }
        return [$codigo, (string)$cuerpo];
    }
}
