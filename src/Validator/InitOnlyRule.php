<?php

namespace Exceedone\Exment\Validator;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;
use Illuminate\Contracts\Validation\Rule;

/**
 * InitOnlyRule.
 * Value changed check.
 * Now only for custom vlaue
 */
class InitOnlyRule implements Rule
{
    protected $custom_column;
    protected $custom_value;

    public function __construct(CustomColumn $custom_column, ?CustomValue $custom_value)
    {
        $this->custom_column = $custom_column;
        $this->custom_value = $custom_value;
    }

    /**
    * Check Validation
    *
    * @param  string  $attribute
    * @param  mixed  $value
    * @return bool
    */
    public function passes($attribute, $value)
    {
        if (is_null($this->custom_value) || !$this->custom_value->exists) {
            return true;
        }

        // if has custom_value, checking value
        $v = $this->getOriginalValue();

        if (is_json($value)) {
            $value = json_decode_ex($value);
        }

        if ($v != $value) {
            return false;
        }

        return true;
    }

    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return exmtrans('validation.init_only', [
            'original_value' => $this->getOriginalValue(),
        ]);
    }

    protected function getOriginalValue()
    {
        return array_get($this->custom_value->value, $this->custom_column->column_name);
    }
}
