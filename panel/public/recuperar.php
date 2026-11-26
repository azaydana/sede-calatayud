<?php
// Recuperar la contraseña. Dos pasos en la misma página:
//   sin token  → se pide el usuario y se apunta la solicitud para el CESAR
//   con token  → el enlace que generó el CESAR: se elige la contraseña nueva
declare(strict_types=1);

require __DIR__ . '/../comun/arranque.php';

if (usuario_actual() !== null) {
    redirigir('index.php');
}

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$enviada = false;

if ($token === '') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $usuario = trim((string) ($_POST['usuario'] ?? ''));
        if ($usuario !== '') {
            solicitar_recuperacion($usuario);
        }
        $enviada = true;   // mismo mensaje exista o no el usuario
    }
} else {
    $enlace = buscar_enlace($token);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $enlace !== null) {
        $error = cambiar_clave($token, (string) ($_POST['clave'] ?? ''));
        if ($error === '') {
            redirigir('login.php?clave=cambiada');
        }
    }
}

cabecera('Recuperar la contraseña', null,
    'El panel no envía correos: la administración del CESAR recibe tu petición y te hace llegar un enlace personal para elegir una contraseña nueva.');
?>
<div class="rejilla-ayuda">
<?php if ($token === ''): ?>
  <?php if ($enviada): ?>
  <article class="tarjeta">
    <h2>Solicitud registrada</h2>
    <p class="exito" role="status"><span aria-hidden="true">✓</span> Si el usuario existe, el CESAR ya tiene tu petición.</p>
    <p>Te hará llegar un enlace que sirve <strong>una sola vez</strong> y caduca en una hora.
      Cuando lo tengas, ábrelo y elige tu contraseña nueva.</p>
    <p><a href="login.php">Volver a la entrada</a></p>
  </article>
  <?php else: ?>
  <form method="post" class="tarjeta formulario">
    <h2>Paso 1 · Pide un enlace</h2>
    <div class="campo">
      <label for="usuario">Tu usuario</label>
      <input id="usuario" name="usuario" autocomplete="username" required autofocus data-error="usuario-error"
             spellcheck="false" autocapitalize="none" autocorrect="off" enterkeyhint="send">
      <p id="usuario-error" class="error-msg"><span aria-hidden="true">⚠</span> Escribe tu usuario.</p>
    </div>
    <button class="ancho">Pedir enlace de recuperación</button>
    <p class="olvido"><a href="login.php">Ya me acuerdo, volver a entrar</a></p>
  </form>
  <?php endif ?>
<?php elseif ($enlace === null): ?>
  <article class="tarjeta">
    <h2>Enlace no válido</h2>
    <p class="error" role="alert"><span aria-hidden="true">⚠</span> El enlace no es válido o ha caducado.</p>
    <p>Los enlaces valen una hora y solo una vez. Pide uno nuevo y avisa al CESAR.</p>
    <p><a class="boton" href="recuperar.php">Pedir un enlace nuevo</a></p>
  </article>
<?php else: ?>
  <form method="post" class="tarjeta formulario">
    <h2>Paso 2 · Elige tu contraseña nueva</h2>
    <p class="pequeno">Cuenta: <strong><?= e($enlace['usuario']) ?></strong> · el enlace caduca a las
      <?= e(date('H:i', $enlace['caduca'])) ?>.</p>
    <?php if ($error !== ''): ?><p class="error" role="alert"><span aria-hidden="true">⚠</span> <?= e($error) ?></p><?php endif ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <!-- El usuario, oculto, para que el gestor de contraseñas sepa a qué cuenta guardarla -->
    <input type="text" name="cuenta" value="<?= e($enlace['usuario']) ?>" autocomplete="username" hidden>
    <div class="campo">
      <label for="clave">Contraseña nueva</label>
      <p id="clave-pista" class="pista">Al menos <?= CLAVE_MINIMA ?> caracteres.</p>
      <div class="clave">
        <input id="clave" name="clave" type="password" autocomplete="new-password" minlength="<?= CLAVE_MINIMA ?>"
               required autofocus aria-describedby="clave-pista" data-error="clave-error" enterkeyhint="done">
        <button type="button" class="ojo" aria-label="Mostrar contraseña" aria-pressed="false" aria-controls="clave">
          <svg class="ver" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="ocultar" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-7 0-11-7-11-7a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19"/><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>
        </button>
      </div>
      <p id="clave-error" class="error-msg"><span aria-hidden="true">⚠</span> Necesita al menos <?= CLAVE_MINIMA ?> caracteres.</p>
    </div>
    <button class="ancho">Guardar la contraseña</button>
  </form>
<?php endif ?>

  <aside class="ayuda" aria-labelledby="titulo-como">
    <h2 id="titulo-como">Cómo funciona</h2>
    <ol>
      <li><strong>Pides el enlace</strong> con tu usuario. Por seguridad, la respuesta es la misma
        aunque el usuario no exista.</li>
      <li><strong>El CESAR lo genera</strong> desde su panel y te lo hace llegar por un canal de
        confianza: en persona, por teléfono o por el correo de la universidad.</li>
      <li><strong>Eliges la contraseña</strong>, de al menos <?= CLAVE_MINIMA ?> caracteres. El
        enlace se anula en cuanto la guardas.</li>
    </ol>
    <p class="pequeno">El panel no guarda tu contraseña, solo su <em>hash</em>. Nadie, tampoco el
      CESAR, puede verla.</p>
  </aside>
</div>
<?php
pie();
