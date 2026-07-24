<?php
/**
 * GoogleCalendar - crea/actualiza/borra un evento en el Google Calendar del
 * responsable de una tarea, usando su refresh token (obtenido al entrar con
 * Google con el permiso de calendario activado en Ajustes).
 *
 * El evento es de día completo, del inicio al fin de la tarea. Se guarda su id
 * en la propia tarea (mapa gcal_eventos: idMiembro => idEvento) para poder
 * actualizarlo o borrarlo después. Sin refresh token del miembro, no hace nada.
 */
require_once __DIR__ . '/Models.php';

class GoogleCalendar
{
    /** Último motivo de fallo (para diagnóstico en la sincronización manual). */
    public static ?string $ultimoError = null;

    /** ¿Está activada la sincronización con el calendario? */
    public static function listo(): bool
    {
        return !empty((array)Config::get('google_login'))
            && !empty(Config::get('google_login')['calendario'])
            && GoogleLogin::listo();
    }

    /** Cambia el refresh token por un access token vigente. string | ['error'=>]. */
    private static function accessToken(string $refresh): string|array
    {
        $c = GoogleLogin::conf();
        [$codigo, $cuerpo] = self::http('https://oauth2.googleapis.com/token', [
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'refresh_token' => $refresh,
            'grant_type'    => 'refresh_token',
        ]);
        $json = json_decode($cuerpo, true) ?: [];
        if ($codigo !== 200 || empty($json['access_token'])) {
            return ['error' => $json['error_description'] ?? $json['error'] ?? ('HTTP ' . $codigo)];
        }
        return $json['access_token'];
    }

    /** Cuerpo del evento (día completo, del inicio al fin de la tarea). */
    private static function evento(array $tarea, array $proyecto): ?array
    {
        $ini = $tarea['fecha_inicio'] ?? '';
        $fin = $tarea['fecha_limite'] ?? '';
        if ($ini === '' && $fin === '') return null;   // sin fechas, no hay evento
        if ($ini === '') $ini = $fin;
        if ($fin === '') $fin = $ini;
        if ($ini > $fin) { [$ini, $fin] = [$fin, $ini]; }

        // En Google, el 'end' de un evento de día completo es EXCLUSIVO: para que
        // el último día se vea incluido, se suma un día.
        $finExcl = date('Y-m-d', strtotime($fin . ' +1 day'));

        $desc = trim(($tarea['descripcion'] ?? '') . "\n\nProyecto: " . ($proyecto['nombre'] ?? ''));
        $base = rtrim((string)(Config::get('correo')['url_panel'] ?? ''), '/');
        if ($base !== '') $desc .= "\n" . $base . '/proyecto.php?id=' . (int)($proyecto['id'] ?? 0);

        $prio = Catalogo::prioridades()[$tarea['prioridad'] ?? '']['0'] ?? '';
        return [
            'summary'     => ($tarea['titulo'] ?? 'Tarea') . ($prio ? ' · ' . $prio : ''),
            'description' => $desc,
            'start'       => ['date' => $ini],
            'end'         => ['date' => $finExcl],
            'source'      => ['title' => $proyecto['nombre'] ?? 'Panel', 'url' => $base ?: 'https://google.com'],
        ];
    }

    /**
     * Crea o actualiza el evento de una tarea en el calendario del miembro.
     * Devuelve el id del evento (para guardarlo) o null si no aplica/falla.
     */
    public static function upsert(array $miembro, array $tarea, array $proyecto, string $eventoId = ''): ?string
    {
        self::$ultimoError = null;
        if (!self::listo()) { self::$ultimoError = 'Google Calendar no está configurado.'; return null; }
        if (empty($miembro['gcal_refresh'])) { self::$ultimoError = 'sin_conexion'; return null; }
        $ev = self::evento($tarea, $proyecto);
        if ($ev === null) {                    // la tarea se quedó sin fechas
            if ($eventoId !== '') self::borrar($miembro, $eventoId);
            return null;
        }
        $token = self::accessToken($miembro['gcal_refresh']);
        if (is_array($token)) {                // no se pudo renovar (permiso revocado, etc.)
            self::$ultimoError = 'Google rechazó el permiso (' . ($token['error'] ?? '') . '). La persona debe volver a entrar con Google.';
            return null;
        }

        // PATCH si ya existía, POST si es nuevo
        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
        $metodo = 'POST';
        if ($eventoId !== '') { $url .= '/' . rawurlencode($eventoId); $metodo = 'PATCH'; }

        [$codigo, $cuerpo] = self::http($url, $ev, $token, $metodo);
        if ($codigo === 404 && $eventoId !== '') {
            // El evento fue borrado a mano en Google: recrearlo
            [$codigo, $cuerpo] = self::http(
                'https://www.googleapis.com/calendar/v3/calendars/primary/events', $ev, $token, 'POST');
        }
        $json = json_decode($cuerpo, true) ?: [];
        if ($codigo >= 200 && $codigo < 300 && !empty($json['id'])) return $json['id'];

        $motivo = $json['error']['message'] ?? $json['error_description'] ?? ('HTTP ' . $codigo);
        self::$ultimoError = 'Google no aceptó el evento (' . $motivo . ').';
        return null;
    }

    /** Borra el evento del calendario del miembro. */
    public static function borrar(array $miembro, string $eventoId): void
    {
        if ($eventoId === '' || empty($miembro['gcal_refresh']) || !self::listo()) return;
        $token = self::accessToken($miembro['gcal_refresh']);
        if (is_array($token)) return;
        self::http('https://www.googleapis.com/calendar/v3/calendars/primary/events/' . rawurlencode($eventoId),
            null, $token, 'DELETE');
    }

    /** HTTP con streams. $body array => JSON. Devuelve [codigo, cuerpo]. */
    private static function http(string $url, ?array $body, string $token = '', string $metodo = 'POST'): array
    {
        $cabeceras = ['Accept: application/json'];
        if ($token !== '') $cabeceras[] = 'Authorization: Bearer ' . $token;
        $contenido = '';
        if ($body !== null) {
            // token endpoint usa form-urlencoded; la API de Calendar usa JSON
            if ($token === '') {
                $cabeceras[] = 'Content-Type: application/x-www-form-urlencoded';
                $contenido = http_build_query($body);
            } else {
                $cabeceras[] = 'Content-Type: application/json';
                $contenido = json_encode($body);
            }
        }
        $ctx = stream_context_create(['http' => [
            'method'        => $metodo,
            'header'        => implode("\r\n", $cabeceras),
            'content'       => $contenido,
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
