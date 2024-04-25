<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\TimeBeforeAfter;

use Exceedone\Exment\Enums\FilterOption;
use Carbon\Carbon;

class TimeOnOrBefore extends TimeBeforeAfterBase
{
    /**
     * @return int|string
     */
    public static function getFilterOption()
    {
        return FilterOption::TIME_ON_OR_BEFORE;
    }

    protected function getTargetDay($query_value)
    {
        return Carbon::parse($query_value)->format('H:i:s');
    }

    protected function getMark(): string
    {
        return "<=";
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
        // This method is not currently used
        return false;
        // $condition_dt = \Carbon\Carbon::parse($conditionValue);
        // return Carbon::parse($query_value)->lte($condition_dt);
    }
}
