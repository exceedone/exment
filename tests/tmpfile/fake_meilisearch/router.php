<?php

/**
 * Stand-in for the Meilisearch HTTP API, for the tests that run the exment:meili-* commands
 * where no Meilisearch server exists (CI): `php -S 127.0.0.1:<port> router.php`.
 *
 * Each request is appended as one json line to the file FAKE_MEILI_LOG names, so a test can
 * check what a command sent. Writes answer an enqueued task and every task reads back as
 * succeeded. FAKE_MEILI_HITS (a json list) are the search hits, FAKE_MEILI_DOCS the document
 * count the index stats report; the index holds no documents otherwise.
 */

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$raw = (string) file_get_contents('php://input');
$body = $raw === '' ? null : json_decode($raw, true);

$log = getenv('FAKE_MEILI_LOG');
if (is_string($log) && $log !== '') {
    $line = json_encode([
        'method' => $method,
        'path' => $path,
        'query' => (string) ($_SERVER['QUERY_STRING'] ?? ''),
        'body' => $body,
    ]);
    file_put_contents($log, $line . "\n", FILE_APPEND | LOCK_EX);
}

$now = '2026-01-01T00:00:00Z';
$status = 200;
if ($path === '/health') {
    $response = ['status' => 'available'];
} elseif ($path === '/version') {
    $response = ['commitSha' => 'fake', 'commitDate' => $now, 'pkgVersion' => '1.10.0'];
} elseif (preg_match('#^/tasks/(\d+)$#', $path, $match) === 1) {
    $response = [
        'uid' => (int) $match[1],
        'indexUid' => 'fake',
        'status' => 'succeeded',
        'type' => 'fake',
        'details' => [],
        'duration' => 'PT0S',
        'enqueuedAt' => $now,
        'startedAt' => $now,
        'finishedAt' => $now,
    ];
} elseif (preg_match('#^/indexes/[^/]+/search$#', $path) === 1) {
    $hits = json_decode((string) getenv('FAKE_MEILI_HITS'), true);
    $hits = is_array($hits) ? array_values($hits) : [];
    $response = [
        'hits' => $hits,
        'query' => is_array($body) ? (string) ($body['q'] ?? '') : '',
        'processingTimeMs' => 1,
        'limit' => is_array($body) ? (int) ($body['limit'] ?? 20) : 20,
        'offset' => 0,
        'estimatedTotalHits' => count($hits),
    ];
} elseif (preg_match('#^/indexes/[^/]+/stats$#', $path) === 1) {
    $response = ['numberOfDocuments' => (int) getenv('FAKE_MEILI_DOCS'), 'isIndexing' => false, 'fieldDistribution' => new stdClass()];
} elseif (preg_match('#^/indexes/[^/]+/documents/fetch$#', $path) === 1 || ($method === 'GET' && preg_match('#^/indexes/[^/]+/documents$#', $path) === 1)) {
    $response = ['results' => [], 'offset' => 0, 'limit' => 20, 'total' => 0];
} elseif ($method === 'GET' && preg_match('#^/indexes/[^/]+/settings$#', $path) === 1) {
    $response = [
        'searchableAttributes' => ['fake_searchable_attribute'],
        'filterableAttributes' => [],
        'stopWords' => [],
        'synonyms' => new stdClass(),
        'localizedAttributes' => null,
        'typoTolerance' => ['enabled' => true],
    ];
} elseif ($method === 'GET' && preg_match('#^/indexes/([^/]+)$#', $path, $match) === 1) {
    $response = ['uid' => $match[1], 'primaryKey' => 'id', 'createdAt' => $now, 'updatedAt' => $now];
} elseif ($method === 'GET' && $path === '/indexes') {
    $response = ['results' => [], 'offset' => 0, 'limit' => 20, 'total' => 0];
} elseif ($method !== 'GET') {
    $status = 202;
    $response = ['taskUid' => 1, 'indexUid' => 'fake', 'status' => 'enqueued', 'type' => 'fake', 'enqueuedAt' => $now];
} else {
    $status = 404;
    $response = ['message' => "fake Meilisearch has no {$path}", 'code' => 'not_found', 'type' => 'invalid_request', 'link' => ''];
}

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($response);
