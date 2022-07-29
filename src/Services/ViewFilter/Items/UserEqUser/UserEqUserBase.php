<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\UserEqUser;

use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

abstract class UserEqUserBase extends ViewFilterBase
{
    /**
     * If true, function "_compareValue" pass as array
     *
     * @var boolean
     */
    protected static $isConditionPassAsArray = true;

    protected function _setFilter($query, $method_name, $query_column, $query_value)
    {
        $user_id = \Exment::getUserId();
        if ($user_id) {
            $mark = $this->getMark();
            if ($this->column_item->isMultipleEnabled()) {
                $method_name_suffix = $mark == '=' ? 'InArrayString' : 'NotInArrayString';
                $query->{$method_name.$method_name_suffix}($query_column, $user_id);
            } else {
                $query->{$method_name}($query_column, $mark, $user_id);
            }
        } else {
            $query->{$method_name . 'NotMatch'}();
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
            if (isMatchString($v, \Exment::getUserId())) {
                return $this->isExists();
            }
        }
        return !$this->isExists();
    }


    abstract protected function getMark(): string;
    abstract protected function isExists(): bool;
}
