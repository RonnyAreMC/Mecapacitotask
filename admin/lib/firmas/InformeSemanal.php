<?php
/**
 * Informe semanal de actividades: cada semana, el Hub le arma a cada persona
 * su informe con lo que hizo (tareas terminadas, requerimientos atendidos) y
 * lo que sigue en curso; la IA redacta el resumen. La persona lo revisa, lo
 * corrige si hace falta y lo firma (gráfica o electrónica); después lo firman
 * quienes el administrador eligió (una o dos personas) y, cuando queda
 * firmado, se le avisa a quien corresponda (Felipe, por ejemplo).
 *
 * Lo genera scripts/cron_correo.php (corre cada minuto) con tick(), el día y
 * la hora de la configuración, de a una persona por vuelta. También se puede
 * generar a mano: el administrador, los de todos; cada persona, el suyo.
 *
 * Configuración (Config 'informes'): activo, personas [uid], revisores
 * [[uid, rol, cargo], …] (hasta 2), avisar [uid], dia (1 = lunes … 5 =
 * viernes), hora ('16:00'), ia (bool).
 *
 * Cada informe es un documento de Firmas con tipo 'informe', semana
 * ('2026-W48'), persona, contenido (lo que se puede corregir) y observadores
 * (los que se avisan al final; también lo pueden ver).
 */
require_once __DIR__ . '/Firmas.php';
require_once __DIR__ . '/InformePdf.php';

final class InformeSemanal
{
    public const DIAS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes'];
    private const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    public static function conf(): array
    {
        $c = (array)(Config::get('informes') ?? []);
        $rev = [];
        foreach (array_slice((array)($c['revisores'] ?? []), 0, 2) as $r) {
            if ((int)($r['uid'] ?? 0) > 0) $rev[] = ['uid' => (int)$r['uid'], 'rol' => trim((string)($r['rol'] ?? '')) ?: 'Revisado por', 'cargo' => trim((string)($r['cargo'] ?? ''))];
        }
        $hora = (string)($c['hora'] ?? '16:00');
        return [
            'activo'    => !empty($c['activo']),
            'personas'  => array_values(array_unique(array_map('intval', (array)($c['personas'] ?? [])))),
            'revisores' => $rev,
            'avisar'    => array_values(array_unique(array_map('intval', (array)($c['avisar'] ?? [])))),
            'dia'       => max(1, min(5, (int)($c['dia'] ?? 5))),
            'hora'      => preg_match('/^\d{2}:\d{2}$/', $hora) ? $hora : '16:00',
            'ia'        => !array_key_exists('ia', $c) || !empty($c['ia']),
        ];
    }

    public static function guardarConf(array $c): void
    {
        Config::guardar(['informes' => $c]);
    }

    /** La semana de esa fecha: clave ISO, lunes, viernes y el texto «del 23 al 27 de noviembre de 2026». */
    public static function semana(?int $t = null): array
    {
        $t ??= time();
        $lunes = strtotime(date('Y-m-d', $t) . ' -' . ((int)date('N', $t) - 1) . ' days');
        $viernes = strtotime(date('Y-m-d', $lunes) . ' +4 days');
        $m1 = (int)date('n', $lunes) - 1;
        $m2 = (int)date('n', $viernes) - 1;
        $texto = $m1 === $m2
            ? 'del ' . (int)date('j', $lunes) . ' al ' . (int)date('j', $viernes) . ' de ' . self::MESES[$m2] . ' de ' . date('Y', $viernes)
            : 'del ' . (int)date('j', $lunes) . ' de ' . self::MESES[$m1] . (date('Y', $lunes) !== date('Y', $viernes) ? ' de ' . date('Y', $lunes) : '')
              . ' al ' . (int)date('j', $viernes) . ' de ' . self::MESES[$m2] . ' de ' . date('Y', $viernes);
        $corto = (int)date('j', $lunes) . '–' . (int)date('j', $viernes) . ' ' . substr(self::MESES[$m2], 0, 3) . ' ' . date('Y', $viernes);
        return [
            'clave' => date('o-\WW', $lunes), 'desde' => date('Y-m-d', $lunes), 'hasta' => date('Y-m-d', strtotime(date('Y-m-d', $lunes) . ' +6 days')),
            'viernes' => date('Y-m-d', $viernes), 'texto' => $texto, 'corto' => $corto,
        ];
    }

