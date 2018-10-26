/// <reference path="../../../../../../Scripts/typings/jquery/jquery.d.ts" />

namespace Exment {
    export class CustomFromEvent {
        public static AddEvent() {
            CustomFromEvent.addDragEvent();
            CustomFromEvent.appendIcheckEvent($('.icheck:visible'));
            $('form').on('submit', CustomFromEvent.ignoreSuggests);
        }

        public static AddEventOnce() {
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

        /**
         * Add All item button event
         */
        private static addAllItems = (ev) => {
            var $block = $(ev.target).closest('.custom_form_column_block_inner');
            var $items = $block.find('.custom_form_column_item:visible'); // ignore template item
            var $target_ul = $block.closest('.box-body').find('.custom_form_column_items').first();
            $items.each(function(index:number, elem:Element){
                $(elem).appendTo($target_ul);
                // show item options, 
                CustomFromEvent.toggleFormColumnItem($(elem), true);
            });
        }

        private static addDragEvent($elem: JQuery<Element>= null) {
            //if (!$elem) {
            // create draagble form
            $('.custom_form_column_suggests.draggables').each(function(index:number, elem:Element){
                var d = $(elem);
                $elem = d.children('.draggable');

                $elem.draggable({
                    // connect to sortable. set only same block
                    connectToSortable: '.' + d.data('connecttosortable') + ' .draggables',
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
                            ui.helper.append($('<input/>', {
                                name: header_name + '[column_no]',
                                value: ui.helper.closest('[data-form_column_no]').data('form_column_no'),
                                'class': 'column_no',
                                type: 'hidden',
                            }));

                            // add icheck event
                            CustomFromEvent.appendIcheckEvent(ui.helper.find('.icheck'));
                        } else {
                            ui.helper.find('.delete,.options').hide();
                        }
                    }
                });
            });
            // add sorable event (only left column)
            $(".custom_form_column_items.draggables")
                .sortable({})
                // add 1to2 or 2to1 draagable event
                .each(function(index:number, elem:Element){
                    var d = $(elem);
                    $elem = d.children('.draggable');

                    $elem.each(function(index2, elem2){
                        CustomFromEvent.setDragItemEvent($(elem2));
                    });
                });
        }

        private static setDragItemEvent($elem, initialize = true){
            // get parent div
            var $div = $elem.parents('.custom_form_column_block');
            // get id name for connectToSortable
            var id = 'ul_' 
                + $div.data('form_block_type') 
                + '_' + $div.data('form_block_target_table_id')
                //+ '_' + ($div.data('form_column_no') == 1 ? 2 : 1);
            if(initialize){
                $elem.draggable({
                    // connect to sortable. set only same block
                    connectToSortable: '.' + id,
                    //cursor: 'move',
                    revert: "invalid",
                    droppable: "drop",
                    stop: (event, ui) => {
                        // reset draageble target
                        CustomFromEvent.setDragItemEvent(ui.helper, false);
                        // set column no
                        ui.helper.find('.column_no').val(ui.helper.closest('[data-form_column_no]').data('form_column_no'));
                    }
                });
            }else{
                $elem.draggable( "option", "connectToSortable", "." + id );
            }
        }

