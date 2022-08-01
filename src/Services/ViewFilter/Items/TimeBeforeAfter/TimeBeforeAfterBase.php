<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\TimeBeforeAfter;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

abstract class TimeBeforeAfterBase extends ViewFilterBase
{
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $target_day = $this->getTargetDay($query_value);
        $mark = $this->getMark();

        $query->{$method_name}($query_column, $mark, $target_day);
    }

    abstract protected function getTargetDay($query_value);

    abstract protected function getMark(): string;
}
