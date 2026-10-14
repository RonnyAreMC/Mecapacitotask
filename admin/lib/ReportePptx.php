<?php
/**
 * El reporte como presentación interactiva (.pptx): portada, índice con una
 * tarjeta por proyecto que lleva a su diapositiva, tablero, listado, una
 * diapositiva por proyecto, requerimientos sueltos y cierre.
 *
 * Se escribe el XML de PresentationML a mano sobre las piezas fijas de
 * lib/reporteria/plantilla-pptx.json (maestro, diseños, tema y logos), que se
 * sacaron de la presentación hecha y revisada a mano. Medidas en centímetros
 * sobre una diapositiva 16:9 de 33,87 × 19,05 cm.
 */
final class ReportePptx
{
    private const NAVY = '150089', CELESTE = '349AFE', VERDE = '21D866', GRIS = '57575B',
                  BLANCO = 'FFFFFF', NEGRO = '202020', GRISF = 'F2F4F8';
    private const ANCHO_UTIL = 31.1;   // de 1,4 a 32,5 cm
    private const Y_MAX = 15.9;        // lo que queda por encima del botón "Volver"

    /** @var array<int, array{xml: string, rels: array, id: int}> */
    private array $diapos = [];
    private int $act = -1;
    private array $anclas = [];
    private array $plantilla;

    public static function generar(array $rep): string
    {
        return (new self())->armar($rep);
    }

    private function __construct()
    {
        $this->plantilla = Reporte::plantilla('pptx');
    }

    /* ======================= Contenido ======================= */

    private function armar(array $rep): string
    {
        $R  = $rep['resumen'];
        $ps = $rep['proyectos'];

        $this->portada($rep);
        $this->indice($rep);
        $this->tablero($rep);
        $this->listado($rep);
        foreach ($ps as $p) $this->proyecto($p);
        $this->requerimientos($rep['requerimientos']);
        $this->cierre($rep);

        return $this->empaquetar($rep);
    }

    private function portada(array $rep): void
    {
        $R = $rep['resumen'];
        $this->nueva('portada');
        $this->rect(0, 0, 0.55, 19.05, self::NAVY);
        $this->rect(0, 0, 0.55, 5.2, self::VERDE);
        $this->imagen(2.2, 1.6, 7.4, 'color');
        $this->caja(2.2, 5.6, 18, 1.0, 'REPORTE EJECUTIVO', 13, true, self::CELESTE, 'l', 0.9);
        $this->caja(2.2, 6.5, 19, 2.4, mb_strtoupper($rep['titulo']), mb_strlen($rep['titulo']) > 22 ? 38 : 48, true, self::NAVY, 'l', 0.95);
        $this->rect(2.3, 9.2, 6.0, 0.16, self::VERDE);
        $this->caja(2.2, 9.9, 18.5, 1.8, $rep['subtitulo'], 17, false, self::GRIS, 'l', 1.15);
        $this->caja(2.2, 12.3, 18, 0.7, 'FECHA DE CORTE: ' . $rep['generado'], 11, true, self::CELESTE);
        $this->rect(2.2, 13.6, 6.4, 1.3, self::VERDE, null, true, ['Comenzar  ▶', 14, true, self::BLANCO], 'indice');

        $this->rect(21.6, 3.2, 10.9, 12.6, self::NAVY, null, true);
        $cifras = [[(string)$R['proyectos'], 'PROYECTOS EN DESARROLLO'],
                   [(string)$R['entregables'], 'ENTREGABLES GESTIONADOS'],
                   [$R['cumplimiento'] . '%', 'NIVEL DE CUMPLIMIENTO']];
        foreach ($cifras as $i => [$n, $et]) {
            $yb = 4.1 + $i * 4.0;
            $this->caja(22.6, $yb, 9.0, 1.9, $n, 44, true, self::BLANCO, 'l', 0.9);
            $this->caja(22.6, $yb + 1.95, 9.0, 0.7, $et, 10, true, self::VERDE);
            if ($i < 2) $this->rect(22.6, $yb + 3.1, 8.9, 0.03, self::CELESTE);
        }
        $this->rect(0, 18.5, 34.0, 0.55, self::GRISF);
        $this->caja(2.2, 18.62, 20, 0.5, mb_strtoupper($rep['pie']), 9, true, self::GRIS);
    }

