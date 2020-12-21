<?php
namespace Exceedone\Exment\Services\ViewFilter\Items;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class NotNull extends ViewFilter\NotNullBase
{
    public static function getFilterOption(){
        return FilterOption::NOT_NULL;
    }
}
