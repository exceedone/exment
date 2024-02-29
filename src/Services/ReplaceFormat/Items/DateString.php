<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

use Carbon\Carbon;

/**
 * replace value
 */
class DateString extends ItemBase
{
    public const dateStrings = [
        'ymdhms' => 'YmdHis',
        'ymdhm' => 'YmdHi',
        'ymdh' => 'YmdH',
        'ymd' => 'Ymd',
        'ym' => 'Ym',
        'hms' => 'His',
        'hm' => 'Hi',

        'ymdhis' => 'YmdHis',
        'ymdhi' => 'YmdHi',
        'his' => 'His',
        'hi' => 'Hi',

        'yymdhms' => 'ymdHis',
        'yymdhm' => 'ymdHi',
        'yymdh' => 'ymdH',
        'yymd' => 'ymd',
        'yym' => 'ym',
        'yymdhis' => 'ymdHis',
        'yymdhi' => 'ymdHi',
    ];

    /**
     * Replace date
     */
    public function replace($format, $options = [])
    {
        return Carbon::now()->format(static::dateStrings[strtolower($this->key)]);
    }
}
