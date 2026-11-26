<?php
/**
 * La hoja «Firmas de responsabilidad» al final de un PDF, sin librerías.
 *
 * No se reescribe el documento: se le agrega una «actualización incremental»
 * (lo mismo que hace Acrobat al firmar o anotar). Los bytes del original quedan
 * intactos al principio del archivo y detrás van solo los objetos nuevos: la
 * hoja, sus letras, las imágenes de las firmas y el árbol de páginas con una
 * hoja más. Por eso el hash del original se puede comprobar siempre.
 *
 * Se lee lo justo para encontrar el árbol de páginas, tanto con la tabla xref
 * clásica como con la comprimida de PDF 1.5+ (xref y object streams), que es
 * la de los PDF que exporta Word y la que el FPDI gratuito no sabe abrir.
 * No se aceptan PDF cifrados (con contraseña o restricciones).
 *
 * Cada vez que alguien firma, la hoja se vuelve a armar desde el ORIGINAL
 * (no se apilan actualizaciones): el firmado siempre es original + una hoja.
 */
final class PdfFirmas
{
    private string $pdf;
    /** num => ['o' => offset, 'g' => gen] | ['s' => object stream, 'i' => índice] */
    private array $xref = [];
    private array $trailer = [];
    private bool $conXrefStream = false;
    private int $startxref = 0;
    private array $cache = [];
    private array $objStm = [];
    private ?array $escaneo = null;

    private const DELIM = " \t\r\n\f\0()<>[]{}/%";

    /* ================= Lectura ================= */

    public function __construct(string $pdf)
    {
        if (!str_starts_with(ltrim(substr($pdf, 0, 1024)), '%PDF-') && !str_contains(substr($pdf, 0, 1024), '%PDF-')) {
            throw new RuntimeException('El archivo no es un PDF.');
        }
        $this->pdf = $pdf;
        $this->leerXref();
        if (isset($this->trailer['Encrypt'])) {
            throw new RuntimeException('El PDF tiene contraseña o restricciones. Guárdalo de nuevo sin protección y vuelve a subirlo.');
        }
        if (($this->trailer['Root'][0] ?? '') !== 'ref') {
            throw new RuntimeException('No se encontró el índice del PDF.');
        }
    }

    /** Cuántas páginas tiene. */
    public function paginas(): int
    {
        [$pages] = $this->arbol();
        return (int)$this->numero($pages[1]['Count'] ?? ['num', '0']);
    }

    /** [ancho, alto] en puntos de la primera página, siempre en vertical. A4 si no se puede leer. */
    public function tamano(): array
    {
        try {
            [$nodo] = $this->arbol();
            $caja = $nodo[1]['MediaBox'] ?? null;
            for ($i = 0; $i < 40 && isset($nodo[1]['Kids']); $i++) {
                $kids = $this->valor($nodo[1]['Kids']);
                if (($kids[0] ?? '') !== 'arr' || !$kids[1]) break;
                $nodo = $this->valor($kids[1][0]);
                if (isset($nodo[1]['MediaBox'])) $caja = $nodo[1]['MediaBox'];
            }
            $caja = $caja ? $this->valor($caja) : null;
            if (($caja[0] ?? '') === 'arr' && count($caja[1]) === 4) {
                $n = array_map(fn($x) => $this->numero($x), $caja[1]);
                $w = abs($n[2] - $n[0]);
                $h = abs($n[3] - $n[1]);
                if ($w > 100 && $h > 100) return [min($w, $h), max($w, $h)];
            }
        } catch (\Throwable $e) { /* A4 */ }
        return [595.28, 841.89];
    }

    /** [dict de Pages, número, generación] */
    private function arbol(): array
    {
        $root = $this->valor($this->trailer['Root']);
        $ref = $root[1]['Pages'] ?? null;
        if (($ref[0] ?? '') !== 'ref') throw new RuntimeException('El PDF no tiene árbol de páginas.');
        $pages = $this->objeto($ref[1])[0];
        if (($pages[0] ?? '') !== 'dict') throw new RuntimeException('El árbol de páginas del PDF está dañado.');
        return [$pages, $ref[1], $ref[2]];
    }

    private function leerXref(): void
    {
        $s = $this->pdf;
        $pos = strrpos($s, 'startxref');
        if ($pos === false || !preg_match('/startxref\s+(\d+)/', $s, $m, 0, $pos)) {
            throw new RuntimeException('El PDF está incompleto o dañado (sin tabla de objetos).');
        }
        $off = (int)$m[1];
        $this->startxref = $off;
        $visto = [];
        $primero = true;
        while ($primero || $off > 0) {
            if (isset($visto[$off]) || $off >= strlen($s)) break;
            $visto[$off] = true;
            $p = $off;
            self::ws($s, $p);
            if (substr($s, $p, 4) === 'xref') {
                $tr = $this->leerTablaClasica($p + 4);
                if ($primero) $this->trailer = $tr;
                if (isset($tr['XRefStm'])) {
                    try { $this->leerXrefStream((int)$this->numero($tr['XRefStm'])); } catch (\Throwable $e) { /* sigue con la clásica */ }
                }
                $off = isset($tr['Prev']) ? (int)$this->numero($tr['Prev']) : 0;
            } else {
                $d = $this->leerXrefStream($off);
                if ($primero) { $this->trailer = $d; $this->conXrefStream = true; }
                $off = isset($d['Prev']) ? (int)$this->numero($d['Prev']) : 0;
            }
            $primero = false;
        }
        if (!$this->trailer) throw new RuntimeException('No se pudo leer la tabla de objetos del PDF.');
    }

