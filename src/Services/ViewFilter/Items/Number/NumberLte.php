<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\Number;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class NumberLte extends NumberCompareBase
{
    public static function getFilterOption()
    {
        return FilterOption::NUMBER_LTE;
    }

    protected function getMark() : string
    {
        return '<=';
    }
}
