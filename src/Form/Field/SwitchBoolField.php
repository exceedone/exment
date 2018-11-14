<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class SwitchBoolField extends Field
{
    protected static $css = [
        '/vendor/laravel-admin/bootstrap-switch/dist/css/bootstrap3/bootstrap-switch.min.css',
    ];

    protected static $js = [
        '/vendor/laravel-admin/bootstrap-switch/dist/js/bootstrap-switch.min.js',
    ];

    protected $view = 'exment::form.field.switchboolfield';


    protected $states = [
        '1'  => ['value' => '1', 'text' => 'YES', 'color' => 'primary'],
        '0' => ['value' => '0', 'text' => 'NO', 'color' => 'default'],
    ];

    public function states($states = [])
    {
        foreach (array_dot($states) as $key => $state) {
            array_set($this->states, $key, $state);
        }

        return $this;
    }

    public function prepare($value)
    {
        if (isset($this->states[$value])) {
            return $this->states[$value]['value'];
        }

        return $value;
    }

    public function render()
    {
        if (is_null($this->value())) {
            $this->value = 0;
        } else {
            foreach ($this->states as $state => $option) {
                if (boolval($this->value()) == boolval($option['value'])) {
                    $this->value = $state;
                    break;
                }
            }
        }

        $this->script = <<<EOT

$('{$this->getElementClassSelector()}.la_checkbox').bootstrapSwitch({
    size:'small',
    onText: '{$this->states['1']['text']}',
    offText: '{$this->states['0']['text']}',
    onColor: '{$this->states['1']['color']}',
    offColor: '{$this->states['0']['color']}',
    onSwitchChange: function(event, state) {
        $(event.target).closest('.bootstrap-switch').next().val(state ? '1' : '0').change();
    }
});

EOT;

        return parent::render();
    }
}
