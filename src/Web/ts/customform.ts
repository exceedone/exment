
namespace Exment {
    export class CustomFromEvent {
        public static AddEvent() {
            
            $('#custom_form_form').off('submit.exment_custom_form').on('submit.exment_custom_form', CustomFromEvent.formSubmitEvent);

            CustomFromEvent.loadingEvent();
            CustomFromEvent.resizeEvent($('.custom_form_area:visible'));
        }

        public static AddEventOnce() {
            $(document).on('ifChanged check', '.box-custom_form_block .icheck_toggleblock', {}, CustomFromEvent.toggleFromBlock);
            $(document).on('click.exment_custom_form', '.box-custom_form_block .custom_form_column_item .delete', {}, CustomFromEvent.deleteColumnEvent);
            $(document).on('click.exment_custom_form', '.box-custom_form_block .custom_form_column_item .setting', {}, CustomFromEvent.settingModalEvent);
            
            $(document).on('click.exment_custom_form', '.box-custom_form_block .custom_form_area_header .delete', {}, CustomFromEvent.deleteBoxEvent);
            
            $(document).on('click.exment_custom_form', '.box-custom_form_block .btn-addallitems', {}, CustomFromEvent.addAllItems);           
            $(document).on('click.exment_custom_form', '.box-custom_form_block .addbutton_button', {}, CustomFromEvent.addAreaButtonEvent);

            $(document).on('change.exment_custom_form', '.box-custom_form_block .changedata_target_column_id', {}, CustomFromEvent.changedataColumnEvent);
            $(document).on('click.exment_custom_form', '#modal-showmodal .modal-customform .modal-submit', {}, CustomFromEvent.settingModalSetting);
            $(document).on('click.exment_custom_form', '.preview-custom_form', {}, CustomFromEvent.previewCustomForm);

            $(document).on('pjax:complete', function (event) {
                CustomFromEvent.AddEvent();
            });
        }

        
        /**
         * Call loading event
         */
        private static loadingEvent() {
            // Add drag item event
            $('.custom_form_column_items .draggables').each(function(index:number, elem:Element){
                CustomFromEvent.addDragItemEvent($(elem).children('.draggable'));
            });
            $('.custom_form_column_suggests .draggables').each(function(index:number, elem:Element){
                CustomFromEvent.addDragSuggestEvent($(elem).children('.draggable'));
            });
        }


        /**
         * Append event for setted item, for loading display.
         * @param $draggable item area list
         */
        public static addDragItemEvent($draggable: JQuery<Element>){
            let $draggables = $draggable.closest('.draggables');
            let connectToSortable = '.' + $draggables.data('connecttosortable') + ' .draggables';

            // destory first, for dragged from suggest.
            //$draggable.draggable('destroy');

            // set event for fix area   
            $draggable.draggable({
                // connect to sortable. set only same block
                connectToSortable: connectToSortable,
                //cursor: 'move',
                revert: "invalid",
                droppable: "drop",
                distance: 40,
                start: (event, ui) => {
                    // reset draageble target
                    ui.helper.addClass('moving');
                },
                stop: (event, ui) => {
                    // reset draageble target
                    CustomFromEvent.setMovedEvent(ui.helper);
                    ui.helper.removeClass('moving');
                },
            });
        }


        /**
         * Append event for suggest item, for loading display.
         * @param $draggable suggest area list
         */
        public static addDragSuggestEvent($draggable: JQuery<Element>){
            let $draggables = $draggable.closest('.draggables');
            let connectToSortable = '.' + $draggables.data('connecttosortable') + ' .draggables';

            $draggable.draggable({
                // connect to sortable. set only same block
                // and filter not draggable_setted
                connectToSortable: connectToSortable,
                helper: $draggables.closest('[data-draggable_clone]').data('draggable_clone') ? 'clone' : '',
                revert: "invalid",
                droppable: "drop",
                distance: 40,
                start: (event, ui) => {
                    // reset draageble target
                    ui.helper.addClass('moving');
                },
                stop: (event, ui) => {
                    ui.helper.removeClass('moving');
                    // if moved to "custom_form_column_items"(for form) ul, show delete button and open detail.
                    if (ui.helper.closest('.custom_form_column_items').length > 0) {
                        CustomFromEvent.setMovedEvent(ui.helper);
                        CustomFromEvent.addDragItemEvent(ui.helper.closest('.draggable'));
                    }
                }
            });

            CustomFromEvent.addSortableEvent($draggable);
        }
        

