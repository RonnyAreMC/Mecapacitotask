<?php
/**
 * El PDF del informe semanal de actividades (lib/firmas/InformeSemanal.php),
 * con el formato de los informes de InnoTech: logo arriba, la ficha de
 * memorando (Para, De, Informe, Emisión, Asunto), los números de la semana en
 * recuadros, tablas con encabezado azul InnoTech y la franja con la dirección
 * al pie. Todo sale de los datos del Hub (commits, tareas, requerimientos,
 * reuniones); la IA, si se usa, solo escribe el resumen corto.
 *
 * Lo arma de cero con PdfFirmas::nuevo(); la hoja «Firmas de responsabilidad»
 * se le agrega después, al firmarlo, como a cualquier documento.
 */
final class InformePdf
{
    private const AZUL  = '0.122 0.22 0.392';     // 1F3864
    private const AZUL2 = '0.18 0.361 0.541';     // 2E5C8A
    private const VERDE = '0.18 0.49 0.196';      // 2E7D32
    private const ROJO  = '0.753 0 0';            // C00000
    private const TINTA = '0.15 0.16 0.2';
    private const GRIS  = '0.42 0.44 0.48';
    private const LINEA = '0.82 0.84 0.87';
    private const FONDO = '0.949 0.961 0.976';    // F2F5F9
    private const BLANCO = '1 1 1';

    private float $w = 595.28;
    private float $h = 841.89;
    private float $x0 = 50;
    private float $ancho;
    private float $y = 0;
    private float $piso = 92;
    private string $ops = '';
    private array $paginas = [];
    private array $imagenes = [];
    private array $c;
    private int $seccion = 0;

    public static function crear(array $c): string
    {
        $p = new self($c);
        $p->dibujar();
        return PdfFirmas::nuevo($p->paginas, $p->imagenes, 'Informe semanal de actividades - ' . ($c['persona'] ?? ''));
    }

    private function __construct(array $c)
    {
        $this->c = $c;
        $this->ancho = $this->w - 2 * $this->x0;
        foreach (['Logo' => 'innotech-logo.png', 'Pie' => 'innotech-pie.png'] as $n => $f) {
            $img = PdfFirmas::imagenPng(__DIR__ . '/plantilla/' . $f);
            if ($img) $this->imagenes[$n] = $img;
        }
    }

