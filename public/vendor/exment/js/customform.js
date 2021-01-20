var Exment;
(function (Exment) {
    class CustomFromEvent {
        static AddEvent() {
            $('.box-custom_form_block').on('ifChanged check', '.icheck_toggleblock', {}, CustomFromEvent.toggleFromBlock);
            $('.box-custom_form_block').on('click.exment_custom_form', '.delete', {}, CustomFromEvent.deleteColumn);
            $('.box-custom_form_block').on('click.exment_custom_form', '.setting', {}, CustomFromEvent.settingModalEvent);
            $('.box-custom_form_block').on('click.exment_custom_form', '.btn-addallitems', {}, CustomFromEvent.addAllItems);
            $(document).off('change.exment_custom_form', '.changedata_target_column_id').on('change.exment_custom_form', '.changedata_target_column_id', {}, CustomFromEvent.changedataColumnEvent);
            $(document).off('click.exment_custom_form', '#modal-showmodal .modal-customform .modal-submit').on('click.exment_custom_form', '#modal-showmodal .modal-customform .modal-submit', {}, CustomFromEvent.settingModalSetting);
            CustomFromEvent.addDragEvent();
            CustomFromEvent.appendSwitchEvent($('.la_checkbox:visible'));
            $('form').on('submit', CustomFromEvent.formSubmitEvent);
        }
        static AddEventOnce() {
            $(document).on('pjax:complete', function (event) {
                CustomFromEvent.AddEvent();
            });
        }
        static addDragEvent($elem = null) {
            //if (!$elem) {
            // create draagble form
            $('.custom_form_column_suggests.draggables').each(function (index, elem) {
                var d = $(elem);
                $elem = d.children('.draggable');
                $elem.draggable({
                    // connect to sortable. set only same block
                    connectToSortable: '.' + d.data('connecttosortable') + ' .draggables',
                    //cursor: 'move',
                    helper: d.data('draggable_clone') ? 'clone' : '',
                    revert: "invalid",
                    droppable: "drop",
                    distance: 40,
                    stop: (event, ui) => {
                        var $ul = ui.helper.closest('.draggables');
                        // if moved to "custom_form_column_items"(for form) ul, show delete button and open detail.
                        if ($ul.hasClass('custom_form_column_items')) {
                            CustomFromEvent.toggleConfigIcon(ui.helper, true);
                            // add hidden form
                            var header_name = CustomFromEvent.getHeaderName(ui.helper);
                            ui.helper.append($('<input/>', {
                                name: header_name + '[form_column_target_id]',
                                value: ui.helper.find('.form_column_target_id').val(),
                                type: 'hidden',
                            }));
                            ui.helper.append($('<input/>', {
                                name: header_name + '[form_column_type]',
                                value: ui.helper.find('.form_column_type').val(),
                                type: 'hidden',
                            }));
                            ui.helper.append($('<input/>', {
                                name: header_name + '[required]',
                                value: ui.helper.find('.required').val(),
                                type: 'hidden',
                            }));
                            ui.helper.append($('<input/>', {
                                name: header_name + '[column_no]',
                                value: ui.helper.closest('[data-form_column_no]').data('form_column_no'),
                                'class': 'column_no',
                                type: 'hidden',
                            }));
                            // rename for toggle
                            if (hasValue(ui.helper.find('[data-toggle]'))) {
                                let uuid = getUuid();
                                ui.helper.find('[data-parent]')
                                    .attr('data-parent', '#' + uuid)
                                    .attr('href', '#' + uuid);
                                ui.helper.find('.panel-collapse').prop('id', uuid);
                            }
                            // replace html name(for clone object)
                            CustomFromEvent.replaceCloneColumnName(ui.helper);
                        }
                        else {
                            CustomFromEvent.toggleConfigIcon(ui.helper, false);
                        }
                    }
                });
            });
            // add sorable event (only left column)
            $(".custom_form_column_items.draggables")
                .sortable({
                distance: 40,
            })
                // add 1to2 or 2to1 draagable event
                .each(function (index, elem) {
                var d = $(elem);
                $elem = d.children('.draggable');
                $elem.each(function (index2, elem2) {
                    CustomFromEvent.setDragItemEvent($(elem2));
                });
            });
        }
        static setDragItemEvent($elem, initialize = true) {
            // get parent div
            var $div = $elem.parents('.custom_form_column_block');
            // get id name for connectToSortable
            var id = 'ul_'
                + $div.data('form_block_type')
                + '_' + $div.data('form_block_target_table_id');
            //+ '_' + ($div.data('form_column_no') == 1 ? 2 : 1);
            if (initialize) {
                $elem.draggable({
                    // connect to sortable. set only same block
                    connectToSortable: '.' + id,
                    //cursor: 'move',
                    revert: "invalid",
                    droppable: "drop",
                    distance: 40,
                    stop: (event, ui) => {
                        // reset draageble target
                        CustomFromEvent.setDragItemEvent(ui.helper, false);
                        // set column no
                        ui.helper.find('.column_no').val(ui.helper.closest('[data-form_column_no]').data('form_column_no'));
                    }
                });
            }
            else {
                $elem.draggable("option", "connectToSortable", "." + id);
            }
        }
        static toggleConfigIcon($elem, isShow) {
            if (isShow) {
                $elem.find('.delete,.options,[data-toggle],.setting').show();
            }
            else {
                $elem.find('.delete,.options,[data-toggle],.setting').hide();
            }
        }
        static toggleFormColumnItem($elem, isShow = true) {
            CustomFromEvent.toggleConfigIcon($elem, isShow);
            if (isShow) {
                // add hidden form
                var header_name = CustomFromEvent.getHeaderName($elem);
                $elem.append($('<input/>', {
                    name: header_name + '[form_column_target_id]',
                    value: $elem.find('.form_column_target_id').val(),
                    type: 'hidden',
                }));
                $elem.append($('<input/>', {
                    name: header_name + '[form_column_type]',
                    value: $elem.find('.form_column_type').val(),
                    type: 'hidden',
                }));
                CustomFromEvent.setDragItemEvent($elem);
            }
        }
        static getHeaderName($li) {
            var header_name = $li.closest('.box-custom_form_block').find('.header_name').val();
            var header_column_name = $li.find('.header_column_name').val();
            return header_name + header_column_name;
        }
        static appendSwitchEvent($elem) {
            $elem.each(function (index, elem) {
                var $e = $(elem);
                $e.bootstrapSwitch({
                    size: 'small',
                    onText: 'YES',
                    offText: 'NO',
                    onColor: 'primary',
                    offColor: 'default',
                    onSwitchChange: function (event, state) {
                        $(event.target).closest('.bootstrap-switch').next().val(state ? '1' : '0').change();
                    }
                });
            });
        }
        /**
         * Replace clone suggest li name.
         * @param $li
         */
        static replaceCloneColumnName($li) {
            let replaceHeaderName = $li.data('header_column_name');
            let $replaceLi = $li.parents('.custom_form_block')
                .find('.custom_form_column_suggests')
                .find('.custom_form_column_item[data-header_column_name="' + replaceHeaderName + '"]');
            if ($replaceLi.length == 0) {
                return;
            }
            // get "NEW__" string
            let newCode = replaceHeaderName.match(/NEW__.{8}-.{4}-.{4}-.{4}-.{12}/);
            if (!newCode) {
                return;
            }
            // set replaced name
            let updateCode = 'NEW__' + getUuid();
            // replace inner
            let html = $replaceLi.html();
            html = html.replace(new RegExp(newCode[0], "g"), updateCode);
            $replaceLi.html(html);
            // replace li id and header_column_name
            let newHeaderName = replaceHeaderName.replace(new RegExp(newCode[0], "g"), updateCode);
            $replaceLi.attr('data-header_column_name', newHeaderName);
            $replaceLi.attr('id', newHeaderName);
        }
    }
    /**
     * Add All item button event
     */
    CustomFromEvent.addAllItems = (ev) => {
        var $block = $(ev.target).closest('.custom_form_column_block_inner');
        var $items = $block.find('.custom_form_column_item:visible'); // ignore template item
        var $target_ul = $block.closest('.box-body').find('.custom_form_column_items').first();
        $items.each(function (index, elem) {
            $(elem).appendTo($target_ul);
            // show item options, 
            CustomFromEvent.toggleFormColumnItem($(elem), true);
        });
    };
    CustomFromEvent.toggleFromBlock = (ev) => {
        ev.preventDefault();
        var available = $(ev.target).closest('.icheck_toggleblock').prop('checked');
        var $block = $(ev.target).closest('.box-custom_form_block').find('.custom_form_block');
        if (available) {
            $block.show();
        }
        else {
            $block.hide();
        }
    };
    CustomFromEvent.deleteColumn = (ev) => {
        ev.preventDefault();
        var item = $(ev.target).closest('.custom_form_column_item');
        if (item.hasClass('deleting')) {
            return;
        }
        item.addClass('deleting');
        var header_name = CustomFromEvent.getHeaderName(item);
        // Add delete flg
        item.append($('<input/>', {
            type: 'hidden',
            name: header_name + '[delete_flg]',
            value: 1
        }));
        item.fadeOut();
        if (item.find('.form_column_type').val() != '99') {
            var form_column_type = item.find('.form_column_type').val();
            var form_column_target_id = item.find('.form_column_target_id').val();
            var form_block_type = item.closest('.custom_form_column_block').data('form_block_type');
            var form_block_target_table_id = item.closest('.custom_form_column_block').data('form_block_target_table_id');
            // get suggest_form_column_type.
            if (form_column_type == '1') {
                var suggest_form_column_type = '0';
            }
            else {
                suggest_form_column_type = form_column_type;
            }
            // get target suggest div area.
            var $custom_form_block_target = $('.custom_form_column_block')
                .filter('[data-form_block_type="' + form_block_type + '"]')
                .filter('[data-form_block_target_table_id="' + form_block_target_table_id + '"]');
            var $custom_form_column_suggests = $custom_form_block_target
                .find('.custom_form_column_suggests')
                .filter('[data-form_column_type="' + suggest_form_column_type + '"]');
            // find the same value hidden in suggest ul.
            var $template = $custom_form_block_target.find('[data-form_column_target_id="' + form_column_target_id + '"]')
                .filter('[data-form_column_type="' + form_column_type + '"]');
            if ($template) {
                var $clone = $template.children('li').clone(true);
                $clone.appendTo($custom_form_column_suggests).show();
                CustomFromEvent.addDragEvent($clone);
            }
        }
    };
    CustomFromEvent.formSubmitEvent = () => {
        // loop "custom_form_block_available" is 1
        let hasRequire = false;
        if (!$('form.custom_form_form').hasClass('confirmed')) {
            $('.custom_form_block_available').each(function (index, elem) {
                // if elem's value is not 1, continue.
                if (!pBool($(elem).val())) {
                    return true;
                }
                // if not check, continue
                if ($(elem).is(':checkbox') && !$(elem).is(':checked')) {
                    return true;
                }
                let $suggests = $(elem).parents('.box-custom_form_block').find('.custom_form_column_suggests li');
                // if required value is 1, hasRequire is true and break
                $suggests.each(function (i, e) {
                    if ($(e).find('.required').val() == '1') {
                        hasRequire = true;
                        return false;
                    }
                });
            });
        }
        if (!hasRequire) {
            CustomFromEvent.ignoreSuggests();
            return true;
        }
        // if has require, show swal
        Exment.CommonEvent.ShowSwal(null, {
            title: $('#cofirm_required_title').val(),
            text: $('#cofirm_required_text').val(),
            confirmCallback: function (result) {
                console.log(result);
                if (pBool(result.value)) {
                    $('form.custom_form_form').addClass('confirmed').submit();
                }
            },
        });
        return false;
    };
    CustomFromEvent.ignoreSuggests = () => {
        $('.custom_form_column_suggests,.template_item_block').find('input,textarea,select,file').attr('disabled', 'disabled');
        return true;
    };
    CustomFromEvent.changedataColumnEvent = (ev, changedata_column_id) => {
        var $d = $.Deferred();
        // get custom_column_id
        // when changed changedata_target_column 
        if (typeof ev.target != "undefined") {
            var custom_column_id = $(ev.target).val();
        }
        // else, selected id
        else {
            var custom_column_id = ev;
        }
        if (!hasValue(custom_column_id)) {
            $('.changedata_column_id').children('option').remove();
            $d.resolve();
        }
        else {
            $.ajax({
                url: admin_url(URLJoin('webapi', 'target_table', 'columns', custom_column_id)),
                type: 'GET'
            })
                .done(function (data) {
                $('.changedata_column_id').children('option').remove();
                $('.changedata_column_id').append($('<option>').val('').text(''));
                $.each(data, function (value, name) {
                    var $option = $('<option>')
                        .val(value)
                        .text(name)
                        .prop('selected', changedata_column_id == value);
                    $('.changedata_column_id').append($option);
                });
                $d.resolve();
            })
                .fail(function (data) {
                console.log(data);
                $d.reject();
            });
        }
        return $d.promise();
    };
    CustomFromEvent.settingModalEvent = (ev) => {
        let formItem = Exment.CustomFromItem.makeByHidden($(ev.target).closest('.custom_form_column_item'));
        formItem.showSettingModal($(ev.target).closest('.setting'));
    };
    /**
     * Settng modal Setting
     */
    CustomFromEvent.settingModalSetting = (ev) => {
        ev.preventDefault();
        let formItem = Exment.CustomFromItem.makeByModal();
        let options = formItem.getOption();
        let $modal = $('#modal-showmodal');
        // get target_header_column_name for updating.
        let widgetmodal_uuid = $modal.find('.widgetmodal_uuid').val();
        let $target_li = $('[data-widgetmodal_uuid="' + widgetmodal_uuid + '"]').closest('.custom_form_column_item');
        // data setting and show message
        $target_li.find('.options').val(JSON.stringify(options));
        // move image event
        let header_name = CustomFromEvent.getHeaderName($target_li);
        $target_li.find('.image').remove();
        $modal.find('.image').appendTo($target_li).prop('name', header_name + '[options][image]').hide();
        $modal.modal('hide');
    };
    Exment.CustomFromEvent = CustomFromEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CustomFromEvent.AddEvent();
    Exment.CustomFromEvent.AddEventOnce();
});
