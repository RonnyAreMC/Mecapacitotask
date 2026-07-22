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

    /** Ruta del logo a incrustar en los correos ('' si no existe). */
    private static function logoPath(): string
    {
        $p = __DIR__ . '/../../assets/mecapacito-logo.png';   // raíz del proyecto /assets
        return is_file($p) ? $p : '';
    }

    /**
     * Cuerpo MIME del correo. Si hay logo, arma un multipart/related con la
     * imagen incrustada (cid:logo) para que se vea sin depender de una URL.
     * Devuelve [ cabecerasContentType[], cuerpo ].
     */
    private static function cuerpoMime(string $html): array
    {
        $logo = self::logoPath();
        $htmlPart = rtrim(chunk_split(base64_encode($html), 76, "\r\n"));
        if ($logo === '') {
            return [['Content-Type: text/html; charset=UTF-8', 'Content-Transfer-Encoding: base64'], $htmlPart];
        }
        $b = 'mc_' . bin2hex(random_bytes(8));
        $img = rtrim(chunk_split(base64_encode((string)file_get_contents($logo)), 76, "\r\n"));
        $cuerpo = implode("\r\n", [
            '--' . $b,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            $htmlPart,
            '--' . $b,
            'Content-Type: image/png',
            'Content-Transfer-Encoding: base64',
            'Content-ID: <logo>',
            'Content-Disposition: inline; filename="logo.png"',
            '',
            $img,
            '--' . $b . '--',
            '',
        ]);
        return [['Content-Type: multipart/related; boundary="' . $b . '"'], $cuerpo];
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
        $base = rtrim(self::conf()['url_panel'], '/');
        return $base !== '' ? $base . '/proyecto.php?id=' . $pid : '';
    }

    /** Envoltura minimalista (estilo iOS): claro, sin degradados, tipografía del sistema. */
    private static function plantilla(string $cuerpo, string $urlBoton = '', string $textoBoton = ''): string
    {
        $m = Config::all();
        $titulo = e($m['titulo'] ?? 'Panel');
        $acento = e($m['color_secundario'] ?? '#2B76F7');
        $fuente = "-apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',Roboto,Helvetica,Arial,sans-serif";
        $logo = self::logoPath() !== ''
            ? '<td width="30" style="padding-right:10px;vertical-align:middle;">
                 <img src="cid:logo" width="26" height="26" alt="" style="display:block;width:26px;height:26px;border-radius:7px;">
               </td>'
            : '';
        $boton = $urlBoton !== ''
            ? '<a href="' . e($urlBoton) . '" target="_blank" style="display:inline-block;margin-top:22px;color:' . $acento . ';text-decoration:none;font-size:15px;font-weight:600;font-family:' . $fuente . ';">' . e($textoBoton) . ' &rsaquo;</a>'
            : '';

        return '<!DOCTYPE html>
<html lang="es"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title>' . $titulo . '</title>
<style>
  @media only screen and (max-width:480px) {
    .mc-wrap { padding:16px 10px !important; }
    .mc-card { border-radius:14px !important; }
    .mc-head { padding:14px 18px !important; }
    .mc-body { padding:22px 18px !important; }
    .mc-foot { padding:14px 18px !important; }
    .mc-det  { padding:14px 16px !important; }
    .mc-h1   { font-size:18px !important; }
    /* Las filas etiqueta/valor se apilan en pantallas chicas */
    .mc-lbl, .mc-val { display:block !important; width:100% !important; text-align:left !important; }
    .mc-lbl { padding:8px 0 0 !important; }
    .mc-val { padding:2px 0 0 !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;background:#f5f5f7;">
<div class="mc-wrap" style="margin:0;padding:36px 16px;background:#f5f5f7;font-family:' . $fuente . ';">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center">
  <table role="presentation" width="472" cellpadding="0" cellspacing="0" border="0" class="mc-card" style="max-width:472px;width:100%;background:#ffffff;border:1px solid #e5e5e7;border-radius:18px;">
    <tr><td class="mc-head" style="padding:18px 26px;border-bottom:1px solid #f0f0f2;">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
        . $logo .
        '<td style="vertical-align:middle;color:#1d1d1f;font-size:15px;font-weight:600;letter-spacing:-.2px;">' . $titulo . '</td>
      </tr></table>
    </td></tr>
    <tr><td class="mc-body" style="padding:28px 26px;">' . $cuerpo . $boton . '</td></tr>
    <tr><td class="mc-foot" style="padding:16px 26px;border-top:1px solid #f0f0f2;color:#a1a1a6;font-size:12px;">Notificación automática de ' . $titulo . '.</td></tr>
  </table>
  </td></tr></table>
</div>
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
