<?php
/**
 * Importar historias de usuario al backlog de un proyecto desde el documento
 * de planificación (historias_importar.php → bl_importar en actions.php).
 *
 * Con el formato de InnoTech («Consolidado de historias de usuario»: por épica
 * su tabla «HU | Título | SP | Prioridad | Sprint» y cada historia con su título
 * «R2-D01-HU01 · …», la línea «5 SP · Must · S17», el «Como…, quiero…, para…»,
 * las Reglas de negocio y los Criterios de aceptación en Dado / Cuando /
 * Entonces) el Word se lee aquí, sin IA: exacto y gratis. Si el documento no
 * tiene ese formato (o es un PDF), lo lee la IA (IAFunciones::historiasDocumento)
 * y devuelve lo mismo.
 *
 * Resultado de leer: ['meta' => [...], 'epicas' => [código => [...]], 'historias' => [[
 *   codigo, epica, titulo, sp, prioridad (Must|Should|Could|Won't), sprint (como
 *   viene: «S18–S19»), como, quiero, para, reglas[], escenarios[[nombre, dado,
 *   cuando, entonces]]]]]
 */
final class BacklogImport
{
    private const NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    public const MOSCOW = ['Must' => 'alta', 'Should' => 'media', 'Could' => 'baja', "Won't" => 'baja'];

    /* ============================== Leer el Word ============================== */

