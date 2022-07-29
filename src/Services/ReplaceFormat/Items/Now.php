<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

/**
 * replace value
 */
class Now extends ItemBase
{
    /**
     * Replace date
     */
    public function replace($format, $options = [])
    {
        $format = null;
        // if user input length
        if (count($this->length_array) > 1) {
            $format = $this->length_array[1];
        }
        // default format is YmdHis
        else {
            $format = 'YmdHis';
        }
        return \Carbon\Carbon::now()->format($format);
    }
}
