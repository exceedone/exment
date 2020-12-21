<?php
namespace Exceedone\Exment\Services\ViewFilter;

use Exceedone\Exment\Enums\FilterOption;

abstract class NullBase extends ViewFilterBase
{
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $query->{$method_name. ($this->isNull() ? 'Null' : 'NotNull')}($query_column);
    }
    
    protected function isNull() : bool{
        return true;
    }
}
