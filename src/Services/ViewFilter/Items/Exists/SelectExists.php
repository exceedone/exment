<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\Exists;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class SelectExists extends ViewFilter\ExistsBase
{
    public static function getFilterOption()
    {
        return FilterOption::SELECT_EXISTS;
    }

    protected function isExists() : bool
    {
        return true;
    }
}
