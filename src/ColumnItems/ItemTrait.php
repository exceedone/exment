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

    protected $options;

    /**
     * get value 
     */
    public function value(){
        return $this->value;
    }

    /**
     * get or set option for convert
     */
    public function options($options = null){
        if(!func_num_args()){
            return $this->options ?? [];
        }

        $this->options = array_merge(
            $options,
            $this->options ?? []
        );

        return $this;
    }

    /**
     * get label. (user theader, form label etc...)
     */
    public function label($label = null){
        if(!func_num_args()){
            return $this->label;
        }
        $this->label = $label;
        return $this;
    }

    /**
     * get value's id.
     */
    public function id($id = null){
        if(!func_num_args()){
            return $this->id;
        }
        $this->id = $id;
        return $this;
    }

    public function prepare(){
    }

}
