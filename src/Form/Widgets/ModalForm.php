<?php

namespace Exceedone\Exment\Form\Widgets;

use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Facades\Admin;

class ModalForm extends WidgetForm
{
    use ModalTrait;

    protected function script()
    {
        $formurl = $this->attributes['action']; // from url
        $id = $this->modalAttributes['id'];  // modal id
        $method = $this->attributes['method'];
        // Add script
        $script = <<<EOT
            $("#$id .modal-submit").off('click').on('click', function (e) {
                e.preventDefault();
                var form = $('#$id form').get()[0];

                // get button element
                var button = $(e.target).closest('button');
                button.data('buttontext', button.text());
                // add class and prop
                button.prop('disabled', 'disabled').addClass('disabled').text('loading...');

                // remove error message
                $('.modal').find('.has-error').removeClass('has-error');
                $('.modal').find('.error-label').remove();
                $('.modal').find('.error-input-area').val('');
            
                // POST Ajax
                if('$method' == 'GET'){
                    var formData = getParamFromArray($(form).serializeArray());
                    $.pjax({container:'#pjax-container', url: '{$formurl}?' + formData });
                    $('.modal').modal('hide');
                    return;
                }
                // Create FormData Object
                var formData = new FormData( form ); 
                    
                $.ajax({
                    url: '$formurl',
                    method: '$method',
                    // data as FormData
                    data: formData,
                    // Ajax doesn't process data
                    processData: false,
                    // contentType is false
                    contentType: false
                }).done(function( res ) {
                    Exment.CommonEvent.CallbackExmentAjax(res);
                }).fail(function( res, textStatus, errorThrown ) {
                    // reomve class and prop
                    button.removeAttr('disabled').removeClass('disabled').text(button.data('buttontext'));
                    // show toastr
                    if(hasValue(res.responseJSON.toastr)){
                        toastr.error(res.responseJSON.toastr);
                    }
                    // show error message
                    if(hasValue(res.responseJSON.errors)){
                        for(key in res.responseJSON.errors){
                            var error = res.responseJSON.errors[key];
                            var target = $('.' + key);
                            var parent = target.closest('.form-group').addClass('has-error');
                            // add message
                            if(error.type == 'input'){
                                message = error.message;
                                // set value
                                var base_message = (target.val().length > 0 ? target.val() + "\\r\\n" : '');
                                target.val(base_message + message).addClass('error-input-area');
                            }else{
                                message = error;
                                parent.children('div').prepend($('<label/>', {
                                    'class': 'control-label error-label',
                                    'for': 'inputError',
                                    'html':[
                                        $('<i/>', {
                                            'class': 'fa fa-times-circle-o'
                                        }),
                                        $('<span/>', {
                                            'text': ' ' + message
                                        }),
                                    ]
                                }));
                            }
                        }
                    }
                }).always(function(res){
                });

                return false;
            });
EOT;
        Admin::script($script);
    }


    /**
     * Add a form field to form.
     * It's the same function WidgetForm pushField, but why don't know pushField is PROTECTED function. so I added
     *
     * @return $this
     */
    public function push_Field(&$field)
    {
        return $this->pushField($field);
    }

    protected function setDefaultAttributes()
    {
        $this->attributes = array_merge([
            'id' => 'modalform-form',
        ], $this->attributes);
        
        $this->setModalAttributes();
    }

    /**
     * Render the form.
     *
     * @return string
     */
    public function render()
    {
        $this->setDefaultAttributes();
        $this->disableAjax();
        $this->disableReset();

        // if has submit button, remove default submit, and add js submit button
        $submit = false;
        if (in_array('submit', $this->buttons)) {
            $this->disableSubmit();
            $submit = true;
            $this->script();
        }

        // get form render
        $form_render = parent::render();

        // get view
        return view('exment::widgets.modal', [
            'header' => $this->modalHeader,
            'body' => $form_render,
            'submit' => $submit,
            'modalAttributes' => $this->convert_attribute($this->modalAttributes),
            'modalInnerAttributes' => $this->convert_attribute($this->modalInnerAttributes),
        ]);
    }
}
