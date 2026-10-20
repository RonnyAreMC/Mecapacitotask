<?php
/**
 * Grabaciones de Zoom → Google Drive (solo el administrador).
 *
 * Busca TODAS las grabaciones de la cuenta de Zoom: las de reuniones creadas desde
 * el panel van a su proyecto y con el nombre del panel; las demás, a «Otras». Las pasa a Drive:
 *   <Proyecto>/Videos|Audios|Textos/<AAAA-MM>/<fecha hora tema>.<ext>
 * Cuando una reunión está entera en Drive (mismo tamaño), se manda a la papelera
 * de Zoom para liberar espacio. El trabajo lo hace lib/ZoomDrive.php: esta página
 * lo avanza mientras está abierta y cron_zoom_drive.php, aunque esté cerrada.
 */
require_once __DIR__ . '/lib/bootstrap.php';
Auth::requiereAdmin();
foreach (['Correo', 'ZoomDrive'] as $m) {
    if (is_file(__DIR__ . '/lib/' . $m . '.php')) require_once __DIR__ . '/lib/' . $m . '.php';
}

/** Respuesta JSON para los pasos que corre la página */
function zdJson(array $d): never
{
    header('Content-Type: application/json; charset=utf-8');
    exit(json_encode($d, JSON_UNESCAPED_UNICODE));
}

// Conectar Google Drive: a Google con el permiso «drive» (vuelve a oauth_google.php)
if (isset($_GET['conectar'])) {
    if (!GoogleLogin::listo()) redirigir('zoom_drive.php', 'Primero configura el acceso con Google en Ajustes → Acceso y respaldo (Client ID y Client Secret).', 'error');
    header('Location: ' . ZoomDrive::urlConectar());
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // Solo desde esta misma página
    $origen = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origen !== '' && parse_url($origen, PHP_URL_HOST) !== parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST)) {
        http_response_code(403);
        exit('Origen no permitido');
    }
    $accion = (string)($_POST['accion'] ?? '');
    switch ($accion) {
        case 'guardar':
            $carpeta = ZoomDrive::idCarpeta((string)($_POST['carpeta'] ?? ''));
            if ($carpeta === '') redirigir('zoom_drive.php', 'Pega el enlace de la carpeta de Drive (drive.google.com/drive/folders/…).', 'error');
            ZoomDrive::guardar(['carpeta' => $carpeta, 'borrar' => ($_POST['borrar'] ?? '') === 'no' ? 'no' : 'papelera']);
            if (ZoomDrive::driveListo()) {
                $p = ZoomDrive::probarCarpeta();
                redirigir('zoom_drive.php', 'Guardado. ' . $p['msg'], $p['ok'] ? 'success' : 'warning');
            }
            redirigir('zoom_drive.php', 'Guardado. Ahora conecta Google Drive.');

        case 'desconectar':
            ZoomDrive::desconectar();
            redirigir('zoom_drive.php', 'Google Drive desconectado. Lo que ya está en Drive no se toca.');

        case 'probar':
            $z = ZoomDrive::probarZoom();
            $d = ZoomDrive::driveListo() ? ZoomDrive::probarCarpeta() : ['ok' => false, 'msg' => 'Google Drive todavía no está conectado.'];
            redirigir('zoom_drive.php', $z['msg'] . ' ' . $d['msg'], $z['ok'] && $d['ok'] ? 'success' : 'warning');

        case 'buscar':
            // Por partes: la página repite hasta que termina
            if (!empty($_POST['reiniciar'])) {
                $desde = (string)($_POST['desde'] ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde)) ZoomDrive::guardar(['desde' => $desde]);
            }
            zdJson(ZoomDrive::buscar(20, !empty($_POST['reiniciar'])));

        case 'grupo':
            $n = ZoomDrive::cambiarGrupo((string)($_POST['reunion'] ?? ''), (string)($_POST['grupo'] ?? ''));
            zdJson(['ok' => $n > 0, 'n' => $n]);

        case 'encolar':
            $uuids = array_map('strval', (array)($_POST['uuids'] ?? []));
            if (($_POST['grupo'] ?? '') !== '') {
                // «Pasar todo a Drive» de una carpeta: solo esa carpeta
                $uuids = [];
                foreach (ZoomDrive::porGrupo()[(string)$_POST['grupo']] ?? [] as $r) $uuids[] = $r['uuid'];
            }
            $n = ZoomDrive::encolar($uuids);
            redirigir('zoom_drive.php' . ($n ? '?seguir=1' : ''), $n ? $n . ' archivo(s) en cola para Drive. Deja esta página abierta o programa el cron para que sigan solos.' : 'No había nada nuevo que poner en cola.', $n ? 'success' : 'info');

        case 'sacar':
            $n = ZoomDrive::sacarDeCola(array_map('strval', (array)($_POST['uuids'] ?? [])));
            redirigir('zoom_drive.php', $n ? $n . ' archivo(s) fuera de la cola.' : 'No había nada en cola para sacar.', 'info');

        case 'reintentar':
            $uuids = [];
            foreach (ZoomDrive::porGrupo() as $reuniones) {
                foreach ($reuniones as $r) {
                    if (ZoomDrive::estadoReunion($r['archivos']) === 'error') $uuids[] = $r['uuid'];
                }
            }
            $n = ZoomDrive::encolar($uuids);
            redirigir('zoom_drive.php' . ($n ? '?seguir=1' : ''), $n . ' archivo(s) de vuelta en la cola.');

        case 'paso':
            zdJson(ZoomDrive::paso(25) + ['ok' => true]);
    }
    redirigir('zoom_drive.php', 'Acción desconocida.', 'error');
}

