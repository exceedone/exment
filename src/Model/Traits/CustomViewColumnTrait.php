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
use Exceedone\Exment\ConditionItems\ConditionItemBase;
use Exceedone\Exment\ColumnItems;

/**
 * @method getOption($key, $default = null))
 * @method setOption($key, $val = null, $forgetIfNull = false)
 */
trait CustomViewColumnTrait
{
    use ColumnOptionQueryTrait;

    private $_custom_item;

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
    public function getCustomTableCacheAttribute()
    {
        return CustomTable::getEloquent($this->view_column_table_id);
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
        if (isset($this->_custom_item)) {
            return $this->_custom_item;
        }

        // if tagret is number, column type is column.
        if ($this->view_column_type == ConditionType::COLUMN) {
            $this->_custom_item = ColumnItems\CustomItem::getItem($this->custom_column, null, $this->view_column_target);
        }
        // workflow
        elseif ($this->view_column_type == ConditionType::WORKFLOW) {
            $this->_custom_item = ColumnItems\WorkflowItem::getItem(CustomTable::getEloquent($this->view_column_table_id), $this->view_column_target);
        }
        // parent_id
        elseif ($this->view_column_type == ConditionType::PARENT_ID) {
            $this->_custom_item = ColumnItems\ParentItem::getItem(CustomTable::getEloquent($this->view_column_table_id));
        }
        // system column
        else {
            $this->_custom_item = ColumnItems\SystemItem::getItem(CustomTable::getEloquent($this->view_column_table_id), $this->view_column_target);
        }

        if (!is_nullorempty($this->suuid)) {
            $this->_custom_item->options([
                'view_column_suuid' => $this->suuid,
            ]);
        }

        return $this->_custom_item;
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
    protected static function getColumnAndTableId($view_column_type, $column_name, ?CustomTable $custom_table = null)
    {
        if (!isset($view_column_type)) {
            $view_column_type = ConditionType::COLUMN;
        } else {
            $view_column_type = ConditionType::getEnumValue($view_column_type);
        }

        $item = ConditionItemBase::getItem($custom_table, $view_column_type, $column_name);
        return $item->getColumnAndTableId($column_name, $custom_table);
    }


    protected function getViewPivotIdTrait($key)
    {
        return $this->getOption($key);
    }
    protected function setViewPivotIdTrait($key, $view_pivot_id)
    {
        if (!isset($view_pivot_id)) {
            $this->setOption($key, null);
            return $this;
        }
        $this->setOption($key, $view_pivot_id);
        return $this;
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
        if ($column_keys == Define::CHARTITEM_LABEL) {
            return $column_keys;
        }
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
