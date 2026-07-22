<?php
/**
 * JsonStore - persistencia simple en archivos JSON con bloqueo de archivo.
 * Cada coleccion (proyectos, tareas, miembros) vive en su propio .json
 * dentro de admin/data/. No requiere base de datos.
 */
class JsonStore
{
    private string $file;

    /**
     * Cache por peticion: ruta del .json => contenido ya decodificado.
     *
     * Una pagina como el dashboard preguntaba por las tareas decenas de
     * veces (resumen, avance y completadas de cada proyecto), y cada
     * llamada releia y decodificaba el archivo entero. Dentro de una misma
     * peticion el contenido no cambia salvo que escribamos nosotros, asi
     * que se guarda aqui y write() lo actualiza.
     */
    private static array $cache = [];

    public function __construct(string $collection)
    {
        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $this->file = $dir . '/' . $collection . '.json';
        if (!file_exists($this->file)) {
            file_put_contents($this->file, json_encode(['seq' => 0, 'items' => []]));
        }
    }

    /** @return array{seq:int, items:array<int,array>} */
    private function read(): array
    {
        if (isset(self::$cache[$this->file])) {
            return self::$cache[$this->file];
        }
        $raw = file_get_contents($this->file);
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['items'])) {
            $data = ['seq' => 0, 'items' => []];
        }
        return self::$cache[$this->file] = $data;
    }

    private function write(array $data): void
    {
        $fp = fopen($this->file, 'c+');
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        self::$cache[$this->file] = $data;   // lo escrito es lo vigente
    }

    /** Todos los registros. */
    public function all(): array
    {
        return array_values($this->read()['items']);
    }

    /** Busca un registro por id. */
    public function find(int $id): ?array
    {
        foreach ($this->read()['items'] as $item) {
            if ((int)($item['id'] ?? 0) === $id) {
                return $item;
            }
        }
        return null;
    }

    /** Registros que cumplen campo = valor. */
    public function where(string $field, mixed $value): array
    {
        return array_values(array_filter(
            $this->read()['items'],
            fn($item) => ($item[$field] ?? null) == $value
        ));
    }

    /** Inserta y devuelve el registro con su id nuevo. */
    public function insert(array $record): array
    {
        $data = $this->read();
        $data['seq']++;
        $record['id'] = $data['seq'];
        $record['creado'] = $record['creado'] ?? date('Y-m-d H:i');
        $data['items'][] = $record;
        $this->write($data);
        return $record;
    }

    /** Mezcla $changes sobre el registro con ese id. */
    public function update(int $id, array $changes): bool
    {
        $data = $this->read();
        foreach ($data['items'] as $i => $item) {
            if ((int)($item['id'] ?? 0) === $id) {
                $data['items'][$i] = array_merge($item, $changes);
                $this->write($data);
                return true;
            }
        }
        return false;
    }

    public function delete(int $id): bool
    {
        $data = $this->read();
        $before = count($data['items']);
        $data['items'] = array_values(array_filter(
            $data['items'],
            fn($item) => (int)($item['id'] ?? 0) !== $id
        ));
        if (count($data['items']) === $before) {
            return false;
        }
        $this->write($data);
        return true;
    }

    /** Borra todos los registros que cumplen campo = valor. */
    public function deleteWhere(string $field, mixed $value): int
    {
        $data = $this->read();
        $before = count($data['items']);
        $data['items'] = array_values(array_filter(
            $data['items'],
            fn($item) => ($item[$field] ?? null) != $value
        ));
        $this->write($data);
        return $before - count($data['items']);
    }
}
