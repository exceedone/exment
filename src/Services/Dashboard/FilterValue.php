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
     * Which control a filter on this column renders: numbers and dates get a from / to
     * 'range', everything else a multi-'select' of the distinct values.
     */
    public static function style($column): string
    {
        return self::kind($column) === 'text' ? 'select' : 'range';
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
