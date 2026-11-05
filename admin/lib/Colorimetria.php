<?php
/**
 * Colorimetría de gestión de proyectos (colorimetria.php), según la guía de la
 * PMO: cada proyecto del panel y cada equipo de Releases tiene un estado con
 * su color, su ícono y su texto (el color nunca va solo).
 *
 *  - Salud: Cumple, Alerta o Crítico, por un puntaje de 0 a 100 que pesa sus
 *    indicadores (Cumple 100 puntos, Alerta 50, Crítico 0). Si varios
 *    indicadores clave están en Crítico, el proyecto escala.
 *  - Ciclo de vida: Sin iniciar, Pausado o Finalizado (no se puntúan).
 *  - Sin medición: el indicador que no tiene dato no cuenta en el puntaje.
 *
 * Los indicadores que el panel ya sabe se calculan solos (cronograma, entregas
 * a tiempo, avance del release, predictibilidad, tiempo de ciclo, impedimentos,
 * defectos y variación de velocidad); los de la PMO se anotan cada mes
 * (col_mediciones). Un valor anotado manda sobre el calculado. Umbrales, pesos,
 * nombres y colores se cambian en la pestaña Configuración (Config 'colorimetria').
 */
final class Colorimetria
{
    /** Paleta validada (contraste AA): [fondo, texto, color pleno, zona de gráfico], tema claro y oscuro. */
    public const PAL = [
        'verde'    => ['l' => ['#E3F4EA', '#0B6B3A', '#168A4A', '#BDE8CF'], 'd' => ['#123B2A', '#7FE0A8', '#34C77B', '#1F5A3E']],
        'ambar'    => ['l' => ['#FFF1D6', '#8A5300', '#C27C00', '#FFDDA0'], 'd' => ['#45330A', '#FFC857', '#F5B301', '#6B4F12']],
        'rojo'     => ['l' => ['#FDE7E5', '#B3261E', '#D93025', '#F9B8B3'], 'd' => ['#4A1714', '#FF9C94', '#F0524A', '#6F2A26']],
        'azul'     => ['l' => ['#E1EFFF', '#0A58B8', '#1A78E0', '#B9D8FA'], 'd' => ['#0F3560', '#8CC4FF', '#3399FF', '#1D4E86']],
        'gris'     => ['l' => ['#EAECF0', '#475467', '#76839A', '#E3E7ED'], 'd' => ['#263449', '#B6C2D3', '#8794A8', '#22304A']],
        'violeta'  => ['l' => ['#EDE7FA', '#5A2EB0', '#7A4FD6', '#D3C5F4'], 'd' => ['#2E2154', '#CDB9FF', '#A082F0', '#46347A']],
        'turquesa' => ['l' => ['#DDF4F4', '#0A6469', '#0E8C93', '#B4E6E8'], 'd' => ['#0F3A3D', '#7FE3E8', '#2BC1C9', '#1C5A5E']],
    ];
    public const PAL_NOM = ['verde' => 'Verde', 'ambar' => 'Ámbar', 'rojo' => 'Rojo', 'azul' => 'Azul', 'gris' => 'Gris', 'violeta' => 'Violeta', 'turquesa' => 'Turquesa'];

    /** Estados: [tipo, nombre, color, cuándo se usa]. */
    public const ESTADOS = [
        'ok'   => ['Salud', 'Cumple', 'verde', 'Puntaje del proyecto en la banda alta, o indicador dentro de la meta.'],
        'warn' => ['Salud', 'Alerta', 'ambar', 'Cerca de la meta. Requiere seguimiento.'],
        'crit' => ['Salud', 'Crítico', 'rojo', 'Lejos de la meta. Requiere acción.'],
        'none' => ['Ciclo de vida', 'Sin iniciar', 'gris', 'Proyecto planificado que todavía no empieza.'],
        'hold' => ['Ciclo de vida', 'Pausado', 'violeta', 'Proyecto detenido temporalmente. Conserva su último puntaje.'],
        'done' => ['Ciclo de vida', 'Finalizado', 'azul', 'Proyecto cerrado y aceptado.'],
        'nm'   => ['Indicador', 'Sin medición', 'gris', 'Indicador sin dato para calcular.'],
    ];
    /** Orden de gravedad (para ordenar la tabla: lo crítico arriba). */
    public const SEV = ['crit' => 5, 'warn' => 4, 'ok' => 3, 'hold' => 2, 'done' => 1, 'none' => 0, 'nm' => 0];

    public const DIMS = ['Cronograma', 'Costo', 'Alcance y entrega', 'Calidad', 'Riesgos y bloqueos', 'Cliente', 'Equipo'];

