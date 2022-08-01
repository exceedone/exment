<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class AjaxButton extends Field
{
    protected $view = 'exment::form.field.ajax-button';

    protected $url;

    protected $button_label;

    protected $button_class;

    protected $beforesubmit_events;

    protected $send_params;

    protected $confirm;
    protected $confirm_title;
    protected $confirm_text;
    protected $confirm_error;

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

    public function beforesubmit_events($beforesubmit_events)
    {
        $this->beforesubmit_events = $beforesubmit_events;

        return $this;
    }

    public function send_params($params)
    {
        $this->send_params = $params;

        return $this;
    }

    /**
     * Whether confirm
     *
     * @param bool $confirm
     * @return $this
     */
    public function confirm(bool $confirm)
    {
        $this->confirm = $confirm;

        return $this;
    }


    /**
     * confirm_title
     *
     * @param string $confirm_title
     * @return $this
     */
    public function confirm_title($confirm_title)
    {
        $this->confirm_title = $confirm_title;

        return $this;
    }

    /**
     * confirm_text
     *
     * @param string $confirm_text
     * @return $this
     */
    public function confirm_text($confirm_text)
    {
        $this->confirm_text = $confirm_text;

        return $this;
    }

    /**
     * confirm_error
     *
     * @param string $confirm_error
     * @return $this
     */
    public function confirm_error($confirm_error)
    {
        $this->confirm_error = $confirm_error;

        return $this;
    }


    public function render()
    {
        $url = $this->url;
        $confirm = [
            'isConfirm' => $this->confirm,
            'title' => $this->confirm_title,
            'text' => $this->confirm_text,
            'error' => $this->confirm_error,
        ];

        $this->script = <<<SCRIPT

        $('{$this->getElementClassSelector()}').off('click').on('click', function(ev) {
            const button = $(ev.target).closest('button');

            // get senddata
            let send_data = {};
            let senddata_params = button.data('senddata');
            if (hasValue(senddata_params)) {
                let parent = button.parents('.fields-group');
                // get data-key
                for (let index in senddata_params.key) {
                    let key = senddata_params.key[index];
                    let elem = parent.find(CommonEvent.getClassKey(key));
                    if (elem.length == 0) {
                        continue;
                    }
                    send_data[key] = elem.val();
                }
            }

            var beforesubmit_events = button.data('beforesubmit-events');
            if (beforesubmit_events) {
                beforesubmit_events.split(',').forEach(function(key) {
                    $('#' + key).trigger('ajaxbutton-beforesubmit');
                })
            }

            send_data['_token'] = LA.token;
            var send_params = button.data('send-params');
            if (send_params) {
                send_params.split(',').forEach(function(key) {
                    send_data[key] = $('#' + key).val();
                })
            }

            let postEvent = function(button, send_data){
                button.text(button.data('loading-label'));
                button.prop('disabled', true);
    
                return new Promise(function (resolve) {
                    $.ajax({
                        type: "POST",
                        url: "{$url}",
                        data: send_data,
                        success:function(repsonse) {
                            button.text(button.data('default-label'));
                            button.prop('disabled', false);
                            Exment.CommonEvent.CallbackExmentAjax(repsonse, resolve);
                        },
                        error: function(repsonse){
                            button.text(button.data('default-label'));
                            button.prop('disabled', false);
                            Exment.CommonEvent.CallbackExmentAjax(repsonse, resolve);
                        }
                    });
                });
            };


            if(pBool("{$confirm['isConfirm']}")){
                Exment.CommonEvent.ShowSwal("{$url}", {
                    title: "{$confirm['title']}",
                    text: "{$confirm['text']}",
                    input: 'text',
                    preConfirmValidate: function(input){
                        if (input != "yes") {
                            return "{$confirm['error']}";
                        }
            
                        return true;
                    },
                    postEvent: function(data){
                        return postEvent(button, data);
                    },
                });
            }
            else{
                postEvent(button, send_data);
            }
        });
SCRIPT;

        return parent::render()->with([
            'button_label' => $this->button_label,
            'button_class' => $this->button_class,
            'send_params' => $this->send_params,
            'beforesubmit_events' => $this->beforesubmit_events,
        ]);
    }
}
