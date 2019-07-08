<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class AjaxButton extends Field
{
    protected $view = 'exment::form.field.ajax-button';
    
    protected $url;
    
    protected $button_label;
    
    protected $button_class;

    public function url($url){
        $this->url = $url;

        return $this;
    }

    public function button_label($button_label){
        $this->button_label = $button_label;

        return $this;
    }

    public function button_class($button_class){
        $this->button_class = $button_class;

        return $this;
    }

    public function render()
    {
        $url = $this->url;

        $this->script = <<<EOT

        $('{$this->getElementClassSelector()}').off('click').on('click', function(ev) {
            const button = $(ev.target).closest('button');
            button.text(button.data('loading-label'));
            button.prop('disabled', true);

            $.ajax({
                type: "POST",
                url: "{$url}",
                data:{ _token: LA.token},
                success:function(repsonse) {
                    button.text(button.data('default-label'));
                    button.prop('disabled', false);
                    Exment.CommonEvent.CallbackExmentAjax(repsonse);
                },
                error: function(repsonse){
                    button.text(button.data('default-label'));
                    button.prop('disabled', false);
                    toastr.error(repsonse.message);
                }
            });
        });
EOT;

        return parent::render()->with([
            'button_label' => $this->button_label,
            'button_class' => $this->button_class,
        ]);
    }
}
