<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\Exists;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

abstract class ExistsBase extends ViewFilterBase
{
    /**
     * If true, function "_compareValue" pass as array
     *
     * @var boolean
     */
    protected static $isConditionPassAsArray = true;

    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $isMultiple = $this->column_item->isMultipleEnabled();
        $query_value = jsonToArray($query_value);

        $isUseUnicode = \ExmentDB::isUseUnicodeMultipleColumn();
        $query_value = collect($query_value)->map(function ($val) use ($isMultiple, $isUseUnicode) {
            return $isMultiple && $isUseUnicode ? unicode_encode($val) : $val;
        })->toArray();
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


    /**
     * compare 2 value
     *
     * @param mixed $value. *When this class, $value is array*
     * @param mixed $conditionValue condition value. Sometimes, this value is not set(Ex. check value is not null)
     * @return boolean is match, return true
     */
    protected function _compareValue($value, $conditionValue): bool
    {
        // if empty array, When isExists is true, return false. not isExists, return true.
        if (is_nullorempty($value)) {
            return !$this->isExists();
        }

        foreach ($value as $v) {
            if (isMatchString($v, $conditionValue)) {
                return $this->isExists();
            }
        }
        return !$this->isExists();
    }

    abstract protected function isExists(): bool;
}
