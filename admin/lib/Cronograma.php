<?php
/**
 * Cronograma de subidas: los builds que terminan bien en GitLab esperan a la
 * hora fija de su proyecto (por defecto las 10:00 de lunes a viernes; un
 * proyecto puede tener la suya, p. ej. la Agraria a las 15:00) y a esa hora
 * quedan registrados como un despliegue —con las tareas completadas de sus
 * proyectos— y se avisa a todos. Así el equipo sabe qué sube y cuándo, y los
 * dev saben que lo suyo ya está en fila. Despliegues muestra el horario y la
 * cuenta regresiva.
 *
 * Lo mueve el cron de cada minuto (cron_correo.php) o cron_deploys.php:
 *  1. buscar(): cada 5 minutos pregunta a GitLab por los builds exitosos de la
 *     rama de cada repo de los proyectos con despliegues. Los nuevos quedan
 *     «en cola» y a su equipo le llega a qué hora suben.
 *  2. avisoPrevio(): unos minutos antes de la hora, lo que va a subir.
 *  3. subirVentana(): a la hora, el despliegue con lo que está en cola y el
 *     aviso a todos.
 * Si alguien registra la subida a mano antes, se lleva los builds en cola de
 * sus proyectos (alRegistrarAMano).
 *
 * La configuración (hora general, hora por proyecto, días, GitLab sí/no, aviso
 * previo) está en Ajustes → Despliegues (configDeploys()['cronograma']).
 * No va en bootstrap: lo cargan deploys.php, actions.php, index.php y los cron.
 */
require_once __DIR__ . '/Models.php';
require_once __DIR__ . '/Repos.php';

final class Cronograma
{
    /** Segundos entre una pregunta a GitLab y la siguiente. */
    private const BUSCAR_CADA = 300;

    private static function builds(): JsonStore
    {
        return new JsonStore('deploy_builds');
    }

    /* ======================= Configuración y estado ======================= */

    public static function conf(): array
    {
        return configDeploys()['cronograma'];
    }

    /** ¿Está andando? El módulo de despliegues encendido y el cronograma también. */
    public static function activo(): bool
    {
        $d = configDeploys();
        return $d['activo'] && $d['cronograma']['activo'];
    }

    /** ¿Puede leer builds? GitLab encendido en el cronograma y el token puesto en Ajustes. */
    public static function conGitLab(): bool
    {
        return self::conf()['gitlab'] && trim((string)(Config::get('gitlab_token') ?? '')) !== '';
    }

    /** La hora de subida de un proyecto: la suya o la general ($pid 0 = la general). */
    public static function horaDe(int $pid = 0): string
    {
        $c = self::conf();
        return $c['horas'][$pid] ?? $c['hora'];
    }

    /** Los proyectos que siguen el cronograma: los de Despliegues que no están completados. */
    public static function proyectos(): array
    {
        return array_values(array_filter((new ProyectoRepo())->todos(),
            fn($p) => ($p['estado'] ?? 'activo') !== 'completado' && proyectoConDeploys((int)$p['id'])));
    }

    private static function archivoEstado(): string
    {
        return __DIR__ . '/../data/cronograma.json';
    }

    /** Lo que recuerda entre vueltas: última búsqueda, ventanas hechas y avisos por proyecto, cursores… */
    public static function estado(): array
    {
        $f = self::archivoEstado();
        return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }

