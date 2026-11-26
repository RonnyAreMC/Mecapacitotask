<?php
/**
 * El PDF del informe semanal de actividades (lib/firmas/InformeSemanal.php).
 * Lo arma de cero con PdfFirmas::nuevo(); la hoja «Firmas de responsabilidad»
 * se le agrega después, al firmarlo, como a cualquier documento.
 *
 * $c = [
 *   'org', 'persona', 'cargo', 'equipo', 'semana' (texto), 'elaborado' (dd/mm/aaaa),
 *   'resumen', 'actividades' => [['actividad', 'origen', 'fecha'], …],
 *   'extra' => [texto, …], 'pendientes' => [texto, …], 'observaciones',
 * ]
 */
final class InformePdf
{
    private const TINTA = '0.11 0.13 0.18';
    private const GRIS  = '0.42 0.45 0.5';
    private const LINEA = '0.82 0.84 0.87';
    private const FONDO = '0.955 0.96 0.97';
    private const MARCA = '0.063 0.157 0.353';

    private float $w = 595.28;
    private float $h = 841.89;
    private float $x0 = 56;
    private float $ancho;
    private float $y = 0;
    private float $piso = 70;
    private string $ops = '';
    private array $paginas = [];
    private array $c;

    public static function crear(array $c): string
    {
        $p = new self($c);
        $p->dibujar();
        return PdfFirmas::nuevo($p->paginas, [], 'Informe semanal de actividades - ' . ($c['persona'] ?? ''));
    }

    private function __construct(array $c)
    {
        $this->c = $c;
        $this->ancho = $this->w - 2 * $this->x0;
    }

    private function dibujar(): void
    {
        $c = $this->c;
        $this->pagina();
        // Cabecera
        $this->texto($this->x0, $this->y, mb_strtoupper((string)($c['org'] ?? 'InnoTech')), 8.5, true, self::GRIS);
        $this->y -= 24;
        $this->texto($this->x0, $this->y, 'Informe semanal de actividades', 19, true, self::MARCA);
        $this->y -= 26;
        // Ficha
        $filas = array_filter([
            'Colaborador' => (string)($c['persona'] ?? ''), 'Cargo' => (string)($c['cargo'] ?? ''), 'Área' => (string)($c['equipo'] ?? ''),
            'Semana' => (string)($c['semana'] ?? ''), 'Fecha de elaboración' => (string)($c['elaborado'] ?? ''),
        ], fn($v) => $v !== '');
        $col = 128;
        $alto = 20;
        $this->rect($this->x0, $this->y - $alto * count($filas), $this->ancho, $alto * count($filas), null, self::LINEA);
        $this->rect($this->x0, $this->y - $alto * count($filas), $col, $alto * count($filas), self::FONDO, self::LINEA);
        $k = 0;
        foreach ($filas as $et => $v) {
            $yy = $this->y - $alto * $k;
            if ($k) $this->linea($this->x0, $yy, $this->x0 + $this->ancho, $yy);
            $this->texto($this->x0 + 10, $yy - 13.5, $et, 9, true, self::TINTA);
            $this->textoW($this->x0 + $col + 10, $yy - 13.5, PdfFirmas::recortar(PdfFirmas::winAnsi($v), 9.5, $this->ancho - $col - 20), 9.5, false, self::TINTA);
            $k++;
        }
        $this->y -= $alto * count($filas) + 24;

        // 1. Resumen
        $this->titulo('1. Resumen de la semana');
        $this->parrafo((string)($c['resumen'] ?? ''));

        // 2. Actividades realizadas
        $this->titulo('2. Actividades realizadas');
        $acts = (array)($c['actividades'] ?? []);
        foreach ((array)($c['extra'] ?? []) as $e) if (trim((string)$e) !== '') $acts[] = ['actividad' => trim((string)$e), 'origen' => 'Otra actividad', 'fecha' => ''];
        if (!$acts) {
            $this->parrafo('Sin actividades cerradas esta semana.', self::GRIS);
        } else {
            $this->tabla($acts);
        }

        // 3. En curso y próximos pasos
        $this->titulo('3. En curso y próximos pasos');
        $pend = array_values(array_filter(array_map('trim', (array)($c['pendientes'] ?? [])), fn($x) => $x !== ''));
        if (!$pend) $this->parrafo('Sin pendientes registrados.', self::GRIS);
        foreach ($pend as $p) $this->vineta($p);
        $this->y -= 12;

        // 4. Observaciones
        if (trim((string)($c['observaciones'] ?? '')) !== '') {
            $this->titulo('4. Observaciones');
            $this->parrafo((string)$c['observaciones']);
        }
        $this->cerrar();
        $n = count($this->paginas);
        foreach ($this->paginas as $i => &$pg) {
            $this->ops = '';
            $t = PdfFirmas::winAnsi('Página ' . ($i + 1) . ' de ' . $n);
            $this->linea($this->x0, 50, $this->x0 + $this->ancho, 50);
            $this->texto($this->x0, 38, 'Informe semanal de actividades · ' . ($c['persona'] ?? ''), 7.5, false, self::GRIS);
            $this->textoW($this->x0 + $this->ancho - PdfFirmas::ancho($t, 7.5), 38, $t, 7.5, false, self::GRIS);
            $pg['contenido'] .= $this->ops;
        }
        unset($pg);
    }

