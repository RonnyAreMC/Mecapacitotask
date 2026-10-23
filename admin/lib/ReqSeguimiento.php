<?php
/**
 * Seguimiento de los requerimientos sueltos.
 *
 * Quien da seguimiento (Felipe, si nadie eligió a otro) recibe en la campana y
 * por correo lo que necesita para perseguir los pedidos:
 *  - cuando alguien termina uno: quién, cuándo, si llegó a tiempo y cómo lo dejó;
 *  - una vez al día, los que se pasaron de fecha sin cerrarse: un solo correo
 *    con todos, con sus responsables y los días de retraso.
 * Cada aviso queda también en la trazabilidad del requerimiento.
 *
 * Se elige en Requerimientos → Seguimiento (Config «req_seguimiento»).
 * Se carga con is_file (no desde bootstrap): si falta, el resto sigue igual.
 */
final class ReqSeguimiento
{
    /** personas (ids), terminado y vencido (se avisa o no). */
    public static function conf(): array
    {
        $c = Config::get('req_seguimiento');
        $c = is_array($c) ? $c : [];
        return [
            'personas'  => array_key_exists('personas', $c)
                ? array_values(array_unique(array_filter(array_map('intval', (array)$c['personas']))))
                : self::porDefecto(),
            'terminado' => !array_key_exists('terminado', $c) || !empty($c['terminado']),
            'vencido'   => !array_key_exists('vencido', $c) || !empty($c['vencido']),
        ];
    }

    public static function guardar(array $d): void
    {
        Config::guardar(['req_seguimiento' => [
            'personas'  => array_values(array_unique(array_filter(array_map('intval', (array)($d['personas'] ?? []))))),
            'terminado' => !empty($d['terminado']),
            'vencido'   => !empty($d['vencido']),
        ]]);
    }

    /** Sin elegir todavía: quien se llame Felipe, que es quien da seguimiento. */
    private static function porDefecto(): array
    {
        $ids = [];
        foreach ((new MiembroRepo())->todos() as $m) {
            if (preg_match('/^felipe\b/iu', trim((string)($m['nombre'] ?? '')))) $ids[] = (int)$m['id'];
        }
        return $ids;
    }

    /** Días de retraso al entregar (o hasta hoy si sigue abierto); 0 = a tiempo, null = sin fecha. */
    public static function retraso(array $r): ?int
    {
        $fin = RequerimientoRepo::fechaFin($r);
        if ($fin === '') return null;
        $hasta = RequerimientoRepo::cerrado($r) && !empty($r['cerrado_en']) ? substr((string)$r['cerrado_en'], 0, 10) : date('Y-m-d');
        return max(0, (int)floor((strtotime($hasta) - strtotime($fin)) / 86400));
    }

    /** Los datos que se cuentan de un requerimiento: responsables, fechas, cómo va. [etiqueta => texto] */
    public static function datos(array $r, array $mapa): array
    {
        $col = RequerimientoRepo::colaboradoresDe($r);
        $resp = [];
        foreach (RequerimientoRepo::asignadosDe($r) as $mid) {
            $n = (string)($mapa[$mid]['nombre'] ?? '');
            if ($n === '') continue;
            $resp[] = $n . (isset($col[$mid]) ? ' (colaborador' . (isset($mapa[$col[$mid]['por']]) ? ', lo sumó ' . explode(' ', trim((string)$mapa[$col[$mid]['por']]['nombre']))[0] : '') . ')' : '');
        }
        $f = [];
        $f['Responsables'] = $resp ? implode(', ', $resp) : 'Sin asignar';
        if (trim((string)($r['solicitante'] ?? '')) !== '') $f['Lo pide'] = (string)$r['solicitante'];
        $asignador = (int)($r['creado_por'] ?? 0);
        if (isset($mapa[$asignador])) $f['Lo registró'] = (string)$mapa[$asignador]['nombre'];
        if (RequerimientoRepo::fechaInicio($r) !== '') $f['Inicio'] = date('d/m/Y', strtotime(RequerimientoRepo::fechaInicio($r)));
        if (RequerimientoRepo::fechaFin($r) !== '') $f['Entrega'] = date('d/m/Y', strtotime(RequerimientoRepo::fechaFin($r)));
        if (!empty($r['cerrado_en'])) $f['Terminado'] = date('d/m/Y H:i', strtotime((string)$r['cerrado_en']));
        $ret = self::retraso($r);
        if ($ret !== null) {
            $f[RequerimientoRepo::cerrado($r) ? 'Resultado' : 'Retraso'] = $ret === 0
                ? (RequerimientoRepo::cerrado($r) ? 'A tiempo' : 'Todavía en plazo')
                : $ret . ' día' . ($ret === 1 ? '' : 's') . ' tarde';
        }
        return $f;
    }

