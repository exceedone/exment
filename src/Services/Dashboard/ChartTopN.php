<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ChartType;

/**
 * Runtime 表示件数 of a chart box (`tn` on the box request), picked in the box menu: only the
 * N largest points drawn, the rest grouped into one point その他 at the end — Excel's Top 10
 * filter hides the rest, this one keeps them as one item, so a total still adds up.
 *
 * `tn` = "{N}" (top N only) or "{N}:other" (top N + その他), N one of CHOICES. Remembered per
 * user like the chart type and the sort (ChartItem::toolbarState).
 *
 * Ranked on the values as the measure is summarized (after the 集計方法, before the 表示方法:
 * a share of the total is a share of the points drawn, その他 included). A multi-series chart
 * ranks its X categories by the total of their series and groups a category's cells per
 * series. A point with no value ranks below any number; ties keep the order the view gives
 * them. The points kept are drawn largest first, or in the runtime sort when there is one;
 * その他 is always last.
 *
 * その他 only for a measure that adds up (SUM / COUNT of an aggregate view): an average, a
 * minimum or a maximum of the rest is no number of the chart's kind, and a list view's rows
 * are rows, not groups — those keep the N points only (the other request is drawn so).
 */
final class ChartTopN
{
    /** the request value */
    public const PARAM = 'tn';

    /** the number of points a viewer can pick */
    public const CHOICES = [5, 10, 20];

    /** the flag of "{N}:other" */
    public const OTHER = 'other';

    /** the key a 明細 request names the その他 point by (ChartDetailRequest): no group column id is a word */
    public const REST_KEY = 'rest';

    /** the color of the その他 point on a chart painting each point (a gray, as Excel's "Other") */
    public const REST_COLOR = '#c5c9d1';

    /** how many of the points grouped into その他 its tooltip names */
    public const REST_LISTED = 6;

    /** @var bool the chart can be cut to its top points (type, X axis) */
    private $available;

    /** @var bool its measure adds up: the rest can be grouped into その他 */
    private $groupable;

    /** @var int|null the N picked */
    private $n;

    /** @var bool その他 asked for (whether drawn: withOther()) */
    private $other_requested = true;

    /** @var int|null the number of points before the cut (set by apply*) */
    private $count;

    /**
     * @param bool $available  the chart can be cut (ChartItem: type, X axis not a date)
     * @param bool $groupable  the measure adds up (SUM / COUNT of an aggregate view)
     * @param mixed $requested  `tn` of the request
     */
    public function __construct(bool $available, bool $groupable, $requested)
    {
        $this->available = $available;
        $this->groupable = $groupable;
        $parsed = static::parse($requested);
        if ($parsed !== null) {
            $this->other_requested = $parsed[1];
            if ($available) {
                $this->n = $parsed[0];
            }
        }
    }

    /**
     * "5" / "10:other" → [5, false] / [10, true]; null for anything else.
     *
     * @param mixed $tn
     * @return array{0: int, 1: bool}|null
     */
    public static function parse($tn): ?array
    {
        if (!is_string($tn) || !preg_match('/^(' . implode('|', self::CHOICES) . ')(:' . self::OTHER . ')?$/', $tn, $m)) {
            return null;
        }
        return [(int) $m[1], isset($m[2])];
    }

    /**
     * `tn` in its own form, or null when it is not one (the toolbar state keeps it so).
     *
     * @param mixed $tn
     */
    public static function normalize($tn): ?string
    {
        $parsed = static::parse($tn);
        return $parsed === null ? null : $parsed[0] . ($parsed[1] ? ':' . self::OTHER : '');
    }

    /**
     * Whether a chart type can be cut to its top points: one value per category, read across
     * them — not the gauge (one value), the scatter (every point a dot of its own place), the
     * heatmap (a table of cells) nor the boxplot (a box of the series' spread: its その他 would
     * be a spread of sums).
     */
    public static function supportsType(?string $type): bool
    {
        return $type !== null && !in_array($type, [ChartType::GAUGE, ChartType::SCATTER, ChartType::HEATMAP, ChartType::BOXPLOT], true);
    }

    /**
     * Whether the points' labels read as a time line — every one a date, a year-month, a year
     * (2026年) or a month (4月): a text column of months ("2026-04"), which the column type does
     * not tell. Two points at least; an empty label (未設定) says nothing either way.
     *
     * @param array<int, mixed> $labels
     */
    public static function isTimeline(array $labels): bool
    {
        $dated = 0;
        foreach ($labels as $label) {
            $label = trim((string) $label);
            if ($label === '') {
                continue;
            }
            if (!preg_match('/^(\d{4}([-\/.]\d{1,2}([-\/.]\d{1,2})?|年(\d{1,2}月(\d{1,2}日)?)?)|\d{1,2}月)$/u', $label)) {
                return false;
            }
            $dated++;
        }
        return $dated >= 2;
    }

    /**
     * Whether the box menu may offer 表示件数.
     */
    public function available(): bool
    {
        return $this->available;
    }

