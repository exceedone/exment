<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\NotNull;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class DayNotNull extends ViewFilter\NotNullBase
{
    public static function getFilterOption(){
        return FilterOption::DAY_NOT_NULL;
    }
}
