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
    private static function conf(): array
    {
        $g      = (array)(Config::get('google_login') ?? []);
        $correo = (array)(Config::get('correo') ?? []);
        return [
            'activo'        => !empty($g['activo']),
            'client_id'     => trim($g['client_id'] ?? '')     ?: trim($correo['client_id'] ?? ''),
            'client_secret' => trim($g['client_secret'] ?? '') ?: trim($correo['client_secret'] ?? ''),
        ];
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

    /** URL a la que se manda al usuario para que elija su cuenta de Google. */
    public static function urlAutorizacion(): string
    {
        $c = self::conf();
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $c['client_id'],
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile',
            'state'         => $_SESSION['oauth_state'],
            'prompt'        => 'select_account',
        ]);
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
        return ['ok' => true, 'email' => strtolower($info['email']), 'nombre' => $info['name'] ?? ''];
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
