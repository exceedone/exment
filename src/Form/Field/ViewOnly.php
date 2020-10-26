<?php

namespace Exceedone\Exment\Form\Field;

class ViewOnly extends Display
{
    protected $view = 'exment::form.field.view_only';

    protected $prepareDefault = false;

    public function prepareDefault()
    {
        $this->prepareDefault = true;
        return $this;
    }

    public function prepare($value)
    {
        // if set value, return always default.
        return $this->default;
    }
    
    /**
     * {@inheritdoc}
     */
    public function render()
    {
        return parent::render()->with([
            'prepareDefault'   => $this->prepareDefault,
        ]);
    }
}
