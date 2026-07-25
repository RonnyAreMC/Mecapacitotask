<?php
/**
 * Documentación: previsualizador de los estándares del equipo.
 * Los PDF ya vienen hechos (assets/docs/*.pdf); esta página solo los muestra
 * y ofrece la descarga en PDF y en MD (para pegar/guardar como CLAUDE.md).
 * La ven todos los colaboradores.
 */
require_once __DIR__ . '/lib/bootstrap.php';

$docs = [
    [
        'clave' => 'estandar',
        'titulo' => 'Estándar del equipo',
        'desc'  => 'Cómo escribimos tareas y commits para que el tablero se enlace y avance solo.',
        'icono' => 'fa-diagram-project',
        'pdf'   => 'assets/docs/mchub-estandar.pdf',
        'md'    => 'assets/docs/mchub-estandar.md',
    ],
    [
        'clave' => 'guia-claude',
        'titulo' => 'Guía para Claude',
        'desc'  => 'Lo que cada dev ingresa en su proyecto (o guarda como CLAUDE.md) para coordinarse.',
        'icono' => 'claude',
        'pdf'   => 'assets/docs/mchub-guia-claude.pdf',
        'md'    => 'assets/docs/mchub-guia-claude.md',
    ],
];

UI::inicio('Documentación', 'docs');
UI::cabecera(
    '<span class="text-secondary">Documentación</span> del equipo',
    'Los estándares para que todos trabajemos igual y el tablero se automatice. Descárgalos o pásalos a tu Claude.'
);
?>

<div class="docs-layout">
  <!-- Lista de documentos -->
  <aside class="docs-lista">
    <?php foreach ($docs as $i => $d): ?>
    <button type="button" class="doc-item <?= $i === 0 ? 'activo' : '' ?>"
            data-pdf="<?= e($d['pdf']) ?>" data-titulo="<?= e($d['titulo']) ?>">
      <span class="doc-ico">
        <?php if ($d['icono'] === 'claude'): ?>
          <img src="assets/claude.svg" alt="Claude" width="22" height="22">
        <?php else: ?>
          <i class="fa-solid <?= e($d['icono']) ?>"></i>
        <?php endif; ?>
      </span>
      <span class="doc-txt">
        <b><?= e($d['titulo']) ?></b>
        <small><?= e($d['desc']) ?></small>
      </span>
    </button>
    <?php endforeach; ?>

    <div class="doc-descargas">
      <span class="doc-desc-lbl">Descargar</span>
      <?php foreach ($docs as $d): ?>
      <div class="doc-desc-fila">
        <span class="doc-desc-nom"><?= e($d['titulo']) ?></span>
        <a class="doc-btn" href="<?= e($d['pdf']) ?>" download title="Descargar PDF"><i class="fa-solid fa-file-pdf"></i> PDF</a>
        <a class="doc-btn doc-btn-md" href="<?= e($d['md']) ?>" download title="Descargar Markdown (CLAUDE.md)"><i class="fa-brands fa-markdown"></i> MD</a>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="doc-claude-nota">
      <img src="assets/claude.svg" alt="" width="20" height="20">
      <span>Guarda la <b>Guía para Claude</b> como <code>CLAUDE.md</code> en la raíz de tu repo y se aplica solo.</span>
    </div>
  </aside>

  <!-- Previsualizador -->
  <section class="card-base docs-visor">
    <div class="docs-visor-top">
      <b id="doc-titulo" class="font-display"><?= e($docs[0]['titulo']) ?></b>
      <div class="docs-visor-acc">
        <a id="doc-abrir" class="btn-outline btn-meca btn-sm" href="<?= e($docs[0]['pdf']) ?>" target="_blank" rel="noopener">
          <i class="fa-solid fa-up-right-from-square"></i> Abrir en pestaña
        </a>
        <a id="doc-bajar" class="btn-primary btn-meca btn-sm" href="<?= e($docs[0]['pdf']) ?>" download>
          <i class="fa-solid fa-download"></i> Descargar PDF
        </a>
      </div>
    </div>
    <iframe id="doc-frame" class="docs-frame" src="<?= e($docs[0]['pdf']) ?>#toolbar=1&navpanes=0" title="Vista del documento"></iframe>
  </section>
</div>

<script>
  document.querySelectorAll('.doc-item').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.doc-item').forEach((b) => b.classList.remove('activo'));
      btn.classList.add('activo');
      const pdf = btn.dataset.pdf;
      document.getElementById('doc-frame').src = pdf + '#toolbar=1&navpanes=0';
      document.getElementById('doc-titulo').textContent = btn.dataset.titulo;
      document.getElementById('doc-abrir').href = pdf;
      document.getElementById('doc-bajar').href = pdf;
    });
  });
</script>

<?php UI::fin(); ?>
