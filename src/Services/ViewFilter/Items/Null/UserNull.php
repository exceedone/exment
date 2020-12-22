<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\Null;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class UserNull extends NullBase
{
    public static function getFilterOption()
    {
        return FilterOption::USER_NULL;
    }
}
