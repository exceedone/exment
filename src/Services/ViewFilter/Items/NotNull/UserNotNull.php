<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\NotNull;

use Exceedone\Exment\Enums\FilterOption;

class UserNotNull extends NotNullBase
{
    public static function getFilterOption()
    {
        return FilterOption::USER_NOT_NULL;
    }
}
