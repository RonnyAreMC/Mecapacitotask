<?php
/**
 * ImportadorTareas - crea tareas en lote a partir de un JSON de planificacion.
 *
 * El JSON referencia proyectos y personas POR NOMBRE (no por id), asi el mismo
 * archivo sirve en local y en produccion aunque los ids difieran. Formato:
 *
 * {
 *   "tareas": [
 *     {
 *       "proyecto": "SIGE",
 *       "titulo": "Login con Google",
 *       "descripcion": "…",                 (opcional)
 *       "prioridad": "alta",                (opcional: clave o etiqueta)
 *       "estado": "pendiente",              (opcional)
 *       "fecha_inicio": "2026-07-25",       (opcional, YYYY-MM-DD)
 *       "fecha_limite": "2026-08-01",       (opcional)
 *       "asignados": ["Kevin", "Dulce"],    (opcional, nombres)
 *       "ref": "t1",                        (opcional, id temporal en este lote)
 *       "depende_de": "t0"                  (opcional: ref o titulo de otra tarea del lote)
 *     }
 *   ]
 * }
 *
 * Tambien acepta un arreglo de tareas directo (sin la envoltura "tareas").
 *
 * Es "todo o nada": si alguna fila tiene un error grave (proyecto inexistente,
 * sin titulo) no se crea NINGUNA. Los avisos leves (un asignado que no existe,
 * una prioridad desconocida) no frenan la creacion, solo se reportan.
 */
final class ImportadorTareas
{
    private array $proyectos;   // [idNorm(nombre) => proyecto]
    private array $miembros;    // [idNorm(nombre) => miembro]
    private array $prioridades; // claves validas
    private array $estados;     // claves validas

    public function __construct(
        private ProyectoRepo $proyectosRepo,
        private MiembroRepo $miembrosRepo,
        private TareaRepo $tareasRepo,
    ) {
        $this->proyectos = [];
        foreach ($proyectosRepo->todos() as $p) {
            $this->proyectos[self::norm($p['nombre'])] = $p;
        }
        $this->miembros = [];
        foreach ($miembrosRepo->todos() as $m) {
            $this->miembros[self::norm($m['nombre'])] = $m;
        }
        $this->prioridades = array_keys(Catalogo::prioridades());
        $this->estados     = array_keys(Catalogo::estadosTarea());
    }

