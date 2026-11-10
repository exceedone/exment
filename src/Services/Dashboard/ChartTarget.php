<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ChartType;

/**
 * The target line of a chart box (目標線), as Excel draws one with a column of the target and a
 * combo chart: a dashed line at the target across the value axis — a horizontal line, a
 * vertical one on a horizontal bar, the total of a stack on a stacked type — named on the
 * chart, and, when asked, the bars of a single-series bar / horizontal bar under it drawn in
 * the line's color. In the units of the measure as the view summarizes it.
 *
 * Stored in the box options: chart_target_value (a number; none = no line), chart_target_label
 * (its name, optional), chart_target_color (#rrggbb, 赤 by default), chart_target_below ("1").
 */
final class ChartTarget
{
    public const VALUE = 'chart_target_value';
    public const LABEL = 'chart_target_label';
    public const COLOR = 'chart_target_color';
    public const BELOW = 'chart_target_below';

    /** the most characters of the name */
    public const LABEL_MAX = 30;

    /** 赤 of the thresholds' palette (the form's color button offers the same) */
    public const DEFAULT_COLOR = ChartThresholds::PALETTE['red'];

    /** @var int|float */
    private $value;

    /** @var string */
    private $label;

    /** @var string */
    private $color;

    /** @var bool */
    private $below;

    /**
     * @param int|float $value
     */
    private function __construct($value, string $label, string $color, bool $below)
    {
        $this->value = $value;
        $this->label = $label;
        $this->color = $color;
        $this->below = $below;
    }

    /**
     * The target of the box options; null without one (no value, or none that is a target).
     *
     * @param mixed $options  the box options
     */
    public static function fromOptions($options): ?self
    {
        if (!is_array($options) || count(static::errors($options)) > 0 || static::isBlank($options[static::VALUE] ?? null)) {
            return null;
        }
        $color = trim((string) ($options[static::COLOR] ?? ''));
        return new self(
            static::number($options[static::VALUE]),
            static::cleanLabel($options[static::LABEL] ?? null),
            $color === '' ? static::DEFAULT_COLOR : strtolower($color),
            (string) ($options[static::BELOW] ?? '') === '1'
        );
    }

    /**
     * The types a target line is drawn on: the ones with a value axis read against a value —
     * not the circular and shape types, the scatter of a list's points, the heatmap colored by
     * its own thresholds, nor a 100% stacked type, whose axis holds shares.
     */
    public static function supportsType(?string $type): bool
    {
        return in_array($type, [ChartType::BAR, ChartType::HBAR, ChartType::LINE, ChartType::AREA, ChartType::COMBO, ChartType::MBAR, ChartType::SBAR, ChartType::MLINE, ChartType::SAREA], true);
    }

    /**
     * The types the bars under the target are colored on: a single series of bars, whose bars
     * have no color of their own to tell apart (a series of a multi-series type keeps its color);
     * the 複合 type's bars of its 値, the line read against the left axis, theirs.
     */
    public static function supportsBelow(?string $type): bool
    {
        return in_array($type, [ChartType::BAR, ChartType::HBAR, ChartType::COMBO], true);
    }

    /**
     * What is wrong with the target fields of the posted options, the field at fault => a
     * validation message (":attribute" left for the validator to name the field). Empty: none.
     *
     * @param array<mixed> $options
     * @return array<string, string>
     */
    public static function errors(array $options): array
    {
        $errors = [];
        $value = $options[static::VALUE] ?? null;
        $label = $options[static::LABEL] ?? null;
        if (static::isBlank($value)) {
            // a name of no line: the value is what was forgotten
            if (!static::isBlank($label)) {
                $errors[static::VALUE] = trans('validation.required');
            }
        } elseif (!static::isNumber($value)) {
            $errors[static::VALUE] = trans('validation.numeric');
        }

        if (!is_null($label) && !is_string($label)) {
            $errors[static::LABEL] = trans('validation.string');
        } elseif (is_string($label) && preg_match('/[\x00-\x1f\x7f]/u', $label)) {
            // one line, drawn beside the line
            $errors[static::LABEL] = trans('validation.regex');
        } elseif (is_string($label) && mb_strlen(trim($label)) > static::LABEL_MAX) {
            $errors[static::LABEL] = trans('validation.max.string', ['max' => static::LABEL_MAX]);
        }

        $color = $options[static::COLOR] ?? null;
        if (!is_null($color) && (!is_string($color) || (trim($color) !== '' && !preg_match('/\A#[0-9a-f]{6}\z/i', trim($color))))) {
            // #rrggbb only: the color is written into the page's script
            $errors[static::COLOR] = trans('validation.regex');
        }
        return $errors;
    }

