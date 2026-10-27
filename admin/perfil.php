<?php
/**
 * Mi perfil: arriba las conexiones (Google Calendar y el asistente de código),
 * debajo la ficha, que solo se ve: cada bloque (datos generales, cuenta,
 * aspecto) se edita en su modal. Todo es un solo formulario con los modales
 * dentro, así que cualquier «Guardar» envía la ficha entera. Seguridad se
 * edita en su tarjeta, con la barra de guardar.
 *
 * Lo que NO se toca aquí, a propósito: el nivel de acceso y el equipo. Los
 * decide un administrador desde Equipo (si no, cualquiera se daría permisos).
 */
require_once __DIR__ . '/lib/bootstrap.php';

$yo = Auth::usuario();
if (!$yo) {
    redirigir('login.php', 'Tu sesión expiró. Entra de nuevo.', 'error');
}

$miId       = (int)$yo['id'];
$eq         = MiembroRepo::equipoDe($yo);
$equipos    = Catalogo::equipos();
[$eqLabel, $eqIcono] = $equipos[$eq];
$c1         = Catalogo::colorDe($yo['color'] ?? 0);
$tieneClave = !empty($yo['pass_hash']);
$googleOn   = GoogleLogin::listo();

$finales   = Catalogo::estadosFinales();
$misTareas = array_filter((new TareaRepo())->todas(), fn($t) => TareaRepo::tieneAsignado($t, $miId));
$abiertas  = count(array_filter($misTareas, fn($t) => !in_array($t['estado'] ?? '', $finales, true)));
$misProyectos = [];
foreach ($misTareas as $t) { $misProyectos[(int)$t['proyecto_id']] = true; }

UI::inicio('Mi perfil', 'perfil');
?>

<!-- Cabecera -->
<header class="pf-hero card-base" style="--av-c1:<?= $c1 ?>">
  <label class="pf-hero-foto" title="Cambiar foto">
    <input type="file" name="foto" class="pf-file" form="perfil-form" accept="image/png,image/jpeg,image/webp,image/gif">
    <span class="avatar pf-hero-avatar" style="--sz:96px;--av-c1:<?= $c1 ?>">
      <img class="pf-img" alt="" <?= empty($yo['foto']) ? 'hidden' : 'src="' . e($yo['foto']) . '"' ?>>
      <span class="pf-iniciales" <?= empty($yo['foto']) ? '' : 'hidden' ?>><?= e(MiembroRepo::iniciales($yo)) ?></span>
    </span>
    <span class="pf-cam"><i class="fa-solid fa-camera"></i></span>
  </label>
  <div class="pf-hero-id">
    <h1 class="font-display js-hero-nombre"><?= e($yo['nombre']) ?></h1>
    <p class="pf-hero-rol" style="--av-c1:<?= $c1 ?>">
      <?= UI::iconoEquipo($eq, $eqIcono, 'ico-equipo--chip') ?> <span class="js-hero-rol"><?= e($yo['rol']) ?: 'Sin rol' ?></span> · <?= e($eqLabel) ?>
    </p>
    <div class="pf-hero-chips">
      <span class="pf-chip"><i class="fa-solid fa-shield-halved"></i> <?= e(Auth::ROLES[Auth::rol()] ?? 'Solo lectura') ?></span>
      <span class="pf-chip <?= $tieneClave ? '' : 'off' ?>"><i class="fa-solid <?= $tieneClave ? 'fa-lock' : 'fa-lock-open' ?>"></i> <?= $tieneClave ? 'Con contraseña' : 'Sin contraseña' ?></span>
    </div>
  </div>
  <div class="pf-hero-stats">
    <span><b><?= $abiertas ?></b> abiertas</span>
    <span><b><?= count($misTareas) ?></b> asignadas</span>
    <span><b><?= count($misProyectos) ?></b> proyectos</span>
  </div>
</header>