    /** El informe de esa persona y esa semana, si ya existe. */
    public static function deLaSemana(int $uid, string $clave): ?array
    {
        foreach (Firmas::todos() as $d) {
            if (($d['tipo'] ?? '') === 'informe' && (int)($d['persona'] ?? 0) === $uid && ($d['semana'] ?? '') === $clave) return $d;
        }
        return null;
    }

    /**
     * Lo que hizo la persona en la semana, todo con datos del Hub: tareas
     * cerradas y en curso, requerimientos sueltos, commits en los repositorios
     * de sus proyectos y las reuniones a las que estaba invitada (con su ingreso).
     */
    public static function datos(array $m, array $sem): array
    {
        $uid = (int)$m['id'];
        $proyectos = [];
        foreach ((new ProyectoRepo())->todos() as $p) $proyectos[(int)$p['id']] = $p;
        $nombreP = fn(int $id) => (string)($proyectos[$id]['nombre'] ?? '');
        $finales = Catalogo::estadosFinales();
        $hechas = [];
        $curso = [];
        $conTareas = [];
        foreach ((new TareaRepo())->todas() as $t) {
            if (!TareaRepo::tieneAsignado($t, $uid)) continue;
            $pid = (int)($t['proyecto_id'] ?? 0);
            $conTareas[$pid] = true;
            $fin = substr((string)($t['completada_en'] ?? ''), 0, 10);
            if (in_array($t['estado'] ?? '', $finales, true)) {
                if ($fin !== '' && $fin >= $sem['desde'] && $fin <= $sem['hasta']) {
                    $hechas[] = ['actividad' => (string)$t['titulo'], 'origen' => $nombreP($pid) ?: 'Tarea', 'fecha' => date('d/m/Y', strtotime($fin)), 'orden' => $fin];
                }
            } else {
                $curso[] = ['titulo' => (string)$t['titulo'], 'origen' => $nombreP($pid), 'limite' => (string)($t['fecha_limite'] ?? '')];
            }
        }
        // Requerimientos sueltos: los atendidos esta semana y los que sigue teniendo abiertos
        $reqs = [];
        $nReqs = 0;
        foreach ((new RequerimientoRepo())->todos() as $r) {
            if (!RequerimientoRepo::tieneAsignado($r, $uid)) continue;
            $fin = substr((string)($r['cerrado_en'] ?? ''), 0, 10);
            $est = (string)($r['estado'] ?? '');
            if ($est === 'hecho') {
                if ($fin === '' || $fin < $sem['desde'] || $fin > $sem['hasta']) continue;
                $nReqs++;
                $hechas[] = ['actividad' => (string)$r['titulo'], 'origen' => 'Requerimiento' . (($r['solicitante'] ?? '') !== '' ? ' de ' . $r['solicitante'] : ''), 'fecha' => date('d/m/Y', strtotime($fin)), 'orden' => $fin];
                $reqs[] = ['titulo' => (string)$r['titulo'], 'solicitante' => (string)($r['solicitante'] ?? ''), 'estado' => 'Atendido el ' . date('d/m', strtotime($fin))];
            } elseif ($est !== 'cancelado') {
                $lim = (string)($r['fecha_fin'] ?? $r['fecha_limite'] ?? '');
                $curso[] = ['titulo' => (string)$r['titulo'], 'origen' => 'Requerimiento', 'limite' => $lim];
                $reqs[] = ['titulo' => (string)$r['titulo'], 'solicitante' => (string)($r['solicitante'] ?? ''), 'estado' => 'Abierto' . ($lim !== '' ? ' · entrega ' . date('d/m', strtotime($lim)) : '')];
            }
        }
        usort($hechas, fn($a, $b) => strcmp($a['orden'], $b['orden']));
        usort($curso, fn($a, $b) => strcmp($a['limite'] ?: '9999', $b['limite'] ?: '9999'));
        [$commits, $porProy, $notaCommits] = self::commits($m, $sem, $proyectos, $conTareas);
        return [
            'hechas' => array_map(fn($h) => array_diff_key($h, ['orden' => 1]), $hechas),
            'curso' => array_slice($curso, 0, 15),
            'reqs' => array_slice($reqs, 0, 20),
            'n_reqs' => $nReqs,
            'n_tareas' => count(array_filter($hechas, fn($h) => !str_starts_with($h['origen'], 'Requerimiento'))),
            'commits' => $commits, 'commits_proyecto' => $porProy, 'commits_nota' => $notaCommits,
            'reuniones' => self::reuniones($uid, $sem),
        ];
    }