    /**
     * Catálogo de indicadores. m: marco (PMO o Scrum). dir: up (más es mejor) o
     * down (menos es mejor). g: umbral de Cumple. a: umbral de Alerta. w: peso.
     * min/max: el rango del gráfico de bala. auto: lo calcula el panel (p:
     * proyectos, e: equipos de Releases); los demás se anotan cada mes y salen
     * apagados de fábrica (se prenden en Configuración si la PMO los va a anotar).
     */
    public const IND = [
        'spi'     => ['n' => 'Índice de cronograma (SPI)', 'dim' => 'Cronograma', 'm' => 'PMO', 'dir' => 'up', 'u' => '', 'd' => 2, 'g' => 0.95, 'a' => 0.85, 'w' => 10, 'clave' => true, 'min' => 0.6, 'max' => 1.2, 'auto' => 'pe'],
        'hitos'   => ['n' => 'Hitos cumplidos a tiempo', 'dim' => 'Cronograma', 'm' => 'PMO', 'dir' => 'up', 'u' => '%', 'd' => 0, 'g' => 90, 'a' => 80, 'w' => 5, 'min' => 50, 'max' => 100, 'auto' => 'pe'],
        'cpi'     => ['n' => 'Índice de costo (CPI)', 'dim' => 'Costo', 'm' => 'PMO', 'dir' => 'up', 'u' => '', 'd' => 2, 'g' => 0.95, 'a' => 0.85, 'w' => 10, 'clave' => true, 'min' => 0.6, 'max' => 1.2],
        'desv'    => ['n' => 'Desviación de presupuesto al cierre', 'dim' => 'Costo', 'm' => 'PMO', 'dir' => 'down', 'u' => '%', 'd' => 1, 'g' => 5, 'a' => 10, 'w' => 5, 'min' => 0, 'max' => 20],
        'margen'  => ['n' => 'Margen de utilidad', 'dim' => 'Costo', 'm' => 'PMO', 'dir' => 'up', 'u' => '%', 'd' => 1, 'g' => 25, 'a' => 15, 'w' => 6, 'min' => 0, 'max' => 40],
        'alcance' => ['n' => 'Variación de alcance', 'dim' => 'Alcance y entrega', 'm' => 'PMO', 'dir' => 'down', 'u' => '%', 'd' => 0, 'g' => 10, 'a' => 20, 'w' => 5, 'min' => 0, 'max' => 30, 'auto' => 'pe'],
        'hist'    => ['n' => 'Avance del release', 'dim' => 'Alcance y entrega', 'm' => 'Scrum', 'dir' => 'up', 'u' => '%', 'd' => 1, 'g' => 95, 'a' => 85, 'w' => 7, 'clave' => true, 'min' => 50, 'max' => 100, 'auto' => 'pe'],
        'pred'    => ['n' => 'Predictibilidad del sprint', 'dim' => 'Alcance y entrega', 'm' => 'Scrum', 'dir' => 'up', 'u' => '%', 'd' => 0, 'g' => 85, 'a' => 70, 'w' => 7, 'min' => 40, 'max' => 100, 'auto' => 'pe'],
        'ciclo'   => ['n' => 'Tiempo de ciclo promedio', 'dim' => 'Alcance y entrega', 'm' => 'Scrum', 'dir' => 'down', 'u' => 'días', 'd' => 1, 'g' => 5, 'a' => 8, 'w' => 3, 'min' => 0, 'max' => 12, 'auto' => 'pe'],
        'defec'   => ['n' => 'Defectos críticos abiertos', 'dim' => 'Calidad', 'm' => 'Scrum', 'dir' => 'down', 'u' => '', 'd' => 0, 'int' => true, 'g' => 0, 'a' => 2, 'w' => 8, 'clave' => true, 'min' => 0, 'max' => 6, 'auto' => 'pe'],
        'cob'     => ['n' => 'Cobertura de pruebas automatizadas', 'dim' => 'Calidad', 'm' => 'Scrum', 'dir' => 'up', 'u' => '%', 'd' => 0, 'g' => 80, 'a' => 60, 'w' => 4, 'min' => 30, 'max' => 100],
        'dod'     => ['n' => 'Historias que cumplen la Definición de Hecho', 'dim' => 'Calidad', 'm' => 'Scrum', 'dir' => 'up', 'u' => '%', 'd' => 0, 'g' => 95, 'a' => 85, 'w' => 4, 'min' => 50, 'max' => 100, 'auto' => 'pe'],
        'riesgos' => ['n' => 'Riesgos críticos abiertos', 'dim' => 'Riesgos y bloqueos', 'm' => 'PMO', 'dir' => 'down', 'u' => '', 'd' => 0, 'int' => true, 'g' => 0, 'a' => 2, 'w' => 8, 'clave' => true, 'min' => 0, 'max' => 6, 'auto' => 'pe'],
        'imped'   => ['n' => 'Impedimentos abiertos', 'dim' => 'Riesgos y bloqueos', 'm' => 'Scrum', 'dir' => 'down', 'u' => '', 'd' => 0, 'int' => true, 'g' => 1, 'a' => 3, 'w' => 3, 'min' => 0, 'max' => 6, 'auto' => 'pe'],
        'sat'     => ['n' => 'Satisfacción del cliente', 'dim' => 'Cliente', 'm' => 'PMO', 'dir' => 'up', 'u' => 'de 5', 'd' => 1, 'g' => 4.5, 'a' => 4.0, 'w' => 6, 'min' => 3, 'max' => 5],
        'acep'    => ['n' => 'Entregables aceptados a la primera', 'dim' => 'Cliente', 'm' => 'PMO', 'dir' => 'up', 'u' => '%', 'd' => 0, 'g' => 90, 'a' => 75, 'w' => 3, 'min' => 50, 'max' => 100, 'auto' => 'pe'],
        'salud'   => ['n' => 'Salud del equipo', 'dim' => 'Equipo', 'm' => 'Scrum', 'dir' => 'up', 'u' => 'de 5', 'd' => 1, 'g' => 4.0, 'a' => 3.5, 'w' => 3, 'min' => 1, 'max' => 5],
        'vel'     => ['n' => 'Variación de velocidad', 'dim' => 'Equipo', 'm' => 'Scrum', 'dir' => 'down', 'u' => '%', 'd' => 0, 'g' => 15, 'a' => 30, 'w' => 3, 'min' => 0, 'max' => 50, 'auto' => 'pe'],
    ];

    /** Cómo lo calcula el panel, para la guía y el detalle (por tipo: p proyecto, e equipo). */
    public const COMO = [
        'spi'   => ['p' => 'Tareas terminadas sobre las que el plan esperaba a hoy (por su fecha límite).', 'e' => 'Avance real de sus ítems sobre el esperado a hoy.'],
        'hitos' => ['p' => 'Tareas que vencieron y se terminaron a tiempo.', 'e' => 'Ítems cuyo fin ya pasó y están terminados.'],
        'hist'  => ['p' => 'Avance de sus ítems de Releases sobre el esperado.', 'e' => 'Avance del release en curso sobre el esperado.'],
        'pred'  => ['p' => 'De lo que vencía en las últimas dos semanas, lo que se terminó a tiempo.', 'e' => 'Cumplimiento de sus revisiones de las últimas cuatro semanas.'],
        'ciclo' => ['p' => 'Días entre empezar y terminar las tareas de los últimos 30 días.', 'e' => 'Igual, con las tareas de sus proyectos.'],
        'alcance' => ['p' => 'Tareas nuevas de los últimos 30 días sobre las que ya había.', 'e' => 'Ítems del release en curso agregados en el panel sobre los del Excel.'],
        'defec' => ['p' => 'Observaciones de QA pendientes en tareas abiertas de prioridad alta.', 'e' => 'Igual, en las tareas de sus proyectos.'],
        'dod'   => ['p' => 'De lo terminado en los últimos 30 días, lo que no tiene observaciones de QA pendientes.', 'e' => 'Igual, con las tareas de sus proyectos.'],
        'riesgos' => ['p' => 'Tareas abiertas de prioridad alta con la fecha límite vencida.', 'e' => 'Igual, con las tareas de sus proyectos.'],
        'acep'  => ['p' => 'Sus ítems que pasaron la primera revisión de cierre; sin revisiones, las tareas terminadas que no se volvieron a abrir.', 'e' => 'Ítems que pasaron su primera revisión de cierre; sin revisiones, las tareas que no se volvieron a abrir.'],
        'imped' => ['p' => 'Tareas abiertas que esperan una dependencia sin terminar.', 'e' => 'Ítems bloqueados.'],
        'vel'   => ['p' => 'Cuánto cambian las tareas terminadas por semana (últimas cuatro).', 'e' => 'Igual, con las tareas de sus proyectos.'],
    ];

