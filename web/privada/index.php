<?php
declare(strict_types=1);

/**
 * Panel de Control de la Intranet - Sede Calatayud
 * Requisitos: PHP 8.2 + extensión pdo_pgsql, Apache, PostgreSQL 16.
 * Variables de entorno: DB_HOST, DB_NOMBRE, DB_USUARIO, DB_CLAVE (opcional: DB_PORT, por defecto 5432).
 */

ini_set('display_errors', '0');
date_default_timezone_set('Europe/Madrid');

/* ---------- Utilidades ---------- */

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_bytes(float|int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $bytes = (float)$bytes;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return number_format($bytes, $i === 0 ? 0 : 1, ',', '.') . ' ' . $units[$i];
}

/** Devuelve las clases de color de una barra según el porcentaje de uso. */
function bar_color(float $pct): string
{
    return match (true) {
        $pct >= 90 => 'bg-red-500',
        $pct >= 70 => 'bg-amber-400',
        default    => 'bg-emerald-500',
    };
}

/** Comprueba si un puerto TCP responde. */
function check_port(string $host, int $port, float $timeout = 1.0): array
{
    $start = microtime(true);
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $ms = (microtime(true) - $start) * 1000;
    if ($fp) {
        fclose($fp);
        return ['up' => true, 'ms' => $ms];
    }
    return ['up' => false, 'ms' => $ms];
}

/* ---------- 1. Cabecera ---------- */

$user     = $_SERVER['PHP_AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? null;
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$now      = new DateTimeImmutable('now');

// Pantalla de splash desactivada: la imagen que mostraba no está en el repo
// (quita las variables y el bloque #splash si se reactiva).
$showSplash = false;
$splashImg  = '';

/* ---------- 2. PostgreSQL ---------- */

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = (int)(getenv('DB_PORT') ?: 5432);
$dbName = getenv('DB_NOMBRE') ?: '';
$dbUser = getenv('DB_USUARIO') ?: '';
$dbPass = getenv('DB_CLAVE') ?: '';

$dbOk = false;
$dbVersion = '';
$dbVersionFull = '';
$dbLatencyMs = null;
$pdo = null;

try {
    $t0 = microtime(true);
    $pdo = new PDO(
        "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName};connect_timeout=3",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $dbVersionFull = (string)$pdo->query('SELECT version();')->fetchColumn();
    $dbLatencyMs = (microtime(true) - $t0) * 1000;
    $dbOk = true;
    $dbVersion = preg_match('/PostgreSQL\s+[\d.]+/', $dbVersionFull, $m) ? $m[0] : $dbVersionFull;
} catch (Throwable $e) {
    error_log('[intranet-panel] Error de conexión PDO: ' . $e->getMessage());
}

/* ---------- 3. Recursos del servidor ---------- */

$path      = '/var/www/html';
$diskFree  = @disk_free_space($path);
$diskTotal = @disk_total_space($path);
$diskOk    = $diskFree !== false && $diskTotal !== false && $diskTotal > 0;
$diskUsed  = $diskOk ? $diskTotal - $diskFree : 0;
$diskPct   = $diskOk ? round($diskUsed / $diskTotal * 100, 1) : 0.0;

$load  = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
$cores = 1;
if (is_readable('/proc/cpuinfo')) {
    $cores = max(1, preg_match_all('/^processor\s*:/m', (string)file_get_contents('/proc/cpuinfo')));
}

$memTotal = $memAvail = null;
if (is_readable('/proc/meminfo')) {
    $info = (string)file_get_contents('/proc/meminfo');
    if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $info, $a)) {
        $memTotal = (int)$a[1] * 1024;
    }
    if (preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $info, $a)) {
        $memAvail = (int)$a[1] * 1024;
    }
}
$memOk  = $memTotal !== null && $memAvail !== null && $memTotal > 0;
$memPct = $memOk ? round(($memTotal - $memAvail) / $memTotal * 100, 1) : 0.0;

$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'No disponible';

/* ---------- 4. Puertos y servicios ---------- */

