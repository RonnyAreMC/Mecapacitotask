<?php
/**
 * HtmlRico - saneado y utilidades para el texto enriquecido del panel.
 *
 * Las descripciones de tareas y requerimientos se editan con un editor rico
 * (se puede pegar contenido con formato y TABLAS, p. ej. un correo). Eso llega
 * como HTML del navegador y NO se puede guardar tal cual: sería una puerta de
 * XSS. Aquí se recorta a una lista blanca de etiquetas/atributos y se quita
 * todo lo peligroso (script, estilos, on*, iframes, javascript:, etc.).
 *
 * `limpiar()` es lo que se guarda; `texto()` da el texto plano para vistas
 * previas y búsquedas; `vacio()` decide si hay algo que mostrar.
 */
class HtmlRico
{
    /** Etiquetas que se conservan (todo lo demás se "desenvuelve"). */
    private const TAGS = [
        'p', 'br', 'hr', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'sub', 'sup',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote',
        'a', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'col', 'colgroup',
        'code', 'pre', 'span', 'div',
    ];

    /** Etiquetas que se eliminan CON su contenido (nunca deben renderizarse). */
    private const QUITAR = [
        'script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'textarea',
        'button', 'select', 'option', 'meta', 'link', 'base', 'noscript', 'svg',
        'math', 'title', 'head',
    ];

    /** Atributos permitidos por etiqueta (el resto se borra siempre). */
    private const ATTRS = [
        'a'        => ['href', 'title'],
        'td'       => ['colspan', 'rowspan'],
        'th'       => ['colspan', 'rowspan'],
        'col'      => ['span'],
        'colgroup' => ['span'],
    ];

    /**
     * Devuelve el HTML saneado y listo para guardar. Cadena vacía si no queda
     * nada con sentido.
     */
    public static function limpiar(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        $doc  = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // El <meta charset> es lo que hace que libxml interprete UTF-8 bien.
        $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $raiz = $doc->getElementsByTagName('body')->item(0);
        if (!$raiz) {
            return '';
        }
        self::limpiarNodo($raiz);

        $out = '';
        foreach (iterator_to_array($raiz->childNodes) as $hijo) {
            // El <meta> que inyectamos no es contenido: se ignora al serializar.
            if ($hijo instanceof DOMElement && strtolower($hijo->nodeName) === 'meta') {
                continue;
            }
            $out .= $doc->saveHTML($hijo);
        }
        return trim($out);
    }

    /** Recorre y limpia los hijos de un nodo (recursivo, in-place). */
    private static function limpiarNodo(DOMNode $nodo): void
    {
        foreach (iterator_to_array($nodo->childNodes) as $hijo) {
            if ($hijo instanceof DOMText || $hijo instanceof DOMDocumentType) {
                continue;                       // el texto se queda tal cual
            }
            if (!($hijo instanceof DOMElement)) {
                $nodo->removeChild($hijo);      // comentarios, PIs → fuera
                continue;
            }

            $tag = strtolower($hijo->nodeName);
            if (in_array($tag, self::QUITAR, true)) {
                $nodo->removeChild($hijo);
                continue;
            }

            self::limpiarNodo($hijo);           // limpia dentro antes de decidir

            if (!in_array($tag, self::TAGS, true)) {
                // Etiqueta desconocida (span de Word, font, o:p…): se desenvuelve
                // conservando su contenido, ya limpio.
                while ($hijo->firstChild) {
                    $nodo->insertBefore($hijo->firstChild, $hijo);
                }
                $nodo->removeChild($hijo);
                continue;
            }

            self::limpiarAtributos($hijo, $tag);
        }
    }

