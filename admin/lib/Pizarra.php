<?php
/**
 * La pizarra de cada reunión (pizarra.php la pinta, pizarra_api.php la sirve).
 *
 * Se usa EN la reunión con varios a la vez: cada uno guarda lo que cambia y
 * cada pocos segundos pregunta qué cambiaron los demás. Por eso no va en una
 * colección de JsonStore (que lee la colección entera en cada consulta) sino
 * en tablas propias, con índice, dentro del mismo data/panel.sqlite (así
 * entra en los respaldos):
 *   piz_elementos  un elemento por fila: (reunion, eid) → datos JSON, con su
 *                  versión v; borrar deja la fila marcada para que los demás
 *                  se enteren
 *   piz_meta       la versión de cada pizarra (sube con cada guardado)
 *   piz_presencia  quién la tiene abierta (último aviso de cada persona)
 *
 * Gana la última escritura por elemento: dos personas moviendo cosas
 * distintas no se pisan; si mueven la misma, queda la última.
 *
 * «Mis notas» es la pizarra personal de cada quien (pizarra.php?mia=1): usa
 * las mismas tablas con la clave en negativo (-id del miembro), así nunca
 * choca con la de una reunión. Solo la ve y la edita su dueño.
 *
 * Una nota de notas.php puede llevar su pizarra (pizarra.php?nota=ID, clave
 * ridNota: -(1000000 + id)): la edita solo el dueño de la nota; si la nota se
 * comparte por enlace, quien lo tenga la mira sin sesión (nota_ver.php).
 *
 * Una nota puede llevar un recordatorio (aviso: 'Y-m-d H:i', aviso_por): se
 * indexa en piz_avisos al guardar y cron_correo.php lo avisa a su hora.
 */
final class Pizarra
{
    /** Topes para que una pizarra no crezca sin control. */
    public const MAX_ELEMENTOS = 2000;
    public const MAX_BYTES_ELEMENTO = 300000;   // un trazo largo lleva sus puntos
    public const PRESENTE_SEG = 30;

    private static ?PDO $pdo = null;

