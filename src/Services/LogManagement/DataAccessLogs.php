<?php

namespace Exceedone\Exment\Services\LogManagement;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\DataAccessDaily;
use Exceedone\Exment\Model\DataAccessLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\UserApiKeys;
use Illuminate\Support\Facades\Event;

/**
 * The reads and the writes of the data (ISS02905): who read (the detail page, a record of the API
 * or MCP), listed (a list of a table: one row a list, not one a record shown), exported, made,
 * changed, deleted or restored which record of which table, when and from where. A small row, no
 * value, written after the response (LogWriter). The same user reading the same record again in
 * a few minutes (config exment.data_access_dedupe_minutes, 10) is not written again. The reads are
 * kept only when the operation log keeps everything (LogSettings), the writes always.
 *
 * Each day the rows of the days before are counted into data_access_daily (summarize), which the
 * IT audit report reads; the rows themselves are kept as the log management says (90 days).
 */
class DataAccessLogs
{
    public const DEFAULT_DAYS = 90;
    public const DEFAULT_DAILY_DAYS = 1825;

    /** The days counted in one run at most. */
    public const SUMMARIZE_DAYS = 400;

    /** The tables that are logs themselves: their rows are not recorded. */
    public const NOT_RECORDED = [SystemTableName::SYSTEM_LOGS, SystemTableName::MAIL_SEND_LOG];

    /** The columns that change when a record is deleted or restored, not by a change of it. */
    protected const NOT_A_CHANGE = ['deleted_user_id', 'deleted_at', 'updated_at', 'updated_user_id'];

    public const COLUMNS = ['id', 'created_at', 'base_user_id', 'custom_table_id', 'record_id', 'custom_view_id', 'kind', 'via', 'ip'];

    /** @var string|null from where, set by the caller (MCP) */
    protected static $via = null;

    public static function register(): void
    {
        foreach (['created' => DataAccessLog::KIND_CREATE, 'updated' => DataAccessLog::KIND_UPDATE, 'deleted' => DataAccessLog::KIND_DELETE, 'restored' => DataAccessLog::KIND_RESTORE] as $event => $kind) {
            Event::listen('eloquent.' . $event . ': *', function (string $name, array $payload) use ($kind) {
                $model = $payload[0] ?? null;
                if ($model instanceof CustomValue) {
                    static::written($model, $kind);
                }
            });
        }
    }

    /**
     * Run a call as from a place (MCP): its reads and writes are marked so.
     *
     * @template T
     * @param \Closure(): T $call
     * @return T
     */
    public static function from(string $via, \Closure $call)
    {
        $before = static::$via;
        static::$via = $via;
        try {
            return $call();
        } finally {
            static::$via = $before;
        }
    }

    /**
     * A record read (its detail page, the API, MCP).
     *
     * @param mixed $id
     */
    public static function read(CustomTable $table, $id): void
    {
        if (!is_numeric($id)) {
            return;
        }
        static::record($table, DataAccessLog::KIND_READ, intval($id), null);
    }

    /**
     * A list of a table (one row, whatever the records shown), or its export.
     */
    public static function listed(CustomTable $table, ?int $viewId = null, string $kind = DataAccessLog::KIND_LIST): void
    {
        static::record($table, $kind, null, $viewId);
    }

    protected static function written(CustomValue $model, string $kind): void
    {
        try {
            $table = $model->custom_table;
            if (!$table instanceof CustomTable) {
                return;
            }
            if ($kind === DataAccessLog::KIND_UPDATE) {
                // the save of a delete or of a restore
                $changed = array_keys($model->getChanges());
                if (count(array_diff($changed, static::NOT_A_CHANGE)) === 0) {
                    return;
                }
            }
            if ($kind === DataAccessLog::KIND_DELETE && $model->isForceDeleting()) {
                $kind = DataAccessLog::KIND_FORCE_DELETE;
            }
            static::record($table, $kind, intval($model->id), null);
        } catch (\Throwable $e) {
            \Log::warning('Exment: a write of the data was not recorded: ' . get_class($e));
        }
    }

