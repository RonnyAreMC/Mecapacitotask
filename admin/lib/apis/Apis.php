<?php
/**
 * APIs de la empresa (apis.php): el administrador registra las claves de los
 * servicios que usan los proyectos (OpenAI, Google Maps, Zoom…) con su
 * documentación, y cada desarrollador ve las de SUS proyectos y las copia al
 * portapapeles con un clic. Las de ningún proyecto son para todos.
 *
 *   · La clave se guarda cifrada (AES-256-GCM, data/apis.secreto) y solo se
 *     manda al navegador cuando alguien la copia (apis_copiar en actions.php),
 *     después de revisar que puede verla. En la página va enmascarada.
 *   · Cada copia queda anotada («apis_copias»): quién, qué y cuándo. El
 *     administrador lo ve en la tarjeta.
 *   · Los logos son de Simple Icons (CC0): assets/apis/<proveedor>.svg, con el
 *     color de la marca (lib/apis/proveedores.php).
 *
 * Se carga con is_file (no desde bootstrap): si falta, el resto sigue igual.
 */
final class Apis
{
    public const AMBIENTES = ['produccion' => 'Producción', 'pruebas' => 'Pruebas'];

    private static function repo(): JsonStore
    {
        return new JsonStore('apis');
    }

    private static function copias(): JsonStore
    {
        return new JsonStore('apis_copias');
    }

    /* ---------------- Proveedores (con su logo) ---------------- */

    /** [clave => [nombre, color]] */
    public static function proveedores(): array
    {
        static $p = null;
        if ($p === null) $p = is_file(__DIR__ . '/proveedores.php') ? (array)require __DIR__ . '/proveedores.php' : [];
        return $p;
    }

    /** ['clave', 'nombre', 'color', 'logo' (ruta o '')]; lo que no está en el catálogo es «otra». */
    public static function proveedor(array $api): array
    {
        $k = (string)($api['proveedor'] ?? '');
        $p = self::proveedores();
        if (isset($p[$k])) return ['clave' => $k, 'nombre' => (string)$p[$k][0], 'color' => (string)$p[$k][1], 'logo' => 'assets/apis/' . $k . '.svg'];
        $otro = trim((string)($api['proveedor_otro'] ?? ''));
        return ['clave' => 'otra', 'nombre' => $otro !== '' ? $otro : 'Otra', 'color' => '#2B76F7', 'logo' => ''];
    }

    /* ---------------- Lectura ---------------- */

    /** Todas, por nombre. */
    public static function todas(): array
    {
        $l = self::repo()->all();
        usort($l, fn($a, $b) => strcasecmp((string)$a['nombre'], (string)$b['nombre']));
        return $l;
    }

    public static function buscar(int $id): ?array
    {
        return self::repo()->find($id);
    }

    public static function proyectosDe(array $api): array
    {
        return array_values(array_unique(array_filter(array_map('intval', (array)($api['proyectos'] ?? [])), fn($n) => $n > 0)));
    }

    /**
     * ¿La puede ver (y copiar) quien está en el panel? El administrador, todas;
     * el supervisor (cliente), ninguna; el resto, las de algún proyecto suyo y
     * las que no son de ningún proyecto (para todos).
     */
    public static function puedeVer(array $api): bool
    {
        if (!Auth::usuario() || Auth::esSupervisor()) return false;
        $alcance = alcanceProyectos();
        if ($alcance === null) return true;
        $ids = self::proyectosDe($api);
        if (!$ids) return true;
        foreach ($ids as $pid) if (isset($alcance[$pid])) return true;
        return false;
    }

    public static function visibles(): array
    {
        return array_values(array_filter(self::todas(), [self::class, 'puedeVer']));
    }

    /** La clave en claro (solo para copiarla). */
    public static function clave(array $api): string
    {
        return self::descifrar((string)($api['clave'] ?? ''));
    }

    /** «sk-p…a1B2»: lo que se ve en la página en vez de la clave. */
    public static function pista(string $clave): string
    {
        $n = mb_strlen($clave);
        if ($n === 0) return '';
        if ($n <= 8) return str_repeat('•', 8);
        return mb_substr($clave, 0, min(4, intdiv($n, 4))) . '••••••' . mb_substr($clave, -4);
    }

    /* ---------------- Escritura (solo administrador) ---------------- */

