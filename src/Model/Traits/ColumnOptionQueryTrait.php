<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\ConditionTypeDetail;

trait ColumnOptionQueryTrait
{
    /**
     * Get select option key (as query string)
     *
     * @param string $column_key
     * @param boolean $append_table if true, append table info to qeury
     * @param string $table_id target table id
     * @param array $options
     * @return string
     */
    protected static function getOptionKey($column_key, $append_table = true, $table_id = null, $options = [])
    {
        return \Exment::getOptionKey($column_key, $append_table, $table_id, $options);
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
        $params['column_target'] = explode_ex("?", $query)[0];
        $defaultCustomTable = CustomTable::getEloquent($defaultCustomTable);

        if (preg_match_ex('/.+\?.+$/i', $query) === 1) {
            $view_column_query = explode_ex("?", $query)[1];
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
        $column_type_target = explode_ex("?", $view_column_target)[0];

        if (!is_nullorempty($column_table_name_key) && isset($this->{$column_table_name_key})) {
            $custom_table_id = $this->{$column_table_name_key}->custom_table_id;
        } else {
            $custom_table_id = null;
        }

        $params = static::getOptionParams($view_column_target, $custom_table_id);

        $view_pivot_column_id = array_get($params, 'view_pivot_column_id');
        $view_pivot_table_id = array_get($params, 'view_pivot_table_id');
        $column_table_id = array_get($params, 'column_table_id');

        if (!is_numeric($column_type_target)) {
            /** @phpstan-ignore-next-line Strict comparison using === between mixed and 0 will always evaluate to false. */
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

    /**
     * Get column's options (only parameter target column)
     *
     * @param string $table_name
     * @param array $options
     * @return array
     */
    protected function getColumnSelectOption(string $table_name, array $options): array
    {
        // create from options
        return collect($options)->mapWithKeys(function ($option) use ($table_name) {
            $column = CustomColumn::getEloquent($option, $table_name);
            return ["{$column->id}?table_id={$column->custom_table_id}" => $option];
        })->toArray();
    }

    /**
     * Get column's options
     *
     * @param string $table_name
     * @param array $options
     * @return array
     */
    protected function getColumnSelectOptions(string $table_name, array $options = []): array
    {
        $options = array_merge([
            'is_index' => false,
            'append_tableid' => true,
            'add_options' => [],
            'ignore_attachment' => false,
        ], $options);
        $is_index = $options['is_index'];
        $add_options = $options['add_options'];

        $custom_table = CustomTable::getEloquent($table_name);
        // create from custom column
        $array = $custom_table->custom_columns->filter(function ($custom_column) use ($is_index, $options) {
            if (boolval($is_index)) {
                return $custom_column->index_enabled;
            }

            if (boolval($options['ignore_attachment']) && ColumnType::isAttachment($custom_column->column_type)) {
                return false;
            }

            return true;
        })->mapWithKeys(function ($custom_column) use ($options) {
            $key = "{$custom_column->id}" . (boolval($options['append_tableid']) ? "?table_id={$custom_column->custom_table_id}" : '');
            return [strval($key) => $custom_column->column_view_name];
        })->toArray();

        return $add_options + $array;
    }
}