        /**
         * Append event for suggest item, for loading display.
         * @param $draggable suggest area list
         */
        public static addSortableEvent($draggable: JQuery<Element>){
            let $draggables = $draggable.closest('.draggables');
            let connectToSortable = '.' + $draggables.data('connecttosortable') + ' .draggables';
            $(connectToSortable)
                .not('.added-sortable')
                .sortable({
                    distance: 40,
                }).each(function(index:number, elem:Element){
                    let d = $(elem);
                    let $draggable = d.children('.draggable');
                    $draggable.each(function(index2, elem2){
                        //CustomFromEvent.setDragItemEvent($(elem2));
                    });
                    
                    d.addClass('added-sortable');
                });
        }
        

        /**
         * Set event after dragged erea.
         */
        private static setMovedEvent($elem: JQuery<Element>){
            CustomFromEvent.toggleConfigIcon($elem, true);
            // add hidden form
            let header_name = CustomFromEvent.getHeaderName($elem);
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
            $elem.append($('<input/>', {
                name: header_name + '[required]',
                value: $elem.find('.required_item').val(), // if name 'required', validation wrong call.
                type: 'hidden',
            }));
            $elem.append($('<input/>', {
                name: header_name + '[row_no]',
                value: $elem.closest('[data-row_no]').data('row_no'),
                'class': 'row_no',
                type: 'hidden',
            }));
            $elem.append($('<input/>', {
                name: header_name + '[column_no]',
                value: $elem.closest('[data-column_no]').data('column_no'),
                'class': 'column_no',
                type: 'hidden',
            }));
            $elem.append($('<input/>', {
                name: header_name + '[width]',
                value: $elem.closest('[data-width]').data('width'),
                'class': 'width',
                type: 'hidden',
            }));

            // rename for toggle
            if(hasValue($elem.find('[data-toggle]'))){
                let uuid = getUuid();
                $elem.find('[data-parent]')
                    .attr('data-parent', '#' + uuid)
                    .attr('href', '#' + uuid);
                $elem.find('.panel-collapse').prop('id', uuid);
            }

            // replace html name(for clone object)
            CustomFromEvent.replaceCloneColumnName($elem);
        }


        private static addAreaButtonEvent = (ev) => {
            let $button = $(ev.target).closest('.addbutton_button');

            let $copy: JQuery<HTMLElement> = null;
            $copy = $button.closest('.box-custom_form_block').find('.template_item_column .custom_form_area').clone(true);
            $button.closest('.addbutton_block').before($copy);

            // update data row and column no
            CustomFromEvent.updateAreaRowNo($copy);
            CustomFromEvent.updateAreaColumnNo($copy);

            // toggle plus button
            CustomFromEvent.togglePlusButton($button);

            CustomFromEvent.appendRow($copy);

            CustomFromEvent.resizeEvent($copy);

            CustomFromEvent.addSortableEvent($copy.find('.draggables'));
        }

        
        private static togglePlusButton($button: JQuery<HTMLElement>)
        {
            let $items = $button.closest('.row').children('.custom_form_area:visible');
            // calc size
            let allWidth = 0;
            $items.each(function(index, element){
                allWidth += $(element).find('[data-width]').data('width');
            });

            if(allWidth >= 4){
                $button.closest('.addbutton_block').hide();
            }
            else{
                $button.closest('.addbutton_block').show();
            }
        }

        /**
         * Update row no. area and each items
         * @param $elem 
         */
        private static updateAreaRowNo($elem: JQuery<HTMLElement>)
        {
            // update data row and column no
            let row = $elem.closest('.custom_form_column_items').children('.row:visible').index($elem.closest('.row')) + 1;
            $elem.find('.draggables').data('row_no', row);

            // update items row no
            $elem.find('.row_no').val(row);
        }
        