    public const FASES = ['' => 'Según el panel', 'run' => 'En curso', 'none' => 'Sin iniciar', 'hold' => 'Pausado', 'done' => 'Finalizado'];

    /** La salud de Releases (al_dia, riesgo, atrasado…) en el estado de la colorimetría. */
    public const DE_SALUD = ['al_dia' => 'ok', 'riesgo' => 'warn', 'atrasado' => 'crit', 'vencido' => 'crit', 'bloqueado' => 'crit',
                             'futuro' => 'none', 'cerrado' => 'done', 'vacio' => 'nm', 'nm' => 'nm'];

    public static function deSalud(string $salud): string { return self::DE_SALUD[$salud] ?? 'nm'; }

    /** Los íconos de estado del Design System Innotech 2.0 (iconos/DS/ds-estado-*.svg). */
    public const ICONOS = ['ok' => 'estado-cumple', 'warn' => 'estado-alerta', 'crit' => 'estado-critico', 'done' => 'estado-finalizado',
                           'hold' => 'estado-pausado', 'none' => 'estado-sin-iniciar', 'nm' => 'estado-sin-medicion'];

    /** El ícono de cada estado: el del Design System; si falta el archivo, uno dibujado aquí. */
    public static function icono(string $k, int $px = 14): string
    {
        $n = self::ICONOS[$k] ?? 'estado-sin-medicion';
        if (class_exists('UI') && method_exists('UI', 'hayIcono') && UI::hayIcono('ds-' . $n)) return UI::iconoDS($n, $px, 'col-ic');
        $c = '<svg class="col-ic" width="' . $px . '" height="' . $px . '" viewBox="0 0 16 16" aria-hidden="true">';
        return $c . match ($k) {
            'ok'   => '<circle fill="currentColor" cx="8" cy="8" r="7"/><path d="M4.8 8.2l2.2 2.2 4.2-4.6" stroke="var(--col-bg, #fff)" stroke-width="1.9" fill="none" stroke-linecap="round" stroke-linejoin="round"/>',
            'warn' => '<path fill="currentColor" d="M8 1.6l6.8 11.8H1.2z"/><path d="M8 6v3.4M8 11.4v.1" stroke="var(--col-bg, #fff)" stroke-width="1.9" fill="none" stroke-linecap="round"/>',
            'crit' => '<rect fill="currentColor" x="1.5" y="1.5" width="13" height="13" rx="3.5"/><path d="M5.4 5.4l5.2 5.2M10.6 5.4l-5.2 5.2" stroke="var(--col-bg, #fff)" stroke-width="1.9" fill="none" stroke-linecap="round"/>',
            'done' => '<path d="M4 14.5V2M4 3h8.2l-2.1 3 2.1 3H4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>',
            'hold' => '<path d="M5.6 3v10M10.4 3v10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
            default => '<circle cx="8" cy="8" r="6.2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5.2 8h5.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>',
        } . '</svg>';
    }

    /** El estado como en el Design System: ícono y texto del color del estado, sin fondo. */
    public static function estado(string $k, ?string $texto = null, string $extra = ''): string
    {
        $k = isset(self::ESTADOS[$k]) ? $k : 'nm';
        return '<span class="col-est col-s-' . $k . ($extra !== '' ? ' ' . $extra : '') . '">' . self::icono($k) . '<span>' . e($texto ?? self::nombre($k)) . '</span></span>';
    }

    /* ============================== Configuración ============================== */

    /** La configuración vigente: la de fábrica con lo que se cambió en la pestaña Configuración. */
    public static function conf(): array
    {
        static $c = null;
        if ($c !== null) return $c;
        $c = ['estados' => [], 'puntos' => ['ok' => 100, 'warn' => 50, 'crit' => 0], 'bandas' => ['ok' => 80, 'warn' => 55], 'escala' => ['on' => true, 'min' => 2], 'ind' => []];
        foreach (self::ESTADOS as $k => [, $nombre, $color]) $c['estados'][$k] = ['nombre' => $nombre, 'color' => $color];
        foreach (self::IND as $id => $i) $c['ind'][$id] = ['act' => !empty($i['auto']), 'g' => $i['g'], 'a' => $i['a'], 'w' => $i['w'], 'clave' => !empty($i['clave'])];
        $o = (array)(Config::get('colorimetria') ?? []);
        foreach (self::ESTADOS as $k => $_) {
            $e = (array)($o['estados'][$k] ?? []);
            if (trim((string)($e['nombre'] ?? '')) !== '') $c['estados'][$k]['nombre'] = mb_substr(trim((string)$e['nombre']), 0, 24);
            if (isset(self::PAL[$e['color'] ?? ''])) $c['estados'][$k]['color'] = $e['color'];
        }
        foreach (['ok', 'warn', 'crit'] as $k) if (is_numeric($o['puntos'][$k] ?? null)) $c['puntos'][$k] = (float)$o['puntos'][$k];
        foreach (['ok', 'warn'] as $k) if (is_numeric($o['bandas'][$k] ?? null)) $c['bandas'][$k] = (float)$o['bandas'][$k];
        if (isset($o['escala']['on'])) $c['escala']['on'] = (bool)$o['escala']['on'];
        if (is_numeric($o['escala']['min'] ?? null) && $o['escala']['min'] >= 1) $c['escala']['min'] = (int)$o['escala']['min'];
        foreach (self::IND as $id => $i) {
            $e = (array)($o['ind'][$id] ?? []);
            if (isset($e['act'])) $c['ind'][$id]['act'] = (bool)$e['act'];
            if (isset($e['clave'])) $c['ind'][$id]['clave'] = (bool)$e['clave'];
            foreach (['g', 'a', 'w'] as $k) if (is_numeric($e[$k] ?? null)) $c['ind'][$id][$k] = (float)$e[$k];
        }
        return $c;
    }