    /** Los bloques del Word en orden: ['p', estilo, texto] o ['t', [[celda (líneas con \n)…]…]]. */
    public static function bloquesDocx(string $ruta): array
    {
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) throw new RuntimeException('El archivo no es un Word (.docx) válido.');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) throw new RuntimeException('El Word no tiene contenido.');
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) throw new RuntimeException('No se pudo leer el Word.');
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', self::NS);
        $texto = function (DOMNode $p) use ($xp): string {
            $t = '';
            foreach ($xp->query('.//w:t|.//w:tab|.//w:br', $p) as $n) $t .= $n->localName === 't' ? $n->textContent : ($n->localName === 'tab' ? ' ' : "\n");
            return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $t));
        };
        $out = [];
        $body = $xp->query('/w:document/w:body')->item(0);
        if (!$body) return [];
        foreach ($body->childNodes as $n) {
            if (!($n instanceof DOMElement)) continue;
            if ($n->localName === 'p') {
                $t = $texto($n);
                if ($t === '') continue;
                $est = $xp->query('./w:pPr/w:pStyle/@w:val', $n)->item(0);
                $out[] = ['p', $est ? (string)$est->nodeValue : '', $t];
            } elseif ($n->localName === 'tbl') {
                $filas = [];
                foreach ($xp->query('./w:tr', $n) as $tr) {
                    $celdas = [];
                    foreach ($xp->query('./w:tc', $tr) as $tc) {
                        $lineas = [];
                        foreach ($xp->query('.//w:p', $tc) as $p) { $l = $texto($p); if ($l !== '') $lineas[] = $l; }
                        $celdas[] = implode("\n", $lineas);
                    }
                    $filas[] = $celdas;
                }
                $out[] = ['t', $filas];
            }
        }
        return $out;
    }

    /**
     * Lee el formato de InnoTech. Sin historias con ese formato devuelve
     * 'historias' => [] (y entonces la lee la IA).
     */
    public static function leer(array $bloques): array
    {
        $meta = [];
        $epicas = [];
        $historias = [];
        $resumen = [];     // por código de HU: [sp, prioridad, sprint] de la tabla de la épica
        $ep = '';          // la épica en curso
        $h = null;         // la historia en curso
        $modo = '';        // qué se está leyendo: reglas, criterios, descripcion, notas
        $cerrar = function () use (&$h, &$historias) { if ($h) $historias[] = $h; $h = null; };
        foreach ($bloques as $i => $b) {
            if ($b[0] === 't') {
                $filas = $b[1];
                // La ficha del documento (Código, Versión, Product Owner, Release…)
                if ($i < 8 && $filas && count($filas[0]) === 2 && !$epicas) {
                    foreach ($filas as $f) if (count($f) === 2) $meta[mb_strtolower(trim($f[0]))] = trim($f[1]);
                    continue;
                }
                // Una épica: una fila «R2-D01 | Título»
                if (count($filas) === 1 && count($filas[0]) === 2 && preg_match('/^R\d+-[A-Z]+\d+$/u', trim($filas[0][0]))) {
                    $cerrar();
                    $ep = trim($filas[0][0]);
                    $epicas[$ep] = ['codigo' => $ep, 'titulo' => trim($filas[0][1]), 'sprints' => '', 'microservicio' => '', 'descripcion' => '', 'notas' => []];
                    $modo = '';
                    continue;
                }
                // La ficha de la épica (Sprints, Microservicio…)
                if ($ep !== '' && !$h && $filas && count($filas[0]) === 2 && !$epicas[$ep]['sprints']) {
                    foreach ($filas as $f) {
                        $k = mb_strtolower(trim($f[0] ?? ''));
                        if ($k === 'sprints') $epicas[$ep]['sprints'] = trim((string)$f[1]);
                        if ($k === 'microservicio') $epicas[$ep]['microservicio'] = trim((string)$f[1]);
                    }
                    continue;
                }
                // La tabla de historias de la épica: HU | Título | SP | Prioridad | Sprint
                if ($filas && preg_match('/^HU$/iu', trim($filas[0][0] ?? ''))) {
                    foreach (array_slice($filas, 1) as $f) {
                        $cod = trim($f[0] ?? '');
                        if (preg_match('/^R\d+-[A-Z]+\d+-HU\d+$/u', $cod)) $resumen[$cod] = ['sp' => trim($f[2] ?? ''), 'prioridad' => trim($f[3] ?? ''), 'sprint' => trim($f[4] ?? '')];
                    }
                    continue;
                }
                // Los criterios de aceptación: # | Escenario (Dado / Cuando / Entonces)
                if ($h && $modo === 'criterios') {
                    foreach ($filas as $f) {
                        if (trim($f[0] ?? '') === '#' || count($f) < 2) continue;
                        $e = self::escenario((string)$f[1]);
                        if ($e) $h['escenarios'][] = $e;
                    }
                }
                continue;
            }
            [, $est, $t] = $b;
            $esTitulo = (bool)preg_match('/^Heading(\d)/i', $est, $mt);
            $nivel = $esTitulo ? (int)$mt[1] : 0;
            if ($esTitulo) {
                // Una historia: «R2-D01-HU01 · Título»
                if (preg_match('/^(R\d+-[A-Z]+\d+-HU\d+)\s*[·\-–:]\s*(.+)$/u', $t, $m)) {
                    $cerrar();
                    $cod = $m[1];
                    $r = $resumen[$cod] ?? [];
                    $epCod = preg_replace('/-HU\d+$/u', '', $cod);
                    if ($ep === '' || $ep !== $epCod) { $ep = $epCod; $epicas[$ep] ??= ['codigo' => $ep, 'titulo' => '', 'sprints' => '', 'microservicio' => '', 'descripcion' => '', 'notas' => []]; }
                    $h = ['codigo' => $cod, 'epica' => $ep, 'titulo' => trim($m[2]), 'sp' => (string)($r['sp'] ?? ''), 'prioridad' => (string)($r['prioridad'] ?? ''),
                          'sprint' => (string)($r['sprint'] ?? ''), 'como' => '', 'quiero' => '', 'para' => '', 'reglas' => [], 'escenarios' => []];
                    $modo = 'historia';
                    continue;
                }
                $tl = mb_strtolower($t);
                if ($h && str_starts_with($tl, 'reglas')) { $modo = 'reglas'; continue; }
                if ($h && str_starts_with($tl, 'criterios')) { $modo = 'criterios'; continue; }
                // Lo que va después de las historias (modelo de datos, eventos, notas) es de la épica
                if ($nivel >= 3 && $ep !== '') {
                    $cerrar();
                    $modo = str_starts_with($tl, 'descripci') ? 'descripcion' : (str_starts_with($tl, 'notas') ? 'notas' : '');
                    continue;
                }
                $cerrar();
                $modo = '';
                if ($nivel === 1) $ep = '';
                continue;
            }
            if ($h && $modo === 'historia') {
                // «5 SP · Must · S17» y el «Como…, quiero…, para…»
                if (preg_match('/^(\d+)\s*SP\s*·\s*([A-Za-z\']+)\s*·\s*(.+)$/u', $t, $m)) { $h['sp'] = $m[1]; $h['prioridad'] = $m[2]; $h['sprint'] = trim($m[3]); continue; }
                if (preg_match('/^Como\s+(.+?),?\s+quiero\s+(.+?),?\s+para\s+(.+?)\.?$/isu', $t, $m)) { $h['como'] = trim($m[1]); $h['quiero'] = trim($m[2]); $h['para'] = trim($m[3]); continue; }
                if ($h['quiero'] === '') { $h['quiero'] = $t; continue; }
            }
            if ($h && $modo === 'reglas') { $h['reglas'][] = $t; continue; }
            if (!$h && $ep !== '' && $modo === 'descripcion') { $epicas[$ep]['descripcion'] = trim($epicas[$ep]['descripcion'] . "\n" . $t); continue; }
            if (!$h && $ep !== '' && $modo === 'notas') { $epicas[$ep]['notas'][] = $t; continue; }
        }
        $cerrar();
        return ['meta' => $meta, 'epicas' => $epicas, 'historias' => $historias];
    }

    /**
     * Un escenario de la tabla de criterios: la primera línea es su nombre y las
     * demás empiezan por Dado, Cuando, Entonces (un «Y…» o «Pero…» sigue a la
     * anterior en otra línea). Todo en una línea también se entiende.
     */
    public static function escenario(string $celda): ?array
    {
        $celda = trim($celda);
        if ($celda === '') return null;
        // Todo en una sola línea: se parte por las palabras clave
        if (!str_contains($celda, "\n")) $celda = preg_replace('/\s+(Dado|Cuando|Entonces|Y|Pero)\s+/u', "\n$1 ", $celda);
        $e = ['nombre' => '', 'dado' => '', 'cuando' => '', 'entonces' => ''];
        $parte = '';
        foreach (preg_split('/\n+/u', $celda) as $l) {
            $l = trim($l);
            if ($l === '') continue;
            if (preg_match('/^(Dado|Cuando|Entonces)\s+(.*)$/u', $l, $m)) {
                $parte = ['Dado' => 'dado', 'Cuando' => 'cuando', 'Entonces' => 'entonces'][$m[1]];
                $e[$parte] = trim($e[$parte] . "\n" . $m[2]);
            } elseif ($parte !== '' && preg_match('/^(Y|Pero)\s+(.*)$/u', $l, $m)) {
                $e[$parte] .= "\n" . mb_strtolower($m[1]) . ' ' . $m[2];
            } elseif ($parte === '') {
                $e['nombre'] = trim($e['nombre'] . ' ' . $l);
            } else {
                $e[$parte] .= ' ' . $l;
            }
        }
        if ($e['dado'] === '' && $e['cuando'] === '' && $e['entonces'] === '') { $e['entonces'] = $e['nombre']; $e['nombre'] = ''; }
        return $e;
    }

    /* ============================== Llevarlo al backlog ============================== */

    /** El primer sprint de «S18–S19» (o '' si no trae). */
    public static function sprintInicial(string $s): string
    {
        return preg_match('/S(\d{1,3})/u', $s, $m) ? 'S' . str_pad($m[1], 2, '0', STR_PAD_LEFT) : '';
    }

    /** Los SP del documento en la escala del panel: el Fibonacci más cercano o su talla de camisa. */
    public static function estimacion(string $sp): string
    {
        if (!is_numeric($sp) || (int)$sp <= 0) return '';
        $n = (int)$sp;
        if (Backlog::conf()['escala'] === 'camisa') {
            $mejor = 'M';
            foreach (Backlog::CAMISA as $t => $v) if (abs($v - $n) < abs(Backlog::CAMISA[$mejor] - $n)) $mejor = $t;
            return $mejor;
        }
        $mejor = '1';
        foreach (Backlog::FIBONACCI as $f) if (abs((int)$f - $n) < abs((int)$mejor - $n)) $mejor = $f;
        return $mejor;
    }

    /** El detalle con formato: las reglas de negocio y lo que dice el documento de su planificación. */
    public static function notas(array $h): string
    {
        $html = '';
        if ($h['reglas']) $html .= '<h3>Reglas de negocio</h3><ul>' . implode('', array_map(fn($r) => '<li>' . e($r) . '</li>', $h['reglas'])) . '</ul>';
        $plan = array_filter([$h['sp'] !== '' ? $h['sp'] . ' SP' : '', $h['prioridad'], $h['sprint'] !== '' ? 'sprint ' . $h['sprint'] : '']);
        if ($plan) $html .= '<p><em>En el documento: ' . e(implode(' · ', $plan)) . '.</em></p>';
        if (array_filter($h['escenarios'], fn($e) => str_contains((string)$e['nombre'], '[Propuesto]'))) $html .= '<p><em>Los escenarios marcados [Propuesto] requieren la validación del PO.</em></p>';
        return $html;
    }

    /**
     * Repartir por prioridad (Must, Should, Could) y en el orden del documento,
     * llenando cada sprint hasta la capacidad (en puntos) desde el primero que no
     * ha pasado. Lo que no cabe queda en el backlog. → [índice => sprint]
     */
    public static function repartir(array $historias, array $sprints, int $capacidad): array
    {
        $rango = ['Must' => 0, 'Should' => 1, 'Could' => 2, "Won't" => 3];
        $orden = array_keys($historias);
        usort($orden, fn($a, $b) => [$rango[$historias[$a]['prioridad']] ?? 2, $a] <=> [$rango[$historias[$b]['prioridad']] ?? 2, $b]);
        $cods = array_values($sprints);
        $out = [];
        $k = 0;
        $usados = 0;
        foreach ($orden as $i) {
            $pts = max(1, Backlog::puntos(self::estimacion((string)$historias[$i]['sp'])));
            while ($k < count($cods) && $usados > 0 && $usados + $pts > $capacidad) { $k++; $usados = 0; }
            if ($k >= count($cods)) { $out[$i] = ''; continue; }
            $out[$i] = $cods[$k];
            $usados += $pts;
        }
        return $out;
    }

    /**
     * Los sprints a los que se puede repartir: los que no terminaron, de los releases
     * de las épicas de esas historias (si ninguna tiene épica en el proyecto, todos).
     */
    public static function candidatos(int $pid, array $historias): array
    {
        $hoy = date('Y-m-d');
        $epicas = [];
        foreach (Backlog::epicas($pid) as $it) $epicas[(string)$it['codigo']] = (string)$it['release'];
        $rels = [];
        foreach ($historias as $h) if (isset($epicas[$h['epica']])) $rels[$epicas[$h['epica']]] = true;
        return array_keys(array_filter(Backlog::sprints(), fn($s) => $s['fin'] >= $hoy && (!$rels || array_intersect($s['releases'], array_keys($rels)))));
    }

    /**
     * Crea (o pone al día, por su código) las historias en el backlog del proyecto.
     * $modo: 'documento' (el sprint que dice el documento; si ya pasó, el de hoy),
     * 'repartir' (por prioridad y capacidad) o 'backlog' (todas sin sprint).
     * Las que ya existían conservan su sprint, su estado, su responsable y sus tareas.
     * → ['creadas', 'actualizadas', 'en_sprint', 'en_backlog', 'sin_epica' => [códigos]]
     */
    public static function aplicar(int $pid, array $historias, string $modo, int $capacidad, int $quien): array
    {
        $hoy = date('Y-m-d');
        $sps = Backlog::sprints();
        $act = Backlog::actual($hoy);
        $epicas = [];
        foreach (Backlog::epicas($pid) as $it) $epicas[(string)$it['codigo']] = (int)$it['id'];
        $ya = [];
        foreach (Backlog::historias(['proyecto_id' => $pid]) as $h) if ($h['codigo'] !== '') $ya[$h['codigo']] = $h;
        // Repartir: los sprints que no pasaron de los releases de sus épicas (o, si no, de todos)
        $destino = [];
        if ($modo === 'repartir') {
            $cand = self::candidatos($pid, $historias);
            // Solo las nuevas: las que ya estaban se quedan en su sprint y no ocupan capacidad
            $destino = self::repartir(array_filter($historias, fn($h) => $h['codigo'] === '' || !isset($ya[$h['codigo']])), $cand, max(1, $capacidad));
        }
        $r = ['creadas' => 0, 'actualizadas' => 0, 'en_sprint' => 0, 'en_backlog' => 0, 'sin_epica' => []];
        foreach ($historias as $i => $h) {
            $d = ['titulo' => mb_substr($h['titulo'], 0, 200), 'como' => $h['como'], 'quiero' => $h['quiero'], 'para' => $h['para'],
                  'escenarios' => $h['escenarios'], 'notas' => self::notas($h), 'estimacion' => self::estimacion((string)$h['sp']),
                  'prioridad' => self::MOSCOW[$h['prioridad']] ?? 'media', 'proyecto_id' => $pid];
            $iid = $epicas[$h['epica']] ?? 0;
            if ($h['epica'] !== '' && !$iid) $r['sin_epica'][$h['epica']] = true;
            if ($iid || !($ya[$h['codigo']] ?? null)) $d['item_id'] = $iid; // una ya enlazada a mano no se suelta
            $previa = $ya[$h['codigo']] ?? null;
            if (!$previa) {
                $sp = match ($modo) {
                    'repartir' => (string)($destino[$i] ?? ''),
                    'backlog'  => '',
                    default    => self::sprintInicial((string)$h['sprint']),
                };
                if ($sp !== '' && (!isset($sps[$sp]) || $sps[$sp]['fin'] < $hoy)) $sp = isset($sps[$sp]) ? $act : '';
                $d['sprint'] = $sp;
                $d['codigo'] = $h['codigo'];
            }
            $g = Backlog::guardar($d, $previa ? (int)$previa['id'] : 0, $quien);
            $r[$previa ? 'actualizadas' : 'creadas']++;
            $r[$g['sprint'] !== '' ? 'en_sprint' : 'en_backlog']++;
        }
        $r['sin_epica'] = array_keys($r['sin_epica']);
        return $r;
    }
}