        private static toggleFormColumnItem($elem: JQuery, isShow = true) {
            if(isShow){
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
            }else{
                $elem.find('.delete,.options').hide();
            }

            $('.custom_form_column_suggests.draggables').each(function(index:number, elem:Element){
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
        private static toggleFromBlock = (ev) => {
            var available = $(ev.target).closest('.icheck_toggleblock').prop('checked');
            var $block = $(ev.target).closest('.box-custom_form_block').find('.custom_form_block');
            if (available) {
                $block.show();
            } else {
                $block.hide();
            }
        }

        private static deleteColumn = (ev) => {
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

                // get suggest_form_column_type.
                if(form_column_type == 'system'){
                   var suggest_form_column_type:any = 'column';
                }else{
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
                    var $clone: any = $template.children('li').clone(true);
                    $clone.appendTo($custom_form_column_suggests).show();

                    CustomFromEvent.addDragEvent($clone);
                }
            }
        }

        private static getHeaderName($li: JQuery): string {
            var header_name = $li.closest('.box-custom_form_block').find('.header_name').val() as string;
            var header_column_name = $li.find('.header_column_name').val() as string;
            return header_name + header_column_name;
        }

        private static ignoreSuggests = () => {
            $('.custom_form_column_suggests,.template_item_block').find('input,textarea,select').attr('disabled', 'disabled');
            return true;
        }

        private static appendIcheckEvent($elem: JQuery) {
            if (!$elem.data('ichecked')) {
                $elem.iCheck({ checkboxClass: 'icheckbox_minimal-blue' });
                $elem.data('ichecked', true);
            }
        }

        private static changedataModalEvent = (ev) => {
            // get target header_column_name
            var $target_li = $(ev.target).closest('.custom_form_column_item');
            var target_header_column_name = $target_li.data('header_column_name');
            var $block = $target_li.closest('.box-custom_form_block');

            // get default value
            var changedata_target_column_id = $target_li.find('.changedata_target_column_id').val();
            var changedata_column_id = $target_li.find('.changedata_column_id').val();

            // get select target columns in target table columns
            var select_table_columns = JSON.parse($block.find('.select-table-columns').val() as string);
            $('.changedata_target_column,.changedata_column').children('option').remove();
            $('.changedata_target_column').append($('<option>').val('').text(''));
            $.each(select_table_columns, function (value, name) {
                var $option = $('<option>')
                    .val(value as string)
                    .text(name)
                    .prop('selected', changedata_target_column_id == value);
                    $('.changedata_target_column').append($option);
            });
            $('#form-changedata-modal').find('.target_header_column_name').val(target_header_column_name);

            // check default changedata_target_column_id value
            if(hasValue(changedata_target_column_id)){
                //hasValue, get changedataColumns, then open modal
                $.when(CustomFromEvent.changedataColumnEvent(changedata_target_column_id, changedata_column_id))
                    .then(function(){
                        $('#form-changedata-modal').modal('show');
                    })
            }
            // not default value
            else{
                $('#form-changedata-modal').modal('show');
            }
        }

        private static changedataColumnEvent = (ev:any, changedata_column_id?) => {
            var $d = $.Deferred();
            // get custom_column_id
            // when changed changedata_target_column 
            if(typeof ev.target != "undefined"){
                var custom_column_id:any = $(ev.target).val();
            }
            // else, selected id
            else{
                var custom_column_id:any = ev;
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
                        .val(value as string)
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
        }

        /**
         * Reset changedata Setting
         */
        private static changedataReset = (ev) => {
            // get target_header_column_name for updating.
            var $target_li = CustomFromEvent.getModalTargetLi();
            
            // data setting and show message
            $target_li.find('.changedata_target_column_id').val('');
            $target_li.find('.changedata_column_id').val('');
            $target_li.find('.changedata_available').hide();

            $('#form-changedata-modal').modal('hide');
        }
        /**
         * Settng changedata Setting
         */
        private static changedataSetting = (ev) => {
            // get target_header_column_name for updating.
            var $target_li = CustomFromEvent.getModalTargetLi();
            
            // data setting and show message
            $target_li.find('.changedata_target_column_id').val($('.changedata_target_column').val());
            $target_li.find('.changedata_column_id').val($('.changedata_column').val());
            $target_li.find('.changedata_available').show();

            $('#form-changedata-modal').modal('hide');
        }

        private static getModalTargetLi(){
            // get target_header_column_name for updating.
            var target_header_column_name = $('#form-changedata-modal').find('.target_header_column_name').val();
            var $target_li = $('[data-header_column_name="' + target_header_column_name + '"]');

            return $target_li;
        }
    }
}
$(function () {
    Exment.CustomFromEvent.AddEvent();
    Exment.CustomFromEvent.AddEventOnce();
});
