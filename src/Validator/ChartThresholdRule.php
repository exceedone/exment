<?php

namespace Exceedone\Exment\Validator;

use Closure;
use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Services\Dashboard\ChartThresholds;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Arr;

/**
 * A cell of the dashboard box form's 色分けのしきい値 table (ChartThresholds): checks the row
 * it is in, as the rules are stored — a range's upper end needs its lower one, so the rule
 * reads the row, and runs on an empty cell too (implicit). No row is checked while the chart
 * is no heatmap (the form hides the table, the save drops it); a row removed on the form is
 * not validated at all (Exment's HasMany leaves it out).
 */
class ChartThresholdRule implements ValidationRule, DataAwareRule
{
    /** @var bool runs on an empty cell (Laravel's implicit rule object) */
    public $implicit = true;

    /** @var string the cell: color / condition / value / value2 */
    protected $cell;

    /** @var array<mixed> */
    protected $data = [];

    public function __construct(string $cell)
    {
        $this->cell = $cell;
    }

    /**
     * @param array<mixed> $data
     * @return $this
     */
    public function setData(array $data)
    {
        $this->data = $data;
        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (request()->input('options.chart_type') !== ChartType::HEATMAP) {
            return;
        }
        // "chart_thresholds.{row key}.{cell}"
        $path = substr($attribute, 0, (int) strrpos($attribute, '.'));
        $row = Arr::get($this->data, $path);
        if (!is_array($row)) {
            return;
        }

        $errors = ChartThresholds::errors($row);
        if (isset($errors[$this->cell])) {
            $fail($errors[$this->cell]);
            return;
        }

        // the rows past the most a box takes: told on the first cell of each
        if ($this->cell === 'color' && $this->position($path) >= ChartThresholds::MAX) {
            $fail(exmtrans('dashboard.chart_threshold.message.max_rules', ChartThresholds::MAX));
        }
    }

    /**
     * How many rows come before the row at $path.
     */
    protected function position(string $path): int
    {
        $key = substr($path, (int) strrpos($path, '.') + 1);
        $position = 0;
        foreach ((array) Arr::get($this->data, ChartThresholds::OPTION, []) as $k => $row) {
            if ((string) $k === $key) {
                break;
            }
            $position++;
        }
        return $position;
    }
}
