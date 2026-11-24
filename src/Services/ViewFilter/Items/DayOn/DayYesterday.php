<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\DayOn;

use Exceedone\Exment\Enums\FilterOption;

class DayYesterday extends DayOnBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_YESTERDAY;
    }

    // @phpstan-ignore-next-line
    protected function getTargetDay($query_value)
    {
        return \Exceedone\Exment\Services\UserTimezone::today()->subDay();
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
        return \Exceedone\Exment\Facades\ExmentFacade::getCarbonOnlyDay($value)->eq(\Exceedone\Exment\Services\UserTimezone::today()->subDay());
    }
}
