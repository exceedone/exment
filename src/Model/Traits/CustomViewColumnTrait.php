<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewSummary;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\ColumnItems;

trait CustomViewColumnTrait
{
    use ColumnOptionQueryTrait;

    public function custom_view()
    {
        return $this->belongsTo(CustomView::class, 'custom_view_id');
    }
    
    public function getCustomColumnAttribute()
    {
        if ($this->view_column_type == ConditionType::COLUMN) {
            return CustomColumn::getEloquent($this->view_column_target_id);
        }
    }
    
    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'view_column_table_id');
    }

    /**
     * get ViewColumnTarget.
     * * we have to convert string if view_column_type is system for custom view form-display*
     */
    public function getViewColumnTargetAttribute()
    {
        return $this->getViewColumnTarget();
    }

    public function getColumnItemAttribute()
    {
        // if tagret is number, column type is column.
        if ($this->view_column_type == ConditionType::COLUMN) {
            return ColumnItems\CustomItem::getItem($this->custom_column, null, $this->view_column_target);
        }
        // workflow
        elseif ($this->view_column_type == ConditionType::WORKFLOW) {
            return ColumnItems\WorkflowItem::getItem(CustomTable::getEloquent($this->view_column_table_id), $this->view_column_target);
        }
        // parent_id
        elseif ($this->view_column_type == ConditionType::PARENT_ID) {
            return ColumnItems\ParentItem::getItem(CustomTable::getEloquent($this->view_column_table_id));
        }
        // system column
        else {
            return ColumnItems\SystemItem::getItem(CustomTable::getEloquent($this->view_column_table_id), $this->view_column_target);
        }
    }
    
    /**
     * set ViewColumnTarget.
     * * we have to convert int if view_column_type is system for custom view form-display*
     */
    public function setViewColumnTargetAttribute($view_column_target)
    {
        $this->setViewColumnTarget($view_column_target);
    }

    protected function getViewColumnTarget($column_table_id_key = 'view_column_table_id', $column_type_key = 'view_column_type', $column_type_target_key = 'view_column_target_id')
    {
        // get option key
        $optionKeyParams = [];

        $column_table_id = array_get($this, $column_table_id_key);
        $column_type = array_get($this, $column_type_key);
        $column_type_target = array_get($this, $column_type_target_key);

        if (!isset($column_type) ||
            !isset($column_type_target)) {
            return null;
        }

        if ($column_type == ConditionType::COLUMN) {
            $column_type = $column_type_target;
        } elseif ($column_type == ConditionType::PARENT_ID) {
            $column_type = 'parent_id';
        } else {
            // get VIEW_COLUMN_SYSTEM_OPTIONS and get name.
            $column_type = SystemColumn::getOption(['id' => $column_type_target])['name'] ?? null;
        }

        $optionKeyParams['view_pivot_column'] = $this->view_pivot_column_id ?? null;
        $optionKeyParams['view_pivot_table'] = $this->view_pivot_table_id ?? null;

        return static::getOptionKey($column_type, true, $column_table_id, $optionKeyParams);
    }

    protected function setViewColumnTarget($view_column_target, $column_table_name_key = 'custom_view', $column_table_id_key = 'view_column_table_id', $column_type_key = 'view_column_type', $column_type_target_key = 'view_column_target_id')
    {
        list($column_type, $column_table_id, $column_type_target, $view_pivot_column, $view_pivot_table) = $this->getViewColumnTargetItems($view_column_target, $column_table_name_key);

        $this->{$column_table_id_key} = $column_table_id;
        $this->{$column_type_key} = $column_type;
        $this->{$column_type_target_key} = $column_type_target;

        if (method_exists($this, 'setViewPivotColumnIdAttribute')) {
            $this->view_pivot_column_id = $view_pivot_column;
            $this->view_pivot_table_id = $view_pivot_table;
        }
    }
    
    /**
     * get column item using view_column_target
     */
    public static function getColumnItem($view_column_target)
    {
        $model = new self;
        $model->view_column_target = $view_column_target;
        return $model->column_item;
    }

    /**
     * get column target id and target table id.
     *
     * @return array first, target column id. second, target table id.
     */
    protected static function getColumnAndTableId($view_column_type, $column_name, $custom_table = null)
    {
        if (!isset($view_column_type)) {
            $view_column_type = ConditionType::COLUMN;
        } else {
            $view_column_type = ConditionType::getEnumValue($view_column_type);
        }

        $target_column_id = null;
        $target_table_id = null;
        switch ($view_column_type) {
            // for table column
            case ConditionType::COLUMN:
                $target_column = CustomColumn::getEloquent($column_name, $custom_table);
                // get table and column id
                if (isset($target_column)) {
                    $target_column_id = $target_column->id ?? null;
                    $target_table_id = $target_column->custom_table_id;
                }
                break;
            // system column
            default:
                // set parent id
                if ($column_name == ConditionType::PARENT_ID || $view_column_type == ConditionType::PARENT_ID) {
                    $target_column_id = Define::CUSTOM_COLUMN_TYPE_PARENT_ID;
                    // get parent table
                    if (isset($custom_table)) {
                        $target_table_id = $custom_table->id;
                    }
                } else {
                    $target_column_id = SystemColumn::getOption(['name' => $column_name])['id'];
                    // set parent table info
                    if (isset($custom_table)) {
                        $target_table_id = $custom_table->id;
                    }
                }
                break;
        }
        return [$target_column_id, $target_table_id];
    }

    /**
     * get Table And Column Name
     */
    public function getUniqueKeyValues()
    {
        if (isset($this->custom_table)) {
            $table_name = $this->custom_table->table_name;
        } else {
            $table_name = $this->custom_view->custom_table->table_name;
        }

        switch ($this->view_column_type) {
            case ConditionType::COLUMN:
                return [
                    'table_name' => $table_name,
                    'column_name' => $this->custom_column->column_name,
                    'column_type' => $this->view_column_type,
                ];
            case ConditionType::SYSTEM:
            case ConditionType::WORKFLOW:
                return [
                    'table_name' => $table_name,
                    'column_name' => SystemColumn::getOption(['id' => $this->view_column_target_id])['name'],
                    'column_type' => $this->view_column_type,
                ];
            
            case ConditionType::PARENT_ID:
                return [
                    'table_name' => $table_name,
                    'column_name' => Define::CUSTOM_COLUMN_TYPE_PARENT_ID,
                    'column_type' => $this->view_column_type,
                ];
        }
        return [];
    }
    
    /**
     * get custom view column or summary record.
     *
     * @param string $column_keys "view_kind_type" _ "view_column_id or view_summary_id"
     * @return CustomViewColumn|CustomViewSummary
     */
    public static function getSummaryViewColumn($column_keys)
    {
        if (preg_match('/\d+_\d+$/i', $column_keys) === 1) {
            $keys = explode('_', $column_keys);
            if (count($keys) === 2) {
                if ($keys[0] == ViewKindType::AGGREGATE) {
                    $view_column = CustomViewSummary::getEloquent($keys[1]);
                } else {
                    $view_column = CustomViewColumn::getEloquent($keys[1]);
                }
                return $view_column;
            }
        }
        return null;
    }

    
    public static function importReplaceJson(&$json, $options = [])
    {
        $custom_view = array_get($options, 'parent');

        // get custom table
        if (array_key_value_exists('view_column_table_name', $json)) {
            $custom_table = CustomTable::getEloquent($json['view_column_table_name']);
        }
        if (!isset($custom_table)) {
            $custom_table = $custom_view->custom_table;
        }

        ///// set view_column_table_name and view_column_target_name
        list($view_column_target_id, $view_column_table_id) = static::getColumnAndTableId(
            array_get($json, "view_column_type"),
            array_get($json, "view_column_target_name"),
            $custom_table
        );

        $json['view_column_target_id'] = $view_column_target_id;
        $json['view_column_table_id'] = $view_column_table_id;

        array_forget($json, 'view_column_target_name');
        array_forget($json, 'view_column_table_name');


        ///// set view_pivot_column_id and view_pivot_table_id
        if (array_key_value_exists("view_pivot_column_name", $json)) {
            list($view_pivot_column_id, $view_pivot_table_id) = static::getColumnAndTableId(
                array_get($json, "view_column_type"),
                array_get($json, "view_pivot_column_name"),
                array_get($json, "view_pivot_table_name")
            );
    
            $json['view_pivot_column_id'] = $view_pivot_column_id;
            $json['view_pivot_table_id'] = $view_pivot_table_id;
        }
        array_forget($json, 'view_pivot_column_name');
        array_forget($json, 'view_pivot_table_name');
    }
}
