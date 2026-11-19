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
 * (chart_click.values). So are the series: by the stored value of the series column when
 * the row carries it (series_key), named by their text.
 *
 * A view grouped by more columns than the two the chart reads yields several rows per
 * cell; they are folded the way the measure's own summary condition folds rows: the lowest
 * for MIN, the highest for MAX, the sum otherwise — SUM, COUNT (the count of a cell is the
 * sum of its rows' counts) and a plain group column as the measure, which has no condition.
 *
 * A cell no row falls into is 0 for an adding measure — the sum or count of no rows — but
 * null for MIN / MAX, where 0 would be a value no row has: the boxplot would count it into
 * the spread, the heatmap and the lines would show it as a measured 0.
 */
final class ChartPivot
{
    /**
     * @param array<int, array{x: mixed, x_text: mixed, series: mixed, series_key?: mixed, y: mixed}> $rows
     *   one per view row, in the view's order: x = the stored value of the X column,
     *   x_text = its display text, series = the series column's text, series_key = its
     *   stored value (none: the text tells the series apart), y = the measure
     * @param string|int|null $condition  SummaryCondition of the measure; null = a group column
     * @return array{x_categories: string[], x_values: string[], series_names: string[], matrix: array<int, array<int, float|int|null>>}
     *   x_categories[i] = the text of category i, x_values[i] = its stored value,
     *   matrix[series][x] = the cell's value (no row falls into it: emptyCell())
     */
    public static function build(array $rows, $condition): array
    {
        $x_values = [];
        $x_categories = [];
        $series_names = [];
        $x_index = [];  // stored value => category index
        $s_index = [];  // series stored value (else text) => series index
        $cells = [];    // [series][x] => folded value, for the cells a row fell into
        foreach ($rows as $row) {
            $x = self::text($row['x'] ?? null);
            $s = array_key_exists('series_key', $row) ? self::text($row['series_key']) : self::text($row['series'] ?? null);
            if (!isset($x_index[$x])) {
                $x_index[$x] = count($x_values);
                $x_values[] = $x;
                $x_categories[] = self::text($row['x_text'] ?? null);
            }
            if (!isset($s_index[$s])) {
                $s_index[$s] = count($series_names);
                $series_names[] = self::text($row['series'] ?? null);
            }
            $y = $row['y'] ?? null;
            if (!is_numeric($y)) {
                continue; // no value: nothing to fold (a cell without one stays empty)
            }
            $xi = $x_index[$x];
            $si = $s_index[$s];
            $cells[$si][$xi] = isset($cells[$si][$xi]) ? self::fold($cells[$si][$xi], (float) $y, $condition) : (float) $y;
        }
        $matrix = array_fill(0, count($series_names), array_fill(0, count($x_values), self::emptyCell($condition)));
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
     * The value of a cell no row falls into: 0 for an adding measure (SUM, COUNT, a group
     * column), null for MIN / MAX — the lowest or highest of no rows is no number.
     *
     * @param string|int|null $condition
     */
    public static function emptyCell($condition): ?int
    {
        return in_array((string) $condition, [SummaryCondition::MIN, SummaryCondition::MAX], true) ? null : 0;
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
