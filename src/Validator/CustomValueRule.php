<?php

namespace Exceedone\Exment\Validator;

use Illuminate\Contracts\Validation\Rule;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;

/**
 * CustomValueRule.
 * Check contains target table
 */
class CustomValueRule implements Rule
{
    protected $custom_table;

    /**
     * Filtering view if needs
     *
     * @var int|string|CustomView|null
     */
    protected $custom_view;

    public function __construct($custom_table, $custom_view = null)
    {
        $this->custom_table = CustomTable::getEloquent($custom_table);
        $this->custom_view = CustomView::getEloquent($custom_view);
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
        if (is_null($value)) {
            return true;
        }
        if (!isset($this->custom_table)) {
            return true;
        }

        $value = array_unique(array_filter(stringToArray($value)));

        // check custom table's data
        if (!$this->hasData($value)) {
            return false;
        }

        if (!$this->hasCustomViewFilter($value)) {
            return false;
        }

        return true;
    }

    /**
     * HasData
     *
     * @return boolean
     */
    protected function hasData(array $values): bool
    {
        foreach ($values as $v) {
            if (!is_numeric($v)) {
                return false;
            }
            // get target table's value (use request session)
            $model = $this->custom_table->getValueModel($v);
            if (!isset($model)) {
                return false;
            }
        }

        return true;
    }


    /**
     * Filter custom view
     *
     * @return boolean
     */
    protected function hasCustomViewFilter(array $values): bool
    {
        if (is_nullorempty($this->custom_view)) {
            return true;
        }

        // filter query
        $query = $this->custom_table->getValueQuery();
        $this->custom_view->filterModel($query); // Not sort.

        $query->whereIn(getDBTableName($this->custom_table) . '.id', $values);

        // check data counts;
        $count = $query->count();

        return $count == count($values);
    }


    /**
     * get validation error message
     *
     * @return string
     */
    public function message()
    {
        return exmtrans('validation.not_has_custom_value', [
            'table_view_name' => $this->custom_table->table_view_name,
            'value' => null,
        ]);
    }
}