    /**
     * Los commits de la semana en los repositorios de sus proyectos, cruzados
     * por sus identidades de Git (usuario, correos de Git y su correo), igual
     * que el gráfico de aportes. [detalle (30), por proyecto, nota].
     */
    private static function commits(array $m, array $sem, array $proyectos, array $conTareas): array
    {
        $ids = [];
        foreach (preg_split('/[,;]+/', (string)($m['git_user'] ?? '')) as $u) {
            $u = mb_strtolower(trim($u, " \t@"), 'UTF-8');
            if ($u !== '') $ids[$u] = true;
        }
        foreach (MiembroRepo::gitEmailsDe($m) as $mail) { $ids[$mail] = true; $ids[(string)strtok($mail, '@')] = true; }
        if (!$ids) return [[], [], 'No tiene usuario ni correo de Git en su perfil: no se pueden contar sus commits.'];
        $uid = (int)$m['id'];
        $vistos = [];
        $detalle = [];
        $porProy = [];
        $conRepo = 0;
        $fallas = [];
        foreach ($proyectos as $pid => $p) {
            $miembros = ProyectoRepo::miembrosDe($p);
            if (!($miembros === null ? isset($conTareas[$pid]) : in_array($uid, $miembros, true))) continue;   // solo los suyos
            foreach (ProyectoRepo::repos($p) as $rp) {
                $conRepo++;
                $cr = Repos::commitsRecientes($rp['url'], 300, $rp['rama'], 10);
                if (($cr['estado'] ?? '') !== 'ok') { if (($cr['estado'] ?? '') === 'error') $fallas[] = $rp['label']; continue; }
                foreach ($cr['commits'] as $c) {
                    $f = (string)($c['fecha'] ?? '');
                    if ($f < $sem['desde'] || $f > $sem['hasta']) continue;
                    $suyo = isset($ids[strtolower((string)($c['login'] ?? ''))]) || isset($ids[strtolower((string)($c['email'] ?? ''))])
                         || isset($ids[mb_strtolower(trim((string)($c['nombre'] ?? '')), 'UTF-8')]);
                    $sha = (string)($c['sha'] ?? '');
                    if (!$suyo || isset($vistos[$sha])) continue;
                    $vistos[$sha] = true;
                    $nom = (string)$p['nombre'];
                    $porProy[$nom] ??= ['proyecto' => $nom, 'repos' => [], 'commits' => 0, 'ultimo' => ''];
                    $porProy[$nom]['repos'][$rp['label']] = true;
                    $porProy[$nom]['commits']++;
                    if ($f > $porProy[$nom]['ultimo']) $porProy[$nom]['ultimo'] = $f;
                    $detalle[] = ['fecha' => $f, 'proyecto' => $nom, 'mensaje' => mb_strimwidth(trim(strtok((string)($c['msg'] ?? ''), "\n")), 0, 140, '…')];
                }
            }
        }
        usort($detalle, fn($a, $b) => strcmp($b['fecha'], $a['fecha']));
        $porProy = array_values(array_map(fn($x) => ['proyecto' => $x['proyecto'], 'repos' => implode(', ', array_keys($x['repos'])),
            'commits' => $x['commits'], 'ultimo' => $x['ultimo'] !== '' ? date('d/m/Y', strtotime($x['ultimo'])) : ''], $porProy));
        usort($porProy, fn($a, $b) => $b['commits'] <=> $a['commits']);
        $nota = !$conRepo ? 'Sus proyectos no tienen repositorio configurado en el Hub.'
            : ($fallas ? 'No se pudieron leer estos repositorios: ' . implode(', ', array_unique($fallas)) . '.' : '');
        if (!$porProy && $nota === '') $nota = 'No se registraron commits esta semana en los repositorios de sus proyectos (rama configurada en el Hub).';
        return [array_map(fn($d) => ['fecha' => date('d/m/Y', strtotime($d['fecha']))] + $d, array_slice($detalle, 0, 30)), $porProy, $nota];
    }

