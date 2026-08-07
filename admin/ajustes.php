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
Auth::requiereAdmin();

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
  <button type="button" class="tab-btn" data-tab="reuniones"><i class="fa-solid fa-calendar-check"></i> Reuniones</button>
  <button type="button" class="tab-btn" data-tab="acceso"><i class="fa-solid fa-shield-halved"></i> Acceso y respaldo</button>
</div>

<form method="post" action="actions.php" class="ajustes-form" enctype="multipart/form-data">
  <input type="hidden" name="accion" value="config_guardar">

  <!-- ================= TAB: Identidad ================= -->
  <div class="tab-panel" data-panel="identidad">
    <div class="ajustes-grid">
      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-palette text-secondary"></i> Colores de marca</h2>
        <p class="ajuste-ayuda">Toca una tarjeta para elegir el color. Se aplican a botones, enlaces y acentos de todo el panel.</p>
        <div class="tarjetas-color">
          <label class="tarjeta-color" style="--tc:<?= e($cfg['color_secundario']) ?>">
            <input type="color" name="color_secundario" value="<?= e($cfg['color_secundario']) ?>">
            <i class="fa-solid fa-wand-magic-sparkles tc-icono"></i>
            <span class="tc-nombre">Color principal</span>
            <b class="tc-hex"><?= e(strtoupper($cfg['color_secundario'])) ?></b>
          </label>
          <label class="tarjeta-color" style="--tc:<?= e($cfg['color_acento']) ?>">
            <input type="color" name="color_acento" value="<?= e($cfg['color_acento']) ?>">
            <i class="fa-solid fa-star tc-icono"></i>
            <span class="tc-nombre">Color de acento</span>
            <b class="tc-hex"><?= e(strtoupper($cfg['color_acento'])) ?></b>
          </label>
        </div>
      </section>

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

        <div class="campo logo-campo">
          <span>Logo del panel</span>
          <div class="logo-config">
            <span class="logo-prev"><img src="<?= e(logoPanel()) ?>" alt="Logo actual"></span>
            <div class="logo-config-txt">
              <input type="file" name="logo" class="input-meca" accept="image/png,image/jpeg,image/webp">
              <small class="campo-ayuda">PNG con fondo transparente, cuadrado (se ve en el menú, el login y la pestaña del navegador).</small>
              <?php if (!empty($cfg['logo'])): ?>
              <label class="logo-quitar"><input type="checkbox" name="logo_quitar" value="1"> Quitar y volver al logo por defecto</label>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>

  <!-- ================= TAB: Catalogos (stepper) ================= -->
  <div class="tab-panel" data-panel="catalogos" hidden>

    <div class="stepper" data-clave="catalogos">
      <button type="button" class="paso active" data-paso="1">
        <span class="paso-num">1</span>
        <span class="paso-txt"><i class="fa-solid fa-list-check"></i> Estados de tarea</span>
      </button>
      <span class="paso-linea"></span>
      <button type="button" class="paso" data-paso="2">
        <span class="paso-num">2</span>
        <span class="paso-txt"><i class="fa-solid fa-angles-up"></i> Prioridades</span>
      </button>
      <span class="paso-linea"></span>
      <button type="button" class="paso" data-paso="3">
        <span class="paso-num">3</span>
        <span class="paso-txt"><i class="fa-solid fa-folder-open"></i> Estados de proyecto</span>
      </button>
      <span class="paso-linea"></span>
      <button type="button" class="paso" data-paso="4">
        <span class="paso-num">4</span>
        <span class="paso-txt"><i class="fa-solid fa-people-group"></i> Equipos</span>
      </button>
    </div>

    <div class="paso-panel" data-paso-panel="1">
    <div class="ajustes-grid">
      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-list-check text-secondary"></i> Estados de tarea</h2>
        <p class="ajuste-ayuda">
          La bandera <i class="fa-solid fa-flag-checkered"></i> marca los que cuentan como <b>completada</b> para el % de avance.
        </p>
        <div class="mc-tabla">
          <div class="mc-tabla-head mc-head-azul">
            <span><i class="fa-solid fa-list-check"></i> Estado</span>
            <button type="button" class="mc-tabla-add btn-agregar-fila" data-lista="cuerpo-et" data-plantilla="tpl-et" data-insertar="inicio">
              <i class="fa-solid fa-plus"></i> Agregar
            </button>
          </div>
          <div class="mc-tabla-cuerpo ajuste-lista" id="cuerpo-et">
            <?php $i = 0; foreach ($cfg['estados_tarea'] as $k => $v): ?>
            <div class="mc-fila mf-estado">
              <input type="hidden" name="et[<?= $i ?>][key]" value="<?= e($k) ?>">
              <input class="input-meca input-icono" name="et[<?= $i ?>][icono]" value="<?= e($v['icono'] ?? 'fa-circle-dot') ?>" title="Ícono (clase Font Awesome)">
              <input class="mc-fila-dato" name="et[<?= $i ?>][label]" maxlength="24" value="<?= e($v['label']) ?>" placeholder="Nombre del estado">
              <input type="color" name="et[<?= $i ?>][color]" value="<?= e($v['color']) ?>" title="Color">
              <label class="chk-final" title="Cuenta como completada">
                <input type="checkbox" name="et[<?= $i ?>][final]" <?= !empty($v['final']) ? 'checked' : '' ?>>
                <i class="fa-solid fa-flag-checkered"></i>
              </label>
              <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
            </div>
            <?php $i++; endforeach; ?>
          </div>
        </div>
      </section>

    </div>
    </div>

    <div class="paso-panel" data-paso-panel="2" hidden>
    <div class="ajustes-grid">
      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-angles-up text-secondary"></i> Prioridades</h2>
        <p class="ajuste-ayuda">El orden aquí define el orden en la tabla (la última es la más urgente).</p>
        <div class="mc-tabla">
          <div class="mc-tabla-head mc-head-naranja">
            <span><i class="fa-solid fa-angles-up"></i> Prioridad</span>
            <button type="button" class="mc-tabla-add btn-agregar-fila" data-lista="cuerpo-pr" data-plantilla="tpl-pr" data-insertar="inicio">
              <i class="fa-solid fa-plus"></i> Agregar
            </button>
          </div>
          <div class="mc-tabla-cuerpo ajuste-lista" id="cuerpo-pr">
            <?php $i = 0; foreach ($cfg['prioridades'] as $k => $v): ?>
            <div class="mc-fila mf-prio">
              <input type="hidden" name="pr[<?= $i ?>][key]" value="<?= e($k) ?>">
              <input class="input-meca input-icono" name="pr[<?= $i ?>][icono]" value="<?= e($v['icono'] ?? 'fa-equals') ?>" title="Ícono (clase Font Awesome)">
              <input class="mc-fila-dato" name="pr[<?= $i ?>][label]" maxlength="24" value="<?= e($v['label']) ?>" placeholder="Nombre de la prioridad">
              <input type="color" name="pr[<?= $i ?>][color]" value="<?= e($v['color']) ?>" title="Color">
              <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
            </div>
            <?php $i++; endforeach; ?>
          </div>
        </div>
      </section>

    </div>
    </div>

    <div class="paso-panel" data-paso-panel="3" hidden>
    <div class="ajustes-grid">
      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-folder-open text-secondary"></i> Estados de proyecto</h2>
        <p class="ajuste-ayuda">Ej. "En propuesta", "Mantenimiento".</p>
        <div class="mc-tabla">
          <div class="mc-tabla-head mc-head-morado">
            <span><i class="fa-solid fa-folder-open"></i> Estado</span>
            <button type="button" class="mc-tabla-add btn-agregar-fila" data-lista="cuerpo-ep" data-plantilla="tpl-ep" data-insertar="inicio">
              <i class="fa-solid fa-plus"></i> Agregar
            </button>
          </div>
          <div class="mc-tabla-cuerpo ajuste-lista" id="cuerpo-ep">
            <?php $i = 0; foreach (Catalogo::estadosProyecto() as $k => [$label, $icono]): ?>
            <div class="mc-fila mf-simple">
              <input type="hidden" name="ep[<?= $i ?>][key]" value="<?= e($k) ?>">
              <input class="input-meca input-icono" name="ep[<?= $i ?>][icono]" value="<?= e($icono) ?>" title="Ícono (clase Font Awesome)">
              <input class="mc-fila-dato" name="ep[<?= $i ?>][label]" maxlength="24" value="<?= e($label) ?>" placeholder="Nombre del estado">
              <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
            </div>
            <?php $i++; endforeach; ?>
          </div>
        </div>
      </section>

    </div>
    </div>

    <div class="paso-panel" data-paso-panel="4" hidden>
    <div class="ajustes-grid">
      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-people-group text-secondary"></i> Equipos</h2>
        <p class="ajuste-ayuda">Cada equipo tiene su página propia en el menú (ej. "Programadores", "Analistas", "Diseño").</p>
        <div class="mc-tabla">
          <div class="mc-tabla-head mc-head-verde">
            <span><i class="fa-solid fa-people-group"></i> Equipo</span>
            <button type="button" class="mc-tabla-add btn-agregar-fila" data-lista="cuerpo-eqs" data-plantilla="tpl-eqs" data-insertar="inicio">
              <i class="fa-solid fa-plus"></i> Agregar
            </button>
          </div>
          <div class="mc-tabla-cuerpo ajuste-lista" id="cuerpo-eqs">
            <?php $i = 0; foreach (Catalogo::equipos() as $k => [$label, $icono]): ?>
            <div class="mc-fila mf-simple">
              <input type="hidden" name="eqs[<?= $i ?>][key]" value="<?= e($k) ?>">
              <input class="input-meca input-icono" name="eqs[<?= $i ?>][icono]" value="<?= e($icono) ?>" title="Ícono (clase Font Awesome)">
              <input class="mc-fila-dato" name="eqs[<?= $i ?>][label]" maxlength="24" value="<?= e($label) ?>" placeholder="Nombre del equipo">
              <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
            </div>
            <?php $i++; endforeach; ?>
          </div>
        </div>
      </section>
    </div>
    </div>

    <div class="paso-nav">
      <button type="button" class="btn-outline btn-meca btn-sm" id="paso-prev" disabled>
        <i class="fa-solid fa-arrow-left"></i> Anterior
      </button>
      <span class="paso-indicador" id="paso-indicador">Paso 1 de 4</span>
      <button type="button" class="btn-outline btn-meca btn-sm" id="paso-next">
        Siguiente <i class="fa-solid fa-arrow-right"></i>
      </button>
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
      <section class="card-base ajuste-card ajuste-card-tabla">
        <h2 class="font-display"><i class="fa-solid fa-user-tag text-secondary"></i> Roles sugeridos</h2>
        <p class="ajuste-ayuda">Se sugieren al escribir el rol de un colaborador. Edita en línea y guarda con "Guardar ajustes".</p>
        <div class="mc-tabla">
          <div class="mc-tabla-head">
            <span><i class="fa-solid fa-user-tag"></i> Rol</span>
            <button type="button" class="mc-tabla-add btn-agregar-fila" data-lista="cuerpo-rl" data-plantilla="tpl-rl" data-insertar="inicio">
              <i class="fa-solid fa-plus"></i> Agregar
            </button>
          </div>
          <div class="mc-tabla-cuerpo ajuste-lista" id="cuerpo-rl">
            <?php foreach ($cfg['roles'] as $rol): ?>
            <div class="mc-fila">
              <input class="mc-fila-dato" name="rl[]" maxlength="40" value="<?= e($rol) ?>" readonly>
              <button type="button" class="accion-btn btn-editar-fila" title="Editar"><i class="fa-solid fa-pen"></i></button>
              <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
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
            <input class="input-meca" type="email" name="correo[usuario]" value="<?= e($co['usuario']) ?>" placeholder="tucorreo@gmail.com">
          </label>
          <label class="campo"><span>URL del panel (botón "Ver tablero" del correo)</span>
            <input class="input-meca" type="url" name="correo[url_panel]" value="<?= e($co['url_panel']) ?>" placeholder="https://panel.innotech-solutions.com.ec/admin">
            <?php $urlLogo = Mailer::logoUrlPublica(); ?>
            <small class="campo-ayuda">
              <?php if ($urlLogo !== ''): ?>
                De aquí sale también la imagen de los correos:
                <a href="<?= e($urlLogo) ?>" target="_blank" rel="noopener">abrirla en una pestaña</a>.
                Si ahí no se ve el logo, tampoco se verá en el correo.
              <?php else: ?>
                Sin esta URL los correos salen sin logo y sin botón: no hay forma de
                construir enlaces absolutos.
              <?php endif; ?>
            </small>
          </label>
        </div>

        <h3 class="correo-sub"><i class="fa-solid fa-bell text-secondary"></i> ¿Qué avisar?</h3>
        <label class="chk-linea">
          <input type="checkbox" name="correo[avisar_asignacion]" <?= !empty($co['avisar_asignacion']) ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Avisar a la persona cuando se le asigna una tarea
        </label>
        <label class="chk-linea">
          <input type="checkbox" name="correo[avisar_proyecto]" <?= !empty($co['avisar_proyecto']) ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Avisar cuando se le suma al equipo de un proyecto
        </label>
        <label class="chk-linea">
          <input type="checkbox" name="correo[avisar_intercambio]" <?= !empty($co['avisar_intercambio']) ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Avisar de las propuestas de intercambio de tareas y sus respuestas
        </label>
        <div class="chk-con-campo">
          <label class="chk-linea">
            <input type="checkbox" name="correo[avisar_recordatorio]" <?= !empty($co['avisar_recordatorio']) ? 'checked' : '' ?>>
            <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
            Recordar tareas próximas a vencer
          </label>
          <label class="campo campo-inline"><span>días antes</span>
            <input class="input-meca" type="number" min="0" max="30" name="correo[dias_recordatorio]" value="<?= (int)($co['dias_recordatorio'] ?? 3) ?>" style="width:90px">
          </label>
        </div>
        <label class="chk-linea">
          <input type="checkbox" name="correo[avisar_completado]" <?= !empty($co['avisar_completado']) ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Avisarme cuando un proyecto se completa (todas sus tareas entregadas)
        </label>
        <label class="campo"><span>Correo del administrador (recibe los avisos de proyecto completado)</span>
          <input class="input-meca" type="email" name="correo[admin_email]" value="<?= e($co['admin_email'] ?? '') ?>" placeholder="tucorreo@gmail.com">
        </label>

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
          <input class="input-meca" type="password" name="correo[clave]" value="" placeholder="<?= !empty($co['clave']) ? '•••••••• guardada' : 'xxxx xxxx xxxx xxxx' ?>">
        </label>

        <p class="ajuste-ayuda"><b>Solo para API de Gmail</b> (proyecto de Google Cloud con scope <code>gmail.send</code>):</p>
        <label class="campo"><span>Client ID</span>
          <input class="input-meca" name="correo[client_id]" value="<?= e($co['client_id'] ?? '') ?>" placeholder="xxxx.apps.googleusercontent.com">
        </label>
        <div class="campo-doble">
          <label class="campo"><span>Client Secret</span>
            <input class="input-meca" type="password" name="correo[client_secret]" value="" placeholder="<?= !empty($co['client_secret']) ? '•••••••• guardado' : '' ?>">
          </label>
          <label class="campo"><span>Refresh Token</span>
            <input class="input-meca" type="password" name="correo[refresh_token]" value="" placeholder="<?= !empty($co['refresh_token']) ? '•••••••• guardado' : '' ?>">
          </label>
        </div>

        <!-- El refresh token no hay que buscarlo a mano: se consigue autorizando
             con la cuenta que enviará (Gmail manda desde quien autoriza). -->
        <div class="conectar-envio <?= !empty($co['refresh_token']) ? 'ok' : '' ?>">
          <div class="ce-txt">
            <?php if (!empty($co['refresh_token'])): ?>
              <strong><i class="fa-solid fa-circle-check"></i> Cuenta de envío conectada</strong>
              <small>Los correos salen desde <b><?= e($co['usuario'] ?: '—') ?></b>. Vuelve a conectar si cambias de cuenta.</small>
            <?php else: ?>
              <strong><i class="fa-solid fa-triangle-exclamation"></i> Falta conectar la cuenta de envío</strong>
              <small>Guarda primero el Client ID y el Secret. Luego autoriza <b>con la cuenta que enviará</b>
                     (no con la tuya): Gmail siempre manda desde quien autoriza.</small>
            <?php endif; ?>
          </div>
          <?php if (Mailer::puedeConectar()): ?>
          <a class="btn-google btn-sm" href="<?= e(Mailer::urlConectar()) ?>">
            <img src="../assets/google.svg" alt="" width="17" height="17">
            <?= !empty($co['refresh_token']) ? 'Volver a conectar' : 'Conectar cuenta de envío' ?>
          </a>
          <?php else: ?>
          <span class="ajuste-ayuda">Pon el Client ID y el Client Secret, guarda, y aquí aparecerá el botón.</span>
          <?php endif; ?>
        </div>
        <!-- La URI se calcula desde la URL con la que estás navegando ahora: si
             entras por localhost es la de localhost, y esa TAMBIÉN hay que
             registrarla en Google Cloud o sale redirect_uri_mismatch. -->
        <p class="ajuste-ayuda">
          URI de redirección que está usando este panel <b>ahora mismo</b> — regístrala en Google Cloud
          (Credenciales → tu ID de cliente → URIs de redireccionamiento autorizados):
        </p>
        <div class="chip-copiar" style="margin-bottom:6px">
          <code><?= e(GoogleLogin::redirectUri()) ?></code>
          <button type="button" class="accion-btn btn-copiar" data-copiar="<?= e(GoogleLogin::redirectUri()) ?>" title="Copiar"><i class="fa-regular fa-copy"></i></button>
        </div>
        <small class="campo-ayuda">
          Puedes tener varias registradas (la de local y la del servidor). Además, la pantalla de
          consentimiento necesita el permiso <code>gmail.send</code> y la <b>Gmail API habilitada</b> en el
          proyecto; si está en modo <b>Prueba</b>, añade esa cuenta como <b>usuario de prueba</b>
          (si no, el permiso caduca a los 7 días y los correos dejan de salir).
        </small>

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

  <!-- ================= TAB: Zoom ================= -->
  <?php $zc = $cfg['zoom']; ?>
  <div class="tab-panel" data-panel="reuniones" hidden>
    <div class="ajustes-grid">
      <section class="card-base ajuste-card ajuste-card-ancha">
        <?php $rc = Reuniones::conf(); $disp = Reuniones::disponibles(); ?>
        <h2 class="font-display"><i class="fa-solid fa-calendar-check text-secondary"></i> Reuniones</h2>
        <p class="ajuste-ayuda">
          Aquí <b>no se crea ninguna reunión</b>: se definen las reglas que aplican a todos los proyectos.
          Cada reunión se crea en <b>Proyecto → Reuniones → Nueva reunión</b>, y es ahí donde eliges su día y
          su hora, y donde está la casilla <i>«Repetir todas las semanas»</i> con las fichas L M X J V S D
          (por ejemplo, todos los días de lunes a viernes a la misma hora).
        </p>

        <?php if (!$disp): ?>
        <div class="obs-intro reu-vacio">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <span>
            <b>Todavía no puedes crear reuniones.</b> El panel no las inventa: necesita conectarse a Zoom o a
            Google. Hasta que hagas una de las dos cosas, en los proyectos verás «Configurar reuniones» en vez
            del botón «Nueva reunión», y lo de aquí abajo no tiene efecto.
            <br><br>
            <b>Opción A — Zoom.</b> Crea una app <i>Server-to-Server OAuth</i> en
            <a href="https://marketplace.zoom.us/develop/create" target="_blank" rel="noopener">Zoom Marketplace</a>
            y pega Account ID, Client ID y Client Secret en <a href="#zoom-credenciales">Conexión con Zoom</a>
            (aquí abajo), marcando «Activar reuniones de Zoom».
            <br>
            <b>Opción B — Google Meet.</b> En <a href="#tab-acceso">Acceso y respaldo → Acceso al panel</a>
            pon el Client ID y el Client Secret de Google y marca «enviar tareas al Google Calendar».
            Después, cada persona conecta su calendario en <b>Mi perfil → Conectar mi calendario</b>.
            <br><br>
            Con cualquiera de las dos basta. Si configuras las dos, podrás elegir por reunión.
          </span>
        </div>
        <?php endif; ?>

        <div class="campo-doble">
          <label class="campo"><span>Plataforma por defecto</span>
            <?= UI::select('reuniones[plataforma]', ['zoom' => 'Zoom', 'meet' => 'Google Meet'], $rc['plataforma']) ?>
            <small class="campo-ayuda">
              <?php if ($disp && !isset($disp[$rc['plataforma']])): ?>
              <b class="sem-txt-rojo">Ojo:</b> esa plataforma no está configurada, así que ahora mismo
              se usa <b><?= e(reset($disp)) ?></b>.
              <?php else: ?>
              La que se usa salvo que un proyecto tenga la suya propia
              (<b>Editar proyecto → Plataforma de reuniones</b>).
              <?php endif; ?>
            </small>
          </label>
          <label class="campo"><span>Duración por defecto</span>
            <?= UI::select('reuniones[duracion]', Reuniones::duraciones(), (string)$rc['duracion']) ?>
            <small class="campo-ayuda">La que viene marcada al abrir "Nueva reunión".</small>
          </label>
        </div>

        <label class="chk-linea">
          <input type="checkbox" name="reuniones[permitir_elegir]" <?= !empty($rc['permitir_elegir']) ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Dejar que el equipo elija la plataforma en cada reunión
        </label>
        <p class="ajuste-ayuda">Si lo desmarcas, el selector desaparece del formulario y todas salen
          por la plataforma de arriba. El selector solo aparece si las dos están configuradas.</p>

        <label class="chk-linea">
          <input type="checkbox" name="reuniones[agendar]" <?= !empty($rc['agendar']) ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Agendar la reunión en el Google Calendar de cada invitado
        </label>
        <p class="ajuste-ayuda">
          En <b>Meet</b>, quien tenga correo registrado recibe la invitación de Google (con aviso por correo).
          En <b>Zoom</b>, y para quien no tenga correo, se escribe el evento en su calendario usando su propia
          conexión de Google — hace falta que cada persona la active en <b>Mi perfil → Conectar mi calendario</b>.
        </p>

        <label class="campo"><span>Zona horaria de las reuniones</span>
          <input class="input-meca" name="reuniones[zona]" value="<?= e($rc['zona']) ?>" placeholder="vacío = la misma que Zoom (<?= e(Reuniones::zona()) ?>)">
          <small class="campo-ayuda">Se aplica a Zoom y a Google Meet por igual.</small>
        </label>
      </section>

      <section class="card-base ajuste-card ajuste-card-ancha">
        <h2 class="font-display" id="zoom-credenciales"><i class="fa-solid fa-video text-secondary"></i> Conexión con Zoom</h2>
        <p class="ajuste-ayuda">
          Credenciales para que el panel pueda crear reuniones de Zoom, con enlace para entrar y acceso a la grabación.
          Necesitas una app <b>Server-to-Server OAuth</b> en
          <a href="https://marketplace.zoom.us/develop/create" target="_blank" rel="noopener">Zoom Marketplace</a>
          con scopes <code>meeting:write</code>, <code>meeting:read</code> y <code>recording:read</code>.
          <br>La grabación en la nube requiere un plan Zoom de pago (Pro o superior).
        </p>

        <label class="chk-linea">
          <input type="checkbox" name="zoom[activo]" <?= !empty($zc['activo']) ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Activar reuniones de Zoom
        </label>

        <div class="campo-doble">
          <label class="campo"><span>Account ID</span>
            <input class="input-meca" name="zoom[account_id]" value="<?= e($zc['account_id']) ?>" placeholder="de la app S2S OAuth">
          </label>
          <label class="campo"><span>Zona horaria</span>
            <input class="input-meca" name="zoom[zona]" value="<?= e($zc['zona']) ?>" placeholder="America/Guayaquil">
          </label>
        </div>
        <div class="campo-doble">
          <label class="campo"><span>Client ID</span>
            <input class="input-meca" name="zoom[client_id]" value="<?= e($zc['client_id']) ?>">
          </label>
          <label class="campo"><span>Client Secret</span>
            <input class="input-meca" type="password" name="zoom[client_secret]" value="" placeholder="<?= !empty($zc['client_secret']) ? '•••••••• guardado' : '' ?>">
          </label>
        </div>

        <div class="correo-prueba">
          <span class="ajuste-ayuda" style="flex:1">Guarda primero; luego prueba la conexión con Zoom.</span>
          <button class="btn-outline btn-meca btn-sm" form="frm-zoom-prueba">
            <i class="fa-solid fa-plug-circle-check"></i> Probar conexión
          </button>
        </div>
      </section>
    </div>
  </div>

  <!-- ================= TAB: Acceso y respaldo ================= -->
  <div class="tab-panel" data-panel="acceso" hidden>
    <div class="ajustes-grid">
      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-brands fa-google text-secondary"></i> Acceso al panel</h2>
        <?php $gl = (array)($cfg['google_login'] ?? []); ?>
        <label class="chk-linea">
          <input type="checkbox" name="google_login[activo]" <?= !empty($gl['activo']) ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Permitir entrar con cuenta de Google
        </label>
        <p class="ajuste-ayuda">
          Solo entran los correos que ya existen como colaboradores. Si dejas vacías las credenciales,
          se reutilizan las del correo (API de Gmail). Registra esta <b>URI de redirección</b> en
          <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a>:
        </p>
        <div class="chip-copiar" style="margin-bottom:6px">
          <code><?= e(GoogleLogin::redirectUri()) ?></code>
          <button type="button" class="accion-btn btn-copiar" data-copiar="<?= e(GoogleLogin::redirectUri()) ?>" title="Copiar"><i class="fa-regular fa-copy"></i></button>
        </div>
        <div class="campo-doble">
          <label class="campo"><span>Client ID (opcional)</span>
            <input class="input-meca" name="google_login[client_id]" value="<?= e($gl['client_id'] ?? '') ?>" placeholder="usa el del correo si lo dejas vacío">
          </label>
          <label class="campo"><span>Client Secret (opcional)</span>
            <input class="input-meca" type="password" name="google_login[client_secret]" value="" placeholder="<?= !empty($gl['client_secret']) ? '•••••••• guardado' : '' ?>">
          </label>
        </div>
        <label class="chk-linea">
          <input type="checkbox" name="google_login[vincular_por_nombre]" <?= !empty($gl['vincular_por_nombre']) ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Reconocer por nombre y apellido si el correo aún no está registrado
        </label>
        <label class="chk-linea">
          <input type="checkbox" name="google_login[calendario]" <?= !empty($gl['calendario']) ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Enviar las tareas al Google Calendar de cada responsable
        </label>
        <small class="campo-ayuda">
          Cuando una tarea tiene fecha, se crea un evento en el Google Calendar de su
          responsable. Cada persona debe <b>volver a entrar con Google</b> una vez para
          conceder el permiso de calendario (en Google Cloud Console agrega el scope
          <code>calendar.events</code> a la pantalla de consentimiento).
        </small>
        <small class="campo-ayuda">
          Con esto, la cuenta de Google «Jaione Cherres» entra como la colaboradora
          Jaione Cherres y su correo queda vinculado solo. Requiere que el nombre
          calce completo y que esa persona todavía no tenga correo.
          <b>Apágalo</b> si prefieres registrar tú cada correo a mano.
        </small>

      </section>

      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-user-plus text-secondary"></i> Registro de cuentas</h2>
        <?php $rg = Auth::registro(); ?>
        <label class="chk-linea">
          <input type="checkbox" name="registro[abierto]" <?= $rg['abierto'] ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Dejar que alguien cree su cuenta desde el login
        </label>
        <p class="ajuste-ayuda">
          La cuenta se crea <b>con Google</b>, que verifica el correo, y registrarse
          <b>no da acceso</b>: queda una solicitud que apruebas o rechazas en
          <a href="equipo.php">Equipo</a>. Hasta que la apruebes, esa persona no existe como
          colaborador (no sale en tareas, proyectos ni selectores).
        </p>
        <?php if (!GoogleLogin::listo()): ?>
        <p class="ajuste-ayuda ajuste-aviso">
          <i class="fa-solid fa-triangle-exclamation"></i>
          Necesita el <b>acceso con Google</b> de aquí arriba: sin él, la pantalla de registro
          avisa de que no está disponible.
        </p>
        <?php endif; ?>
        <label class="campo">
          <span>Dominios de correo permitidos</span>
          <input class="input-meca" name="registro[dominios]" value="<?= e($rg['dominios']) ?>"
                 placeholder="itb.edu.ec, innotech.ec">
        </label>
        <small class="campo-ayuda">
          Separados por comas. Se aceptan también sus subdominios (<code>mail.itb.edu.ec</code>).
          Déjalo vacío para admitir cualquier cuenta de Google.
        </small>
        <label class="chk-linea">
          <input type="checkbox" name="registro[avisar]" <?= $rg['avisar'] ? 'checked' : '' ?>>
          <span class="chk-caja"><i class="fa-solid fa-check"></i></span>
          Avisarme por correo de cada solicitud nueva
        </label>
        <small class="campo-ayuda">
          Se envía a todos los administradores con correo registrado
          <?php $ae = trim((string)($cfg['correo']['admin_email'] ?? '')); ?>
          <?= $ae !== '' ? 'y a ' . e($ae) . '.' : '(y al correo de contacto, si lo pones en Correo).' ?>
          Necesita el correo del panel configurado.
        </small>
      </section>

      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-brands fa-github text-secondary"></i> Repositorios (GitHub y GitLab)</h2>
        <p class="ajuste-ayuda">
          El panel reconoce el proveedor por la dirección del repositorio, así que basta
          con pegar el enlace en cada proyecto. Los tokens son opcionales: sirven para leer
          repos privados y para no quedarse sin cuota de la API.
        </p>
        <label class="campo">
          <span><i class="fa-brands fa-github"></i> Token de GitHub (opcional)</span>
          <input class="input-meca" type="password" name="github_token" value="" placeholder="<?= !empty($cfg['github_token']) ? '•••••••• guardado' : 'ghp_...' ?>">
        </label>
        <small class="campo-ayuda">
          Para el mapa de actividad de repos privados y más cuota de la API.
          Créalo en <a href="https://github.com/settings/tokens" target="_blank" rel="noopener">github.com/settings/tokens</a> con permiso de solo lectura de repos.
        </small>

        <label class="campo">
          <span><i class="fa-brands fa-gitlab"></i> Token de GitLab (opcional)</span>
          <input class="input-meca" type="password" name="gitlab_token" value="" placeholder="<?= !empty($cfg['gitlab_token']) ? '•••••••• guardado' : 'glpat-...' ?>">
        </label>
        <small class="campo-ayuda">
          Token personal con permiso <code>read_api</code>. Créalo en tu propio GitLab, en
          <code>/-/user_settings/personal_access_tokens</code> (Preferencias → Access tokens);
          si usas <a href="https://gitlab.com/-/user_settings/personal_access_tokens" target="_blank" rel="noopener">gitlab.com</a>,
          ahí mismo. Sin token solo se leen repos públicos: los privados contestan
          «404 Project Not Found» y el gráfico de aportes se queda vacío.
        </small>

        <label class="campo">
          <span>Instancia propia de GitLab (opcional)</span>
          <input class="input-meca" name="gitlab_host" value="<?= e($cfg['gitlab_host'] ?? '') ?>" placeholder="git.miempresa.com">
        </label>
        <small class="campo-ayuda">
          Solo si tu GitLab está en un dominio que no lleva «gitlab» en el nombre; si no,
          se detecta solo. Puedes poner varios separados por coma. El token de arriba se
          usa para todos, así que si son instancias distintas usa una sola aquí.
        </small>
      </section>

      <section class="card-base ajuste-card">
        <h2 class="font-display"><i class="fa-solid fa-file-arrow-down text-secondary"></i> Respaldo de la configuración</h2>
        <p class="ajuste-ayuda">
          Descarga todos estos ajustes en un archivo y súbelo en otra instalación
          (por ejemplo, del panel local al servidor) para no volver a escribirlos a mano.
          <b>El archivo lleva tus claves en texto plano</b>: guárdalo en un lugar seguro
          y bórralo cuando termines.
        </p>
        <div class="respaldo-acciones">
          <button class="btn-outline btn-meca" form="frm-config-exportar">
            <i class="fa-solid fa-download"></i> Exportar configuración
          </button>
          <label class="respaldo-archivo">
            <input type="file" name="archivo" accept="application/json,.json" form="frm-config-importar" required>
            <span><i class="fa-solid fa-file-arrow-up"></i> Elegir archivo .json</span>
          </label>
          <button class="btn-primary btn-meca" form="frm-config-importar">
            <i class="fa-solid fa-upload"></i> Importar
          </button>
        </div>
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
<form id="frm-zoom-prueba" method="post" action="actions.php">
  <input type="hidden" name="accion" value="zoom_prueba">
