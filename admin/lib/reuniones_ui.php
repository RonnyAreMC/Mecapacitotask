<?php
/**
 * Reuniones: lo que comparten la suite de cada proyecto (reuniones.php?p=ID),
 * el detalle de una reunión (reunion.php), la pestaña «Reuniones» de cada
 * proyecto (proyecto.php) y la videoteca de las grabaciones (videoteca.php,
 * la entrada «Reuniones» del menú lateral).
 *
 * La idea viene del inicio de Zoom y de Meet: las próximas por día, lo que
 * está en curso o empieza pronto arriba y destacado, el historial aparte y,
 * en cada una, su detalle con todo lo que dejó (grabación, transcripción,
 * acta con IA, observaciones, pizarra y quién entró). En las filas, solo
 * botones de ícono con su ayuda de vidrio (data-ds-tip) y su aria-label.
 *
 * Una reunión que se repite es UNA fila guardada, pero aquí se trata como lo
 * que vive el equipo: una reunión por día (Reuniones::fechasOcurrencias).
 * Cada «vez» es ['r' => reunión, 'id', 'ts', 'fin', 'dia' => 'Y-m-d',
 * 'estado' => paso|vivo|pronto|luego, 'p' => proyecto, 'serie' => bool].
 *
 * Estilos: admin.css (bloque «Reuniones: la suite…»). El buscador, la pieza
 * de las pestañas y abrir los asistentes por la URL: admin.js (al final).
 */
require_once __DIR__ . '/Models.php';
require_once __DIR__ . '/Reuniones.php';
if (!class_exists('Cronograma') && is_file(__DIR__ . '/Cronograma.php')) require_once __DIR__ . '/Cronograma.php';
if (!class_exists('ZoomDrive') && is_file(__DIR__ . '/ZoomDrive.php')) require_once __DIR__ . '/ZoomDrive.php';

final class ReunionesUI
{
    /** «Empieza pronto» desde media hora antes (lo mismo que el Resumen). */
    public const PRONTO = 30 * 60;
    /** Próximas: cada vez de los próximos 7 días; más allá, solo la siguiente de cada reunión. */
    public const VENTANA_DIAS = 7;
    /** Asistencia: quien pulsó «Entrar» de 15 minutos antes a 15 después (como Releases). */
    public const MARGEN_INGRESO = 15 * 60;
    /**
     * ¿pizarra.php sabe mostrar la de una reunión en vista previa de solo lectura
     * (&embed=1)? Con eso la videoteca la mete en su pestaña «Pizarra»; sin eso,
     * una tarjeta con «Abrir la pizarra».
     */
    public const PIZARRA_EMBED = true;
    /** Estados de Drive que ya se pueden abrir (los de ZoomDrive::LISTOS). */
    private const DRIVE_LISTOS = ['en_drive', 'borrado'];

    private const DIAS  = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    private const MESES = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

    /* ======================= Las veces de cada reunión ======================= */

    public static function duracion(array $r): int
    {
        return max(1, (int)($r['duracion'] ?? 60));
    }

    /** Los inicios (timestamps) de una reunión: el suyo o el de cada día de la serie. */
    public static function inicios(array $r): array
    {
        $ini = trim(str_replace('T', ' ', (string)($r['inicio'] ?? '')));
        if ($ini === '') return [];
        $fechas = Reuniones::esRecurrente($r)
            ? Reuniones::fechasOcurrencias($ini, (array)($r['dias'] ?? []), (string)($r['hasta'] ?? ''))
            : [substr($ini, 0, 16)];
        return array_values(array_filter(array_map(fn($f) => strtotime($f) ?: 0, $fechas)));
    }

    /** 'paso' (terminó), 'vivo' (en curso), 'pronto' (en media hora) o 'luego'. */
    public static function estado(int $ts, int $fin, int $ahora): string
    {
        if ($fin <= $ahora) return 'paso';
        if ($ts <= $ahora)  return 'vivo';
        return $ts - $ahora <= self::PRONTO ? 'pronto' : 'luego';
    }

    /** Una vez de una reunión, lista para pintar. */
    public static function vez(array $r, int $ts, array $p, int $ahora): array
    {
        $fin = $ts + self::duracion($r) * 60;
        return [
            'r' => $r, 'id' => (int)($r['id'] ?? 0), 'ts' => $ts, 'fin' => $fin, 'dia' => date('Y-m-d', $ts),
            'estado' => self::estado($ts, $fin, $ahora), 'p' => $p, 'serie' => Reuniones::esRecurrente($r),
        ];
    }

    /**
     * Las próximas (en curso o por venir), de la más cercana a la más lejana:
     * cada vez de los próximos VENTANA_DIAS días y, más allá, solo la siguiente
     * de cada reunión (una daily no llena la lista con sus dos meses).
     * $proyectos: [id => proyecto]; las reuniones de otros proyectos no salen.
     */
    public static function proximas(array $reuniones, array $proyectos, int $ahora): array
    {
        $limite = strtotime(date('Y-m-d', $ahora) . ' 00:00 +' . self::VENTANA_DIAS . ' days');
        $out = [];
        foreach ($reuniones as $r) {
            $p = $proyectos[(int)($r['proyecto_id'] ?? 0)] ?? null;
            if (!$p) continue;
            $dur = self::duracion($r) * 60;
            $futuras = array_values(array_filter(self::inicios($r), fn($t) => $t + $dur > $ahora));
            if (!$futuras) continue;
            $enVentana = array_values(array_filter($futuras, fn($t) => $t < $limite));
            foreach ($enVentana ?: [$futuras[0]] as $t) $out[] = self::vez($r, $t, $p, $ahora);
        }
        usort($out, fn($a, $b) => $a['ts'] <=> $b['ts'] ?: strcasecmp(self::tema($a['r']), self::tema($b['r'])));
        return $out;
    }

