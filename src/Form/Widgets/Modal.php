<?php

namespace Exceedone\Exment\Form\Widgets;

use Encore\Admin\Facades\Admin;

class Modal
{
    use ModalTrait;
    protected $modalBody;

    public function body($modalBody)
    {
        $this->modalBody = $modalBody;
    }

    protected function script()
    {
        // modal id
        $id = $this->modalAttributes['id'];
        // Add script
        $script = <<<EOT
            $(document).off('click', '[data-widgetmodal_url]').on('click', '[data-widgetmodal_url]', {}, function(ev){
                var target = $(ev.target).closest('[data-widgetmodal_url]');
                var url = target.data('widgetmodal_url');
                var original_title = target.data('original-title');

                /// get data from "data-widgetmodal_getdata"
                var data = {targetid: $(this).attr('id')};
                var getdataKeys = target.data('widgetmodal_getdata');
                if(hasValue(getdataKeys)){
                    for(var key in getdataKeys){
                        data[getdataKeys[key]] = target.find('.' + getdataKeys[key]).val();
                    }
                }

                // get ajax
                $.ajax({
                    url: url,
                    method: 'GET',
                    data: data
                }).done(function( res ) {
                    $('#$id button.modal-submit').removeClass('d-none');
                    // change html
                    setBodyHtml(res, null, original_title);

                    if(!$('#$id').hasClass('in')){
                        $('#$id').modal('show');

                        Exment.CommonEvent.AddEvent();
                    }
                }).fail(function( res, textStatus, errorThrown ) {
                    
                }).always(function(res){
                });
            });
            $(document).off('click', '#$id .modal-body a').on('click', '#$id .modal-body a', {}, function(ev){
                let a = $(ev.target).closest('a');
                if(hasValue(a.data('widgetmodal_url'))){
                    return;
                }
                if(a.data('modalclose') === false){
                    return;
                }
                $('#$id .modal-body').html('');
                $('#$id').modal('hide');
            });
            $("#$id .modal-submit").off('click').on('click', function (e) {
                var formurl = $(this).parents('.modal-content').find('form').attr('action');
                if (!formurl) return;
                e.preventDefault();
                var form = $('#$id form').get()[0];

                if(!form.reportValidity()){
                    return;
                }

                // get button element
                var button = $(e.target).closest('button');
                button.data('buttontext', button.text());
                // add class and prop
                button.prop('disabled', 'disabled').addClass('disabled').text('loading...');

                // remove error message
                $('.modal').find('.has-error').removeClass('has-error');
                $('.modal').find('.error-label').remove();
                $('.modal').find('.error-input-area').val('');
            
                // Create FormData Object
                var formData = new FormData( form ); 
                    
                $.ajax({
                    url: formurl,
                    method: 'POST',
                    // data as FormData
                    data: formData,
                    // Ajax doesn't process data
                    processData: false,
                    // contentType is false
                    contentType: false
                }).done(function( res ) {
                    if(hasValue(res.body)){
                        setBodyHtml(res, button, null);
                    }
                    else{
                        // reomve class and prop
                        button.removeAttr('disabled').removeClass('disabled').text(button.data('buttontext'));
                        Exment.CommonEvent.CallbackExmentAjax(res);    
                    }
                }).fail(function( res, textStatus, errorThrown ) {
                    // reomve class and prop
                    button.removeAttr('disabled').removeClass('disabled').text(button.data('buttontext'));
                    
                    // if not have responseJSON, undefined error
                    if(!hasValue(res.responseJSON)){
                        toastr.error('Undefined Error');
                        return;
                    }
                    
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

            function setBodyHtml(res, button, original_title){
                // change html
                if (res.body) {
                    $('#$id .modal-body').html(res.body);
                    if (res.script) {
                        for(var script of res.script) {
                            eval(script);
                        }
                    }
                    if (res.title) {
                        $('#$id .modal-title').html(res.title);
                    }
                    if (res.actionurl) {
                        $('#$id .modal-action-url').val(res.actionurl);
                    }
                } else {
                    $('#$id .modal-body').html(res);
                    if(hasValue(original_title)){
                        $('#$id .modal-title').html(original_title);
                    }
                    $('#$id button.modal-submit').addClass('d-none');
                }
                // reomve class and prop
                if(hasValue(button)){
                    button.removeAttr('disabled').removeClass('disabled').text(button.data('buttontext'));
                }
            }
EOT;
        Admin::script($script);
    }

    public static function widgetModalRender()
    {
        // add modal for showmodal
        $modal = new Modal();
        $modal->modalHeader(exmtrans('custom_value.data_detail'));
        $modal->modalAttribute(['id' => 'modal-showmodal', 'data-backdrop' => true]);

        return $modal->render();
    }

    /**
     * Render the form.
     *
     * @return string
     */
    public function render()
    {
        $this->setModalAttributes();

        $this->script();

        // get view
        return view('exment::widgets.modal', [
            'header' => $this->modalHeader,
            'body' => $this->modalBody,
            'modalSubmitAttributes' => 'd-none',
            'modalAttributes' => $this->convert_attribute($this->modalAttributes),
            'modalInnerAttributes' => $this->convert_attribute($this->modalInnerAttributes),
        ]);
    }
}
