<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\NotNull;

use Exceedone\Exment\Enums\FilterOption;

class DayNotNull extends NotNullBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_NOT_NULL;
    }
}
