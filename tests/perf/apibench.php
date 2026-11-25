<?php
/**
 * ISS02880: one API call, measured inside the application (as php-fpm runs one request).
 *
 * usage (in the php container, from the Laravel root, e.g. /var/www/exment):
 *   php apibench.php METHOD PATH [JSON_BODY]
 *   env: APIBENCH_TOKEN   the Bearer token (OAuth access token or an exk_ API key)
 *        APIBENCH_TOP=5   how many repeated SQL statements to list
 * PATH is after the admin prefix: "api/data/foo?count=20", "api/mcp".
 *
 * Prints ONE JSON line: status, ms (the handling, not the boot), boot_ms, sql (count),
 * sql_ms, sql_distinct, top (the most repeated statements = N+1), peak_mb (above the
 * boot), bytes (the answer), plus the MCP error if any. One process per call, so the
 * static caches start empty each time as with php-fpm; the cache store (file/redis)
 * stays: run a call twice to see cold and warm.
 */

$t0 = hrtime(true);
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

[$script, $method, $path] = array_pad($argv, 3, null);
$body = $argv[3] ?? null;
if (!$method || !$path) {
    fwrite(STDERR, "usage: php apibench.php METHOD PATH [JSON_BODY]\n");
    exit(2);
}
$token = getenv('APIBENCH_TOKEN') ?: '';
$top = intval(getenv('APIBENCH_TOP') ?: 5);

// (before the boot: no config() yet) the admin prefix, "admin" by default
$prefix = trim(strval(getenv('APIBENCH_PREFIX') !== false ? getenv('APIBENCH_PREFIX') : 'admin'), '/');
$uri = '/' . ltrim(($prefix === '' ? '' : $prefix . '/') . ltrim($path, '/'), '/');
$server = [
    'HTTP_ACCEPT' => 'application/json',
    'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
    'REMOTE_ADDR' => '127.0.0.1',
];
if (isset($body)) {
    $server['CONTENT_TYPE'] = 'application/json';
}
$request = Illuminate\Http\Request::create($uri, strtoupper($method), [], [], [], $server, $body);
// the request first (Exment's providers read it), then the boot (the facades for Event::listen)
$app->instance('request', $request);
$kernel->bootstrap();

$queries = [];
$sqlMs = 0.0;
Illuminate\Support\Facades\Event::listen(Illuminate\Database\Events\QueryExecuted::class, function ($e) use (&$queries, &$sqlMs) {
    // the same statement with other values = one key (N+1 shows as a big count)
    $key = preg_replace('/\s+/', ' ', $e->sql);
    $key = preg_replace('/\bin \([?, ]+\)/i', 'in (?)', $key);
    $queries[$key] = ($queries[$key] ?? 0) + 1;
    $sqlMs += $e->time;
});

$bootMs = (hrtime(true) - $t0) / 1e6;
$mem0 = memory_get_usage(true);
// APIBENCH_PROFILE=<file>: a sampling profile of the handling (the pecl extension excimer),
// every 0.5 ms; <file> gets the frames that take the most time (inclusive and self)
$profiler = null;
if (($profileFile = getenv('APIBENCH_PROFILE')) && class_exists('ExcimerProfiler')) {
    $profiler = new ExcimerProfiler();
    $profiler->setPeriod(0.0005);
    $profiler->setEventType(EXCIMER_REAL);
    $profiler->start();
}
$t1 = hrtime(true);
$response = $kernel->handle($request);
$ms = (hrtime(true) - $t1) / 1e6;
if (isset($profiler)) {
    $profiler->stop();
    $incl = [];
    $self = [];
    $total = 0;
    foreach (explode("
", trim($profiler->getLog()->formatCollapsed())) as $line) {
        if (!preg_match('/^(.*) (\d+)$/', $line, $m)) {
            continue;
        }
        $frames = explode(';', $m[1]);
        $n = intval($m[2]);
        $total += $n;
        foreach (array_unique($frames) as $f) {
            $incl[$f] = ($incl[$f] ?? 0) + $n;
        }
        $last = end($frames);
        $self[$last] = ($self[$last] ?? 0) + $n;
    }
    arsort($incl);
    arsort($self);
    $out = "samples $total ({$method} {$path})

# inclusive
";
    foreach (array_slice($incl, 0, 60, true) as $f => $n) {
        $out .= sprintf("%5.1f%%  %s
", 100 * $n / max(1, $total), $f);
    }
    $out .= "
# self
";
    foreach (array_slice($self, 0, 30, true) as $f => $n) {
        $out .= sprintf("%5.1f%%  %s
", 100 * $n / max(1, $total), $f);
    }
    file_put_contents($profileFile, $out);
}
$content = (string)$response->getContent();
$kernel->terminate($request, $response);

arsort($queries);
$result = [
    'method' => strtoupper($method),
    'path' => $path,
    'status' => $response->getStatusCode(),
    'ms' => round($ms, 1),
    'boot_ms' => round($bootMs, 1),
    'sql' => array_sum($queries),
    'sql_ms' => round($sqlMs, 1),
    'sql_distinct' => count($queries),
    'top' => array_map(function ($sql, $count) {
        return ['count' => $count, 'sql' => mb_substr($sql, 0, 160)];
    }, array_keys(array_slice($queries, 0, $top, true)), array_slice($queries, 0, $top, true)),
    'peak_mb' => round((memory_get_peak_usage(true) - $mem0) / 1048576, 1),
    'bytes' => strlen($content),
];
$json = json_decode($content, true);
if (is_array($json) && isset($json['error'])) {
    $result['error'] = $json['error'];
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
