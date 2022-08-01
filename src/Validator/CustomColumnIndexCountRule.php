<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;

/**
 * CustomColumnIndexRule.
 * Max index is 20
 */
class CustomColumnIndexCountRule implements Rule
{
    protected $custom_table;
    protected $custom_column_id;

    public function __construct(...$parameters)
    {
        $this->custom_table = CustomTable::getEloquent($parameters[0]);
        $this->custom_column_id = $parameters[1];
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
        // if value is off, no validate
        if (!$value) {
            return true;
        }

        // get count index columns
        $count = CustomColumn::where('custom_table_id', $this->custom_table->id)
            ->where('id', '<>', $this->custom_column_id)
            ->whereIn('options->index_enabled', [1, "1"])
            ->count();

        if ($count >= config('exment.column_index_enabled_count', 20)) {
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
        return exmtrans('validation.max_table_index', [
            'count' => config('exment.column_index_enabled_count', 20),
        ]);
    }
}
