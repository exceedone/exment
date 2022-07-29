<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

/**
 * replace value
 */
class SelectTableValue extends ItemBase
{
    /**
     * Replace value from format. ex. ${select_table.customer.customer_name} to user_name's value
     */
    public function replace($format, $options = [])
    {
        $target_value = $this->custom_value;
        if (!isset($target_value)) {
            return '';
        }

        // get dotted string from index 1.
        $this->length_array = array_slice($this->length_array, 1);

        // replace length_string dotted comma
        $length_string = implode('.', $this->length_array);
        $length_string = str_replace('.', ',', $length_string);

        $str = $target_value->getValue($length_string, true, $this->matchOptions) ?? '';

        return $str;
    }
}
