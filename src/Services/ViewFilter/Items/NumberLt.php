<?php
namespace Exceedone\Exment\Services\ViewFilter\Items;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class NumberLt extends ViewFilter\NumberCompareBase
{
    public static function getFilterOption()
    {
        return FilterOption::NUMBER_LT;
    }

    protected function getMark() : string
    {
        return '<';
    }
}
