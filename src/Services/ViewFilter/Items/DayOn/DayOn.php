<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\DayOn;

use Exceedone\Exment\Enums\FilterOption;

class DayOn extends DayOnBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_ON;
    }

    protected function getTargetDay($query_value)
    {
        return \Carbon\Carbon::parse($query_value);
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
        $condition_dt = \Carbon\Carbon::parse($conditionValue);
        return \Carbon\Carbon::parse($value)->isSameDay($condition_dt);
    }
}