    /** Las que ya terminaron (cada día de las que se repiten), la más reciente primero. */
    public static function pasadas(array $reuniones, array $proyectos, int $ahora): array
    {
        $out = [];
        foreach ($reuniones as $r) {
            $p = $proyectos[(int)($r['proyecto_id'] ?? 0)] ?? null;
            if (!$p) continue;
            $dur = self::duracion($r) * 60;
            foreach (self::inicios($r) as $t) {
                if ($t + $dur <= $ahora) $out[] = self::vez($r, $t, $p, $ahora);
            }
        }
        usort($out, fn($a, $b) => $b['ts'] <=> $a['ts'] ?: strcasecmp(self::tema($a['r']), self::tema($b['r'])));
        return $out;
    }

    /** Las veces agrupadas por día ('Y-m-d' => [veces]), en el orden en que llegan. */
    public static function porDia(array $veces): array
    {
        $out = [];
        foreach ($veces as $v) $out[$v['dia']][] = $v;
        return $out;
    }

    /**
     * La vez que se mira en el detalle: la del día pedido si la reunión cae
     * ese día; si no, la que está en curso, la de hoy, la última que pasó o,
     * si todavía no pasó ninguna, la primera. null si no tiene fecha.
     */
    public static function vezDelDia(array $r, string $dia, array $p, int $ahora): ?array
    {
        $inicios = self::inicios($r);
        if (!$inicios) return null;
        $dur = self::duracion($r) * 60;
        $hoy = date('Y-m-d', $ahora);
        $elegido = null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dia)) {
            foreach ($inicios as $t) if (date('Y-m-d', $t) === $dia) { $elegido = $t; break; }
        }
        if ($elegido === null) foreach ($inicios as $t) if ($t <= $ahora && $t + $dur > $ahora) { $elegido = $t; break; }
        if ($elegido === null) foreach ($inicios as $t) if (date('Y-m-d', $t) === $hoy) { $elegido = $t; break; }
        if ($elegido === null) {
            $pasadas = array_filter($inicios, fn($t) => $t + $dur <= $ahora);
            $elegido = $pasadas ? max($pasadas) : $inicios[0];
        }
        return self::vez($r, $elegido, $p, $ahora);
    }

    /* ======================= Lo que dejó cada vez ======================= */

    /**
     * Lo que hace falta para las marcas de cada vez, con una sola pasada por
     * cada colección: grabaciones y transcripciones de Drive (lib/ZoomDrive.php),
     * cuántas observaciones se ligaron y quién pulsó «Entrar» (reunion_ingresos).
     */
    public static function rastros(array $reuniones): array
    {
        $ids = [];
        $zids = [];
        foreach ($reuniones as $r) {
            $ids[(int)($r['id'] ?? 0)] = true;
            if ((string)($r['zoom_id'] ?? '') !== '') $zids[(string)$r['zoom_id']] = true;
        }
        $conDrive = class_exists('ZoomDrive');
        $grab = $conDrive && $zids ? ZoomDrive::deReuniones(array_keys($zids)) : [];
        // Transcripciones: [zoom_id][día] => cuántas partes y cuántas ya están en Drive
        $trans = [];
        if ($conDrive && $zids) {
            foreach ((new JsonStore('zoom_drive'))->all() as $f) {
                $zid = (string)($f['reunion'] ?? '');
                if (!isset($zids[$zid]) || ($f['file_type'] ?? '') !== 'TRANSCRIPT') continue;
                $d = substr((string)($f['inicio'] ?? ''), 0, 10);
                $trans[$zid][$d] ??= ['n' => 0, 'listas' => 0];
                $trans[$zid][$d]['n']++;
                if (in_array($f['estado'] ?? '', self::DRIVE_LISTOS, true) && (string)($f['drive_id'] ?? '') !== '') $trans[$zid][$d]['listas']++;
            }
        }
        // Observaciones que abren hilo: en total y por día (las de una serie se miran por día)
        $obs = [];
        foreach ((new JsonStore('observaciones'))->all() as $o) {
            $rid = (int)($o['reunion_id'] ?? 0);
            if (!$rid || !isset($ids[$rid]) || (int)($o['padre_id'] ?? 0)) continue;
            $d = substr((string)($o['creado'] ?? ''), 0, 10);
            $obs[$rid]['*'] = ($obs[$rid]['*'] ?? 0) + 1;
            $obs[$rid][$d] = ($obs[$rid][$d] ?? 0) + 1;
        }
        $ing = [];
        foreach ((new JsonStore('reunion_ingresos'))->all() as $x) {
            $rid = (int)($x['reunion_id'] ?? 0);
            if (isset($ids[$rid])) $ing[$rid][] = [(int)($x['miembro_id'] ?? 0), strtotime((string)($x['t'] ?? '')) ?: 0];
        }
        return ['grab' => $grab, 'buscando' => $conDrive && ZoomDrive::buscandoTodo(), 'trans' => $trans, 'obs' => $obs, 'ing' => $ing];
    }

    /**
     * La grabación de una vez, con la lógica de siempre de proyecto.php: se
     * abre desde Drive; mientras no llega, «esperando» (hasta dos días, o
     * mientras se revisa todo Drive); sin rastro y de hace días, «sin».
     * $dia: '' en una reunión suelta (la del último día que tenga).
     * ['estado' => 'lista'|'espera'|'sin', 'ver' => enlace de Drive]
     */
    public static function grabacion(array $r, string $dia, int $termino, array $rastros): array
    {
        $porDia = $rastros['grab'][(string)($r['zoom_id'] ?? '')] ?? [];
        $g = $dia !== '' ? ($porDia[$dia] ?? null) : ($porDia ? $porDia[max(array_keys($porDia))] : null);
        if ($g && $g['ver'] !== '') return ['estado' => 'lista', 'ver' => (string)$g['ver']];
        if (($g && $g['pendiente']) || time() - $termino < 2 * 86400 || !empty($rastros['buscando'])) return ['estado' => 'espera', 'ver' => ''];
        return ['estado' => 'sin', 'ver' => ''];
    }

    /** La transcripción de una vez, con las reglas de ZoomDrive::transcripcion: 'lista', 'espera' o 'sin'. */
    public static function transcripcion(array $r, string $dia, int $termino, array $rastros): string
    {
        if (($r['plataforma'] ?? 'zoom') !== 'zoom' || (string)($r['zoom_id'] ?? '') === '') return 'sin';
        $porDia = $rastros['trans'][(string)$r['zoom_id']] ?? [];
        $t = $dia !== '' ? ($porDia[$dia] ?? null) : ($porDia ? $porDia[max(array_keys($porDia))] : null);
        if (!$t) return time() - $termino < 2 * 86400 || !empty($rastros['buscando']) ? 'espera' : 'sin';
        return $t['listas'] >= $t['n'] ? 'lista' : 'espera';
    }

    /** Quiénes pulsaron «Entrar» en esa vez: [id del miembro => hora de su primer ingreso]. */
    public static function entraron(array $vez, array $rastros): array
    {
        $out = [];
        foreach ($rastros['ing'][$vez['id']] ?? [] as [$mid, $t]) {
            if ($mid <= 0 || $t < $vez['ts'] - self::MARGEN_INGRESO || $t > $vez['fin'] + self::MARGEN_INGRESO) continue;
            if (!isset($out[$mid]) || $t < $out[$mid]) $out[$mid] = $t;
        }
        asort($out);
        return $out;
    }

    /** Cuántas observaciones se ligaron a esa vez (en una serie, las anotadas ese día). */
    public static function observaciones(array $vez, array $rastros): int
    {
        $o = $rastros['obs'][$vez['id']] ?? [];
        return (int)($vez['serie'] ? ($o[$vez['dia']] ?? 0) : ($o['*'] ?? 0));
    }

    /* ======================= Fechas y datos para leer ======================= */

    /** ['Hoy', 'jueves 29 oct'], ['Mañana', …], ['Ayer', …] o ['Jueves 5', 'nov'] (Cronograma::diaLargo). */
    public static function diaTitulo(int $ts, int $ahora): array
    {
        $rel = class_exists('Cronograma') ? Cronograma::diaLargo($ts, $ahora) : '';
        $nombre = self::DIAS[(int)date('w', $ts)];
        $mes = self::MESES[(int)date('n', $ts) - 1] . (date('Y', $ts) !== date('Y', $ahora) ? ' ' . date('Y', $ts) : '');
        if (in_array($rel, ['hoy', 'mañana', 'ayer'], true)) return [self::mayuscula($rel), $nombre . ' ' . (int)date('j', $ts) . ' ' . $mes];
        return [self::mayuscula($nombre) . ' ' . (int)date('j', $ts), $mes];
    }

    /** «10:00 am», como el resto del panel (Cronograma::hora). */
    public static function hora(int $ts): string
    {
        return class_exists('Cronograma') ? Cronograma::hora($ts) : date('g:i', $ts) . ' ' . date('a', $ts);
    }

    /** ['10:00', 'am'] */
    public static function horaPartes(int $ts): array
    {
        return array_pad(explode(' ', self::hora($ts), 2), 2, '');
    }

    /** «HOY · 10:00 AM», «JUEVES 5 NOV · 9:00 AM». */
    public static function cuando(int $ts, int $ahora): string
    {
        [$a, $b] = self::diaTitulo($ts, $ahora);
        $dia = in_array(mb_strtolower($a), ['hoy', 'mañana', 'ayer'], true) ? $a : $a . ' ' . $b;
        return mb_strtoupper($dia . ' · ' . self::hora($ts));
    }

    /** «De lunes a viernes, hasta el 30 nov» (vacío si no se repite). */
    public static function repeticion(array $r): string
    {
        if (!Reuniones::esRecurrente($r)) return '';
        $h = strtotime((string)$r['hasta']);
        return Reuniones::diasTexto((array)$r['dias']) . ($h ? ', hasta el ' . (int)date('j', $h) . ' ' . self::MESES[(int)date('n', $h) - 1] : '');
    }

    public static function tema(array $r): string
    {
        return trim((string)($r['topic'] ?? '')) ?: 'Reunión';
    }

    /** El código para entrar a mano: el de Zoom en bloques («123 4567 8901») o el de Meet. */
    public static function codigo(array $r): string
    {
        $plat = (string)($r['plataforma'] ?? 'zoom');
        $url = (string)($r['join_url'] ?? '');
        if ($plat === 'zoom') {
            $d = preg_replace('/\D/', '', (string)($r['zoom_id'] ?? ''));
            if ($d === '' && preg_match('#/j/(\d{9,11})#', $url, $m)) $d = $m[1];
            return match (strlen($d)) {
                11      => substr($d, 0, 3) . ' ' . substr($d, 3, 4) . ' ' . substr($d, 7),
                10      => substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ' . substr($d, 6),
                9       => substr($d, 0, 3) . ' ' . substr($d, 3, 3) . ' ' . substr($d, 6),
                default => $d,
            };
        }
        if ($plat === 'meet' && preg_match('#meet\.google\.com/([a-z]{3,4}-[a-z]{4}-[a-z]{3,4})#i', $url, $m)) return strtolower($m[1]);
        return '';
    }

    /** La etiqueta de la plataforma, con su color. */
    public static function plataforma(array $r): string
    {
        $k = (string)($r['plataforma'] ?? 'zoom');
        $k = in_array($k, ['zoom', 'meet', 'enlace'], true) ? $k : 'zoom';
        return '<span class="ru-plat ru-plat--' . $k . '"' . ($k === 'enlace' ? ' title="Enlace puesto a mano: no queda grabada"' : '') . '>'
            . e(Reuniones::etiquetaPlataforma($k)) . '</span>';
    }

    /** Los invitados que siguen en el equipo (miembros completos). */
    public static function invitados(array $r, array $miembros): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', (array)($r['invitados'] ?? []))) as $mid) {
            if (isset($miembros[$mid])) $out[] = $miembros[$mid];
        }
        return $out;
    }

    /** Minúsculas y sin tildes, para buscar (lo mismo que hace admin.js). */
    public static function normal(string $s): string
    {
        return strtr(mb_strtolower($s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }

    /** Lo que encuentra el buscador en una vez: el tema, el proyecto, la plataforma y la gente. */
    public static function textoBuscar(array $vez, array $miembros): string
    {
        $r = $vez['r'];
        $gente = array_map(fn($m) => (string)$m['nombre'], self::invitados($r, $miembros));
        if (isset($miembros[(int)($r['creador_id'] ?? 0)])) $gente[] = (string)$miembros[(int)$r['creador_id']]['nombre'];
        return self::normal(implode(' ', [self::tema($r), (string)($vez['p']['nombre'] ?? ''),
            Reuniones::etiquetaPlataforma((string)($r['plataforma'] ?? 'zoom')), implode(' ', array_unique($gente))]));
    }

    /* ======================= Enlaces ======================= */

    /** «Entrar» (anota la asistencia y lleva al enlace), si hay a dónde. */
    public static function enlaceEntrar(array $r): string
    {
        return preg_match('#^https?://#i', trim((string)($r['join_url'] ?? ''))) ? 'reunion_entrar.php?id=' . (int)$r['id'] : '';
    }

    /**
     * El detalle de esa vez: reunion.php?id=…&dia=… (el día, solo en las que se
     * repiten). $desde: de dónde se llega ('historial' o 'proyecto'), para que su
     * botón de volver regrese ahí.
     */
    public static function urlDetalle(array $vez, string $desde = ''): string
    {
        return 'reunion.php?id=' . $vez['id'] . ($vez['serie'] ? '&dia=' . $vez['dia'] : '')
            . (in_array($desde, ['historial', 'proyecto'], true) ? '&desde=' . $desde : '');
    }

    /** El color del proyecto, el mismo del menú lateral (los muy oscuros se aclaran). */
    public static function colorProyecto(array $p): string
    {
        $c = ProyectoRepo::colorBase($p);
        return $c === '#2D3E50' ? '#40CFFF' : $c;
    }

    /** El ícono del proyecto, siempre SVG: los viejos que no son del set caen a la carpeta. */
    public static function iconoProyecto(array $p, string $clase = ''): string
    {
        $ico = UI::icono((string)($p['icono'] ?? '') ?: 'FolderOpen', $clase);
        return str_starts_with($ico, '<svg') ? $ico : UI::icono('FolderOpen', $clase);
    }

    /** El proyecto como chip, con su ícono y su color. */
    public static function chipProyecto(array $p, string $href): string
    {
        return '<a class="ru-chip-proy" href="' . e($href) . '" style="--pc:' . e(self::colorProyecto($p)) . '">'
            . '<span class="ru-chip-proy__ic" aria-hidden="true">' . self::iconoProyecto($p) . '</span>' . e((string)($p['nombre'] ?? 'Proyecto')) . '</a>';
    }

    /**
     * El encabezado de la suite, del detalle y de la videoteca: el botón de
     * volver a la izquierda del título y, a la derecha, en la misma fila, los
     * botones redondos de vidrio (en el celular bajan bajo el título).
     * $titulo y $sub van tal cual (HTML ya escapado); $acciones, los botones.
     */
    public static function cabecera(string $volver, string $volverTip, string $titulo, string $sub = '', string $acciones = ''): void
    {
        echo '<header class="page-head ru-cab">'
            . '<div class="ru-cab__main">'
            . ($volver !== '' ? '<a class="rs-boton rs-boton--icono ru-cab__volver" href="' . e($volver) . '" aria-label="' . e($volverTip) . '" data-ds-tip="' . e($volverTip) . '">' . UI::icono('ds-chevron-izq') . '</a>' : '')
            . '<div class="ru-cab__txt"><h1 class="page-title font-display">' . $titulo . '</h1>'
            . ($sub !== '' ? '<p class="page-sub ru-cab__sub">' . $sub . '</p>' : '') . '</div></div>'
            . ($acciones !== '' ? '<div class="page-actions ru-cab__acc">' . $acciones . '</div>' : '')
            . '</header>';
    }

    /** Un botón redondo de vidrio del encabezado (.rs-boton), con su ayuda y su nombre. */
    public static function botonCab(string $icono, string $tip, string $href = '', string $clase = '', string $attrs = '', string $tipo = 'button'): string
    {
        $a = ' class="rs-boton rs-boton--icono' . ($clase !== '' ? ' ' . e($clase) : '') . '" aria-label="' . e($tip) . '" data-ds-tip="' . e($tip) . '"'
           . ($attrs !== '' ? ' ' . $attrs : '');
        return $href !== ''
            ? '<a href="' . e($href) . '"' . $a . '>' . UI::icono($icono) . '</a>'
            : '<button type="' . ($tipo === 'submit' ? 'submit' : 'button') . '"' . $a . '>' . UI::icono($icono) . '</button>';
    }

    /**
     * La transcripción para Claude Code: el formulario de descarga de siempre
     * (reunion_transcripcion, data-descarga; actions.php la lee de Drive). En
     * una serie lleva el día (ocurrencia).
     */
    public static function formTranscripcion(array $r, string $diaOc, string $claseBoton = ''): string
    {
        return '<form method="post" action="actions.php" class="inline-form" data-descarga>'
            . '<input type="hidden" name="accion" value="reunion_transcripcion">'
            . '<input type="hidden" name="id" value="' . (int)$r['id'] . '">'
            . ($diaOc !== '' ? '<input type="hidden" name="ocurrencia" value="' . e($diaOc) . '">' : '')
            . self::ico('ds-descargar', $diaOc !== '' ? 'Descargar el resumen de ese día para Claude Code' : 'Descargar la transcripción para Claude Code', '', $claseBoton, '', 'submit')
            . '</form>';
    }

    /** El acta con IA: los mismos data-* del botón de siempre (los maneja assets/ia.js). */
    public static function botonActa(array $r, string $diaOc, string $claseBoton = ''): string
    {
        $plat = (string)($r['plataforma'] ?? 'zoom');
        $attrs = 'data-ia-acta="1" data-reunion="' . (int)$r['id'] . '"' . ($diaOc !== '' ? ' data-ocurrencia="' . e($diaOc) . '"' : '')
            . ' data-zoom="' . ($plat === 'meet' ? '0' : '1') . '" data-titulo="' . e(self::tema($r) . ($diaOc !== '' ? ' · ' . $diaOc : '')) . '"';
        return self::ico('ds-ia', $diaOc !== '' ? 'Acta de ese día con IA' : 'Acta de la reunión con IA: resumen, acuerdos y tareas', '', trim('ru-ico--ia ' . $claseBoton), $attrs);
    }

    /** El id del archivo de Drive de un enlace «…/file/d/ID/view» ('' si no lo es). */
    public static function driveId(string $url): string
    {
        return preg_match('#/file/d/([\w-]+)#', $url, $m) ? $m[1] : '';
    }

    /** La duración como en un reproductor: «15:00», «1:30:00». */
    public static function duracionTexto(int $min): string
    {
        return $min >= 60 ? intdiv($min, 60) . ':' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT) . ':00' : $min . ':00';
    }

    /** Cuándo fue, corto: «hoy», «ayer», «hace 3 días», «12 oct», «12 oct 2025». */
    public static function cuandoFue(int $ts, int $ahora): string
    {
        $dias = (int)round((strtotime(date('Y-m-d', $ahora)) - strtotime(date('Y-m-d', $ts))) / 86400);
        return match (true) {
            $dias <= 0 => 'hoy',
            $dias === 1 => 'ayer',
            $dias < 7 => 'hace ' . $dias . ' días',
            default => (int)date('j', $ts) . ' ' . self::MESES[(int)date('n', $ts) - 1] . (date('Y', $ts) !== date('Y', $ahora) ? ' ' . date('Y', $ts) : ''),
        };
    }

    /**
     * Las grabaciones: las veces que ya pasaron y tienen su video en Drive, de la
     * más nueva a la más vieja. Cada una suma 'ver' (el enlace de Drive) y 'drive' (su id).
     */
    public static function grabadas(array $reuniones, array $proyectos, int $ahora, array $rastros): array
    {
        $out = [];
        foreach (self::pasadas(array_values(array_filter($reuniones, fn($r) => ($r['plataforma'] ?? 'zoom') === 'zoom' && (string)($r['zoom_id'] ?? '') !== '')), $proyectos, $ahora) as $v) {
            $g = self::grabacion($v['r'], $v['serie'] ? $v['dia'] : '', $v['fin'], $rastros);
            if ($g['estado'] !== 'lista') continue;
            $out[] = $v + ['ver' => $g['ver'], 'drive' => self::driveId($g['ver'])];
        }
        return $out;
    }

    /** Los adjuntos de unas observaciones y de sus respuestas: [['a' => adjunto, 'o' => observación]]. */
    public static function adjuntos(array $hilos): array
    {
        $out = [];
        foreach ($hilos as $o) {
            foreach (array_merge([$o], (array)($o['respuestas'] ?? [])) as $x) {
                foreach ((array)($x['adjuntos'] ?? []) as $a) {
                    if (is_array($a) && (string)($a['ruta'] ?? '') !== '') $out[] = ['a' => $a, 'o' => $x];
                }
            }
        }
        return $out;
    }

    /**
     * La portada 16:9 de una grabación: la generada (el color y el ícono del
     * proyecto, con el tema en grande) siempre está debajo; encima, la miniatura
     * de Drive, que se quita sola si no carga (sin permiso, o todavía sin
     * miniatura): así la portada nunca queda vacía.
     */
    public static function portada(array $vez, string $drive, string $extra = '', bool $conMiniatura = true): string
    {
        $p = $vez['p'];
        return '<span class="vt-portada" style="--pc:' . e(self::colorProyecto($p)) . '">'
            . '<span class="vt-portada__gen" aria-hidden="true"><span class="vt-portada__ic">' . self::iconoProyecto($p) . '</span>'
            . '<b class="vt-portada__t">' . e(self::tema($vez['r'])) . '</b><small class="vt-portada__p">' . e((string)($p['nombre'] ?? '')) . '</small></span>'
            . ($conMiniatura && $drive !== '' ? '<img class="vt-portada__img" src="https://drive.google.com/thumbnail?id=' . rawurlencode($drive) . '&amp;sz=w640" alt="" loading="lazy" decoding="async" onerror="this.remove()">' : '')
            . $extra . '</span>';
    }

    /**
     * Una tarjeta de video (la videoteca): la portada con su duración, el
     * proyecto como canal, el título en dos líneas y «Proyecto · fecha · N asistentes».
     * $ctx: ahora, miembros, rastros.
     */
    public static function tarjetaVideo(array $vez, array $ctx, string $href, string $id = ''): string
    {
        $r = $vez['r'];
        $p = $vez['p'];
        $nEnt = count(self::entraron($vez, $ctx['rastros']));
        $conTrans = self::transcripcion($r, $vez['serie'] ? $vez['dia'] : '', $vez['fin'], $ctx['rastros']) === 'lista';
        $extra = '<span class="vt-dur">' . self::duracionTexto(self::duracion($r)) . '</span>'
            . ($conTrans ? '<span class="vt-cc" role="img" aria-label="Con transcripción" data-ds-tip="Con transcripción">' . UI::icono('NoteText') . '</span>' : '');
        $meta = e((string)($p['nombre'] ?? '')) . '<span class="ru-sep" aria-hidden="true">·</span>' . e(self::cuandoFue($vez['ts'], $ctx['ahora']))
            . ($nEnt ? '<span class="ru-sep" aria-hidden="true">·</span>' . ($nEnt === 1 ? '1 asistente' : $nEnt . ' asistentes') : '');
        return '<li class="vt-item" data-ru-txt="' . e(self::textoBuscar($vez, $ctx['miembros'])) . '"' . ($id !== '' ? ' id="' . e($id) . '"' : '') . '>'
            . '<a class="vt-card" href="' . e($href) . '">'
            . self::portada($vez, (string)($vez['drive'] ?? ''), $extra)
            . '<span class="vt-info"><span class="vt-canal" style="--pc:' . e(self::colorProyecto($p)) . '" aria-hidden="true">' . self::iconoProyecto($p) . '</span>'
            . '<span class="vt-info__txt"><b class="vt-t" title="' . e(self::tema($r)) . '">' . e(self::tema($r)) . '</b>'
            . '<small class="vt-meta">' . $meta . '</small></span></span>'
            . '</a></li>';
    }

    /** El ícono de un adjunto según su extensión (iconos/Archivos/adj-*). */
    public static function iconoAdjunto(string $ext): string
    {
        return UI::icono(match (strtolower($ext)) {
            'pdf'                                        => 'adj-pdf',
            'doc', 'docx'                                => 'adj-word',
            'xls', 'xlsx', 'csv'                         => 'adj-excel',
            'ppt', 'pptx'                                => 'adj-ppt',
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'  => 'adj-imagen',
            default                                      => 'adj-otro',
        });
    }

    public static function hayPizarra(): bool
    {
        return is_file(__DIR__ . '/../pizarra.php');
    }

    /** ¿Están Zoom o Meet? Sin ninguno, proyecto.php no trae los asistentes de crear y editar. */
    public static function plataformasListas(): bool
    {
        return Zoom::listo() || GoogleCalendar::listo();
    }

    /** Los datos que lee el asistente de editar (admin.js, .js-editar-reunion), ya escapados para un atributo. */
    public static function datosEditar(array $r): string
    {
        return htmlspecialchars(json_encode([
            'id'         => (int)$r['id'],
            'topic'      => (string)($r['topic'] ?? ''),
            'inicio'     => str_replace(' ', 'T', (string)($r['inicio'] ?? '')),
            'duracion'   => (int)($r['duracion'] ?? 60),
            'invitados'  => array_map('intval', (array)($r['invitados'] ?? [])),
            'recurrente' => Reuniones::esRecurrente($r),
            'dias'       => array_map('intval', (array)($r['dias'] ?? [])),
            'hasta'      => (string)($r['hasta'] ?? ''),
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES);
    }

    /* ======================= Piezas ======================= */

    /**
     * Un botón redondo de solo ícono, con su ayuda (data-ds-tip) y su nombre
     * para lectores de pantalla. Con $href es un enlace; si no, un botón.
     */
    public static function ico(string $icono, string $tip, string $href = '', string $clase = '', string $attrs = '', string $tipo = 'button'): string
    {
        $a = ' class="ru-ico' . ($clase !== '' ? ' ' . e($clase) : '') . '" aria-label="' . e($tip) . '" data-ds-tip="' . e($tip) . '"'
           . ($attrs !== '' ? ' ' . $attrs : '');
        return $href !== ''
            ? '<a href="' . e($href) . '"' . $a . '>' . UI::icono($icono) . '</a>'
            : '<button type="' . ($tipo === 'submit' ? 'submit' : 'button') . '"' . $a . '>' . UI::icono($icono) . '</button>';
    }

    /** Copiar el enlace (lo copia admin.js, .btn-copiar). */
    public static function icoCopiar(array $r, string $clase = ''): string
    {
        $url = trim((string)($r['join_url'] ?? ''));
        return $url === '' ? '' : self::ico('ds-copiar', 'Copiar el enlace de la reunión', '', trim('btn-copiar ' . $clase), 'data-copiar="' . e($url) . '"');
    }

    /** Entrar: el principal, verde. */
    public static function icoEntrar(array $vez, string $clase = ''): string
    {
        $url = self::enlaceEntrar($vez['r']);
        if ($url === '') return '';
        $tip = $vez['estado'] === 'vivo' ? 'Entrar: está en curso' : 'Entrar a la reunión (queda anotado para la asistencia)';
        return self::ico('ds-video', $tip, $url, trim('ru-ico--entrar' . ($vez['estado'] === 'vivo' ? ' is-ya' : '') . ' ' . $clase), 'target="_blank" rel="noopener"');
    }

    /** Los botones de una vez próxima: Entrar (solo si toca), la pizarra, copiar el enlace y el detalle. */
    public static function acciones(array $vez, bool $conEntrar = true, string $desde = ''): string
    {
        $h = '';
        if ($conEntrar && in_array($vez['estado'], ['vivo', 'pronto'], true)) $h .= self::icoEntrar($vez);
        if (self::hayPizarra()) $h .= self::ico('pz-pizarra', 'Pizarra de la reunión', 'pizarra.php?reunion=' . $vez['id']);
        $h .= self::icoCopiar($vez['r']);
        $h .= self::ico('ds-ver', 'Ver el detalle', self::urlDetalle($vez, $desde));
        return '<div class="ru-acc">' . $h . '</div>';
    }

    /** El renglón de abajo del título: la plataforma, el proyecto, cuánto dura y si se repite. */
    private static function meta(array $vez, bool $conProyecto): string
    {
        $r = $vez['r'];
        $partes = [self::plataforma($r)];
        if ($conProyecto) $partes[] = '<span>' . e((string)($vez['p']['nombre'] ?? '')) . '</span>';
        $partes[] = '<span>' . self::duracion($r) . ' min</span>';
        if ($vez['serie']) $partes[] = '<span class="ru-repite">' . UI::icono('RepeatCircle') . e(Reuniones::diasTexto((array)$r['dias'])) . '</span>';
        return implode('<span class="ru-sep" aria-hidden="true">·</span>', $partes);
    }

    /** Una fila de las próximas. $ctx: ahora, miembros, proyecto (¿se muestra?). */
    public static function fila(array $vez, array $ctx): string
    {
        $r = $vez['r'];
        [$hh, $ap] = self::horaPartes($vez['ts']);
        $inv = self::invitados($r, $ctx['miembros']);
        $estado = match ($vez['estado']) {
            'vivo'   => '<span class="ru-tag ru-tag--vivo"><span class="rs-punto-vivo" aria-hidden="true"></span>En curso</span>',
            'pronto' => '<span class="ru-tag ru-tag--pronto"><b data-cuenta-regresiva data-corto data-ya="ya empezó" data-hasta="' . $vez['ts'] . '" data-ahora="' . $ctx['ahora'] . '">&nbsp;</b></span>',
            default  => '',
        };
        return '<li class="ru-fila is-' . $vez['estado'] . '" data-ru-txt="' . e(self::textoBuscar($vez, $ctx['miembros'])) . '">'
            . '<span class="ru-hora" aria-hidden="true"><b>' . e($hh) . '</b><small>' . e($ap) . '</small></span>'
            . '<span class="ru-fila__txt"><a class="ru-fila__t" href="' . e(self::urlDetalle($vez, (string)($ctx['desde'] ?? ''))) . '" title="' . e(self::tema($r)) . '">'
            . '<span class="ru-sr">' . e(self::hora($vez['ts'])) . ' · </span>' . e(self::tema($r)) . '</a>'
            . '<small class="ru-fila__meta">' . self::meta($vez, !empty($ctx['proyecto'])) . '</small></span>'
            . $estado
            . ($inv ? '<span class="ru-gente">' . UI::avatarStack($inv, 4, 28) . '</span>' : '')
            . self::acciones($vez, true, (string)($ctx['desde'] ?? ''))
            . '</li>';
    }

    /** Una marca chica de lo que dejó una vez (grabación, transcripción, observaciones, asistencia). */
    private static function marca(string $icono, string $tip, string $tono, int $n = 0): string
    {
        return '<span class="ru-marca ru-marca--' . $tono . '" role="img" aria-label="' . e($tip) . '" data-ds-tip="' . e($tip) . '"'
            . ($n ? ' data-n="' . ($n > 99 ? '99+' : $n) . '"' : '') . '>' . UI::icono($icono) . '</span>';
    }

    /** Las marcas de una vez que ya pasó. */
    public static function marcas(array $vez, array $rastros): string
    {
        $r = $vez['r'];
        $dia = $vez['serie'] ? $vez['dia'] : '';
        $h = '';
        if (($r['plataforma'] ?? 'zoom') === 'zoom') {
            $g = self::grabacion($r, $dia, $vez['fin'], $rastros);
            // Solo lo que sí hay: «esperando» se explica en el detalle (en cada fila era ruido)
            if ($g['estado'] === 'lista') $h .= self::marca('MonitorPlay', 'Con grabación', 'grab');
            if (self::transcripcion($r, $dia, $vez['fin'], $rastros) === 'lista') $h .= self::marca('NoteText', 'Con transcripción', 'trans');
        }
        $nObs = self::observaciones($vez, $rastros);
        if ($nObs) $h .= self::marca('ds-mensajes', $nObs === 1 ? '1 observación' : $nObs . ' observaciones', 'obs', $nObs);
        $nEnt = count(self::entraron($vez, $rastros));
        if ($nEnt) $h .= self::marca('Users', ($nEnt === 1 ? '1 persona entró' : $nEnt . ' personas entraron') . ' desde el panel', 'gente', $nEnt);
        return $h;
    }

    /** Una fila del historial: toda la fila lleva al detalle de ese día. $ctx: miembros, proyecto, rastros. */
    public static function filaPasada(array $vez, array $ctx): string
    {
        $r = $vez['r'];
        [$hh, $ap] = self::horaPartes($vez['ts']);
        $marcas = self::marcas($vez, $ctx['rastros']);
        return '<li data-ru-txt="' . e(self::textoBuscar($vez, $ctx['miembros'])) . '"><a class="ru-fila ru-fila--pasada" href="' . e(self::urlDetalle($vez, (string)($ctx['desde'] ?? ''))) . '">'
            . '<span class="ru-hora" aria-hidden="true"><b>' . e($hh) . '</b><small>' . e($ap) . '</small></span>'
            . '<span class="ru-fila__txt"><b class="ru-fila__t" title="' . e(self::tema($r)) . '"><span class="ru-sr">' . e(self::hora($vez['ts'])) . ' · </span>' . e(self::tema($r)) . '</b>'
            . '<small class="ru-fila__meta">' . self::meta($vez, !empty($ctx['proyecto'])) . '</small></span>'
            . ($marcas !== '' ? '<span class="ru-marcas">' . $marcas . '</span>' : '')
            . UI::icono('ds-chevron-der', 'ru-fila__ir')
            . '</a></li>';
    }

    /**
     * Las veces agrupadas por día, con su título grande («HOY», «JUEVES 5»).
     * $pasadas: filas del historial (si no, de las próximas).
     */
    public static function dias(array $veces, array $ctx, bool $pasadas = false): string
    {
        $h = '';
        $hoy = date('Y-m-d', $ctx['ahora']);
        $tag = ($ctx['h'] ?? 'h3') === 'h2' ? 'h2' : 'h3';   // en la suite, bajo el título de la página; en el proyecto, bajo «Reuniones»
        foreach (self::porDia($veces) as $dia => $lista) {
            [$a, $b] = self::diaTitulo((int)strtotime($dia . ' 12:00'), $ctx['ahora']);
            $n = count($lista);
            $h .= '<section class="ru-dia' . ($dia === $hoy ? ' is-hoy' : '') . '" data-ru-dia>'
                . '<' . $tag . ' class="ru-dia__t"><b>' . e($a) . '</b><span>' . e($b) . '</span>'
                . '<small class="ru-dia__n" aria-label="' . ($n === 1 ? '1 reunión' : $n . ' reuniones') . '">' . $n . '</small></' . $tag . '>'
                . '<ul class="ru-filas">';
            foreach ($lista as $v) $h .= $pasadas ? self::filaPasada($v, $ctx) : self::fila($v, $ctx);
            $h .= '</ul></section>';
        }
        return $h;
    }

    /**
     * Lo que está en curso o empieza pronto: arriba y destacado, grande, con
     * su cuenta regresiva y «Entrar» (el borde de vidrio que gira es el de la
     * reunión destacada del Resumen, .rs-reu).
     */
    public static function ahora(array $veces, array $ctx): string
    {
        if (!$veces) return '';
        $h = '';
        foreach ($veces as $vez) {
            $r = $vez['r'];
            $vivo = $vez['estado'] === 'vivo';
            $inv = self::invitados($r, $ctx['miembros']);
            $h .= '<article class="rs-reu rs-reu--' . $vez['estado'] . ' ru-ahora__item" data-ru-txt="' . e(self::textoBuscar($vez, $ctx['miembros'])) . '">'
                . '<div class="rs-reu__top"><span class="rs-reu__eti">' . ($vivo ? '<span class="rs-punto-vivo" aria-hidden="true"></span>En curso' : 'Empieza pronto') . '</span>'
                . '<span class="rs-cuenta">' . UI::icono('ds-reloj') . ' ' . ($vivo ? 'termina' : 'empieza')
                . ' <b data-cuenta-regresiva data-corto data-ya="' . ($vivo ? 'ya terminó' : 'ya empezó') . '" data-hasta="' . ($vivo ? $vez['fin'] : $vez['ts']) . '" data-ahora="' . $ctx['ahora'] . '">&nbsp;</b></span></div>'
                . '<b class="rs-reu__cuando">' . e(self::cuando($vez['ts'], $ctx['ahora'])) . '</b>'
                . '<p class="rs-reu__t"><a class="ru-ahora__t" href="' . e(self::urlDetalle($vez, (string)($ctx['desde'] ?? ''))) . '">' . e(self::tema($r)) . '</a></p>'
                . '<p class="rs-reu__meta ru-fila__meta">' . self::meta($vez, !empty($ctx['proyecto'])) . '</p>'
                . '<div class="rs-reu__pie">' . ($inv ? UI::avatarStack($inv, 5, 30) : '<span></span>')
                . '<div class="ru-acc">'
                . (self::hayPizarra() ? self::ico('pz-pizarra', 'Pizarra de la reunión', 'pizarra.php?reunion=' . $vez['id']) : '')
                . self::icoCopiar($r)
                . self::ico('ds-ver', 'Ver el detalle', self::urlDetalle($vez, (string)($ctx['desde'] ?? '')))
                . self::icoEntrar($vez, 'ru-ico--grande')
                . '</div></div></article>';
        }
        return '<div class="ru-ahora" data-ru-dia>' . $h . '</div>';
    }

    /**
     * Las próximas completas: lo de ahora arriba y el resto por día.
     * Devuelve '' si no hay ninguna (quien llama pinta su vacío).
     */
    public static function proximasHtml(array $veces, array $ctx): string
    {
        $ya = array_values(array_filter($veces, fn($v) => in_array($v['estado'], ['vivo', 'pronto'], true)));
        $resto = array_values(array_filter($veces, fn($v) => !in_array($v['estado'], ['vivo', 'pronto'], true)));
        return self::ahora($ya, $ctx) . self::dias($resto, $ctx);
    }

    /** Un vacío corto, de una línea con su ícono (el del Resumen): sin el hueco de una ilustración. */
    public static function vacio(string $icono, string $titulo, string $texto, string $extra = ''): string
    {
        return '<div class="rs-vacio ru-vacio"><span class="rs-vacio__ic" aria-hidden="true">' . UI::icono($icono) . '</span>'
            . '<p><b>' . e($titulo) . '</b><small>' . e($texto) . '</small></p>' . $extra . '</div>';
    }

    private static function mayuscula(string $s): string
    {
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
