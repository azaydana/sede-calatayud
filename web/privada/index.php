<?php
declare(strict_types=1);

/**
 * Intranet Calatayud - Panel de Control
 * PHP 8.2 + Apache + PostgreSQL 16 (PDO).
 * Variables de entorno: DB_HOST, DB_NOMBRE, DB_USUARIO, DB_CLAVE (opcional DB_PORT, por defecto 5432).
 */

ini_set('display_errors', '0');
date_default_timezone_set('Europe/Madrid');

/* =====================================================================
 *  Utilidades
 * ===================================================================== */

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

function fmt_duration(int $s): string
{
    $d = intdiv($s, 86400);
    $hh = intdiv($s % 86400, 3600);
    $m = intdiv($s % 3600, 60);
    return $d > 0 ? "{$d} d {$hh} h" : ($hh > 0 ? "{$hh} h {$m} min" : "{$m} min");
}

function rel_time(DateTimeImmutable $when, DateTimeImmutable $now): string
{
    $s = max(0, $now->getTimestamp() - $when->getTimestamp());
    return match (true) {
        $s < 90        => 'hace un momento',
        $s < 3600      => 'hace ' . intdiv($s, 60) . ' min',
        $s < 86400     => 'hace ' . intdiv($s, 3600) . ' h',
        default        => 'hace ' . intdiv($s, 86400) . ' d',
    };
}

/** Color hexadecimal según el nivel de uso. */
function level_hex(?float $pct): string
{
    return match (true) {
        $pct === null => '#5b6f8a',
        $pct >= 90    => '#f87171',
        $pct >= 70    => '#fbbf24',
        default       => '#34d399',
    };
}

/** Medidor circular SVG. $pct null = dato no disponible. */
function gauge(?float $pct, string $center, string $title, string $detail): string
{
    $c = 2 * M_PI * 44;
    $dash = $pct === null ? 0 : round($c * min(100, max(0, $pct)) / 100, 2);
    $cs = round($c, 2);
    $hex = level_hex($pct);
    return '<div class="flex flex-col items-center text-center">'
        . '<div class="relative h-32 w-32">'
        . '<svg viewBox="0 0 100 100" class="h-full w-full -rotate-90" aria-hidden="true">'
        . '<circle cx="50" cy="50" r="44" fill="none" stroke="#1d3050" stroke-width="6"/>'
        . '<circle class="gauge" cx="50" cy="50" r="44" fill="none" stroke="' . $hex . '" stroke-width="6" stroke-linecap="round" '
        . 'style="stroke-dasharray:' . $dash . ' ' . $cs . '"/>'
        . '</svg>'
        . '<div class="absolute inset-0 flex items-center justify-center text-2xl font-semibold tabular-nums text-white">' . h($center) . '</div>'
        . '</div>'
        . '<p class="mt-3 text-sm font-medium text-white">' . h($title) . '</p>'
        . '<p class="mt-0.5 text-xs leading-5 text-mute">' . h($detail) . '</p>'
        . '</div>';
}

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

function normalize_status(string $s): string
{
    return match (strtolower(trim($s))) {
        'ok', 'correcta', 'success'     => 'ok',
        'aviso', 'warn', 'warning'      => 'warn',
        'error', 'fail', 'failed'       => 'fail',
        'en_curso', 'run', 'running'    => 'run',
        default                         => 'unknown',
    };
}

/* =====================================================================
 *  Sesión y cabecera
 * ===================================================================== */

$user     = $_SERVER['PHP_AUTH_USER'] ?? $_SERVER['REMOTE_USER'] ?? null;
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'desconocida';
$now      = new DateTimeImmutable('now');
$nowMs    = (int)(microtime(true) * 1000);

// Imagen de la animación de entrada y del avatar (JPEG 320x320 incrustado).
$faceImg = 'data:image/jpeg;base64,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';

/* =====================================================================
 *  PostgreSQL
 * ===================================================================== */

$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = (int)(getenv('DB_PORT') ?: 5432);
$dbName = getenv('DB_NOMBRE') ?: '';
$dbUser = getenv('DB_USUARIO') ?: '';
$dbPass = getenv('DB_CLAVE') ?: '';

$dbOk = false;
$dbVersion = '';
$dbVersionFull = '';
$dbLatencyMs = null;
$dbSize = $dbConns = $dbMaxConns = $dbUptime = null;
$pdo = null;

