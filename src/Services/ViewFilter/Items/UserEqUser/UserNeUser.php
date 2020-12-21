<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\UserEqUser;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class UserNeUser extends ViewFilter\UserEqUserBase
{
    public static function getFilterOption()
    {
        return FilterOption::USER_NE_USER;
    }

    protected function getMark() : string
    {
        return '<>';
    }
}
