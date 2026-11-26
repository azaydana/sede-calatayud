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

/**
 * Proyectos con la lista de sus miembros, como los usan las páginas:
 * ['codigo', 'titulo', 'responsable', 'tarifa', 'miembros' => ['ana', …]].
 * $donde es un filtro SQL con parámetros con nombre.
 */
function proyectos_donde(string $donde, array $parametros = []): array
{
    $filas = consulta(
        "SELECT p.codigo, p.titulo, p.responsable, p.tarifa,
                coalesce(json_agg(pa.usuario ORDER BY pa.usuario)
                         FILTER (WHERE pa.usuario IS NOT NULL), '[]') AS miembros
         FROM proyecto p
         LEFT JOIN participa pa ON pa.proyecto = p.codigo
         WHERE $donde
         GROUP BY p.codigo
         ORDER BY p.codigo",
        $parametros
    );
    foreach ($filas as &$fila) {
        $fila['miembros'] = json_decode($fila['miembros'], true);
    }
    return $filas;
}

/**
 * La misma regla que puede_ver(), pero en la consulta: la base solo devuelve lo que el perfil
 * puede ver, y el panel no carga proyectos ajenos.
 */
function proyectos_visibles(array $u): array
{
    return match ($u['perfil']) {
        'cesar'        => proyectos_donde('true'),
        'responsable'  => proyectos_donde('p.responsable = :u', ['u' => $u['usuario']]),
        'investigador' => proyectos_donde(
            'EXISTS (SELECT FROM participa m WHERE m.proyecto = p.codigo AND m.usuario = :u)',
            ['u' => $u['usuario']]
        ),
        default => [],
    };
}

function buscar_proyecto(string $codigo): ?array
{
    return proyectos_donde('p.codigo = :codigo', ['codigo' => $codigo])[0] ?? null;
}

function usuarios_con_perfil(string $perfil): array
{
    return consulta('SELECT usuario, nombre, perfil FROM usuario WHERE perfil = ? ORDER BY nombre', [$perfil]);
}

/** Los trabajos de un proyecto, del más antiguo al más reciente. */
function usos_de(string $codigo): array
{
    $usos = consulta(
        "SELECT proyecto, usuario, maquina, to_char(fecha, 'YYYY-MM-DD') AS fecha, nucleos, horas
         FROM uso WHERE proyecto = ? ORDER BY fecha, id",
        [$codigo]
    );
    foreach ($usos as &$uso) {
        $uso['nucleos'] = (int) $uso['nucleos'];
        $uso['horas'] = (float) $uso['horas'];   // numeric llega como texto: '6.50'
    }
    return $usos;
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
 * Guarda un proyecto ya validado. Devuelve un mensaje de error, o '' si ha ido bien.
 * Si otra persona ha dado de alta el mismo código entre la validación y el INSERT, la clave
 * primaria lo para (23505) y se dice como un error más del formulario.
 */
function crear_proyecto(array $p): string
{
    try {
        ejecutar(
            'INSERT INTO proyecto (codigo, titulo, responsable, tarifa) VALUES (?, ?, ?, ?)',
            [$p['codigo'], trim($p['titulo']), $p['responsable'], $p['tarifa']]
        );
        return '';
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23505') {
            return 'Ya existe un proyecto con ese código.';
        }
        throw $ex;
    }
}

/**
 * TODO (RA5 f). Añade o quita un investigador de un proyecto (formulario de proyecto.php).
 * $entrada['accion'] es 'anadir' o 'quitar'; $entrada['investigador'], el usuario.
 * Comprueba que es un investigador que existe; que no esté ya (al añadir) o que esté (al quitar)
 * lo dice la propia base: ON CONFLICT y el número de filas borradas.
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

    if ($accion === 'quitar') {
        $borradas = ejecutar('DELETE FROM participa WHERE proyecto = ? AND usuario = ?',
            [$proyecto['codigo'], $investigador]);
        return $borradas === 1 ? '' : 'El investigador no pertenece al proyecto.';
    }

    try {
        $metidas = ejecutar(
            'INSERT INTO participa (proyecto, usuario) VALUES (?, ?) ON CONFLICT DO NOTHING',
            [$proyecto['codigo'], $investigador]
        );
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23503') {     // clave ajena: el proyecto ya no existe
            return 'El proyecto ya no está disponible.';
        }
        throw $ex;
    }
    return $metidas === 1 ? '' : 'El investigador ya pertenece al proyecto.';
}
