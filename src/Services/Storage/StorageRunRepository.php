<?php

namespace Exceedone\Exment\Services\Storage;

use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Support\Facades\DB;

/**
 * Persistence of backfill / verification runs and their pending rows
 * (custom_table_storage_runs, custom_table_storage_pending_rows). Counts, ids and column names
 * only - never a cell value.
 */
final class StorageRunRepository
{
    public const KIND_BACKFILL = 'backfill';
    public const KIND_VERIFY = 'verify';

    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    /** the table's state or generation changed under the run: never resumed */
    public const STATUS_STALE = 'stale';

    public const RESULT_PASSED = 'passed';
    public const RESULT_FAILED = 'failed';

    public const RUNS = 'custom_table_storage_runs';
    public const PENDING = 'custom_table_storage_pending_rows';

    /** @return object|null the latest run of a kind at a generation */
    public static function latest(int $tableId, string $kind, int $generation)
    {
        return DB::table(self::RUNS)->where('custom_table_id', $tableId)->where('kind', $kind)
            ->where('generation', $generation)->orderByDesc('id')->first();
    }

    /** @return object|null the running run of a kind at a generation */
    public static function running(int $tableId, string $kind, int $generation)
    {
        return DB::table(self::RUNS)->where('custom_table_id', $tableId)->where('kind', $kind)
            ->where('generation', $generation)->where('status', self::STATUS_RUNNING)->orderByDesc('id')->first();
    }

    public static function create(int $tableId, string $kind, int $generation, ?int $boundaryId): int
    {
        return (int)DB::table(self::RUNS)->insertGetId([
            'custom_table_id' => $tableId,
            'kind' => $kind,
            'generation' => $generation,
            'status' => self::STATUS_RUNNING,
            'boundary_id' => $boundaryId,
            'checkpoint_id' => 0,
            'counts' => Json::encode(static::emptyCounts()),
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return object|null the run row under FOR UPDATE (serializes batches of one run) */
    public static function lock(int $runId)
    {
        return DB::table(self::RUNS)->where('id', $runId)->lockForUpdate()->first();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public static function update(int $runId, array $fields): void
    {
        if (array_key_exists('counts', $fields) && is_array($fields['counts'])) {
            $fields['counts'] = Json::encode($fields['counts']);
        }
        $fields['updated_at'] = now();
        DB::table(self::RUNS)->where('id', $runId)->update($fields);
    }

    /** @return array<string, mixed> */
    public static function counts($run): array
    {
        $counts = Projection::decode($run->counts ?? null);
        return array_merge(static::emptyCounts(), $counts ?? []);
    }

    /** @return array<string, mixed> */
    public static function emptyCounts(): array
    {
        return [
            'batches' => 0,
            'rows' => 0,          // rows examined in the range
            'written' => 0,       // backfill: typed columns / value_original written
            'unchanged' => 0,     // backfill: already equal to the projection
            'matched' => 0,       // verify: public value reconstructed exactly
            'deleted' => 0,       // row gone before it was settled
            'pending_added' => 0, // conflicts / mismatches durably recorded
            'pending_resolved' => 0,
            'l5' => [],           // column_name => reason => count (non-lossless cells)
        ];
    }

    /**
     * @param array<int, string> $columns
     */
    public static function addPending(int $runId, int $tableId, int $rowId, string $reason, array $columns = []): void
    {
        $exists = DB::table(self::PENDING)->where('run_id', $runId)->where('row_id', $rowId)->exists();
        if ($exists) {
            DB::table(self::PENDING)->where('run_id', $runId)->where('row_id', $rowId)->update([
                'reason' => $reason,
                'columns' => empty($columns) ? null : Json::encode(array_values($columns)),
                'updated_at' => now(),
            ]);
            DB::table(self::PENDING)->where('run_id', $runId)->where('row_id', $rowId)->increment('attempts');
            return;
        }
        DB::table(self::PENDING)->insert([
            'run_id' => $runId,
            'custom_table_id' => $tableId,
            'row_id' => $rowId,
            'reason' => $reason,
            'columns' => empty($columns) ? null : Json::encode(array_values($columns)),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, object> */
    public static function pending(int $runId, int $limit)
    {
        return DB::table(self::PENDING)->where('run_id', $runId)->orderBy('row_id')->limit($limit)->get();
    }

    public static function resolvePending(int $runId, int $rowId): void
    {
        DB::table(self::PENDING)->where('run_id', $runId)->where('row_id', $rowId)->delete();
    }

    public static function pendingCount(int $runId): int
    {
        return (int)DB::table(self::PENDING)->where('run_id', $runId)->count();
    }

    /**
     * @param array<string, mixed> $counts
     * @param array<string, string> $reasons column_name => reason
     */
    public static function addL5(array &$counts, array $reasons): void
    {
        foreach ($reasons as $column => $reason) {
            $counts['l5'][$column][$reason] = ($counts['l5'][$column][$reason] ?? 0) + 1;
        }
    }
}
