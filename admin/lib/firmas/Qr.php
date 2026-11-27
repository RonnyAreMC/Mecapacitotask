<?php
/**
 * Código QR en PHP puro, sin librerías: modo byte (UTF-8) y corrección M.
 * Lo usa el sello de la firma electrónica en la hoja de firmas (como el de
 * FirmaEC). Sigue la norma ISO/IEC 18004 con el mismo algoritmo que la
 * biblioteca de Nayuki (MIT): datos → bloques con Reed-Solomon → patrones,
 * datos en zigzag, la mejor de las 8 máscaras y la información de formato.
 *
 *   Qr::matriz('texto') → [[bool, …], …]  (true = módulo oscuro; fila 0 arriba)
 */
final class Qr
{
    // Codewords de corrección por bloque y número de bloques, por nivel [L, M, Q, H] y versión (índice 0 sin uso)
    private const ECC = [
        [-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
        [-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
        [-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
    ];
    private const BLOQUES = [
        [-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
        [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
        [-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
        [-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81],
    ];
    private const NIVELES = ['L' => [0, 1], 'M' => [1, 0], 'Q' => [2, 3], 'H' => [3, 2]];   // [índice en las tablas, bits de formato]

    private int $ver;
    private int $n;
    private int $nivel;
    private int $formato;
    private array $m = [];      // [y][x] oscuro
    private array $f = [];      // [y][x] es patrón fijo

    public static function matriz(string $datos, string $nivel = 'M'): array
    {
        [$idx, $fmt] = self::NIVELES[$nivel] ?? self::NIVELES['M'];
        $len = strlen($datos);
        for ($v = 1; $v <= 40; $v++) {
            $cc = $v <= 9 ? 8 : 16;
            if (4 + $cc + 8 * $len <= self::datosCodewords($v, $idx) * 8) break;
        }
        if ($v > 40) throw new InvalidArgumentException('El texto es demasiado largo para un código QR.');
        $qr = new self($v, $idx, $fmt);
        $qr->dibujar($qr->codewords($datos));
        return $qr->m;
    }

    private function __construct(int $ver, int $nivel, int $formato)
    {
        $this->ver = $ver;
        $this->n = $ver * 4 + 17;
        $this->nivel = $nivel;
        $this->formato = $formato;
        $fila = array_fill(0, $this->n, false);
        $this->m = array_fill(0, $this->n, $fila);
        $this->f = array_fill(0, $this->n, $fila);
    }

    /* ---------- Datos ---------- */

    private static function modulosCrudos(int $v): int
    {
        $r = (16 * $v + 128) * $v + 64;
        if ($v >= 2) {
            $na = intdiv($v, 7) + 2;
            $r -= (25 * $na - 10) * $na - 55;
            if ($v >= 7) $r -= 36;
        }
        return $r;
    }

    private static function datosCodewords(int $v, int $idx): int
    {
        return intdiv(self::modulosCrudos($v), 8) - self::ECC[$idx][$v] * self::BLOQUES[$idx][$v];
    }

    /** Los codewords finales: datos con relleno, en bloques con su corrección e intercalados. */
    private function codewords(string $datos): array
    {
        $bits = [];
        $poner = function (int $val, int $n) use (&$bits): void { for ($i = $n - 1; $i >= 0; $i--) $bits[] = ($val >> $i) & 1; };
        $poner(4, 4);                                            // modo byte
        $poner(strlen($datos), $this->ver <= 9 ? 8 : 16);
        foreach (str_split($datos) as $ch) $poner(ord($ch), 8);
        $cap = self::datosCodewords($this->ver, $this->nivel) * 8;
        $poner(0, min(4, $cap - count($bits)));                  // terminador
        $poner(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $cap; $pad ^= 0xEC ^ 0x11) $poner($pad, 8);
        $dat = [];
        foreach (array_chunk($bits, 8) as $b) $dat[] = bindec(implode('', $b));

        // Bloques con Reed-Solomon
        $nb = self::BLOQUES[$this->nivel][$this->ver];
        $eccLen = self::ECC[$this->nivel][$this->ver];
        $crudos = intdiv(self::modulosCrudos($this->ver), 8);
        $cortos = $nb - $crudos % $nb;
        $largoCorto = intdiv($crudos, $nb);
        $div = self::divisor($eccLen);
        $bloques = [];
        for ($i = 0, $k = 0; $i < $nb; $i++) {
            $tam = $largoCorto - $eccLen + ($i < $cortos ? 0 : 1);
            $b = array_slice($dat, $k, $tam);
            $k += $tam;
            $ecc = self::resto($b, $div);
            if ($i < $cortos) $b[] = 0;                          // hueco para intercalar parejo
            $bloques[] = array_merge($b, $ecc);
        }
        $out = [];
        $largo = count($bloques[0]);
        for ($i = 0; $i < $largo; $i++) {
            foreach ($bloques as $j => $b) {
                if ($i !== $largoCorto - $eccLen || $j >= $cortos) $out[] = $b[$i];
            }
        }
        return $out;
    }

    private static function mul(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }
        return $z;
    }

    private static function divisor(int $grado): array
    {
        $r = array_fill(0, $grado, 0);
        $r[$grado - 1] = 1;
        $raiz = 1;
        for ($i = 0; $i < $grado; $i++) {
            for ($j = 0; $j < $grado; $j++) {
                $r[$j] = self::mul($r[$j], $raiz);
                if ($j + 1 < $grado) $r[$j] ^= $r[$j + 1];
            }
            $raiz = self::mul($raiz, 0x02);
        }
        return $r;
    }

    private static function resto(array $datos, array $div): array
    {
        $r = array_fill(0, count($div), 0);
        foreach ($datos as $b) {
            $factor = $b ^ array_shift($r);
            $r[] = 0;
            foreach ($div as $i => $d) $r[$i] ^= self::mul($d, $factor);
        }
        return $r;
    }

    /* ---------- Dibujo ---------- */

    private function fijo(int $x, int $y, bool $oscuro): void
    {
        $this->m[$y][$x] = $oscuro;
        $this->f[$y][$x] = true;
    }

    private function dibujar(array $cw): void
    {
        $n = $this->n;
        for ($i = 0; $i < $n; $i++) {                            // patrones de sincronía
            $this->fijo(6, $i, $i % 2 === 0);
            $this->fijo($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {   // los tres cuadros de las esquinas
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx; $y = $cy + $dy;
                    if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) {
                        $d = max(abs($dx), abs($dy));
                        $this->fijo($x, $y, $d !== 2 && $d !== 4);
                    }
                }
            }
        }
        $pos = $this->alineacion();                              // patrones de alineación
        $np = count($pos);
        foreach ($pos as $i => $px) {
            foreach ($pos as $j => $py) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $np - 1) || ($i === $np - 1 && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) for ($dx = -2; $dx <= 2; $dx++) $this->fijo($px + $dx, $py + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
        $this->formato(0);                                       // reserva su lugar
        $this->version();

        // Los datos en zigzag, de abajo a la derecha
        $total = count($cw) * 8;
        $i = 0;
        for ($der = $n - 1; $der >= 1; $der -= 2) {
            if ($der === 6) $der = 5;
            for ($v = 0; $v < $n; $v++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $der - $j;
                    $sube = (($der + 1) & 2) === 0;
                    $y = $sube ? $n - 1 - $v : $v;
                    if (!$this->f[$y][$x] && $i < $total) {
                        $this->m[$y][$x] = (($cw[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }

        // La máscara con menos penalidad
        $mejor = 0;
        $menor = PHP_INT_MAX;
        for ($k = 0; $k < 8; $k++) {
            $this->mascara($k);
            $this->formato($k);
            $p = $this->penalidad();
            if ($p < $menor) { $menor = $p; $mejor = $k; }
            $this->mascara($k);                                  // la deshace (XOR)
        }
        $this->mascara($mejor);
        $this->formato($mejor);
    }

    private function alineacion(): array
    {
        if ($this->ver === 1) return [];
        $na = intdiv($this->ver, 7) + 2;
        $paso = intdiv($this->ver * 8 + $na * 3 + 5, $na * 4 - 4) * 2;
        $r = [6];
        for ($p = $this->n - 7; count($r) < $na; $p -= $paso) array_splice($r, 1, 0, [$p]);
        return $r;
    }

    private function formato(int $mascara): void
    {
        $dat = ($this->formato << 3) | $mascara;
        $r = $dat;
        for ($i = 0; $i < 10; $i++) $r = ($r << 1) ^ (($r >> 9) * 0x537);
        $bits = (($dat << 10) | ($r & 0x3FF)) ^ 0x5412;
        $b = fn(int $i) => (($bits >> $i) & 1) === 1;
        $n = $this->n;
        for ($i = 0; $i <= 5; $i++) $this->fijo(8, $i, $b($i));
        $this->fijo(8, 7, $b(6));
        $this->fijo(8, 8, $b(7));
        $this->fijo(7, 8, $b(8));
        for ($i = 9; $i < 15; $i++) $this->fijo(14 - $i, 8, $b($i));
        for ($i = 0; $i < 8; $i++) $this->fijo($n - 1 - $i, 8, $b($i));
        for ($i = 8; $i < 15; $i++) $this->fijo(8, $n - 15 + $i, $b($i));
        $this->fijo(8, $n - 8, true);                            // el módulo oscuro fijo
    }

    private function version(): void
    {
        if ($this->ver < 7) return;
        $r = $this->ver;
        for ($i = 0; $i < 12; $i++) $r = ($r << 1) ^ (($r >> 11) * 0x1F25);
        $bits = ($this->ver << 12) | ($r & 0xFFF);
        for ($i = 0; $i < 18; $i++) {
            $o = (($bits >> $i) & 1) === 1;
            $a = $this->n - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->fijo($a, $b, $o);
            $this->fijo($b, $a, $o);
        }
    }

    private function mascara(int $k): void
    {
        for ($y = 0; $y < $this->n; $y++) {
            for ($x = 0; $x < $this->n; $x++) {
                if ($this->f[$y][$x]) continue;
                $inv = match ($k) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    default => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($inv) $this->m[$y][$x] = !$this->m[$y][$x];
            }
        }
    }

    /** Las cuatro reglas de la norma: rachas, bloques 2×2, patrones parecidos a los cuadros y proporción de oscuros. */
    private function penalidad(): int
    {
        $n = $this->n;
        $p = 0;
        $oscuros = 0;
        $lineas = [];
        for ($y = 0; $y < $n; $y++) {
            $fila = '';
            $col = '';
            for ($x = 0; $x < $n; $x++) {
                $fila .= $this->m[$y][$x] ? '1' : '0';
                $col .= $this->m[$x][$y] ? '1' : '0';
                if ($this->m[$y][$x]) $oscuros++;
                if ($x < $n - 1 && $y < $n - 1) {
                    $c = $this->m[$y][$x];
                    if ($c === $this->m[$y][$x + 1] && $c === $this->m[$y + 1][$x] && $c === $this->m[$y + 1][$x + 1]) $p += 3;
                }
            }
            $lineas[] = $fila;
            $lineas[] = $col;
        }
        foreach ($lineas as $l) {
            preg_match_all('/0{5,}|1{5,}/', $l, $rachas);
            foreach ($rachas[0] as $r) $p += 3 + strlen($r) - 5;
            $p += 40 * (substr_count($l, '10111010000') + substr_count($l, '00001011101'));
        }
        $total = $n * $n;
        $p += 10 * intdiv(abs($oscuros * 20 - $total * 10), $total);
        return $p;
    }
}
