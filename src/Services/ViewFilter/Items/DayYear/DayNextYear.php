<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\DayYear;

use Exceedone\Exment\Enums\FilterOption;

class DayNextYear extends DayYearBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_NEXT_YEAR;
    }

    protected function getTargetDay($query_value)
    {
        return new \Carbon\Carbon('first day of next year');
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
        return \Carbon\Carbon::parse($value)->isNextYear();
    }
}
