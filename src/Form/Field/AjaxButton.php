<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class AjaxButton extends Field
{
    protected $view = 'exment::form.field.ajax-button';
    
    protected $url;
    
    protected $button_label;
    
    protected $button_class;

    protected $send_params;

    public function url($url)
    {
        $this->url = $url;

        return $this;
    }

    public function button_label($button_label)
    {
        $this->button_label = $button_label;

        return $this;
    }

    public function button_class($button_class)
    {
        $this->button_class = $button_class;

        return $this;
    }

    public function send_params($params)
    {
        $this->send_params = $params;

        return $this;
    }

    public function render()
    {
        $url = $this->url;

        // $param_scripts = [];
        // if (isset($this->params)) {
        //     $param_scripts[] = '';
        //     $param_script = collect($this->params)->map(function($param) {
        //         return "$(ev.target).val;";
        //     })->join('\n');
        // }
        $param_scripts = "var data = { _token: LA.token};\ndata['param'] = $('#test_mail_to').val()";

        $this->script = <<<EOT

        $('{$this->getElementClassSelector()}').off('click').on('click', function(ev) {
            const button = $(ev.target).closest('button');
            button.text(button.data('loading-label'));
            button.prop('disabled', true);

            var send_data = {};
            send_data['_token'] = LA.token;
            var send_params = button.data('send-params');
            if (send_params) {
                send_params.split(',').forEach(function(key) {
                    send_data[key] = $('#' + key).val();
                })
            }

            $.ajax({
                type: "POST",
                url: "{$url}",
                data: send_data,
                success:function(repsonse) {
                    button.text(button.data('default-label'));
                    button.prop('disabled', false);
                    Exment.CommonEvent.CallbackExmentAjax(repsonse);
                },
                error: function(repsonse){
                    button.text(button.data('default-label'));
                    button.prop('disabled', false);
                    Exment.CommonEvent.CallbackExmentAjax(repsonse);
                }
            });
        });
EOT;

        return parent::render()->with([
            'button_label' => $this->button_label,
            'button_class' => $this->button_class,
            'send_params' => $this->send_params,
        ]);
    }
}
