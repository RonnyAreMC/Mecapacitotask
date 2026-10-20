<?php
/**
 * Notificaciones del panel (la campana de la barra de arriba).
 *   ?json=1&desde=ID  lo que pide la campana cada minuto (JSON).
 *   ?abrir=ID         la marca leída y lleva a donde apunta.
 *   sin parámetros    la lista completa, para ver las viejas.
 *   ?tab=correo       el correo InnoTech de la persona (lib/correo_vista.php).
 *   ?correo=adjunto   descarga un adjunto de su correo.
 *   ?tab=dispositivos dónde le llegan los avisos nativos (Web Push, lib/Push.php).
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Notificaciones.php';
// El correo dentro del panel: si al subir por FTP falta, solo no aparece.
if (is_file(__DIR__ . '/lib/Correo.php')) require_once __DIR__ . '/lib/Correo.php';
if (is_file(__DIR__ . '/lib/correo_vista.php')) require_once __DIR__ . '/lib/correo_vista.php';
if (is_file(__DIR__ . '/lib/Push.php')) require_once __DIR__ . '/lib/Push.php';
if (is_file(__DIR__ . '/lib/Tutoriales.php')) require_once __DIR__ . '/lib/Tutoriales.php';
if (is_file(__DIR__ . '/lib/ReqCorreo.php')) require_once __DIR__ . '/lib/ReqCorreo.php';
if (is_file(__DIR__ . '/lib/CorreoCopias.php')) require_once __DIR__ . '/lib/CorreoCopias.php';
Auth::requiereLogin();

$yo  = Auth::usuario();
$mid = (int)($yo['id'] ?? 0);
$correoOn = class_exists('Correo') && function_exists('correoVista') && Correo::activo();
$pushOn = class_exists('Push') && is_file(__DIR__ . '/sw.js') && Push::activo();

if (isset($_GET['abrir'])) {
    $n = Notif::leer((int)$_GET['abrir'], $mid);
    $url = $n ? Notif::urlSegura((string)($n['url'] ?? '')) : '';
    header('Location: ' . ($url !== '' ? $url : 'notificaciones.php'));
    exit;
}

if ($correoOn && ($_GET['correo'] ?? '') === 'adjunto') {
    correoDescargarAdjunto($mid);
}

$miembros = (new MiembroRepo())->mapa();

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $items = array_map(fn($n) => Notif::paraJs($n, $miembros), Notif::de($mid, 15, max(0, (int)($_GET['desde'] ?? 0))));
    $res = ['ok' => true, 'sinLeer' => Notif::sinLeer($mid), 'items' => $items];
    // Correos sin leer: el servidor solo se consulta cada pocos minutos
    if ($correoOn) $res['correo'] = Correo::noLeidos($mid, true);
    // Teléfono que falta o está mal escrito: la pestaña abierta muestra la ventana para corregirlo
    $telDlg = method_exists('UI', 'dialogTelefonoHtml') ? UI::dialogTelefonoHtml() : '';
    if ($telDlg !== '') $res['telefono'] = $telDlg;
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

$tabPedida = (string)($_GET['tab'] ?? '');
$tab = ($correoOn && $tabPedida === 'correo') ? 'correo' : (($pushOn && $tabPedida === 'dispositivos') ? 'dispositivos' : 'avisos');
$nSin = Notif::sinLeer($mid);
$nCorreo = $correoOn ? Correo::noLeidos($mid) : null;

// Arriba a la derecha: «Marcar todo» en avisos; «Redactar» en el correo ya sincronizado
$accionesCab = '';
if ($tab === 'avisos' && $nSin) {
    $accionesCab = '<form method="post" action="actions.php"><input type="hidden" name="accion" value="notif_leer_todas">'
        . '<button class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--md">' . UI::iconoDS('check-doble', 18) . '<span class="mc-btn__label">Marcar todo como leído</span></button></form>';
} elseif ($tab === 'correo' && Correo::conectado($mid) && !isset($_GET['redactar'])) {
    $accionesCab = '<a class="mc-btn mc-btn--success mc-btn--md" href="' . e(correoUrl(['redactar' => 1, 'c' => (string)($_GET['c'] ?? '')])) . '">'
        . UI::iconoDS('mas', 18) . '<span class="mc-btn__label">Redactar</span></a>';
}

// «Ver tutorial»: si el administrador asignó un video a esta pestaña, se abre ahí mismo
if (class_exists('Tutoriales')) {
    $accionesCab = Tutoriales::boton($tab === 'avisos' ? 'notificaciones' : $tab) . $accionesCab;
}

$tituloPag = ['correo' => 'Correo', 'dispositivos' => 'Mis dispositivos', 'avisos' => 'Notificaciones'][$tab];
UI::inicio($tituloPag, ['correo' => 'correo', 'dispositivos' => 'dispositivos', 'avisos' => 'notificaciones'][$tab]);
UI::cabecera(
    $tituloPag,
    [
        'correo'       => 'Tu correo InnoTech, sin salir del panel: lee, responde y escribe desde aquí.',
        'dispositivos' => 'Recibe los avisos al instante en tu celular o tu computadora, aunque el panel esté cerrado.',
        'avisos'       => 'Lo que pasó en tus proyectos y equipos: tareas que te asignaron, reuniones, pedidos de otros equipos, observaciones…',
    ][$tab],
    $accionesCab
);
?>
<div class="ds" id="notif-pagina">
  <?php /* Sin pestañas: Correo está en el menú de la izquierda y «Mis dispositivos» en el menú de la cuenta */ ?>
  <?php if ($tab === 'correo'): ?>
    <?php correoVista($yo); ?>
  <?php elseif ($tab === 'dispositivos'): ?>
    <?php pushDispositivos($yo); ?>
  <?php else: $todas = Notif::de($mid, 200); ?>
  <?php if (!$todas): ?>
    <?= UI::vacioDS('notificaciones-vacio', 'Estás al día', 'No tienes avisos. Cuando te asignen una tarea, te inviten a una reunión o te pidan algo de otro equipo, te llega aquí y en la campana de arriba.') ?>
  <?php else: ?>
  <section class="mc-card">
    <div class="mc-card__body" style="padding:6px">
      <ul class="mc-notif__list ds-notif-lista">
        <?php foreach ($todas as $n): $j = Notif::paraJs($n, $miembros); ?>
        <li>
          <a class="mc-notif__item<?= $j['leida'] ? '' : ' is-unread' ?>" href="<?= e($j['abrir']) ?>">
            <span class="mc-tile mc-tile--sm mc-tile--<?= e($j['tono']) ?>"><?= UI::iconoDS($j['icono'], 18) ?></span>
            <span class="mc-notif__body">
              <span class="mc-notif__ititle"><?= e($j['titulo']) ?></span>
              <?php if ($j['texto'] !== ''): ?><span class="mc-notif__text"><?= e($j['texto']) ?></span><?php endif; ?>
              <span class="mc-notif__time"><?= e($j['rotulo']) ?><?= $j['quien'] !== '' ? ' · ' . e($j['quien']) : '' ?> · <?= e($j['hace']) ?></span>
            </span>
            <?php if (!$j['leida']): ?><span class="mc-notif__dot" aria-label="Sin leer"></span><?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </section>
  <p class="ds-ayuda" style="margin-top:var(--space-3)">Las leídas se borran solas a los 30 días.</p>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php UI::fin(); ?>

