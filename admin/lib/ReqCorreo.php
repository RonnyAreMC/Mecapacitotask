<?php
/**
 * Requerimientos desde el correo InnoTech.
 *
 *  - A mano: en un correo, «Requerimiento con IA» lo analiza y abre el
 *    formulario de requerimiento ya relleno (con los adjuntos del correo).
 *  - Solo: cada persona administradora pone reglas (de quién, o hacia qué
 *    dirección) y cron_correo.php analiza sus correos nuevos que las cumplen.
 *    Según elija, el requerimiento queda registrado y asignado, o como
 *    propuesta para que lo confirme.
 *
 * La IA propone qué se pide, la institución, el responsable, quién lo
 * solicitó y la magnitud; la fecha de entrega sale de la magnitud (días
 * hábiles configurables, como mucho una semana) salvo que el correo dé una.
 *
 * Cada correo analizado queda en la tabla «req_correo» (por su Message-ID):
 * así no se analiza dos veces y el correo muestra en qué requerimiento quedó.
 * Leer el buzón aquí nunca marca los correos como leídos.
 *
 * Se carga con is_file (no desde bootstrap): si falta, el resto sigue igual.
 */
final class ReqCorreo
{
    public const MAGNITUDES = [
        'pequena' => ['Pequeña', 'un ajuste o cambio puntual'],
        'mediana' => ['Mediana', 'un entregable: una pantalla, un reporte, un diseño'],
        'grande'  => ['Grande', 'varios entregables o una semana de trabajo'],
    ];
    public const PLAZOS_DEFECTO = ['pequena' => 1, 'mediana' => 3, 'grande' => 5];
    /** Una semana de trabajo, en días hábiles. */
    public const TOPE_DIAS = 5;
    /** Lo mismo que deja subir el formulario de requerimientos. */
    private const TOPE_ADJUNTO = 10485760;

    public const ESTADOS = [
        'propuesta'  => ['Por revisar', 'info'],
        'registrado' => ['Registrado', 'success'],
        'no_es'      => ['No era un pedido', 'neutral'],
        'descartado' => ['Descartado', 'neutral'],
        'error'      => ['No se pudo analizar', 'danger'],
    ];

    private static function repo(): JsonStore
    {
        return new JsonStore('req_correo');
    }

    /* ---------------- Plazos por magnitud ---------------- */

    /** Días hábiles por magnitud (entre 1 y 5). */
    public static function plazos(): array
    {
        $g = (array)Config::get('req_plazos');
        $out = [];
        foreach (self::PLAZOS_DEFECTO as $k => $def) {
            $out[$k] = max(1, min(self::TOPE_DIAS, (int)($g[$k] ?? $def)));
        }
        return $out;
    }

    public static function guardarPlazos(array $p): void
    {
        $out = [];
        foreach (self::PLAZOS_DEFECTO as $k => $def) $out[$k] = max(1, min(self::TOPE_DIAS, (int)($p[$k] ?? $def)));
        Config::guardar(['req_plazos' => $out]);
    }

    /** Hoy si es día hábil; si es fin de semana, el lunes. */
    public static function inicioHabil(?string $hoy = null): string
    {
        $t = strtotime($hoy ?? date('Y-m-d'));
        while ((int)date('N', $t) >= 6) $t = strtotime('+1 day', $t);
        return date('Y-m-d', $t);
    }

    /** $desde + $n días hábiles (sin sábados ni domingos). */
    public static function sumarHabiles(string $desde, int $n): string
    {
        $t = strtotime($desde);
        while ($n > 0) {
            $t = strtotime('+1 day', $t);
            if ((int)date('N', $t) <= 5) $n--;
        }
        return date('Y-m-d', $t);
    }

    /* ---------------- Reglas de cada persona ---------------- */

    /** Lo que la persona configuró para su buzón. */
    public static function reglas(int $mid): array
    {
        $r = (array)((Correo::cuenta($mid) ?? [])['req_auto'] ?? []);
        return [
            'activo'     => !empty($r['activo']),
            'remitentes' => array_values(array_filter((array)($r['remitentes'] ?? []), 'is_string')),
            'destinos'   => array_values(array_filter((array)($r['destinos'] ?? []), 'is_string')),
            'solo_cc'    => !empty($r['solo_cc']),
            'modo'       => ($r['modo'] ?? '') === 'revisar' ? 'revisar' : 'auto',
            'ultimo_uid' => isset($r['ultimo_uid']) ? (int)$r['ultimo_uid'] : null,
            'uidvalidity'=> isset($r['uidvalidity']) ? (int)$r['uidvalidity'] : null,
            'repasar'    => (string)($r['repasar'] ?? ''),
        ];
    }

