<?php
/**
 * Dibuja la hoja «Firmas de responsabilidad» que PdfFirmas agrega al final del
 * documento: la misma tabla «Rol / Cargo | Firma» de los informes, con la
 * imagen de cada firma (o «Firmado electrónicamente», si firmó con su
 * certificado), su nombre, cargo y fecha; al pie, el SHA-256 del original.
 * El historial NO va en el PDF: se ve en el Hub (modal «Historial»).
 * Cabe siempre en una hoja (hasta 8 firmantes), porque cuando ya hay firmas
 * electrónicas se redibuja en el mismo lugar y no puede cambiar de tamaño.
 *
 * $d = [
 *   'titulo'    => 'Informe de desarrolladores',
 *   'codigo'    => 'Documento N.º 12',
 *   'estado'    => 'Firmado por todos' | 'En firma: 1 de 3' | …,
 *   'firmantes' => [['rol', 'nombre', 'cargo', 'estado' => firmado|pendiente|rechazado, 'fecha', 'png'], …],
 *   'hash'      => sha256 del original,
 *   'generado'  => '26/11/2026 15:04',
 * ]
 * Si no cabe en una hoja, sigue en otra.
 */
final class HojaFirmas
{
    private const TINTA = '0.11 0.13 0.18';
    private const GRIS  = '0.42 0.45 0.5';
    private const LINEA = '0.82 0.84 0.87';
    private const FONDO = '0.955 0.96 0.97';
    private const MARCA = '0.063 0.157 0.353';
    private const VERDE = '0.1 0.46 0.27';
    private const ROJO  = '0.72 0.16 0.16';

    private float $w;
    private float $h;
    private float $x0 = 56;
    private float $ancho;
    private float $y = 0;
    private float $piso = 92;      // por debajo va el pie
    private array $rects = [];     // dónde va la firma de cada uno: [página, x1, y1, x2, y2]
    private string $ops = '';
    private array $paginas = [];
    private array $imagenes = [];
    private array $d;

    /** [páginas, imágenes, casillas de firma] para PdfFirmas::agregar() y firmarDigital(). */
    public static function armar(array $d, float $w, float $h): array
    {
        $hoja = new self($d, $w, $h);
        $hoja->dibujar();
        return [$hoja->paginas, $hoja->imagenes, $hoja->rects];
    }

    private function __construct(array $d, float $w, float $h)
    {
        $this->d = $d;
        $this->w = $w;
        $this->h = $h;
        $this->ancho = $w - 2 * $this->x0;
    }

    private function dibujar(): void
    {
        $this->nuevaPagina(false);
        $this->tabla();
        $this->cerrarPagina();
        // El pie lleva «hoja x de n»: se agrega al final, cuando ya se sabe cuántas son
        $n = count($this->paginas);
        foreach ($this->paginas as $i => &$pg) $pg['contenido'] .= $this->pie($i + 1, $n);
        unset($pg);
    }

    /* ---------- Páginas ---------- */

    private function nuevaPagina(bool $sigue): void
    {
        $this->ops = '';
        $this->y = $this->h - 64;
        $this->texto($this->x0, $this->y, 'Firmas de responsabilidad' . ($sigue ? ' (continuación)' : ''), 17, true, self::MARCA);
        $this->y -= 22;
        if ($sigue) { $this->y -= 6; return; }
        $tit = PdfFirmas::winAnsi('Documento: ' . ($this->d['titulo'] ?? ''));
        foreach (array_slice(PdfFirmas::renglones($tit, 10.5, $this->ancho), 0, 2) as $r) {
            $this->textoW($this->x0, $this->y, $r, 10.5, false, self::TINTA);
            $this->y -= 14;
        }
        $meta = array_filter([$this->d['codigo'] ?? '', $this->d['estado'] ?? '', 'Hoja generada el ' . ($this->d['generado'] ?? '')]);
        $this->texto($this->x0, $this->y, implode('  ·  ', $meta), 8.5, false, self::GRIS);
        $this->y -= 22;
    }

    private function cerrarPagina(): void
    {
        $this->paginas[] = ['contenido' => $this->ops, 'w' => $this->w, 'h' => $this->h];
        $this->ops = '';
    }