    /** Las reuniones de la semana a las que estaba invitada, con si pulsó «Entrar» en el Hub. */
    private static function reuniones(int $uid, array $sem): array
    {
        $ingresos = [];
        foreach ((new JsonStore('reunion_ingresos'))->all() as $x) {
            if ((int)($x['miembro_id'] ?? 0) !== $uid) continue;
            $t = (string)($x['t'] ?? '');
            $ingresos[(int)($x['reunion_id'] ?? 0)][substr($t, 0, 10)] ??= substr($t, 11, 5);
        }
        $out = [];
        $hoy = date('Y-m-d');
        foreach ((new ReunionRepo())->todas() as $r) {
            if (!in_array($uid, array_map('intval', (array)($r['invitados'] ?? [])), true) && (int)($r['creador_id'] ?? 0) !== $uid) continue;
            $inicio = (string)($r['inicio'] ?? '');
            if ($inicio === '') continue;
            $dia0 = substr($inicio, 0, 10);
            $hora = substr($inicio, 11, 5);
            $dias = [];
            if (!empty($r['recurrente'])) {
                $hasta = (string)($r['hasta'] ?? '') ?: $sem['hasta'];
                for ($d = $sem['desde']; $d <= $sem['hasta']; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
                    if ($d >= $dia0 && $d <= $hasta && in_array((int)date('N', strtotime($d)), array_map('intval', (array)($r['dias'] ?? [])), true)) $dias[] = $d;
                }
            } elseif ($dia0 >= $sem['desde'] && $dia0 <= $sem['hasta']) {
                $dias[] = $dia0;
            }
            foreach ($dias as $d) {
                if ($d > $hoy) continue;   // las que todavía no pasan no cuentan
                $entro = $ingresos[(int)$r['id']][$d] ?? '';
                $out[] = ['tema' => trim((string)($r['topic'] ?? '')) ?: 'Reunión', 'fecha' => date('d/m', strtotime($d)) . ' · ' . $hora,
                          'asistio' => $entro !== '', 'entro' => $entro, 'orden' => $d . ' ' . $hora];
            }
        }
        usort($out, fn($a, $b) => strcmp($a['orden'], $b['orden']));
        return array_map(fn($x) => array_diff_key($x, ['orden' => 1]), $out);
    }

