<?php
/**
 * Piezas de pantalla de la firma de documentos, compartidas por firmas.php,
 * firma.php y perfil.php. Los estilos están en assets/firmas.css y lo que se
 * mueve (firmantes, lienzo de la firma) en assets/firmas.js.
 */
require_once __DIR__ . '/Firmas.php';

final class FirmasUI
{
    public static function estado(array $doc, bool $chico = true): string
    {
        return '<span class="mc-status' . ($chico ? ' mc-status--sm' : '') . ' mc-status--' . Firmas::tono($doc) . ' fd-estado-chip">'
            . '<span class="mc-status__dot"></span>' . e(Firmas::rotulo($doc)) . '</span>';
    }

    /** [texto, tono] de un firmante: Firmó, Le toca, Pendiente, Rechazó. */
    public static function estadoFirmante(array $doc, int $i): array
    {
        $f = $doc['firmantes'][$i] ?? [];
        return match (true) {
            ($f['estado'] ?? '') === 'firmado'   => [(($f['tipo'] ?? '') === 'electronica' ? 'Firmó con certificado · ' : 'Firmó · ') . Firmas::fecha((string)$f['fecha']), 'success'],
            ($f['estado'] ?? '') === 'rechazado' => ['Observó · ' . Firmas::fecha((string)$f['fecha']), 'danger'],
            Firmas::turno($doc) === $i           => ['Le toca', 'warning'],
            default                              => ['Pendiente', 'neutral'],
        };
    }

    /** Las personas del equipo para la lista: con su foto o iniciales y su cargo como segunda línea. */
    public static function opcionesPersonas(): string
    {
        $h = '<option value="">Elegir persona…</option>';
        foreach ((new MiembroRepo())->todos() as $m) {
            $h .= '<option value="' . (int)$m['id'] . '" data-desc="' . e(Firmas::cargoDe($m)) . '" data-ini="' . e(MiembroRepo::iniciales($m))
                . '" data-color="' . e(Catalogo::colorDe($m['color'] ?? 0)) . '"' . (!empty($m['foto']) ? ' data-foto="' . e((string)$m['foto']) . '"' : '')
                . '>' . e((string)$m['nombre']) . '</option>';
        }
        return $h;
    }

