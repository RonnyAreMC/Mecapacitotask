<?php
/**
 * IA del panel: GPT (OpenAI) y Gemini (Google) para tareas concretas —planificar
 * desde un documento, actas de reunión, redactar observaciones…—.
 *
 * Mismo principio que la consola de IA de ms-project-ube-itb: el código de cada
 * función NO conoce claves ni proveedores. Pide IA para una TAREA
 * (IA::completar('acta_reunion', …)) y aquí se resuelve qué modelo le toca, con
 * qué clave, y qué hacer si falla: la siguiente clave del proveedor y, después,
 * el modelo de respaldo de esa tarea.
 *
 *  - La configuración (claves, modelos y precios, asignaciones, tope mensual)
 *    vive en data/ia.json y no en config.json: Ajustes reescribe config.json
 *    entero con las claves que conoce y esto se perdería.
 *  - Las claves se guardan cifradas (AES-256-GCM) con un secreto propio,
 *    data/ia.secreto, que se crea solo y nunca sale del servidor.
 *  - Cada llamada queda registrada (tarea, persona, modelo, tokens y costo
 *    estimado) en la colección ia_uso, y al llegar al tope del mes se corta.
 *  - Sin SDKs ni Composer: HTTP directo a las APIs (curl o, si no hay, streams).
 *
 * La IA solo propone: toda función devuelve un BORRADOR que la persona revisa
 * y guarda. Nada se guarda solo.
 */
final class IA
{
    public const PROVEEDORES = [
        'openai' => ['nombre' => 'OpenAI (GPT)', 'consola' => 'https://platform.openai.com/api-keys',
                     'base' => 'https://api.openai.com/v1'],
        'gemini' => ['nombre' => 'Google Gemini', 'consola' => 'https://aistudio.google.com/apikey',
                     'base' => 'https://generativelanguage.googleapis.com/v1beta'],
    ];

    /**
     * Tareas de IA: clave => [etiqueta, grupo, para qué sirve, principal, respaldo].
     * El modelo se elige en la consola; estos son los de fábrica.
     */
    public const TAREAS = [
        'plan_documento'     => ['Planificar desde un documento', 'Planificar',
                                 'Lee el Excel, PDF o Word del plan y devuelve las tareas desglosadas para el importador.',
                                 'gemini:gemini-2.5-flash', 'openai:gpt-4.1-mini'],
        'acta_reunion'       => ['Acta de reunión', 'Reuniones',
                                 'Resumen, acuerdos, responsables y tareas propuestas a partir de la transcripción.',
                                 'gemini:gemini-2.5-flash', 'openai:gpt-4.1-mini'],
        'req_desde_texto'    => ['Requerimiento desde un texto', 'Requerimientos',
                                 'Convierte un correo o un WhatsApp en un requerimiento y busca parecidos.',
                                 'openai:gpt-4.1-mini', 'gemini:gemini-2.5-flash'],
        'obs_redaccion'      => ['Observación: mejorar la redacción', 'Observaciones',
                                 'Reescribe el borrador de una observación de forma clara y profesional.',
                                 'openai:gpt-4.1-mini', 'gemini:gemini-2.5-flash'],
        'obs_adjunto'        => ['Observación: describir la captura o el PDF', 'Observaciones',
                                 'Mira los adjuntos y redacta la observación sobre lo que se ve.',
                                 'gemini:gemini-2.5-flash', 'openai:gpt-4.1-mini'],
        'obs_resumen'        => ['Observación: resumir el hilo', 'Observaciones',
                                 'Resume una conversación larga de observación y respuestas.',
                                 'openai:gpt-4.1-mini', 'gemini:gemini-2.5-flash'],
        'tarea_descripcion'  => ['Tarea: redactar la descripción', 'Tareas',
                                 'Propone objetivo y criterios de aceptación a partir del título.',
                                 'openai:gpt-4.1-mini', 'gemini:gemini-2.5-flash'],
        'tarea_desglose'     => ['Tarea: partir en subtareas', 'Tareas',
                                 'Divide una tarea grande en subtareas con su orden.',
                                 'openai:gpt-4.1-mini', 'gemini:gemini-2.5-flash'],
        'tarea_dependencias' => ['Tarea: sugerir dependencias', 'Tareas',
                                 'Señala de qué tareas del proyecto depende la que se está creando.',
                                 'openai:gpt-4.1-mini', 'gemini:gemini-2.5-flash'],
        'deploy_notas'       => ['Notas de despliegue', 'Despliegues',
                                 'Redacta las notas de la versión con las tareas que se lleva la subida.',
                                 'openai:gpt-4.1-mini', 'gemini:gemini-2.5-flash'],
        'daily_resumen'      => ['Resumen para el daily', 'Daily',
                                 'Qué hizo cada persona (commits y tareas) y en qué sigue.',
                                 'gemini:gemini-2.5-flash', 'openai:gpt-4.1-mini'],
    ];

