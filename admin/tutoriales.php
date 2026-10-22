<?php
/**
 * Tutoriales en video: cualquiera los ve en el reproductor del panel; el
 * administrador los publica pegando un enlace de YouTube o de Google Drive
 * (lib/Tutoriales.php) y elige si además aparecen en alguna pantalla.
 */
require_once __DIR__ . '/lib/bootstrap.php';
if (is_file(__DIR__ . '/lib/Tutoriales.php')) require_once __DIR__ . '/lib/Tutoriales.php';
Auth::requiereLogin();
if (!class_exists('Tutoriales')) {
    header('Location: index.php');
    exit;
}

$esAdmin = Auth::esAdmin();
$lista = Tutoriales::todos();
$acciones = $esAdmin
    ? '<button type="button" class="mc-btn mc-btn--success mc-btn--md" data-abrir-dlg="dlg-tutorial-nuevo">' . UI::iconoDS('mas', 18) . '<span class="mc-btn__label">Agregar tutorial</span></button>'
    : '';

UI::inicio('Tutoriales', 'tutoriales');
UI::cabecera('Tutoriales', 'Videos cortos para aprender a usar el panel. Se ven aquí mismo, sin salir de la página.', $acciones);
?>
<div class="ds" id="tutoriales-pagina">
  <?php if (!$lista): ?>
    <?= UI::vacioDS('nexo:leyendo', 'Todavía no hay tutoriales', $esAdmin
        ? 'Publica el primero con «Agregar tutorial»: pega el enlace de YouTube o de Google Drive y listo.'
        : 'Pronto vas a encontrar aquí videos cortos para aprender a usar el panel.') ?>
  <?php else: ?>
  <div class="tut-grid">
    <?php foreach ($lista as $t): ?>
    <article class="mc-card tut-card">
      <button type="button" class="tut-mini" <?= Tutoriales::datos($t) ?> aria-label="Ver «<?= e($t['titulo']) ?>»">
        <img src="<?= e(Tutoriales::miniatura($t)) ?>" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()">
        <span class="tut-play" aria-hidden="true"><?= UI::iconoDS('play', 26) ?></span>
        <span class="tut-proveedor"><?= $t['proveedor'] === 'youtube' ? 'YouTube' : 'Google Drive' ?></span>
      </button>
      <div class="tut-cuerpo">
        <h3><?= e($t['titulo']) ?></h3>
        <?php if (trim((string)($t['descripcion'] ?? '')) !== ''): ?><p><?= e($t['descripcion']) ?></p><?php endif; ?>
        <?php if (!empty($t['avisar'])): $al = Tutoriales::alcance((int)$t['id']); ?>
        <span class="tut-lugar"><?= UI::iconoDS('notificaciones', 14) ?> Avisando a todos<?= $esAdmin ? ' · lo vieron ' . (int)$al['vieron'] . ($al['cerraron'] ? ', lo cerraron ' . (int)$al['cerraron'] : '') : '' ?></span>
        <?php endif; ?>
      </div>
      <?php if ($esAdmin): ?>
      <div class="tut-acciones">
      <form method="post" action="actions.php">
        <input type="hidden" name="accion" value="tutorial_avisar"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <?php if (empty($t['avisar'])): ?><input type="hidden" name="avisar" value="1"><?php endif; ?>
        <button class="mc-btn mc-btn--ghost mc-btn--sm"><?= UI::iconoDS('notificaciones', 16) ?><span class="mc-btn__label"><?= empty($t['avisar']) ? 'Avisar a todos' : 'Dejar de avisar' ?></span></button>
      </form>
      <form method="post" action="actions.php" data-confirmar="Se quita del panel (el video sigue en YouTube o Drive)." data-confirmar-titulo="¿Quitar «<?= e($t['titulo']) ?>»?" data-confirmar-ok="Sí, quitar">
        <input type="hidden" name="accion" value="tutorial_quitar"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <button class="mc-btn mc-btn--ghost mc-btn--sm ia-quitar"><?= UI::iconoDS('eliminar', 16) ?><span class="mc-btn__label">Quitar</span></button>
      </form>
      </div>
      <?php endif; ?>
    </article>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($esAdmin): ?>
