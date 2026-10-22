<?php
// W3: muestra versión del gestor y nº de filas de tabla propia.
// Credenciales SIEMPRE desde entorno (fichero .env fuera de git).
$host = getenv('DB_HOST') ?: 'db';
$name = getenv('DB_NOMBRE') ?: 'sede';
$user = getenv('DB_USUARIO') ?: 'sede';
$pass = getenv('DB_CLAVE') ?: '';

header('Content-Type: text/html; charset=utf-8');

try {
    $dsn = "host=$host dbname=$name user=$user password=$pass connect_timeout=5";
    $db = pg_connect($dsn);
    if (!$db) throw new Exception('No se pudo conectar a PostgreSQL');

    $v = pg_query($db, 'SELECT version()');
    $row = pg_fetch_row($v);
    $version = htmlspecialchars($row[0]);

    // Consulta preparada (rúbrica IAW: evita inyección)
    pg_prepare($db, 'count_clientes', 'SELECT COUNT(*) FROM clientes');
    $r = pg_execute($db, 'count_clientes', []);
    $n = pg_fetch_row($r)[0];

    echo "<h1>Estado de la sede</h1>";
    echo "<p>PostgreSQL: $version</p>";
    echo "<p>Filas en clientes: " . (int)$n . "</p>";
} catch (Throwable $e) {
    http_response_code(500);
    // No filtrar credenciales ni stack al cliente; detalle solo en logs Apache.
    error_log('estado.php: ' . $e->getMessage());
    echo "<h1>Error</h1><p>Base de datos no disponible. Ver logs Apache/PostgreSQL (W7).</p>";
}