    /* ---------- Bloques ---------- */

    private function titulo(string $t): void
    {
        $this->cabe(46);
        $this->texto($this->x0, $this->y, $t, 12, true, self::MARCA);
        $this->y -= 18;
    }

    private function parrafo(string $t, string $color = self::TINTA): void
    {
        foreach (preg_split('/\R{2,}/u', trim($t)) ?: [] as $bloque) {
            foreach (PdfFirmas::renglones(PdfFirmas::winAnsi(preg_replace('/\s+/u', ' ', $bloque)), 10, $this->ancho) as $r) {
                $this->cabe(14);
                $this->textoW($this->x0, $this->y, $r, 10, false, $color);
                $this->y -= 14;
            }
            $this->y -= 5;
        }
        $this->y -= 10;
    }

    private function vineta(string $t): void
    {
        $rs = PdfFirmas::renglones(PdfFirmas::winAnsi($t), 10, $this->ancho - 16);
        $this->cabe(14 * count($rs));
        $this->ops .= self::MARCA . ' rg ' . PdfFirmas::n($this->x0 + 3) . ' ' . PdfFirmas::n($this->y + 2.5) . " 3 3 re f\n";
        foreach ($rs as $r) {
            $this->textoW($this->x0 + 16, $this->y, $r, 10, false, self::TINTA);
            $this->y -= 14;
        }
        $this->y -= 3;
    }

    private function tabla(array $filas): void
    {
        $cN = 26; $cF = 64;
        $cO = round(($this->ancho - $cN - $cF) * 0.34, 2);
        $cA = $this->ancho - $cN - $cF - $cO;
        $xs = [$this->x0, $this->x0 + $cN, $this->x0 + $cN + $cA, $this->x0 + $cN + $cA + $cO, $this->x0 + $this->ancho];
        $cab = function () use ($xs): void {
            $this->rect($this->x0, $this->y - 20, $this->ancho, 20, self::FONDO, self::LINEA);
            foreach (['N.º', 'Actividad', 'Proyecto / origen', 'Terminada'] as $i => $t) $this->texto($xs[$i] + 6, $this->y - 13.5, $t, 8.5, true, self::TINTA);
            $this->y -= 20;
        };
        $this->cabe(20 + 24);
        $cab();
        foreach (array_values($filas) as $i => $f) {
            $ra = array_slice(PdfFirmas::renglones(PdfFirmas::winAnsi((string)($f['actividad'] ?? '')), 9, $xs[2] - $xs[1] - 12), 0, 4);
            $ro = array_slice(PdfFirmas::renglones(PdfFirmas::winAnsi((string)($f['origen'] ?? '')), 8.5, $xs[3] - $xs[2] - 12), 0, 3);
            $alto = max(count($ra), count($ro), 1) * 11.5 + 10;
            if ($this->y - $alto < $this->piso) { $this->cerrar(); $this->pagina(); $cab(); }
            $top = $this->y;
            $this->rect($this->x0, $top - $alto, $this->ancho, $alto, null, self::LINEA);
            $this->texto($xs[0] + 6, $top - 14, (string)($i + 1), 9, false, self::GRIS);
            $yy = $top - 14;
            foreach ($ra as $r) { $this->textoW($xs[1] + 6, $yy, $r, 9, false, self::TINTA); $yy -= 11.5; }
            $yy = $top - 14;
            foreach ($ro as $r) { $this->textoW($xs[2] + 6, $yy, $r, 8.5, false, self::GRIS); $yy -= 11.5; }
            $this->texto($xs[3] + 6, $top - 14, (string)($f['fecha'] ?? ''), 8.5, false, self::GRIS);
            $this->y -= $alto;
        }
        $this->y -= 20;
    }

    /* ---------- Páginas y dibujo ---------- */

    private function pagina(): void
    {
        $this->ops = '';
        $this->y = $this->h - 60;
    }

    private function cerrar(): void
    {
        $this->paginas[] = ['contenido' => $this->ops, 'w' => $this->w, 'h' => $this->h];
        $this->ops = '';
    }

    private function cabe(float $alto): void
    {
        if ($this->y - $alto >= $this->piso) return;
        $this->cerrar();
        $this->pagina();
    }

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
        $this->ops .= '0.6 w ' . $color . ' RG ' . PdfFirmas::n($x1) . ' ' . PdfFirmas::n($y1) . ' m ' . PdfFirmas::n($x2) . ' ' . PdfFirmas::n($y2) . " l S\n";
    }
}