    /**
     * El resumen corto. Por defecto se arma con los datos; si la IA está
     * encendida, solo la usa para redactar 2 o 3 oraciones con esos mismos
     * números (nada más sale de la IA). Los pendientes salen de los datos.
     */
    public static function redactar(array $m, array $sem, array $d, bool $conIa): array
    {
        $nC = array_sum(array_map(fn($p) => $p['commits'], $d['commits_proyecto']));
        $nP = count($d['commits_proyecto']);
        $nT = (int)$d['n_tareas'];
        $nR = (int)$d['n_reqs'];
        $reu = $d['reuniones'];
        $asis = count(array_filter($reu, fn($r) => $r['asistio']));
        $partes = [];
        if ($nC) $partes[] = 'registré ' . $nC . ($nC === 1 ? ' commit' : ' commits') . ' en ' . $nP . ($nP === 1 ? ' proyecto' : ' proyectos');
        if ($nT) $partes[] = 'cerré ' . $nT . ($nT === 1 ? ' tarea' : ' tareas');
        if ($nR) $partes[] = 'atendí ' . $nR . ($nR === 1 ? ' requerimiento suelto' : ' requerimientos sueltos');
        $base = 'Durante la semana ' . $sem['texto'] . ' ' . ($partes ? implode(', ', array_slice($partes, 0, -1)) . (count($partes) > 1 ? ' y ' : '') . end($partes) : 'no cerré actividades registradas en el Hub')
            . '.' . ($reu ? ' Ingresé a ' . $asis . ' de ' . count($reu) . ' reuniones agendadas.' : '')
            . (count($d['curso']) ? ' Sigo con ' . count($d['curso']) . (count($d['curso']) === 1 ? ' pendiente' : ' pendientes') . ' en curso.' : '');
        $pend = array_map(fn($c) => $c['titulo'] . ($c['origen'] !== '' ? ' (' . $c['origen'] . ')' : '') . ($c['limite'] !== '' ? ' · entrega ' . date('d/m', strtotime($c['limite'])) : ''), array_slice($d['curso'], 0, 8));
        $out = ['resumen' => $base, 'pendientes' => $pend, 'observaciones' => '', 'ia' => false];
        if (!$conIa || !is_file(__DIR__ . '/../ai/IA.php')) return $out;
        require_once __DIR__ . '/../ai/IA.php';
        if (!IA::lista()) return $out;
        $sistema = 'Resume en 2 o 3 oraciones, en primera persona y en español de Ecuador, la semana de trabajo de un desarrollador. '
            . 'Usa SOLO los datos que te dan (números, proyectos y títulos); no inventes nada ni agregues opiniones. Devuelve JSON: {"resumen": "…"}.';
        $texto = json_encode([
            'semana' => $sem['texto'], 'commits' => $nC, 'commits_por_proyecto' => $d['commits_proyecto'],
            'tareas_cerradas' => array_column(array_slice($d['hechas'], 0, 12), 'actividad'), 'requerimientos_atendidos' => $nR,
            'reuniones' => ['agendadas' => count($reu), 'ingreso' => $asis], 'en_curso' => array_column(array_slice($d['curso'], 0, 6), 'titulo'),
        ], JSON_UNESCAPED_UNICODE);
        try {
            $r = IA::completar('informe_semanal', $sistema, $texto, [], ['json' => true, 'max' => 300]);
            $res = trim((string)(IA::json((string)$r['texto'])['resumen'] ?? ''));
            if ($res !== '') { $out['resumen'] = $res; $out['ia'] = true; }
        } catch (\Throwable $e) {
            error_log('Informe semanal: la IA no respondió: ' . $e->getMessage());
        }
        return $out;
    }

    /** El PDF del informe con ese contenido. [bytes, páginas, nombre de archivo] */
    public static function pdf(array $c, string $archivo): array
    {
        $bytes = InformePdf::crear($c);
        return [$bytes, (new PdfFirmas($bytes))->paginas(), $archivo];
    }