<?php
// Conectar Claude Code al panel (api.php): el token se ve una sola vez, recién creado
require_once __DIR__ . '/lib/ApiToken.php';
$tk = ApiToken::estado($yo);
$tkNuevo = (string)($_SESSION['api_token_nuevo'] ?? '');
unset($_SESSION['api_token_nuevo']);
$apiUrl = ((!empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . urlPanel('api.php');
$cuandoTk = fn(string $f) => $f !== '' ? date('d/m/Y H:i', strtotime($f)) : '';
// Los logos van dentro de la página (no como <img>) para que tomen el color del tema
$logoIa = fn(string $f) => is_file(__DIR__ . '/assets/' . $f) ? (string)preg_replace('#<title>.*?</title>#s', '', (string)file_get_contents(__DIR__ . '/assets/' . $f)) : '';
$logosIa = '<span class="pf-api-ias" aria-hidden="true">'
    . '<span class="pf-api-ia" title="Claude Code"><img src="assets/claude.svg" alt="" width="24" height="24"></span>'
    . '<span class="pf-api-ia pf-api-ia--marca" title="Codex">' . $logoIa('codex.svg') . '</span>'
    . '<span class="pf-api-ia pf-api-ia--marca" title="GitHub Copilot">' . $logoIa('copilot.svg') . '</span>'
    . '<span class="pf-api-ia pf-api-ia--mas" title="Y cualquier asistente que pueda usar curl">+</span>'
    . '</span>';
$guiaApi = "## InnoTech Hub: mis tareas (API)\n\n"
    . "Las tareas del panel se manejan con su API. El token está en la variable de entorno\n"
    . "MCHUB_TOKEN: nunca lo escribas en el repo, en un archivo ni en un commit.\n\n"
    . "API=\"$apiUrl\"\n"
    . "AUTH=\"Authorization: Bearer \$MCHUB_TOKEN\"\n\n"
    . "Consultar:\n"
    . "- Mis tareas abiertas:  curl -s -H \"\$AUTH\" \"\$API?r=tareas\"\n"
    . "- Buscar una:           curl -s -H \"\$AUTH\" \"\$API?r=tareas&q=pertinencia\"\n"
    . "- Las de mis proyectos: curl -s -H \"\$AUTH\" \"\$API?r=tareas&todas=1\"\n"
    . "- Una tarea en detalle: curl -s -H \"\$AUTH\" \"\$API?r=tarea&id=812\"\n"
    . "- Ítems del release:    curl -s -H \"\$AUTH\" \"\$API?r=items&equipo=Beta\"\n\n"
    . "Registrar y mover:\n"
    . "- Crear tareas (el JSON de Planificar, con item_release). Primero con \"validar\": true; si no hay errores, sin él.\n"
    . "  curl -s -X POST -H \"\$AUTH\" -H \"Content-Type: application/json\" -d @tareas.json \"\$API?r=tareas\"\n"
    . "  La respuesta trae el #id de cada tarea creada.\n"
    . "- Asociar un commit (y, si se quiere, cambiar el estado en la misma llamada):\n"
    . "  curl -s -X POST -H \"\$AUTH\" -H \"Content-Type: application/json\" -d '{\"id\":812,\"sha\":\"<sha>\",\"mensaje\":\"<mensaje>\",\"repo\":\"<repo>\",\"estado\":\"revision\"}' \"\$API?r=commit\"\n"
    . "- Cambiar el estado:\n"
    . "  curl -s -X POST -H \"\$AUTH\" -H \"Content-Type: application/json\" -d '{\"id\":812,\"estado\":\"hecho\"}' \"\$API?r=estado\"\n"
    . "  Estados: pendiente, progreso, revision, hecho.\n\n"
    . "Cómo trabajar:\n"
    . "1. Antes de empezar algo, busca si ya tiene tarea (?r=tareas&q=…). Si no, créala con su item_release.\n"
    . "2. El mensaje de cada commit termina con su #id; después del commit, asócialo con ?r=commit.\n"
    . "3. Hecho pero sin subir al servidor de pruebas: \"revision\". Tras el deploy (lunes y jueves, 22:00): \"hecho\".\n"
    . "4. Si la API responde 401, avisa: el token se quitó o se cambió (Mi perfil → Tu asistente de código).\n";

$misGitMails = array_values(array_filter(array_map('trim', explode(',', (string)($yo['git_emails'] ?? '')))));
$sinDefinir = '<i class="pf-vacio">Sin definir</i>';
?>

<!-- Conexiones: arriba, y en verde cuando ya están hechas -->
<div class="pf-conexiones">
  <?php if (GoogleCalendar::listo()): $calConectado = !empty($yo['gcal_refresh']); ?>
  <section class="card-base pf-con<?= $calConectado ? ' is-on' : '' ?>">
    <span class="pf-con-ic"><img src="assets/calendar.svg" alt="" width="42" height="42"></span>
    <div class="pf-con-txt">
      <h2>Google Calendar</h2>
      <p class="pf-con-estado"><?= $calConectado
          ? '<i class="fa-solid fa-circle-check"></i> Conectado: tus tareas con fecha llegan a tu calendario'
          : 'Recibe en tu calendario tus tareas con fecha' ?></p>
    </div>
    <a href="google_calendario.php" class="btn-meca <?= $calConectado ? 'btn-outline btn-neutro' : 'btn-primary' ?>">
      <i class="fa-brands fa-google"></i> <?= $calConectado ? 'Volver a conectar' : 'Conectar' ?>
    </a>
  </section>
  <?php endif; ?>

  <section class="card-base pf-con pf-con--ia<?= $tk['tiene'] ? ' is-on' : '' ?>" id="claude-api">
    <?= $logosIa ?>
    <div class="pf-con-txt">
      <h2>Tu asistente de código</h2>
      <p class="pf-con-estado"><?= $tk['tiene']
          ? '<i class="fa-solid fa-circle-check"></i> Conectado · token …' . e($tk['fin']) . ($tk['usado'] !== '' ? ' · último uso ' . e($cuandoTk($tk['usado'])) : ' · sin usar todavía')
          : 'Claude Code, Codex o Copilot trabajan con tus tareas' ?></p>
    </div>
    <button type="button" class="btn-meca <?= $tk['tiene'] ? 'btn-outline btn-neutro' : 'btn-primary' ?>" data-abrir-dlg="dlg-asistente"<?= $tk['tiene'] ? ' data-wz-ir="2"' : '' ?>>
      <i class="fa-solid <?= $tk['tiene'] ? 'fa-terminal' : 'fa-plug' ?>"></i> <?= $tk['tiene'] ? 'Instrucciones' : 'Conectar' ?>
    </button>
  </section>
</div>

<!-- La ficha: todo se ve; cada bloque se edita en su modal. Los modales viven
     DENTRO del formulario: su «Guardar» envía la ficha entera, que es lo que
     espera perfil_guardar (si faltara un campo, lo dejaría vacío). -->
<form method="post" action="actions.php" id="perfil-form" class="pf-form" enctype="multipart/form-data" novalidate>
  <input type="hidden" name="accion" value="perfil_guardar">

  <div class="pf-grid">
    <!-- Datos generales -->
    <section class="card-base pf-card">
      <div class="pf-card-cab">
        <h2 class="pf-card-tit"><i class="fa-solid fa-id-badge"></i> Datos generales</h2>
        <button type="button" class="pf-lapiz pf-editar" data-abrir-dlg="dlg-pf-datos" title="Editar datos generales" aria-label="Editar datos generales"><i class="fa-solid fa-pen"></i></button>
      </div>
      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid fa-user"></i></div>
        <div class="pf-cuerpo"><span class="pf-label">Nombre</span><span class="pf-valor"><?= e($yo['nombre']) ?></span></div>
      </div>
      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid fa-briefcase"></i></div>
        <div class="pf-cuerpo"><span class="pf-label">Rol</span><span class="pf-valor"><?= e($yo['rol'] ?? '') ?: $sinDefinir ?></span></div>
      </div>
      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid fa-people-group"></i></div>
        <div class="pf-cuerpo"><span class="pf-label">Equipo y acceso</span><span class="pf-valor"><?= e($eqLabel) ?> · <?= e(Auth::ROLES[Auth::rol()] ?? 'Solo lectura') ?></span></div>
      </div>
      <p class="pf-nota"><i class="fa-solid fa-circle-info"></i> Tu <b>equipo</b> y tu <b>acceso</b> los cambia un administrador desde Equipo.</p>
    </section>

    <!-- Cuenta -->
    <section class="card-base pf-card">
      <div class="pf-card-cab">
        <h2 class="pf-card-tit"><i class="fa-solid fa-at"></i> Cuenta</h2>
        <button type="button" class="pf-lapiz pf-editar" data-abrir-dlg="dlg-pf-cuenta" title="Editar cuenta" aria-label="Editar cuenta"><i class="fa-solid fa-pen"></i></button>
      </div>
      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-brands fa-github"></i></div>
        <div class="pf-cuerpo"><span class="pf-label">Usuario de Git</span><span class="pf-valor"><?= !empty($yo['git_user']) ? '@' . e($yo['git_user']) : $sinDefinir ?></span></div>
      </div>
      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid fa-envelope"></i></div>
        <div class="pf-cuerpo"><span class="pf-label">Correo</span><span class="pf-valor"><?= !empty($yo['email']) ? e($yo['email']) : $sinDefinir ?></span></div>
      </div>
      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid fa-phone"></i></div>
        <div class="pf-cuerpo"><span class="pf-label">Teléfono</span><span class="pf-valor"><?= !empty($yo['telefono']) ? e($yo['telefono']) : $sinDefinir ?></span></div>
      </div>
      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid fa-code-branch"></i></div>
        <div class="pf-cuerpo"><span class="pf-label">Correos de Git</span>
          <?php if ($misGitMails): ?>
          <span class="pf-valor pf-valor-lista"><?php foreach ($misGitMails as $ge): ?><span><?= e($ge) ?></span><?php endforeach; ?></span>
          <?php else: ?><span class="pf-valor"><?= $sinDefinir ?></span><?php endif; ?>
        </div>
      </div>
      <p class="pf-nota"><i class="fa-solid fa-circle-info"></i> Con el correo o el usuario de Git entras al panel<?= $googleOn ? '; con el correo, también con «Continuar con Google»' : '' ?>.</p>
    </section>

    <!-- Aspecto -->
    <section class="card-base pf-card">
      <div class="pf-card-cab">
        <h2 class="pf-card-tit"><i class="fa-solid fa-palette"></i> Aspecto</h2>
        <button type="button" class="pf-lapiz pf-editar" data-abrir-dlg="dlg-pf-aspecto" title="Editar aspecto" aria-label="Editar aspecto"><i class="fa-solid fa-pen"></i></button>
      </div>
      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid fa-droplet"></i></div>
        <div class="pf-cuerpo"><span class="pf-label">Color del avatar</span>
          <span class="pf-valor pf-valor-color"><span class="pf-muestra" style="--av-c1:<?= $c1 ?>"></span><?= e(strtoupper($c1)) ?></span></div>
      </div>
      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid fa-camera"></i></div>
        <div class="pf-cuerpo"><span class="pf-label">Foto</span><span class="pf-valor"><?= empty($yo['foto']) ? '<i class="pf-vacio">Sin foto: se ven tus iniciales</i>' : 'Con foto' ?></span></div>
      </div>
    </section>

    <!-- Seguridad: se queda como estaba (se abre aquí mismo) -->
    <section class="card-base pf-card">
      <h2 class="pf-card-tit"><i class="fa-solid fa-lock"></i> Seguridad</h2>

      <div class="pf-fila">
        <div class="pf-ic"><i class="fa-solid <?= $tieneClave ? 'fa-lock' : 'fa-lock-open' ?>"></i></div>
        <div class="pf-cuerpo">
          <span class="pf-label">Contraseña</span>
          <span class="pf-valor"><?= $tieneClave ? '••••••••' : '<i class="pf-vacio">Sin contraseña — entras con Google</i>' ?></span>
        </div>
        <button type="button" class="pf-lapiz js-clave-toggle" title="Cambiar contraseña"><i class="fa-solid fa-pen"></i></button>
      </div>

      <div class="pf-clave" hidden>
        <?php if ($tieneClave): ?>
        <label class="campo"><span>Contraseña actual</span>
          <div class="input-prefijo"><i class="fa-solid fa-lock"></i>
            <input class="input-meca" type="password" name="clave_actual" autocomplete="current-password" placeholder="La que usas hoy"></div>
        </label>
        <?php endif; ?>
        <div class="campo-doble">
          <label class="campo"><span>Nueva</span>
            <div class="input-prefijo"><i class="fa-solid fa-key"></i>
              <input class="input-meca" type="password" name="clave_nueva" minlength="6" autocomplete="new-password" placeholder="mínimo 6"></div>
          </label>
          <label class="campo"><span>Repetir</span>
            <div class="input-prefijo"><i class="fa-solid fa-key"></i>
              <input class="input-meca" type="password" name="clave_repetir" minlength="6" autocomplete="new-password" placeholder="otra vez"></div>
          </label>
        </div>
      </div>
    </section>
  </div>

  <!-- Modal: datos generales (nombre y rol) -->
  <dialog id="dlg-pf-datos" class="dlg-meca dlg-wizard pf-dlg" aria-labelledby="pf-dlg-datos-t">
    <div class="pf-dlg-cab">
      <span class="pf-dlg-ic"><i class="fa-solid fa-id-badge"></i></span>
      <div><h3 id="pf-dlg-datos-t">Datos generales</h3><p>Tu nombre y tu rol, como los ve el equipo</p></div>
      <button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="pf-dlg-cuerpo">
      <label class="campo"><span>Nombre</span>
        <input class="input-meca" name="nombre" required maxlength="60" value="<?= e($yo['nombre']) ?>"></label>
      <label class="campo"><span>Rol</span>
        <input class="input-meca" name="rol" maxlength="40" list="lista-roles" value="<?= e($yo['rol'] ?? '') ?>" placeholder="Frontend Dev, Backend Dev…"></label>
      <p class="pf-nota"><i class="fa-solid fa-circle-info"></i> Tu equipo (<?= e($eqLabel) ?>) y tu acceso los cambia un administrador desde Equipo.</p>
    </div>
    <div class="pf-dlg-pie">
      <button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cancelar</button>
      <button type="submit" class="btn-primary btn-meca btn-agregar"><i class="fa-solid fa-check"></i> Guardar</button>
    </div>
  </dialog>

  <!-- Modal: cuenta (con qué entras y con qué commiteas) -->
  <dialog id="dlg-pf-cuenta" class="dlg-meca dlg-wizard pf-dlg" aria-labelledby="pf-dlg-cuenta-t">
    <div class="pf-dlg-cab">
      <span class="pf-dlg-ic"><i class="fa-solid fa-at"></i></span>
      <div><h3 id="pf-dlg-cuenta-t">Cuenta</h3><p>Con qué entras al panel y con qué se cuentan tus commits</p></div>
      <button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="pf-dlg-cuerpo">
      <div class="campo-doble">
        <label class="campo"><span>Usuario de Git</span>
          <input class="input-meca" name="git_user" maxlength="40" value="<?= e($yo['git_user'] ?? '') ?>" placeholder="usuario-github"></label>
        <label class="campo"><span>Teléfono</span>
          <input class="input-meca" type="tel" name="telefono" minlength="9" maxlength="15" inputmode="numeric" pattern="<?= e(MiembroRepo::TELEFONO_PATRON) ?>" title="<?= e(MiembroRepo::TELEFONO_AYUDA) ?>" autocomplete="tel" value="<?= e(preg_replace('/\D/', '', (string)($yo['telefono'] ?? ''))) ?>" placeholder="Ej.: 0991234567"></label>
      </div>
      <label class="campo"><span>Correo</span>
        <input class="input-meca" type="email" name="email" maxlength="80" value="<?= e($yo['email'] ?? '') ?>" placeholder="nombre@innotech-solutions.com.ec"></label>
      <div class="campo">
        <span>Correos de Git (para contar tus commits)</span>
        <div class="git-emails" data-git-emails>
          <?php foreach ($misGitMails ?: [''] as $ge): ?>
          <div class="git-email-fila">
            <div class="input-prefijo"><i class="fa-solid fa-envelope"></i>
              <input class="input-meca" type="text" inputmode="email" name="git_emails[]" maxlength="80" placeholder="correo-github@ejemplo.com" value="<?= e($ge) ?>"></div>
            <button type="button" class="accion-btn accion-peligro git-email-quitar" title="Quitar"><i class="fa-solid fa-xmark"></i></button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn-ghost btn-meca btn-sm git-email-agregar"><i class="fa-solid fa-plus"></i> Agregar otro correo</button>
        <small class="campo-ayuda">El correo con el que commiteas en cada cuenta (GitHub, GitLab…). Así se te cuentan todos tus commits aunque cambies de usuario o de máquina.</small>
      </div>
      <p class="pf-nota"><i class="fa-solid fa-circle-info"></i> Deja al menos el correo o el usuario de Git: son las dos formas de entrar al panel.</p>
    </div>
    <div class="pf-dlg-pie">
      <button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cancelar</button>
      <button type="submit" class="btn-primary btn-meca btn-agregar"><i class="fa-solid fa-check"></i> Guardar</button>
    </div>
  </dialog>

  <!-- Modal: aspecto (color del avatar y foto) -->
  <dialog id="dlg-pf-aspecto" class="dlg-meca dlg-wizard pf-dlg" aria-labelledby="pf-dlg-aspecto-t">
    <div class="pf-dlg-cab">
      <span class="pf-dlg-ic"><i class="fa-solid fa-palette"></i></span>
      <div><h3 id="pf-dlg-aspecto-t">Aspecto</h3><p>Cómo te ve el equipo en el panel</p></div>
      <button type="button" class="dlg-close" data-cerrar-dlg aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="pf-dlg-cuerpo">
      <div class="campo"><span>Color del avatar</span><?= UI::colorPicker($yo['color'] ?? 0) ?></div>
      <div class="campo"><span>Foto</span>
        <div class="pf-dlg-foto">
          <span class="avatar" style="--sz:56px;--av-c1:<?= $c1 ?>">
            <img class="pf-img" alt="" <?= empty($yo['foto']) ? 'hidden' : 'src="' . e($yo['foto']) . '"' ?>>
            <span class="pf-iniciales" <?= empty($yo['foto']) ? '' : 'hidden' ?>><?= e(MiembroRepo::iniciales($yo)) ?></span>
          </span>
          <button type="button" class="btn-outline btn-meca btn-sm btn-neutro js-pf-foto"><i class="fa-solid fa-camera"></i> Cambiar foto</button>
          <small class="campo-ayuda">JPG, PNG, WebP o GIF. También la cambias tocando el avatar de arriba.</small>
        </div>
      </div>
    </div>
    <div class="pf-dlg-pie">
      <button type="button" class="btn-outline btn-meca btn-neutro" data-cerrar-dlg>Cancelar</button>
      <button type="submit" class="btn-primary btn-meca btn-agregar"><i class="fa-solid fa-check"></i> Guardar</button>
    </div>
  </dialog>

  <!-- Barra de guardar: para la contraseña y la foto cambiada desde arriba -->
  <div class="pf-guardar" id="pf-guardar" hidden>
    <span class="pf-guardar-txt"><i class="fa-solid fa-circle-dot"></i> Tienes cambios sin guardar</span>
    <div class="pf-guardar-acc">
      <button type="button" class="btn-outline btn-meca btn-rojo" id="pf-descartar">Descartar</button>
      <button type="submit" class="btn-primary btn-meca btn-agregar"><i class="fa-solid fa-check"></i> Guardar cambios</button>
    </div>
  </div>
</form>

<datalist id="lista-roles">
  <?php foreach (Catalogo::roles() as $rol): ?><option value="<?= e($rol) ?>"></option><?php endforeach; ?>
</datalist>

<?php /* Los botones del asistente envían ESTOS formularios (atributo form=): un
         formulario no puede ir dentro de otro y el del asistente es por pasos. */ ?>
<form method="post" action="actions.php" id="f-tk-crear" class="inline-form" hidden<?= $tk['tiene'] ? ' data-confirmar="El token que tienes deja de servir y tu asistente tendrá que usar el nuevo." data-confirmar-titulo="¿Crear un token nuevo?" data-confirmar-ok="Sí, crear otro"' : '' ?>>
  <input type="hidden" name="accion" value="api_token_crear">
</form>
<form method="post" action="actions.php" id="f-tk-quitar" class="inline-form" hidden data-confirmar="Tu asistente ya no podrá entrar al panel con ese token." data-confirmar-titulo="¿Quitar el token?" data-confirmar-ok="Sí, quitar">
  <input type="hidden" name="accion" value="api_token_quitar">
</form>
<form method="post" action="actions.php" id="f-json" class="inline-form" hidden data-descarga>
  <input type="hidden" name="accion" value="mis_tareas_json">
</form>
<form method="post" action="actions.php" id="f-contexto" class="inline-form" hidden data-descarga>
  <input type="hidden" name="accion" value="contexto_tareas_json">
</form>

<!-- Asistente: conectar Claude Code, Codex, Copilot… (recién creado el token, se abre en el paso 2) -->
<dialog id="dlg-asistente" class="dlg-meca dlg-wizard"<?= $tkNuevo !== '' ? ' data-abrir-al-cargar' : '' ?>>
  <form class="dlg-form wz" onsubmit="return false">
    <?= UI::wizardRiel('fa-plug', 'Tu asistente', 'Claude Code, Codex, Copilot…', UI::PASOS_ASISTENTE) ?>
    <div class="wz-cuerpo">
      <header>
        <div>
          <h4 class="wz-titulo-paso"></h4>
          <p class="wz-ayuda-paso"></p>
        </div>
        <button type="button" class="dlg-close" onclick="this.closest('dialog').close()" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
      </header>

      <section class="wz-panel">
        <div class="pf-as-logos"><?= $logosIa ?></div>
        <p class="pf-as-txt">Con un token tuyo, tu asistente trabaja con tus tareas sin que descargues ni pegues el JSON. Actúa como tú, con tus mismos permisos.</p>
        <ul class="pf-as-lista">
          <li><i class="fa-solid fa-magnifying-glass"></i> Consulta tus tareas y las de tus proyectos</li>
          <li><i class="fa-solid fa-plus"></i> Crea las nuevas, amarradas a su ítem del release</li>
          <li><i class="fa-solid fa-code-commit"></i> Asocia cada commit a su tarea por el #id</li>
          <li><i class="fa-solid fa-arrows-rotate"></i> Les cambia el estado: en progreso, revisión, hecho</li>
        </ul>
        <p class="pf-as-estado<?= $tk['tiene'] ? ' is-on' : '' ?>"><?= $tk['tiene']
            ? '<i class="fa-solid fa-circle-check"></i> Ya está conectado · token …' . e($tk['fin']) . ($tk['usado'] !== '' ? ' · último uso ' . e($cuandoTk($tk['usado'])) : ' · sin usar todavía')
            : '<i class="fa-regular fa-circle"></i> Todavía sin token: lo creas en el paso siguiente' ?></p>
      </section>

      <section class="wz-panel">
        <?php if ($tkNuevo !== ''): ?>
        <div class="pf-as-nuevo">
          <b><i class="fa-solid fa-key"></i> Cópialo ahora: no se vuelve a mostrar</b>
          <div class="pf-api-token"><code><?= e($tkNuevo) ?></code>
            <button type="button" class="btn-outline btn-meca btn-sm btn-azul btn-copiar" data-copiar="<?= e($tkNuevo) ?>"><i class="fa-regular fa-copy"></i> Copiar</button></div>
          <p>Guárdalo en tu computadora, no en el repo. En la terminal (y abre otra después):</p>
          <div class="pf-api-token"><code>echo 'export MCHUB_TOKEN="<?= e($tkNuevo) ?>"' &gt;&gt; ~/.zshrc</code>
            <button type="button" class="btn-outline btn-meca btn-sm btn-azul btn-copiar" data-copiar="<?= e("echo 'export MCHUB_TOKEN=\"" . $tkNuevo . "\"' >> ~/.zshrc") ?>"><i class="fa-regular fa-copy"></i> Copiar</button></div>
        </div>
        <?php elseif ($tk['tiene']): ?>
        <div class="pf-as-caja">
          <p class="pf-as-estado is-on"><i class="fa-solid fa-circle-check"></i> Tienes un token activo: …<?= e($tk['fin']) ?><?= $tk['creado'] !== '' ? ' · creado el ' . e($cuandoTk($tk['creado'])) : '' ?></p>
          <p class="pf-as-txt">Por seguridad solo se ve una vez, al crearlo. Si lo perdiste o cambiaste de computadora, crea uno nuevo: el anterior deja de servir.</p>
        </div>
        <?php else: ?>
        <div class="pf-as-caja">
          <p class="pf-as-txt">Tu token es una llave personal: con ella tu asistente entra al panel como tú. Se muestra una sola vez, al crearlo; aquí solo se guarda su huella.</p>
        </div>
        <?php endif; ?>
        <div class="pf-as-acc">
          <button type="submit" form="f-tk-crear" class="btn-primary btn-meca"><i class="fa-solid fa-key"></i> <?= $tk['tiene'] ? 'Crear un token nuevo' : 'Crear mi token' ?></button>
          <?php if ($tk['tiene']): ?>
          <button type="submit" form="f-tk-quitar" class="btn-outline btn-meca btn-neutro"><i class="fa-solid fa-ban"></i> Quitar el token</button>
          <?php endif; ?>
        </div>
      </section>

      <section class="wz-panel">
        <p class="pf-as-txt">Va en <code>CLAUDE.md</code> para Claude Code, en <code>AGENTS.md</code> para Codex y en <code>.github/copilot-instructions.md</code> para Copilot: sirve para cualquiera que pueda usar <code>curl</code>.</p>
        <div class="pf-as-guia">
          <button type="button" class="btn-outline btn-meca btn-sm btn-azul btn-copiar" data-copiar="<?= e($guiaApi) ?>"><i class="fa-regular fa-copy"></i> Copiar</button>
          <pre><?= e($guiaApi) ?></pre>
        </div>
        <?php /* El formato de tareas con los releases e ítems de todos los equipos, en vivo */ ?>
        <div class="pf-as-contexto">
          <span class="pf-as-contexto-ic"><i class="fa-solid fa-diagram-project" aria-hidden="true"></i></span>
          <div>
            <b>Formato de tareas y releases</b>
            <small>El formato para crear tareas, los releases y los ítems de todos los equipos: dáselo a tu asistente para que amarre cada tarea a su ítem.</small>
          </div>
          <button type="submit" form="f-contexto" class="btn-outline btn-meca btn-sm btn-azul"><i class="fa-solid fa-file-arrow-down"></i> Descargar</button>
        </div>
        <p class="pf-as-otro">¿Sin token? También puedes
          <button type="submit" form="f-json" class="pf-as-link"><i class="fa-solid fa-file-arrow-down"></i> descargar tus tareas en JSON</button>
          y pasárselas, o <a href="docs.php" class="pf-as-link"><i class="fa-solid fa-book-open"></i> ver el estándar de commits</a>.</p>
      </section>

      <div class="wz-pie">
        <span class="wz-contador"></span>
        <div class="wz-acciones">
          <button type="button" class="btn-outline btn-meca btn-neutro wz-atras"><i class="fa-solid fa-arrow-left"></i> Atrás</button>
          <button type="button" class="btn-primary btn-meca wz-siguiente">Siguiente <i class="fa-solid fa-arrow-right"></i></button>
          <button type="button" class="btn-primary btn-meca btn-agregar wz-guardar" onclick="this.closest('dialog').close()"><i class="fa-solid fa-check"></i> Listo</button>
        </div>
      </div>
    </div>
  </form>
</dialog>

<script>
(() => {
  const form = document.getElementById('perfil-form');
  const barra = document.getElementById('pf-guardar');
  if (!form) return;

  // Cada bloque se edita en su modal. Al abrirlo se toma una foto de sus
  // valores; si se cierra sin guardar, vuelven, y la ficha no se lleva nada.
  const foto = (d) => {
    d._valores = [...d.querySelectorAll('input, select, textarea')]
      .map((c) => [c, c.type === 'radio' || c.type === 'checkbox' ? c.checked : c.value]);
    const ge = d.querySelector('[data-git-emails]');
    d._gitEmails = ge ? ge.innerHTML : null;
  };
  const restaurar = (d) => {
    const ge = d.querySelector('[data-git-emails]');
    if (ge && d._gitEmails !== null) ge.innerHTML = d._gitEmails;
    (d._valores || []).forEach(([c, v]) => {
      if (!c.isConnected || c.type === 'file') return;
      if (typeof v === 'boolean') c.checked = v; else c.value = v;
    });
  };

  document.addEventListener('click', (e) => {
    const abrir = e.target.closest('[data-abrir-dlg]');
    if (abrir) {
      const d = document.getElementById(abrir.dataset.abrirDlg);
      if (!d) return;
      if (abrir.dataset.wzIr) d.dataset.wzIr = abrir.dataset.wzIr;
      if (form.contains(d)) { foto(d); d._guardando = false; }
      d.showModal();
      return;
    }
    const cerrar = e.target.closest('[data-cerrar-dlg]');
    if (cerrar) cerrar.closest('dialog')?.close();
  });
  form.querySelectorAll('dialog').forEach((d) => d.addEventListener('close', () => { if (!d._guardando) restaurar(d); }));

  // «Guardar» de un modal: primero lo de ese modal tiene que valer
  form.addEventListener('submit', (e) => {
    const d = e.submitter && e.submitter.closest('dialog');
    if (!d) return;
    const malo = [...d.querySelectorAll('input, select, textarea')].find((c) => c.willValidate && !c.checkValidity());
    if (malo) { e.preventDefault(); malo.reportValidity(); return; }
    d._guardando = true;
  });

  // Cambiar contraseña: revela el bloque (Seguridad se edita aquí mismo)
  const claveToggle = form.querySelector('.js-clave-toggle');
  const claveBox = form.querySelector('.pf-clave');
  claveToggle?.addEventListener('click', () => {
    claveBox.hidden = !claveBox.hidden;
    claveToggle.classList.toggle('activo', !claveBox.hidden);
    if (!claveBox.hidden) claveBox.querySelector('input')?.focus();
  });

  // La barra de guardar es para lo que se cambia fuera de los modales
  const mostrar = (e) => { if (!e.target.closest?.('dialog')) barra.hidden = false; };
  form.addEventListener('input', mostrar);
  form.addEventListener('change', mostrar);
  document.getElementById('pf-descartar')?.addEventListener('click', () => location.reload());

  // Foto: el input vive en la cabecera, FUERA del form (asociado con form="…").
  // Se previsualiza en la cabecera y en el modal; si no se está en el modal de
  // Aspecto (que tiene su «Guardar»), aparece la barra.
  const file = document.querySelector('.pf-file');
  const dAspecto = document.getElementById('dlg-pf-aspecto');
  document.querySelector('.js-pf-foto')?.addEventListener('click', () => file?.click());
  file?.addEventListener('change', () => {
    const f = file.files && file.files[0];
    if (!f) return;
    const url = URL.createObjectURL(f);
    document.querySelectorAll('.pf-img').forEach((img) => { img.src = url; img.hidden = false; });
    document.querySelectorAll('.pf-iniciales').forEach((ini) => { ini.hidden = true; });
    if (!dAspecto?.open) barra.hidden = false;
  });
  // Cerrar Aspecto sin guardar con una foto ya elegida: queda pendiente en la barra
  dAspecto?.addEventListener('close', () => { if (!dAspecto._guardando && file?.files?.length) barra.hidden = false; });

  // Recién creado el token, el asistente se abre solo en el paso del token
  window.addEventListener('DOMContentLoaded', () => {
    const dA = document.getElementById('dlg-asistente');
    if (dA && dA.dataset.abrirAlCargar !== undefined) { dA.dataset.wzIr = '1'; dA.showModal(); }
  });
})();
</script>

<?php UI::fin(); ?>
