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
            return $this->custom_value->getParentValue(true);
        }

        $parentModel = $this->custom_value->getParentValue(false);
        return $parentModel->getValue($this->length_array[1], true, $this->matchOptions) ?? '';
    }
}
