<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\DayOn;

use Exceedone\Exment\Enums\FilterOption;

class DayToday extends DayOnBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_TODAY;
    }

    // @phpstan-ignore-next-line
    protected function getTargetDay($query_value)
    {
        return \Exceedone\Exment\Services\UserTimezone::today();
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
        return \Exment::getCarbonOnlyDay($value)->eq(\Exceedone\Exment\Services\UserTimezone::today());
    }
}
