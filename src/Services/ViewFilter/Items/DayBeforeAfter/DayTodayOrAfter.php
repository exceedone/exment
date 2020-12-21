<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\DayBeforeAfter;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;
use Carbon\Carbon;

class DayTodayOrAfter extends ViewFilter\DayBeforeAfterBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_TODAY_OR_AFTER;
    }

    protected function getTargetDay($query_value)
    {
        return Carbon::today();
    }
    
    protected function getMark() : string
    {
        return ">=";
    }
}
