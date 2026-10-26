<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\SummaryCondition;

/**
 * The pivot behind a multi-series chart: the rows of an aggregate view — one per
 * combination of its group columns — folded into X categories × series, each cell holding
 * the measure of the rows that fall into it. Pure functions over the arrays ChartItem
 * reads off the rows — no query involved.
 *
 * A category is one STORED value of the X column, not one display text: two values that
 * share a text (two same-name records behind a select_table column) stay two categories,
 * as the single-series chart draws them, and a click on either picks exactly that value
 * (chart_click.values). The series are told apart by their text, as the legend is.
 *
 * A view grouped by more columns than the two the chart reads yields several rows per
 * cell; they are folded the way the measure's own summary condition folds rows: the lowest
 * for MIN, the highest for MAX, the sum otherwise — SUM, COUNT (the count of a cell is the
 * sum of its rows' counts) and a plain group column as the measure, which has no condition.
 */
final class ChartPivot
{
    /**
     * @param array<int, array{x: mixed, x_text: mixed, series: mixed, y: mixed}> $rows
     *   one per view row, in the view's order: x = the stored value of the X column,
     *   x_text = its display text, series = the series column's text, y = the measure
     * @param string|int|null $condition  SummaryCondition of the measure; null = a group column
     * @return array{x_categories: string[], x_values: string[], series_names: string[], matrix: array<int, array<int, float|int>>}
     *   x_categories[i] = the text of category i, x_values[i] = its stored value,
     *   matrix[series][x] = the cell's value (0 when no row falls into it)
     */
    public static function build(array $rows, $condition): array
    {
        $x_values = [];
        $x_categories = [];
        $series_names = [];
        $x_index = [];  // stored value => category index
        $s_index = [];  // series text => series index
        $cells = [];    // [series][x] => folded value, for the cells a row fell into
        foreach ($rows as $row) {
            $x = self::text($row['x'] ?? null);
            $s = self::text($row['series'] ?? null);
            if (!isset($x_index[$x])) {
                $x_index[$x] = count($x_values);
                $x_values[] = $x;
                $x_categories[] = self::text($row['x_text'] ?? null);
            }
            if (!isset($s_index[$s])) {
                $s_index[$s] = count($series_names);
                $series_names[] = $s;
            }
            $y = $row['y'] ?? null;
            if (!is_numeric($y)) {
                continue; // no value: nothing to fold (a cell without one stays 0)
            }
            $xi = $x_index[$x];
            $si = $s_index[$s];
            $cells[$si][$xi] = isset($cells[$si][$xi]) ? self::fold($cells[$si][$xi], (float) $y, $condition) : (float) $y;
        }
        $matrix = array_fill(0, count($series_names), array_fill(0, count($x_values), 0));
        foreach ($cells as $si => $row) {
            foreach ($row as $xi => $value) {
                $matrix[$si][$xi] = $value;
            }
        }
        return [
            'x_categories' => $x_categories,
            'x_values' => $x_values,
            'series_names' => $series_names,
            'matrix' => $matrix,
        ];
    }

    /**
     * Two values of one cell folded as $condition (SummaryCondition) folds rows.
     *
     * @param string|int|null $condition
     */
    public static function fold(float $a, float $b, $condition): float
    {
        if ((string) $condition === SummaryCondition::MIN) {
            return min($a, $b);
        }
        if ((string) $condition === SummaryCondition::MAX) {
            return max($a, $b);
        }
        return $a + $b;
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