    private static function db(): PDO
    {
        if (self::$pdo) return self::$pdo;
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $pdo = new PDO('sqlite:' . $dir . '/panel.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('CREATE TABLE IF NOT EXISTS piz_elementos (
            reunion INTEGER NOT NULL, eid TEXT NOT NULL, v INTEGER NOT NULL,
            borrado INTEGER NOT NULL DEFAULT 0, datos TEXT NOT NULL DEFAULT \'{}\',
            por INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (reunion, eid))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS piz_elementos_v ON piz_elementos (reunion, v)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS piz_meta (reunion INTEGER PRIMARY KEY, version INTEGER NOT NULL DEFAULT 0, actualizado TEXT)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS piz_presencia (reunion INTEGER NOT NULL, miembro INTEGER NOT NULL, t INTEGER NOT NULL, PRIMARY KEY (reunion, miembro))');
        // Recordatorios puestos en una nota (campo «aviso»): se avisan a su hora (avisarRecordatorios)
        $pdo->exec('CREATE TABLE IF NOT EXISTS piz_avisos (reunion INTEGER NOT NULL, eid TEXT NOT NULL, cuando TEXT NOT NULL, para INTEGER NOT NULL, texto TEXT NOT NULL DEFAULT \'\', enviado INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (reunion, eid))');
        $pdo->exec('CREATE INDEX IF NOT EXISTS piz_avisos_cuando ON piz_avisos (enviado, cuando)');
        return self::$pdo = $pdo;
    }

    /* ---------- Permisos ---------- */

    /** La clave de la pizarra: la de la reunión, o -id para «Mis notas» de quien tiene la sesión. */
    public static function rid(bool $mia, int $reunionId = 0): int
    {
        return $mia ? -(int)(Auth::usuario()['id'] ?? 0) : $reunionId;
    }

    /**
     * La pizarra de una nota de «Mis notas» (pizarra.php?nota=ID): -(1000000 + id),
     * lejos de las reuniones (positivas) y de la personal de cada quien (-id del miembro).
     */
    public static function ridNota(int $idNota): int
    {
        return -(1000000 + $idNota);
    }

    /** La nota, si es de quien tiene la sesión (solo su dueño ve y edita su pizarra; «ver como» no cuenta). */
    public static function notaPropia(int $idNota): ?array
    {
        if (!is_file(__DIR__ . '/Notas.php')) return null;
        require_once __DIR__ . '/Notas.php';
        return Notas::propia($idNota, (int)(Auth::usuario()['id'] ?? 0));
    }

    /** La nota de un enlace compartido que tiene pizarra (nota_ver.php): se mira sin sesión, nunca se edita. */
    public static function notaCompartida(string $token): ?array
    {
        if (!is_file(__DIR__ . '/Notas.php')) return null;
        require_once __DIR__ . '/Notas.php';
        $n = Notas::porToken($token);
        return $n && !empty($n['pizarra']) ? $n : null;
    }


    /** La ve quien ve el proyecto de la reunión. */
    public static function puedeVer(?array $r): bool
    {
        return $r !== null && Auth::usuario() !== null && puedeVerProyecto((int)($r['proyecto_id'] ?? 0));
    }

    /** La editan los invitados, quien la creó, quien lleva las reuniones del proyecto y el administrador. */
    public static function puedeEditar(?array $r): bool
    {
        if (!self::puedeVer($r)) return false;
        if (Auth::esAdmin()) return true;
        $yo = (int)(Auth::usuario()['id'] ?? 0);
        if ($yo > 0 && (in_array($yo, array_map('intval', (array)($r['invitados'] ?? [])), true) || (int)($r['creador_id'] ?? 0) === $yo)) return true;
        return puedeReunionesDelProyecto((int)($r['proyecto_id'] ?? 0));
    }

    /* ---------- Leer ---------- */

    /** Cuántas cosas vivas tiene (la nota con la pizarra vacía no carga la vista previa). */
    public static function cuantos(int $reunion): int
    {
        $st = self::db()->prepare('SELECT COUNT(*) FROM piz_elementos WHERE reunion = ? AND borrado = 0');
        $st->execute([$reunion]);
        return (int)$st->fetchColumn();
    }

    /** Al borrar una nota se va su pizarra entera, con sus recordatorios. */
    public static function borrarTodo(int $reunion): void
    {
        $db = self::db();
        foreach (['piz_elementos', 'piz_meta', 'piz_presencia', 'piz_avisos'] as $t) {
            $db->prepare("DELETE FROM $t WHERE reunion = ?")->execute([$reunion]);
        }
    }

    public static function version(int $reunion): int
    {
        $st = self::db()->prepare('SELECT version FROM piz_meta WHERE reunion = ?');
        $st->execute([$reunion]);
        return (int)($st->fetchColumn() ?: 0);
    }

    /**
     * Lo que cambió después de $desde (con 0, todo lo vivo). Los borrados
     * salen como {id, borrado:true, v}; con 0 no hace falta mandarlos.
     */
    public static function cambios(int $reunion, int $desde = 0): array
    {
        $sql = 'SELECT eid, v, borrado, datos FROM piz_elementos WHERE reunion = ? AND v > ?' . ($desde > 0 ? '' : ' AND borrado = 0') . ' ORDER BY v';
        $st = self::db()->prepare($sql);
        $st->execute([$reunion, $desde]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
            if ((int)$f['borrado']) { $out[] = ['id' => $f['eid'], 'borrado' => true, 'v' => (int)$f['v']]; continue; }
            $d = json_decode((string)$f['datos'], true);
            if (!is_array($d)) continue;
            $out[] = ['id' => $f['eid'], 'v' => (int)$f['v']] + $d;
        }
        return $out;
    }

    /* ---------- Guardar ---------- */

    /**
     * Aplica una tanda de cambios (cada uno un elemento completo, o {id,
     * borrado:true}) y devuelve la versión nueva. Todo en una transacción:
     * si dos guardan a la vez, el segundo espera al primero.
     */
    public static function guardar(int $reunion, array $cambios, int $por): int
    {
        $db = self::db();
        $db->exec('BEGIN IMMEDIATE');
        try {
            $v = self::version($reunion);
            $vivos = (int)$db->query('SELECT COUNT(*) FROM piz_elementos WHERE borrado = 0 AND reunion = ' . $reunion)->fetchColumn();
            $existe = $db->prepare('SELECT borrado FROM piz_elementos WHERE reunion = ? AND eid = ?');
            $poner = $db->prepare('INSERT INTO piz_elementos (reunion, eid, v, borrado, datos, por) VALUES (?, ?, ?, ?, ?, ?)
                ON CONFLICT (reunion, eid) DO UPDATE SET v = excluded.v, borrado = excluded.borrado, datos = excluded.datos, por = excluded.por');
            $ponerAviso = $db->prepare('INSERT INTO piz_avisos (reunion, eid, cuando, para, texto) VALUES (?, ?, ?, ?, ?)
                ON CONFLICT (reunion, eid) DO UPDATE SET enviado = CASE WHEN piz_avisos.cuando <> excluded.cuando THEN 0 ELSE piz_avisos.enviado END,
                cuando = excluded.cuando, para = excluded.para, texto = excluded.texto');
            $quitarAviso = $db->prepare('DELETE FROM piz_avisos WHERE reunion = ? AND eid = ?');
            foreach (array_slice($cambios, 0, 500) as $c) {
                if (!is_array($c)) continue;
                $eid = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($c['id'] ?? ''));
                if ($eid === '' || strlen($eid) > 64) continue;
                $existe->execute([$reunion, $eid]);
                $antes = $existe->fetchColumn();         // false: no existía; '0' vivo; '1' borrado
                $existe->closeCursor();
                if (!empty($c['borrado'])) {
                    if ($antes === false) continue;
                    $poner->execute([$reunion, $eid, ++$v, 1, '{}', $por]);
                    if ((string)$antes === '0') $vivos--;
                    $quitarAviso->execute([$reunion, $eid]);
                    continue;
                }
                unset($c['id'], $c['v'], $c['borrado']);
                $json = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                if ($json === false || strlen($json) > self::MAX_BYTES_ELEMENTO) continue;
                $nuevo = $antes === false || (string)$antes === '1';
                if ($nuevo && $vivos >= self::MAX_ELEMENTOS) continue;
                $poner->execute([$reunion, $eid, ++$v, 0, $json, $por]);
                if ($nuevo) $vivos++;
                // El recordatorio de la nota: si cambia la hora, se vuelve a avisar
                $cuando = DateTime::createFromFormat('!Y-m-d H:i', trim((string)($c['aviso'] ?? '')));
                if ($cuando) {
                    $para = (int)($c['aviso_por'] ?? 0) ?: ($reunion < 0 && $reunion > -1000000 ? -$reunion : $por);
                    $texto = mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags((string)($c['texto'] ?? '')))), 0, 200);
                    $ponerAviso->execute([$reunion, $eid, $cuando->format('Y-m-d H:i'), $para, $texto]);
                } else {
                    $quitarAviso->execute([$reunion, $eid]);
                }
            }
            $db->prepare('INSERT INTO piz_meta (reunion, version, actualizado) VALUES (?, ?, ?)
                ON CONFLICT (reunion) DO UPDATE SET version = excluded.version, actualizado = excluded.actualizado')
               ->execute([$reunion, $v, date('Y-m-d H:i:s')]);
            $db->exec('COMMIT');
            return $v;
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    /* ---------- Quién está ---------- */

    /** Anota que $mid la tiene abierta y devuelve a los demás que la miran ahora. */
    public static function presentes(int $reunion, int $mid): array
    {
        $db = self::db();
        if ($mid > 0) {
            $db->prepare('INSERT INTO piz_presencia (reunion, miembro, t) VALUES (?, ?, ?)
                ON CONFLICT (reunion, miembro) DO UPDATE SET t = excluded.t')->execute([$reunion, $mid, time()]);
        }
        $st = $db->prepare('SELECT miembro FROM piz_presencia WHERE reunion = ? AND miembro <> ? AND t >= ? ORDER BY t DESC');
        $st->execute([$reunion, $mid, time() - self::PRESENTE_SEG]);
        $mapa = (new MiembroRepo())->mapa();
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $m = $mapa[(int)$id] ?? null;
            if (!$m) continue;
            $out[] = [
                'id'        => (int)$id,
                'nombre'    => (string)$m['nombre'],
                'iniciales' => MiembroRepo::iniciales($m),
                'color'     => Catalogo::colorDe($m['color'] ?? 0),
                'foto'      => (string)($m['foto'] ?? ''),
            ];
        }
        return $out;
    }

    /* ---------- Imágenes ---------- */

    /**
     * Sube una imagen pegada o soltada en la pizarra (uploads/piz_…). Valida
     * el tipo real del archivo, no lo que dice el navegador.
     * Devuelve ['url', 'ancho', 'alto'] o lanza RuntimeException con el motivo.
     */
    public static function subirImagen(array $f): array
    {
        $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('La imagen pesa demasiado (límite del servidor: ' . ini_get('upload_max_filesize') . ').');
        if ($err !== UPLOAD_ERR_OK || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('No llegó la imagen.');
        if ((int)($f['size'] ?? 0) > 8 * 1024 * 1024) throw new RuntimeException('La imagen pasa de 8 MB.');
        $tipos = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = (string)mime_content_type($f['tmp_name']);
        if (!isset($tipos[$mime])) throw new RuntimeException('Formato no soportado. Usa PNG, JPG, WebP o GIF.');
        $dim = @getimagesize($f['tmp_name']) ?: [0, 0];
        $nombre = 'uploads/piz_' . bin2hex(random_bytes(8)) . '.' . $tipos[$mime];
        if (!is_dir(__DIR__ . '/../uploads')) @mkdir(__DIR__ . '/../uploads', 0775, true);
        if (!move_uploaded_file($f['tmp_name'], __DIR__ . '/../' . $nombre)) throw new RuntimeException('No se pudo guardar la imagen en el servidor.');
        return ['url' => $nombre, 'ancho' => (int)$dim[0], 'alto' => (int)$dim[1]];
    }

    /* ---------- Recordatorios ---------- */

    /**
     * Los recordatorios que ya tocan: a quien lo puso, en la campana, como
     * notificación nativa (Notif::crear la manda a sus dispositivos) y por
     * correo. Lo llama cron_correo.php cada minuto. Devuelve cuántos avisó.
     */
    public static function avisarRecordatorios(): int
    {
        $db = self::db();
        $st = $db->prepare('SELECT reunion, eid, cuando, para, texto FROM piz_avisos WHERE enviado = 0 AND cuando <= ? ORDER BY cuando LIMIT 50');
        $st->execute([date('Y-m-d H:i')]);
        $filas = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!$filas) return 0;
        if (is_file(__DIR__ . '/Notificaciones.php')) require_once __DIR__ . '/Notificaciones.php';
        $hecho = $db->prepare('UPDATE piz_avisos SET enviado = 1 WHERE reunion = ? AND eid = ?');
        $miembros = (new MiembroRepo())->mapa();
        $reuniones = new ReunionRepo();
        $n = 0;
        foreach ($filas as $f) {
            $hecho->execute([(int)$f['reunion'], $f['eid']]);   // antes de avisar: si algo falla, no se repite cada minuto
            $m = $miembros[(int)$f['para']] ?? null;
            if (!$m) continue;
            $rid = (int)$f['reunion'];
            $idNota = $rid <= -1000000 ? -$rid - 1000000 : 0;   // la pizarra de una nota (ridNota)
            $donde = $idNota ? 'Pizarra de tu nota' : ($rid < 0 ? 'Mis notas' : 'Pizarra de «' . (string)(($reuniones->buscar($rid) ?? [])['topic'] ?? 'la reunión') . '»');
            $url = $idNota ? 'pizarra.php?nota=' . $idNota : ($rid < 0 ? 'pizarra.php?mia=1' : 'pizarra.php?reunion=' . $rid);
            $texto = (string)$f['texto'] !== '' ? (string)$f['texto'] : 'Tienes un recordatorio';
            if (class_exists('Notif')) Notif::crear([(int)$f['para']], 'recordatorio', $texto, $donde, $url, 0);
            try { Mailer::recordatorioNota($m, $texto, $donde, $url); } catch (\Throwable $e) { error_log('Recordatorio de nota: ' . $e->getMessage()); }
            $n++;
        }
        return $n;
    }

    /* ---------- Tareas para el kanban ---------- */

    /** En «Mis notas»: las tareas abiertas de la persona en todos sus proyectos. */
    public static function tareasDe(int $mid): array
    {
        $finales = Catalogo::estadosFinales();
        $proyectos = [];
        foreach ((new ProyectoRepo())->todos() as $p) $proyectos[(int)$p['id']] = $p;
        $out = [];
        foreach ((new TareaRepo())->todas() as $t) {
            $pid = (int)($t['proyecto_id'] ?? 0);
            if (!isset($proyectos[$pid]) || !TareaRepo::tieneAsignado($t, $mid) || in_array($t['estado'] ?? '', $finales, true)) continue;
            if (!puedeVerProyecto($pid)) continue;
            $out[] = [
                'id'          => (int)$t['id'],
                'titulo'      => (string)($t['titulo'] ?? ''),
                'estado'      => (string)($t['estado'] ?? 'pendiente'),
                'quien'       => '',
                'proyecto'    => (string)($proyectos[$pid]['nombre'] ?? ''),
                'proyecto_id' => $pid,
            ];
        }
        return $out;
    }


    public static function tareas(int $proyectoId): array
    {
        $mapa = (new MiembroRepo())->mapa();
        $out = [];
        foreach ((new TareaRepo())->delProyecto($proyectoId) as $t) {
            $quien = [];
            foreach (TareaRepo::asignadosDe($t) as $mid) {
                if (isset($mapa[$mid])) $quien[] = explode(' ', trim((string)$mapa[$mid]['nombre']))[0];
            }
            $out[] = [
                'id'     => (int)$t['id'],
                'titulo' => (string)($t['titulo'] ?? ''),
                'estado' => (string)($t['estado'] ?? 'pendiente'),
                'quien'  => implode(', ', $quien),
            ];
        }
        return $out;
    }
}
