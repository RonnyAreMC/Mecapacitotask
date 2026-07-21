<?php
/**
 * Repositorios de dominio: Proyectos, Miembros y Tareas.
 * Encapsulan la logica de cada coleccion sobre JsonStore.
 */
require_once __DIR__ . '/Storage.php';

/* =========================================================
   Catalogos compartidos (estados, prioridades, colores)
   ========================================================= */
final class Catalogo
{
    /** Estados de tarea: clave => [etiqueta, icono] */
    public const ESTADOS_TAREA = [
        'pendiente' => ['Por hacer',   'fa-circle-dot'],
        'progreso'  => ['En progreso', 'fa-spinner'],
        'revision'  => ['En revision', 'fa-magnifying-glass-chart'],
        'hecho'     => ['Completada',  'fa-circle-check'],
    ];

    public const PRIORIDADES = [
        'baja'  => ['Baja',  'fa-angle-down'],
        'media' => ['Media', 'fa-equals'],
        'alta'  => ['Alta',  'fa-angles-up'],
    ];

    public const ESTADOS_PROYECTO = [
        'activo'     => ['Activo',     'fa-bolt'],
        'pausado'    => ['Pausado',    'fa-pause'],
        'completado' => ['Completado', 'fa-flag-checkered'],
    ];

    /** Iconos disponibles para proyectos */
    public const ICONOS_PROYECTO = [
        'fa-rocket', 'fa-store', 'fa-graduation-cap', 'fa-cart-shopping',
        'fa-mobile-screen', 'fa-globe', 'fa-server', 'fa-robot',
        'fa-truck-fast', 'fa-heart-pulse', 'fa-gamepad', 'fa-chart-line',
    ];

    /**
     * Paleta amplia de colores planos para avatares y proyectos.
     * Los 6 primeros son los originales: NO cambiar su orden
     * (los registros guardados referencian su indice).
     */
    public const COLORES = [
        '#1A4B99', '#2B76F7', '#2BB673', '#F7931E', '#C66B2D', '#2D3E50',
        '#40CFFF', '#7AC943', '#FFD700', '#E63946', '#FF6B6B', '#E91E8C',
        '#B5179E', '#8E44AD', '#6C5CE7', '#4834D4', '#1ABC9C', '#0E9594',
        '#3498DB', '#FF9F1C', '#8D6E63', '#607D8B',
    ];

    /** Resuelve el color guardado (indice de la paleta o hex custom) a hex. */
    public static function colorDe(int|string $valor): string
    {
        if (is_string($valor) && preg_match('/^#[0-9a-fA-F]{6}$/', $valor)) {
            return $valor;
        }
        return self::COLORES[((int)$valor) % count(self::COLORES)];
    }

    /** Normaliza el color que llega de un formulario: indice o "custom" + hex. */
    public static function colorEntrada(array $datos): int|string
    {
        $c = $datos['color'] ?? 0;
        if ($c === 'custom') {
            $hex = $datos['color_hex'] ?? '';
            return preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? strtoupper($hex) : 0;
        }
        return (int)$c;
    }

    /* ----- Catalogos parametrizables y AMPLIABLES (leen Config) ----- */

    /** Estados de tarea (dinamicos): clave => [etiqueta, icono]. */
    public static function estadosTarea(): array
    {
        $out = [];
        foreach ((array)Config::get('estados_tarea') as $k => $v) {
            $out[$k] = [$v['label'] ?? ucfirst($k), $v['icono'] ?? 'fa-circle-dot'];
        }
        return $out ?: self::ESTADOS_TAREA;
    }

    /** Claves de estados que cuentan como "completada" (para el % de avance). */
    public static function estadosFinales(): array
    {
        $finales = [];
        foreach ((array)Config::get('estados_tarea') as $k => $v) {
            if (!empty($v['final'])) $finales[] = $k;
        }
        return $finales ?: ['hecho'];
    }

    /** Prioridades (dinamicas): clave => [etiqueta, icono]. */
    public static function prioridades(): array
    {
        $out = [];
        foreach ((array)Config::get('prioridades') as $k => $v) {
            $out[$k] = [$v['label'] ?? ucfirst($k), $v['icono'] ?? 'fa-equals'];
        }
        return $out ?: self::PRIORIDADES;
    }