    /**
     * Arma el informe de esa persona (o devuelve el que ya existe) y le avisa
     * que está listo para revisar y firmar. Devuelve el documento o el error.
     */
    public static function generar(array $m, ?array $sem = null, int $por = 0): array|string
    {
        $sem ??= self::semana();
        if ($ya = self::deLaSemana((int)$m['id'], $sem['clave'])) return $ya + ['_ya' => true];
        $conf = self::conf();
        $d = self::datos($m, $sem);
        $red = self::redactar($m, $sem, $d, $conf['ia']);
        $eqs = Catalogo::equipos();
        $eq = MiembroRepo::equipoDe($m);
        // Lo firman los revisores de su coordinación (Parámetros de firmas); si no está en ninguna, los de la configuración
        $firmantes = [['uid' => (int)$m['id'], 'rol' => 'Elaborado por', 'cargo' => Firmas::cargoDe($m)]];
        $coord = Firmas::coordinacionDe((int)$m['id']);
        $revisores = $coord ? Firmas::revisoresDe($coord, (int)$m['id'])
            : array_map(fn($r) => $r + ['cargo' => ''], array_filter($conf['revisores'], fn($r) => $r['uid'] !== (int)$m['id']));
        foreach ($revisores as $r) $firmantes[] = ['uid' => $r['uid'], 'rol' => $r['rol'], 'cargo' => $r['cargo'] !== '' ? $r['cargo'] : Firmas::cargoDe($r['uid'])];
        $ultimo = count($firmantes) > 1 ? end($firmantes) : null;
        $nC = array_sum(array_map(fn($p) => $p['commits'], $d['commits_proyecto']));
        $contenido = [
            'org' => (string)(Config::get('titulo') ?: 'InnoTech'), 'persona' => (string)$m['nombre'], 'cargo' => Firmas::cargoDe($m),
            'equipo' => (string)($coord['nombre'] ?? ($eqs[$eq][0] ?? '')), 'semana' => $sem['texto'], 'elaborado' => date('d/m/Y'),
            'codigo' => 'IS-' . str_replace('-W', '-S', $sem['clave']) . '-' . (int)$m['id'],
            'para' => $ultimo ? ['nombre' => Firmas::nombre($ultimo['uid']), 'cargo' => $ultimo['cargo']] : [],
            'kpi' => ['commits' => $nC, 'proyectos' => count($d['commits_proyecto']), 'tareas' => (int)$d['n_tareas'], 'curso' => count($d['curso']),
                      'reqs' => (int)$d['n_reqs'], 'reu_conv' => count($d['reuniones']), 'reu_asis' => count(array_filter($d['reuniones'], fn($r) => $r['asistio']))],
            'resumen' => $red['resumen'], 'actividades' => $d['hechas'], 'extra' => [], 'pendientes' => $red['pendientes'],
            'commits_proyecto' => $d['commits_proyecto'], 'commits' => $d['commits'], 'commits_nota' => $d['commits_nota'],
            'reqs' => $d['reqs'], 'reuniones' => $d['reuniones'],
            'observaciones' => $red['observaciones'], 'ia' => $red['ia'],
        ];
        $archivo = 'Informe semanal ' . $m['nombre'] . ' ' . $sem['clave'] . '.pdf';
        try {
            $subida = self::pdf($contenido, $archivo);
        } catch (\Throwable $e) {
            return 'No se pudo armar el PDF del informe: ' . $e->getMessage();
        }
        $firmantes = array_map(fn($f) => $f + ['estado' => 'pendiente', 'fecha' => '', 'ip' => '', 'png' => '', 'nombre' => '', 'motivo' => ''], $firmantes);
        $doc = Firmas::crear($m, 'Informe semanal de actividades · ' . $m['nombre'] . ' · ' . $sem['corto'], $subida, $firmantes, false, [
            'tipo' => 'informe', 'semana' => $sem['clave'], 'persona' => (int)$m['id'], 'contenido' => $contenido,
            'observadores' => array_values(array_diff($conf['avisar'], [(int)$m['id']])),
        ], $por > 0 ? ['id' => $por] : ['id' => 0]);
        $revs = array_map(fn($r) => Firmas::nombre($r['uid']), array_slice($firmantes, 1));
        $url = 'firma.php?id=' . $doc['id'];
        if (class_exists('Notif')) {
            Notif::crear([(int)$m['id']], 'firma', 'Tu informe semanal está listo', 'Revísalo y fírmalo' . ($revs ? ': se envía a ' . implode(' y ', $revs) : ''), $url, 0);
        }
        if (class_exists('Mailer')) {
            Mailer::avisoFirma($m, 'Tu informe semanal: ' . $sem['corto'], 'Tu informe semanal está listo',
                'armamos tu informe de actividades de la semana ' . e($sem['texto']) . ' con lo que registraste en el Hub. Revísalo, corrige lo que haga falta y fírmalo.',
                ['Documento' => 'Informe semanal de actividades', 'Semana' => $sem['texto'], 'Commits' => (string)$nC, 'Tareas cerradas' => (string)$d['n_tareas'], 'Lo firman después' => implode(' y ', $revs)],
                '', $url, '#2B76F7', 'Revisar y firmar');
        }
        return $doc;
    }

