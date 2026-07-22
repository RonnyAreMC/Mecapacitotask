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
            'remitente' => 'Panel Mecapacito',
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

        $mime = implode("\r\n", [
            'From: =?UTF-8?B?' . base64_encode($c['remitente']) . '?= <' . $c['usuario'] . '>',
            'To: <' . $para . '>',
            'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            rtrim(chunk_split(base64_encode($html), 76, "\r\n")),
        ]);
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

            $mensaje = implode("\r\n", [
                'From: =?UTF-8?B?' . base64_encode($c['remitente']) . '?= <' . $c['usuario'] . '>',
                'To: <' . $para . '>',
                'Subject: =?UTF-8?B?' . base64_encode($asunto) . '?=',
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64',
                'Date: ' . date('r'),
                'Message-ID: <' . uniqid('meca', true) . '@mecapacito.panel>',
                '',
                rtrim(chunk_split(base64_encode($html), 76, "\r\n")),
            ]);
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
        $base = rtrim(self::conf()['url_panel'], '/');
        return $base !== '' ? $base . '/proyecto.php?id=' . $pid : '';
    }

    /** Envoltura HTML con el branding del panel (logo, título, colores de Ajustes). */
    private static function plantilla(string $cuerpo, string $urlBoton = '', string $textoBoton = ''): string
    {
        $m = Config::all();
        $titulo = e($m['titulo'] ?? 'Panel');
        $sub    = strtoupper(e($m['subtitulo'] ?? ''));
        $acento = e($m['color_secundario'] ?? '#2B76F7');
        // URL absoluta del logo (mismo dominio del panel, carpeta /assets)
        $raiz = preg_replace('#/admin/?$#', '', rtrim(self::conf()['url_panel'], '/'));
        $logo = $raiz !== '' ? $raiz . '/assets/mecapacito-logo.png' : '';

        $celdaLogo = $logo !== ''
            ? '<td width="52" style="padding-right:14px;vertical-align:middle;">
                 <img src="' . e($logo) . '" width="48" height="48" alt="' . $titulo . '"
                      style="display:block;width:48px;height:48px;border-radius:12px;background:#fff;padding:5px;box-sizing:border-box;">
               </td>'
            : '';

        $boton = $urlBoton !== ''
            ? '<table role="presentation" cellpadding="0" cellspacing="0" style="margin-top:22px;">
                 <tr><td style="border-radius:12px;background:' . $acento . ';">
                   <a href="' . e($urlBoton) . '" target="_blank"
                      style="display:inline-block;padding:13px 28px;color:#fff;text-decoration:none;font-size:14px;font-weight:bold;font-family:Arial,Helvetica,sans-serif;">'
                   . e($textoBoton) . '</a>
                 </td></tr>
               </table>'
            : '';

        return '
<div style="margin:0;padding:28px 16px;background:#e9edf4;font-family:Arial,Helvetica,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center">
  <table role="presentation" width="580" cellpadding="0" cellspacing="0" style="max-width:580px;width:100%;background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 18px 50px -20px rgba(15,23,42,.35);">
    <!-- Encabezado con logo y marca -->
    <tr><td style="background-color:#242c3a;background-image:linear-gradient(135deg,#2D3E50 0%,#1A4B99 70%,' . $acento . ' 130%);padding:22px 28px;">
      <table role="presentation" cellpadding="0" cellspacing="0"><tr>'
        . $celdaLogo .
        '<td style="vertical-align:middle;">
          <div style="color:#ffffff;font-size:22px;font-weight:800;letter-spacing:-.3px;">' . $titulo . '</div>'
          . ($sub ? '<div style="color:' . $acento . ';font-size:11px;font-weight:700;letter-spacing:2.5px;margin-top:2px;">' . $sub . '</div>' : '') . '
        </td>
      </tr></table>
    </td></tr>
    <!-- Franja de acento -->
    <tr><td style="height:4px;background:' . $acento . ';line-height:4px;font-size:0;">&nbsp;</td></tr>
    <!-- Cuerpo -->
    <tr><td style="padding:30px 30px 34px;color:#0f172a;">' . $cuerpo . $boton . '</td></tr>
    <!-- Pie -->
    <tr><td style="padding:18px 30px;background:#f6f8fb;border-top:1px solid #eef2f6;color:#94a3b8;font-size:11px;">
      Correo automático de <b style="color:#64748b;">' . $titulo . '</b> — no es necesario responder.
    </td></tr>
  </table>
  </td></tr></table>
</div>';
    }

    /** Tarjeta HTML de una tarea (reutilizada en asignación y recordatorio). */
    private static function cardTarea(array $tarea, array $proyecto): string
    {
        $prioridades = Catalogo::prioridades();
        $prioridad = $prioridades[$tarea['prioridad'] ?? '']['0'] ?? ($tarea['prioridad'] ?? '');
        $color = ProyectoRepo::colorBase($proyecto);
        return '<div style="border:1px solid #e2e8f0;border-left:5px solid ' . e($color) . ';border-radius:12px;padding:16px 18px;">'
            . '<p style="margin:0 0 6px;color:#0f172a;font-size:16px;font-weight:bold;">' . e($tarea['titulo']) . '</p>'
            . (!empty($tarea['descripcion']) ? '<p style="margin:0 0 12px;color:#475569;font-size:13px;line-height:1.5;">' . e($tarea['descripcion']) . '</p>' : '')
            . '<p style="margin:0;color:#64748b;font-size:12px;">📁 <b>' . e($proyecto['nombre']) . '</b> &nbsp;·&nbsp; Prioridad: <b>' . e($prioridad) . '</b>'
            . (!empty($tarea['fecha_limite']) ? ' &nbsp;·&nbsp; 📅 Límite: <b>' . e($tarea['fecha_limite']) . '</b>' : '') . '</p></div>';
    }

    /** Aviso de asignación de tarea (al asignado). */
    public static function notificarAsignacion(array $tarea, array $miembro, array $proyecto): true|string|null
    {
        $c = self::conf();
        if (!self::listo() || empty($c['avisar_asignacion']) || empty($miembro['email'])) {
            return null;
        }
        $cuerpo = '<p style="margin:0 0 6px;color:#0f172a;font-size:16px;">¡Hola <b>' . e($miembro['nombre']) . '</b>! 👋</p>'
            . '<p style="margin:0 0 18px;color:#475569;font-size:14px;">Te asignaron una nueva tarea:</p>'
            . self::cardTarea($tarea, $proyecto);
        return self::enviar($miembro['email'], '📋 Nueva tarea asignada: ' . $tarea['titulo'],
            self::plantilla($cuerpo, self::urlProyecto((int)$proyecto['id']), 'Ver el tablero →'));
    }

    /** Recordatorio de una tarea próxima a vencer (al asignado). */
    public static function recordatorioTarea(array $tarea, array $miembro, array $proyecto, int $dias): true|string|null
    {
        $c = self::conf();
        if (!self::listo() || empty($c['avisar_recordatorio']) || empty($miembro['email'])) {
            return null;
        }
        $cuando = $dias <= 0 ? 'vence <b>hoy</b>' : ('vence en <b>' . $dias . ' día' . ($dias === 1 ? '' : 's') . '</b>');
        $cuerpo = '<p style="margin:0 0 6px;color:#0f172a;font-size:16px;">Hola <b>' . e($miembro['nombre']) . '</b> ⏰</p>'
            . '<p style="margin:0 0 18px;color:#475569;font-size:14px;">Recordatorio: tu tarea ' . $cuando . '.</p>'
            . self::cardTarea($tarea, $proyecto);
        return self::enviar($miembro['email'], '⏰ Recordatorio: ' . $tarea['titulo'],
            self::plantilla($cuerpo, self::urlProyecto((int)$proyecto['id']), 'Ver el tablero →'));
    }

    /** Aviso de proyecto/fase concluida — SOLO al correo del administrador. */
    public static function notificarProyectoCompleto(array $proyecto, int $total): true|string|null
    {
        $c = self::conf();
        if (!self::listo() || empty($c['avisar_completado']) || empty($c['admin_email'])) {
            return null;
        }
        $cuerpo = '<p style="margin:0 0 6px;color:#0f172a;font-size:18px;">🎉 ¡Fase concluida!</p>'
            . '<p style="margin:0 0 18px;color:#475569;font-size:14px;">El proyecto <b>' . e($proyecto['nombre']) . '</b> completó <b>' . $total . ' de ' . $total . '</b> tareas. ¡Todo entregado!</p>'
            . '<div style="border:1px solid #e2e8f0;border-left:5px solid ' . e(ProyectoRepo::colorBase($proyecto)) . ';border-radius:12px;padding:16px 18px;">'
            . '<p style="margin:0;color:#0f172a;font-size:15px;font-weight:bold;">' . e($proyecto['nombre']) . '</p>'
            . '<p style="margin:6px 0 0;color:#64748b;font-size:12px;">✅ ' . $total . '/' . $total . ' tareas completadas</p></div>';
        return self::enviar($c['admin_email'], '✅ ' . $proyecto['nombre'] . ': fase concluida (' . $total . '/' . $total . ')',
            self::plantilla($cuerpo, self::urlProyecto((int)$proyecto['id']), 'Ver el proyecto →'));
    }
}
