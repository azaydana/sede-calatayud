<?php
// Cabecera, menú y pie comunes a todas las páginas.
declare(strict_types=1);

require_once __DIR__ . '/funciones.php';

/** Iconos del menú, en SVG en línea: decorativos, el texto del enlace ya dice qué es. */
function icono(string $nombre): string
{
    $trazos = [
        'proyectos'   => '<path d="M3 7h18M3 12h18M3 17h12"/>',
        'nuevo'       => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
        'solicitudes' => '<rect x="4" y="10" width="16" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'salir'       => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l-4-4 4-4M6 12h10"/>',
    ];
    return '<svg class="icono-svg" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . ($trazos[$nombre] ?? '') . '</svg>';
}

/** URL de un fichero de public/ con su fecha: si cambia, cambia la URL y no se usa la caché. */
function recurso(string $fichero): string
{
    return e($fichero) . '?v=' . filemtime(__DIR__ . '/../public/' . $fichero);
}

/**
 * Abre la página. $intro es un párrafo bajo el título; $con_titulo = false para las páginas que
 * ponen el título ellas mismas (el login).
 */
function cabecera(string $titulo, ?array $u = null, string $intro = '', bool $con_titulo = true): void
{
    ?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <!-- Modo claro y oscuro según el sistema; el tamaño de letra del sistema también se respeta -->
  <meta name="color-scheme" content="light dark">
  <meta name="text-scale" content="scale">
  <title><?= e($titulo) ?> · Panel del BIFI</title>
  <link rel="stylesheet" href="<?= recurso('estilo.css') ?>">
  <script src="<?= recurso('panel.js') ?>" defer></script>
</head>
<body class="<?= $u !== null ? 'con-menu' : 'sin-menu' ?>">
<a class="saltar" href="#contenido">Saltar al contenido</a>
<div class="app">
<header class="lateral">
  <a class="marca" href="index.php">
    <span class="teselas" aria-hidden="true"><i>b</i><i>i</i><i>f</i><i>i</i></span>
    <span class="marca-texto"><strong>Panel del BIFI</strong>
      <small>Consumo de cálculo · Calatayud</small></span>
  </a>
  <?php if ($u !== null): ?>
  <nav class="menu" aria-label="Menú principal"><?= menu($u) ?></nav>
  <div class="quien">
    <span class="avatar" aria-hidden="true"><?= e(iniciales($u['nombre'])) ?></span>
    <span class="quien-texto"><strong><?= e($u['nombre']) ?></strong><small><?= e(PERFILES[$u['perfil']]) ?></small></span>
    <a class="salir" href="logout.php"><?= icono('salir') ?><span>Salir</span></a>
  </div>
  <?php endif ?>
</header>
<div class="lienzo">
<main id="contenido">
<?php if ($con_titulo): ?>
<header class="cabecera-pagina">
  <h1><?= e($titulo) ?></h1>
  <?php if ($intro !== ''): ?><p class="intro"><?= e($intro) ?></p><?php endif ?>
</header>
<?php endif ?>
    <?php
}

/** El menú cambia según el perfil: «Nuevo proyecto» y «Solicitudes», solo el CESAR. */
function menu(array $u): string
{
    $enlaces = ['index.php' => ['Proyectos', 'proyectos', 0]];
    if ($u['perfil'] === 'cesar') {
        $enlaces['proyecto-nuevo.php'] = ['Nuevo proyecto', 'nuevo', 0];
        $enlaces['solicitudes.php'] = ['Solicitudes', 'solicitudes', count(solicitudes_pendientes())];
    }

    // La ficha de un proyecto cuenta como «Proyectos» para marcar dónde está uno
    $actual = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($actual === 'proyecto.php') {
        $actual = 'index.php';
    }

    $html = '';
    foreach ($enlaces as $url => [$texto, $icono, $avisos]) {
        $marca = $url === $actual ? ' aria-current="page"' : '';
        $globo = $avisos > 0
            ? ' <span class="globo">' . $avisos . '<span class="oculto"> pendientes</span></span>' : '';
        $html .= '<a href="' . e($url) . '"' . $marca . '>' . icono($icono) . '<span>' . e($texto) . '</span>' . $globo . '</a>';
    }
    return $html;
}

function pie(): void
{
    ?>
</main>
<footer class="pie">
  <div class="pie-columnas">
    <section>
      <h2>El BIFI</h2>
      <p>Instituto Universitario de Investigación de Biocomputación y Física de Sistemas Complejos
        de la Universidad de Zaragoza. Sus grupos lanzan trabajos de cálculo en
        <em>cierzo</em>, <em>agustina</em> y <em>colossus</em>, y cada trabajo se cobra a un proyecto.</p>
    </section>
    <section>
      <h2>Cómo se calcula</h2>
      <p>Núcleos × horas de cada trabajo dan sus núcleo-horas. La factura de un proyecto es la suma
        por el precio de su tarifa: académica <?= e(formato_precio('academica')) ?> y empresa
        <?= e(formato_precio('empresa')) ?> por núcleo-hora.</p>
    </section>
    <section>
      <h2>¿Dudas?</h2>
      <p>Sobre quién está en un proyecto, habla con su responsable. Sobre tarifas, altas o tu
        cuenta, con la administración del CESAR.</p>
    </section>
  </div>
  <p class="pie-legal">Panel del BIFI · Sede de Calatayud · Precios de ejemplo, no son las tarifas
    reales del CESAR.</p>
</footer>
</div>
</div>
</body>
</html>
    <?php
}

function pagina_error(int $codigo, string $mensaje): never
{
    http_response_code($codigo);
    $titulos = [403 => 'No tienes permiso', 404 => 'No encontrado'];
    cabecera($titulos[$codigo] ?? 'Error ' . $codigo, usuario_actual());
    echo '<article class="aviso-error"><p class="codigo-http" aria-hidden="true">' . $codigo . '</p>'
        . '<p>' . e($mensaje) . '</p>'
        . '<p>Si crees que deberías poder entrar aquí, habla con el responsable del proyecto o '
        . 'con la administración del CESAR.</p>'
        . '<p><a class="boton" href="index.php">Volver a mis proyectos</a></p></article>';
    pie();
    exit;
}
