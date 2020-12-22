<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\Null;

use Exceedone\Exment\Enums\FilterOption;

class DayNull extends NullBase
{
    public static function getFilterOption()
    {
        return FilterOption::DAY_NULL;
    }
}
