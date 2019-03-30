<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Text;

/**
 * change field. If user select other input select, change input field
 */
class ChangeField extends Text
{
    protected $view = 'exment::form.field.changefield';

    protected $ajax = '';

    public function ajax($ajax)
    {
        $this->ajax = $ajax;

        return $this;
    }

    public function render()
    {
        $url = $this->ajax;
        $script = <<<EOT
    $(document).off('click', '.{$this->column}_button').on('click', '.{$this->column}_button', {}, function(event) {
        // get target row
        var parent = $(event.target).parents('tr');
        var targets = parent.find('[data-change_field_target]');

        // create submits keyvalue
        var data = {};
        targets.each(function(index){
            var key = $(this).data('change_field_target');
            data[key] = $(this).val();
        });
        data['_token'] = LA.token;

        $.ajax({
            method: 'POST',
            url: '$url',
            data: data,
            success: function (data) {
                resolve(data);
            }
        });
    });
EOT;
        \Admin::script($script);

        return parent::render();
    }
}