    /** Guarda lo de la pestaña Configuración. Un umbral al revés (Alerta mejor que Cumple) se rechaza. */
    public static function guardarConf(array $d): array
    {
        // Lo que no viene en el formulario se queda como estaba
        $ya = self::conf();
        $o = ['estados' => [], 'puntos' => [], 'bandas' => [], 'escala' => [], 'ind' => []];
        foreach (self::ESTADOS as $k => $_) {
            $o['estados'][$k] = ['nombre' => mb_substr(trim((string)($d['estados'][$k]['nombre'] ?? '')), 0, 24) ?: $ya['estados'][$k]['nombre'],
                                 'color' => isset(self::PAL[$d['estados'][$k]['color'] ?? '']) ? $d['estados'][$k]['color'] : $ya['estados'][$k]['color']];
        }
        foreach (['ok', 'warn', 'crit'] as $k) $o['puntos'][$k] = max(0, min(100, (float)($d['puntos'][$k] ?? self::conf()['puntos'][$k])));
        foreach (['ok', 'warn'] as $k) $o['bandas'][$k] = max(0, min(100, (float)($d['bandas'][$k] ?? self::conf()['bandas'][$k])));
        if ($o['bandas']['warn'] >= $o['bandas']['ok']) throw new RuntimeException('El puntaje de «' . $o['estados']['warn']['nombre'] . '» tiene que ser menor que el de «' . $o['estados']['ok']['nombre'] . '».');
        $o['escala'] = isset($d['escala']) ? ['on' => !empty($d['escala']['on']), 'min' => max(1, min(10, (int)($d['escala']['min'] ?? $ya['escala']['min'])))] : $ya['escala'];
        foreach (self::IND as $id => $i) {
            if (!isset($d['ind'][$id])) { $o['ind'][$id] = $ya['ind'][$id]; continue; }
            $e = (array)$d['ind'][$id];
            $g = self::numero($e['g'] ?? '') ?? $i['g'];
            $a = self::numero($e['a'] ?? '') ?? $i['a'];
            if ($i['dir'] === 'up' ? $a >= $g : $a <= $g) throw new RuntimeException('En «' . $i['n'] . '» el umbral de Alerta tiene que ser ' . ($i['dir'] === 'up' ? 'menor' : 'mayor') . ' que el de Cumple.');
            $o['ind'][$id] = ['act' => !empty($e['act']), 'clave' => !empty($e['clave']), 'g' => $g, 'a' => $a, 'w' => max(0, (float)(self::numero($e['w'] ?? '') ?? $i['w']))];
        }
        Config::guardar(['colorimetria' => $o]);
        return $o;
    }

    public static function restablecer(): void { Config::guardar(['colorimetria' => []]); }

    /** El indicador con los umbrales y el peso vigentes. */
    public static function ind(string $id): array
    {
        return ['id' => $id] + self::conf()['ind'][$id] + self::IND[$id];
    }

    public static function nombre(string $k): string { return self::conf()['estados'][$k]['nombre'] ?? $k; }

    /* ============================== Cálculo ============================== */

    public static function estadoInd(array $i, ?float $v): string
    {
        if ($v === null) return 'nm';
        if ($i['dir'] === 'up') return $v >= $i['g'] ? 'ok' : ($v >= $i['a'] ? 'warn' : 'crit');
        return $v <= $i['g'] ? 'ok' : ($v <= $i['a'] ? 'warn' : 'crit');
    }

    public static function banda(float $s): string
    {
        $b = self::conf()['bandas'];
        return $s >= $b['ok'] ? 'ok' : ($s >= $b['warn'] ? 'warn' : 'crit');
    }

    /** Puntaje ponderado de 0 a 100 (los indicadores sin dato no cuentan); null si ninguno tiene dato. */
    public static function puntaje(array $filas): ?float
    {
        $p = self::conf()['puntos'];
        $sw = $sp = 0.0;
        foreach ($filas as $r) {
            if ($r['v'] === null) continue;
            $sw += $r['i']['w'];
            $sp += $r['i']['w'] * $p[$r['st']];
        }
        return $sw > 0 ? $sp / $sw : null;
    }

    /**
     * La salud con los valores de cada indicador ([id => valor|null]) y la fase
     * (run, none, hold, done). Devuelve las filas, el puntaje, la salud (con el
     * escalamiento por claves), las dimensiones, los conteos y el estado final.
     */
    public static function evaluar(array $vals, string $fase = 'run'): array
    {
        $c = self::conf();
        $filas = [];
        foreach (self::IND as $id => $_) {
            $i = self::ind($id);
            if (!$i['act']) continue;
            $v = isset($vals[$id]) && is_numeric($vals[$id]) ? (float)$vals[$id] : null;
            $filas[$id] = ['i' => $i, 'v' => $v, 'st' => self::estadoInd($i, $v)];
        }
        $tot = self::puntaje($filas);
        $claves = array_values(array_filter($filas, fn($r) => $r['i']['clave'] && $r['st'] === 'crit'));
        $salud = $tot === null ? 'nm' : self::banda($tot);
        $escalo = false;
        if ($tot !== null && $c['escala']['on']) {
            if (count($claves) >= $c['escala']['min']) { if ($salud !== 'crit') { $salud = 'crit'; $escalo = true; } }
            elseif ($claves && $salud === 'ok') { $salud = 'warn'; $escalo = true; }
        }
        $dims = [];
        foreach (self::DIMS as $n) {
            $rs = array_filter($filas, fn($r) => $r['i']['dim'] === $n);
            if (!$rs) continue;
            $s = self::puntaje($rs);
            $dims[] = ['n' => $n, 'filas' => $rs, 'puntaje' => $s, 'st' => $s === null ? 'nm' : self::banda($s)];
        }
        $cuenta = ['ok' => 0, 'warn' => 0, 'crit' => 0, 'nm' => 0];
        foreach ($filas as $r) $cuenta[$r['st']]++;
        $estado = $fase === 'run' ? ($tot === null ? 'nm' : $salud) : $fase;
        return ['filas' => $filas, 'puntaje' => $tot, 'salud' => $salud, 'claves' => $claves, 'escalo' => $escalo, 'dims' => $dims, 'cuenta' => $cuenta, 'estado' => $estado];
    }

    /* ============================== Formato ============================== */

    /** Número en formato Ecuador: coma decimal y punto de miles. */
    public static function num(float $v, int $d = 0): string { return number_format($v, $d, ',', '.'); }

    public static function fmt(array $i, ?float $v): string
    {
        if ($v === null) return 'Sin dato';
        $t = self::num($v, (int)$i['d']);
        return $i['u'] === '%' ? $t . ' %' : ($i['u'] !== '' ? $t . ' ' . $i['u'] : $t);
    }