$serverPort = (int)($_SERVER['SERVER_PORT'] ?? 80);
$services = [
    ['name' => 'Servidor web (Apache)', 'host' => '127.0.0.1', 'port' => $serverPort],
    ['name' => 'PostgreSQL',            'host' => $dbHost,     'port' => $dbPort],
];
foreach ($services as &$svc) {
    $svc += check_port($svc['host'], $svc['port']);
}
unset($svc);
$servicesUp = count(array_filter($services, fn($s) => $s['up']));

/* ---------- 5. Tareas y auditoría ---------- */

$tasks = [];
$tasksDemo = true;

// Si existe la tabla public.auditoria_tareas (tarea, estado, ejecutado_en, detalle) se usa; si no, datos de demostración.
if ($dbOk && $pdo) {
    try {
        $exists = $pdo->query("SELECT to_regclass('public.auditoria_tareas')")->fetchColumn();
        if ($exists) {
            $rows = $pdo->query(
                'SELECT tarea, estado, ejecutado_en, detalle FROM public.auditoria_tareas ORDER BY ejecutado_en DESC LIMIT 8'
            )->fetchAll();
            foreach ($rows as $r) {
                $tasks[] = [
                    'name'   => (string)$r['tarea'],
                    'when'   => new DateTimeImmutable((string)$r['ejecutado_en']),
                    'status' => (string)$r['estado'],
                    'detail' => (string)($r['detalle'] ?? ''),
                    'cron'   => null,
                ];
            }
            $tasksDemo = false;
        }
    } catch (Throwable $e) {
        error_log('[intranet-panel] Error leyendo auditoria_tareas: ' . $e->getMessage());
    }
}

if ($tasksDemo || !$tasks) {
    $tasksDemo = true;
    $ago = fn(string $spec) => $now->modify($spec);
    $tasks = [
        ['name' => 'Copia de seguridad de PostgreSQL', 'cron' => '0 2 * * *',    'when' => $ago('-8 hours'),    'status' => 'ok',    'detail' => 'Volcado completo, 1,8 GB comprimidos'],
        ['name' => 'Rotación de logs de Apache',       'cron' => '30 0 * * *',   'when' => $ago('-10 hours'),   'status' => 'ok',    'detail' => '14 archivos rotados'],
        ['name' => 'Sincronización de usuarios LDAP',  'cron' => '*/30 * * * *', 'when' => $ago('-12 minutes'), 'status' => 'warn',  'detail' => '2 cuentas sin correo, omitidas'],
        ['name' => 'Renovación de certificados TLS',   'cron' => '0 4 * * 1',    'when' => $ago('-2 days'),     'status' => 'ok',    'detail' => 'Vencen en 61 días'],
        ['name' => 'Limpieza de archivos temporales',  'cron' => '0 3 * * 0',    'when' => $ago('-3 days'),     'status' => 'fail',  'detail' => 'Sin permisos sobre /var/tmp/informes'],
        ['name' => 'Informe de inventario',            'cron' => '*/15 * * * *', 'when' => $ago('-1 minute'),   'status' => 'run',   'detail' => 'Procesando sede Calatayud'],
    ];
}

