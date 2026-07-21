<?php
/**
 * Ajustes: parametrizacion del panel, organizada en tabs.
 *  - Identidad: textos y colores de marca
 *  - Catalogos: estados de tarea, prioridades, estados de proyecto y equipos
 *  - Iconos: galeria visual (clic para elegir, sin escribir clases)
 *  - Roles: sugerencias de rol
 *  - Correo: notificaciones (SMTP o API de Gmail)
 * Todo se guarda junto en data/config.json con un solo boton.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$cfg = Config::all();
$co  = $cfg['correo'];

// Galeria de iconos disponibles (los ya elegidos se agregan aunque no esten aqui)
$galeriaIconos = [
    'fa-rocket', 'fa-store', 'fa-graduation-cap', 'fa-cart-shopping', 'fa-mobile-screen',
    'fa-globe', 'fa-server', 'fa-robot', 'fa-truck-fast', 'fa-heart-pulse',
    'fa-gamepad', 'fa-chart-line', 'fa-database', 'fa-cloud', 'fa-code',
    'fa-terminal', 'fa-bug', 'fa-shield-halved', 'fa-lock', 'fa-key',
    'fa-credit-card', 'fa-money-bill-wave', 'fa-wallet', 'fa-building', 'fa-city',
    'fa-house', 'fa-school', 'fa-book', 'fa-newspaper', 'fa-envelope',
    'fa-comments', 'fa-phone', 'fa-camera', 'fa-image', 'fa-film',
    'fa-music', 'fa-palette', 'fa-brush', 'fa-wand-magic-sparkles', 'fa-bolt',
    'fa-fire', 'fa-leaf', 'fa-tree', 'fa-paw', 'fa-car',
    'fa-plane', 'fa-ship', 'fa-bicycle', 'fa-utensils', 'fa-mug-hot',
    'fa-pizza-slice', 'fa-stethoscope', 'fa-pills', 'fa-dumbbell', 'fa-futbol',
    'fa-trophy', 'fa-gift', 'fa-bell', 'fa-calendar', 'fa-map-location-dot',
    'fa-users', 'fa-user-tie', 'fa-briefcase', 'fa-boxes-stacked', 'fa-industry',
    'fa-microchip', 'fa-network-wired', 'fa-satellite-dish', 'fa-flask', 'fa-dna',
    'fa-atom', 'fa-brain', 'fa-puzzle-piece', 'fa-cubes', 'fa-gears',
];
$galeriaIconos = array_values(array_unique(array_merge($cfg['iconos'], $galeriaIconos)));

UI::inicio('Ajustes', 'ajustes');
UI::cabecera(
    'Ajustes del <span class="text-secondary">panel</span>',
    'Todo es parametrizable. Los cambios se guardan con el botón "Guardar ajustes".',
    '<form method="post" action="actions.php" class="inline-form"
           data-confirmar="Se perderán todos los ajustes personalizados y el panel volverá a sus valores por defecto."
           data-confirmar-titulo="¿Restaurar los defaults?" data-confirmar-ok="Sí, restaurar">
       <input type="hidden" name="accion" value="config_reset">
       <button class="btn-outline btn-meca btn-verde"><i class="fa-solid fa-rotate-left"></i> Restaurar defaults</button>
     </form>'
);
?>

<!-- Tabs -->
<div class="tabs-meca" data-clave="ajustes">
  <button type="button" class="tab-btn active" data-tab="identidad"><i class="fa-solid fa-id-badge"></i> Identidad</button>
  <button type="button" class="tab-btn" data-tab="catalogos"><i class="fa-solid fa-layer-group"></i> Catálogos</button>
  <button type="button" class="tab-btn" data-tab="iconos"><i class="fa-solid fa-icons"></i> Íconos</button>
  <button type="button" class="tab-btn" data-tab="roles"><i class="fa-solid fa-user-tag"></i> Roles</button>
  <button type="button" class="tab-btn" data-tab="correo"><i class="fa-solid fa-envelope"></i> Correo</button>
</div>

<form method="post" action="actions.php" class="ajustes-form">
  <input type="hidden" name="accion" value="config_guardar">

  <!-- ================= TAB: Identidad ================= -->
  <div class="tab-panel" data-panel="identidad">
    <div class="ajustes-grid">
      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-id-badge text-secondary"></i> Identidad del panel</h2>
        <div class="campo-doble">
          <label class="campo">
            <span>Título</span>
            <input class="input-meca" name="titulo" maxlength="30" value="<?= e($cfg['titulo']) ?>">
          </label>
          <label class="campo">
            <span>Subtítulo</span>
            <input class="input-meca" name="subtitulo" maxlength="30" value="<?= e($cfg['subtitulo']) ?>">
          </label>
        </div>
        <div class="campo-doble">
          <label class="campo">
            <span>Color principal (botones, enlaces)</span>
            <input class="input-meca input-color-linea" type="color" name="color_secundario" value="<?= e($cfg['color_secundario']) ?>">
          </label>
          <label class="campo">
            <span>Color de acento</span>
            <input class="input-meca input-color-linea" type="color" name="color_acento" value="<?= e($cfg['color_acento']) ?>">
          </label>
        </div>
      </section>
    </div>
  </div>

  <!-- ================= TAB: Catalogos ================= -->
  <div class="tab-panel" data-panel="catalogos" hidden>
    <div class="ajustes-grid">

      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-list-check text-secondary"></i> Estados de tarea</h2>
        <p class="ajuste-ayuda">
          Agrega los estados que necesites (ej. "Bloqueada", "En pruebas").
          La bandera <i class="fa-solid fa-flag-checkered"></i> marca los que cuentan como <b>completada</b> para el % de avance.
        </p>
        <div class="ajuste-lista" id="lista-et">
          <?php $i = 0; foreach ($cfg['estados_tarea'] as $k => $v): ?>
          <div class="ajuste-fila fila-cat">
            <input type="hidden" name="et[<?= $i ?>][key]" value="<?= e($k) ?>">
            <input class="input-meca input-icono" name="et[<?= $i ?>][icono]" value="<?= e($v['icono'] ?? 'fa-circle-dot') ?>" title="Ícono (clase Font Awesome)">
            <input class="input-meca" name="et[<?= $i ?>][label]" maxlength="24" value="<?= e($v['label']) ?>" placeholder="Nombre del estado">
            <input type="color" name="et[<?= $i ?>][color]" value="<?= e($v['color']) ?>" title="Color">
            <label class="chk-final" title="Cuenta como completada">
              <input type="checkbox" name="et[<?= $i ?>][final]" <?= !empty($v['final']) ? 'checked' : '' ?>>
              <i class="fa-solid fa-flag-checkered"></i>
            </label>
            <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Quitar"><i class="fa-solid fa-trash"></i></button>
          </div>
          <?php $i++; endforeach; ?>
        </div>
        <button type="button" class="btn-outline btn-meca btn-sm btn-agregar-fila" data-lista="lista-et" data-plantilla="tpl-et">
          <i class="fa-solid fa-plus"></i> Agregar estado
        </button>
      </section>

      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-angles-up text-secondary"></i> Prioridades</h2>
        <p class="ajuste-ayuda">El orden aquí define el orden en la tabla (la última es la más urgente).</p>
        <div class="ajuste-lista" id="lista-pr">
          <?php $i = 0; foreach ($cfg['prioridades'] as $k => $v): ?>
          <div class="ajuste-fila fila-cat fila-cat-4">
            <input type="hidden" name="pr[<?= $i ?>][key]" value="<?= e($k) ?>">
            <input class="input-meca input-icono" name="pr[<?= $i ?>][icono]" value="<?= e($v['icono'] ?? 'fa-equals') ?>" title="Ícono (clase Font Awesome)">
            <input class="input-meca" name="pr[<?= $i ?>][label]" maxlength="24" value="<?= e($v['label']) ?>" placeholder="Nombre de la prioridad">
            <input type="color" name="pr[<?= $i ?>][color]" value="<?= e($v['color']) ?>" title="Color">
            <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Quitar"><i class="fa-solid fa-trash"></i></button>
          </div>
          <?php $i++; endforeach; ?>
        </div>
        <button type="button" class="btn-outline btn-meca btn-sm btn-agregar-fila" data-lista="lista-pr" data-plantilla="tpl-pr">
          <i class="fa-solid fa-plus"></i> Agregar prioridad
        </button>
      </section>

      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-folder-open text-secondary"></i> Estados de proyecto</h2>
        <p class="ajuste-ayuda">Ej. "En propuesta", "Mantenimiento".</p>
        <div class="ajuste-lista" id="lista-ep">
          <?php $i = 0; foreach (Catalogo::estadosProyecto() as $k => [$label, $icono]): ?>
          <div class="ajuste-fila fila-cat fila-cat-3">
            <input type="hidden" name="ep[<?= $i ?>][key]" value="<?= e($k) ?>">
            <input class="input-meca input-icono" name="ep[<?= $i ?>][icono]" value="<?= e($icono) ?>" title="Ícono (clase Font Awesome)">
            <input class="input-meca" name="ep[<?= $i ?>][label]" maxlength="24" value="<?= e($label) ?>" placeholder="Nombre del estado">
            <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Quitar"><i class="fa-solid fa-trash"></i></button>
          </div>
          <?php $i++; endforeach; ?>
        </div>
        <button type="button" class="btn-outline btn-meca btn-sm btn-agregar-fila" data-lista="lista-ep" data-plantilla="tpl-ep">
          <i class="fa-solid fa-plus"></i> Agregar estado
        </button>
      </section>

      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-people-group text-secondary"></i> Equipos</h2>
        <p class="ajuste-ayuda">Cada equipo tiene su página propia en el menú (ej. "Programadores", "Analistas", "Diseño").</p>
        <div class="ajuste-lista" id="lista-eqs">
          <?php $i = 0; foreach (Catalogo::equipos() as $k => [$label, $icono]): ?>
          <div class="ajuste-fila fila-cat fila-cat-3">
            <input type="hidden" name="eqs[<?= $i ?>][key]" value="<?= e($k) ?>">
            <input class="input-meca input-icono" name="eqs[<?= $i ?>][icono]" value="<?= e($icono) ?>" title="Ícono (clase Font Awesome)">
            <input class="input-meca" name="eqs[<?= $i ?>][label]" maxlength="24" value="<?= e($label) ?>" placeholder="Nombre del equipo">
            <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Quitar"><i class="fa-solid fa-trash"></i></button>
          </div>
          <?php $i++; endforeach; ?>
        </div>
        <button type="button" class="btn-outline btn-meca btn-sm btn-agregar-fila" data-lista="lista-eqs" data-plantilla="tpl-eqs">
          <i class="fa-solid fa-plus"></i> Agregar equipo
        </button>
      </section>

    </div>
    <p class="ajuste-ayuda ajuste-nota">
      <i class="fa-solid fa-circle-info"></i>
      Si quitas un estado, prioridad o equipo en uso, lo afectado pasa automáticamente a la primera opción de su catálogo.
    </p>
  </div>

  <!-- ================= TAB: Iconos ================= -->
  <div class="tab-panel" data-panel="iconos" hidden>
    <div class="ajustes-grid">
      <section class="card-base ajuste-card ajuste-card-ancha">
        <h2 class="font-display"><i class="fa-solid fa-icons text-secondary"></i> Íconos de proyecto</h2>
        <p class="ajuste-ayuda">
          Haz clic para elegir los íconos disponibles al crear un proyecto — se agregan solos, sin escribir nada.
          Los seleccionados se marcan hundidos y en color.
        </p>
        <input type="hidden" name="iconos" id="iconos-valor" value="<?= e(implode("\n", $cfg['iconos'])) ?>">
        <div class="icon-galeria">
          <?php foreach ($galeriaIconos as $ic): ?>
          <button type="button" class="ig-btn <?= in_array($ic, $cfg['iconos'], true) ? 'sel' : '' ?>"
                  data-icono="<?= e($ic) ?>" title="<?= e($ic) ?>">
            <i class="fa-solid <?= e($ic) ?>"></i>
          </button>
          <?php endforeach; ?>
        </div>
        <p class="ajuste-ayuda"><span id="iconos-conteo"><?= count($cfg['iconos']) ?></span> seleccionados.
          ¿Falta alguno? Busca su clase en <a href="https://fontawesome.com/search?ic=free" target="_blank" rel="noopener">Font Awesome</a>
          y agrégala aquí:
        </p>
        <div class="icon-extra">
          <input class="input-meca input-icono" id="icono-extra" placeholder="fa-nombre-del-icono">
          <button type="button" class="btn-outline btn-meca btn-sm" id="icono-extra-btn"><i class="fa-solid fa-plus"></i> Agregar</button>
        </div>
      </section>
    </div>
  </div>

  <!-- ================= TAB: Roles ================= -->
  <div class="tab-panel" data-panel="roles" hidden>
    <div class="ajustes-grid">
      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-user-tag text-secondary"></i> Roles sugeridos</h2>
        <p class="ajuste-ayuda">Se sugieren al escribir el rol de un colaborador. Agrega o quita los que necesiten.</p>
        <div class="ajuste-lista" id="lista-rl">
          <?php foreach ($cfg['roles'] as $rol): ?>
          <div class="ajuste-fila fila-cat fila-cat-2">
            <input class="input-meca" name="rl[]" maxlength="40" value="<?= e($rol) ?>" placeholder="Nombre del rol">
            <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Quitar"><i class="fa-solid fa-trash"></i></button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn-outline btn-meca btn-sm btn-agregar-fila" data-lista="lista-rl" data-plantilla="tpl-rl">
          <i class="fa-solid fa-plus"></i> Agregar rol
        </button>
      </section>
    </div>
  </div>

  <!-- ================= TAB: Correo ================= -->
  <div class="tab-panel" data-panel="correo" hidden>
    <div class="ajustes-grid">
      <section class="card-base ajuste-card ajuste-card-ancha">
        <h2 class="font-display"><i class="fa-solid fa-envelope text-secondary"></i> Notificaciones por correo</h2>
        <p class="ajuste-ayuda">
          Cuando se asigne una tarea a alguien con correo registrado, le llegará un aviso automático.
        </p>

        <label class="chk-linea">
          <input type="checkbox" name="correo[activo]" <?= !empty($co['activo']) ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Activar envío de correos
        </label>

        <div class="campo-doble">
          <label class="campo"><span>Método de envío</span>
            <?= UI::select('correo[modo]', ['smtp' => 'SMTP (contraseña de aplicación)', 'gmail_api' => 'API de Gmail (OAuth de Google Cloud)'], $co['modo'] ?? 'smtp') ?>
          </label>
          <label class="campo"><span>Nombre del remitente</span>
            <input class="input-meca" name="correo[remitente]" value="<?= e($co['remitente']) ?>">
          </label>
        </div>
        <div class="campo-doble">
          <label class="campo"><span>Correo remitente (cuenta que envía)</span>
            <input class="input-meca" type="email" name="correo[usuario]" value="<?= e($co['usuario']) ?>" placeholder="mecapacito.ecuador@gmail.com">
          </label>
          <label class="campo"><span>URL del panel (botón "Ver tablero" del correo)</span>
            <input class="input-meca" type="url" name="correo[url_panel]" value="<?= e($co['url_panel']) ?>" placeholder="https://mecapacito.com/admin">
          </label>
        </div>

        <p class="ajuste-ayuda"><b>Solo para SMTP</b> — con Gmail usa una
          <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">contraseña de aplicación</a>:</p>
        <div class="campo-doble">
          <label class="campo"><span>Servidor SMTP</span>
            <input class="input-meca" name="correo[host]" value="<?= e($co['host']) ?>" placeholder="smtp.gmail.com">
          </label>
          <label class="campo"><span>Puerto (587 o 465)</span>
            <input class="input-meca" type="number" name="correo[puerto]" value="<?= (int)$co['puerto'] ?>">
          </label>
        </div>
        <label class="campo"><span>Contraseña de aplicación</span>
          <input class="input-meca" type="password" name="correo[clave]" value="<?= e($co['clave']) ?>" placeholder="xxxx xxxx xxxx xxxx">
        </label>

        <p class="ajuste-ayuda"><b>Solo para API de Gmail</b> (proyecto de Google Cloud con scope <code>gmail.send</code>):</p>
        <label class="campo"><span>Client ID</span>
          <input class="input-meca" name="correo[client_id]" value="<?= e($co['client_id'] ?? '') ?>" placeholder="xxxx.apps.googleusercontent.com">
        </label>
        <div class="campo-doble">
          <label class="campo"><span>Client Secret</span>
            <input class="input-meca" type="password" name="correo[client_secret]" value="<?= e($co['client_secret'] ?? '') ?>">
          </label>
          <label class="campo"><span>Refresh Token</span>
            <input class="input-meca" type="password" name="correo[refresh_token]" value="<?= e($co['refresh_token'] ?? '') ?>">
          </label>
        </div>

        <div class="correo-prueba">
          <input class="input-meca" type="email" name="para" form="frm-correo-prueba" placeholder="tucorreo@gmail.com" required>
          <button class="btn-outline btn-meca btn-sm" form="frm-correo-prueba">
            <i class="fa-solid fa-paper-plane"></i> Probar envío
          </button>
        </div>
        <small class="campo-ayuda">Guarda los ajustes primero y luego usa "Probar envío".</small>
      </section>
    </div>
  </div>

  <footer class="ajustes-guardar">
    <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-floppy-disk"></i> Guardar ajustes</button>
  </footer>
</form>

<!-- Form auxiliar para el correo de prueba (asociado via atributo form) -->
<form id="frm-correo-prueba" method="post" action="actions.php">
  <input type="hidden" name="accion" value="correo_prueba">
</form>

<!-- Plantillas para filas nuevas -->
<template id="tpl-et">
  <div class="ajuste-fila fila-cat">
    <input type="hidden" name="et[__i__][key]" value="">
    <input class="input-meca input-icono" name="et[__i__][icono]" value="fa-circle-dot" title="Ícono (clase Font Awesome)">
    <input class="input-meca" name="et[__i__][label]" maxlength="24" value="" placeholder="Nuevo estado">
    <input type="color" name="et[__i__][color]" value="#2B76F7" title="Color">
    <label class="chk-final" title="Cuenta como completada">
      <input type="checkbox" name="et[__i__][final]">
      <i class="fa-solid fa-flag-checkered"></i>
    </label>
    <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Quitar"><i class="fa-solid fa-trash"></i></button>
  </div>
</template>
<template id="tpl-pr">
  <div class="ajuste-fila fila-cat fila-cat-4">
    <input type="hidden" name="pr[__i__][key]" value="">
    <input class="input-meca input-icono" name="pr[__i__][icono]" value="fa-equals" title="Ícono (clase Font Awesome)">
    <input class="input-meca" name="pr[__i__][label]" maxlength="24" value="" placeholder="Nueva prioridad">
    <input type="color" name="pr[__i__][color]" value="#F7931E" title="Color">
    <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Quitar"><i class="fa-solid fa-trash"></i></button>
  </div>
</template>
<template id="tpl-eqs">
  <div class="ajuste-fila fila-cat fila-cat-3">
    <input type="hidden" name="eqs[__i__][key]" value="">
    <input class="input-meca input-icono" name="eqs[__i__][icono]" value="fa-users" title="Ícono (clase Font Awesome)">
    <input class="input-meca" name="eqs[__i__][label]" maxlength="24" value="" placeholder="Nuevo equipo">
    <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Quitar"><i class="fa-solid fa-trash"></i></button>
  </div>
</template>
<template id="tpl-rl">
  <div class="ajuste-fila fila-cat fila-cat-2">
    <input class="input-meca" name="rl[]" maxlength="40" value="" placeholder="Nuevo rol">
    <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Quitar"><i class="fa-solid fa-trash"></i></button>
  </div>
</template>
<template id="tpl-ep">
  <div class="ajuste-fila fila-cat fila-cat-3">
    <input type="hidden" name="ep[__i__][key]" value="">
    <input class="input-meca input-icono" name="ep[__i__][icono]" value="fa-flag" title="Ícono (clase Font Awesome)">
    <input class="input-meca" name="ep[__i__][label]" maxlength="24" value="" placeholder="Nuevo estado">
    <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Quitar"><i class="fa-solid fa-trash"></i></button>
  </div>
</template>

<?php UI::fin(); ?>
