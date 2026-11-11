<?php
/**
 * Avisos de los requerimientos sueltos que comparten el panel (actions.php) y
 * la API (api.php): así marcarlo terminado desde el asistente de código avisa
 * exactamente igual que desde la bandeja.
 */

if (!function_exists('notificarReqTerminado')) {
    /**
     * Avisa de que un requerimiento se terminó.
     *
     * Van dos avisos, cada uno por su lado:
     *  - a quien lo creó/asignó, que es el que espera la respuesta;
     *  - al correo del administrador de Ajustes → Correo, con el mismo
     *    interruptor que los proyectos completados, para que tenga la foto de
     *    todo lo que se cierra aunque el requerimiento no lo pidiera él.
     * Si son el mismo correo solo sale uno. También queda en la campana del
     * creador y de los administradores. Devuelve la coletilla para el mensaje.
     */
    function notificarReqTerminado(array $req, array $quien, MiembroRepo $miembros, string $nota): string
    {
        if (class_exists('Notif')) {
            $admins = array_values(array_map(fn($m) => (int)$m['id'],
                array_filter($miembros->todos(), fn($m) => MiembroRepo::tieneAcceso($m, 'admin'))));
            Notif::crear(array_merge([(int)($req['creado_por'] ?? 0)], $admins), 'requerimiento',
                'Terminaron un requerimiento', '«' . ($req['titulo'] ?? '') . '» · ' . ($quien['nombre'] ?? ''), 'requerimientos.php');
        }
        $para    = '';
        $creador = $miembros->buscar((int)($req['creado_por'] ?? 0));
        if ($creador && !empty($creador['email'])) {
            $para = (string)$creador['email'];
        }
        if ($para === '') {
            $para = trim((string)(Mailer::config()['admin_email'] ?? ''));
        }

        $aQuienPidio = $para !== ''
            && Mailer::notificarRequerimientoHecho($req, $quien, $para, $nota) === true;
        $alAdmin = Mailer::avisarAdminRequerimientoHecho($req, $quien, $nota, $para) === true;
        // Quien da seguimiento (Felipe): lo terminado, con si llegó a tiempo (sin repetir a los de arriba)
        $alSeguimiento = class_exists('ReqSeguimiento')
            ? ReqSeguimiento::avisarTerminado($req, $quien, $nota, array_merge([$para], Mailer::listo() ? Mailer::destinatariosAdmin($para) : []))
            : 0;
        if ($alSeguimiento) return ' Avisamos por correo a quien lo asignó y a quien le da seguimiento.';

        if ($aQuienPidio && $alAdmin) return ' Avisamos por correo a quien lo asignó y al administrador.';
        if ($aQuienPidio)             return ' Le avisamos por correo a quien lo asignó.';
        if ($alAdmin)                 return ' Le avisamos por correo al administrador.';
        return '';
    }
}
