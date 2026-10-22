<?php
$dsn = 'pgsql:host=' . getenv('DB_HOST') . ';dbname=' . getenv('DB_NOMBRE');
try {
    $pdo = new PDO($dsn, getenv('DB_USUARIO'), getenv('DB_CLAVE'),
                   [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo '<h1>Conectado a la Base de Datos</h1><p>' . $pdo->query('SELECT version()')->fetchColumn() . '</p>';
} catch (PDOException $e) {
    http_response_code(503);
    echo '<h1>Sin base de datos</h1>';
    error_log('fallo de conexion: ' . $e->getMessage());
}
