<?php
/**
 * Equipo: colaboradores con usuario de Git, foto y rol.
 * Soporta varios equipos (Programacion, Analistas, ...) via ?e=<clave>.
 * Los equipos son un catalogo parametrizable en Ajustes.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$miembrosRepo = new MiembroRepo();
$tareasRepo   = new TareaRepo();

$equipos = Catalogo::equipos();
$eq = MiembroRepo::equipoValido($_GET['e'] ?? '');
[$eqLabel, $eqIcono] = $equipos[$eq];

// El interruptor de administrador se muestra solo al admin y solo en el
// equipo de analistas: son los jefes que pueden editar el panel.
$puedeAcceso = esAdmin();
$yoId = (int)(Auth::usuario()['id'] ?? 0);

$equipo = array_values(array_filter(
    $miembrosRepo->todos(),
    fn($m) => MiembroRepo::equipoDe($m) === $eq
));

// Solo se abre la ficha ajena siendo administrador
$yo = (int)(Auth::usuario()['id'] ?? 0);

// Tareas por miembro: abiertas (no finales), total asignadas y detalle.
// Para quien no es administrador, solo cuentan las de sus propios proyectos.
$alcance  = alcanceProyectos();
$finales  = Catalogo::estadosFinales();
$abiertas = [];
$asignadas = [];
$tareasDe = [];
$nombresProyecto = [];
foreach (soloProyectosVisibles((new ProyectoRepo())->todos()) as $p) {
    $nombresProyecto[(int)$p['id']] = $p['nombre'];
}
foreach ($tareasRepo->todas() as $t) {
    if ($alcance !== null && !isset($alcance[(int)$t['proyecto_id']])) continue;
    $abierta = !in_array($t['estado'] ?? '', $finales, true);
    foreach (TareaRepo::asignadosDe($t) as $mid) {   // cuenta para cada responsable
        $asignadas[$mid] = ($asignadas[$mid] ?? 0) + 1;
        if ($abierta) {
            $abiertas[$mid] = ($abiertas[$mid] ?? 0) + 1;
            $tareasDe[$mid][] = $t;
        }
    }
}

// Solicitudes de acceso pendientes (solo las ve el admin). No están atadas a
// un equipo: quien se registra solo deja su nombre, correo y contraseña, y es
// el administrador quien decide aquí a qué equipo y con qué rol entra.
$solicitudes = esAdmin() ? (new SolicitudRepo())->todas() : [];
$rolesCat = (array)Config::get('roles');

// Carga masiva pendiente de confirmar (la dejó actions.php al leer el archivo)
$importe = esAdmin() ? ($_SESSION['import_equipo'] ?? null) : null;
$importePrevia = $importe ? ImportadorEquipo::aplicar($importe['filas'], true) : null;

UI::inicio('Equipo ' . $eqLabel, 'equipo-' . $eq);
UI::cabecera(
    'Equipo de <span class="text-secondary">' . e(mb_strtolower($eqLabel)) . '</span>',
    'Colaboradores del equipo, sus usuarios de Git y sus fotos.',
    '<button class="btn-outline btn-meca solo-admin" onclick="document.getElementById(\'dlg-importar\').showModal()">
       <i class="fa-solid fa-file-arrow-up"></i> Cargar desde Excel
     </button>
     <button class="btn-primary btn-meca solo-admin" onclick="document.getElementById(\'dlg-nuevo-miembro\').showModal()">
       <i class="fa-solid fa-user-plus"></i> Agregar colaborador
     </button>'
);
?>

<?php if ($importePrevia): ?>
<!-- Previsualización de la carga: se ve QUÉ va a pasar antes de escribir nada.
     Cargar veinte fichas a ciegas no hay forma de deshacerlo. -->
<section class="card-base imp-card">
  <div class="tabla-toolbar">
    <h2 class="font-display"><i class="fa-solid fa-file-arrow-up text-secondary"></i> Revisa la carga
      <span class="tabla-count"><?= count($importe['filas']) ?></span>
    </h2>
    <span class="ajuste-ayuda"><?= e($importe['archivo']) ?></span>
  </div>

  <div class="imp-resumen">
    <span class="imp-chip imp-nuevo"><b><?= $importePrevia['nuevos'] ?></b> nuevos</span>
    <span class="imp-chip imp-actualiza"><b><?= $importePrevia['actualizados'] ?></b> se actualizan</span>
    <span class="imp-chip imp-igual"><b><?= $importePrevia['iguales'] ?></b> sin cambios</span>
    <?php foreach ($importePrevia['proyectos'] as $nombreProy => $cuantos): ?>
    <span class="imp-chip imp-proy"><i class="fa-solid fa-folder-open"></i> <b><?= (int)$cuantos ?></b> a <?= e($nombreProy) ?></span>
    <?php endforeach; ?>
    <?php if ($importePrevia['sinProyecto']): ?>
    <span class="imp-chip"><b><?= $importePrevia['sinProyecto'] ?></b> solo como colaboradores</span>
    <?php endif; ?>
  </div>

  <?php if ($importePrevia['desconocidos']): ?>
  <!-- Un proyecto que no existe no frena la carga: entran igual y se asignan
       luego. Se dice una vez por nombre, no una por fila. -->
  <p class="imp-nota">
    <i class="fa-solid fa-circle-info"></i>
    <?php $partes = [];
      foreach ($importePrevia['desconocidos'] as $nom => $n) $partes[] = '«' . e($nom) . '» (' . (int)$n . ')';
      echo implode(', ', $partes); ?>
    no <?= count($importePrevia['desconocidos']) === 1 ? 'es un proyecto' : 'son proyectos' ?> del panel.
    Esas personas entran como colaboradoras y las asignas al proyecto cuando quieras.
  </p>
  <?php endif; ?>

  <div class="tabla-scroll imp-scroll">
    <table class="tabla-meca">
      <thead>
        <tr><th></th><th>Colaborador</th><th>Correo</th><th>Equipo</th><th>Proyecto</th><th>Nota</th></tr>
      </thead>
      <tbody>
        <?php foreach ($importePrevia['detalle'] as $d): ?>
        <tr>
          <td><span class="imp-tag imp-<?= e($d['accion']) ?>"><?= $d['accion'] === 'nuevo' ? 'nuevo' : ($d['accion'] === 'actualiza' ? 'actualiza' : '=') ?></span></td>
          <td><?= e($d['nombre']) ?></td>
          <td><?= $d['correo'] !== '' ? e($d['correo']) : '<span class="celda-muted">—</span>' ?></td>
          <td><?= e($d['equipo']) ?></td>
          <td><?= ($d['proyecto'] ?? '') !== ''
                    ? e($d['proyecto'])
                    : '<span class="celda-muted">sin asignar</span>' ?></td>
          <td class="celda-muted"><?= e($d['nota']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($importePrevia['avisos']): ?>
  <ul class="imp-avisos">
    <?php foreach ($importePrevia['avisos'] as $a): ?>
    <li><i class="fa-solid fa-triangle-exclamation"></i> <?= e($a) ?></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>

  <div class="imp-pie">
    <small class="ajuste-ayuda">
      Entran como colaboradores de <b>solo lectura</b>: cargar la lista no le da acceso al panel a nadie.
    </small>
    <div class="imp-botones">
      <form method="post" action="actions.php" class="inline-form">
        <input type="hidden" name="accion" value="equipo_importar_cancelar">
        <input type="hidden" name="volver" value="equipo.php?e=<?= e($eq) ?>">
        <button class="btn-outline btn-meca">Descartar</button>
      </form>
      <form method="post" action="actions.php" class="inline-form">
        <input type="hidden" name="accion" value="equipo_importar_confirmar">
        <input type="hidden" name="volver" value="equipo.php?e=<?= e($eq) ?>">
        <button class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Confirmar carga</button>
      </form>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if ($solicitudes): ?>
<!-- Solicitudes de acceso: gente que se registró y espera aprobación.
     Todavía no es del equipo, por eso va en su propia tarjeta y no en la tabla. -->
<section class="card-base sol-card">
  <div class="tabla-toolbar">
    <h2 class="font-display"><i class="fa-solid fa-user-clock text-secondary"></i> Solicitudes de acceso
      <span class="tabla-count"><?= count($solicitudes) ?></span>
    </h2>
    <span class="ajuste-ayuda">Tú decides el equipo y el rol. Entrará con la cuenta de Google con la que se registró.</span>
  </div>

  <div class="sol-lista">
    <?php foreach ($solicitudes as $s): $sid = (int)$s['id']; ?>
    <article class="sol-item">
      <div class="sol-quien">
        <?= UI::avatar(['nombre' => $s['nombre'], 'color' => 5], 42) ?>
        <div class="sol-datos">
          <strong><?= e($s['nombre']) ?></strong>
          <small>Pidió acceso el <?= e($s['creado'] ?? '') ?></small>
          <div class="sol-chips">
            <span class="sol-chip"><i class="fa-solid fa-envelope"></i> <?= e($s['email']) ?></span>
            <span class="sol-chip sol-chip-ok" title="El correo lo verificó Google al autorizar">
              <i class="fa-brands fa-google"></i> verificado
            </span>
          </div>
        </div>
      </div>

      <form method="post" action="actions.php" class="sol-acciones">
        <input type="hidden" name="accion" value="solicitud_aprobar">
        <input type="hidden" name="id" value="<?= $sid ?>">
        <input type="hidden" name="volver" value="equipo.php?e=<?= e($eq) ?>">
        <label class="sol-campo">
          <span>Equipo</span>
          <?= UI::select('equipo', array_map(fn($v) => $v[0], $equipos), $eq, false, 'select-sm') ?>
        </label>
        <label class="sol-campo">
          <span>Rol</span>
          <?= UI::select('rol', array_combine($rolesCat, $rolesCat), $rolesCat[0] ?? '', false, 'select-sm') ?>
        </label>
        <label class="sol-campo">
          <span>Acceso</span>
          <?= UI::select('acceso', Auth::ROLES, 'lector', false, 'select-sm') ?>
        </label>
        <div class="sol-botones">
          <button class="btn-primary btn-meca btn-sm"><i class="fa-solid fa-check"></i> Aprobar</button>
          <button type="button" class="btn-outline btn-meca btn-sm sol-no"
                  data-rechazar="<?= $sid ?>" data-nombre="<?= e($s['nombre']) ?>">
            <i class="fa-solid fa-xmark"></i> Rechazar
          </button>
        </div>
      </form>
    </article>
    <?php endforeach; ?>
  </div>
</section>

<!-- Modal: rechazar (el motivo viaja en el correo de aviso) -->
<dialog id="dlg-rechazar" class="dlg-meca">
  <form method="post" action="actions.php" class="dlg-form">
    <input type="hidden" name="accion" value="solicitud_rechazar">
    <input type="hidden" name="id" id="rc-id">
    <input type="hidden" name="volver" value="equipo.php?e=<?= e($eq) ?>">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-user-xmark text-secondary"></i> Rechazar solicitud</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <p class="ajuste-ayuda">Se borrará la solicitud de <b id="rc-nombre"></b>. Si el correo del panel está
       configurado, se le avisa con el motivo que escribas (puedes dejarlo vacío).</p>
    <label class="campo"><span>Motivo (opcional)</span>
      <textarea class="input-meca" name="motivo" rows="2" maxlength="300"
                placeholder="Ej. No reconocemos esta cuenta; escríbenos desde tu correo institucional."></textarea>
    </label>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-peligro btn-meca"><i class="fa-solid fa-xmark"></i> Rechazar</button>
    </footer>
  </form>
</dialog>
<?php endif; ?>

<?php if (empty($equipo)): ?>
  <?= UI::vacio($eqIcono, 'El equipo de ' . mb_strtolower($eqLabel) . ' está vacío', 'Agrega al primer colaborador con el botón de arriba.') ?>
<?php else: ?>

  <!-- Tabla de colaboradores: vision general con git/correo copiables -->
  <div class="card-base tabla-card">
    <div class="tabla-toolbar">
      <h2 class="font-display"><i class="fa-solid <?= e($eqIcono) ?> text-secondary"></i> Colaboradores
        <span class="tabla-count"><?= count($equipo) ?></span>
      </h2>
      <span class="ajuste-ayuda"><i class="fa-regular fa-copy"></i> copia el usuario o correo<?= esAdmin() ? ' · <i class="fa-solid fa-eye"></i> abre su ficha.' : '.' ?></span>
    </div>
    <div class="tabla-scroll">
      <table class="tabla-meca tabla-equipo">
        <thead>
          <tr>
            <th>Colaborador</th>
            <th><i class="fa-brands fa-github"></i> Usuario de Git</th>
            <th><i class="fa-solid fa-envelope"></i> Correo</th>
            <th>Abiertas</th>
            <?php if ($puedeAcceso): ?><th><i class="fa-solid fa-shield-halved"></i> Admin</th><?php endif; ?>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($equipo as $m):
              $c1 = Catalogo::colorDe($m['color'] ?? 0);
              $mid = (int)$m['id'];
              $pendientes = $abiertas[$mid] ?? 0;
              // La ficha ajena es solo para el administrador
              $ficha = (esAdmin() || $mid === $yo) ? 'colaborador.php?id=' . $mid : '';
          ?>
          <tr class="fila-colab<?= $ficha ? '' : ' fila-sin-ficha' ?>" style="--av-c1:<?= $c1 ?>"
              <?php if ($ficha): ?>onclick="if(!event.target.closest('.btn-copiar'))location.href='<?= $ficha ?>'"<?php endif; ?>>
            <td>
              <div class="celda-persona">
                <?= UI::avatar($m, 38) ?>
                <div class="cp-info">
                  <span><?= e($m['nombre']) ?></span>
                  <small><?= e($m['rol']) ?></small>
                </div>
              </div>
            </td>
            <td>
              <?php if (!empty($m['git_user'])): ?>
              <span class="chip-copiar">
                <code><i class="fa-brands fa-github"></i> @<?= e($m['git_user']) ?></code>
                <button type="button" class="accion-btn btn-copiar" data-copiar="<?= e($m['git_user']) ?>" title="Copiar usuario de Git">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </span>
              <?php else: ?><span class="celda-muted">—</span><?php endif; ?>
            </td>
            <td>
              <?php if (!empty($m['email'])): ?>
              <span class="chip-copiar">
                <code><i class="fa-solid fa-envelope"></i> <?= e($m['email']) ?></code>
                <button type="button" class="accion-btn btn-copiar" data-copiar="<?= e($m['email']) ?>" title="Copiar correo">
                  <i class="fa-regular fa-copy"></i>
                </button>
              </span>
              <?php else: ?><span class="celda-muted">—</span><?php endif; ?>
            </td>
            <td><span class="pr-chip" title="Tareas abiertas"><?= $pendientes ?></span></td>
            <?php if ($puedeAcceso): $esAdm = ($m['acceso'] ?? 'lector') === 'admin'; ?>
            <td class="celda-acceso" onclick="event.stopPropagation()">
              <?php if ($mid === $yoId): ?>
                <span class="acceso-yo"><i class="fa-solid fa-shield-halved"></i> Tú (admin)</span>
              <?php else: ?>
              <form method="post" action="actions.php" class="inline-form">
                <input type="hidden" name="accion" value="miembro_acceso_set">
                <input type="hidden" name="id" value="<?= $mid ?>">
                <input type="hidden" name="volver" value="equipo.php?e=<?= e($eq) ?>">
                <?= UI::select('acceso', Auth::ROLES, $m['acceso'] ?? 'lector', true, 'select-sm select-acceso ' . ($esAdm ? 'es-admin' : 'es-lector')) ?>
              </form>
              <?php if ($esAdm && empty($m['pass_hash']) && empty($m['email'])): ?>
                <small class="sw-aviso"><i class="fa-solid fa-triangle-exclamation"></i> sin correo ni clave</small>
              <?php endif; ?>
              <?php endif; ?>
            </td>
            <?php endif; ?>
            <td class="celda-acciones">
              <?php if ($ficha): ?>
              <a class="accion-btn" href="<?= $ficha ?>" title="Ver ficha"><i class="fa-solid fa-eye"></i></a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/lib/campos_persona.php'; ?>

<!-- Modal: cargar el equipo desde una hoja de cálculo -->
<dialog id="dlg-importar" class="dlg-meca">
  <form method="post" action="actions.php" class="dlg-form" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="equipo_importar">
    <input type="hidden" name="volver" value="equipo.php?e=<?= e($eq) ?>">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-file-arrow-up text-secondary"></i> Cargar equipo</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>

    <p class="ajuste-ayuda">
      Sube la hoja de Excel tal cual (<code>.xlsx</code>) o guardada como <code>.csv</code>.
      La primera fila tiene que ser la de los títulos; el orden de las columnas da igual.
    </p>

    <!-- Las columnas como fichas y no como tabla de ejemplo: una tabla de
         cuatro columnas no cabe en el modal y lo desbordaba a lo ancho. -->
    <div class="imp-cols">
      <span class="imp-col">Apellidos</span>
      <span class="imp-col">Nombres</span>
      <span class="imp-col">Correo</span>
      <span class="imp-col">Proyecto <em>o equipo</em></span>
      <span class="imp-col">Rol <em>opcional</em></span>
    </div>
    <small class="campo-ayuda">
      En la última columna puedes poner el <b>nombre de un proyecto</b> que ya exista
      (<?php
        $listaProy = array_map(fn($p) => $p['nombre'], soloProyectosVisibles((new ProyectoRepo())->todos()));
        echo $listaProy ? e(implode(' · ', array_slice($listaProy, 0, 4))) . (count($listaProy) > 4 ? '…' : '')
                        : 'todavía no hay proyectos';
      ?>) y esas personas quedan vinculadas a él al cargar. Si lo dejas vacío o el proyecto
      todavía no existe, entran igual como colaboradores y los asignas cuando quieras.
      También acepta un equipo (<?= e(implode(' · ', array_map(fn($v) => $v[0], $equipos))) ?>).
    </small>

    <label class="respaldo-archivo imp-archivo" data-vacio="Elegir archivo .xlsx o .csv">
      <input type="file" name="archivo" accept=".xlsx,.csv,text/csv" required>
      <span><i class="fa-solid fa-file-arrow-up"></i> Elegir archivo .xlsx o .csv</span>
    </label>

    <small class="campo-ayuda">
      <i class="fa-solid fa-circle-info"></i> No se guarda nada al subirlo: primero verás el
      resumen de qué se crea y qué se actualiza.
    </small>

    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-eye"></i> Leer y revisar</button>
    </footer>
  </form>
</dialog>

<!-- Modal: nuevo colaborador -->
<dialog id="dlg-nuevo-miembro" class="dlg-meca dlg-persona">
  <form method="post" action="actions.php" class="dlg-form form-persona" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="miembro_crear">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-user-plus text-secondary"></i> Nuevo colaborador</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <?php camposPersona(false, $eq, $equipos); ?>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Agregar al equipo</button>
    </footer>
  </form>
</dialog>

<!-- Modal: editar colaborador (se rellena por JS) -->
<dialog id="dlg-editar-miembro" class="dlg-meca dlg-persona">
  <form method="post" action="actions.php" class="dlg-form form-persona" enctype="multipart/form-data">
    <input type="hidden" name="accion" value="miembro_editar">
    <input type="hidden" name="id" id="em-id">
    <header>
      <h3 class="font-display"><i class="fa-solid fa-user-pen text-secondary"></i> Editar colaborador</h3>
      <button type="button" class="dlg-close" onclick="this.closest('dialog').close()"><i class="fa-solid fa-xmark"></i></button>
    </header>
    <?php camposPersona(true, $eq, $equipos); ?>
    <footer>
      <button type="button" class="btn-outline btn-meca" onclick="this.closest('dialog').close()">Cancelar</button>
      <button type="submit" class="btn-primary btn-meca"><i class="fa-solid fa-check"></i> Guardar cambios</button>
    </footer>
  </form>
</dialog>

<!-- Roles sugeridos (parametrizables en Ajustes) -->
<datalist id="lista-roles">
  <?php foreach (Catalogo::roles() as $rol): ?>
  <option value="<?= e($rol) ?>"></option>
  <?php endforeach; ?>
</datalist>

<?php UI::fin(); ?>
