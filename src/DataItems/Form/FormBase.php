<?php

namespace Exceedone\Exment\DataItems\Form;

abstract class FormBase
{
    protected $custom_table;
    protected $custom_form;
    protected $id;
    protected $custom_value;

    public static function getItem(...$args)
    {
        list($custom_table, $custom_form) = $args + [null, null];

        return new static($custom_table, $custom_form);
    }

    public function id($id = null)
    {
        $this->id = $id;
        if(!is_nullorempty($id)){
            $this->custom_value = $this->custom_table->getValueModel($id);
        }

        return $this;
    }


    abstract public function form();
}
