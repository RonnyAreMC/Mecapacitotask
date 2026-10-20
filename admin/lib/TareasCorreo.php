<?php
/**
 * TareasCorreo: el administrador crea una o varias tareas en un proyecto a
 * partir de un correo de su buzón InnoTech (la estrella de la IA en el correo,
 * lib/correo_vista.php → assets/ia.js).
 *
 *  1. leer():  el correo, del servidor o, si ya no está ahí, de la copia del panel.
 *  2. gente(): quiénes pueden ser responsables en ese proyecto (su equipo).
 *  3. La IA propone las tareas (IAFunciones::tareasCorreo); el administrador
 *     revisa título, descripción, responsable (obligatorio), fechas y prioridad.
 *  4. actions.php → tareas_correo_crear las crea como cualquier tarea (avisos,
 *     calendario) y anotar() recuerda qué salió de cada correo.
 *
 * Solo el administrador. No va en bootstrap: lo cargan actions.php y la vista del correo.
 */
require_once __DIR__ . '/Models.php';

final class TareasCorreo
{
    private static function repo(): JsonStore { return new JsonStore('tareas_correo'); }

    /** El correo en la forma de ReqCorreo::leer (asunto, de, para, cc, fecha, texto, adjuntos…) */
    public static function leer(int $mid, string $carpeta, int $uid): array
    {
        $msg = null;
        $imap = Correo::abrir($mid);
        try {
            $c = Correo::carpetaValida(Correo::carpetas($imap, $mid), $carpeta);
            $carpeta = (string)$c['nombre'];
            $msg = ReqCorreo::leer($imap, $carpeta, $uid);
        } finally {
            $imap->cerrar();
        }
        // Ya no está en el servidor (por ejemplo, Gmail lo bajó por POP): la copia del panel
        if (!$msg && class_exists('CorreoCopias')) {
            $m = CorreoCopias::mensaje($mid, $carpeta, $uid);
            if ($m) {
                $texto = trim((string)($m['texto_cita'] ?? '')) ?: trim((string)($m['texto'] ?? ''));
                $msg = [
                    'uid' => $uid, 'carpeta' => $carpeta, 'asunto' => (string)$m['asunto'],
                    'de' => (array)$m['de'], 'para' => (array)$m['para'], 'cc' => (array)$m['cc'],
                    'fecha' => (int)$m['fecha'], 'message_id' => (string)$m['message_id'], 'texto' => $texto,
                    'adjuntos' => array_map(fn($a) => ['nombre' => CorreoMime::nombreArchivo((string)($a['nombre'] ?? 'archivo'))], (array)($m['adjuntos'] ?? [])),
                ];
            }
        }
        if (!$msg) throw new CorreoError('No encontré ese correo: puede que ya no esté en esa carpeta.');
        return $msg;
    }

    /**
     * Quiénes pueden ser responsables en el proyecto: su equipo (o todos, si el
     * proyecto está abierto a todo el equipo), con su PO y el de relevo.
     * [id, nombre, rol, correos].
     */
    public static function gente(array $proyecto): array
    {
        $ids = ProyectoRepo::miembrosDe($proyecto);
        if ($ids !== null) {
            foreach ([ProyectoRepo::poDe($proyecto), (int)($proyecto['po_relevo'] ?? 0)] as $extra) {
                if ($extra > 0 && !in_array($extra, $ids, true)) $ids[] = $extra;
            }
        }
        $sinc = class_exists('Correo') && method_exists('Correo', 'correosSincronizados') ? Correo::correosSincronizados() : [];
        $out = [];
        foreach ((new MiembroRepo())->todos() as $m) {
            $id = (int)$m['id'];
            if ($ids !== null && !in_array($id, $ids, true)) continue;
            $correos = array_values(array_unique(array_filter([strtolower(trim((string)($m['email'] ?? ''))), (string)($sinc[$id] ?? '')])));
            $out[] = ['id' => $id, 'nombre' => trim((string)($m['nombre'] ?? '')), 'rol' => trim((string)($m['rol'] ?? '')), 'correos' => $correos];
        }
        usort($out, fn($a, $b) => strnatcasecmp($a['nombre'], $b['nombre']));
        return $out;
    }

    /** «Desde el correo «Asunto» de Nombre (14/10/2026).» — va al pie de cada tarea */
    public static function origen(array $msg): string
    {
        $de = (array)($msg['de'][0] ?? []);
        $quien = trim((string)($de['nombre'] ?? '')) ?: trim((string)($de['email'] ?? ''));
        return 'Desde el correo «' . trim((string)$msg['asunto']) . '»' . ($quien !== '' ? ' de ' . $quien : '')
            . ' (' . date('d/m/Y', (int)$msg['fecha']) . ').';
    }

    /** Recuerda las tareas que salieron de un correo (para avisarlo al abrirlo de nuevo) */
    public static function anotar(int $mid, string $messageId, int $pid, array $tareaIds): void
    {
        if ($messageId === '' || !$tareaIds) return;
        self::repo()->insert(['miembro_id' => $mid, 'message_id' => $messageId, 'proyecto_id' => $pid,
                              'tareas' => array_values(array_map('intval', $tareaIds)), 'fecha' => date('Y-m-d H:i')]);
    }

    /** Lo que ya se creó desde ese correo: [['proyecto_id'=>…, 'n'=>…], …] */
    public static function deMensaje(int $mid, string $messageId): array
    {
        if ($messageId === '') return [];
        $por = [];
        foreach (self::repo()->all() as $f) {
            if ((int)$f['miembro_id'] !== $mid || ($f['message_id'] ?? '') !== $messageId) continue;
            $pid = (int)$f['proyecto_id'];
            $por[$pid] = ($por[$pid] ?? 0) + count((array)$f['tareas']);
        }
        $out = [];
        foreach ($por as $pid => $n) $out[] = ['proyecto_id' => $pid, 'n' => $n];
        return $out;
    }
}
