<?php
declare(strict_types=1);

require __DIR__ . '/../comun/arranque.php';

$u = exigir_login();
$proyectos = proyectos_visibles($u);
$propio = $u['perfil'] === 'investigador';

// Por proyecto: núcleo-horas y coste. Un investigador solo cuenta lo suyo (RA5 i).
$filas = [];
$todos_usos = [];
foreach ($proyectos as $p) {
    $usos = usos_de($p['codigo']);
    if ($propio) {
        $usos = array_values(array_filter($usos, fn ($uso) => $uso['usuario'] === $u['usuario']));
    }
    $todos_usos = array_merge($todos_usos, $usos);
    $nh = nucleo_horas($usos);
    $filas[] = ['proyecto' => $p, 'nh' => $nh, 'coste' => $nh * precio_nucleo_hora($p['tarifa'])];
}
$total_nh = array_sum(array_column($filas, 'nh'));
$total_coste = array_sum(array_column($filas, 'coste'));
$maquinas = nh_por_maquina($todos_usos);
$trabajos = count($todos_usos);

$intro = match ($u['perfil']) {
    'cesar'       => 'Vista completa del instituto: todos los proyectos, quién los dirige y cuánto llevan gastado.',
    'responsable' => 'Los proyectos que diriges, con el consumo de todo tu equipo y la factura estimada.',
    default       => 'Los proyectos en los que participas y lo que has consumido tú en cada uno.',
};
$titulo = $u['perfil'] === 'cesar' ? 'Todos los proyectos' : 'Hola, ' . explode(' ', $u['nombre'])[0];
cabecera($titulo, $u, $intro);
?>
<section class="resumen" aria-label="Resumen">
  <article class="cifra"><h2><?= count($filas) === 1 ? 'Proyecto' : 'Proyectos' ?></h2><p><?= count($filas) ?></p></article>
  <article class="cifra"><h2><?= $propio ? 'Tus trabajos' : 'Trabajos lanzados' ?></h2><p><?= $trabajos ?></p></article>
  <article class="cifra"><h2><?= $propio ? 'Tus núcleo-horas' : 'Núcleo-horas' ?></h2><p><?= formato_nh($total_nh) ?></p></article>
  <article class="cifra destacada"><h2>Coste estimado</h2><p><?= formato_euros($total_coste) ?></p></article>
</section>

<?php if ($u['perfil'] === 'cesar'): ?>
<aside class="llamada">
  <p><strong>¿Un grupo empieza un proyecto nuevo?</strong> Dalo de alta con su código, título,
    tarifa y responsable. Después, el responsable añade a su equipo.</p>
  <a class="boton" href="proyecto-nuevo.php">Nuevo proyecto</a>
</aside>
<?php elseif ($u['perfil'] === 'responsable'): ?>
<aside class="llamada">
  <p><strong>¿Se incorpora alguien a tu equipo?</strong> Entra en el proyecto pulsando su código
    y añádelo desde la sección <em>Equipo</em>. Desde ahí también puedes quitar a quien ya no esté.</p>
</aside>
<?php endif ?>

<?php if ($filas === []): ?>
<h2>Proyectos</h2>
<p class="vacio"><?= match ($u['perfil']) {
    'cesar'       => 'Todavía no hay proyectos dados de alta.',
    'responsable' => 'No diriges ningún proyecto todavía. El CESAR te lo asignará al darlo de alta.',
    default       => 'Aún no estás en ningún proyecto. Pide a su responsable que te añada.',
} ?></p>
<?php else: ?>
<div class="tabla" tabindex="0" role="region" aria-labelledby="tabla-proyectos">
<table class="t-proyectos">
  <caption id="tabla-proyectos">Proyectos</caption>
  <thead>
    <tr><th scope="col">Código</th><th scope="col">Título</th><th scope="col">Responsable</th><th scope="col">Tarifa</th>
        <th scope="col" class="num">Equipo</th><th scope="col" class="num">Núcleo-horas</th><th scope="col" class="num">Coste</th></tr>
  </thead>
  <tbody>
  <?php foreach ($filas as $f): $p = $f['proyecto']; ?>
    <tr>
      <th scope="row"><?php if ($p['codigo'] !== ''): ?><a class="codigo" href="proyecto.php?codigo=<?= urlencode($p['codigo']) ?>"><?= e($p['codigo']) ?></a><?php else: ?><span class="sin-codigo">sin código</span><?php endif ?></th>
      <td><?= e($p['titulo']) ?></td>
      <td><?= e(buscar_usuario($p['responsable'])['nombre'] ?? $p['responsable']) ?></td>
      <td><span class="etiqueta <?= e($p['tarifa']) ?>"><?= e(TARIFAS[$p['tarifa']] ?? $p['tarifa']) ?></span></td>
      <td class="num"><?= count($p['miembros']) ?></td>
      <td class="num"><?= formato_nh($f['nh']) ?></td>
      <td class="num"><?= formato_euros($f['coste']) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
  <tfoot>
    <tr><th scope="row" colspan="5">Total</th>
      <td class="num"><?= formato_nh($total_nh) ?></td>
      <td class="num"><?= formato_euros($total_coste) ?></td></tr>
  </tfoot>
</table>
</div>
<?php endif ?>

<div class="rejilla-dos">
  <article class="bloque">
    <h2>Reparto por máquina</h2>
    <?php if ($maquinas === []): ?>
      <p class="vacio">Todavía no hay trabajos registrados.</p>
    <?php else: ?>
      <p class="pequeno">Núcleo-horas <?= $propio ? 'tuyas' : 'de tus proyectos' ?> en cada máquina del BIFI.</p>
      <ul class="barras">
        <?php foreach ($maquinas as $maquina => $nh): $pct = $total_nh > 0 ? round($nh / $total_nh * 100) : 0; ?>
        <li><span class="barra-nombre"><?= e($maquina) ?></span>
          <meter class="barra" min="0" max="100" value="<?= $pct ?>" aria-label="<?= e($maquina) ?>"><?= $pct ?> %</meter>
          <span class="barra-valor"><?= formato_nh($nh) ?> · <?= $pct ?> %</span></li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </article>
  <article class="bloque">
    <h2>Cómo leer esta página</h2>
    <dl class="glosario">
      <dt>Núcleo-horas</dt><dd>Núcleos de un trabajo por las horas que duró. Un trabajo de 64 núcleos durante 2 horas suma 128.</dd>
      <dt>Coste estimado</dt><dd>Núcleo-horas por el precio de la tarifa del proyecto. Es orientativo: la factura la emite el CESAR.</dd>
      <dt>Tarifa</dt><dd>Académica (<?= e(formato_precio('academica')) ?>) para proyectos de investigación; empresa (<?= e(formato_precio('empresa')) ?>) para encargos externos.</dd>
    </dl>
  </article>
</div>
<?php
pie();
