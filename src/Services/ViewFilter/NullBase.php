<?php
namespace Exceedone\Exment\Services\ViewFilter;

abstract class NullBase extends ViewFilterBase
{
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        // if multiple enabled, check is empty or null
        if ($this->column_item->isMultipleEnabled()) {
            $query->{$method_name}(function ($query) use ($query_column) {
                $query->orWhereNull($query_column);
                $query->orWhere($query_column, '[]');
            });
        } else {
            $query->{$method_name. 'Null'}($query_column);
        }
    }
}