        /**
         * Update column no. area and each items
         * @param $elem 
         */
        private static updateAreaColumnNo($elem: JQuery<HTMLElement>)
        {
            // update data row and column no
            let column = $elem.closest('.row').children('.custom_form_area:visible').index($elem.closest('.custom_form_area')) + 1;
            $elem.find('.draggables').data('column_no', column);

            // update items column no
            $elem.find('.column_no').val(column);
        }
        
        /**
         * Update width no. each items.
         * @param $elem 
         */
        private static updateAreaWidth($elem: JQuery<HTMLElement>)
        {
            // update data row and column no
            let $custom_form_area = $elem.closest('.custom_form_area');

            let width = $custom_form_area.data('grid_column') / 3;
            $custom_form_area.find('.draggables').data('width', width);

            // update items column no
            $elem.find('.width').val(width);
        }


        private static appendRow($copy){
            if($copy.find('[data-column_no]').data('column_no') != 1){
                return;
            }
            let $rowcopy = $('.template_item_row .row').clone(true);
            
            $copy.closest('.custom_form_column_items').append($rowcopy);
        }
        

        /**
         * Add All item button event
         */
        private static addAllItems = (ev) => {
            let $block = $(ev.target).closest('.custom_form_column_block_inner');
            let $items = $block.find('.custom_form_column_item:visible'); // ignore template item
            let $target_ul = $block.closest('.box-body').find('.custom_form_column_items .draggables').first();
            $items.each(function(index:number, elem:Element){
                $(elem).appendTo($target_ul);
                // show item options, 
                CustomFromEvent.setMovedEvent($(elem));
                //CustomFromEvent.toggleFormColumnItem($(elem), true);
            });
        }


        // private static setDragItemEvent($elem, initialize = true){
        //     // get parent div
        //     var $div = $elem.parents('.custom_form_column_block');
        //     // get id name for connectToSortable
        //     var id = 'ul_' 
        //         + $div.data('form_block_type') 
        //         + '_' + $div.data('form_block_target_table_id')
        //         //+ '_' + ($div.data('form_column_no') == 1 ? 2 : 1);
        //     if(initialize){
        //         $elem.draggable({
        //             // connect to sortable. set only same block
        //             connectToSortable: '.' + id,
        //             //cursor: 'move',
        //             revert: "invalid",
        //             droppable: "drop",
        //             distance: 40,
        //             stop: (event, ui) => {
        //                 // reset draageble target
        //                 CustomFromEvent.setDragItemEvent(ui.helper, false);
        //                 // set column no
        //                 ui.helper.find('.column_no').val(ui.helper.closest('[data-form_column_no]').data('form_column_no'));
        //             }
        //         });
        //     }else{
        //         $elem.draggable( "option", "connectToSortable", "." + id );
        //     }
        // }


        private static toggleConfigIcon($elem: JQuery<Element>, isShow:boolean){
            if(isShow){
                $elem.find('.delete,.options,[data-toggle],.setting').show();
            }else{
                $elem.find('.delete,.options,[data-toggle],.setting').hide();
            }
        }

        // private static toggleFormColumnItem($elem: JQuery<Element>, isShow = true) {
        //     CustomFromEvent.toggleConfigIcon($elem, isShow);

        //     if(isShow){
        //         // add hidden form
        //         var header_name = CustomFromEvent.getHeaderName($elem);
        //         $elem.append($('<input/>', {
        //             name: header_name + '[form_column_target_id]',
        //             value: $elem.find('.form_column_target_id').val(),
        //             type: 'hidden',
        //         }));
        //         $elem.append($('<input/>', {
        //             name: header_name + '[form_column_type]',
        //             value: $elem.find('.form_column_type').val(),
        //             type: 'hidden',
        //         }));
        //         CustomFromEvent.setDragItemEvent($elem);
        //     }
        // }
        
        private static toggleFromBlock = (ev) => {
            ev.preventDefault();
            
            let available = $(ev.target).closest('.icheck_toggleblock').prop('checked');
            let $block = $(ev.target).closest('.box-custom_form_block').find('.custom_form_block');
            if (available) {
                $block.show();
            } else {
                $block.hide();
            }
        }


        /**
         * delete form column
         * @param ev 
         */
        private static deleteColumnEvent = (ev) => {
            ev.preventDefault();

            CustomFromEvent.deleteColumn($(ev.target));
        }

