<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\DayBeforeAfter;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;
use Carbon\Carbon;

class DayNextXDayOrAfter extends ViewFilter\DayBeforeAfterBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_NEXT_X_DAY_OR_AFTER;
    }

    protected function getTargetDay($query_value)
    {
        $today =  Carbon::today();
        return $today->addDay(intval($query_value));
    }
    
    protected function getMark() : string
    {
        return ">=";
    }
}