    private function leerTablaClasica(int $p): array
    {
        $s = $this->pdf;
        while (true) {
            self::ws($s, $p);
            if (!preg_match('/\G(\d+)\s+(\d+)[ \t]*[\r\n]/', $s, $m, 0, $p)) break;
            $p += strlen($m[0]);
            $ini = (int)$m[1];
            $cant = (int)$m[2];
            for ($k = 0; $k < $cant; $k++) {
                self::ws($s, $p);
                if (!preg_match('/\G(\d{1,10})\s+(\d{1,5})\s+([nf])/', $s, $e, 0, $p)) {
                    throw new RuntimeException('La tabla de objetos del PDF está dañada.');
                }
                $p += strlen($e[0]);
                $num = $ini + $k;
                // Lo más nuevo manda; los libres no se anotan (en los híbridos los pone XRefStm)
                if ($e[3] === 'n' && !isset($this->xref[$num])) $this->xref[$num] = ['o' => (int)$e[1], 'g' => (int)$e[2]];
            }
        }
        self::ws($s, $p);
        if (substr($s, $p, 7) !== 'trailer') throw new RuntimeException('El PDF no tiene trailer.');
        $p += 7;
        $tr = self::valorEn($s, $p);
        if ($tr[0] !== 'dict') throw new RuntimeException('El trailer del PDF está dañado.');
        return $tr[1];
    }

    private function leerXrefStream(int $off): array
    {
        [$v, $raw] = $this->objetoEn($this->pdf, $off, null);
        if ($v[0] !== 'dict' || $raw === null || ($v[1]['Type'][1] ?? '') !== 'XRef') {
            throw new RuntimeException('La tabla de objetos del PDF está dañada.');
        }
        $d = $v[1];
        $data = $this->decodificar($d, $raw);
        $w = array_map(fn($x) => (int)$this->numero($x), $this->valor($d['W'])[1]);
        if (count($w) !== 3) throw new RuntimeException('La tabla de objetos del PDF está dañada.');
        $indice = isset($d['Index']) ? array_map(fn($x) => (int)$this->numero($x), $this->valor($d['Index'])[1]) : [0, (int)$this->numero($d['Size'])];
        $fila = $w[0] + $w[1] + $w[2];
        $p = 0;
        $n = strlen($data);
        for ($i = 0; $i + 1 < count($indice); $i += 2) {
            for ($k = 0; $k < $indice[$i + 1]; $k++) {
                if ($p + $fila > $n) break 2;
                $campo = function (int $ancho, int $defecto) use ($data, &$p): int {
                    if ($ancho === 0) return $defecto;
                    $x = 0;
                    for ($j = 0; $j < $ancho; $j++) $x = ($x << 8) | ord($data[$p++]);
                    return $x;
                };
                $tipo = $campo($w[0], 1);
                $f2 = $campo($w[1], 0);
                $f3 = $campo($w[2], 0);
                $num = $indice[$i] + $k;
                if (isset($this->xref[$num])) continue;
                if ($tipo === 1) $this->xref[$num] = ['o' => $f2, 'g' => $f3];
                elseif ($tipo === 2) $this->xref[$num] = ['s' => $f2, 'i' => $f3];
            }
        }
        return $d;
    }

    /** [valor, datos del stream o null] del objeto número $num. */
    private function objeto(int $num): array
    {
        if (isset($this->cache[$num])) return $this->cache[$num];
        $e = $this->xref[$num] ?? null;
        $r = null;
        if (isset($e['s'])) {
            $r = [$this->desdeObjStm((int)$e['s'], $num), null];
        } elseif (isset($e['o'])) {
            try { $r = $this->objetoEn($this->pdf, (int)$e['o'], $num); } catch (\Throwable $x) { $r = null; }
        }
        if ($r === null) {
            // El desplazamiento no apunta bien (pasa en PDF retocados): se busca en el archivo
            $off = $this->buscar($num);
            if ($off === null) throw new RuntimeException('Falta un objeto del PDF (' . $num . ').');
            $r = $this->objetoEn($this->pdf, $off, $num);
        }
        return $this->cache[$num] = $r;
    }

    private function buscar(int $num): ?int
    {
        if ($this->escaneo === null) {
            $this->escaneo = [];
            if (preg_match_all('/(?<![0-9])(\d+)\s+(\d+)\s+obj\b/', $this->pdf, $m, PREG_OFFSET_CAPTURE)) {
                foreach ($m[1] as $i => [$n, $o]) $this->escaneo[(int)$n] = (int)$m[0][$i][1];
            }
        }
        return $this->escaneo[$num] ?? null;
    }

    private function objetoEn(string $s, int $p, ?int $num): array
    {
        self::ws($s, $p);
        if (!preg_match('/\G(\d+)\s+(\d+)\s+obj/', $s, $m, 0, $p) || ($num !== null && (int)$m[1] !== $num)) {
            throw new RuntimeException('Objeto del PDF fuera de lugar.');
        }
        $p += strlen($m[0]);
        $v = self::valorEn($s, $p);
        self::ws($s, $p);
        $datos = null;
        if ($v[0] === 'dict' && substr($s, $p, 6) === 'stream') {
            $p += 6;
            if (($s[$p] ?? '') === "\r") $p++;
            if (($s[$p] ?? '') === "\n") $p++;
            $largo = -1;
            try { $largo = (int)$this->numero($v[1]['Length'] ?? ['num', '-1']); } catch (\Throwable $e) { $largo = -1; }
            $q = $p + max(0, $largo);
            self::ws($s, $q);
            if ($largo >= 0 && substr($s, $q, 9) === 'endstream') {
                $datos = substr($s, $p, $largo);
            } else {
                $fin = strpos($s, 'endstream', $p);
                if ($fin === false) throw new RuntimeException('Un stream del PDF está incompleto.');
                $datos = substr($s, $p, $fin - $p);
                if (str_ends_with($datos, "\r\n")) $datos = substr($datos, 0, -2);
                elseif (str_ends_with($datos, "\n") || str_ends_with($datos, "\r")) $datos = substr($datos, 0, -1);
            }
        }
        return [$v, $datos];
    }

