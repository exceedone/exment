<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\Number;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

abstract class NumberCompareBase extends ViewFilterBase
{
    /**
     * If true, called setFilter function, append column name.
     * If append cast, please set false.
     *
     * @var boolean
     */
    protected static $isAppendDatabaseTable = false;

    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $query_column = $this->column_item->getCastWrapTableColumn($query_column);
        $query->{$method_name}(\DB::raw($query_column), $this->getMark(), $query_value);
    }

    abstract protected function getMark(): string;
}
