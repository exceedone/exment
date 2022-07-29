<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

use Exceedone\Exment\Model\System;

/**
 * replace value
 */
class SystemValue extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    public function replace($format, $options = [])
    {
        if (!isset($this->custom_value)) {
            return null;
        }
        //else, get system value
        else {
            $str = $this->custom_value->{$this->key};
            if (count($this->length_array) > 1) {
                $str = sprintf('%0'.$this->length_array[1].'d', $str);
            }

            return $str;
        }
    }
}
