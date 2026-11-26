<?php
declare(strict_types=1);

require __DIR__ . '/../comun/arranque.php';

$u = exigir_login();
$p = buscar_proyecto((string) ($_GET['codigo'] ?? ''));

// Aislamiento (RA5 i): si no existe o no es suyo, lo mismo. ¿Por qué no un 403 cuando no es suyo?
if ($p === null || !puede_ver($u, $p)) {
    pagina_error(404, 'No hay ningún proyecto con ese código entre los tuyos.');
}

// Añadir o quitar investigadores: solo su responsable o el CESAR.
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!puede_gestionar($u, $p)) {
        pagina_error(403, 'Solo el responsable del proyecto o el CESAR cambian sus miembros.');
    }
    $error = gestionar_miembros($p, $_POST);
    if ($error === '') {
        redirigir('proyecto.php?codigo=' . urlencode($p['codigo']));  // PRG: recargar no repite el cambio
    }
}

$propio = $u['perfil'] === 'investigador';
$gestiona = puede_gestionar($u, $p);
$usos = usos_de($p['codigo']);
if ($propio) {
    $usos = array_values(array_filter($usos, fn ($uso) => $uso['usuario'] === $u['usuario']));
}
usort($usos, fn ($a, $b) => strcmp($b['fecha'], $a['fecha']));   // lo más reciente arriba
$nh = nucleo_horas($usos);
$precio = precio_nucleo_hora($p['tarifa']);
$coste = $nh * $precio;
$maquinas = nh_por_maquina($usos);
$candidatos = array_filter(usuarios_con_perfil('investigador'), fn ($i) => !in_array($i['usuario'], $p['miembros'], true));
$responsable = buscar_usuario($p['responsable'])['nombre'] ?? $p['responsable'];

cabecera($p['codigo'] . ' · ' . $p['titulo'], $u, match (true) {
    $propio   => 'Participas en este proyecto. Aquí ves tus trabajos y lo que llevas consumido.',
    $gestiona => 'Gestionas este proyecto: ves el consumo de todo el equipo y puedes cambiar quién está en él.',
    default   => 'Ficha del proyecto con su equipo y su consumo.',
});
?>
<p class="miga"><a href="index.php">← Volver a los proyectos</a></p>

<dl class="ficha" aria-label="Datos del proyecto">
  <div><dt>Responsable</dt><dd><?= e($responsable) ?></dd></div>
  <div><dt>Tarifa</dt><dd><span class="etiqueta <?= e($p['tarifa']) ?>"><?= e(TARIFAS[$p['tarifa']] ?? $p['tarifa']) ?></span>
    <span class="precio"><?= e(formato_precio($p['tarifa'])) ?>/núcleo-hora</span></dd></div>
  <div><dt>Equipo</dt><dd><?= count($p['miembros']) ?> <?= count($p['miembros']) === 1 ? 'investigador' : 'investigadores' ?></dd></div>
  <div><dt><?= $propio ? 'Tus trabajos' : 'Trabajos' ?></dt><dd><?= count($usos) ?></dd></div>
</dl>

