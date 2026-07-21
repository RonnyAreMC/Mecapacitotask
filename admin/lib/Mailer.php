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
        ], (array)Config::get('correo'));
    }

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

    /**
     * Notifica por correo la asignacion de una tarea.
     * Devuelve true (enviado), string (error) o null (sin correo / desactivado).
     */
    public static function notificarAsignacion(array $tarea, array $miembro, array $proyecto): true|string|null
    {
        if (!self::listo() || empty($miembro['email'])) {
            return null;
        }
        $c = self::conf();
        $prioridades = Catalogo::prioridades();
        $prioridad = $prioridades[$tarea['prioridad'] ?? '']['0'] ?? ($tarea['prioridad'] ?? '');
        $colorProyecto = ProyectoRepo::colorBase($proyecto);
        $urlTablero = rtrim($c['url_panel'], '/');
        $urlTablero = $urlTablero !== '' ? $urlTablero . '/proyecto.php?id=' . (int)$proyecto['id'] : '';

        $html = '
<div style="margin:0;padding:24px;background:#eef1f7;font-family:Arial,Helvetica,sans-serif;">
  <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0;">
    <div style="background:#2D3E50;padding:18px 26px;">
      <span style="color:#ffffff;font-size:18px;font-weight:bold;">Mecapacito</span>
      <span style="color:#40CFFF;font-size:12px;letter-spacing:2px;"> · PANEL DEV</span>
    </div>
    <div style="padding:26px;">
      <p style="margin:0 0 6px;color:#0f172a;font-size:16px;">¡Hola <b>' . e($miembro['nombre']) . '</b>! 👋</p>
      <p style="margin:0 0 18px;color:#475569;font-size:14px;">Te asignaron una nueva tarea:</p>
      <div style="border:1px solid #e2e8f0;border-left:5px solid ' . e($colorProyecto) . ';border-radius:12px;padding:16px 18px;margin-bottom:18px;">
        <p style="margin:0 0 6px;color:#0f172a;font-size:16px;font-weight:bold;">' . e($tarea['titulo']) . '</p>'
        . (!empty($tarea['descripcion'])
            ? '<p style="margin:0 0 12px;color:#475569;font-size:13px;line-height:1.5;">' . e($tarea['descripcion']) . '</p>'
            : '') . '
        <p style="margin:0;color:#64748b;font-size:12px;">
          📁 <b>' . e($proyecto['nombre']) . '</b>
          &nbsp;·&nbsp; Prioridad: <b>' . e($prioridad) . '</b>'
        . (!empty($tarea['fecha_limite']) ? ' &nbsp;·&nbsp; 📅 Límite: <b>' . e($tarea['fecha_limite']) . '</b>' : '') . '
        </p>
      </div>'
      . ($urlTablero !== ''
          ? '<a href="' . e($urlTablero) . '" style="display:inline-block;background:#2B76F7;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:10px;font-size:14px;font-weight:bold;">Ver el tablero →</a>'
          : '') . '
    </div>
    <div style="padding:14px 26px;background:#f6f8fb;color:#94a3b8;font-size:11px;">
      Correo automático del panel Mecapacito — no es necesario responder.
    </div>
  </div>
</div>';

        return self::enviar($miembro['email'], '📋 Nueva tarea asignada: ' . $tarea['titulo'], $html);
    }
}