        private static deleteColumn = ($elem : JQuery<HTMLElement>, isShowToastr = true) => {
            let item = $elem.closest('.custom_form_column_item');
            if(item.hasClass('deleting')){
                return;
            }
            item.addClass('deleting');

            let header_name = CustomFromEvent.getHeaderName(item);
            // Add delete flg
            item.append($('<input/>', {
                type: 'hidden',
                name: header_name + '[delete_flg]',
                'class': 'delete_flg',
                value: 1
            }));
            item.fadeOut();
            let $clone: JQuery<HTMLElement> = null;
            if (item.find('.form_column_type').val() != '99') {
                let form_column_type = item.find('.form_column_type').val();
                let form_column_target_id = item.find('.form_column_target_id').val();
                let form_block_type = item.closest('.custom_form_column_block').data('form_block_type');
                let form_block_target_table_id = item.closest('.custom_form_column_block').data('form_block_target_table_id');

                // get suggest_form_column_type.
                let suggest_form_column_type;
                if(form_column_type == '1'){
                    suggest_form_column_type = '0';
                }else{
                    suggest_form_column_type = form_column_type;
                }

                // get target suggest div area.
                let $custom_form_block_target = $('.custom_form_column_block')
                .filter('[data-form_block_type="' + form_block_type + '"]')
                .filter('[data-form_block_target_table_id="' + form_block_target_table_id + '"]');

                let $custom_form_column_suggests = $custom_form_block_target
                    .find('.custom_form_column_suggests')
                    .filter('[data-form_column_type="' + suggest_form_column_type + '"]')
                    .find('.draggables');
                // find the same value hidden in suggest ul.
                let $template = $custom_form_block_target.find('[data-form_column_target_id="' + form_column_target_id + '"]')
                    .filter('[data-form_column_type="' + form_column_type + '"]');
                if ($template) {
                    $clone = $template.children('.custom_form_column_item').clone(true);
                    $clone.appendTo($custom_form_column_suggests).show();

                    CustomFromEvent.addDragSuggestEvent($clone);
                }
            }

            if(isShowToastr){
                toastr.warning($('#delete_revert_message').val(), $('#delete_title').val(), {timeOut:5000, preventDuplicates: true, positionClass: 'toast-bottom-center', onclick: function(){
                    CustomFromEvent.revertDeleteColumn(item, $clone);
                }});
            }
        }


        /**
         * delete box
         * @param ev 
         */
        private static deleteBoxEvent = (ev) => {
            ev.preventDefault();

            $(ev.target).closest('.custom_form_area').fadeOut();

            $(ev.target).find('.custom_form_column_item').each(function(index, element){
                CustomFromEvent.deleteColumn($(element), false);
            })
        }


        /**
         * revert deleting column.
         */
        private static revertDeleteColumn($item: JQuery<HTMLElement>, $clone: JQuery<HTMLElement>){
            if($clone){
                $clone.remove();
            }

            $item.removeClass('deleting').fadeIn();
            $item.find('.delete_flg').remove();
        }


        private static getHeaderName($li: JQuery<Element>): string {
            var header_name = $li.closest('.box-custom_form_block').find('.header_name').val() as string;
            var header_column_name = $li.find('.header_column_name').val() as string;
            return header_name + header_column_name;
        }

        private static formSubmitEvent = () => {
            if(!CustomFromEvent.validateSubmit()){
                CommonEvent.ShowSwal(null, {
                    type: 'error',
                    title: $('#validate_error_title').val(),
                    text: $('#validate_error_message').val(),
                    showCancelButton: false,
                });
                return false;
            };

            // loop "custom_form_block_available" is 1
            let hasRequire = false;
            if(!$('form.custom_form_form').hasClass('confirmed')){
                $('.custom_form_block_available').each(function(index, elem){
                    // if elem's value is not 1, continue.
                    if(!pBool($(elem).val())){
                        return true;
                    }
                    // if not check, continue
                    if($(elem).is(':checkbox') && !$(elem).is(':checked')){
                        return true;
                    }

                    let $suggests = $(elem).parents('.box-custom_form_block').find('.custom_form_column_suggests li');
                    // if required value is 1, hasRequire is true and break
                    $suggests.each(function(i, e){
                        if($(e).find('.required_item').val() == '1'){
                            hasRequire = true;
                            return false;
                        }
                    })
                });
            }

            if(!hasRequire){
                CustomFromEvent.ignoreSuggests();
                return true;
            }

            // if has require, show swal
            CommonEvent.ShowSwal(null, {
                title: $('#cofirm_required_title').val(),
                text: $('#cofirm_required_text').val(),
                confirmCallback: function(result){
                    if(pBool(result.value)){
                        $('form.custom_form_form').addClass('confirmed').submit();
                    }
                },
            });

            return false;
        }


