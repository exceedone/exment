<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\ColumnItems\CustomColumns;
use Encore\Admin\Form\Field;

class AgreegateChildItem implements ItemInterface
{
    use ItemTrait;
    
    protected $custom_column;

    public function __construct($custom_column, $custom_value){
        $this->custom_column = $custom_column;
        $this->label = $this->custom_column->column_view_name;
        $this->setCustomValue($custom_value);
    }

    /**
     * get column name
     */
    public function name(){
        return 'agreegate_child_'.$this->custom_column->column_name;
    }

    /**
     * get index name
     */
    public function index(){
        return $this->custom_column->getIndexColumnName();
    }

    /**
     * get text(for display) 
     */
    public function text(){
        return $this->value();
    }

    /**
     * get html(for display) 
     * *this function calls from non-escaping value method. So please escape if not necessary unescape. 
     */
    public function html(){
        return $this->value();
    }

    /**
     * sortable for grid
     */
    public function sortable(){
        return false;
    }

    public function setCustomValue($custom_value){
        $this->value = $this->getTargetValue($custom_value);
        if(isset($custom_value)){
            $this->id = $custom_value->id;
        }

        $this->prepare();
        
        return $this;
    }

    protected function getTargetValue($custom_value){
        if(isset($custom_value)){
            return $custom_value->getSum($this->custom_column);
        }
        return null;
    }
    
    public static function getItem(...$args){
        list($custom_table, $custom_value) = $args;
        return new self($custom_table, $custom_value);
    }
}
