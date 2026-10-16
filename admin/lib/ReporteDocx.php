<?php
/**
 * El reporte como documento Word (.docx) sobre el formato corporativo de
 * InnoTech: el encabezado con el logo, el pie institucional, los estilos y
 * los márgenes salen de lib/reporteria/plantilla-docx.json (el formato
 * oficial, ya revisado); aquí solo se escribe el cuerpo.
 *
 * Es el mismo documento que la vista de impresión (PDF): mismo orden, mismas
 * tablas, mismos números.
 */
final class ReporteDocx
{
    private const NAVY = '150089', VERDE = '21D866', GRIS = '57575B', TEXTO = '202020';
    private const CABFILL = 'EAF2FF', ALT = 'F7F7F9';

    private string $cuerpo = '';
    private bool $ultimoTabla = false;

    public static function generar(array $rep): string
    {
        $g = new self();
        $g->armar($rep);
        return $g->empaquetar($rep);
    }

    private function armar(array $rep): void
    {
        $R  = $rep['resumen'];
        $rq = $rep['requerimientos'];

        /* ---- Portada ---- */
        $this->p('', 11, false, self::TEXTO, 26, 0);
        $this->p(mb_strtoupper($rep['titulo']), 28, true, self::NAVY, 0, 2);
        $this->p(mb_strtoupper($rep['subtitulo']), 13, true, self::NAVY, 0, 8);
        $this->p('Fecha de corte: ' . $rep['generado'], 10, false, self::GRIS, 0, 2);
        $this->p(sprintf('%s   ·   %s   ·   %d%% de cumplimiento', Reporte::n($R['proyectos'], 'proyecto', 'proyectos'),
            Reporte::n($R['entregables'], 'entregable', 'entregables'), $R['cumplimiento']), 13, true, self::VERDE, 4, 0);

        $n = 0;
        /* ---- 1. Resumen ejecutivo ---- */
        $this->titulo(++$n . '. Resumen ejecutivo');
        $this->p(($R['proyectos'] === 1
                ? sprintf('El reporte cubre el proyecto %s, con %s registrados. ', $rep['proyectos'][0]['nombre'],
                          Reporte::n($R['entregables'], 'entregable', 'entregables'))
                : sprintf('El portafolio comprende %d proyectos en desarrollo con %s registrados. ', $R['proyectos'],
                          Reporte::n($R['entregables'], 'entregable', 'entregables')))
            . sprintf('El nivel de cumplimiento global es del %d%%: %d completados, %d en ejecución o validación '
            . 'y %d pendientes de iniciar.', $R['cumplimiento'], $R['completados'], $R['en_curso'], $R['por_hacer']));
        if ($R['observadas'] > 0) {
            $this->p(sprintf('De los entregables en ejecución, %d ya están terminados pero tienen observaciones abiertas: '
                . 'cuentan como en curso hasta que se resuelvan.', $R['observadas']));
        }
        if ($rep['con_requerimientos']) {
            $this->p(sprintf('Se gestionan además %d requerimientos sueltos, de los cuales %d siguen abiertos y %d están vencidos.',
                $rq['total'], count($rq['abiertos']), $rq['vencidos']));
        }

        /* ---- 2. Proyectos y volumen (con uno solo, sería repetir su tabla) ---- */
        if (count($rep['proyectos']) > 1) {
            $this->titulo(++$n . '. Proyectos y volumen de trabajo');
            $filas = array_map(fn($p) => [$p['nombre'], $p['avance'] . '%', $p['entregables'], $p['completados'],
                                          $p['en_curso'], $p['por_hacer']], $rep['proyectos']);
            $filas[] = ['TOTAL', $R['cumplimiento'] . '%', $R['entregables'], $R['completados'], $R['en_curso'], $R['por_hacer']];
            $this->tabla(['Proyecto', 'Avance', 'Entregables', 'Completados', 'En curso', 'Por hacer'],
                $filas, [4.9, 1.7, 2.6, 2.7, 2.0, 2.1]);
        }

        /* ---- 3. Líneas de trabajo ---- */
        $this->titulo(++$n . '. Líneas de trabajo por proyecto');
        $this->p('Qué se está construyendo en cada proyecto, agrupado por área funcional, '
            . 'con el número de entregables que agrupa cada línea.', 10, false, self::GRIS, 2, 6);
        foreach ($rep['proyectos'] as $p) {
            $this->p(mb_strtoupper($p['nombre']) . '  ·  ' . $p['entregables'] . ' ENTREGABLES', 11, true, self::NAVY, 10, 3, true);
            $this->tabla(['Línea de trabajo', 'Entregables', 'Completados', 'En curso', 'Por hacer'],
                array_map(fn($l) => [$l['nombre'], $l['entregables'], $l['completados'], $l['en_curso'], $l['por_hacer']], $p['lineas']),
                [6.0, 2.6, 2.7, 2.1, 2.1]);
        }

        /* ---- 4. Requerimientos sueltos (si entran en el reporte) ---- */
        if ($rep['con_requerimientos']) {
            $this->titulo(++$n . '. Requerimientos sueltos');
            $this->p(sprintf('Peticiones que no pertenecen a ningún proyecto: %d en total, %d abiertas y %d vencidas.',
                $rq['total'], count($rq['abiertos']), $rq['vencidos']));
            if ($rq['abiertos']) {
                $this->tabla(['Requerimiento abierto', 'Entrega', 'Situación'],
                    array_map(fn($q) => [$q['titulo'], $q['entrega'] ?: '—', $q['vencido'] ? 'Vencido' : 'En plazo'], $rq['abiertos']),
                    [10.4, 2.8, 2.8]);
            }
        }

        /* ---- Conclusiones ---- */
        $this->titulo(++$n . '. Conclusiones');
        foreach ($rep['conclusiones'] as $c) {
            $this->p('•  ' . $c, 10.5, false, self::TEXTO, 2, 2);
        }
    }

