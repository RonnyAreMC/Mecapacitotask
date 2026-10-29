<?php
/**
 * «Mis notas»: las notas personales de cada quien, al estilo de las Notas del
 * iPhone o de Google Keep (notas.php las pinta, notas_api.php las sirve).
 *
 * Colección JsonStore('notas'), una fila por nota:
 *   miembro_id     su dueño: el único que la ve y la edita (ni «ver como» la muestra)
 *   titulo         texto corto; si va vacío, la lista usa la primera línea
 *   html           el texto, del editor del panel, ya saneado (HtmlRico::limpiar)
 *   color          '' o uno de COLORES (fondo suave de la nota)
 *   fijada         arriba de todo en la lista
 *   aviso          'Y-m-d H:i' o '': a esa hora le avisa a su dueño (cron_correo.php)
 *   aviso_enviado  ya se avisó (si cambia la hora, vuelve a 0 y se avisa otra vez)
 *   compartir      la clave del enlace público (nota_ver.php?t=…) o '' si no se comparte
 *   pizarra        si su dueño le puso una pizarra (pizarra.php?nota=ID; clave Pizarra::ridNota)
 *   creado, actualizado  'Y-m-d H:i:s'
 *
 * Las búsquedas por dueño, por clave y por hora usan json_extract sobre la
 * misma tabla (no hace falta leer las notas de todos); si el SQLite del
 * servidor no lo tuviera, se cae a recorrer la colección.
 *
 * Fuera de bootstrap a propósito (el panel se sube por FTP archivo por
 * archivo): quien la usa la carga con require_once.
 */
final class Notas
{
    /** Colores suaves de la nota: clave => nombre (los tonos están en assets/notas.css y nota_ver.php). */
    public const COLORES = [
        ''         => 'Sin color',
        'celeste'  => 'Celeste',
        'menta'    => 'Menta',
        'lila'     => 'Lila',
        'rosa'     => 'Rosa',
        'durazno'  => 'Durazno',
        'vainilla' => 'Vainilla',
    ];

    /** Topes: una nota es texto, no un archivo. */
    public const MAX_TITULO = 200;
    public const MAX_HTML = 300000;
    public const MAX_NOTAS = 2000;

    private static ?JsonStore $repo = null;
    private static ?PDO $pdo = null;

    private static function repo(): JsonStore
    {
        return self::$repo ??= new JsonStore('notas');
    }

    /** Conexión propia al mismo archivo, para las consultas con json_extract. */
    private static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;
        self::repo();   // que la tabla exista
        $pdo = new PDO('sqlite:' . __DIR__ . '/../data/panel.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        return self::$pdo = $pdo;
    }

