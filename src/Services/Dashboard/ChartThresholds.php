<?php

namespace Exceedone\Exment\Services\Dashboard;

use ExmentAdminCore\Admin\Form\NestedForm;

/**
 * The color thresholds of a heatmap box (box option chart_thresholds), as Excel's 条件付き書式 →
 * セルの強調表示ルール: each rule a color, a condition and its value(s), in the units of the
 * drawn measure. A cell takes the color of the first rule it meets, top to bottom (the rules'
 * order is their priority, as Excel's); a cell no rule meets is drawn plain. No rule: the
 * heatmap keeps its continuous color scale.
 *
 * Stored: [{"color": "#f8696b", "condition": "lt", "value": 60},
 *          {"color": "#ffeb84", "condition": "range", "value": 60, "value2": 75}, ...]
 */
final class ChartThresholds
{
    /** the box option, and the name of the form's rules table */
    public const OPTION = 'chart_thresholds';

    /** the rules a box takes at most */
    public const MAX = 10;

    public const LT = 'lt';       // 未満: v < A
    public const LTE = 'lte';     // 以下: v <= A
    public const GTE = 'gte';     // 以上: v >= A
    public const GT = 'gt';       // より大きい: v > A
    public const RANGE = 'range'; // 範囲: A <= v < B

    public const CONDITIONS = [self::LT, self::LTE, self::GTE, self::GT, self::RANGE];

    /** the colors the form offers (Excel's color scale ones and a few more), name => color */
    public const PALETTE = [
        'red' => '#f8696b',
        'orange' => '#f9a65a',
        'yellow' => '#ffeb84',
        'green' => '#63be7b',
        'blue' => '#5a8ac6',
        'purple' => '#a881c9',
        'gray' => '#bfbfbf',
    ];

    /** the class of a cell no rule meets, and its color: plain */
    public const NONE = -1;
    public const NONE_COLOR = '#f7f7f7';

    /** @var array<int, array{color: string, condition: string, value: int|float, value2?: int|float}> */
    private $rules;

    /**
     * @param array<int, array{color: string, condition: string, value: int|float, value2?: int|float}> $rules
     */
    private function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    /**
     * @param mixed $raw  the stored option
     */
    public static function fromOption($raw): self
    {
        return new self(static::normalize($raw));
    }

