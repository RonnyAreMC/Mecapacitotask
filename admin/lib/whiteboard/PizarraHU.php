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
 *  - lee las historias de usuario del proyecto (lib/backlog/Backlog.php) con los
 *    permisos de siempre (quien ve el proyecto), para arrastrarlas al lienzo;
 *  - y, si el diagrama viene de una historia real y la persona arma el
 *    backlog del proyecto (Backlog::puede), escribe de vuelta en la historia
 *    Como, Quiero, Para y los escenarios, y la IMAGEN del diagrama (el PNG que
 *    arma la pizarra: uploads/hu_dg_<historia>_<único>.png) y el TEXTO del diagrama.
 *    Nada más de la historia se toca.
 *
 * vista($historia) es el diagrama VIVO de solo lectura para la página de la
 * historia (historia.php · lib/backlog/backlog_ui.php): ▶ Reproducir y los escenarios,
 * sin escribir, mover ni menús. Lo arma el mismo assets/pizarra-hu.js.
 */
final class PizarraHU
{
    /** La imagen del diagrama que va a la historia: un PNG de hasta 4 MB. */
    public const MAX_IMAGEN = 4 * 1024 * 1024;

    /** ¿Está el módulo de Sprints? (sin él, la herramienta muestra un vacío claro). */
    public static function hayBacklog(): bool
    {
        if (class_exists('Backlog')) return true;
        if (!is_file(__DIR__ . '/../backlog/Backlog.php') || !is_file(__DIR__ . '/../releases/Releases.php')) return false;
        require_once __DIR__ . '/../releases/Releases.php';
        require_once __DIR__ . '/../backlog/Backlog.php';
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
            'estado_txt'  => class_exists('Backlog') ? (string)(Backlog::ESTADOS[$estado][0] ?? $estado) : $estado,
            'puntos'      => (int)($h['puntos'] ?? 0),
            'epica'       => isset($h['item']['codigo']) ? trim($h['item']['codigo'] . ' ' . ($h['item']['titulo'] ?? '')) : '',
            // El texto del diagrama que se guardó desde la pizarra (si hay, el diagrama sale de aquí)
            'diagrama_texto' => mb_substr(str_replace("\r", '', (string)($h['diagrama']['texto'] ?? '')), 0, 20000),
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
    public static function guardarEnHistoria(int $id, array $d, int $quien, string $origen = ''): array
    {
        self::historiaQuePuede($id);
        $t = fn($v, int $max) => mb_substr(trim((string)preg_replace('/\s+/u', ' ', is_scalar($v) ? (string)$v : '')), 0, $max);
        $esc = [];
        foreach (array_slice(array_values((array)($d['escenarios'] ?? [])), 0, 20) as $e) {
            if (!is_array($e)) continue;
            $esc[] = ['nombre' => $t($e['nombre'] ?? '', 160), 'dado' => $t($e['dado'] ?? '', 1500), 'cuando' => $t($e['cuando'] ?? '', 1500), 'entonces' => $t($e['entonces'] ?? '', 1500)];
        }
        $cambios = ['como' => $t($d['como'] ?? '', 160), 'quiero' => $t($d['quiero'] ?? '', 800), 'para' => $t($d['para'] ?? '', 800), 'escenarios' => $esc];
        // El texto del diagrama: la historia lo muestra vivo (vista()). La imagen, si llega, va aparte (guardarImagen).
        if (is_string($d['texto'] ?? null)) $cambios['diagrama'] = ['texto' => mb_substr(str_replace("\r", '', $d['texto']), 0, 20000), 'origen' => mb_substr($origen, 0, 60)];
        return self::corta(Backlog::guardar($cambios, $id, $quien));
    }

    /**
     * El diagrama VIVO de una historia, solo para ver (historia.php): el actor con su
     * dibujo, los pasos, lo que obtiene, «Mira: …», los escenarios y ▶ Reproducir. Sin
     * escribir, mover, el texto ni los menús. Sale de $historia['diagrama']['texto'] (lo
     * que se guardó desde la pizarra) o, si no hay, de sus campos, como al arrastrarla.
     * Devuelve el contenedor con sus datos en JSON; la primera vez en la página, también
     * el CSS, los íconos y assets/pizarra-hu.js (que lo arma al cargar).
     */
    public static function vista(array $historia): string
    {
        static $conAssets = false;
        $h = self::corta($historia);
        $datos = ['texto' => $h['diagrama_texto'], 'historia' => array_diff_key($h, ['diagrama_texto' => 1])];
        $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $out = '<div class="pz-hu-vista" data-pz-hu-vista role="figure" aria-label="' . e('Diagrama de la historia ' . $h['codigo']) . '">'
            . '<script type="application/json">' . $json . '</script>'
            . '<p class="pz-hu-vista__espera">Armando el diagrama…</p></div>';
        if (!$conAssets) {
            $conAssets = true;
            $iconos = '';
            foreach (['ds-play', 'ds-pausa', 'ds-check'] as $ic) $iconos .= '<span data-ico="' . $ic . '">' . UI::icono($ic) . '</span>';
            $out .= '<link rel="stylesheet" href="' . e(asset('assets/pizarra-hu.css')) . '">'
                . '<template id="pz-hu-iconos">' . $iconos . '</template>'
                . '<script src="' . e(asset('assets/pizarra-hu.js')) . '" defer></script>';
        }
        return $out;
    }

    /** La historia, si quien la cambia puede: ve su proyecto y arma su backlog. Si no, RuntimeException. */
    private static function historiaQuePuede(int $id): array
    {
        if (!self::hayBacklog()) throw new RuntimeException('Este panel todavía no tiene el módulo de Sprints.');
        $h = $id > 0 ? Backlog::historia($id) : null;
        if (!$h || !puedeVerProyecto((int)($h['proyecto_id'] ?? 0))) throw new RuntimeException('Esa historia ya no está o no es de tus proyectos.');
        if (!Backlog::puede((int)$h['proyecto_id'])) throw new RuntimeException('Las historias las cambian el PO del proyecto, su Scrum Master o el administrador.');
        return $h;
    }

    /**
     * La imagen del diagrama, a la historia (pizarra_api.php?accion=guardar_hu_imagen).
     * Llega como archivo (campo «imagen») o como dataURL («data:image/png;base64,…»).
     * Tiene que ser un PNG de verdad (su firma \x89PNG y sus medidas), de hasta 4 MB.
     * Se escribe en uploads/hu_dg_<historia>_<único>.png y Backlog::guardar la deja en
     * la historia (y borra la anterior). $origen: «mia», «nota:ID» o «reunion:ID».
     * Mismo permiso que «Guardar en la historia». Devuelve ['ruta', 'ancho', 'alto'].
     */
    public static function guardarImagen(int $id, array $archivo, string $dataUrl, string $origen, int $quien): array
    {
        self::historiaQuePuede($id);
        $bin = '';
        $err = (int)($archivo['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_NO_FILE) {
            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException('La imagen del diagrama pesa más de lo que acepta el servidor (' . ini_get('upload_max_filesize') . ').');
            if ($err !== UPLOAD_ERR_OK || empty($archivo['tmp_name']) || !is_uploaded_file($archivo['tmp_name'])) throw new RuntimeException('No llegó la imagen del diagrama.');
            if ((int)($archivo['size'] ?? 0) > self::MAX_IMAGEN) throw new RuntimeException('La imagen del diagrama pasa de 4 MB.');
            $bin = (string)file_get_contents($archivo['tmp_name']);
        } elseif ($dataUrl !== '') {
            if (strlen($dataUrl) > (int)(self::MAX_IMAGEN * 1.4) + 64) throw new RuntimeException('La imagen del diagrama pasa de 4 MB.');
            if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=\s]+)$#', $dataUrl, $m)) throw new RuntimeException('La imagen del diagrama no es un PNG.');
            $bin = (string)base64_decode(preg_replace('/\s+/', '', $m[1]), true);
        }
        if ($bin === '') throw new RuntimeException('No llegó la imagen del diagrama.');
        if (strlen($bin) > self::MAX_IMAGEN) throw new RuntimeException('La imagen del diagrama pasa de 4 MB.');
        if (strncmp($bin, "\x89PNG\r\n\x1a\n", 8) !== 0) throw new RuntimeException('La imagen del diagrama no es un PNG.');
        $dim = @getimagesizefromstring($bin);
        if (!$dim || (int)($dim[2] ?? 0) !== IMAGETYPE_PNG || $dim[0] < 16 || $dim[1] < 16 || $dim[0] > 12000 || $dim[1] > 12000) {
            throw new RuntimeException('La imagen del diagrama no es un PNG válido.');
        }
        $dir = __DIR__ . '/../../uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $ruta = 'uploads/hu_dg_' . $id . '_' . str_replace('.', '', uniqid('', true)) . '.png';
        if (@file_put_contents(__DIR__ . '/../../' . $ruta, $bin, LOCK_EX) === false) throw new RuntimeException('No se pudo guardar la imagen del diagrama en el servidor.');
        try {
            Backlog::guardar(['diagrama' => ['ruta' => $ruta, 'origen' => mb_substr($origen, 0, 60)]], $id, $quien);
        } catch (\Throwable $e) {
            @unlink(__DIR__ . '/../../' . $ruta);
            throw $e;
        }
        return ['ruta' => $ruta, 'ancho' => (int)$dim[0], 'alto' => (int)$dim[1]];
    }
}