$conf      = ZoomDrive::conf();
$zoomOk    = ZoomDrive::zoomListo();
$driveOk   = ZoomDrive::driveListo();
$googleOk  = GoogleLogin::listo();
$curlOk    = function_exists('curl_init');
$resumen   = ZoomDrive::resumen();
$grupos    = ZoomDrive::porGrupo();
$proyectos = ZoomDrive::proyectos();
$escaneo   = (array)$conf['escaneo'];
$cron      = json_decode((string)@file_get_contents(__DIR__ . '/data/cron_zoom_drive.json'), true);
$cronHace  = is_array($cron) ? time() - (int)($cron['t'] ?? 0) : null;
$b = fn(int $n) => ZoomDrive::bytes($n);

// Opciones de carpeta: los proyectos del panel, las que ya se usan y, al final, «Otras»
$opciones = [];
foreach ($proyectos as $nombre) $opciones[$nombre] = $nombre;
foreach (array_keys($grupos) as $g) $opciones[$g] = $g;
unset($opciones[ZoomDrive::OTRAS]);
$opciones[ZoomDrive::OTRAS] = ZoomDrive::OTRAS;
$etiquetaEstado = ZoomDrive::ESTADOS;
$tonoEstado = ['nuevo' => 'neutral', 'en_cola' => 'info', 'subiendo' => 'info', 'en_drive' => 'success', 'borrado' => 'success', 'error' => 'danger'];