    /** Estados de proyecto (dinamicos): clave => [etiqueta, icono]. */
    public static function estadosProyecto(): array
    {
        $out = [];
        foreach ((array)Config::get('estados_proyecto') as $k => $v) {
            if (is_string($v)) {
                $out[$k] = [$v, self::ESTADOS_PROYECTO[$k][1] ?? 'fa-bolt'];
            } else {
                $out[$k] = [$v['label'] ?? ucfirst($k), $v['icono'] ?? 'fa-bolt'];
            }
        }
        return $out ?: self::ESTADOS_PROYECTO;
    }

    /** Iconos de proyecto configurables (clases Font Awesome). */
    public static function iconosProyecto(): array
    {
        $iconos = Config::get('iconos');
        return is_array($iconos) && $iconos !== [] ? $iconos : self::ICONOS_PROYECTO;
    }

    /** Roles sugeridos para colaboradores. */
    public static function roles(): array
    {
        $roles = Config::get('roles');
        return is_array($roles) && $roles !== [] ? $roles : [];
    }

    /** Equipos de trabajo (dinamicos): clave => [etiqueta, icono]. */
    public static function equipos(): array
    {
        $out = [];
        foreach ((array)Config::get('equipos') as $k => $v) {
            $out[$k] = [$v['label'] ?? ucfirst($k), $v['icono'] ?? 'fa-users'];
        }
        return $out ?: ['programacion' => ['Programadores', 'fa-code']];
    }
}

/* =========================================================
   Config - parametros del panel (data/config.json)
   Todo lo visual/textual del panel se puede parametrizar aqui.
   ========================================================= */
final class Config
{
    private static ?array $cache = null;

    public static function defaults(): array
    {
        return [
            'titulo'           => 'Mecapacito',
            'subtitulo'        => 'Panel Dev',
            'color_secundario' => '#2B76F7',   // acento principal (botones, links)
            'color_acento'     => '#FFD700',   // acento secundario
            'estados_tarea' => [
                'pendiente' => ['label' => 'Por hacer',   'color' => '#0B7EA8', 'icono' => 'fa-circle-dot',              'final' => false],
                'progreso'  => ['label' => 'En progreso', 'color' => '#2B76F7', 'icono' => 'fa-spinner',                 'final' => false],
                'revision'  => ['label' => 'En revision', 'color' => '#C26F0E', 'icono' => 'fa-magnifying-glass-chart',  'final' => false],
                'hecho'     => ['label' => 'Completada',  'color' => '#2BB673', 'icono' => 'fa-circle-check',            'final' => true],
            ],
            'prioridades' => [
                'baja'  => ['label' => 'Baja',  'color' => '#4E8A24', 'icono' => 'fa-angle-down'],
                'media' => ['label' => 'Media', 'color' => '#A8860A', 'icono' => 'fa-equals'],
                'alta'  => ['label' => 'Alta',  'color' => '#C66B2D', 'icono' => 'fa-angles-up'],
            ],
            'estados_proyecto' => [
                'activo'     => ['label' => 'Activo',     'icono' => 'fa-bolt'],
                'pausado'    => ['label' => 'Pausado',    'icono' => 'fa-pause'],
                'completado' => ['label' => 'Completado', 'icono' => 'fa-flag-checkered'],
            ],
            'equipos' => [
                'programacion' => ['label' => 'Programadores', 'icono' => 'fa-code'],
                'analistas'    => ['label' => 'Analistas',     'icono' => 'fa-chart-line'],
            ],
            'iconos' => Catalogo::ICONOS_PROYECTO,
            'roles'  => ['Tech Lead', 'Frontend Dev', 'Backend Dev', 'Full Stack Developer', 'QA', 'DevOps', 'UI/UX Designer', 'Analista Funcional', 'Analista de Datos'],
            'correo' => [
                'activo'    => false,
                'modo'      => 'smtp',
                'host'      => 'smtp.gmail.com',
                'puerto'    => 587,
                'usuario'   => '',
                'clave'     => '',
                'remitente' => 'Panel Mecapacito',
                'url_panel' => '',
                'client_id'     => '',
                'client_secret' => '',
                'refresh_token' => '',
            ],
        ];
    }

    private static function archivo(): string
    {
        return __DIR__ . '/../data/config.json';
    }