    /**
     * Whether the measure adds up, so the rest can be grouped into その他.
     */
    public function groupable(): bool
    {
        return $this->groupable;
    }

    /**
     * Whether the chart is cut to its top N.
     */
    public function active(): bool
    {
        return $this->n !== null;
    }

    public function n(): ?int
    {
        return $this->n;
    }

    /**
     * Whether the rest is drawn as その他 (asked for, and the measure adds up).
     */
    public function withOther(): bool
    {
        return $this->active() && $this->other_requested && $this->groupable;
    }

    /**
     * Whether その他 is asked for — the menu's checkbox: on unless the request says "{N}" alone.
     */
    public function otherRequested(): bool
    {
        return $this->other_requested;
    }

    /**
     * The number of points before the cut; null until a result is cut (apply*).
     */
    public function count(): ?int
    {
        return $this->count;
    }

    /**
     * The number of points the cut leaves out (into その他 or out of the chart).
     */
    public function hidden(): int
    {
        return $this->active() && $this->count !== null ? max(0, $this->count - (int) $this->n) : 0;
    }

    /**
     * `tn` as drawn: "" when the chart is not cut.
     */
    public function applied(): string
    {
        return $this->active() ? $this->n . ($this->withOther() ? ':' . self::OTHER : '') : '';
    }

    /**
     * The name of what is drawn: 上位5件 / 上位5件＋その他.
     */
    public function label(): string
    {
        return (string) exmtrans($this->withOther() ? 'dashboard.chart_top_n.top_other' : 'dashboard.chart_top_n.top', $this->n);
    }

    /**
     * $label with the cut after it: クラス別 件数 ： 学籍番号（上位5件＋その他）. As it is when
     * the chart is not cut.
     *
     * @param mixed $label
     * @return mixed
     */
    public function decorate($label)
    {
        if (!$this->active()) {
            return $label;
        }
        return (string) exmtrans('dashboard.chart_show_as.decorated', (string) $label, $this->label());
    }

    /**
     * A single-series result {chart_label, chart_data, ...} cut to its top N points, in the
     * runtime sort ($sort) or largest first, その他 appended (rest()).
     *
     * @param array<string, mixed> $result
     * @param bool $rest_detail  the その他 point has a 明細 (the rows of the points left out)
     * @return array<string, mixed>
     */
    public function applySingle(array $result, ?ChartSort $sort, bool $rest_detail): array
    {
        $values = ChartSorter::listOf($result['chart_data'] ?? null);
        $labels = ChartSorter::listOf($result['chart_label'] ?? null);
        $this->count = count($values);
        $ranks = $this->rank($values);
        $kept = $this->kept($ranks);
        $result = ChartSorter::applySingle(ChartSorter::pickSingle($result, $kept), $sort ?? static::largestFirst());
        $rest = $this->restOf($ranks);
        if (empty($rest)) {
            return $result;
        }

        // the 明細 keys of the points kept: a row of その他 is a row of none of them
        $kept_keys = array_values(array_filter((array) ($result['detail']['points'] ?? []), 'is_array'));
        $label = $this->restLabel(count($rest));
        $result['chart_label'] = array_merge(ChartSorter::listOf($result['chart_label']), [$label]);
        $result['chart_data'] = array_merge(ChartSorter::listOf($result['chart_data']), [static::sum(array_map(function ($i) use ($values) {
            return $values[$i];
        }, $rest))]);
        if (isset($result['chart_fields'])) {
            $result['chart_fields'] = array_map(function ($column, $x) use ($label) {
                return array_merge(ChartSorter::listOf($column), [$x === 0 ? $label : '']);
            }, array_values((array) $result['chart_fields']), array_keys(array_values((array) $result['chart_fields'])));
        }
        if (is_array($result['point_colors'] ?? null)) {
            $result['point_colors'][] = static::REST_COLOR;
        }
        $result = $this->appendKeys($result, 'points', $rest_detail ? [static::REST_KEY => 1] : null);
        $result['top_n'] = $this->restPayload($labels, $values, $rest, count($result['chart_data']) - 1) + [
            'excluded' => $rest_detail ? $kept_keys : null,
        ];
        return $result;
    }