    private function dibujar(): void
    {
        $c = $this->c;
        $k = (array)($c['kpi'] ?? []);
        $this->pagina();

        // Ficha de memorando
        $para = (array)($c['para'] ?? []);
        $ficha = array_filter([
            'Para:'    => trim(($para['nombre'] ?? '') . (($para['cargo'] ?? '') !== '' ? "\n" . $para['cargo'] : '')),
            'De:'      => trim(($c['persona'] ?? '') . (($c['cargo'] ?? '') !== '' ? "\n" . $c['cargo'] : '')),
            'Informe:' => (string)($c['codigo'] ?? ''),
            'Emisión:' => (string)($c['elaborado'] ?? ''),
            'Asunto:'  => 'Informe semanal de actividades · semana ' . ($c['semana'] ?? '') . (($c['equipo'] ?? '') !== '' ? ' · ' . $c['equipo'] : ''),
        ], fn($v) => trim((string)$v) !== '');
        $col = 82;
        foreach ($ficha as $et => $v) {
            $lineas = [];
            foreach (explode("\n", (string)$v) as $i => $parte) {
                foreach (PdfFirmas::renglones(PdfFirmas::winAnsi($parte), $i ? 9 : 10, $this->ancho - $col - 16, $i === 0 && $et !== 'Asunto:') as $r) $lineas[] = [$r, $i];
            }
            $alto = 8 + count($lineas) * 13;
            $this->rect($this->x0, $this->y - $alto, $col, $alto, self::FONDO, null);
            $this->linea($this->x0, $this->y - $alto, $this->x0 + $this->ancho, $this->y - $alto);
            $this->texto($this->x0 + 8, $this->y - 15, $et, 9.5, true, self::AZUL);
            $yy = $this->y - 15;
            foreach ($lineas as [$r, $i]) {
                $this->textoW($this->x0 + $col + 10, $yy, $r, $i ? 9 : 10, $i === 0 && $et !== 'Asunto:', $i ? self::GRIS : self::TINTA);
                $yy -= 13;
            }
            $this->y -= $alto;
        }
        $this->y -= 26;
        $this->texto($this->x0, $this->y, 'Informe semanal de actividades', 18, true, self::AZUL);
        $this->y -= 16;
        $this->texto($this->x0, $this->y, 'Semana ' . ($c['semana'] ?? '') . ' · datos registrados en InnoTech Hub', 9.5, false, self::GRIS);
        $this->y -= 24;

        // 1. Resumen: los números y el resumen corto
        $this->titulo('Resumen de la semana');
        $cajas = [
            [(string)(int)($k['commits'] ?? 0), 'Commits', (int)($k['proyectos'] ?? 0) . ' proyecto' . ((int)($k['proyectos'] ?? 0) === 1 ? '' : 's')],
            [(string)(int)($k['tareas'] ?? 0), 'Tareas completadas', (int)($k['curso'] ?? 0) . ' en curso'],
            [(string)(int)($k['reqs'] ?? 0), 'Requerimientos', 'atendidos'],
            [(int)($k['reu_conv'] ?? 0) ? (int)($k['reu_asis'] ?? 0) . '/' . (int)$k['reu_conv'] : '—', 'Reuniones', (int)($k['reu_conv'] ?? 0) ? round(100 * (int)$k['reu_asis'] / max(1, (int)$k['reu_conv'])) . ' % de ingreso' : 'sin reuniones'],
        ];
        $this->cabe(64);
        $gap = 10;
        $cw = ($this->ancho - 3 * $gap) / 4;
        foreach ($cajas as $i => [$num, $et, $sub]) {
            $x = $this->x0 + $i * ($cw + $gap);
            $this->rect($x, $this->y - 58, $cw, 58, self::FONDO, null);
            $this->rect($x, $this->y - 58, 3, 58, self::AZUL, null);
            $this->texto($x + 12, $this->y - 25, $num, 19, true, self::AZUL);
            $this->texto($x + 12, $this->y - 39, $et, 8.5, true, self::TINTA);
            $this->texto($x + 12, $this->y - 50, $sub, 7.5, false, self::GRIS);
        }
        $this->y -= 74;
        $this->parrafo((string)($c['resumen'] ?? ''));

        // 2. Commits por proyecto
        $this->titulo('Commits por proyecto');
        $porProy = (array)($c['commits_proyecto'] ?? []);
        if (!$porProy) {
            $this->parrafo((string)($c['commits_nota'] ?? 'No se registraron commits esta semana en los repositorios de sus proyectos.'), self::GRIS);
        } else {
            $this->tabla([['Proyecto', 0.34], ['Repositorio', 0.36], ['Commits', 0.12, 'der'], ['Último', 0.18, 'der']],
                array_map(fn($p) => [$p['proyecto'], $p['repos'], (string)$p['commits'], $p['ultimo']], $porProy));
            $det = (array)($c['commits'] ?? []);
            if ($det) {
                $this->subtitulo('Detalle de commits' . ((int)($k['commits'] ?? 0) > count($det) ? ' (los ' . count($det) . ' más recientes de ' . (int)$k['commits'] . ')' : ''));
                $this->tabla([['Fecha', 0.15], ['Proyecto', 0.22], ['Mensaje', 0.63]],
                    array_map(fn($x) => [$x['fecha'], $x['proyecto'], $x['mensaje']], $det), 8.5);
            }
            if (($c['commits_nota'] ?? '') !== '') $this->parrafo((string)$c['commits_nota'], self::GRIS, 8.5);
        }

        // 3. Tareas realizadas (las del Hub y las que la persona agregó)
        $this->titulo('Tareas realizadas');
        $acts = array_values(array_filter((array)($c['actividades'] ?? []), fn($a) => !str_starts_with((string)($a['origen'] ?? ''), 'Requerimiento')));
        foreach ((array)($c['extra'] ?? []) as $e) if (trim((string)$e) !== '') $acts[] = ['actividad' => trim((string)$e), 'origen' => 'Otra actividad', 'fecha' => ''];
        if (!$acts) {
            $this->parrafo('Sin tareas cerradas en el Hub esta semana.', self::GRIS);
        } else {
            $this->tabla([['N.º', 0.07], ['Tarea', 0.53], ['Proyecto', 0.25], ['Terminada', 0.15, 'der']],
                array_map(fn($i, $a) => [(string)($i + 1), $a['actividad'], $a['origen'], $a['fecha']], array_keys($acts), $acts));
        }

        // 4. Requerimientos sueltos (si aplica)
        $reqs = (array)($c['reqs'] ?? []);
        if ($reqs) {
            $this->titulo('Requerimientos sueltos');
            $this->tabla([['Requerimiento', 0.55], ['Solicitante', 0.2], ['Estado', 0.25, 'der']],
                array_map(fn($r) => [$r['titulo'], $r['solicitante'], $r['estado']], $reqs));
        }

        // 5. Cumplimiento de reuniones
        $reu = (array)($c['reuniones'] ?? []);
        $this->titulo('Cumplimiento de reuniones');
        if (!$reu) {
            $this->parrafo('No tuvo reuniones agendadas en el Hub esta semana.', self::GRIS);
        } else {
            $asis = count(array_filter($reu, fn($r) => !empty($r['asistio'])));
            $this->parrafo('Ingresó a ' . $asis . ' de ' . count($reu) . ' reuniones agendadas (' . round(100 * $asis / count($reu)) . ' %). '
                . 'El ingreso se registra al pulsar «Entrar» en el Hub; si alguien entró directo al enlace de Zoom o Meet, aparece como «Sin registro».', self::TINTA, 9);
            $this->tabla([['Reunión', 0.56], ['Fecha y hora', 0.22], ['Ingreso', 0.22, 'der']],
                array_map(fn($r) => [$r['tema'], $r['fecha'], !empty($r['asistio']) ? 'Sí · ' . $r['entro'] : 'Sin registro'], $reu), 9,
                fn($fila) => str_starts_with($fila[2], 'Sí') ? self::VERDE : self::ROJO);
        }

        // 6. En curso y próximos pasos
        $this->titulo('En curso y próximos pasos');
        $pend = array_values(array_filter(array_map('trim', (array)($c['pendientes'] ?? [])), fn($x) => $x !== ''));
        if (!$pend) $this->parrafo('Sin pendientes registrados.', self::GRIS);
        foreach ($pend as $p) $this->vineta($p);
        $this->y -= 10;

        // 7. Observaciones
        if (trim((string)($c['observaciones'] ?? '')) !== '') {
            $this->titulo('Observaciones');
            $this->parrafo((string)$c['observaciones']);
        }
        $this->cerrar();

        // Pie de cada página: la franja de InnoTech y «Página x de n»
        $n = count($this->paginas);
        foreach ($this->paginas as $i => &$pg) {
            $this->ops = '';
            if (isset($this->imagenes['Pie'])) {
                $im = $this->imagenes['Pie'];
                $pw = $this->ancho;
                $ph = $pw * $im['h'] / $im['w'];
                $this->ops .= 'q ' . PdfFirmas::n($pw) . ' 0 0 ' . PdfFirmas::n($ph) . ' ' . PdfFirmas::n($this->x0) . ' 22 cm /Pie Do Q' . "\n";
            }
            $t = PdfFirmas::winAnsi('Página ' . ($i + 1) . ' de ' . $n);
            $this->textoW($this->x0 + $this->ancho - PdfFirmas::ancho($t, 7.5), 74, $t, 7.5, false, self::GRIS);
            $this->texto($this->x0, 74, 'Informe semanal de actividades · ' . ($c['persona'] ?? '') . (($c['codigo'] ?? '') !== '' ? ' · ' . $c['codigo'] : ''), 7.5, false, self::GRIS);
            $pg['contenido'] .= $this->ops;
        }
        unset($pg);
    }

