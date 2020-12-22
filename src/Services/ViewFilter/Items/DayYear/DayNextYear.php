<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\DayYear;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class DayNextYear extends DayYearBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_NEXT_YEAR;
    }

    protected function getTargetDay($query_value)
    {
        return new \Carbon\Carbon('first day of next year');
    }
}
