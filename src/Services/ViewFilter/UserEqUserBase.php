<?php
namespace Exceedone\Exment\Services\ViewFilter;

use Exceedone\Exment\Enums\FilterOption;

abstract class UserEqUserBase extends ViewFilterBase
{
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $user_id = \Exment::getUserId();
        if ($user_id) {
            $mark = $this->getMark();
            $query->{$method_name}($query_column, $mark, $user_id);
        } else {
            $query->{$method_name . 'Raw'}('1 = 0');
        }
    }
    
    abstract protected function getMark() : string;
}