    public static function meta(array $i): string { return ($i['dir'] === 'up' ? '≥ ' : '≤ ') . self::fmt($i, (float)$i['g']); }

    /** «0,95» o «95» (del formulario) → número, o null si está vacío. */
    public static function numero(mixed $v): ?float
    {
        $t = trim(str_replace(' ', '', (string)$v));
        if ($t === '') return null;
        // Con coma decimal (formato Ecuador): los puntos son de miles
        if (str_contains($t, ',')) $t = str_replace(['.', ','], ['', '.'], $t);
        return is_numeric($t) ? (float)$t : null;
    }

    /* ============================== Mediciones y fichas ============================== */

    private static function repo(string $c): JsonStore { return new JsonStore($c); }

    public static function periodo(?string $f = null): string { return substr($f ?? date('Y-m-d'), 0, 7); }

    /** Lo anotado a mano: la medición más reciente hasta ese mes ([vals, periodo, medicion]). */
    public static function manuales(string $tipo, string $ref, ?string $periodo = null): array
    {
        $periodo ??= self::periodo();
        $mejor = null;
        foreach (self::repo('col_mediciones')->all() as $m) {
            if (($m['tipo'] ?? '') !== $tipo || (string)($m['ref'] ?? '') !== $ref || (string)($m['periodo'] ?? '') > $periodo) continue;
            if (!$mejor || (string)$m['periodo'] > (string)$mejor['periodo']) $mejor = $m;
        }
        return [$mejor ? array_filter((array)$mejor['vals'], 'is_numeric') : [], (string)($mejor['periodo'] ?? ''), $mejor];
    }

