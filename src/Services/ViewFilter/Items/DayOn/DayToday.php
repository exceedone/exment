<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\DayOn;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class DayToday extends DayOnBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_TODAY;
    }

    protected function getTargetDay($query_value)
    {
        return \Carbon\Carbon::today();
    }
}
