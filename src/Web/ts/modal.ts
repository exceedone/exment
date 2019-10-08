namespace Exment {
    export class ModalEvent {

        /**
         * Call only once. It's $(document).on event.
         */
        public static AddEventOnce() {
            $(document).off('click', '[data-widgetmodal_url]').on('click', '[data-widgetmodal_url]', {}, Exment.ModalEvent.setModalEvent);
            $(document).off('click', '#modal-showmodal .modal-body a').on('click', '#modal-showmodal .modal-body a', {}, Exment.ModalEvent.setLinkClickEvent);
            $(document).off('click', '#modal-showmodal .modal-submit').on('click', '#modal-showmodal .modal-submit', {}, Exment.ModalEvent.setSubmitEvent);
        }

        public static AddEvent() {
        }

        public static ShowModal($target, url, params = []){
            let original_title = $target.data('original-title');

            /// get data from "data-widgetmodal_getdata"
            let data = {targetid: $target.attr('id')};
            let getdataKeys = $target.data('widgetmodal_getdata');
            if(hasValue(getdataKeys)){
                for(var key in getdataKeys){
                    data[getdataKeys[key]] = $target.find('.' + getdataKeys[key]).val();
                }
            }

            // get expand data
            let expand = $target.data('widgetmodal_expand');
            if(hasValue(expand)){
                data = $.extend(
                    data, expand
                );
            }
            
            let method = hasValue($target.data('widgetmodal_method')) ? $target.data('widgetmodal_method') : 'GET';
            if(method.toUpperCase() == 'POST'){
                data['_token'] = LA.token;
            }

            // if get index
            if(hasValue($target.data('widgetmodal_hasmany'))){
                data['index'] = Exment.ModalEvent.getIndex($target);
            }

            data = $.extend(
                data, params
            );

            // get ajax
            $.ajax({
                url: url,
                method: method,
                data: data
            }).done(function( res ) {
                $('#modal-showmodal button.modal-submit').removeClass('d-none');
                // change html
                Exment.ModalEvent.setBodyHtml(res, null, original_title);

                if(!$('#modal-showmodal').hasClass('in')){
                    $('#modal-showmodal').modal('show');

                    Exment.CommonEvent.AddEvent();
                }
            }).fail(function( res, textStatus, errorThrown ) {
                
            }).always(function(res){
            });

        }

        private static setModalEvent = (ev) =>{
            const target = $(ev.target).closest('[data-widgetmodal_url]');
            const url = target.data('widgetmodal_url');

            if(!hasValue(url)){
                return;
            }

            Exment.ModalEvent.ShowModal(target, url);
        }
        
        /**
         * Set Link click event in Modal
         */
        private static setLinkClickEvent = (ev) =>{
            let a = $(ev.target).closest('a');
            if(hasValue(a.data('widgetmodal_url'))){
                return;
            }
            if(a.data('modalclose') === false){
                return;
            }
            $('#modal-showmodal .modal-body').html('');
            $('#modal-showmodal').modal('hide');
        }
        
        /**
         * set modal submit event
         */
        private static setSubmitEvent = (e) => {
            let formurl = $(e.target).parents('.modal-content').find('form').attr('action');
            let method = $(e.target).parents('.modal-content').find('form').attr('method');
            if (!formurl) return;
            e.preventDefault();
            let form : HTMLFormElement = $('#modal-showmodal form').get()[0] as HTMLFormElement;

            if(!form.reportValidity()){
                return;
            }

            // get button element
            let button = $(e.target).closest('button');
            button.data('buttontext', button.text());
            // add class and prop
            button.prop('disabled', 'disabled').addClass('disabled').text('loading...');

            // remove error message
            $('.modal').find('.has-error').removeClass('has-error');
            $('.modal').find('.error-label').remove();
            $('.modal').find('.error-input-area').val('');
    
            // POST Ajax
            if(method == 'GET'){
                let formData = getParamFromArray($(form).serializeArray());
                $.pjax({container:'#pjax-container', url: formurl + '?' + formData });
                $('.modal').modal('hide');
                Exment.ModalEvent.enableSubmit(button);
                return;
            }

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
                    Exment.ModalEvent.setBodyHtml(res, button, null);
                }
                else{
                    // reomve class and prop
                    Exment.ModalEvent.enableSubmit(button);
                    Exment.CommonEvent.CallbackExmentAjax(res);    
                }
            }).fail(function( res, textStatus, errorThrown ) {
                // reomve class and prop
                Exment.ModalEvent.enableSubmit(button);
                
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
                    for(let key in res.responseJSON.errors){
                        var error = res.responseJSON.errors[key];
                        var target = $('.' + key);
                        var parent = target.closest('.form-group').addClass('has-error');
                        // add message
                        if(error.type == 'input'){
                            let message = error.message;
                            // set value
                            var base_message = (target.val().length > 0 ? target.val() + "\\r\\n" : '');
                            target.val(base_message + message).addClass('error-input-area');
                        }else{
                            let message = error;
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
        }

        private static setBodyHtml(res, button, original_title){
            // change html
            if (res.body) {
                $('#modal-showmodal .modal-body').html(res.body);
                if (res.script) {
                    for(var script of res.script) {
                        eval(script);
                    }
                }
                if (res.title) {
                    $('#modal-showmodal .modal-title').html(res.title);
                }
                if (res.actionurl) {
                    $('#modal-showmodal .modal-action-url').val(res.actionurl);
                }
            } else {
                $('#modal-showmodal .modal-body').html(res);
                if(hasValue(original_title)){
                    $('#modal-showmodal .modal-title').html(original_title);
                }
                $('#modal-showmodal button.modal-submit').addClass('d-none');
            }

            // set modal contentname
            $('#modal-showmodal').attr('data-contentname', res.contentname);

            // set buttonname
            const closelabel = hasValue(res.closelabel) ? res.closelabel : $('#modal-showmodal .modal-close-defaultlabel').val();
            const submitlabel = res.submitlabel ? res.submitlabel : $('#modal-showmodal .modal-submit-defaultlabel').val();
            $('#modal-showmodal').find('.modal-close').text(closelabel);
            $('#modal-showmodal').find('.modal-submit').text(submitlabel);

            $('#modal-showmodal').find('.modal-reset').toggle(res.showReset === true);

            Exment.ModalEvent.enableSubmit(button);
        }

        private static enableSubmit(button){
            if(!hasValue(button)){
                return;
            }
            button.removeAttr('disabled').removeClass('disabled').text(button.data('buttontext'));
        }

        /**
         * get row index. ignore hide row
         * NOW ONLY has-many-table
         * @param $target 
         */
        private static getIndex($target){
            const $tr = $target.closest('tr');
            const $table = $target.closest('table');

            let count = 0;
            $table.find('tbody tr').each(function(index, element){
                if($(element).is(':hidden')){
                    return;
                }

                if($(element).is($tr)){
                    return false;
                }

                count++;
            });

            return count;
        }
    }
}

$(function () {
    Exment.ModalEvent.AddEvent();
    Exment.ModalEvent.AddEventOnce();
});