    private function indice(array $rep): void
    {
        $this->nueva('indice');
        $this->cabecera('Navegación', 'Índice');
        $this->caja(1.4, 3.5, 28, 0.7, 'Pulsa cualquier tarjeta para ir a su detalle.', 12, false, self::GRIS);

        $ps   = $rep['proyectos'];
        $fils = max(1, (int)ceil(count($ps) / 4));
        $paso = min(3.0, 8.9 / $fils);
        $alto = $paso - 0.4;
        foreach ($ps as $i => $p) {
            $x = 1.4 + ($i % 4) * 7.9;
            $y = 4.5 + intdiv($i, 4) * $paso;
            $this->rect($x, $y, 7.4, $alto, self::BLANCO, self::CELESTE, true, null, 'proyecto:' . $p['id']);
            $this->caja($x + 0.45, $y + 0.3, 5.3, 1.0, $p['nombre'], 11.5, true, self::NAVY);
            $this->caja($x + 0.45, $y + 0.3, 6.5, 0.6, $p['avance'] . '%', 11.5, true, self::VERDE, 'r');
            $this->caja($x + 0.45, $y + $alto * 0.48, 6.5, 0.6, $p['entregables'] . ' entregables', 9.5, false, self::GRIS);
            $this->rect($x + 0.45, $y + $alto - 0.65, 6.5, 0.3, self::GRISF);
            $this->rect($x + 0.45, $y + $alto - 0.65, max(0.12, 6.5 * $p['avance'] / 100), 0.3, self::VERDE);
        }

        $extras = [['Tablero de control', 'tablero'], ['Listado de proyectos', 'listado'],
                   ['Requerimientos sueltos', 'requerimientos'], ['Lectura ejecutiva', 'cierre']];
        $n  = count($extras);
        $an = (self::ANCHO_UTIL - 0.5 * ($n - 1)) / $n;
        foreach ($extras as $i => [$txt, $destino]) {
            $this->rect(1.4 + $i * ($an + 0.5), 13.6, $an, 1.2, self::NAVY, null, true, [$txt, 11, true, self::BLANCO], $destino);
        }
    }

    private function tablero(array $rep): void
    {
        $R  = $rep['resumen'];
        $ps = $rep['proyectos'];
        $this->nueva('tablero');
        $this->cabecera('Indicadores globales', 'Tablero de control');
        $this->kpis(3.9, [[$R['entregables'], 'Entregables'], [$R['completados'], 'Completados'],
                          [$R['en_curso'], 'En curso'], [$R['por_hacer'], 'Por hacer'],
                          [$R['cumplimiento'] . '%', 'Cumplimiento']]);
        if (!$ps) {
            $this->volver();
            return;
        }
        $paso = min(0.78, 7.9 / count($ps));
        $hb   = min(0.42, $paso * 0.55);
        $tam  = $paso < 0.6 ? 8 : 9.5;

        $this->caja(1.4, 7.1, 14, 0.7, 'VOLUMEN DE ENTREGABLES POR PROYECTO', 11, true, self::NAVY);
        $porVol = $ps;
        usort($porVol, fn($a, $b) => $b['entregables'] <=> $a['entregables']);
        $max = max(1, $porVol[0]['entregables']);
        foreach ($porVol as $i => $p) {
            $y = 7.9 + $i * $paso;
            $this->caja(1.4, $y, 5.6, 0.6, self::corto($p['nombre'], 26), $tam, false, self::NEGRO);
            $this->rect(7.2, $y + 0.08, 12.0, $hb, self::GRISF);
            $this->rect(7.2, $y + 0.08, max(0.1, 12.0 * $p['entregables'] / $max), $hb, self::CELESTE);
            $this->caja(19.4, $y, 2.0, 0.6, (string)$p['entregables'], $tam, true, self::NAVY);
        }
        $this->caja(22.4, 7.1, 9, 0.7, 'NIVEL DE AVANCE POR PROYECTO', 11, true, self::NAVY);
        foreach ($ps as $i => $p) {
            $y = 7.9 + $i * $paso;
            $this->caja(22.4, $y, 4.6, 0.6, self::corto($p['nombre'], 22), $tam, false, self::NEGRO);
            $this->rect(27.0, $y + 0.08, 3.6, $hb, self::GRISF);
            $this->rect(27.0, $y + 0.08, max(0.1, 3.6 * $p['avance'] / 100), $hb, self::VERDE);
            $this->caja(30.7, $y, 1.6, 0.6, $p['avance'] . '%', $tam, true, self::VERDE);
        }
        $this->volver();
    }