    /**
     * Las notas que cumplen $sql (sobre la columna data). $filtro se vuelve a
     * aplicar en PHP: decide de verdad, la consulta solo acorta el camino.
     */
    private static function donde(string $sql, array $params, callable $filtro): array
    {
        try {
            $st = self::pdo()->prepare('SELECT id, data FROM notas WHERE ' . $sql);
            // Con su tipo: json_extract da un entero, y en SQLite 2 no es igual a '2'
            foreach (array_values($params) as $i => $v) $st->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
            $st->execute();
            $out = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $d = json_decode((string)$f['data'], true);
                if (!is_array($d)) continue;
                $d['id'] = (int)$f['id'];
                if ($filtro($d)) $out[] = $d;
            }
            return $out;
        } catch (\Throwable $e) {
            return array_values(array_filter(self::repo()->all(), $filtro));
        }
    }

    /* ---------- Leer ---------- */

    /** Las notas de una persona: primero las fijadas, luego de la más reciente a la más vieja. */
    public static function de(int $mid): array
    {
        if ($mid <= 0) return [];
        $lista = self::donde("json_extract(data, '$.miembro_id') = ?", [$mid], fn($n) => (int)($n['miembro_id'] ?? 0) === $mid);
        usort($lista, fn($a, $b) => [(int)!empty($b['fijada']), (string)($b['actualizado'] ?? '')] <=> [(int)!empty($a['fijada']), (string)($a['actualizado'] ?? '')]);
        return $lista;
    }

    /** La nota, solo si es de $mid (si no existe o es de otro: null). */
    public static function propia(int $id, int $mid): ?array
    {
        if ($id <= 0 || $mid <= 0) return null;
        $n = self::repo()->find($id);
        return $n && (int)($n['miembro_id'] ?? 0) === $mid ? $n : null;
    }

    /** La nota de un enlace compartido (nota_ver.php?t=…), o null si la clave no existe o se apagó. */
    public static function porToken(string $t): ?array
    {
        if (!preg_match('/^[a-f0-9]{24,64}$/', $t)) return null;
        $hallada = self::donde("json_extract(data, '$.compartir') = ?", [$t], fn($n) => hash_equals((string)($n['compartir'] ?? ''), $t));
        return $hallada[0] ?? null;
    }

    /** El enlace completo para compartir: se abre sin iniciar sesión y solo muestra esa nota. */
    public static function enlace(array $n): string
    {
        $tk = (string)($n['compartir'] ?? '');
        if ($tk === '') return '';
        $https = !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . urlPanel('nota_ver.php?t=' . rawurlencode($tk));
    }

    /** El nombre que se ve: el título o, si no tiene, la primera línea del texto. */
    public static function nombre(array $n, int $max = 80): string
    {
        $t = trim((string)($n['titulo'] ?? ''));
        if ($t === '') {
            $html = preg_replace('#</(p|div|h[1-6]|li|tr|blockquote|pre)>|<br\s*/?>#i', "\n", (string)($n['html'] ?? ''));
            foreach (preg_split('/\n/', html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) as $linea) {
                if (($linea = trim($linea)) !== '') { $t = $linea; break; }
            }
        }
        if ($t === '') return 'Nota sin título';
        return mb_strlen($t) > $max ? rtrim(mb_substr($t, 0, $max - 1)) . '…' : $t;
    }

    /** Lo que viaja al navegador (sin la clave a la vista: solo si se comparte y su enlace). */
    public static function paraJs(array $n): array
    {
        $out = [
            'id'            => (int)$n['id'],
            'titulo'        => (string)($n['titulo'] ?? ''),
            'html'          => (string)($n['html'] ?? ''),
            'color'         => isset(self::COLORES[(string)($n['color'] ?? '')]) ? (string)($n['color'] ?? '') : '',
            'fijada'        => !empty($n['fijada']),
            'aviso'         => (string)($n['aviso'] ?? ''),
            'aviso_enviado' => !empty($n['aviso_enviado']),
            'compartida'    => (string)($n['compartir'] ?? '') !== '',
            'enlace'        => self::enlace($n),
            'pizarra'       => !empty($n['pizarra']),
            'pizarra_n'     => 0,
            'creado'        => (string)($n['creado'] ?? ''),
            'actualizado'   => (string)($n['actualizado'] ?? ($n['creado'] ?? '')),
        ];
        // Cuántas cosas tiene su pizarra (vacía: la nota muestra una línea, no la vista previa)
        if ($out['pizarra'] && class_exists('Pizarra')) {
            try { $out['pizarra_n'] = Pizarra::cuantos(Pizarra::ridNota($out['id'])); } catch (\Throwable $e) { /* sin el conteo, sale la vista previa */ $out['pizarra_n'] = 1; }
        }
        return $out;
    }

    /* ---------- Guardar ---------- */

    /**
     * Crea (sin id) o actualiza una nota de $mid. Solo cambia lo que viene en
     * $d (titulo, html, color, fijada): así fijar o cambiar el color no
     * necesita mandar el texto. Devuelve la nota guardada.
     */
    public static function guardar(int $mid, array $d): array
    {
        if ($mid <= 0) throw new RuntimeException('Tu sesión expiró. Entra de nuevo.');
        $id = (int)($d['id'] ?? 0);
        $antes = null;
        if ($id > 0) {
            $antes = self::propia($id, $mid);
            if (!$antes) throw new RuntimeException('Esa nota ya no existe.');
        }
        $cambios = [];
        if (array_key_exists('titulo', $d)) {
            $t = trim((string)preg_replace('/\s+/u', ' ', (string)$d['titulo']));
            $cambios['titulo'] = mb_substr($t, 0, self::MAX_TITULO);
        }
        if (array_key_exists('html', $d)) {
            $html = (string)$d['html'];
            if (strlen($html) > self::MAX_HTML) throw new RuntimeException('La nota es demasiado larga. Pásala a varias notas.');
            $html = HtmlRico::limpiar($html);
            $cambios['html'] = HtmlRico::vacio($html) ? '' : $html;
        }
        if (array_key_exists('color', $d)) {
            $c = (string)$d['color'];
            $cambios['color'] = isset(self::COLORES[$c]) ? $c : '';
        }
        if (array_key_exists('fijada', $d)) $cambios['fijada'] = !empty($d['fijada']);

        $ahora = date('Y-m-d H:i:s');
        if (!$antes) {
            if (count(self::de($mid)) >= self::MAX_NOTAS) throw new RuntimeException('Llegaste al tope de ' . self::MAX_NOTAS . ' notas. Borra alguna que ya no uses.');
            return self::repo()->insert($cambios + [
                'miembro_id' => $mid, 'titulo' => '', 'html' => '', 'color' => '', 'fijada' => false,
                'aviso' => '', 'aviso_enviado' => false, 'compartir' => '', 'pizarra' => false,
                'creado' => $ahora, 'actualizado' => $ahora,
            ]);
        }
        // Fijar o cambiar el color no la mueve en la lista: solo lo que se escribe la «actualiza»
        if (array_key_exists('titulo', $cambios) || array_key_exists('html', $cambios)) {
            $igual = ($cambios['titulo'] ?? $antes['titulo'] ?? '') === ($antes['titulo'] ?? '') && ($cambios['html'] ?? $antes['html'] ?? '') === ($antes['html'] ?? '');
            if (!$igual) $cambios['actualizado'] = $ahora;
        }
        if ($cambios) self::repo()->update($id, $cambios);
        return self::repo()->find($id) ?? ($cambios + $antes);
    }

    /** Borra la nota de $mid (y su pizarra, con sus recordatorios). */
    public static function eliminar(int $id, int $mid): bool
    {
        if (!self::propia($id, $mid)) return false;
        if (class_exists('Pizarra')) {
            try { Pizarra::borrarTodo(Pizarra::ridNota($id)); } catch (\Throwable $e) { error_log('Notas: no se pudo borrar la pizarra de #' . $id . ': ' . $e->getMessage()); }
        }
        return self::repo()->delete($id);
    }

    /**
     * Enciende o apaga el enlace público. Al encender sin enlace se crea una
     * clave nueva (32 hex, al azar); apagarlo la borra, así que otro enlace
     * después da otra dirección y el viejo deja de servir.
     */
    public static function compartir(int $id, int $mid, bool $si): array
    {
        $n = self::propia($id, $mid);
        if (!$n) throw new RuntimeException('Esa nota ya no existe.');
        $tk = (string)($n['compartir'] ?? '');
        if ($si && $tk === '') $tk = bin2hex(random_bytes(16));
        if (!$si) $tk = '';
        self::repo()->update($id, ['compartir' => $tk]);
        return self::repo()->find($id) ?? $n;
    }

    /** Pone ('Y-m-d H:i', en el futuro) o quita ('') el recordatorio. Si cambia la hora, se vuelve a avisar. */
    public static function recordatorio(int $id, int $mid, string $cuando): array
    {
        $n = self::propia($id, $mid);
        if (!$n) throw new RuntimeException('Esa nota ya no existe.');
        $cuando = trim(str_replace('T', ' ', $cuando));
        if ($cuando !== '') {
            $dt = DateTime::createFromFormat('!Y-m-d H:i', substr($cuando, 0, 16));
            if (!$dt) throw new RuntimeException('Elige una fecha y una hora.');
            if ($dt->getTimestamp() < time() - 60) throw new RuntimeException('Esa hora ya pasó: elige una que venga.');
            if ($dt->getTimestamp() > time() + 2 * 365 * 86400) throw new RuntimeException('Elige una fecha dentro de los próximos dos años.');
            $cuando = $dt->format('Y-m-d H:i');
        }
        $cambios = ['aviso' => $cuando];
        if ($cuando !== (string)($n['aviso'] ?? '')) $cambios['aviso_enviado'] = false;
        self::repo()->update($id, $cambios);
        return self::repo()->find($id) ?? $n;
    }

    /** «Yo decido si pongo una pizarra»: la pone (true) o la quita (false, solo si está vacía). */
    public static function ponerPizarra(int $id, int $mid, bool $si): array
    {
        $n = self::propia($id, $mid);
        if (!$n) throw new RuntimeException('Esa nota ya no existe.');
        if (!$si && class_exists('Pizarra') && Pizarra::cuantos(Pizarra::ridNota($id)) > 0) {
            throw new RuntimeException('La pizarra tiene cosas: ábrela y bórralas primero.');
        }
        self::repo()->update($id, ['pizarra' => $si]);
        return self::repo()->find($id) ?? $n;
    }

    /* ---------- Recordatorios ---------- */

    /**
     * Los recordatorios que ya tocan: a su dueño, en la campana (Notif::crear
     * también lo manda a sus dispositivos) y por correo. Se marca enviado ANTES
     * de avisar: si algo falla, no se repite cada minuto. Lo llama
     * cron_correo.php cada minuto. Devuelve cuántos avisó.
     */
    public static function avisarRecordatorios(): int
    {
        $ahora = date('Y-m-d H:i');
        $toca = fn($n) => (string)($n['aviso'] ?? '') !== '' && (string)$n['aviso'] <= $ahora && empty($n['aviso_enviado']);
        $filas = self::donde("json_extract(data, '$.aviso') <> '' AND json_extract(data, '$.aviso') <= ?", [$ahora], $toca);
        if (!$filas) return 0;
        if (is_file(__DIR__ . '/Notificaciones.php')) require_once __DIR__ . '/Notificaciones.php';
        $miembros = (new MiembroRepo())->mapa();
        $n = 0;
        foreach (array_slice($filas, 0, 50) as $nota) {
            self::repo()->update((int)$nota['id'], ['aviso_enviado' => true]);
            $m = $miembros[(int)($nota['miembro_id'] ?? 0)] ?? null;
            if (!$m) continue;
            $texto = self::nombre($nota, 120);
            $url = 'notas.php?id=' . (int)$nota['id'];
            if (class_exists('Notif')) Notif::crear([(int)$m['id']], 'recordatorio', $texto, 'Mis notas', $url, 0);
            try { Mailer::recordatorioNota($m, $texto, 'Mis notas', $url); } catch (\Throwable $e) { error_log('Recordatorio de Mis notas: ' . $e->getMessage()); }
            $n++;
        }
        return $n;
    }
}
