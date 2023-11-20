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

        $onText = esc_html($this->states['on']['text']);
        $offText = esc_html($this->states['off']['text']);
        $onColor = esc_html($this->states['on']['color']);
        $offColor = esc_html($this->states['off']['color']);
        $onValue = esc_html($this->states['on']['value']);
        $offValue = esc_html($this->states['off']['value']);
        $this->script = <<<EOT

$('{$this->getElementClassSelector()}.la_checkbox').bootstrapSwitch({
    size:'small',
    onText: '{$onText}',
    offText: '{$offText}',
    onColor: '{$onColor}',
    offColor: '{$offColor}',
    onSwitchChange: function(event, state) {
        let onValue = $( '<span/>' ).html( '{$onValue}' ).text();
        let offValue = $( '<span/>' ).html( '{$offValue}' ).text();
        $(event.target).closest('.bootstrap-switch').next().val(state ? onValue : offValue).change();
        if(event.target.classList[0] == 'log_available' && state == false) {
            $(event.target).closest('.form-group').nextAll('.form-group:lt(2)').hide();
        } else if (event.target.classList[0] == 'log_available' && state == true) {
            $(event.target).closest('.form-group').nextAll('.form-group:lt(2)').show();
        }
    }
});

EOT;

        $this->attribute(['data-onvalue' => $onValue, 'data-offvalue' => $offValue]);

        $grandParent = $this->getParentClassname();
        if ($this->getElementName() == 'log_available' && $this->value() == '0'){
            $this->script .= <<<EOT
                $('.form-group label[for="time_clear_log"]').closest('.form-group').hide();
                $('.form-group label[for="time_clear_log_unit"]').closest('.form-group').hide();
            EOT;
        }
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
