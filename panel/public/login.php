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
    $error = 'Usuario o contraseña incorrectos.';
}

cabecera('Entrar');
?>
<form method="post" class="tarjeta">
  <?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif ?>
  <label>Usuario <input name="usuario" value="<?= e($usuario) ?>" required autofocus></label>
  <label for="clave">Contraseña</label>
  <div class="clave">
    <input id="clave" name="clave" type="password" autocomplete="current-password" required>
    <button type="button" class="ojo" aria-label="Mostrar contraseña" aria-pressed="false" aria-controls="clave">
      <!-- Ojo abierto: se ve mientras la contraseña está oculta -->
      <svg class="ver" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"/><circle cx="12" cy="12" r="3"/>
      </svg>
      <!-- Ojo tachado: se ve mientras la contraseña está a la vista -->
      <svg class="ocultar" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 19c-7 0-11-7-11-7a18.45 18.45 0 0 1 5.06-5.94"/>
        <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 7 11 7a18.5 18.5 0 0 1-2.16 3.19"/>
        <path d="M14.12 14.12a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/>
      </svg>
    </button>
  </div>
  <button>Entrar</button>
</form>
<script src="ojo.js"></script>
<?php
pie();
