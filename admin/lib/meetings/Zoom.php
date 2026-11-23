<?php
/**
 * Zoom - integración con la API de Zoom vía Server-to-Server OAuth.
 * Crea reuniones, consulta grabaciones y prueba la conexión, sin
 * dependencias externas. Credenciales en Ajustes (Config 'zoom').
 *
 * Requiere una app "Server-to-Server OAuth" en https://marketplace.zoom.us/
 * con scopes: meeting:write, meeting:read, recording:read (o :admin).
 */
require_once __DIR__ . '/../core/Models.php';

class Zoom
{
    /** Último motivo de fallo al quitar el código de la grabación (diagnóstico). */
    public static ?string $ultimoError = null;

    /**
     * Varias cuentas (licencias) de Zoom. Una licencia solo da UNA reunión a la
     * vez: con dos, caben dos reuniones a la misma hora. Cada cuenta es:
     *  - otra licencia de la MISMA cuenta de Zoom: el correo de ese usuario
     *    (las reuniones se crean a su nombre con la app de siempre), o
     *  - una cuenta de Zoom APARTE: su correo y su propia app Server-to-Server
     *    (Account ID, Client ID y Client Secret).
     * La principal es la de siempre (Ajustes → Conexión con Zoom); las demás van
     * en Config zoom.cuentas. Cada reunión guarda la suya en 'zoom_cuenta'.
     */
    public const PRINCIPAL = 'principal';

    /** La cuenta con la que se habla ahora (usar() / usarDe()). */
    private static string $actual = self::PRINCIPAL;

    /** Colores para distinguir las cuentas en el calendario de horas. */
    public const COLORES = ['#2563EB', '#7C3AED', '#0D9488', '#EA580C', '#DB2777', '#4D7C0F'];

    private static function confBase(): array
    {
        return array_merge([
            'activo'        => false,
            'account_id'    => '',
            'client_id'     => '',
            'client_secret' => '',
            'zona'          => 'America/Guayaquil',
            'nombre'        => '',
            'usuario'       => '',
            'cuentas'       => [],
        ], (array)Config::get('zoom'));
    }

