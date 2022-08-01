<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model\CustomColumn;

trait UniqueKeyCustomColumnTrait
{
    /**
     * get Table And Column Name
     */
    protected function getUniqueKeyValues($key)
    {
        if (is_array($key) && count($key) > 0) {
            $key = $key[0];
        }
        if (is_numeric($key)) {
            $custom_column = CustomColumn::getEloquent($key);
        } else {
            $custom_column = CustomColumn::getEloquent(array_get($this, $key));
        }

        if (!isset($custom_column)) {
            return [
                'table_name' => null,
                'column_name' => null,
            ];
        }

        return [
            'table_name' => $custom_column->custom_table->table_name,
            'column_name' => $custom_column->column_name,
        ];
    }

    protected static function importReplaceJsonCustomColumn(&$json, $replace_custom_column_key, $custom_column_key, $custom_table_key, $options = [])
    {
        $custom_column = CustomColumn::getEloquent(array_get($json, $custom_column_key), array_get($json, $custom_table_key));

        $result = false;
        if (isset($custom_column)) {
            array_set($json, $replace_custom_column_key, $custom_column->id);
            $result = true;
        }

        array_forget($json, $custom_column_key);
        array_forget($json, $custom_table_key);

        return $result;
    }
}