    /**
     * The rules of $rows as stored, top to bottom: a row the form removed (_remove_) or one
     * that is no rule (errors()) left out, MAX at most.
     *
     * @param mixed $rows  the posted table / the stored option
     * @return array<int, array{color: string, condition: string, value: int|float, value2?: int|float}>
     */
    public static function normalize($rows): array
    {
        $rules = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (count($rules) >= static::MAX) {
                break;
            }
            if (!is_array($row) || static::removed($row) || count(static::errors($row)) > 0) {
                continue;
            }
            $rule = [
                'color' => strtolower(trim($row['color'])),
                'condition' => $row['condition'],
                'value' => static::number($row['value']),
            ];
            if ($rule['condition'] === static::RANGE) {
                $rule['value2'] = static::number($row['value2']);
            }
            $rules[] = $rule;
        }
        return $rules;
    }

    /**
     * What is wrong with a row of the form's table, as the cell at fault => a validation
     * message (":attribute" left for the validator to name the cell). Empty: a rule.
     *
     * @param array<mixed> $row
     * @return array<string, string>
     */
    public static function errors(array $row): array
    {
        $errors = [];
        $color = $row['color'] ?? null;
        if (!is_string($color) || trim($color) === '') {
            $errors['color'] = trans('validation.required');
        } elseif (!preg_match('/\A#[0-9a-f]{6}\z/i', trim($color))) {
            // #rrggbb only: the color is written into the page's style and script
            $errors['color'] = trans('validation.regex');
        }

        $condition = $row['condition'] ?? null;
        if (!is_string($condition) || !in_array($condition, static::CONDITIONS, true)) {
            $errors['condition'] = trans('validation.in', ['values' => implode(', ', static::conditionOptions())]);
        }

        $value = $row['value'] ?? null;
        if (static::isBlank($value)) {
            $errors['value'] = trans('validation.required');
        } elseif (!static::isNumber($value)) {
            $errors['value'] = trans('validation.numeric');
        }

        if ($condition === static::RANGE) {
            $value2 = $row['value2'] ?? null;
            if (static::isBlank($value2)) {
                $errors['value2'] = trans('validation.required');
            } elseif (!static::isNumber($value2)) {
                $errors['value2'] = trans('validation.numeric');
            } elseif (!isset($errors['value']) && (float) trim($value2) <= (float) trim($value)) {
                // A以上B未満 holds nothing unless B is above A
                $errors['value2'] = exmtrans('dashboard.chart_threshold.message.range_order');
            }
        }
        return $errors;
    }

    /**
     * @param array<mixed> $row
     */
    public static function removed(array $row): bool
    {
        return (string) ($row[NestedForm::REMOVE_FLAG_NAME] ?? '') === '1';
    }

    public function isEmpty(): bool
    {
        return count($this->rules) === 0;
    }

    /**
     * @return array<int, array{color: string, condition: string, value: int|float, value2?: int|float}>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * The class of a cell: the index of the first rule its value meets, NONE when it meets no
     * rule, null for an empty cell (no row fell into it).
     *
     * @param mixed $value
     */
    public function classOf($value): ?int
    {
        if (!static::isNumber($value)) {
            return null;
        }
        $v = (float) $value;
        foreach ($this->rules as $i => $rule) {
            if (static::meets($rule, $v)) {
                return $i;
            }
        }
        return static::NONE;
    }

    /**
     * What the heatmap draws by: the legend's pieces (pieces()) numbered in their order — the
     * number, `value`, is the class of the cells a piece colors (the legend lists its pieces
     * by it) — and each cell's class, matrix[series][x] as the chart's (null: empty).
     *
     * @param array<mixed> $matrix
     * @return array{classes: array<int, array<int, int|null>>, pieces: array<int, array{value: int, rule: int, color: string, label: string}>}
     */
    public function payload(array $matrix): array
    {
        $pieces = [];
        $position = [];
        foreach ($this->pieces() as $i => $piece) {
            $pieces[] = ['value' => $i] + $piece;
            $position[$piece['rule']] = $i;
        }
        $classes = [];
        foreach (array_values($matrix) as $row) {
            $classes[] = array_map(function ($v) use ($position) {
                $class = $this->classOf($v);
                return $class === null ? null : $position[$class];
            }, array_values((array) $row));
        }
        return ['classes' => $classes, 'pieces' => $pieces];
    }

    /**
     * The legend's pieces: a rule's named by its condition (60 未満, 75 以上), the plain cells'
     * by the values no rule takes (60〜75) — absent when the rules take every value. Ordered
     * by where they start on the scale, a rule before another starting there; `rule` the rule's
     * index (classOf), NONE the plain cells'.
     *
     * @return array<int, array{rule: int, color: string, label: string}>
     */
    public function pieces(): array
    {
        $pieces = [];
        foreach ($this->rules as $i => $rule) {
            $pieces[] = ['rule' => $i, 'color' => $rule['color'], 'label' => static::ruleLabel($rule), 'from' => static::interval($rule)];
        }
        $gaps = static::gaps(array_map(function ($rule) {
            return static::interval($rule);
        }, $this->rules));
        if (count($gaps)) {
            $pieces[] = [
                'rule' => static::NONE,
                'color' => static::NONE_COLOR,
                'label' => implode(exmtrans('dashboard.chart_threshold.legend.separator'), array_map(function ($gap) {
                    return static::gapLabel($gap);
                }, $gaps)),
                'from' => $gaps[0],
            ];
        }
        usort($pieces, function ($a, $b) {
            return static::compareStart($a['from'], $b['from']) ?: ($a['rule'] === static::NONE ? 1 : ($b['rule'] === static::NONE ? -1 : $a['rule'] <=> $b['rule']));
        });
        return array_map(function ($piece) {
            unset($piece['from']);
            return $piece;
        }, $pieces);
    }

    /**
     * The conditions as the form offers them.
     *
     * @return array<string, string>
     */
    public static function conditionOptions(): array
    {
        $options = [];
        foreach (static::CONDITIONS as $condition) {
            $options[$condition] = exmtrans('dashboard.chart_threshold.condition_options.' . $condition);
        }
        return $options;
    }

    /**
     * The palette as the form offers it.
     *
     * @return array<int, array{name: string, color: string}>
     */
    public static function palette(): array
    {
        $palette = [];
        foreach (static::PALETTE as $key => $color) {
            $palette[] = ['name' => exmtrans('dashboard.chart_threshold.palette.' . $key), 'color' => $color];
        }
        return $palette;
    }

    /**
     * A number as a label writes it: no trailing zero, thousands separated (1,000 / 59.5).
     *
     * @param int|float $n
     */
    public static function text($n): string
    {
        $fixed = rtrim(rtrim(sprintf('%.10F', $n), '0'), '.');
        $dot = strpos($fixed, '.');
        $text = number_format((float) $n, $dot === false ? 0 : strlen($fixed) - $dot - 1);
        return $text === '-0' ? '0' : $text;
    }

    /**
     * @param array{condition: string, value: int|float, value2?: int|float} $rule
     */
    private static function meets(array $rule, float $v): bool
    {
        $a = (float) $rule['value'];
        switch ($rule['condition']) {
            case static::LT:
                return $v < $a;
            case static::LTE:
                return $v <= $a;
            case static::GTE:
                return $v >= $a;
            case static::GT:
                return $v > $a;
            case static::RANGE:
                return $v >= $a && $v < (float) ($rule['value2'] ?? $a);
        }
        return false;
    }

    /**
     * @param array{condition: string, value: int|float, value2?: int|float} $rule
     */
    private static function ruleLabel(array $rule): string
    {
        $key = 'dashboard.chart_threshold.legend.' . $rule['condition'];
        return $rule['condition'] === static::RANGE
            ? exmtrans($key, static::text($rule['value']), static::text($rule['value2'] ?? $rule['value']))
            : exmtrans($key, static::text($rule['value']));
    }

    /**
     * The values a rule takes: [from, from included, to, to included] (±INF unbounded).
     *
     * @param array{condition: string, value: int|float, value2?: int|float} $rule
     * @return array{0: float, 1: bool, 2: float, 3: bool}
     */
    private static function interval(array $rule): array
    {
        $a = (float) $rule['value'];
        switch ($rule['condition']) {
            case static::LT:
                return [-INF, false, $a, false];
            case static::LTE:
                return [-INF, false, $a, true];
            case static::GTE:
                return [$a, true, INF, false];
            case static::GT:
                return [$a, false, INF, false];
        }
        return [$a, true, (float) ($rule['value2'] ?? $a), false];
    }

    /**
     * The values none of the intervals takes, as intervals in order.
     *
     * @param array<int, array{0: float, 1: bool, 2: float, 3: bool}> $intervals
     * @return array<int, array{0: float, 1: bool, 2: float, 3: bool}>
     */
    private static function gaps(array $intervals): array
    {
        usort($intervals, function ($a, $b) {
            return static::compareStart($a, $b);
        });
        // merged: the values taken, as disjoint intervals in order
        $merged = [];
        foreach ($intervals as $iv) {
            $last = count($merged) - 1;
            if ($last >= 0 && ($iv[0] < $merged[$last][2] || ($iv[0] == $merged[$last][2] && ($merged[$last][3] || $iv[1])))) {
                if ($iv[2] > $merged[$last][2]) {
                    $merged[$last][2] = $iv[2];
                    $merged[$last][3] = $iv[3];
                } elseif ($iv[2] == $merged[$last][2]) {
                    $merged[$last][3] = $merged[$last][3] || $iv[3];
                }
                continue;
            }
            $merged[] = $iv;
        }

        $gaps = [];
        $from = -INF;
        $fromIn = false;
        foreach ($merged as $iv) {
            // the values from where the last interval ended up to where this one starts
            if ($iv[0] > $from || ($iv[0] == $from && $fromIn && !$iv[1] && $from !== -INF)) {
                $gaps[] = [$from, $fromIn, $iv[0], !$iv[1]];
            }
            $from = $iv[2];
            $fromIn = !$iv[3];
        }
        if ($from !== INF) {
            $gaps[] = [$from, $fromIn, INF, false];
        }
        return $gaps;
    }

    /**
     * @param array{0: float, 1: bool, 2: float, 3: bool} $gap
     */
    private static function gapLabel(array $gap): string
    {
        $key = 'dashboard.chart_threshold.legend.';
        if ($gap[0] === -INF) {
            return exmtrans($key . ($gap[3] ? static::LTE : static::LT), static::text($gap[2]));
        }
        if ($gap[2] === INF) {
            return exmtrans($key . ($gap[1] ? static::GTE : static::GT), static::text($gap[0]));
        }
        if ($gap[0] == $gap[2]) {
            return static::text($gap[0]);
        }
        return exmtrans($key . 'between', static::text($gap[0]), static::text($gap[2]));
    }

    /**
     * Where two intervals start on the scale: the lower first, an included start before an
     * excluded one at the same value, then the one ending first.
     *
     * @param array{0: float, 1: bool, 2: float, 3: bool} $a
     * @param array{0: float, 1: bool, 2: float, 3: bool} $b
     */
    private static function compareStart(array $a, array $b): int
    {
        return ($a[0] <=> $b[0]) ?: (($b[1] <=> $a[1]) ?: ($a[2] <=> $b[2]));
    }

    /**
     * @param mixed $value
     */
    private static function isBlank($value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * A finite number, or a text of one (a stored measure comes as a string).
     *
     * @param mixed $value
     */
    private static function isNumber($value): bool
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) && is_finite((float) $value);
    }

    /**
     * @param mixed $value  a number or the text of one (isNumber)
     * @return int|float  a whole number as an int (60, not 60.0, in the stored JSON)
     */
    private static function number($value)
    {
        $f = (float) (is_string($value) ? trim($value) : $value);
        return (floor($f) == $f && abs($f) < 1e15) ? (int) $f : $f;
    }
}
