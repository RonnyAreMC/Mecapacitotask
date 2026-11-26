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

    /** Lo que hizo la persona en la semana y lo que sigue abierto, con datos del Hub. */
    public static function datos(array $m, array $sem): array
    {
        $uid = (int)$m['id'];
        $proyectos = [];
        foreach ((new ProyectoRepo())->todos() as $p) $proyectos[(int)$p['id']] = (string)($p['nombre'] ?? '');
        $finales = Catalogo::estadosFinales();
        $hechas = [];
        $curso = [];
        foreach ((new TareaRepo())->todas() as $t) {
            if (!TareaRepo::tieneAsignado($t, $uid)) continue;
            $proy = $proyectos[(int)($t['proyecto_id'] ?? 0)] ?? '';
            $fin = substr((string)($t['completada_en'] ?? ''), 0, 10);
            if (in_array($t['estado'] ?? '', $finales, true)) {
                if ($fin !== '' && $fin >= $sem['desde'] && $fin <= $sem['hasta']) {
                    $hechas[] = ['actividad' => (string)$t['titulo'], 'origen' => $proy !== '' ? $proy : 'Tarea', 'fecha' => date('d/m/Y', strtotime($fin)), 'orden' => $fin];
                }
            } else {
                $curso[] = ['titulo' => (string)$t['titulo'], 'origen' => $proy, 'limite' => (string)($t['fecha_limite'] ?? '')];
            }
        }
        foreach ((new RequerimientoRepo())->todos() as $r) {
            if (!RequerimientoRepo::tieneAsignado($r, $uid)) continue;
            $fin = substr((string)($r['cerrado_en'] ?? ''), 0, 10);
            if (($r['estado'] ?? '') === 'hecho') {
                if ($fin !== '' && $fin >= $sem['desde'] && $fin <= $sem['hasta']) {
                    $hechas[] = ['actividad' => (string)$r['titulo'], 'origen' => 'Requerimiento' . (($r['solicitante'] ?? '') !== '' ? ' de ' . $r['solicitante'] : ''), 'fecha' => date('d/m/Y', strtotime($fin)), 'orden' => $fin];
                }
            } elseif (($r['estado'] ?? '') !== 'cancelado') {
                $curso[] = ['titulo' => (string)$r['titulo'], 'origen' => 'Requerimiento', 'limite' => (string)($r['fecha_fin'] ?? $r['fecha_limite'] ?? '')];
            }
        }
        usort($hechas, fn($a, $b) => strcmp($a['orden'], $b['orden']));
        usort($curso, fn($a, $b) => strcmp($a['limite'] ?: '9999', $b['limite'] ?: '9999'));
        return ['hechas' => array_map(fn($h) => array_diff_key($h, ['orden' => 1]), $hechas), 'curso' => array_slice($curso, 0, 15)];
    }

    /** Resumen, pendientes y observaciones: con la IA si está disponible; si no, una redacción simple. */
    public static function redactar(array $m, array $sem, array $d, bool $conIa): array
    {
        $nH = count($d['hechas']);
        $proys = array_values(array_unique(array_filter(array_map(fn($h) => $h['origen'], $d['hechas']))));
        $base = [
            'resumen' => $nH
                ? 'Durante la semana ' . $sem['texto'] . ' terminé ' . $nH . ($nH === 1 ? ' actividad' : ' actividades')
                  . ($proys ? ' (' . implode(', ', array_slice($proys, 0, 4)) . ')' : '') . '. El detalle está en la tabla de actividades realizadas.'
                : 'Durante la semana ' . $sem['texto'] . ' no cerré actividades registradas en el Hub; el avance está en la sección de lo que sigue en curso.',
            'pendientes' => array_map(fn($c) => $c['titulo'] . ($c['origen'] !== '' ? ' (' . $c['origen'] . ')' : ''), array_slice($d['curso'], 0, 8)),
            'observaciones' => '',
            'ia' => false,
        ];
        if (!$conIa || !is_file(__DIR__ . '/../ai/IA.php')) return $base;
        require_once __DIR__ . '/../ai/IA.php';
        if (!IA::lista()) return $base;
        $sistema = 'Eres el asistente de redacción de InnoTech. Redactas el informe semanal de actividades de un colaborador, '
            . 'en español de Ecuador, en primera persona, con tono profesional y frases cortas. Usa SOLO los datos que te dan: '
            . 'no inventes actividades, cifras ni fechas. Devuelve JSON: {"resumen": "3 a 5 oraciones", '
            . '"pendientes": ["próximos pasos concretos, máximo 8"], "observaciones": "riesgos o bloqueos si se deducen de los datos, si no vacío"}.';
        $texto = json_encode([
            'colaborador' => $m['nombre'], 'cargo' => $m['rol'] ?? '', 'semana' => $sem['texto'],
            'actividades_terminadas' => $d['hechas'], 'en_curso' => $d['curso'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        try {
            $r = IA::completar('informe_semanal', $sistema, $texto, [], ['json' => true, 'max' => 1200]);
            $j = IA::json((string)$r['texto']);
            $res = trim((string)($j['resumen'] ?? ''));
            if ($res === '') return $base;
            return [
                'resumen' => $res,
                'pendientes' => array_values(array_filter(array_map(fn($x) => trim((string)$x), (array)($j['pendientes'] ?? [])))) ?: $base['pendientes'],
                'observaciones' => trim((string)($j['observaciones'] ?? '')),
                'ia' => true,
            ];
        } catch (\Throwable $e) {
            error_log('Informe semanal: la IA no respondió: ' . $e->getMessage());
            return $base;
        }
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
        $contenido = [
            'org' => (string)(Config::get('titulo') ?: 'InnoTech'), 'persona' => (string)$m['nombre'], 'cargo' => Firmas::cargoDe($m),
            'equipo' => (string)(Firmas::coordinacionDe((int)$m['id'])['nombre'] ?? ($eqs[$eq][0] ?? '')), 'semana' => $sem['texto'], 'elaborado' => date('d/m/Y'),
            'resumen' => $red['resumen'], 'actividades' => $d['hechas'], 'extra' => [], 'pendientes' => $red['pendientes'],
            'observaciones' => $red['observaciones'], 'ia' => $red['ia'],
        ];
        $archivo = 'Informe semanal ' . $m['nombre'] . ' ' . $sem['clave'] . '.pdf';
        try {
            $subida = self::pdf($contenido, $archivo);
        } catch (\Throwable $e) {
            return 'No se pudo armar el PDF del informe: ' . $e->getMessage();
        }
        // Lo firman los revisores de su coordinación (Parámetros de firmas); si no está en ninguna, los de la configuración
        $firmantes = [['uid' => (int)$m['id'], 'rol' => 'Elaborado por', 'cargo' => Firmas::cargoDe($m)]];
        $coord = Firmas::coordinacionDe((int)$m['id']);
        $revisores = $coord ? Firmas::revisoresDe($coord, (int)$m['id'])
            : array_map(fn($r) => $r + ['cargo' => ''], array_filter($conf['revisores'], fn($r) => $r['uid'] !== (int)$m['id']));
        foreach ($revisores as $r) $firmantes[] = ['uid' => $r['uid'], 'rol' => $r['rol'], 'cargo' => $r['cargo'] !== '' ? $r['cargo'] : Firmas::cargoDe($r['uid'])];
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
                ['Documento' => 'Informe semanal de actividades', 'Semana' => $sem['texto'], 'Actividades' => count($d['hechas']) . ' terminadas', 'Lo firman después' => implode(' y ', $revs)],
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
