<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

use Exceedone\Exment\Enums\SystemTableName;

/**
 * replace value
 */
class Value extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    public function replace($format, $options = [])
    {
        if ($this->key == "value") {
            $target_value = $this->custom_value;
        } else {
            $target_value = getModelName(SystemTableName::BASEINFO)::first();
        }
        if (!isset($target_value)) {
            $str = '';
        }
        // get value from model
        elseif (count($this->length_array) <= 1) {
            $str = $target_value->getLabel();
        } else {
            // get comma string from index 1.
            $this->length_array = array_slice($this->length_array, 1);

            $str = $target_value->getValue(implode(',', $this->length_array), true, $this->matchOptions) ?? '';
        }

        return $str;
    }
}