    /** Deja solo los atributos de la lista blanca y valida los href. */
    private static function limpiarAtributos(DOMElement $el, string $tag): void
    {
        $permitidos = self::ATTRS[$tag] ?? [];
        foreach (iterator_to_array($el->attributes) as $attr) {
            $nombre = strtolower($attr->nodeName);
            if (!in_array($nombre, $permitidos, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }
            if ($tag === 'a' && $nombre === 'href') {
                $href = trim($el->getAttribute('href'));
                // Solo enlaces navegables y seguros: nada de javascript:, data:…
                if (!preg_match('#^(https?:|mailto:)#i', $href)) {
                    $el->removeAttribute('href');
                }
            }
        }
        // Un enlace que sobrevive se abre fuera y sin fugar el referer.
        if ($tag === 'a' && $el->hasAttribute('href')) {
            $el->setAttribute('target', '_blank');
            $el->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    /**
     * Texto plano de un HTML rico, para vistas previas y búsquedas: sin
     * etiquetas, con los espacios colapsados y, si $max > 0, recortado.
     */
    public static function texto(string $html, int $max = 0): string
    {
        $t = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = trim((string)preg_replace('/\s+/u', ' ', $t));
        if ($max > 0 && mb_strlen($t) > $max) {
            $t = rtrim(mb_substr($t, 0, $max - 1)) . '…';
        }
        return $t;
    }

    /** ¿No hay nada que mostrar? (texto vacío y sin tabla/imagen/regla). */
    public static function vacio(string $html): bool
    {
        if (self::texto($html) !== '') {
            return false;
        }
        return !preg_match('/<(table|img|hr)\b/i', $html);
    }

    /**
     * Añade estilos EN LÍNEA a un HTML ya saneado para que se vea bien en un
     * correo (los clientes ignoran las hojas de estilo y no heredan bordes de
     * tabla). $acento colorea los enlaces. Devuelve '' si no hay contenido.
     */
    public static function paraCorreo(string $html, string $acento = '#2B76F7'): string
    {
        // Se sanea primero: aunque el flujo ya guarda HTML limpio, así este
        // método es seguro aunque le llegue algo crudo (defensa en profundidad).
        $html = self::limpiar($html);
        if ($html === '') {
            return '';
        }
        $doc  = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $estilos = [
            'table'      => 'border-collapse:collapse;width:100%;margin:10px 0;font-size:13px;',
            'th'         => 'border:1px solid #e5e7eb;padding:7px 10px;text-align:left;background:#f3f4f6;font-weight:700;color:#111827;',
            'td'         => 'border:1px solid #e5e7eb;padding:7px 10px;text-align:left;color:#1f2937;vertical-align:top;',
            'p'          => 'margin:0 0 10px;line-height:1.55;',
            'ul'         => 'margin:0 0 10px;padding-left:20px;',
            'ol'         => 'margin:0 0 10px;padding-left:20px;',
            'li'         => 'margin:0 0 4px;line-height:1.5;',
            'blockquote' => 'margin:0 0 10px;padding:6px 14px;border-left:3px solid ' . $acento . ';color:#4b5563;',
            'h1'         => 'font-size:19px;margin:0 0 8px;font-weight:800;color:#111827;',
            'h2'         => 'font-size:17px;margin:0 0 8px;font-weight:800;color:#111827;',
            'h3'         => 'font-size:15px;margin:0 0 8px;font-weight:700;color:#111827;',
            'h4'         => 'font-size:14px;margin:0 0 6px;font-weight:700;color:#111827;',
            'a'          => 'color:' . $acento . ';text-decoration:underline;',
            'code'       => 'font-family:Consolas,Menlo,monospace;background:#f3f4f6;padding:1px 5px;border-radius:5px;',
            'pre'        => 'background:#f3f4f6;padding:10px 12px;border-radius:8px;overflow:auto;font-size:12.5px;',
        ];
        foreach ($estilos as $tag => $css) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $el) {
                $el->setAttribute('style', $css);
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        $raiz = $body ?: $doc->documentElement;
        $out  = '';
        foreach (iterator_to_array($raiz->childNodes) as $hijo) {
            if ($hijo instanceof DOMElement && strtolower($hijo->nodeName) === 'meta') {
                continue;
            }
            $out .= $doc->saveHTML($hijo);
        }
        return trim($out);
    }
}
