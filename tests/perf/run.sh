#!/usr/bin/env bash
# ISS02880: measure the API and MCP calls, the same way before and after the fixes.
#
# Run in the php container, from the Laravel root (/var/www/exment), with apibench.php there:
#   APIBENCH_TOKEN=... T=<table name> ID=<a data id> VIEW=<a view id of T> Q=<a word in T> \
#     LABEL=before bash run.sh
# Writes apibench-$LABEL.jsonl: per call, "cold" (after cache:clear) then "warm" x2.
# Run it twice: EXMENT_USE_CACHE unset (the default) and EXMENT_USE_CACHE=true (USECACHE=1 below
# adds the mode to the label).
set -u
: "${APIBENCH_TOKEN:?an OAuth access token or exk_ key of a system administrator, with every scope}"
: "${T:?table name}" "${ID:?data id}" "${VIEW:?view id}" "${Q:?search word}"
LABEL="${LABEL:-before}${USECACHE:+-usecache}"
OUT="apibench-${LABEL}.jsonl"
: > "$OUT"

mcp() { # name, arguments json
  printf '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"%s","arguments":%s}}' "$1" "$2"
}

CALLS=(
  "GET|api/data/$T?count=20"
  "GET|api/data/$T?count=100"
  "GET|api/data/$T?count=20&page=500"
  "GET|api/data/$T?count=20&label=1"
  "GET|api/data/$T?count=20&valuetype=text"
  "GET|api/data/$T?count=20&children=1"
  "GET|api/data/$T/$ID"
  "GET|api/data/$T/query?q=$Q&count=20"
  "GET|api/viewdata/$T/$VIEW?count=20"
  "GET|api/table"
  "GET|api/table/$T/columns"
  "POST|api/mcp|{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\",\"params\":{\"protocolVersion\":\"2025-06-18\",\"capabilities\":{},\"clientInfo\":{\"name\":\"apibench\",\"version\":\"1\"}}}"
  "POST|api/mcp|{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}"
  "POST|api/mcp|$(mcp list_tables '{}')"
  "POST|api/mcp|$(mcp get_table "{\"table\":\"$T\"}")"
  "POST|api/mcp|$(mcp search_data "{\"table\":\"$T\",\"q\":\"$Q\"}")"
  "POST|api/mcp|$(mcp search_data "{\"table\":\"$T\"}")"
  "POST|api/mcp|$(mcp get_data "{\"table\":\"$T\",\"id\":$ID}")"
  "POST|api/mcp|$(mcp list_attachments "{\"table\":\"$T\",\"id\":$ID}")"
  "POST|api/mcp|$(mcp get_workflow_status "{\"table\":\"$T\",\"id\":$ID}")"
)
# the writes last (they change the data): one create, then an update of it is not
# repeatable, so they run once each, warm
WRITES=(
  "POST|api/data/$T|{\"value\":{}}"
  "PUT|api/data/$T/$ID|{\"value\":{}}"
)

run() { # method path [body] mode
  local method="$1" path="$2" body="${3:-}" mode="$4"
  local line
  if [ -n "$body" ]; then
    line=$(php apibench.php "$method" "$path" "$body")
  else
    line=$(php apibench.php "$method" "$path")
  fi
  echo "{\"mode\":\"$mode\",${line#\{}" >> "$OUT"
}

for c in "${CALLS[@]}"; do
  IFS='|' read -r method path body <<< "$c"
  php artisan cache:clear > /dev/null
  run "$method" "$path" "$body" cold
  run "$method" "$path" "$body" warm
  run "$method" "$path" "$body" warm
done
for c in "${WRITES[@]}"; do
  IFS='|' read -r method path body <<< "$c"
  run "$method" "$path" "$body" warm
done

# a summary: the median of the warm runs and the cold run, per call
php -r '
$rows = array_map(fn ($l) => json_decode($l, true), file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
$by = [];
foreach ($rows as $r) { $by[$r["method"] . " " . $r["path"]][$r["mode"]][] = $r; }
printf("%-70s %6s %8s %8s %5s %6s %7s %9s\n", "call", "status", "cold ms", "warm ms", "sql", "sql ms", "peak MB", "bytes");
foreach ($by as $call => $m) {
  $w = $m["warm"] ?? []; usort($w, fn ($a, $b) => $a["ms"] <=> $b["ms"]); $mid = $w[intdiv(count($w), 2)] ?? null;
  $c = $m["cold"][0] ?? null; $x = $mid ?? $c;
  printf("%-70s %6s %8s %8s %5s %6s %7s %9s\n", mb_substr($call, 0, 70), $x["status"], $c["ms"] ?? "-", $mid["ms"] ?? "-", $x["sql"], $x["sql_ms"], $x["peak_mb"], $x["bytes"]);
}
' "$OUT"