    /** Crear (sin $doc) o editar un documento: título, PDF y firmantes en orden. */
    public static function modalDocumento(?array $doc, array $yo): string
    {
        $nuevo = $doc === null;
        $id = $nuevo ? 'dlg-doc-nuevo' : 'dlg-doc-editar';
        $firmantes = $nuevo
            ? Firmas::cadenaPropuesta($yo)
            : array_map(fn($f) => ['uid' => (int)$f['uid'], 'rol' => (string)$f['rol'], 'cargo' => (string)$f['cargo']], $doc['firmantes'] ?? []);
        $conFirmas = !$nuevo && ($doc['estado'] ?? '') === 'en_firma' && Firmas::firmados($doc) > 0;
        $roles = '';
        foreach (Firmas::ROLES as $r) $roles .= '<option value="' . e($r) . '">' . e($r) . '</option>';
        $roles .= '<option value="__otro">Otro…</option>';
        ob_start();
        ?>
<dialog class="ds-modal fd-dlg fd-dlg--lg" id="<?= $id ?>" aria-labelledby="<?= $id ?>-t">
  <form method="post" action="actions.php" enctype="multipart/form-data" class="ds fd-form" data-fd-form novalidate>
    <input type="hidden" name="accion" value="<?= $nuevo ? 'firma_doc_crear' : 'firma_doc_editar' ?>">
    <?php if (!$nuevo): ?><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>"><?php endif; ?>
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--petroleo"><?= UI::iconoDS('firma', 18) ?></span>
      <div><h2 class="ds-modal__title" id="<?= $id ?>-t"><?= $nuevo ? 'Nuevo documento para firmar' : 'Editar el documento' ?></h2>
        <p class="ds-modal__sub"><?= $nuevo
            ? 'Sube el PDF y elige quién firma y en qué orden. A cada uno le llega el aviso cuando le toca.'
            : 'Cambia el título o los firmantes, o sube la versión corregida del PDF.' ?></p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body fd-cuerpo">
      <?php if ($conFirmas): ?>
      <div class="mc-alert mc-alert--warning" role="status"><?= UI::iconoDS('alerta', 20, 'mc-alert__icon') ?>
        <div class="mc-alert__content"><p class="mc-alert__title">Ya hay firmas en este documento</p>
          <p class="mc-alert__desc">Si cambias los firmantes o subes otro PDF, esas firmas se anulan y el flujo empieza de nuevo. Queda en el historial. Cambiar solo el título no las toca.</p></div></div>
      <?php endif; ?>
      <div class="mc-field"><label class="mc-field__label" for="<?= $id ?>-tit">Título</label>
        <div class="mc-input"><input class="mc-input__control" id="<?= $id ?>-tit" name="titulo" required maxlength="160" autocomplete="off"
          value="<?= e((string)($doc['titulo'] ?? '')) ?>" placeholder="Ej.: Informe de desarrolladores · noviembre 2026"></div></div>
      <div class="mc-field"><span class="mc-field__label" id="<?= $id ?>-pdf-l"><?= $nuevo ? 'PDF' : 'Versión corregida (opcional)' ?></span>
        <label class="fd-archivo" data-fd-archivo>
          <input type="file" name="pdf" accept="application/pdf,.pdf" class="mc-sr-only" aria-labelledby="<?= $id ?>-pdf-l"<?= $nuevo ? ' required' : '' ?>>
          <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('documento', 18) ?></span>
          <span class="fd-archivo__txt"><b class="truncate" data-fd-archivo-nombre><?= $nuevo ? 'Elige el PDF que se va a firmar' : e((string)$doc['archivo']) ?></b>
            <small><?= $nuevo ? 'Hasta ' . Firmas::MAX_MB . ' MB. Si es un Word, guárdalo antes como PDF.' : 'Si eliges otro PDF, reemplaza al actual.' ?></small></span>
          <span class="mc-btn mc-btn--secondary mc-btn--sm fd-archivo__btn" aria-hidden="true"><span class="mc-btn__label"><?= $nuevo ? 'Elegir' : 'Cambiar' ?></span></span>
        </label></div>
      <div class="fd-firmantes" data-fd-firmantes data-max="<?= Firmas::MAX_FIRMANTES ?>"
           data-iniciales="<?= e(json_encode($firmantes, JSON_UNESCAPED_UNICODE)) ?>" data-roles="<?= e(json_encode(Firmas::ROLES, JSON_UNESCAPED_UNICODE)) ?>">
        <div class="fd-firmantes__cab">
          <span class="mc-field__label">Quién firma y en qué orden</span>
          <small class="mc-field__hint">Firman de arriba hacia abajo. El cargo es el que sale en el cuadro de firmas.</small>
        </div>
        <?php if ($coords = Firmas::coordinaciones()):
            $datosCoord = array_map(fn($c) => ['id' => $c['id'], 'nombre' => $c['nombre'], 'firmantes' => Firmas::revisoresDe($c, (int)$yo['id'])], $coords);
            $opc = ['' => 'Elegir coordinación…'];
            foreach ($coords as $c) $opc[$c['id']] = [$c['nombre'], implode(' → ', array_map(fn($rv) => Firmas::nombre($rv['uid']), $c['revisores']))]; ?>
        <div class="fd-coord" data-fd-coords="<?= e(json_encode($datosCoord, JSON_UNESCAPED_UNICODE)) ?>" data-fd-yo="<?= e(json_encode(['uid' => (int)$yo['id'], 'cargo' => Firmas::cargoDe($yo)], JSON_UNESCAPED_UNICODE)) ?>">
          <span class="fd-coord__lbl"><?= UI::iconoDS('usuarios', 16) ?> Usar la cadena de</span>
          <?= UI::selectDS('', $opc, '', ['tam' => 'sm', 'aria' => 'Usar la cadena de una coordinación', 'attrs' => 'data-fd-coord']) ?>
        </div>
        <?php endif; ?>
        <ol class="fd-lista" data-fd-lista></ol>
        <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm fd-agregar" data-fd-agregar><?= UI::iconoDS('mas', 16) ?><span class="mc-btn__label">Agregar firmante</span></button>
      </div>
      <?php if ($nuevo): ?>
      <?= UI::checkDS('enviar', true, 'Enviarlo a firmar ahora', 'Si lo desmarcas, queda como borrador y lo envías después.') ?>
      <?php endif; ?>
      <p class="fd-error" data-fd-error role="alert" hidden></p>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md" data-fd-enviar><?= UI::iconoDS($nuevo ? 'enviar' : 'check', 18) ?><span class="mc-btn__label"><?= $nuevo ? 'Crear documento' : 'Guardar cambios' ?></span></button>
    </footer>
  </form>
  <template data-fd-tpl>
    <li class="fd-fila">
      <div class="fd-fila__cab">
        <span class="fd-fila__n" data-fd-n>1</span>
        <span class="fd-fila__acc">
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-fd-subir aria-label="Subir en el orden"><?= UI::iconoDS('chevron-arriba', 16) ?></button>
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-fd-bajar aria-label="Bajar en el orden"><?= UI::iconoDS('chevron-abajo', 16) ?></button>
          <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-fd-quitar aria-label="Quitar a este firmante"><?= UI::iconoDS('eliminar', 16) ?></button>
        </span>
      </div>
      <div class="fd-fila__campos">
        <div class="mc-field"><span class="mc-field__label">Rol</span>
          <select data-ds-select data-ds-tam="md" data-fd-rol aria-label="Rol"><?= $roles ?></select>
          <div class="mc-input fd-otro" data-fd-otro hidden><input class="mc-input__control" data-fd-rol-otro maxlength="60" placeholder="Ej.: Validado por" aria-label="Otro rol"></div></div>
        <div class="mc-field"><span class="mc-field__label">Persona</span>
          <select data-ds-select data-ds-buscar data-ds-tam="md" data-fd-uid aria-label="Persona que firma"><?= self::opcionesPersonas() ?></select></div>
        <div class="mc-field fd-fila__cargo"><span class="mc-field__label">Cargo en el cuadro</span>
          <div class="mc-input"><input class="mc-input__control" data-fd-cargo maxlength="120" placeholder="Ej.: Jefa de Proyectos de Tecnología" aria-label="Cargo"></div></div>
      </div>
    </li>
  </template>
</dialog>
        <?php
        return (string)ob_get_clean();
    }

