<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Model\CustomTable;
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

    public function selectTableColumnList($index_enabled_only = false)
    {
        $table_id = $this->target_table->id;
        $table_name = $this->target_table->table_view_name;
        return $this->target_table->custom_columns
        ->filter(function ($item) use ($index_enabled_only) {
            return !$index_enabled_only || $item->indexEnabled();
        })->mapWithKeys(function ($item) use ($table_id, $table_name) {
            return [$table_id . '-' . $item['id'] => $table_name . ' : ' . $item['column_view_name']];
        })->all();
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
                $texts[] = $m->text;
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
        $field->options(function ($value) {
            // get DB option value
            return $this->target_table->getOptions($value, $this->custom_column->custom_table);
        });
        $ajax = $this->target_table ? $this->target_table->getOptionAjaxUrl() : null;
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
        if(isset($this->target_table)){
            $options = $this->target_table->getOptions();
            $ajax = $this->target_table->getOptionAjaxUrl();
    
            if (isset($ajax)) {
                $filter->select([])->ajax($ajax);
            } else {
                $filter->select($options);
            }    
        }
    }
}
