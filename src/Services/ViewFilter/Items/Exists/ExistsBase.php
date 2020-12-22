<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\Exists;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

abstract class ExistsBase extends ViewFilterBase
{
    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $isMultiple = $this->column_item->isMultipleEnabled();
        if ($isMultiple) {
            $method_name_suffix = $this->isExists() ? 'InArrayString' : 'NotInArrayString';
            $query->{$method_name.$method_name_suffix}($query_column, $query_value);
        }
        // if default
        else {
            $mark = $this->isExists() ? '=' : '<>';
            $query->{$method_name . 'OrIn'}($query_column, $mark, $query_value);
        }
    }

    abstract protected function isExists() : bool;
}
