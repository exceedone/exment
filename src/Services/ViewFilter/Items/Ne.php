<?php
namespace Exceedone\Exment\Services\ViewFilter\Items;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class Ne extends ViewFilter\ViewFilterBase
{
    public static function getFilterOption()
    {
        return FilterOption::NE;
    }
    

    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $query->{$method_name}($query_column, '<>', $query_value);
    }
}
