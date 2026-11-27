<?php

/**
 * Independent expectation of the A36 summary (count of children per parent through the multi-value pivot
 * parent_multi), computed in PHP from the stored JSON, not through the join under test.
 * Each child counts once per DISTINCT referenced parent id; an invalid element matches nothing.
 *
 *   php tests/Support/s36-expected-summary.php <DB_HOST> <DB_PORT> <DB_DATABASE> <DB_USERNAME> [DB_PASSWORD]
 *   (the table names are resolved from custom_tables)
 *
 * Reads only. Credentials come from the command line, never from this repository.
 */

if ($argc < 5) {
    fwrite(STDERR, "usage: php s36-expected-summary.php host port database user [password]\n");
    exit(1);
}
[, $host, $port, $db, $user] = $argv;
$pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $argv[5] ?? '');
$suuid = fn (string $name) => $pdo->query('select suuid from custom_tables where table_name=' . $pdo->quote($name))->fetchColumn();
$parentTable = 'exm__' . $suuid('parent_table_select');
$childTable = 'exm__' . $suuid('pivot_table_select');

$parents = $pdo->query("select id from `$parentTable` where deleted_at is null order by id")->fetchAll(PDO::FETCH_COLUMN);
$count = array_fill_keys($parents, 0);
foreach ($pdo->query("select value from `$childTable` where deleted_at is null") as $row) {
    $elements = json_decode($row['value'], true)['parent_multi'] ?? [];
    foreach (array_unique(array_map('strval', (array)$elements)) as $e) {
        if (isset($count[$e])) {
            $count[$e]++;
        }
    }
}
echo 'expected_all=', json_encode($count), "\n";
echo 'expected_user2(1,3,10)=', json_encode(array_intersect_key($count, array_flip([1, 3, 10]))), "\n";
