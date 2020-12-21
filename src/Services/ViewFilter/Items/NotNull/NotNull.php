<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\NotNull;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class NotNull extends ViewFilter\NotNullBase
{
    public static function getFilterOption()
    {
        return FilterOption::NOT_NULL;
    }
}
