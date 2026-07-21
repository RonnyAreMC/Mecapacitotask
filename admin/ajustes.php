<?php
/**
 * Ajustes: parametrizacion del panel.
 * Identidad, colores de marca y catalogos AMPLIABLES: estados de tarea,
 * prioridades y estados de proyecto (agregar/quitar/renombrar/colorear).
 * Se guarda en data/config.json.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$cfg = Config::all();

UI::inicio('Ajustes', 'ajustes');
UI::cabecera(
    'Ajustes del <span class="text-secondary">panel</span>',
    'Todo es parametrizable: textos, colores y catálogos ampliables (estados, prioridades, íconos y roles).',
    '<form method="post" action="actions.php" class="inline-form" onsubmit="return confirm(\'¿Volver todos los ajustes a sus valores por defecto?\')">
       <input type="hidden" name="accion" value="config_reset">
       <button class="btn-outline btn-meca"><i class="fa-solid fa-rotate-left"></i> Restaurar defaults</button>
     </form>'
);
?>

<form method="post" action="actions.php" class="ajustes-grid">
  <input type="hidden" name="accion" value="config_guardar">

  <!-- Identidad -->
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

  <!-- Estados de tarea (ampliable) -->
  <section class="card-base ajuste-card">
    <h2 class="font-display"><i class="fa-solid fa-list-check text-secondary"></i> Estados de tarea</h2>
    <p class="ajuste-ayuda">
      Agrega los estados que necesites (ej. "Bloqueada", "En pruebas"). El ícono es una clase de
      <a href="https://fontawesome.com/search?ic=free" target="_blank" rel="noopener">Font Awesome</a>.
      La bandera <i class="fa-solid fa-flag-checkered"></i> marca los estados que cuentan como <b>completada</b> para el % de avance.
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

  <!-- Prioridades (ampliable) -->
  <section class="card-base ajuste-card">
    <h2 class="font-display"><i class="fa-solid fa-angles-up text-secondary"></i> Prioridades</h2>
    <p class="ajuste-ayuda">Agrega o quita niveles de prioridad. El orden aquí define el orden en la tabla (la última es la más urgente).</p>
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

  <!-- Estados de proyecto (ampliable) -->
  <section class="card-base ajuste-card">
    <h2 class="font-display"><i class="fa-solid fa-folder-open text-secondary"></i> Estados de proyecto</h2>
    <p class="ajuste-ayuda">Los estados que puede tener un proyecto (ej. "En propuesta", "Mantenimiento").</p>
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

  <!-- Equipos (ampliable) -->
  <section class="card-base ajuste-card">
    <h2 class="font-display"><i class="fa-solid fa-people-group text-secondary"></i> Equipos</h2>
    <p class="ajuste-ayuda">Los equipos de trabajo del panel (ej. "Programación", "Analistas", "Diseño"). Cada uno tiene su página propia en el menú.</p>
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

  <!-- Iconos -->
  <section class="card-base ajuste-card">
    <h2 class="font-display"><i class="fa-solid fa-icons text-secondary"></i> Íconos de proyecto</h2>
    <p class="ajuste-ayuda">
      Clases de <a href="https://fontawesome.com/search?ic=free" target="_blank" rel="noopener">Font Awesome</a>,
      una por línea (ej. <code>fa-rocket</code>). Aparecen al crear o editar un proyecto.
    </p>
    <textarea class="input-meca" name="iconos" rows="6"><?= e(implode("\n", $cfg['iconos'])) ?></textarea>
    <div class="ajuste-iconos-preview">
      <?php foreach ($cfg['iconos'] as $ic): ?><i class="fa-solid <?= e($ic) ?>"></i><?php endforeach; ?>
    </div>
  </section>

  <!-- Roles -->
  <section class="card-base ajuste-card">
    <h2 class="font-display"><i class="fa-solid fa-user-tag text-secondary"></i> Roles sugeridos</h2>
    <p class="ajuste-ayuda">Se sugieren al escribir el rol de un colaborador, uno por línea.</p>
    <textarea class="input-meca" name="roles" rows="6"><?= e(implode("\n", $cfg['roles'])) ?></textarea>
  </section>

  <!-- Notificaciones por correo -->
  <?php $co = $cfg['correo']; ?>
  <section class="card-base ajuste-card">
    <h2 class="font-display"><i class="fa-solid fa-envelope text-secondary"></i> Notificaciones por correo</h2>
    <p class="ajuste-ayuda">
      Cuando se asigne una tarea a alguien con correo registrado, le llegará un aviso automático.
      Con Gmail usa una <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">contraseña de aplicación</a>
      (requiere verificación en 2 pasos), no la contraseña normal.
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
    <label class="campo"><span>Correo remitente (cuenta que envía)</span>
      <input class="input-meca" type="email" name="correo[usuario]" value="<?= e($co['usuario']) ?>" placeholder="mecapacito.ecuador@gmail.com">
    </label>

    <p class="ajuste-ayuda" style="margin-top:4px"><b>Solo para SMTP:</b></p>
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

    <p class="ajuste-ayuda" style="margin-top:4px"><b>Solo para API de Gmail</b> (proyecto de Google Cloud con scope <code>gmail.send</code>):</p>
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
    <label class="campo"><span>URL del panel (para el botón "Ver tablero" del correo)</span>
      <input class="input-meca" type="url" name="correo[url_panel]" value="<?= e($co['url_panel']) ?>" placeholder="https://mecapacito.com/admin">
    </label>

    <div class="correo-prueba">
      <input class="input-meca" type="email" name="para" form="frm-correo-prueba" placeholder="tucorreo@gmail.com" required>
      <button class="btn-outline btn-meca btn-sm" form="frm-correo-prueba">
        <i class="fa-solid fa-paper-plane"></i> Probar envío
      </button>
    </div>
    <small class="campo-ayuda">Guarda los ajustes primero y luego usa "Probar envío".</small>
  </section>

  <footer class="ajustes-guardar">
    <span class="ajuste-ayuda">Si quitas un estado o prioridad en uso, las tareas afectadas pasan automáticamente al primero de la lista.</span>
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
<template id="tpl-ep">
  <div class="ajuste-fila fila-cat fila-cat-3">
    <input type="hidden" name="ep[__i__][key]" value="">
    <input class="input-meca input-icono" name="ep[__i__][icono]" value="fa-flag" title="Ícono (clase Font Awesome)">
    <input class="input-meca" name="ep[__i__][label]" maxlength="24" value="" placeholder="Nuevo estado">
    <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Quitar"><i class="fa-solid fa-trash"></i></button>
  </div>
</template>

<?php UI::fin(); ?>