UI::inicio('Grabaciones a Drive', 'ajustes');
UI::cabecera(
    'Grabaciones de Zoom <span class="text-secondary">a Drive</span>',
    'Copia cada grabación a tu Drive, ordenada por proyecto, y la saca de Zoom para liberar espacio.',
    '<a class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md" href="ajustes.php#tab-reuniones">' . UI::iconoDS('configuracion', 18) . '<span class="mc-btn__label">Ajustes de Zoom</span></a>',
    'despliegue'
);
?>
<div class="ds zd" data-zd data-seguir="<?= isset($_GET['seguir']) ? '1' : '' ?>">

  <?php if (!$curlOk): ?>
    <?= UI::alertaDS('danger', 'Este servidor no tiene cURL', 'Hace falta la extensión curl de PHP para bajar de Zoom y subir a Drive.') ?>
  <?php endif; ?>

  <!-- 1. Conexión -->
  <section class="mc-card zd-card">
    <h2 class="zd-t"><?= UI::iconoDS('enlace', 20) ?> Conexión</h2>
    <div class="zd-con">
      <div class="zd-con__fila">
        <span class="zd-punto zd-punto--<?= $zoomOk ? 'ok' : 'no' ?>"></span>
        <div class="zd-con__txt"><b>Zoom</b>
          <small><?= $zoomOk ? 'La app de Ajustes. Necesita los permisos user:read:admin, recording:read:admin y recording:write:admin.'
                             : 'Falta conectar Zoom en Ajustes → Reuniones → Conexión con Zoom.' ?></small></div>
      </div>
      <div class="zd-con__fila">
        <span class="zd-punto zd-punto--<?= $driveOk ? 'ok' : 'no' ?>"></span>
        <div class="zd-con__txt"><b>Google Drive</b>
          <small><?= $conf['email'] !== '' ? 'Conectado con ' . e($conf['email']) . '.' : ($googleOk ? 'Conecta la cuenta de Google que puede editar la carpeta.' : 'Falta configurar el acceso con Google en Ajustes → Acceso y respaldo.') ?></small></div>
        <?php if ($conf['email'] !== ''): ?>
          <form method="post"><input type="hidden" name="accion" value="desconectar">
            <button class="mc-btn mc-btn--ghost mc-btn--sm"><span class="mc-btn__label">Desconectar</span></button></form>
        <?php endif; ?>
        <a class="mc-btn mc-btn--<?= $conf['email'] !== '' ? 'outline mc-btn--t-azul' : 'primary' ?> mc-btn--sm<?= $googleOk ? '' : ' is-disabled' ?>" href="<?= $googleOk ? 'zoom_drive.php?conectar=1' : '#' ?>">
          <?= UI::iconoDS('enlace', 16) ?><span class="mc-btn__label"><?= $conf['email'] !== '' ? 'Cambiar cuenta' : 'Conectar Google Drive' ?></span></a>
      </div>
    </div>

    <form method="post" class="zd-form">
      <input type="hidden" name="accion" value="guardar">
      <div class="zd-form__campos">
        <div class="mc-field">
          <label class="mc-field__label" for="zd-carpeta">Carpeta de Drive</label>
          <div class="mc-input mc-input--md"><?= UI::iconoDS('carpeta', 18, 'mc-input__icon') ?>
            <input class="mc-input__control" id="zd-carpeta" name="carpeta" value="https://drive.google.com/drive/folders/<?= e($conf['carpeta']) ?>" placeholder="https://drive.google.com/drive/folders/…"></div>
          <div class="mc-field__foot"><span class="mc-field__hint">Adentro va una carpeta por proyecto, con Videos, Audios y Textos. <a href="https://drive.google.com/drive/folders/<?= e($conf['carpeta']) ?>" target="_blank" rel="noopener">Abrir en Drive</a></span></div>
        </div>
        <div class="mc-field">
          <label class="mc-field__label">Después de copiar a Drive</label>
          <?= UI::selectDS('borrar', [
                'papelera' => ['Mandarla a la papelera de Zoom', 'Solo cuando la reunión entera está en Drive, con el mismo tamaño.'],
                'no'       => ['Dejarla en Zoom', 'Solo copia; borras tú desde Zoom.'],
              ], $conf['borrar'], ['tam' => 'md']) ?>
          <div class="mc-field__foot"><span class="mc-field__hint">Zoom la guarda 30 días en su papelera, por si acaso.</span></div>
        </div>
      </div>
      <div class="zd-form__botones">
        <button class="mc-btn mc-btn--primary mc-btn--md"><span class="mc-btn__label">Guardar</span></button>
        <button class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md" name="accion" value="probar"><span class="mc-btn__label">Probar conexión</span></button>
      </div>
    </form>
  </section>

  <!-- 2. Resumen -->
  <div class="zd-tiles" data-zd-tiles>
    <div class="mc-card zd-tile"><span class="zd-tile__n" data-zd="zoom"><?= $b($resumen['zoom_bytes']) ?></span><span class="zd-tile__t">En Zoom, sin copiar</span><small data-zd="zoom_n"><?= $resumen['zoom'] ?> archivo(s)</small></div>
    <div class="mc-card zd-tile"><span class="zd-tile__n" data-zd="cola"><?= $b($resumen['cola_bytes']) ?></span><span class="zd-tile__t">En cola</span><small data-zd="cola_n"><?= $resumen['cola'] ?> archivo(s)</small></div>
    <div class="mc-card zd-tile"><span class="zd-tile__n" data-zd="drive"><?= $b($resumen['drive_bytes']) ?></span><span class="zd-tile__t">Ya en Drive</span><small data-zd="drive_n"><?= $resumen['drive'] ?> archivo(s)</small></div>
    <div class="mc-card zd-tile zd-tile--verde"><span class="zd-tile__n" data-zd="liberado"><?= $b($resumen['liberado_bytes']) ?></span><span class="zd-tile__t">Liberado de Zoom</span><small>en la papelera de Zoom</small></div>
  </div>

  <!-- 3. Progreso -->
  <section class="mc-card zd-card zd-progreso" data-zd-progreso<?= $resumen['cola'] ? '' : ' hidden' ?>>
    <div class="zd-progreso__cab">
      <h2 class="zd-t"><?= UI::iconoDS('publicar', 20) ?> Pasando a Drive</h2>
      <button type="button" class="mc-btn mc-btn--primary mc-btn--sm" data-zd-seguir><span class="mc-btn__label">Seguir ahora</span></button>
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-zd-pausar hidden><span class="mc-btn__label">Pausar</span></button>
    </div>
    <p class="zd-progreso__archivo" data-zd-archivo><?= $resumen['actual'] ? e($resumen['actual']['nombre']) : 'Esperando el siguiente archivo…' ?></p>
    <div class="zd-barra"><span data-zd-barra style="width:<?= $resumen['actual'] && $resumen['actual']['tamano'] ? round(100 * $resumen['actual']['subido'] / $resumen['actual']['tamano']) : 0 ?>%"></span></div>
    <p class="zd-ayuda" data-zd-estado>
      <?php if ($cronHace !== null && $cronHace < 900): ?>
        El cron está trabajando (última vuelta hace <?= max(1, (int)round($cronHace / 60)) ?> min): sigue aunque cierres esta página.
      <?php else: ?>
        Sigue mientras esta página esté abierta. Para que avance sola, en cPanel → Cron Jobs agrega uno cada 5 minutos:
        <code>/usr/local/bin/php <?= e(str_replace('\\', '/', __DIR__)) ?>/cron_zoom_drive.php</code>
      <?php endif; ?>
    </p>
  </section>

  <!-- 4. Buscar en Zoom -->
  <section class="mc-card zd-card">
    <h2 class="zd-t"><?= UI::iconoDS('buscar', 20) ?> Buscar grabaciones en Zoom</h2>
    <div class="zd-buscar">
      <div class="mc-field"><label class="mc-field__label" for="zd-desde">Desde</label>
        <input type="date" id="zd-desde" value="<?= e($conf['desde']) ?>" max="<?= date('Y-m-d') ?>"></div><?php /* el calendario del panel (MecaDate) arma su propio campo */ ?>
      <button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-zd-buscar<?= $zoomOk && $curlOk ? '' : ' disabled' ?>>
        <?= UI::iconoDS('buscar', 18) ?><span class="mc-btn__label">Buscar grabaciones</span></button>
      <p class="zd-ayuda" data-zd-buscando>
        <?php if (!empty($escaneo['termino'])): ?>Última búsqueda: <?= e($escaneo['termino']) ?> · <?= (int)($escaneo['nuevas'] ?? 0) ?> archivo(s) nuevos.
        <?php elseif (!empty($escaneo['usuarios'])): ?>Búsqueda a medias: vuelve a pulsar para seguir.
        <?php else: ?>Trae todas las grabaciones de la cuenta, mes por mes: las de reuniones creadas en el panel van a su proyecto y con su nombre; las demás, a «Otras».<?php endif; ?>
      </p>
    </div>
  </section>

  <!-- 5. Las grabaciones, por proyecto -->
  <?php if (!$grupos): ?>
    <?= UI::vacioDS('despliegue', 'Todavía no hay grabaciones en la lista', 'Pulsa «Buscar grabaciones» para traer las de Zoom.') ?>
  <?php else: ?>
  <form method="post" class="zd-lista" data-zd-lista>
    <!-- Lo que hace el formulario si no se pulsa otro botón (los de abajo lo cambian) -->
    <input type="hidden" name="accion" value="encolar">
    <div class="zd-acciones">
      <button class="mc-btn mc-btn--primary mc-btn--md" name="accion" value="encolar" data-zd-con-sel disabled><?= UI::iconoDS('publicar', 18) ?><span class="mc-btn__label">Pasar las elegidas a Drive</span></button>
      <button class="mc-btn mc-btn--ghost mc-btn--md" name="accion" value="sacar" data-zd-con-sel disabled><span class="mc-btn__label">Sacar de la cola</span></button>
      <?php if ($resumen['errores']): ?>
      <button class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md" name="accion" value="reintentar"><span class="mc-btn__label">Reintentar las que fallaron (<?= $resumen['errores'] ?>)</span></button>
      <?php endif; ?>
      <span class="zd-ayuda" data-zd-cuenta></span>
    </div>

    <?php foreach ($grupos as $grupo => $reuniones):
        $peso = 0; $pendientes = 0;
        foreach ($reuniones as $r) {
            foreach ($r['archivos'] as $f) $peso += (int)$f['tamano'];
            if (ZoomDrive::estadoReunion($r['archivos']) === 'nuevo') $pendientes++;
        } ?>
    <details class="mc-card zd-grupo" open>
      <summary class="zd-grupo__cab">
        <span class="mc-tile mc-tile--<?= $grupo === ZoomDrive::OTRAS ? 'amarillo' : 'azul' ?> mc-tile--sm"><?= UI::iconoDS('carpeta', 18) ?></span>
        <span class="zd-grupo__t"><b><?= e($grupo) ?></b><small><?= count($reuniones) ?> reunión(es) · <?= $b($peso) ?></small></span>
        <?php if ($pendientes): ?>
        <button class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm" name="grupo" value="<?= e($grupo) ?>">
          <span class="mc-btn__label">Pasar todo a Drive (<?= $pendientes ?>)</span></button>
        <?php endif; ?>
      </summary>
      <?php if ($grupo === ZoomDrive::OTRAS): ?>
        <?= UI::alertaDS('info', 'Estas reuniones no se crearon desde el panel', 'Por eso van a «Otras». Si alguna es de un proyecto, elígelo en la columna «Carpeta»: se recuerda para las siguientes grabaciones de esa reunión.', true) ?>
      <?php endif; ?>
      <div class="zd-tabla">
        <table class="tabla-meca">
          <thead><tr>
            <th class="zd-col-sel"><input type="checkbox" data-zd-todas aria-label="Elegir todas las de <?= e($grupo) ?>"></th>
            <th>Fecha</th><th>Reunión</th><th>Archivos</th><th>Carpeta</th><th>Estado</th>
          </tr></thead>
          <tbody>
          <?php foreach ($reuniones as $r):
              $estado = ZoomDrive::estadoReunion($r['archivos']);
              $porTipo = ['video' => 0, 'audio' => 0, 'texto' => 0];
              $cuantos = ['video' => 0, 'audio' => 0, 'texto' => 0];
              $subido = 0; $total = 0; $error = '';
              foreach ($r['archivos'] as $f) {
                  $porTipo[$f['tipo']] += (int)$f['tamano'];
                  $cuantos[$f['tipo']]++;
                  $total += (int)$f['tamano'];
                  $subido += in_array($f['estado'], ['en_drive', 'borrado'], true) ? (int)$f['tamano'] : (int)$f['subido'];
                  if ($f['error'] !== '') $error = $f['error'];
              }
              $elegible = in_array($estado, ['nuevo', 'en_cola', 'error'], true); ?>
            <tr data-zd-fila>
              <td class="zd-col-sel"><?php if ($elegible): ?><input type="checkbox" name="uuids[]" value="<?= e($r['uuid']) ?>" data-zd-sel aria-label="Elegir <?= e($r['tema']) ?>"><?php endif; ?></td>
              <td class="zd-fecha"><?= e(substr($r['inicio'], 0, 10)) ?><small><?= e(substr($r['inicio'], 11, 5)) ?></small></td>
              <td class="zd-tema"><b><?= e($r['tema']) ?></b><?php if ($r['del_panel']): ?> <span class="zd-chip zd-chip--panel">Del panel</span><?php endif; ?><small><?= e($r['host']) ?></small></td>
              <td class="zd-archivos">
                <?php if ($cuantos['video']): ?><span class="zd-chip"><?= UI::iconoDS('video', 14) ?> <?= $cuantos['video'] > 1 ? $cuantos['video'] . ' videos' : 'Video' ?> · <?= $b($porTipo['video']) ?></span><?php endif; ?>
                <?php if ($cuantos['audio']): ?><span class="zd-chip"><?= UI::iconoDS('microfono', 14) ?> Audio · <?= $b($porTipo['audio']) ?></span><?php endif; ?>
                <?php if ($cuantos['texto']): ?><span class="zd-chip"><?= UI::iconoDS('documento', 14) ?> <?= $cuantos['texto'] ?> texto<?= $cuantos['texto'] > 1 ? 's' : '' ?></span><?php endif; ?>
              </td>
              <td class="zd-carpeta">
                <?php if ($elegible && $estado !== 'en_cola'): ?>
                  <?= UI::selectDS('g_' . md5($r['uuid']), $opciones, $grupo, ['aria' => 'Carpeta de ' . $r['tema']]) ?>
                  <input type="hidden" data-zd-reunion value="<?= e($r['reunion']) ?>">
                <?php else: ?><span class="zd-ayuda"><?= e($grupo) ?></span><?php endif; ?>
              </td>
              <td class="zd-estado">
                <span class="mc-status mc-status--pill mc-status--sm mc-status--<?= $tonoEstado[$estado] ?? 'neutral' ?>"><span class="mc-status__content"><span class="mc-status__label"><?= e($etiquetaEstado[$estado] ?? $estado) ?><?= $estado === 'subiendo' && $total ? ' ' . round(100 * $subido / $total) . '%' : '' ?></span></span></span>
                <?php if ($error !== '' && in_array($estado, ['error', 'en_drive', 'subiendo'], true)): ?><small class="zd-error"><?= e($error) ?></small><?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
    <?php endforeach; ?>
  </form>
  <?php endif; ?>