    /* ---------- Bloques ---------- */

    private function titulo(string $t): void
    {
        $this->cabe(52);
        $this->seccion++;
        $this->texto($this->x0, $this->y, $this->seccion . '. ' . $t, 12.5, true, self::AZUL);
        $this->y -= 6;
        $this->linea($this->x0, $this->y, $this->x0 + $this->ancho, $this->y, self::AZUL2);
        $this->y -= 16;
    }

    private function subtitulo(string $t): void
    {
        $this->cabe(40);
        $this->texto($this->x0, $this->y, $t, 9.5, true, self::AZUL2);
        $this->y -= 14;
    }

    private function parrafo(string $t, string $color = self::TINTA, float $tam = 10): void
    {
        if (trim($t) === '') return;
        foreach (preg_split('/\R{2,}/u', trim($t)) ?: [] as $bloque) {
            foreach (PdfFirmas::renglones(PdfFirmas::winAnsi(preg_replace('/\s+/u', ' ', $bloque)), $tam, $this->ancho) as $r) {
                $this->cabe($tam + 4);
                $this->textoW($this->x0, $this->y, $r, $tam, false, $color);
                $this->y -= $tam + 4;
            }
            $this->y -= 4;
        }
        $this->y -= 8;
    }

    private function vineta(string $t): void
    {
        $rs = PdfFirmas::renglones(PdfFirmas::winAnsi($t), 10, $this->ancho - 16);
        $this->cabe(14 * count($rs));
        $this->ops .= self::AZUL . ' rg ' . PdfFirmas::n($this->x0 + 3) . ' ' . PdfFirmas::n($this->y + 2.5) . " 3.5 3.5 re f\n";
        foreach ($rs as $r) {
            $this->textoW($this->x0 + 16, $this->y, $r, 10, false, self::TINTA);
            $this->y -= 14;
        }
        $this->y -= 3;
    }