$statusMap = [
    'ok'         => ['Correcta',  'bg-emerald-500/10 text-emerald-300 ring-emerald-500/30'],
    'aviso'      => ['Con avisos', 'bg-amber-400/10 text-amber-300 ring-amber-400/30'],
    'warn'       => ['Con avisos', 'bg-amber-400/10 text-amber-300 ring-amber-400/30'],
    'error'      => ['Fallida',   'bg-red-500/10 text-red-300 ring-red-500/30'],
    'fail'       => ['Fallida',   'bg-red-500/10 text-red-300 ring-red-500/30'],
    'en_curso'   => ['En curso',  'bg-sky-400/10 text-sky-300 ring-sky-400/30'],
    'run'        => ['En curso',  'bg-sky-400/10 text-sky-300 ring-sky-400/30'],
];
$statusDefault = ['Desconocido', 'bg-slate-500/10 text-slate-300 ring-slate-500/30'];
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60;url=?r=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Panel de Control de la Intranet - Sede Calatayud</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['"IBM Plex Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                        mono: ['"IBM Plex Mono"', 'ui-monospace', 'monospace'],
                    },
                    colors: {
                        ink:   '#0d1520',
                        panel: '#142031',
                        line:  '#25364c',
                        mute:  '#8fa3bb',
                    },
                },
            },
        };
    </script>
    <style>
        :focus-visible { outline: 2px solid #7fb4ff; outline-offset: 2px; }
        @media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }

        /* ---------- Animación de entrada ---------- */
        #splash { position: fixed; inset: 0; z-index: 100; display: flex; flex-direction: column; align-items: center; justify-content: center;
                  background: radial-gradient(ellipse at 50% 38%, #1a3454 0%, #0d1520 62%);
                  animation: sp-out .7s cubic-bezier(.4,0,.2,1) 3.5s forwards; }
        #splash.sp-skip { animation: sp-out .35s ease forwards; }
        @keyframes sp-out { to { opacity: 0; visibility: hidden; transform: scale(1.03); } }
        .sp-avatar { animation: sp-avatar 1.1s cubic-bezier(.2,.8,.2,1) .15s both; }
        @keyframes sp-avatar { from { opacity: 0; transform: scale(.82); filter: blur(14px); } to { opacity: 1; transform: scale(1); filter: blur(0); } }
        .sp-ring { stroke-dasharray: 516; stroke-dashoffset: 516; animation: sp-ring 2.7s cubic-bezier(.65,0,.35,1) .4s forwards; }
        @keyframes sp-ring { to { stroke-dashoffset: 0; } }
        .sp-halo { animation: sp-halo 3s ease-in-out .4s both; }
        @keyframes sp-halo { 0%,100% { opacity: .0; } 50% { opacity: .55; } }
        .sp-fade { animation: sp-fade .9s cubic-bezier(.2,.8,.2,1) both; }
        @keyframes sp-fade { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }
        .sp-bar > i { display: block; height: 100%; width: 0; border-radius: 9999px; background: linear-gradient(90deg, #4f8fe6, #8fc1ff);
                      animation: sp-bar 2.7s cubic-bezier(.65,0,.35,1) .4s forwards; }
        @keyframes sp-bar { to { width: 100%; } }
        .sp-msg { position: absolute; inset: 0; text-align: center; animation: sp-msg .9s ease both; }
        @keyframes sp-msg { 0% { opacity: 0; transform: translateY(5px); } 18%,82% { opacity: 1; transform: none; } 100% { opacity: 0; } }
        .sp-msg.sp-last { animation: sp-msg-last .5s ease both; }
        @keyframes sp-msg-last { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: none; } }
        @media (prefers-reduced-motion: reduce) { #splash { display: none !important; } }
        @media print { #splash { display: none !important; } }
    </style>
</head>
<body class="bg-ink text-slate-200 font-sans antialiased min-h-screen">
<?php if ($showSplash): ?>
<div id="splash" role="status" aria-label="Cargando el panel">
    <div class="relative h-44 w-44">
        <div class="sp-halo absolute -inset-6 rounded-full bg-sky-400/30 blur-2xl"></div>
        <svg class="absolute inset-0 -rotate-90" viewBox="0 0 176 176" fill="none" aria-hidden="true">
            <circle cx="88" cy="88" r="82" stroke="#25364c" stroke-width="2"/>
            <circle class="sp-ring" cx="88" cy="88" r="82" stroke="#8fc1ff" stroke-width="3" stroke-linecap="round"/>
        </svg>
        <img class="sp-avatar absolute inset-3 h-[8.5rem] w-[8.5rem] rounded-full object-cover ring-1 ring-white/10 shadow-2xl shadow-black/60"
             style="width:9.5rem;height:9.5rem" src="<?= $splashImg ?>" alt="">
    </div>

    <p class="sp-fade mt-9 text-sm text-mute" style="animation-delay:.7s">Sede Calatayud</p>
    <h2 class="sp-fade mt-1 px-6 text-center text-2xl font-semibold tracking-tight text-white sm:text-3xl" style="animation-delay:.9s">
        Panel de Control de la Intranet
    </h2>
    <p class="sp-fade mt-3 text-base text-slate-300" style="animation-delay:1.2s">
        Bienvenido<?= $user !== null ? ', <span class="font-medium text-white">' . h($user) . '</span>' : '' ?>
    </p>

    <div class="sp-fade mt-10 w-64" style="animation-delay:1.4s">
        <div class="sp-bar h-1 overflow-hidden rounded-full bg-line"><i></i></div>
        <div class="relative mt-3 h-5 text-xs text-mute">
            <span class="sp-msg" style="animation-delay:.4s">Verificando credenciales</span>
            <span class="sp-msg" style="animation-delay:1.3s">Conectando con PostgreSQL</span>
            <span class="sp-msg sp-last" style="animation-delay:2.2s">Panel listo</span>
        </div>
    </div>

    <button type="button" id="splash-skip"
            class="absolute bottom-6 right-6 rounded-md px-3 py-1.5 text-sm text-mute transition-colors hover:text-white focus-visible:text-white">
        Omitir
    </button>
</div>
<script>
    (function () {
        var el = document.getElementById('splash');
        if (!el) return;
        var done = function () { el.classList.add('sp-skip'); };
        document.getElementById('splash-skip').addEventListener('click', done);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') done(); });
        el.addEventListener('animationend', function (e) {
            if (e.target === el && e.animationName === 'sp-out') el.remove();
        });
    })();
</script>
<?php endif; ?>
<div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 lg:py-10 space-y-6">

    <!-- 1. Cabecera -->
    <header class="rounded-2xl border border-line bg-gradient-to-br from-[#182b44] to-panel p-6 sm:p-8">
        <div class="flex flex-col gap-6 lg:flex-row lg:items-end lg:justify-between">
            <div class="max-w-xl">
                <h1 class="text-2xl sm:text-3xl font-semibold tracking-tight text-white">
                    Panel de Control de la Intranet - Sede Calatayud
                </h1>
                <p class="mt-2 text-sm text-mute">
                    Estado del servidor, la base de datos y las tareas programadas de un vistazo.
                </p>
            </div>
            <dl class="grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-3 text-sm">
                <div>
                    <dt class="text-mute">Sesión iniciada como</dt>
                    <dd class="mt-0.5 font-medium text-white"><?= $user !== null ? h($user) : '<span class="text-amber-300">Sin autenticar</span>' ?></dd>
                </div>
                <div>
                    <dt class="text-mute">Tu dirección IP</dt>
                    <dd class="mt-0.5 font-mono text-white"><?= h($clientIp) ?></dd>
                </div>
                <div>
                    <dt class="text-mute">Hora del servidor</dt>
                    <dd class="mt-0.5 font-mono text-white"><?= h($now->format('d/m/Y H:i:s')) ?></dd>
                </div>
            </dl>
        </div>
    </header>

    <div class="grid gap-6 lg:grid-cols-3">

        <!-- 2. Estado de PostgreSQL -->
        <section class="rounded-xl border bg-panel p-5 <?= $dbOk ? 'border-emerald-500/40' : 'border-red-500/50' ?>" aria-labelledby="db-title">
            <h2 id="db-title" class="text-base font-semibold text-white">Base de datos PostgreSQL</h2>
            <div class="mt-4 flex items-center gap-3">
                <span class="relative flex h-3.5 w-3.5">
                    <?php if ($dbOk): ?>
                        <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-50"></span>
                        <span class="relative inline-flex h-3.5 w-3.5 rounded-full bg-emerald-500"></span>
                    <?php else: ?>
                        <span class="relative inline-flex h-3.5 w-3.5 rounded-full bg-red-500"></span>
                    <?php endif; ?>
                </span>
                <p class="text-lg font-semibold <?= $dbOk ? 'text-emerald-400' : 'text-red-400' ?>">
                    <?= $dbOk ? 'Servicio DB Activo' : 'Servicio DB Sin Conexión' ?>
                </p>
            </div>
            <?php if ($dbOk): ?>
                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex justify-between gap-4">
                        <dt class="text-mute">Motor</dt>
                        <dd class="font-mono text-right" title="<?= h($dbVersionFull) ?>"><?= h($dbVersion) ?></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-mute">Base de datos</dt>
                        <dd class="font-mono"><?= h($dbName) ?></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-mute">Servidor</dt>
                        <dd class="font-mono"><?= h($dbHost) ?>:<?= $dbPort ?></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-mute">Tiempo de conexión</dt>
                        <dd class="font-mono"><?= number_format((float)$dbLatencyMs, 1, ',', '.') ?> ms</dd>
                    </div>
                </dl>
                <p class="mt-4 break-words text-xs text-mute"><?= h($dbVersionFull) ?></p>
            <?php else: ?>
                <p class="mt-4 text-sm text-slate-300">
                    No se pudo abrir la conexión con <span class="font-mono"><?= h($dbHost) ?>:<?= $dbPort ?></span>.
                    Revisa que el contenedor de PostgreSQL esté en marcha y que las variables
                    <span class="font-mono">DB_HOST</span>, <span class="font-mono">DB_NOMBRE</span>,
                    <span class="font-mono">DB_USUARIO</span> y <span class="font-mono">DB_CLAVE</span> sean correctas.
                    El detalle técnico está en el log de Apache.
                </p>
            <?php endif; ?>
        </section>

        <!-- 3. Recursos del servidor -->
        <section class="rounded-xl border border-line bg-panel p-5 lg:col-span-2" aria-labelledby="res-title">
            <h2 id="res-title" class="text-base font-semibold text-white">Recursos del servidor</h2>

            <div class="mt-4 grid gap-6 md:grid-cols-2">
                <!-- Disco -->
                <div>
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="text-mute">Disco (<span class="font-mono"><?= h($path) ?></span>)</span>
                        <span class="font-mono text-white"><?= $diskOk ? $diskPct . ' %' : 'n/d' ?></span>
                    </div>
                    <div class="mt-2 h-3 overflow-hidden rounded-full bg-ink" role="progressbar"
                         aria-label="Uso de disco" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$diskPct ?>">
                        <div class="h-full rounded-full <?= bar_color($diskPct) ?>" style="width: <?= min(100, $diskPct) ?>%"></div>
                    </div>
                    <?php if ($diskOk): ?>
                        <p class="mt-2 text-sm text-mute">
                            <span class="text-white"><?= h(fmt_bytes($diskFree)) ?></span> libres de
                            <?= h(fmt_bytes($diskTotal)) ?> (<?= h(fmt_bytes($diskUsed)) ?> en uso)
                        </p>
                    <?php else: ?>
                        <p class="mt-2 text-sm text-amber-300">No se pudo leer el espacio en disco.</p>
                    <?php endif; ?>
                </div>

                <!-- Memoria -->
                <div>
                    <div class="flex items-baseline justify-between text-sm">
                        <span class="text-mute">Memoria RAM</span>
                        <span class="font-mono text-white"><?= $memOk ? $memPct . ' %' : 'n/d' ?></span>
                    </div>
                    <div class="mt-2 h-3 overflow-hidden rounded-full bg-ink" role="progressbar"
                         aria-label="Uso de memoria" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$memPct ?>">
                        <div class="h-full rounded-full <?= bar_color($memPct) ?>" style="width: <?= min(100, $memPct) ?>%"></div>
                    </div>
                    <?php if ($memOk): ?>
                        <p class="mt-2 text-sm text-mute">
                            <span class="text-white"><?= h(fmt_bytes($memAvail)) ?></span> disponibles de <?= h(fmt_bytes($memTotal)) ?>
                        </p>
                    <?php else: ?>
                        <p class="mt-2 text-sm text-amber-300">No se pudo leer /proc/meminfo.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Carga -->
            <div class="mt-6 border-t border-line pt-5">
                <div class="flex items-baseline justify-between text-sm">
                    <span class="text-mute">Carga del sistema (<?= $cores ?> <?= $cores === 1 ? 'núcleo' : 'núcleos' ?>)</span>
                </div>
                <?php if (is_array($load)): ?>
                    <div class="mt-3 grid grid-cols-3 gap-3">
                        <?php foreach ([['1 min', $load[0]], ['5 min', $load[1]], ['15 min', $load[2]]] as [$label, $val]):
                            $loadPct = min(100, $val / $cores * 100); ?>
                            <div class="rounded-lg bg-ink p-3">
                                <p class="text-xs text-mute"><?= $label ?></p>
                                <p class="mt-1 font-mono text-xl text-white"><?= number_format((float)$val, 2, ',', '.') ?></p>
                                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-line">
                                    <div class="h-full rounded-full <?= bar_color($loadPct) ?>" style="width: <?= round($loadPct) ?>%"></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="mt-2 text-sm text-amber-300">sys_getloadavg() no está disponible en este sistema.</p>
                <?php endif; ?>
            </div>

            <!-- Entorno -->
            <dl class="mt-6 grid gap-3 border-t border-line pt-5 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-mute">Versión de PHP</dt>
                    <dd class="mt-0.5 font-mono text-white"><?= h(phpversion()) ?></dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-mute">Servidor web</dt>
                    <dd class="mt-0.5 font-mono text-white break-words"><?= h($serverSoftware) ?></dd>
                </div>
            </dl>
        </section>
    </div>

    <!-- 4. Puertos y servicios -->
    <section class="rounded-xl border border-line bg-panel p-5" aria-labelledby="svc-title">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="svc-title" class="text-base font-semibold text-white">Servicios internos</h2>
            <p class="text-sm text-mute">
                <?= $servicesUp ?> de <?= count($services) ?> respondiendo
            </p>
        </div>
        <ul class="mt-4 grid gap-3 sm:grid-cols-2">
            <?php foreach ($services as $s): ?>
                <li class="flex items-center justify-between gap-4 rounded-lg bg-ink px-4 py-3">
                    <div class="min-w-0">
                        <p class="font-medium text-white"><?= h($s['name']) ?></p>
                        <p class="font-mono text-xs text-mute"><?= h($s['host']) ?>:<?= (int)$s['port'] ?></p>
                    </div>
                    <div class="text-right">
                        <span class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset
                            <?= $s['up'] ? 'bg-emerald-500/10 text-emerald-300 ring-emerald-500/30' : 'bg-red-500/10 text-red-300 ring-red-500/30' ?>">
                            <span class="h-1.5 w-1.5 rounded-full <?= $s['up'] ? 'bg-emerald-400' : 'bg-red-400' ?>"></span>
                            <?= $s['up'] ? 'Responde' : 'Sin respuesta' ?>
                        </span>
                        <p class="mt-1 font-mono text-xs text-mute"><?= number_format($s['ms'], 1, ',', '.') ?> ms</p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <!-- 5. Tareas y auditoría -->
    <section class="rounded-xl border border-line bg-panel p-5" aria-labelledby="task-title">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 id="task-title" class="text-base font-semibold text-white">Tareas programadas y registro de auditoría</h2>
            <?php if ($tasksDemo): ?>
                <p class="text-sm text-amber-300">Datos de demostración: crea la tabla <span class="font-mono">auditoria_tareas</span> para ver registros reales.</p>
            <?php else: ?>
                <p class="text-sm text-mute">Últimas <?= count($tasks) ?> ejecuciones</p>
            <?php endif; ?>
        </div>

        <div class="mt-4 overflow-x-auto">
            <table class="w-full min-w-[40rem] text-left text-sm">
                <thead class="text-mute">
                    <tr class="border-b border-line">
                        <th scope="col" class="py-2 pr-4 font-medium">Tarea</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Última ejecución</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Estado</th>
                        <th scope="col" class="py-2 font-medium">Detalle</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <?php foreach ($tasks as $t):
                        [$label, $classes] = $statusMap[strtolower($t['status'])] ?? $statusDefault; ?>
                        <tr>
                            <td class="py-3 pr-4 align-top">
                                <p class="font-medium text-white"><?= h($t['name']) ?></p>
                                <?php if (!empty($t['cron'])): ?>
                                    <p class="font-mono text-xs text-mute"><?= h($t['cron']) ?></p>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 pr-4 align-top font-mono text-slate-300"><?= h($t['when']->format('d/m/Y H:i')) ?></td>
                            <td class="py-3 pr-4 align-top">
                                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset <?= $classes ?>"><?= h($label) ?></span>
                            </td>
                            <td class="py-3 align-top text-slate-300"><?= h($t['detail']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <footer class="pb-4 text-center text-xs text-mute">
        La página se actualiza sola cada 60 segundos · Generada a las <?= h($now->format('H:i:s')) ?>
    </footer>
</div>
</body>
</html>