    /** Crea o actualiza ($id = 0: nueva). Devuelve la API o el motivo del error. */
    public static function guardar(int $id, array $d, int $autor): array|string
    {
        $actual = $id ? self::buscar($id) : null;
        if ($id && !$actual) return 'Esa API ya no existe.';
        $nombre = trim((string)preg_replace('/\s+/u', ' ', (string)($d['nombre'] ?? '')));
        if ($nombre === '') return 'Ponle un nombre a la API (ej. OpenAI para las actas).';
        $prov = (string)($d['proveedor'] ?? '');
        if (!isset(self::proveedores()[$prov])) $prov = 'otra';
        $provOtro = $prov === 'otra' ? mb_substr(trim((string)($d['proveedor_otro'] ?? '')), 0, 40) : '';
        $url = fn(string $k, string $que) => self::urlValida((string)($d[$k] ?? ''), $que);
        $urlBase = $url('url', 'la URL');
        if (is_array($urlBase)) return $urlBase[0];
        $docs = $url('docs', 'la documentación');
        if (is_array($docs)) return $docs[0];
        $existen = array_flip(array_map(fn($p) => (int)$p['id'], (new ProyectoRepo())->todos()));
        $proys = array_values(array_filter(self::proyectosDe(['proyectos' => $d['proyectos'] ?? []]), fn($n) => isset($existen[$n])));
        $datos = [
            'nombre'         => mb_substr($nombre, 0, 80),
            'proveedor'      => $prov,
            'proveedor_otro' => $provOtro,
            'uso'            => mb_substr(trim((string)($d['uso'] ?? '')), 0, 140),
            'ambiente'       => isset(self::AMBIENTES[(string)($d['ambiente'] ?? '')]) ? (string)$d['ambiente'] : 'produccion',
            'proyectos'      => $proys,
            'url'            => $urlBase,
            'docs'           => $docs,
            'notas'          => mb_substr(trim(str_replace("\r\n", "\n", (string)($d['notas'] ?? ''))), 0, 3000),
        ];
        // La clave: vacía al editar = se queda la que estaba
        $clave = trim((string)($d['clave'] ?? ''));
        if ($clave !== '') {
            if (mb_strlen($clave) > 4000) return 'Esa clave es demasiado larga.';
            $datos['clave'] = self::cifrar($clave);
            $datos['pista'] = self::pista($clave);
        } elseif (!empty($d['quitar_clave'])) {
            $datos['clave'] = '';
            $datos['pista'] = '';
        }
        if ($actual) {
            self::repo()->update($id, $datos + ['editado' => date('Y-m-d H:i'), 'editado_por' => $autor]);
            return self::buscar($id) ?? $datos;
        }
        return self::repo()->insert($datos + ['clave' => $datos['clave'] ?? '', 'pista' => $datos['pista'] ?? '',
                                              'creado' => date('Y-m-d H:i'), 'creado_por' => $autor]);
    }

    public static function quitar(int $id): ?array
    {
        $a = self::buscar($id);
        if ($a) {
            self::repo()->delete($id);
            self::copias()->deleteWhere('api', $id);
        }
        return $a;
    }

    /** '' (vacía), la URL normalizada, o [motivo] si no sirve. */
    private static function urlValida(string $v, string $que): string|array
    {
        $v = trim($v);
        if ($v === '') return '';
        if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
        if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('~^https?://[^\s<>"]+$~i', $v)) return ['Revisa ' . $que . ': tiene que ser un enlace (https://…).'];
        return mb_substr($v, 0, 500);
    }

    /* ---------------- Copias (quién copió qué) ---------------- */

    public static function anotarCopia(int $apiId, int $mid, string $que): void
    {
        self::copias()->insert(['api' => $apiId, 'quien' => $mid, 'que' => $que === 'url' ? 'url' : 'clave', 'fecha' => date('Y-m-d H:i')]);
    }

    /** Las últimas copias de una API (la más nueva primero) y cuántas van. */
    public static function copiasDe(int $apiId, int $max = 6): array
    {
        $l = array_values(array_filter(self::copias()->where('api', $apiId), fn($c) => ($c['que'] ?? '') === 'clave'));
        usort($l, fn($a, $b) => (int)$b['id'] <=> (int)$a['id']);
        return ['total' => count($l), 'ultimas' => array_slice($l, 0, $max)];
    }

    /* ---------------- Cifrado de las claves ---------------- */

    private static function secreto(): string
    {
        $f = __DIR__ . '/../../data/apis.secreto';
        if (!is_file($f)) {
            file_put_contents($f, base64_encode(random_bytes(32)));
            @chmod($f, 0600);
        }
        return (string)base64_decode(trim((string)file_get_contents($f)));
    }

    private static function cifrar(string $plano): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $ct = openssl_encrypt($plano, 'aes-256-gcm', self::secreto(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    private static function descifrar(string $guardado): string
    {
        if (!str_starts_with($guardado, 'v1:')) return '';
        $b = (string)base64_decode(substr($guardado, 3));
        if (strlen($b) < 29) return '';
        $r = openssl_decrypt(substr($b, 28), 'aes-256-gcm', self::secreto(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
        return $r === false ? '' : $r;
    }
}