    /**
     * Guarda lo que la persona corrigió (resumen, otras actividades, pendientes,
     * observaciones) y vuelve a armar el PDF. Si ya había firmas, el flujo empieza de nuevo.
     */
    public static function editarContenido(array $doc, array $yo, array $post): true|string
    {
        if (($doc['tipo'] ?? '') !== 'informe') return 'Este documento no es un informe semanal.';
        if (($doc['estado'] ?? '') === 'firmado') return 'El informe ya está firmado por todos.';
        $lineas = fn(string $t) => array_values(array_filter(array_map(fn($l) => mb_substr(trim(ltrim($l, "-•* \t")), 0, 300), preg_split('/\R/u', $t) ?: []), fn($l) => $l !== ''));
        $c = (array)($doc['contenido'] ?? []);
        $c['resumen'] = mb_substr(trim((string)($post['resumen'] ?? '')), 0, 4000);
        $c['extra'] = array_slice($lineas((string)($post['extra'] ?? '')), 0, 30);
        $c['pendientes'] = array_slice($lineas((string)($post['pendientes'] ?? '')), 0, 20);
        $c['observaciones'] = mb_substr(trim((string)($post['observaciones'] ?? '')), 0, 3000);
        if ($c['resumen'] === '') return 'El resumen no puede quedar vacío.';
        try {
            $subida = self::pdf($c, (string)($doc['archivo'] ?? 'Informe semanal.pdf'));
        } catch (\Throwable $e) {
            return 'No se pudo armar el PDF del informe: ' . $e->getMessage();
        }
        $firmantes = array_map(fn($f) => ['uid' => (int)$f['uid'], 'rol' => (string)$f['rol'], 'cargo' => (string)$f['cargo'],
            'estado' => 'pendiente', 'fecha' => '', 'ip' => '', 'png' => '', 'nombre' => '', 'motivo' => ''], $doc['firmantes'] ?? []);
        $r = Firmas::editar($doc, $yo, (string)$doc['titulo'], $firmantes, $subida);
        if ($r !== true) return $r;
        Firmas::guardarCampos((int)$doc['id'], ['contenido' => $c]);
        return true;
    }

    /**
     * Desde el cron, cada minuto: si ya es el día y la hora de esta semana, arma
     * el informe de la siguiente persona que no lo tiene (uno por vuelta, porque
     * la IA tarda). Devuelve lo que hizo, para el registro.
     */
    public static function tick(): string
    {
        $c = self::conf();
        if (!$c['activo'] || !$c['personas']) return '';
        $sem = self::semana();
        $momento = strtotime($sem['desde'] . ' +' . ($c['dia'] - 1) . ' days ' . $c['hora']);
        if (time() < $momento) return '';
        if (class_exists('Feriados') && Feriados::hoy() !== '' && date('Y-m-d') === date('Y-m-d', $momento)) {
            // Feriado: se arma el siguiente día hábil (o el lunes, con la semana que pasó)
            return '';
        }
        $intentos = self::intentos();
        $miembros = (new MiembroRepo())->mapa();
        foreach ($c['personas'] as $uid) {
            $m = $miembros[$uid] ?? null;
            if (!$m || MiembroRepo::deBaja($m) || self::deLaSemana($uid, $sem['clave'])) continue;   // de baja: ya no hace informe
            $k = $sem['clave'] . ':' . $uid;
            if (($intentos[$k] ?? 0) >= 3) continue;   // falló tres veces: no insistir cada minuto
            $r = self::generar($m, $sem);
            if (is_string($r)) {
                $intentos[$k] = ($intentos[$k] ?? 0) + 1;
                self::intentos($intentos);
                return 'informe semanal de ' . $m['nombre'] . ': ' . $r;
            }
            return 'informe semanal de ' . $m['nombre'] . ' listo (documento ' . $r['id'] . ')';
        }
        return '';
    }

    private static function intentos(?array $nuevos = null): array
    {
        $f = Firmas::carpeta() . '/informes_intentos.json';
        if ($nuevos !== null) { @file_put_contents($f, json_encode($nuevos)); return $nuevos; }
        $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
        return is_array($d) ? $d : [];
    }
}
