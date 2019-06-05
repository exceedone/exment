<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\ColumnType;
use Encore\Admin\Form\Field;
use Encore\Admin\Grid\Filter;
use Illuminate\Support\Collection;

class SelectTable extends CustomItem
{
    protected $target_table;
    
    public function __construct($custom_column, $custom_value)
    {
        parent::__construct($custom_column, $custom_value);

        $this->target_table = CustomTable::getEloquent(array_get($custom_column, 'options.select_target_table'));
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
        $model = getModelName($this->target_table)::find($this->value);
        if (is_null($model)) {
            return null;
        }
        if ($text === false) {
            return $model;
        }
        
        // if $model is array multiple, set as array
        if (!($model instanceof Collection)) {
            $model = [$model];
        }

        $texts = [];
        foreach ($model as $m) {
            if (is_null($m)) {
                continue;
            }
            
            // get text column
            if ($html) {
                $texts[] = $m->getUrl(true);
            } else {
                $texts[] = $m->getLabel();
            }
        }
        return implode(exmtrans('common.separate_word'), $texts);
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
                } else {
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
            return $this->target_table->getOptions($value, $this->custom_column->custom_table, null, null, $callback ?? null);
        });
        $ajax = $this->target_table->getOptionAjaxUrl();
        if (isset($ajax)) {
            $field->attribute([
                'data-add-select2' => $this->label(),
                'data-add-select2-ajax' => $ajax
            ]);
        }
        // add table info
        $field->attribute(['data-target_table_name' => array_get($this->target_table, 'table_name')]);
    }
    
    public function getAdminFilterWhereQuery($query, $input)
    {
        $index = $this->index();
        $query->whereRaw("FIND_IN_SET(?, REPLACE(REPLACE(REPLACE(REPLACE(`$index`, '[', ''), ' ', ''), '[', ''), '\\\"', ''))", $input);
    }

    protected function setAdminFilterOptions(&$filter)
    {
        if (isset($this->target_table)) {
            $options = $this->target_table->getOptions();
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
        if (!isset($this->target_table)) {
            return null;
        }

        if (is_null($target_column_name = array_get($setting, 'target_column_name'))) {
            return $value;
        }

        // get target value
        $target_value = $this->target_table->getValueModel()->where("value->$target_column_name", $value)->first();

        if (!isset($target_value)) {
            return null;
        }

        return $target_value->id;
    }
}
