<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;

/**
 * Select_valtext Rule for setting.
 * Check has val, and has comma.
 */
class SelectValTextSettingRule implements Rule
{
    protected $errors = [];
    public function __construct()
    {
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
        if (is_nullorempty($value)) {
            return true;
        }

        $values = explodeBreak($value);

        $errors = [];
        foreach ($values as $index => $value) {
            // If empty
            if (is_nullorempty($value)) {
                continue;
            }

            // not has comma
            $keyvalues = explode(",", $value);
            if (count($keyvalues) < 2) {
                $errors[] = exmtrans('custom_column.error.select_valtext_notkeyvalue', $index + 1);
                continue;
            }

            if (count($keyvalues) > 2) {
                $errors[] = exmtrans('custom_column.error.select_valtext_toocomma', $index + 1);
                continue;
            }

            // if $key is not value
            if (is_nullorempty($keyvalues[0])) {
                $errors[] = exmtrans('custom_column.error.select_valtext_notkey', $index + 1);
            }
        }

        if (count($errors) > 0) {
            $this->errors = $errors;
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
        return implode('', $this->errors);
    }
}
