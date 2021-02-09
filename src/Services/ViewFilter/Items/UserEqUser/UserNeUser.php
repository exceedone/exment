<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\UserEqUser;

use Exceedone\Exment\Enums\FilterOption;

class UserNeUser extends UserEqUserBase
{
    public static function getFilterOption()
    {
        return FilterOption::USER_NE_USER;
    }

    protected function getMark() : string
    {
        return '<>';
    }


    /**
     * compare 2 value
     *
     * @param mixed $value
     * @param mixed $conditionValue condition value. Sometimes, this value is not set(Ex. check value is not null)
     * @return boolean is match, return true
     */
    protected function _compareValue($value, $conditionValue) : bool{
        return !isMatchString($value, \Exment::getUserId());
    }
}
