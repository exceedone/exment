<?php

namespace Exceedone\Exment\Validator;

use Closure;
use Exceedone\Exment\Services\Dashboard\ChartTarget;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A field of the dashboard box form's 目標線 (ChartTarget): checks the target fields together —
 * a name needs its value, so the rule reads the posted options and runs on an empty field too
 * (implicit). Nothing is checked while the chart is of a type the form hides them on (the save
 * drops them).
 */
class ChartTargetRule implements ValidationRule, DataAwareRule
{
    /** @var bool runs on an empty field (Laravel's implicit rule object) */
    public $implicit = true;

    /** @var string the option: ChartTarget::VALUE / LABEL / COLOR */
    protected $key;

    /** @var array<mixed> */
    protected $data = [];

    public function __construct(string $key)
    {
        $this->key = $key;
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
        $options = $this->data['options'] ?? null;
        if (!is_array($options)) {
            return;
        }
        $type = $options['chart_type'] ?? null;
        if (!ChartTarget::supportsType(is_string($type) ? $type : null)) {
            return;
        }
        $errors = ChartTarget::errors($options);
        if (isset($errors[$this->key])) {
            $fail($errors[$this->key]);
        }
    }
}