</form>

<!-- Respaldo: exportar (descarga) e importar (subida de un config.json) -->
<form id="frm-config-exportar" method="post" action="actions.php" data-descarga>
  <input type="hidden" name="accion" value="config_exportar">
</form>
<form id="frm-config-importar" method="post" action="actions.php" enctype="multipart/form-data"
      data-confirmar="Se reemplazarán los ajustes actuales con los del archivo. Tus proyectos, tareas y personas no se tocan."
      data-confirmar-titulo="¿Importar configuración?" data-confirmar-ok="Sí, importar">
  <input type="hidden" name="accion" value="config_importar">
</form>

<!-- Plantillas para filas nuevas -->
<template id="tpl-et">
  <div class="mc-fila mf-estado editando">
    <input type="hidden" name="et[__i__][key]" value="">
    <input class="input-meca input-icono" name="et[__i__][icono]" value="fa-circle-dot" title="Ícono (clase Font Awesome)">
    <input class="mc-fila-dato" name="et[__i__][label]" maxlength="24" value="" placeholder="Nuevo estado...">
    <input type="color" name="et[__i__][color]" value="#2B76F7" title="Color">
    <label class="chk-final" title="Cuenta como completada">
      <input type="checkbox" name="et[__i__][final]">
      <i class="fa-solid fa-flag-checkered"></i>
    </label>
    <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
  </div>
