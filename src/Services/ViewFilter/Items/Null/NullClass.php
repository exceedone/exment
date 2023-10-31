<?php

namespace Exceedone\Exment\Services\ViewFilter\Items\Null;

use Exceedone\Exment\Enums\FilterOption;

class NullClass extends NullBase
{
    /**
     * @return int|string
     */
    public static function getFilterOption()
    {
        return FilterOption::NULL;
    }
}
