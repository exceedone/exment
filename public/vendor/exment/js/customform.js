/// <reference path="../../../../../../Scripts/typings/jquery/jquery.d.ts" />
var Exment;
(function (Exment) {
    class CustomFromEvent {
        static AddEvent() {
            CustomFromEvent.addDragEvent();
            CustomFromEvent.appendIcheckEvent($('.icheck:visible'));
            $('form').on('submit', CustomFromEvent.ignoreSuggests);
        }
        static AddEventOnce() {
            $(document).on('ifChanged check', '.icheck_toggleblock', {}, CustomFromEvent.toggleFromBlock);
            $(document).on('click', '.delete', {}, CustomFromEvent.deleteColumn);
            $(document).on('click', '.btn-addallitems', {}, CustomFromEvent.addAllItems);
            $(document).on('click', '.changedata-modal', {}, CustomFromEvent.changedataModalEvent);
            $(document).on('change', '.changedata_target_column', {}, CustomFromEvent.changedataColumnEvent);
            $(document).on('click', '#changedata-button-setting', {}, CustomFromEvent.changedataSetting);
            $(document).on('click', '#changedata-button-reset', {}, CustomFromEvent.changedataReset);
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
                    connectToSortable: '#' + d.data('connecttosortable') + ' .draggables',
                    //cursor: 'move',
                    helper: d.data('draggable_clone') ? 'clone' : '',
                    revert: "invalid",
                    droppable: "drop",
                    stop: (event, ui) => {
                        var $ul = ui.helper.closest('.draggables');
                        // if moved to "custom_form_column_items"(for form) ul, show delete button and open detail.
                        if ($ul.hasClass('custom_form_column_items')) {
                            ui.helper.find('.delete,.options').show();
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
                            // add icheck event
                            CustomFromEvent.appendIcheckEvent(ui.helper.find('.icheck'));
                            // replace header full name.
                            //ui.helper.html(ui.helper.html().replace(new RegExp(header_name + '[custom_form_columns][]', 'g'), header_name + '[custom_form_columns][' + 'aaaa' + ']'));
                        }
                        else {
                            ui.helper.find('.delete,.options').hide();
                        }
                    }
                });
            });
            // add sorable event (only left column)
            $(".custom_form_column_items.draggables").sortable({});
        }
        static toggleFormColumnItem($elem, isShow = true) {
            if (isShow) {
                $elem.find('.delete,.options').show();
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
                // add icheck event
                CustomFromEvent.appendIcheckEvent($elem.find('.icheck'));
            }
            else {
                $elem.find('.delete,.options').hide();
            }
            $('.custom_form_column_suggests.draggables').each(function (index, elem) {
                var d = $(elem);
                $elem = d.children('.draggable');
                $elem.draggable({
                    // connect to sortable. set only same block
                    connectToSortable: '#' + d.data('connecttosortable') + ' .draggables',
                    //cursor: 'move',
                    helper: d.data('draggable_clone') ? 'clone' : '',
                    revert: "invalid",
                    droppable: "drop",
                    stop: (event, ui) => {
                        var $ul = ui.helper.closest('.draggables');
                        // if moved to "custom_form_column_items"(for form) ul, show delete button and open detail.
                        CustomFromEvent.toggleFormColumnItem(ui.helper, $ul.hasClass('custom_form_column_items'));
                    }
                });
            });
            // add sorable event (only left column)
            $(".custom_form_column_items.draggables").sortable({});
        }
        static getHeaderName($li) {
            var header_name = $li.closest('.box-custom_form_block').find('.header_name').val();
            var header_column_name = $li.find('.header_column_name').val();
            return header_name + header_column_name;
        }
        static appendIcheckEvent($elem) {
            if (!$elem.data('ichecked')) {
                $elem.iCheck({ checkboxClass: 'icheckbox_minimal-blue' });
                $elem.data('ichecked', true);
            }
        }
        static getModalTargetLi() {
            // get target_header_column_name for updating.
            var target_header_column_name = $('#form-changedata-modal').find('.target_header_column_name').val();
            var $target_li = $('[data-header_column_name="' + target_header_column_name + '"]');
            return $target_li;
        }
    }
    /**
     * Add All item button event
     */
    CustomFromEvent.addAllItems = (ev) => {
        var $block = $(ev.target).closest('.custom_form_column_block_inner');
        var $items = $block.find('.custom_form_column_item:visible'); // ignore template item
        var $target_ul = $block.closest('.box-body').find('.custom_form_column_items');
        $items.each(function (index, elem) {
            $(elem).appendTo($target_ul);
            // show item options, 
            CustomFromEvent.toggleFormColumnItem($(elem), true);
        });
    };
    CustomFromEvent.toggleFromBlock = (ev) => {
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
        var item = $(ev.target).closest('.custom_form_column_item');
        var header_name = CustomFromEvent.getHeaderName(item);
        // Add delete flg
        item.append($('<input/>', {
            type: 'hidden',
            name: header_name + '[delete_flg]',
            value: 1
        }));
        item.fadeOut();
        if (item.find('.form_column_type').val() != 'other') {
            var form_column_type = item.find('.form_column_type').val();
            var form_column_target_id = item.find('.form_column_target_id').val();
            var form_block_type = item.closest('.custom_form_column_block').data('form_block_type');
            var form_block_target_table_id = item.closest('.custom_form_column_block').data('form_block_target_table_id');
            var $custom_form_column_suggests = $('.custom_form_column_block')
                .filter('[data-form_block_type="' + form_block_type + '"]')
                .filter('[data-form_block_target_table_id="' + form_block_target_table_id + '"]')
                .find('.custom_form_column_suggests')
                .filter('[data-form_column_type="' + form_column_type + '"]');
            // find the same value hidden in suggest ul.
            var $template = $('[data-form_column_target_id="' + form_column_target_id + '"]')
                .filter('[data-form_column_type="' + form_column_type + '"]');
            if ($template) {
                var $clone = $template.children('li').clone(true);
                $clone.appendTo($custom_form_column_suggests).show();
                CustomFromEvent.addDragEvent($clone);
            }
        }
    };
    CustomFromEvent.ignoreSuggests = () => {
        $('.custom_form_column_suggests,.template_item_block').find('input,textarea,select').attr('disabled', 'disabled');
        return true;
    };
    CustomFromEvent.changedataModalEvent = (ev) => {
        // get target header_column_name
        var $target_li = $(ev.target).closest('.custom_form_column_item');
        var target_header_column_name = $target_li.data('header_column_name');
        var $block = $target_li.closest('.box-custom_form_block');
        // get default value
        var changedata_target_column_id = $target_li.find('.changedata_target_column_id').val();
        var changedata_column_id = $target_li.find('.changedata_column_id').val();
        // get select target columns in target table columns
        var select_table_columns = JSON.parse($block.find('.select-table-columns').val());
        $('.changedata_target_column,.changedata_column').children('option').remove();
        $('.changedata_target_column').append($('<option>').val('').text(''));
        $.each(select_table_columns, function (value, name) {
            var $option = $('<option>')
                .val(value)
                .text(name)
                .prop('selected', changedata_target_column_id == value);
            $('.changedata_target_column').append($option);
        });
        $('#form-changedata-modal').find('.target_header_column_name').val(target_header_column_name);
        // check default changedata_target_column_id value
        if (hasValue(changedata_target_column_id)) {
            //hasValue, get changedataColumns, then open modal
            $.when(CustomFromEvent.changedataColumnEvent(changedata_target_column_id, changedata_column_id))
                .then(function () {
                $('#form-changedata-modal').modal('show');
            });
        }
        // not default value
        else {
            $('#form-changedata-modal').modal('show');
        }
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
        $.ajax({
            url: admin_base_path('/api/target_table/columns/' + custom_column_id),
            type: 'GET'
        })
            .done(function (data) {
            $('.changedata_column').children('option').remove();
            $('.changedata_column').append($('<option>').val('').text(''));
            $.each(data, function (value, name) {
                var $option = $('<option>')
                    .val(value)
                    .text(name)
                    .prop('selected', changedata_column_id == value);
                $('.changedata_column').append($option);
            });
            $d.resolve();
        })
            .fail(function (data) {
            console.log(data);
            $d.reject();
        });
        return $d.promise();
    };
    /**
     * Reset changedata Setting
     */
    CustomFromEvent.changedataReset = (ev) => {
        // get target_header_column_name for updating.
        var $target_li = CustomFromEvent.getModalTargetLi();
        // data setting and show message
        $target_li.find('.changedata_target_column_id').val('');
        $target_li.find('.changedata_column_id').val('');
        $target_li.find('.changedata_available').hide();
        $('#form-changedata-modal').modal('hide');
    };
    /**
     * Settng changedata Setting
     */
    CustomFromEvent.changedataSetting = (ev) => {
        // get target_header_column_name for updating.
        var $target_li = CustomFromEvent.getModalTargetLi();
        // data setting and show message
        $target_li.find('.changedata_target_column_id').val($('.changedata_target_column').val());
        $target_li.find('.changedata_column_id').val($('.changedata_column').val());
        $target_li.find('.changedata_available').show();
        $('#form-changedata-modal').modal('hide');
    };
    Exment.CustomFromEvent = CustomFromEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CustomFromEvent.AddEvent();
    Exment.CustomFromEvent.AddEventOnce();
});