    private function listado(array $rep): void
    {
        $R = $rep['resumen'];
        $filas = array_map(fn($p) => [$p['nombre'], $p['avance'] . '%', $p['entregables'], $p['completados'],
                                      $p['en_curso'], $p['por_hacer']], $rep['proyectos']);
        $filas[] = ['TOTAL', $R['cumplimiento'] . '%', $R['entregables'], $R['completados'], $R['en_curso'], $R['por_hacer']];
        $this->nueva('listado');
        $this->cabecera('Panorama', 'Listado de proyectos');
        $this->tablaPaginada(3.8, ['Proyecto', 'Avance', 'Entregables', 'Completados', 'En curso', 'Por hacer'],
            $filas, [11.0, 3.4, 4.4, 4.6, 3.9, 3.8], 0.82, 11, 'Panorama', 'Listado de proyectos');
        $this->volver();
    }

    private function proyecto(array $p): void
    {
        $this->nueva('proyecto:' . $p['id']);
        $this->cabecera('Proyecto', $p['nombre']);
        $this->kpis(3.9, [[$p['entregables'], 'Entregables'], [$p['completados'], 'Completados'],
                          [$p['en_curso'], 'En curso'], [$p['por_hacer'], 'Por hacer'], [$p['avance'] . '%', 'Avance']]);
        $this->caja(1.4, 7.2, 20, 0.7, 'LÍNEAS DE TRABAJO', 11, true, self::NAVY);
        $filas = array_map(fn($l) => [$l['nombre'], $l['entregables'], $l['completados'], $l['en_curso'], $l['por_hacer']], $p['lineas']);
        $hayNota = $p['observadas'] > 0;
        $alto = min(1.15, (self::Y_MAX - 8.0 - ($hayNota ? 0.8 : 0)) / (count($filas) + 1));
        $fin = $this->tablaPaginada(8.0, ['Línea de trabajo', 'Entregables', 'Completados', 'En curso', 'Por hacer'],
            $filas, [15.5, 3.8, 4.2, 3.8, 3.8], max(0.62, $alto), 10.5, 'Proyecto', $p['nombre']);
        if ($hayNota) {
            $this->caja(1.4, $fin + 0.3, 31, 0.6, sprintf('%d %s con observaciones abiertas: cuentan como en curso hasta que se resuelvan.',
                $p['observadas'], $p['observadas'] === 1 ? 'entregable terminado sigue' : 'entregables terminados siguen'), 10, false, self::GRIS);
        }
        $this->volver();
    }

    private function requerimientos(array $rq): void
    {
        $this->nueva('requerimientos');
        $this->cabecera('Fuera de proyecto', 'Requerimientos sueltos');
        $this->kpis(3.9, [[$rq['total'], 'Total'], [$rq['resueltos'], 'Resueltos'],
                          [count($rq['abiertos']), 'Abiertos'], [$rq['vencidos'], 'Vencidos']]);
        if ($rq['abiertos']) {
            $filas = array_map(fn($q) => [self::corto($q['titulo'], 90), $q['entrega'] ?: '—', $q['vencido'] ? 'Vencido' : 'En plazo'], $rq['abiertos']);
            $this->tablaPaginada(7.3, ['Requerimiento abierto', 'Entrega', 'Situación'], $filas,
                [20.9, 5.2, 5.0], 0.74, 10.5, 'Fuera de proyecto', 'Requerimientos sueltos');
        } else {
            $this->caja(1.4, 7.4, 30, 0.8, 'No hay requerimientos abiertos.', 13, false, self::GRIS);
        }
        $this->volver();
    }

