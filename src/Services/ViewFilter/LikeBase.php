<?php

namespace Exceedone\Exment\Services\ViewFilter;

use Exceedone\Exment\ColumnItems\CommentItem;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\FilterSearchType;

abstract class LikeBase extends ViewFilterBase
{
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $filter_search_type = System::filter_search_type();
        if ($this->column_item instanceof CommentItem) {
            $filter_search_type = FilterSearchType::ALL;
        }
        $query_value = ($filter_search_type == FilterSearchType::ALL ? '%' : '') . $query_value . '%';
        $query->{$method_name}($query_column, $this->isLike() ? 'LIKE' : 'NOT LIKE', $query_value);
    }

    abstract protected function isLike(): bool;
}
