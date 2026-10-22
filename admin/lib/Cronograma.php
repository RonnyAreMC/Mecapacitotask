<?php
/**
 * Horario de subidas y deploys de GitLab.
 *
 * Cada proyecto tiene su HORA LÍMITE (por defecto las 10:00 de lunes a viernes;
 * la Agraria, p. ej., a las 15:00): hasta esa hora el equipo tiene que dejar
 * subidos sus cambios. Despliegues la muestra con su cuenta regresiva y, unos
 * minutos antes, se le recuerda al equipo.
 *
 * Los deploys NO esperan a esa hora: cuando un pipeline de GitLab termina bien
 * y trae jobs de deploy (en la rama que sea, p. ej. «build»), queda registrado
 * en ese momento, con su hora real, los proyectos de ese repo y las tareas
 * completadas que nadie había subido, y se avisa a todos. Si alguien ya lo
 * registró a mano mientras corría el pipeline, se le junta a ese registro.
 * Quién lanzó el pipeline no sale en ningún lado (lo pidió así el equipo).
 *
 * Lo mueve el cron de cada minuto (cron_correo.php) o cron_deploys.php:
 *  1. buscar(): cada 5 minutos pregunta a GitLab por los deploys nuevos.
 *  2. avisoPrevio(): unos minutos antes de la hora límite, el recordatorio.
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

    /** La primera vez se mira tres días atrás: así entra el deploy de ayer que nadie registró. */
    private const PRIMERA_VEZ = 3 * 86400;

    /** Un deploy más viejo que esto se registra sin avisar: es ponerse al día, no una novedad. */
    private const AVISAR_HASTA = 2 * 3600;

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

    /** ¿Puede leer deploys? GitLab encendido en el cronograma y el token puesto en Ajustes. */
    public static function conGitLab(): bool
    {
        return self::conf()['gitlab'] && trim((string)(Config::get('gitlab_token') ?? '')) !== '';
    }

    /** La hora límite de un proyecto: la suya o la general ($pid 0 = la general). */
    public static function horaDe(int $pid = 0): string
    {
        $c = self::conf();
        return $c['horas'][$pid] ?? $c['hora'];
    }

    /** Los proyectos que siguen el horario: los de Despliegues que no están completados. */
    public static function proyectos(): array
    {
        return array_values(array_filter((new ProyectoRepo())->todos(),
            fn($p) => ($p['estado'] ?? 'activo') !== 'completado' && proyectoConDeploys((int)$p['id'])));
    }

    /**
     * Los repos de GitLab de esos proyectos, cada uno una vez: varios proyectos
     * pueden vivir en el mismo repo (un monorepo con un módulo por proyecto), y
     * un deploy de ese repo los sube a todos.
     * ['host/ruta' => ['url','ruta','pids']]
     */
    private static function repos(): array
    {
        $out = [];
        foreach (self::proyectos() as $p) {
            foreach (ProyectoRepo::repos($p) as $r) {
                $url = (string)($r['url'] ?? '');
                if (Repos::proveedor($url) !== 'gitlab' || !($rp = GitLab::parsearRepo($url))) continue;
                $k = strtolower($rp[0] . '/' . $rp[1]);
                $out[$k] ??= ['url' => $url, 'ruta' => $rp[1], 'pids' => []];
                if (!in_array((int)$p['id'], $out[$k]['pids'], true)) $out[$k]['pids'][] = (int)$p['id'];
            }
        }
        return $out;
    }

    private static function archivoEstado(): string
    {
        return __DIR__ . '/../data/cronograma.json';
    }

    /** Lo que recuerda entre vueltas: última búsqueda, avisos por proyecto, desde dónde sigue cada repo… */
    public static function estado(): array
    {
        $f = self::archivoEstado();
        return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }

    private static function guardarEstado(array $cambios): void
    {
        @file_put_contents(self::archivoEstado(), json_encode(array_replace(self::estado(), $cambios), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    /* ======================= La hora límite ======================= */

    /** La hora límite de un día (timestamp), o 0 si ese día no hay. */
    private static function ventanaDelDia(int $dia, string $hora): int
    {
        if (!in_array((int)date('N', $dia), self::conf()['dias'], true)) return 0;
        [$h, $m] = array_map('intval', explode(':', $hora));
        return mktime($h, $m, 0, (int)date('n', $dia), (int)date('j', $dia), (int)date('Y', $dia));
    }

    /** La primera hora límite del proyecto en $t o después. */
    public static function siguiente(int $t, int $pid = 0): int
    {
        $hora = self::horaDe($pid);
        for ($i = 0; $i <= 14; $i++) {
            $v = self::ventanaDelDia(strtotime(date('Y-m-d', $t) . ' +' . $i . ' day'), $hora);
            if ($v >= $t) return $v;
        }
        return 0;
    }

    /** La próxima hora límite de un proyecto. */
    public static function proxima(int $pid = 0, ?int $ahora = null): int
    {
        return self::siguiente($ahora ?? time(), $pid);
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
     * El horario para mostrarlo: una fila por hora límite con sus proyectos y
     * la próxima, ordenadas por la más cercana.
     * [['hora','proxima','proyectos' => [[id, nombre, alias, p]]]]
     */
    public static function agenda(?int $ahora = null): array
    {
        $ahora ??= time();
        $filas = [];
        foreach (self::proyectos() as $p) {
            $pid = (int)$p['id'];
            $hora = self::horaDe($pid);
            $prox = self::proxima($pid, $ahora);
            $k = $hora . '|' . $prox;
            $filas[$k] ??= ['hora' => $hora, 'proxima' => $prox, 'proyectos' => []];
            $filas[$k]['proyectos'][] = ['id' => $pid, 'nombre' => (string)$p['nombre'], 'alias' => aliasDeploy($pid), 'p' => $p];
        }
        $filas = array_values($filas);
        usort($filas, fn($a, $b) => $a['proxima'] <=> $b['proxima']);
        return $filas;
    }

    /* ======================= Los deploys de GitLab ======================= */

    /** Los pipelines de GitLab que se llevó un despliegue. */
    public static function deDeploy(array $deploy): array
    {
        $ids = array_flip(array_map('intval', (array)($deploy['builds'] ?? [])));
        if (!$ids) return [];
        $l = array_values(array_filter(self::builds()->all(), fn($b) => isset($ids[(int)$b['id']])));
        usort($l, fn($a, $b) => (int)$a['terminado_t'] <=> (int)$b['terminado_t']);
        return $l;
    }

    /** El último deploy que se vio en GitLab de alguno de esos proyectos (null si ninguno). */
    public static function ultimoDeGitLab(array $pids): ?array
    {
        $pids = array_flip(array_map('intval', $pids));
        $ult = null;
        foreach (self::builds()->all() as $b) {
            if (($b['estado'] ?? '') !== 'subido' || !array_intersect_key(array_flip(array_map('intval', (array)($b['proyectos'] ?? [$b['proyecto_id'] ?? 0]))), $pids)) continue;
            if (!$ult || (int)$b['terminado_t'] > (int)$ult['terminado_t']) $ult = $b;
        }
        return $ult;
    }

    /**
     * Pregunta a GitLab por los deploys nuevos (cada 5 minutos, o ya con $forzar)
     * y registra cada uno con su hora real. Devuelve los despliegues que creó.
     */
    public static function buscar(bool $forzar = false): array
    {
        if (!self::activo() || !self::conGitLab()) return [];
        $e = self::estado();
        if (!$forzar && time() - (int)($e['ultima_busqueda'] ?? 0) < self::BUSCAR_CADA) return [];
        self::guardarEstado(['ultima_busqueda' => time()]);

        $store = self::builds();
        $vistos = [];
        foreach ($store->all() as $b) {
            // Lo que quedó «en cola» del horario de antes no cuenta como visto: se registra ahora
            if (($b['estado'] ?? '') === 'subido') $vistos[(string)$b['llave']] = true;
            else $store->delete((int)$b['id']);
        }
        $desdeRepo = (array)($e['deploys_desde'] ?? []);
        $errores = [];
        $creados = [];
        $inicio = time();
        foreach (self::repos() as $k => $r) {
            if (time() - $inicio > 25) break;                  // lo que falte, en la próxima vuelta
            $desde = (string)($desdeRepo[$k] ?? gmdate('Y-m-d\TH:i:s\Z', time() - self::PRIMERA_VEZ));
            $res = GitLab::deploysExitosos($r['url'], $desde);
            if ($res === null) { $errores[] = $r['ruta']; continue; }
            $desdeRepo[$k] = $res['hasta'];
            foreach ($res['deploys'] as $pl) {
                $llave = $k . '#' . (int)$pl['id'];
                if (isset($vistos[$llave])) continue;
                $vistos[$llave] = true;
                $b = $store->insert([
                    'llave'       => $llave,
                    'proyecto_id' => $r['pids'][0],
                    'proyectos'   => $r['pids'],
                    'repo'        => $r['ruta'],
                    'ruta'        => $r['ruta'],
                    'rama'        => (string)$pl['ref'],
                    'pipeline_id' => (int)$pl['id'],
                    'iid'         => (int)$pl['iid'],
                    'sha'         => substr((string)$pl['sha'], 0, 40),
                    'titulo'      => (string)$pl['titulo'],
                    'jobs'        => (array)$pl['jobs'],
                    'web_url'     => preg_match('#^https?://#i', (string)$pl['web_url']) ? (string)$pl['web_url'] : '',
                    'inicio_t'    => (int)$pl['inicio'],
                    'terminado'   => date('Y-m-d H:i', (int)$pl['terminado']),
                    'terminado_t' => (int)$pl['terminado'],
                    'estado'      => 'subido',
                    'deploy_id'   => 0,
                    'visto'       => date('Y-m-d H:i'),
                ]);
                $dep = self::registrar($b, $r['pids']);
                if ($dep) $creados[] = $dep;
            }
        }
        self::guardarEstado(['deploys_desde' => $desdeRepo, 'errores' => array_values(array_unique($errores)),
                             'ultima_ok' => $errores ? (int)($e['ultima_ok'] ?? 0) : time()]);
        return $creados;
    }

    /**
     * El despliegue de un deploy de GitLab. Si alguien ya lo registró a mano
     * mientras corría el pipeline (o justo al terminar), es ese mismo: se le
     * junta y no se avisa otra vez. Si no, uno nuevo con su hora real, las
     * tareas completadas que nadie había subido y el aviso a todos.
     */
    private static function registrar(array $b, array $pids): ?array
    {
        $deploys = new DeployRepo();
        $ini = (int)$b['inicio_t'];
        $fin = (int)$b['terminado_t'];
        foreach ($deploys->todos() as $d) {
            if (($d['origen'] ?? 'manual') !== 'manual' || !empty($d['builds'])) continue;
            $t = strtotime((string)$d['fecha']);
            if ($t === false || $t < $ini - 600 || $t > $fin + 1800) continue;
            if (!array_intersect(array_map('intval', DeployRepo::proyectosDe($d)), $pids)) continue;
            $deploys->actualizar((int)$d['id'], ['builds' => [(int)$b['id']]]);
            self::builds()->update((int)$b['id'], ['deploy_id' => (int)$d['id']]);
            return null;
        }

        // Las tareas completadas hasta que arrancó el pipeline y que ningún despliegue se llevó
        $tareas = new TareaRepo();
        $finales = Catalogo::estadosFinales();
        $hasta = date('Y-m-d H:i', $ini);
        $pend = [];
        foreach ($pids as $pid) {
            foreach ($tareas->delProyecto($pid) as $t) {
                if (!in_array($t['estado'] ?? '', $finales, true) || (int)($t['deploy_id'] ?? 0) !== 0) continue;
                if ((string)($t['completada_en'] ?? '') > $hasta) continue;
                $pend[] = (int)$t['id'];
            }
        }
        $cfg = configDeploys();
        $nota = 'Deploy de GitLab · pipeline #' . (int)($b['iid'] ?: $b['pipeline_id']) . ' en ' . $b['rama']
            . ' · empezó a las ' . date('g:i a', $ini) . ' y terminó a las ' . date('g:i a', $fin) . '.'
            . ($b['titulo'] !== '' ? "\n«" . $b['titulo'] . '»' : '');
        $dep = $deploys->crear([
            'proyectos' => $pids, 'autor_id' => 0, 'entorno' => $cfg['entorno'], 'fecha' => date('Y-m-d H:i', $fin),
            'nota' => mb_substr($nota, 0, 1500), 'tareas' => $pend, 'origen' => 'gitlab', 'builds' => [(int)$b['id']],
        ]);
        foreach ($pend as $tid) $tareas->actualizar($tid, ['deploy_id' => (int)$dep['id'], 'desplegada_en' => $dep['fecha']]);
        self::builds()->update((int)$b['id'], ['deploy_id' => (int)$dep['id']]);

        if (time() - $fin <= self::AVISAR_HASTA) {
            $nt = count($pend);
            self::avisar(self::interesados($pids, true), 'Se subieron los cambios a ' . $cfg['entorno'],
                self::nombres($pids) . ($nt ? ' · ' . $nt . ' tarea' . ($nt === 1 ? '' : 's') : '')
                . ' · deploy de GitLab terminado a las ' . date('g:i a', $fin) . '.');
        }
        return $dep;
    }

    /* ======================= La vuelta del cron ======================= */

    /** Una vuelta (cada minuto): busca deploys en GitLab y avisa antes de la hora límite. Devuelve qué hizo. */
    public static function paso(?int $ahora = null): array
    {
        $ahora ??= time();
        $res = ['nuevos' => 0, 'avisos' => 0, 'deploys' => []];
        if (!self::activo()) return $res;
        $candado = @fopen(__DIR__ . '/../data/cronograma.lock', 'c');
        if (!$candado || !flock($candado, LOCK_EX | LOCK_NB)) return $res;
        try {
            $res['deploys'] = array_map(fn($d) => (int)$d['id'], self::buscar());
            $res['nuevos'] = count($res['deploys']);
            $res['avisos'] = self::avisoPrevio($ahora);
        } finally {
            flock($candado, LOCK_UN);
            fclose($candado);
        }
        return $res;
    }

    /** Unos minutos antes de la hora límite de cada proyecto, el recordatorio a su equipo. Una vez por día. Devuelve cuántos avisos. */
    public static function avisoPrevio(?int $ahora = null): int
    {
        $ahora ??= time();
        $min = (int)self::conf()['aviso'];
        if ($min <= 0) return 0;
        $avisados = (array)(self::estado()['avisos'] ?? []);
        $porHora = [];
        foreach (self::proyectos() as $p) {
            $pid = (int)$p['id'];
            $v = self::siguiente($ahora, $pid);
            if ($v <= 0 || $v - $ahora > $min * 60 || (int)($avisados[$pid] ?? 0) >= $v) continue;
            $avisados[$pid] = $v;
            $porHora[$v][] = $pid;
        }
        if (!$porHora) return 0;
        self::guardarEstado(['avisos' => $avisados]);
        foreach ($porHora as $v => $pids) {
            self::avisar(self::interesados($pids), 'Hoy a las ' . self::horaTexto(date('H:i', (int)$v)) . ' es la hora límite para subir los cambios',
                'Deja subido lo de ' . self::nombres($pids) . ' antes de esa hora.');
        }
        return count($porHora);
    }

    /** Alguien registró la subida a mano: se avisa a todos. */
    public static function alRegistrarAMano(array $deploy): void
    {
        $pids = array_map('intval', DeployRepo::proyectosDe($deploy));
        $nt = count(DeployRepo::tareasDe($deploy));
        self::avisar(self::interesados($pids, true), 'Se subieron los cambios a ' . (string)($deploy['entorno'] ?? configDeploys()['entorno']),
            self::nombres($pids) . ($nt ? ' · ' . $nt . ' tarea' . ($nt === 1 ? '' : 's') : '')
            . ' · a las ' . self::horaTexto(substr((string)$deploy['fecha'], 11, 5)) . '.', (int)($deploy['autor_id'] ?? 0));
    }

    /* ======================= Avisos ======================= */

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
