<?php
/**
 * Mailer - envio de correos por SMTP sin dependencias externas.
 * Habla SMTP directamente (STARTTLS en 587 o TLS implicito en 465),
 * pensado para Gmail con "contraseña de aplicación", pero sirve
 * con cualquier SMTP. La configuracion vive en Ajustes (Config 'correo').
 */
require_once __DIR__ . '/Models.php';

class Mailer
{
    private static function conf(): array
    {
        return array_merge([
            'activo'    => false,
            'modo'      => 'smtp',            // 'smtp' | 'gmail_api'
            'host'      => 'smtp.gmail.com',
            'puerto'    => 587,
            'usuario'   => '',
            'clave'     => '',
            'remitente' => 'InnoTech Hub',
            'url_panel' => '',
            // Modo gmail_api (OAuth de un proyecto de Google Cloud)
            'client_id'     => '',
            'client_secret' => '',
            'refresh_token' => '',
            // Qué notificar
            'avisar_asignacion'   => true,
            'avisar_recordatorio' => false,
            'dias_recordatorio'   => 3,
            'avisar_completado'   => false,
            'admin_email'         => '',
        ], (array)Config::get('correo'));
    }

    public static function config(): array { return self::conf(); }

    /** true si las notificaciones estan activas y configuradas. */
    public static function listo(): bool
    {
        $c = self::conf();
        if (empty($c['activo']) || $c['usuario'] === '') {
            return false;
        }
        if ($c['modo'] === 'gmail_api') {
            return $c['client_id'] !== '' && $c['client_secret'] !== '' && $c['refresh_token'] !== '';
        }
        return $c['clave'] !== '';
    }

    /**
     * Envia un correo HTML. Devuelve true si salio bien,
     * o un string con el motivo del fallo (para mostrarlo en un toast).
     */
    public static function enviar(string $para, string $asunto, string $html): true|string
    {
        if (self::conf()['modo'] === 'gmail_api') {
            return self::enviarGmailApi($para, $asunto, $html);
        }
        return self::enviarSmtp($para, $asunto, $html);
    }

    /** Ruta del logo a incrustar en los correos ('' si no existe). */
    private static function logoPath(): string
    {
        // Versión chica y liviana para el correo (el logo grande, 167 KB, se
        // veía "cargando"). Si no está, cae al logo normal.
        // Los clientes de correo NO renderizan SVG: siempre PNG. Preferimos el
        // logo de InnoTech (rasterizado del SVG) si existe.
        // En el correo va SOLO el ícono (sin el texto del wordmark), al lado de
        // la marca. Preferimos el ícono; si no, cae al wordmark o al logo base.

        // Antes que nada, el logo que se haya subido en Ajustes: es el de esta
        // instalación. Solo si es rasterizado; un SVG no se vería.
        $propio = trim((string)(Config::get('logo') ?? ''));
        if ($propio !== '' && preg_match('/\.(png|jpe?g|gif|webp)$/i', $propio)) {
            $ruta = __DIR__ . '/../' . $propio;
            if (is_file($ruta)) return $ruta;
        }

        $base = __DIR__ . '/../../assets/';
        foreach (['innotech-hub-icon-email.png', 'innotech-hub-logo-email.png', 'mecapacito-logo-email.png', 'mecapacito-logo.png'] as $f) {
            if (is_file($base . $f)) return $base . $f;
        }
        return '';
    }

    /**
     * Base del panel a partir de la URL de Ajustes, tolerante con lo que se
     * pega: sobra una barra final o incluso un archivo ("…/admin/index.php").
     * Si se deja el archivo, todo lo que se cuelgue detrás queda inservible:
     * "…/index.php/logo.php" acaba redirigido al panel y la imagen sale rota.
     */
    private static function baseUrl(): string
    {
        // Cuando el correo sale de un clic en el panel, la URL real del
        // navegador es más fiable que la escrita a mano en Ajustes: es la que
        // el administrador está usando ahora mismo y no puede estar mal.
        // Para los envíos por consola (recordatorios) no hay petición y se cae
        // a la de Ajustes.
        if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST']) && function_exists('rutaPanelBase')) {
            $esquema = (!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                ? 'https' : 'http';
            return $esquema . '://' . $_SERVER['HTTP_HOST'] . rutaPanelBase();
        }
        $base = trim((string)(self::conf()['url_panel'] ?? ''));
        $base = preg_replace('#/[^/]*\.php.*$#i', '', $base);
        return rtrim((string)$base, '/');
    }

    /** URL del logo tal como se pone en los correos (vacía si no se puede). */
    public static function logoUrlPublica(): string
    {
        return self::logoUrl();
    }

