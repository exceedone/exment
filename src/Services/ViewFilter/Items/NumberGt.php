<?php
namespace Exceedone\Exment\Services\ViewFilter\Items;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class NumberGt extends ViewFilter\NumberCompareBase
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