    /**
     * Todas las cuentas, la principal primero: id => [id, nombre, usuario (correo
     * del anfitrión; '' = el dueño de la app), propias (bool), account_id,
     * client_id, client_secret, color]. Las apagadas no salen.
     */
    public static function cuentas(): array
    {
        $b = self::confBase();
        $out = [self::PRINCIPAL => [
            'id' => self::PRINCIPAL, 'nombre' => trim((string)$b['nombre']) ?: 'Zoom 1', 'usuario' => trim((string)$b['usuario']),
            'propias' => false, 'account_id' => '', 'client_id' => '', 'client_secret' => '', 'color' => self::COLORES[0],
        ]];
        $i = 1;
        foreach ((array)$b['cuentas'] as $x) {
            if (!is_array($x) || empty($x['activa'])) continue;
            $id = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($x['id'] ?? '')));
            if ($id === '' || isset($out[$id])) continue;
            $out[$id] = [
                'id' => $id, 'nombre' => trim((string)($x['nombre'] ?? '')) ?: 'Zoom ' . ($i + 1), 'usuario' => trim((string)($x['usuario'] ?? '')),
                'propias' => !empty($x['propias']), 'account_id' => trim((string)($x['account_id'] ?? '')),
                'client_id' => trim((string)($x['client_id'] ?? '')), 'client_secret' => (string)($x['client_secret'] ?? ''),
                'color' => self::COLORES[$i % count(self::COLORES)],
            ];
            $i++;
        }
        return $out;
    }

    /** Las cuentas con las que de verdad se pueden crear reuniones ahora. */
    public static function activas(): array
    {
        if (!self::listo()) return [];
        return array_filter(self::cuentas(), function ($c) {
            if (!$c['propias']) return $c['id'] === self::PRINCIPAL || $c['usuario'] !== '';   // otra licencia: hace falta su correo
            return $c['account_id'] !== '' && $c['client_id'] !== '' && $c['client_secret'] !== '';
        });
    }

    /** ¿Hay más de una cuenta? (si no, el panel se ve como siempre). */
    public static function varias(): bool
    {
        return count(self::activas()) > 1;
    }

    /** El nombre de una cuenta («Zoom 2»), o el de la principal si ya no está. */
    public static function nombre(string $id): string
    {
        $c = self::cuentas();
        return ($c[$id] ?? $c[self::PRINCIPAL])['nombre'];
    }

    /** Habla con esa cuenta desde ahora (la principal si no existe). */
    public static function usar(string $id): void
    {
        self::$actual = isset(self::cuentas()[$id]) ? $id : self::PRINCIPAL;
    }

    /** Habla con la cuenta en la que se creó esa reunión. */
    public static function usarDe(array $reunion): void
    {
        self::usar((string)($reunion['zoom_cuenta'] ?? '') ?: self::PRINCIPAL);
    }

    public static function actual(): string
    {
        return self::$actual;
    }

    /** Las credenciales con las que se habla ahora: las de la cuenta aparte, o las de siempre. */
    private static function conf(): array
    {
        $b = self::confBase();
        $c = self::cuentas()[self::$actual] ?? null;
        if ($c && $c['propias']) {
            return ['activo' => $b['activo'], 'account_id' => $c['account_id'], 'client_id' => $c['client_id'],
                    'client_secret' => $c['client_secret'], 'zona' => $b['zona']];
        }
        return $b;
    }

    /** El anfitrión de las reuniones de la cuenta actual, para /users/{…}/meetings. */
    private static function anfitrion(): string
    {
        $u = (string)(self::cuentas()[self::$actual]['usuario'] ?? '');
        return $u !== '' ? rawurlencode($u) : 'me';
    }

    /** Las direcciones de Zoom (ZOOM_PRUEBA las manda a un servidor falso en las pruebas). */
    private static function url(string $que): string
    {
        $prueba = rtrim((string)getenv('ZOOM_PRUEBA'), '/');
        if ($prueba !== '') return $prueba . ($que === 'token' ? '/zoom/oauth/token' : '/zoom/v2');
        return $que === 'token' ? 'https://zoom.us/oauth/token' : 'https://api.zoom.us/v2';
    }

    /** true si está activo y con credenciales completas. */
    public static function listo(): bool
    {
        $c = self::confBase();
        return !empty($c['activo']) && $c['account_id'] !== '' && $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    public static function zona(): string
    {
        return self::confBase()['zona'] ?: 'America/Guayaquil';
    }

    /** Token de acceso (cacheado ~55 min). Devuelve string o ['error'=>...].
     *  $forzar ignora la caché (útil tras cambiar los scopes en la app de Zoom). */
    private static function token(bool $forzar = false): string|array
    {
        $c = self::conf();
        // Cada app (la de siempre o la de una cuenta aparte) tiene su token
        $propia = (self::cuentas()[self::$actual]['propias'] ?? false);
        $cacheFile = __DIR__ . '/../../data/cache_zoom' . ($propia ? '_' . self::$actual : '') . (getenv('ZOOM_PRUEBA') ? '_prueba' : '') . '.json';
        $cache = file_exists($cacheFile) ? (json_decode((string)file_get_contents($cacheFile), true) ?: []) : [];
        if (!$forzar && !empty($cache['token']) && ($cache['exp'] ?? 0) > time() + 60) {
            return $cache['token'];
        }
        $url = self::url('token') . '?grant_type=account_credentials&account_id=' . urlencode($c['account_id']);
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
    private static function api(string $metodo, string $ruta, ?array $body = null, bool $forzarToken = false): array
    {
        $token = self::token($forzarToken);
        if (is_array($token)) return [0, $token];
        [$codigo, $cuerpo] = self::http($metodo, self::url('api') . $ruta,
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
        $cuerpo = [
            'topic'      => $datos['topic'],
            'type'       => 2,                       // reunión programada
            'start_time' => str_replace(' ', 'T', $datos['inicio']) . ':00',
            'duration'   => (int)$datos['duracion'],
            'timezone'   => self::zona(),
            'settings'   => [
                'join_before_host' => true,
                'waiting_room'     => false,
                'auto_recording'   => 'cloud',       // graba en la nube (requiere plan de pago)
                'approval_type'    => 2,
            ],
        ];
        // A nombre del anfitrión de la cuenta elegida (Zoom::usar): una licencia, una reunión a la vez
        [$codigo, $json] = self::api('POST', '/users/' . self::anfitrion() . '/meetings', self::conRepeticion($cuerpo, $datos));
        if ($codigo === 201) return $json;
        return ['error' => 'Zoom rechazó la creación: ' . ($json['message'] ?? ('HTTP ' . $codigo))];
    }

    /**
     * Edita una reunión existente (tema, inicio, duración y repetición).
     * Devuelve true o un mensaje de error.
     */
    public static function actualizarReunion(string $zoomId, array $datos): true|string
    {
        $cuerpo = [
            'topic'      => $datos['topic'],
            'type'       => 2,
            'start_time' => str_replace(' ', 'T', $datos['inicio']) . ':00',
            'duration'   => (int)$datos['duracion'],
            'timezone'   => self::zona(),
        ];
        [$codigo, $json] = self::api('PATCH', '/meetings/' . $zoomId, self::conRepeticion($cuerpo, $datos));
        if ($codigo === 204) return true;             // Zoom no devuelve cuerpo al editar
        return 'Zoom rechazó la edición: ' . ($json['message'] ?? ('HTTP ' . $codigo));
    }

    /**
     * Añade la repetición semanal al cuerpo si $datos trae dias + hasta.
     * En Zoom una reunión que se repite es de tipo 8 y su semana empieza en
     * domingo, de ahí la traducción en Reuniones::zoomDias().
     */
    private static function conRepeticion(array $cuerpo, array $datos): array
    {
        $dias  = Reuniones::diasValidos($datos['dias'] ?? []);
        $hasta = trim((string)($datos['hasta'] ?? ''));
        if (!$dias || $hasta === '') return $cuerpo;

        $cuerpo['type'] = 8;                          // reunión recurrente con hora fija
        $cuerpo['recurrence'] = [
            'type'            => 2,                   // semanal
            'repeat_interval' => 1,                   // cada semana
            'weekly_days'     => Reuniones::zoomDias($dias),
            'end_date_time'   => Reuniones::finUtcZoom($hasta),
        ];
        return $cuerpo;
    }

    public static function eliminarReunion(string $zoomId): void
    {
        self::api('DELETE', '/meetings/' . $zoomId);
    }

    /**
     * Grabaciones de una reunión.
     * ['estado'=>'ok'|'vacio'|'error', 'archivos'=>[...], 'share_url'=>, 'msg'=>]
     */
    /**
     * Quita el passcode de la grabación para que el enlace abra sin pedir
     * "Código de acceso". Es la única forma realmente automática (meter el
     * código en la URL no lo evita: Zoom lo valida del lado del servidor).
     * Requiere el scope cloud_recording:write. Devuelve true si lo logró.
     */
    public static function abrirGrabacion(string $zoomId): bool
    {
        self::$ultimoError = null;
        $cuerpo = [
            'password'                 => '',
            'viewer_download'          => true,
            'on_demand'                => false,
            'recording_authentication' => false,
        ];
        [$codigo, $json] = self::api('PATCH', '/meetings/' . $zoomId . '/recordings/settings', $cuerpo);
        // Acabas de añadir el scope pero el token cacheado aún no lo tiene:
        // reintenta UNA vez pidiendo un token nuevo.
        if ($codigo === 400 || $codigo === 401 || $codigo === 403) {
            [$codigo, $json] = self::api('PATCH', '/meetings/' . $zoomId . '/recordings/settings', $cuerpo, true);
        }
        if ($codigo === 204) return true;
        $msg = $json['message'] ?? ($json['error']['message'] ?? ('HTTP ' . $codigo));
        if ($codigo === 0 && is_array($json) && isset($json['error'])) $msg = $json['error'];
        self::$ultimoError = $msg;
        return false;
    }

    /**
     * Ruta de grabaciones de una reunión o de UNA ocurrencia suya. Un id
     * numérico va tal cual; un UUID de ocurrencia (empieza por '/' o lleva
     * '//') exige DOBLE codificación, según la API de Zoom.
     */
    private static function rutaGrabacion(string $idOuuid): string
    {
        if (preg_match('/^\d+$/', $idOuuid)) {
            return '/meetings/' . $idOuuid . '/recordings';
        }
        // UUID: Zoom pide DOBLE codificación SOLO si empieza por '/' o lleva
        // '//'; en el resto, codificación simple. Doble-codificar un UUID normal
        // (con '=' o '+') lo rompe y la API responde 404.
        $enc = (str_starts_with($idOuuid, '/') || str_contains($idOuuid, '//'))
            ? rawurlencode(rawurlencode($idOuuid))
            : rawurlencode($idOuuid);
        return '/meetings/' . $enc . '/recordings';
    }

    /**
     * Instancias PASADAS de una reunión recurrente (las veces que de verdad
     * ocurrió, según Zoom). Devuelve ['estado'=>..., 'items'=>[['uuid'=>,
     * 'fecha'=>'Y-m-d','inicio'=>'Y-m-d H:i'], ...]] con la hora ya en la zona
     * del equipo. Es lo que asocia cada día del historial con su grabación.
     */
    public static function instancias(string $zoomId): array
    {
        [$codigo, $json] = self::api('GET', '/past_meetings/' . $zoomId . '/instances');
        if ($codigo === 404) {
            return ['estado' => 'vacio', 'items' => [], 'msg' => 'Zoom no tiene todavía ninguna ocurrencia registrada.'];
        }
        if ($codigo !== 200) {
            $msg = $json['error']['message'] ?? ($json['message'] ?? ('HTTP ' . $codigo));
            return ['estado' => 'error', 'items' => [], 'msg' => 'No se pudieron leer las ocurrencias: ' . $msg];
        }
        $zona  = Reuniones::zona();
        $items = [];
        foreach ((array)($json['meetings'] ?? []) as $m) {
            $uuid  = (string)($m['uuid'] ?? '');
            $start = (string)($m['start_time'] ?? '');
            if ($uuid === '' || $start === '') continue;
            try {
                $dt = new DateTime($start);                 // viene en UTC (…Z)
                $dt->setTimezone(new DateTimeZone($zona));
                $items[] = ['uuid' => $uuid, 'fecha' => $dt->format('Y-m-d'), 'inicio' => $dt->format('Y-m-d H:i')];
            } catch (Exception $e) { /* fecha rara: se ignora esa instancia */ }
        }
        return ['estado' => 'ok', 'items' => $items, 'msg' => ''];
    }

    public static function grabaciones(string $zoomId, string $passReunion = '', string $uuid = ''): array
    {
        // Antes de leerla, intentamos quitarle el passcode para que abra directo.
        $abierto = self::abrirGrabacion($zoomId);
        $abrirError = self::$ultimoError;
        [$codigo, $json] = self::api('GET', self::rutaGrabacion($uuid !== '' ? $uuid : $zoomId));
        if ($codigo === 200 && !empty($json['recording_files'])) {
            // Passcode para incrustar en la URL (?pwd=) y que abra sin pedirlo.
            // Como la grabación suele usar la contraseña de la REUNIÓN
            // (useWhichPasswd=meeting), usamos esa si la API no da una propia.
            $pass = (string)($json['recording_play_passcode'] ?? $json['password'] ?? '');
            if ($pass === '') $pass = $passReunion;
            $conPwd = function (string $url) use ($pass): string {
                if ($url === '' || $pass === '') return $url;
                if (str_contains($url, 'pwd=')) return $url;
                return $url . (str_contains($url, '?') ? '&' : '?') . 'pwd=' . rawurlencode($pass);
            };
            $archivos = [];
            foreach ($json['recording_files'] as $f) {
                if (($f['status'] ?? 'completed') !== 'completed') continue;
                $archivos[] = [
                    'tipo'     => $f['recording_type'] ?? ($f['file_type'] ?? 'archivo'),
                    'ext'      => strtolower($f['file_type'] ?? ''),
                    'play'     => $conPwd($f['play_url'] ?? ''),
                    'download' => $conPwd($f['download_url'] ?? ''),
                ];
            }
            return [
                'estado'     => 'ok',
                'archivos'   => $archivos,
                'share_url'  => $conPwd($json['share_url'] ?? ''),
                'password'   => $abierto ? '' : $pass,   // si se quitó, no hace falta el código
                'abierto'    => $abierto,
                'abrir_error' => $abrirError,
            ];
        }
        if ($codigo === 404) {
            return ['estado' => 'vacio', 'archivos' => [], 'msg' => 'Aún no hay grabación (aparece cuando Zoom termina de procesarla).'];
        }
        $msg = $json['error']['message'] ?? ($json['message'] ?? ('HTTP ' . $codigo));
        return ['estado' => 'error', 'archivos' => [], 'msg' => 'No se pudo leer la grabación: ' . $msg];
    }

    /**
     * Descarga la transcripción de la grabación (archivo VTT) y la devuelve
     * como texto plano, listo para pasárselo a Claude.
     * ['estado'=>'ok'|'vacio'|'error', 'texto'=>string, 'msg'=>string]
     */
    public static function transcripcion(string $zoomId, string $uuid = ''): array
    {
        [$codigo, $json] = self::api('GET', self::rutaGrabacion($uuid !== '' ? $uuid : $zoomId));
        if ($codigo === 404 || ($codigo === 200 && empty($json['recording_files']))) {
            return ['estado' => 'vacio', 'texto' => '', 'msg' => 'Aún no hay grabación.'];
        }
        if ($codigo !== 200) {
            $msg = $json['error']['message'] ?? ($json['message'] ?? ('HTTP ' . $codigo));
            return ['estado' => 'error', 'texto' => '', 'msg' => 'No se pudo leer la grabación: ' . $msg];
        }
        // Busca el archivo de transcripción (VTT)
        $url = '';
        foreach ($json['recording_files'] as $f) {
            if (strtoupper($f['file_type'] ?? '') === 'TRANSCRIPT'
                || ($f['recording_type'] ?? '') === 'audio_transcript') {
                $url = (string)($f['download_url'] ?? '');
                break;
            }
        }
        if ($url === '') {
            return ['estado' => 'vacio', 'texto' => '',
                'msg' => 'Esta grabación no tiene transcripción. Zoom tarda un rato en generarla, y debe estar activada la "Transcripción de audio" en Zoom → Configuración → Grabación.'];
        }
        $token = self::token();
        if (is_array($token)) return ['estado' => 'error', 'texto' => '', 'msg' => $token['error']];
        // La descarga autenticada de S2S se hace con el token en la URL
        $sep = str_contains($url, '?') ? '&' : '?';
        $vtt = @file_get_contents($url . $sep . 'access_token=' . rawurlencode($token));
        if ($vtt === false || trim((string)$vtt) === '') {
            return ['estado' => 'error', 'texto' => '', 'msg' => 'No se pudo descargar la transcripción.'];
        }
        return ['estado' => 'ok', 'texto' => self::vttATexto((string)$vtt), 'msg' => ''];
    }

    /**
     * Quién se conectó a una ocurrencia ya terminada (por su UUID). Una persona
     * que entró y salió varias veces sale una sola vez, con sus minutos sumados.
     * Prueba la lista de la reunión pasada y, si a la app le falta ese permiso,
     * el reporte de la cuenta.
     * ['estado'=>'ok'|'error', 'items'=>[['nombre','email','minutos','entro'=>'H:i']], 'msg'=>string]
     */
    public static function participantes(string $uuid): array
    {
        $enc = (str_starts_with($uuid, '/') || str_contains($uuid, '//')) ? rawurlencode(rawurlencode($uuid)) : rawurlencode($uuid);
        $msg = '';
        foreach (['/past_meetings/', '/report/meetings/'] as $base) {
            $filas = [];
            $token = '';
            do {
                [$codigo, $json] = self::api('GET', $base . $enc . '/participants?page_size=300' . ($token !== '' ? '&next_page_token=' . rawurlencode($token) : ''));
                if ($codigo !== 200) break;
                foreach ((array)($json['participants'] ?? []) as $p) $filas[] = $p;
                $token = (string)($json['next_page_token'] ?? '');
            } while ($token !== '');
            if ($codigo === 200) {
                $zona = new DateTimeZone(Reuniones::zona());
                $porPersona = [];
                foreach ($filas as $p) {
                    $email = strtolower(trim((string)($p['user_email'] ?? ($p['email'] ?? ''))));
                    $nombre = trim((string)($p['name'] ?? ($p['user_name'] ?? '')));
                    $k = $email !== '' ? $email : mb_strtolower($nombre);
                    if ($k === '') continue;
                    $entro = '';
                    try { if (!empty($p['join_time'])) $entro = (new DateTime((string)$p['join_time']))->setTimezone($zona)->format('H:i'); } catch (Exception $e) { /* sin hora */ }
                    $porPersona[$k] ??= ['nombre' => $nombre, 'email' => $email, 'minutos' => 0, 'entro' => $entro];
                    $porPersona[$k]['minutos'] += (int)round((int)($p['duration'] ?? 0) / 60);
                    if ($entro !== '' && ($porPersona[$k]['entro'] === '' || $entro < $porPersona[$k]['entro'])) $porPersona[$k]['entro'] = $entro;
                }
                return ['estado' => 'ok', 'items' => array_values($porPersona), 'msg' => ''];
            }
            $msg = (string)($json['error']['message'] ?? ($json['message'] ?? ('HTTP ' . $codigo)));
        }
        return ['estado' => 'error', 'items' => [],
            'msg' => 'Zoom no deja ver quién se conectó (' . $msg . '). En la app Server-to-Server añade el permiso «meeting:read:list_past_participants:admin» o «report:read:list_meeting_participants:admin».'];
    }

    /** Convierte un VTT (con tiempos y numeración) en texto plano legible. */
    private static function vttATexto(string $vtt): string
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $vtt) as $linea) {
            $l = trim($linea);
            if ($l === '' || $l === 'WEBVTT') continue;
            if (str_contains($l, '-->')) continue;       // línea de tiempos
            if (ctype_digit($l)) continue;               // número de segmento
            if (str_starts_with($l, 'NOTE ')) continue;
            $out[] = $l;
        }
        return implode("\n", $out);
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

    /**
     * Prueba una cuenta: que su app entre y que su anfitrión exista y tenga
     * licencia (en Zoom, type 2 = con licencia; 1 = básica, sin grabación en la
     * nube y con reuniones de 40 minutos). Devuelve [ok, mensaje].
     */
    public static function probarCuenta(string $id): array
    {
        $antes = self::$actual;
        self::usar($id);
        $c = self::cuentas()[self::$actual];
        $t = self::token(true);
        if (is_array($t)) { self::$actual = $antes; return [false, $c['nombre'] . ': ' . $t['error']]; }
        [$codigo, $json] = self::api('GET', '/users/' . self::anfitrion());
        self::$actual = $antes;
        if ($codigo !== 200) {
            return [false, $c['nombre'] . ': ' . ($codigo === 404
                ? 'en esa cuenta de Zoom no hay un usuario con el correo ' . $c['usuario'] . '.'
                : 'Zoom respondió ' . $codigo . ' (' . ($json['message'] ?? 'revisa los permisos de la app: user:read:admin y meeting:write:admin') . ').')];
        }
        $quien = trim((string)($json['email'] ?? '')) ?: $c['nombre'];
        if ((int)($json['type'] ?? 0) === 1) {
            return [false, $c['nombre'] . ': ' . $quien . ' es un usuario básico (sin licencia): sus reuniones durarían 40 minutos y no se grabarían. Asígnale la licencia en Zoom.'];
        }
        return [true, $c['nombre'] . ': conectada, anfitrión ' . $quien . ($c['propias'] ? ' (cuenta de Zoom aparte).' : ' (licencia de la misma cuenta).')];
    }
}