    /**
     * Catálogo de fábrica: [proveedor, código, nombre, USD por millón de tokens
     * de entrada, de salida]. Son precios DE REFERENCIA: cambian y se editan
     * en la consola.
     */
    private const MODELOS_BASE = [
        ['openai', 'gpt-4.1-mini', 'GPT-4.1 mini', 0.40, 1.60],
        ['openai', 'gpt-4.1', 'GPT-4.1', 2.00, 8.00],
        ['openai', 'gpt-4o-mini', 'GPT-4o mini', 0.15, 0.60],
        ['openai', 'gpt-5-mini', 'GPT-5 mini', 0.25, 2.00],
        ['gemini', 'gemini-2.5-flash', 'Gemini 2.5 Flash', 0.30, 2.50],
        ['gemini', 'gemini-2.5-flash-lite', 'Gemini 2.5 Flash-Lite', 0.10, 0.40],
        ['gemini', 'gemini-2.5-pro', 'Gemini 2.5 Pro', 1.25, 10.00],
    ];

    /** Lo que se le puede mandar a la IA como archivo, y el máximo por archivo. */
    public const MAX_ARCHIVO = 15 * 1024 * 1024;

    private static ?array $cfg = null;

    /* ======================= Configuración ======================= */

    private static function archivo(): string { return __DIR__ . '/../data/ia.json'; }

    public static function config(): array
    {
        if (self::$cfg !== null) return self::$cfg;
        $g = is_file(self::archivo()) ? (json_decode((string)file_get_contents(self::archivo()), true) ?: []) : [];

        $modelos = [];
        foreach ((array)($g['modelos'] ?? []) as $m) {
            if (!isset(self::PROVEEDORES[$m['proveedor'] ?? '']) || trim((string)($m['codigo'] ?? '')) === '') continue;
            $modelos[] = [
                'proveedor' => $m['proveedor'], 'codigo' => trim((string)$m['codigo']),
                'nombre'    => trim((string)($m['nombre'] ?? '')) ?: trim((string)$m['codigo']),
                'entrada'   => max(0, (float)($m['entrada'] ?? 0)), 'salida' => max(0, (float)($m['salida'] ?? 0)),
                'activo'    => !array_key_exists('activo', $m) || !empty($m['activo']),
            ];
        }
        if (!$modelos) {
            foreach (self::MODELOS_BASE as [$p, $c, $n, $e, $s]) {
                $modelos[] = ['proveedor' => $p, 'codigo' => $c, 'nombre' => $n, 'entrada' => $e, 'salida' => $s, 'activo' => true];
            }
        }

        $asig = [];
        foreach (self::TAREAS as $k => [, , , $pri, $res]) {
            $a = (array)($g['asignaciones'][$k] ?? []);
            $asig[$k] = [
                'principal' => (string)($a['principal'] ?? $pri),
                'respaldo'  => array_key_exists('respaldo', $a) ? (string)$a['respaldo'] : $res,
                'activa'    => !array_key_exists('activa', $a) || !empty($a['activa']),
            ];
        }

        $claves = [];
        foreach (self::PROVEEDORES as $p => $_) {
            $claves[$p] = [];
            foreach ((array)($g['claves'][$p] ?? []) as $c) {
                if (trim((string)($c['valor'] ?? '')) === '') continue;
                $claves[$p][] = [
                    'id'       => (string)($c['id'] ?? '') ?: substr(md5((string)$c['valor']), 0, 8),
                    'etiqueta' => trim((string)($c['etiqueta'] ?? '')) ?: 'Clave',
                    'valor'    => (string)$c['valor'],          // cifrado
                    'final'    => (string)($c['final'] ?? ''),  // últimos 4, para reconocerla
                    'activa'   => !array_key_exists('activa', $c) || !empty($c['activa']),
                ];
            }
        }

        return self::$cfg = [
            'activa'       => !array_key_exists('activa', $g) || !empty($g['activa']),
            'tope_mensual' => max(0, (float)($g['tope_mensual'] ?? 20)),
            'claves'       => $claves,
            'modelos'      => $modelos,
            'asignaciones' => $asig,
            // Solo para pruebas o un proxy: no se edita desde la consola.
            'base'         => (array)($g['base'] ?? []),
        ];
    }

