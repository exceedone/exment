<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\Exists;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class UserEq extends ViewFilter\ExistsBase
{
    public static function getFilterOption(){
        return FilterOption::USER_EQ;
    }

    protected function isExists() : bool{
        return true;
    }
}
