<?php
/**
 * WhatsApp del panel: cliente de la pasarela Baileys (servicios/whatsapp) y la
 * cola de mensajes.
 *
 * Encendido (Ajustes → Reuniones → WhatsApp), las reuniones avisan por WhatsApp
 * en lugar del correo —la campana del panel avisa igual— y el código para
 * recuperar la contraseña llega por WhatsApp. A quien no tenga un teléfono
 * válido le sigue llegando por correo. Apagado, todo va por correo.
 *
 * Los avisos de reuniones van a una cola (tabla whatsapp_cola) que manda
 * cron_whatsapp.php cada minuto, dejando 3 s entre mensajes: crear una reunión
 * no espera a WhatsApp. El código de recuperación sale en el momento.
 */
final class Whatsapp
{
    private const ESPERA_ENTRE_MS = 3100;              // la pasarela pide 3 s entre mensajes
    private const REINTENTOS = [60, 300, 900, 3600];   // segundos hasta el próximo intento
    private const MAX_INTENTOS = 5;

    public static function conf(): array
    {
        $def = Config::defaults()['whatsapp'];
        $c = array_replace($def, array_intersect_key((array)(Config::get('whatsapp') ?? []), $def));
        $c['activo'] = !empty($c['activo']);
        $c['url'] = rtrim(trim((string)$c['url']), '/');
        $c['token'] = (string)$c['token'];
        $c['recordar_min'] = max(0, (int)$c['recordar_min']);
        return $c;
    }

    /** ¿Encendido y con dirección y token? Si no, todo va por correo. */
    public static function activo(): bool
    {
        $c = self::conf();
        return $c['activo'] && $c['url'] !== '' && $c['token'] !== '' && function_exists('curl_init');
    }

    /** 0991234567 → 593991234567 · 022345678 → 59322345678 · los demás quedan igual. '' si no es válido. */
    public static function numero(string $telefono): string
    {
        $t = preg_replace('/\D/', '', $telefono);
        if (!MiembroRepo::telefonoValido($t)) return '';
        return $t[0] === '0' ? '593' . substr($t, 1) : $t;
    }

    /** Número de WhatsApp de un miembro ('' si no tiene un teléfono válido). */
    public static function numeroDe(array $m): string
    {
        return self::numero((string)($m['telefono'] ?? ''));
    }

    /* ---------------- Pasarela ---------------- */

    /** ['conectado' => bool, 'numero' => ?string, 'qr' => ?string (imagen)] o el error en texto. */
    public static function estado(): array|string
    {
        [$codigo, $datos] = self::pedir('GET', '/estado', null, 12000);
        if ($codigo !== 200 || !array_key_exists('conectado', $datos)) return self::errorDe($codigo);
        $qr = (string)($datos['qr'] ?? '');
        return [
            'conectado' => (bool)$datos['conectado'],
            'numero'    => !empty($datos['numero']) ? preg_replace('/\D/', '', (string)$datos['numero']) : null,
            'qr'        => preg_match('#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/=]+$#', $qr) ? $qr : null,
        ];
    }

    /** ¿La pasarela contesta? De paso la mantiene despierta (cPanel apaga las apps quietas). */
    public static function salud(): bool
    {
        [$codigo, $datos] = self::pedir('GET', '/salud', null, 8000);
        return $codigo === 200 && !empty($datos['ok']);
    }

    /** Manda un texto ya. true, o [código HTTP (0 = no contesta), texto del error]. */
    public static function enviarAhora(string $numero, string $texto): true|array
    {
        if (!preg_match('/^\d{8,15}$/', $numero)) return [422, 'El número no es válido.'];
        [$codigo, $datos] = self::pedir('POST', '/mensajes', ['numero' => $numero, 'texto' => mb_substr($texto, 0, 4000)], 15000);
        if (($codigo === 200 || $codigo === 201) && !empty($datos['id'])) return true;
        return [$codigo, self::errorDe($codigo)];
    }

    public static function errorDe(int $codigo): string
    {
        return match (true) {
            $codigo === 0                     => 'La pasarela de WhatsApp no contesta: revisa la dirección y que la app esté encendida en cPanel.',
            $codigo === 401 || $codigo === 403 => 'La pasarela rechazó el token: tiene que ser el mismo en cPanel y en Ajustes.',
            $codigo === 409                   => 'El número de WhatsApp no está vinculado: escanea el QR en Ajustes → Reuniones → WhatsApp.',
            $codigo === 422 || $codigo === 400 => 'Ese número no tiene WhatsApp.',
            $codigo === 429                   => 'WhatsApp va despacio para cuidar el número: se reintenta en un momento.',
            $codigo === 503                   => 'La pasarela se está reiniciando: se reintenta en un momento.',
            default                           => 'La pasarela de WhatsApp respondió con un error (' . $codigo . ').',
        };
    }

