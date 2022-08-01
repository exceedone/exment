<?php

namespace Exceedone\Exment\Services\ReplaceFormat\Items;

/**
 * replace value
 */
class CustomTable extends ItemBase
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    public function replace($format, $options = [])
    {
        if (is_nullorempty($this->custom_value)) {
            return '';
        }
        $custom_table = $this->custom_value->custom_table;
        if (is_nullorempty($custom_table)) {
            return '';
        }
        if ($this->key == "table_view_name") {
            return $custom_table->table_view_name;
        } elseif ($this->key == "table_name") {
            return $custom_table->table_name;
        }
        return '';
    }
}
