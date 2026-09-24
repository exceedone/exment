<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ColumnType;

/**
 * One filter value, as the dashboard filter bar (df_{column}) and the chart-level filter
 * (bf_{column}) put it on a request, and the SQL it turns into.
 *
 *   ?df_col=v                     → ['in' => ['v']]
 *   ?df_col[]=a&df_col[]=b        → ['in' => ['a', 'b']]
 *   ?df_col[from]=x&df_col[to]=y  → ['from' => 'x', 'to' => 'y']   (either side may be null)
 *
 * Number / date columns compare their range bounds as numbers / ISO dates; a range never
 * matches an empty value.
 */
final class FilterValue
{
    /**
     * Only plain identifiers are ever interpolated into SQL or a JSON path.
     */
    public static function isIdentifier($name): bool
    {
        return is_string($name) && preg_match('/\A[A-Za-z0-9_]+\z/', $name) === 1;
    }

    /**
     * Normalize a raw request value into a spec, or null when it carries no filter.
     */
    public static function parse($raw): ?array
    {
        if (is_scalar($raw)) {
            $v = trim((string) $raw);
            return $v === '' ? null : ['in' => [$v]];
        }
        if (!is_array($raw) || empty($raw)) {
            return null;
        }
        if (array_key_exists('from', $raw) || array_key_exists('to', $raw)) {
            $from = self::bound($raw['from'] ?? null);
            $to = self::bound($raw['to'] ?? null);
            return ($from === null && $to === null) ? null : ['from' => $from, 'to' => $to];
        }
        $in = [];
        foreach ($raw as $k => $v) {
            if (!is_int($k) || !is_scalar($v)) {
                continue;
            }
            $v = trim((string) $v);
            if ($v !== '' && !in_array($v, $in, true)) {
                $in[] = $v;
            }
        }
        return empty($in) ? null : ['in' => $in];
    }

    /**
     * The spec carried by one request param (e.g. 'df_grade').
     */
    public static function fromRequest(string $key): ?array
    {
        return self::parse(request()->input($key));
    }

    /**
     * Display strings of a spec: the values, or the range as "from – to".
     */
    public static function values(array $spec): array
    {
        if (isset($spec['in'])) {
            return $spec['in'];
        }
        return [trim(($spec['from'] ?? '') . ' – ' . ($spec['to'] ?? ''))];
    }

    /**
     * Canonical string of a spec (order-independent) for cache keys.
     */
    public static function token(array $spec): string
    {
        if (isset($spec['in'])) {
            $values = $spec['in'];
            sort($values, SORT_STRING);
            return 'in:' . implode("\x1f", $values);
        }
        return 'range:' . ($spec['from'] ?? '') . "\x1f" . ($spec['to'] ?? '');
    }

    /**
     * How a column compares inside a range and which input a range renders:
     * 'number' | 'date' | 'datetime' | 'text'.
     */
    public static function kind($column): string
    {
        $type = $column ? $column->column_type : null;
        if (ColumnType::isCalc($type)) {
            return 'number';
        }
        if ($type === ColumnType::DATETIME) {
            return 'datetime';
        }
        if (ColumnType::isDate($type)) {
            return 'date';
        }
        return 'text';
    }

    /**
     * Which control a filter on this column renders.
     *
     * A filter lists the values the data actually holds, whatever the column stores —
     * numbers included: 384k score rows still make a list of the ~80 marks awarded, and a
     * mark is picked, not typed. Only dates keep a from / to 'range', because their input
     * already carries a calendar and a year of days is a list nobody scrolls.
     *
     * $configured (a dim's "style" in the filter bar config) overrides the default, for a
     * continuous column — money, a measurement — where picking one exact value is never
     * the question being asked.
     */
    public static function style($column, ?string $configured = null): string
    {
        if ($configured === 'select' || $configured === 'range') {
            return $configured;
        }
        return in_array(self::kind($column), ['date', 'datetime'], true) ? 'range' : 'select';
    }

