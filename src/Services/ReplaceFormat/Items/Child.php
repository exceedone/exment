<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

/**
 * replace value
 */
class Child extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    public function replace($format, $options = [])
    {
        if (!isset($this->custom_value)) {
            return null;
        }

        // get sum value from children model
        elseif (count($this->length_array) <= 3) {
            return null;
        }
        //else, getting value using cihldren
        else {
            // get children values
            $children = $this->custom_value->getChildrenValues($this->length_array[1]) ?? [];
            // get length
            $index = intval($this->length_array[3]);
            // get value
            if (count($children) <= $index) {
                $str = '';
            } else {
                $str = $children[$index]->getValue($this->length_array[2], true, $this->matchOptions) ?? '';
            }

            return $str;
        }
    }
}