    /**
     * Tabla con encabezado azul InnoTech y filas alternadas. $cols: [[título, fracción del ancho, 'der'?], …].
     * $colorUltima: fn(fila) => color de la última columna (el «Sí»/«Sin registro» de las reuniones).
     */
    private function tabla(array $cols, array $filas, float $tam = 9, ?callable $colorUltima = null): void
    {
        $xs = [$this->x0];
        foreach ($cols as $c) $xs[] = end($xs) + $this->ancho * $c[1];
        $cab = function () use ($cols, $xs): void {
            $this->rect($this->x0, $this->y - 20, $this->ancho, 20, self::AZUL, null);
            foreach ($cols as $i => $c) {
                $t = PdfFirmas::winAnsi($c[0]);
                $x = ($c[2] ?? '') === 'der' ? $xs[$i + 1] - 7 - PdfFirmas::ancho($t, 8.5, true) : $xs[$i] + 7;
                $this->textoW($x, $this->y - 13.5, $t, 8.5, true, self::BLANCO);
            }
            $this->y -= 20;
        };
        $this->cabe(20 + 22);
        $cab();
        foreach (array_values($filas) as $k => $f) {
            $celdas = [];
            $max = 1;
            foreach ($cols as $i => $c) {
                $rs = array_slice(PdfFirmas::renglones(PdfFirmas::winAnsi((string)($f[$i] ?? '')), $tam, $xs[$i + 1] - $xs[$i] - 14), 0, 3);
                $celdas[] = $rs;
                $max = max($max, count($rs));
            }
            $alto = $max * ($tam + 3) + 9;
            if ($this->y - $alto < $this->piso) { $this->cerrar(); $this->pagina(); $cab(); }
            if ($k % 2) $this->rect($this->x0, $this->y - $alto, $this->ancho, $alto, self::FONDO, null);
            $this->linea($this->x0, $this->y - $alto, $this->x0 + $this->ancho, $this->y - $alto);
            foreach ($cols as $i => $c) {
                $yy = $this->y - $tam - 4;
                $color = $i === count($cols) - 1 && $colorUltima ? $colorUltima($f) : ($i === 0 ? self::TINTA : self::GRIS);
                foreach ($celdas[$i] as $r) {
                    $x = ($c[2] ?? '') === 'der' ? $xs[$i + 1] - 7 - PdfFirmas::ancho($r, $tam) : $xs[$i] + 7;
                    $this->textoW($x, $yy, $r, $tam, false, $color);
                    $yy -= $tam + 3;
                }
            }
            $this->y -= $alto;
        }
        $this->y -= 16;
    }

    /* ---------- Páginas y dibujo ---------- */

    private function pagina(): void
    {
        $this->ops = '';
        $top = $this->h - 40;
        if (isset($this->imagenes['Logo'])) {
            $im = $this->imagenes['Logo'];
            $lw = 150;
            $lh = $lw * $im['h'] / $im['w'];
            $this->ops .= 'q ' . PdfFirmas::n($lw) . ' 0 0 ' . PdfFirmas::n($lh) . ' ' . PdfFirmas::n($this->x0) . ' ' . PdfFirmas::n($top - $lh) . ' cm /Logo Do Q' . "\n";
        }
        $t = PdfFirmas::winAnsi('Informe semanal de actividades');
        $this->textoW($this->x0 + $this->ancho - PdfFirmas::ancho($t, 8.5, true), $top - 14, $t, 8.5, true, self::AZUL);
        if (($this->c['codigo'] ?? '') !== '') {
            $cod = PdfFirmas::winAnsi((string)$this->c['codigo']);
            $this->textoW($this->x0 + $this->ancho - PdfFirmas::ancho($cod, 8), $top - 26, $cod, 8, false, self::GRIS);
        }
        $this->y = $top - 58;
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
