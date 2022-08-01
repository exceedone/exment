<?php

namespace Exceedone\Exment\Services\ViewFilter;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\FilterSearchType;

abstract class LikeBase extends ViewFilterBase
{
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $query_value = (System::filter_search_type() == FilterSearchType::ALL ? '%' : '') . $query_value . '%';
        $query->{$method_name}($query_column, $this->isLike() ? 'LIKE' : 'NOT LIKE', $query_value);
    }

    abstract protected function isLike(): bool;
}
