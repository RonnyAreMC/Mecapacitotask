<?php
/**
 * Diagrama de historia en la pizarra (pizarra.php · assets/pizarra-hu.js).
 *
 * Cada diagrama tiene su TEXTO fuente («Historia: … · Como: … · Quiero: … ·
 * Escenario: … Dado/Cuando/Entonces · Resultados: …») y el navegador lo dibuja
 * solo: quién la usa, qué hace en el sistema y qué obtiene (PDF, Excel,
 * reporte, horario…). Todo es determinista, por palabras clave: aquí no hay IA.
 *
 * Esta clase solo:
 *  - lee las historias de usuario del proyecto (lib/Backlog.php) con los
 *    permisos de siempre (quien ve el proyecto), para arrastrarlas al lienzo;
 *  - y, si el diagrama viene de una historia real y la persona arma el
 *    backlog del proyecto (Backlog::puede), escribe de vuelta en la historia
 *    Como, Quiero, Para y los escenarios. Nada más de la historia se toca.
 */
final class PizarraHU
{
    /** ¿Está el módulo de Sprints? (sin él, la herramienta muestra un vacío claro). */
    public static function hayBacklog(): bool
    {
        if (class_exists('Backlog')) return true;
        if (!is_file(__DIR__ . '/Backlog.php') || !is_file(__DIR__ . '/Releases.php')) return false;
        require_once __DIR__ . '/Releases.php';
        require_once __DIR__ . '/Backlog.php';
        return class_exists('Backlog');
    }

    /** Los proyectos que la persona puede ver (para elegir en «Mis notas» y en la pizarra de una nota). */
    public static function proyectosVisibles(): array
    {
        $out = [];
        foreach (soloProyectosVisibles((new ProyectoRepo())->todos()) as $p) {
            $out[] = ['id' => (int)$p['id'], 'nombre' => (string)($p['nombre'] ?? 'Proyecto')];
        }
        usort($out, fn($a, $b) => strcasecmp($a['nombre'], $b['nombre']));
        return $out;
    }

    /** ¿Puede escribir en las historias de este proyecto? Su PO, su Scrum Master y el administrador. */
    public static function puede(int $pid): bool
    {
        return $pid > 0 && self::hayBacklog() && puedeVerProyecto($pid) && Backlog::puede($pid);
    }

    /** De estos proyectos, en cuáles puede guardar en la historia (para mostrar el botón solo a quien puede). */
    public static function proyectosQuePuede(array $pids): array
    {
        return array_values(array_filter(array_unique(array_map('intval', $pids)), fn($p) => self::puede($p)));
    }

    /** Una historia, solo con lo que el diagrama usa (los textos, recortados). */
    public static function corta(array $h): array
    {
        $t = fn($v, int $max) => mb_substr(trim((string)preg_replace('/\s+/u', ' ', strip_tags((string)$v))), 0, $max);
        $esc = [];
        foreach (array_slice(array_values((array)($h['escenarios'] ?? [])), 0, 20) as $e) {
            if (!is_array($e)) continue;
            $x = ['nombre' => $t($e['nombre'] ?? '', 160), 'dado' => $t($e['dado'] ?? '', 600), 'cuando' => $t($e['cuando'] ?? '', 600), 'entonces' => $t($e['entonces'] ?? '', 600)];
            if (implode('', $x) !== '') $esc[] = $x;
        }
        $estado = (string)($h['estado'] ?? 'por_hacer');
        return [
            'id'          => (int)($h['id'] ?? 0),
            'proyecto_id' => (int)($h['proyecto_id'] ?? 0),
            'codigo'      => $t($h['codigo'] ?? '', 40),
            'titulo'      => $t($h['titulo'] ?? '', 200),
            'como'        => $t($h['como'] ?? '', 160),
            'quiero'      => $t($h['quiero'] ?? '', 800),
            'para'        => $t($h['para'] ?? '', 800),
            'escenarios'  => $esc,
            'sprint'      => (string)($h['sprint'] ?? ''),
            'estado'      => $estado,
            'estado_txt'  => (string)(Backlog::ESTADOS[$estado][0] ?? $estado),
            'puntos'      => (int)($h['puntos'] ?? 0),
            'epica'       => isset($h['item']['codigo']) ? trim($h['item']['codigo'] . ' ' . ($h['item']['titulo'] ?? '')) : '',
        ];
    }

    /** Las historias de un proyecto (ya comprobado que se puede ver), en el orden del backlog. */
    public static function historias(int $pid): array
    {
        if ($pid <= 0 || !self::hayBacklog()) return [];
        return array_map([self::class, 'corta'], Backlog::historias(['proyecto_id' => $pid]));
    }

    /** Los estados de una historia: [clave => nombre]. */
    public static function estados(): array
    {
        if (!self::hayBacklog()) return [];
        return array_map(fn($v) => (string)$v[0], Backlog::ESTADOS);
    }

    /** Una historia, si quien pregunta puede ver su proyecto. */
    public static function historia(int $id): ?array
    {
        if ($id <= 0 || !self::hayBacklog()) return null;
        $h = Backlog::historia($id);
        if (!$h || !puedeVerProyecto((int)($h['proyecto_id'] ?? 0))) return null;
        return self::corta($h);
    }

    /**
     * «Guardar en la historia»: lo que dice el diagrama (Como, Quiero, Para y
     * los escenarios Dado/Cuando/Entonces) vuelve a la historia del backlog.
     * Solo esos campos; el título, el sprint, la estimación… no se tocan.
     * Lanza RuntimeException con un mensaje para la persona.
     */
    public static function guardarEnHistoria(int $id, array $d, int $quien): array
    {
        if (!self::hayBacklog()) throw new RuntimeException('Este panel todavía no tiene el módulo de Sprints.');
        $h = $id > 0 ? Backlog::historia($id) : null;
        if (!$h || !puedeVerProyecto((int)($h['proyecto_id'] ?? 0))) throw new RuntimeException('Esa historia ya no está o no es de tus proyectos.');
        if (!Backlog::puede((int)$h['proyecto_id'])) throw new RuntimeException('Las historias las cambian el PO del proyecto, su Scrum Master o el administrador.');
        $t = fn($v, int $max) => mb_substr(trim((string)preg_replace('/\s+/u', ' ', is_scalar($v) ? (string)$v : '')), 0, $max);
        $esc = [];
        foreach (array_slice(array_values((array)($d['escenarios'] ?? [])), 0, 20) as $e) {
            if (!is_array($e)) continue;
            $esc[] = ['nombre' => $t($e['nombre'] ?? '', 160), 'dado' => $t($e['dado'] ?? '', 1500), 'cuando' => $t($e['cuando'] ?? '', 1500), 'entonces' => $t($e['entonces'] ?? '', 1500)];
        }
        $cambios = ['como' => $t($d['como'] ?? '', 160), 'quiero' => $t($d['quiero'] ?? '', 800), 'para' => $t($d['para'] ?? '', 800), 'escenarios' => $esc];
        return self::corta(Backlog::guardar($cambios, $id, $quien));
    }
}
