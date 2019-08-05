<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Form\Field as ExmentField;
use Encore\Admin\Form\Field;
use Encore\Admin\Grid\Filter;
use Illuminate\Support\Collection;

class SelectTable extends CustomItem
{
    protected $target_table;
    protected $target_view;
    
    public function __construct($custom_column, $custom_value)
    {
        parent::__construct($custom_column, $custom_value);

        $this->target_table = CustomTable::getEloquent(array_get($custom_column, 'options.select_target_table'));
        $this->target_view = CustomView::getEloquent(array_get($custom_column, 'options.select_target_view'));
    }

    public function getSelectTable()
    {
        return $this->target_table;
    }

    public function value()
    {
        return $this->getValue(false, false);
    }

    public function text()
    {
        return $this->getValue(true, false);
    }

    public function html()
    {
        return $this->getValue(true, true);
    }

    protected function getValue($text, $html)
    {
        if (!isset($this->target_table)) {
            return;
        }
        
        if (!is_array($this->value) && preg_match('/\[.+\]/i', $this->value)) {
            $this->value = json_decode($this->value);
        }

        $value = is_array($this->value) ? $this->value : [$this->value];
        $result = [];

        foreach ($value as $v) {
            if (!isset($v)) {
                continue;
            }
            
            $key = sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE, $this->target_table->table_name, $v);
            $model = System::requestSession($key, function () use ($v) {
                return getModelName($this->target_table)::find($v);
            });
            if (is_null($model)) {
                continue;
            }
            
            // if $model is array multiple, set as array
            if (!($model instanceof Collection)) {
                $model = [$model];
            }
    
            foreach ($model as $m) {
                if (is_null($m)) {
                    continue;
                }
                
                if ($text === false) {
                    $result[] = $m;
                // get text column
                } elseif ($html) {
                    $result[] = $m->getUrl(true);
                } else {
                    $result[] = $m->getLabel();
                }
            }
        }
        
        if ($text === false) {
            return $result;
        } else {
            return implode(exmtrans('common.separate_word'), $result);
        }
    }
    
    protected function getAdminFieldClass()
    {
        if (boolval(array_get($this->custom_column, 'options.multiple_enabled'))) {
            return Field\MultipleSelect::class;
        } else {
            return Field\Select::class;
        }
    }
    
    protected function getAdminFilterClass()
    {
        if (boolval($this->custom_column->getOption('multiple_enabled'))) {
            return Filter\Where::class;
        }
        return Filter\Equal::class;
    }

    protected function setAdminOptions(&$field, $form_column_options)
    {
        if (!isset($this->target_table)) {
            return;
        }
        if($field instanceof ExmentField\Display){
            return;
        }

        $relationColumn = collect($this->custom_column->custom_table
            ->getSelectTableRelationColumns())
            ->first(function ($relationColumn) {
                return array_get($relationColumn, 'child_column')->id == $this->custom_column->id;
            });

        $field->options(function ($value) use ($relationColumn) {
            if (isset($relationColumn)) {
                $parent_value = $this->custom_column->custom_table->getValueModel($this->id);
                $parent_v = array_get($parent_value, 'value.' . $relationColumn['parent_column']->column_name);
                $parent_target_table_id = $relationColumn['parent_column']->select_target_table->id;
                $parent_target_table_name = $relationColumn['parent_column']->select_target_table->table_name;

                //TODO:refactor
                if ($relationColumn['searchType'] == SearchType::ONE_TO_MANY) {
                    $callback = function (&$query) use ($parent_v, $parent_target_table_name) {
                        $query = $query->where("parent_id", $parent_v)->where('parent_type', $parent_target_table_name);
                        return $query;
                    };
                }
                // elseif ($relationColumn['searchType'] == SearchType::MANY_TO_MANY) {
                //     $callback = function (&$query) use ($parent_v, $parent_target_table_name, $relationColumn) {
                //         $relation = $relationColumn['relation'];
                //         $query->whereHas($relation->getRelationName(), function ($query) use($relation, $parent_v) {
                //             $query->where($relation->getRelationName() . '.parent_id', $parent_v);
                //         });
                //         return $query;
                //     };
                // }
                else {
                    $searchColumn = $relationColumn['child_column']->select_target_table->custom_columns()
                        ->where('column_type', ColumnType::SELECT_TABLE)
                        ->whereIn('options->select_target_table', [strval($parent_target_table_id), intval($parent_target_table_id)])
                        ->first();
                    if (isset($searchColumn)) {
                        $callback = function (&$query) use ($parent_v, $searchColumn) {
                            $query = $query->where("value->{$searchColumn->column_name}", $parent_v);
                            return $query;
                        };
                    }
                }
            }
            // get DB option value
            return $this->target_table->getSelectOptions([
                'custom_column' => $this->custom_column,
                'selected_value' => $value,
                'display_table' => $this->custom_column->custom_table,
                'filterCallback' => $callback ?? null,
                'target_view' => $this->target_view,
            ]);
        });
        $ajax = $this->target_table->getOptionAjaxUrl(['custom_column' => $this->custom_column]);
        if (isset($ajax)) {
            $field->attribute([
                'data-add-select2' => $this->label(),
                'data-add-select2-ajax' => $ajax
            ]);
        }
        // add table info
        $field->attribute(['data-target_table_name' => array_get($this->target_table, 'table_name')]);

        // add view info
        if(isset($this->target_view)){
            $field->attribute(['data-select2_expand' => json_encode([
                    'target_view_id' => array_get($this->target_view, 'id')
                ])
            ]);
        }
    }
    
    public function getAdminFilterWhereQuery($query, $input)
    {
        $index = $this->index();
        $query->whereRaw("FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(REPLACE(`$index`, '[', ''), ' ', ''), '[', ''), '\\\"', ''))", $input);
    }

    protected function setAdminFilterOptions(&$filter)
    {
        if (isset($this->target_table)) {
            $options = $this->target_table->getSelectOptions();
            $ajax = $this->target_table->getOptionAjaxUrl();
    
            if (isset($ajax)) {
                $filter->select([])->ajax($ajax);
            } else {
                $filter->select($options);
            }
        }
    }
    
    /**
     * replace value for import
     *
     * @param mixed $value
     * @param array $setting
     * @return void
     */
    public function getImportValue($value, $setting = [])
    {
        $result = true;
        $message = null;

        if (!isset($this->target_table)) {
            $result = false;
        }

        elseif (is_null($target_column_name = array_get($setting, 'target_column_name'))) {
            // if get as id and not numeric, set error
            if(!is_numeric($value)){
                $result = false;
                $message = trans('validation.integer', ['attribute' => $this->label()]);
            }
        }

        else{
            // get target value
            $target_value = $this->target_table->getValueModel()->where("value->$target_column_name", $value)->first();

            if (!isset($target_value)) {
                $result = false;
            }
            else{
                $value = $target_value->id;
            }
        }
        
        return [
            'result' => $result,
            'value' => $value,
            'message' => $message,
        ];
    }
}
