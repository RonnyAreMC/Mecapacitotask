<?php
/**
 * Zoom - integración con la API de Zoom vía Server-to-Server OAuth.
 * Crea reuniones, consulta grabaciones y prueba la conexión, sin
 * dependencias externas. Credenciales en Ajustes (Config 'zoom').
 *
 * Requiere una app "Server-to-Server OAuth" en https://marketplace.zoom.us/
 * con scopes: meeting:write, meeting:read, recording:read (o :admin).
 */
require_once __DIR__ . '/Models.php';

class Zoom
{
    private static function conf(): array
    {
        return array_merge([
            'activo'        => false,
            'account_id'    => '',
            'client_id'     => '',
            'client_secret' => '',
            'zona'          => 'America/Guayaquil',
        ], (array)Config::get('zoom'));
    }

    /** true si está activo y con credenciales completas. */
    public static function listo(): bool
    {
        $c = self::conf();
        return !empty($c['activo']) && $c['account_id'] !== '' && $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    public static function zona(): string
    {
        return self::conf()['zona'] ?: 'America/Guayaquil';
    }

    /** Token de acceso (cacheado ~55 min). Devuelve string o ['error'=>...]. */
    private static function token(): string|array
    {
        $c = self::conf();
        $cacheFile = __DIR__ . '/../data/cache_zoom.json';
        $cache = file_exists($cacheFile) ? (json_decode((string)file_get_contents($cacheFile), true) ?: []) : [];
        if (!empty($cache['token']) && ($cache['exp'] ?? 0) > time() + 60) {
            return $cache['token'];
        }
        $url = 'https://zoom.us/oauth/token?grant_type=account_credentials&account_id=' . urlencode($c['account_id']);
        [$codigo, $cuerpo] = self::http('POST', $url, null, [
            'Authorization: Basic ' . base64_encode($c['client_id'] . ':' . $c['client_secret']),
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        $json = json_decode($cuerpo, true) ?: [];
        if ($codigo !== 200 || empty($json['access_token'])) {
            return ['error' => 'No se pudo autenticar con Zoom: ' . ($json['reason'] ?? $json['error'] ?? ('HTTP ' . $codigo))];
        }
        file_put_contents($cacheFile, json_encode(['token' => $json['access_token'], 'exp' => time() + (int)($json['expires_in'] ?? 3600)]));
        return $json['access_token'];
    }

    /** Llamada autenticada a la API. Devuelve [codigoHttp, arrayJson]. */
    private static function api(string $metodo, string $ruta, ?array $body = null): array
    {
        $token = self::token();
        if (is_array($token)) return [0, $token];
        [$codigo, $cuerpo] = self::http($metodo, 'https://api.zoom.us/v2' . $ruta,
            $body !== null ? json_encode($body) : null,
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        return [$codigo, json_decode($cuerpo, true) ?: []];
    }

    /** HTTP genérico con streams. Devuelve [codigo, cuerpoCrudo]. */
    private static function http(string $metodo, string $url, ?string $body, array $cabeceras): array
    {
        $ctx = stream_context_create(['http' => [
            'method'        => $metodo,
            'header'        => implode("\r\n", $cabeceras),
            'content'       => $body ?? '',
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

    /**
     * Crea una reunión programada. $datos: topic, inicio (Y-m-d H:i), duracion.
     * Devuelve el objeto de la reunión o ['error'=>...].
     */
    public static function crearReunion(array $datos): array
    {
        $inicioIso = str_replace(' ', 'T', $datos['inicio']) . ':00';
        [$codigo, $json] = self::api('POST', '/users/me/meetings', [
            'topic'      => $datos['topic'],
            'type'       => 2,                       // reunión programada
            'start_time' => $inicioIso,
            'duration'   => (int)$datos['duracion'],
            'timezone'   => self::zona(),
            'settings'   => [
                'join_before_host' => true,
                'waiting_room'     => false,
                'auto_recording'   => 'cloud',       // graba en la nube (requiere plan de pago)
                'approval_type'    => 2,
            ],
        ]);
        if ($codigo === 201) return $json;
        return ['error' => 'Zoom rechazó la creación: ' . ($json['message'] ?? ('HTTP ' . $codigo))];
    }

    /**
     * Edita una reunión existente (tema, inicio, duración).
     * Devuelve true o un mensaje de error.
     */
    public static function actualizarReunion(string $zoomId, array $datos): true|string
    {
        $inicioIso = str_replace(' ', 'T', $datos['inicio']) . ':00';
        [$codigo, $json] = self::api('PATCH', '/meetings/' . $zoomId, [
            'topic'      => $datos['topic'],
            'start_time' => $inicioIso,
            'duration'   => (int)$datos['duracion'],
            'timezone'   => self::zona(),
        ]);
        if ($codigo === 204) return true;             // Zoom no devuelve cuerpo al editar
        return 'Zoom rechazó la edición: ' . ($json['message'] ?? ('HTTP ' . $codigo));
    }

    public static function eliminarReunion(string $zoomId): void
    {
        self::api('DELETE', '/meetings/' . $zoomId);
    }

    /**
     * Grabaciones de una reunión.
     * ['estado'=>'ok'|'vacio'|'error', 'archivos'=>[...], 'share_url'=>, 'msg'=>]
     */
    public static function grabaciones(string $zoomId): array
    {
        [$codigo, $json] = self::api('GET', '/meetings/' . $zoomId . '/recordings');
        if ($codigo === 200 && !empty($json['recording_files'])) {
            $archivos = [];
            foreach ($json['recording_files'] as $f) {
                if (($f['status'] ?? 'completed') !== 'completed') continue;
                $archivos[] = [
                    'tipo'     => $f['recording_type'] ?? ($f['file_type'] ?? 'archivo'),
                    'ext'      => strtolower($f['file_type'] ?? ''),
                    'play'     => $f['play_url'] ?? '',
                    'download' => $f['download_url'] ?? '',
                ];
            }
            return ['estado' => 'ok', 'archivos' => $archivos, 'share_url' => $json['share_url'] ?? ''];
        }
        if ($codigo === 404) {
            return ['estado' => 'vacio', 'archivos' => [], 'msg' => 'Aún no hay grabación (aparece cuando Zoom termina de procesarla).'];
        }
        $msg = $json['error']['message'] ?? ($json['message'] ?? ('HTTP ' . $codigo));
        return ['estado' => 'error', 'archivos' => [], 'msg' => 'No se pudo leer la grabación: ' . $msg];
    }

    /** Prueba la conexión creando (y no) — solo pide el token. true|string. */
    public static function probar(): true|string
    {
        $t = self::token();
        if (is_array($t)) return $t['error'];
        [$codigo, $json] = self::api('GET', '/users/me');
        if ($codigo === 200) {
            return true;
        }
        return 'Conectó pero la API respondió ' . $codigo . ': ' . ($json['message'] ?? 'revisa los scopes de la app (meeting:write, recording:read).');
    }
}