    private function cabe(float $alto): void
    {
        if ($this->y - $alto >= $this->piso) return;
        $this->cerrarPagina();
        $this->nuevaPagina(true);
    }

    /* ---------- El cuadro de firmas ---------- */

    private function tabla(): void
    {
        $firmantes = $this->d['firmantes'] ?? [];
        $col1 = round($this->ancho * 0.45, 2);
        $col2 = $this->ancho - $col1;
        $n = count($firmantes);
        $filaH = $n > 6 ? 64 : ($n > 5 ? 82 : 94);
        $cabH = 22;

        $cabecera = function () use ($col1, $cabH): void {
            $this->rect($this->x0, $this->y - $cabH, $this->ancho, $cabH, self::FONDO, self::LINEA);
            $this->linea($this->x0 + $col1, $this->y, $this->x0 + $col1, $this->y - $cabH);
            $this->texto($this->x0 + 12, $this->y - 14.5, 'Rol / Cargo', 9, true, self::TINTA);
            $this->texto($this->x0 + $col1 + 12, $this->y - 14.5, 'Firma', 9, true, self::TINTA);
            $this->y -= $cabH;
        };
        $this->cabe($cabH + $filaH);
        $cabecera();

        foreach ($firmantes as $i => $f) {
            if ($this->y - $filaH < $this->piso) {
                $this->cerrarPagina();
                $this->nuevaPagina(true);
                $cabecera();
            }
            $top = $this->y;
            $bot = $top - $filaH;
            $this->rect($this->x0, $bot, $this->ancho, $filaH, null, self::LINEA);
            $this->linea($this->x0 + $col1, $top, $this->x0 + $col1, $bot);

            // Izquierda: rol, nombre y cargo
            $tx = $this->x0 + 12;
            $max = $col1 - 24;
            $yy = $top - 20;
            $rol = trim((string)($f['rol'] ?? ''));
            $this->textoW($tx, $yy, PdfFirmas::recortar(PdfFirmas::winAnsi($rol !== '' ? rtrim($rol, ':') . ':' : ''), 10, $max, true), 10, true, self::TINTA);
            $yy -= 15;
            $this->textoW($tx, $yy, PdfFirmas::recortar(PdfFirmas::winAnsi((string)($f['nombre'] ?? '')), 10, $max), 10, false, self::TINTA);
            $yy -= 13;
            foreach (array_slice(PdfFirmas::renglones(PdfFirmas::winAnsi((string)($f['cargo'] ?? '')), 9, $max), 0, 2) as $r) {
                if ($r === '') break;
                $this->textoW($tx, $yy, $r, 9, false, self::GRIS);
                $yy -= 11.5;
            }

            // Derecha: la firma sobre una línea y, debajo, cuándo
            $fx = $this->x0 + $col1 + 12;
            $fw = $col2 - 24;
            $baseLinea = $bot + 22;
            $estado = (string)($f['estado'] ?? 'pendiente');
            $this->rects[$i] = [count($this->paginas), $fx, $baseLinea + 1, $fx + $fw, $top - 4];
            if ($estado === 'firmado' && ($f['tipo'] ?? '') === 'electronica') {
                // Firma con certificado: la firma digital va dentro del PDF; aquí, quién y con qué certificado
                $ty = $top - 18;
                $this->texto($fx, $ty, 'Firmado electrónicamente', 9.5, true, self::VERDE);
                foreach (array_filter(['Certificado: ' . ($f['cert'] ?? ''), ($f['emisor'] ?? '') !== '' ? 'Emitido por: ' . $f['emisor'] : '']) as $l) {
                    if ($ty - 12 < $baseLinea + 4) break;
                    $ty -= 12;
                    $this->textoW($fx, $ty, PdfFirmas::recortar(PdfFirmas::winAnsi($l), 8, $fw), 8, false, self::GRIS);
                }
            } elseif ($estado === 'firmado' && !empty($f['png']) && is_file($f['png']) && ($img = PdfFirmas::imagenPng($f['png']))) {
                $nombre = 'Im' . ($i + 1);
                $this->imagenes[$nombre] = $img;
                $cajaH = $filaH - 34;
                $esc = min($fw / $img['w'], $cajaH / $img['h'], 1.0);
                $iw = $img['w'] * $esc;
                $ih = $img['h'] * $esc;
                $this->ops .= 'q ' . PdfFirmas::n($iw) . ' 0 0 ' . PdfFirmas::n($ih) . ' ' . PdfFirmas::n($fx) . ' '
                    . PdfFirmas::n($baseLinea + 2) . ' cm /' . $nombre . " Do Q\n";
            }
            $this->linea($fx, $baseLinea, $fx + $fw, $baseLinea, self::LINEA);
            [$leyenda, $color] = match ($estado) {
                'firmado'   => ['Firmado el ' . ($f['fecha'] ?? ''), self::VERDE],
                'rechazado' => ['Rechazado el ' . ($f['fecha'] ?? ''), self::ROJO],
                default     => ['Pendiente de firma', self::GRIS],
            };
            $this->textoW($fx, $bot + 9, PdfFirmas::recortar(PdfFirmas::winAnsi($leyenda), 8, $fw), 8, false, $color);
            $this->y = $bot;
        }
        $this->y -= 26;
    }