    private function desdeObjStm(int $sn, int $num): array
    {
        if (!isset($this->objStm[$sn])) {
            [$d, $raw] = $this->objeto($sn);
            if ($raw === null) throw new RuntimeException('Un object stream del PDF está dañado.');
            $buf = $this->decodificar($d[1], $raw);
            $n = (int)$this->numero($d[1]['N']);
            $first = (int)$this->numero($d[1]['First']);
            $p = 0;
            $mapa = [];
            for ($i = 0; $i < $n; $i++) {
                self::ws($buf, $p);
                if (!preg_match('/\G(\d+)\s+(\d+)/', $buf, $m, 0, $p)) break;
                $p += strlen($m[0]);
                $mapa[(int)$m[1]] = $first + (int)$m[2];
            }
            $this->objStm[$sn] = [$buf, $mapa];
        }
        [$buf, $mapa] = $this->objStm[$sn];
        if (!isset($mapa[$num])) throw new RuntimeException('Falta un objeto del PDF (' . $num . ').');
        $p = $mapa[$num];
        return self::valorEn($buf, $p);
    }

    private function decodificar(array $dict, string $raw): string
    {
        $f = isset($dict['Filter']) ? $this->valor($dict['Filter']) : null;
        $filtros = $f === null ? [] : ($f[0] === 'arr' ? array_map(fn($x) => $x[1], $f[1]) : [$f[1]]);
        $parms = isset($dict['DecodeParms']) ? $this->valor($dict['DecodeParms']) : null;
        foreach ($filtros as $i => $nombre) {
            if ($nombre !== 'FlateDecode' && $nombre !== 'Fl') {
                throw new RuntimeException('El PDF usa una compresión que no se puede leer (' . $nombre . ').');
            }
            $out = @gzuncompress($raw);
            if ($out === false) $out = @gzinflate(substr($raw, 2));
            if ($out === false) throw new RuntimeException('Un stream del PDF está dañado.');
            $raw = $out;
            $pp = $parms === null ? null : ($parms[0] === 'arr' ? $this->valor($parms[1][$i] ?? ['null']) : $parms);
            if (($pp[0] ?? '') === 'dict' && isset($pp[1]['Predictor']) && (int)$this->numero($pp[1]['Predictor']) >= 10) {
                $cols = isset($pp[1]['Columns']) ? (int)$this->numero($pp[1]['Columns']) : 1;
                $raw = self::predictorPng($raw, max(1, $cols));
            }
        }
        return $raw;
    }

    private static function predictorPng(string $d, int $fila): string
    {
        $out = '';
        $prev = array_fill(0, $fila, 0);
        $n = strlen($d);
        for ($i = 0; $i < $n; $i += $fila + 1) {
            $tipo = ord($d[$i]);
            $cur = [];
            for ($j = 0; $j < $fila; $j++) {
                $x = ord($d[$i + 1 + $j] ?? "\0");
                $a = $j > 0 ? $cur[$j - 1] : 0;
                $b = $prev[$j];
                $c = $j > 0 ? $prev[$j - 1] : 0;
                switch ($tipo) {
                    case 1: $x += $a; break;
                    case 2: $x += $b; break;
                    case 3: $x += ($a + $b) >> 1; break;
                    case 4:
                        $pa = abs($b - $c); $pb = abs($a - $c); $pc = abs($a + $b - 2 * $c);
                        $x += ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
                        break;
                }
                $cur[$j] = $x & 255;
            }
            $out .= pack('C*', ...$cur);
            $prev = $cur;
        }
        return $out;
    }

    /** Si es una referencia, el objeto al que apunta. */
    private function valor(array $v): array
    {
        for ($i = 0; $i < 20 && $v[0] === 'ref'; $i++) $v = $this->objeto($v[1])[0];
        return $v;
    }

    private function numero(array $v): float
    {
        $v = $this->valor($v);
        if ($v[0] !== 'num') throw new RuntimeException('Se esperaba un número en el PDF.');
        return (float)$v[1];
    }

    private static function ws(string $s, int &$p): void
    {
        $n = strlen($s);
        while ($p < $n) {
            $c = $s[$p];
            if ($c === '%') {
                while ($p < $n && $s[$p] !== "\n" && $s[$p] !== "\r") $p++;
                continue;
            }
            if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t" || $c === "\f" || $c === "\0") { $p++; continue; }
            break;
        }
    }