    /**
     * «Mi firma»: dibujarla o subir una foto. $volver: a dónde regresar al guardar.
     * $abrir: el atributo con el que se abren los modales en esa página.
     */
    public static function modalMiFirma(array $yo, string $volver): string
    {
        $tiene = Firmas::tieneFirma((int)$yo['id']);
        $v = $tiene ? (int)@filemtime(Firmas::rutaMiFirma((int)$yo['id'])) : 0;
        $cert = Firmas::certGuardado((int)$yo['id']);
        $tipo = $cert && !$tiene ? 'electronica' : 'grafica';
        ob_start();
        ?>
<dialog class="ds-modal fd-dlg fd-dlg--md" id="dlg-mi-firma" aria-labelledby="dlg-mi-firma-t">
  <form method="post" action="actions.php" enctype="multipart/form-data" class="ds" data-mf-form novalidate>
    <input type="hidden" name="accion" value="<?= $tipo === 'electronica' ? 'firma_cert_guardar' : 'firma_mia_guardar' ?>" data-mf-accion>
    <input type="hidden" name="volver" value="<?= e($volver) ?>">
    <input type="hidden" name="firma_png" data-mf-png>
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--petroleo"><?= UI::iconoDS('firma', 18) ?></span>
      <div><h2 class="ds-modal__title" id="dlg-mi-firma-t">Mi firma</h2>
        <p class="ds-modal__sub">Solo tú la usas, al pulsar «Firmar» en un documento. Puede ser tu firma gráfica o tu firma electrónica (.p12).</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body mf-cuerpo">
      <div class="mc-tabs mc-tabs--segmented mc-tabs--fitted fd-modos" role="tablist" aria-label="Tipo de firma">
        <button type="button" class="mc-tab<?= $tipo === 'grafica' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $tipo === 'grafica' ? 'true' : 'false' ?>" data-mf-tipo="grafica"><?= UI::iconoDS('firma', 16) ?><span class="mc-tab__label">Firma gráfica</span></button>
        <button type="button" class="mc-tab<?= $tipo === 'electronica' ? ' is-active' : '' ?>" role="tab" aria-selected="<?= $tipo === 'electronica' ? 'true' : 'false' ?>" data-mf-tipo="electronica"><?= UI::iconoDS('certificado', 16) ?><span class="mc-tab__label">Firma electrónica</span></button>
      </div>
      <div class="mf-tipo" data-mf-tipo-panel="grafica"<?= $tipo === 'grafica' ? '' : ' hidden' ?>>
      <?php if ($tiene): ?>
      <div class="mf-actual">
        <span class="mf-actual__img"><img src="firma_archivo.php?mia=1&amp;v=<?= $v ?>" alt="Tu firma guardada"></span>
        <span class="mf-actual__txt"><b>Tu firma guardada</b><small>Dibuja o sube otra para cambiarla.</small></span>
        <button type="submit" form="f-quitar-firma" class="mc-btn mc-btn--ghost mc-btn--sm"><span class="mc-btn__label">Quitar</span></button>
      </div>
      <?php endif; ?>
      <div class="mc-tabs mc-tabs--segmented mc-tabs--sm mf-modos" role="tablist" aria-label="Cómo cargar la firma">
        <button type="button" class="mc-tab is-active" role="tab" aria-selected="true" data-mf-modo="dibujar"><?= UI::iconoDS('editar', 16) ?><span class="mc-tab__label">Dibujarla</span></button>
        <button type="button" class="mc-tab" role="tab" aria-selected="false" tabindex="-1" data-mf-modo="subir"><?= UI::iconoDS('importar', 16) ?><span class="mc-tab__label">Subir una foto</span></button>
      </div>
      <div class="mf-panel" data-mf-panel="dibujar">
        <div class="mf-lienzo">
          <canvas data-mf-canvas role="img" aria-label="Espacio para dibujar tu firma"></canvas>
          <span class="mf-lienzo__linea" aria-hidden="true"></span>
          <span class="mf-lienzo__ayuda" data-mf-ayuda aria-hidden="true">Firma aquí con el mouse, el lápiz o el dedo</span>
        </div>
        <div class="mf-herr">
          <span class="mf-tintas" role="radiogroup" aria-label="Color de la tinta">
            <button type="button" class="mf-tinta is-on" role="radio" aria-checked="true" data-mf-tinta="#1B2A6B" style="--t:#1B2A6B" aria-label="Tinta azul"></button>
            <button type="button" class="mf-tinta" role="radio" aria-checked="false" data-mf-tinta="#111418" style="--t:#111418" aria-label="Tinta negra"></button>
          </span>
          <button type="button" class="mc-btn mc-btn--ghost mc-btn--sm" data-mf-borrar><?= UI::iconoDS('reintentar', 16) ?><span class="mc-btn__label">Borrar y empezar de nuevo</span></button>
        </div>
      </div>
      <div class="mf-panel" data-mf-panel="subir" hidden>
        <label class="fd-archivo" data-mf-archivo>
          <input type="file" name="firma_archivo" accept="image/png,image/jpeg,image/webp" class="mc-sr-only" aria-label="Foto o escaneo de tu firma">
          <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('importar', 18) ?></span>
          <span class="fd-archivo__txt"><b class="truncate" data-mf-archivo-nombre>Elige una foto o un escaneo</b>
            <small>Firma con tinta oscura sobre papel blanco. El fondo lo quitamos nosotros.</small></span>
          <span class="mc-btn mc-btn--secondary mc-btn--sm fd-archivo__btn" aria-hidden="true"><span class="mc-btn__label">Elegir</span></span>
        </label>
        <div class="mf-vista" data-mf-vista hidden><img alt="Vista previa de la foto elegida"></div>
      </div>
      </div>

      <div class="mf-tipo" data-mf-tipo-panel="electronica"<?= $tipo === 'electronica' ? '' : ' hidden' ?>>
        <?php if ($cert): ?>
        <div class="mf-actual">
          <span class="mc-tile mc-tile--sm mc-tile--verde"><?= UI::iconoDS('certificado', 18) ?></span>
          <span class="mf-actual__txt"><b class="truncate"><?= e((string)($cert['cn'] ?? 'Tu certificado')) ?></b>
            <small><?= e(implode(' · ', array_filter([($cert['emisor'] ?? '') !== '' ? 'Emitido por ' . $cert['emisor'] : '', ($cert['vence'] ?? '') !== '' ? 'vence el ' . date('d/m/Y', strtotime((string)$cert['vence'])) : '', (string)($cert['archivo'] ?? '')]))) ?></small></span>
          <button type="submit" form="f-olvidar-cert-mf" class="mc-btn mc-btn--ghost mc-btn--sm"><span class="mc-btn__label">Olvidar</span></button>
        </div>
        <?php endif; ?>
        <div class="mc-field"><span class="mc-field__label"><?= $cert ? 'Cambiar por otro archivo (.p12)' : 'Archivo de firma electrónica (.p12)' ?></span>
          <label class="fd-archivo" data-fd-archivo>
            <input type="file" name="p12" accept=".p12" class="mc-sr-only" data-mf-p12 aria-label="Archivo de firma electrónica">
            <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('certificado', 18) ?></span>
            <span class="fd-archivo__txt"><b class="truncate" data-fd-archivo-nombre>Elige tu archivo .p12</b>
              <small>El que te entregó tu entidad certificadora (Banco Central, Security Data, ANF…).</small></span>
            <span class="mc-btn mc-btn--secondary mc-btn--sm fd-archivo__btn" aria-hidden="true"><span class="mc-btn__label">Cargar</span></span>
          </label></div>
        <div class="mc-field"><label class="mc-field__label" for="mf-clave-cert">Contraseña de tu firma</label>
          <div class="mc-input"><input class="mc-input__control" id="mf-clave-cert" type="password" name="clave_cert" autocomplete="off" data-mf-clave placeholder="Para comprobar que el archivo es tuyo"></div>
          <span class="mc-field__hint">Solo la usamos para comprobar el archivo y su vigencia; no se guarda. El archivo queda cifrado hasta que cierres sesión; al firmar, eliges «Firma electrónica» y escribes tu contraseña.</span></div>
      </div>
      <p class="fd-error" data-mf-error role="alert" hidden></p>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--ghost mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Guardar firma</span></button>
    </footer>
  </form>
  <form id="f-olvidar-cert-mf" method="post" action="actions.php" hidden
        data-confirmar="Se borra tu archivo de firma electrónica de esta sesión. Para firmar con ella tendrás que cargarlo de nuevo." data-confirmar-titulo="¿Olvidar tu firma electrónica?" data-confirmar-ok="Sí, olvidar">
    <input type="hidden" name="accion" value="firma_cert_olvidar"><input type="hidden" name="volver" value="<?= e($volver) ?>">
  </form>
  <form id="f-quitar-firma" method="post" action="actions.php" hidden
        data-confirmar="No podrás firmar hasta que guardes otra. Los documentos que ya firmaste la conservan." data-confirmar-titulo="¿Quitar tu firma?" data-confirmar-ok="Sí, quitar">
    <input type="hidden" name="accion" value="firma_mia_quitar">
  </form>
</dialog>
        <?php
        return (string)ob_get_clean();
    }