try {
    $t0 = microtime(true);
    $pdo = new PDO(
        "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName};connect_timeout=3",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $dbVersionFull = (string)$pdo->query('SELECT version();')->fetchColumn();
    $dbLatencyMs = (microtime(true) - $t0) * 1000;
    $dbOk = true;
    $dbVersion = preg_match('/PostgreSQL\s+[\d.]+/', $dbVersionFull, $m) ? $m[0] : $dbVersionFull;
} catch (Throwable $e) {
    error_log('[intranet-panel] Error de conexión PDO: ' . $e->getMessage());
}

if ($dbOk && $pdo) {
    try {
        $dbSize     = (string)$pdo->query('SELECT pg_size_pretty(pg_database_size(current_database()))')->fetchColumn();
        $dbConns    = (int)$pdo->query('SELECT count(*) FROM pg_stat_activity WHERE datname = current_database()')->fetchColumn();
        $dbMaxConns = (int)$pdo->query('SHOW max_connections')->fetchColumn();
        $dbUptime   = (int)$pdo->query('SELECT extract(epoch FROM now() - pg_postmaster_start_time())::bigint')->fetchColumn();
    } catch (Throwable $e) {
        error_log('[intranet-panel] Métricas extra de PostgreSQL no disponibles: ' . $e->getMessage());
    }
}

/* =====================================================================
 *  Recursos del servidor
 * ===================================================================== */

$path      = '/var/www/html';
$diskFree  = @disk_free_space($path);
$diskTotal = @disk_total_space($path);
$diskOk    = $diskFree !== false && $diskTotal !== false && $diskTotal > 0;
$diskUsed  = $diskOk ? $diskTotal - $diskFree : 0;
$diskPct   = $diskOk ? round($diskUsed / $diskTotal * 100, 1) : null;

$load  = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;
$cores = 1;
if (is_readable('/proc/cpuinfo')) {
    $cores = max(1, preg_match_all('/^processor\s*:/m', (string)file_get_contents('/proc/cpuinfo')));
}
$loadPct = is_array($load) ? round(min(100, $load[0] / $cores * 100), 1) : null;

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
$memPct = $memOk ? round(($memTotal - $memAvail) / $memTotal * 100, 1) : null;

$uptimeSec = null;
if (is_readable('/proc/uptime')) {
    $uptimeSec = (int)explode(' ', trim((string)file_get_contents('/proc/uptime')))[0];
}

$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'No disponible';
$hostname       = gethostname() ?: 'No disponible';
$osName         = php_uname('s') . ' ' . php_uname('r');

/* =====================================================================
 *  Puertos y servicios
 * ===================================================================== */

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

/* =====================================================================
 *  Tareas y auditoría
 *  Si existe public.auditoria_tareas (tarea, estado, ejecutado_en, detalle) se usa; si no, datos de demostración.
 * ===================================================================== */

$tasks = [];
$tasksDemo = true;

if ($dbOk && $pdo) {
    try {
        if ($pdo->query("SELECT to_regclass('public.auditoria_tareas')")->fetchColumn()) {
            $rows = $pdo->query(
                'SELECT tarea, estado, ejecutado_en, detalle FROM public.auditoria_tareas ORDER BY ejecutado_en DESC LIMIT 10'
            )->fetchAll();
            foreach ($rows as $r) {
                $tasks[] = [
                    'name'   => (string)$r['tarea'],
                    'when'   => new DateTimeImmutable((string)$r['ejecutado_en']),
                    'status' => normalize_status((string)$r['estado']),
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
        ['name' => 'Informe de inventario',            'cron' => '*/15 * * * *', 'when' => $ago('-1 minute'),   'status' => 'run',  'detail' => 'Procesando la sede de Calatayud'],
        ['name' => 'Sincronización de usuarios LDAP',  'cron' => '*/30 * * * *', 'when' => $ago('-12 minutes'), 'status' => 'warn', 'detail' => '2 cuentas sin correo, omitidas'],
        ['name' => 'Copia de seguridad de PostgreSQL', 'cron' => '0 2 * * *',    'when' => $ago('-8 hours'),    'status' => 'ok',   'detail' => 'Volcado completo de 1,8 GB comprimidos'],
        ['name' => 'Rotación de logs de Apache',       'cron' => '30 0 * * *',   'when' => $ago('-10 hours'),   'status' => 'ok',   'detail' => '14 archivos rotados'],
        ['name' => 'Renovación de certificados TLS',   'cron' => '0 4 * * 1',    'when' => $ago('-2 days'),     'status' => 'ok',   'detail' => 'Caducan dentro de 61 días'],
        ['name' => 'Limpieza de archivos temporales',  'cron' => '0 3 * * 0',    'when' => $ago('-3 days'),     'status' => 'fail', 'detail' => 'Sin permisos en /var/tmp/informes'],
    ];
}

$taskCounts = ['ok' => 0, 'warn' => 0, 'fail' => 0, 'run' => 0, 'unknown' => 0];
foreach ($tasks as $t) {
    $taskCounts[$t['status']]++;
}
$taskLabels = [
    'ok'      => ['Correcta',    'bg-emerald-400/10 text-emerald-300 ring-emerald-400/25', 'bg-emerald-400'],
    'warn'    => ['Con avisos',  'bg-amber-400/10 text-amber-300 ring-amber-400/25',       'bg-amber-400'],
    'fail'    => ['Fallida',     'bg-red-400/10 text-red-300 ring-red-400/25',             'bg-red-400'],
    'run'     => ['En curso',    'bg-sky-400/10 text-sky-300 ring-sky-400/25',             'bg-sky-400'],
    'unknown' => ['Desconocido', 'bg-slate-400/10 text-slate-300 ring-slate-400/25',       'bg-slate-400'],
];

/* =====================================================================
 *  Estado global
 * ===================================================================== */

$issues = [];
if (!$dbOk)                                  $issues[] = 'PostgreSQL no responde';
if ($servicesUp < count($services))          $issues[] = 'Hay servicios sin respuesta';
if ($diskPct !== null && $diskPct >= 90)     $issues[] = 'Disco casi lleno';
if ($taskCounts['fail'] > 0)                 $issues[] = $taskCounts['fail'] . ' tarea(s) fallida(s)';
$critical = !$dbOk || $servicesUp < count($services);
$healthClass = $issues === [] ? 'bg-emerald-400/10 text-emerald-300 ring-emerald-400/25'
    : ($critical ? 'bg-red-400/10 text-red-300 ring-red-400/25' : 'bg-amber-400/10 text-amber-300 ring-amber-400/25');
$healthDot = $issues === [] ? 'bg-emerald-400' : ($critical ? 'bg-red-400' : 'bg-amber-400');
$healthText = $issues === [] ? 'Todos los sistemas funcionan con normalidad' : implode(' · ', $issues);
?>
<!DOCTYPE html>
<html lang="es" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Intranet Calatayud</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
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
                        ink:   '#0a1424',
                        panel: '#0f1d33',
                        line:  '#1d3050',
                        mute:  '#8da2bd',
                        brass: '#d9b878',
                    },
                },
            },
        };
    </script>
    <style>
        :root { --face: url("<?= $faceImg ?>"); --gd: 4.3s; }
        html.sp-done { --gd: 0s; }
        html { color-scheme: dark; }
        body {
            background-color: #0a1424;
            background-image: radial-gradient(1100px 420px at 50% -8%, rgba(110,168,254,.10), transparent 70%);
            background-repeat: no-repeat;
        }
        :focus-visible { outline: 2px solid #8fc1ff; outline-offset: 2px; }
        .tnum { font-variant-numeric: tabular-nums; }

        /* ---------- Medidores ---------- */
        .gauge { animation: gauge-in 1.4s cubic-bezier(.2,.7,.2,1) var(--gd) both; }
        @keyframes gauge-in { from { stroke-dasharray: 0 277; } }
        #live.refreshed .gauge { animation: none; }
        .spin { animation: spin 0.9s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ---------- Animación de entrada (solo al abrir la intranet) ---------- */
        #splash {
            position: fixed; inset: 0; z-index: 100;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: radial-gradient(ellipse at 50% 42%, #14284a 0%, #0a1424 68%);
            clip-path: inset(0 0 0 0);
            animation: sp-wipe .95s cubic-bezier(.76,0,.24,1) 4.2s forwards;
        }
        #splash.sp-skip { animation: sp-wipe .6s cubic-bezier(.76,0,.24,1) forwards; }
        @keyframes sp-wipe { from { clip-path: inset(0 0 0 0); } to { clip-path: inset(0 0 100% 0); visibility: hidden; } }

        #splash::before {
            content: ""; position: absolute; inset: 0; pointer-events: none;
            background-image:
                linear-gradient(rgba(143,193,255,.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(143,193,255,.06) 1px, transparent 1px);
            background-size: 56px 56px;
            -webkit-mask-image: radial-gradient(ellipse at center, #000 0%, transparent 70%);
                    mask-image: radial-gradient(ellipse at center, #000 0%, transparent 70%);
            animation: sp-fade 1.6s ease .1s both;
        }
        @keyframes sp-fade { from { opacity: 0; } to { opacity: 1; } }

        .sp-stage { position: relative; width: 14rem; height: 14rem; }
        .sp-halo  { position: absolute; inset: -2.5rem; border-radius: 9999px; background: radial-gradient(circle, rgba(110,168,254,.35), transparent 65%);
                    animation: sp-halo 3.2s ease-in-out .5s both; }
        @keyframes sp-halo { 0%,100% { opacity: 0; transform: scale(.9); } 55% { opacity: 1; transform: scale(1.05); } }

        .sp-photo {
            position: absolute; inset: 1.1rem; border-radius: 9999px;
            background: var(--face) center / cover no-repeat;
            box-shadow: 0 25px 60px -15px rgba(0,0,0,.7);
            animation: sp-photo 1.5s cubic-bezier(.2,.7,.2,1) .25s both;
        }
        @keyframes sp-photo {
            from { clip-path: circle(0% at 50% 50%); transform: scale(1.3); filter: blur(10px) saturate(.6); }
            to   { clip-path: circle(50% at 50% 50%); transform: scale(1); filter: none; }
        }
        .sp-ring  { stroke-dasharray: 690; stroke-dashoffset: 690; animation: sp-ring 2.4s cubic-bezier(.65,0,.35,1) .6s forwards; }
        @keyframes sp-ring { to { stroke-dashoffset: 0; } }
        .sp-ticks { transform-origin: 50% 50%; animation: sp-fade 1s ease 1.2s both, spin 40s linear infinite; }

        .sp-mask  { display: block; overflow: hidden; padding-bottom: .12em; }
        .sp-rise  { display: block; transform: translateY(115%); animation: sp-rise 1s cubic-bezier(.2,.8,.2,1) both; }
        @keyframes sp-rise { to { transform: none; } }
        .sp-line  { transform: scaleX(0); transform-origin: left; animation: sp-line 1s cubic-bezier(.65,0,.35,1) 2.9s forwards; }
        @keyframes sp-line { to { transform: scaleX(1); } }
        .sp-sub   { opacity: 0; animation: sp-fade .9s ease 3.15s forwards; }
        .sp-load  { position: absolute; left: 0; bottom: 0; height: 2px; width: 100%; transform-origin: left; transform: scaleX(0);
                    background: linear-gradient(90deg, #d9b878, #6ea8fe);
                    animation: sp-load 3.9s cubic-bezier(.45,0,.2,1) .2s forwards; }
        @keyframes sp-load { to { transform: scaleX(1); } }

        @media (max-width: 480px) { .sp-stage { width: 11.5rem; height: 11.5rem; } .sp-photo { inset: .9rem; } }
        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
            #splash { display: none !important; }
        }
        @media print { #splash { display: none !important; } }
    </style>
</head>
<body class="min-h-screen bg-ink font-sans text-slate-200 antialiased">

<!-- ===== Animación de entrada ===== -->
<div id="splash" role="status" aria-label="Abriendo la Intranet Calatayud">
    <div class="sp-stage">
        <div class="sp-halo"></div>
        <svg class="absolute inset-0 h-full w-full -rotate-90" viewBox="0 0 224 224" fill="none" aria-hidden="true">
            <defs>
                <linearGradient id="spg" x1="0" y1="0" x2="224" y2="224" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stop-color="#d9b878"/>
                    <stop offset="1" stop-color="#6ea8fe"/>
                </linearGradient>
            </defs>
            <circle cx="112" cy="112" r="109" stroke="#1d3050" stroke-width="1.5"/>
            <circle class="sp-ring" cx="112" cy="112" r="109" stroke="url(#spg)" stroke-width="2.5" stroke-linecap="round"/>
            <g class="sp-ticks">
                <circle cx="112" cy="112" r="101" stroke="#8fc1ff" stroke-opacity=".35" stroke-width="1" stroke-dasharray="1 9"/>
            </g>
        </svg>
        <div class="sp-photo" aria-hidden="true"></div>
    </div>

    <div class="mt-10 px-6 text-center">
        <h2 class="text-white">
            <span class="sp-mask text-lg font-light tracking-[.35em] text-slate-300 sm:text-xl"><span class="sp-rise" style="animation-delay:2.1s">Intranet</span></span>
            <span class="sp-mask text-4xl font-semibold tracking-tight sm:text-6xl"><span class="sp-rise" style="animation-delay:2.3s">Calatayud</span></span>
        </h2>
        <div class="sp-line mx-auto mt-4 h-px w-40 bg-gradient-to-r from-transparent via-brass to-transparent"></div>
        <p class="sp-sub mt-4 text-sm text-mute">Panel de control corporativo</p>
    </div>

    <div class="sp-load" aria-hidden="true"></div>
    <button type="button" id="splash-skip"
            class="absolute bottom-6 right-6 rounded-md px-3 py-1.5 text-sm text-mute transition-colors hover:text-white focus-visible:text-white">
        Saltar introducción
    </button>
</div>

<!-- ===== Barra superior ===== -->
<header class="sticky top-0 z-40 border-b border-line bg-ink/80 backdrop-blur">
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
        <div class="flex items-center gap-3">
            <span class="h-9 w-9 shrink-0 rounded-full bg-cover bg-center ring-2 ring-brass/60" style="background-image: var(--face)" aria-hidden="true"></span>
            <p class="leading-tight">
                <span class="block text-xs font-light tracking-[.25em] text-slate-400">Intranet</span>
                <span class="block text-base font-semibold text-white">Calatayud</span>
            </p>
        </div>
        <div class="flex items-center gap-3 text-sm">
            <p id="upd" class="hidden text-mute sm:block" aria-live="polite">Actualizado <span class="tnum" data-clock="time"><?= h($now->format('H:i:s')) ?></span></p>
            <button type="button" id="refresh"
                    class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-1.5 text-slate-200 transition-colors hover:border-sky-400/50 hover:bg-white/5">
                <svg id="refresh-icon" class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 4v5h-5"/></svg>
                Actualizar
            </button>
            <span class="hidden items-center gap-2 rounded-full border border-line py-1 pl-1 pr-3 sm:inline-flex">
                <span class="h-6 w-6 rounded-full bg-cover bg-center" style="background-image: var(--face)" aria-hidden="true"></span>
                <span class="text-slate-200"><?= $user !== null ? h($user) : 'Sin autenticar' ?></span>
            </span>
        </div>
    </div>
</header>

<main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 sm:py-10">
<div id="live" class="space-y-10">

    <!-- ===== 1. Banner de bienvenida ===== -->
    <section aria-labelledby="hero-title">
        <h1 id="hero-title" class="max-w-3xl text-3xl font-semibold tracking-tight text-white sm:text-4xl">
            Panel de Control de la Intranet - Sede Calatayud
        </h1>
        <p class="mt-4 inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-sm ring-1 ring-inset <?= $healthClass ?>">
            <span class="h-2 w-2 rounded-full <?= $healthDot ?>"></span><?= h($healthText) ?>
        </p>

        <dl class="mt-8 grid gap-px overflow-hidden rounded-xl bg-line sm:grid-cols-3">
            <div class="bg-panel px-5 py-4">
                <dt class="text-sm text-mute">Sesión iniciada como</dt>
                <dd class="mt-1 flex items-center gap-3 text-lg font-medium text-white">
                    <span class="h-8 w-8 rounded-full bg-cover bg-center ring-1 ring-white/15" style="background-image: var(--face)" aria-hidden="true"></span>
                    <?= $user !== null ? h($user) : '<span class="text-amber-300">Sin autenticar</span>' ?>
                </dd>
            </div>
            <div class="bg-panel px-5 py-4">
                <dt class="text-sm text-mute">Tu dirección IP</dt>
                <dd class="mt-1 font-mono text-lg text-white"><?= h($clientIp) ?></dd>
            </div>
            <div class="bg-panel px-5 py-4">
                <dt class="text-sm text-mute">Fecha y hora del servidor</dt>
                <dd class="tnum mt-1 text-lg text-white" data-clock="full"><?= h($now->format('d/m/Y H:i:s')) ?></dd>
            </div>
        </dl>
    </section>

    <!-- ===== Resumen ===== -->
    <section aria-label="Resumen del estado" class="grid grid-cols-2 gap-px overflow-hidden rounded-xl bg-line lg:grid-cols-4">
        <div class="bg-panel px-5 py-5">
            <p class="text-sm text-mute">Base de datos</p>
            <p class="mt-2 flex items-center gap-2 text-xl font-semibold <?= $dbOk ? 'text-emerald-300' : 'text-red-300' ?>">
                <span class="h-2.5 w-2.5 rounded-full <?= $dbOk ? 'bg-emerald-400' : 'bg-red-400' ?>"></span><?= $dbOk ? 'Activa' : 'Sin conexión' ?>
            </p>
            <p class="mt-1 text-sm text-mute"><?= $dbOk ? h($dbVersion) : 'Revisa el contenedor' ?></p>
        </div>
        <div class="bg-panel px-5 py-5">
            <p class="text-sm text-mute">Servicios internos</p>
            <p class="tnum mt-2 text-xl font-semibold text-white"><?= $servicesUp ?> de <?= count($services) ?></p>
            <p class="mt-1 text-sm text-mute">respondiendo</p>
        </div>
        <div class="bg-panel px-5 py-5">
            <p class="text-sm text-mute">Disco en uso</p>
            <p class="tnum mt-2 text-xl font-semibold text-white"><?= $diskPct !== null ? number_format($diskPct, 1, ',', '.') . ' %' : 'n/d' ?></p>
            <p class="mt-1 text-sm text-mute"><?= $diskOk ? h(fmt_bytes($diskFree)) . ' libres' : 'Sin lectura' ?></p>
        </div>
        <div class="bg-panel px-5 py-5">
            <p class="text-sm text-mute">Tareas correctas</p>
            <p class="tnum mt-2 text-xl font-semibold text-white"><?= $taskCounts['ok'] ?> de <?= count($tasks) ?></p>
            <p class="mt-1 text-sm text-mute"><?= $taskCounts['fail'] > 0 ? $taskCounts['fail'] . ' con fallo' : 'Sin fallos' ?></p>
        </div>
    </section>

    <!-- ===== Servidor y base de datos ===== -->
    <div class="grid gap-10 lg:grid-cols-5">

        <!-- Recursos -->
        <section class="lg:col-span-3" aria-labelledby="res-title">
            <h2 id="res-title" class="text-xl font-semibold text-white">Recursos del servidor</h2>
            <p class="mt-1 text-sm text-mute">Lectura en tiempo real del contenedor.</p>

            <div class="mt-6 grid grid-cols-1 gap-8 rounded-xl border border-line bg-panel/60 p-6 sm:grid-cols-3">
                <?= gauge(
                    $diskPct,
                    $diskPct !== null ? round($diskPct) . '%' : 'n/d',
                    'Disco',
                    $diskOk ? fmt_bytes($diskFree) . ' libres de ' . fmt_bytes($diskTotal) : 'No se pudo leer ' . $path
                ) ?>
                <?= gauge(
                    $memPct,
                    $memPct !== null ? round($memPct) . '%' : 'n/d',
                    'Memoria',
                    $memOk ? fmt_bytes($memAvail) . ' libres de ' . fmt_bytes($memTotal) : 'No se pudo leer /proc/meminfo'
                ) ?>
                <?= gauge(
                    $loadPct,
                    is_array($load) ? number_format($load[0], 2, ',', '.') : 'n/d',
                    'Carga del sistema',
                    is_array($load)
                        ? '5 min: ' . number_format($load[1], 2, ',', '.') . ' · 15 min: ' . number_format($load[2], 2, ',', '.') . ' · ' . $cores . ' ' . ($cores === 1 ? 'núcleo' : 'núcleos')
                        : 'sys_getloadavg() no disponible'
                ) ?>
            </div>

            <dl class="mt-6 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
                <div class="flex justify-between gap-4 border-b border-line pb-3">
                    <dt class="text-mute">Versión de PHP</dt><dd class="text-white"><?= h(phpversion()) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-line pb-3">
                    <dt class="text-mute">Servidor web</dt><dd class="text-right text-white"><?= h($serverSoftware) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-line pb-3">
                    <dt class="text-mute">Sistema operativo</dt><dd class="text-right text-white"><?= h($osName) ?></dd>
                </div>
                <div class="flex justify-between gap-4 border-b border-line pb-3">
                    <dt class="text-mute">Nombre del equipo</dt><dd class="font-mono text-white"><?= h($hostname) ?></dd>
                </div>
                <?php if ($uptimeSec !== null): ?>
                <div class="flex justify-between gap-4 border-b border-line pb-3">
                    <dt class="text-mute">Tiempo encendido</dt><dd class="text-white"><?= h(fmt_duration($uptimeSec)) ?></dd>
                </div>
                <?php endif; ?>
                <div class="flex justify-between gap-4 border-b border-line pb-3">
                    <dt class="text-mute">Memoria máxima por script</dt><dd class="text-white"><?= h((string)ini_get('memory_limit')) ?></dd>
                </div>
            </dl>
        </section>

        <!-- Base de datos + servicios -->
        <div class="space-y-10 lg:col-span-2">
            <section aria-labelledby="db-title">
                <h2 id="db-title" class="text-xl font-semibold text-white">PostgreSQL</h2>
                <div class="mt-5 rounded-xl border p-5 <?= $dbOk ? 'border-emerald-400/30 bg-emerald-400/[.04]' : 'border-red-400/40 bg-red-400/[.05]' ?>">
                    <p class="flex items-center gap-3 text-lg font-semibold <?= $dbOk ? 'text-emerald-300' : 'text-red-300' ?>">
                        <span class="relative flex h-3 w-3">
                            <?php if ($dbOk): ?><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-50"></span><?php endif; ?>
                            <span class="relative inline-flex h-3 w-3 rounded-full <?= $dbOk ? 'bg-emerald-400' : 'bg-red-400' ?>"></span>
                        </span>
                        <?= $dbOk ? 'Servicio DB Activo' : 'Servicio DB Sin Conexión' ?>
                    </p>
                    <?php if ($dbOk): ?>
                        <p class="mt-3 break-words text-sm leading-6 text-slate-300"><?= h($dbVersionFull) ?></p>
                        <dl class="mt-4 space-y-2.5 border-t border-white/10 pt-4 text-sm">
                            <div class="flex justify-between gap-4"><dt class="text-mute">Base de datos</dt><dd class="font-mono text-white"><?= h($dbName) ?></dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-mute">Servidor</dt><dd class="font-mono text-white"><?= h($dbHost) ?>:<?= $dbPort ?></dd></div>
                            <div class="flex justify-between gap-4"><dt class="text-mute">Tiempo de conexión</dt><dd class="tnum text-white"><?= number_format((float)$dbLatencyMs, 1, ',', '.') ?> ms</dd></div>
                            <?php if ($dbSize !== null): ?>
                            <div class="flex justify-between gap-4"><dt class="text-mute">Tamaño de la base</dt><dd class="text-white"><?= h($dbSize) ?></dd></div>
                            <?php endif; ?>
                            <?php if ($dbConns !== null): ?>
                            <div class="flex justify-between gap-4"><dt class="text-mute">Conexiones abiertas</dt><dd class="tnum text-white"><?= $dbConns ?> de <?= $dbMaxConns ?></dd></div>
                            <?php endif; ?>
                            <?php if ($dbUptime !== null): ?>
                            <div class="flex justify-between gap-4"><dt class="text-mute">Tiempo encendido</dt><dd class="text-white"><?= h(fmt_duration($dbUptime)) ?></dd></div>
                            <?php endif; ?>
                        </dl>
                    <?php else: ?>
                        <p class="mt-3 text-sm leading-6 text-slate-300">
                            No se pudo conectar con <span class="font-mono"><?= h($dbHost) ?>:<?= $dbPort ?></span>.
                            Comprueba que el contenedor de PostgreSQL esté en marcha y que <span class="font-mono">DB_HOST</span>,
                            <span class="font-mono">DB_NAME</span>, <span class="font-mono">DB_USER</span> y
                            <span class="font-mono">DB_PASSWORD</span> sean correctas. El detalle técnico está en el log de Apache.
                        </p>
                    <?php endif; ?>
                </div>
            </section>

            <section aria-labelledby="svc-title">
                <h2 id="svc-title" class="text-xl font-semibold text-white">Servicios internos</h2>
                <ul class="mt-5 divide-y divide-line rounded-xl border border-line bg-panel/60">
                    <?php foreach ($services as $s): ?>
                        <li class="flex items-center justify-between gap-4 px-5 py-4">
                            <div>
                                <p class="font-medium text-white"><?= h($s['name']) ?></p>
                                <p class="font-mono text-xs text-mute"><?= h($s['host']) ?>:<?= (int)$s['port'] ?></p>
                            </div>
                            <div class="text-right">
                                <span class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset
                                    <?= $s['up'] ? 'bg-emerald-400/10 text-emerald-300 ring-emerald-400/25' : 'bg-red-400/10 text-red-300 ring-red-400/25' ?>">
                                    <span class="h-1.5 w-1.5 rounded-full <?= $s['up'] ? 'bg-emerald-400' : 'bg-red-400' ?>"></span>
                                    <?= $s['up'] ? 'Responde' : 'Sin respuesta' ?>
                                </span>
                                <p class="tnum mt-1 text-xs text-mute"><?= number_format($s['ms'], 1, ',', '.') ?> ms</p>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        </div>
    </div>

    <!-- ===== Tareas ===== -->
    <section aria-labelledby="task-title">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 id="task-title" class="text-xl font-semibold text-white">Tareas programadas y auditoría</h2>
                <?php if ($tasksDemo): ?>
                    <p class="mt-1 text-sm text-amber-300">Datos de demostración. Crea la tabla <span class="font-mono">auditoria_tareas</span> para ver las ejecuciones reales.</p>
                <?php else: ?>
                    <p class="mt-1 text-sm text-mute">Últimas <?= count($tasks) ?> ejecuciones registradas.</p>
                <?php endif; ?>
            </div>
            <ul class="flex flex-wrap gap-2 text-sm">
                <?php foreach (['ok', 'warn', 'fail', 'run'] as $k): [$lbl, $cls, $dot] = $taskLabels[$k]; ?>
                    <li class="inline-flex items-center gap-2 rounded-full px-3 py-1 ring-1 ring-inset <?= $cls ?>">
                        <span class="h-1.5 w-1.5 rounded-full <?= $dot ?>"></span><span class="tnum"><?= $taskCounts[$k] ?></span> <?= h(mb_strtolower($lbl)) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div class="mt-5 overflow-x-auto rounded-xl border border-line bg-panel/60">
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead class="border-b border-line text-mute">
                    <tr>
                        <th scope="col" class="px-5 py-3 font-medium">Tarea</th>
                        <th scope="col" class="px-5 py-3 font-medium">Última ejecución</th>
                        <th scope="col" class="px-5 py-3 font-medium">Estado</th>
                        <th scope="col" class="px-5 py-3 font-medium">Resultado</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <?php foreach ($tasks as $t): [$lbl, $cls, $dot] = $taskLabels[$t['status']]; ?>
                        <tr class="transition-colors hover:bg-white/[.03]">
                            <td class="px-5 py-4 align-top">
                                <p class="font-medium text-white"><?= h($t['name']) ?></p>
                                <?php if (!empty($t['cron'])): ?><p class="mt-0.5 font-mono text-xs text-mute"><?= h($t['cron']) ?></p><?php endif; ?>
                            </td>
                            <td class="px-5 py-4 align-top">
                                <p class="tnum text-slate-200"><?= h($t['when']->format('d/m/Y H:i')) ?></p>
                                <p class="mt-0.5 text-xs text-mute"><?= h(rel_time($t['when'], $now)) ?></p>
                            </td>
                            <td class="px-5 py-4 align-top">
                                <span class="inline-flex items-center gap-2 rounded-full px-2.5 py-1 text-xs font-medium ring-1 ring-inset <?= $cls ?>">
                                    <span class="h-1.5 w-1.5 rounded-full <?= $dot ?>"></span><?= h($lbl) ?>
                                </span>
                            </td>
                            <td class="px-5 py-4 align-top text-slate-300"><?= h($t['detail']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</main>

<footer class="border-t border-line">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-5 text-sm text-mute sm:px-6">
        <p>Intranet Calatayud. Acceso restringido a personal autorizado.</p>
        <p>PHP <?= h(phpversion()) ?> · se actualiza cada minuto sin recargar la página</p>
    </div>
</footer>

<script>
(function () {
    'use strict';

    /* ---- Introducción: se reproduce solo al cargar la página, nunca en las actualizaciones ---- */
    var splash = document.getElementById('splash');
    if (splash) {
        var skip = function () {
            splash.classList.add('sp-skip');
            document.documentElement.classList.add('sp-done');
        };
        document.getElementById('splash-skip').addEventListener('click', skip);
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') skip(); });
        splash.addEventListener('animationend', function (e) {
            if (e.target === splash && e.animationName === 'sp-wipe') {
                splash.remove();
                document.documentElement.classList.add('sp-done');
            }
        });
    }

    /* ---- Reloj del servidor ---- */
    var offset = <?= $nowMs ?> - Date.now();
    var fmtFull = new Intl.DateTimeFormat('es-ES', { timeZone: 'Europe/Madrid', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    var fmtTime = new Intl.DateTimeFormat('es-ES', { timeZone: 'Europe/Madrid', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
    function tick() {
        var d = new Date(Date.now() + offset);
        document.querySelectorAll('[data-clock="full"]').forEach(function (el) { el.textContent = fmtFull.format(d).replace(',', ''); });
    }
    setInterval(tick, 1000);

    /* ---- Actualización de datos sin recargar (no repite la introducción) ---- */
    var live = document.getElementById('live');
    var btn = document.getElementById('refresh');
    var icon = document.getElementById('refresh-icon');
    var upd = document.getElementById('upd');
    var busy = false;

    async function refresh() {
        if (busy) return;
        busy = true;
        icon.classList.add('spin');
        try {
            var r = await fetch('?r=1', { cache: 'no-store', credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } });
            if (!r.ok) throw new Error('HTTP ' + r.status);
            var doc = new DOMParser().parseFromString(await r.text(), 'text/html');
            var next = doc.getElementById('live');
            if (!next) throw new Error('Respuesta inesperada');
            live.innerHTML = next.innerHTML;
            live.classList.add('refreshed');
            offset = <?= $nowMs ?> - Date.now();
            upd.classList.remove('text-amber-300');
            upd.innerHTML = 'Actualizado <span class="tnum">' + fmtTime.format(new Date()) + '</span>';
            tick();
        } catch (err) {
            upd.classList.add('text-amber-300');
            upd.textContent = 'No se pudo actualizar. Reintentando en 1 min';
        } finally {
            icon.classList.remove('spin');
            busy = false;
        }
    }
    btn.addEventListener('click', refresh);
    setInterval(function () { if (!document.hidden) refresh(); }, 60000);
})();
</script>
</body>
</html>