    /** Quita tildes/mayusculas/espacios para comparar nombres. */
    public static function norm(string $t): string
    {
        $t = mb_strtolower(trim($t), 'UTF-8');
        $t = strtr($t, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ñ'=>'n','ü'=>'u',
                        'à'=>'a','è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u']);
        return (string)preg_replace('/\s+/', ' ', $t);
    }

    /**
     * Busca un colaborador por nombre, tolerante:
     *  - coincidencia exacta del nombre completo, o
     *  - que un nombre sea prefijo del otro por palabras ("Kevin" ↔ "Kevin
     *    Arellano"), siempre que la coincidencia sea UNICA.
     * Devuelve el miembro, 'ambiguo' si hay varios, o null si ninguno.
     */
    private function resolverMiembro(string $nombre): array|string|null
    {
        $q = self::norm($nombre);
        if ($q === '') return null;
        if (isset($this->miembros[$q])) return $this->miembros[$q];

        $qp = explode(' ', $q);
        $hallados = [];
        foreach ($this->miembros as $clave => $m) {
            $mp = explode(' ', $clave);
            $corto = min(count($qp), count($mp));
            if (array_slice($qp, 0, $corto) === array_slice($mp, 0, $corto)) {
                $hallados[] = $m;
            }
        }
        if (count($hallados) === 1) return $hallados[0];
        if (count($hallados) > 1)   return 'ambiguo';
        return null;
    }

    /** Resuelve una prioridad por clave o por etiqueta; '' si no encaja. */
    private function prioridad(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        if (in_array($v, $this->prioridades, true)) return $v;
        foreach (Catalogo::prioridades() as $k => [$label]) {
            if (self::norm($label) === self::norm($v)) return $k;
        }
        return '';
    }

    private function estado(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        if (in_array($v, $this->estados, true)) return $v;
        foreach (Catalogo::estadosTarea() as $k => [$label]) {
            if (self::norm($label) === self::norm($v)) return $k;
        }
        return '';
    }

    /**
     * Procesa el JSON ya decodificado.
     * @param bool $soloValidar  true = no crea nada, solo revisa.
     * @return array{ok:bool, creadas:int, filas:array, errores:array}
     *   filas: por cada tarea [titulo, proyecto, asignados[], avisos[], error]
     */
    /**
     * @param ?array $soloProyectoIds null = sin restricción (admin). Si es una
     *   lista de ids, solo se aceptan tareas de esos proyectos (Scrum Master).
     */
    public function procesar(array $json, bool $soloValidar, bool $actualizar = false, ?array $soloProyectoIds = null): array
    {
        $lista = $json['tareas'] ?? $json;
        if (!is_array($lista) || $lista === [] || array_is_list($lista) === false && isset($lista['titulo'])) {
            // permitir una sola tarea suelta como objeto
            $lista = isset($lista['titulo']) ? [$lista] : $lista;
        }
        if (!is_array($lista) || $lista === []) {
            return ['ok' => false, 'creadas' => 0, 'filas' => [],
                    'errores' => ['El JSON no trae ninguna tarea. Debe ser {"tareas":[ … ]} o un arreglo de tareas.']];
        }

        $filas = [];
        $errores = [];
        $preparadas = [];   // datos listos para crear, en orden

        // Índice de tareas ya existentes por proyecto + título (para actualizar
        // en vez de duplicar cuando el admin marca la opción).
        $existentes = [];
        foreach ($this->tareasRepo->todas() as $tx) {
            $existentes[(int)($tx['proyecto_id'] ?? 0) . '|' . self::norm((string)($tx['titulo'] ?? ''))] = (int)$tx['id'];
        }

        foreach (array_values($lista) as $i => $t) {
            $n = $i + 1;
            if (!is_array($t)) {
                $errores[] = "Tarea #$n: no es un objeto válido.";
                continue;
            }
            $avisos = [];
            $titulo = trim((string)($t['titulo'] ?? ''));
            $nomProy = trim((string)($t['proyecto'] ?? ''));

            $error = '';
            if ($titulo === '') {
                $error = 'sin título';
            }
            $proyecto = $this->proyectos[self::norm($nomProy)] ?? null;
            if (!$proyecto) {
                $error = $error ? $error . ' y proyecto «' . $nomProy . '» no existe'
                                : 'el proyecto «' . ($nomProy ?: '(vacío)') . '» no existe';
            } elseif ($soloProyectoIds !== null && !in_array((int)$proyecto['id'], $soloProyectoIds, true)) {
                // Scrum Master: solo puede planificar en sus propios proyectos.
                $error = $error ? $error . ' y no participas en «' . $proyecto['nombre'] . '»'
                                : 'no participas en el proyecto «' . $proyecto['nombre'] . '», no puedes planificar ahí';
            }

            // Asignados por nombre (los que no existan solo avisan)
            $ids = [];
            $nombresOk = [];
            foreach ((array)($t['asignados'] ?? $t['asignado'] ?? []) as $nom) {
                $nom = trim((string)$nom);
                if ($nom === '') continue;
                $m = $this->resolverMiembro($nom);
                if (is_array($m))          { $ids[] = (int)$m['id']; $nombresOk[] = $m['nombre']; }
                elseif ($m === 'ambiguo')  { $avisos[] = 'hay varios que coinciden con «' . $nom . '», usa el nombre completo'; }
                else                       { $avisos[] = 'no encontré a «' . $nom . '», tarea sin ese responsable'; }
            }

            // Prioridad / estado
            $prio = 'media';
            if (isset($t['prioridad']) && trim((string)$t['prioridad']) !== '') {
                $p = $this->prioridad((string)$t['prioridad']);
                if ($p) $prio = $p; else $avisos[] = 'prioridad «' . $t['prioridad'] . '» desconocida, uso «media»';
            }
            $est = 'pendiente';
            if (isset($t['estado']) && trim((string)$t['estado']) !== '') {
                $e = $this->estado((string)$t['estado']);
                if ($e) $est = $e; else $avisos[] = 'estado «' . $t['estado'] . '» desconocido, uso «pendiente»';
            }

            // Fechas
            $fi = ProyectoRepo::fecha((string)($t['fecha_inicio'] ?? ''));
            $fl = ProyectoRepo::fecha((string)($t['fecha_limite'] ?? ''));
            if (!empty($t['fecha_inicio']) && $fi === '') $avisos[] = 'fecha_inicio inválida (usa AAAA-MM-DD), la dejé vacía';
            if (!empty($t['fecha_limite']) && $fl === '') $avisos[] = 'fecha_limite inválida (usa AAAA-MM-DD), la dejé vacía';
            if ($fi !== '' && $fl !== '' && $fl < $fi) $avisos[] = 'la fecha límite es anterior al inicio';

            // ¿Ya existe una tarea con ese título en el proyecto?
            $existe = ($proyecto && $titulo !== '')
                ? ($existentes[(int)$proyecto['id'] . '|' . self::norm($titulo)] ?? 0)
                : 0;
            if ($existe && !$actualizar) {
                $avisos[] = 'ya existe una tarea con ese título en «' . $proyecto['nombre'] . '»; se creará OTRA (marca "Actualizar" para reemplazarla)';
            }

            $filas[] = [
                'n'         => $n,
                'titulo'    => $titulo ?: '(sin título)',
                'proyecto'  => $proyecto['nombre'] ?? $nomProy,
                'asignados' => $nombresOk,
                'avisos'    => $avisos,
                'error'     => $error,
                'accion'    => ($existe && $actualizar) ? 'actualizar' : 'crear',
            ];
            if ($error) {
                $errores[] = "Tarea #$n («" . ($titulo ?: $nomProy) . "»): $error.";
                continue;
            }

            $preparadas[] = [
                'ref'  => trim((string)($t['ref'] ?? '')),
                'dep'  => trim((string)($t['depende_de'] ?? '')),
                'existe' => $existe,
                'datos' => [
                    'proyecto_id'  => (int)$proyecto['id'],
                    'titulo'       => $titulo,
                    'descripcion'  => trim((string)($t['descripcion'] ?? '')),
                    'estado'       => $est,
                    'prioridad'    => $prio,
                    'fecha_inicio' => $fi,
                    'fecha_limite' => $fl,
                    'asignados'    => $ids,
                ],
                'idx' => count($filas) - 1,
            ];
        }

        // Con errores graves: no se crea nada
        if ($errores) {
            return ['ok' => false, 'creadas' => 0, 'actualizadas' => 0, 'filas' => $filas, 'errores' => $errores];
        }
        if ($soloValidar) {
            return ['ok' => true, 'creadas' => 0, 'actualizadas' => 0, 'filas' => $filas, 'errores' => []];
        }

        // Aplicar: crear las nuevas y (si se pidió) actualizar las que ya existen.
        // Recuerda ref -> id y titulo -> id para resolver las dependencias.
        $porRef = [];
        $porTitulo = [];
        $creadasIds = [];
        $creadas = 0;
        $actualizadas = 0;
        foreach ($preparadas as $p) {
            if ($actualizar && $p['existe'] > 0) {
                $this->tareasRepo->actualizar($p['existe'], $p['datos'] + TareaRepo::camposAsignado($p['datos']));
                $id = (int)$p['existe'];
                $actualizadas++;
            } else {
                $t = $this->tareasRepo->crear($p['datos']);
                $id = (int)$t['id'];
                $creadas++;
            }
            $creadasIds[] = ['id' => $id, 'pid' => (int)$p['datos']['proyecto_id'], 'dep' => $p['dep']];
            if ($p['ref'] !== '') $porRef[self::norm($p['ref'])] = $id;
            $porTitulo[self::norm($p['datos']['titulo'])] = $id;
        }

        // Segunda pasada: resolver depende_de (por ref o por titulo del lote)
        foreach ($creadasIds as $c) {
            if ($c['dep'] === '') continue;
            $depId = $porRef[self::norm($c['dep'])] ?? $porTitulo[self::norm($c['dep'])] ?? 0;
            if ($depId <= 0) continue;
            $valido = $this->tareasRepo->dependenciaValida($c['id'], $depId, $c['pid']);
            if ($valido > 0) $this->tareasRepo->actualizar($c['id'], ['depende_de' => $valido]);
        }

        return ['ok' => true, 'creadas' => $creadas, 'actualizadas' => $actualizadas, 'filas' => $filas, 'errores' => []];
    }
}