    /* ======================= WordprocessingML ======================= */

    private static function xml(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function run(string $txt, float $tam, bool $neg, string $color): string
    {
        $sz = (int)round($tam * 2);
        return '<w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
             . ($neg ? '<w:b/><w:bCs/>' : '') . '<w:color w:val="' . $color . '"/>'
             . '<w:sz w:val="' . $sz . '"/><w:szCs w:val="' . $sz . '"/></w:rPr>'
             . '<w:t xml:space="preserve">' . self::xml($txt) . '</w:t></w:r>';
    }

    /** Párrafo. Espaciados en puntos; $conSiguiente lo amarra a lo que viene (títulos de tabla). */
    private function p(string $txt, float $tam = 10.5, bool $neg = false, string $color = self::TEXTO,
                       float $antes = 4, float $despues = 3, bool $conSiguiente = false, string $borde = '',
                       string $jc = ''): void
    {
        $this->cuerpo .= '<w:p><w:pPr>' . ($conSiguiente ? '<w:keepNext/>' : '') . $borde
            . '<w:spacing w:before="' . (int)round($antes * 20) . '" w:after="' . (int)round($despues * 20) . '"/>'
            . ($jc !== '' ? '<w:jc w:val="' . $jc . '"/>' : '') . '</w:pPr>'
            . ($txt !== '' ? self::run($txt, $tam, $neg, $color) : '') . '</w:p>';
        $this->ultimoTabla = false;
    }

    /** Título de sección: MAYÚSCULA, navy y un filete fino debajo. */
    private function titulo(string $txt): void
    {
        $this->p(mb_strtoupper($txt), 14, true, self::NAVY, 16, 2, true,
            '<w:pBdr><w:bottom w:val="single" w:sz="12" w:space="3" w:color="' . self::NAVY . '"/></w:pBdr>');
    }

    /** Tabla con cabecera celeste claro (se repite si salta de página) y filas alternas. Anchos en cm. */
    private function tabla(array $cabeceras, array $filas, array $anchos): void
    {
        if ($this->ultimoTabla) $this->p('', 4, false, self::TEXTO, 0, 0);   // dos tablas pegadas Word las fusiona
        $tw = array_map(fn($cm) => (int)round($cm * 567), $anchos);
        $celda = function (string $txt, int $w, bool $centro, bool $neg, string $color, string $fondo, int $esp): string {
            return '<w:tc><w:tcPr><w:tcW w:w="' . $w . '" w:type="dxa"/>'
                 . ($fondo !== '' ? '<w:shd w:val="clear" w:color="auto" w:fill="' . $fondo . '"/>' : '')
                 . '<w:vAlign w:val="center"/></w:tcPr>'
                 . '<w:p><w:pPr><w:spacing w:before="' . $esp . '" w:after="' . $esp . '"/>'
                 . '<w:jc w:val="' . ($centro ? 'center' : 'left') . '"/></w:pPr>'
                 . self::run($txt, 9, $neg, $color) . '</w:p></w:tc>';
        };
        $x = '<w:tbl><w:tblPr><w:tblStyle w:val="Tablaconcuadrcula"/><w:tblW w:w="' . array_sum($tw) . '" w:type="dxa"/>'
           . '<w:jc w:val="center"/><w:tblLayout w:type="fixed"/>'
           . '<w:tblLook w:val="04A0" w:firstRow="1" w:lastRow="0" w:firstColumn="1" w:lastColumn="0" w:noHBand="0" w:noVBand="1"/>'
           . '</w:tblPr><w:tblGrid>' . implode('', array_map(fn($w) => '<w:gridCol w:w="' . $w . '"/>', $tw)) . '</w:tblGrid>';
        // Columnas de texto corrido a la izquierda; las de cifras, al centro.
        $larga = [];
        foreach ($cabeceras as $i => $_) {
            $larga[$i] = $i === 0;
            foreach ($filas as $f) {
                if (mb_strlen((string)($f[$i] ?? '')) > 24) { $larga[$i] = true; break; }
            }
        }
        $x .= '<w:tr><w:trPr><w:tblHeader/></w:trPr>';
        foreach ($cabeceras as $i => $c) {
            $x .= $celda(mb_strtoupper((string)$c), $tw[$i] ?? 1000, !$larga[$i], true, self::NAVY, self::CABFILL, 40);
        }
        $x .= '</w:tr>';
        foreach (array_values($filas) as $j => $f) {
            $total = mb_strtoupper((string)($f[0] ?? '')) === 'TOTAL';
            $x .= '<w:tr><w:trPr><w:cantSplit/></w:trPr>';
            foreach ($cabeceras as $i => $_) {
                $x .= $celda((string)($f[$i] ?? ''), $tw[$i] ?? 1000, !$larga[$i], $total,
                             $total ? self::NAVY : self::TEXTO, $j % 2 === 1 ? self::ALT : '', 20);
            }
            $x .= '</w:tr>';
        }
        $this->cuerpo .= $x . '</w:tbl>';
        $this->ultimoTabla = true;
    }

    /* ======================= Paquete ======================= */

    private function empaquetar(array $rep): string
    {
        $zip = new ZipSimple();
        $plantilla = Reporte::plantilla('docx');
        // [Content_Types].xml primero, como lo deja Word.
        $orden = array_keys($plantilla);
        usort($orden, fn($a, $b) => ($b === '[Content_Types].xml') <=> ($a === '[Content_Types].xml'));
        foreach ($orden as $ruta) {
            $datos = base64_decode((string)$plantilla[$ruta]);
            if ($ruta === 'word/document.xml') {
                $datos = str_replace('{{CUERPO}}', $this->cuerpo, $datos);
            }
            $zip->agregar($ruta, $datos);
        }
        $ahora = gmdate('Y-m-d\TH:i:s\Z');
        $zip->agregar('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::xml($rep['titulo']) . '</dc:title><dc:creator>InnoTech Hub</dc:creator>'
            . '<cp:lastModifiedBy>InnoTech Hub</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $ahora . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $ahora . '</dcterms:modified></cp:coreProperties>');
        return $zip->binario();
    }
}