    /** Configuración de los informes semanales (solo el administrador). */
    public static function modalInformes(): string
    {
        require_once __DIR__ . '/InformeSemanal.php';
        $c = InformeSemanal::conf();
        $miembros = (new MiembroRepo())->todos();
        // Si todavía no se eligió a quién avisar, se propone a Felipe (quien da seguimiento)
        $avisar = $c['avisar'] ?: array_map(fn($m) => (int)$m['id'], array_filter($miembros, fn($m) => stripos((string)$m['nombre'], 'felipe') === 0));
        $persona = function (string $name, array $m, bool $on): string {
            return '<label class="fi-persona' . ($on ? ' is-on' : '') . '"><input type="checkbox" name="' . $name . '" value="' . (int)$m['id'] . '"' . ($on ? ' checked' : '') . ' class="mc-sr-only">'
                . UI::avatar($m, 28) . '<span class="fi-persona__txt"><b class="truncate">' . e((string)$m['nombre']) . '</b><small class="truncate">' . e((string)($m['rol'] ?? '')) . '</small></span>'
                . '<span class="fi-persona__check" aria-hidden="true">' . UI::iconoDS('check', 14) . '</span></label>';
        };
        $opciones = function (int $sel): string {
            return $sel > 0 ? str_replace('value="' . $sel . '"', 'value="' . $sel . '" selected', self::opcionesPersonas()) : self::opcionesPersonas();
        };
        $rev = $c['revisores'] + [['uid' => 0, 'rol' => 'Revisado por', 'cargo' => ''], ['uid' => 0, 'rol' => 'Aprobado por', 'cargo' => '']];
        if (count($c['revisores']) === 1) $rev[1] = ['uid' => 0, 'rol' => 'Aprobado por', 'cargo' => ''];
        ob_start();
        ?>
<dialog class="ds-modal fd-dlg fd-dlg--lg" id="dlg-informes" aria-labelledby="dlg-informes-t">
  <form method="post" action="actions.php" class="ds" data-fi-form>
    <input type="hidden" name="accion" value="informes_conf">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('reportes', 18) ?></span>
      <div><h2 class="ds-modal__title" id="dlg-informes-t">Informes semanales</h2>
        <p class="ds-modal__sub">Cada semana el Hub arma el informe de actividades de cada persona con lo que registró. Lo revisa, lo firma y sigue a quienes elijas.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body fi-cuerpo">
      <?= UI::checkDS('activo', $c['activo'], 'Armar los informes cada semana', 'Si lo apagas, no se arman solos; igual puedes generarlos a mano.') ?>
      <div class="ds-modal__fila">
        <div class="mc-field"><span class="mc-field__label">Día</span>
          <?= UI::selectDS('dia', array_map('strval', InformeSemanal::DIAS), (string)$c['dia'], ['tam' => 'md', 'aria' => 'Día de la semana']) ?></div>
        <div class="mc-field"><label class="mc-field__label" for="fi-hora">Hora</label>
          <div class="mc-input"><input class="mc-input__control" id="fi-hora" type="time" name="hora" value="<?= e($c['hora']) ?>" required></div></div>
      </div>
      <div class="mc-field"><span class="mc-field__label">Quiénes hacen informe</span>
        <span class="mc-field__hint">A cada uno le llega el aviso cuando su informe está listo para revisar y firmar.</span>
        <div class="fi-personas"><?php foreach ($miembros as $m) echo $persona('personas[]', $m, in_array((int)$m['id'], $c['personas'], true)); ?></div></div>
      <div class="mc-field"><span class="mc-field__label">Si la persona no está en ninguna coordinación, lo firman</span>
        <span class="mc-field__hint">Lo normal es que lo firmen los revisores de su coordinación (pestaña «Coordinaciones»). Puede ser una sola persona: deja la segunda sin elegir.</span>
        <?php foreach ([0, 1] as $i): $r = $rev[$i]; ?>
        <div class="fi-rev">
          <span class="fd-fila__n"><?= $i + 2 ?></span>
          <div class="mc-input"><input class="mc-input__control" name="revisores[<?= $i ?>][rol]" value="<?= e($r['rol']) ?>" maxlength="60" aria-label="Rol"></div>
          <select data-ds-select data-ds-buscar data-ds-tam="md" name="revisores[<?= $i ?>][uid]" aria-label="Quién firma"><?= str_replace('Elegir persona…', $i ? 'Nadie más' : 'Elegir persona…', $opciones((int)$r['uid'])) ?></select>
          <div class="mc-input"><input class="mc-input__control" name="revisores[<?= $i ?>][cargo]" value="<?= e($r['cargo']) ?>" maxlength="120" placeholder="Cargo en el cuadro" aria-label="Cargo"></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="mc-field"><span class="mc-field__label">Cuando quede firmado, avisar a</span>
        <span class="mc-field__hint">Le llega en la campana y por correo, y puede ver y descargar el informe.</span>
        <div class="fi-personas fi-personas--chico"><?php foreach ($miembros as $m) echo $persona('avisar[]', $m, in_array((int)$m['id'], $avisar, true)); ?></div></div>
      <?= UI::checkDS('ia', $c['ia'], 'Redactar el resumen con IA', 'Usa la consola de IA del Hub. Sin IA, el resumen se arma con los datos y cada persona lo completa.') ?>
      <div class="fi-ahora">
        <span><b>¿Los necesitas hoy?</b> Arma ya los de esta semana (los que falten).</span>
        <button type="submit" form="f-informes-ahora" class="mc-btn mc-btn--ghost mc-btn--sm"><?= UI::iconoDS('ejecutar', 16) ?><span class="mc-btn__label">Generar ahora</span></button>
      </div>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--secondary mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Guardar</span></button>
    </footer>
  </form>
  <form id="f-informes-ahora" method="post" action="actions.php" hidden data-confirmar="Se arman los informes de esta semana de quienes todavía no lo tienen, y a cada uno le avisamos. Puede tardar un poco si usa la IA." data-confirmar-titulo="¿Generar los informes ahora?" data-confirmar-ok="Sí, generar">
    <input type="hidden" name="accion" value="informes_generar">
  </form>
</dialog>
        <?php
        return (string)ob_get_clean();
    }

