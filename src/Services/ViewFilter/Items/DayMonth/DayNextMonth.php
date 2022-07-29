<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\DayMonth;

use Exceedone\Exment\Enums\FilterOption;

class DayNextMonth extends DayMonthBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_NEXT_MONTH;
    }

    protected function getTargetDay($query_value)
    {
        return new \Carbon\Carbon('first day of next month');
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
        $today = $today->addMonth(1);
        return $target_day->format('Y-m') == $today->format('Y-m');
    }
}
