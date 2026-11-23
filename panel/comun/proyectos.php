<?php
// Proyectos, miembros y usos: quién ve qué (RA5 i) y validación del alta (RA5 f).
declare(strict_types=1);

require_once __DIR__ . '/funciones.php';

/**
 * TODO (RA5 i). La regla de aislamiento: todo lo que enseña un proyecto pasa por aquí.
 * El CESAR ve todos; un responsable, los suyos; un investigador, aquellos en los que es miembro.
 * Ahora deja ver todo a todo el mundo.
 */
function puede_ver(array $u, array $proyecto): bool
{
    return match ($u['perfil']) {
        'cesar' => true,
        'responsable' => $proyecto['responsable'] === $u['usuario'],
        'investigador' => in_array($u['usuario'], $proyecto['miembros'], true),
        default => false,
    };
}

/** TODO (RA5 i). Quién cambia los miembros de un proyecto: su responsable o el CESAR. Ahora, todos. */
function puede_gestionar(array $u, array $proyecto): bool
{
    return $u['perfil'] === 'cesar'
        || ($u['perfil'] === 'responsable' && $proyecto['responsable'] === $u['usuario']);
}

function proyectos_visibles(array $u): array
{
    return array_values(array_filter(leer_datos('proyectos'), fn ($p) => puede_ver($u, $p)));
}

function buscar_proyecto(string $codigo): ?array
{
    foreach (leer_datos('proyectos') as $p) {
        if ($p['codigo'] === $codigo) {
            return $p;
        }
    }
    return null;
}

function usuarios_con_perfil(string $perfil): array
{
    return array_values(array_filter(leer_datos('usuarios'), fn ($u) => $u['perfil'] === $perfil));
}

function usos_de(string $codigo): array
{
    return array_values(array_filter(leer_datos('usos'), fn ($uso) => $uso['proyecto'] === $codigo));
}

function nucleo_horas(array $usos): float
{
    return array_sum(array_map(fn ($uso) => $uso['nucleos'] * $uso['horas'], $usos));
}

/**
 * TODO (RA5 f). Valida el formulario de alta (solo lo usa el CESAR). Devuelve [proyecto, errores]:
 * si no hay errores, el proyecto está listo para guardar. Nada de lo que llega del navegador se da
 * por bueno.
 *
 * - Código: tres letras, un guion y cuatro cifras (BIO-0003), y que no exista ya.
 * - Título: entre 5 y 120 caracteres.
 * - Tarifa: una de TARIFAS.
 * - Responsable: tiene que existir y tener perfil de responsable.
 *
 * Cada error, en $errores['campo'] con un mensaje que entienda quien rellena el formulario.
 * Ahora no valida nada: guarda lo que llegue.
 */
function validar_proyecto(array $entrada, array $u): array
{
    $proyecto = ['miembros' => []];
    foreach (['codigo', 'titulo', 'tarifa', 'responsable'] as $campo) {
        $valor = $entrada[$campo] ?? '';
        $proyecto[$campo] = is_string($valor) ? $valor : '';
    }

    $errores = [];
    if (!preg_match('/^[A-Z]{3}-[0-9]{4}$/D', $proyecto['codigo'])
        || buscar_proyecto($proyecto['codigo']) !== null) {
        $errores['codigo'] = 'Introduce un código único con formato BIO-0003.';
    }

    $longitud = preg_match_all('/./us', trim($proyecto['titulo']));
    if ($longitud < 5 || $longitud > 120) {
        $errores['titulo'] = 'El título debe tener entre 5 y 120 caracteres.';
    }

    if (!isset(TARIFAS[$proyecto['tarifa']])) {
        $errores['tarifa'] = 'Selecciona una tarifa válida.';
    }

    if ((buscar_usuario($proyecto['responsable'])['perfil'] ?? null) !== 'responsable') {
        $errores['responsable'] = 'Selecciona un responsable de proyecto válido.';
    }

    return [$proyecto, $errores];
}

/**
 * TODO (RA5 f). Añade o quita un investigador de un proyecto (formulario de proyecto.php).
 * $entrada['accion'] es 'anadir' o 'quitar'; $entrada['investigador'], el usuario.
 * Comprobad que es un investigador que existe y que no está ya (al añadir), cambiad
 * $proyecto['miembros'] y guardad la lista de proyectos con guardar_datos('proyectos', …).
 * Devuelve un mensaje de error, o '' si ha ido bien.
 */
function gestionar_miembros(array $proyecto, array $entrada): string
{
    $accion = $entrada['accion'] ?? '';
    $investigador = $entrada['investigador'] ?? '';
    if (!in_array($accion, ['anadir', 'quitar'], true)) {
        return 'Acción desconocida.';
    }
    if (!is_string($investigador)
        || (buscar_usuario($investigador)['perfil'] ?? null) !== 'investigador') {
        return 'Selecciona un investigador válido.';
    }

    $proyectos = leer_datos('proyectos');
    foreach ($proyectos as $indice => $actual) {
        if ($actual['codigo'] !== $proyecto['codigo']) {
            continue;
        }

        $miembros = $actual['miembros'];
        $posicion = array_search($investigador, $miembros, true);

        if ($accion === 'anadir') {
            if ($posicion !== false) {
                return 'El investigador ya pertenece al proyecto.';
            }
            $miembros[] = $investigador;
        } else {
            if ($posicion === false) {
                return 'El investigador no pertenece al proyecto.';
            }
            unset($miembros[$posicion]);
        }

        $proyectos[$indice]['miembros'] = array_values($miembros);
        guardar_datos('proyectos', $proyectos);
        return '';
    }

    return 'El proyecto ya no está disponible.';
}