    /** Corregir el informe semanal: resumen, otras actividades, pendientes y observaciones. */
    public static function modalInforme(array $doc): string
    {
        $c = (array)($doc['contenido'] ?? []);
        $acts = (array)($c['actividades'] ?? []);
        $conFirmas = ($doc['estado'] ?? '') === 'en_firma' && Firmas::firmados($doc) > 0;
        ob_start();
        ?>
<dialog class="ds-modal fd-dlg fd-dlg--lg" id="dlg-informe" aria-labelledby="dlg-informe-t">
  <form method="post" action="actions.php" class="ds">
    <input type="hidden" name="accion" value="informe_editar"><input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('editar', 18) ?></span>
      <div><h2 class="ds-modal__title" id="dlg-informe-t">Corregir el informe</h2>
        <p class="ds-modal__sub">Semana <?= e((string)($c['semana'] ?? '')) ?>. Al guardar se vuelve a armar el PDF.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body">
      <?php if ($conFirmas): ?>
      <div class="mc-alert mc-alert--warning" role="status"><?= UI::iconoDS('alerta', 20, 'mc-alert__icon') ?>
        <div class="mc-alert__content"><p class="mc-alert__title">Ya hay firmas</p><p class="mc-alert__desc">Si lo corriges, las firmas se anulan y todos firman de nuevo.</p></div></div>
      <?php endif; ?>
      <?php if (!empty($c['ia'])): ?>
      <div class="mc-alert mc-alert--tip" role="status"><?= UI::iconoDS('ia', 20, 'mc-alert__icon') ?>
        <div class="mc-alert__content"><p class="mc-alert__desc">El resumen y los próximos pasos los redactó la IA con tus datos de la semana. Revísalos antes de firmar.</p></div></div>
      <?php endif; ?>
      <div class="mc-field"><label class="mc-field__label" for="fi-resumen">Resumen de la semana</label>
        <div class="mc-input mc-input--multiline"><textarea class="mc-input__control" id="fi-resumen" name="resumen" rows="5" maxlength="4000" required><?= e((string)($c['resumen'] ?? '')) ?></textarea></div></div>
      <div class="mc-field"><span class="mc-field__label">Actividades realizadas (del Hub)</span>
        <?php if ($acts): ?>
        <ul class="fi-acts"><?php foreach ($acts as $a): ?><li><span class="truncate" title="<?= e((string)$a['actividad']) ?>"><?= e((string)$a['actividad']) ?></span><small><?= e((string)$a['origen']) ?> · <?= e((string)$a['fecha']) ?></small></li><?php endforeach; ?></ul>
        <?php else: ?><span class="mc-field__hint">No cerraste tareas ni requerimientos en el Hub esta semana.</span><?php endif; ?></div>
      <div class="mc-field"><label class="mc-field__label" for="fi-extra">Otras actividades</label>
        <div class="mc-input mc-input--multiline"><textarea class="mc-input__control" id="fi-extra" name="extra" rows="3" placeholder="Una por línea. Ej.: Reunión con el cliente de la UAE para revisar el cronograma"><?= e(implode("\n", (array)($c['extra'] ?? []))) ?></textarea></div>
        <span class="mc-field__hint">Lo que hiciste y no está en el Hub. Van en la misma tabla.</span></div>
      <div class="mc-field"><label class="mc-field__label" for="fi-pend">En curso y próximos pasos</label>
        <div class="mc-input mc-input--multiline"><textarea class="mc-input__control" id="fi-pend" name="pendientes" rows="4" placeholder="Uno por línea"><?= e(implode("\n", (array)($c['pendientes'] ?? []))) ?></textarea></div></div>
      <div class="mc-field"><label class="mc-field__label" for="fi-obs">Observaciones</label>
        <div class="mc-input mc-input--multiline"><textarea class="mc-input__control" id="fi-obs" name="observaciones" rows="3" placeholder="Bloqueos, riesgos o lo que haga falta saber (opcional)"><?= e((string)($c['observaciones'] ?? '')) ?></textarea></div></div>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--secondary mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Guardar cambios</span></button>
    </footer>
  </form>
</dialog>
        <?php
        return (string)ob_get_clean();
    }

