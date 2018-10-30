<?php

namespace Exceedone\Exment\Form\Widgets;

use Encore\Admin\Widgets\Form;
use Encore\Admin\Facades\Admin;


class ModalForm extends Form
{
    /**
     * Render the form.
     *
     * @return string
     */
    public function render()
    {
        $url = $this->attributes['action'];
        $this->attribute('id', 'modal-form');
        $this->disableAjax();

        // Add script
        $script = <<<EOT
            $("#modal-form [type='submit']").off('click').on('click', function (e) {
                e.preventDefault();
                var form = $('form#modal-form').get()[0];

                // Create FormData Object
                var formData = new FormData( form );

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
                $.ajaxSetup({
                    headers: {
                        'X-CSRF-TOKEN': LA.token
                    }
                });
                $.ajax({
                    url: '$url',
                    method: 'POST',
                    // data as FormData
                    data: formData,
                    // Ajax doesn't process data
                    processData: false,
                    // contentType is false
                    contentType: false
                }).done(function( res ) {
                    $('.modal').modal('hide');
                    $.pjax.reload('#pjax-container');
                    // show toastr
                    if(hasValue(res.toastr)){
                        toastr.success(res.toastr);
                    }
    
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

        return view('admin::widgets.form', $this->getVariables())->render();
    }

    public static function getAjaxResponse($results){
        $results = array_merge([
            'result' => true,
            'toastr' => null,
            'errors' => [],
        ], $results);

        // loop for $results
        foreach($results as $result){

        }

        return response($results, $results['result'] === true ? 200 : 400);
    }
}
