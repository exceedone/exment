<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\DayBeforeAfter;

use Exceedone\Exment\Enums\FilterOption;
use Carbon\Carbon;

class DayTodayOrBefore extends DayBeforeAfterBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_TODAY_OR_BEFORE;
    }

    protected function getTargetDay($query_value)
    {
        return Carbon::today();
    }
    
    protected function getMark() : string
    {
        return "<=";
    }
}
