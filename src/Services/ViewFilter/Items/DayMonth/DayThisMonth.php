<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\DayMonth;

use Exceedone\Exment\Enums\FilterOption;

class DayThisMonth extends DayMonthBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_THIS_MONTH;
    }

    protected function getTargetDay($query_value)
    {
        return new \Carbon\Carbon('first day of this month');
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
        list($target_day, $today) = $this->getTargetAndTodayFirstDay($value);
        return $target_day->format('Y-m') == $today->format('Y-m');
    }
}
