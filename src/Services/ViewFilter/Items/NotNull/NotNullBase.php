<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\NotNull;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

abstract class NotNullBase extends ViewFilterBase
{
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        if ($this->column_item->isMultipleEnabled()) {
            $query->{$method_name}(function ($query) use ($query_column) {
                $query->whereNotNull($query_column);
                $query->where($query_column, '<>', '[]');
            });
        } else {
            $query->{$method_name. 'NotNull'}($query_column);
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
        return !is_nullorempty($value);
    }
}
