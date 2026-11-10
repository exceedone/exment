<?php

namespace Exceedone\Exment\Validator;

use Closure;
use Exceedone\Exment\Services\Dashboard\ChartSecondValue;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A field of the dashboard box form's 2つ目の値 (ChartSecondValue): a summary column of the
 * posted view other than the 値, required on the 複合 type — so the rule reads the posted options
 * and runs on an empty field too (implicit). Nothing is checked on a type the form hides it on.
 */
class ChartSecondValueRule implements ValidationRule, DataAwareRule
{
    /** @var bool runs on an empty field (Laravel's implicit rule object) */
    public $implicit = true;

    /** @var string the option: ChartSecondValue::COLUMN / TYPE */
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
        $errors = ChartSecondValue::errors($options);
        if (isset($errors[$this->key])) {
            $fail($errors[$this->key]);
        }
    }
}
