<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;

trait ColumnOptionQueryTrait
{
    /**
     * Get select option key
     *
     * @param [type] $key
     * @param boolean $append_table
     * @param [type] $table_id
     * @return void
     */
    protected static function getOptionKey($column_key, $append_table = true, $table_id = null, $options = [])
    {
        extract(array_merge(
            [
                'view_pivot_column' => null,
                'child_sum' => false,
            ],
            $options
        ));

        if (!$append_table) {
            return $column_key;
        }

        $query = ['table_id' => $table_id ?? null];

        // set as select_table key
        if(isset($view_pivot_column)){
            $query['view_pivot_column_id'] = CustomColumn::getEloquent($view_pivot_column)->id ?? null;
        }
        if(boolval($child_sum)){
            $query['child_sum'] = true;
        }

        return $column_key . '?' . implode('&', collect($query)->map(function($val, $key){
            return $key . '=' . $val;
        })->toArray());
    }
    
    protected static function setKeyValueOption(&$options, $key, $value, $table_view_name)
    {
        $options[$key] = static::getViewColumnLabel($value, $table_view_name);
    }

    protected static function getViewColumnLabel($value, $table_view_name){
        return isset($table_view_name) ? $table_view_name . ' : ' . $value : $value;
    }

    /**
     * Get params(custom_table, column_name etc) from query
     *
     * @return void
     */
    protected static function getOptionParams($query, $defaultCustomTable){
        $params = [];
        $params['column_column_target'] = explode("?", $query)[0];
        $defaultCustomTable = CustomTable::getEloquent($defaultCustomTable);

        if (preg_match('/\d+?.+$/i', $query) === 1) {
            $view_column_query = explode("?", $query)[1];
            parse_str($view_column_query, $view_column_query_array);

            $params['column_table_id'] = array_get($view_column_query_array, 'table_id', $defaultCustomTable->id ?? null);
            $params['view_pivot_column_id'] = array_get($view_column_query_array, 'view_pivot_column_id');
        }else{
            $params['column_table_id'] = $defaultCustomTable->id ?? null;
        }

        // check not match pivot table and custom table
        if(isset($params['view_pivot_column_id'])){
            $view_pivot_column = CustomColumn::getEloquent($params['view_pivot_column_id']);
            $view_pivot_table = CustomTable::getEloquent($view_pivot_column);
            if(isset($view_pivot_table) && $params['column_table_id'] != $view_pivot_table->id){
                $params['view_pivot_table_id'] = $view_pivot_table->id;
            }    
        }

        return $params;
    }
}