    /**
     * URL pública del logo para el correo ('' si no se puede construir).
     *
     * Se ENLAZA en vez de adjuntarse: incrustarlo con cid: dejaba el PNG como
     * archivo adjunto del mensaje. Hace falta la URL del panel (Ajustes →
     * Correo); sin ella no hay imagen y la plantilla usa la inicial de la marca.
     */
    private static function logoUrl(): string
    {
        $base = self::baseUrl();
        $file = self::logoPath();
        if ($base === '' || $file === '') {
            return '';
        }
        // Se sirve desde el propio panel (logo.php) en vez de deducir dónde
        // queda la carpeta assets: la estructura de carpetas cambia según cómo
        // esté desplegado y así el enlace no depende de adivinarla.
        // La fecha del archivo evita que un cliente sirva una versión vieja.
        return $base . '/logo.php?v=' . (@filemtime($file) ?: 1);
    }

    /** Archivo del logo, para que logo.php sirva el mismo que usa el correo. */
    public static function logoArchivo(): string
    {
        return self::logoPath();
    }

    /**
     * Mezcla un color con blanco. $t=0 es el color puro, $t=1 blanco.
     * Se usa para los tintes claros de fondos y bordes: da un hex sólido de
     * 6 dígitos, que Outlook sí entiende (el hex de 8 con alfa no siempre).
     */
    private static function tinte(string $hex, float $t): string
    {
        $hex = ltrim($hex, '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) return '#' . $hex;
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        $mix = fn($c) => (int)round($c + (255 - $c) * $t);
        return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
    }

    /**
     * Cuerpo MIME del correo: HTML y nada más.
     *
     * Antes armaba un multipart/related con el logo incrustado, pero eso deja
     * el PNG colgando como adjunto del mensaje (y algún cliente lo mostraba
     * roto). El logo ahora se enlaza por URL desde la plantilla.
     * Devuelve [ cabecerasContentType[], cuerpo ].
     */
    private static function cuerpoMime(string $html): array
    {
        return [
            ['Content-Type: text/html; charset=UTF-8', 'Content-Transfer-Encoding: base64'],
            rtrim(chunk_split(base64_encode($html), 76, "\r\n")),
        ];
    }

    /* ---------- Modo Gmail API (OAuth de Google Cloud) ---------- */

    /** POST JSON/form simple con streams; devuelve [codigoHttp, cuerpo]. */
    private static function httpPost(string $url, array|string $datos, array $cabeceras = []): array
    {
        $esJson = is_string($datos);
        $cabeceras[] = $esJson ? 'Content-Type: application/json' : 'Content-Type: application/x-www-form-urlencoded';
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $cabeceras),
            'content'       => $esJson ? $datos : http_build_query($datos),
            'timeout'       => 20,
            'ignore_errors' => true,   // queremos leer el cuerpo aunque sea 4xx
        ]]);
        $cuerpo = @file_get_contents($url, false, $ctx);
        $codigo = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $codigo = (int)$m[1];
        }
        return [$codigo, (string)$cuerpo];
    }

    /** Cambia el refresh token por un access token vigente. */
    private static function accessToken(array $c): string|array
    {
        [$codigo, $cuerpo] = self::httpPost('https://oauth2.googleapis.com/token', [
            'client_id'     => $c['client_id'],
            'client_secret' => $c['client_secret'],
            'refresh_token' => $c['refresh_token'],
            'grant_type'    => 'refresh_token',
        ]);
        $json = json_decode($cuerpo, true) ?: [];
        if ($codigo !== 200 || empty($json['access_token'])) {
            $motivo = $json['error_description'] ?? $json['error'] ?? ('HTTP ' . $codigo);
            return ['error' => 'No se pudo renovar el token de Google: ' . $motivo];
        }
        return $json['access_token'];
    }

    private static function enviarGmailApi(string $para, string $asunto, string $html): true|string
    {
        $c = self::conf();
        $token = self::accessToken($c);
        if (is_array($token)) {
            return $token['error'];
        }

        [$ctHeaders, $cuerpo] = self::cuerpoMime($html);
        $mime = implode("\r\n", array_merge([
            'From: =?UTF-8?B?' . base64_encode($c['remitente']) . '?= <' . $c['usuario'] . '>',
            'To: <' . $para . '>',
            'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
            'MIME-Version: 1.0',
        ], $ctHeaders, ['', $cuerpo]));
        $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

        [$codigo, $cuerpo] = self::httpPost(
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send',
            json_encode(['raw' => $raw]),
            ['Authorization: Bearer ' . $token]
        );
        if ($codigo === 200) {
            return true;
        }
        $json = json_decode($cuerpo, true) ?: [];
        return 'Gmail API rechazó el envío: ' . ($json['error']['message'] ?? ('HTTP ' . $codigo));
    }

    /* ---------- Modo SMTP clasico ---------- */

    private static function enviarSmtp(string $para, string $asunto, string $html): true|string
    {
        $c = self::conf();
        $host = $c['host'];
        $puerto = (int)$c['puerto'];
        $sslDirecto = $puerto === 465;

        $fp = @stream_socket_client(
            ($sslDirecto ? 'ssl://' : 'tcp://') . $host . ':' . $puerto,
            $errno, $errstr, 12
        );
        if (!$fp) {
            return "No se pudo conectar a $host:$puerto ($errstr)";
        }
        stream_set_timeout($fp, 15);

        $leer = function () use ($fp): string {
            $resp = '';
            while (($linea = fgets($fp, 515)) !== false) {
                $resp .= $linea;
                if (isset($linea[3]) && $linea[3] === ' ') break; // ultima linea "250 ..."
            }
            return $resp;
        };
        $mandar = function (string $cmd) use ($fp, $leer): string {
            fwrite($fp, $cmd . "\r\n");
            return $leer();
        };
        $ok = fn(string $resp, string $codigo) => str_starts_with($resp, $codigo);

        try {
            if (!$ok($leer(), '220')) return 'El servidor SMTP no respondió al conectar.';
            if (!$ok($r = $mandar('EHLO mecapacito.panel'), '250')) return 'Fallo EHLO: ' . trim($r);

            if (!$sslDirecto) {
                if (!$ok($r = $mandar('STARTTLS'), '220')) return 'Fallo STARTTLS: ' . trim($r);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    return 'No se pudo activar el cifrado TLS.';
                }
                if (!$ok($r = $mandar('EHLO mecapacito.panel'), '250')) return 'Fallo EHLO tras TLS: ' . trim($r);
            }

            if (!$ok($r = $mandar('AUTH LOGIN'), '334')) return 'El servidor no aceptó AUTH LOGIN: ' . trim($r);
            $mandar(base64_encode($c['usuario']));
            if (!$ok($r = $mandar(base64_encode($c['clave'])), '235')) {
                return 'Autenticación rechazada — revisa el usuario y la contraseña de aplicación. (' . trim($r) . ')';
            }

            if (!$ok($r = $mandar('MAIL FROM:<' . $c['usuario'] . '>'), '250')) return 'Fallo MAIL FROM: ' . trim($r);
            if (!$ok($r = $mandar('RCPT TO:<' . $para . '>'), '250'))         return 'Destinatario rechazado: ' . trim($r);
            if (!$ok($r = $mandar('DATA'), '354'))                             return 'Fallo DATA: ' . trim($r);

            [$ctHeaders, $cuerpo] = self::cuerpoMime($html);
            $mensaje = implode("\r\n", array_merge([
                'From: =?UTF-8?B?' . base64_encode($c['remitente']) . '?= <' . $c['usuario'] . '>',
                'To: <' . $para . '>',
                'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
                'MIME-Version: 1.0',
                'Date: ' . date('r'),
                'Message-ID: <' . uniqid('meca', true) . '@mecapacito.panel>',
            ], $ctHeaders, ['', $cuerpo]));
            if (!$ok($r = $mandar($mensaje . "\r\n."), '250')) return 'El servidor rechazó el mensaje: ' . trim($r);
            $mandar('QUIT');
            return true;
        } finally {
            fclose($fp);
        }
    }

    /** URL absoluta a un proyecto (o '' si no hay url_panel). */
    private static function urlProyecto(int $pid): string
    {
        $base = self::baseUrl();
        return $base !== '' ? $base . '/proyecto.php?id=' . $pid : '';
    }

    /** URL absoluta a una página del panel (o '' si no hay url_panel). */
    private static function urlPagina(string $pagina): string
    {
        $base = self::baseUrl();
        return $base !== '' ? $base . '/' . ltrim($pagina, '/') : '';
    }

    /**
     * Envoltura del correo. Diseño minimalista: tarjeta blanca con borde
     * sutil, cabecera con logo + chip, título con barra de acento, chip de
     * aviso automático, tarjeta institucional y footer con línea de acento.
     * El color se toma de la marca (Ajustes → color secundario).
     */
    private static function plantilla(string $cuerpo, string $urlBoton = '', string $textoBoton = ''): string
    {
        $m        = Config::all();
        $marca    = e($m['titulo'] ?? 'Panel');
        $sub      = e($m['subtitulo'] ?? 'Notificación');
        $pri      = preg_match('/^#[0-9a-fA-F]{6}$/', $m['color_secundario'] ?? '') ? $m['color_secundario'] : '#2B76F7';
        $priLight = self::tinte($pri, 0.38);
        $chipBg   = self::tinte($pri, 0.90);
        $chipBd   = self::tinte($pri, 0.74);
        $infoBg   = self::tinte($pri, 0.92);
        $anio     = date('Y');
        $fuente   = "Helvetica,Arial,sans-serif";

        // El logo se ENLAZA, no se adjunta: incrustarlo con cid: lo dejaba
        // colgando como archivo adjunto del correo (y varios clientes lo
        // mostraban roto). Sin URL pública no hay imagen: se usa la inicial.
        $logoSrc   = self::logoUrl();
        $tieneLogo = $logoSrc !== '';
        // Solo el ícono (cuadrado), en su pastilla, al lado de la marca.
        $logoHead = $tieneLogo
            ? '<td valign="middle" width="52" style="padding:0 14px 0 0;">
                 <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                   <td align="center" valign="middle" width="44" height="44" style="width:44px;height:44px;background:#f9fafb;border:1px solid #ececec;border-radius:11px;text-align:center;">
                     <img src="' . e($logoSrc) . '" alt="' . $marca . '" width="30" height="30" style="display:inline-block;vertical-align:middle;border:0;width:30px;height:30px;">
                   </td>
                 </tr></table>
               </td>'
            : '';

        // Botón CTA: píldora sólida con el color de acento
        $boton = $urlBoton !== ''
            ? '<div style="text-align:center;margin-top:24px;">
                 <a href="' . e($urlBoton) . '" target="_blank" style="display:inline-block;background:' . $pri . ';color:#ffffff;text-decoration:none;font-family:' . $fuente . ';font-size:14px;font-weight:700;padding:12px 26px;border-radius:10px;letter-spacing:.2px;">' . e($textoBoton) . ' &rsaquo;</a>
               </div>'
            : '';

        // Tarjeta institucional del pie (logo enmarcado + marca)
        $logoPie = $tieneLogo
            ? '<td valign="middle" style="padding:22px 0 22px 22px;">
                 <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                   <td style="background:linear-gradient(135deg,' . $pri . ',' . $priLight . ');padding:3px;border-radius:16px;">
                     <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
                       <td style="background:#ffffff;border-radius:13px;padding:9px;">
                         <img src="' . e($logoSrc) . '" alt="' . $marca . '" width="64" height="64" style="display:block;border:0;width:64px;height:64px;border-radius:8px;">
                       </td>
                     </tr></table>
                   </td>
                 </tr></table>
               </td>'
            : '';

        return '<!DOCTYPE html>
<html lang="es"><head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title>' . $marca . '</title>
<style>
  @media only screen and (max-width:480px) {
    .mc-pad  { padding-left:18px !important; padding-right:18px !important; }
    .mc-h1   { font-size:19px !important; }
    .mc-lbl, .mc-val { display:block !important; width:100% !important; text-align:left !important; }
    .mc-lbl { padding:8px 0 0 !important; } .mc-val { padding:2px 0 0 !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#fafafa;font-family:' . $fuente . ';-webkit-text-size-adjust:100%;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#fafafa;"><tr>
<td align="center" style="padding:24px 8px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;">

  <!-- Tarjeta principal -->
  <tr><td style="padding:0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border:1px solid #ececec;border-radius:18px;border-collapse:separate;overflow:hidden;">

      <!-- Cabecera: logo + marca + chip -->
      <tr><td class="mc-pad" style="padding:22px 28px 14px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
          . $logoHead .
          '<td valign="middle">
            <div style="font-size:9.5px;text-transform:uppercase;letter-spacing:2.4px;color:#9ca3af;font-weight:700;margin-bottom:2px;">' . $sub . '</div>
            <div style="font-size:14px;color:#111827;font-weight:800;letter-spacing:-.005em;line-height:1.2;">' . $marca . '</div>
          </td>
          <td valign="middle" align="right" style="white-space:nowrap;">
            <span style="display:inline-block;padding:5px 11px;font-size:9.5px;font-weight:800;letter-spacing:2px;text-transform:uppercase;color:' . $pri . ';background:' . $chipBg . ';border:1px solid ' . $chipBd . ';border-radius:999px;">Notificación</span>
          </td>
        </tr></table>
      </td></tr>

      <!-- Puntos + línea -->
      <tr><td class="mc-pad" style="padding:0 28px 4px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
          <td valign="middle">
            <span style="display:inline-block;width:6px;height:6px;background:' . $pri . ';border-radius:50%;margin-right:5px;"></span>
            <span style="display:inline-block;width:5px;height:5px;background:' . $priLight . ';border-radius:50%;margin-right:5px;"></span>
            <span style="display:inline-block;width:4px;height:4px;background:' . $chipBd . ';border-radius:50%;"></span>
          </td>
          <td valign="middle" align="right">
            <div style="display:inline-block;width:64px;height:2px;background:' . $pri . ';border-radius:2px;"></div>
          </td>
        </tr></table>
      </td></tr>

      <!-- Cuerpo (con barra de acento a la izquierda) -->
      <tr><td class="mc-pad" style="padding:18px 28px 24px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
          <td valign="top" width="4" style="padding-top:4px;"><div style="width:4px;background:' . $pri . ';border-radius:4px;height:40px;"></div></td>
          <td valign="top" style="padding-left:16px;">' . $cuerpo . $boton . '</td>
        </tr></table>
      </td></tr>

    </table>
  </td></tr>

  <!-- Chip de aviso automático -->
  <tr><td style="padding:14px 0 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border:1px solid #ececec;border-radius:14px;border-collapse:separate;"><tr>
      <td valign="top" width="44" style="padding:14px 0 14px 16px;">
        <div style="width:28px;height:28px;background:' . $infoBg . ';border-radius:50%;text-align:center;line-height:28px;color:' . $pri . ';font-size:13px;font-weight:800;">i</div>
      </td>
      <td style="padding:14px 18px 14px 12px;font-size:12.5px;line-height:1.6;color:#6b7280;">Mensaje automático de <b>' . $marca . '</b>. No hace falta responder a este correo.</td>
    </tr></table>
  </td></tr>

  <!-- Tarjeta institucional -->
  <tr><td style="padding:14px 0 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border:1px solid #ececec;border-radius:18px;border-collapse:separate;overflow:hidden;"><tr>
      <td style="padding:0;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
          . $logoPie .
          '<td valign="middle" style="padding:22px 24px;">
            <div style="font-size:9.5px;text-transform:uppercase;letter-spacing:2px;color:' . $priLight . ';font-weight:700;margin-bottom:6px;">' . $sub . '</div>
            <div style="font-size:17px;color:#111827;font-weight:800;letter-spacing:-.01em;line-height:1.25;">' . $marca . '</div>
          </td>
        </tr></table>
      </td>
    </tr></table>
  </td></tr>

  <!-- Footer -->
  <tr><td style="padding:14px 0 0;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr><td style="padding:0 0 8px;line-height:0;font-size:0;"><div style="height:3px;background:linear-gradient(90deg,' . $pri . ' 0%,' . $priLight . ' 100%);border-radius:2px;"></div></td></tr>
      <tr><td align="center" style="padding:6px 8px 4px;">
        <span style="font-size:11px;color:#6b7280;font-weight:600;">' . $marca . '</span>
        <span style="font-size:11px;color:#d1d5db;margin:0 6px;">|</span>
        <span style="font-size:11px;color:#6b7280;">Todos los derechos reservados</span>
        <span style="font-size:11px;color:#d1d5db;margin:0 6px;">|</span>
        <span style="font-size:11px;color:' . $pri . ';font-weight:800;letter-spacing:.5px;">' . $anio . '</span>
      </td></tr>
    </table>
  </td></tr>

  <tr><td style="height:18px;font-size:0;line-height:0;">&nbsp;</td></tr>
</table>
</td></tr></table>
</body></html>';
    }

    /** Encabezado: icono pequeño en círculo + título + descripción. */
    private static function encabezado(string $color, string $glifo, string $titulo, string $sub): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td width="34" style="padding-right:12px;vertical-align:middle;">'
            . '<div style="width:24px;height:24px;background:' . $color . ';border-radius:50%;color:#fff;font-size:13px;font-weight:700;text-align:center;line-height:24px;">' . $glifo . '</div>'
            . '</td>'
            . '<td class="mc-h1" style="vertical-align:middle;color:#1d1d1f;font-size:19px;font-weight:700;letter-spacing:-.3px;">' . $titulo . '</td>'
            . '</tr></table>'
            . '<div style="color:#6e6e73;font-size:15px;line-height:1.5;margin-top:12px;">' . $sub . '</div>';
    }

    /** Caja de detalle minimalista con filas etiqueta / valor. */
    private static function detalle(string $titulo, array $filas, string $desc = ''): string
    {
        $rows = '';
        foreach ($filas as $k => $v) {
            $rows .= '<tr>'
                . '<td class="mc-lbl" style="padding:5px 0;color:#86868b;font-size:13px;">' . e($k) . '</td>'
                . '<td class="mc-val" style="padding:5px 0;color:#1d1d1f;font-size:13px;font-weight:600;text-align:right;">' . $v . '</td>'
                . '</tr>';
        }
        return '<div class="mc-det" style="margin-top:18px;background:#f5f5f7;border-radius:14px;padding:16px 18px;">'
            . '<div style="color:#1d1d1f;font-size:16px;font-weight:600;letter-spacing:-.2px;">' . e($titulo) . '</div>'
            . ($desc !== '' ? '<div style="color:#6e6e73;font-size:14px;line-height:1.5;margin-top:6px;">' . e($desc) . '</div>' : '')
            . ($rows !== '' ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:10px;">' . $rows . '</table>' : '')
            . '</div>';
    }

    private static function filasTarea(array $tarea, array $proyecto): array
    {
        $prioridades = Catalogo::prioridades();
        $prioridad = $prioridades[$tarea['prioridad'] ?? '']['0'] ?? ($tarea['prioridad'] ?? '');
        $filas = ['Proyecto' => e($proyecto['nombre']), 'Prioridad' => e($prioridad)];
        if (!empty($tarea['fecha_limite'])) $filas['Fecha límite'] = e($tarea['fecha_limite']);
        return $filas;
    }

    /** Aviso de asignación de tarea (al asignado). */
    public static function notificarAsignacion(array $tarea, array $miembro, array $proyecto): true|string|null
    {
        $c = self::conf();
        if (!self::listo() || empty($c['avisar_asignacion']) || empty($miembro['email'])) {
            return null;
        }
        $acento = Config::all()['color_secundario'] ?? '#2B76F7';
        $cuerpo = self::encabezado($acento, '&#43;', 'Nueva tarea asignada',
                    'Hola ' . e($miembro['nombre']) . ', se te asignó una tarea en ' . e($proyecto['nombre']) . '.')
            . self::detalle($tarea['titulo'], self::filasTarea($tarea, $proyecto), $tarea['descripcion'] ?? '');
        return self::enviar($miembro['email'], 'Nueva tarea asignada: ' . $tarea['titulo'],
            self::plantilla($cuerpo, self::urlProyecto((int)$proyecto['id']), 'Ver la tarea'));
    }

    /**
     * Resumen de TODAS las tareas que alguien tiene en un proyecto, en un solo
     * correo. Es lo que se manda tras cargar una planificación en lote: avisar
     * tarea por tarea sería una lluvia de correos por la misma noticia.
     *
     * A diferencia del aviso de asignación, este no depende de
     * 'avisar_asignacion': lo dispara un administrador a mano.
     */
    public static function resumenTareas(array $miembro, array $proyecto, array $tareas, string $nota = ''): true|string|null
    {
        if (!self::listo() || empty($miembro['email']) || !$tareas) {
            return null;
        }
        return self::enviar($miembro['email'],
            'Tus tareas en ' . $proyecto['nombre'] . ' (' . count($tareas) . ')',
            self::htmlResumen($miembro, $proyecto, $tareas, $nota));
    }

    /** HTML del correo de resumen (aparte, para poder revisarlo sin enviarlo). */
    private static function htmlResumen(array $miembro, array $proyecto, array $tareas, string $nota = ''): string
    {
        $acento = Config::all()['color_secundario'] ?? '#2B76F7';
        $n = count($tareas);
        $prioridades = Catalogo::prioridades();

        $filas = '';
        foreach ($tareas as $t) {
            $prio = $prioridades[$t['prioridad'] ?? '']['0'] ?? ($t['prioridad'] ?? '');
            $meta = [];
            if ($prio !== '')                 $meta[] = 'Prioridad ' . e($prio);
            if (!empty($t['fecha_limite']))   $meta[] = 'Hasta el ' . e($t['fecha_limite']);
            elseif (!empty($t['fecha_inicio'])) $meta[] = 'Desde el ' . e($t['fecha_inicio']);
            $filas .= '<tr><td style="padding:9px 0;border-top:1px solid #e5e5ea;">'
                . '<div style="color:#1d1d1f;font-size:14px;font-weight:600;line-height:1.4;">' . e($t['titulo'] ?? '') . '</div>'
                . ($meta ? '<div style="color:#86868b;font-size:12px;margin-top:3px;">' . implode(' · ', $meta) . '</div>' : '')
                . '</td></tr>';
        }

        $cuerpo = self::encabezado($acento, '&#9776;', 'Tus tareas en ' . e($proyecto['nombre']),
                    'Hola ' . e($miembro['nombre']) . ', esto es lo que quedó a tu nombre en la planificación: '
                    . $n . ($n === 1 ? ' tarea.' : ' tareas.'))
            // Nota que escribe quien envía, tal cual, antes de la lista
            . ($nota !== ''
                ? '<div style="margin-top:16px;padding:12px 16px;border-left:3px solid ' . $acento . ';background:#fbfbfd;'
                  . 'color:#1d1d1f;font-size:14px;line-height:1.55;">' . nl2br(e($nota)) . '</div>'
                : '')
            . '<div class="mc-det" style="margin-top:18px;background:#f5f5f7;border-radius:14px;padding:6px 18px 14px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">' . $filas . '</table>'
            . '</div>'
            . '<div style="color:#86868b;font-size:13px;line-height:1.5;margin-top:14px;">'
            . 'El detalle de cada una (descripción, dependencias y documentos de respaldo) está en el panel.'
            . '</div>';

        return self::plantilla($cuerpo, self::urlProyecto((int)$proyecto['id']), 'Ver mis tareas');
    }

    /** Aviso de entrada al equipo de un proyecto (a quien se acaba de sumar). */
    public static function notificarEquipoProyecto(array $miembro, array $proyecto): true|string|null
    {
        $c = self::conf();
        if (!self::listo() || empty($c['avisar_proyecto']) || empty($miembro['email'])) {
            return null;
        }
        $acento = Config::all()['color_secundario'] ?? '#2B76F7';
        $filas = ['Proyecto' => e($proyecto['nombre'])];
        if (!empty($proyecto['fecha_inicio'])) $filas['Inicia'] = e($proyecto['fecha_inicio']);
        $cuerpo = self::encabezado($acento, '&#9679;', 'Te sumaron a un proyecto',
                    'Hola ' . e($miembro['nombre']) . ', ya formas parte del equipo de ' . e($proyecto['nombre']) . '.')
            . self::detalle($proyecto['nombre'], $filas, $proyecto['descripcion'] ?? '');
        return self::enviar($miembro['email'], 'Ahora participas en ' . $proyecto['nombre'],
            self::plantilla($cuerpo, self::urlProyecto((int)$proyecto['id']), 'Ver el proyecto'));
    }

    /* ---------- Registro público ---------- */

    /**
     * Avisa a un administrador de que alguien pidió acceso al panel.
     * No depende de los interruptores de "avisar_*": es un aviso de
     * seguridad, se rige por Ajustes → Registro.
     */
    public static function solicitudNueva(array $solicitud, string $paraEmail): true|string|null
    {
        if (!self::listo() || trim($paraEmail) === '') {
            return null;
        }
        $acento = Config::all()['color_secundario'] ?? '#2B76F7';
        $filas = [
            'Correo'     => e($solicitud['email'] ?? ''),
            'Solicitado' => e($solicitud['creado'] ?? date('Y-m-d H:i')),
        ];

        $cuerpo = self::encabezado($acento, '&#9679;', 'Alguien pide acceso al panel',
                    '<b>' . e($solicitud['nombre'] ?? '') . '</b> pidió acceso con su cuenta de Google '
                    . '(correo ya verificado) y espera tu aprobación. Hasta que la apruebes no ve nada '
                    . 'del panel. Al aprobarla eliges tú su equipo y su rol.')
            . self::detalle($solicitud['nombre'] ?? '', $filas);

        return self::enviar($paraEmail, 'Solicitud de acceso: ' . ($solicitud['nombre'] ?? ''),
            self::plantilla($cuerpo, self::urlPagina('equipo.php'), 'Revisar la solicitud'));
    }

    /** Avisa a la persona de que ya puede entrar. */
    public static function solicitudAprobada(array $miembro): true|string|null
    {
        if (!self::listo() || empty($miembro['email'])) {
            return null;
        }
        $acento = Config::all()['color_secundario'] ?? '#2BB673';
        $rol = ($miembro['acceso'] ?? 'lector') === 'admin' ? 'Administrador' : 'Solo lectura';
        $cuerpo = self::encabezado($acento, '&#10003;', 'Tu acceso está aprobado',
                    'Hola ' . e(explode(' ', trim($miembro['nombre'] ?? ''))[0] ?: '') . ', ya puedes entrar al panel '
                    . 'con el botón «Continuar con Google», usando la misma cuenta con la que te registraste.')
            . self::detalle('Tu cuenta', [
                'Correo' => e($miembro['email']),
                'Equipo' => e(Catalogo::equipos()[MiembroRepo::equipoDe($miembro)][0] ?? ''),
                'Rol'    => e($miembro['rol'] ?? ''),
                'Perfil' => e($rol),
            ])
            . '<div style="color:#86868b;font-size:13px;line-height:1.5;margin-top:14px;">'
            . 'Cuando entres, completa tu foto y tu usuario de Git en <b>Mi perfil</b>: con el usuario de Git '
            . 'tus commits aparecen en las métricas de cada proyecto.'
            . '</div>';
        return self::enviar($miembro['email'], 'Ya tienes acceso al panel',
            self::plantilla($cuerpo, self::urlPagina('login.php'), 'Entrar al panel'));
    }

    /** Avisa de que la solicitud no fue aceptada (con el motivo, si lo hay). */
    public static function solicitudRechazada(array $solicitud, string $motivo = ''): true|string|null
    {
        if (!self::listo() || empty($solicitud['email'])) {
            return null;
        }
        $cuerpo = self::encabezado('#86868b', '&#8212;', 'Tu solicitud no fue aprobada',
                    'Hola ' . e(explode(' ', trim($solicitud['nombre'] ?? ''))[0] ?: '') . ', por ahora no se te dio '
                    . 'acceso al panel. Si crees que es un error, responde a este correo.')
            . ($motivo !== '' ? self::detalle('Motivo', [], $motivo) : '');
        return self::enviar($solicitud['email'], 'Sobre tu solicitud de acceso', self::plantilla($cuerpo));
    }

    /** Invitación a una reunión de Zoom (a cada invitado). */
    public static function notificarReunion(array $reunion, array $miembro, array $proyecto): true|string|null
    {
        if (!self::listo() || empty($miembro['email'])) {
            return null;
        }
        $acento = Config::all()['color_secundario'] ?? '#2B76F7';
        $filas = [
            'Proyecto' => e($proyecto['nombre']),
            'Cuándo'   => e($reunion['inicio'] ?? ''),
        ];
        // Si se repite, la fecha suelta engaña: hay que decir los días y hasta cuándo.
        if (Reuniones::esRecurrente($reunion)) {
            $filas['Cuándo']  = e(Reuniones::etiqueta((array)$reunion['dias'])
                                  . ' a las ' . substr((string)$reunion['inicio'], 11, 5));
            $filas['Desde']   = e(substr((string)$reunion['inicio'], 0, 10));
            $filas['Se repite hasta'] = e((string)$reunion['hasta']);
        }
        if (!empty($reunion['duracion'])) $filas['Duración'] = (int)$reunion['duracion'] . ' min';
        if (!empty($reunion['password'])) $filas['Código'] = e($reunion['password']);
        $cuerpo = self::encabezado($acento, '&#9658;', 'Te invitaron a una reunión',
                    'Hola ' . e($miembro['nombre']) . ', tienes una reunión de ' . e($proyecto['nombre']) . '.')
            . self::detalle($reunion['topic'] ?? 'Reunión', $filas);
        return self::enviar($miembro['email'], 'Reunión: ' . ($reunion['topic'] ?? ''),
            self::plantilla($cuerpo, $reunion['join_url'] ?? '', 'Entrar a la reunión'));
    }

    /**
     * Propuesta de intercambio de tareas (a quien la recibe).
     * $de y $para son miembros; $tareaDe es la del proponente.
     */
    public static function notificarIntercambio(array $intercambio, array $de, array $para, array $tareaDe, array $tareaPara, array $proyecto): true|string|null
    {
        $c = self::conf();
        if (!self::listo() || empty($c['avisar_intercambio']) || empty($para['email'])) {
            return null;
        }
        $motivo = Catalogo::MOTIVOS_INTERCAMBIO[$intercambio['motivo']][0]
            ?? ($intercambio['motivo'] ?? '');
        $cuerpo = self::encabezado('#ff9500', '&#8646;', 'Te proponen un intercambio',
                    e($de['nombre']) . ' quiere intercambiar tareas contigo en ' . e($proyecto['nombre']) . '.')
            . self::detalle('Qué se intercambia', [
                'Pasarías a llevar' => e($tareaDe['titulo']),
                'Y ' . e(explode(' ', $de['nombre'])[0]) . ' tomaría' => e($tareaPara['titulo']),
                'Motivo'            => e($motivo),
            ], $intercambio['nota'] ?? '')
            . '<div style="color:#6e6e73;font-size:14px;line-height:1.5;margin-top:16px;">'
            . 'Nada cambia hasta que lo aceptes desde el panel.</div>';
        return self::enviar($para['email'], $de['nombre'] . ' quiere intercambiar tareas contigo',
            self::plantilla($cuerpo, self::urlProyecto((int)$proyecto['id']), 'Ver la propuesta'));
    }

    /** Respuesta a la propuesta (a quien la hizo). */
    public static function notificarRespuestaIntercambio(array $intercambio, array $quienResponde, array $destino, array $proyecto, bool $aceptado): true|string|null
    {
        $c = self::conf();
        if (!self::listo() || empty($c['avisar_intercambio']) || empty($destino['email'])) {
            return null;
        }
        $cuerpo = $aceptado
            ? self::encabezado('#34c759', '&#10003;', 'Intercambio aceptado',
                e($quienResponde['nombre']) . ' aceptó el intercambio. Las tareas ya cambiaron de responsable.')
            : self::encabezado('#ff3b30', '&#10005;', 'Intercambio rechazado',
                e($quienResponde['nombre']) . ' no pudo aceptar el intercambio. Todo sigue como estaba.');
        $cuerpo .= self::detalle($proyecto['nombre'], [
            'Estado' => $aceptado ? '<span style="color:#34c759;">Aceptado</span>' : '<span style="color:#ff3b30;">Rechazado</span>',
        ], $intercambio['respuesta'] ?? '');
        return self::enviar($destino['email'],
            ($aceptado ? 'Aceptado' : 'Rechazado') . ': tu intercambio en ' . $proyecto['nombre'],
            self::plantilla($cuerpo, self::urlProyecto((int)$proyecto['id']), 'Ver el proyecto'));
    }

    /** Recordatorio de una tarea próxima a vencer (al asignado). */
    public static function recordatorioTarea(array $tarea, array $miembro, array $proyecto, int $dias): true|string|null
    {
        $c = self::conf();
        if (!self::listo() || empty($c['avisar_recordatorio']) || empty($miembro['email'])) {
            return null;
        }
        $cuando = $dias <= 0 ? 'vence hoy' : ('vence en ' . $dias . ' día' . ($dias === 1 ? '' : 's'));
        $cuerpo = self::encabezado('#ff9500', '!', 'Tarea por vencer',
                    'Hola ' . e($miembro['nombre']) . ', tu tarea ' . $cuando . '.')
            . self::detalle($tarea['titulo'], self::filasTarea($tarea, $proyecto), $tarea['descripcion'] ?? '');
        return self::enviar($miembro['email'], 'Recordatorio: ' . $tarea['titulo'],
            self::plantilla($cuerpo, self::urlProyecto((int)$proyecto['id']), 'Ver la tarea'));
    }

    /** Aviso de proyecto/fase concluida — SOLO al correo del administrador. */
    public static function notificarProyectoCompleto(array $proyecto, int $total): true|string|null
    {
        $c = self::conf();
        if (!self::listo() || empty($c['avisar_completado']) || empty($c['admin_email'])) {
            return null;
        }
        $cuerpo = self::encabezado('#34c759', '&#10003;', 'Proyecto completado',
                    'Todas las tareas de ' . e($proyecto['nombre']) . ' ya se entregaron.')
            . self::detalle($proyecto['nombre'], [
                'Estado'  => '<span style="color:#34c759;">Completado</span>',
                'Tareas'  => $total . ' de ' . $total,
            ]);
        return self::enviar($c['admin_email'], $proyecto['nombre'] . ' — proyecto completado',
            self::plantilla($cuerpo, self::urlProyecto((int)$proyecto['id']), 'Ver el proyecto'));
    }
}
