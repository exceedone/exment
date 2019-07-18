
namespace Exment {
    export class CommonEvent {
        protected static calcDataList = [];
        protected static relatedLinkageList = [];
        /**
         * Call only once. It's $(document).on event.
         */
        public static AddEventOnce() {
            $(document).on('change', '[data-changedata]', {}, async (ev) => { await CommonEvent.changeModelData($(ev.target)); });
            $(document).on('ifChanged change check', '[data-filter],[data-filtertrigger]', {}, (ev: JQueryEventObject) => {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('click', '.add,.remove', {}, (ev: JQueryEventObject) => {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('switchChange.bootstrapSwitch', '[data-filter],[data-filtertrigger]', {}, (ev: JQueryEventObject, state) => {
                CommonEvent.setFormFilter($(ev.target));
            });

            $(document).on('change', '[data-linkage]', {}, CommonEvent.setLinkageEvent);

            $(document).on('pjax:complete', function (event) {
                CommonEvent.AddEvent();
            });
        }
        public static AddEvent() {
            CommonEvent.ToggleHelp();
            CommonEvent.addSelect2();
            CommonEvent.setFormFilter($('[data-filter]'));
            CommonEvent.tableHoverLink();

            $.numberformat('[number_format]');
        }

        /**
         * toggle right-top help link and color
         */
        public static ToggleHelp(){
            var help_urls = $('#help_urls').val();
            if(!hasValue(help_urls)){
                return;
            }
            var helps = JSON.parse(help_urls);

            var pathname = location.pathname;
            var $manual = $('#manual_link');
            var manual_base_uri = $('#manual_base_uri').val();

            for(var i = 0; i < helps.length; i++){
                var help = helps[i];

                // if match first current uri and pathname, set help url
                let uri = trimAny(admin_base_path(help.uri), '/');
                let isMatch = false;
                if(!hasValue(uri)){
                    isMatch = trimAny(pathname, '/') == uri;
                }else{
                    isMatch  = trimAny(pathname, '/').indexOf(uri) === 0;
                }
                if(isMatch){
                    // set new url
                    var help_url = URLJoin(manual_base_uri, help.help_uri);
                    $manual.prop('href', help_url);
                    // chenge color
                    $manual.children('i').addClass('help_personal');
                    return;
                }
            }

            // if not exists, default help
            $manual.prop('href', manual_base_uri);
            $manual.children('i').removeClass('help_personal');
        }
        
        /**
         * 
         */
        public static CallbackExmentAjax(res) {
            if(hasValue(res.responseJSON)){
                res = res.responseJSON;
            }
            
            if (res.result === true || res.status === true) {
                if ($(".modal:visible").length > 0) {
                    $(".modal").off("hidden.bs.modal").on("hidden.bs.modal", function () {
                        // put your default event here
                        $(".modal").off("hidden.bs.modal");
                        CommonEvent.redirectCallback(res);
                    });
                }
                else {
                    CommonEvent.redirectCallback(res);
                }

                // show toastr
                if (hasValue(res.toastr)) {
                    toastr.success(res.toastr);
                }
                $('.modal').modal('hide');
            }
            else {
                // show toastr
                if (hasValue(res.toastr)) {
                    toastr.error(res.toastr);
                } else {
                    toastr.error('Undeifned Error');
                }
            }
        }

        private static redirectCallback(res) {
            if(hasValue(res.reload) && res.reload === false){
                return;
            }

            if (hasValue(res.redirect)) {
                $.pjax({ container: '#pjax-container', url: res.redirect });
            } else {
                $.pjax.reload('#pjax-container');
            }
        }


        public static ShowSwal(url: string, options = []) {
            options = $.extend(
                {
                    title: 'Swal',
                    text: null,
                    type: "warning",
                    input: null,
                    confirm: 'OK',
                    cancel: 'Cancel',
                    method: 'POST',
                    data: [],
                    redirect: null,
                    preConfirmValidate: null
                },
                options
            );

            var data = $.extend(
                {
                    _pjax: true,
                    _token: LA.token,
                }, options.data
            );

            if(options.method.toLowerCase == 'delete'){
                data._method = 'delete';
                options.method = 'POST';
            }

            var swalOptions = {
                title: options.title,
                type: options.type,
                showCancelButton: true,
                confirmButtonColor: "#DD6B55",
                confirmButtonText: options.confirm,
                showLoaderOnConfirm: true,
                allowOutsideClick: false,
                cancelButtonText: options.cancel,
                preConfirm: function (input) {
                    $('.swal2-cancel').hide();
                    
                    if(hasValue(options.preConfirmValidate)){
                        var result = options.preConfirmValidate(input);
                        if(result !== true){
                            return result;
                        }
                    }

                    return new Promise(function (resolve) {
                        $.ajax({
                            type: options.method,
                            url: url,
                            //container: "#pjax-container",
                            data: data,
                            success: function (repsonse) {
                                if(hasValue(options.redirect)){
                                    repsonse.redirect = options.redirect;
                                }
                                Exment.CommonEvent.CallbackExmentAjax(repsonse);
                                resolve(repsonse);
                            },
                            error: function (repsonse) {
                                Exment.CommonEvent.CallbackExmentAjax(repsonse);
                            }
                        });
                    });
                }
            };
            if(hasValue(options.input)){ swalOptions.input = options.input; }
            if(hasValue(options.text)){ swalOptions.text = options.text; }

            swal(swalOptions)
                .then(function(result) {
                    var data = result.value;
                    if (typeof data === 'object' && hasValue(data.message)) {
                        if (data.status === true || data.result === true) {
                            swal(data.message, '', 'success');
                        } else {
                            swal(data.message, '', 'error');
                        }
                    } else if (typeof data === 'string') {
                        swal(data, '', 'error');
                    }
                });
        }

        /**
         * if click grid row, move page
         */
        public static tableHoverLink() {
            $('table').find('[data-id]').closest('tr').not('.tableHoverLinkEvent').on('click', function (ev: JQueryEventObject) {
                // if e.target closest"a" is length > 0, return
                if ($(ev.target).closest('a').length > 0) {
                    return;
                }
                if ($(ev.target).closest('.popover').length > 0) {
                    return;
                }
                
                var linkElem = $(ev.target).closest('tr').find('.fa-eye');
                if (!hasValue(linkElem)) {
                    linkElem = $(ev.target).closest('tr').find('.fa-edit');
                }
                if (!hasValue(linkElem)) {
                    linkElem = $(ev.target).closest('tr').find('.fa-external-link');
                }
                if (!hasValue(linkElem)) {
                    return;
                }
                linkElem.closest('a').click();
            }).addClass('tableHoverLinkEvent');
        }

        /**
        * Calc Date
        */
        private static calcDate = () => {
            var $type = $('.subscription_claim_type');
            var $start_date = $('.subscription_agreement_start_date');
            var $term = $('.subscription_agreement_term');
            var $end_date = $('.subscription_agreement_limit_date');
            var term = pInt($term.val());
            if (!$type.val() || !$start_date.val()) {
                return;
            }

            // 日付計算
            var dt = new Date($('.subscription_agreement_start_date').val() as string);
            if ($type.val() == 'month') {
                dt.setMonth(dt.getMonth() + term);
            } else if ($type.val() == 'year') {
                dt.setFullYear(dt.getFullYear() + term);
            }
            dt.setDate(dt.getDate() - 1);
            // セット
            $end_date.val(dt.getFullYear() + '-'
                + ('00' + (dt.getMonth() + 1)).slice(-2)
                + '-' + ('00' + dt.getDate()).slice(-2)
            );
        }

        /**
         * Set changedata event
         */
        public static setChangedataEvent(datalist) {
            // loop "data-changedata" targets   
            for (var key in datalist) {
                var data = datalist[key];

                // set change event
                $('.box-body').on('change', CommonEvent.getClassKey(key), { data: data }, async (ev) => {
                    await CommonEvent.changeModelData($(ev.target), ev.data.data);
                });

                // if hasvalue to_block, add event when click add button
                for (var table_name in data) {
                    var target_table_data = data[table_name];
                    if (!hasValue(target_table_data)) {
                        continue;
                    }

                    for (var i = 0; i < target_table_data.length; i++) {
                        var d = target_table_data[i];
                        if (!hasValue(d.to_block)) {
                            continue;
                        }
                        $(d.to_block).on('click', '.add', { key: key, data: target_table_data, index: i, table_name: table_name }, async (ev) => {
                            // get target
                            var $target = CommonEvent.getParentRow($(ev.target)).find(CommonEvent.getClassKey(ev.data.key));
                            var data = ev.data.data;
                            // set to_lastindex matched index
                            for (var i = 0; i < data.length; i++) {
                                if (i != ev.data.index) { continue; }
                                data[i]['to_lastindex'] = true;
                            }
                            // create rensou array.
                            var modelArray = {};
                            modelArray[ev.data.table_name] = data;
                            await CommonEvent.changeModelData($target, modelArray);
                        });
                    }
                }
            }
        }

        /**
        * get model and change value
        */
        private static async changeModelData($target: JQuery<TElement>, data: any = null) {
            var $d = $.Deferred();
            // get parent element from the form field.
            var $parent = CommonEvent.getParentRow($target);

            // if has data, get from data object
            if (hasValue(data)) {
                // if data is not array, set as array
                //if(!Array.isArray(data)){data = [data];}
                // loop for model table
                for (var table_name in data) {
                    var target_table_data = data[table_name];
                    if (!hasValue(target_table_data)) {
                        continue;
                    }
                    // get selected model
                    // get value.
                    var value = $target.val();
                    if (!hasValue(value)) {
                        await CommonEvent.setModelItem(null, $parent, $target, target_table_data);
                        continue;
                    }
                    $.ajaxSetup({
                        headers: {
                            'X-CSRF-TOKEN': $('[name="_token"]').val()
                        }
                    });
                    $.ajax({
                        url: admin_url(URLJoin('webapi', 'data', table_name, value)),
                        type: 'POST',
                        context: {
                            data: target_table_data,
                        }
                    })
                        .done(async function (modeldata) {
                            await CommonEvent.setModelItem(modeldata, $parent, $target, this.data);
                            $d.resolve();
                        })
                        .fail(function (errordata) {
                            console.log(errordata);
                            $d.reject();
                        });
                }
                //}
            }

            // getItem
            var changedata_data = $target.data('changedata');
            if (hasValue(changedata_data)) {
                var getitem = changedata_data.getitem;
                if (hasValue(getitem)) {
                    var send_data = {};
                    send_data['value'] = $target.val();
                    // get data-key
                    for (var index in getitem.key) {
                        var key = getitem.key[index];
                        var $elem = $parent.find(CommonEvent.getClassKey(key));
                        if ($elem.length == 0) {
                            continue;
                        }
                        send_data[key] = $elem.val();
                    }

                    // send ajax
                    $.ajaxSetup({
                        headers: {
                            'X-CSRF-TOKEN': $('[name="_token"]').val()
                        }
                    });
                    $.ajax({
                        url: getitem.uri,
                        type: 'POST',
                        data: send_data,
                        context: {
                            target: $target,
                            parent: $parent,
                        }
                    })
                        .done(function (data) {
                            CommonEvent.setModelItemKey(this.target, this.parent, data);
                            $d.resolve();
                        })
                        .fail(function (data) {
                            console.log(data);
                            $d.reject();
                        });
                }
            }

            return $d.promise();
        }

        /**
         * set getmodel or getitem data to form
         */
        private static async setModelItem(modeldata: any, $changedata_target: JQuery, $elem: JQuery, options: Array<any>) {
            // loop for options
            for (var i = 0; i < options.length; i++) {
                var option = options[i];
                // if has changedata_to_block, get $elem using changedata_to_block
                if (hasValue(option.to_block)) {
                    $changedata_target = $(option.to_block);
                    // if has to_lastindex, get last children item
                    if (hasValue(option.to_lastindex)) {
                        $changedata_target = $changedata_target.find(option.to_block_form).last();
                    }
                }
                // get element
                var $elem = $changedata_target.find(CommonEvent.getClassKey(option.to));
                if (!hasValue(modeldata)) {
                    await CommonEvent.setValue($elem, null);
                    //$elem.val('');
                } else {
                    // get element value from model
                    var from = modeldata['value'][option.from];
                    await CommonEvent.setValue($elem, from);
                }

                // view filter execute
                CommonEvent.setFormFilter($elem);

                // add $elem to option
                option['elem'] = $elem;
            }

            // re-loop for options
            for (var i = 0; i < options.length; i++) {
                var option = options[i];
                $elem = option['elem'];
                ///// execute calc
                for (var j = 0; j < CommonEvent.calcDataList.length; j++) {
                    var calcData = CommonEvent.calcDataList[j];
                    // if calcData.key matches option.to, execute cals
                    if (calcData.key == option.to) {
                        var $filterTo = $elem.filter(calcData.classKey);
                        if (hasValue($filterTo)) {
                            await CommonEvent.setCalc($filterTo, calcData.data);
                        }
                    }
                }
            }
        }

        /**
         * set getmodel or getitem data to form
         */
        private static setModelItemKey($target: JQuery, $parent: JQuery, data: any) {
            // Loop with acquired element
            for (var key in data) {
                //remove id, created_at, updated_at
                if ($.inArray(key, ['id', 'created_at', 'updated_at']) != -1) {
                    continue;
                }
                var $elem = $parent.find('.' + key);
                if ($elem.length == 0) {
                    continue;
                }

                for (var i = 0; i < $elem.length; i++) {
                    var $e = $elem.eq(i);
                    // 選択した要素そのものであればcontinue
                    if ($e.data('getitem')) {
                        continue;
                    }
                    CommonEvent.setValue($e, data[key]);
                    
                    // if target-item is "iconpicker-input", set icon
                    if ($e.hasClass('icon')) {
                        $e.data('iconpicker').update(data['icon']);
                    }
                }
            }
            CommonEvent.setFormFilter($target);
        }

        /**
         * Set RelatedLinkage event
         */
        public static setRelatedLinkageEvent(datalist) {
            // set relatedLinkageList for after flow.
            CommonEvent.relatedLinkageList = [];
            // loop "related Linkage" targets   
            for (var key in datalist) {
                var data = datalist[key];

                // set data to element
                // cannot use because cannot fire new row
                //$(CommonEvent.getClassKey(key)).data('calc_data', data);
                // set relatedLinkageList array. key is getClassKey. data is data
                CommonEvent.relatedLinkageList.push({ "key": key, "classKey": CommonEvent.getClassKey(key), "data": data });

                // set linkage event
                $('.box-body').on('change', CommonEvent.getClassKey(key), { data: data, key: key }, CommonEvent.setRelatedLinkageChangeEvent);
            }
        }

        /**
         * call select2 items using linkage
         */
        private static setRelatedLinkageChangeEvent = (ev: JQueryEventObject) => {
            var $base = $(ev.target).closest(CommonEvent.getClassKey(ev.data.key));
            if (!hasValue($base)) {
                return;
            }
            var $parent = CommonEvent.getParentRow($base);
            var linkages = ev.data.data;
            if (!hasValue(linkages)) {
                return;
            }

            // execute linkage event
            for (var key in linkages) {
                // set param from PHP
                var link = linkages[key];
                var url = link.url;
                var expand = link.expand;
                var $target = $parent.find(CommonEvent.getClassKey(link.to));
                CommonEvent.linkage($target, url, $base.val(), expand);
            }
        }

        /**
         * call select2 items using linkage
         */
        private static setLinkageEvent = (ev: JQueryEventObject) => {
            var $base = $(ev.target).closest('[data-linkage]');
            if (!hasValue($base)) {
                return;
            }
            var $parent = CommonEvent.getParentRow($base);
            var linkages = $base.data('linkage');
            if (!hasValue(linkages)) {
                return;
            }

            // get expand data
            var expand = $base.data('linkage-expand');
            
            var linkage_text = $base.data('linkage-text');
            // execute linkage event
            for (var key in linkages) {
                var link = linkages[key];
                var url = link;
                // if link is object and has 'text', set linkage_text
                if(link instanceof Object){
                    url = link.url;
                    linkage_text = link.text;
                }
                var $target = $parent.find(CommonEvent.getClassKey(key));
                CommonEvent.linkage($target, url, $base.val(), expand, linkage_text);
            }
        }

        private static linkage($target: JQuery<Element>, url: string, val: any, expand?: any, linkage_text?: string) {
            var $d = $.Deferred();

            // create querystring
            if (!hasValue(expand)) { expand = {}; }
            if (!hasValue(linkage_text)) { linkage_text = 'text'; }

            expand['q'] = val;
            var query = $.param(expand);
            $.get(url + '?' + query, function (json) {
                $target.find("option").remove();
                var options = [];
                options.push({id: '', text: ''});

                $.each(json, function(index, d){
                    options.push({id: hasValue(d.id) ? d.id : '', text: d[linkage_text]});
                })

                $target.select2({
                    data: options,
                    "allowClear": true,
                    "placeholder": $target.next().find('.select2-selection__placeholder').text(),
                }).trigger('change');

                $d.resolve();
            });
            return $d.promise();
        }

        /**
         * Switch display / non-display according to the target input value
         * @param $target
         */
        private static setFormFilter = ($target: JQuery<TElement>) => {
            $target = CommonEvent.getParentRow($target).find('[data-filter]');
            for (var tIndex = 0; tIndex < $target.length; tIndex++) {
                var $t = $target.eq(tIndex);
                // Get parent element of that input
                var $parent = CommonEvent.getParentRow($t);
                // Get parent element with row
                var $eParent = $t.parents('.form-group');

                // Get search target key and value
                try {
                    var array = $t.data('filter');
                    // if not array, convert array
                    if (!Array.isArray(array)) {
                        array = [array];
                    }
                    var isShow = true;
                    var isReadOnly = false;
                    for (var index = 0; index < array.length; index++) {
                        var a = array[index];
                        // Get value of class with that key
                        // if has parent value
                        var parentCount = a.parent ? a.parent : 0;
                        if (parentCount > 0) {
                            var $calcParent = $parent;
                            for (var i = 0; i < parentCount; i++) {
                                $calcParent = CommonEvent.getParentRow($calcParent);
                            }
                            var filterVal = CommonEvent.getFilterVal($calcParent, a);
                        } else {
                            var filterVal = CommonEvent.getFilterVal($parent, a);
                        }

                        if (isShow) {
                            // check whether null
                            if (a.hasValue) {
                                if (!hasValue(filterVal)) {
                                    isShow = false;
                                }
                            }
                            
                            // when value is null and not set "nullValue", isSnow = false
                            if (filterVal == null && !a.nullValue) {
                                isShow = false;
                            } else if (filterVal != null && a.nullValue) {
                                isShow = false;
                            }

                            // その値が、a.valueに含まれているか
                            if (a.value) {
                                if(!CommonEvent.findValue(filterVal, a.value)){
                                    isShow = false;
                                }
                            }
                            
                            if (a.notValue) {
                                if(!CommonEvent.findValue(filterVal, a.notValue)){
                                    isShow = false;
                                }
                            }
                        }

                        // change readonly attribute
                        if (!isReadOnly && a.readonlyValue) {
                            if(CommonEvent.findValue(filterVal, a.readonlyValue)){
                                isReadOnly = true;
                            }
                        }
                    }
                    if (isShow) {
                        $eParent.show();
                        if ($t.parents().hasClass('bootstrap-switch')) {
                            $t.bootstrapSwitch('disabled', false);
                        } else {
                            $t.prop('disabled', false);
                        }
                        // disabled false
                    } else {
                        $eParent.hide();
                        $t.prop('disabled', true);
                        ///// remove value
                        // comment out because remove default value
                        //$t.val('');
                    }

                    // if selectbox, disabled
                    var propName = $t.prop('type') == 'select-one' || $t.prop('tagName').toLowerCase() == 'select'
                        ? 'disabled' : 'readonly';
                    if (isReadOnly) {
                        $t.prop(propName, true);
                    } else {
                        if (propName != 'disabled' || isShow){
                            $t.prop(propName, false);
                        }
                    }
                } catch (e) {

                }
            }
        }

        /**
         * Set calc event
         */
        public static setCalcEvent = (datalist) => {
            // set datalist for after flow.
            CommonEvent.calcDataList = [];
            // loop "data-calc" targets   
            for (var key in datalist) {
                var data = datalist[key];

                // set data to element
                // cannot use because cannot fire new row
                //$(CommonEvent.getClassKey(key)).data('calc_data', data);
                // set calcDataList array. key is getClassKey. data is data
                CommonEvent.calcDataList.push({ "key": key, "classKey": CommonEvent.getClassKey(key), "data": data });

                // set calc event
                $('.box-body').on('change', CommonEvent.getClassKey(key), { data: data, key: key }, async (ev) => {
                    await CommonEvent.setCalc($(ev.target), ev.data.data);
                });
                // set event for plus minus button
                $('.box-body').on('click', '.btn-number-plus,.btn-number-minus', { data: data, key: key }, async (ev) => {
                    await CommonEvent.setCalc($(ev.target).closest('.input-group').find(CommonEvent.getClassKey(ev.data.key)), ev.data.data);
                });
            }
        }

        /**
         * set calc 
         * data : has "to" and "options". options has properties "val" and "type"
         * 
         */
        public static async setCalc($target: JQuery<TElement>, data) {
            // if not found target, return.
            if (!hasValue($target)) { return; }

            var $parent = CommonEvent.getParentRow($target);
            if (!hasValue(data)) {
                return;
            }
            // loop for calc target.
            for (var i = 0; i < data.length; i++) {
                // for creating array contains object "value0" and "calc_type" and "value1".
                var value_itemlist = [];
                var value_item = { values: [], calc_type: null };
                var $to = $parent.find(CommonEvent.getClassKey(data[i].to));
                var isfirst = true;
                for (var j = 0; j < data[i].options.length; j++) {
                    var val: any = 0;
                    // calc option
                    var option = data[i].options[j];

                    // when fixed value
                    if (option.type == 'fixed') {
                        value_item.values.push(rmcomma(option.val));
                    }
                    // when dynamic value, get value
                    else if (option.type == 'dynamic') {
                        val = rmcomma($parent.find(CommonEvent.getClassKey(option.val)).val());
                        if (!hasValue(val)) { val = 0; }
                        value_item.values.push(val);
                    }
                    // when select_table value, get value from table
                    else if (option.type == 'select_table') {
                        // find select target table
                        var $select = $parent.find(CommonEvent.getClassKey(option.val));
                        var table_name = $select.data('target_table_name');
                        // get selected table model
                        var model = await CommonEvent.findModel(table_name, $select.val());
                        // get value
                        if (hasValue(model)) {
                            val = model['value'][option.from];
                            if (!hasValue(val)) { val = 0; }
                        }
                        value_item.values.push(val);
                    }
                    // when symbol
                    else if (option.type == 'symbol') {
                        value_item.calc_type = option.val;
                    }

                    // if hasValue calc_type and values.length == 1 or first, set value_itemlist
                    if (hasValue(value_item.calc_type) &&
                        value_item.values.length >= 2 || (!isfirst && value_item.values.length >= 1)) {
                        value_itemlist.push(value_item);

                        // reset
                        value_item = { values: [], calc_type: null };
                        isfirst = false;
                    }
                }
                // get value useing value_itemlist
                var bn = null;
                for (var j = 0; j < value_itemlist.length; j++) {
                    value_item = value_itemlist[j];
                    // if first item, new BigNumber using first item
                    if (value_item.values.length == 2) {
                        bn = new BigNumber(value_item.values[0]);
                    }
                    // get appended value
                    var v = value_item.values[value_item.values.length - 1];
                    switch (value_item.calc_type) {
                        case 'plus':
                            bn = bn.plus(v);
                            break;
                        case 'minus':
                            bn = bn.minus(v);
                            break;
                        case 'times':
                            bn = bn.times(v);
                            break;
                        case 'div':
                            if (v == 0) {
                                bn = new BigNumber(0);
                            } else {
                                bn = bn.div(v);
                            }
                            break;
                    }
                }
                var precision = bn.toPrecision();
                CommonEvent.setValue($to, precision);
            }


            ///// re-loop after all data setting value
            for (var i = 0; i < data.length; i++) {
                var $to = $parent.find(CommonEvent.getClassKey(data[i].to));
                // if $to has "calc_data" data, execute setcalc function again
                //var to_data = $to.data('calc_data');
                for (var key in CommonEvent.calcDataList) {
                    var calcData = CommonEvent.calcDataList[key];
                    // filter $to obj
                    var $filterTo = $to.filter(calcData.classKey);
                    if (hasValue($filterTo)) {
                        await CommonEvent.setCalc($filterTo, calcData.data);
                    }
                }
            }
        }

        /**
         * find table data
         * @param table_name 
         * @param value 
         * @param context 
         */
        private static findModel(table_name, value, context = null) {
            var $d = $.Deferred();
            if (!hasValue(value)) {
                $d.resolve(null);
            } else {
                $.ajax({
                    url: admin_url(URLJoin('webapi', 'data', table_name, value)),
                    type: 'POST',
                    context: context
                })
                    .done(function (modeldata) {
                        $d.resolve(modeldata);
                    })
                    .fail(function (errordata) {
                        console.log(errordata);

                        $d.reject();
                    });
            }

            return $d.promise();
        }

        /**
         * set value. check number format, column type, etc...
         * @param $target 
         */
        private static setValue($target, value) {
            if (!hasValue($target)) { return; }
            var column_type = $target.data('column_type');
            
            // if 'image' or 'file', cannot setValue, continue
            if ($.inArray(column_type, ['file', 'image']) != -1) {
                return;
            }

            var isNumber = $.inArray(column_type, ['integer', 'decimal', 'currency']) != -1;
            // if number, remove comma
            if (isNumber) {
                value = rmcomma(value);
            }

            // if integer, floor value
            if (column_type == 'integer') {
                if(hasValue(value)){
                    var bn = new BigNumber(value);
                    value = bn.integerValue().toPrecision();
                }
            }

            // if 'decimal' or 'currency', floor 
            if ($.inArray(column_type, ['decimal', 'currency']) != -1 && hasValue($target.attr('decimal_digit'))) {
                if(hasValue(value)){
                    var bn = new BigNumber(value);
                    value = bn.decimalPlaces(pInt($target.attr('decimal_digit'))).toPrecision();
                }
            }

            // if number format, add comma
            if (isNumber && $target.attr('number_format')) {
                value = comma(value);
            }

            // switch bootstrapSwitch
            if ($.inArray(column_type, ['boolean', 'yesno']) != -1) {
                var $bootstrapSwitch = $target.filter('[type="checkbox"]');
                $bootstrapSwitch.bootstrapSwitch('toggleReadonly').bootstrapSwitch('state', $bootstrapSwitch.data('onvalue') == value).bootstrapSwitch('toggleReadonly');
            }
            
            // set value
            $target.val(value).trigger('change');
        }

        /**
         * add select2
         */
        private static addSelect2() {
            $('[data-add-select2]').not('.added-select2').each(function (index, elem: Element) {
                let $elem = $(elem);
                let allowClear = hasValue($elem.data('add-select2-allow-clear')) ? $elem.data('add-select2-allow-clear') : true;
                let options = {
                    "allowClear": allowClear, "placeholder": $elem.data('add-select2'), width: '100%'
                };
                if (hasValue($elem.data('add-select2-ajax'))) {
                    options['ajax'] = {
                        url: $(elem).data('add-select2-ajax'),
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return {
                                q: params.term,
                                page: params.page
                            };
                        },
                        processResults: function (data, params) {
                            if (!hasValue(data) || !hasValue(data.data)) { return { results: [] }; }
                            params.page = params.page || 1;

                            return {
                                results: $.map(data.data, function (d) {
                                    d.id = d.id;
                                    d.text = d.label; // label is custom value label appended.
                                    return d;
                                }),
                                pagination: {
                                    more: data.next_page_url
                                }
                            };
                        },
                        cache: true
                    };
                    options['escapeMarkup'] = function (markup) {
                        return markup;
                    };
                    options['minimumInputLength'] = 1;
                }
                $(elem).select2(options);
            }).addClass('added-select2');
        }

        private static getFilterVal($parent: JQuery, a) {
            // get filter object
            var $filterObj = $parent.find(CommonEvent.getClassKey(a.key)).filter(':last');

            // if checkbox
            if ($filterObj.is(':checkbox')) {
                return $filterObj.is(':checked') ? $filterObj.val() : null;
            }
            return $filterObj.val();
        }

        private static getParentRow($query) {
            if ($query.closest('tr').length > 0) {
                return $query.closest('tr');
            }
            //return $query.closest('.fields-group');
            // if hasClass ".fields-group" in $query, return parents
            if ($query.hasClass('fields-group')) {
                return $query.parents('.fields-group').eq(0);
            }
            return $query.closest('.fields-group');
        }
        private static getClassKey(key, prefix = '') {
            return '.' + prefix + key + ',.' + prefix + 'value_' + key;
        }


        private static findValue(key, values){
            values = !Array.isArray(values) ? values.split(',') : values;
            for(var i = 0; i < values.length; i++){
                if(values[i] == key){
                    return true;
                }
            }
            return false;
        }
    }
}

$(function () {
    Exment.CommonEvent.AddEvent();
    Exment.CommonEvent.AddEventOnce();
});

const URLJoin = (...args) =>
    args
        .join('/')
        .replace(/[\/]+/g, '/')
        .replace(/^(.+):\//, '$1://')
        .replace(/^file:/, 'file:/')
        .replace(/\/(\?|&|#[^!])/g, '$1')
        .replace(/\?/g, '&')
        .replace('&', '?');

const pInt = (obj) => {
    if (!hasValue(obj)) {
        return 0;
    }
    obj = obj.toString().replace(/,/g, '');
    return parseInt(obj);
}

const hasValue = (obj): boolean => {
    if (obj == null || obj == undefined || obj.length == 0) {
        return false;
    }
    return true;
}
//const comma = (x) => {
//    return rmcomma(x).replace(/(\d)(?=(?:\d{3}){2,}(?:\.|$))|(\d)(\d{3}(?:\.\d*)?$)/g
//        , '$1$2,$3');
//}

const comma = (x) => {
    if (x === null || x === undefined) {
        return x;
    }
    return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}
const rmcomma = (x) => {
    if (x === null || x === undefined) {
        return x;
    }
    return x.toString().replace(/,/g, '');
}
const trimAny = function (str, any) {
    if(!hasValue(str)){
        return str;
    }
    return str.replace(new RegExp("^" + any + "+|" + any + "+$", "g"), '');
}

const selectedRow = function () {
    var id = $('.grid-row-checkbox:checked').eq(0).data('id');
    return id;
}

const selectedRows = function () {
    var rows = [];
    $('.grid-row-checkbox:checked').each((num, element) => {
        rows.push($(element).data('id'));
    });
    return rows;
}

const admin_base_path = function (path) {
    var urls = [];

    var admin_base_uri = trimAny($('#admin_base_uri').val(), '/');
    if (admin_base_uri.length > 0) {
        urls.push(admin_base_uri);
    }

    var prefix = trimAny($('#admin_prefix').val(), '/');
    if(hasValue(prefix)){
        urls.push(prefix);
    }

    prefix = '/' + urls.join('/');
    prefix = (prefix == '/') ? '' : prefix;
    return prefix + '/' + trimAny(path, '/');
}

const admin_url = function (path) {
    return URLJoin($('#admin_uri').val(), path);
}

const getParamFromArray = function (array) {
    array = array.filter(function (x) {
        return (x.value !== (undefined || null || ''));
    });
    return $.param(array);

}