    /** Un valor PDF como [tipo, …] guardando el texto crudo, para poder volver a escribirlo igual. */
    private static function valorEn(string $s, int &$p): array
    {
        self::ws($s, $p);
        $c = $s[$p] ?? '';
        $n = strlen($s);
        if ($c === '<') {
            if (($s[$p + 1] ?? '') === '<') {
                $p += 2;
                $d = [];
                while (true) {
                    self::ws($s, $p);
                    if ($p >= $n) throw new RuntimeException('Diccionario del PDF sin cerrar.');
                    if ($s[$p] === '>' && ($s[$p + 1] ?? '') === '>') { $p += 2; break; }
                    $k = self::valorEn($s, $p);
                    if ($k[0] !== 'name') throw new RuntimeException('Diccionario del PDF dañado.');
                    $d[$k[1]] = self::valorEn($s, $p);
                }
                return ['dict', $d];
            }
            $fin = strpos($s, '>', $p);
            if ($fin === false) throw new RuntimeException('Texto del PDF sin cerrar.');
            $raw = substr($s, $p + 1, $fin - $p - 1);
            $p = $fin + 1;
            return ['hex', $raw];
        }
        if ($c === '[') {
            $p++;
            $a = [];
            while (true) {
                self::ws($s, $p);
                if ($p >= $n) throw new RuntimeException('Lista del PDF sin cerrar.');
                if ($s[$p] === ']') { $p++; break; }
                $a[] = self::valorEn($s, $p);
            }
            return ['arr', $a];
        }
        if ($c === '(') {
            $i = $p + 1;
            $hondo = 1;
            while ($i < $n && $hondo > 0) {
                $ch = $s[$i];
                if ($ch === '\\') { $i += 2; continue; }
                if ($ch === '(') $hondo++;
                elseif ($ch === ')') $hondo--;
                $i++;
            }
            $raw = substr($s, $p + 1, $i - $p - 2);
            $p = $i;
            return ['str', $raw];
        }
        if ($c === '/') {
            $i = $p + 1;
            while ($i < $n && strpos(self::DELIM, $s[$i]) === false) $i++;
            $nombre = substr($s, $p + 1, $i - $p - 1);
            $p = $i;
            return ['name', $nombre];
        }
        if (preg_match('/\G[+-]?(?:\d+\.?\d*|\.\d+)/', $s, $m, 0, $p)) {
            $tok = $m[0];
            $q = $p + strlen($tok);
            if (ctype_digit($tok) && preg_match('/\G\s+(\d+)\s+R(?![A-Za-z0-9])/', $s, $r, 0, $q)) {
                $p = $q + strlen($r[0]);
                return ['ref', (int)$tok, (int)$r[1]];
            }
            $p = $q;
            return ['num', $tok];
        }
        if (preg_match('/\G(true|false|null)/', $s, $m, 0, $p)) {
            $p += strlen($m[0]);
            return $m[0] === 'null' ? ['null'] : ['bool', $m[0]];
        }
        throw new RuntimeException('El PDF tiene un valor que no se entiende (posición ' . $p . ').');
    }

    private static function escribirValor(array $v): string
    {
        switch ($v[0]) {
            case 'dict':
                $o = '<<';
                foreach ($v[1] as $k => $x) $o .= ' /' . $k . ' ' . self::escribirValor($x);
                return $o . ' >>';
            case 'arr':  return '[' . implode(' ', array_map([self::class, 'escribirValor'], $v[1])) . ']';
            case 'name': return '/' . $v[1];
            case 'ref':  return $v[1] . ' ' . $v[2] . ' R';
            case 'num':  return (string)$v[1];
            case 'str':  return '(' . $v[1] . ')';
            case 'hex':  return '<' . $v[1] . '>';
            case 'bool': return (string)$v[1];
            default:     return 'null';
        }
    }

    /* ================= Escritura ================= */

    /** Los números de objeto de las páginas que agregó la última llamada a agregar(). */
    public array $paginasNuevas = [];

    /**
     * El PDF con $paginas agregadas al final. $paginas: [['contenido' => operadores, 'w' => , 'h' => ], …];
     * $imagenes: ['Im1' => ['w' =>, 'h' =>, 'rgb' => bytes, 'alfa' => bytes|null], …].
     */
    public function agregar(array $paginas, array $imagenes): string
    {
        [$pages, $pagesNum, $pagesGen] = $this->arbol();
        $sig = $this->siguiente();
        $objs = [];
        $rec = self::recursos($imagenes, $objs, $sig);
        $nuevas = [];
        $this->paginasNuevas = [];
        foreach ($paginas as $pg) {
            $c = $sig++;
            $z = gzcompress($pg['contenido'], 9);
            $objs[$c] = [0, '<< /Filter /FlateDecode /Length ' . strlen($z) . " >>\nstream\n" . $z . "\nendstream"];
            $p = $sig++;
            $caja = '[0 0 ' . self::n($pg['w']) . ' ' . self::n($pg['h']) . ']';
            // Todo explícito: así no hereda giro, recorte ni recursos del árbol del documento
            $objs[$p] = [0, '<< /Type /Page /Parent ' . $pagesNum . ' ' . $pagesGen . ' R /MediaBox ' . $caja . ' /CropBox ' . $caja
                . ' /Rotate 0 /Resources ' . $rec . ' 0 R /Contents ' . $c . ' 0 R >>'];
            $nuevas[] = ['ref', $p, 0];
            $this->paginasNuevas[] = $p;
        }
        // El árbol de páginas, con las hojas nuevas al final
        $d = $pages[1];
        $kids = $this->valor($d['Kids'] ?? ['arr', []]);
        $d['Kids'] = ['arr', array_merge($kids[0] === 'arr' ? $kids[1] : [], $nuevas)];
        $d['Count'] = ['num', (string)((int)$this->numero($d['Count'] ?? ['num', '0']) + count($nuevas))];
        $objs[$pagesNum] = [$pagesGen, self::escribirValor(['dict', $d])];
        return $this->actualizar($objs, $sig);
    }

