<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

use Carbon\Carbon;

/**
 * replace value
 */
class DateValue extends ItemBase
{
    public const dateValues = [
        'year' => 'year',
        'month' => 'month',
        'day' => 'day',
        'hour' => 'hour',
        'minute' => 'minute',
        'second' => 'second',
        'y' => 'year',
        'm' => 'month',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    ];

    /**
     * Replace date
     */
    public function replace($format, $options = [])
    {
        $str = Carbon::now()->{static::dateValues[$this->key]};
        // if user input length
        if (count($this->length_array) > 1) {
            $length = $this->length_array[1];
        }
        // default 2
        else {
            $length = 1;
        }
        $str = sprintf('%0'.$length.'d', $str);

        return $str;
    }
}
