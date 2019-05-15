<?php

namespace Exceedone\Exment\Form\Field;

class SwitchBoolField extends SwitchField
{
    protected $view = 'exment::form.field.switchfield';

    protected $states = [
        'on'  => ['value' => '1', 'text' => 'YES', 'color' => 'primary'],
        'off' => ['value' => '0', 'text' => 'NO', 'color' => 'default'],
    ];
    
    protected function getParentClassname(){
        return get_parent_class(get_parent_class(get_parent_class($this)));
    }
}