    /** Anota los valores del mes (solo los que vienen con número; vacío borra el anotado). */
    public static function guardarMedicion(string $tipo, string $ref, string $periodo, array $vals, string $nota, int $quien): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) throw new RuntimeException('Ese mes no es válido.');
        $limpio = [];
        foreach (self::IND as $id => $i) {
            $v = self::numero($vals[$id] ?? '');
            if ($v === null) continue;
            if ($i['u'] === '%' && ($v < -100 || $v > 1000)) throw new RuntimeException('«' . $i['n'] . '» tiene un valor fuera de rango.');
            $limpio[$id] = !empty($i['int']) ? (float)round($v) : $v;
        }
        $repo = self::repo('col_mediciones');
        foreach ($repo->all() as $m) {
            if (($m['tipo'] ?? '') === $tipo && (string)($m['ref'] ?? '') === $ref && ($m['periodo'] ?? '') === $periodo) {
                $repo->update((int)$m['id'], ['vals' => $limpio, 'nota' => mb_substr(trim($nota), 0, 1000), 'por' => $quien, 'fecha' => date('Y-m-d H:i')]);
                return $repo->find((int)$m['id']);
            }
        }
        return $repo->insert(['tipo' => $tipo, 'ref' => $ref, 'periodo' => $periodo, 'vals' => $limpio, 'nota' => mb_substr(trim($nota), 0, 1000), 'por' => $quien, 'fecha' => date('Y-m-d H:i')]);
    }

    /** Código, cliente y fase elegida a mano de un proyecto o equipo. */
    public static function ficha(string $tipo, string $ref): array
    {
        foreach (self::repo('col_fichas')->all() as $f) {
            if (($f['tipo'] ?? '') === $tipo && (string)($f['ref'] ?? '') === $ref) return $f;
        }
        return ['tipo' => $tipo, 'ref' => $ref, 'codigo' => '', 'cliente' => '', 'fase' => ''];
    }

    public static function guardarFicha(string $tipo, string $ref, array $d): void
    {
        $c = ['codigo' => mb_substr(strtoupper(trim((string)($d['codigo'] ?? ''))), 0, 40), 'cliente' => mb_substr(trim((string)($d['cliente'] ?? '')), 0, 80),
              'fase' => isset(self::FASES[$d['fase'] ?? '']) ? (string)$d['fase'] : ''];
        $repo = self::repo('col_fichas');
        $ya = self::ficha($tipo, $ref);
        if (!empty($ya['id'])) $repo->update((int)$ya['id'], $c);
        else $repo->insert(['tipo' => $tipo, 'ref' => $ref] + $c);
    }

    /* ============================== Lo que calcula el panel ============================== */

    /**
     * Indicadores de un grupo de tareas: cronograma (SPI), entregas a tiempo,
     * predictibilidad, tiempo de ciclo, impedimentos y variación de velocidad.
     * También el avance real y el esperado a hoy (para la barra).
     */
    public static function deTareas(array $tareas, ?string $hoy = null, array $obsPend = []): array
    {
        $hoy ??= date('Y-m-d');
        $finales = Catalogo::estadosFinales();
        $porId = [];
        foreach ($tareas as $t) $porId[(int)$t['id']] = $t;
        $hecha = fn(array $t) => in_array($t['estado'] ?? '', $finales, true);
        $total = count($tareas);
        $hechas = count(array_filter($tareas, $hecha));
        $conFecha = array_filter($tareas, fn($t) => (string)($t['fecha_limite'] ?? '') !== '');
        $planHoy = count(array_filter($conFecha, fn($t) => $t['fecha_limite'] <= $hoy));
        $hechasConFecha = count(array_filter($conFecha, $hecha));
        $x = ['spi' => null, 'hitos' => null, 'pred' => null, 'ciclo' => null, 'imped' => null, 'vel' => null,
              'alcance' => null, 'defec' => null, 'dod' => null, 'riesgos' => null, 'acep' => null,
              'avance' => $total ? (int)round($hechas / $total * 100) : null, 'plan' => $conFecha ? (int)round($planHoy / count($conFecha) * 100) : null];
        if ($planHoy > 0) $x['spi'] = round(min(2, $hechasConFecha / $planHoy), 2);
        // A tiempo: terminada a más tardar en su fecha límite (sin fecha de cierre anotada, cuenta como a tiempo)
        $aTiempo = fn(array $t) => $hecha($t) && ((string)($t['completada_en'] ?? '') === '' || substr((string)$t['completada_en'], 0, 10) <= $t['fecha_limite']);
        $vencidas = array_filter($conFecha, fn($t) => $t['fecha_limite'] < $hoy);
        if ($vencidas) $x['hitos'] = round(count(array_filter($vencidas, $aTiempo)) / count($vencidas) * 100, 1);
        $hace14 = date('Y-m-d', strtotime($hoy . ' -14 days'));
        $sprint = array_filter($vencidas, fn($t) => $t['fecha_limite'] >= $hace14);
        if ($sprint) $x['pred'] = round(count(array_filter($sprint, $aTiempo)) / count($sprint) * 100, 1);
        // Tiempo de ciclo: desde que pasó a «en progreso» (o su fecha de inicio, o su creación) hasta que se terminó
        $hace30 = date('Y-m-d', strtotime($hoy . ' -30 days'));
        $dias = [];
        foreach ($tareas as $t) {
            $fin = substr((string)($t['completada_en'] ?? ''), 0, 10);
            if (!$hecha($t) || $fin === '' || $fin < $hace30) continue;
            $ini = '';
            foreach ((array)($t['historial'] ?? []) as $h) {
                if (($h['tipo'] ?? '') === 'estado' && ($h['de'] ?? '') === 'pendiente') { $ini = substr((string)($h['t'] ?? ''), 0, 10); break; }
            }
            $ini = $ini ?: substr((string)($t['fecha_inicio'] ?? ''), 0, 10) ?: substr((string)($t['creado'] ?? ''), 0, 10);
            if ($ini !== '' && $ini <= $fin) $dias[] = (strtotime($fin) - strtotime($ini)) / 86400;
        }
        if ($dias) $x['ciclo'] = round(array_sum($dias) / count($dias), 1);
        // Impedimentos: tareas abiertas que esperan una dependencia sin terminar
        $n = 0;
        foreach ($tareas as $t) {
            if ($hecha($t)) continue;
            foreach (array_map('intval', (array)($t['dependencias'] ?? [])) as $dep) {
                if (isset($porId[$dep]) && !$hecha($porId[$dep])) { $n++; break; }
            }
        }
        if ($total) $x['imped'] = $n;
        // Variación de velocidad: cuánto cambian las terminadas por semana (las cuatro semanas completas anteriores)
        $lunes = strtotime('monday this week', strtotime($hoy));
        $semanas = [0, 0, 0, 0];
        foreach ($tareas as $t) {
            $fin = strtotime(substr((string)($t['completada_en'] ?? ''), 0, 10));
            if (!$hecha($t) || !$fin || $fin >= $lunes) continue;
            $k = (int)floor(($lunes - $fin) / (7 * 86400));
            if ($k >= 0 && $k < 4) $semanas[$k]++;
        }
        $media = array_sum($semanas) / 4;
        if ($media > 0) {
            $var = array_sum(array_map(fn($s) => ($s - $media) ** 2, $semanas)) / 4;
            $x['vel'] = round(sqrt($var) / $media * 100, 1);
        }
        if (!$total) return $x;
        $alta = fn(array $t) => in_array(mb_strtolower((string)($t['prioridad'] ?? '')), ['alta', 'urgente', 'critica', 'crítica'], true);
        $conObs = [];
        foreach ($obsPend as $o) if (($tid = (int)($o['tarea_id'] ?? 0)) && isset($porId[$tid])) $conObs[$tid] = ($conObs[$tid] ?? 0) + 1;
        // Defectos críticos: observaciones de QA sin resolver en tareas abiertas de prioridad alta
        $x['defec'] = array_sum(array_map(fn($t) => !$hecha($t) && $alta($t) ? ($conObs[(int)$t['id']] ?? 0) : 0, $tareas));
        // Riesgos críticos: tareas abiertas de prioridad alta que ya se pasaron de su fecha límite
        $x['riesgos'] = count(array_filter($conFecha, fn($t) => !$hecha($t) && $alta($t) && $t['fecha_limite'] < $hoy));
        // Definición de Hecho: de lo terminado en los últimos 30 días, lo que no tiene observaciones de QA pendientes
        $fin = fn(array $t) => substr((string)($t['completada_en'] ?? ''), 0, 10);
        $recientes = array_filter($tareas, fn($t) => $hecha($t) && $fin($t) >= $hace30);
        if ($recientes) $x['dod'] = round(count(array_filter($recientes, fn($t) => empty($conObs[(int)$t['id']]))) / count($recientes) * 100, 1);
        // Aceptadas a la primera: de lo terminado en los últimos 60 días, lo que no se volvió a abrir
        $hace60 = date('Y-m-d', strtotime($hoy . ' -60 days'));
        $reabierta = fn(array $t) => (bool)array_filter((array)($t['historial'] ?? []), fn($h) => ($h['tipo'] ?? '') === 'estado'
            && in_array($h['de'] ?? '', $finales, true) && !in_array($h['a'] ?? '', $finales, true));
        $ultimas = array_filter($tareas, fn($t) => $hecha($t) && $fin($t) >= $hace60);
        if ($ultimas) $x['acep'] = round(count(array_filter($ultimas, fn($t) => !$reabierta($t))) / count($ultimas) * 100, 1);
        // Variación de alcance: tareas nuevas de los últimos 30 días sobre las que ya había (con al menos 5)
        $creada = fn(array $t) => substr((string)($t['creado'] ?? ''), 0, 10);
        $base = count(array_filter($tareas, fn($t) => $creada($t) !== '' && $creada($t) < $hace30));
        if ($base >= 5) $x['alcance'] = round(count(array_filter($tareas, fn($t) => $creada($t) >= $hace30)) / $base * 100, 1);
        return $x;
    }

    /** Las observaciones de QA sin resolver (las que abren hilo), de todo el panel. */
    public static function obsPendientes(): array
    {
        static $l = null;
        return $l ??= array_values(array_filter((new JsonStore('observaciones'))->all(), fn($o) => ($o['tipo'] ?? 'nota') === 'nota'
            && ($o['estado'] ?? '') === 'pendiente' && (int)($o['padre_id'] ?? 0) === 0));
    }

    /** De los ítems con estos códigos, el % que pasó su primera revisión de cierre (null si ninguno llegó a una). */
    private static function aLaPrimera(array $codigos): ?float
    {
        if (!$codigos || !class_exists('Releases')) return null;
        $quiero = array_flip(array_map(fn($c) => strtoupper((string)$c), $codigos));
        $primera = [];
        foreach (Releases::revisiones() as $r) {   // vienen por fecha: la primera que se ve es la primera revisión
            foreach ((array)($r['filas'] ?? []) as $f) {
                $cod = strtoupper((string)($f['codigo'] ?? ''));
                if (!isset($quiero[$cod]) || isset($primera[$cod]) || stripos((string)($f['tipo'] ?? ''), 'cierre') === false) continue;
                if (in_array($f['estado'] ?? '', ['cumplido', 'parcial', 'no_cumplido'], true)) $primera[$cod] = $f['estado'] === 'cumplido';
            }
        }
        return $primera ? round(count(array_filter($primera)) / count($primera) * 100, 1) : null;
    }

    /** Lo que el panel calcula de un proyecto: sus tareas, sus observaciones de QA y sus ítems de Releases. */
    public static function autoProyecto(array $p, array $tareas, array $obsPendientes): array
    {
        $x = self::deTareas($tareas, null, $obsPendientes);
        $x['hist'] = null;
        if (class_exists('Releases') && method_exists('Releases', 'itemsDeProyecto')) {
            // Los ítems vienen sin calcular: el esperado a hoy y el avance (un terminado cuenta 100)
            $items = Releases::itemsDeProyecto((int)$p['id']);
            $esp = array_sum(array_map(fn($it) => Releases::esperado($it), $items));
            $av = array_sum(array_map(fn($it) => ($it['estado'] ?? '') === 'cerrado' ? 100 : (int)($it['avance'] ?? 0), $items));
            if ($esp > 0) $x['hist'] = round(min(150, $av / $esp * 100), 1);
            // Aceptados a la primera: con revisiones de cierre de sus ítems, esas mandan sobre las tareas reabiertas
            $x['acep'] = self::aLaPrimera(array_column($items, 'codigo')) ?? $x['acep'];
        }
        return $x;
    }

    /** Lo que el panel calcula de un equipo de Releases: sus ítems, sus revisiones y las tareas de sus proyectos. */
    public static function autoEquipo(string $equipo, ?string $hoy = null): array
    {
        $hoy ??= date('Y-m-d');
        $items = Releases::items(['equipo' => $equipo], $hoy);
        $x = self::deTareas(Releases::tareasEquipo($equipo), $hoy, self::obsPendientes());
        $esp = array_sum(array_column($items, 'esperado'));
        $x['spi'] = $esp > 0 ? round(min(2, array_sum(array_column($items, 'avance')) / $esp), 2) : null;
        $x['avance'] = $items ? (int)round(array_sum(array_column($items, 'avance')) / count($items)) : null;
        $x['plan'] = $items ? (int)round($esp / count($items)) : null;
        $vencidos = array_filter($items, fn($it) => ($it['fin'] ?? '') !== '' && $it['fin'] < $hoy);
        $x['hitos'] = $vencidos ? round(count(array_filter($vencidos, fn($it) => $it['estado'] === 'cerrado')) / count($vencidos) * 100, 1) : null;
        // El release en curso: el que tiene hoy entre sus fechas (o, si no, el próximo)
        $enCurso = '';
        foreach (Releases::releases() as $cod => $r) {
            if (($r['inicio'] ?? '') <= $hoy && ($r['fin'] ?? '') >= $hoy) { $enCurso = (string)$cod; break; }
            if ($enCurso === '' && ($r['inicio'] ?? '') > $hoy) $enCurso = (string)$cod;
        }
        $delRel = array_filter($items, fn($it) => $it['release'] === $enCurso);
        $espR = array_sum(array_column($delRel, 'esperado'));
        $x['hist'] = $espR > 0 ? round(min(150, array_sum(array_column($delRel, 'avance')) / $espR * 100), 1) : null;
        // Variación de alcance: los ítems del release en curso que se agregaron en el panel sobre los que venían del Excel
        if ($delRel) {
            $agregados = count(array_filter($delRel, fn($it) => (string)(((array)($it['historial'] ?? []))[0]['texto'] ?? '') === 'Creó el ítem'));
            $baseR = count($delRel) - $agregados;
            $x['alcance'] = $baseR > 0 ? round($agregados / $baseR * 100, 1) : $x['alcance'];
        }
        $x['acep'] = self::aLaPrimera(array_column($items, 'codigo')) ?? $x['acep'];
        $x['release'] = $enCurso;
        // Predictibilidad: el cumplimiento de sus revisiones de las últimas cuatro semanas
        $revs = array_filter(Releases::revisiones(['equipo' => $equipo, 'desde' => date('Y-m-d', strtotime($hoy . ' -28 days')), 'hasta' => $hoy]), fn($r) => ($r['estado'] ?? '') === 'realizada');
        $cu = $revs ? Releases::cumplimiento($revs) : null;
        $x['pred'] = $cu && $cu['pct'] !== null ? (float)$cu['pct'] : null;
        $x['imped'] = $items ? count(array_filter($items, fn($it) => $it['estado'] === 'bloqueado')) : null;
        $x['items'] = count($items);
        $x['cerrados'] = count(array_filter($items, fn($it) => $it['estado'] === 'cerrado'));
        return $x;
    }

    /* ============================== El tablero ============================== */

    /** Junta lo calculado y lo anotado: [vals, fuente por indicador ('auto' | 'manual' | ''), periodo anotado]. */
    private static function mezclar(array $auto, string $tipo, string $ref): array
    {
        [$man, $per] = self::manuales($tipo, $ref);
        $vals = $fuente = [];
        foreach (self::IND as $id => $i) {
            if (array_key_exists($id, $man)) { $vals[$id] = (float)$man[$id]; $fuente[$id] = 'manual'; }
            elseif (isset($auto[$id]) && $auto[$id] !== null && str_contains((string)($i['auto'] ?? ''), $tipo === 'proyecto' ? 'p' : 'e')) { $vals[$id] = (float)$auto[$id]; $fuente[$id] = 'auto'; }
            else { $vals[$id] = null; $fuente[$id] = ''; }
        }
        return [$vals, $fuente, $per];
    }

    /** Los proyectos del panel que ve quien entra, con su salud. */
    public static function proyectos(): array
    {
        $tareasPor = [];
        foreach ((new TareaRepo())->todas() as $t) $tareasPor[(int)$t['proyecto_id']][] = $t;
        $obsPor = [];
        foreach (self::obsPendientes() as $o) $obsPor[(int)($o['proyecto_id'] ?? 0)][] = $o;
        $out = [];
        foreach ((new ProyectoRepo())->todos() as $p) {
            $pid = (int)$p['id'];
            if (!Auth::esAdmin() && function_exists('puedeVerProyecto') && !puedeVerProyecto($pid)) continue;
            $out[] = self::filaProyecto($p, $tareasPor[$pid] ?? [], $obsPor[$pid] ?? []);
        }
        usort($out, fn($a, $b) => [self::SEV[$b['estado']], $a['nombre']] <=> [self::SEV[$a['estado']], $b['nombre']]);
        return $out;
    }

    /** La salud de un proyecto (con sus tareas y sus observaciones de QA pendientes). */
    public static function filaProyecto(array $p, array $ts, array $obs): array
    {
        $pid = (int)$p['id'];
        $auto = self::autoProyecto($p, $ts, $obs);
        [$vals, $fuente, $per] = self::mezclar($auto, 'proyecto', (string)$pid);
        $f = self::ficha('proyecto', (string)$pid);
        $empezo = (bool)array_filter($ts, fn($t) => ($t['estado'] ?? 'pendiente') !== 'pendiente');
        // Todas sus tareas terminadas: está Finalizado (si le suman tareas nuevas, vuelve a medirse su salud)
        $todasHechas = $ts && !array_filter($ts, fn($t) => !in_array($t['estado'] ?? '', Catalogo::estadosFinales(), true));
        $fase = $f['fase'] !== '' ? $f['fase'] : match ((string)($p['estado'] ?? 'activo')) {
            'pausado' => 'hold', 'completado' => 'done', default => $todasHechas ? 'done' : ($empezo ? 'run' : 'none'),
        };
        $ev = self::evaluar($vals, $fase);
        return ['tipo' => 'proyecto', 'ref' => (string)$pid, 'nombre' => (string)$p['nombre'], 'p' => $p,
                'codigo' => $f['codigo'] !== '' ? $f['codigo'] : self::codigoPorDefecto($p), 'cliente' => $f['cliente'], 'ficha' => $f,
                'vals' => $vals, 'fuente' => $fuente, 'periodo' => $per, 'auto' => $auto, 'fase' => $fase, 'ev' => $ev, 'estado' => $ev['estado']];
    }

    /** La salud de un solo proyecto (su página): lee solo sus tareas y sus observaciones. */
    public static function delProyecto(array $p): array
    {
        $pid = (int)$p['id'];
        $obs = array_filter(self::obsPendientes(), fn($o) => (int)($o['proyecto_id'] ?? 0) === $pid);
        return self::filaProyecto($p, (new TareaRepo())->delProyecto($pid), array_values($obs));
    }

    /** Los equipos de Releases, con su salud. */
    public static function equipos(): array
    {
        if (!class_exists('Releases')) return [];
        $out = [];
        foreach (Releases::equipos() as $nom => $e) {
            if ($nom === 'Todos' || !Releases::items(['equipo' => $nom])) continue;
            $out[] = self::filaEquipo((string)$nom, $e);
        }
        usort($out, fn($a, $b) => [self::SEV[$b['estado']], $a['nombre']] <=> [self::SEV[$a['estado']], $b['nombre']]);
        return $out;
    }

    /** La salud de un equipo de Releases (su página), o null si no tiene ítems. */
    public static function delEquipo(string $nom): ?array
    {
        if (!class_exists('Releases') || $nom === 'Todos' || !($e = Releases::equipo($nom)) || !Releases::items(['equipo' => $nom])) return null;
        return self::filaEquipo($nom, $e);
    }

    private static function filaEquipo(string $nom, array $e): array
    {
        $auto = self::autoEquipo($nom);
        [$vals, $fuente, $per] = self::mezclar($auto, 'equipo', $nom);
        $f = self::ficha('equipo', $nom);
        $fase = $f['fase'] !== '' ? $f['fase'] : ($auto['items'] && $auto['cerrados'] >= $auto['items'] ? 'done' : (($auto['avance'] ?? 0) > 0 ? 'run' : 'none'));
        $ev = self::evaluar($vals, $fase);
        return ['tipo' => 'equipo', 'ref' => $nom, 'nombre' => 'Equipo ' . $nom, 'e' => $e,
                'codigo' => $f['codigo'] !== '' ? $f['codigo'] : 'EQ-' . strtoupper(self::slug($nom)), 'cliente' => $f['cliente'] !== '' ? $f['cliente'] : (string)($e['dominio'] ?? ''), 'ficha' => $f,
                'vals' => $vals, 'fuente' => $fuente, 'periodo' => $per, 'auto' => $auto, 'fase' => $fase, 'ev' => $ev, 'estado' => $ev['estado']];
    }

    /** PRY-<NOMBRE>-<AÑO>-<id>, como en la guía, mientras no se le ponga uno. */
    public static function codigoPorDefecto(array $p): string
    {
        $anio = substr((string)($p['fecha_inicio'] ?? '') ?: (string)($p['creado'] ?? '') ?: date('Y'), 0, 4);
        return 'PRY-' . strtoupper(mb_substr(self::slug((string)$p['nombre']), 0, 8)) . '-' . $anio . '-' . str_pad((string)(int)$p['id'], 3, '0', STR_PAD_LEFT);
    }

    private static function slug(string $s): string
    {
        $s = strtr(mb_strtolower($s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-') ?: 'x';
    }

    /** Quién ve el tablero: el administrador, los Scrum Master y los supervisores. */
    public static function puedeVer(): bool { return Auth::esAdmin() || Auth::esScrum() || Auth::esSupervisor(); }

    /** Quién anota los valores del mes: el administrador o quien gestiona ese proyecto (SM o PO); en Releases, sus editores o el SM del equipo. */
    public static function puedeMedir(string $tipo, string $ref): bool
    {
        if (Auth::esAdmin()) return true;
        if ($tipo === 'proyecto') return (function_exists('puedeGestionar') && puedeGestionar((int)$ref)) || (function_exists('esPODelProyecto') && esPODelProyecto((int)$ref));
        if (!class_exists('Releases')) return false;
        $yo = (int)(Auth::usuario()['id'] ?? 0);
        return Releases::puedeEditar() || ($yo > 0 && (int)(Releases::equipo($ref)['sm_id'] ?? 0) === $yo);
    }

    /** Los tokens de color de cada estado (tema claro y oscuro), con los colores que se eligieron. */
    public static function css(): string
    {
        $t = function (string $tema): string {
            $s = '';
            foreach (self::conf()['estados'] as $k => $e) {
                [$bg, $fg, $solid, $band] = self::PAL[$e['color']][$tema];
                $s .= "--col-$k-bg:$bg;--col-$k-fg:$fg;--col-$k-solid:$solid;--col-$k-band:$band;";
            }
            return $s;
        };
        return ':root{' . $t('l') . '}html.dark{' . $t('d') . '}';
    }
}
