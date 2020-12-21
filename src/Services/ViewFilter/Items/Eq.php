<?php
namespace Exceedone\Exment\Services\ViewFilter\Items;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class Eq extends ViewFilter\ViewFilterBase
{
    public static function getFilterOption(){
        return FilterOption::EQ;
    }
    

    
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $query->{$method_name}($query_column, $query_value);
    }
}
