<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ConditionTypeDetail;

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
        $options = array_merge(
            [
                'view_pivot_column' => null,
                'view_pivot_table' => null,
                'codition_type' => null,
            ],
            $options
        );

        $view_pivot_column = $options['view_pivot_column'];
        $view_pivot_table = $options['view_pivot_table'];
        $codition_type = $options['codition_type'];
        
        $query = [];
        
        if ($append_table && isset($table_id)) {
            $query['table_id'] = $table_id;
        }

        // set as select_table key
        if (isset($view_pivot_column)) {
            if ($view_pivot_column == SystemColumn::PARENT_ID) {
                $query['view_pivot_column_id'] = SystemColumn::PARENT_ID;
            } else {
                $query['view_pivot_column_id'] = CustomColumn::getEloquent($view_pivot_column)->id ?? null;
            }

            $query['view_pivot_table_id'] = CustomTable::getEloquent($view_pivot_table)->id ?? null;
        }

        if (isset($codition_type)) {
            if ($view_pivot_column == SystemColumn::PARENT_ID) {
                $query['view_pivot_column_id'] = SystemColumn::PARENT_ID;
            } else {
                $query['view_pivot_column_id'] = CustomColumn::getEloquent($view_pivot_column)->id ?? null;
            }

            $query['view_pivot_table_id'] = CustomTable::getEloquent($view_pivot_table)->id ?? null;
        }

        if (count($query) == 0) {
            return $column_key;
        }

        return $column_key . '?' . implode('&', collect($query)->map(function ($val, $key) {
            return $key . '=' . $val;
        })->toArray());
    }
    
    protected static function setKeyValueOption(&$options, $key, $value, $table_view_name)
    {
        $options[$key] = static::getViewColumnLabel($value, $table_view_name);
    }

    protected static function getViewColumnLabel($value, $table_view_name)
    {
        return isset($table_view_name) ? $table_view_name . ' : ' . $value : $value;
    }

    /**
     * Get params(custom_table, column_name etc) from query
     *
     * @return array
     */
    protected static function getOptionParams($query, $defaultCustomTable)
    {
        $params = [];
        $params['column_target'] = explode("?", $query)[0];
        $defaultCustomTable = CustomTable::getEloquent($defaultCustomTable);

        if (preg_match('/.+\?.+$/i', $query) === 1) {
            $view_column_query = explode("?", $query)[1];
            parse_str($view_column_query, $view_column_query_array);

            $params['column_table_id'] = array_get($view_column_query_array, 'table_id', $defaultCustomTable->id ?? null);
            $params['view_pivot_column_id'] = array_get($view_column_query_array, 'view_pivot_column_id');
            $params['view_pivot_table_id'] = array_get($view_column_query_array, 'view_pivot_table_id');
        } else {
            $params['column_table_id'] = $defaultCustomTable->id ?? null;
        }

        return $params;
    }
    
    /**
     * Get ViewColumnTargetItems using $view_column_target.
     * it contains $column_type, $column_table_id, $column_type_target
     *
     * @param mixed $view_column_target
     * @param string $column_table_name_key
     * @return array [$column_type, $column_table_id, $column_type_target]
     */
    protected function getViewColumnTargetItems($view_column_target, $column_table_name_key = 'custom_view')
    {
        $column_type_target = explode("?", $view_column_target)[0];
        
        if (isset($column_table_name_key) && isset($this->{$column_table_name_key})) {
            $custom_table_id = $this->{$column_table_name_key}->custom_table_id;
        } else {
            $custom_table_id = null;
        }

        $params = static::getOptionParams($view_column_target, $custom_table_id);

        $view_pivot_column_id = array_get($params, 'view_pivot_column_id');
        $view_pivot_table_id = array_get($params, 'view_pivot_table_id');
        $column_table_id = array_get($params, 'column_table_id');

        if (!is_numeric($column_type_target)) {
            if ($column_type_target === Define::CUSTOM_COLUMN_TYPE_PARENT_ID || $column_type_target === SystemColumn::PARENT_ID) {
                $column_type = ConditionType::PARENT_ID;
                $column_type_target = Define::CUSTOM_COLUMN_TYPE_PARENT_ID;
            } elseif (ConditionTypeDetail::isValidKey($column_type_target)) {
                $column_type = ConditionType::CONDITION;
                $column_type_target = ConditionTypeDetail::getEnum(strtolower($column_type_target))->getValue();
            } elseif (SystemColumn::isWorkflow($column_type_target)) {
                $column_type = ConditionType::WORKFLOW;
                $column_type_target = SystemColumn::getOption(['name' => $column_type_target])['id'] ?? null;
            } else {
                $column_type = ConditionType::SYSTEM;
                $column_type_target = SystemColumn::getOption(['name' => $column_type_target])['id'] ?? null;
            }
        } else {
            $column_type = ConditionType::COLUMN;
        }

        return [$column_type, $column_table_id, $column_type_target, $view_pivot_column_id, $view_pivot_table_id];
    }
}
