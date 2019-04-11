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
        return $this->getViewColumnTarget();
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

    protected function getViewColumnTarget($column_table_id_key = 'view_column_table_id', $column_type_key = 'view_column_type', $column_type_target_key = 'view_column_target_id')
    {
        if (!isset($this->{$column_table_id_key}) ||
            !isset($this->{$column_type_key}) ||
            !isset($this->{$column_type_target_key})) {
            return null;
        }

        $column_table_id = $this->{$column_table_id_key};

        if ($this->{$column_type_key} == ViewColumnType::SYSTEM) {
            // get VIEW_COLUMN_SYSTEM_OPTIONS and get name.
            $column_type = SystemColumn::getOption(['id' => $this->{$column_type_target_key}])['name'] ?? null;
        } elseif ($this->{$column_type_key} == ViewColumnType::PARENT_ID) {
            $column_type = 'parent_id';
        } else {
            $column_type = $this->{$column_type_target_key};
        }

        return $column_table_id . '-' . $column_type;
    }

    protected function setViewColumnTarget($view_column_target, $column_table_name_key = 'custom_view', $column_table_id_key = 'view_column_table_id', $column_type_key = 'view_column_type', $column_type_target_key = 'view_column_target_id')
    {
        if (preg_match('/\d+-.+$/i', $view_column_target) === 1) {
            list($this->{$column_table_id_key}, $view_column_target) = explode("-", $view_column_target);
        } else {
            $this->{$column_table_id_key} = $this->{$column_table_name_key}->custom_table_id;
        }

        if (!is_numeric($view_column_target)) {
            if ($view_column_target === Define::CUSTOM_COLUMN_TYPE_PARENT_ID) {
                $this->{$column_type_key} = ViewColumnType::PARENT_ID;
                $this->{$column_type_target_key} = Define::CUSTOM_COLUMN_TYPE_PARENT_ID;
            } elseif (preg_match('/^\d+_\d+$/u', $view_column_target)) {
                $items = explode('_', $view_column_target);
                $this->{$column_type_key} = ViewColumnType::CHILD_SUM;
                $this->{$column_type_target_key} = $items[1];
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
    protected static function getColumnAndTableId($view_column_type, $column_name, $custom_table = null)
    {
        if (!isset($view_column_type)) {
            $view_column_type = ViewColumnType::COLUMN;
        }else{
            $view_column_type = ViewColumnType::getEnumValue($view_column_type);
        }

        $target_column_id = null;
        $target_table_id = null;
        switch ($view_column_type) {
            // for table column
            case ViewColumnType::COLUMN:
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
                if ($column_name == ViewColumnType::PARENT_ID || $view_column_type == ViewColumnType::PARENT_ID) {
                    $target_column_id = Define::CUSTOM_COLUMN_TYPE_PARENT_ID;
                    // get parent table
                    if (isset($custom_table)) {
                        $relation = CustomRelation::getRelationByChild($custom_table, RelationType::ONE_TO_MANY);

                        if (isset($relation)) {
                            $target_table_id = $relation->parent_custom_table_id;
                        }
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
    protected function getUniqueKeyValues()
    {
        $table_name = $this->custom_table->table_name;
        switch ($this->view_column_type) {
            case ViewColumnType::COLUMN:
                return [
                    'table_name' => $table_name,
                    'column_name' => $this->custom_column->column_name,
                ];
            case ViewColumnType::SYSTEM:
                return [
                    'table_name' => $table_name,
                    'column_name' => SystemColumn::getOption(['id' => $this->view_column_target_id])['name'],
                ];
            
            case ViewColumnType::PARENT_ID:
                return [
                    'table_name' => $table_name,
                    'column_name' => Define::CUSTOM_COLUMN_TYPE_PARENT_ID,
                ];
        }
        return [];
    }
    
    public static function importReplaceJson(&$json, $options = []){
        $custom_view = array_get($options, 'parent');

        list($view_column_target_id, $view_column_table_id) = static::getColumnAndTableId(
            array_get($json, "view_column_type"),
            array_get($json, "view_column_target_name"),
            $custom_view->custom_table
        );

        $json['view_column_target_id'] = $view_column_target_id;
        $json['view_column_table_id'] = $view_column_table_id;

        array_forget($json, 'view_column_target_name');
        array_forget($json, 'view_column_table_name');
    }

}
