<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\Number;

use Exceedone\Exment\Enums\FilterOption;

class NumberGt extends NumberCompareBase
{
    public static function getFilterOption()
    {
        return FilterOption::NUMBER_GT;
    }

    protected function getMark() : string
    {
        return '>';
    }
}
