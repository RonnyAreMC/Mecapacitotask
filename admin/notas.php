<?php
/**
 * Mis notas: las notas de cada quien, al estilo de las Notas del iPhone o de
 * Google Keep. A la izquierda la lista (buscador, fijadas arriba y luego de la
 * más reciente a la más vieja); a la derecha la nota abierta, con su título y
 * su texto en el editor del panel. Se guarda sola mientras se escribe.
 *
 * Cada nota puede llevar un color suave, un recordatorio (cron_correo.php avisa
 * en la campana, en los dispositivos y por correo), un enlace para compartirla
 * sin iniciar sesión (nota_ver.php) y, si su dueño quiere, una pizarra
 * (pizarra.php?nota=ID), que se ve en miniatura debajo del texto.
 *
 * Son SIEMPRE las de quien tiene la sesión: ni el administrador ni «ver como»
 * ven las de otro. Datos en lib/Notas.php, API en notas_api.php; lo que se
 * mueve, en assets/notas.js y assets/notas.css.
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/Notas.php';
if (is_file(__DIR__ . '/lib/Pizarra.php')) require_once __DIR__ . '/lib/Pizarra.php';

$yo  = Auth::usuario();
$mid = (int)($yo['id'] ?? 0);

// Lo que el JS necesita para arrancar sin pedir nada (después habla con notas_api.php)
$datos = [
    'notas'   => array_map([Notas::class, 'paraJs'], Notas::de($mid)),
    'colores' => Notas::COLORES,
    'abrir'   => (int)($_GET['id'] ?? 0),
    'pizarra' => class_exists('Pizarra') && is_file(__DIR__ . '/pizarra.php'),
    'ahora'   => date('Y-m-d H:i:s'),   // la hora del panel: las fechas de la lista salen con ella
];
// Íconos que el JS pinta en las filas y en los cuadros (SVG del set, una sola vez)
$iconosJs = ['ds-candado', 'pz-pizarra', 'ds-notificaciones', 'ds-enlace', 'ds-check', 'pz-sin-red', 'ds-alerta'];
$verComo = verComo();

UI::inicio('Mis notas', 'mis-notas');
?>
<link rel="stylesheet" href="<?= asset('assets/notas.css') ?>">
<div class="nt" id="nt">

  <!-- La lista: buscar, nueva y las notas (las pinta assets/notas.js) -->
  <aside class="nt-lista" aria-label="Tus notas">
    <header class="nt-lista__cab">
      <div class="nt-lista__t">
        <h1 class="font-display">Mis notas</h1>
        <small id="nt-cuenta"><?= UI::icono('ds-candado') ?> Solo tú las ves</small>
      </div>
      <button type="button" class="nt-ib nt-ib--nueva" id="nt-nueva" data-ds-tip="Nueva nota" aria-label="Nueva nota" aria-keyshortcuts="Control+N Meta+N Alt+N"><?= UI::icono('nt-nueva') ?></button>
    </header>
    <?php if ($verComo): ?>
    <p class="nt-aviso-vc"><?= UI::icono('ds-info') ?> «Ver como» no cambia esta pantalla: tus notas son solo tuyas.</p>
    <?php endif; ?>
    <label class="nt-buscar">
      <?= UI::icono('ds-buscar') ?>
      <input type="search" id="nt-buscar" placeholder="Buscar" autocomplete="off" spellcheck="false" aria-label="Buscar en tus notas" aria-keyshortcuts="Control+F Meta+F">
      <span class="nt-buscar__atajo" aria-hidden="true"><kbd data-mod>Ctrl</kbd><kbd>F</kbd></span>
    </label>
    <div class="nt-filas" id="nt-filas" role="group" aria-label="Notas"></div>
    <p class="nt-lista__nada" id="nt-nada" hidden></p>
  </aside>

  <!-- La nota abierta -->
  <section class="nt-hoja" id="nt-hoja" aria-label="Nota abierta" data-color="" hidden>
    <header class="nt-barra" role="toolbar" aria-label="Opciones de la nota">
      <button type="button" class="nt-ib nt-solo-cel" data-accion="volver" data-ds-tip="Volver a la lista" aria-label="Volver a la lista"><?= UI::icono('ds-chevron-izq') ?></button>
      <span class="nt-estado" id="nt-estado" data-estado="ok" role="status" aria-live="polite">
        <span class="nt-estado__ico" aria-hidden="true"><?= UI::icono('ds-check') ?></span><span class="nt-estado__txt">Guardado</span>
      </span>
      <span class="nt-barra__hueco"></span>
      <button type="button" class="nt-ib" data-accion="fijar" data-ds-tip="Fijar arriba" aria-label="Fijar arriba" aria-pressed="false"><?= UI::icono('nt-fijar') ?></button>
      <button type="button" class="nt-ib" data-accion="color" data-ds-tip="Color" aria-label="Color de la nota" aria-haspopup="dialog" aria-expanded="false" aria-controls="nt-pop-color"><?= UI::icono('nt-color') ?></button>
      <button type="button" class="nt-ib" data-accion="aviso" data-ds-tip="Recordatorio" aria-label="Recordatorio" aria-haspopup="dialog" aria-expanded="false" aria-controls="nt-pop-aviso"><?= UI::icono('ds-notificaciones') ?></button>
      <button type="button" class="nt-ib" data-accion="compartir" data-ds-tip="Compartir un enlace" aria-label="Compartir un enlace" aria-haspopup="dialog" aria-expanded="false" aria-controls="nt-pop-compartir"><?= UI::icono('nt-compartir') ?></button>
      <?php if ($datos['pizarra']): ?>
      <button type="button" class="nt-ib" data-accion="pizarra" data-ds-tip="Agregar pizarra" aria-label="Agregar pizarra"><?= UI::icono('pz-pizarra') ?></button>
      <?php endif; ?>
      <button type="button" class="nt-ib nt-ib--peligro" data-accion="eliminar" data-ds-tip="Eliminar la nota" aria-label="Eliminar la nota"><?= UI::icono('ds-eliminar') ?></button>
    </header>

    <div class="nt-papel" id="nt-papel">
      <div class="nt-meta" id="nt-meta"></div>
      <input type="text" class="nt-titulo font-display" id="nt-titulo" placeholder="Título" maxlength="<?= Notas::MAX_TITULO ?>" autocomplete="off" aria-label="Título de la nota">
      <?= UI::editorRico(['id' => 'nt-editor', 'name' => 'html', 'clase' => 'rt--nota', 'placeholder' => 'Escribe tu nota…']) ?>

      <?php if ($datos['pizarra']): ?>
      <!-- Si la nota tiene pizarra: su vista previa, solo para mirar; «Abrir» la edita -->
      <section class="nt-pz" id="nt-pz" aria-label="Pizarra de la nota" hidden>
        <div class="nt-pz__cab">
          <span class="nt-pz__t"><?= UI::icono('pz-pizarra') ?> Pizarra</span>
          <a class="nt-pz__abrir" id="nt-pz-abrir" href="#">Abrir</a>
        </div>
        <a class="nt-pz__marco" id="nt-pz-marco" href="#" aria-label="Abrir la pizarra" hidden>
          <iframe id="nt-pz-iframe" title="Vista previa de la pizarra" loading="lazy" tabindex="-1" aria-hidden="true"></iframe>
        </a>
        <p class="nt-pz__vacia" id="nt-pz-vacia" hidden>
          Tu pizarra está vacía · <a href="#" data-pz-abrir>Abrir</a> · <button type="button" data-pz-quitar>Quitar</button>
        </p>
      </section>
      <?php endif; ?>
    </div>

    <!-- Cuadros que flotan (los ubica assets/notas.js junto a su botón) -->
    <div class="nt-pop" id="nt-pop-color" role="dialog" aria-label="Color de la nota" hidden>
      <div class="nt-colores" role="radiogroup" aria-label="Color">
        <?php foreach (Notas::COLORES as $clave => $nombre): ?>
        <button type="button" class="nt-color" role="radio" aria-checked="false" data-color="<?= e($clave) ?>" data-ds-tip="<?= e($nombre) ?>" aria-label="<?= e($nombre) ?>"></button>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="nt-pop nt-pop--ancho" id="nt-pop-aviso" role="dialog" aria-label="Recordatorio" hidden>
      <b class="nt-pop__t"><?= UI::icono('ds-notificaciones') ?> Recordatorio</b>
      <small class="nt-pop__nota" id="nt-aviso-actual"></small>
      <div class="nt-pop__chips">
        <button type="button" class="nt-chip" data-aviso="hora">En 1 h</button>
        <button type="button" class="nt-chip" data-aviso="manana">Mañana 9:00</button>
      </div>
      <label class="nt-pop__campo"><span>Fecha y hora</span>
        <?php /* data-md: el nativo, no el selector del panel (MecaDate abre su cuadro fuera de este y lo cerraría) */ ?>
        <input type="datetime-local" id="nt-aviso-cuando" step="60" data-md="nativo">
      </label>
      <small class="nt-pop__error" id="nt-aviso-error" role="alert" hidden></small>
      <div class="nt-pop__pie">
        <button type="button" class="nt-pop__b nt-pop__b--quitar" data-aviso="quitar" hidden>Quitar</button>
        <button type="button" class="nt-pop__b nt-pop__b--si" data-aviso="guardar">Guardar</button>
      </div>
    </div>

    <div class="nt-pop nt-pop--ancho" id="nt-pop-compartir" role="dialog" aria-label="Compartir un enlace" hidden>
      <b class="nt-pop__t"><?= UI::icono('ds-enlace') ?> Compartir un enlace</b>
      <div data-compartir="no">
        <small class="nt-pop__nota">Quien tenga el enlace podrá leer esta nota<?= $datos['pizarra'] ? ' y mirar su pizarra' : '' ?>, sin iniciar sesión. No podrá cambiar nada.</small>
        <div class="nt-pop__pie">
          <button type="button" class="nt-pop__b nt-pop__b--si" data-compartir-accion="crear">Crear enlace</button>
        </div>
      </div>
      <div data-compartir="si" hidden>
        <label class="nt-enlace">
          <?= UI::icono('ds-enlace') ?>
          <input type="text" id="nt-enlace" readonly aria-label="Enlace de la nota" spellcheck="false">
        </label>
        <small class="nt-pop__nota">Cualquiera con este enlace la lee, sin iniciar sesión. Si lo apagas, deja de servir.</small>
        <div class="nt-pop__pie">
          <button type="button" class="nt-pop__b nt-pop__b--quitar" data-compartir-accion="apagar">Dejar de compartir</button>
          <button type="button" class="nt-pop__b" data-compartir-accion="nativo" hidden>Compartir…</button>
          <button type="button" class="nt-pop__b nt-pop__b--si" data-compartir-accion="copiar"><?= UI::icono('ds-copiar') ?> Copiar</button>
        </div>
      </div>
    </div>
  </section>

  <!-- Sin notas (o sin ninguna abierta): una invitación, no un hueco -->
  <section class="nt-bienvenida" id="nt-bienvenida" hidden>
    <span class="nt-bienvenida__ico" aria-hidden="true"><?= UI::icono('ds-notas') ?></span>
    <b>Tus notas, solo para ti</b>
    <p>Ideas, listas o lo que no quieres olvidar. A cada nota le puedes poner un recordatorio, compartirla con un enlace o agregarle una pizarra.</p>
    <button type="button" class="nt-ib nt-ib--nueva nt-ib--grande" data-nueva data-ds-tip="Nueva nota" aria-label="Nueva nota"><?= UI::icono('nt-nueva') ?></button>
  </section>

  <template id="nt-iconos">
    <?php foreach ($iconosJs as $ic): ?><span data-ico="<?= e($ic) ?>"><?= UI::icono($ic) ?></span><?php endforeach; ?>
  </template>
  <script type="application/json" id="nt-datos"><?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</div>
<script src="<?= asset('assets/notas.js') ?>" defer></script>
<?php UI::fin(); ?>
