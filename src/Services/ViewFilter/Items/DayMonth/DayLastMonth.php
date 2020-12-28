<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\DayMonth;

use Exceedone\Exment\Enums\FilterOption;

class DayLastMonth extends DayMonthBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_LAST_MONTH;
    }

    protected function getTargetDay($query_value)
    {
        return new \Carbon\Carbon('first day of last month');
    }
}
