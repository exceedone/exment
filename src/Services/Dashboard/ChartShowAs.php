<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Enums\SummaryCondition;

/**
 * Runtime "show values as" of a chart box (`sv` on the box request), picked in the box menu's
 * 表示方法 submenu as Excel's PivotTable 値の表示方法 ▸: the measure's numbers, as the
 * aggregate view summarized them, shown as a share of the grand total, a running total or the
 * difference from the previous item, for this render only.
 *
 * Calculated on the drawn numbers in the order they are drawn (after a runtime sort), the way
 * Excel calculates on the cells of the pivot: the view's query is not touched. Nothing is saved
 * and the choice is not remembered (a reload shows the plain values).
 *
 * A multi-series chart calculates as Excel does with the series as columns of the pivot and the
 * X axis as its rows (the base field): the grand total is the total of every cell; running
 * totals and differences go along the X axis within each series.
 *
 * An empty point (null: no value, or a cell no row fell into) adds nothing to a total; it shows
 * no difference, and nothing is a difference from it. A percent difference is over the previous
 * item as it is, sign and all, as Excel's ((value - previous) / previous). A percent with nothing
 * to divide by (a total or a previous item of 0) is empty too, where Excel shows #DIV/0!.
 */
final class ChartShowAs
{
    /** the request value */
    public const PARAM = 'sv';

    public const NONE = 'none';
    public const PERCENT_OF_TOTAL = 'percent_of_total';
    public const RUNNING_TOTAL = 'running_total';
    public const DIFFERENCE = 'difference';
    public const PERCENT_DIFFERENCE = 'percent_difference';
    /**
     * 行集計に対する比率: each cell as its share of its X item (a row of the pivot), over the
     * size of the item — the total of its cells as they are, a negative one counted by its size,
     * as Excel's 100% stacked chart draws it below 0 (with no negative cell, Excel's 行集計に対する
     * 比率 itself). What the 100% stacked types draw (fullStacked()); not in the 表示方法 menu.
     */
    public const PERCENT_OF_ROW = 'percent_of_row';

    /** decimals a calculated value keeps: float noise (0.1 + 0.2) off, any real digit on */
    private const PRECISION = 10;

    /** @var string[] the ways offered, NONE first ([] = no 表示方法 row) */
    private $choices;

    /** @var string|null the picked way when it is offered and not NONE */
    private $picked;

    /**
     * @param string[] $choices  choicesFor()
     * @param mixed $requested  `sv` of the request
     */
    public function __construct(array $choices, $requested)
    {
        $this->choices = $choices;
        if (is_string($requested) && $requested !== self::NONE && in_array($requested, $choices, true)) {
            $this->picked = $requested;
        }
    }

    /**
     * The ways a chart may show its measure, NONE first; [] when it has none but NONE.
     *
     * - The rendered type must be one whose value axis a calculated number fits: the bars,
     *   the lines and the areas; the pie and the doughnut take the share of the total only.
     *   A stacked type does not take the percent difference: stacked percents of each series
     *   add up to no percent of the stack.
     * - The measure must be a summary column of the aggregate view (its condition known).
     *   An average, a minimum or a maximum adds up to nothing, so it takes the differences only;
     *   a minimum / maximum of a column that is no number (a date, a text) takes nothing.
     *
     * @param string|null $chart_type  the rendered type
     * @param string|null $condition  the measure's summary condition as drawn (null: not a summary column)
     * @param bool $numeric  the measure's column is a number column
     * @return string[]
     */
    public static function choicesFor(?string $chart_type, ?string $condition, bool $numeric): array
    {
        if ($condition === null) {
            return [];
        }
        $additive = in_array($condition, [SummaryCondition::SUM, SummaryCondition::COUNT], true);
        if (!$additive && !$numeric) {
            return [];
        }
        if (ChartType::isFullStacked($chart_type)) {
            // the type itself shows the values as shares of their bar: nothing to calculate on top
            return [self::NONE];
        }
        $all = [self::PERCENT_OF_TOTAL, self::RUNNING_TOTAL, self::DIFFERENCE, self::PERCENT_DIFFERENCE];
        $by_type = [
            ChartType::BAR => $all,
            ChartType::HBAR => $all,
            ChartType::LINE => $all,
            ChartType::AREA => $all,
            ChartType::MBAR => $all,
            ChartType::MLINE => $all,
            ChartType::SBAR => [self::PERCENT_OF_TOTAL, self::RUNNING_TOTAL, self::DIFFERENCE],
            ChartType::SAREA => [self::PERCENT_OF_TOTAL, self::RUNNING_TOTAL, self::DIFFERENCE],
            ChartType::PIE => [self::PERCENT_OF_TOTAL],
            ChartType::DOUGHNUT => [self::PERCENT_OF_TOTAL],
        ];
        $choices = $by_type[$chart_type] ?? [];
        if (!$additive) {
            $choices = array_values(array_diff($choices, [self::PERCENT_OF_TOTAL, self::RUNNING_TOTAL]));
        }
        return empty($choices) ? [] : array_merge([self::NONE], $choices);
    }

    /**
     * The values of a 100% stacked chart: each cell its share of its X item (PERCENT_OF_ROW).
     */
    public static function fullStacked(): self
    {
        return new self([self::PERCENT_OF_ROW], self::PERCENT_OF_ROW);
    }