    private static function guardarEstado(array $cambios): void
    {
        @file_put_contents(self::archivoEstado(), json_encode(array_replace(self::estado(), $cambios), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /* ======================= Las ventanas (la hora fija) ======================= */

    /** La ventana de un día a esa hora (timestamp), o 0 si ese día no se sube. */
    private static function ventanaDelDia(int $dia, string $hora): int
    {
        if (!in_array((int)date('N', $dia), self::conf()['dias'], true)) return 0;
        [$h, $m] = array_map('intval', explode(':', $hora));
        return mktime($h, $m, 0, (int)date('n', $dia), (int)date('j', $dia), (int)date('Y', $dia));
    }

    /** La primera ventana del proyecto en $t o después. */
    public static function siguiente(int $t, int $pid = 0): int
    {
        $hora = self::horaDe($pid);
        for ($i = 0; $i <= 14; $i++) {
            $v = self::ventanaDelDia(strtotime(date('Y-m-d', $t) . ' +' . $i . ' day'), $hora);
            if ($v >= $t) return $v;
        }
        return 0;
    }

    /** La última ventana del proyecto en $t o antes (0 si ninguna en dos semanas). */
    public static function anterior(int $t, int $pid = 0): int
    {
        $hora = self::horaDe($pid);
        for ($i = 0; $i <= 14; $i++) {
            $v = self::ventanaDelDia(strtotime(date('Y-m-d', $t) . ' -' . $i . ' day'), $hora);
            if ($v > 0 && $v <= $t) return $v;
        }
        return 0;
    }

    /**
     * La próxima subida de un proyecto: su ventana que viene o, si el cron se
     * atrasó y la de hoy aún no se hizo, esa.
     */
    public static function proxima(int $pid = 0, ?int $ahora = null): int
    {
        $ahora ??= time();
        $a = self::anterior($ahora, $pid);
        $hechas = (array)(self::estado()['ventanas'] ?? []);
        $ult = (int)($hechas[$pid] ?? 0);
        if ($a > 0 && $ult > 0 && $a > $ult) return $a;
        return self::siguiente($ahora, $pid);
    }

    /** «hoy a las 10:00 am», «mañana miércoles 22 a las 10:00 am», «el lunes 26 a las 3:00 pm». */
    public static function texto(int $v, ?int $ahora = null): string
    {
        $ahora ??= time();
        if ($v <= 0) return 'sin fecha';
        $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
        $hora = date('g:i', $v) . ' ' . date('a', $v);
        $d = date('Y-m-d', $v);
        if ($d === date('Y-m-d', $ahora)) return 'hoy a las ' . $hora;
        $dia = $dias[(int)date('w', $v)] . ' ' . (int)date('j', $v);
        if ($d === date('Y-m-d', strtotime('+1 day', $ahora))) return 'mañana ' . $dia . ' a las ' . $hora;
        return 'el ' . $dia . ' a las ' . $hora;
    }

    /** «hoy 9:42 am», «ayer 6:10 pm», «20/10 6:10 pm» de una fecha 'Y-m-d H:i'. */
    public static function cuandoFue(string $fecha): string
    {
        $t = strtotime($fecha);
        if ($t === false) return $fecha;
        $h = date('g:i', $t) . ' ' . date('a', $t);
        return match (date('Y-m-d', $t)) {
            date('Y-m-d')                      => 'hoy ' . $h,
            date('Y-m-d', strtotime('-1 day')) => 'ayer ' . $h,
            default                            => date('d/m', $t) . ' ' . $h,
        };
    }

    /** «10:00 am» de una hora 'HH:MM'. */
    public static function horaTexto(string $hora): string
    {
        [$h, $m] = array_map('intval', explode(':', $hora));
        $t = mktime($h, $m, 0);
        return date('g:i', $t) . ' ' . date('a', $t);
    }

    /** Los días: «de lunes a viernes», «todos los días», «lunes, miércoles y viernes». */
    public static function dias(): string
    {
        $nombres = [1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado', 7 => 'domingo'];
        $d = self::conf()['dias'];
        if (count($d) === 7) return 'todos los días';
        if (count($d) === 1) return 'los ' . $nombres[$d[0]];
        if (count($d) >= 3 && $d === range($d[0], end($d))) return 'de ' . $nombres[$d[0]] . ' a ' . $nombres[end($d)];
        return implode(', ', array_map(fn($n) => $nombres[$n], array_slice($d, 0, -1))) . ' y ' . $nombres[end($d)];
    }

    /**
     * El horario para mostrarlo: una fila por hora de subida con sus proyectos,
     * la próxima ventana y los builds en cola, ordenadas por la más cercana.
     * [['hora','proxima','proyectos' => [[id, nombre, alias, cola]], 'cola']]
     */
    public static function agenda(?int $ahora = null): array
    {
        $ahora ??= time();
        $cola = [];
        foreach (self::enCola() as $b) $cola[(int)$b['proyecto_id']] = ($cola[(int)$b['proyecto_id']] ?? 0) + 1;
        $filas = [];
        foreach (self::proyectos() as $p) {
            $pid = (int)$p['id'];
            $hora = self::horaDe($pid);
            $prox = self::proxima($pid, $ahora);
            $k = $hora . '|' . $prox;
            $filas[$k] ??= ['hora' => $hora, 'proxima' => $prox, 'proyectos' => [], 'cola' => 0];
            $filas[$k]['proyectos'][] = ['id' => $pid, 'nombre' => (string)$p['nombre'], 'alias' => aliasDeploy($pid), 'cola' => $cola[$pid] ?? 0, 'p' => $p];
            $filas[$k]['cola'] += $cola[$pid] ?? 0;
        }
        $filas = array_values($filas);
        usort($filas, fn($a, $b) => $a['proxima'] <=> $b['proxima']);
        return $filas;
    }

    /* ======================= Los builds ======================= */

    /** Los que esperan su subida, del más viejo al más nuevo. */
    public static function enCola(): array
    {
        $l = array_values(array_filter(self::builds()->all(), fn($b) => ($b['estado'] ?? '') === 'en_cola'));
        usort($l, fn($a, $b) => (int)$a['terminado_t'] <=> (int)$b['terminado_t']);
        return $l;
    }

    /** Los builds que se llevó un despliegue. */
    public static function deDeploy(array $deploy): array
    {
        $ids = array_flip(array_map('intval', (array)($deploy['builds'] ?? [])));
        if (!$ids) return [];
        $l = array_values(array_filter(self::builds()->all(), fn($b) => isset($ids[(int)$b['id']])));
        usort($l, fn($a, $b) => (int)$a['terminado_t'] <=> (int)$b['terminado_t']);
        return $l;
    }

    /**
     * Pregunta a GitLab por los builds exitosos nuevos (cada 5 minutos, o ya con
     * $forzar). Los mete en cola y avisa a su equipo. Devuelve los nuevos.
     */
    public static function buscar(bool $forzar = false): array
    {
        if (!self::activo() || !self::conGitLab()) return [];
        $e = self::estado();
        if (!$forzar && time() - (int)($e['ultima_busqueda'] ?? 0) < self::BUSCAR_CADA) return [];
        self::guardarEstado(['ultima_busqueda' => time()]);

        $store = self::builds();
        $vistos = [];
        foreach ($store->all() as $b) $vistos[(string)$b['llave']] = true;
        $cursores = (array)($e['cursores'] ?? []);
        $ramas = (array)($e['ramas'] ?? []);
        $errores = [];
        $nuevos = [];
        $inicio = time();
        foreach (self::proyectos() as $p) {
            $pid = (int)$p['id'];
            foreach (ProyectoRepo::repos($p) as $r) {
                if (time() - $inicio > 25) break 2;            // lo que falte, en la próxima vuelta
                $url = (string)($r['url'] ?? '');
                if (Repos::proveedor($url) !== 'gitlab' || !($rp = GitLab::parsearRepo($url))) continue;
                // La rama del repo en el proyecto o, si no se puso, la principal de GitLab
                $rama = trim((string)($r['rama'] ?? '')) ?: (string)($ramas[$url] ?? '');
                if ($rama === '') {
                    $rama = GitLab::ramaPrincipal($url);
                    if ($rama === '') { $errores[] = $rp[1]; continue; }
                    $ramas[$url] = $rama;
                }
                $clave = $url . '#' . $rama;
                // La primera vez, solo lo de las últimas 24 h: no se encolan de golpe builds viejos
                $desde = (string)($cursores[$clave] ?? gmdate('Y-m-d\TH:i:s\Z', time() - 86400));
                $lista = GitLab::pipelinesExitosos($url, $rama, $desde);
                if ($lista === null) { $errores[] = $rp[1]; continue; }
                foreach ($lista as $pl) {
                    if ((string)$pl['actualizado'] > (string)($cursores[$clave] ?? '')) $cursores[$clave] = (string)$pl['actualizado'];
                    $llave = strtolower($rp[0] . '/' . $rp[1]) . '#' . (int)$pl['id'];
                    if (isset($vistos[$llave])) continue;
                    $vistos[$llave] = true;
                    $nuevos[] = $store->insert([
                        'llave'       => $llave,
                        'proyecto_id' => $pid,
                        'repo'        => (string)($r['label'] ?? $rp[1]),
                        'ruta'        => $rp[1],
                        'rama'        => (string)$pl['ref'],
                        'pipeline_id' => (int)$pl['id'],
                        'iid'         => (int)$pl['iid'],
                        'sha'         => substr((string)$pl['sha'], 0, 40),
                        'titulo'      => (string)$pl['titulo'],
                        'autor'       => (string)$pl['autor'],
                        'lanzo'       => (string)$pl['lanzo'],
                        'web_url'     => preg_match('#^https?://#i', (string)$pl['web_url']) ? (string)$pl['web_url'] : '',
                        'terminado'   => date('Y-m-d H:i', (int)$pl['terminado']),
                        'terminado_t' => (int)$pl['terminado'],
                        'estado'      => 'en_cola',
                        'deploy_id'   => 0,
                        'visto'       => date('Y-m-d H:i'),
                    ]);
                }
            }
        }
        self::guardarEstado(['cursores' => $cursores, 'ramas' => $ramas, 'errores' => array_values(array_unique($errores)),
                             'ultima_ok' => $errores ? (int)($e['ultima_ok'] ?? 0) : time()]);
        if ($nuevos) self::avisarNuevos($nuevos);
        return $nuevos;
    }

    /* ======================= La vuelta del cron ======================= */

    /**
     * Una vuelta (cada minuto): busca builds, avisa antes de la hora y, a la
     * hora de cada proyecto, sube lo que tiene en cola. Devuelve qué hizo.
     */
    public static function paso(?int $ahora = null): array
    {
        $ahora ??= time();
        $res = ['nuevos' => 0, 'avisos' => 0, 'deploys' => []];
        if (!self::activo()) return $res;
        $candado = @fopen(__DIR__ . '/../data/cronograma.lock', 'c');
        if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) return $res;
        try {
            $ventanas = (array)(self::estado()['ventanas'] ?? []);
            $porSubir = [];
            foreach (self::proyectos() as $p) {
                $pid = (int)$p['id'];
                $a = self::anterior($ahora, $pid);
                // La primera vez se toma como hecha su última ventana: no se sube de golpe lo de antes
                if (empty($ventanas[$pid])) { $ventanas[$pid] = $a ?: $ahora; continue; }
                if ($a > (int)$ventanas[$pid]) $porSubir[$a][] = $pid;
            }
            self::guardarEstado(['ventanas' => $ventanas]);
            // Justo antes de subir se pregunta otra vez a GitLab: lo que terminó un minuto antes también va
            $res['nuevos'] = count(self::buscar((bool)$porSubir));
            $res['avisos'] = self::avisoPrevio($ahora);
            ksort($porSubir);
            foreach ($porSubir as $v => $pids) {
                $dep = self::subirVentana((int)$v, $pids);
                if ($dep) $res['deploys'][] = (int)$dep['id'];
            }
        } finally {
            flock($candado, LOCK_UN);
            fclose($candado);
        }
        return $res;
    }

    /** Unos minutos antes de la hora de cada proyecto: qué va a subir. Una vez por ventana. Devuelve cuántos avisos. */
    public static function avisoPrevio(?int $ahora = null): int
    {
        $ahora ??= time();
        $min = (int)self::conf()['aviso'];
        if ($min <= 0) return 0;
        $avisados = (array)(self::estado()['avisos'] ?? []);
        $porVentana = [];
        foreach (self::proyectos() as $p) {
            $pid = (int)$p['id'];
            $v = self::siguiente($ahora, $pid);
            if ($v <= 0 || $v - $ahora > $min * 60 || (int)($avisados[$pid] ?? 0) >= $v) continue;
            $avisados[$pid] = $v;
            $porVentana[$v][] = $pid;
        }
        if (!$porVentana) return 0;
        self::guardarEstado(['avisos' => $avisados]);
        $n = 0;
        foreach ($porVentana as $v => $pids) {
            $cola = array_values(array_filter(self::enCola(), fn($b) => in_array((int)$b['proyecto_id'], $pids, true) && (int)$b['terminado_t'] <= $ahora));
            if (!$cola) continue;
            $conCola = array_values(array_unique(array_map(fn($b) => (int)$b['proyecto_id'], $cola)));
            $nb = count($cola);
            self::avisar(self::interesados($conCola), 'Hoy a las ' . self::horaTexto(date('H:i', (int)$v)) . ' se suben los cambios',
                $nb . ' build' . ($nb === 1 ? '' : 's') . ' de ' . self::nombres($conCola) . ': '
                . implode(' · ', array_map(fn($b) => $b['titulo'] !== '' ? $b['titulo'] : $b['rama'], array_slice($cola, 0, 3))) . ($nb > 3 ? '…' : ''));
            $n++;
        }
        return $n;
    }

    /**
     * La subida de la ventana $v de esos proyectos: un despliegue con sus builds
     * en cola que terminaron antes de esa hora, las tareas completadas que nadie
     * subió, y el aviso a todos. Sin builds no se registra nada.
     */
    public static function subirVentana(int $v, array $pids): ?array
    {
        $ventanas = (array)(self::estado()['ventanas'] ?? []);
        foreach ($pids as $pid) $ventanas[(int)$pid] = $v;
        self::guardarEstado(['ventanas' => $ventanas]);
        $cola = array_values(array_filter(self::enCola(), fn($b) => in_array((int)$b['proyecto_id'], $pids, true) && (int)$b['terminado_t'] <= $v));
        if (!$cola) return null;
        $pids = array_values(array_filter(array_unique(array_map(fn($b) => (int)$b['proyecto_id'], $cola)), fn($pid) => proyectoConDeploys($pid)));
        if (!$pids) return null;

        $tareas = new TareaRepo();
        $finales = Catalogo::estadosFinales();
        $pend = [];
        foreach ($pids as $pid) {
            foreach ($tareas->delProyecto($pid) as $t) {
                if (in_array($t['estado'] ?? '', $finales, true) && (int)($t['deploy_id'] ?? 0) === 0) $pend[] = (int)$t['id'];
            }
        }
        $cfg = configDeploys();
        $nota = 'Subida programada de las ' . self::horaTexto(date('H:i', $v)) . ' (cronograma).' . "\n"
            . implode("\n", array_map(fn($b) => '- ' . (aliasDeploy((int)$b['proyecto_id']) ?: $b['repo']) . ' · ' . $b['rama'] . ' · '
                . ($b['titulo'] !== '' ? $b['titulo'] : substr($b['sha'], 0, 8)) . ($b['autor'] !== '' ? ' (' . $b['autor'] . ')' : ''), $cola));
        $dep = (new DeployRepo())->crear([
            'proyectos' => $pids, 'autor_id' => 0, 'entorno' => $cfg['entorno'], 'fecha' => date('Y-m-d H:i', $v),
            'nota' => mb_substr($nota, 0, 1500), 'tareas' => $pend, 'origen' => 'cronograma',
            'builds' => array_map(fn($b) => (int)$b['id'], $cola),
        ]);
        foreach ($pend as $tid) $tareas->actualizar($tid, ['deploy_id' => (int)$dep['id'], 'desplegada_en' => $dep['fecha']]);
        $store = self::builds();
        foreach ($cola as $b) $store->update((int)$b['id'], ['estado' => 'subido', 'deploy_id' => (int)$dep['id']]);

        $n = count($cola);
        $nt = count($pend);
        self::avisar(self::interesados($pids, true), 'Se subieron los cambios a ' . $cfg['entorno'],
            self::nombres($pids) . ' · ' . $n . ' build' . ($n === 1 ? '' : 's') . ($nt ? ' y ' . $nt . ' tarea' . ($nt === 1 ? '' : 's') : '')
            . ' · subida de las ' . self::horaTexto(date('H:i', $v)) . '.');
        return $dep;
    }

    /**
     * Alguien registró la subida a mano: se lleva los builds en cola de sus
     * proyectos (ya están arriba) y se avisa igual que en la subida programada.
     */
    public static function alRegistrarAMano(array $deploy): void
    {
        $pids = array_map('intval', DeployRepo::proyectosDe($deploy));
        $cola = array_values(array_filter(self::enCola(), fn($b) => in_array((int)$b['proyecto_id'], $pids, true)));
        if ($cola) {
            $store = self::builds();
            foreach ($cola as $b) $store->update((int)$b['id'], ['estado' => 'subido', 'deploy_id' => (int)$deploy['id']]);
            (new DeployRepo())->actualizar((int)$deploy['id'], ['builds' => array_map(fn($b) => (int)$b['id'], $cola)]);
        }
        $n = count($cola);
        $nt = count(DeployRepo::tareasDe($deploy));
        self::avisar(self::interesados($pids, true), 'Se subieron los cambios a ' . (string)($deploy['entorno'] ?? configDeploys()['entorno']),
            self::nombres($pids) . ($n ? ' · ' . $n . ' build' . ($n === 1 ? '' : 's') : '') . ($nt ? ' · ' . $nt . ' tarea' . ($nt === 1 ? '' : 's') : '')
            . ' · a las ' . self::horaTexto(substr((string)$deploy['fecha'], 11, 5)) . '.', (int)($deploy['autor_id'] ?? 0));
    }

    /* ======================= Avisos ======================= */

    /** Al equipo de cada proyecto, cuando sus builds entran en cola: a qué hora suben. */
    private static function avisarNuevos(array $nuevos): void
    {
        $porProy = [];
        foreach ($nuevos as $b) $porProy[(int)$b['proyecto_id']][] = $b;
        foreach ($porProy as $pid => $bs) {
            $n = count($bs);
            $nombre = self::nombres([$pid]);
            $cuando = self::texto(self::proxima($pid));
            self::avisar(self::interesados([$pid]),
                $n === 1 ? 'Build listo de ' . $nombre : $n . ' builds listos de ' . $nombre,
                $n === 1 ? '«' . ($bs[0]['titulo'] !== '' ? $bs[0]['titulo'] : $bs[0]['rama']) . '»' . ($bs[0]['autor'] !== '' ? ' de ' . $bs[0]['autor'] : '') . ' sube ' . $cuando . '.'
                         : 'Suben ' . $cuando . '.');
        }
    }

    /**
     * A quién se avisa: el equipo de esos proyectos (miembros, PO y Scrum Master),
     * los administradores y quien lleva los despliegues; con $todos, además,
     * quien ve el módulo (visores y equipos marcados en Ajustes).
     */
    private static function interesados(array $pids, bool $todos = false): array
    {
        $cfg = configDeploys();
        $ids = array_merge($cfg['encargados'], $todos ? $cfg['visores'] : []);
        $repo = new ProyectoRepo();
        foreach ($pids as $pid) {
            $p = $repo->buscar((int)$pid);
            if (!$p) continue;
            $ids = array_merge($ids, (array)(ProyectoRepo::miembrosDe($p) ?? []), [ProyectoRepo::poDe($p), (int)($p['po_relevo'] ?? 0), ProyectoRepo::scrumDe($p)]);
        }
        foreach ((new MiembroRepo())->todos() as $m) {
            $acc = MiembroRepo::accesosDe($m);
            if (in_array('admin', $acc, true) || ($todos && $cfg['equipos'] && in_array(MiembroRepo::equipoDe($m), $cfg['equipos'], true))) $ids[] = (int)$m['id'];
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    }

    /** Campana (y aviso al teléfono): a quien puede ver Despliegues lo lleva ahí; a los demás, al inicio. */
    private static function avisar(array $para, string $titulo, string $texto, int $de = 0): void
    {
        if (!$para) return;
        if (!class_exists('Notif') && is_file(__DIR__ . '/Notificaciones.php')) require_once __DIR__ . '/Notificaciones.php';
        if (!class_exists('Notif')) return;
        $miembros = (new MiembroRepo())->mapa();
        $conModulo = $sinModulo = [];
        foreach ($para as $mid) {
            if (!isset($miembros[$mid])) continue;
            if (self::puedeVer($miembros[$mid])) $conModulo[] = $mid; else $sinModulo[] = $mid;
        }
        if ($conModulo) Notif::crear($conModulo, 'deploy', $titulo, $texto, 'deploys.php', $de);
        if ($sinModulo) Notif::crear($sinModulo, 'deploy', $titulo, $texto, 'index.php', $de);
    }

    /** ¿Esta persona entra a Despliegues? Lo mismo que puedeVerDeploys(), pero de cualquiera (el cron no tiene sesión). */
    public static function puedeVer(array $m): bool
    {
        $cfg = configDeploys();
        if (!$cfg['activo']) return false;
        $id = (int)$m['id'];
        $acc = MiembroRepo::accesosDe($m);
        if (in_array('admin', $acc, true) || in_array($id, $cfg['encargados'], true) || in_array($id, $cfg['visores'], true)) return true;
        if ($cfg['equipos'] && in_array(MiembroRepo::equipoDe($m), $cfg['equipos'], true)) return true;
        if (!$cfg['ver_po']) return false;
        if (in_array('scrum', $acc, true)) return true;
        foreach ((new ProyectoRepo())->todos() as $p) {
            if (esPOde($p, $id) || ProyectoRepo::scrumDe($p) === $id) return true;
        }
        return false;
    }

    /** «ms-academico, SIAU-AGRARIA» (el alias de cada proyecto, o su nombre). */
    private static function nombres(array $pids): string
    {
        $repo = new ProyectoRepo();
        return implode(', ', array_map(fn($pid) => aliasDeploy((int)$pid) ?: (string)(($repo->buscar((int)$pid) ?? [])['nombre'] ?? ('#' . $pid)), $pids));
    }
}