    /** [código HTTP (0 si no contesta), JSON decodificado]. Nunca sigue redirecciones. */
    private static function pedir(string $metodo, string $ruta, ?array $cuerpo, int $esperaMs): array
    {
        $c = self::conf();
        if ($c['url'] === '' || !preg_match('#^https?://#i', $c['url']) || !function_exists('curl_init')) return [0, []];
        $cabeceras = ['Authorization: Bearer ' . $c['token'], 'Accept: application/json'];
        $ch = curl_init($c['url'] . $ruta);
        $op = [
            CURLOPT_CUSTOMREQUEST     => $metodo,
            CURLOPT_RETURNTRANSFER    => true,
            CURLOPT_CONNECTTIMEOUT_MS => 5000,
            CURLOPT_TIMEOUT_MS        => $esperaMs,
            CURLOPT_FOLLOWLOCATION    => false,
        ];
        if ($cuerpo !== null) {
            $op[CURLOPT_POSTFIELDS] = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
            $cabeceras[] = 'Content-Type: application/json';
        }
        $op[CURLOPT_HTTPHEADER] = $cabeceras;
        curl_setopt_array($ch, $op);
        $resp = curl_exec($ch);
        $codigo = $resp === false ? 0 : (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $datos = is_string($resp) ? json_decode($resp, true) : null;
        return [$codigo, is_array($datos) ? $datos : []];
    }

    /* ---------------- Cola ---------------- */

    private static function cola(): JsonStore
    {
        return new JsonStore('whatsapp_cola');
    }

    /**
     * Deja un mensaje en la cola. $clave evita mandar dos veces lo mismo (la
     * invitación o el recordatorio de una reunión a una persona). $extra se
     * guarda con el mensaje (p. ej. reunion_id, para el correo de respaldo).
     */
    public static function encolar(array $miembro, string $texto, string $motivo, string $clave = '', array $extra = []): bool
    {
        $num = self::numeroDe($miembro);
        if ($num === '' || trim($texto) === '') return false;
        $cola = self::cola();
        if ($clave !== '' && $cola->where('clave', $clave)) return false;
        $cola->insert($extra + [
            'miembro_id' => (int)($miembro['id'] ?? 0),
            'numero'     => $num,
            'texto'      => $texto,
            'motivo'     => $motivo,
            'clave'      => $clave,
            'estado'     => 'pendiente',
            'intentos'   => 0,
            'proximo'    => time(),
            'creado'     => date('Y-m-d H:i:s'),
            'error'      => '',
        ]);
        return true;
    }

    /** Saca de la cola lo que falte mandar de una reunión (al cancelarla). */
    public static function quitarDeReunion(int $reunionId): void
    {
        $cola = self::cola();
        foreach ($cola->where('reunion_id', $reunionId) as $r) {
            if (($r['estado'] ?? '') === 'pendiente') $cola->delete((int)$r['id']);
        }
    }

    /** Cuántos mensajes hay pendientes y cuántos fallaron en las últimas 24 h. */
    public static function resumenCola(): array
    {
        $desde = date('Y-m-d H:i:s', time() - 86400);
        $p = $f = 0;
        foreach (self::cola()->all() as $r) {
            if (($r['estado'] ?? '') === 'pendiente') $p++;
            elseif (($r['estado'] ?? '') === 'fallido' && (string)($r['creado'] ?? '') >= $desde) $f++;
        }
        return ['pendientes' => $p, 'fallidos' => $f];
    }

    /**
     * Manda lo pendiente (lo llama cron_whatsapp.php). Reintenta con espera
     * creciente lo que falló por la pasarela; lo que WhatsApp rechaza (el número
     * no tiene WhatsApp) o falla 5 veces queda como fallido, y si era una
     * invitación a una reunión, se manda por correo.
     */
    public static function procesarCola(int $segundos = 45): array
    {
        $res = ['enviados' => 0, 'fallidos' => 0, 'error' => ''];
        if (!self::activo()) return $res;
        $inicio = time();
        $cola = self::cola();
        $pendientes = array_values(array_filter($cola->where('estado', 'pendiente'), fn($r) => (int)($r['proximo'] ?? 0) <= time()));
        usort($pendientes, fn($a, $b) => (int)$a['id'] <=> (int)$b['id']);
        foreach ($pendientes as $i => $r) {
            if (time() - $inicio > $segundos) break;
            if ($i > 0) usleep(self::ESPERA_ENTRE_MS * 1000);
            $ok = self::enviarAhora((string)$r['numero'], (string)$r['texto']);
            if ($ok === true) {
                // El texto se borra al enviarse: no hace falta guardarlo
                $cola->update((int)$r['id'], ['estado' => 'enviado', 'texto' => '', 'enviado' => date('Y-m-d H:i:s'), 'error' => '']);
                $res['enviados']++;
                continue;
            }
            [$codigo, $error] = $ok;
            $intentos = (int)($r['intentos'] ?? 0) + 1;
            $definitivo = $codigo === 422 || $codigo === 400 || $intentos >= self::MAX_INTENTOS;
            if ($definitivo) {
                $cola->update((int)$r['id'], ['estado' => 'fallido', 'texto' => '', 'intentos' => $intentos, 'error' => $error]);
                $res['fallidos']++;
                if (($r['motivo'] ?? '') === 'reunion') self::correoDeRespaldo($r);
            } else {
                $espera = self::REINTENTOS[min($intentos - 1, count(self::REINTENTOS) - 1)];
                $cola->update((int)$r['id'], ['intentos' => $intentos, 'proximo' => time() + $espera, 'error' => $error]);
            }
            // Sin pasarela, token malo o número sin vincular: no tiene caso seguir en esta vuelta
            if (in_array($codigo, [0, 401, 403, 409, 503], true)) {
                $res['error'] = $error;
                break;
            }
        }
        // Lo enviado o fallido hace más de 14 días ya no hace falta
        $limite = date('Y-m-d H:i:s', time() - 14 * 86400);
        foreach ($cola->all() as $r) {
            if (($r['estado'] ?? '') !== 'pendiente' && (string)($r['creado'] ?? '') < $limite) $cola->delete((int)$r['id']);
        }
        return $res;
    }

    /** La invitación que no salió por WhatsApp va por correo, para que nadie se quede sin saber. */
    private static function correoDeRespaldo(array $fila): void
    {
        if (!class_exists('Mailer') || !Mailer::listo()) return;
        $reu = (new ReunionRepo())->buscar((int)($fila['reunion_id'] ?? 0));
        $m   = (new MiembroRepo())->buscar((int)($fila['miembro_id'] ?? 0));
        if (!$reu || !$m) return;
        $p = (new ProyectoRepo())->buscar((int)($reu['proyecto_id'] ?? 0)) ?? [];
        Mailer::notificarReunion($reu, $m, $p);
    }

    /* ---------------- Reuniones ---------------- */

    /**
     * Avisa a los invitados de una reunión (a la cola). $tipo: nueva | cambio |
     * cancelada. Nadie se avisa a sí mismo ($actor). Devuelve [encolados, sin
     * teléfono]: a los segundos (miembros) quien llama les manda el correo.
     */
    public static function avisarReunion(array $reu, array $invitados, array $proyecto, string $tipo, int $actor = 0): array
    {
        $repo = new MiembroRepo();
        $texto = self::textoReunion($reu, $proyecto, $tipo);
        $encolados = 0;
        $sinTelefono = [];
        foreach (array_unique(array_map('intval', $invitados)) as $mid) {
            if ($mid <= 0 || $mid === $actor) continue;
            $m = $repo->buscar($mid);
            if (!$m) continue;
            if (self::numeroDe($m) === '') {
                $sinTelefono[] = $m;
                continue;
            }
            $clave  = $tipo === 'nueva' ? 'reu:' . (int)$reu['id'] . ':nueva:' . $mid : '';
            $motivo = $tipo === 'nueva' ? 'reunion' : 'reunion-' . $tipo;
            if (self::encolar($m, $texto, $motivo, $clave, ['reunion_id' => (int)$reu['id']])) $encolados++;
        }
        return [$encolados, $sinTelefono];
    }

    /** ¿A la reunión le queda alguna fecha por delante? (para avisar que se canceló) */
    public static function quedaPorDelante(array $reu): bool
    {
        $ahora = time();
        foreach (self::fechasDe($reu) as $f) {
            $ts = strtotime($f);
            if ($ts !== false && $ts > $ahora) return true;
        }
        return false;
    }

    /**
     * Pone en la cola el recordatorio de las reuniones que empiezan dentro de los
     * próximos minutos (Ajustes: «Recordatorio antes de cada reunión»). Va a
     * todos los invitados; la clave evita repetirlo en la siguiente vuelta.
     */
    public static function recordatorios(): int
    {
        $min = self::conf()['recordar_min'];
        if (!self::activo() || $min <= 0) return 0;
        $ahora = time();
        $hasta = $ahora + $min * 60;
        $miembros = new MiembroRepo();
        $proyectos = new ProyectoRepo();
        $n = 0;
        foreach ((new JsonStore('reuniones'))->all() as $reu) {
            foreach (self::fechasDe($reu) as $f) {
                $ts = strtotime($f);
                if ($ts === false || $ts <= $ahora || $ts > $hasta) continue;
                $p = $proyectos->buscar((int)($reu['proyecto_id'] ?? 0)) ?? [];
                $texto = self::textoRecordatorio($reu, $p, $f, max(1, (int)round(($ts - $ahora) / 60)));
                foreach (array_unique(array_map('intval', (array)($reu['invitados'] ?? []))) as $mid) {
                    $m = $miembros->buscar($mid);
                    if ($m && self::encolar($m, $texto, 'recordatorio', 'rec:' . (int)$reu['id'] . ':' . $f . ':' . $mid, ['reunion_id' => (int)$reu['id']])) $n++;
                }
            }
        }
        return $n;
    }

    /** Las fechas "Y-m-d H:i" de una reunión (una sola, o cada día de la serie). */
    private static function fechasDe(array $reu): array
    {
        $inicio = (string)($reu['inicio'] ?? '');
        if ($inicio === '') return [];
        return Reuniones::esRecurrente($reu)
            ? Reuniones::fechasOcurrencias($inicio, (array)$reu['dias'], (string)$reu['hasta'])
            : [$inicio];
    }

    public static function textoReunion(array $reu, array $proyecto, string $tipo): string
    {
        $titulo = trim((string)($reu['topic'] ?? '')) ?: 'Reunión';
        $lineas = match ($tipo) {
            'cambio'    => ['✏️ *Cambió una reunión*', '*' . $titulo . '*'],
            'cancelada' => ['❌ *Se canceló una reunión*', '*' . $titulo . '*'],
            default     => ['📅 *Te invitaron a una reunión*', '*' . $titulo . '*'],
        };
        if (!empty($proyecto['nombre'])) $lineas[] = 'Proyecto: ' . $proyecto['nombre'];
        $inicio = (string)($reu['inicio'] ?? '');
        if (Reuniones::esRecurrente($reu)) {
            $lineas[] = ($tipo === 'cancelada' ? 'Era: ' : 'Cuándo: ') . Reuniones::etiqueta((array)$reu['dias'])
                . ' a las ' . substr($inicio, 11, 5) . ', hasta el ' . self::fecha((string)$reu['hasta'], false);
        } else {
            $lineas[] = ($tipo === 'cancelada' ? 'Era el ' : ($tipo === 'cambio' ? 'Ahora: ' : 'Cuándo: ')) . self::fecha($inicio);
        }
        if ($tipo === 'cancelada') return implode("\n", $lineas);
        if (!empty($reu['duracion'])) $lineas[] = 'Duración: ' . (int)$reu['duracion'] . ' min';
        if (!empty($reu['password'])) $lineas[] = 'Código: ' . $reu['password'];
        if (!empty($reu['join_url'])) $lineas[] = 'Entrar: ' . $reu['join_url'];
        return implode("\n", $lineas);
    }

    private static function textoRecordatorio(array $reu, array $proyecto, string $fecha, int $minutos): string
    {
        $titulo = trim((string)($reu['topic'] ?? '')) ?: 'Reunión';
        $lineas = ['⏰ *En ' . $minutos . ' min: ' . $titulo . '*',
                   'A las ' . substr($fecha, 11, 5) . (!empty($proyecto['nombre']) ? ' · ' . $proyecto['nombre'] : '')];
        if (!empty($reu['password'])) $lineas[] = 'Código: ' . $reu['password'];
        if (!empty($reu['join_url'])) $lineas[] = 'Entrar: ' . $reu['join_url'];
        return implode("\n", $lineas);
    }

    /** "lunes 20 de octubre a las 10:00" */
    private static function fecha(string $f, bool $conHora = true): string
    {
        $ts = strtotime($f);
        if ($ts === false) return $f;
        $dias  = ['lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado', 'domingo'];
        $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        $t = $dias[(int)date('N', $ts) - 1] . ' ' . (int)date('j', $ts) . ' de ' . $meses[(int)date('n', $ts) - 1];
        return $conHora ? $t . ' a las ' . date('H:i', $ts) : $t;
    }

    /* ---------------- Código para recuperar la contraseña ---------------- */

    /**
     * Manda el código de 6 dígitos ya (no por la cola: la persona lo está
     * esperando). Si la pasarela estaba despertando, reintenta una vez.
     */
    public static function enviarCodigo(array $miembro, string $codigo, int $minutos): bool
    {
        $num = self::numeroDe($miembro);
        if (!self::activo() || $num === '') return false;
        $marca = trim((string)(Config::get('titulo') ?? '')) ?: 'el panel';
        $texto = '*' . $codigo . '* es tu código para cambiar la contraseña de ' . $marca . ".\n"
               . 'Vence en ' . $minutos . ' minutos. Si no lo pediste, ignora este mensaje.';
        $r = self::enviarAhora($num, $texto);
        if ($r !== true && in_array($r[0], [0, 409, 429, 503], true)) {
            sleep(3);
            $r = self::enviarAhora($num, $texto);
        }
        return $r === true;
    }
}
