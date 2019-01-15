<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Exceedone\Exment\Model\CustomTable;
use Encore\Admin\Form\Field;

class SelectTable extends CustomItem 
{
    protected $target_table;
    
    public function __construct($custom_column, $custom_value){
        parent::__construct($custom_column, $custom_value);

        $this->target_table = CustomTable::getEloquent(array_get($custom_column, 'options.select_target_table'));
    }

    public function value(){
        return $this->getValue(false);
    }

    public function text(){
        return $this->getValue(true);
    }

    public function html(){
        return $this->getValue(true);
    }

    protected function getValue($text){
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
            
            // get label column
            $texts[] = $m->label;
        }
        return implode(exmtrans('common.separate_word'), $texts);
    }
    
    protected function getAdminFieldClass(){
        if (boolval(array_get($this->custom_column, 'options.multiple_enabled'))) {
            return Field\MultipleSelect::class;
        } else {
            return Field\Select::class;
        }
    }
    
    protected function setAdminOptions(&$field, $form_column_options){
        $field->options(function ($value) {
            // get DB option value
            return $this->target_table->getOptions($value, $this->custom_column->custom_table);
        });
        $ajax = $this->target_table->getOptionAjaxUrl() ?? null;
        if (isset($ajax)) {
            $field->attribute([
                'data-add-select2' => $this->label(),
                'data-add-select2-ajax' => $ajax
            ]);
        }
        // add table info
        $field->attribute(['data-target_table_name' => array_get($this->target_table, 'table_name')]);
    }
}
