<?php
// Sesión, login y control de acceso por perfil (RA5 g, h).
declare(strict_types=1);

require_once __DIR__ . '/funciones.php';

function iniciar_sesion(): void
{
    session_set_cookie_params([
        'httponly' => true,   // JavaScript no puede leer la cookie de sesión
        'samesite' => 'Lax',  // no viaja en peticiones que vienen de otros sitios
    ]);
    session_start();
}

function buscar_usuario(string $usuario): ?array
{
    foreach (leer_datos('usuarios') as $u) {
        if ($u['usuario'] === $usuario) {
            return $u;
        }
    }
    return null;
}

/**
 * TODO (RA5 h). Comprueba usuario y contraseña contra datos/usuarios.json, que guarda el hash, nunca
 * la contraseña. Si son buenos, guarda en $_SESSION['usuario'] quién es y su perfil y devuelve true.
 * Pistas: buscar_usuario(), password_verify(), session_regenerate_id().
 */
function entrar(string $usuario, string $clave): bool
{
    $u = buscar_usuario($usuario);

    // Si el usuario no existe se verifica igual contra un hash cualquiera: así tarda lo mismo y no
    // se puede saber por el tiempo de respuesta qué usuarios existen. El mensaje también es el mismo.
    $hash = $u['hash'] ?? '$2y$10$46h.P91DIGOFh6zwFu9REeuZvuU1HrV3rGwoc9q0ttAj10p0MO3WO';
    if (!password_verify($clave, $hash) || $u === null) {
        return false;
    }

    // Identificador de sesión nuevo al entrar: evita la fijación de sesión.
    session_regenerate_id(true);

    // En la sesión, quién es y su perfil. Nunca el hash.
    $_SESSION['usuario'] = [
        'usuario' => $u['usuario'],
        'nombre'  => $u['nombre'],
        'perfil'  => $u['perfil'],
    ];
    return true;
}

/**
 * TODO (RA5 h). Cierra la sesión de verdad: que con la cookie antigua no se pueda volver a entrar.
 * Pistas: $_SESSION = [], setcookie() con fecha pasada, session_destroy().
 */
function salir(): void
{
    // Vaciar los datos de la sesión
    $_SESSION = [];

    // Borrar la cookie en el navegador: misma ruta y dominio, fecha ya pasada
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 3600,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'],
    ]);

    // Destruir la sesión en el servidor: la cookie antigua ya no sirve
    session_destroy();
}

function usuario_actual(): ?array
{
    return $_SESSION['usuario'] ?? null;
}

/**
 * TODO (RA5 g, h). Para las páginas que piden login: si no hay nadie en la sesión, a login.php.
 * Mientras no esté hecho, todo el mundo entra como CESAR. Es justo el agujero que hay que cerrar.
 */
function exigir_login(): array
{
    $usuario = usuario_actual();
    if ($usuario === null) {
        redirigir('login.php');
    }
    return $usuario;
}

/**
 * TODO (RA5 h). Para las páginas de unos perfiles concretos: si el perfil no está en la lista,
 * error 403 con pagina_error().
 */
function exigir_perfil(array $perfiles): array
{
    $usuario = exigir_login();
    if (!in_array($usuario['perfil'], $perfiles, true)) {
        pagina_error(403, 'Tu perfil no permite esta operación.');
    }
    return $usuario;
}
