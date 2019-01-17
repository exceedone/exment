<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Form\Field;

class SystemItem implements ItemInterface
{
    use ItemTrait;
    
    protected $column_name;
    
    protected $custom_table;
    
    public function __construct($custom_table, $column_name, $custom_value){
        $this->custom_table = $custom_table;
        $this->column_name = $column_name;
        $this->value = $this->getTargetValue($custom_value);
        $this->label = exmtrans("common.$this->column_name");
    }

    /**
     * get column name
     */
    public function name(){
        return $this->column_name;
    }

    /**
     * get column key sql name.
     */
    public function sqlname(){
        return getDBTableName($this->custom_table) .'.'. $this->column_name;
    }

    /**
     * get index name
     */
    public function index(){
        return $this->name();
    }

    /**
     * get text(for display) 
     */
    public function text(){
        return $this->value;
    }

    /**
     * get html(for display) 
     * *this function calls from non-escaping value method. So please escape if not necessary unescape. 
     */
    public function html(){
        return esc_html($this->text());
    }

    /**
     * sortable for grid
     */
    public function sortable(){
        return true;
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
        return array_get($custom_value, $this->column_name);
    }   
    
    public function getAdminField($form_column = null, $column_name_prefix = null){
        $field = new Field\Display($this->name(), [$this->label()]);
        $field->default($this->value);

        return $field;
    }

    public static function getItem(...$args){
        list($custom_table, $column_name, $custom_value) = $args;
        return new self($custom_table, $column_name, $custom_value);
    }
}
