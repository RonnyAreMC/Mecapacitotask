<?php
/**
 * Mueve las tareas según los commits (el "flujo" del estándar, ahora en código).
 *
 * Cada commit referencia su tarea con `#id` y, opcionalmente, una palabra clave
 * PEGADA al #id que la avanza de estado:
 *
 *   #42                                   -> En progreso
 *   wip #42                               -> En progreso
 *   closes/fixes/cierra/resuelve #42      -> En revisión
 *                                            (o Completada si no tiene observaciones pendientes)
 *
 * Reglas:
 *  - Solo avanza, nunca retrocede (no pelea con lo que muevas a mano).
 *  - Cada commit se aplica UNA sola vez (se recuerda su sha).
 *  - La palabra clave debe ir junto al #id: `fixes #42` cierra, pero `fix(area):
 *    … #42` NO (ahí `fix` es el TIPO del commit, no un cierre).
 */
class AutoEstado
{
    // Palabra de cierre pegada al #id (con o sin dos puntos/espacio).
    private const RE_CIERRE = '/\b(?:clos|fix|cierr|resuelv|resolv|complet)\w*\s*:?\s*#(\d+)/iu';
    private const RE_WIP    = '/\bwip\s*:?\s*#(\d+)/iu';
    private const RE_REF    = '/#(\d+)/';

    /** Del mensaje de un commit → [idTarea => estadoDestino]. */
    public static function referencias(string $msg): array
    {
        $refs = [];
        if (preg_match_all(self::RE_CIERRE, $msg, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) $refs[(int)$m[1]] = 'revision';
        }
        if (preg_match_all(self::RE_WIP, $msg, $ms, PREG_SET_ORDER)) {
            foreach ($ms as $m) { $id = (int)$m[1]; if (($refs[$id] ?? '') !== 'revision') $refs[$id] = 'progreso'; }
        }
        if (preg_match_all(self::RE_REF, $msg, $all)) {
            foreach ($all[1] as $s) { $id = (int)$s; if (!isset($refs[$id])) $refs[$id] = 'progreso'; }
        }
        return $refs;
    }

    /**
     * Aplica los estados de los commits al proyecto. Idempotente (cada sha una
     * vez). Devuelve los cambios aplicados: [['tarea'=>id,'de'=>..,'a'=>..], ...].
     */
    public static function aplicar(int $proyectoId, array $commits): array
    {
        if (!$commits) return [];

        $tareas  = new TareaRepo();
        $delProy = [];
        foreach ($tareas->delProyecto($proyectoId) as $t) $delProy[(int)$t['id']] = $t;
        if (!$delProy) return [];

        $orden   = array_flip(array_keys(Catalogo::estadosTarea()));   // pendiente=0 … hecho=3
        $finales = Catalogo::estadosFinales();
        $obsPend = (new ObservacionRepo())->pendientesPorTarea($proyectoId);

        // Commits ya procesados (para no re-aplicar ni pelear con cambios manuales)
        $store = new JsonStore('commits_estado');
        $visto = [];
        foreach ($store->all() as $r) { if (!empty($r['sha'])) $visto[$r['sha']] = true; }

        $cambios = [];
        foreach ($commits as $c) {
            $sha = (string)($c['sha'] ?? '');
            if ($sha === '' || isset($visto[$sha])) continue;

            $refs = self::referencias((string)($c['msg'] ?? ''));
            $mias = array_intersect_key($refs, $delProy);   // solo tareas de este proyecto
            if (!$mias) continue;                            // no toca nada nuestro: se re-evalúa barato

            foreach ($mias as $tid => $destino) {
                $actual = $delProy[$tid]['estado'] ?? 'pendiente';
                // "cierra" manda a revisión, o directo a completada si no hay observaciones pendientes
                if ($destino === 'revision' && empty($obsPend[$tid])) $destino = 'hecho';
                if (($orden[$destino] ?? 0) <= ($orden[$actual] ?? 0)) continue;   // solo hacia adelante

                $extra = [];
                $eraFinal = in_array($actual, $finales, true);
                $esFinal  = in_array($destino, $finales, true);
                if ($esFinal && !$eraFinal) $extra['completada_en'] = date('Y-m-d');
                if (!$esFinal && $eraFinal) $extra['completada_en'] = '';

                $tareas->actualizar($tid, ['estado' => $destino] + $extra);
                // Si el commit la dio por terminada, avisa a quien lleva el
                // proyecto igual que si la hubieran cerrado a mano. Sin id de
                // persona: aqui no hay sesion detras, la cerro un commit.
                avisarTareaTerminada($delProy[$tid], $actual, $destino, 0);
                $delProy[$tid]['estado'] = $destino;
                $cambios[] = ['tarea' => $tid, 'de' => $actual, 'a' => $destino];
            }

            // Recordar el commit (aunque no haya movido nada): así un cambio manual
            // posterior no se revierte en la siguiente carga de métricas.
            $store->insert(['sha' => $sha, 'proyecto' => $proyectoId]);
            $visto[$sha] = true;
        }
        return $cambios;
    }
}
