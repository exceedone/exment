<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\DayBeforeAfter;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;
use Carbon\Carbon;

class DayOnOrAfter extends DayBeforeAfterBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_ON_OR_AFTER;
    }

    protected function getTargetDay($query_value)
    {
        return Carbon::parse($query_value);
    }
    
    protected function getMark() : string
    {
        return ">=";
    }
}
