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

$prefix = trim(strval(config('admin.route.prefix')), '/');
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
$t1 = hrtime(true);
$response = $kernel->handle($request);
$ms = (hrtime(true) - $t1) / 1e6;
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