    /**
     * Vuelve a dibujar páginas que ya están en el PDF (la hoja de firmas) sin
     * tocar nada más: se reescriben esos objetos en una actualización nueva.
     * Así las firmas electrónicas que ya tiene el archivo siguen siendo válidas
     * para su versión. $nums: los objetos de esas páginas, en orden.
     */
    public function reemplazarPaginas(array $nums, array $paginas, array $imagenes): string
    {
        if (count($nums) !== count($paginas)) throw new RuntimeException('La hoja de firmas cambió de tamaño.');
        $sig = $this->siguiente();
        $objs = [];
        $rec = self::recursos($imagenes, $objs, $sig);
        foreach (array_values($nums) as $i => $num) {
            $e = $this->xref[$num] ?? null;
            $gen = (int)($e['g'] ?? 0);
            $pag = $this->objeto((int)$num)[0];
            if (($pag[0] ?? '') !== 'dict') throw new RuntimeException('No se encontró la hoja de firmas en el PDF.');
            $c = $sig++;
            $z = gzcompress($paginas[$i]['contenido'], 9);
            $objs[$c] = [0, '<< /Filter /FlateDecode /Length ' . strlen($z) . " >>\nstream\n" . $z . "\nendstream"];
            $d = $pag[1];
            $d['Contents'] = ['ref', $c, 0];
            $d['Resources'] = ['ref', $rec, 0];
            $objs[(int)$num] = [$gen, self::escribirValor(['dict', $d])];
        }
        return $this->actualizar($objs, $sig);
    }

    /**
     * Firma electrónica (PAdES básica, adbe.pkcs7.detached) con el certificado
     * .p12/.pfx de la persona, como una actualización más: el campo de firma va
     * en la hoja de firmas, sobre la fila de quien firma. $op: pagina (objeto de
     * la página), rect [x1, y1, x2, y2], nombre, razon, lugar.
     */
    public function firmarDigital(string $p12, string $clave, array $op): string
    {
        $c = self::abrirCertificado($p12, $clave);
        $sig = $this->siguiente();
        $objs = [];
        $tam = 16384;                                  // bytes reservados para la firma (CMS con su cadena)
        $sigNum = $sig++;
        $apNum = $sig++;
        $widNum = $sig++;
        $pagNum = (int)$op['pagina'];
        $pagGen = (int)($this->xref[$pagNum]['g'] ?? 0);
        [$x1, $y1, $x2, $y2] = array_map('floatval', $op['rect'] ?? [0, 0, 0, 0]);
        $n = 1 + $this->firmasExistentes();

        $fecha = 'D:' . date('YmdHis') . str_replace(':', "'", date('P')) . "'";
        $txt = fn(string $s) => self::cadena(self::winAnsi($s));
        $objs[$sigNum] = [0, '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached'
            . ' /ByteRange [0 ' . str_repeat(' ', 32) . ']'
            . ' /Contents <' . str_repeat('0', $tam * 2) . '>'
            . ' /M (' . $fecha . ') /Name ' . $txt((string)($op['nombre'] ?? $c['cn']))
            . (($op['razon'] ?? '') !== '' ? ' /Reason ' . $txt((string)$op['razon']) : '')
            . (($op['lugar'] ?? '') !== '' ? ' /Location ' . $txt((string)$op['lugar']) : '') . ' >>'];
        $w = max(0, $x2 - $x1);
        $h = max(0, $y2 - $y1);
        $objs[$apNum] = [0, '<< /Type /XObject /Subtype /Form /BBox [0 0 ' . self::n($w) . ' ' . self::n($h) . "] /Length 0 >>\nstream\n\nendstream"];
        $objs[$widNum] = [0, '<< /Type /Annot /Subtype /Widget /FT /Sig /F 132 /T ' . $txt('Firma ' . $n . ' - ' . $c['cn'])
            . ' /V ' . $sigNum . ' 0 R /Rect [' . self::n($x1) . ' ' . self::n($y1) . ' ' . self::n($x2) . ' ' . self::n($y2) . ']'
            . ' /P ' . $pagNum . ' ' . $pagGen . ' R /AP << /N ' . $apNum . ' 0 R >> >>'];

        // La página lleva el campo entre sus anotaciones
        $pag = $this->objeto($pagNum)[0];
        if (($pag[0] ?? '') !== 'dict') throw new RuntimeException('No se encontró la hoja de firmas en el PDF.');
        $pd = $pag[1];
        $annots = isset($pd['Annots']) ? $this->valor($pd['Annots']) : ['arr', []];
        $pd['Annots'] = ['arr', array_merge($annots[0] === 'arr' ? $annots[1] : [], [['ref', $widNum, 0]])];
        $objs[$pagNum] = [$pagGen, self::escribirValor(['dict', $pd])];

        // Y el formulario del documento, con el campo y SigFlags 3 (tiene firmas; solo se agregan cosas)
        $rootRef = $this->trailer['Root'];
        $root = $this->valor($rootRef);
        $rd = $root[1];
        $af = $rd['AcroForm'] ?? null;
        if (($af[0] ?? '') === 'ref') {
            $afd = $this->valor($af);
            $afd = ($afd[0] ?? '') === 'dict' ? $afd[1] : [];
            $campos = isset($afd['Fields']) ? $this->valor($afd['Fields']) : ['arr', []];
            $afd['Fields'] = ['arr', array_merge($campos[0] === 'arr' ? $campos[1] : [], [['ref', $widNum, 0]])];
            $afd['SigFlags'] = ['num', '3'];
            $objs[$af[1]] = [(int)$af[2], self::escribirValor(['dict', $afd])];
        } else {
            $afd = ($af[0] ?? '') === 'dict' ? $af[1] : [];
            $campos = isset($afd['Fields']) ? $this->valor($afd['Fields']) : ['arr', []];
            $afd['Fields'] = ['arr', array_merge($campos[0] === 'arr' ? $campos[1] : [], [['ref', $widNum, 0]])];
            $afd['SigFlags'] = ['num', '3'];
            $rd['AcroForm'] = ['dict', $afd];
            $objs[$rootRef[1]] = [(int)$rootRef[2], self::escribirValor(['dict', $rd])];
        }

        $out = $this->actualizar($objs, $sig);

        // ByteRange: todo el archivo menos el hueco de /Contents
        $ini = $this->posObjeto($out, $sigNum);
        $a = strpos($out, '/Contents <', $ini);
        $br = strpos($out, '/ByteRange [0 ', $ini);
        if ($a === false || $br === false) throw new RuntimeException('No se pudo preparar la firma.');
        $a += 10;                                       // en el «<»
        $b = $a + $tam * 2 + 2;                         // después del «>»
        $rango = str_pad($a . ' ' . $b . ' ' . (strlen($out) - $b), 32);
        if (strlen($rango) > 32) throw new RuntimeException('No se pudo preparar la firma.');
        $out = substr_replace($out, $rango, $br + 14, 32);

        $cms = self::cms(substr($out, 0, $a) . substr($out, $b), $c);
        $hex = bin2hex($cms);
        if (strlen($hex) > $tam * 2) throw new RuntimeException('La firma del certificado no cabe en el espacio reservado.');
        return substr_replace($out, str_pad($hex, $tam * 2, '0'), $a + 1, strlen($hex) < $tam * 2 ? $tam * 2 : strlen($hex));
    }

