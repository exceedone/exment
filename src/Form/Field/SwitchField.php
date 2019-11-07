<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\SwitchField as AdminSwitchField;

class SwitchField extends AdminSwitchField
{
    protected $view = 'exment::form.field.switchfield';

    protected $states = [
        'on'  => ['value' => '1', 'text' => 'YES', 'color' => 'primary'],
        'off' => ['value' => '0', 'text' => 'NO', 'color' => 'default'],
    ];

    public function render()
    {
        if (is_null($this->value()) && is_null($this->getOld())) {
            $this->value = $this->states['off']['value'];
        }
        
        $this->script = <<<EOT

$('{$this->getElementClassSelector()}.la_checkbox').bootstrapSwitch({
    size:'small',
    onText: '{$this->states['on']['text']}',
    offText: '{$this->states['off']['text']}',
    onColor: '{$this->states['on']['color']}',
    offColor: '{$this->states['off']['color']}',
    onSwitchChange: function(event, state) {
        $(event.target).closest('.bootstrap-switch').next().val(state ? '{$this->states['on']['value']}' : '{$this->states['off']['value']}').change();
    }
});

EOT;

        $this->attribute(['data-onvalue' => $this->states['on']['value'], 'data-offvalue' => $this->states['off']['value']]);

        $grandParent = $this->getParentClassname();
        return $grandParent::render()->with([
            'onValue'  => $this->states['on']['value'],
            'offValue'  => $this->states['off']['value'],
        ]);
    }

    protected function getParentClassname()
    {
        return get_parent_class(get_parent_class($this));
    }
}
