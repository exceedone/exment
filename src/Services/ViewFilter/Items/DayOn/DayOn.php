<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\DayOn;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class DayOn extends ViewFilter\DayOnBase
{
    public static function getFilterOption(){
        return FilterOption::DAY_ON;
    }

    protected function getTargetDay($query_value)
    {
        return \Carbon\Carbon::parse($query_value);
    }
}