<?php
/** Notificaciones → Tus dispositivos: dónde le llegan los avisos y cuáles. */
function pushDispositivos(array $yo): void
{
    $mid = (int)$yo['id'];
    $lista = Push::dispositivos($mid);
    $pref = Push::prefs($mid);
    usort($lista, fn($a, $b) => strcmp((string)($b['usado'] ?? ''), (string)($a['usado'] ?? '')));
    $correoSinc = class_exists('Correo') && Correo::conectado($mid);
    ?>
<section class="mc-card push-card">
  <header class="push-cab">
    <span class="mc-tile mc-tile--lg mc-tile--azul"><?= UI::iconoDS('notificaciones', 24) ?></span>
    <div class="push-cab__txt">
      <h2>Notificaciones en tus dispositivos</h2>
      <p>Te avisamos al instante —en el celular o en la computadora, aunque el panel esté cerrado— cuando te llega un correo InnoTech o te asignan algo.</p>
    </div>
    <button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-push-activar><?= UI::iconoDS('notificaciones', 18) ?><span class="mc-btn__label">Activar en este dispositivo</span></button>
  </header>

  <p class="push-aqui" data-push-aqui hidden></p>

  <?php pushInstalarApps(); ?>

  <div class="push-seccion">
    <h3>Dispositivos que reciben avisos</h3>
    <?php if (!$lista): ?>
      <p class="push-vacio">Todavía ninguno. Entra al panel desde tu celular o tu computadora y acepta las notificaciones cuando te lo preguntemos (o pulsa «Activar en este dispositivo»).</p>
    <?php else: ?>
    <ul class="push-lista">
      <?php foreach ($lista as $d):
          $tel = (bool)preg_match('/iPhone|iPad|Android/', (string)$d['equipo']);
          $est = method_exists('Push', 'estado') ? Push::estado($d) : null; ?>
      <li class="push-equipo" data-push-endpoint="<?= e((string)$d['endpoint']) ?>">
        <span class="mc-tile mc-tile--sm mc-tile--cian"><?= UI::iconoDS($tel ? 'telefono' : 'computadora', 18) ?></span>
        <span class="push-equipo__txt">
          <b><?= e((string)$d['equipo']) ?><?= ['correo' => ' · app del Correo (solo correos)', 'panel' => ' · app del panel'][$d['app'] ?? ''] ?? '' ?> <span class="push-equipo__este" hidden>· este dispositivo</span></b>
          <small>Activado <?= e(Notif::hace((string)($d['creado'] ?? ''))) ?><?= !empty($d['usado']) && !empty($d['ultimo']) ? ' · último aviso entregado ' . e(Notif::hace((string)$d['usado'])) : '' ?></small>
          <?php if ($est): ?>
          <small class="push-estado push-estado--<?= e($est['tono']) ?>"><?= e($est['texto']) ?><?= $est['cuando'] !== '' ? ' (' . e(Notif::hace($est['cuando'])) . ')' : '' ?></small>
          <?php endif; ?>
        </span>
        <form method="post" action="actions.php" data-confirmar="Ese dispositivo dejará de recibir avisos del panel." data-confirmar-titulo="¿Quitar este dispositivo?" data-confirmar-ok="Sí, quitar">
          <input type="hidden" name="accion" value="push_quitar"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button class="mc-btn mc-btn--ghost mc-btn--sm"><?= UI::iconoDS('eliminar', 16) ?><span class="mc-btn__label">Quitar</span></button>
        </form>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

  <form method="post" action="actions.php" class="push-seccion push-prefs">
    <input type="hidden" name="accion" value="push_prefs">
    <h3>Qué te avisamos</h3>
    <?= UI::checkDS('correo', $pref['correo'], 'Correos nuevos de tu buzón InnoTech', $correoSinc ? 'Quién te escribe y el asunto, al minuto de llegar.' : 'Primero sincroniza tu correo en la pestaña «Correo InnoTech».') ?>
    <?= UI::checkDS('avisos', $pref['avisos'], 'Avisos del panel', 'Tareas que te asignan, reuniones, pedidos de otros equipos, observaciones…') ?>
    <div class="push-prefs__pie">
      <button class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm"><span class="mc-btn__label">Guardar</span></button>
    </div>
  </form>

  <div class="push-seccion push-prueba">
    <form method="post" action="actions.php">
      <input type="hidden" name="accion" value="push_prueba">
      <button class="mc-btn mc-btn--ghost mc-btn--sm"<?= $lista ? '' : ' disabled' ?>><?= UI::iconoDS('enviar', 16) ?><span class="mc-btn__label">Enviar un aviso de prueba</span></button>
    </form>
    <p class="ds-ayuda">Llega como notificación del sistema (arriba a la derecha en Mac y Windows, en la barra de avisos en Android), aunque el panel esté abierto. En iPhone y iPad llega si abres el panel desde la pantalla de inicio (Compartir → Agregar a inicio).</p>
    <details class="push-ayuda">
      <summary>¿El panel dice que el aviso se entregó pero no te aparece?</summary>
      <p>Entonces lo está ocultando tu equipo. Revisa esto:</p>
      <ul>
        <li><b>Mac:</b> Ajustes del Sistema → Notificaciones → busca tu navegador (Google Chrome, Safari, Edge o Firefox) → activa «Permitir notificaciones» y elige el estilo «Tiras» o «Alertas». Desactiva «No molestar» y las Concentraciones. Con Chrome, este tiene que estar abierto (aunque sea sin ventanas); con Safari no hace falta.</li>
        <li><b>Windows:</b> Configuración → Sistema → Notificaciones → activa las notificaciones y las de tu navegador; desactiva «No molestar». En Chrome, Configuración → Sistema → «Seguir ejecutando aplicaciones en segundo plano al cerrar Google Chrome» (así llegan con el navegador cerrado).</li>
        <li><b>Android:</b> Ajustes → Aplicaciones → Chrome → Notificaciones: permitidas (también «Sitios»). Quita a Chrome del ahorro de batería («Sin restricciones»).</li>
        <li><b>En el navegador:</b> toca el candado junto a la dirección del panel → Notificaciones: «Permitir».</li>
      </ul>
    </details>
  </div>

  <?php if (Auth::esAdmin()):
      $cron = json_decode((string)@file_get_contents(__DIR__ . '/data/cron_correo.json'), true);
      $haceCron = is_array($cron) ? time() - (int)($cron['t'] ?? 0) : null; ?>
  <div class="push-seccion">
    <?php if ($haceCron !== null && $haceCron < 300): ?>
      <?= UI::alertaDS('success', 'Correos al instante: activo', 'El servidor revisa los buzones sincronizados cada minuto (última revisión hace ' . max(1, (int)round($haceCron / 60)) . ' min).', true) ?>
    <?php else: ?>
      <?= UI::alertaDS('warning', 'Falta programar la revisión de correos (solo administradores)',
          'Para avisar los correos al minuto, en cPanel → <b>Cron Jobs</b> agrega uno que corra <b>cada minuto</b>:<br><code>/usr/local/bin/php ' . e(str_replace('\\', '/', __DIR__)) . '/cron_correo.php</code>'
          . ($haceCron !== null ? '<br>La última revisión fue hace ' . (int)round($haceCron / 60) . ' minutos.' : ''), true) ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</section>
    <?php
}

