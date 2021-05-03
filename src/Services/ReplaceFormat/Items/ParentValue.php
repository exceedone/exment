<?php
namespace Exceedone\Exment\Services\ReplaceFormat\Items;

/**
 * replace value
 */
class ParentValue extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    public function replace($format, $options = [])
    {
        if (!isset($this->custom_value)) {
            return null;
        }
        
        // get value from model
        if (count($this->length_array) <= 1) {
            $parent_value = $this->custom_value->getParentValue();
            return $parent_value ? $parent_value->label : null;
        }

        $parentModel = $this->custom_value->getParentValue();
        
        // replace length_string dotted comma
        $length_string = $this->length_array[1];
        $length_string = str_replace('.', ',', $length_string);

        return $parentModel->getValue($length_string, true, $this->matchOptions) ?? '';
    }
}
