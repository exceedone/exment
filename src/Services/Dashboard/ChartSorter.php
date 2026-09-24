<?php

namespace Exceedone\Exment\Services\Dashboard;

/**
 * Re-orders the points of a chart result under a ChartSort: labels, values, the per-column
 * X texts and the click-to-filter values move together, so a bar keeps its label and its
 * filter value. Pure functions over the arrays ChartItem builds — no query involved.
 */
class ChartSorter
{
    /**
     * Index order of the points by $keys: numeric (non-numeric last either way) or natural
     * case-insensitive text ("2年" before "10年"); ties keep the incoming order.
     *
     * @param mixed[] $keys  one per point
     * @return int[]
     */
    public static function order(array $keys, bool $desc, bool $numeric): array
    {
        $keys = array_values($keys);
        $index = array_keys($keys);
        usort($index, function ($a, $b) use ($keys, $desc, $numeric) {
            if ($numeric) {
                $na = is_numeric($keys[$a]);
                $nb = is_numeric($keys[$b]);
                if ($na !== $nb) {
                    return $na ? -1 : 1;
                }
                $cmp = $na ? (float) $keys[$a] <=> (float) $keys[$b] : 0;
            } else {
                $cmp = strnatcasecmp(static::text($keys[$a]), static::text($keys[$b]));
            }
            if ($cmp === 0) {
                return $a <=> $b;
            }
            return $desc ? -$cmp : $cmp;
        });
        return $index;
    }

    /**
     * A single-series result {chart_label, chart_data, chart_fields?, chart_click?} with its
     * points re-ordered. `chart_fields[i]` = the texts of X column i, one per point; without
     * it the label stands in for `x0`. An X index the chart does not have leaves it as is.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function applySingle(array $result, ChartSort $sort): array
    {
        $labels = collect($result['chart_label'] ?? [])->values()->all();
        $values = collect($result['chart_data'] ?? [])->values()->all();
        $fields = array_map(function ($column) {
            return collect($column)->values()->all();
        }, array_values((array) ($result['chart_fields'] ?? [$labels])));

        if ($sort->byValue()) {
            $order = static::order($values, $sort->desc, true);
        } elseif (isset($fields[$sort->xIndex()])) {
            $order = static::order($fields[$sort->xIndex()], $sort->desc, false);
        } else {
            return $result;
        }

        $result['chart_label'] = static::pick($labels, $order);
        $result['chart_data'] = static::pick($values, $order);
        if (isset($result['chart_fields'])) {
            $result['chart_fields'] = array_map(function ($column) use ($order) {
                return static::pick($column, $order);
            }, $fields);
        }
        if (is_array($result['chart_counts'] ?? null)) {
            $result['chart_counts'] = static::pick($result['chart_counts'], $order);
        }
        // each point keeps the color it was given in the view's own order
        if (is_array($result['point_colors'] ?? null)) {
            $result['point_colors'] = static::pick($result['point_colors'], $order);
        }
        return static::pickClick($result, $order);
    }

    /**
     * A multi-series result {x_categories, matrix[series][x], chart_click?} with its X
     * categories re-ordered: by their text (`x0`) or by their total over the series (`y`).
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public static function applyMulti(array $result, ChartSort $sort): array
    {
        $categories = array_values($result['x_categories'] ?? []);
        if ($sort->byValue()) {
            $totals = array_fill(0, count($categories), 0.0);
            foreach ($result['matrix'] ?? [] as $row) {
                foreach (array_values((array) $row) as $x => $value) {
                    if (is_numeric($value) && array_key_exists($x, $totals)) {
                        $totals[$x] += (float) $value;
                    }
                }
            }
            $order = static::order($totals, $sort->desc, true);
        } elseif ($sort->xIndex() === 0) {
            $order = static::order($categories, $sort->desc, false);
        } else {
            return $result;
        }

        $result['x_categories'] = static::pick($categories, $order);
        $result['matrix'] = array_map(function ($row) use ($order) {
            return static::pick(array_values((array) $row), $order);
        }, array_values($result['matrix'] ?? []));
        return static::pickClick($result, $order);
    }

    /**
     * @param mixed $value
     */
    protected static function text($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param mixed[] $items
     * @param int[] $order
     * @return mixed[]
     */
    protected static function pick(array $items, array $order): array
    {
        $items = array_values($items);
        return array_values(array_map(function ($i) use ($items) {
            return $items[$i] ?? null;
        }, $order));
    }

    /**
     * @param array<string, mixed> $result
     * @param int[] $order
     * @return array<string, mixed>
     */
    protected static function pickClick(array $result, array $order): array
    {
        if (is_array($result['chart_click'] ?? null) && is_array($result['chart_click']['values'] ?? null)) {
            $result['chart_click']['values'] = static::pick($result['chart_click']['values'], $order);
        }
        return $result;
    }
}
