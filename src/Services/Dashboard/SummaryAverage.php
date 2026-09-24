<?php

namespace Exceedone\Exment\Services\Dashboard;

use Illuminate\Support\Collection;

/**
 * Mean of a summary column out of two engine runs of one aggregate view — SUM and COUNT
 * of the measure — matched on their group values, so it is a true mean of the records
 * (also across a joined child table, where the engine folds child sums and counts into
 * the parent row). Pure functions over ArrayAccess rows (the engine's models or plain
 * arrays): the chart's code stays free of SQL of its own.
 */
class SummaryAverage
{
    /** suffixes of the companion attributes an averaged row carries next to its mean */
    public const SUM_SUFFIX = '__sum';
    public const COUNT_SUFFIX = '__n';

    /**
     * The SUM rows with $alias rewritten to their mean (null when the group has no count),
     * in the SUM rows' order; the sum and count land in $alias.'__sum' / $alias.'__n'.
     *
     * @param Collection $sums       rows of the SUM run
     * @param Collection $counts     rows of the COUNT run
     * @param string[]   $keyAliases attributes identifying a group (the group columns' aliases)
     * @param string     $alias      attribute of the measure
     */
    public static function merge(Collection $sums, Collection $counts, array $keyAliases, string $alias): Collection
    {
        $countByKey = [];
        foreach ($counts as $row) {
            $countByKey[static::key($row, $keyAliases)] = $row[$alias] ?? null;
        }
        return $sums->map(function ($row) use ($countByKey, $keyAliases, $alias) {
            $sum = $row[$alias] ?? null;
            $count = $countByKey[static::key($row, $keyAliases)] ?? null;
            $count = is_numeric($count) ? (int) $count : 0;
            $row[$alias . static::SUM_SUFFIX] = is_numeric($sum) ? (float) $sum : null;
            $row[$alias . static::COUNT_SUFFIX] = $count;
            $row[$alias] = static::mean($sum, $count);
            return $row;
        })->values();
    }

    /**
     * SUM / COUNT to 1 decimal; null when either is missing or the count is 0.
     *
     * @param mixed $sum
     * @param mixed $count
     */
    public static function mean($sum, $count): ?float
    {
        if (!is_numeric($sum) || !is_numeric($count) || $count <= 0) {
            return null;
        }
        return round((float) $sum / (float) $count, 1);
    }

    /**
     * Cell means of a pivot: $sums / $counts element-wise, 0 where a cell has no records.
     *
     * @param array<int, array<int, mixed>> $sums
     * @param array<int, array<int, mixed>> $counts
     * @return array<int, array<int, float|int>>
     */
    public static function cellMeans(array $sums, array $counts): array
    {
        foreach ($sums as $s => $cells) {
            foreach ($cells as $x => $sum) {
                $sums[$s][$x] = static::mean($sum, $counts[$s][$x] ?? 0) ?? 0;
            }
        }
        return $sums;
    }

    /**
     * Whether the engine orders the view's rows by the measure before any group column —
     * its sort order strictly lower than every group column's (unset = 1; on a tie the
     * group columns come first). Then rows averaged from a SUM run need re-sorting.
     *
     * @param mixed   $measureSortOrder
     * @param mixed[] $groupSortOrders
     */
    public static function valueLeadsOrder($measureSortOrder, array $groupSortOrders): bool
    {
        $measure = static::sortOrder($measureSortOrder);
        foreach ($groupSortOrders as $group) {
            if (static::sortOrder($group) <= $measure) {
                return false;
            }
        }
        return true;
    }

    /**
     * Rows sorted by $alias (stable), non-numeric values last, keys reset.
     */
    public static function sortByValue(Collection $rows, string $alias, bool $desc): Collection
    {
        return $rows->sortBy(function ($row) use ($alias, $desc) {
            $value = $row[$alias] ?? null;
            return is_numeric($value) ? (float) $value : ($desc ? -INF : INF);
        }, SORT_REGULAR, $desc)->values();
    }

    /**
     * @param mixed $value
     */
    protected static function sortOrder($value): int
    {
        return is_nullorempty($value) ? 1 : (int) $value;
    }

    /**
     * @param mixed    $row
     * @param string[] $keyAliases
     */
    protected static function key($row, array $keyAliases): string
    {
        $parts = [];
        foreach ($keyAliases as $keyAlias) {
            $value = $row[$keyAlias] ?? null;
            $parts[] = is_scalar($value) ? (string) $value : '';
        }
        return implode("\x1f", $parts);
    }
}