    /** Nueva coordinación (sin $c) o editar una: nombre, personas y revisores en orden. */
    public static function modalCoordinacion(?array $c): string
    {
        $nueva = $c === null;
        $id = $nueva ? 'dlg-coord-nueva' : 'dlg-coord-' . $c['id'];
        $miembros = (new MiembroRepo())->todos();
        $personas = $c['personas'] ?? [];
        $rev = array_values($c['revisores'] ?? []);
        while (count($rev) < 3) $rev[] = ['uid' => 0, 'rol' => ['Revisado por', 'Aprobado por', 'Visto bueno de'][count($rev)], 'cargo' => ''];
        ob_start();
        ?>
<dialog class="ds-modal fd-dlg fd-dlg--lg" id="<?= e($id) ?>" aria-labelledby="<?= e($id) ?>-t">
  <form method="post" action="actions.php" class="ds">
    <input type="hidden" name="accion" value="firma_coord_guardar"><input type="hidden" name="id" value="<?= e((string)($c['id'] ?? '')) ?>">
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--azul"><?= UI::iconoDS('usuarios', 18) ?></span>
      <div><h2 class="ds-modal__title" id="<?= e($id) ?>-t"><?= $nueva ? 'Nueva coordinación' : 'Editar «' . e($c['nombre']) . '»' ?></h2>
        <p class="ds-modal__sub">Quiénes están en la coordinación y quiénes revisan lo que ellos elaboran, en orden.</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body fi-cuerpo">
      <div class="mc-field"><label class="mc-field__label" for="<?= e($id) ?>-n">Nombre</label>
        <div class="mc-input"><input class="mc-input__control" id="<?= e($id) ?>-n" name="nombre" required maxlength="60" value="<?= e((string)($c['nombre'] ?? '')) ?>" placeholder="Ej.: Desarrollo, Procesos, Diseño"></div></div>
      <div class="mc-field"><span class="mc-field__label">Revisores, en orden</span>
        <span class="mc-field__hint">Firman después de quien elabora (el paso 1). Deja vacías las filas que no hagan falta. Si no escribes el cargo, se usa el de la pestaña «Cargos».</span>
        <?php foreach ($rev as $i => $rv): ?>
        <div class="fi-rev">
          <span class="fd-fila__n"><?= $i + 2 ?></span>
          <div class="mc-input"><input class="mc-input__control" name="revisores[<?= $i ?>][rol]" value="<?= e($rv['rol']) ?>" maxlength="60" aria-label="Rol del paso <?= $i + 2 ?>"></div>
          <select data-ds-select data-ds-buscar data-ds-tam="md" name="revisores[<?= $i ?>][uid]" aria-label="Quién firma en el paso <?= $i + 2 ?>"><?= str_replace('Elegir persona…', $i ? 'Nadie' : 'Elegir persona…', (int)$rv['uid'] > 0 ? str_replace('value="' . (int)$rv['uid'] . '"', 'value="' . (int)$rv['uid'] . '" selected', self::opcionesPersonas()) : self::opcionesPersonas()) ?></select>
          <div class="mc-input"><input class="mc-input__control" name="revisores[<?= $i ?>][cargo]" value="<?= e($rv['cargo']) ?>" maxlength="120" placeholder="Su cargo (opcional)" aria-label="Cargo del paso <?= $i + 2 ?>"></div>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="mc-field"><span class="mc-field__label">Personas de la coordinación</span>
        <span class="mc-field__hint">Sus informes y los documentos que crean proponen a estos revisores.</span>
        <div class="fi-personas"><?php foreach ($miembros as $m): $on = in_array((int)$m['id'], $personas, true); ?>
          <label class="fi-persona<?= $on ? ' is-on' : '' ?>"><input type="checkbox" name="personas[]" value="<?= (int)$m['id'] ?>"<?= $on ? ' checked' : '' ?> class="mc-sr-only">
            <?= UI::avatar($m, 28) ?><span class="fi-persona__txt"><b class="truncate"><?= e((string)$m['nombre']) ?></b><small class="truncate"><?= e(Firmas::cargoDe($m)) ?></small></span>
            <span class="fi-persona__check" aria-hidden="true"><?= UI::iconoDS('check', 14) ?></span></label>
        <?php endforeach; ?></div></div>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--secondary mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label"><?= $nueva ? 'Crear coordinación' : 'Guardar cambios' ?></span></button>
    </footer>
  </form>
</dialog>
        <?php
        return (string)ob_get_clean();
    }

