<?php
// Funciones generales del panel: salida segura y conexión con la base bifi (RA6).
declare(strict_types=1);

const PERFILES = [
    'investigador' => 'Investigador',
    'responsable'  => 'Responsable de proyecto',
    'cesar'        => 'Administración del CESAR',
];

const TARIFAS = [
    'academica' => 'Académica',
    'empresa'   => 'Empresa',
];

/** Escapa un texto para meterlo en HTML. Todo lo que viene del usuario o de los datos pasa por aquí. */
function e(?string $texto): string
{
    return htmlspecialchars($texto ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * La conexión con la base bifi, una por petición. Los datos de conexión llegan por el entorno
 * (compose.yaml, servicio panel): el rol panel, que solo puede lo que el panel necesita.
 *
 * Todas las consultas van con sentencias preparadas: lo que llega del navegador viaja como
 * parámetro, nunca pegado al SQL. Un error de la base lanza PDOException.
 */
function bd(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;connect_timeout=5;application_name=panel',
        getenv('DB_HOST') ?: 'db',
        getenv('DB_PUERTO') ?: '5432',
        getenv('DB_NOMBRE') ?: 'bifi'
    );
    try {
        $pdo = new PDO($dsn, getenv('DB_USUARIO') ?: 'panel', getenv('DB_CLAVE') ?: '', [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $ex) {
        // Al registro de Apache el motivo; a la persona, un mensaje sin detalles de la base.
        // Página mínima: la plantilla normal también consulta la base y volvería a fallar.
        error_log('panel: sin conexión con la base: ' . $ex->getMessage());
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Panel no disponible</title>'
            . '<h1>Panel no disponible</h1><p>No se puede conectar con la base de datos del BIFI. '
            . 'Prueba dentro de un rato o avisa a la administración del CESAR.</p></html>';
        exit;
    }
    return $pdo;
}

/** Ejecuta una consulta preparada y devuelve todas sus filas. */
function consulta(string $sql, array $parametros = []): array
{
    $sentencia = bd()->prepare($sql);
    $sentencia->execute($parametros);
    return $sentencia->fetchAll();
}

/** Ejecuta una sentencia preparada (INSERT, UPDATE, DELETE) y devuelve las filas afectadas. */
function ejecutar(string $sql, array $parametros = []): int
{
    $sentencia = bd()->prepare($sql);
    $sentencia->execute($parametros);
    return $sentencia->rowCount();
}

/** Euros por núcleo-hora de una tarifa, de la tabla tarifa. Se lee una vez por petición. */
function precio_nucleo_hora(string $tarifa): float
{
    static $precios = null;
    $precios ??= array_column(consulta('SELECT tipo, precio_nucleo_hora FROM tarifa'),
        'precio_nucleo_hora', 'tipo');
    return (float) ($precios[$tarifa] ?? 0);
}

/** Núcleo-horas con formato español: 1.600,0. Se usa en la portada y en la ficha del proyecto. */
function formato_nh(float $nh): string
{
    return number_format($nh, 1, ',', '.');
}

/** Euros con formato español: 16,00 €. */
function formato_euros(float $euros): string
{
    return number_format($euros, 2, ',', '.') . ' €';
}

/** Precio del núcleo-hora de una tarifa, con tres decimales: 0,010 €. */
function formato_precio(string $tarifa): string
{
    return number_format(precio_nucleo_hora($tarifa), 3, ',', '.') . ' €';
}

/** Iniciales de un nombre para el avatar de la cabecera: «Ana Lorente» → «AL». */
function iniciales(string $nombre): string
{
    $partes = preg_split('/\s+/u', trim($nombre), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $letras = array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($partes, 0, 2));
    return mb_strtoupper(implode('', $letras));
}

/**
 * Núcleo-horas por máquina de una lista de usos, de más a menos: ['cierzo' => 1600.0, …].
 * Lo usan la portada y la ficha del proyecto para el reparto por máquina.
 */
function nh_por_maquina(array $usos): array
{
    $reparto = [];
    foreach ($usos as $uso) {
        $reparto[$uso['maquina']] = ($reparto[$uso['maquina']] ?? 0) + $uso['nucleos'] * $uso['horas'];
    }
    arsort($reparto);
    return $reparto;
}

function redirigir(string $url): never
{
    header("Location: $url");
    exit;
}
