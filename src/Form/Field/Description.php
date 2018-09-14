<?php

namespace Exceedone\Exment\Form\Field;
use Encore\Admin\Form\Field;

class Description extends Field\Display
{
    protected $view = 'exment::form.field.description';

    public function __construct($label){
        $this->label = $label;
    }

    public function render()
    {
        return parent::render()->with(
            [
                'offset' => str_replace("col-sm-", "col-sm-offset-", array_get($this->getViewElementClasses(), 'label'))
            ]
        );
    }
}
