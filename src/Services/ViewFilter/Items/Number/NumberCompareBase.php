<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\Number;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

abstract class NumberCompareBase extends ViewFilterBase
{
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        // if sql server, Append cast
        //if (\Exment::isSqlServer()) {
        $query_column = $this->column_item->getCastColumn($query_column);
        $query->{$method_name}(\DB::raw($query_column), $this->getMark(), $query_value);
        // } else {
        //     $query->{$method_name}($query_column, $this->getMark(), $query_value);
        // }
    }
    
    abstract protected function getMark() : string;
}