    private function cierre(array $rep): void
    {
        $this->nueva('cierre');
        $this->imagen(1.6, 1.4, 6.4, 'color');
        $this->caja(1.6, 4.4, 26, 1.4, 'LECTURA EJECUTIVA', 34, true, self::NAVY);
        $this->rect(1.7, 6.1, 5.4, 0.14, self::VERDE);
        $cs   = array_slice($rep['conclusiones'], 0, 8);
        $paso = $cs ? min(1.5, 8.0 / count($cs)) : 1.5;
        $tam  = $paso < 1.2 ? 11.5 : 13;
        foreach ($cs as $i => $c) {
            $y = 6.9 + $i * $paso;
            $this->rect(1.6, $y, 0.9, 0.9, self::VERDE, null, true, [(string)($i + 1), 12, true, self::BLANCO]);
            $this->caja(2.9, $y + 0.08, 29, 1.2, $c, $tam, false, self::NAVY);
        }
        $this->rect(1.6, 15.6, 5.6, 1.1, self::BLANCO, self::NAVY, true, ['◀  Volver al índice', 11, true, self::NAVY], 'indice');
    }

    /* ======================= Piezas comunes ======================= */

    private function cabecera(string $kicker, string $titulo): void
    {
        $this->rect(0, 0, 34.0, 3.1, self::CELESTE);
        $this->caja(1.4, 0.55, 22, 0.6, mb_strtoupper($kicker), 10, true, self::BLANCO);
        $tam = mb_strlen($titulo) > 70 ? 17 : (mb_strlen($titulo) > 44 ? 20 : 24);
        $this->caja(1.4, 1.15, 24.5, 1.8, $titulo, $tam, true, self::BLANCO, 'l', 0.92);
        $this->imagen(27.2, 0.95, 5.0, 'blanco');
    }

    private function volver(): void
    {
        $this->rect(1.4, 16.3, 4.4, 1.0, self::BLANCO, self::NAVY, true, ['◀  Volver al índice', 11, true, self::NAVY], 'indice');
    }

    /** Fila de tarjetas de cifras a todo el ancho útil. */
    private function kpis(float $y, array $items): void
    {
        $n  = max(1, count($items));
        $an = (self::ANCHO_UTIL - 0.4 * ($n - 1)) / $n;
        foreach (array_values($items) as $i => [$cifra, $etq]) {
            $x = 1.4 + $i * ($an + 0.4);
            $this->rect($x, $y, $an, 2.6, self::GRISF, null, true);
            $this->caja($x, $y + 0.35, $an, 1.1, (string)$cifra, 30, true, self::NAVY, 'ctr');
            $this->caja($x, $y + 1.72, $an, 0.7, mb_strtoupper((string)$etq), 9, true, self::VERDE, 'ctr');
        }
    }

    /**
     * Tabla que, si no cabe, sigue en diapositivas de continuación con la
     * misma cabecera. Devuelve la Y donde terminó.
     */
    private function tablaPaginada(float $y, array $cols, array $filas, array $anchos, float $altoMin,
                                   float $tam, string $kicker, string $titulo): float
    {
        $mayus  = array_map(fn($f) => array_map(fn($v) => self::celda((string)$v), $f), $filas);
        $altos  = array_map(fn($f) => $this->altoFila($f, $anchos, $altoMin, $tam), $mayus);
        $altoCab = $altoMin;
        $tramo  = [];
        $suma   = $altoCab;
        foreach ($mayus as $i => $f) {
            if ($tramo && $y + $suma + $altos[$i] > self::Y_MAX) {
                $this->tabla($y, $cols, $tramo, $anchos, $altoCab, $tam);
                $this->volver();
                $this->nueva(null);
                $this->cabecera($kicker, $titulo . ' (cont.)');
                $y = 3.8; $tramo = []; $suma = $altoCab;
            }
            $tramo[] = [$f, $altos[$i]];
            $suma += $altos[$i];
        }
        if ($tramo) $this->tabla($y, $cols, $tramo, $anchos, $altoCab, $tam);
        return $y + $suma;
    }

    /** Detalle de tabla: MAYÚSCULA y negrita, salvo los párrafos largos, que así no se leen. */
    private static function celda(string $v): string
    {
        return mb_strlen($v) > 90 ? $v : mb_strtoupper($v);
    }

    private function altoFila(array $fila, array $anchos, float $altoMin, float $tam): float
    {
        $lin = 1;
        foreach ($fila as $i => $v) {
            $lin = max($lin, $this->lineas((string)$v, ($anchos[$i] ?? 4) - 0.3, $tam, mb_strtoupper((string)$v) === (string)$v));
        }
        return max($altoMin, $lin * $tam * 0.0353 * 1.2 + 0.3);
    }

