<?php
declare(strict_types=1);

require __DIR__ . '/../comun/arranque.php';

$u = exigir_perfil(['cesar']);

$errores = [];
$p = ['codigo' => '', 'titulo' => '', 'tarifa' => 'academica', 'responsable' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    [$p, $errores] = validar_proyecto($_POST, $u);
    if ($errores === []) {
        $error = crear_proyecto($p);
        if ($error === '') {
            redirigir('proyecto.php?codigo=' . urlencode($p['codigo']));
        }
        $errores['codigo'] = $error;
    }
}

/** Mensaje de error del servidor para un campo, si lo hay. */
function error_de(array $errores, string $campo): string
{
    return isset($errores[$campo])
        ? '<p class="error" id="' . $campo . '-servidor"><span aria-hidden="true">⚠</span> ' . e($errores[$campo]) . '</p>'
        : '';
}

/**
 * Atributos ARIA de un campo: su pista siempre y, si el servidor lo rechazó, aria-invalid y el
 * mensaje. data-servidor deja que panel.js lo desenganche en cuanto la persona lo corrige.
 */
function aria_de(array $errores, string $campo, bool $con_pista = true): string
{
    $ids = $con_pista ? [$campo . '-pista'] : [];
    if (!isset($errores[$campo])) {
        return $ids ? 'aria-describedby="' . implode(' ', $ids) . '"' : '';
    }
    $ids[] = $campo . '-servidor';
    return 'aria-invalid="true" data-servidor="' . $campo . '-servidor" aria-describedby="' . implode(' ', $ids) . '"';
}

cabecera('Nuevo proyecto', $u,
    'Da de alta un proyecto para que su responsable pueda formar el equipo y los trabajos empiecen a cobrarse a él.');
?>
<div class="rejilla-ayuda">
  <form method="post" class="tarjeta formulario">
    <?php if ($errores !== []): ?>
      <p class="alerta" role="alert"><span aria-hidden="true">⚠</span> Revisa <?= count($errores) === 1 ? 'el campo marcado' : 'los ' . count($errores) . ' campos marcados' ?>: no se ha guardado nada.</p>
    <?php endif ?>

    <div class="campo">
      <label for="codigo">Código</label>
      <p id="codigo-pista" class="pista">Tres letras mayúsculas, un guion y cuatro cifras: BIO-0003.</p>
      <input id="codigo" name="codigo" value="<?= e($p['codigo']) ?>" required pattern="[A-Z]{3}-[0-9]{4}" maxlength="8"
             autocomplete="off" spellcheck="false" autocapitalize="characters" data-error="codigo-error" <?= aria_de($errores, 'codigo') ?>>
      <p id="codigo-error" class="error-msg"><span aria-hidden="true">⚠</span> El código tiene que ser como BIO-0003.</p>
      <?= error_de($errores, 'codigo') ?>
    </div>

    <div class="campo">
      <label for="titulo">Título</label>
      <p id="titulo-pista" class="pista">Entre 5 y 120 caracteres.</p>
      <input id="titulo" name="titulo" value="<?= e($p['titulo']) ?>" required minlength="5" maxlength="120"
             autocomplete="off" data-error="titulo-error" <?= aria_de($errores, 'titulo') ?>>
      <p id="titulo-error" class="error-msg"><span aria-hidden="true">⚠</span> El título necesita entre 5 y 120 caracteres.</p>
      <?= error_de($errores, 'titulo') ?>
    </div>

    <div class="campo">
      <label for="tarifa">Tarifa</label>
      <select id="tarifa" name="tarifa" <?= aria_de($errores, 'tarifa', false) ?>>
        <?php foreach (TARIFAS as $clave => $nombre): ?>
        <option value="<?= e($clave) ?>" <?= $p['tarifa'] === $clave ? 'selected' : '' ?>><?= e($nombre) ?> · <?= e(formato_precio($clave)) ?>/núcleo-hora</option>
        <?php endforeach ?>
      </select>
      <?= error_de($errores, 'tarifa') ?>
    </div>

    <div class="campo">
      <label for="responsable">Responsable</label>
      <p id="responsable-pista" class="pista">Será quien añada y quite investigadores.</p>
      <select id="responsable" name="responsable" <?= aria_de($errores, 'responsable') ?>>
        <?php foreach (usuarios_con_perfil('responsable') as $r): ?>
        <option value="<?= e($r['usuario']) ?>" <?= $p['responsable'] === $r['usuario'] ? 'selected' : '' ?>><?= e($r['nombre']) ?></option>
        <?php endforeach ?>
      </select>
      <?= error_de($errores, 'responsable') ?>
    </div>

    <button class="ancho">Dar de alta</button>
  </form>

  <aside class="ayuda" aria-labelledby="titulo-antes">
    <h2 id="titulo-antes">Antes de dar de alta</h2>
    <dl class="glosario">
      <dt>Código</dt><dd>Identifica el proyecto en las facturas y no se puede repetir. Las tres
        letras indican el área: <code>BIO</code> biocomputación, <code>FIS</code> física…</dd>
      <dt>Tarifa</dt><dd><strong>Académica</strong> para proyectos de investigación con financiación
        pública; <strong>empresa</strong> para encargos y convenios con entidades privadas.</dd>
      <dt>Responsable</dt><dd>Solo aparecen las personas con perfil de responsable. Si falta
        alguien, hay que darle ese perfil en su cuenta antes.</dd>
    </dl>
    <p class="pequeno">Al guardar, el proyecto se abre sin equipo. Su responsable lo verá en su
      portada y podrá añadir a los investigadores.</p>
  </aside>
</div>
<?php
pie();