</template>
<template id="tpl-pr">
  <div class="mc-fila mf-prio editando">
    <input type="hidden" name="pr[__i__][key]" value="">
    <input class="input-meca input-icono" name="pr[__i__][icono]" value="fa-equals" title="Ícono (clase Font Awesome)">
    <input class="mc-fila-dato" name="pr[__i__][label]" maxlength="24" value="" placeholder="Nueva prioridad...">
    <input type="color" name="pr[__i__][color]" value="#F7931E" title="Color">
    <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
  </div>
</template>
<template id="tpl-eqs">
  <div class="mc-fila mf-simple editando">
    <input type="hidden" name="eqs[__i__][key]" value="">
    <input class="input-meca input-icono" name="eqs[__i__][icono]" value="fa-users" title="Ícono (clase Font Awesome)">
    <input class="mc-fila-dato" name="eqs[__i__][label]" maxlength="24" value="" placeholder="Nuevo equipo...">
    <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
  </div>
</template>
<template id="tpl-rl">
  <div class="mc-fila editando">
    <input class="mc-fila-dato" name="rl[]" maxlength="40" value="" placeholder="Nuevo rol...">
    <button type="button" class="accion-btn btn-editar-fila" title="Listo"><i class="fa-solid fa-check"></i></button>
    <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
  </div>
</template>
<template id="tpl-ep">
  <div class="mc-fila mf-simple editando">
    <input type="hidden" name="ep[__i__][key]" value="">
    <input class="input-meca input-icono" name="ep[__i__][icono]" value="fa-flag" title="Ícono (clase Font Awesome)">
    <input class="mc-fila-dato" name="ep[__i__][label]" maxlength="24" value="" placeholder="Nuevo estado...">
    <button type="button" class="accion-btn accion-peligro btn-quitar-fila" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
  </div>
</template>

<?php UI::fin(); ?>
