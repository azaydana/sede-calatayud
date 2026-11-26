<?php
// Recuperar la contraseña sin correo: el usuario la pide, el CESAR genera un enlace de un solo uso
// y se lo hace llegar, y con ese enlace el usuario pone una contraseña nueva.
//
// Tabla solicitud     peticiones pendientes: (usuario, fecha)
// Tabla recuperacion  enlaces vivos: (hash, usuario, caduca). Se guarda el sha256 del token,
//                     nunca el token: quien lea la tabla no puede usar los enlaces.
declare(strict_types=1);

require_once __DIR__ . '/funciones.php';
require_once __DIR__ . '/sesion.php';

const VALIDEZ_ENLACE = 3600;      // segundos que vale un enlace
const MAX_SOLICITUDES = 50;       // tope de pendientes: que nadie llene el fichero a base de peticiones
const CLAVE_MINIMA = 8;

/**
 * Apunta que $usuario pide recuperar su contraseña. No dice nada hacia fuera: la página enseña
 * el mismo mensaje exista o no el usuario, para no revelar qué cuentas hay.
 */
function solicitar_recuperacion(string $usuario): void
{
    if (buscar_usuario($usuario) === null) {
        return;
    }
    // El tope y el «ya la había pedido» en la misma sentencia: ON CONFLICT con la clave primaria.
    ejecutar(
        'INSERT INTO solicitud (usuario)
         SELECT ? WHERE (SELECT count(*) FROM solicitud) < ?
         ON CONFLICT DO NOTHING',
        [$usuario, MAX_SOLICITUDES]
    );
}

function solicitudes_pendientes(): array
{
    return consulta("SELECT usuario, to_char(fecha, 'YYYY-MM-DD HH24:MI') AS fecha
                     FROM solicitud ORDER BY solicitud.fecha");
}

function descartar_solicitud(string $usuario): void
{
    ejecutar('DELETE FROM solicitud WHERE usuario = ?', [$usuario]);
}

/**
 * Crea un enlace de un solo uso para $usuario y devuelve el token (solo esta vez se ve en claro).
 * Anula los enlaces anteriores de ese usuario (y los caducados) y quita su solicitud. Todo o nada.
 */
function crear_enlace(string $usuario): string
{
    $token = bin2hex(random_bytes(32));
    bd()->beginTransaction();
    try {
        ejecutar('DELETE FROM recuperacion WHERE usuario = ? OR caduca <= now()', [$usuario]);
        ejecutar(
            "INSERT INTO recuperacion (hash, usuario, caduca) VALUES (?, ?, now() + make_interval(secs => ?))",
            [hash('sha256', $token), $usuario, VALIDEZ_ENLACE]
        );
        descartar_solicitud($usuario);
        bd()->commit();
    } catch (Throwable $ex) {
        bd()->rollBack();
        throw $ex;
    }
    return $token;
}

/** El enlace vivo que corresponde a $token, o null si no existe o ha caducado. */
function buscar_enlace(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/D', $token)) {
        return null;
    }
    return consulta(
        'SELECT usuario, hash, extract(epoch FROM caduca)::bigint AS caduca
         FROM recuperacion WHERE hash = ? AND caduca > now()',
        [hash('sha256', $token)]
    )[0] ?? null;
}

/**
 * Cambia la contraseña con el enlace $token. Devuelve un mensaje de error, o '' si ha ido bien.
 * El enlace se gasta al usarlo: el DELETE … RETURNING lo consume y dice de quién era en un solo
 * paso, así dos envíos a la vez con el mismo enlace no pueden usarlo los dos.
 * No se pide dos veces: el botón del ojo deja comprobar lo escrito.
 */
function cambiar_clave(string $token, string $clave): string
{
    if (buscar_enlace($token) === null) {
        return 'El enlace no es válido o ha caducado. Pide uno nuevo.';
    }
    if (mb_strlen($clave) < CLAVE_MINIMA) {
        return 'La contraseña debe tener al menos ' . CLAVE_MINIMA . ' caracteres.';
    }

    bd()->beginTransaction();
    try {
        $gastado = consulta('DELETE FROM recuperacion WHERE hash = ? AND caduca > now() RETURNING usuario',
            [hash('sha256', $token)]);
        if ($gastado === []) {
            bd()->rollBack();
            return 'El enlace no es válido o ha caducado. Pide uno nuevo.';
        }
        ejecutar('UPDATE usuario SET hash = ? WHERE usuario = ?',
            [password_hash($clave, PASSWORD_DEFAULT), $gastado[0]['usuario']]);
        bd()->commit();
    } catch (Throwable $ex) {
        bd()->rollBack();
        throw $ex;
    }
    return '';
}
