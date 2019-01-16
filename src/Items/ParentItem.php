<?php

namespace Exceedone\Exment\Items;

use Exceedone\Exment\Model\CustomRelation;

class ParentItem implements ItemInterface
{
    use ItemTrait;
    
    /**
     * this column's target custom_table
     */
    protected $custom_table;
    
    public function __construct($custom_table, $custom_value){
        $this->custom_table = $custom_table;
        $this->value = $this->getTargetValue($custom_value);
        $this->label = $custom_table->table_view_name;
    }

    /**
     * get column name
     */
    public function name(){
        return 'parent_id_'.$this->custom_table->table_name;
    }

    /**
     * get index name
     */
    public function index(){
        return null;
    }

    /**
     * get text(for display) 
     */
    public function text(){
        return $this->value->getText();
    }

    /**
     * get html(for display) 
     * *this function calls from non-escaping value method. So please escape if not necessary unescape. 
     */
    public function html(){
        return $this->value->getUrl(true);
    }

    /**
     * sortable for grid
     */
    public function sortable(){
        return true;
    }

    public function setCustomValue($custom_value){
        $relation = CustomRelation::getRelationByChild($this->custom_table);
        if (!isset($relation)) {
            return;
        }

        $this->value = $this->getTargetValue($custom_value);
        if(isset($custom_value)){
            $this->id = $custom_value->id;
        }
        $this->prepare();
        
        return $this;
    }

    protected function getTargetValue($custom_value){
        if(is_null($custom_value)){
            return;
        }

        if (!isset($custom_value->parent_id) || !isset($custom_value->parent_type)) {
            return;
        }

        return getModelName($custom_value->parent_type)::find($custom_value->parent_id);
    }   
    
    public static function getItem(...$args){
        list($custom_table, $custom_value) = $args;
        return new self($custom_table, $custom_value);
    }
}
