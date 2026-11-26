<?php
declare(strict_types=1);

require __DIR__ . '/../comun/arranque.php';

if (usuario_actual() !== null) {
    redirigir('index.php');
}

$error = '';
$usuario = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario = trim((string) ($_POST['usuario'] ?? ''));
    $clave = (string) ($_POST['clave'] ?? '');
    if (entrar($usuario, $clave)) {
        redirigir('index.php');
    }
    // El mismo mensaje si el usuario no existe y si la contraseña está mal (A2)
    $error = 'Usuario o contraseña incorrectos.';
}
$clave_cambiada = ($_GET['clave'] ?? '') === 'cambiada';

cabecera('Entrar', null, '', false);
?>
<div class="portada-login">
  <section class="acceso" aria-labelledby="titulo-acceso">
    <form method="post" class="tarjeta formulario">
      <h1 id="titulo-acceso">Entra en el panel</h1>
      <p class="pequeno">Con el usuario y la contraseña que te dio el CESAR.</p>
      <?php if ($clave_cambiada): ?>
        <p class="exito" role="status"><span aria-hidden="true">✓</span> Contraseña cambiada. Ya puedes entrar con la nueva.</p>
      <?php endif ?>
      <?php if ($error !== ''): ?><p class="error" role="alert"><span aria-hidden="true">⚠</span> <?= e($error) ?></p><?php endif ?>
      <div class="campo">
        <label for="usuario">Usuario</label>
        <input id="usuario" name="usuario" value="<?= e($usuario) ?>" autocomplete="username" required
               autofocus spellcheck="false" autocapitalize="none" autocorrect="off" enterkeyhint="next">
      </div>
      <div class="campo">
        <label for="clave">Contraseña</label>
        <div class="clave">
          <input id="clave" name="clave" type="password" autocomplete="current-password" required enterkeyhint="done">
          <button type="button" class="ojo" aria-label="Mostrar contraseña" aria-pressed="false" aria-controls="clave">
            <svg class="ver" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>
            </svg>
            <svg class="ocultar" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-7 0-11-7-11-7a18.45 18.45 0 0 1 5.06-5.94"/>
              <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19"/>
              <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/>
            </svg>
          </button>
        </div>
      </div>
      <button class="ancho">Entrar</button>
      <p class="olvido"><a href="recuperar.php">¿Has olvidado tu contraseña?</a></p>
    </form>
    <aside class="consejos" aria-labelledby="titulo-consejos">
      <h2 id="titulo-consejos">Antes de entrar</h2>
      <ul>
        <li>No compartas tu cuenta: lo que ves depende de tu perfil.</li>
        <li>Pulsa <strong>Salir</strong> al terminar, sobre todo en los equipos del aula.</li>
        <li>¿Falta un proyecto tuyo? Pide a su responsable que te añada.</li>
      </ul>
    </aside>
  </section>

  <section class="presentacion" aria-labelledby="titulo-presentacion">
    <p class="antetitulo">Instituto BIFI · Universidad de Zaragoza</p>
    <h2 id="titulo-presentacion">Lo que gasta cada proyecto, a la vista</h2>
    <p>El panel reúne los trabajos de cálculo que se lanzan en <em>cierzo</em>, <em>agustina</em>
      y <em>colossus</em> y los reparte entre los proyectos que los pagan. Cada persona ve
      exactamente lo que le toca: ni más ni menos.</p>

    <h3>Tres maneras de usarlo</h3>
    <ul class="perfiles">
      <li><span class="insignia inv" aria-hidden="true">I</span>
        <div><strong>Investigador</strong>
          <p>Consulta los proyectos en los que participa y su propio consumo en cada uno.</p></div></li>
      <li><span class="insignia res" aria-hidden="true">R</span>
        <div><strong>Responsable de proyecto</strong>
          <p>Ve el consumo de todo su equipo y la factura estimada, y añade o quita investigadores.</p></div></li>
      <li><span class="insignia ces" aria-hidden="true">C</span>
        <div><strong>Administración del CESAR</strong>
          <p>Tiene la vista completa del instituto, da de alta proyectos y atiende las cuentas.</p></div></li>
    </ul>

    <h3>Cómo se calcula la factura</h3>
    <p class="formula">núcleos × horas × precio de la tarifa</p>
    <p class="pequeno">Académica: <?= e(formato_precio('academica')) ?> por núcleo-hora ·
      Empresa: <?= e(formato_precio('empresa')) ?> por núcleo-hora (precios de ejemplo).</p>
  </section>
</div>
<?php
pie();
