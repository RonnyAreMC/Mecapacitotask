<?php
/**
 * Cuentas de correo del cPanel, desde el panel (solo el administrador).
 *
 * Crear una cuenta, cambiarle la contraseña o el espacio, suspenderla o
 * reactivarla, borrarla y manejar los reenvíos, sin entrar al cPanel. Lo hace
 * lib/CPanel.php con la API oficial de cPanel y un token de API.
 *
 * Las contraseñas no se guardan: se mandan a cPanel y se muestran una sola vez
 * para copiarlas. Al cambiar una, la conexión de esa persona en el Correo del
 * panel se pone al día sola (si la tiene). Todo queda en la bitácora.
 */
require_once __DIR__ . '/lib/bootstrap.php';
Auth::requiereAdmin();
if (verComo()) redirigir('index.php', 'Sal de «ver como» para manejar las cuentas de correo.', 'info');
foreach (['Correo', 'CPanel'] as $m) {
    if (is_file(__DIR__ . '/lib/' . $m . '.php')) require_once __DIR__ . '/lib/' . $m . '.php';
}
if (!class_exists('CPanel')) redirigir('ajustes.php', 'Falta lib/CPanel.php en el servidor.', 'error');

/** Respuesta para el JS de la página */
function bzJson(bool $ok, string $msg = '', array $extra = []): never
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$ok) http_response_code(400);
    exit(json_encode(['ok' => $ok, 'error' => $ok ? '' : $msg, 'mensaje' => $ok ? $msg : ''] + $extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** El espacio pedido en MB: una de las opciones o «otro» con su número. */
function bzCuota(): int
{
    $v = (string)($_POST['cuota'] ?? '1024');
    if ($v === 'otro') return max(50, min(1048576, (int)($_POST['cuota_mb'] ?? 0)));
    return max(0, (int)$v);
}

/** Datos para entrar al correo (los que se copian al crear o al cambiar la contraseña). */
function bzAcceso(string $email): array
{
    $c = class_exists('Correo') ? Correo::conf() : [];
    $dom = CPanel::partes($email)[1];
    return ['webmail' => 'https://' . $dom . '/webmail', 'imap' => (string)($c['imap_host'] ?? ('mail.' . $dom)) . ':' . (int)($c['imap_puerto'] ?? 993),
            'smtp' => (string)($c['smtp_host'] ?? ('mail.' . $dom)) . ':' . (int)($c['smtp_puerto'] ?? 465)];
}

/** Campo de contraseña con «Generar» y ver/ocultar. Vacío = se genera una segura en el servidor. */
function bzCampoClave(string $id): string
{
    return '<div class="mc-field"><label class="mc-field__label" for="' . e($id) . '">Contraseña</label>'
        . '<div class="bz-clave"><div class="mc-input mc-input--md"><input class="mc-input__control" id="' . e($id) . '" name="clave" type="text" minlength="10" autocomplete="new-password" spellcheck="false" placeholder="Vacía = se genera una segura"></div>'
        . '<button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md" data-bz-generar>' . UI::iconoDS('reintentar', 16) . '<span class="mc-btn__label">Generar</span></button></div>'
        . '<div class="mc-field__foot"><span class="mc-field__hint">Al menos 10 caracteres, con mayúsculas, números y símbolos.</span></div></div>';
}

/** Selector del espacio (con «Otro tamaño…» en MB). */
function bzCampoCuota(string $id, string $sel, array $ops): string
{
    return '<div class="mc-field"><label class="mc-field__label">Espacio del buzón</label>'
        . UI::selectDS('cuota', $ops, $sel, ['aria' => 'Espacio del buzón', 'attrs' => 'data-bz-cuota'])
        . '<div class="mc-input mc-input--md bz-otro" hidden><input class="mc-input__control" name="cuota_mb" type="number" min="50" step="50" placeholder="Tamaño en MB"><span class="mc-input__affix">MB</span></div></div>';
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Solo desde esta misma página
    $origen = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origen !== '' && parse_url($origen, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        http_response_code(403);
        exit('Origen no permitido');
    }
    $accion = (string)($_POST['accion'] ?? '');
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if ($accion !== 'conexion' && !CPanel::listo()) bzJson(false, 'Primero conecta el cPanel.');
    if (in_array($accion, ['clave', 'cuota', 'suspender', 'borrar'], true) && !CPanel::emailValido($email)) bzJson(false, 'Esa no es una cuenta de correo válida.');
    $cuotaTxt = fn(int $mb) => $mb === 0 ? 'sin límite' : (CPanel::CUOTAS[$mb] ?? ($mb >= 1024 ? round($mb / 1024, 1) . ' GB' : $mb . ' MB'));

    switch ($accion) {
        case 'conexion':
            CPanel::guardar($_POST);
            $p = CPanel::probar();
            redirigir('buzones.php', $p['ok'] ? $p['msg'] : 'Guardado, pero no se pudo conectar: ' . $p['error'], $p['ok'] ? 'success' : 'warning');

        case 'crear':
            $usuario = strtolower(trim((string)($_POST['usuario'] ?? '')));
            $dominio = strtolower(trim((string)($_POST['dominio'] ?? '')));
            if (!CPanel::usuarioValido($usuario)) bzJson(false, 'El nombre de la cuenta lleva solo letras, números, punto, guion o guion bajo (ej. jperez o j.perez).');
            if (!in_array($dominio, CPanel::dominios(), true)) bzJson(false, 'Ese dominio no es de esta cuenta de cPanel.');
            $clave = (string)($_POST['clave'] ?? '');
            if ($clave === '') $clave = CPanel::claveSegura();
            if (mb_strlen($clave) < 10) bzJson(false, 'La contraseña necesita al menos 10 caracteres (o déjala vacía y se genera una segura).');
            $mb = bzCuota();
            $r = CPanel::crear($usuario, $dominio, $clave, $mb);
            if (!$r['ok']) bzJson(false, $r['error']);
            $nueva = $usuario . '@' . $dominio;
            CPanel::anotar('crear', $nueva, 'Espacio: ' . $cuotaTxt($mb));
            bzJson(true, 'Cuenta creada: ' . $nueva . '.', ['email' => $nueva, 'clave' => $clave, 'acceso' => bzAcceso($nueva)]);

        case 'clave':
            $clave = (string)($_POST['clave'] ?? '');
            if ($clave === '') $clave = CPanel::claveSegura();
            if (mb_strlen($clave) < 10) bzJson(false, 'La contraseña necesita al menos 10 caracteres (o déjala vacía y se genera una segura).');
            $r = CPanel::clave($email, $clave);
            if (!$r['ok']) bzJson(false, $r['error']);
            // Quien lo tiene sincronizado en el Correo del panel sigue entrando, sin volver a escribirla
            $sinc = !empty($_POST['sincronizar']) && class_exists('Correo') ? Correo::actualizarClave($email, $clave) : 0;
            CPanel::anotar('clave', $email, $sinc ? 'Se actualizó también su conexión en el panel.' : '');
            bzJson(true, 'Contraseña cambiada.' . ($sinc ? ' Su correo en el panel sigue conectado con la nueva.' : ''), ['email' => $email, 'clave' => $clave, 'acceso' => bzAcceso($email)]);

        case 'cuota':
            $mb = bzCuota();
            $r = CPanel::cuota($email, $mb);
            if (!$r['ok']) bzJson(false, $r['error']);
            CPanel::anotar('cuota', $email, 'Espacio: ' . $cuotaTxt($mb));
            bzJson(true, 'Ahora ' . $email . ' tiene ' . $cuotaTxt($mb) . '.');

        case 'suspender':
            $que = ($_POST['que'] ?? '') === 'incoming' ? 'incoming' : 'login';
            $si = !empty($_POST['valor']);
            $r = CPanel::suspender($email, $que, $si);
            if (!$r['ok']) bzJson(false, $r['error']);
            $txt = $que === 'login' ? ($si ? 'ya no puede entrar' : 'puede volver a entrar') : ($si ? 'ya no recibe correos' : 'vuelve a recibir correos');
            CPanel::anotar($si ? 'suspender' : 'reactivar', $email, ucfirst($txt) . '.');
            bzJson(true, $email . ' ' . $txt . '.');

        case 'borrar':
            if (strtolower(trim((string)($_POST['confirmar'] ?? ''))) !== $email) bzJson(false, 'Escribe la cuenta completa para confirmar que quieres borrarla.');
            $r = CPanel::borrar($email);
            if (!$r['ok']) bzJson(false, $r['error']);
            CPanel::anotar('borrar', $email, 'Se borró la cuenta y sus correos.');
            bzJson(true, 'Cuenta ' . $email . ' borrada.');

        case 'reenvio_crear':
            $de = strtolower(trim((string)($_POST['de'] ?? '')));
            $a = strtolower(trim((string)($_POST['a'] ?? '')));
            if (!CPanel::emailValido($de) || !in_array(CPanel::partes($de)[1], CPanel::dominios(), true)) bzJson(false, 'La dirección que se reenvía tiene que ser de uno de tus dominios.');
            if (!CPanel::emailValido($a)) bzJson(false, 'Escribe a qué correo se reenvía.');
            $r = CPanel::crearReenvio($de, $a);
            if (!$r['ok']) bzJson(false, $r['error']);
            CPanel::anotar('reenvio', $de, 'Ahora se reenvía a ' . $a . '.');
            bzJson(true, 'Listo: lo que llegue a ' . $de . ' se reenvía a ' . $a . '.');

        case 'reenvio_borrar':
            $de = strtolower(trim((string)($_POST['de'] ?? '')));
            $a = trim((string)($_POST['a'] ?? ''));
            $r = CPanel::borrarReenvio($de, $a);
            if (!$r['ok']) bzJson(false, $r['error']);
            CPanel::anotar('reenvio_quitar', $de, 'Ya no se reenvía a ' . $a . '.');
            bzJson(true, 'Reenvío quitado.');
    }
    bzJson(false, 'No sé hacer eso.');
}

/* ---------------- La página ---------------- */
$conf = CPanel::conf();
$listo = CPanel::listo();
$cuentas = [];
$error = '';
$dominios = [];
$reenvios = [];
$hosting = null;
if ($listo) {
    $rc = CPanel::cuentas();
    if ($rc['ok']) {
        $cuentas = $rc['data'];
        $dominios = CPanel::dominios();
        foreach ($dominios as $d) { $rr = CPanel::reenvios($d); if ($rr['ok']) $reenvios = array_merge($reenvios, $rr['data']); }
        $hosting = CPanel::espacioHosting();
    } else {
        $error = $rc['error'];
    }
}
// Quién es cada cuenta en el panel: por su correo de acceso o por el que sincronizó en el Correo
$porCorreo = [];
$mapa = (new MiembroRepo())->mapa();
foreach ($mapa as $m) if (trim((string)($m['email'] ?? '')) !== '') $porCorreo[strtolower(trim((string)$m['email']))] = $m;
$sincronizadas = [];
foreach ((new JsonStore('correo_cuentas'))->all() as $cc) {
    $em = strtolower((string)($cc['email'] ?? ''));
    if ($em === '') continue;
    $sincronizadas[$em] = true;
    if (!isset($porCorreo[$em]) && isset($mapa[(int)($cc['miembro_id'] ?? 0)])) $porCorreo[$em] = $mapa[(int)$cc['miembro_id']];
}
$usadoTotal = array_sum(array_column($cuentas, 'usado'));
$casiLlenas = count(array_filter($cuentas, fn($c) => $c['pct'] !== null && $c['pct'] >= 85));
$suspendidas = count(array_filter($cuentas, fn($c) => $c['login_suspendido'] || $c['entrada_suspendida']));
$bitacora = CPanel::bitacora(30);
$accionTxt = ['crear' => 'Creó', 'clave' => 'Cambió la contraseña de', 'cuota' => 'Cambió el espacio de', 'suspender' => 'Suspendió', 'reactivar' => 'Reactivó',
              'borrar' => 'Borró', 'reenvio' => 'Agregó un reenvío en', 'reenvio_quitar' => 'Quitó un reenvío de'];
$opcionesCuota = [];
foreach (CPanel::CUOTAS as $mb => $t) $opcionesCuota[(string)$mb] = $t;
$opcionesCuota['otro'] = 'Otro tamaño…';

UI::inicio('Cuentas de correo', 'buzones');
UI::cabecera(
    'Cuentas de <span class="text-secondary">correo</span>',
    'Las cuentas del cPanel sin entrar al cPanel: crea, cambia la contraseña o el espacio, suspende y maneja los reenvíos.',
    ($listo && !$error ? '<button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-bz-abrir="bz-dlg-crear">' . UI::iconoDS('mas', 18) . '<span class="mc-btn__label">Nueva cuenta</span></button>' : '')
    . '<button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md" data-bz-abrir="bz-dlg-conexion">' . UI::iconoDS('configuracion', 18) . '<span class="mc-btn__label">Conexión</span></button>'
);
?>
<div class="ds bz" data-bz>

<?php if (!function_exists('curl_init')): ?>
  <?= UI::alertaDS('danger', 'Este servidor no tiene cURL', 'Hace falta la extensión curl de PHP para hablar con cPanel.') ?>
<?php elseif (!$listo): ?>
  <section class="mc-card bz-card bz-vacio">
    <span class="mc-tile mc-tile--azul"><?= UI::iconoDS('correo', 24) ?></span>
    <div>
      <h2 class="bz-t">Conecta el cPanel</h2>
      <ol class="bz-pasos">
        <li>Entra a cPanel → <b>Seguridad</b> → <b>Administrar tokens de API</b> → <b>Crear</b>.</li>
        <li>Ponle de nombre <b>panel</b>, sin fecha de vencimiento (o una lejana), y copia el token: cPanel lo muestra una sola vez.</li>
        <li>Pulsa <b>Conexión</b> y pega el servidor, tu usuario de cPanel y el token.</li>
      </ol>
      <button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-bz-abrir="bz-dlg-conexion"><?= UI::iconoDS('enlace', 18) ?><span class="mc-btn__label">Conectar</span></button>
    </div>
  </section>
<?php elseif ($error !== ''): ?>
  <?= UI::alertaDS('warning', 'No se pudo leer el cPanel', e($error) . ' Revisa la <a href="#" data-bz-abrir="bz-dlg-conexion">conexión</a>.', true) ?>
<?php else: ?>

  <section class="bz-kpis" aria-label="Resumen">
    <article class="mc-card bz-kpi"><small>Cuentas</small><b class="mc-num"><?= count($cuentas) ?></b><span><?= e(implode(' · ', $dominios)) ?></span></article>
    <article class="mc-card bz-kpi"><small>Espacio de los buzones</small><b class="mc-num"><?= e(CPanel::tamano($usadoTotal)) ?></b>
      <span><?php if ($hosting && $hosting[1] > 0): ?>del hosting: <?= e(CPanel::tamano((int)($hosting[0] * 1048576))) ?> de <?= e(CPanel::tamano((int)($hosting[1] * 1048576))) ?><?php else: ?>en uso<?php endif; ?></span></article>
    <article class="mc-card bz-kpi<?= $casiLlenas ? ' is-aviso' : '' ?>"><small>Casi llenas</small><b class="mc-num"><?= $casiLlenas ?></b><span>con 85 % o más</span></article>
    <article class="mc-card bz-kpi"><small>Suspendidas</small><b class="mc-num"><?= $suspendidas ?></b><span>sin entrar o sin recibir</span></article>
  </section>

  <section class="mc-card bz-card">
    <header class="bz-cab">
      <h2 class="bz-t"><?= UI::iconoDS('usuarios', 20) ?> Cuentas</h2>
      <div class="mc-input mc-input--sm bz-buscar"><?= UI::iconoDS('buscar', 16, 'mc-input__icon') ?>
        <input class="mc-input__control" type="search" placeholder="Buscar cuenta o persona…" data-bz-buscar aria-label="Buscar cuenta o persona"></div>
    </header>
    <div class="mc-table bz-tabla"><div class="mc-table__scroll">
      <table class="mc-table__table">
        <thead><tr><th scope="col">Cuenta</th><th scope="col">Espacio</th><th scope="col">Estado</th><th scope="col"><span class="mc-sr-only">Acciones</span></th></tr></thead>
        <tbody>
        <?php foreach ($cuentas as $c): $m = $porCorreo[$c['email']] ?? null; $pct = $c['pct'];
            $nivel = $pct === null ? 'libre' : ($pct >= 95 ? 'lleno' : ($pct >= 85 ? 'alto' : 'ok'));
            $datos = ['email' => $c['email'], 'cuotaMb' => (int)round($c['cuota'] / 1048576), 'login' => $c['login_suspendido'], 'entrada' => $c['entrada_suspendida'],
                      'sinc' => isset($sincronizadas[$c['email']]), 'nombre' => (string)($m['nombre'] ?? '')]; ?>
          <tr data-bz-fila data-buscar="<?= e(mb_strtolower($c['email'] . ' ' . ($m['nombre'] ?? ''))) ?>">
            <td class="is-primary"><div class="mc-table__cell bz-cuenta">
              <?= $m ? UI::avatar($m, 32) : '<span class="bz-ini" aria-hidden="true">' . e(mb_strtoupper(mb_substr($c['usuario'], 0, 2))) . '</span>' ?>
              <span><b><?= e($c['email']) ?></b><small><?= $m ? e($m['nombre']) . (isset($sincronizadas[$c['email']]) ? ' · conectado al panel' : '') : 'Sin persona del panel' ?></small></span></div></td>
            <td><div class="mc-table__cell bz-espacio bz-n--<?= $nivel ?>">
              <span class="bz-barra" aria-hidden="true"><i style="--p:<?= (int)($pct ?? 0) ?>%"></i></span>
              <small class="mc-num"><?= e(CPanel::tamano($c['usado'])) ?> de <?= $c['cuota'] > 0 ? e(CPanel::tamano($c['cuota'])) : 'sin límite' ?><?= $pct !== null ? ' · ' . $pct . ' %' : '' ?></small></div></td>
            <td><div class="mc-table__cell bz-estado">
              <?php if (!$c['login_suspendido'] && !$c['entrada_suspendida']): ?><span class="mc-status mc-status--sm mc-status--success"><span class="mc-status__dot"></span>Activa</span><?php endif; ?>
              <?php if ($c['login_suspendido']): ?><span class="mc-status mc-status--sm mc-status--danger"><span class="mc-status__dot"></span>No puede entrar</span><?php endif; ?>
              <?php if ($c['entrada_suspendida']): ?><span class="mc-status mc-status--sm mc-status--neutral"><span class="mc-status__dot"></span>No recibe</span><?php endif; ?>
            </div></td>
            <td><div class="mc-table__cell bz-acc" data-bz-cuenta="<?= e(json_encode($datos, JSON_UNESCAPED_UNICODE)) ?>">
              <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-bz-accion="clave"><?= UI::iconoDS('candado', 16) ?><span class="mc-btn__label">Contraseña</span></button>
              <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-bz-accion="cuota"><?= UI::iconoDS('datos', 16) ?><span class="mc-btn__label">Espacio</span></button>
              <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-bz-accion="mas" aria-label="Más acciones de <?= e($c['email']) ?>" data-ds-tip="Suspender, reactivar o borrar"><?= UI::iconoDS('opciones', 18) ?></button>
            </div></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$cuentas): ?><tr><td colspan="4"><p class="bz-nada">Todavía no hay cuentas de correo en este cPanel.</p></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div></div>
    <p class="bz-nada" data-bz-sin-resultados hidden>Nadie coincide con la búsqueda.</p>
  </section>

  <div class="bz-dos">
    <section class="mc-card bz-card">
      <header class="bz-cab"><h2 class="bz-t"><?= UI::iconoDS('enviar', 20) ?> Reenvíos</h2>
        <button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm" data-bz-abrir="bz-dlg-reenvio"><?= UI::iconoDS('mas', 16) ?><span class="mc-btn__label">Nuevo reenvío</span></button></header>
      <?php if ($reenvios): ?>
      <ul class="bz-lista">
        <?php foreach ($reenvios as $f): ?>
        <li><span><b><?= e($f['de']) ?></b> <span aria-hidden="true">→</span> <?= e($f['a']) ?></span>
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-bz-reenvio-borrar="<?= e(json_encode($f, JSON_UNESCAPED_UNICODE)) ?>" aria-label="Quitar el reenvío de <?= e($f['de']) ?> a <?= e($f['a']) ?>"><?= UI::iconoDS('eliminar', 16) ?></button></li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?><p class="bz-nada">No hay reenvíos.</p><?php endif; ?>
    </section>

    <section class="mc-card bz-card">
      <header class="bz-cab"><h2 class="bz-t"><?= UI::iconoDS('auditoria', 20) ?> Bitácora</h2></header>
      <?php if ($bitacora): ?>
      <ol class="bz-bitacora">
        <?php foreach ($bitacora as $b): ?>
        <li><span><b><?= e((string)($mapa[(int)$b['quien']]['nombre'] ?? 'Alguien')) ?></b> <?= e($accionTxt[$b['accion']] ?? $b['accion']) ?> <b><?= e((string)$b['email']) ?></b><?= ($b['detalle'] ?? '') !== '' ? '. ' . e((string)$b['detalle']) : '' ?></span>
          <small class="mc-num"><?= e((string)$b['fecha']) ?></small></li>
        <?php endforeach; ?>
      </ol>
      <?php else: ?><p class="bz-nada">Aquí queda quién creó, cambió o borró cada cuenta.</p><?php endif; ?>
    </section>
  </div>
<?php endif; ?>

<!-- Conexión con cPanel -->
<dialog class="ds-modal" id="bz-dlg-conexion" aria-labelledby="bz-con-t">
  <form method="post" action="buzones.php" class="ds">
    <input type="hidden" name="accion" value="conexion">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('enlace', 18) ?></span>
      <div><h2 class="ds-modal__title" id="bz-con-t">Conexión con cPanel</h2>
        <p class="ds-modal__sub">Con un token de API de cPanel (Seguridad → Administrar tokens de API). Se guarda cifrado.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body bz-form">
      <div class="bz-fila2">
        <div class="mc-field"><label class="mc-field__label" for="bz-host">Servidor</label>
          <div class="mc-input mc-input--md"><input class="mc-input__control" id="bz-host" name="host" value="<?= e($conf['host']) ?>" placeholder="mail.tu-dominio.com" required></div></div>
        <div class="mc-field bz-puerto"><label class="mc-field__label" for="bz-puerto">Puerto</label>
          <div class="mc-input mc-input--md"><input class="mc-input__control" id="bz-puerto" name="puerto" type="number" value="<?= (int)$conf['puerto'] ?>" min="1" max="65535"></div></div>
      </div>
      <div class="mc-field"><label class="mc-field__label" for="bz-usuario">Usuario de cPanel</label>
        <div class="mc-input mc-input--md"><input class="mc-input__control" id="bz-usuario" name="usuario" value="<?= e($conf['usuario']) ?>" autocomplete="off" required></div></div>
      <div class="mc-field"><label class="mc-field__label" for="bz-token">Token de API</label>
        <div class="mc-input mc-input--md"><input class="mc-input__control" id="bz-token" name="token" type="password" autocomplete="new-password"
          placeholder="<?= $conf['token'] !== '' ? '•••••••• guardado (déjalo vacío para no cambiarlo)' : 'Pega el token que te dio cPanel' ?>"<?= $conf['token'] === '' ? ' required' : '' ?>></div></div>
      <?= UI::checkDS('verificar_ssl', $conf['verificar_ssl'], 'Verificar el certificado', 'Déjalo encendido. Apágalo solo si el certificado del servidor no coincide con ese nombre.') ?>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Guardar y probar</span></button>
    </footer>
  </form>
</dialog>

<?php if ($listo && !$error): ?>
<!-- Nueva cuenta -->
<dialog class="ds-modal" id="bz-dlg-crear" aria-labelledby="bz-cr-t">
  <form class="ds" data-bz-form="crear">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('mas', 18) ?></span>
      <div><h2 class="ds-modal__title" id="bz-cr-t">Nueva cuenta de correo</h2>
        <p class="ds-modal__sub">La contraseña se muestra una sola vez al crearla, para que la copies.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body bz-form">
      <div class="mc-field"><label class="mc-field__label" for="bz-cr-u">Correo</label>
        <div class="bz-correo"><div class="mc-input mc-input--md"><input class="mc-input__control" id="bz-cr-u" name="usuario" placeholder="jperez" autocomplete="off" required pattern="[a-zA-Z0-9._\-]+"></div>
          <span class="bz-arroba">@</span><?= UI::selectDS('dominio', array_combine($dominios, $dominios), $dominios[0] ?? '', ['aria' => 'Dominio']) ?></div></div>
      <?= bzCampoClave('bz-cr-c') ?>
      <?= bzCampoCuota('bz-cr', '2048', $opcionesCuota) ?>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Crear cuenta</span></button>
    </footer>
  </form>
</dialog>

<!-- Contraseña -->
<dialog class="ds-modal" id="bz-dlg-clave" aria-labelledby="bz-cl-t">
  <form class="ds" data-bz-form="clave">
    <input type="hidden" name="email">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('candado', 18) ?></span>
      <div><h2 class="ds-modal__title" id="bz-cl-t">Cambiar la contraseña</h2><p class="ds-modal__sub" data-bz-email></p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body bz-form">
      <?= bzCampoClave('bz-cl-c') ?>
      <div data-bz-sinc><?= UI::checkDS('sincronizar', true, 'Que su correo en el panel siga conectado', 'Tiene el Correo del panel sincronizado: se pone al día con la nueva contraseña.') ?></div>
      <p class="bz-nota"><?= UI::iconoDS('info', 15) ?> En su celular o en Outlook tendrá que poner la nueva.</p>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Cambiar</span></button>
    </footer>
  </form>
</dialog>

<!-- Espacio -->
<dialog class="ds-modal" id="bz-dlg-cuota" aria-labelledby="bz-cu-t">
  <form class="ds" data-bz-form="cuota">
    <input type="hidden" name="email">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('datos', 18) ?></span>
      <div><h2 class="ds-modal__title" id="bz-cu-t">Espacio del buzón</h2><p class="ds-modal__sub" data-bz-email></p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body bz-form">
      <?= bzCampoCuota('bz-cu', '2048', $opcionesCuota) ?>
      <p class="bz-nota"><?= UI::iconoDS('info', 15) ?> Sale del espacio del hosting, que comparten todos los buzones.</p>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Guardar</span></button>
    </footer>
  </form>
</dialog>

<!-- Más: suspender, reactivar, borrar -->
<dialog class="ds-modal" id="bz-dlg-mas" aria-labelledby="bz-ma-t">
  <div class="ds">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('opciones', 18) ?></span>
      <div><h2 class="ds-modal__title" id="bz-ma-t">Más acciones</h2><p class="ds-modal__sub" data-bz-email></p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body bz-mas">
      <button type="button" class="bz-op" data-bz-suspender="login"><?= UI::iconoDS('candado', 20) ?><span><b data-bz-t-login></b><small data-bz-d-login></small></span></button>
      <button type="button" class="bz-op" data-bz-suspender="incoming"><?= UI::iconoDS('correo', 20) ?><span><b data-bz-t-incoming></b><small data-bz-d-incoming></small></span></button>
      <form class="bz-borrar" data-bz-form="borrar">
        <input type="hidden" name="email">
        <p><b>Borrar la cuenta</b><small>Se borran la cuenta y todos sus correos en el servidor. No se puede deshacer.</small></p>
        <div class="mc-input mc-input--sm"><input class="mc-input__control" name="confirmar" placeholder="Escribe la cuenta completa para confirmar" autocomplete="off" required></div>
        <button class="mc-btn mc-btn--danger mc-btn--sm"><?= UI::iconoDS('eliminar', 16) ?><span class="mc-btn__label">Borrar para siempre</span></button>
      </form>
    </div>
  </div>
</dialog>

<!-- Nuevo reenvío -->
<dialog class="ds-modal" id="bz-dlg-reenvio" aria-labelledby="bz-re-t">
  <form class="ds" data-bz-form="reenvio_crear">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('enviar', 18) ?></span>
      <div><h2 class="ds-modal__title" id="bz-re-t">Nuevo reenvío</h2><p class="ds-modal__sub">Lo que llegue a una dirección se manda también a otra.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body bz-form">
      <div class="mc-field"><label class="mc-field__label" for="bz-re-de">Lo que llegue a</label>
        <div class="mc-input mc-input--md"><input class="mc-input__control" id="bz-re-de" name="de" type="email" placeholder="info@<?= e($dominios[0] ?? 'tu-dominio.com') ?>" list="bz-lista-cuentas" required></div></div>
      <datalist id="bz-lista-cuentas"><?php foreach ($cuentas as $c): ?><option value="<?= e($c['email']) ?>"><?php endforeach; ?></datalist>
      <div class="mc-field"><label class="mc-field__label" for="bz-re-a">Se reenvía a</label>
        <div class="mc-input mc-input--md"><input class="mc-input__control" id="bz-re-a" name="a" type="email" placeholder="alguien@gmail.com" required></div></div>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Crear reenvío</span></button>
    </footer>
  </form>
</dialog>

<!-- La contraseña, una sola vez -->
<dialog class="ds-modal" id="bz-dlg-listo" aria-labelledby="bz-li-t">
  <div class="ds">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--verde"><?= UI::iconoDS('check', 18) ?></span>
      <div><h2 class="ds-modal__title" id="bz-li-t" data-bz-li-t>Listo</h2><p class="ds-modal__sub">Cópiala ahora: el panel no la guarda y no se vuelve a mostrar.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body">
      <dl class="bz-datos" data-bz-datos></dl>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md" data-bz-copiar><?= UI::iconoDS('copiar', 18) ?><span class="mc-btn__label">Copiar los datos</span></button>
      <button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Ya la copié</span></button>
    </footer>
  </div>
</dialog>
<?php endif; ?>
</div>
<script src="<?= asset('assets/buzones.js') ?>" defer></script>
<?php UI::fin(); ?>
