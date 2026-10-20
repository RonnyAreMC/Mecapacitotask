<?php
/**
 * ZoomDrive: pasa las grabaciones de la cuenta de Zoom a Google Drive y, cuando
 * todos los archivos de una reunión llegaron completos, la manda a la papelera
 * de Zoom (30 días para recuperarla) para ir liberando el almacenamiento.
 *
 * En Drive, dentro de la carpeta elegida (zoom_drive.php):
 *   <Proyecto o equipo>/Videos|Audios|Textos/<AAAA-MM>/<AAAA-MM-DD HH.mm Tema>.<ext>
 * Se toman TODAS las grabaciones de la cuenta. Las de reuniones creadas desde el
 * panel van a su proyecto y con el nombre que tienen en el panel; las demás, a «Otras».
 *
 * - Zoom: la app Server-to-Server de Ajustes. Lista los usuarios de la cuenta y
 *   sus grabaciones mes a mes; descarga por partes (Range) y borra a la papelera.
 * - Drive: la cuenta de Google que conecta el administrador (permiso «drive»).
 *   Subida reanudable de 16 MB por vez: en el servidor nunca hay más de un pedazo
 *   y, si algo se corta, sigue donde iba.
 * - Cada archivo de Zoom es una fila de la tabla zoom_drive:
 *     nuevo → en_cola → subiendo → en_drive (mismo tamaño en Drive) → borrado (en la papelera de Zoom)
 * Lo mueve cron_zoom_drive.php (cada 5 minutos) o la página mientras está abierta.
 *
 * No va en bootstrap: lo cargan zoom_drive.php, cron_zoom_drive.php y oauth_google.php.
 */
require_once __DIR__ . '/Models.php';

final class ZoomDrive
{
    /** Pedazo de subida: múltiplo de 256 KiB, como pide Drive */
    public const PEDAZO = 16 * 1024 * 1024;
    /** Hasta este tamaño, el archivo va de una sola vez (los textos) */
    private const DE_UNA = 5 * 1024 * 1024;
    /** La carpeta que pasó el usuario (InnoTech) */
    public const CARPETA_DEFECTO = '1wJOhdBO_xwf_fNFt64qGGjwnx5_r9fGU';
    /** Las grabaciones que no se crearon desde el panel */
    public const OTRAS = 'Otras';
    public const ESTADOS = [
        'nuevo'    => 'En Zoom',
        'en_cola'  => 'En cola',
        'subiendo' => 'Subiendo',
        'en_drive' => 'En Drive',
        'borrado'  => 'En Drive · liberado de Zoom',
        'error'    => 'Con error',
    ];
    private const TIPOS_CARPETA = ['video' => 'Videos', 'audio' => 'Audios', 'texto' => 'Textos'];

    /** Lo último que salió mal (para mostrarlo) */
    public static string $ultimoError = '';

    /* ======================= Configuración ======================= */

    public static function conf(): array
    {
        return array_replace([
            'refresh' => '',            // refresh token de Google, sellado
            'email'   => '',
            'carpeta' => self::CARPETA_DEFECTO,
            'borrar'  => 'papelera',    // papelera | no
            'desde'   => date('Y-m-d', strtotime('-2 years')),
            'grupos'  => [],            // id de reunión de Zoom => carpeta (lo que eligió el administrador)
            'escaneo' => [],
        ], (array)Config::get('zoom_drive'));
    }

    public static function guardar(array $cambios): void
    {
        Config::guardar(['zoom_drive' => array_replace(self::conf(), $cambios)]);
    }

    private static function zoomConf(): array
    {
        return array_replace(['activo' => false, 'account_id' => '', 'client_id' => '', 'client_secret' => ''], (array)Config::get('zoom'));
    }

    public static function zoomListo(): bool
    {
        $c = self::zoomConf();
        return !empty($c['activo']) && $c['account_id'] !== '' && $c['client_id'] !== '' && $c['client_secret'] !== '';
    }

    public static function driveListo(): bool
    {
        return self::refresh() !== '' && trim(self::conf()['carpeta']) !== '';
    }

    public static function listo(): bool
    {
        return self::zoomListo() && self::driveListo() && function_exists('curl_init');
    }

