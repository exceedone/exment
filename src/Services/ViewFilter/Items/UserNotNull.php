<?php
namespace Exceedone\Exment\Services\ViewFilter\Items;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class UserNotNull extends ViewFilter\NotNullBase
{
    public static function getFilterOption(){
        return FilterOption::USER_NOT_NULL;
    }
}
