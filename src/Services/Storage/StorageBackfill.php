<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Support\Facades\DB;

/**
 * Forward backfill (02 §15.7): sets every row's typed columns and value_original from its JSON,
 * in state `backfilling` (dual-write active, drain attested). Never writes `value`.
 *
 * Per row: read (id, value) -> Projection (CellConverter) -> one UPDATE of the typed columns and
 * value_original WHERE id = ? AND value is exactly what was read (DriverSupport::whereValueIs:
 * MariaDB text compare, MySQL CAST(? AS JSON)).
 *  - 1 row affected: written.
 *  - 0 affected: re-read - row gone: deleted; same value still there: unchanged (MySQL reports
 *    changed rows only, so an UPDATE writing equal values affects 0); value changed meanwhile:
 *    pending 'conflict' (the concurrent writer was a dual-write, which wrote the projection
 *    itself; the row is retried until it settles).
 * L5 counts (column -> reason) for the cells written to value_original; no value is recorded.
 */
class StorageBackfill extends StorageRunner
{
    protected function kind(): string
    {
        return StorageRunRepository::KIND_BACKFILL;
    }

    protected function requiredState(): string
    {
        return StorageState::BACKFILLING;
    }

    protected function capability(): string
    {
        return DriverSupport::BACKFILL;
    }

    protected function completesWithPending(): bool
    {
        return false;
    }

    protected function extendsBoundary(): bool
    {
        return false;
    }

    protected function columns(CustomTable $table): array
    {
        return ['id', 'value'];
    }

    protected function processRows(CustomTable $table, $rows, array &$counts, bool $retry): array
    {
        $db_table_name = getDBTableName($table);
        $outcomes = [];
        DualWriter::assertColumnsExist($db_table_name, array_keys(Projection::of($table, null)->typed()));
        foreach ($rows as $row) {
            $id = (int)$row->id;
            $raw = $row->value === null ? null : (string)$row->value;
            $projection = Projection::of($table, Projection::decode($raw));
            $original = $projection->original();
            $update = $projection->typed();
            $update[PhysicalNaming::VALUE_ORIGINAL] = empty($original) ? null : Json::encode($original);

            $query = DB::table($db_table_name)->where('id', $id);
            DriverSupport::whereValueIs($query, $raw);
            $affected = $query->update($update);

            if ($affected === 1) {
                $counts['written']++;
                StorageRunRepository::addL5($counts, $projection->reasons());
                $outcomes[$id] = 'settled';
                continue;
            }
            $still = DB::table($db_table_name)->where('id', $id);
            if (!$still->exists()) {
                $outcomes[$id] = 'deleted';
                continue;
            }
            $same = DB::table($db_table_name)->where('id', $id);
            DriverSupport::whereValueIs($same, $raw);
            if ($same->exists()) {
                $counts['unchanged']++;
                StorageRunRepository::addL5($counts, $projection->reasons());
                $outcomes[$id] = 'settled';
                continue;
            }
            $outcomes[$id] = ['pending', 'conflict', []];
        }
        return $outcomes;
    }
}
