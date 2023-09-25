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
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @method getOption($key, $default = null))
 * @method setOption($key, $val = null, $forgetIfNull = false)
 * @method static mixed findBySuuid($key)
 * @property string $view_pivot_column_id
 * @property string $view_pivot_table_id
 * @property string $view_column_type
 */
trait CustomViewColumnTrait
{
    use ColumnOptionQueryTrait;

    private $_custom_item;

    public function custom_view(): BelongsTo
    {
        return $this->belongsTo(CustomView::class, 'custom_view_id');
    }

    public function getCustomColumnAttribute()
    {
        if ($this->view_column_type == ConditionType::COLUMN) {
            return CustomColumn::getEloquent($this->view_column_target_id);
        }
    }

    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'view_column_table_id');
    }
    public function getCustomTableCacheAttribute()
    {
        return CustomTable::getEloquent($this->view_column_table_id);
    }
    public function getCustomViewCacheAttribute()
    {
        return CustomView::getEloquent($this->custom_view_id);
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
            $this->_custom_item->setUniqueName(Define::COLUMN_ITEM_UNIQUE_PREFIX . $this->suuid);
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
    public static function getColumnItem($view_column_target, ?CustomTable $custom_table = null)
    {
        $model = new self();
        $model->view_column_target = $view_column_target;

        // if not view_column_table_id, set custom table
        if (is_nullorempty(array_get($model, 'view_column_table_id')) && $custom_table) {
            $model->view_column_table_id = $custom_table->id;
        }

        $column_item = $model->column_item;
        // set custom table(if workflow item is not set custom table)
        if (!$column_item->getCustomTable() && isset($custom_table)) {
            $column_item->setCustomTable($custom_table);
        }
        return $column_item;
    }


    /**
     * Get column target id and target table id.
     *
     * @param string|null $view_column_type
     * @param string|null $column_name
     * @param string|CustomTable|null $custom_table
     * @return array offset 0 : column id, 1 : table id
     */
    protected static function getColumnAndTableId($view_column_type, $column_name, $custom_table = null): array
    {
        if (!isset($view_column_type)) {
            $view_column_type = ConditionType::COLUMN;
        } else {
            $view_column_type = ConditionType::getEnumValue($view_column_type);
        }

        $custom_table = CustomTable::getEloquent($custom_table);

        if (is_null($column_name) || is_null($view_column_type)) {
            return [null, $custom_table ? $custom_table->id : null];
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
     * @return mixed|null
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

    public static function findByCkey($ckey)
    {
        return static::findBySuuid(str_replace(Define::COLUMN_ITEM_UNIQUE_PREFIX, '', $ckey));
    }

    /**
     * get Table And Column Name
     */
    public function getPivotUniqueKeyValues()
    {
        if (!isset($this->view_pivot_column_id)) {
            return [
                'table_name' => null,
                'column_name' => null,
            ];
        }

        $table_name = CustomTable::getEloquent($this->view_pivot_table_id)->table_name;
        switch ($this->view_column_type) {
            case ConditionType::COLUMN:
                return [
                    'table_name' => $table_name,
                    'column_name' => CustomColumn::getEloquent($this->view_pivot_column_id)->column_name ?? null,
                ];
            case ConditionType::SYSTEM:
                return [
                    'table_name' => $table_name,
                    'column_name' => SystemColumn::getOption(['id' => $this->view_pivot_column_id])['name'] ?? null,
                ];
        }
        return [];
    }
}