    protected static function record(CustomTable $table, string $kind, ?int $recordId, ?int $viewId): void
    {
        try {
            if (!DataAccessLog::tableExists() || in_array($table->table_name, static::NOT_RECORDED, true)) {
                return;
            }
            $isRead = in_array($kind, DataAccessLog::READS, true);
            if ($isRead && !LogSettings::logsMethod('GET')) {
                return;
            }
            $userId = \Exment::getUserId();
            $via = static::via();
            if ($isRead && !static::firstIn($userId, intval($table->id), $kind, $recordId, $viewId, $via)) {
                return;
            }
            $ip = $via === DataAccessLog::VIA_SYSTEM ? null : LogPrivacy::ip(request()->ip());
            LogWriter::insert(DataAccessLog::class, [
                'base_user_id' => is_numeric($userId) ? intval($userId) : null,
                'custom_table_id' => intval($table->id),
                'record_id' => $recordId,
                'custom_view_id' => $viewId,
                'kind' => $kind,
                'via' => $via,
                'ip' => is_null($ip) ? null : mb_substr(strval($ip), 0, 64),
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Exment: a read or a write of the data was not recorded: ' . get_class($e));
        }
    }

    public static function via(): string
    {
        if (isset(static::$via)) {
            return static::$via;
        }
        $request = request();
        if (is_null($request->route())) {
            return DataAccessLog::VIA_SYSTEM;
        }
        return UserApiKeys::isApiRoute($request) ? DataAccessLog::VIA_API : DataAccessLog::VIA_PAGE;
    }

    public static function dedupeMinutes(): int
    {
        return max(0, intval(config('exment.data_access_dedupe_minutes', 10)));
    }

    /**
     * Whether this read is the first of the user in the minutes (the others are not written).
     *
     * @param mixed $userId
     */
    protected static function firstIn($userId, int $tableId, string $kind, ?int $recordId, ?int $viewId, string $via): bool
    {
        $minutes = static::dedupeMinutes();
        if ($minutes === 0) {
            return true;
        }
        $key = 'exment-data-access:' . implode(':', [strval($userId), $tableId, $kind, strval($recordId), strval($viewId), $via]);
        try {
            return \Cache::add($key, 1, $minutes * 60);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Count the rows of the days not counted yet, up to yesterday, into data_access_daily.
     *
     * @return int the days counted
     */
    public static function summarize(?Carbon $now = null): int
    {
        if (!DataAccessLog::tableExists()) {
            return 0;
        }
        $today = ($now ?? Carbon::now())->copy()->startOfDay();
        $until = static::summarizedUntil();
        if (isset($until)) {
            $day = $until->copy()->addDay();
        } else {
            $first = DataAccessLog::query()->min('created_at');
            if (is_null($first)) {
                return 0;
            }
            $day = Carbon::parse($first)->startOfDay();
        }
        $days = 0;
        while ($day->lt($today) && $days < static::SUMMARIZE_DAYS) {
            $next = $day->copy()->addDay();
            $rows = DataAccessLog::query()
                ->where('created_at', '>=', $day)->where('created_at', '<', $next)
                ->selectRaw('base_user_id, custom_table_id, kind, via, count(*) as total')
                ->groupBy('base_user_id', 'custom_table_id', 'kind', 'via')
                ->get();
            \DB::transaction(function () use ($rows, $day) {
                DataAccessDaily::query()->where('date', $day->format('Y-m-d'))->delete();
                foreach (array_chunk($rows->all(), 500) as $chunk) {
                    DataAccessDaily::query()->insert(array_map(function ($row) use ($day) {
                        return [
                            'date' => $day->format('Y-m-d'),
                            'base_user_id' => $row->base_user_id,
                            'custom_table_id' => $row->custom_table_id,
                            'kind' => $row->kind,
                            'via' => $row->via,
                            'count' => intval($row->total),
                        ];
                    }, $chunk));
                }
                System::data_access_summarized_until($day->format('Y-m-d'));
            });
            $day = $next;
            $days++;
        }
        System::clearCache();
        return $days;
    }

    /**
     * The last day counted, null when none.
     */
    public static function summarizedUntil(): ?Carbon
    {
        $value = System::data_access_summarized_until();
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        return Carbon::parse($value)->startOfDay();
    }
}