    /**
     * The control an item renders with $spec (its parsed value) on it: style(), except that
     * a from / to on a number or a date is always a 'range'. The bar shows from / to for
     * such a column once its list outgrows the option cap, and a pick elsewhere can bring
     * the list back under it — the typed bounds must stay visible, and stay applied
     * (DashboardFilter::columnsFor decides by the same rule), rather than turn into an
     * empty select that narrows every chart in silence. A text column never compares, so a
     * from / to on it is no control at all (and no filter).
     *
     * @param array|null $spec  parse() of the item's value; null when nothing is selected
     */
    public static function styleFor($column, ?string $configured, ?array $spec): string
    {
        $style = self::style($column, $configured);
        if ($style === 'select' && $spec !== null && !isset($spec['in']) && self::kind($column) !== 'text') {
            return 'range';
        }
        return $style;
    }

    /**
     * SQL reading a custom column's stored value: its generated index column when it has
     * one, JSON extraction otherwise.
     */
    public static function columnExpr($column): string
    {
        if ($column->index_enabled) {
            return '`' . $column->getIndexColumnName(false) . '`';
        }
        return "JSON_UNQUOTE(JSON_EXTRACT(`value`, '$.\"{$column->column_name}\"'))";
    }

    /**
     * SQL a range compares on: a number column as DECIMAL (so "72" sorts before "1001"),
     * every other column its stored text (ISO dates order as text).
     */
    public static function compareExpr($column): string
    {
        $expr = self::columnExpr($column);
        return self::kind($column) === 'number' ? "CAST({$expr} AS DECIMAL(20,4))" : $expr;
    }

    /**
     * An end of the data (MIN / MAX of a column, ColumnOptions::bounds) as a range input
     * shows it: a number without the DECIMAL's trailing zeros ("72.0000" → "72", "1.5000"
     * → "1.5"), a date or datetime as its ISO day, anything else as is; '' when there is
     * none (no rows) or it cannot be shown.
     *
     * @param mixed $value
     */
    public static function formatBound($value, string $kind): string
    {
        if (!is_scalar($value) || (string) $value === '') {
            return '';
        }
        $v = (string) $value;
        if ($kind === 'number') {
            if (!is_numeric($v)) {
                return '';
            }
            if (strpos($v, '.') !== false) {
                $v = rtrim(rtrim($v, '0'), '.');
            }
            return (float) $v == 0 ? '0' : $v;
        }
        if ($kind === 'date' || $kind === 'datetime') {
            return self::isoDate($v) ?? '';
        }
        return $v;
    }

    /**
     * AND one spec for one custom column onto a query.
     */
    public static function apply($query, $column, array $spec): void
    {
        if ($column->index_enabled && isset($spec['in'])) {
            $name = $column->getIndexColumnName(false);
            if (count($spec['in']) === 1) {
                $query->where($name, $spec['in'][0]);
            } else {
                $query->whereIn($name, $spec['in']);
            }
            return;
        }
        self::applyExpr($query, self::columnExpr($column), $spec, self::kind($column));
    }

    /**
     * AND one spec onto a query against a ready SQL expression.
     */
    public static function applyExpr($query, string $expr, array $spec, string $kind = 'text'): void
    {
        if (isset($spec['in'])) {
            if (count($spec['in']) === 1) {
                $query->whereRaw("{$expr} = ?", [$spec['in'][0]]);
            } else {
                $query->whereRaw("{$expr} IN (" . implode(',', array_fill(0, count($spec['in']), '?')) . ')', $spec['in']);
            }
            return;
        }

        $from = $spec['from'] ?? null;
        $to = $spec['to'] ?? null;
        $compare = $expr;
        if ($kind === 'number') {
            $from = is_numeric($from) ? $from : null;
            $to = is_numeric($to) ? $to : null;
            $compare = "CAST({$expr} AS DECIMAL(20,4))";
        } elseif ($kind === 'date' || $kind === 'datetime') {
            $from = self::isoDate($from);
            $to = self::isoDate($to);
            if ($to !== null && $kind === 'datetime') {
                $to .= ' 23:59:59';
            }
        }
        if ($from === null && $to === null) {
            return;
        }
        $query->whereRaw("{$expr} IS NOT NULL AND {$expr} <> ''");
        if ($from !== null) {
            $query->whereRaw("{$compare} >= ?", [$from]);
        }
        if ($to !== null) {
            $query->whereRaw("{$compare} <= ?", [$to]);
        }
    }

    private static function bound($v): ?string
    {
        if (!is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private static function isoDate($v): ?string
    {
        return ($v !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) ? substr($v, 0, 10) : null;
    }
}