    /** Config completa: lo guardado se mezcla sobre los defaults. */
    public static function all(): array
    {
        if (self::$cache !== null) return self::$cache;
        $def = self::defaults();
        $guardado = [];
        if (file_exists(self::archivo())) {
            $guardado = json_decode((string)file_get_contents(self::archivo()), true) ?: [];
        }
        $mezcla = array_replace_recursive($def, $guardado);
        // Las listas y catalogos se reemplazan completos: replace_recursive los
        // mezclaria con los defaults y "reviviria" entradas eliminadas.
        foreach (['iconos', 'roles'] as $k) {
            if (isset($guardado[$k]) && is_array($guardado[$k]) && $guardado[$k] !== []) {
                $mezcla[$k] = array_values($guardado[$k]);
            }
        }
        foreach (['estados_tarea', 'prioridades', 'estados_proyecto', 'equipos'] as $k) {
            if (isset($guardado[$k]) && is_array($guardado[$k]) && $guardado[$k] !== []) {
                $mezcla[$k] = $guardado[$k];
            }
        }
        self::$cache = $mezcla;
        return self::$cache;
    }

    public static function get(string $clave): mixed
    {
        return self::all()[$clave] ?? null;
    }

    public static function guardar(array $datos): void
    {
        file_put_contents(
            self::archivo(),
            json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
        self::$cache = null;
    }

    /** Vuelve a los valores por defecto. */
    public static function restaurar(): void
    {
        if (file_exists(self::archivo())) {
            @unlink(self::archivo());
        }
        self::$cache = null;
    }

    /** '#RRGGBB' => 'r, g, b' (para variables CSS con rgba). */
    public static function hexARgb(string $hex): string
    {
        $hex = ltrim($hex, '#');
        return hexdec(substr($hex, 0, 2)) . ', ' . hexdec(substr($hex, 2, 2)) . ', ' . hexdec(substr($hex, 4, 2));
    }
}

/* =========================================================
   Proyectos
   ========================================================= */
class ProyectoRepo
{
    private JsonStore $store;

    public function __construct()
    {
        $this->store = new JsonStore('proyectos');
    }

    public function todos(): array
    {
        $items = $this->store->all();
        usort($items, fn($a, $b) => strcmp($b['creado'] ?? '', $a['creado'] ?? ''));
        return $items;
    }

    public function buscar(int $id): ?array
    {
        return $this->store->find($id);
    }

    public function crear(array $datos): array
    {
        return $this->store->insert([
            'nombre'      => trim($datos['nombre'] ?? ''),
            'descripcion' => trim($datos['descripcion'] ?? ''),
            'repo'        => trim($datos['repo'] ?? ''),
            'estado'      => $datos['estado'] ?? 'activo',
            'icono'       => $datos['icono'] ?? 'fa-rocket',
            'color'       => Catalogo::colorEntrada($datos),
        ]);
    }

    public function actualizar(int $id, array $datos): bool
    {
        return $this->store->update($id, $datos);
    }

    /** Elimina el proyecto y sus tareas asociadas. */
    public function eliminar(int $id): bool
    {
        (new JsonStore('tareas'))->deleteWhere('proyecto_id', $id);
        return $this->store->delete($id);
    }

    /** Color plano del proyecto (diseno neumorfista, sin degradados). */
    public static function colorBase(array $p): string
    {
        return Catalogo::colorDe($p['color'] ?? 0);
    }
}

/* =========================================================
   Miembros del equipo
   ========================================================= */
class MiembroRepo
{
    private JsonStore $store;

    public function __construct()
    {
        $this->store = new JsonStore('miembros');
    }

    public function todos(): array
    {
        $items = $this->store->all();
        usort($items, fn($a, $b) => strcasecmp($a['nombre'] ?? '', $b['nombre'] ?? ''));
        return $items;
    }

    /** Mapa id => miembro, util para pintar tablas sin buscar uno por uno. */
    public function mapa(): array
    {
        $map = [];
        foreach ($this->store->all() as $m) {
            $map[$m['id']] = $m;
        }
        return $map;
    }

    public function buscar(int $id): ?array
    {
        return $this->store->find($id);
    }

