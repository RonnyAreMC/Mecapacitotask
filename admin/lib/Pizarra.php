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
        return self::$pdo = $pdo;
    }

    /* ---------- Permisos ---------- */

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
                    continue;
                }
                unset($c['id'], $c['v'], $c['borrado']);
                $json = json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                if ($json === false || strlen($json) > self::MAX_BYTES_ELEMENTO) continue;
                $nuevo = $antes === false || (string)$antes === '1';
                if ($nuevo && $vivos >= self::MAX_ELEMENTOS) continue;
                $poner->execute([$reunion, $eid, ++$v, 0, $json, $por]);
                if ($nuevo) $vivos++;
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

    /* ---------- Tareas del proyecto (para el kanban) ---------- */

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