    /**
     * A multi-series result {x_categories, matrix[series][x], ...} cut to its top N X
     * categories by the total of their series, その他 appended with each series' total of the
     * rest.
     *
     * @param array<string, mixed> $result
     * @param bool $rest_detail  the その他 cells have a 明細
     * @param mixed $x_column  the X view column id (the 明細 keys of the points kept)
     * @return array<string, mixed>
     */
    public function applyMulti(array $result, ?ChartSort $sort, bool $rest_detail, $x_column = null): array
    {
        $categories = array_values($result['x_categories'] ?? []);
        $matrix = array_map(function ($row) {
            return array_values((array) $row);
        }, array_values($result['matrix'] ?? []));
        $totals = [];
        foreach (array_keys($categories) as $x) {
            $totals[$x] = static::sum(array_map(function ($row) use ($x) {
                return $row[$x] ?? null;
            }, $matrix));
        }
        $this->count = count($categories);
        $ranks = $this->rank($totals);
        $kept = $this->kept($ranks);
        $x_keys = is_array($result['detail']['x_keys'] ?? null) ? array_values($result['detail']['x_keys']) : null;
        $result = ChartSorter::applyMulti(ChartSorter::pickMulti($result, $kept), $sort ?? static::largestFirst());
        $rest = $this->restOf($ranks);
        if (empty($rest)) {
            return $result;
        }

        $result['x_categories'][] = $this->restLabel(count($rest));
        $result['matrix'] = array_map(function ($row, $s) use ($matrix, $rest) {
            return array_merge($row, [static::sum(array_map(function ($x) use ($matrix, $s) {
                return $matrix[$s][$x] ?? null;
            }, $rest))]);
        }, $result['matrix'], array_keys($result['matrix']));
        $result = $this->appendKeys($result, 'x_keys', null);
        if (is_array($result['detail'] ?? null)) {
            // the その他 category of a cell's 明細 (echart_multi detailKeys)
            $result['detail']['rest'] = $rest_detail ? count($result['x_categories']) - 1 : null;
        }
        $excluded = null;
        if ($rest_detail && $x_keys !== null && $x_column !== null) {
            $excluded = array_map(function ($x) use ($x_keys, $x_column) {
                return [$x_column => $x_keys[$x] ?? null];
            }, $kept);
        }
        $result['top_n'] = $this->restPayload($categories, $totals, $rest, count($result['x_categories']) - 1) + [
            'excluded' => $excluded,
        ];
        return $result;
    }

    /**
     * The indexes of the points, largest value first (a point with no value last; ties in
     * the view's order).
     *
     * @param array<int, mixed> $values
     * @return int[]
     */
    private function rank(array $values): array
    {
        return ChartSorter::order($values, true, true);
    }

    /**
     * The indexes of the top N points, in the view's order.
     *
     * @param int[] $ranks
     * @return int[]
     */
    private function kept(array $ranks): array
    {
        $kept = array_slice($ranks, 0, (int) $this->n);
        sort($kept);
        return $kept;
    }

    /**
     * The indexes of the points grouped into その他, largest first; [] without one.
     *
     * @param int[] $ranks
     * @return int[]
     */
    private function restOf(array $ranks): array
    {
        return $this->withOther() ? array_slice($ranks, (int) $this->n) : [];
    }


    /**
     * The point's 明細 keys list ($key: points / x_keys) with the その他 point's appended.
     *
     * @param array<string, mixed> $result
     * @param mixed $keys
     * @return array<string, mixed>
     */
    private function appendKeys(array $result, string $key, $keys): array
    {
        if (is_array($result['chart_click']['values'] ?? null)) {
            // a click on その他 picks nothing on the filter bar (dashboard.js pick ignores '')
            $result['chart_click']['values'][] = '';
        }
        if (is_array($result['detail'][$key] ?? null)) {
            $result['detail'][$key][] = $keys;
        }
        if (is_array($result['sort_keys'] ?? null)) {
            foreach ($result['sort_keys'] as $x => $column) {
                $result['sort_keys'][$x] = array_merge(ChartSorter::listOf($column), [null]);
            }
        }
        return $result;
    }

    /**
     * What the chart's tooltip of その他 and the 明細 caption tell: {index, count, items
     * [[label, value]] (the largest REST_LISTED), more}.
     *
     * @param array<int, mixed> $labels  every point's label, before the cut
     * @param array<int, mixed> $values  every point's value (a category's total), before the cut
     * @param int[] $rest  the points in その他, largest first
     * @return array<string, mixed>
     */
    private function restPayload(array $labels, array $values, array $rest, int $index): array
    {
        $items = [];
        foreach (array_slice($rest, 0, static::REST_LISTED) as $i) {
            $items[] = [(string) ($labels[$i] ?? ''), is_numeric($values[$i] ?? null) ? $values[$i] + 0 : null];
        }
        return [
            'index' => $index,
            'count' => count($rest),
            'items' => $items,
            'more' => max(0, count($rest) - static::REST_LISTED),
        ];
    }

    /**
     * その他（13件）
     */
    public function restLabel(int $count): string
    {
        return (string) exmtrans('dashboard.chart_top_n.other', $count);
    }

    /**
     * The total of the values that are numbers; null when none is.
     *
     * @param array<int, mixed> $values
     * @return int|float|null
     */
    private static function sum(array $values)
    {
        $sum = null;
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $sum = ($sum ?? 0) + $value;
            }
        }
        return $sum === null ? null : (is_float($sum) ? round($sum, 10) : $sum);
    }

    private static function largestFirst(): ChartSort
    {
        /** @var ChartSort $sort */
        $sort = ChartSort::parse('y:' . ChartSort::DESC);
        return $sort;
    }
}