    /**
     * Un PDF nuevo (el informe semanal, por ejemplo) con esas páginas, sin
     * partir de otro archivo. Mismo formato de páginas e imágenes que agregar().
     */
    public static function nuevo(array $paginas, array $imagenes, string $titulo = ''): string
    {
        $objs = [];
        $sig = 4;                                       // 1 catálogo, 2 árbol de páginas, 3 datos del documento
        $rec = self::recursos($imagenes, $objs, $sig);
        $kids = [];
        foreach ($paginas as $pg) {
            $c = $sig++;
            $z = gzcompress($pg['contenido'], 9);
            $objs[$c] = [0, '<< /Filter /FlateDecode /Length ' . strlen($z) . " >>\nstream\n" . $z . "\nendstream"];
            $p = $sig++;
            $caja = '[0 0 ' . self::n($pg['w']) . ' ' . self::n($pg['h']) . ']';
            $objs[$p] = [0, '<< /Type /Page /Parent 2 0 R /MediaBox ' . $caja . ' /Resources ' . $rec . ' 0 R /Contents ' . $c . ' 0 R >>'];
            $kids[] = $p . ' 0 R';
        }
        $objs[1] = [0, '<< /Type /Catalog /Pages 2 0 R >>'];
        $objs[2] = [0, '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>'];
        $objs[3] = [0, '<< /Producer (InnoTech Hub) /Title ' . self::cadena(self::winAnsi($titulo)) . ' /CreationDate (D:' . date('YmdHis') . ') >>'];
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $pos = [];
        foreach ($objs as $num => [$gen, $cuerpo]) {
            $pos[$num] = strlen($out);
            $out .= $num . ' ' . $gen . " obj\n" . $cuerpo . "\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 " . ($sig) . "\n0000000000 65535 f\r\n";
        for ($i = 1; $i < $sig; $i++) $out .= isset($pos[$i]) ? sprintf("%010d 00000 n\r\n", $pos[$i]) : "0000000000 65535 f\r\n";
        $id = md5($titulo . microtime(true));
        $out .= "trailer\n<< /Size " . $sig . ' /Root 1 0 R /Info 3 0 R /ID [<' . $id . '> <' . $id . ">] >>\nstartxref\n" . $xref . "\n%%EOF\n";
        return $out;
    }

    /** Cuántas firmas electrónicas tiene ya el PDF. */
    public function firmasExistentes(): int
    {
        return preg_match_all('#/ByteRange\s*\[#', $this->pdf);
    }

    /**
     * Abre el .p12/.pfx con su contraseña. Devuelve cert, pkey, extracerts y los
     * datos para mostrar (cn, emisor, serie, desde, hasta) o lanza el motivo.
     */
    public static function abrirCertificado(string $p12, string $clave): array
    {
        if ($p12 === '') throw new RuntimeException('Carga tu archivo de firma electrónica (.p12 o .pfx).');
        if (!function_exists('openssl_pkcs12_read')) throw new RuntimeException('El servidor no puede leer certificados.');
        $c = [];
        if (!@openssl_pkcs12_read($p12, $c, $clave)) {
            throw new RuntimeException('No se pudo abrir el certificado: la contraseña no es correcta o el archivo no es un .p12 o .pfx válido.');
        }
        $x = openssl_x509_parse($c['cert']) ?: [];
        $hasta = (int)($x['validTo_time_t'] ?? 0);
        $desde = (int)($x['validFrom_time_t'] ?? 0);
        if ($hasta && $hasta < time()) throw new RuntimeException('Tu certificado venció el ' . date('d/m/Y', $hasta) . '. Renuévalo con tu entidad certificadora.');
        if ($desde && $desde > time() + 300) throw new RuntimeException('Tu certificado todavía no está vigente (desde el ' . date('d/m/Y', $desde) . ').');
        $nombre = fn(array $dn) => (string)($dn['CN'] ?? $dn['O'] ?? '');
        return [
            'cert' => $c['cert'], 'pkey' => $c['pkey'], 'extracerts' => (array)($c['extracerts'] ?? []),
            'cn' => $nombre((array)($x['subject'] ?? [])) ?: 'Certificado', 'emisor' => $nombre((array)($x['issuer'] ?? [])),
            'serie' => (string)($x['serialNumberHex'] ?? $x['serialNumber'] ?? ''), 'desde' => $desde, 'hasta' => $hasta,
        ];
    }

    /** La firma CMS (PKCS#7 desacoplada, DER) de $datos con el certificado. */
    private static function cms(string $datos, array $c): string
    {
        $dir = sys_get_temp_dir();
        $in = tempnam($dir, 'fdi');
        $out = tempnam($dir, 'fdo');
        $extra = null;
        try {
            file_put_contents($in, $datos);
            if ($c['extracerts']) {
                $extra = tempnam($dir, 'fde');
                file_put_contents($extra, implode("\n", $c['extracerts']));
            }
            $ok = openssl_cms_sign($in, $out, $c['cert'], $c['pkey'], null, OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER, $extra);
            $der = $ok ? (string)file_get_contents($out) : '';
            if ($der === '') throw new RuntimeException('El certificado no pudo firmar (' . (openssl_error_string() ?: 'error de OpenSSL') . ').');
            return $der;
        } finally {
            @unlink($in);
            @unlink($out);
            if ($extra) @unlink($extra);
        }
    }

    private function siguiente(): int
    {
        return max((int)$this->numero($this->trailer['Size'] ?? ['num', '0']), $this->xref ? max(array_keys($this->xref)) + 1 : 1);
    }

    /** Letras (Helvetica normal y negrita) e imágenes en un solo diccionario de recursos. Devuelve su número. */
    private static function recursos(array $imagenes, array &$objs, int &$sig): int
    {
        $f1 = $sig++;
        $f2 = $sig++;
        $objs[$f1] = [0, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
        $objs[$f2] = [0, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
        $xo = '';
        foreach ($imagenes as $nombre => $im) {
            $smask = '';
            if (($im['alfa'] ?? null) !== null) {
                $a = $sig++;
                $z = gzcompress($im['alfa'], 9);
                $objs[$a] = [0, '<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h']
                    . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($z) . " >>\nstream\n" . $z . "\nendstream"];
                $smask = ' /SMask ' . $a . ' 0 R';
            }
            $i = $sig++;
            $z = gzcompress($im['rgb'], 9);
            $objs[$i] = [0, '<< /Type /XObject /Subtype /Image /Width ' . $im['w'] . ' /Height ' . $im['h']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode' . $smask . ' /Length ' . strlen($z) . " >>\nstream\n" . $z . "\nendstream"];
            $xo .= ' /' . $nombre . ' ' . $i . ' 0 R';
        }
        $rec = $sig++;
        $objs[$rec] = [0, '<< /ProcSet [/PDF /Text /ImageB /ImageC] /Font << /F1 ' . $f1 . ' 0 R /F2 ' . $f2 . ' 0 R >>'
            . ($xo !== '' ? ' /XObject <<' . $xo . ' >>' : '') . ' >>'];
        return $rec;
    }

    /**
     * Escribe la actualización incremental: los bytes del original intactos y,
     * detrás, los objetos nuevos o reescritos con su tabla. Si el original usa
     * xref comprimida, la actualización también (así lo esperan los lectores).
     * $objs: num => [generación, cuerpo].
     */
    private function actualizar(array $objs, int $sig): string
    {
        $out = $this->pdf;
        if (!str_ends_with($out, "\n") && !str_ends_with($out, "\r")) $out .= "\n";
        $pos = [];
        foreach ($objs as $num => [$gen, $cuerpo]) {
            $pos[$num] = [strlen($out), $gen];
            $out .= $num . ' ' . $gen . " obj\n" . $cuerpo . "\nendobj\n";
        }
        $tr = ' /Root ' . self::escribirValor($this->trailer['Root'])
            . (isset($this->trailer['Info']) ? ' /Info ' . self::escribirValor($this->trailer['Info']) : '')
            . (isset($this->trailer['ID']) ? ' /ID ' . self::escribirValor($this->trailer['ID']) : '')
            . ' /Prev ' . $this->startxref;
        if ($this->conXrefStream) {
            $x = $sig;
            $pos[$x] = [strlen($out), 0];
            ksort($pos);
            [$indice, $filas] = self::tramos($pos);
            $bin = '';
            foreach ($filas as [$o, $g]) $bin .= chr(1) . pack('N', $o) . pack('n', $g);
            $out .= $x . " 0 obj\n<< /Type /XRef /Size " . (max(array_keys($pos)) + 1) . ' /W [1 4 2] /Index [' . implode(' ', $indice) . ']' . $tr
                . ' /Length ' . strlen($bin) . " >>\nstream\n" . $bin . "\nendstream\nendobj\n";
            $out .= "startxref\n" . $pos[$x][0] . "\n%%EOF\n";
        } else {
            ksort($pos);
            $inicio = strlen($out);
            $out .= "xref\n";
            foreach (self::tramosClasicos($pos) as [$ini, $lista]) {
                $out .= $ini . ' ' . count($lista) . "\n";
                foreach ($lista as [$o, $g]) $out .= sprintf("%010d %05d n\r\n", $o, $g);
            }
            $out .= "trailer\n<< /Size " . max(max(array_keys($pos)) + 1, (int)$this->numero($this->trailer['Size'] ?? ['num', '0'])) . $tr . " >>\nstartxref\n" . $inicio . "\n%%EOF\n";
        }
        return $out;
    }

    /** Dónde empieza el objeto $num dentro de la actualización recién escrita. */
    private function posObjeto(string $out, int $num): int
    {
        $p = strrpos($out, "\n" . $num . ' 0 obj');
        if ($p === false) throw new RuntimeException('No se encontró el objeto de la firma.');
        return $p + 1;
    }

    /** [/Index, filas] de la xref comprimida: tramos de números seguidos. */
    private static function tramos(array $pos): array
    {
        $indice = [];
        $filas = [];
        $ini = null;
        $prev = null;
        $cant = 0;
        foreach ($pos as $num => $e) {
            if ($prev !== null && $num === $prev + 1) { $cant++; }
            else {
                if ($ini !== null) { $indice[] = $ini; $indice[] = $cant; }
                $ini = $num;
                $cant = 1;
            }
            $filas[] = $e;
            $prev = $num;
        }
        if ($ini !== null) { $indice[] = $ini; $indice[] = $cant; }
        return [$indice, $filas];
    }

    private static function tramosClasicos(array $pos): array
    {
        $out = [];
        foreach ($pos as $num => $e) {
            $u = count($out) - 1;
            if ($u >= 0 && $out[$u][0] + count($out[$u][1]) === $num) $out[$u][1][] = $e;
            else $out[] = [$num, [$e]];
        }
        return $out;
    }

    public static function n(float $x): string
    {
        $s = number_format($x, 2, '.', '');
        return rtrim(rtrim($s, '0'), '.') ?: '0';
    }

    /* ================= Texto (Helvetica estándar, WinAnsi) ================= */

    private const ANCHOS_N = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584,350,556,350,222,556,333,1000,556,556,333,1000,667,333,1000,350,611,350,350,222,222,333,333,350,556,1000,333,1000,500,333,944,350,500,667,278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500];
    private const ANCHOS_B = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584,350,556,350,278,556,500,1000,556,556,333,1000,667,333,1000,350,611,350,350,278,278,500,500,350,556,1000,333,1000,556,333,944,350,500,667,278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556];

    /** UTF-8 a WinAnsi (lo que no exista se aproxima). */
    public static function winAnsi(string $t): string
    {
        $t = str_replace(["\u{2028}", "\u{2029}", "\t"], ' ', $t);
        $r = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $t);
        if ($r === false) $r = mb_convert_encoding($t, 'Windows-1252', 'UTF-8');
        return preg_replace('/[\x00-\x1F]/', ' ', $r);
    }

