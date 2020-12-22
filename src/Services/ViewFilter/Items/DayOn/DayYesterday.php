<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\DayOn;

use Exceedone\Exment\Enums\FilterOption;

class DayYesterday extends DayOnBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_YESTERDAY;
    }

    protected function getTargetDay($query_value)
    {
        return \Carbon\Carbon::yesterday();
    }
}
