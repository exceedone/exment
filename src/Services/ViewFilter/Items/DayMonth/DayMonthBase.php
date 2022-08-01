<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\DayMonth;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;
use Carbon\Carbon;

abstract class DayMonthBase extends ViewFilterBase
{
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $isDateTime = $this->column_item->isDateTime();
        $target_day = $this->getTargetDay($query_value);

        $query->{"{$method_name}YearMonthExment"}($query_column, $target_day, $isDateTime);
    }

    /**
     * Get target and now 1st day.
     * Ex. target day is 2021/01/31, and today is 2021/03/29, return [2021/03/21, 2020/01/01]
     *
     * @param Carbon|string $target_day
     * @return array
     */
    protected function getTargetAndTodayFirstDay($target_day)
    {
        $target_day = Carbon::parse($target_day)->firstOfMonth();
        $today = Carbon::today()->firstOfMonth();

        return [$target_day, $today];
    }

    abstract protected function getTargetDay($query_value);
}