    /** Ancho en puntos de un texto ya en WinAnsi. */
    public static function ancho(string $w, float $tam, bool $negrita = false): float
    {
        $t = $negrita ? self::ANCHOS_B : self::ANCHOS_N;
        $s = 0;
        $n = strlen($w);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($w[$i]);
            $s += $c >= 32 ? $t[$c - 32] : 278;
        }
        return $s * $tam / 1000;
    }

    /** Parte un texto (WinAnsi) en renglones que caben en $max puntos. */
    public static function renglones(string $w, float $tam, float $max, bool $negrita = false): array
    {
        $out = [];
        $linea = '';
        foreach (preg_split('/ +/', trim($w)) as $pal) {
            $prueba = $linea === '' ? $pal : $linea . ' ' . $pal;
            if (self::ancho($prueba, $tam, $negrita) <= $max) { $linea = $prueba; continue; }
            if ($linea !== '') $out[] = $linea;
            // Una palabra que sola no cabe (un correo, un hash): se corta por letras
            while (self::ancho($pal, $tam, $negrita) > $max && strlen($pal) > 1) {
                $k = strlen($pal);
                while ($k > 1 && self::ancho(substr($pal, 0, $k), $tam, $negrita) > $max) $k--;
                $out[] = substr($pal, 0, $k);
                $pal = substr($pal, $k);
            }
            $linea = $pal;
        }
        if ($linea !== '') $out[] = $linea;
        return $out ?: [''];
    }

    /** Recorta con «...» para que quepa en una línea. */
    public static function recortar(string $w, float $tam, float $max, bool $negrita = false): string
    {
        if (self::ancho($w, $tam, $negrita) <= $max) return $w;
        while ($w !== '' && self::ancho($w . '...', $tam, $negrita) > $max) $w = substr($w, 0, -1);
        return rtrim($w) . '...';
    }

    public static function cadena(string $w): string
    {
        return '(' . strtr($w, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => ' ', "\n" => ' ']) . ')';
    }

    /* ================= Imágenes ================= */

    /** Una PNG (con transparencia) como datos de imagen para agregar(). */
    public static function imagenPng(string $ruta): ?array
    {
        $im = @imagecreatefrompng($ruta);
        if (!$im) return null;
        $w = imagesx($im);
        $h = imagesy($im);
        $rgb = '';
        $alfa = '';
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                if (!imageistruecolor($im)) {
                    $i = imagecolorsforindex($im, $c);
                    $r = $i['red']; $g = $i['green']; $b = $i['blue']; $a = $i['alpha'];
                } else {
                    $a = ($c >> 24) & 0x7F; $r = ($c >> 16) & 0xFF; $g = ($c >> 8) & 0xFF; $b = $c & 0xFF;
                }
                $rgb .= chr($r) . chr($g) . chr($b);
                $alfa .= chr(255 - (int)round($a * 255 / 127));
            }
        }
        return ['w' => $w, 'h' => $h, 'rgb' => $rgb, 'alfa' => $alfa];
    }
}
