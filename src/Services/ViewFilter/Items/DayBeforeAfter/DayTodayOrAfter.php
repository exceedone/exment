<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\DayBeforeAfter;

use Exceedone\Exment\Enums\FilterOption;
use Carbon\Carbon;

class DayTodayOrAfter extends DayBeforeAfterBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_TODAY_OR_AFTER;
    }

    protected function getTargetDay($query_value)
    {
        return Carbon::today();
    }

    protected function getMark(): string
    {
        return ">=";
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
        $today = \Carbon\Carbon::today();
        return \Exment::getCarbonOnlyDay($value)->gte($today);
    }
}
