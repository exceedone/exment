<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\Exists;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class UserNe extends ViewFilter\ExistsBase
{
    public static function getFilterOption(){
        return FilterOption::USER_NE;
    }

    protected function isExists() : bool{
        return false;
    }
}
