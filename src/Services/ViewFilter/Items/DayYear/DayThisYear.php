<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\DayYear;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class DayThisYear extends DayYearBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_THIS_YEAR;
    }

    protected function getTargetDay($query_value)
    {
        return new \Carbon\Carbon('first day of this year');
    }
}
