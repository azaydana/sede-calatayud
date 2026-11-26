<?php
// Solicitudes de recuperación de contraseña. Solo el CESAR.
declare(strict_types=1);

require __DIR__ . '/../comun/arranque.php';

$u = exigir_perfil(['cesar']);

$enlace = null;   // [usuario, url, caduca] del enlace recién generado: se enseña una vez
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = (string) ($_POST['usuario'] ?? '');
    $pedido = in_array($usuario, array_column(solicitudes_pendientes(), 'usuario'), true);
    if ($pedido && ($_POST['accion'] ?? '') === 'generar') {
        $token = crear_enlace($usuario);
        $base = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
            . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        $enlace = [
            'usuario' => $usuario,
            'url'     => $base . '/recuperar.php?token=' . $token,
            'caduca'  => time() + VALIDEZ_ENLACE,
        ];
    } elseif ($pedido && ($_POST['accion'] ?? '') === 'descartar') {
        descartar_solicitud($usuario);
        redirigir('solicitudes.php');
    }
}

$solicitudes = solicitudes_pendientes();
cabecera('Solicitudes de contraseña', $u,
    'Personas que han pedido recuperar su contraseña. Comprueba que es quien dice ser antes de darle el enlace.');
?>
<?php if ($enlace !== null): ?>
<article class="tarjeta enlace-nuevo">
  <h2>Enlace para <?= e(buscar_usuario($enlace['usuario'])['nombre'] ?? $enlace['usuario']) ?></h2>
  <p class="exito" role="status"><span aria-hidden="true">✓</span> Enlace generado. Cópialo ahora: <strong>no se vuelve a mostrar</strong>.</p>
  <p>Vale una sola vez y caduca a las <?= e(date('H:i', $enlace['caduca'])) ?>.</p>
  <label for="enlace" class="oculto">Enlace de recuperación</label>
  <input id="enlace" class="enlace" readonly value="<?= e($enlace['url']) ?>" onfocus="this.select()">
</article>
<?php endif ?>

<?php if ($solicitudes === []): ?>
<h2>Pendientes</h2>
<p class="vacio">No hay solicitudes pendientes.</p>
<?php else: ?>
<div class="tabla" tabindex="0" role="region" aria-labelledby="tabla-solicitudes">
<table class="t-solicitudes">
  <caption id="tabla-solicitudes">Pendientes</caption>
  <thead>
    <tr><th scope="col">Persona</th><th scope="col">Usuario</th><th scope="col">Perfil</th><th scope="col">Pedida</th><th scope="col">Acciones</th></tr>
  </thead>
  <tbody>
  <?php foreach ($solicitudes as $s): $persona = buscar_usuario($s['usuario']); ?>
    <tr>
      <th scope="row"><?= e($persona['nombre'] ?? $s['usuario']) ?></th>
      <td><?= e($s['usuario']) ?></td>
      <td><?= e(PERFILES[$persona['perfil'] ?? ''] ?? '—') ?></td>
      <td><?= e($s['fecha']) ?></td>
      <td>
        <form method="post" class="en-linea">
          <input type="hidden" name="usuario" value="<?= e($s['usuario']) ?>">
          <button name="accion" value="generar">Generar enlace</button>
          <button name="accion" value="descartar" class="secundario">Descartar</button>
        </form>
      </td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
<?php endif ?>

<aside class="ayuda separada" aria-labelledby="titulo-antes">
  <h2 id="titulo-antes">Antes de generar un enlace</h2>
  <ul>
    <li>Confirma la identidad: en persona, llamando a su extensión o escribiendo a su correo de la universidad.</li>
    <li>Entrega el enlace solo a esa persona. Quien lo tenga puede cambiar la contraseña durante una hora.</li>
    <li>Generar un enlace nuevo anula el anterior de la misma persona.</li>
    <li>Si no reconoces la petición, descártala: la contraseña actual sigue valiendo.</li>
  </ul>
</aside>
<?php
pie();