    /**
     * Whether the box menu offers the 表示方法 row: a 100% stacked type shows it with 計算なし only.
     */
    public function available(): bool
    {
        return !empty($this->choices);
    }

    /**
     * Whether the values are shown other than as they are.
     */
    public function active(): bool
    {
        return $this->picked !== null;
    }

    /**
     * The way the values are shown: NONE unless one is picked.
     */
    public function current(): string
    {
        return $this->picked ?? self::NONE;
    }

    /**
     * @return string[]
     */
    public function choices(): array
    {
        return $this->choices;
    }

    /**
     * Whether the shown values are percents (drawn and exported with "%").
     */
    public function isPercent(): bool
    {
        return in_array($this->picked, [self::PERCENT_OF_TOTAL, self::PERCENT_DIFFERENCE, self::PERCENT_OF_ROW], true);
    }

    /**
     * Whether the shown values are differences from the previous item (as a number or a percent).
     */
    public function isDifference(): bool
    {
        return in_array($this->picked, [self::DIFFERENCE, self::PERCENT_DIFFERENCE], true);
    }

    /**
     * The name of a way: 総計に対する比率, 累計, …
     */
    public static function label(string $show_as): string
    {
        return (string) exmtrans('dashboard.chart_show_as.options.' . $show_as);
    }

    /**
     * $label with the way the values are shown after it: 合計 ： 売上金額（累計）. As it is
     * when nothing is picked.
     *
     * @param mixed $label
     * @return mixed
     */
    public function decorate($label)
    {
        if (!$this->active()) {
            return $label;
        }
        return (string) exmtrans('dashboard.chart_show_as.decorated', (string) $label, static::label($this->current()));
    }

    /**
     * The values of a single-series chart, in drawn order, shown the picked way.
     *
     * @param iterable<mixed> $values
     * @return array<int, mixed>
     */
    public function applyValues($values): array
    {
        $values = collect($values)->values()->all();
        if (!$this->active()) {
            return $values;
        }
        return $this->calculate([$values])[0];
    }

    /**
     * The cells of a multi-series chart (matrix[series][x], X in drawn order) shown the picked way.
     *
     * @param array<int, iterable<mixed>> $matrix
     * @return array<int, array<int, mixed>>
     */
    public function applyMatrix(array $matrix): array
    {
        $rows = array_map(function ($row) {
            return collect($row)->values()->all();
        }, array_values($matrix));
        if (!$this->active()) {
            return $rows;
        }
        return $this->calculate($rows);
    }

    /**
     * @param array<int, array<int, mixed>> $rows  a row per series, a cell per X item
     * @return array<int, array<int, float|null>>
     */
    private function calculate(array $rows): array
    {
        $rows = array_map(function ($row) {
            return array_map([self::class, 'number'], $row);
        }, $rows);

        switch ($this->picked) {
            case self::PERCENT_OF_TOTAL:
                $total = 0.0;
                foreach ($rows as $row) {
                    $total += array_sum(array_filter($row, 'is_float'));
                }
                return array_map(function ($row) use ($total) {
                    return array_map(function ($v) use ($total) {
                        return static::percent($v, $total);
                    }, $row);
                }, $rows);

            case self::PERCENT_OF_ROW:
                $sizes = [];
                foreach ($rows as $row) {
                    foreach ($row as $x => $v) {
                        $sizes[$x] = ($sizes[$x] ?? 0.0) + abs($v ?? 0.0);
                    }
                }
                return array_map(function ($row) use ($sizes) {
                    $out = [];
                    foreach ($row as $x => $v) {
                        $out[] = static::percent($v, $sizes[$x]);
                    }
                    return $out;
                }, $rows);

            case self::RUNNING_TOTAL:
                return array_map(function ($row) {
                    $sum = null;
                    return array_map(function ($v) use (&$sum) {
                        if ($v !== null) {
                            $sum = ($sum ?? 0.0) + $v;
                        }
                        return $sum === null ? null : static::round($sum);
                    }, $row);
                }, $rows);

            case self::DIFFERENCE:
            case self::PERCENT_DIFFERENCE:
                $percent = $this->picked === self::PERCENT_DIFFERENCE;
                return array_map(function ($row) use ($percent) {
                    $out = [];
                    foreach ($row as $x => $v) {
                        $previous = $x > 0 ? $row[$x - 1] : null;
                        if ($v === null || $previous === null) {
                            $out[] = null;
                        } elseif ($percent) {
                            // over the previous item as it is, as Excel: -100 → -50 is -50%
                            $out[] = static::percent($v - $previous, $previous);
                        } else {
                            $out[] = static::round($v - $previous);
                        }
                    }
                    return $out;
                }, $rows);
        }
        return $rows;
    }

    /**
     * A drawn value as a number; null for an empty or non-numeric one.
     *
     * @param mixed $value
     */
    private static function number($value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function percent(?float $value, float $whole): ?float
    {
        if ($value === null || $whole == 0) {
            return null;
        }
        return static::round($value / $whole * 100);
    }

    private static function round(float $value): float
    {
        return round($value, self::PRECISION);
    }
}