    /** Líneas que ocupa un texto en un ancho dado (estimación por ancho medio de letra en Calibri). */
    private function lineas(string $txt, float $anchoCm, float $tam, bool $mayus): int
    {
        $porLetra = $tam * 0.0353 * ($mayus ? 0.6 : 0.46);
        $caben = max(1, (int)floor($anchoCm / $porLetra));
        $n = 0;
        foreach (preg_split('/\R/u', $txt) as $parr) {
            $n += max(1, (int)ceil(mb_strlen($parr) / $caben));
        }
        return $n;
    }

    private static function corto(string $s, int $max = 30): string
    {
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }

    /* ======================= Formas (PresentationML) ======================= */

    private function nueva(?string $ancla): void
    {
        $this->diapos[] = ['xml' => '', 'rels' => [], 'id' => 1];
        $this->act = count($this->diapos) - 1;
        if ($ancla !== null && !isset($this->anclas[$ancla])) $this->anclas[$ancla] = $this->act;
    }

    private function idForma(): int
    {
        return ++$this->diapos[$this->act]['id'];
    }

    private function rel(string $tipo, string $destino): string
    {
        $rels = &$this->diapos[$this->act]['rels'];
        foreach ($rels as $id => $r) {
            if ($r === [$tipo, $destino]) return $id;
        }
        $id = 'rId' . (count($rels) + 2);   // rId1 es el diseño de diapositiva
        $rels[$id] = [$tipo, $destino];
        return $id;
    }

    private static function emu(float $cm): int
    {
        return (int)round($cm * 360000);
    }