    /** ID de carpeta a partir de un enlace de Drive o del ID tal cual */
    public static function idCarpeta(string $texto): string
    {
        $t = trim($texto);
        if (preg_match('#/folders/([A-Za-z0-9_-]{10,})#', $t, $m)) return $m[1];
        if (preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $t, $m)) return $m[1];
        return preg_match('/^[A-Za-z0-9_-]{10,}$/', $t) ? $t : '';
    }

    /* ======================= Llaves ======================= */

    private static function sellar(string $plano): string
    {
        if (class_exists('Correo') && method_exists('Correo', 'sellar') && function_exists('openssl_encrypt')) {
            return 's:' . base64_encode(Correo::sellar($plano));
        }
        return 'p:' . $plano;
    }

    private static function refresh(): string
    {
        $r = (string)self::conf()['refresh'];
        if (str_starts_with($r, 's:')) {
            if (!class_exists('Correo') && is_file(__DIR__ . '/Correo.php')) require_once __DIR__ . '/Correo.php';
            return class_exists('Correo') ? Correo::desellar((string)base64_decode(substr($r, 2))) : '';
        }
        return str_starts_with($r, 'p:') ? substr($r, 2) : '';
    }

    /* ======================= Direcciones (las pruebas usan un servidor falso) ======================= */

    private static function url(string $que): string
    {
        $prueba = rtrim((string)getenv('ZOOM_DRIVE_PRUEBA'), '/');
        $real = [
            'zoom_token' => 'https://zoom.us/oauth/token',
            'zoom'       => 'https://api.zoom.us/v2',
            'g_token'    => 'https://oauth2.googleapis.com/token',
            'drive'      => 'https://www.googleapis.com/drive/v3',
            'subir'      => 'https://www.googleapis.com/upload/drive/v3',
        ];
        $falso = ['zoom_token' => '/zoom/oauth/token', 'zoom' => '/zoom/v2', 'g_token' => '/google/token', 'drive' => '/drive/v3', 'subir' => '/upload/drive/v3'];
        return $prueba !== '' ? $prueba . $falso[$que] : $real[$que];
    }

    /* ======================= Google: conectar Drive ======================= */

    /** Pide a Google el permiso de Drive (lo devuelve a oauth_google.php) */
    public static function urlConectar(): string
    {
        $c = GoogleLogin::conf();
        $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
        $_SESSION['oauth_drive'] = true;
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => $c['client_id'],
            'redirect_uri'  => GoogleLogin::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'openid email profile https://www.googleapis.com/auth/drive',
            'state'         => $_SESSION['oauth_state'],
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'include_granted_scopes' => 'true',
        ]);
    }

    public static function guardarConexion(string $refresh, string $email): void
    {
        self::guardar(['refresh' => self::sellar($refresh), 'email' => $email]);
        @unlink(self::cache('drive'));
    }

    public static function desconectar(): void
    {
        self::guardar(['refresh' => '', 'email' => '']);
        @unlink(self::cache('drive'));
    }

    /* ======================= HTTP ======================= */

    /**
     * Una llamada con cURL. Devuelve [código, cuerpo, cabeceras en minúscula].
     * $op: cuerpo (string), cabeceras (lista), seguir (redirecciones), max (corta
     * la respuesta al pasar esos bytes), timeout.
     */
    private static function http(string $metodo, string $url, array $op = []): array
    {
        $ch = curl_init($url);
        $cab = [];
        $cuerpo = '';
        $max = (int)($op['max'] ?? 0);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $metodo,
            CURLOPT_HTTPHEADER     => $op['cabeceras'] ?? [],
            CURLOPT_FOLLOWLOCATION => !empty($op['seguir']),
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT        => (int)($op['timeout'] ?? 120),
            CURLOPT_HEADERFUNCTION => function ($c, $linea) use (&$cab) {
                if (preg_match('#^HTTP/\S+\s+\d{3}#', $linea)) $cab = [];   // al seguir una redirección, valen las del final
                $p = strpos($linea, ':');
                if ($p !== false) $cab[strtolower(trim(substr($linea, 0, $p)))] = trim(substr($linea, $p + 1));
                return strlen($linea);
            },
            CURLOPT_WRITEFUNCTION  => function ($c, $datos) use (&$cuerpo, $max) {
                $cuerpo .= $datos;
                return ($max > 0 && strlen($cuerpo) > $max) ? 0 : strlen($datos);   // 0 = cortar
            },
        ]);
        if (array_key_exists('cuerpo', $op)) curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$op['cuerpo']);
        curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        unset($ch);
        if ($codigo === 0 && $err) self::$ultimoError = 'Sin conexión (cURL ' . $err . ')';
        return [$codigo, $cuerpo, $cab];
    }

    private static function cache(string $que): string
    {
        return __DIR__ . '/../data/cache_zoom_drive_' . $que . (getenv('ZOOM_DRIVE_PRUEBA') ? '_prueba' : '') . '.json';
    }

    /* ======================= Tokens ======================= */

    private static function tokenZoom(bool $forzar = false): string
    {
        $f = self::cache('zoom');
        $c = json_decode((string)@file_get_contents($f), true) ?: [];
        if (!$forzar && !empty($c['t']) && ($c['exp'] ?? 0) > time() + 60) return $c['t'];
        $z = self::zoomConf();
        [$codigo, $cuerpo] = self::http('POST', self::url('zoom_token') . '?grant_type=account_credentials&account_id=' . rawurlencode($z['account_id']), [
            'cabeceras' => ['Authorization: Basic ' . base64_encode($z['client_id'] . ':' . $z['client_secret']), 'Content-Type: application/x-www-form-urlencoded'],
            'cuerpo' => '', 'timeout' => 30,
        ]);
        $j = json_decode($cuerpo, true) ?: [];
        if ($codigo !== 200 || empty($j['access_token'])) {
            self::$ultimoError = 'Zoom no dejó entrar: ' . ($j['reason'] ?? $j['error'] ?? ('HTTP ' . $codigo));
            return '';
        }
        @file_put_contents($f, json_encode(['t' => $j['access_token'], 'exp' => time() + (int)($j['expires_in'] ?? 3600)]));
        return $j['access_token'];
    }

    private static function tokenDrive(bool $forzar = false): string
    {
        $f = self::cache('drive');
        $c = json_decode((string)@file_get_contents($f), true) ?: [];
        if (!$forzar && !empty($c['t']) && ($c['exp'] ?? 0) > time() + 60) return $c['t'];
        $g = GoogleLogin::conf();
        [$codigo, $cuerpo] = self::http('POST', self::url('g_token'), [
            'cabeceras' => ['Content-Type: application/x-www-form-urlencoded'],
            'cuerpo' => http_build_query(['client_id' => $g['client_id'], 'client_secret' => $g['client_secret'],
                'refresh_token' => self::refresh(), 'grant_type' => 'refresh_token']),
            'timeout' => 30,
        ]);
        $j = json_decode($cuerpo, true) ?: [];
        if ($codigo !== 200 || empty($j['access_token'])) {
            self::$ultimoError = 'Google no renovó el permiso de Drive (' . ($j['error_description'] ?? $j['error'] ?? ('HTTP ' . $codigo))
                . '). Vuelve a conectar Google Drive.';
            return '';
        }
        @file_put_contents($f, json_encode(['t' => $j['access_token'], 'exp' => time() + (int)($j['expires_in'] ?? 3600)]));
        return $j['access_token'];
    }

    /** Llamada a la API de Zoom → [código, json] (reintenta una vez con token nuevo) */
    private static function zoom(string $metodo, string $ruta): array
    {
        foreach ([false, true] as $forzar) {
            $t = self::tokenZoom($forzar);
            if ($t === '') return [0, []];
            [$codigo, $cuerpo] = self::http($metodo, self::url('zoom') . $ruta, ['cabeceras' => ['Authorization: Bearer ' . $t], 'timeout' => 60]);
            if ($codigo !== 401) break;
        }
        $j = json_decode($cuerpo, true) ?: [];
        if ($codigo >= 300) self::$ultimoError = 'Zoom: ' . ($j['message'] ?? ('HTTP ' . $codigo));
        return [$codigo, $j];
    }

    /** Llamada a la API de Drive (JSON) → [código, json, cabeceras] */
    private static function drive(string $metodo, string $url, ?array $json = null, array $mas = []): array
    {
        foreach ([false, true] as $forzar) {
            $t = self::tokenDrive($forzar);
            if ($t === '') return [0, [], []];
            $cab = array_merge(['Authorization: Bearer ' . $t], $mas);
            $op = ['cabeceras' => $cab, 'timeout' => 60];
            if ($json !== null) { $op['cuerpo'] = json_encode($json, JSON_UNESCAPED_UNICODE); $op['cabeceras'][] = 'Content-Type: application/json; charset=UTF-8'; }
            elseif ($metodo === 'POST') $op['cuerpo'] = '';
            [$codigo, $cuerpo, $cabR] = self::http($metodo, $url, $op);
            if ($codigo !== 401) break;
        }
        $j = json_decode($cuerpo, true) ?: [];
        if ($codigo >= 300 && $codigo !== 308) self::$ultimoError = 'Drive: ' . ($j['error']['message'] ?? ('HTTP ' . $codigo));
        return [$codigo, $j, $cabR];
    }

    /* ======================= Pruebas de conexión ======================= */

    /** ['ok'=>bool, 'msg'=>...] — Zoom: token y permiso para listar los usuarios */
    public static function probarZoom(): array
    {
        self::$ultimoError = '';
        [$codigo, $j] = self::zoom('GET', '/users?page_size=1&status=active');
        if ($codigo === 200) return ['ok' => true, 'msg' => 'Zoom conectado: ' . (int)($j['total_records'] ?? 0) . ' usuario(s) en la cuenta.'];
        return ['ok' => false, 'msg' => self::$ultimoError . ' — la app de Zoom necesita los permisos user:read:admin, recording:read:admin y recording:write:admin (o los «granulares» equivalentes).'];
    }

    /** Revisa que la carpeta de Drive exista y se pueda escribir en ella */
    public static function probarCarpeta(): array
    {
        self::$ultimoError = '';
        $id = self::conf()['carpeta'];
        [$codigo, $j] = self::drive('GET', self::url('drive') . '/files/' . rawurlencode($id) . '?fields=id,name,mimeType,capabilities(canAddChildren)&supportsAllDrives=true');
        if ($codigo !== 200) return ['ok' => false, 'msg' => self::$ultimoError ?: 'No se pudo abrir la carpeta de Drive.'];
        if (($j['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') return ['ok' => false, 'msg' => 'Ese enlace no es una carpeta de Drive.'];
        if (isset($j['capabilities']['canAddChildren']) && !$j['capabilities']['canAddChildren']) {
            return ['ok' => false, 'msg' => 'La cuenta ' . self::conf()['email'] . ' puede ver «' . $j['name'] . '» pero no agregar archivos: compártela con permiso de editor.'];
        }
        return ['ok' => true, 'msg' => 'Carpeta «' . ($j['name'] ?? '') . '» lista para recibir las grabaciones.', 'nombre' => (string)($j['name'] ?? '')];
    }

    /* ======================= Escaneo de Zoom ======================= */

    private static function tabla(): JsonStore { return new JsonStore('zoom_drive'); }

    /**
     * Busca grabaciones nuevas, por partes (cabe en una petición web).
     * Recorre los usuarios de la cuenta y, para cada uno, los meses desde la fecha
     * elegida. Devuelve ['terminado'=>bool, 'texto'=>'Usuario 2 de 4 · mar 2025', 'nuevas'=>n].
     */
    public static function buscar(int $segundos, bool $reiniciar = false): array
    {
        self::$ultimoError = '';
        $inicio = time();
        $conf = self::conf();
        $e = $reiniciar ? [] : (array)$conf['escaneo'];
        if (empty($e['usuarios'])) $e = [];
        if (!$e) {
            $usuarios = [];
            foreach (['active', 'inactive'] as $estado) {
                $token = '';
                do {
                    [$codigo, $j] = self::zoom('GET', '/users?page_size=300&status=' . $estado . ($token !== '' ? '&next_page_token=' . rawurlencode($token) : ''));
                    if ($codigo !== 200) {
                        if ($estado === 'active') return ['terminado' => true, 'error' => self::$ultimoError, 'texto' => '', 'nuevas' => 0];
                        break;
                    }
                    foreach ((array)($j['users'] ?? []) as $u) $usuarios[] = ['id' => (string)$u['id'], 'email' => (string)($u['email'] ?? '')];
                    $token = (string)($j['next_page_token'] ?? '');
                } while ($token !== '');
            }
            $e = ['usuarios' => $usuarios, 'u' => 0, 'mes' => date('Y-m-01', strtotime($conf['desde'])), 'nuevas' => 0, 'terminado' => false, 'empezo' => date('Y-m-d H:i')];
        }
        $proyectos = self::proyectos();
        $reuniones = self::reunionesPanel();
        $existentes = [];
        foreach (self::tabla()->all() as $f) $existentes[(string)$f['zoom_file']] = $f;
        while (!$e['terminado'] && time() - $inicio < $segundos) {
            $u = $e['usuarios'][$e['u']] ?? null;
            if (!$u) { $e['terminado'] = true; break; }
            $desde = $e['mes'];
            $hasta = min(date('Y-m-t', strtotime($desde)), date('Y-m-d'));
            $token = '';
            do {
                [$codigo, $j] = self::zoom('GET', '/users/' . rawurlencode($u['id']) . '/recordings?page_size=300&from=' . $desde . '&to=' . $hasta
                    . ($token !== '' ? '&next_page_token=' . rawurlencode($token) : ''));
                if ($codigo !== 200) {
                    self::guardar(['escaneo' => $e]);
                    return ['terminado' => false, 'error' => self::$ultimoError, 'texto' => self::textoEscaneo($e), 'nuevas' => $e['nuevas']];
                }
                foreach ((array)($j['meetings'] ?? []) as $m) {
                    $e['nuevas'] += self::anotarReunion($m, $u['email'], $existentes, $proyectos, $reuniones);
                }
                $token = (string)($j['next_page_token'] ?? '');
            } while ($token !== '');
            // Siguiente mes; al llegar a hoy, siguiente usuario
            $sig = date('Y-m-01', strtotime($desde . ' +1 month'));
            if ($sig > date('Y-m-d')) { $e['u']++; $e['mes'] = date('Y-m-01', strtotime($conf['desde'])); }
            else $e['mes'] = $sig;
            if ($e['u'] >= count($e['usuarios'])) $e['terminado'] = true;
        }
        if ($e['terminado']) $e['termino'] = date('Y-m-d H:i');
        self::guardar(['escaneo' => $e]);
        return ['terminado' => (bool)$e['terminado'], 'texto' => self::textoEscaneo($e), 'nuevas' => (int)$e['nuevas']];
    }

    private static function textoEscaneo(array $e): string
    {
        $n = count((array)($e['usuarios'] ?? []));
        if (!empty($e['terminado'])) return 'Búsqueda terminada';
        $meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
        $t = strtotime($e['mes'] ?? 'now');
        return 'Usuario ' . min($n, (int)($e['u'] ?? 0) + 1) . ' de ' . $n . ' · ' . $meses[(int)date('n', $t) - 1] . ' ' . date('Y', $t);
    }

    /** Guarda los archivos de una reunión grabada (sin repetir). Devuelve cuántos son nuevos. */
    private static function anotarReunion(array $m, string $host, array &$existentes, array $proyectos, array $reuniones): int
    {
        $archivos = array_values(array_filter((array)($m['recording_files'] ?? []),
            fn($f) => ($f['status'] ?? 'completed') === 'completed' && !empty($f['id']) && !empty($f['download_url'])));
        if (!$archivos) return 0;
        $inicio = self::horaLocal((string)($m['start_time'] ?? ''));
        $idReunion = (string)($m['id'] ?? '');
        $delPanel = $reuniones[$idReunion] ?? null;
        // Si la reunión salió del panel, se llama como en el panel
        $tema = trim((string)($delPanel['tema'] ?? '')) ?: (trim((string)($m['topic'] ?? '')) ?: 'Reunión');
        $grupo = self::grupoSugerido($idReunion, $proyectos, $reuniones);
        $manual = !empty(((array)self::conf()['grupos'])[$idReunion]);
        $videos = count(array_filter($archivos, fn($f) => self::tipo($f) === 'video'));
        $nuevas = 0;
        foreach ($archivos as $f) {
            $id = (string)$f['id'];
            $fila = [
                'zoom_file'  => $id,
                'uuid'       => (string)($m['uuid'] ?? ''),
                'reunion'    => $idReunion,
                'tema'       => $tema,
                'inicio'     => $inicio,
                'host'       => $host,
                'file_type'  => strtoupper((string)($f['file_type'] ?? '')),
                'rec_type'   => (string)($f['recording_type'] ?? ''),
                'ext'        => self::extension($f),
                'tamano'     => (int)($f['file_size'] ?? 0),
                'tipo'       => self::tipo($f),
                'nombre'     => self::nombre($inicio, $tema, $f, $videos),
                'url'        => (string)$f['download_url'],
                'del_panel'  => $delPanel !== null,
            ];
            if (isset($existentes[$id])) {
                $ya = $existentes[$id];
                // Lo que puede cambiar: el enlace de Zoom y, si todavía no se sube, el nombre
                // y la carpeta (por ejemplo, la reunión ahora sí está en el panel). Lo que
                // eligió el administrador a mano se respeta.
                if (in_array($ya['estado'] ?? '', ['nuevo', 'error'], true)) {
                    $cambio = ['url' => $fila['url'], 'tamano' => $fila['tamano'], 'tema' => $fila['tema'], 'nombre' => $fila['nombre'], 'del_panel' => $fila['del_panel']];
                    if (!$manual) $cambio['grupo'] = $grupo;
                    self::tabla()->update((int)$ya['id'], $cambio);
                } elseif (($ya['estado'] ?? '') === 'en_cola') {
                    self::tabla()->update((int)$ya['id'], ['url' => $fila['url']]);
                }
                continue;
            }
            $fila += ['grupo' => $grupo, 'estado' => 'nuevo', 'sesion' => '', 'subido' => 0, 'drive_id' => '', 'error' => '', 'intentos' => 0,
                      'encontrado' => date('Y-m-d H:i'), 'actualizado' => date('Y-m-d H:i')];
            $existentes[$id] = self::tabla()->insert($fila);
            $nuevas++;
        }
        return $nuevas;
    }

    private static function horaLocal(string $utc): string
    {
        try {
            $d = new DateTime($utc !== '' ? $utc : 'now');
            $d->setTimezone(new DateTimeZone((string)(Config::get('zoom')['zona'] ?? '') ?: 'America/Guayaquil'));
            return $d->format('Y-m-d H:i');
        } catch (Exception $e) {
            return date('Y-m-d H:i');
        }
    }

    public static function tipo(array $f): string
    {
        $t = strtoupper((string)($f['file_type'] ?? $f['file_type'] ?? ''));
        if ($t === 'MP4') return 'video';
        if ($t === 'M4A') return 'audio';
        return 'texto';
    }

    private static function extension(array $f): string
    {
        $ext = strtolower((string)($f['file_extension'] ?? ''));
        if ($ext !== '') return $ext;
        return match (strtoupper((string)($f['file_type'] ?? ''))) {
            'MP4' => 'mp4', 'M4A' => 'm4a', 'TRANSCRIPT', 'CC' => 'vtt', 'CHAT' => 'txt', 'CSV' => 'csv', 'TIMELINE', 'SUMMARY' => 'json', default => 'bin',
        };
    }

    /** «2026-10-14 09.00 Daily SIAU-UBE (pantalla y orador).mp4» */
    private static function nombre(string $inicio, string $tema, array $f, int $videos): string
    {
        $tema = trim(preg_replace('/[\\\\\/:*?"<>|\r\n\t]+/u', ' ', $tema));
        $tema = mb_substr(preg_replace('/\s+/u', ' ', $tema), 0, 110);
        $base = str_replace(':', '.', $inicio) . ' ' . $tema;
        $vistas = [
            'shared_screen_with_speaker_view' => 'pantalla y orador', 'shared_screen_with_speaker_view(CC)' => 'pantalla y orador',
            'shared_screen_with_gallery_view' => 'pantalla y galería', 'speaker_view' => 'orador', 'gallery_view' => 'galería',
            'shared_screen' => 'pantalla', 'active_speaker' => 'orador', 'audio_only' => 'audio',
        ];
        $sufijo = match (strtoupper((string)($f['file_type'] ?? ''))) {
            'MP4'        => $videos > 1 ? ' (' . ($vistas[$f['recording_type'] ?? ''] ?? 'video') . ')' : '',
            'M4A'        => '',
            'TRANSCRIPT' => ' - transcripción',
            'CC'         => ' - subtítulos',
            'CHAT'       => ' - chat',
            'CSV'        => ' - encuestas',
            'TIMELINE'   => ' - línea de tiempo',
            'SUMMARY'    => ' - resumen',
            default      => ' - ' . strtolower((string)($f['file_type'] ?? 'archivo')),
        };
        return $base . $sufijo . '.' . self::extension($f);
    }

    /* ======================= Clasificación por proyecto ======================= */

    /** Nombres de los proyectos del panel (las carpetas de primer nivel) */
    public static function proyectos(): array
    {
        $n = [];
        foreach ((new ProyectoRepo())->todos() as $p) {
            $nombre = trim((string)($p['nombre'] ?? ''));
            if ($nombre !== '') $n[(int)$p['id']] = $nombre;
        }
        return $n;
    }

    /** id de reunión de Zoom => ['proyecto' => id, 'tema' => nombre], de las reuniones creadas desde el panel */
    private static function reunionesPanel(): array
    {
        $r = [];
        foreach ((new JsonStore('reuniones'))->all() as $x) {
            if (!empty($x['zoom_id'])) $r[(string)$x['zoom_id']] = ['proyecto' => (int)($x['proyecto_id'] ?? 0), 'tema' => (string)($x['topic'] ?? '')];
        }
        return $r;
    }

    /**
     * Carpeta para una reunión: la que eligió el administrador para esa reunión;
     * si no, el proyecto de la reunión del panel (se sabe de dónde salió);
     * si no se creó desde el panel, «Otras».
     */
    public static function grupoSugerido(string $idReunion, array $proyectos, array $reuniones): string
    {
        $elegidos = (array)self::conf()['grupos'];
        if ($idReunion !== '' && !empty($elegidos[$idReunion])) return (string)$elegidos[$idReunion];
        $pid = (int)($reuniones[$idReunion]['proyecto'] ?? 0);
        if ($idReunion !== '' && $pid > 0 && isset($proyectos[$pid])) return $proyectos[$pid];
        return self::OTRAS;
    }

    /** El administrador cambia la carpeta de una reunión: vale para todas sus grabaciones que aún no se subieron */
    public static function cambiarGrupo(string $idReunion, string $grupo): int
    {
        $grupo = trim(preg_replace('/[\\\\\/:*?"<>|\r\n\t]+/u', ' ', $grupo));
        if ($idReunion === '' || $grupo === '') return 0;
        $elegidos = (array)self::conf()['grupos'];
        $elegidos[$idReunion] = $grupo;
        self::guardar(['grupos' => $elegidos]);
        $n = 0;
        foreach (self::tabla()->where('reunion', $idReunion) as $f) {
            if (in_array($f['estado'], ['nuevo', 'en_cola', 'error'], true)) { self::tabla()->update((int)$f['id'], ['grupo' => $grupo]); $n++; }
        }
        return $n;
    }

    /* ======================= Cola ======================= */

    /** Pone en cola las grabaciones (por uuid de reunión) */
    public static function encolar(array $uuids): int
    {
        $set = array_flip(array_map('strval', $uuids));
        $n = 0;
        foreach (self::tabla()->all() as $f) {
            if (isset($set[(string)$f['uuid']]) && in_array($f['estado'], ['nuevo', 'error'], true)) {
                self::tabla()->update((int)$f['id'], ['estado' => 'en_cola', 'error' => '', 'intentos' => 0, 'actualizado' => date('Y-m-d H:i')]);
                $n++;
            }
        }
        return $n;
    }

    public static function sacarDeCola(array $uuids): int
    {
        $set = array_flip(array_map('strval', $uuids));
        $n = 0;
        foreach (self::tabla()->all() as $f) {
            if (isset($set[(string)$f['uuid']]) && $f['estado'] === 'en_cola') {
                self::tabla()->update((int)$f['id'], ['estado' => 'nuevo']);
                $n++;
            }
        }
        return $n;
    }

    /* ======================= Drive: carpetas y archivos ======================= */

    private static function comillas(string $t): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $t);
    }

    /** Busca o crea una subcarpeta (se recuerda en la tabla zoom_drive_carpetas) */
    private static function carpeta(string $padre, string $nombre): string
    {
        $cache = new JsonStore('zoom_drive_carpetas');
        $clave = $padre . '/' . $nombre;
        foreach ($cache->where('clave', $clave) as $c) return (string)$c['drive_id'];
        $q = "name = '" . self::comillas($nombre) . "' and '" . self::comillas($padre) . "' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        [$codigo, $j] = self::drive('GET', self::url('drive') . '/files?q=' . rawurlencode($q) . '&fields=files(id,name)&supportsAllDrives=true&includeItemsFromAllDrives=true');
        if ($codigo !== 200) return '';
        $id = (string)($j['files'][0]['id'] ?? '');
        if ($id === '') {
            [$codigo, $j] = self::drive('POST', self::url('drive') . '/files?fields=id&supportsAllDrives=true',
                ['name' => $nombre, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => [$padre]]);
            if ($codigo !== 200 || empty($j['id'])) return '';
            $id = (string)$j['id'];
        }
        $cache->insert(['clave' => $clave, 'drive_id' => $id]);
        return $id;
    }

    /** Carpeta final de un archivo: Grupo / Videos|Audios|Textos / AAAA-MM */
    private static function carpetaDe(array $f): string
    {
        $raiz = self::conf()['carpeta'];
        $g = self::carpeta($raiz, (string)$f['grupo']);
        if ($g === '') return '';
        $t = self::carpeta($g, self::TIPOS_CARPETA[$f['tipo']] ?? 'Textos');
        if ($t === '') return '';
        return self::carpeta($t, substr((string)$f['inicio'], 0, 7));
    }

    private static function yaEnDrive(string $carpeta, string $nombre): ?array
    {
        $q = "name = '" . self::comillas($nombre) . "' and '" . self::comillas($carpeta) . "' in parents and trashed = false";
        [$codigo, $j] = self::drive('GET', self::url('drive') . '/files?q=' . rawurlencode($q) . '&fields=files(id,name,size)&supportsAllDrives=true&includeItemsFromAllDrives=true');
        return $codigo === 200 && !empty($j['files'][0]) ? $j['files'][0] : null;
    }

    private static function mime(string $ext): string
    {
        return ['mp4' => 'video/mp4', 'm4a' => 'audio/mp4', 'vtt' => 'text/vtt', 'txt' => 'text/plain', 'csv' => 'text/csv', 'json' => 'application/json'][$ext] ?? 'application/octet-stream';
    }

    /** Sube un archivo chico de una vez. Devuelve el id en Drive o '' */
    private static function subirDeUna(string $carpeta, string $nombre, string $mime, string $datos): array
    {
        $limite = 'zd' . bin2hex(random_bytes(8));
        $meta = json_encode(['name' => $nombre, 'parents' => [$carpeta]], JSON_UNESCAPED_UNICODE);
        $cuerpo = "--$limite\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n--$limite\r\nContent-Type: $mime\r\n\r\n$datos\r\n--$limite--";
        $t = self::tokenDrive();
        if ($t === '') return ['', 0];
        [$codigo, $r] = self::http('POST', self::url('subir') . '/files?uploadType=multipart&fields=id,size&supportsAllDrives=true', [
            'cabeceras' => ['Authorization: Bearer ' . $t, 'Content-Type: multipart/related; boundary=' . $limite], 'cuerpo' => $cuerpo, 'timeout' => 120,
        ]);
        $j = json_decode($r, true) ?: [];
        if ($codigo !== 200 || empty($j['id'])) { self::$ultimoError = 'Drive: ' . ($j['error']['message'] ?? ('HTTP ' . $codigo)); return ['', 0]; }
        return [(string)$j['id'], (int)($j['size'] ?? strlen($datos))];
    }

    /* ======================= Zoom: descargar y borrar ======================= */

    /**
     * Descarga [desde, desde+largo) de un archivo de Zoom; con $largo 0, el
     * archivo entero (solo para los chicos). Devuelve [bytes|null, ¿por partes?]
     */
    private static function bajar(string $url, int $desde, int $largo): array
    {
        $t = self::tokenZoom();
        if ($t === '') return [null, false];
        $u = $url . (str_contains($url, '?') ? '&' : '?') . 'access_token=' . rawurlencode($t);
        [$codigo, $datos] = self::http('GET', $u, [
            'cabeceras' => $largo > 0 ? ['Range: bytes=' . $desde . '-' . ($desde + $largo - 1)] : [],
            'seguir' => true, 'max' => ($largo > 0 ? $largo : self::DE_UNA * 2) + 1024, 'timeout' => 300,
        ]);
        if ($codigo === 206) return [substr($datos, 0, $largo), true];
        if ($codigo === 200 && $desde === 0) return [$largo > 0 ? substr($datos, 0, $largo) : $datos, false];
        self::$ultimoError = $codigo === 200
            ? 'Zoom no deja bajar este archivo por partes.'
            : 'Zoom no entregó el archivo (HTTP ' . $codigo . ($codigo === 401 || $codigo === 403 ? ': revisa el permiso recording:read:admin' : '') . ').';
        return [null, false];
    }

    /** Manda a la papelera de Zoom TODOS los archivos de esa reunión (30 días para recuperarla) */
    private static function aPapelera(string $uuid): bool
    {
        $enc = (str_starts_with($uuid, '/') || str_contains($uuid, '//')) ? rawurlencode(rawurlencode($uuid)) : rawurlencode($uuid);
        [$codigo] = self::zoom('DELETE', '/meetings/' . $enc . '/recordings?action=trash');
        return $codigo === 204 || $codigo === 200 || $codigo === 404;   // 404: ya no estaba
    }

    /* ======================= El trabajo ======================= */

    /**
     * Avanza la cola durante $segundos: sube por pedazos y, cuando una reunión
     * está entera en Drive, la manda a la papelera de Zoom. Un solo proceso a la
     * vez (candado). Devuelve el resumen.
     */
    public static function paso(int $segundos): array
    {
        $candado = @fopen(__DIR__ . '/../data/zoom_drive.lock', 'c');
        if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) {
            return ['ocupado' => true] + self::resumen();
        }
        try {
            if (!self::listo()) return ['error' => 'Falta conectar Zoom o Google Drive.'] + self::resumen();
            @set_time_limit($segundos + 120);
            $fin = microtime(true) + $segundos;
            while (microtime(true) < $fin) {
                $f = self::siguiente();
                if (!$f) break;
                self::avanzar($f, $fin);
            }
            self::liberarZoom();
        } finally {
            flock($candado, LOCK_UN);
            fclose($candado);
        }
        return self::resumen();
    }

    private static function siguiente(): ?array
    {
        $cola = array_filter(self::tabla()->all(), fn($f) => in_array($f['estado'], ['subiendo', 'en_cola'], true));
        if (!$cola) return null;
        usort($cola, fn($a, $b) => [$a['estado'] === 'subiendo' ? 0 : 1, $a['inicio'], $a['id']] <=> [$b['estado'] === 'subiendo' ? 0 : 1, $b['inicio'], $b['id']]);
        return reset($cola);
    }

    private static function fallo(array $f, string $msg): void
    {
        $n = (int)($f['intentos'] ?? 0) + 1;
        self::tabla()->update((int)$f['id'], [
            'intentos' => $n, 'error' => $msg, 'actualizado' => date('Y-m-d H:i'),
            // Tras 5 intentos queda en «error» (se reintenta desde la página); antes, sigue en su lugar
            'estado' => $n >= 5 ? 'error' : $f['estado'],
        ]);
    }

    /** Un archivo: lo sube entero (chico) o un pedazo más (grande) */
    private static function avanzar(array $f, float $fin): void
    {
        self::$ultimoError = '';
        $id = (int)$f['id'];
        $carpeta = self::carpetaDe($f);
        if ($carpeta === '') { self::fallo($f, self::$ultimoError ?: 'No se pudo crear la carpeta en Drive.'); return; }

        // ¿Ya está (una corrida anterior lo subió)? Mismo nombre y tamaño
        if ($f['estado'] === 'en_cola') {
            $ya = self::yaEnDrive($carpeta, (string)$f['nombre']);
            if ($ya && (int)($ya['size'] ?? -1) === (int)$f['tamano']) {
                self::tabla()->update($id, ['estado' => 'en_drive', 'drive_id' => (string)$ya['id'], 'subido' => (int)$f['tamano'], 'error' => '', 'actualizado' => date('Y-m-d H:i')]);
                return;
            }
        }

        $total = (int)$f['tamano'];
        // Chicos (los textos, y cualquier archivo de hasta 5 MB): de una sola vez
        if ($total <= self::DE_UNA) {
            [$datos] = self::bajar((string)$f['url'], 0, 0);
            if ($datos === null) { self::fallo($f, self::$ultimoError); return; }
            if ($total > 0 && strlen($datos) !== $total) {
                // Un texto puede venir con otro tamaño que el anunciado: vale lo que llegó.
                // Un video o un audio, no: se reintenta.
                if ($f['tipo'] !== 'texto' || $datos === '') { self::fallo($f, 'Zoom entregó ' . strlen($datos) . ' de ' . $total . ' bytes.'); return; }
                $total = strlen($datos);
                self::tabla()->update($id, ['tamano' => $total]);
            }
            [$did, $tam] = self::subirDeUna($carpeta, (string)$f['nombre'], self::mime((string)$f['ext']), $datos);
            if ($did === '') { self::fallo($f, self::$ultimoError); return; }
            // La transcripción, además, en texto corrido para leerla sin programas
            if ($f['file_type'] === 'TRANSCRIPT') {
                self::subirDeUna($carpeta, preg_replace('/\.vtt$/', '', (string)$f['nombre']) . '.txt', 'text/plain', self::vttATexto($datos));
            }
            self::tabla()->update($id, ['estado' => 'en_drive', 'drive_id' => $did, 'subido' => $total, 'error' => '', 'actualizado' => date('Y-m-d H:i')]);
            return;
        }

        // Grandes: subida reanudable, pedazo a pedazo
        $sesion = (string)$f['sesion'];
        $subido = (int)$f['subido'];
        if ($sesion === '') {
            [$codigo, , $cab] = self::drive('POST', self::url('subir') . '/files?uploadType=resumable&fields=id,size&supportsAllDrives=true',
                ['name' => $f['nombre'], 'parents' => [$carpeta]],
                ['X-Upload-Content-Type: ' . self::mime((string)$f['ext']), 'X-Upload-Content-Length: ' . $total]);
            $sesion = (string)($cab['location'] ?? '');
            if ($codigo !== 200 || $sesion === '') { self::fallo($f, self::$ultimoError ?: 'Drive no abrió la subida.'); return; }
            $subido = 0;
            self::tabla()->update($id, ['estado' => 'subiendo', 'sesion' => $sesion, 'subido' => 0, 'actualizado' => date('Y-m-d H:i')]);
        }
        while ($subido < $total && microtime(true) < $fin) {
            $largo = min(self::PEDAZO, $total - $subido);
            [$datos, $partes] = self::bajar((string)$f['url'], $subido, $largo);
            if ($datos === null || strlen($datos) !== $largo || ($subido > 0 && !$partes)) {
                self::fallo(array_replace($f, ['estado' => 'subiendo']), self::$ultimoError ?: 'Zoom no entregó el pedazo completo.');
                return;
            }
            $t = self::tokenDrive();
            if ($t === '') { self::fallo($f, self::$ultimoError); return; }
            [$codigo, $r, $cab] = self::http('PUT', $sesion, [
                'cabeceras' => ['Authorization: Bearer ' . $t, 'Content-Type: ' . self::mime((string)$f['ext']), 'Content-Length: ' . $largo,
                                'Content-Range: bytes ' . $subido . '-' . ($subido + $largo - 1) . '/' . $total, 'Expect:'],
                'cuerpo' => $datos, 'timeout' => 300,
            ]);
            unset($datos);
            if ($codigo === 308) {
                // Drive dice hasta dónde llegó (puede ser menos de lo enviado)
                $subido = preg_match('/bytes=0-(\d+)/', (string)($cab['range'] ?? ''), $m) ? (int)$m[1] + 1 : $subido;
                self::tabla()->update($id, ['subido' => $subido, 'actualizado' => date('Y-m-d H:i'), 'error' => '']);
                continue;
            }
            if ($codigo === 200 || $codigo === 201) {
                $j = json_decode($r, true) ?: [];
                $did = (string)($j['id'] ?? '');
                $tam = (int)($j['size'] ?? 0);
                if ($did !== '' && $tam === 0) {
                    [, $m2] = self::drive('GET', self::url('drive') . '/files/' . rawurlencode($did) . '?fields=size&supportsAllDrives=true');
                    $tam = (int)($m2['size'] ?? 0);
                }
                if ($did === '' || $tam !== $total) {
                    self::tabla()->update($id, ['sesion' => '', 'subido' => 0]);
                    self::fallo(array_replace($f, ['estado' => 'en_cola']), 'En Drive quedó con ' . $tam . ' de ' . $total . ' bytes: se vuelve a subir.');
                    return;
                }
                self::tabla()->update($id, ['estado' => 'en_drive', 'drive_id' => $did, 'subido' => $total, 'sesion' => '', 'error' => '', 'actualizado' => date('Y-m-d H:i')]);
                return;
            }
            if ($codigo === 404 || $codigo === 410) {
                // La sesión de Drive venció (dura una semana): se empieza de nuevo
                self::tabla()->update($id, ['sesion' => '', 'subido' => 0, 'estado' => 'en_cola']);
                return;
            }
            self::fallo(array_replace($f, ['estado' => 'subiendo']), 'Drive no recibió el pedazo (HTTP ' . $codigo . ').');
            return;
        }
    }

    /** Las reuniones que ya están enteras en Drive se mandan a la papelera de Zoom */
    private static function liberarZoom(): void
    {
        if (self::conf()['borrar'] !== 'papelera') return;
        $porReunion = [];
        foreach (self::tabla()->all() as $f) $porReunion[(string)$f['uuid']][] = $f;
        foreach ($porReunion as $uuid => $archivos) {
            $estados = array_unique(array_column($archivos, 'estado'));
            if ($uuid === '' || $estados !== ['en_drive']) continue;
            if (self::aPapelera($uuid)) {
                foreach ($archivos as $f) self::tabla()->update((int)$f['id'], ['estado' => 'borrado', 'borrado' => date('Y-m-d H:i'), 'error' => '']);
            } else {
                foreach ($archivos as $f) self::tabla()->update((int)$f['id'], ['error' => 'Está en Drive, pero Zoom no la dejó mandar a la papelera: ' . self::$ultimoError]);
            }
        }
    }

    private static function vttATexto(string $vtt): string
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $vtt) as $l) {
            $l = trim($l);
            if ($l === '' || $l === 'WEBVTT' || str_contains($l, '-->') || ctype_digit($l) || str_starts_with($l, 'NOTE ')) continue;
            $out[] = $l;
        }
        return implode("\n", $out) . "\n";
    }

    /* ======================= Para la página ======================= */

    /** Las grabaciones agrupadas: carpeta => [uuid => reunión con sus archivos] */
    public static function porGrupo(): array
    {
        $g = [];
        foreach (self::tabla()->all() as $f) {
            $r = &$g[(string)$f['grupo']][(string)$f['uuid']];
            $r['uuid'] = (string)$f['uuid'];
            $r['reunion'] = (string)$f['reunion'];
            $r['tema'] = (string)$f['tema'];
            $r['inicio'] = (string)$f['inicio'];
            $r['host'] = (string)$f['host'];
            $r['del_panel'] = !empty($f['del_panel']);
            $r['archivos'][] = $f;
            unset($r);
        }
        foreach ($g as &$reuniones) {
            uasort($reuniones, fn($a, $b) => strcmp($b['inicio'], $a['inicio']));
        }
        unset($reuniones);
        uksort($g, fn($a, $b) => [$a === self::OTRAS, $a] <=> [$b === self::OTRAS, $b]);   // «Otras» al final
        return $g;
    }

    /** Estado de una reunión según sus archivos */
    public static function estadoReunion(array $archivos): string
    {
        $e = array_unique(array_column($archivos, 'estado'));
        if (in_array('error', $e, true)) return 'error';
        if (in_array('subiendo', $e, true)) return 'subiendo';
        if (in_array('en_cola', $e, true)) return in_array('en_drive', $e, true) ? 'subiendo' : 'en_cola';
        if ($e === ['borrado']) return 'borrado';
        if (!array_diff($e, ['en_drive', 'borrado'])) return 'en_drive';
        return 'nuevo';
    }

    public static function resumen(): array
    {
        $r = ['zoom' => 0, 'zoom_bytes' => 0, 'cola' => 0, 'cola_bytes' => 0, 'subidos_bytes' => 0, 'drive' => 0, 'drive_bytes' => 0, 'liberado_bytes' => 0, 'errores' => 0, 'actual' => null];
        foreach (self::tabla()->all() as $f) {
            $b = (int)$f['tamano'];
            switch ($f['estado']) {
                case 'nuevo':    $r['zoom']++; $r['zoom_bytes'] += $b; break;
                case 'en_cola':  $r['cola']++; $r['cola_bytes'] += $b; break;
                case 'subiendo': $r['cola']++; $r['cola_bytes'] += $b; $r['subidos_bytes'] += (int)$f['subido'];
                                 $r['actual'] = ['nombre' => $f['nombre'], 'subido' => (int)$f['subido'], 'tamano' => $b]; break;
                case 'en_drive': $r['drive']++; $r['drive_bytes'] += $b; break;
                case 'borrado':  $r['drive']++; $r['drive_bytes'] += $b; $r['liberado_bytes'] += $b; break;
                case 'error':    $r['errores']++; break;
            }
        }
        return $r;
    }

    public static function bytes(int $b): string
    {
        if ($b >= 1073741824) return number_format($b / 1073741824, 1, ',', '.') . ' GB';
        if ($b >= 1048576) return number_format($b / 1048576, 0, ',', '.') . ' MB';
        if ($b >= 1024) return number_format($b / 1024, 0, ',', '.') . ' KB';
        return $b . ' B';
    }
}