        private static validateSubmit() : boolean{
            if(pBool($('#custom_form_form').data('preview'))){
                $('#custom_form_form').data('preview', 0);
                return true;
            }

            $.validator.addMethod('options', function(value, element){
                return CustomFromEvent.validateOption(value, element);
            });

            $('#custom_form_form').validate({
                ignore: [],
                errorPlacement: function (err, element) {
                    // append class "error" to .custom_form_column_item
                    element.closest('.custom_form_column_item').addClass('error');
                },  
            });

            $('[name$="options\]"]').each(function() {
                $(this).rules('add', {
                    options: true,
                    messages: {
                        options: '',
                    },
                });
            });


            let result = $('#custom_form_form').valid();
            if(result){
                $('#custom_form_form .error').removeClass('error');
            }
            return result;
        }


        private static validateOption(value, element) : boolean
        {
            if($(element).closest('.custom_form_column_suggests').length > 0){
                return true;
            }
            let $elem = $(element);
            let $item = $elem.closest('.custom_form_column_item');
            let optionJson = JSON.parse(value);

            // if already deleted, skip
            if(pBool($item.find('.delete_flg').val())){
                return true;
            }

            // get rules
            let rules = JSON.parse($elem.closest('.custom_form_column_item').find('.validation_rules').val() as string);
            for(let key in rules){
                let rule = rules[key];
                let optionVal = optionJson[key];

                // execute rule
                switch(rule){
                    // required
                    case 'required':
                        if(!hasValue(optionVal)){
                            return false;
                        }
                        break;
                    case 'required_image':
                        if(hasValue(optionJson['image_url'])){
                            continue;
                        }
                        // check image element and has item
                        if(!hasValue($item.find('.image')) || $item.find('.image').get(0).isDefaultNamespace.length == 0){
                            return false;
                        }
                        break;
                }
            }

            return true;
        }
        

        private static ignoreSuggests = () => {
            $('.custom_form_column_suggests,.template_item_block').find('input,textarea,select,file').attr('disabled', 'disabled');
            return true;
        }