    public static function guardar(array $cfg): void
    {
        $limpio = [
            'activa'       => !empty($cfg['activa']),
            'tope_mensual' => max(0, (float)($cfg['tope_mensual'] ?? 0)),
            'claves'       => $cfg['claves'] ?? [],
            'modelos'      => array_values($cfg['modelos'] ?? []),
            'asignaciones' => $cfg['asignaciones'] ?? [],
        ];
        if (!empty($cfg['base'])) $limpio['base'] = $cfg['base'];
        file_put_contents(self::archivo(), json_encode($limpio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @chmod(self::archivo(), 0600);
        self::$cfg = null;
    }

    /** ¿Hay al menos una clave activa? Sin esto los botones de IA ni se muestran. */
    public static function lista(): bool
    {
        $c = self::config();
        if (!$c['activa']) return false;
        foreach ($c['claves'] as $lista) {
            foreach ($lista as $k) if ($k['activa']) return true;
        }
        return false;
    }

    /** ¿Puede esta persona usar la IA? Administrador y Scrum Master. */
    public static function puedeUsar(): bool
    {
        return Auth::esGestor() && self::lista();
    }

    public static function modelo(string $ref): ?array
    {
        [$p, $c] = array_pad(explode(':', $ref, 2), 2, '');
        foreach (self::config()['modelos'] as $m) {
            if ($m['proveedor'] === $p && $m['codigo'] === $c) return $m;
        }
        return null;
    }

    /* ======================= Claves cifradas ======================= */

    private static function secreto(): string
    {
        $f = __DIR__ . '/../data/ia.secreto';
        if (!is_file($f)) {
            file_put_contents($f, base64_encode(random_bytes(32)));
            @chmod($f, 0600);
        }
        return base64_decode(trim((string)file_get_contents($f)));
    }

    public static function cifrar(string $plano): string
    {
        if (!function_exists('openssl_encrypt')) {
            // Sin openssl queda como el resto de secretos del panel: en data/,
            // que el .htaccess cierra a la web.
            return 'plano:' . base64_encode($plano);
        }
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt($plano, 'aes-256-gcm', self::secreto(), OPENSSL_RAW_DATA, $iv, $tag);
        return 'v1:' . base64_encode($iv . $tag . $ct);
    }

    public static function descifrar(string $guardado): string
    {
        if (str_starts_with($guardado, 'plano:')) return (string)base64_decode(substr($guardado, 6));
        if (!str_starts_with($guardado, 'v1:') || !function_exists('openssl_decrypt')) return '';
        $b = (string)base64_decode(substr($guardado, 3));
        if (strlen($b) < 29) return '';
        $r = openssl_decrypt(substr($b, 28), 'aes-256-gcm', self::secreto(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
        return $r === false ? '' : $r;
    }

    /* ======================= Consumo ======================= */

    public static function gastoMes(?string $mes = null): float
    {
        $mes ??= date('Y-m');
        $t = 0.0;
        foreach ((new JsonStore('ia_uso'))->where('mes', $mes) as $u) $t += (float)($u['costo'] ?? 0);
        return $t;
    }

    private static function registrar(array $r): void
    {
        (new JsonStore('ia_uso'))->insert($r + ['fecha' => date('Y-m-d H:i:s'), 'mes' => date('Y-m'),
            'miembro_id' => (int)(Auth::usuario()['id'] ?? 0)]);
    }

    public static function costo(?array $m, int $entrada, int $salida): float
    {
        if (!$m) return 0.0;
        return round(($entrada * $m['entrada'] + $salida * $m['salida']) / 1e6, 6);
    }

    /* ======================= Llamar a la IA ======================= */

    /**
     * Pide a la IA lo de una tarea. Prueba el modelo principal con cada clave
     * activa de su proveedor y, si todas fallan, el de respaldo.
     *
     * $archivos = [['nombre' => , 'mime' => , 'datos' => binario], ...]: los PDF
     * e imágenes van tal cual; Word, Excel y texto, como texto (leerArchivo()).
     * $op: json (bool), max (tokens de salida), proyecto_id (para el registro).
     *
     * Devuelve ['texto', 'proveedor', 'modelo', 'entrada', 'salida', 'costo', 'respaldo'].
     * @throws IAError con un mensaje que se le puede mostrar a la persona.
     */
    public static function completar(string $tarea, string $sistema, string $texto, array $archivos = [], array $op = []): array
    {
        $cfg = self::config();
        if (!isset(self::TAREAS[$tarea])) throw new IAError('Tarea de IA desconocida.');
        if (!$cfg['activa']) throw new IAError('La IA está desactivada en la consola.');
        $asig = $cfg['asignaciones'][$tarea];
        if (!$asig['activa']) throw new IAError('Esta función de IA está desactivada en la consola.');
        if ($cfg['tope_mensual'] > 0 && self::gastoMes() >= $cfg['tope_mensual']) {
            throw new IAError(sprintf('Se alcanzó el tope de gasto de IA de este mes (US$ %.2f).', $cfg['tope_mensual']));
        }
        @set_time_limit(240);

        $cola = array_values(array_unique(array_filter([$asig['principal'], $asig['respaldo']])));
        $errores = [];
        foreach ($cola as $n => $ref) {
            $m = self::modelo($ref);
            if (!$m || !$m['activo']) { $errores[] = $ref . ': el modelo no está activo en la consola'; continue; }
            $claves = array_values(array_filter($cfg['claves'][$m['proveedor']] ?? [], fn($k) => $k['activa']));
            if (!$claves) { $errores[] = self::PROVEEDORES[$m['proveedor']]['nombre'] . ': sin clave'; continue; }
            foreach ($claves as $k) {
                $clave = self::descifrar($k['valor']);
                if ($clave === '') { $errores[] = $k['etiqueta'] . ': no se pudo descifrar'; continue; }
                $t0 = microtime(true);
                try {
                    $r = $m['proveedor'] === 'openai'
                        ? self::openai($m['codigo'], $clave, $sistema, $texto, $archivos, $op)
                        : self::gemini($m['codigo'], $clave, $sistema, $texto, $archivos, $op);
                    $costo = self::costo($m, $r['entrada'], $r['salida']);
                    self::registrar(['tarea' => $tarea, 'proveedor' => $m['proveedor'], 'modelo' => $m['codigo'],
                        'entrada' => $r['entrada'], 'salida' => $r['salida'], 'costo' => $costo, 'estado' => 'ok',
                        'respaldo' => $n > 0, 'ms' => (int)((microtime(true) - $t0) * 1000),
                        'proyecto_id' => (int)($op['proyecto_id'] ?? 0)]);
                    return $r + ['proveedor' => $m['proveedor'], 'modelo' => $m['codigo'], 'costo' => $costo, 'respaldo' => $n > 0];
                } catch (IAError $e) {
                    $errores[] = $m['nombre'] . ' (' . $k['etiqueta'] . '): ' . $e->getMessage();
                }
            }
        }
        self::registrar(['tarea' => $tarea, 'proveedor' => '', 'modelo' => '', 'entrada' => 0, 'salida' => 0,
            'costo' => 0, 'estado' => 'error', 'error' => mb_substr(implode(' | ', $errores), 0, 600),
            'proyecto_id' => (int)($op['proyecto_id'] ?? 0)]);
        throw new IAError('La IA no respondió. ' . ($errores ? end($errores) : 'Revisa la consola de IA.'));
    }

    /** Prueba rápida de un modelo con una clave (botón "Probar" de la consola). */
    public static function probar(string $ref): array
    {
        $m = self::modelo($ref);
        if (!$m) throw new IAError('Ese modelo no está en el catálogo.');
        $claves = array_values(array_filter(self::config()['claves'][$m['proveedor']] ?? [], fn($k) => $k['activa']));
        if (!$claves) throw new IAError('No hay ninguna clave activa de ' . self::PROVEEDORES[$m['proveedor']]['nombre'] . '.');
        $res = [];
        foreach ($claves as $k) {
            $t0 = microtime(true);
            try {
                $args = [$m['codigo'], self::descifrar($k['valor']), 'Responde solo con la palabra OK.', 'Prueba de conexión.', [], ['max' => 40]];
                $r = $m['proveedor'] === 'openai' ? self::openai(...$args) : self::gemini(...$args);
                $res[] = ['clave' => $k['etiqueta'], 'ok' => true, 'ms' => (int)((microtime(true) - $t0) * 1000),
                          'texto' => mb_substr(trim($r['texto']), 0, 40)];
            } catch (IAError $e) {
                $res[] = ['clave' => $k['etiqueta'], 'ok' => false, 'error' => $e->getMessage()];
            }
        }
        return $res;
    }

    private static function base(string $proveedor): string
    {
        return rtrim((string)(self::config()['base'][$proveedor] ?? self::PROVEEDORES[$proveedor]['base']), '/');
    }

    private static function openai(string $modelo, string $clave, string $sistema, string $texto, array $archivos, array $op): array
    {
        $partes = [['type' => 'text', 'text' => $texto]];
        foreach ($archivos as $a) {
            $b64 = base64_encode($a['datos']);
            if (str_starts_with($a['mime'], 'image/')) {
                $partes[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $a['mime'] . ';base64,' . $b64]];
            } elseif ($a['mime'] === 'application/pdf') {
                $partes[] = ['type' => 'file', 'file' => ['filename' => $a['nombre'], 'file_data' => 'data:application/pdf;base64,' . $b64]];
            }
        }
        $cuerpo = [
            'model'    => $modelo,
            'messages' => [['role' => 'system', 'content' => $sistema], ['role' => 'user', 'content' => $partes]],
            // max_completion_tokens vale para todos los modelos actuales; los de
            // razonamiento rechazan max_tokens y una temperature distinta de 1.
            'max_completion_tokens' => (int)($op['max'] ?? 4000),
        ];
        if (!empty($op['json'])) $cuerpo['response_format'] = ['type' => 'json_object'];

        [$st, $j, $crudo] = self::http(self::base('openai') . '/chat/completions',
            ['Authorization: Bearer ' . $clave, 'Content-Type: application/json'], $cuerpo);
        if ($st !== 200) throw new IAError(self::errorApi($st, $j, $crudo));
        $txt = (string)($j['choices'][0]['message']['content'] ?? '');
        if (($j['choices'][0]['finish_reason'] ?? '') === 'length' && !empty($op['json'])) {
            throw new IAError('la respuesta salió cortada (demasiado larga)');
        }
        if (trim($txt) === '') throw new IAError('respuesta vacía');
        return ['texto' => $txt, 'entrada' => (int)($j['usage']['prompt_tokens'] ?? 0),
                'salida' => (int)($j['usage']['completion_tokens'] ?? 0)];
    }

    private static function gemini(string $modelo, string $clave, string $sistema, string $texto, array $archivos, array $op): array
    {
        $partes = [['text' => $texto]];
        foreach ($archivos as $a) {
            if (str_starts_with($a['mime'], 'image/') || $a['mime'] === 'application/pdf') {
                $partes[] = ['inline_data' => ['mime_type' => $a['mime'], 'data' => base64_encode($a['datos'])]];
            }
        }
        $gen = ['maxOutputTokens' => (int)($op['max'] ?? 4000)];
        if (!empty($op['json'])) $gen['responseMimeType'] = 'application/json';
        $cuerpo = [
            'systemInstruction' => ['parts' => [['text' => $sistema]]],
            'contents'          => [['role' => 'user', 'parts' => $partes]],
            'generationConfig'  => $gen,
        ];
        [$st, $j, $crudo] = self::http(self::base('gemini') . '/models/' . rawurlencode($modelo) . ':generateContent',
            ['x-goog-api-key: ' . $clave, 'Content-Type: application/json'], $cuerpo);
        if ($st !== 200) throw new IAError(self::errorApi($st, $j, $crudo));
        $cand = $j['candidates'][0] ?? [];
        $txt = '';
        foreach ((array)($cand['content']['parts'] ?? []) as $p) {
            if (empty($p['thought'])) $txt .= (string)($p['text'] ?? '');
        }
        if (($cand['finishReason'] ?? '') === 'MAX_TOKENS' && !empty($op['json'])) {
            throw new IAError('la respuesta salió cortada (demasiado larga)');
        }
        if (trim($txt) === '') {
            $motivo = $cand['finishReason'] ?? ($j['promptFeedback']['blockReason'] ?? '');
            throw new IAError('respuesta vacía' . ($motivo ? ' (' . $motivo . ')' : ''));
        }
        $u = $j['usageMetadata'] ?? [];
        return ['texto' => $txt, 'entrada' => (int)($u['promptTokenCount'] ?? 0),
                // Los tokens de "pensamiento" de los 2.5 se cobran como salida.
                'salida' => (int)($u['candidatesTokenCount'] ?? 0) + (int)($u['thoughtsTokenCount'] ?? 0)];
    }

    /** POST JSON. Devuelve [status, json decodificado, cuerpo crudo]. */
    private static function http(string $url, array $cabeceras, array $cuerpo): array
    {
        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $cabeceras,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 200,
            ]);
            $resp = curl_exec($ch);
            $st   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            // Sin curl_close(): desde PHP 8.0 no hace nada y en 8.5 avisa de obsoleto.
            if ($resp === false) throw new IAError('sin conexión con el proveedor (' . $err . ')');
        } else {
            $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $cabeceras),
                'content' => $json, 'timeout' => 200, 'ignore_errors' => true]]);
            $resp = @file_get_contents($url, false, $ctx);
            if ($resp === false) throw new IAError('sin conexión con el proveedor');
            $st = 0;
            foreach ($http_response_header ?? [] as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $mm)) $st = (int)$mm[1];
            }
        }
        return [$st, json_decode((string)$resp, true) ?: [], (string)$resp];
    }

    /**
     * '' si la clave tiene la forma de las de ese proveedor; si no, el motivo.
     * Evita guardar otro texto pegado por error (OpenAI: «sk-…»; Gemini: «AIza…»).
     */
    public static function validarClave(string $prov, string $v): string
    {
        $nombre = self::PROVEEDORES[$prov]['nombre'] ?? $prov;
        if (preg_match('/\s/u', $v)) return 'La clave de ' . $nombre . ' no lleva espacios: revisa que copiaste solo la clave.';
        $prefijo = ['openai' => 'sk-', 'gemini' => 'AIza'][$prov] ?? '';
        if ($prefijo !== '' && !str_starts_with($v, $prefijo)) {
            return 'Eso no parece una clave de ' . $nombre . ': empiezan con «' . $prefijo . '». Revisa que copiaste la clave y no otro texto.';
        }
        if (strlen($v) < 12 || strlen($v) > 300) return 'La clave de ' . $nombre . ' no tiene el largo esperado: revisa que esté completa.';
        return '';
    }

    /** El motivo legible de un error de la API (clave inválida, sin saldo, modelo inexistente…). */
    private static function errorApi(int $st, array $j, string $crudo): string
    {
        if ($st === 401) return 'clave inválida: el proveedor no la reconoce (revisa que esté completa o crea una nueva)';
        $msg = (string)($j['error']['message'] ?? $j[0]['error']['message'] ?? '');
        // El proveedor repite la clave enmascarada («sk-ab****…»): no hace falta mostrarla
        $msg = (string)preg_replace('/\S*\*{3,}\S*/', '••••', $msg);
        $msg = (string)preg_replace('/\s*You can find your API key at \S+/i', '', $msg);
        $pre = match (true) {
            $st === 401, $st === 403 => 'clave inválida o sin permiso',
            $st === 429              => 'sin cupo o demasiadas solicitudes',
            $st === 404              => 'modelo no encontrado',
            $st >= 500               => 'el proveedor está fallando',
            default                  => 'error ' . $st,
        };
        if ($msg === '' && $crudo !== '' && !$j) $msg = strip_tags($crudo);
        return $pre . ($msg !== '' ? ': ' . mb_substr(trim($msg), 0, 220) : '');
    }

    /* ======================= Archivos ======================= */

    /**
     * Prepara un archivo subido para la IA. PDF e imágenes van tal cual; Word,
     * Excel, CSV y texto se convierten a texto (el hosting no tiene ZipArchive:
     * los .docx y .xlsx se abren con un lector ZIP propio).
     * Devuelve ['archivo' => [...]] o ['texto' => '...'].
     */
    public static function leerArchivo(string $nombre, string $ruta): array
    {
        $tam = (int)@filesize($ruta);
        if ($tam <= 0) throw new IAError('El archivo «' . $nombre . '» está vacío.');
        if ($tam > self::MAX_ARCHIVO) throw new IAError('El archivo «' . $nombre . '» pesa más de 15 MB.');
        $ext  = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
        $datos = (string)file_get_contents($ruta);
        $imgs = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
        if ($ext === 'pdf') return ['archivo' => ['nombre' => $nombre, 'mime' => 'application/pdf', 'datos' => $datos]];
        if (isset($imgs[$ext])) return ['archivo' => ['nombre' => $nombre, 'mime' => $imgs[$ext], 'datos' => $datos]];
        if ($ext === 'docx') return ['texto' => self::textoDocx($datos)];
        if ($ext === 'xlsx') return ['texto' => self::textoXlsx($datos)];
        if (in_array($ext, ['csv', 'txt', 'md', 'json', 'tsv'], true)) {
            if (!mb_check_encoding($datos, 'UTF-8')) $datos = mb_convert_encoding($datos, 'UTF-8', 'Windows-1252');
            return ['texto' => $datos];
        }
        throw new IAError('Formato no admitido: «' . $nombre . '». Usa Excel (.xlsx), Word (.docx), PDF, CSV o texto.');
    }

    /** Lector ZIP mínimo: [ruta interna => contenido]. Solo lo que se pide. */
    public static function zip(string $bin, array $quiero = []): array
    {
        $fin = strrpos($bin, "PK\x05\x06");
        if ($fin === false) throw new IAError('El archivo no es un Word/Excel válido.');
        $e = unpack('vdisco/vdiscocd/vn/vtotal/Vtam/Voff', substr($bin, $fin + 4, 16));
        $p = $e['off'];
        $out = [];
        for ($i = 0; $i < $e['total']; $i++) {
            if (substr($bin, $p, 4) !== "PK\x01\x02") break;
            $c = unpack('vhecho/vnecesita/vflags/vmetodo/vhora/vfecha/Vcrc/Vcomp/Vtam/vlnom/vlextra/vlcom/vdisco/vint/Vext/Voff', substr($bin, $p + 4, 42));
            $nom = substr($bin, $p + 46, $c['lnom']);
            $p += 46 + $c['lnom'] + $c['lextra'] + $c['lcom'];
            if ($quiero && !self::coincide($nom, $quiero)) continue;
            $l = unpack('vlnom/vlextra', substr($bin, $c['off'] + 26, 4));
            $crudo = substr($bin, $c['off'] + 30 + $l['lnom'] + $l['lextra'], $c['comp']);
            $out[$nom] = $c['metodo'] === 8 ? (string)@gzinflate($crudo) : ($c['metodo'] === 0 ? $crudo : '');
        }
        return $out;
    }

    private static function coincide(string $nom, array $patrones): bool
    {
        foreach ($patrones as $pat) if (fnmatch($pat, $nom)) return true;
        return false;
    }

    private static function xmlTexto(string $s): string
    {
        return html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    public static function textoDocx(string $bin): string
    {
        $x = self::zip($bin, ['word/document.xml'])['word/document.xml'] ?? '';
        $x = preg_replace(['#<w:tab/>#', '#</w:tc>#', '#</w:tr>#', '#</w:p>#', '#<w:br[^>]*/>#'], ["\t", ' | ', "\n", "\n", "\n"], $x);
        return trim((string)preg_replace("/\n{3,}/", "\n\n", self::xmlTexto((string)$x)));
    }

    /** Las hojas como texto: una fila por renglón, celdas separadas por " | ". */
    public static function textoXlsx(string $bin, int $maxFilas = 3000): string
    {
        $z = self::zip($bin, ['xl/sharedStrings.xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels', 'xl/worksheets/*.xml']);
        $comunes = [];
        if (preg_match_all('#<si>(.*?)</si>#s', $z['xl/sharedStrings.xml'] ?? '', $mm)) {
            foreach ($mm[1] as $si) {
                preg_match_all('#<t[^>]*>(.*?)</t>#s', $si, $ts);
                $comunes[] = self::xmlTexto(implode('', $ts[1]));
            }
        }
        $rels = [];
        if (preg_match_all('#<Relationship [^>]*Id="([^"]+)"[^>]*Target="([^"]+)"#', $z['xl/_rels/workbook.xml.rels'] ?? '', $rr, PREG_SET_ORDER)) {
            foreach ($rr as $r) $rels[$r[1]] = 'xl/' . ltrim(str_replace('/xl/', '', $r[2]), '/');
        }
        $hojas = [];
        if (preg_match_all('#<sheet [^>]*name="([^"]*)"[^>]*r:id="([^"]+)"#', $z['xl/workbook.xml'] ?? '', $ss, PREG_SET_ORDER)) {
            foreach ($ss as $s) $hojas[] = [html_entity_decode($s[1], ENT_QUOTES | ENT_XML1, 'UTF-8'), $rels[$s[2]] ?? ''];
        }
        if (!$hojas) foreach ($z as $n => $_) if (str_starts_with($n, 'xl/worksheets/')) $hojas[] = [basename($n, '.xml'), $n];

        $out = [];
        $filas = 0;
        foreach ($hojas as [$nombre, $ruta]) {
            $x = $z[$ruta] ?? '';
            if ($x === '') continue;
            $out[] = '## Hoja: ' . $nombre;
            preg_match_all('#<row[^>]*>(.*?)</row>#s', $x, $rows);
            foreach ($rows[1] as $row) {
                preg_match_all('#<c ([^>]*?)(?:/>|>(.*?)</c>)#s', $row, $cs, PREG_SET_ORDER);
                $celdas = [];
                foreach ($cs as $c) {
                    $tipo = preg_match('#t="([^"]+)"#', $c[1], $tt) ? $tt[1] : '';
                    $cont = $c[2] ?? '';
                    if ($tipo === 'inlineStr') {
                        preg_match_all('#<t[^>]*>(.*?)</t>#s', $cont, $ts);
                        $v = self::xmlTexto(implode('', $ts[1]));
                    } else {
                        $v = preg_match('#<v>(.*?)</v>#s', $cont, $vv) ? $vv[1] : '';
                        if ($tipo === 's') $v = $comunes[(int)$v] ?? '';
                        else $v = self::xmlTexto($v);
                    }
                    $celdas[] = trim(preg_replace('/\s+/', ' ', $v));
                }
                while ($celdas && end($celdas) === '') array_pop($celdas);
                if (!$celdas) continue;
                $out[] = implode(' | ', $celdas);
                if (++$filas >= $maxFilas) { $out[] = '[… se cortó en ' . $maxFilas . ' filas]'; break 2; }
            }
        }
        return implode("\n", $out);
    }

    /* ======================= Respuestas ======================= */

    /**
     * El JSON de una respuesta, aunque venga envuelto en ```json … ``` o con
     * texto alrededor. Nunca lanza: si no hay JSON, devuelve [].
     */
    public static function json(string $texto): array
    {
        $t = trim($texto);
        $d = json_decode($t, true);
        if (is_array($d)) return $d;
        if (preg_match('/\{.*\}/s', $t, $m)) {
            $d = json_decode($m[0], true);
            if (is_array($d)) return $d;
        }
        return [];
    }

    /**
     * Texto de la IA → HTML seguro para el editor del panel: se escapa todo y
     * solo se reconstruyen párrafos y viñetas. Lo que venga de un documento o
     * una transcripción podría traer HTML; aquí no pasa nada de eso.
     */
    public static function aHtml(string $texto): string
    {
        $t = str_replace("\r", '', trim($texto));
        $t = preg_replace('/\*\*(.+?)\*\*/s', "\x01$1\x02", $t);   // **negrita** del modelo
        $esc = fn($l) => str_replace(["\x01", "\x02"], ['<b>', '</b>'], htmlspecialchars($l, ENT_QUOTES, 'UTF-8'));
        $vineta = '/^([-*•]|\d+[.)])\s+/u';
        $html = '';
        // Los modelos mezclan en un mismo bloque una línea y sus viñetas
        // («Criterios de aceptación:» y debajo «- …»): las viñetas seguidas
        // van a una lista y el resto a párrafos.
        foreach (preg_split("/\n{2,}/", (string)$t) as $bloque) {
            $parrafo = [];
            $lista = [];
            $volcarP = function () use (&$html, &$parrafo, $esc) {
                if ($parrafo) $html .= '<p>' . implode('<br>', array_map($esc, $parrafo)) . '</p>';
                $parrafo = [];
            };
            $volcarL = function () use (&$html, &$lista, $esc) {
                if ($lista) $html .= '<ul>' . implode('', array_map(fn($l) => '<li>' . $esc($l) . '</li>', $lista)) . '</ul>';
                $lista = [];
            };
            foreach (explode("\n", $bloque) as $l) {
                $l = trim($l);
                if ($l === '') continue;
                $l = (string)preg_replace('/^#{1,6}\s+(.+)$/u', "\x01$1\x02", $l);   // ### Título → negrita
                if (preg_match($vineta, $l)) {
                    $volcarP();
                    $lista[] = (string)preg_replace($vineta, '', $l);
                } else {
                    $volcarL();
                    $parrafo[] = $l;
                }
            }
            $volcarP();
            $volcarL();
        }
        return HtmlRico::limpiar($html);
    }
}

final class IAError extends RuntimeException {}
