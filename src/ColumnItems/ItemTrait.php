<?php

namespace Exceedone\Exment\ColumnItems;

trait ItemTrait
{
    /**
     * this column's target custom_table
     */
    protected $value;

    protected $label;

    protected $id;

    /**
     * get value 
     */
    public function value(){
        return $this->value;
    }

    /**
     * get label. (user theader, form label etc...)
     */
    public function label($label = null){
        if(is_null($label)){
            return $this->label;
        }
        $this->label = $label;
        return $this;
    }

    /**
     * get value's id.
     */
    public function id($id = null){
        if(is_null($id)){
            return $this->id;
        }
        $this->id = $id;
        return $this;
    }

    public function prepare(){
    }

}