<div class="rejilla-dos">
  <article class="bloque">
    <h2>Equipo</h2>
    <?php if ($p['miembros'] === []): ?>
      <p class="vacio">Todavía no hay investigadores en este proyecto.</p>
    <?php else: ?>
    <ul class="equipo">
      <?php foreach ($p['miembros'] as $n => $m): $nombre = buscar_usuario($m)['nombre'] ?? $m; ?>
      <li><span class="avatar" aria-hidden="true"><?= e(iniciales($nombre)) ?></span>
        <span class="equipo-nombre"><?= e($nombre) ?><?= $m === $u['usuario'] ? ' <small>(tú)</small>' : '' ?></span>
        <?php if ($gestiona): ?>
        <button type="button" class="secundario" command="show-modal" commandfor="quitar-<?= $n ?>"
                aria-label="Quitar a <?= e($nombre) ?>">Quitar</button>
        <dialog id="quitar-<?= $n ?>" aria-labelledby="quitar-<?= $n ?>-titulo" closedby="any">
          <h1 id="quitar-<?= $n ?>-titulo">¿Quitar a <?= e($nombre) ?>?</h1>
          <p>Dejará de ver <?= e($p['codigo']) ?> y no podrá cobrarle trabajos nuevos. Sus trabajos
            anteriores siguen contando en la factura.</p>
          <form method="post" class="acciones-dialogo">
            <input type="hidden" name="accion" value="quitar">
            <input type="hidden" name="investigador" value="<?= e($m) ?>">
            <button class="peligro">Sí, quitar</button>
            <button class="secundario" formmethod="dialog" formnovalidate value="cancelar" autofocus>Cancelar</button>
          </form>
        </dialog>
        <?php endif ?>
      </li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>

    <?php if ($gestiona): ?>
    <form method="post" class="anadir">
      <input type="hidden" name="accion" value="anadir">
      <?php if ($error !== ''): ?><p class="error" role="alert"><span aria-hidden="true">⚠</span> <?= e($error) ?></p><?php endif ?>
      <?php if ($candidatos === []): ?>
        <p class="pequeno">Todos los investigadores del BIFI ya están en el proyecto.</p>
      <?php else: ?>
      <label for="investigador">Añadir a alguien al equipo</label>
      <div class="en-linea">
        <select id="investigador" name="investigador">
          <?php foreach ($candidatos as $i): ?>
          <option value="<?= e($i['usuario']) ?>"><?= e($i['nombre']) ?></option>
          <?php endforeach ?>
        </select>
        <button>Añadir al proyecto</button>
      </div>
      <p class="pequeno">Los trabajos que lance a partir de ahora podrán cobrarse a este proyecto.</p>
      <?php endif ?>
    </form>
    <?php endif ?>
  </article>

  <article class="bloque factura">
    <h2><?= $propio ? 'Tu consumo' : 'Factura estimada' ?></h2>
    <p class="importe"><?= formato_euros($coste) ?></p>
    <p class="formula"><?= formato_nh($nh) ?> núcleo-horas × <?= e(formato_precio($p['tarifa'])) ?></p>
    <?php if ($maquinas !== []): ?>
    <ul class="barras">
      <?php foreach ($maquinas as $maquina => $nh_m): $pct = $nh > 0 ? round($nh_m / $nh * 100) : 0; ?>
      <li><span class="barra-nombre"><?= e($maquina) ?></span>
        <meter class="barra" min="0" max="100" value="<?= $pct ?>" aria-label="<?= e($maquina) ?>"><?= $pct ?> %</meter>
        <span class="barra-valor"><?= $pct ?> %</span></li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>
    <p class="pequeno">Estimación con precios de ejemplo. La factura real la emite el CESAR.</p>
  </article>
</div>

<?php if ($usos === []): ?>
<h2><?= $propio ? 'Tus trabajos' : 'Trabajos del equipo' ?></h2>
<p class="vacio">No hay trabajos registrados <?= $propio ? 'a tu nombre ' : '' ?>en este proyecto.</p>
<?php else: ?>
<div class="tabla" tabindex="0" role="region" aria-labelledby="tabla-trabajos">
<table class="t-trabajos">
  <caption id="tabla-trabajos"><?= $propio ? 'Tus trabajos' : 'Trabajos del equipo' ?></caption>
  <thead>
    <tr><th scope="col">Fecha</th><th scope="col">Persona</th><th scope="col">Máquina</th><th scope="col" class="num">Núcleos</th>
        <th scope="col" class="num">Horas</th><th scope="col" class="num">Núcleo-horas</th><th scope="col" class="num">Coste</th></tr>
  </thead>
  <tbody>
  <?php foreach ($usos as $uso): $nh_uso = $uso['nucleos'] * $uso['horas']; ?>
    <tr>
      <th scope="row"><time datetime="<?= e($uso['fecha']) ?>"><?= e(date('d/m/Y', strtotime($uso['fecha']) ?: 0)) ?></time></th>
      <td><?= e(buscar_usuario($uso['usuario'])['nombre'] ?? $uso['usuario']) ?></td>
      <td><?= e($uso['maquina']) ?></td>
      <td class="num"><?= (int) $uso['nucleos'] ?></td>
      <td class="num"><?= number_format((float) $uso['horas'], 1, ',', '.') ?></td>
      <td class="num"><?= formato_nh($nh_uso) ?></td>
      <td class="num"><?= formato_euros($nh_uso * $precio) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
  <tfoot>
    <tr><th scope="row" colspan="5">Total</th>
      <td class="num"><?= formato_nh($nh) ?></td>
      <td class="num"><?= formato_euros($coste) ?></td></tr>
  </tfoot>
</table>
</div>
<?php endif ?>
<?php
pie();
