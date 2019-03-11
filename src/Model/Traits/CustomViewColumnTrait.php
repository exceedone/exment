<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Enums\ViewColumnType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\ColumnItems;

trait CustomViewColumnTrait
{
    /**
     * get ViewColumnTarget.
     * * we have to convert string if view_column_type is system for custom view form-display*
     */
    public function getViewColumnTargetAttribute()
    {
        return $this->getViewColumnTargetWithTable();
    }

    public function getColumnItemAttribute()
    {
        // if tagret is number, column type is column.
        if ($this->view_column_type == ViewColumnType::COLUMN) {
            return $this->custom_column->column_item;
        }
        // parent_id
        elseif ($this->view_column_type == ViewColumnType::PARENT_ID) {
            return ColumnItems\ParentItem::getItem($this->custom_table);
        }
        // system column
        else {
            return ColumnItems\SystemItem::getItem($this->custom_table, $this->view_column_target);
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
    
    protected function getViewColumnTargetWithTable() {
        if (!isset($this->view_column_table_id)) {
            return null;
        }
        return $this->view_column_table_id . '-' . $this->getViewColumnTarget();
    }

    protected function getViewColumnTarget($column_type_key = 'view_column_type', $column_type_target_key = 'view_column_target_id')
    {
        if (!isset($this->{$column_type_key}) || 
            !isset($this->{$column_type_target_key})) {
            return null;
        }
        if ($this->{$column_type_key} == ViewColumnType::SYSTEM) {
            // get VIEW_COLUMN_SYSTEM_OPTIONS and get name.
            return SystemColumn::getOption(['id' => $this->{$column_type_target_key}])['name'] ?? null;
        } elseif ($this->{$column_type_key} == ViewColumnType::PARENT_ID) {
            return 'parent_id';
        } else {
            return $this->view_column_target_id;
        }
    }

    protected function setViewColumnTarget($view_column_target, $column_type_key = 'view_column_type', $column_type_target_key = 'view_column_target_id')
    {
        if (preg_match('/\d+-.+$/i', $view_column_target) === 1) {
            list($this->view_column_table_id, $view_column_target) = explode("-", $view_column_target);
        } else {
            $this->view_column_table_id = $this->custom_view->custom_table_id;
        }

        if (!is_numeric($view_column_target)) {
            if ($view_column_target === 'parent_id') {
                $this->{$column_type_key} = ViewColumnType::PARENT_ID;
                $this->{$column_type_target_key} = DEFINE::CUSTOM_COLUMN_TYPE_PARENT_ID;
            } else {
                $this->{$column_type_key} = ViewColumnType::SYSTEM;
                $this->{$column_type_target_key} = SystemColumn::getOption(['name' => $view_column_target])['id'] ?? null;
            }
        } else {
            $this->{$column_type_key} = ViewColumnType::COLUMN;
            $this->{$column_type_target_key} = $view_column_target;
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
    protected static function getColumnAndTableId($column_type, $column_name, $custom_table = null)
    {
        if (!isset($column_type)) {
            $column_type = ViewColumnType::COLUMN;
        }

        $target_column_id = null;
        $target_table_id = null;
        switch ($column_type) {
            // for table column
            case ViewColumnType::COLUMN:
                $target_column = CustomColumn::getEloquent($column_name, $custom_table);
                // get table and column id
                if(isset($target_column)){
                    $target_column_id = $target_column->id ?? null;
                    $target_table_id = $target_column->custom_table_id;
                }
                break;
            // system column
            default:
                // set parent id
                if ($column_name == ViewColumnType::PARENT_ID || $column_type == ViewColumnType::PARENT_ID) {
                    $target_column_id = Define::CUSTOM_COLUMN_TYPE_PARENT_ID;
                    // get parent table
                    if(isset($custom_table)){
                        $relation = CustomRelation::getRelationByChild($custom_table, RelationType::ONE_TO_MANY);

                        if(isset($relation)){
                            $target_table_id = $relation->parent_custom_table_id;
                        }
                    }
                }else{
                    $target_column_id = SystemColumn::getOption(['name' => $column_name])['id'];
                    // set parent table info
                    if(isset($custom_table)){
                        $target_table_id = $custom_table->id;
                    }
                }
                break;
        }
        return [$target_column_id, $target_table_id];
    }
}
