<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\Number;

use Exceedone\Exment\Enums\FilterOption;

class NumberLt extends NumberCompareBase
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
