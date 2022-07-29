<?php

namespace Exceedone\Exment\Services\ViewFilter\Items;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class Eq extends ViewFilter\ViewFilterBase
{
    public static function getFilterOption()
    {
        return FilterOption::EQ;
    }



    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $query->{$method_name}($query_column, $query_value);
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
        if (!$this->isNumeric()) {
            return isMatchString($value, $conditionValue);
        }
        return (float)$value == (float)$conditionValue;
    }
}