    /* ---------- Pie ---------- */

    private function pie(int $i, int $n): string
    {
        $guardado = $this->ops;
        $this->ops = '';
        $y = 74;
        $this->linea($this->x0, $y + 12, $this->x0 + $this->ancho, $y + 12, self::LINEA);
        $hoja = $n > 1 ? 'Hoja de firmas ' . $i . ' de ' . $n : 'Hoja de firmas';
        $wHoja = PdfFirmas::ancho(PdfFirmas::winAnsi($hoja), 7.5);
        $this->texto($this->x0 + $this->ancho - $wHoja, $y, $hoja, 7.5, false, self::GRIS);
        $this->texto($this->x0, $y, 'SHA-256 del documento original:', 7.5, true, self::GRIS);
        $y -= 10;
        $this->texto($this->x0, $y, (string)($this->d['hash'] ?? ''), 7.5, false, self::GRIS);
        $y -= 12;
        $nota = 'Las firmas gráficas quedan registradas en InnoTech Hub: quién firmó, cuándo y desde qué conexión. '
            . 'Las firmas electrónicas van dentro del PDF y se validan con FirmaEC o Adobe Acrobat.';
        foreach (PdfFirmas::renglones(PdfFirmas::winAnsi($nota), 7.5, $this->ancho) as $r) {
            $this->textoW($this->x0, $y, $r, 7.5, false, self::GRIS);
            $y -= 9.5;
        }
        $pie = $this->ops;
        $this->ops = $guardado;
        return $pie;
    }

    /* ---------- Dibujo ---------- */

    private function texto(float $x, float $y, string $utf8, float $tam, bool $negrita, string $color): void
    {
        $this->textoW($x, $y, PdfFirmas::winAnsi($utf8), $tam, $negrita, $color);
    }

    private function textoW(float $x, float $y, string $w, float $tam, bool $negrita, string $color): void
    {
        if ($w === '') return;
        $this->ops .= 'BT /' . ($negrita ? 'F2' : 'F1') . ' ' . PdfFirmas::n($tam) . ' Tf ' . $color . ' rg '
            . PdfFirmas::n($x) . ' ' . PdfFirmas::n($y) . ' Td ' . PdfFirmas::cadena($w) . " Tj ET\n";
    }

    private function rect(float $x, float $y, float $w, float $h, ?string $relleno, ?string $borde): void
    {
        $r = PdfFirmas::n($x) . ' ' . PdfFirmas::n($y) . ' ' . PdfFirmas::n($w) . ' ' . PdfFirmas::n($h) . ' re';
        if ($relleno !== null) $this->ops .= $relleno . ' rg ' . $r . " f\n";
        if ($borde !== null) $this->ops .= '0.6 w ' . $borde . ' RG ' . $r . " S\n";
    }

    private function linea(float $x1, float $y1, float $x2, float $y2, string $color = self::LINEA): void
    {
        $this->ops .= '0.6 w ' . $color . ' RG ' . PdfFirmas::n($x1) . ' ' . PdfFirmas::n($y1) . ' m '
            . PdfFirmas::n($x2) . ' ' . PdfFirmas::n($y2) . " l S\n";
    }
}
