<?php

namespace Exceedone\Exment\Grid\Filter;

class BetweenDatetime extends BetweenDate
{
    // protected function convertValue($value)
    // {
    //     if (isset($value['end'])) {
    //         $end = \Carbon\Carbon::parse($value['end'])->addDays(1);
    //         $value['end'] = $end->format('Y-m-d');
    //     }

    //     return $value;
    // }
}
