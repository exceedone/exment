<?php
namespace Exceedone\Exment\Services\ReplaceFormat\Items;

use Carbon\Carbon;

/**
 * replace value
 */
class DateString extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    public function replace($format, $options = [])
    {
        $dateStrings = [
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
        ];
        return Carbon::now()->format($dateStrings[strtolower($this->key)]);
    }
}