<dialog class="ds-modal" id="dlg-tutorial-nuevo" aria-labelledby="dlg-tut-t">
  <form method="post" action="actions.php" class="ds" data-tutorial-form>
    <input type="hidden" name="accion" value="tutorial_crear">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('video', 18) ?></span>
      <div><h2 class="ds-modal__title" id="dlg-tut-t">Agregar tutorial</h2>
        <p class="ds-modal__sub">Pega el enlace del video. En YouTube puede estar «No listado»; en Drive, compártelo como «Cualquier persona con el enlace».</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body ds-grid" style="gap:var(--space-4)">
      <div class="mc-field"><label class="mc-field__label" for="tut-enlace">Enlace de YouTube o de Google Drive</label>
        <div class="mc-input"><?= UI::iconoDS('enlace', 18, 'mc-input__icon') ?>
          <input class="mc-input__control" id="tut-enlace" name="enlace" required maxlength="500" autocomplete="off"
                 placeholder="https://youtu.be/… o https://drive.google.com/file/d/…" data-tutorial-enlace></div>
        <div class="mc-field__foot"><span class="mc-field__hint" data-tutorial-estado>Se acepta: youtube.com/watch, youtu.be, Shorts y archivos de Drive.</span></div>
      </div>
      <div class="tut-previa" data-tutorial-previa hidden></div>
      <div class="mc-field"><label class="mc-field__label" for="tut-titulo">Título</label>
        <div class="mc-input"><input class="mc-input__control" id="tut-titulo" name="titulo" required maxlength="120" placeholder="Ej.: Sincroniza tu correo InnoTech"></div></div>
      <div class="mc-field"><label class="mc-field__label" for="tut-desc">Descripción <small>(opcional)</small></label>
        <div class="mc-input mc-input--multiline"><textarea class="mc-input__control" id="tut-desc" name="descripcion" rows="3" maxlength="400" placeholder="Qué se aprende en este video"></textarea></div></div>
      <?= UI::checkDS('avisar', true, 'Avisar a todos', 'Al entrar al panel, a cada persona le sale este video en una ventana con su nombre, para verlo o cerrarlo. Sale una sola vez.') ?>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--success mc-btn--md" data-tutorial-guardar><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Publicar</span></button>
    </footer>
  </form>
</dialog>
<script>
// Corre al terminar de cargar: assets/ds.js (DSTutorial) va al final de la página
document.addEventListener('DOMContentLoaded', () => {
  // Abrir el modal de «Agregar tutorial»
  document.querySelectorAll('[data-abrir-dlg]').forEach((b) => b.addEventListener('click', () => document.getElementById(b.dataset.abrirDlg)?.showModal()));
  // Revisa el enlace mientras se pega: dice si sirve y muestra la vista previa
  const form = document.querySelector('[data-tutorial-form]');
  if (!form || !window.DSTutorial) return;
  const campo = form.querySelector('[data-tutorial-enlace]');
  const estado = form.querySelector('[data-tutorial-estado]');
  const previa = form.querySelector('[data-tutorial-previa]');
  const guardar = form.querySelector('[data-tutorial-guardar]');
  const ayuda = estado.textContent;
  const revisar = () => {
    const v = campo.value.trim();
    if (!v) { estado.textContent = ayuda; estado.classList.remove('is-error', 'is-ok'); previa.hidden = true; guardar.disabled = false; return; }
    const r = DSTutorial.analizar(v);
    estado.classList.toggle('is-error', !!r.error);
    estado.classList.toggle('is-ok', !r.error);
    guardar.disabled = !!r.error;
    if (r.error) { estado.textContent = r.error; previa.hidden = true; return; }
    estado.textContent = r.proveedor === 'youtube' ? 'Video de YouTube: listo para publicar.' : 'Archivo de Google Drive: revisa que esté compartido con «Cualquier persona con el enlace».';
    previa.hidden = false;
    previa.innerHTML = '';
    const img = new Image();
    img.alt = '';
    img.referrerPolicy = 'no-referrer';
    img.src = r.proveedor === 'youtube' ? 'https://i.ytimg.com/vi/' + r.id + '/hqdefault.jpg' : 'https://drive.google.com/thumbnail?id=' + r.id + '&sz=w640';
    img.onerror = () => img.remove();
    previa.append(img);
  };
  campo.addEventListener('input', revisar);
  campo.addEventListener('paste', () => setTimeout(revisar, 0));
  revisar();
});
</script>
<?php endif; ?>
<?php UI::fin(); ?>