    /** El cargo con el que firma una persona (lo llena assets/firmas.js con la fila elegida). */
    public static function modalCargo(): string
    {
        ob_start();
        ?>
<dialog class="ds-modal fd-dlg fd-dlg--md" id="dlg-cargo" aria-labelledby="dlg-cargo-t">
  <form method="post" action="actions.php" class="ds">
    <input type="hidden" name="accion" value="firma_cargo_guardar"><input type="hidden" name="uid" value="" data-fc-uid>
    <header class="ds-modal__head">
      <span class="mc-tile mc-tile--sm mc-tile--petroleo"><?= UI::iconoDS('insignia', 18) ?></span>
      <div><h2 class="ds-modal__title" id="dlg-cargo-t">Cargo para firmar</h2>
        <p class="ds-modal__sub" data-fc-nombre>—</p></div>
      <button type="button" class="mc-iconbtn mc-iconbtn--sm" data-cerrar-dlg aria-label="Cerrar"><?= UI::iconoDS('cerrar', 18) ?></button>
    </header>
    <div class="ds-modal__body">
      <div class="mc-field"><label class="mc-field__label" for="fc-cargo">Cargo</label>
        <div class="mc-input"><input class="mc-input__control" id="fc-cargo" name="cargo_firma" maxlength="120" data-fc-cargo placeholder="Ej.: Jefa de Proyectos de Tecnología e Innovación Académica"></div>
        <span class="mc-field__hint">Es el que sale bajo su nombre en el cuadro de firmas. Si lo dejas vacío, se usa su rol del Hub (<span data-fc-rol>—</span>).</span></div>
    </div>
    <footer class="ds-modal__foot">
      <button type="button" class="mc-btn mc-btn--secondary mc-btn--md" data-cerrar-dlg><span class="mc-btn__label">Cancelar</span></button>
      <button class="mc-btn mc-btn--primary mc-btn--md"><?= UI::iconoDS('check', 18) ?><span class="mc-btn__label">Guardar</span></button>
    </footer>
  </form>
</dialog>
        <?php
        return (string)ob_get_clean();
    }
}
