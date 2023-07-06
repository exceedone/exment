<?php

namespace Exceedone\Exment\Form\Field;

class Password extends \Encore\Admin\Form\Field\Password
{
    protected $showToggleIcon = false;

    /**
     * set toggle icon password display
     */
    public function toggleShowEvent()
    {
        $this->showToggleIcon = true;

        return $this;
    }

    public function render()
    {
        if ($this->showToggleIcon) {
            $this->script = <<<EOT
    var target = $('{$this->getElementClassSelector()}').closest('.input-group').find('.input-group-addon');
    target.css('cursor', 'pointer').off('click').on('click', function(ev){
    // toggle class
    $(this).find('.fa').toggleClass("fa-eye-slash fa-eye");

    // get input
    var input = $(this).closest('.input-group').find('input');

    // toggle type
    if (input.attr("type") == "password") {
        input.attr("type", "text");
    } else {
        input.attr("type", "password");
    }
});
EOT;
        }

        return parent::render();
    }
}