    /**
     * The options a save stores: the target as a number, its name trimmed, its color in lower
     * case (赤 when none is picked), the bars under it colored on a bar / horizontal bar only;
     * no target field at all without a value, or on a type the form hides them on.
     *
     * @param array<string, mixed> $options  the box options as posted (validated)
     * @return array<string, mixed>
     */
    public static function saved(array $options): array
    {
        $type = $options['chart_type'] ?? null;
        $target = static::supportsType(is_string($type) ? $type : null) ? static::fromOptions($options) : null;
        foreach ([static::VALUE, static::LABEL, static::COLOR, static::BELOW] as $key) {
            unset($options[$key]);
        }
        if ($target === null) {
            return $options;
        }
        $options[static::VALUE] = $target->value;
        if ($target->label !== '') {
            $options[static::LABEL] = $target->label;
        }
        $options[static::COLOR] = $target->color;
        if ($target->below && static::supportsBelow($type)) {
            $options[static::BELOW] = '1';
        }
        return $options;
    }

    /**
     * @return int|float
     */
    public function value()
    {
        return $this->value;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function color(): string
    {
        return $this->color;
    }

    public function below(): bool
    {
        return $this->below;
    }

    /**
     * The name the chart gives the line: its label, else 目標.
     */
    public function name(): string
    {
        return $this->label !== '' ? $this->label : (string) exmtrans('dashboard.chart_target.default_name');
    }

    /**
     * The points under the target, by index: a number less than it (one equal to it reaches it),
     * as drawn — not an empty point, nor the その他 point of 表示件数, which groups several.
     *
     * @param iterable<mixed> $values  the drawn values, in the drawn order
     * @param int|null $rest  the index of その他
     * @return int[]
     */
    public function belowPoints(iterable $values, ?int $rest = null): array
    {
        $points = [];
        $i = 0;
        foreach ($values as $v) {
            if ($i !== $rest && static::isNumber($v) && (float) (is_string($v) ? trim($v) : $v) < (float) $this->value) {
                $points[] = $i;
            }
            $i++;
        }
        return $points;
    }

    /**
     * What a chart draws the line by: {value, name, text (the value as a label writes it), diff
     * (the tooltip's gap of a point to it, {d} for the gap), color, below (the indexes of the
     * bars drawn in its color; [] when they are not colored)}.
     *
     * @param iterable<mixed>|null $values  the drawn values of a type coloring the bars under it; null: no bar colored
     * @param int|null $rest  the index of その他
     * @return array{value: int|float, name: string, text: string, diff: string, color: string, below: int[]}
     */
    public function payload(?iterable $values = null, ?int $rest = null): array
    {
        return [
            'value' => $this->value,
            'name' => $this->name(),
            'text' => ChartThresholds::text($this->value),
            'diff' => (string) exmtrans('dashboard.chart_target.diff', '{d}'),
            'color' => $this->color,
            'below' => ($this->below && $values !== null) ? $this->belowPoints($values, $rest) : [],
        ];
    }

    /**
     * @param mixed $label
     */
    private static function cleanLabel($label): string
    {
        return is_string($label) ? trim($label) : '';
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
