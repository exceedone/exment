<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\Number;

use Exceedone\Exment\Enums\FilterOption;

class NumberGte extends NumberCompareBase
{
    /**
     * @return int|string
     */
    public static function getFilterOption()
    {
        return FilterOption::NUMBER_GTE;
    }

    protected function getMark(): string
    {
        return '>=';
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
        if (is_null($value) || is_null($conditionValue)) {
            return false;
        }
        return (float)$value >= (float)$conditionValue;
    }
}
