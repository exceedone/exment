<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSort;

/**
 * CustomColumnUsingIndexRule.
 */
class CustomColumnUsingIndexRule implements Rule
{
    protected $custom_column_id;

    public function __construct(...$parameters)
    {
        $this->custom_column_id = $parameters[0];
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
        // if value is on, no validate
        if ($value) {
            return true;
        }

        // when new record, $custom_column_id is null
        if (!$this->custom_column_id) {
            return true;
        }

        // if saved is false, return false
        $custom_column = CustomColumn::getEloquent($this->custom_column_id);
        if (!isset($custom_column) || !$custom_column->index_enabled) {
            return true;
        }

        // get group key column count of summary view
        $count = CustomView::where('view_kind_type', 1)
            ->withoutGlobalScopes()
            ->whereHas('custom_view_columns', function ($query) {
                $query->withoutGlobalScopes()->where('view_column_type', 0)
                    ->where("view_column_target_id", $this->custom_column_id);
            })->count();

        if ($count > 0) {
            return false;
        }

        // get count index columns refered in view filters
        $count = CustomViewFilter::where('view_column_target_id', $this->custom_column_id)
            ->where('view_column_type', 0)
            ->count();

        if ($count > 0) {
            return false;
        }

        // get count index columns refered in view sorts
        $count = CustomViewSort::where('view_column_target_id', $this->custom_column_id)
            ->where('view_column_type', 0)
            ->count();

        if ($count > 0) {
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
        return exmtrans('validation.using_index_column');
    }
}
