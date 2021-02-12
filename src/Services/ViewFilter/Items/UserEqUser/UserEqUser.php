<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\UserEqUser;

use Exceedone\Exment\Enums\FilterOption;

class UserEqUser extends UserEqUserBase
{
    public static function getFilterOption()
    {
        return FilterOption::USER_EQ_USER;
    }

    protected function getMark() : string
    {
        return '=';
    }
    
    protected function isExists() : bool
    {
        return true;
    }
}