    /**
     * Guarda las reglas. Al activarlas se empieza por los correos que lleguen
     * desde ahora; con $repasarDias > 0 también se revisan los de esos días.
     */
    public static function guardarReglas(int $mid, array $d, int $repasarDias = 0): array
    {
        $antes = self::reglas($mid);
        $r = [
            'activo'     => !empty($d['activo']),
            'remitentes' => Correo::listaCorreos((string)($d['remitentes'] ?? '')),
            'destinos'   => Correo::listaCorreos((string)($d['destinos'] ?? '')),
            'solo_cc'    => !empty($d['solo_cc']),
            'modo'       => ($d['modo'] ?? '') === 'revisar' ? 'revisar' : 'auto',
            'ultimo_uid' => $antes['ultimo_uid'],
            'uidvalidity'=> $antes['uidvalidity'],
            'repasar'    => $repasarDias > 0 ? date('Y-m-d', strtotime('-' . min(30, $repasarDias) . ' days')) : $antes['repasar'],
        ];
        // Recién activado: lo que ya estaba en el buzón no se toca (salvo el repaso)
        if ($r['activo'] && !$antes['activo']) $r['ultimo_uid'] = null;
        Correo::guardarDatos($mid, ['req_auto' => $r]);
        return $r;
    }

    /** Buzones con reglas activas (de personas que siguen siendo administradoras). */
    public static function cuentasActivas(): array
    {
        $out = [];
        $miembros = new MiembroRepo();
        foreach (Correo::correosSincronizados() as $mid => $email) {
            $r = self::reglas($mid);
            if (!$r['activo'] || (!$r['remitentes'] && !$r['destinos'])) continue;
            $m = $miembros->buscar($mid);
            if ($m && MiembroRepo::tieneAcceso($m, 'admin')) $out[] = $mid;
        }
        return $out;
    }

    /** ¿El correo cumple las reglas? Devuelve el motivo ('' si no). */
    public static function coincide(array $reglas, string $yoMail, array $de, array $para, array $cc): string
    {
        $yoMail = strtolower($yoMail);
        $emails = fn(array $l) => array_map(fn($x) => strtolower((string)$x['email']), $l);
        if ($reglas['solo_cc'] && !in_array($yoMail, $emails($cc), true)) return '';
        $remitente = $emails($de)[0] ?? '';
        if ($remitente !== '' && in_array($remitente, $reglas['remitentes'], true)) return 'De ' . $remitente;
        foreach (array_merge($emails($para), $emails($cc)) as $e) {
            if (in_array($e, $reglas['destinos'], true)) return 'Para ' . $e;
        }
        return '';
    }

    /* ---------------- Leer un correo (sin marcarlo leído) ---------------- */

    public static function leer(CorreoImap $imap, string $carpeta, int $uid): ?array
    {
        $imap->abrir($carpeta);
        $crudo = $imap->crudo($uid);
        if ($crudo === '') return null;
        $m = CorreoMime::analizar($crudo);
        $h = $m['cab'];
        $texto = trim((string)($m['texto'] ?? ''));
        if ($texto === '') $texto = CorreoHtml::aTexto((string)($m['html'] ?? ''));
        $adj = [];
        foreach ($m['adjuntos'] as $i => $a) {
            if (!empty($a['inline']) && ($a['cid'] ?? '') !== '') continue;      // logos de firmas y similares
            $nombre = CorreoMime::nombreArchivo((string)$a['nombre']);
            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            $tam = strlen((string)$a['datos']);
            $adj[] = ['i' => $i, 'nombre' => mb_substr($nombre, 0, 120), 'tam' => $tam, 'ext' => $ext,
                      'ok' => isset(ADJUNTOS_PERMITIDOS[$ext]) && $tam <= self::TOPE_ADJUNTO];
        }
        $fecha = strtotime((string)preg_replace('/\s*\([^)]*\)\s*$/', '', trim((string)($h['date'][0] ?? '')))) ?: time();
        return [
            'uid'        => $uid,
            'carpeta'    => $carpeta,
            'asunto'     => CorreoMime::cab($h, 'subject'),
            'de'         => CorreoMime::direcciones($h['from'][0] ?? ''),
            'para'       => CorreoMime::direcciones($h['to'][0] ?? ''),
            'cc'         => CorreoMime::direcciones($h['cc'][0] ?? ''),
            'fecha'      => $fecha,
            'message_id' => trim((string)($h['message-id'][0] ?? '')),
            'texto'      => $texto,
            'adjuntos'   => $adj,
        ];
    }