    private static function xml(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function xfrm(float $x, float $y, float $w, float $h): string
    {
        return '<a:xfrm><a:off x="' . self::emu($x) . '" y="' . self::emu($y) . '"/>'
             . '<a:ext cx="' . self::emu(max(0.01, $w)) . '" cy="' . self::emu(max(0.01, $h)) . '"/></a:xfrm>';
    }

    private static function run(string $txt, float $tam, bool $neg, string $color): string
    {
        return '<a:r><a:rPr lang="es-EC" sz="' . (int)round($tam * 100) . '" b="' . ($neg ? 1 : 0) . '" dirty="0">'
             . '<a:solidFill><a:srgbClr val="' . $color . '"/></a:solidFill>'
             . '<a:latin typeface="Calibri"/><a:cs typeface="Calibri"/></a:rPr>'
             . '<a:t>' . self::xml($txt) . '</a:t></a:r>';
    }

    /** Párrafos (uno por salto de línea) con el mismo formato. */
    private static function parrafos(string $txt, float $tam, bool $neg, string $color, string $algn, float $esp = 1.0): string
    {
        $out = '';
        foreach (preg_split('/\R/u', $txt) as $linea) {
            $out .= '<a:p><a:pPr algn="' . $algn . '"><a:lnSpc><a:spcPct val="' . (int)round($esp * 100000) . '"/></a:lnSpc></a:pPr>'
                  . ($linea !== '' ? self::run($linea, $tam, $neg, $color) : '')
                  . '<a:endParaRPr lang="es-EC" sz="' . (int)round($tam * 100) . '" dirty="0"/></a:p>';
        }
        return $out;
    }

    /** Cuadro de texto sin fondo ni márgenes. */
    private function caja(float $x, float $y, float $w, float $h, string $txt, float $tam, bool $neg,
                          string $color, string $algn = 'l', float $esp = 1.0): void
    {
        $id = $this->idForma();
        $this->diapos[$this->act]['xml'] .=
            '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Texto ' . $id . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
          . '<p:spPr>' . self::xfrm($x, $y, $w, $h) . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'
          . '<p:txBody><a:bodyPr wrap="square" lIns="0" tIns="0" rIns="0" bIns="0" rtlCol="0"><a:spAutoFit/></a:bodyPr><a:lstStyle/>'
          . self::parrafos($txt, $tam, $neg, $color, $algn, $esp) . '</p:txBody></p:sp>';
    }

    /**
     * Rectángulo (o redondeado), con borde, texto centrado y enlace a otra
     * diapositiva opcionales. $texto = [texto, tamaño, negrita, color].
     */
    private function rect(float $x, float $y, float $w, float $h, string $fondo, ?string $borde = null,
                          bool $redondo = false, ?array $texto = null, ?string $enlace = null): void
    {
        $id = $this->idForma();
        $link = $enlace !== null
            ? '<a:hlinkClick r:id="' . $this->rel('slide', $enlace) . '" action="ppaction://hlinksldjump"/>' : '';
        $ln = $borde !== null
            ? '<a:ln w="12700"><a:solidFill><a:srgbClr val="' . $borde . '"/></a:solidFill></a:ln>'
            : '<a:ln><a:noFill/></a:ln>';
        $cuerpo = $texto
            ? self::parrafos((string)$texto[0], (float)$texto[1], (bool)$texto[2], (string)$texto[3], 'ctr')
            : '<a:p><a:endParaRPr lang="es-EC" dirty="0"/></a:p>';
        $this->diapos[$this->act]['xml'] .=
            '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Forma ' . $id . '">' . $link . '</p:cNvPr><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
          . '<p:spPr>' . self::xfrm($x, $y, $w, $h) . '<a:prstGeom prst="' . ($redondo ? 'roundRect' : 'rect') . '"><a:avLst/></a:prstGeom>'
          . '<a:solidFill><a:srgbClr val="' . $fondo . '"/></a:solidFill>' . $ln . '</p:spPr>'
          . '<p:txBody><a:bodyPr wrap="square" lIns="45720" tIns="0" rIns="45720" bIns="0" rtlCol="0" anchor="ctr"/><a:lstStyle/>'
          . $cuerpo . '</p:txBody></p:sp>';
    }

    /** Logo de la plantilla, con su proporción real. */
    private function imagen(float $x, float $y, float $w, string $cual): void
    {
        $archivo = 'logo-' . ($cual === 'blanco' ? 'blanco' : 'color') . '.png';
        $datos = base64_decode((string)($this->plantilla['ppt/media/' . $archivo] ?? ''));
        $dim = $datos !== '' ? @getimagesizefromstring($datos) : false;
        $h = $dim ? $w * $dim[1] / $dim[0] : $w * 0.23;
        $id = $this->idForma();
        $rid = $this->rel('image', '../media/' . $archivo);
        $this->diapos[$this->act]['xml'] .=
            '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="Logo ' . $id . '" descr="' . $archivo . '"/>'
          . '<p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
          . '<p:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
          . '<p:spPr>' . self::xfrm($x, $y, $w, $h) . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    /** $filas = [[celdas, alto], ...]. Cabecera navy; detalle en negrita, filas alternas. */
    private function tabla(float $y, array $cols, array $filas, array $anchos, float $altoCab, float $tam): void
    {
        // Columna "larga" (texto corrido): se alinea a la izquierda; las de cifras, al centro.
        $larga = [];
        foreach ($cols as $i => $_) {
            $larga[$i] = $i === 0;
            foreach ($filas as [$f, $_h]) {
                if (mb_strlen((string)($f[$i] ?? '')) > 24) { $larga[$i] = true; break; }
            }
        }
        $celda = function (string $txt, float $t, string $color, string $fondo, string $algn): string {
            return '<a:tc><a:txBody><a:bodyPr/><a:lstStyle/><a:p><a:pPr algn="' . $algn . '"/>'
                 . self::run($txt, $t, true, $color) . '</a:p></a:txBody>'
                 . '<a:tcPr marL="91440" marR="91440" marT="45720" marB="45720" anchor="ctr">'
                 . '<a:solidFill><a:srgbClr val="' . $fondo . '"/></a:solidFill></a:tcPr></a:tc>';
        };
        $grid = implode('', array_map(fn($w) => '<a:gridCol w="' . self::emu($w) . '"/>', $anchos));
        $xml = '<a:tr h="' . self::emu($altoCab) . '">';
        foreach ($cols as $i => $c) {
            $xml .= $celda(mb_strtoupper((string)$c), $tam - 1, self::BLANCO, self::NAVY, $larga[$i] ? 'l' : 'ctr');
        }
        $xml .= '</a:tr>';
        $alto = $altoCab;
        foreach ($filas as $j => [$f, $h]) {
            $total = mb_strtoupper((string)($f[0] ?? '')) === 'TOTAL';
            $xml .= '<a:tr h="' . self::emu($h) . '">';
            foreach ($cols as $i => $_) {
                $xml .= $celda((string)($f[$i] ?? ''), $tam, $total ? self::NAVY : self::NEGRO,
                               $j % 2 === 0 ? self::GRISF : self::BLANCO, $larga[$i] ? 'l' : 'ctr');
            }
            $xml .= '</a:tr>';
            $alto += $h;
        }
        $id = $this->idForma();
        $this->diapos[$this->act]['xml'] .=
            '<p:graphicFrame><p:nvGraphicFramePr><p:cNvPr id="' . $id . '" name="Tabla ' . $id . '"/>'
          . '<p:cNvGraphicFramePr><a:graphicFrameLocks noGrp="1"/></p:cNvGraphicFramePr><p:nvPr/></p:nvGraphicFramePr>'
          . '<p:xfrm><a:off x="' . self::emu(1.4) . '" y="' . self::emu($y) . '"/><a:ext cx="' . self::emu(array_sum($anchos))
          . '" cy="' . self::emu($alto) . '"/></p:xfrm>'
          . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/table"><a:tbl>'
          . '<a:tblPr firstRow="1" bandRow="1"><a:tableStyleId>{5C22544A-7EE6-4342-B048-85BDC9FD1C3A}</a:tableStyleId></a:tblPr>'
          . '<a:tblGrid>' . $grid . '</a:tblGrid>' . $xml . '</a:tbl></a:graphicData></a:graphic></p:graphicFrame>';
    }

    /* ======================= Paquete ======================= */

    private function empaquetar(array $rep): string
    {
        $P = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
        $zip = new ZipSimple();
        $n = count($this->diapos);

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>';
        foreach ((array)($this->plantilla['__overrides__'] ?? []) as [$parte, $tipo]) {
            $ct .= '<Override PartName="' . $parte . '" ContentType="' . $tipo . '"/>';
        }
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }
        $zip->agregar('[Content_Types].xml', $ct . '</Types>');

        foreach ($this->plantilla as $ruta => $b64) {
            if (str_starts_with($ruta, '__')) continue;
            $datos = base64_decode((string)$b64);
            if ($ruta === 'ppt/presentation.xml') {
                $ids = '';
                for ($i = 1; $i <= $n; $i++) $ids .= '<p:sldId id="' . (255 + $i) . '" r:id="rId' . (100 + $i) . '"/>';
                $datos = str_replace('{{SLIDES}}', $ids, $datos);
            }
            $zip->agregar($ruta, $datos);
        }

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ((array)($this->plantilla['__rels_fijas__'] ?? []) as [$id, $tipo, $destino]) {
            $rels .= '<Relationship Id="' . $id . '" Type="' . $tipo . '" Target="' . $destino . '"/>';
        }
        for ($i = 1; $i <= $n; $i++) {
            $rels .= '<Relationship Id="rId' . (100 + $i) . '" Type="' . $P . 'slide" Target="slides/slide' . $i . '.xml"/>';
        }
        $zip->agregar('ppt/_rels/presentation.xml.rels', $rels . '</Relationships>');

        $ahora = gmdate('Y-m-d\TH:i:s\Z');
        $zip->agregar('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::xml($rep['titulo']) . '</dc:title><dc:creator>InnoTech Hub</dc:creator>'
            . '<cp:lastModifiedBy>InnoTech Hub</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $ahora . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $ahora . '</dcterms:modified></cp:coreProperties>');

        foreach ($this->diapos as $k => $d) {
            $i = $k + 1;
            $zip->agregar('ppt/slides/slide' . $i . '.xml',
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              . '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
              . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
              . 'xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
              . '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'
              . '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'
              . $d['xml'] . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>');

            $r = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
               . '<Relationship Id="rId1" Type="' . $P . 'slideLayout" Target="../slideLayouts/slideLayout7.xml"/>';
            foreach ($d['rels'] as $id => [$tipo, $destino]) {
                if ($tipo === 'slide') {
                    // Un enlace a algo que no llegó a existir lleva al índice.
                    $destino = 'slide' . (($this->anclas[$destino] ?? $this->anclas['indice'] ?? 0) + 1) . '.xml';
                }
                $r .= '<Relationship Id="' . $id . '" Type="' . $P . $tipo . '" Target="' . $destino . '"/>';
            }
            $zip->agregar('ppt/slides/_rels/slide' . $i . '.xml.rels', $r . '</Relationships>');
        }
        return $zip->binario();
    }
}