        /**
         * Replace clone suggest li name.
         * @param $li 
         */
        private static replaceCloneColumnName($li){
            let replaceHeaderName = $li.data('header_column_name');
            let $replaceLi = $li.parents('.custom_form_block')
                .find('.custom_form_column_suggests')
                .find('.custom_form_column_item[data-header_column_name="' + replaceHeaderName + '"]');

            if($replaceLi.length == 0){
                return;
            }

            // get "NEW__" string
            let newCode = replaceHeaderName.match(/NEW__.{8}-.{4}-.{4}-.{4}-.{12}/);
            if(!newCode){
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

            if(!hasValue(custom_column_id)){
                $('.changedata_column_id').children('option').remove();
                $d.resolve();
            }
            else{
                $.ajax({
                    url: admin_url(URLJoin('webapi', 'target_table', 'columns', custom_column_id)),
                    type: 'GET'
                })
                .done(function (data) {
                    $('.changedata_column_id').children('option').remove();
                    $('.changedata_column_id').append($('<option>').val('').text(''));
                    $.each(data, function (value, name) {
                        var $option = $('<option>')
                            .val(value as string)
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
        }


        private static settingModalEvent = (ev:JQueryEventObject) => {
            let formItem = CustomFromItem.makeByHidden($(ev.target).closest('.custom_form_column_item'));
            formItem.showSettingModal($(ev.target).closest('.setting'));
        }
 
        
        /**
         * Settng modal Setting
         */
        private static settingModalSetting = (ev) => {
            ev.preventDefault();

            let form : HTMLFormElement = $('#modal-showmodal form').get()[0] as HTMLFormElement;
            if(!form.reportValidity()){
                return;
            }
            
            let formItem = CustomFromItem.makeByModal();
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
        }


        /**
         * Box resize event
         * https://codepen.io/delagics/pen/PWxjMN
         * Delagics CA
         * Customized
         */
        private static resizeEvent(resizableEl:JQuery<HTMLElement>){
            let columns = 12,
                fullWidth = resizableEl.parent().width(),
                columnWidth = fullWidth / columns,
                updateClass = function(el, col, updateValue) {
                    el.css('width', ''); // remove width, our class already has it
                    el.removeClass(function(index, className) {
                    return (className.match(/(^|\s)col-\S+/g) || []).join(' ');
                    }).addClass('col-sm-' + col);

                    // if 1 or 2, resize this
                    if(updateValue == 1 || updateValue == 2){
                        el.data('grid_column', col);
                        CustomFromEvent.updateAreaWidth(el);
                    }
                    // if 2, size down next element and resize.
                    if(updateValue == 2){
                        let $next = $(el).closest('[data-grid_column]').next('[data-grid_column]');
                        updateClass($next, $next.data('grid_column') - 3, 1);
                    }
                };

            // jQuery UI Resizable
            resizableEl.resizable({
                handles: 'e',
                start: function(event, ui) {
                let target = ui.element;
                
                let targetCol = Math.round(target.width() / columnWidth);
                target.resizable('option', 'minWidth', columnWidth);
                },
                resize: function(event, ui) {
                    let $element = $(ui.element);
                    let beforeGridColumn = $element.data('grid_column');
            
                    let target = ui.element;
                    let targetColumnCount = Math.round(target.width() / columnWidth);
                    let updateValue = 1;

                    // Whether update next
                    if(beforeGridColumn == targetColumnCount || targetColumnCount % 3 !== 0){
                        targetColumnCount = beforeGridColumn;
                        updateValue = 0;
                    }
                    else{
                        updateValue = CustomFromEvent.isEnableResize($element, targetColumnCount);
                        if(updateValue == 0){
                            targetColumnCount = beforeGridColumn;
                        }
                    }
                    updateClass(target, targetColumnCount, updateValue);

                    // toggle append button
                    let $button = target.closest('.row').find('.addbutton_button');
                    CustomFromEvent.togglePlusButton($button);
                },
            });
            resizableEl.prop('data-add-resizable', 1);
            $('.ui-resizable-e').attr('data-toggle', 'tooltip').prop('title', $('#resize_box_tooltip').val());
        }
        
        /**
         * whether inable resize
         * @param el 
         * @param nextSize resizing expects size
         * @return 1: can resize. 0: cannot resize. 2: next box size resize to down.
         */
        private static isEnableResize = function(el, nextSize){
            // calc size
            let $items = $(el).closest('.row').find('[data-grid_column]:visible').not(el);
            let columns = 0;
            $items.each(function(index, elem){
                columns += $(elem).data('grid_column');
            });
    
            if(columns + nextSize <= 12){
                return 1;
            }

            // if next size is upper 6 and can resize, return 2;
            let $next = $(el).closest('[data-grid_column]').next('[data-grid_column]');
            if(hasValue($next) && $next.data('grid_column') >= 6){
                return 2;
            }

            return 0;
        }


        /**
         * Showing preview
         */
        private static previewCustomForm()
        {
			window.open('', 'exment_preview');

			const form = $('#custom_form_form');
			const action = form.attr('action');
			const method = form.attr('method');

            // update form info
			form.attr('action', URLJoin($('#formroot').val(), 'preview'))
                .attr('method', 'post')
                .attr('target', 'exment_preview')
                .removeAttr('pjax-container')
                .data('preview', 1);
			form.submit();
			form.attr('action', action).attr('method', method).attr('target', '').attr('pjax-container', '');
        }
    }
    
}
$(function () {
    Exment.CustomFromEvent.AddEvent();
    Exment.CustomFromEvent.AddEventOnce();
});