    /* ---------------- Registro de lo analizado ---------------- */

    /** La fila de un correo ya analizado (por su Message-ID), o null. */
    public static function deMensaje(int $mid, string $messageId): ?array
    {
        if ($messageId === '') return null;
        foreach (self::repo()->all() as $f) {
            if ((int)$f['miembro_id'] === $mid && ($f['message_id'] ?? '') === $messageId) return $f;
        }
        return null;
    }

    public static function fila(int $id): ?array
    {
        return self::repo()->find($id);
    }

    /** Lo último analizado de una persona (lo más nuevo primero). */
    public static function historial(int $mid, int $max = 10): array
    {
        $l = array_values(array_filter(self::repo()->all(), fn($f) => (int)$f['miembro_id'] === $mid));
        usort($l, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        return array_slice($l, 0, $max);
    }

    /** Propuestas pendientes de confirmar. */
    public static function propuestas(int $mid): array
    {
        return array_values(array_filter(self::historial($mid, 200), fn($f) => ($f['estado'] ?? '') === 'propuesta'));
    }

    public static function descartar(int $id, int $mid): ?array
    {
        $f = self::fila($id);
        if (!$f || (int)$f['miembro_id'] !== $mid || ($f['estado'] ?? '') !== 'propuesta') return null;
        self::repo()->update($id, ['estado' => 'descartado']);
        return $f;
    }

    /* ---------------- Analizar con IA ---------------- */

    /**
     * Analiza un correo del buzón de $mid y deja su propuesta en la tabla.
     * Si ya se había analizado, devuelve esa fila (no gasta IA de nuevo).
     * Lanza CorreoError o IAError si no se puede.
     */
    public static function analizar(int $mid, string $carpeta, int $uid, string $origen = 'manual', string $motivo = '', ?CorreoImap $imap = null, string $indicaciones = ''): array
    {
        $propio = $imap === null;
        $imap ??= Correo::abrir($mid);
        try {
            $msg = self::leer($imap, $carpeta, $uid);
        } finally {
            if ($propio) $imap->cerrar();
        }
        if (!$msg) throw new CorreoError('Ese correo ya no está en el buzón.', 'uso');
        $ya = self::deMensaje($mid, $msg['message_id']);
        // Ya registrado, o ya propuesto y sin indicaciones nuevas: no se gasta IA otra vez
        if ($ya && (($ya['estado'] ?? '') === 'registrado' || (($ya['estado'] ?? '') === 'propuesta' && $indicaciones === ''))) return $ya;

        $base = [
            'miembro_id' => $mid, 'message_id' => $msg['message_id'], 'carpeta' => $carpeta, 'uid' => $uid,
            'asunto' => mb_substr($msg['asunto'], 0, 250), 'de' => $msg['de'][0] ?? ['nombre' => '', 'email' => ''],
            'fecha' => date('Y-m-d H:i', $msg['fecha']), 'adjuntos' => $msg['adjuntos'], 'origen' => $origen,
            'motivo' => $motivo, 'creado' => date('Y-m-d H:i'),
        ];
        try {
            $datos = self::conIA($msg, $indicaciones);
        } catch (IAError $e) {
            $err = $base + ['estado' => 'error', 'error' => $e->getMessage(), 'datos' => [], 'req_id' => 0];
            if ($ya) self::repo()->update((int)$ya['id'], $err);
            else self::repo()->insert($err);
            throw $e;
        }
        $estado = $datos['es_requerimiento'] ? 'propuesta' : 'no_es';
        // A mano, la persona decide aunque la IA crea que no es un pedido
        if ($origen === 'manual') $estado = 'propuesta';
        if ($ya) {
            self::repo()->update((int)$ya['id'], $base + ['estado' => $estado, 'datos' => $datos, 'error' => '', 'req_id' => 0]);
            return self::fila((int)$ya['id']);
        }
        return self::repo()->insert($base + ['estado' => $estado, 'datos' => $datos, 'error' => '', 'req_id' => 0]);
    }

    /** Arma el contexto, pide la propuesta a la IA y calcula el plazo. */
    private static function conIA(array $msg, string $indicaciones = ''): array
    {
        $abiertos = [];
        foreach ((new RequerimientoRepo())->todos() as $r) {
            if (!RequerimientoRepo::cerrado($r)) $abiertos[] = ['id' => (int)$r['id'], 'titulo' => (string)($r['titulo'] ?? '')];
        }
        $equipos = Catalogo::equipos();
        $sinc = Correo::correosSincronizados();
        $gente = [];
        foreach ((new MiembroRepo())->todos() as $m) {
            $gente[] = ['id' => (int)$m['id'], 'nombre' => (string)$m['nombre'],
                        'rol' => trim((string)($m['rol'] ?? '') . ' · ' . ($equipos[$m['equipo'] ?? ''][0] ?? ''), ' ·'),
                        'correos' => array_values(array_unique(array_filter([strtolower((string)($m['email'] ?? '')), $sinc[(int)$m['id']] ?? ''])))];
        }
        $inst = [];
        foreach ((new InstitucionRepo())->todas() as $i) $inst[] = ['id' => (int)$i['id'], 'nombre' => (string)$i['nombre']];

        $d = IAFunciones::requerimientoCorreo($msg, array_slice($abiertos, 0, 200), $gente, $inst, $indicaciones);
        $plazos = self::plazos();
        $inicio = self::inicioHabil();
        $fin = $d['fecha_fin'] !== '' && $d['fecha_fin'] >= $inicio ? $d['fecha_fin'] : self::sumarHabiles($inicio, $plazos[$d['magnitud']]);
        return $d + ['fecha_inicio' => $inicio, 'fecha_fin_calc' => $fin, 'dias' => $plazos[$d['magnitud']],
                     'fecha_del_correo' => $d['fecha_fin'] !== '' && $d['fecha_fin'] >= $inicio];
    }

    /* ---------------- Registrar ---------------- */

    /**
     * Copia del correo a uploads/ los adjuntos elegidos (por su índice), con
     * las mismas reglas que el formulario. Devuelve [adjuntos, rechazados].
     */
    public static function traerAdjuntos(array $fila, array $indices): array
    {
        $indices = array_map('intval', $indices);
        $quiere = array_filter((array)$fila['adjuntos'], fn($a) => in_array((int)$a['i'], $indices, true));
        if (!$quiere) return [[], []];
        $imap = Correo::abrir((int)$fila['miembro_id']);
        try {
            $imap->abrir((string)$fila['carpeta']);
            $crudo = $imap->crudo((int)$fila['uid']);
        } finally {
            $imap->cerrar();
        }
        $m = $crudo !== '' ? CorreoMime::analizar($crudo) : ['adjuntos' => []];
        $out = $rech = [];
        $fi = new finfo(FILEINFO_MIME_TYPE);
        foreach ($quiere as $q) {
            $a = $m['adjuntos'][(int)$q['i']] ?? null;
            $nombre = (string)$q['nombre'];
            $ext = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
            $datos = $a ? (string)$a['datos'] : '';
            $mime = $datos !== '' ? (string)$fi->buffer($datos) : '';
            if ($datos === '' || strlen($datos) > self::TOPE_ADJUNTO || !in_array($mime, ADJUNTOS_PERMITIDOS[$ext] ?? [], true)) {
                $rech[] = $nombre;
                continue;
            }
            $ruta = 'uploads/' . uniqid('req_') . '.' . $ext;
            if (file_put_contents(__DIR__ . '/../' . $ruta, $datos) === false) { $rech[] = $nombre; continue; }
            $out[] = ['ruta' => $ruta, 'nombre' => mb_substr($nombre, 0, 80), 'tipo' => str_starts_with($mime, 'image/') ? 'img' : 'doc', 'ext' => $ext];
        }
        return [$out, $rech];
    }

    /** Lo que se guarda en el requerimiento para saber de qué correo salió. */
    public static function origen(array $fila): array
    {
        return ['fila' => (int)$fila['id'], 'asunto' => (string)$fila['asunto'], 'de' => (array)$fila['de'],
                'fecha' => (string)$fila['fecha'], 'message_id' => (string)$fila['message_id']];
    }

    public static function marcarRegistrado(array $fila, array $req): void
    {
        self::repo()->update((int)$fila['id'], ['estado' => 'registrado', 'req_id' => (int)$req['id'], 'registrado' => date('Y-m-d H:i')]);
        self::marcarLeido($fila);
    }

    /**
     * El correo que ya es requerimiento se queda en su carpeta (no se mueve ni
     * se borra): solo pasa a leído, sin el punto azul.
     */
    public static function marcarLeido(array $fila): void
    {
        try {
            $imap = Correo::abrir((int)$fila['miembro_id']);
            try {
                $imap->abrir((string)$fila['carpeta'], true);
                $imap->marcar((int)$fila['uid'], '\\Seen');
            } finally {
                $imap->cerrar();
            }
        } catch (CorreoError $e) {
            // Si el buzón no responde, el requerimiento igual quedó registrado
        }
    }

    /**
     * Registra y asigna una propuesta sin pasar por el formulario (modo
     * automático del cron): con todos los adjuntos que se puedan guardar.
     */
    public static function registrarSolo(array $fila): array
    {
        $d = (array)$fila['datos'];
        $mid = (int)$fila['miembro_id'];
        [$adj, $rech] = self::traerAdjuntos($fila, array_column(array_filter((array)$fila['adjuntos'], fn($a) => !empty($a['ok'])), 'i'));
        $repo = new RequerimientoRepo();
        $req = $repo->crear([
            'titulo' => (string)$d['titulo'], 'detalle' => HtmlRico::limpiar((string)$d['detalle']),
            'solicitante' => (string)$d['solicitante'], 'prioridad' => (string)$d['prioridad'],
            'instituciones' => (array)$d['instituciones'], 'creado_por' => $mid, 'adjuntos' => $adj,
        ]);
        $repo->actualizar((int)$req['id'], ['origen_correo' => self::origen($fila)]);
        $miembros = new MiembroRepo();
        $resp = [];
        foreach ((array)$d['responsables'] as $id) if ($m = $miembros->buscar((int)$id)) $resp[(int)$m['id']] = $m;
        $repo->asignar((int)$req['id'], array_keys($resp), ['fecha_inicio' => (string)$d['fecha_inicio'], 'fecha_fin' => (string)$d['fecha_fin_calc']]);
        $req = $repo->buscar((int)$req['id']) ?? $req;
        if ($resp && class_exists('Notif')) {
            Notif::crear(array_keys($resp), 'requerimiento', 'Te asignaron un requerimiento',
                '«' . $req['titulo'] . '» · entrega ' . $d['fecha_fin_calc'], 'bandeja.php', $mid);
        }
        foreach ($resp as $rid => $m) {
            $otros = array_map(fn($o) => $o['nombre'], array_diff_key($resp, [$rid => true]));
            Mailer::notificarRequerimiento($req, $m, array_values($otros));
        }
        self::marcarRegistrado($fila, $req);
        // Quien puso las reglas se entera (campana y, si los tiene, aviso nativo)
        if (class_exists('Notif')) {
            $quien = $resp ? implode(', ', array_map(fn($m) => explode(' ', trim($m['nombre']))[0], $resp)) : 'nadie todavía';
            Notif::crear([$mid], 'requerimiento', 'La IA registró un requerimiento del correo',
                '«' . $req['titulo'] . '» · para ' . $quien . ($rech ? ' · no se pudo guardar: ' . implode(', ', $rech) : ''),
                'requerimientos.php?ver=' . (int)$req['id'], 0);
        }
        return $req;
    }

    /* ---------------- El cron: revisar el buzón ---------------- */

    /**
     * Revisa los correos nuevos de $mid que cumplen sus reglas. Analiza como
     * mucho $max por vuelta (la IA tarda); lo demás queda para la siguiente.
     * Devuelve cuántos analizó.
     */
    public static function procesar(int $mid, int $max = 2): int
    {
        $r = self::reglas($mid);
        $cuenta = Correo::cuenta($mid);
        if (!$r['activo'] || !$cuenta || ($cuenta['estado'] ?? '') !== 'ok') return 0;
        $imap = Correo::abrir($mid);
        $hechos = 0;
        try {
            $st = $imap->estado('INBOX');
            $tope = max(0, (int)$st['uidnext'] - 1);
            $cambios = [];
            if ($r['uidvalidity'] !== (int)$st['uidvalidity'] || $r['ultimo_uid'] === null) {
                $cambios = ['ultimo_uid' => $tope, 'uidvalidity' => (int)$st['uidvalidity']];
                $r['ultimo_uid'] = $tope;
            }
            $imap->abrir('INBOX');
            $uids = [];
            if ($r['repasar'] !== '') {
                $uids = self::desdeFecha($imap, $r['repasar']);
            }
            if ($tope > (int)$r['ultimo_uid']) {
                $uids = array_merge($uids, array_filter($imap->buscarDesde((int)$r['ultimo_uid'] + 1, false), fn($u) => $u > (int)$r['ultimo_uid']));
            }
            $uids = array_values(array_unique($uids));
            sort($uids);
            $uids = array_slice($uids, -60);
            $cabs = $uids ? $imap->traer($uids, 'UID BODY.PEEK[HEADER.FIELDS (FROM TO CC MESSAGE-ID)]') : [];
            $avance = (int)$r['ultimo_uid'];
            $pendientes = false;
            foreach ($uids as $uid) {
                $cruda = '';
                foreach ($cabs[$uid] ?? [] as $k => $v) if (str_starts_with($k, 'BODY[')) $cruda = (string)$v;
                [$h] = CorreoMime::partir($cruda);
                $motivo = self::coincide($r, (string)$cuenta['email'], CorreoMime::direcciones($h['from'][0] ?? ''),
                    CorreoMime::direcciones($h['to'][0] ?? ''), CorreoMime::direcciones($h['cc'][0] ?? ''));
                $msgId = trim((string)($h['message-id'][0] ?? ''));
                if ($motivo !== '' && !self::deMensaje($mid, $msgId)) {
                    if ($hechos >= $max) { $pendientes = true; break; }
                    $hechos++;
                    try {
                        $fila = self::analizar($mid, 'INBOX', $uid, 'auto', $motivo, $imap);
                        if (($fila['estado'] ?? '') === 'propuesta') {
                            if ($r['modo'] === 'auto') {
                                self::registrarSolo($fila);
                            } elseif (class_exists('Notif')) {
                                Notif::crear([$mid], 'requerimiento', 'Un correo parece un requerimiento',
                                    '«' . (string)$fila['datos']['titulo'] . '» · revísalo y confírmalo', 'requerimientos.php?propuesta=' . (int)$fila['id'], 0);
                            }
                        }
                    } catch (IAError $e) {
                        // queda anotado como error en la tabla; se puede reintentar a mano desde el correo
                    }
                }
                if ($uid > $avance) $avance = $uid;
            }
            if (!$pendientes) {
                $cambios['ultimo_uid'] = max($avance, (int)($cambios['ultimo_uid'] ?? $r['ultimo_uid']));
                if ($r['repasar'] !== '') $cambios['repasar'] = '';
            } else {
                // Lo que quedó pendiente se retoma en la próxima vuelta
                $cambios['ultimo_uid'] = max((int)$r['ultimo_uid'], $avance);
            }
            if ($cambios) Correo::guardarDatos($mid, ['req_auto' => array_merge((array)(Correo::cuenta($mid)['req_auto'] ?? []), $cambios)]);
        } finally {
            $imap->cerrar();
        }
        return $hechos;
    }

    /** UIDs de la carpeta abierta desde una fecha (AAAA-MM-DD). */
    private static function desdeFecha(CorreoImap $imap, string $fecha): array
    {
        $meses = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $t = strtotime($fecha) ?: time();
        $r = $imap->cmd('UID SEARCH SINCE ' . date('j', $t) . '-' . $meses[(int)date('n', $t) - 1] . '-' . date('Y', $t));
        $uids = [];
        foreach ($r['datos'] ?? [] as $d) {
            if (stripos($d, '* SEARCH') !== 0) continue;
            foreach (preg_split('/\s+/', trim(substr($d, 8))) as $u) if (ctype_digit($u)) $uids[] = (int)$u;
        }
        return $uids;
    }
}