    public function crear(array $datos): array
    {
        return $this->store->insert([
            'nombre'   => trim($datos['nombre'] ?? ''),
            'rol'      => trim($datos['rol'] ?? 'Developer'),
            'git_user' => ltrim(trim($datos['git_user'] ?? ''), '@'),
            'email'    => filter_var(trim($datos['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: '',
            'foto'     => $datos['foto'] ?? '',
            'color'    => Catalogo::colorEntrada($datos),
            'equipo'   => self::equipoValido($datos['equipo'] ?? ''),
        ]);
    }

    /** Clave de equipo valida (si no existe, el primero del catalogo). */
    public static function equipoValido(?string $equipo): string
    {
        $claves = array_keys(Catalogo::equipos());
        return in_array($equipo, $claves, true) ? $equipo : $claves[0];
    }

    /** Equipo del miembro (miembros antiguos sin campo caen al primero). */
    public static function equipoDe(array $m): string
    {
        return self::equipoValido($m['equipo'] ?? '');
    }

    public function actualizar(int $id, array $datos): bool
    {
        return $this->store->update($id, $datos);
    }

    /** Elimina al miembro y des-asigna sus tareas. */
    public function eliminar(int $id): bool
    {
        $tareas = new JsonStore('tareas');
        foreach ($tareas->where('asignado_id', $id) as $t) {
            $tareas->update((int)$t['id'], ['asignado_id' => 0]);
        }
        $m = $this->buscar($id);
        if ($m && !empty($m['foto']) && file_exists(__DIR__ . '/../' . $m['foto'])) {
            @unlink(__DIR__ . '/../' . $m['foto']);
        }
        return $this->store->delete($id);
    }

    public static function iniciales(array $m): string
    {
        $partes = preg_split('/\s+/', trim($m['nombre'] ?? '?'));
        $ini = mb_strtoupper(mb_substr($partes[0], 0, 1));
        if (count($partes) > 1) {
            $ini .= mb_strtoupper(mb_substr(end($partes), 0, 1));
        }
        return $ini;
    }
}

/* =========================================================
   Tareas
   ========================================================= */
class TareaRepo
{
    private JsonStore $store;

    public function __construct()
    {
        $this->store = new JsonStore('tareas');
    }

    public function delProyecto(int $proyectoId): array
    {
        $items = $this->store->where('proyecto_id', $proyectoId);
        $ordenEstado = array_flip(array_keys(Catalogo::estadosTarea()));
        $ordenPrio   = array_flip(array_reverse(array_keys(Catalogo::prioridades())));
        usort($items, function ($a, $b) use ($ordenEstado, $ordenPrio) {
            $e = ($ordenEstado[$a['estado']] ?? 99) <=> ($ordenEstado[$b['estado']] ?? 99);
            if ($e !== 0) return $e;
            return ($ordenPrio[$a['prioridad']] ?? 99) <=> ($ordenPrio[$b['prioridad']] ?? 99);
        });
        return $items;
    }

    public function todas(): array
    {
        return $this->store->all();
    }

    public function buscar(int $id): ?array
    {
        return $this->store->find($id);
    }

    public function crear(array $datos): array
    {
        return $this->store->insert([
            'proyecto_id' => (int)($datos['proyecto_id'] ?? 0),
            'titulo'      => trim($datos['titulo'] ?? ''),
            'descripcion' => trim($datos['descripcion'] ?? ''),
            'estado'      => $datos['estado'] ?? 'pendiente',
            'prioridad'   => $datos['prioridad'] ?? 'media',
            'asignado_id' => (int)($datos['asignado_id'] ?? 0),
            'fecha_limite'=> $datos['fecha_limite'] ?? '',
        ]);
    }

    public function actualizar(int $id, array $datos): bool
    {
        return $this->store->update($id, $datos);
    }

    public function eliminar(int $id): bool
    {
        return $this->store->delete($id);
    }

    /** Conteo por estado para un proyecto: ['pendiente'=>2, ...] */
    public function resumen(int $proyectoId): array
    {
        $r = array_fill_keys(array_keys(Catalogo::estadosTarea()), 0);
        foreach ($this->store->where('proyecto_id', $proyectoId) as $t) {
            $estado = $t['estado'] ?? array_key_first($r);
            $r[$estado] = ($r[$estado] ?? 0) + 1;
        }
        return $r;
    }

    /** Tareas en estados marcados como finales (completadas). */
    public function completadas(int $proyectoId): int
    {
        $finales = Catalogo::estadosFinales();
        $n = 0;
        foreach ($this->resumen($proyectoId) as $estado => $cuenta) {
            if (in_array($estado, $finales, true)) $n += $cuenta;
        }
        return $n;
    }

    /** Porcentaje de avance (tareas en estados finales / total). */
    public function avance(int $proyectoId): int
    {
        $total = array_sum($this->resumen($proyectoId));
        return $total === 0 ? 0 : (int)round($this->completadas($proyectoId) * 100 / $total);
    }
}
