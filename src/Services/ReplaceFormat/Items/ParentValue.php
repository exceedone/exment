<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

use Exceedone\Exment\Model\CustomRelation;

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

        $relation = CustomRelation::with('parent_custom_table')->where('child_custom_table_id', $this->custom_value->custom_table->id)->first();

        $parentModel = $this->custom_value->getParentValue($relation);

        if (!is_list($parentModel)) {
            $parentModel = [$parentModel];
        }

        // replace length_string dotted comma
        $length_string = $this->length_array[1];
        $length_string = str_replace('.', ',', $length_string);

        return collect($parentModel)->map(function ($model) use ($length_string) {
            return $model->getValue($length_string, true, $this->matchOptions) ?? '';
        })->join(exmtrans('common.separate_word'));
    }
}
