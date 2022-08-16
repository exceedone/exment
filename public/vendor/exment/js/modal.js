var Exment;
(function (Exment) {
    class ModalEvent {
        /**
         * Call only once. It's $(document).on event.
         */
        static AddEventOnce() {
            $(document).off('click', '[data-widgetmodal_url]').on('click', '[data-widgetmodal_url]', {}, Exment.ModalEvent.setModalEvent);
            $(document).off('click', '#modal-showmodal .modal-body a').on('click', '#modal-showmodal .modal-body a', {}, Exment.ModalEvent.setLinkClickEvent);
            $(document).off('click', '#modal-showmodal .modal-submit').on('click', '#modal-showmodal .modal-submit', {}, Exment.ModalEvent.setSubmitEvent);
            $(document).off('keydown', '#modal-showmodal form input').on('keydown', '#modal-showmodal form input', {}, Exment.ModalEvent.setEnterEvent);
            // selectitem box
            $(document).off('click', '.table .button-append-selectitem').on('click', '.table .button-append-selectitem', {}, Exment.ModalEvent.appendSelectItemEvent);
            $(document).off('click', '#modal-showmodal .selectitembox-item .button-delete').on('click', '#modal-showmodal .selectitembox-item .button-delete', {}, Exment.ModalEvent.deleteSelectItemEvent);
            $(document).off('click', '#modal-showmodal .modal-selectitem .modal-submit').on('click', '#modal-showmodal .modal-selectitem .modal-submit', {}, Exment.ModalEvent.setCalledSelectItemEvent);
            Exment.ModalEvent.autoloadModalEvent();
            $('#modal-showmodal').on('hidden.bs.modal', function (e) {
                $('#modal-showmodal .modal-body').html('');
            });
        }
        static AddEvent() {
        }
        /**
         * Show modal
         * @param $target click target button
         * @param url request url
         * @param post_params post params
         * @param options options
         */
        static ShowModal($target, url, post_params = {}) {
            let original_title = $target.data('original-title');
            let data = { targetid: $target.attr('id') };
            /// get data from "data-widgetmodal_getdata". only get in targets.
            let getdataKeys = $target.data('widgetmodal_getdata');
            if (hasValue(getdataKeys)) {
                for (var key in getdataKeys) {
                    data[getdataKeys[key]] = $target.find('.' + getdataKeys[key]).val();
                }
            }
            /// get data from "data-widgetmodal_getdata_fieldsgroup". get from parents "fields-group".
            /// key is posted keyname. value is class selector name.
            getdataKeys = $target.data('widgetmodal_getdata_fieldsgroup');
            if (hasValue(getdataKeys)) {
                for (var key in getdataKeys) {
                    data[key] = $target.closest('.form-group, .form-group-vertical').find('.' + getdataKeys[key]).val();
                }
            }
            // set uuid
            let uuid = $target.attr('data-widgetmodal_uuid');
            if (!hasValue(uuid)) {
                uuid = getUuid();
                $target.attr('data-widgetmodal_uuid', uuid);
            }
            data['widgetmodal_uuid'] = uuid;
            // get expand data
            let expand = $target.data('widgetmodal_expand');
            if (hasValue(expand)) {
                data = $.extend(data, expand);
            }
            let method = hasValue($target.data('widgetmodal_method')) ? $target.data('widgetmodal_method') : 'GET';
            if (method.toUpperCase() == 'POST') {
                data['_token'] = LA.token;
            }
            // if get index
            if (hasValue($target.data('widgetmodal_hasmany'))) {
                data['index'] = Exment.ModalEvent.getIndex($target);
            }
            data = $.extend(data, post_params);
            // get ajax
            $.ajax({
                url: url,
                method: method,
                data: data
            }).done(function (res) {
                $('#modal-showmodal button.modal-submit').removeClass('d-none');
                // change html
                Exment.ModalEvent.setBodyHtml(res, null, original_title);
                if (!$('#modal-showmodal').hasClass('in')) {
                    $('#modal-showmodal').modal('show');
                    Exment.CommonEvent.AddEvent();
                }
            }).fail(function (res, textStatus, errorThrown) {
                Exment.CommonEvent.CallbackExmentAjax(res);
            }).always(function (res) {
            });
        }
        /**
         * Showing static html
         */
        static ShowModalHtml($target, $html, title) {
            let original_title = $target.data('original-title');
            $('#modal-showmodal button.modal-submit').removeClass('d-none');
            // change html
            Exment.ModalEvent.setBodyHtml({ body: $html.html(), title: title, showSubmit: false }, null, original_title);
            if (!$('#modal-showmodal').hasClass('in')) {
                $('#modal-showmodal').modal('show');
                Exment.CommonEvent.AddEvent();
            }
        }
        /**
         * Open modal automatic
         */
        static autoloadModalEvent() {
            const target = $('[data-widgetmodal_autoload]');
            const url = target.data('widgetmodal_autoload');
            if (!hasValue(url)) {
                return;
            }
            Exment.ModalEvent.ShowModal(target, url);
        }
        static setMessagesOrErrors(messages, isError) {
            for (let key in messages) {
                var message = messages[key];
                var target = $('.modal .' + key);
                var parent = target.closest('.form-group');
                if (isError) {
                    parent.addClass('has-error');
                }
                // add message
                if (message.type == 'input') {
                    let m = message.message;
                    // set value
                    var base_message = (target.val().length > 0 ? target.val() + "\\r\\n" : '');
                    target.val(base_message + m).addClass('modal-input-area');
                }
                else {
                    let m = message;
                    parent.children('div').prepend($('<label/>', {
                        'class': 'control-label error-label',
                        'for': 'inputError',
                        'html': [
                            $('<i/>', {
                                'class': 'fa fa-times-circle-o'
                            }),
                            $('<span/>', {
                                'text': ' ' + m
                            }),
                        ]
                    }));
                }
            }
        }
        /**
         * set body html in modal.
         * res: {
         *     'body': showing body html.
         *     'script': executing script.
         *     'title': modal title.
         *     'actionurl': form action url.
         *     'closelabel': Close button label.
         *     'submitlabel': Submit button label.
         *     'showSubmit': Showing Submit button.
         *     'showReset': Showing reset button.
         *     'preventSubmit': Prevent default submit event.
         *     'modalSize': Classname modal size.
         *     'modalClass': Appending class in modal.
         * }
         * @param res
         * @param button
         * @param original_title
         */
        static setBodyHtml(res, button = null, original_title = null) {
            // change html
            if (res.body) {
                $('#modal-showmodal .modal-body').html(res.body);
                if (res.script) {
                    if (!Array.isArray(res.script)) {
                        eval(res.script);
                    }
                    else {
                        for (var script of res.script) {
                            eval(script);
                        }
                    }
                }
                if (res.title) {
                    $('#modal-showmodal .modal-title').text(res.title);
                }
                if (res.actionurl) {
                    $('#modal-showmodal .modal-action-url').val(res.actionurl);
                }
            }
            else {
                $('#modal-showmodal .modal-body').html(res);
                if (hasValue(original_title)) {
                    $('#modal-showmodal .modal-title').text(original_title);
                }
                $('#modal-showmodal button.modal-submit').addClass('d-none');
            }
            // set modal contentname
            $('#modal-showmodal').attr('data-contentname', hasValue(res.contentname) ? res.contentname : null);
            // set buttonname
            const closelabel = hasValue(res.closelabel) ? res.closelabel : $('#modal-showmodal .modal-close-defaultlabel').val();
            const submitlabel = res.submitlabel ? res.submitlabel : $('#modal-showmodal .modal-submit-defaultlabel').val();
            const $submitButton = $('#modal-showmodal').find('.modal-submit');
            $('#modal-showmodal').find('.modal-close').text(closelabel);
            $submitButton.text(submitlabel);
            $('#modal-showmodal').find('.modal-reset').toggle(res.showReset === true);
            let showSubmit = true;
            if (res.showSubmit !== undefined) {
                showSubmit = res.showSubmit;
            }
            $submitButton.toggle(showSubmit);
            let disableSubmit = false;
            if (res.disableSubmit !== undefined) {
                disableSubmit = res.disableSubmit;
            }
            $submitButton.prop('disabled', disableSubmit);
            let preventSubmit = false;
            if (res.preventSubmit !== undefined) {
                preventSubmit = res.preventSubmit;
            }
            // toggle form pjax-container
            let $form = $submitButton.closest('.modal-content').find('form');
            if (preventSubmit) {
                $submitButton.addClass('preventSubmit');
                // remove pjax-container in form
            }
            else {
                $submitButton.removeClass('preventSubmit');
            }
            let modalSize = 'modal-lg';
            if (hasValue(res.modalSize)) {
                modalSize = res.modalSize;
            }
            let modalClass = hasValue(res.modalClass) ? ' ' + res.modalClass : '';
            $('.exment-modal-dialog').removeClass().addClass('exment-modal-dialog modal-dialog ' + modalSize + modalClass);
            Exment.ModalEvent.enableSubmit(button);
        }
        static enableSubmit(button) {
            if (!hasValue(button)) {
                return;
            }
            button.removeAttr('disabled').removeClass('disabled').text(button.data('buttontext'));
        }
        /**
         * get row index. ignore hide row
         * NOW ONLY has-many-table
         * @param $target
         */
        static getIndex($target) {
            const $tr = $target.closest('tr');
            const $table = $target.closest('table');
            let count = 0;
            $table.find('tbody tr').each(function (index, element) {
                if ($(element).is(':hidden')) {
                    return;
                }
                if ($(element).is($tr)) {
                    return false;
                }
                count++;
            });
            return count;
        }
        /**
         * Append select item event if click append button.
         * @param ev
         */
        static appendSelectItemEvent(ev) {
            let $button = $(ev.target).closest('button');
            if (!hasValue($button)) {
                return;
            }
            let value = $button.data('value');
            // get "parent's" target item.
            let parent = window.parent;
            if (!parent) {
                return;
            }
            // get parent target
            let $target = $('#modal-showmodal', parent.document).find('[data-selectitem="' + $button.data('target-selectitem') + '"]');
            if (!hasValue($target) || !hasValue(value)) {
                return;
            }
            // find value, .if already exists, return
            let $checkValue = $target.find('.selectitem-value[value="' + value + '"]');
            if (hasValue($checkValue)) {
                return;
            }
            // if not multiple, remove exists items
            if (!pBool($target.data('multiple'))) {
                $target.find('.selectitembox-item-inner .selectitembox-value').remove();
            }
            // get html from template
            let templateHtml = $target.find('template').html();
            let html = ModalEvent.replaceTemplate(templateHtml, {
                'value': escHtml($button.data('value')),
                'label': escHtml($button.data('label')),
            });
            $target.find('.selectitembox-item-inner').append(html);
        }
        /**
         * Delete select item event if click ×.
         * @param ev
         */
        static deleteSelectItemEvent(ev) {
            let $targetItem = (ev.target).closest('span');
            if (!hasValue($targetItem)) {
                return;
            }
            $targetItem.remove();
        }
        static replaceTemplate(content, data) {
            return content.replace(/%(\w*)%/g, // or /{(\w*)}/g for "{this} instead of %this%"
            function (m, key) {
                return data.hasOwnProperty(key) ? data[key] : "";
            });
        }
        static setCalledSelectItemEvent(ev) {
            ev.preventDefault();
            //TODO: now only support single selectitembox-item.
            let $target = $('.selectitembox-item');
            let values = [];
            $target.find('.selectitembox-value').each(function (index, element) {
                values.push({
                    value: $(element).find('.selectitem-value').val(),
                    label: $(element).find('.selectitem-label').text(),
                });
            });
            // set based select item
            let widgetmodal_uuid = $target.data('selectitem-widgetmodal_uuid');
            let $baseSelect = $('[data-widgetmodal_uuid="' + widgetmodal_uuid + '"]').closest('.form-group, .form-group-vertical').find('.' + $target.data('selectitem-target_class'));
            $baseSelect.val(null);
            for (let i = 0; i < values.length; i++) {
                let v = values[i];
                // Set the value, creating a new option if necessary
                if ($baseSelect.find("option[value='" + v.value + "']").length == 0) {
                    // Create a DOM Option and pre-select by default
                    var newOption = new Option(v.label, v.value, true, true);
                    // Append it to the select
                    $baseSelect.append(newOption);
                }
            }
            $baseSelect.val(values.map(x => x.value));
            $baseSelect.trigger('change');
            $('.modal').modal('hide');
        }
    }
    ModalEvent.setModalEvent = (ev) => {
        const target = $(ev.target).closest('[data-widgetmodal_url]');
        const url = target.data('widgetmodal_url');
        const isHtml = target.data('widgetmodal_html');
        if (isHtml) {
            // get target html
            let uuid = target.data('widgetmodal_uuid');
            let html = $('.widgetmodal_html[data-widgetmodal_html_target="' + uuid + '"]');
            if (!hasValue(html)) {
                return;
            }
            Exment.ModalEvent.ShowModalHtml(target, html, html.data('widgetmodal_title'));
            return;
        }
        if (!hasValue(url)) {
            return;
        }
        Exment.ModalEvent.ShowModal(target, url);
    };
    /**
     * Set Link click event in Modal
     */
    ModalEvent.setLinkClickEvent = (ev) => {
        let a = $(ev.target).closest('a');
        if (hasValue(a.data('widgetmodal_url'))) {
            return;
        }
        if (a.data('modalclose') === false) {
            return;
        }
        if (a.data('toggle') === 'tooltip') {
            return;
        }
        $('#modal-showmodal .modal-body').html('');
        $('#modal-showmodal').modal('hide');
    };
    /**
     * Enter Keydown event. Now disable click event
     * @param e
     */
    ModalEvent.setEnterEvent = (e) => {
        if (e.keyCode != 13) {
            return;
        }
        e.preventDefault();
    };
    /**
     * set modal submit event
     */
    ModalEvent.setSubmitEvent = (e) => {
        let formurl = $(e.target).parents('.modal-content').find('form').attr('action');
        let method = $(e.target).parents('.modal-content').find('form').attr('method');
        if (!formurl)
            return;
        e.preventDefault();
        // get button element
        let button = $(e.target).closest('button');
        // if has 'preventSubmit' class, not submit
        if (button.hasClass('preventSubmit')) {
            return;
        }
        let form = $('#modal-showmodal form').get()[0];
        if (!form.reportValidity()) {
            return;
        }
        button.data('buttontext', button.text());
        // add class and prop
        button.prop('disabled', 'disabled').addClass('disabled').text('loading...');
        // remove error message
        $('.modal').find('.has-error').removeClass('has-error');
        $('.modal').find('.error-label').remove();
        $('.modal').find('.modal-input-area').val('');
        // POST Ajax
        if (method == 'GET') {
            let formData = getParamFromArray($(form).serializeArray());
            $.pjax({ container: '#pjax-container', url: formurl + '?' + formData });
            $('.modal').modal('hide');
            Exment.ModalEvent.enableSubmit(button);
            return;
        }
        // Create FormData Object
        var formData = getFormData(form);
        $.ajax({
            url: formurl,
            method: 'POST',
            // data as FormData
            data: formData,
            // Ajax doesn't process data
            processData: false,
            // contentType is false
            contentType: false
        }).done(function (res) {
            if (hasValue(res.body)) {
                Exment.ModalEvent.setBodyHtml(res, button, null);
            }
            else {
                // reomve class and prop
                Exment.ModalEvent.enableSubmit(button);
                Exment.CommonEvent.CallbackExmentAjax(res);
            }
            if (hasValue(res.messages)) {
                ModalEvent.setMessagesOrErrors(res.messages, false);
            }
        }).fail(function (res, textStatus, errorThrown) {
            // reomve class and prop
            Exment.ModalEvent.enableSubmit(button);
            // if not have responseJSON, undefined error
            if (!hasValue(res.responseJSON)) {
                Exment.CommonEvent.UndefinedError();
                return;
            }
            // show toastr
            if (hasValue(res.responseJSON.toastr)) {
                toastr.error(res.responseJSON.toastr);
            }
            // show error message
            if (hasValue(res.responseJSON.errors)) {
                ModalEvent.setMessagesOrErrors(res.responseJSON.errors, true);
            }
            if (hasValue(res.responseJSON.messages)) {
                ModalEvent.setMessagesOrErrors(res.responseJSON.messages, false);
            }
        }).always(function (res) {
        });
        return false;
    };
    Exment.ModalEvent = ModalEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.ModalEvent.AddEvent();
    Exment.ModalEvent.AddEventOnce();
});
