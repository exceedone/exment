<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\DayOn;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

abstract class DayOnBase extends ViewFilterBase
{
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $isDateTime = $this->column_item->isDateTime();
        $target_day = $this->getTargetDay($query_value);

        $query->{"{$method_name}DateExment"}($query_column, $target_day, $isDateTime);
    }

    abstract protected function getTargetDay($query_value);
}