    /**
     * Alguien terminó un requerimiento: aviso en la campana y correo a los de
     * seguimiento. $ya: correos que ya recibieron el aviso por otra vía (quien
     * lo asignó, el administrador), para no mandarlo dos veces. Devuelve cuántos.
     */
    public static function avisarTerminado(array $r, array $quien, string $nota, array $ya = []): int
    {
        $c = self::conf();
        if (!$c['terminado'] || !$c['personas']) return 0;
        $miembros = new MiembroRepo();
        $mapa = $miembros->mapa();
        $yo = (int)($quien['id'] ?? 0);
        $para = array_values(array_filter($c['personas'], fn($id) => $id !== $yo && isset($mapa[$id])));
        if (!$para) return 0;
        $ret = self::retraso($r);
        $titulo = 'Terminado: ' . ($r['titulo'] ?? '');
        $texto = ($quien['nombre'] ?? 'Alguien') . ' lo terminó' . ($ret ? ' con ' . $ret . ' día' . ($ret === 1 ? '' : 's') . ' de retraso' : ($ret === 0 ? ' a tiempo' : ''));
        if (class_exists('Notif')) Notif::crear($para, 'requerimiento', 'Terminaron un requerimiento', '«' . ($r['titulo'] ?? '') . '» · ' . $texto, 'requerimientos.php?ver=' . (int)$r['id'], $yo);
        $ya = array_map('strtolower', $ya);
        $n = 0;
        foreach ($para as $mid) {
            $m = $mapa[$mid];
            if (in_array(strtolower(trim((string)($m['email'] ?? ''))), $ya, true)) continue;
            if (Mailer::seguimientoRequerimientos($m, $titulo, $texto . '. Para que le des seguimiento:',
                [[(string)($r['titulo'] ?? ''), self::datos($r, $mapa), $nota !== '' ? 'Cómo lo dejó: ' . $nota : '']], 'requerimientos.php?ver=' . (int)$r['id'], '#2BB673') === true) $n++;
        }
        return $n;
    }

    /**
     * Una vez al día (cron_correo.php): los requerimientos abiertos a los que
     * se les pasó la fecha de entrega y todavía no se avisaron (si se cambia la
     * fecha, se vuelve a avisar con la nueva). Un correo por persona con todos.
     */
    public static function paso(?string $hoy = null): int
    {
        $hoy ??= date('Y-m-d');
        if (date('H:i') < '08:00' && $hoy === date('Y-m-d')) return 0;
        $marca = __DIR__ . '/../data/req_seguimiento.json';
        $ultimo = json_decode((string)@file_get_contents($marca), true);
        if (($ultimo['dia'] ?? '') === $hoy) return 0;
        @file_put_contents($marca, json_encode(['dia' => $hoy, 't' => time()]));
        $c = self::conf();
        if (!$c['vencido']) return 0;
        $repo = new RequerimientoRepo();
        $mapa = (new MiembroRepo())->mapa();
        $nuevos = [];
        foreach ($repo->todos() as $r) {
            $fin = RequerimientoRepo::fechaFin($r);
            if ($fin === '' || RequerimientoRepo::cerrado($r) || $fin >= $hoy) continue;
            if (($r['aviso_vencido'] ?? '') === $fin) continue;
            $repo->actualizar((int)$r['id'], ['aviso_vencido' => $fin]);
            $repo->anotar((int)$r['id'], 0, 'Se pasó la fecha de entrega (' . date('d/m', strtotime($fin)) . ') sin terminarse.'
                . ($c['personas'] ? ' Se avisó a ' . implode(', ', array_map(fn($id) => trim((string)($mapa[$id]['nombre'] ?? '')), array_filter($c['personas'], fn($id) => isset($mapa[$id])))) . '.' : ''));
            $nuevos[] = $repo->buscar((int)$r['id']) ?? $r;
        }
        if (!$nuevos || !$c['personas']) return 0;
        usort($nuevos, fn($a, $b) => strcmp(RequerimientoRepo::fechaFin($a), RequerimientoRepo::fechaFin($b)));
        $n = count($nuevos);
        $titulo = $n === 1 ? 'Se pasó de fecha: ' . $nuevos[0]['titulo'] : $n . ' requerimientos se pasaron de fecha';
        $para = array_values(array_filter($c['personas'], fn($id) => isset($mapa[$id])));
        if (class_exists('Notif')) Notif::crear($para, 'requerimiento', $n === 1 ? 'Un requerimiento se pasó de fecha' : $n . ' requerimientos se pasaron de fecha',
            implode(' · ', array_map(fn($r) => '«' . mb_strimwidth((string)$r['titulo'], 0, 60, '…') . '»', array_slice($nuevos, 0, 4))), 'requerimientos.php', 0);
        $bloques = array_map(fn($r) => [(string)$r['titulo'], self::datos($r, $mapa), ''], $nuevos);
        $enviados = 0;
        foreach ($para as $mid) {
            if (Mailer::seguimientoRequerimientos($mapa[$mid], $titulo, ($n === 1 ? 'Este requerimiento llegó a su fecha de entrega y sigue abierto.' : 'Estos requerimientos llegaron a su fecha de entrega y siguen abiertos.') . ' Para que les des seguimiento:',
                $bloques, 'requerimientos.php', '#C66B2D') === true) $enviados++;
        }
        return $enviados;
    }
}