</div>

<script>
(() => {
  const raiz = document.querySelector('[data-zd]');
  if (!raiz) return;
  const aviso = (t, tipo, op) => { if (window.MC && MC.toast) MC.toast(t, tipo || 'info', 6000, op); };
  const post = async (datos) => {
    const fd = new FormData();
    Object.entries(datos).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch('zoom_drive.php', { method: 'POST', body: fd, credentials: 'same-origin' });
    return r.json();
  };

  /* ---------- Buscar en Zoom, por partes ---------- */
  const bBuscar = raiz.querySelector('[data-zd-buscar]');
  const txtBuscar = raiz.querySelector('[data-zd-buscando]');
  if (bBuscar) bBuscar.addEventListener('click', async () => {
    bBuscar.disabled = true;
    let primera = true;
    try {
      for (;;) {
        const d = await post({ accion: 'buscar', reiniciar: primera ? '1' : '', desde: raiz.querySelector('#zd-desde').value });
        primera = false;
        if (d.error) { txtBuscar.textContent = d.error; aviso('Zoom no respondió', 'error', { desc: d.error }); break; }
        txtBuscar.textContent = (d.texto || 'Buscando…') + ' · ' + (d.nuevas || 0) + ' archivo(s) nuevos';
        if (d.terminado) { location.href = 'zoom_drive.php'; return; }
      }
    } catch (e) { aviso('Se cortó la búsqueda', 'error', { desc: 'Vuelve a pulsar «Buscar grabaciones»: sigue donde iba.' }); }
    bBuscar.disabled = false;
  });

  /* ---------- Cambiar la carpeta de una reunión ---------- */
  raiz.querySelectorAll('.zd-carpeta select').forEach((sel) => {
    sel.addEventListener('change', async () => {
      const reunion = sel.closest('td').querySelector('[data-zd-reunion]').value;
      const d = await post({ accion: 'grupo', reunion, grupo: sel.value }).catch(() => null);
      if (d && d.ok) { aviso('Va a «' + sel.value + '»', 'success', { desc: d.n + ' archivo(s); las siguientes grabaciones de esta reunión también.' }); setTimeout(() => location.reload(), 900); }
      else aviso('No se pudo cambiar la carpeta', 'error');
    });
  });

  /* ---------- Elegir reuniones ---------- */
  const lista = raiz.querySelector('[data-zd-lista]');
  if (lista) {
    const pintar = () => {
      const n = lista.querySelectorAll('[data-zd-sel]:checked').length;
      lista.querySelectorAll('[data-zd-con-sel]').forEach((b) => { b.disabled = n === 0; });
      lista.querySelector('[data-zd-cuenta]').textContent = n ? n + ' reunión(es) elegida(s)' : '';
    };
    lista.addEventListener('change', (e) => {
      if (e.target.matches('[data-zd-todas]')) {
        e.target.closest('table').querySelectorAll('[data-zd-sel]').forEach((c) => { c.checked = e.target.checked; });
      }
      if (e.target.matches('[data-zd-sel], [data-zd-todas]')) pintar();
    });
  }

  /* ---------- Avanzar la cola mientras la página está abierta ---------- */
  const caja = raiz.querySelector('[data-zd-progreso]');
  const bSeguir = raiz.querySelector('[data-zd-seguir]');
  const bPausar = raiz.querySelector('[data-zd-pausar]');
  const tam = (n) => n >= 1073741824 ? (n / 1073741824).toFixed(1).replace('.', ',') + ' GB' : n >= 1048576 ? Math.round(n / 1048576) + ' MB' : n >= 1024 ? Math.round(n / 1024) + ' KB' : n + ' B';
  let andando = false;
  const pintarResumen = (d) => {
    const pon = (k, v) => { const el = raiz.querySelector('[data-zd="' + k + '"]'); if (el) el.textContent = v; };
    pon('zoom', tam(d.zoom_bytes)); pon('zoom_n', d.zoom + ' archivo(s)');
    pon('cola', tam(d.cola_bytes)); pon('cola_n', d.cola + ' archivo(s)');
    pon('drive', tam(d.drive_bytes)); pon('drive_n', d.drive + ' archivo(s)');
    pon('liberado', tam(d.liberado_bytes));
    raiz.querySelector('[data-zd-archivo]').textContent = d.actual ? d.actual.nombre + ' · ' + tam(d.actual.subido) + ' de ' + tam(d.actual.tamano) : 'Esperando el siguiente archivo…';
    raiz.querySelector('[data-zd-barra]').style.width = (d.actual && d.actual.tamano ? Math.round(100 * d.actual.subido / d.actual.tamano) : 0) + '%';
  };
  const seguir = async () => {
    if (andando) return;
    andando = true;
    bSeguir.hidden = true; bPausar.hidden = false;
    while (andando) {
      let d = null;
      try { d = await post({ accion: 'paso' }); } catch (e) { d = null; }
      if (!d) { aviso('Se cortó la conexión con el panel', 'error', { desc: 'Pulsa «Seguir ahora» para continuar.' }); break; }
      pintarResumen(d);
      if (d.error) { aviso('No se puede seguir', 'error', { desc: d.error }); break; }
      if (d.ocupado) { raiz.querySelector('[data-zd-estado]').textContent = 'El cron está subiendo en este momento; esta página solo mira.'; await new Promise((r) => setTimeout(r, 10000)); continue; }
      if (!d.cola) { aviso('¡Listo! Todo lo de la cola ya está en Drive', 'success'); setTimeout(() => location.reload(), 1200); break; }
    }
    andando = false;
    bSeguir.hidden = false; bPausar.hidden = true;
  };
  if (bSeguir) bSeguir.addEventListener('click', seguir);
  if (bPausar) bPausar.addEventListener('click', () => { andando = false; });
  if (caja && !caja.hidden && raiz.dataset.seguir === '1') seguir();
})();
</script>
<?php UI::fin(); ?>