/**
 * Tus dispositivos → «Instala la app»: el panel (InnoTech Hub) y solo el correo
 * (correo.php), cada uno con un botón grande por sistema: Apple, Android y Windows.
 * assets/ds.js (MCInstalar) guarda el pedido de instalar del navegador: con él, el
 * botón del sistema que se usa instala el panel con un clic; el del correo lleva a
 * correo.php?instalar=1 (una página solo instala la app de su propio manifest).
 * Sin él (Safari, Firefox), o para otro sistema, el botón abre los pasos en un modal.
 */
function pushInstalarApps(): void
{
    $apps = [];
    if (is_file(__DIR__ . '/manifest.webmanifest')) {
        $apps['panel'] = ['titulo' => 'InnoTech Hub', 'nombre' => 'InnoTech Hub', 'corto' => 'InnoTech', 'icono' => 'iconos/pwa/icono-192.png',
            'desc' => 'Todo el panel: proyectos, tareas, avisos y correo.', 'dir' => 'index.php', 'unclic' => ''];
    }
    if (is_file(__DIR__ . '/correo.php') && is_file(__DIR__ . '/manifest-correo.webmanifest') && class_exists('Correo') && Correo::activo()) {
        $apps['correo'] = ['titulo' => 'Correo', 'nombre' => 'Correo InnoTech', 'corto' => 'Correo', 'icono' => 'iconos/pwa/correo-192.png',
            'desc' => 'Solo tu correo InnoTech, con un aviso al instante por cada correo nuevo.', 'dir' => 'correo.php', 'unclic' => 'correo.php?instalar=1'];
    }
    if (!$apps) return;
    // Logos de los sistemas, en línea y con fill="currentColor" (toman el color del botón).
    // Apple y Windows: los mismos de la pantalla «Descargar la app» de SIGC (paginas/instalar.js).
    // Android: el robot de Font Awesome Free 6.4.0 (brands «android»), ícono con licencia
    // CC BY 4.0 (https://fontawesome.com/license/free). Solo se recortó el viewBox para centrarlo.
    $logos = [
        'apple'   => '<svg class="ins-logo" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M494.782 340.02c-.803-81.025 66.084-119.907 69.072-121.832-37.595-54.993-96.167-62.552-117.037-63.402-49.843-5.032-97.242 29.362-122.565 29.362-25.253 0-64.277-28.607-105.604-27.85-54.32.803-104.4 31.594-132.403 80.245C29.81 334.457 71.81 479.58 126.816 558.976c26.87 38.882 58.914 82.56 100.997 81 40.512-1.594 55.843-26.244 104.848-26.244 48.993 0 62.753 26.245 105.64 25.406 43.606-.803 71.232-39.638 97.925-78.65 30.887-45.12 43.548-88.75 44.316-90.994-.969-.437-85.029-32.634-85.879-129.439l.118-.035zM414.23 102.178C436.553 75.095 451.636 37.5 447.514-.024c-32.162 1.311-71.163 21.437-94.253 48.485-20.729 24.012-38.836 62.28-33.993 99.036 35.918 2.8 72.591-18.248 94.926-45.272l.036-.047z"/></svg>',
        'android' => '<svg class="ins-logo" viewBox="0 32 576 384" aria-hidden="true" focusable="false"><path fill="currentColor" d="M420.55,301.93a24,24,0,1,1,24-24,24,24,0,0,1-24,24m-265.1,0a24,24,0,1,1,24-24,24,24,0,0,1-24,24m273.7-144.48,47.94-83a10,10,0,1,0-17.27-10h0l-48.54,84.07a301.25,301.25,0,0,0-246.56,0L116.18,64.45a10,10,0,1,0-17.27,10h0l47.94,83C64.53,202.22,8.24,285.55,0,384H576c-8.24-98.45-64.54-181.78-146.85-226.55"/></svg>',
        'windows' => '<svg class="ins-logo" viewBox="0 0 640 640" aria-hidden="true" focusable="false"><path fill="currentColor" d="M.2 298.669L0 90.615l256.007-34.76v242.814H.201zM298.658 49.654L639.905-.012v298.681H298.657V49.654zM640 341.331l-.071 298.681L298.669 592V341.332h341.33zM255.983 586.543L.189 551.463v-210.18h255.794v245.26z"/></svg>',
    ];
    $sistemas = [
        'apple'   => ['Apple', 'iPhone, iPad y Mac'],
        'android' => ['Android', 'Celular y tableta'],
        'windows' => ['Windows', 'Windows 10 y 11'],
    ];
    // La flecha que cae a la bandeja mientras instala (reemplaza al logo) y el visto al terminar
    $bajando = '<svg class="ins-bajando" viewBox="0 0 48 48" aria-hidden="true" focusable="false"><g class="ins-bajando__flecha"><path d="M24 6v24M14 21l10 10 10-10" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></g><path d="M8 32v6a4 4 0 0 0 4 4h24a4 4 0 0 0 4-4v-6" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    $listo = '<svg class="ins-boton__listo" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    ?>
  <div class="push-seccion ins-apps" id="instalar" data-instalar>
    <h3>Instala la app</h3>
    <p class="ins-apps__intro">Tenla en tu pantalla de inicio, el Dock o la barra de tareas como una app más: se abre en su propia ventana y te llegan los avisos aunque la cierres. En iPhone y iPad es la única forma de recibirlos.</p>
    <div class="ins-apps__lista">
      <?php foreach ($apps as $id => $a): ?>
      <article class="ins-app" data-instalar-app="<?= e($id) ?>" data-nombre="<?= e($a['nombre']) ?>" data-corto="<?= e($a['corto']) ?>" data-dir="<?= e($a['dir']) ?>" data-un-clic="<?= e($a['unclic']) ?>">
        <header class="ins-app__cab">
          <img src="<?= e($a['icono']) ?>" alt="" width="44" height="44" loading="lazy">
          <span><b><?= e($a['titulo']) ?></b><small><?= e($a['desc']) ?></small></span>
        </header>
        <?php if ($id === 'panel'): ?>
        <div class="ins-app__ya" data-instalar-ya hidden>
          <?= UI::iconoDS('exito', 20) ?>
          <span><b>Ya la estás usando instalada.</b> Los avisos te llegan aunque la cierres.</span>
          <button type="button" class="ins-app__otro" data-instalar-otro>Instalar en otro dispositivo</button>
        </div>
        <?php endif; ?>
        <div class="ins-botones" data-instalar-botones>
          <?php foreach ($sistemas as $so => [$sNombre, $sDetalle]): ?>
          <button type="button" class="ins-boton" data-instalar-so="<?= e($so) ?>" data-detalle="<?= e($sDetalle) ?>">
            <span class="ins-boton__marca"><?= $logos[$so] . $bajando . $listo ?></span>
            <span class="ins-boton__texto">
              <span class="mc-sr-only">Instalar <?= e($a['titulo']) ?> en</span>
              <strong><?= e($sNombre) ?></strong>
              <small data-estado><?= e($sDetalle) ?></small>
              <span class="ins-boton__reco" data-reco hidden>Recomendado para este dispositivo</span>
            </span>
            <span class="ins-boton__barra" aria-hidden="true"><span></span></span>
          </button>
          <?php endforeach; ?>
        </div>
      </article>
      <?php endforeach; ?>
    </div>
    <p class="ins-nota" data-instalar-nota aria-live="polite" hidden></p>
  </div>

  <dialog class="ds-modal ins-pasos" data-instalar-pasos aria-labelledby="ins-pasos-titulo">
    <header class="ds-modal__head">
      <span class="ins-pasos__logo" data-pasos-logo></span>
      <div>
        <h2 class="ds-modal__title" id="ins-pasos-titulo" data-pasos-titulo>Instalar la app</h2>
        <p class="ds-modal__sub">Queda como una app más, en su propia ventana, y te llegan los avisos aunque la cierres.</p>
      </div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 16) ?></button>
    </header>
    <div class="ds-modal__body">
      <p class="ins-pasos__fuera" data-pasos-fuera hidden></p>
      <div class="ins-pasos__dir">
        <span class="ins-pasos__dir-txt"><small>Dirección de la app</small><code data-pasos-dir></code></span>
        <button type="button" class="mc-btn mc-btn--outline mc-btn--t-azul mc-btn--sm" data-pasos-copiar><?= UI::iconoDS('copiar', 16) ?><span class="mc-btn__label">Copiar</span></button>
      </div>
      <div data-pasos-cuerpo></div>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--primary mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Entendido</span></button>
    </footer>
  </dialog>

  <script>
  // Corre al terminar de cargar: assets/ds.js (MCInstalar) va al final de la página
  document.addEventListener('DOMContentLoaded', () => {
    const caja = document.querySelector('[data-instalar]');
    const dlg = document.querySelector('[data-instalar-pasos]');
    if (!caja || !dlg) return;
    const I = window.MCInstalar || { disponible: false, instalada: false, plataforma: 'otro', navegador: 'otro', unClic: false, instalar: async () => null };
    const actual = { ios: 'apple', mac: 'apple', android: 'android', windows: 'windows' }[I.plataforma] || '';
    const nota = caja.querySelector('[data-instalar-nota]');
    const sinMovimiento = () => !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
    const esperar = (ms) => new Promise((r) => setTimeout(r, ms));
    const esc = (s) => String(s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
    const datos = (card) => ({ id: card.dataset.instalarApp, nombre: card.dataset.nombre, corto: card.dataset.corto,
      dir: new URL(card.dataset.dir, location.href).href, unClic: card.dataset.unClic || '' });
    const donde = { windows: 'La encuentras en el menú Inicio y en el escritorio.', mac: 'La encuentras en el Launchpad y puedes dejarla en el Dock.',
      android: 'La encuentras en tu pantalla de inicio.' }[I.plataforma] || '';
    const pintarNota = (tono, texto) => { nota.dataset.tono = tono; nota.textContent = texto; nota.hidden = false; };
    const listoTxt = (nombre) => 'Listo: ' + nombre + ' quedó instalada. ' + (donde ? donde + ' ' : '') + 'Ábrela y acepta los avisos cuando te lo pregunte.';

    // ¿Este botón instala directo? El panel, con el pedido del navegador; el correo, desde su propia página
    const directo = (app, so) => so === actual && (app.id === 'panel' ? I.disponible : (!!app.unClic && I.unClic && !I.instalada));

    /** «Recomendado», «Instalar con un clic» y «Ya la estás usando instalada», según este navegador. */
    const pintar = () => {
      caja.querySelectorAll('[data-instalar-app]').forEach((card) => {
        const app = datos(card);
        const enUso = app.id === 'panel' && I.instalada;
        const verOtros = card.dataset.verOtros === '1';
        const ya = card.querySelector('[data-instalar-ya]');
        if (ya) ya.hidden = !enUso;
        const otro = card.querySelector('[data-instalar-otro]');
        if (otro) otro.hidden = verOtros;
        card.querySelector('[data-instalar-botones]').hidden = enUso && !verOtros;
        card.querySelectorAll('[data-instalar-so]').forEach((b) => {
          const so = b.dataset.instalarSo;
          const rec = so === actual && !enUso;
          b.classList.toggle('is-recomendado', rec);
          b.querySelector('[data-reco]').hidden = !rec;
          if (b.classList.contains('is-descargando') || b.classList.contains('is-listo')) return;
          b.querySelector('[data-estado]').textContent = rec ? (directo(app, so) ? 'Instalar con un clic' : 'Ver cómo instalarla') : b.dataset.detalle;
        });
      });
    };

    /* ---------- Los pasos para instalar a mano, por sistema ---------- */
    const ICO_COMPARTIR = '<svg class="ins-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<path d="M12 14.5V3.5M8 7.5l4-4 4 4"/><path d="M8.5 10H7a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-7a2 2 0 0 0-2-2h-1.5"/></svg>';
    const ICO_INSTALAR = '<svg class="ins-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
      + '<rect x="3" y="4" width="18" height="12.5" rx="2"/><path d="M8.5 20.5h7M12 7.5v5M9.5 10l2.5 2.5 2.5-2.5"/></svg>';
    const tecla = (t) => '<kbd class="ins-tecla">' + t + '</kbd>';
    const avisos = 'acepta los avisos cuando te lo pregunte.';
    const grupo = (titulo, con, pasos, pie, este) => '<section class="ins-pasos__grupo">'
      + '<h3>' + titulo + ' <small>' + con + '</small>' + (este ? ' <span class="ins-pasos__este">Este dispositivo</span>' : '') + '</h3>'
      + '<ol class="ins-pasos__lista">' + pasos.map((p) => '<li>' + p + '</li>').join('') + '</ol>'
      + (pie ? '<p class="ins-pasos__nota">' + pie + '</p>' : '') + '</section>';
    const PASOS = {
      apple: (n, c) => {
        const ios = grupo('iPhone y iPad', 'con Safari', [
          'Abre la dirección de arriba en <b>Safari</b>.',
          'Toca <b>Compartir</b> ' + ICO_COMPARTIR + ' (abajo en el iPhone, arriba en el iPad; si no lo ves, toca antes ' + tecla('···') + ').',
          'Elige <b>«Agregar a inicio»</b> (desliza hacia abajo si no aparece) y toca <b>Agregar</b>.',
          'Abre <b>' + c + '</b> desde ese ícono de tu pantalla de inicio y ' + avisos,
        ], 'En iPhone y iPad los avisos solo llegan si abres la app desde ese ícono.', I.plataforma === 'ios');
        const mac = grupo('Mac', 'con Safari (macOS 14 o más)', [
          'Abre la dirección de arriba en <b>Safari</b>.',
          'En la barra de menús elige <b>Archivo → «Agregar al Dock»</b> y haz clic en <b>Agregar</b>.',
          'Ábrela desde el Dock y ' + avisos,
        ], 'Con Chrome o Edge: haz clic en el ícono de instalar ' + ICO_INSTALAR + ' de la barra de direcciones, o en ' + tecla('⋮') + ' → <b>«Instalar ' + n + '»</b>.', I.plataforma === 'mac');
        return I.plataforma === 'mac' ? mac + ios : ios + mac;
      },
      android: () => grupo('Android', 'con Chrome', [
        'Abre la dirección de arriba en <b>Chrome</b>.',
        'Toca ' + tecla('⋮') + ' (arriba a la derecha) → <b>«Instalar app»</b>. Si no aparece, busca <b>«Agregar a la pantalla principal»</b>.',
        'Toca <b>Instalar</b>.',
        'Ábrela desde el ícono de tu pantalla de inicio y ' + avisos,
      ], 'En Samsung Internet: menú ' + tecla('☰') + ' → «Agregar página a» → «Pantalla de inicio».', I.plataforma === 'android'),
      windows: (n) => grupo('Windows', 'con Chrome o Edge', [
        'Abre la dirección de arriba en <b>Chrome</b> o <b>Edge</b>.',
        'Haz clic en el ícono de instalar ' + ICO_INSTALAR + ' a la derecha de la barra de direcciones, o en ' + tecla('⋮') + ' → <b>«Instalar ' + n + '»</b>.',
        'Confirma con <b>Instalar</b>: queda en el menú Inicio y puedes anclarla a la barra de tareas.',
        'Ábrela y ' + avisos,
      ], 'Si no ves «Instalar»: en Chrome puede estar en ⋮ → «Transmitir, guardar y compartir»; en Edge, en ··· → Aplicaciones. Firefox no instala apps: usa Chrome o Edge.', I.plataforma === 'windows'),
    };
    const DONDE = { apple: 'iPhone, iPad o Mac', android: 'Android', windows: 'Windows' };

    /** Un aviso arriba de los pasos cuando en este dispositivo hay algo distinto. */
    const avisoAqui = (app, so) => {
      if (so !== actual) return '';
      const nav = (I.plataforma === 'ios' || I.plataforma === 'mac') ? 'Safari' : 'Chrome o Edge';
      if (I.instalada) {
        return app.id === 'panel' ? 'Ya la estás usando instalada en este dispositivo.'
          : 'Estás dentro de la app InnoTech Hub: copia la dirección y ábrela en ' + nav + ' para instalar ' + app.nombre + '.';
      }
      if (I.plataforma === 'ios' && I.navegador !== 'safari') return 'Estás en otro navegador: copia la dirección y ábrela en Safari.';
      if (I.navegador === 'firefox' && I.plataforma !== 'android') return 'Firefox no instala apps en la computadora: copia la dirección y ábrela en ' + (I.plataforma === 'mac' ? 'Safari, Chrome o Edge' : 'Chrome o Edge') + '.';
      if (app.id === 'panel' && I.unClic) return '¿No te aparece «Instalar»? Puede que ya la tengas instalada: búscala entre tus apps.';
      return '';
    };

    const abrirPasos = (card, so) => {
      const app = datos(card);
      const logo = dlg.querySelector('[data-pasos-logo]');
      const suLogo = card.querySelector('[data-instalar-so="' + so + '"] .ins-logo');
      logo.innerHTML = suLogo ? suLogo.outerHTML : '';
      logo.dataset.so = so;
      dlg.querySelector('[data-pasos-titulo]').textContent = 'Instalar ' + app.nombre + ' en ' + DONDE[so];
      dlg.querySelector('[data-pasos-dir]').textContent = app.dir;
      dlg.querySelector('[data-pasos-cuerpo]').innerHTML = PASOS[so](esc(app.nombre), esc(app.corto));
      const fuera = dlg.querySelector('[data-pasos-fuera]');
      fuera.textContent = avisoAqui(app, so);
      fuera.hidden = fuera.textContent === '';
      if (dlg.open) return;
      if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
    };

    const copiar = dlg.querySelector('[data-pasos-copiar]');
    copiar.addEventListener('click', async () => {
      const dir = dlg.querySelector('[data-pasos-dir]');
      let ok = false;
      try { await navigator.clipboard.writeText(dir.textContent); ok = true; } catch (e) {
        try {
          const r = document.createRange();
          r.selectNodeContents(dir);
          const s = getSelection();
          s.removeAllRanges();
          s.addRange(r);
          ok = document.execCommand('copy');
        } catch (e2) { ok = false; }
      }
      const et = copiar.querySelector('.mc-btn__label');
      et.textContent = ok ? 'Copiada' : 'Cópiala a mano';
      setTimeout(() => { et.textContent = 'Copiar'; }, 2200);
    });
    dlg.addEventListener('click', (e) => { if (e.target === dlg) dlg.close(); });

    /** Instala el panel con el cuadro del navegador, con la flecha que baja mientras tanto. */
    const instalarAqui = async (b, app) => {
      const estado = b.querySelector('[data-estado]');
      const inicio = Date.now();
      const respuesta = I.instalar();        // el cuadro del navegador sale ya, dentro del clic
      b.classList.remove('is-listo');
      b.classList.add('is-descargando');
      estado.textContent = 'Instalando…';
      const r = await respuesta;
      if (r === 'accepted' && !sinMovimiento()) await esperar(Math.max(0, 1200 - (Date.now() - inicio)));
      b.classList.remove('is-descargando');
      if (r === 'accepted') {
        b.classList.add('is-listo');
        estado.textContent = '¡Listo! Ya está instalada';
        pintarNota('ok', listoTxt(app.nombre));
        setTimeout(() => { b.classList.remove('is-listo'); pintar(); }, 6000);
      } else if (r === 'dismissed') {
        pintar();
        pintarNota('aviso', 'No se instaló. Cuando quieras, vuelve a tocar el botón y te mostramos cómo hacerlo desde el menú del navegador.');
      } else {
        pintar();
        abrirPasos(b.closest('[data-instalar-app]'), b.dataset.instalarSo);
      }
    };

    caja.addEventListener('click', (ev) => {
      const otro = ev.target.closest('[data-instalar-otro]');
      if (otro) {
        otro.closest('[data-instalar-app]').dataset.verOtros = '1';
        pintar();
        return;
      }
      const b = ev.target.closest('[data-instalar-so]');
      if (!b || b.classList.contains('is-descargando')) return;
      const card = b.closest('[data-instalar-app]');
      const app = datos(card);
      const so = b.dataset.instalarSo;
      if (!directo(app, so)) { abrirPasos(card, so); return; }
      if (app.id !== 'panel') {
        // El correo tiene su propio manifest: se instala desde su página
        b.classList.add('is-descargando');
        b.querySelector('[data-estado]').textContent = 'Abriendo el correo…';
        location.href = app.unClic;
        return;
      }
      instalarAqui(b, app);
    });

    document.addEventListener('mcinstalar:cambio', (e) => {
      pintar();
      // Instalada desde el ícono de la barra de direcciones (sin pasar por el botón)
      if (e.detail && e.detail.motivo === 'instalada' && nota.dataset.tono !== 'ok') {
        const panel = caja.querySelector('[data-instalar-app="panel"]');
        pintarNota('ok', listoTxt(panel ? panel.dataset.nombre : 'La app'));
      }
    });
    // Al volver con «Atrás» desde el correo, el botón no queda girando
    addEventListener('pageshow', (e) => {
      if (!e.persisted) return;
      caja.querySelectorAll('.ins-boton.is-descargando').forEach((x) => x.classList.remove('is-descargando'));
      pintar();
    });
    pintar();
  });
  </script>
    <?php
}
