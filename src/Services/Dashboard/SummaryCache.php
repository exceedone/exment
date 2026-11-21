<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\Cache;

/**
 * The rows of an aggregate view a chart box draws, kept for a while (ISS00413): a dashboard
 * aggregated every table of its boxes on every load, the slowest part of a dashboard of a
 * large table. Off unless `exment.dashboard_cache_seconds` (EXMENT_DASHBOARD_CACHE_SECONDS)
 * is set.
 *
 * A kept result is the viewer's own: the key holds the viewer, their permissions and their
 * organizations, so no one gets rows aggregated for another. It also holds what the rows are
 * made of (the box, its view, the dashboard and the filters asked for) and the data version
 * of each table the view reads; saving or deleting a row of one changes its version, so the
 * next load aggregates again. What does not change a version — a write that goes around the
 * models (an SQL of a plugin), a change of a share, of a membership, of "today" — shows at the
 * latest when the result expires.
 */
class SummaryCache
{
    protected const KEY_ROWS = 'exment_dashboard_summary_';
    protected const KEY_VERSION = 'exment_dashboard_data_version_';

    /**
     * How long a result is kept, in seconds. 0: off.
     */
    public static function seconds(): int
    {
        return max(0, intval(config('exment.dashboard_cache_seconds', 0)));
    }

    public static function enabled(): bool
    {
        return static::seconds() > 0;
    }

    /**
     * A row of the table was saved or deleted: the kept results that read it are not used again.
     *
     * @param CustomTable|int|string|null $table
     */
    public static function touch($table): void
    {
        if (!static::enabled() || is_null($id = static::tableId($table))) {
            return;
        }
        try {
            Cache::forever(static::KEY_VERSION . $id, uniqid('', true));
        } catch (\Throwable $ex) {
            // a cache that cannot be written keeps nothing either: the next load reads anew
        }
    }

    /**
     * The rows, kept or read. Off, or a viewer not known: read.
     *
     * @param array<mixed> $parts  what the rows are made of
     * @param iterable<mixed> $tables  the tables the rows are read from
     * @param callable(): \Illuminate\Support\Collection<array-key, mixed> $read
     * @param callable(array<int, array<string, mixed>>): \Illuminate\Support\Collection<array-key, mixed> $restore  rows from what was kept
     * @return \Illuminate\Support\Collection<array-key, mixed>
     */
    public static function rows(array $parts, $tables, callable $read, callable $restore)
    {
        if (!static::enabled() || is_null($viewer = static::viewer())) {
            return $read();
        }

        $versions = [];
        foreach ($tables as $table) {
            if (!is_null($id = static::tableId($table))) {
                $versions[$id] = Cache::get(static::KEY_VERSION . $id);
            }
        }
        ksort($versions);
        $key = static::KEY_ROWS . hash('sha256', serialize([$parts, $versions, $viewer]));

        try {
            $kept = Cache::get($key);
        } catch (\Throwable $ex) {
            $kept = null;
        }
        if (is_array($kept)) {
            return $restore($kept);
        }

        $rows = $read();
        try {
            Cache::put($key, $rows->map(function ($row) {
                return is_object($row) && method_exists($row, 'getAttributes') ? $row->getAttributes() : (array) $row;
            })->values()->all(), static::seconds());
        } catch (\Throwable $ex) {
            // not kept: read again next time
        }
        return $rows;
    }

    /**
     * Who sees the rows: the user, their permissions and organizations. null: no viewer.
     *
     * @return array<mixed>|null
     */
    protected static function viewer(): ?array
    {
        $user = \Exment::user();
        if (is_null($user)) {
            return null;
        }
        try {
            $organizations = $user->base_user ? (array) $user->base_user->getOrganizationIdsForQuery() : [];
            sort($organizations);
            return [
                $user->getUserId(),
                hash('sha256', serialize($user->allPermissions()->all())),
                $organizations,
            ];
        } catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * @param mixed $table
     */
    protected static function tableId($table): ?string
    {
        if ($table instanceof CustomTable) {
            return (string) $table->id;
        }
        if (is_numeric($table)) {
            return (string) $table;
        }
        $table = is_null($table) ? null : CustomTable::getEloquent($table);
        return is_null($table) ? null : (string) $table->id;
    }
}
