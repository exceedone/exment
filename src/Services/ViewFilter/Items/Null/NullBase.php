<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\Null;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

abstract class NullBase extends ViewFilterBase
{
    /**
     * For condition value, if value is null or empty array, whether ignore the value.
     *
     * @var boolean
     */
    protected static $isConditionNullIgnore = false;

    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        // if multiple enabled, check is empty or null
        if ($this->column_item->isMultipleEnabled()) {
            $query->{$method_name}(function ($query) use ($query_column) {
                $query->orWhereNull($query_column);
                $query->orWhere($query_column, '[]');
            });
        } else {
            $query->{$method_name. 'Null'}($query_column);
        }
    }


    /**
     * compare 2 value
     *
     * @param mixed $value
     * @param mixed $conditionValue condition value. Sometimes, this value is not set(Ex. check value is not null)
     * @return boolean is match, return true
     */
    protected function _compareValue($value, $conditionValue): bool
    {
        return is_nullorempty($value);
    }
}
