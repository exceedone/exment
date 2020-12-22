<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\DayBeforeAfter;

use Exceedone\Exment\Enums\FilterOption;
use Carbon\Carbon;

class DayLastXDayOrBefore extends DayBeforeAfterBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_LAST_X_DAY_OR_BEFORE;
    }

    protected function getTargetDay($query_value)
    {
        $today =  Carbon::today();
        return $today->addDay(-1 * intval($query_value));
    }
    
    protected function getMark() : string
    {
        return "<=";
    }
}
