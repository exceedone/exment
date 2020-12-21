<?php
namespace Exceedone\Exment\Services\ViewFilter\Items\Number;

use Exceedone\Exment\Services\ViewFilter;
use Exceedone\Exment\Enums\FilterOption;

class NumberGte extends ViewFilter\NumberCompareBase
{
    public static function getFilterOption()
    {
        return FilterOption::NUMBER_GTE;
    }

    protected function getMark() : string
    {
        return '>=';
    }
}
