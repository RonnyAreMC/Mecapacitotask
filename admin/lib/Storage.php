<?php
/**
 * JsonStore - persistencia en SQLite con la MISMA interfaz que antes.
 *
 * Antes cada coleccion era un .json que se reescribia entero, y dos guardados
 * simultaneos podian pisarse (se perdian datos o salian ids duplicados). Ahora
 * todo vive en un unico archivo SQLite (data/panel.sqlite), una tabla por
 * coleccion, con transacciones y bloqueo real: sigue siendo "liviano" (un solo
 * archivo, sin servidor de BD) pero aguanta varios usuarios a la vez.
 *
 * El nombre de la clase se conserva (JsonStore) y los metodos son identicos
 * (all/find/where/insert/update/delete/deleteWhere), asi el resto del panel no
 * cambia. Cada registro se guarda como JSON en la columna 'data'; el 'id' lo
 * pone SQLite (AUTOINCREMENT), lo que elimina los ids duplicados.
 *
 * La primera vez que se crea una tabla, si existe el .json viejo de esa
 * coleccion, se importan sus registros y el .json se renombra a .importado
 * (migracion automatica, una sola vez).
 */
class JsonStore
{
    private string $tabla;
    private static ?PDO $pdo = null;
    /** Cache por peticion: tabla => lista de registros ya decodificados. */
    private static array $cache = [];

    public function __construct(string $collection)
    {
        // Nombre de tabla seguro (las colecciones son fijas, pero por si acaso)
        $this->tabla = preg_replace('/[^a-z0-9_]/', '', strtolower($collection)) ?: 'datos';
        self::conectar();
        $this->asegurarTabla($collection);
    }

    /** ¿El servidor tiene el driver de SQLite? (para el script de migracion). */
    public static function disponible(): bool
    {
        return in_array('sqlite', PDO::getAvailableDrivers(), true);
    }

    private static function conectar(): void
    {
        if (self::$pdo) return;
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $pdo = new PDO('sqlite:' . $dir . '/panel.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // WAL: los que leen no se bloquean con el que escribe.
        // busy_timeout: si dos escriben a la vez, el segundo espera en vez de fallar.
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        self::$pdo = $pdo;
    }

    private function asegurarTabla(string $collection): void
    {
        $q = self::$pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name=" . self::$pdo->quote($this->tabla));
        if ($q->fetch()) {
            return;   // ya existe
        }
        self::$pdo->exec("CREATE TABLE {$this->tabla} (id INTEGER PRIMARY KEY AUTOINCREMENT, data TEXT NOT NULL)");
        $this->importarLegacy($collection);
    }

    /** Migra el .json viejo a la tabla (una sola vez) conservando los ids. */
    private function importarLegacy(string $collection): void
    {
        $json = __DIR__ . '/../data/' . $collection . '.json';
        if (!is_file($json)) return;
        $data = json_decode((string)file_get_contents($json), true);
        if (!is_array($data) || empty($data['items'])) return;

        $st = self::$pdo->prepare("INSERT INTO {$this->tabla} (id, data) VALUES (?, ?)");
        self::$pdo->beginTransaction();
        foreach ($data['items'] as $item) {
            $id = (int)($item['id'] ?? 0);
            if ($id <= 0) continue;
            unset($item['id']);   // el id va en su columna
            $st->execute([$id, json_encode($item, JSON_UNESCAPED_UNICODE)]);
        }
        self::$pdo->commit();
        // No volver a importar en el futuro
        @rename($json, $json . '.importado');
    }

    /** Todos los registros (con su id), cacheado por peticion. */
    private function items(): array
    {
        if (isset(self::$cache[$this->tabla])) {
            return self::$cache[$this->tabla];
        }
        $rows = self::$pdo->query("SELECT id, data FROM {$this->tabla} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($rows as $row) {
            $it = json_decode($row['data'], true);
            if (is_array($it)) {
                $it['id'] = (int)$row['id'];
                $items[] = $it;
            }
        }
        return self::$cache[$this->tabla] = $items;
    }

    private function invalidar(): void
    {
        unset(self::$cache[$this->tabla]);
    }

    public function all(): array
    {
        return $this->items();
    }

    public function find(int $id): ?array
    {
        $st = self::$pdo->prepare("SELECT id, data FROM {$this->tabla} WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $it = json_decode($row['data'], true) ?: [];
        $it['id'] = (int)$row['id'];
        return $it;
    }

    public function where(string $field, mixed $value): array
    {
        return array_values(array_filter(
            $this->items(),
            fn($item) => ($item[$field] ?? null) == $value
        ));
    }

    public function insert(array $record): array
    {
        unset($record['id']);   // lo asigna SQLite
        $record['creado'] = $record['creado'] ?? date('Y-m-d H:i');
        $st = self::$pdo->prepare("INSERT INTO {$this->tabla} (data) VALUES (?)");
        $st->execute([json_encode($record, JSON_UNESCAPED_UNICODE)]);
        $record['id'] = (int)self::$pdo->lastInsertId();
        $this->invalidar();
        return $record;
    }

    /**
     * Mezcla $changes sobre el registro con ese id, bajo una transaccion
     * IMMEDIATE: si dos personas editan a la vez, el segundo espera al primero
     * en vez de pisarlo (esto es lo que el JSON no podia garantizar).
     */
    public function update(int $id, array $changes): bool
    {
        self::$pdo->exec('BEGIN IMMEDIATE');
        try {
            $st = self::$pdo->prepare("SELECT data FROM {$this->tabla} WHERE id = ?");
            $st->execute([$id]);
            $raw = $st->fetchColumn();
            if ($raw === false) {
                self::$pdo->exec('ROLLBACK');
                return false;
            }
            $item = json_decode($raw, true) ?: [];
            $item = array_merge($item, $changes);
            unset($item['id']);
            $up = self::$pdo->prepare("UPDATE {$this->tabla} SET data = ? WHERE id = ?");
            $up->execute([json_encode($item, JSON_UNESCAPED_UNICODE), $id]);
            self::$pdo->exec('COMMIT');
        } catch (\Throwable $e) {
            self::$pdo->exec('ROLLBACK');
            throw $e;
        }
        $this->invalidar();
        return true;
    }

    public function delete(int $id): bool
    {
        $st = self::$pdo->prepare("DELETE FROM {$this->tabla} WHERE id = ?");
        $st->execute([$id]);
        $this->invalidar();
        return $st->rowCount() > 0;
    }

    /** Borra todos los registros que cumplen campo = valor. */
    public function deleteWhere(string $field, mixed $value): int
    {
        $ids = [];
        foreach ($this->items() as $item) {
            if (($item[$field] ?? null) == $value) {
                $ids[] = (int)$item['id'];
            }
        }
        if (!$ids) return 0;
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        $st = self::$pdo->prepare("DELETE FROM {$this->tabla} WHERE id IN ($marcas)");
        $st->execute($ids);
        $this->invalidar();
        return $st->rowCount();
    }
}
