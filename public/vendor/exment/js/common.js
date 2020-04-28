var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    function adopt(value) { return value instanceof P ? value : new P(function (resolve) { resolve(value); }); }
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : adopt(result.value).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
var Exment;
(function (Exment) {
    class CommonEvent {
        /**
         * Call only once. It's $(document).on event.
         */
        static AddEventOnce() {
            $(document).on('change', '[data-changedata]', {}, (ev) => __awaiter(this, void 0, void 0, function* () { yield CommonEvent.changeModelData($(ev.target)); }));
            $(document).on('ifChanged change check', '[data-filter],[data-filtertrigger]', {}, (ev) => {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('click', '.add,.remove', {}, (ev) => {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('switchChange.bootstrapSwitch', '[data-filter],[data-filtertrigger]', {}, (ev) => {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('change', '[data-linkage]', {}, CommonEvent.setLinkageEvent);
            $(document).off('click', '[data-help-text]').on('click', '[data-help-text]', {}, CommonEvent.showHelpModalEvent);
            $(document).off('click', '.copyScript').on('click', '.copyScript', {}, CommonEvent.copyScriptEvent);
            $(document).on('pjax:complete', function (event) {
                CommonEvent.AddEvent();
            });
        }
        static AddEvent() {
            CommonEvent.ToggleHelp();
            CommonEvent.addSelect2();
            CommonEvent.addShowModalEvent();
            CommonEvent.addFieldEvent();
            CommonEvent.setFormFilter($('[data-filter]'));
            if (!$('#gridrow_select_disabled').val()) {
                CommonEvent.tableHoverLink();
            }
            $.numberformat('[number_format]:not(".disableNumberFormat")');
        }
        /**
         * toggle right-top help link and color
         */
        static ToggleHelp() {
            if (!hasValue($('#help_urls').val())) {
                return;
            }
            const help_urls = String($('#help_urls').val());
            const helps = JSON.parse(help_urls);
            const pathname = trimAny(location.pathname, '/');
            const $manual = $('#manual_link');
            const manual_base_uri = $('#manual_base_uri').val();
            for (let i = 0; i < helps.length; i++) {
                let help = helps[i];
                // if match first current uri and pathname, set help url
                let uri = trimAny(admin_base_path(help.uri), '/');
                let isMatch = false;
                if (!hasValue(uri)) {
                    isMatch = (pathname == uri);
                }
                else if (trimAny(admin_base_path(''), '/') == uri) {
                    isMatch = (pathname == uri);
                }
                else {
                    isMatch = pathname.indexOf(uri) === 0;
                }
                if (isMatch) {
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
         * Add Help modal event
         */
        static showHelpModalEvent(ev) {
            let elem = $(ev.target).closest('[data-help-text]');
            swal(elem.data('help-title'), elem.data('help-text'), 'info');
        }
        /**
         * Copy Script event
         */
        static copyScriptEvent(ev) {
            let input = $(ev.target).closest('input');
            if (input.prop('type') != 'text') {
                return;
            }
            input.select();
            document.execCommand('copy');
            toastr.success($('#copy_toastr').val(), null, { timeOut: 1000 });
        }
        /**
         *
         */
        static CallbackExmentAjax(res) {
            if (hasValue(res.responseJSON)) {
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
                else if (hasValue(res.swal)) {
                    swal(res.swal, (hasValue(res.swaltext) ? res.swaltext : ''), 'success');
                }
                $('.modal').modal('hide');
            }
            else {
                // show toastr
                if (hasValue(res.toastr)) {
                    toastr.error(res.toastr);
                }
                // show swal
                else if (hasValue(res.swal)) {
                    swal(res.swal, (hasValue(res.swaltext) ? res.swaltext : ''), 'error');
                }
                // if has message, not execute action
                else if (hasValue(res.message)) {
                }
                else {
                    toastr.error('Undeifned Error');
                }
            }
        }
        static redirectCallback(res) {
            if (hasValue(res.reload) && res.reload === false) {
                return;
            }
            if (hasValue(res.redirect)) {
                $.pjax({ container: '#pjax-container', url: res.redirect });
            }
            else {
                $.pjax.reload('#pjax-container');
            }
        }
        /**
         * Show Modal Event
         */
        static ShowSwal(url, options) {
            options = $.extend({
                title: 'Swal',
                text: null,
                html: null,
                type: "warning",
                input: null,
                inputKey: null,
                confirm: 'OK',
                cancel: 'Cancel',
                method: 'POST',
                data: [],
                redirect: null,
                preConfirmValidate: null
            }, options);
            let data = $.extend({
                _pjax: true,
                _token: LA.token,
            }, options.data);
            if (options.method.toLowerCase() == 'delete') {
                data._method = 'delete';
                options.method = 'POST';
            }
            let swalOptions = {
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
                    if (hasValue(options.preConfirmValidate)) {
                        var result = options.preConfirmValidate(input);
                        if (result !== true) {
                            return result;
                        }
                    }
                    if (hasValue(options.inputKey)) {
                        data[options.inputKey] = input;
                    }
                    return new Promise(function (resolve) {
                        $.ajax({
                            type: options.method,
                            url: url,
                            //container: "#pjax-container",
                            data: data,
                            success: function (repsonse) {
                                if (hasValue(options.redirect)) {
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
            if (hasValue(options.input)) {
                swalOptions.input = options.input;
            }
            if (hasValue(options.text)) {
                swalOptions.text = options.text;
            }
            if (hasValue(options.html)) {
                swalOptions.html = options.html;
            }
            swal(swalOptions)
                .then(function (result) {
                var data = result.value;
                if (typeof data === 'object' && hasValue(data.message)) {
                    let message = data.message;
                    let swaltext = hasValue(data.swaltext) ? data.swaltext : '';
                    let swalresult = (data.status === true || data.result === true) ? 'success' : 'error';
                    swal(message, swaltext, swalresult);
                }
                else if (typeof data === 'string') {
                    swal(data, '', 'error');
                }
            });
        }
        static addShowModalEvent() {
            $('[data-add-swal]').not('.added-swal').each(function (index, elem) {
                $(elem).on('click', function (ev) {
                    let $target = $(ev.target).closest('[data-add-swal]');
                    const keys = [
                        'title',
                        'text',
                        'html',
                        'type',
                        'input',
                        'confirm',
                        'cancel',
                        'method',
                        'data',
                        'redirect',
                        'preConfirmValidate'
                    ];
                    let options = [];
                    for (let i = 0; i < keys.length; i++) {
                        let value = $target.data('add-swal-' + keys[i]);
                        if (!hasValue(value)) {
                            continue;
                        }
                        options[keys[i]] = value;
                    }
                    CommonEvent.ShowSwal($target.data('add-swal'), options);
                });
            }).addClass('added-swal');
        }
        /**
         * if click grid row, move page
         */
        static tableHoverLink() {
            $('table').find('[data-id]').closest('tr').not('.tableHoverLinkEvent').on('click', function (ev) {
                // if e.target closest"a" is length > 0, return
                if ($(ev.target).closest('a').length > 0) {
                    return;
                }
                if ($(ev.target).closest('.popover').length > 0) {
                    return;
                }
                let editFlg = $('#gridrow_select_edit').val();
                let linkElem = $(ev.target).closest('tr').find('.rowclick');
                if (editFlg) {
                    if (!hasValue(linkElem)) {
                        linkElem = $(ev.target).closest('tr').find('.fa-edit');
                    }
                    if (!hasValue(linkElem)) {
                        linkElem = $(ev.target).closest('tr').find('.fa-eye');
                    }
                }
                else {
                    if (!hasValue(linkElem)) {
                        linkElem = $(ev.target).closest('tr').find('.fa-eye');
                    }
                    if (!hasValue(linkElem)) {
                        linkElem = $(ev.target).closest('tr').find('.fa-edit');
                    }
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
         * Set changedata event
         */
        static setChangedataEvent(datalist) {
            // loop "data-changedata" targets   
            for (var key in datalist) {
                var data = datalist[key];
                // set change event
                $('.box-body').on('change', CommonEvent.getClassKey(key), { data: data }, (ev) => __awaiter(this, void 0, void 0, function* () {
                    yield CommonEvent.changeModelData($(ev.target), ev.data.data);
                }));
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
                        $(d.to_block).on('click', '.add', { key: key, data: target_table_data, index: i, table_name: table_name }, (ev) => __awaiter(this, void 0, void 0, function* () {
                            // get target
                            var $target = CommonEvent.getParentRow($(ev.target)).find(CommonEvent.getClassKey(ev.data.key));
                            var data = ev.data.data;
                            // set to_lastindex matched index
                            for (var i = 0; i < data.length; i++) {
                                if (i != ev.data.index) {
                                    continue;
                                }
                                data[i]['to_lastindex'] = true;
                            }
                            // create rensou array.
                            var modelArray = {};
                            modelArray[ev.data.table_name] = data;
                            yield CommonEvent.changeModelData($target, modelArray);
                        }));
                    }
                }
            }
        }
        /**
        * get model and change value
        */
        static changeModelData($target, data = null) {
            return __awaiter(this, void 0, void 0, function* () {
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
                            yield CommonEvent.setModelItem(null, $parent, $target, target_table_data);
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
                            .done(function (modeldata) {
                            return __awaiter(this, void 0, void 0, function* () {
                                yield CommonEvent.setModelItem(modeldata, $parent, $target, this.data);
                                $d.resolve();
                            });
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
            });
        }
        /**
         * set getmodel or getitem data to form
         */
        static setModelItem(modeldata, $changedata_target, $elem, options) {
            var $elem;
            return __awaiter(this, void 0, void 0, function* () {
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
                    $elem = $changedata_target.find(CommonEvent.getClassKey(option.to));
                    if (!hasValue(modeldata)) {
                        yield CommonEvent.setValue($elem, null);
                        //$elem.val('');
                    }
                    else {
                        // get element value from model
                        var from = modeldata['value'][option.from];
                        yield CommonEvent.setValue($elem, from);
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
                                yield CommonEvent.setCalc($filterTo, calcData.data);
                            }
                        }
                    }
                }
            });
        }
        /**
         * set getmodel or getitem data to form
         */
        static setModelItemKey($target, $parent, data) {
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
        static setRelatedLinkageEvent(datalist) {
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
        static linkage($target, url, val, expand, linkage_text) {
            var $d = $.Deferred();
            // create querystring
            if (!hasValue(expand)) {
                expand = {};
            }
            if (!hasValue(linkage_text)) {
                linkage_text = 'text';
            }
            expand['q'] = val;
            var query = $.param(expand);
            $.get(url + '?' + query, function (json) {
                $target.find("option").remove();
                var options = [];
                options.push({ id: '', text: '' });
                $.each(json, function (index, d) {
                    options.push({ id: hasValue(d.id) ? d.id : '', text: d[linkage_text] });
                });
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
         * set calc
         * data : has "to" and "options". options has properties "val" and "type"
         *
         */
        static setCalc($target, data) {
            return __awaiter(this, void 0, void 0, function* () {
                if (!hasValue(data)) {
                    return;
                }
                var $parent = null;
                // if not found target, set root.
                if (hasValue($target)) {
                    $parent = CommonEvent.getParentRow($target);
                }
                if (!hasValue($parent)) {
                    $parent = $('.box-body');
                }
                // loop for calc target.
                for (var i = 0; i < data.length; i++) {
                    // for creating array contains object "value0" and "calc_type" and "value1".
                    var formula_list = [];
                    var $to = $parent.find(CommonEvent.getClassKey(data[i].to));
                    if (data[i].is_default) {
                        $to = $('.box-body').find(CommonEvent.getClassKey(data[i].to)).first();
                    }
                    for (var j = 0; j < data[i].options.length; j++) {
                        var val = 0;
                        // calc option
                        var option = data[i].options[j];
                        // when fixed value
                        if (option.type == 'fixed') {
                            formula_list.push(rmcomma(option.val));
                        }
                        // when dynamic value, get value
                        else if (option.type == 'dynamic') {
                            val = rmcomma($parent.find(CommonEvent.getClassKey(option.val)).val());
                            if (!hasValue(val)) {
                                val = 0;
                            }
                            formula_list.push(val);
                        }
                        // when summary value, get value
                        else if (option.type == 'summary') {
                            var sub_formula_list = [];
                            $('.box-body').find('.has-many-' + option.relation_name + '-form:visible, .has-many-table-' + option.relation_name + '-row:visible').find(CommonEvent.getClassKey(option.val)).each(function () {
                                if (hasValue($(this).val())) {
                                    sub_formula_list.push($(this).val());
                                }
                            });
                            if (sub_formula_list.length > 0) {
                                formula_list.push('(' + sub_formula_list.join(' + ') + ')');
                            }
                            else {
                                formula_list.push(0);
                            }
                        }
                        // when count value, get count
                        else if (option.type == 'count') {
                            val = $('.box-body').find('.has-many-' + option.relation_name + '-form:visible, .has-many-table-' + option.relation_name + '-row:visible').length;
                            if (!hasValue(val)) {
                                val = 0;
                            }
                            formula_list.push(val);
                        }
                        // when select_table value, get value from table
                        else if (option.type == 'select_table') {
                            // find select target table
                            var $select = $parent.find(CommonEvent.getClassKey(option.val));
                            var table_name = $select.data('target_table_name');
                            // get selected table model
                            var model = yield CommonEvent.findModel(table_name, $select.val());
                            // get value
                            if (hasValue(model)) {
                                val = model['value'][option.from];
                                if (!hasValue(val)) {
                                    val = 0;
                                }
                            }
                            formula_list.push(val);
                        }
                        // when symbol
                        else if (option.type == 'symbol') {
                            switch (option.val) {
                                case 'plus':
                                    formula_list.push('+');
                                    break;
                                case 'minus':
                                    formula_list.push('-');
                                    break;
                                case 'times':
                                    formula_list.push('*');
                                    break;
                                case 'div':
                                    formula_list.push('/');
                                    break;
                            }
                        }
                    }
                    var precision = math.evaluate(formula_list.join(' '));
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
                            yield CommonEvent.setCalc($filterTo, calcData.data);
                        }
                    }
                }
            });
        }
        /**
         * find table data
         * @param table_name
         * @param value
         * @param context
         */
        static findModel(table_name, value, context = null) {
            var $d = $.Deferred();
            if (!hasValue(value)) {
                $d.resolve(null);
            }
            else {
                $.ajax({
                    url: admin_url(URLJoin('webapi', 'data', table_name, value)),
                    type: 'GET',
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
        static setValue($target, value) {
            if (!hasValue($target)) {
                return;
            }
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
                if (hasValue(value)) {
                    var bn = new BigNumber(value);
                    value = bn.integerValue().toPrecision();
                }
            }
            // if 'decimal' or 'currency', floor 
            if ($.inArray(column_type, ['decimal', 'currency']) != -1 && hasValue($target.attr('decimal_digit'))) {
                if (hasValue(value)) {
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
        static addSelect2() {
            $('[data-add-select2]').not('.added-select2').each(function (index, elem) {
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
                                page: params.page,
                            };
                        },
                        processResults: function (data, params) {
                            if (!hasValue(data) || !hasValue(data.data)) {
                                return { results: [] };
                            }
                            params.page = params.page || 1;
                            return {
                                results: $.map(data.data, function (d) {
                                    d.id = d.id;
                                    d.text = hasValue(d.text) ? d.text : d.label; // label is custom value label appended.
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
        /**
         * add field event (datepicker, icheck)
         */
        static addFieldEvent() {
            $('[data-add-date]').not('.added-datepicker').each(function (index, elem) {
                $(elem).datetimepicker({ "useCurrent": false, "format": "YYYY-MM-DD", "locale": "ja", "allowInputToggle": true });
            }).addClass('added-datepicker');
            $('[data-add-icheck]').not('.added-icheck').each(function (index, elem) {
                $(elem).iCheck({ checkboxClass: 'icheckbox_minimal-blue' });
            }).addClass('added-icheck');
        }
        static getFilterVal($parent, a) {
            // get filter object
            let $filterObj = $parent.find(CommonEvent.getClassKey(a.key));
            // if redio
            if ($filterObj.is(':radio')) {
                $filterObj = $filterObj.filter(':checked');
                return hasValue($filterObj) ? $filterObj.val() : null;
            }
            $filterObj = $filterObj.filter(':last');
            // if checkbox
            if ($filterObj.is(':checkbox')) {
                return $filterObj.is(':checked') ? $filterObj.val() : null;
            }
            return $filterObj.val();
        }
        static getParentRow($query) {
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
        static getClassKey(key, prefix = '') {
            return '.' + prefix + key + ',.' + prefix + 'value_' + key;
        }
        static findValue(values, keys) {
            if (!hasValue(values)) {
                return false;
            }
            keys = !Array.isArray(keys) ? keys.split(',') : keys;
            values = !Array.isArray(values) ? values.split(',') : values;
            for (let i = 0; i < keys.length; i++) {
                for (let j = 0; j < values.length; j++) {
                    if (keys[i] == values[j]) {
                        return true;
                    }
                }
            }
            return false;
        }
    }
    CommonEvent.calcDataList = [];
    CommonEvent.relatedLinkageList = [];
    /**
     * call select2 items using linkage
     */
    CommonEvent.setRelatedLinkageChangeEvent = (ev) => {
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
    };
    /**
     * call select2 items using linkage
     */
    CommonEvent.setLinkageEvent = (ev) => {
        let $base = $(ev.target).closest('[data-linkage]');
        if (!hasValue($base)) {
            return;
        }
        let $parent = CommonEvent.getParentRow($base);
        let linkages = $base.data('linkage');
        if (!hasValue(linkages)) {
            return;
        }
        // get expand data
        let expand = $base.data('linkage-expand');
        if (!hasValue(expand)) {
            expand = {};
        }
        // get input data
        let getdata = $base.data('linkage-getdata');
        if (hasValue(getdata)) {
            // execute linkage event
            for (let i = 0; i < getdata.length; i++) {
                let g = getdata[i];
                let $getdata = $parent;
                if (hasValue(g.parent)) {
                    $getdata = CommonEvent.getParentRow($parent);
                }
                let key = g.key;
                let $target = $parent.find(CommonEvent.getClassKey(key));
                expand[key] = $target.val();
            }
        }
        let linkage_text = $base.data('linkage-text');
        // execute linkage event
        for (var key in linkages) {
            var link = linkages[key];
            var url = link;
            // if link is object and has 'text', set linkage_text
            if (link instanceof Object) {
                url = link.url;
                linkage_text = link.text;
            }
            var $target = $parent.find(CommonEvent.getClassKey(key));
            CommonEvent.linkage($target, url, $base.val(), expand, linkage_text);
        }
    };
    /**
     * Switch display / non-display according to the target input value
     * @param $target
     */
    CommonEvent.setFormFilter = ($target) => {
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
                    }
                    else {
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
                        }
                        else if (filterVal != null && a.nullValue) {
                            isShow = false;
                        }
                        if (a.value) {
                            if (!CommonEvent.findValue(filterVal, a.value)) {
                                isShow = false;
                            }
                        }
                        if (a.notValue) {
                            if (!hasValue(filterVal) || CommonEvent.findValue(filterVal, a.notValue)) {
                                isShow = false;
                            }
                        }
                    }
                    // change readonly attribute
                    if (!isReadOnly && a.readonlyValue) {
                        if (CommonEvent.findValue(filterVal, a.readonlyValue)) {
                            isReadOnly = true;
                        }
                    }
                }
                if (isShow) {
                    $eParent.show();
                    if ($t.parents().hasClass('bootstrap-switch')) {
                        $t.bootstrapSwitch('disabled', false);
                    }
                    else {
                        $t.prop('disabled', false);
                    }
                    // disabled false
                }
                else {
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
                }
                else {
                    if (propName != 'disabled' || isShow) {
                        $t.prop(propName, false);
                    }
                }
            }
            catch (e) {
            }
        }
    };
    /**
     * Set calc event
     */
    CommonEvent.setCalcEvent = (datalist) => {
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
            $('.box-body').on('change', CommonEvent.getClassKey(key), { data: data, key: key }, (ev) => __awaiter(this, void 0, void 0, function* () {
                yield CommonEvent.setCalc($(ev.target), ev.data.data);
            }));
            // set event for plus minus button
            $('.box-body').on('click', '.btn-number-plus,.btn-number-minus', { data: data, key: key }, (ev) => __awaiter(this, void 0, void 0, function* () {
                // call only has $target. $target is autocalc's key
                let $target = $(ev.target).closest('.input-group').find(CommonEvent.getClassKey(ev.data.key));
                if (!hasValue($target)) {
                    return;
                }
                yield CommonEvent.setCalc($target, ev.data.data);
            }));
        }
    };
    Exment.CommonEvent = CommonEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CommonEvent.AddEvent();
    Exment.CommonEvent.AddEventOnce();
});
const URLJoin = (...args) => args
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
};
const hasValue = (obj) => {
    if (obj == null || obj == undefined || obj.length == 0) {
        return false;
    }
    return true;
};
const comma = (x) => {
    if (x === null || x === undefined) {
        return x;
    }
    return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
};
const rmcomma = (x) => {
    if (x === null || x === undefined) {
        return x;
    }
    return x.toString().replace(/,/g, '');
};
const trimAny = function (str, any) {
    if (!hasValue(str)) {
        return str;
    }
    return str.replace(new RegExp("^" + any + "+|" + any + "+$", "g"), '');
};
const entityMap = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
    '/': '&#x2F;',
    '`': '&#x60;',
    '=': '&#x3D;'
};
function escHtml(string) {
    if (!string) {
        return string;
    }
    return String(string).replace(/[&<>"'`=\/]/g, function (s) {
        return entityMap[s];
    });
}
const selectedRow = function () {
    var id = $('.grid-row-checkbox:checked').eq(0).data('id');
    return id;
};
const selectedRows = function () {
    var rows = [];
    $('.grid-row-checkbox:checked').each((num, element) => {
        rows.push($(element).data('id'));
    });
    return rows;
};
const admin_base_path = function (path) {
    var urls = [];
    var admin_base_uri = trimAny($('#admin_base_uri').val(), '/');
    if (admin_base_uri.length > 0) {
        urls.push(admin_base_uri);
    }
    var prefix = trimAny($('#admin_prefix').val(), '/');
    if (hasValue(prefix)) {
        urls.push(prefix);
    }
    prefix = '/' + urls.join('/');
    prefix = (prefix == '/') ? '' : prefix;
    return prefix + '/' + trimAny(path, '/');
};
const admin_url = function (path) {
    return URLJoin($('#admin_uri').val(), path);
};
const getParamFromArray = function (array) {
    array = array.filter(function (x) {
        return (x.value !== (undefined || null || ''));
    });
    return $.param(array);
};
const serializeFromArray = function (form) {
    let param = {};
    $(form.serializeArray()).each(function (i, v) {
        // if name is array
        if (v.name.slice(-2) == '[]') {
            if (!hasValue(v.value)) {
                return;
            }
            let name = v.name.slice(0, -2);
            if (!hasValue(param[name])) {
                param[name] = [];
            }
            param[name].push(v.value);
        }
        else {
            param[v.name] = v.value;
        }
    });
    return param;
};
const getUuid = function () {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
        var r = Math.random() * 16 | 0, v = c == 'x' ? r : (r & 0x3 | 0x8);
        return v.toString(16);
    });
};
