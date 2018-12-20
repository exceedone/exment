var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : new P(function (resolve) { resolve(result.value); }).then(fulfilled, rejected); }
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
            $(document).on('switchChange.bootstrapSwitch', '[data-filter],[data-filtertrigger]', {}, (ev, state) => {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('change', '[data-linkage]', {}, CommonEvent.setLinkageEvent);
            $(document).on('pjax:complete', function (event) {
                CommonEvent.AddEvent();
            });
            $(function () {
                CommonEvent.GetVersion();
            });
        }
        static AddEvent() {
            CommonEvent.addSelect2();
            CommonEvent.setFormFilter($('[data-filter]'));
            CommonEvent.tableHoverLink();
            $.numberformat('[number_format]');
        }
        static GetVersion() {
            if ($('#version').text().length > 0) {
                return;
            }
            $.ajax({
                url: admin_base_path('webapi/version'),
                type: 'GET',
            })
                .done(function (data) {
                $('#version').text(data);
            })
                .fail(function (data) {
                console.log(data);
            });
        }
        /**
         *
         */
        static CallbackExmentAjax(res) {
            if (res.result === true) {
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
                }
                else {
                    toastr.error('Undeifned Error');
                }
            }
        }
        static redirectCallback(res) {
            if (hasValue(res.redirect)) {
                $.pjax({ container: '#pjax-container', url: res.redirect });
            }
            else {
                $.pjax.reload('#pjax-container');
            }
        }
        static ShowSwal(url, options = []) {
            options = $.extend({
                title: 'Swal',
                confirm: 'OK',
                cancel: 'Cancel',
                method: 'POST',
                data: [],
            }, options);
            var data = $.extend({
                _pjax: true,
                _token: LA.token,
            }, options.data);
            swal({
                title: options.title,
                type: "warning",
                showCancelButton: true,
                confirmButtonColor: "#DD6B55",
                confirmButtonText: options.confirm,
                showLoaderOnConfirm: true,
                allowOutsideClick: false,
                cancelButtonText: options.cancel,
                preConfirm: function () {
                    return new Promise(function (resolve) {
                        $.ajax({
                            type: options.method,
                            url: url,
                            //container: "#pjax-container",
                            data: data,
                            success: function (repsonse) {
                                Exment.CommonEvent.CallbackExmentAjax(repsonse);
                                resolve(repsonse);
                            },
                            error: function (repsonse) {
                                Exment.CommonEvent.CallbackExmentAjax(repsonse);
                                //toastr.error(repsonse.message);
                                //reject(repsonse);
                            }
                        });
                    });
                }
            });
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
                //その要素の先祖要素で一番近いtrの
                //data-href属性の値に書かれているURLに遷移する
                var linkElem = $(ev.target).closest('tr').find('.fa-eye');
                if (!hasValue(linkElem)) {
                    linkElem = $(ev.target).closest('tr').find('.fa-edit');
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
                            url: admin_base_path(URLJoin('webapi', 'data', table_name, value)),
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
                        $elem.val('');
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
            // 取得した要素でループ
            for (var key in data) {
                //id系は除外
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
                    if ($e.hasClass('iconpicker-input')) {
                        $e.closest('.iconpicker-container').find('i').removeClass().addClass('fa ' + val);
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
        static linkage($target, url, val, expand) {
            var $d = $.Deferred();
            // create querystring
            if (!hasValue(expand)) {
                expand = {};
            }
            expand['q'] = val;
            var query = $.param(expand);
            $.get(url + '?' + query, function (json) {
                $target.find("option").remove();
                $target.select2({
                    data: $.map(json, function (d) {
                        d.id = hasValue(d.id) ? d.id : '';
                        d.text = d.text;
                        return d;
                    }),
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
                // if not found target, return.
                if (!hasValue($target)) {
                    return;
                }
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
                        var val = 0;
                        // calc option
                        var option = data[i].options[j];
                        // when fixed value
                        if (option.type == 'fixed') {
                            value_item.values.push(rmcomma(option.val));
                        }
                        // when dynamic value, get value
                        else if (option.type == 'dynamic') {
                            val = rmcomma($parent.find(CommonEvent.getClassKey(option.val)).val());
                            if (!hasValue(val)) {
                                val = 0;
                            }
                            value_item.values.push(val);
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
                                }
                                else {
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
                    url: admin_base_path(URLJoin('webapi', 'data', table_name, value)),
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
        static setValue($target, value) {
            if (!hasValue($target)) {
                return;
            }
            var column_type = $target.data('column_type');
            var isNumber = $.inArray(column_type, ['integer', 'decimal', 'currency']);
            // if number, remove comma
            if (isNumber) {
                value = rmcomma(value);
            }
            // if integer, floor value
            if (column_type == 'integer') {
                var bn = new BigNumber(value);
                value = bn.integerValue().toPrecision();
            }
            // if 'decimal' or 'currency', floor 
            if ($.inArray(column_type, ['decimal', 'currency']) && hasValue($target.attr('decimal_digit'))) {
                var bn = new BigNumber(value);
                value = bn.decimalPlaces(pInt($target.attr('decimal_digit'))).toPrecision();
            }
            // if number format, add comma
            if (isNumber && $target.attr('number_format')) {
                value = comma(value);
            }
            // set value
            $target.val(value);
        }
        /**
         * add select2
         */
        static addSelect2() {
            $('[data-add-select2]').not('.added-select2').each(function (index, elem) {
                var $elem = $(elem);
                var options = {
                    "allowClear": true, "placeholder": $elem.data('add-select2'), width: '100%'
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
                            if (!hasValue(data) || !hasValue(data.data)) {
                                return { results: [] };
                            }
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
        static getFilterVal($parent, a) {
            // get filter object
            var $filterObj = $parent.find(CommonEvent.getClassKey(a.key)).filter(':last');
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
    }
    CommonEvent.calcDataList = [];
    CommonEvent.relatedLinkageList = [];
    /**
    * 日付の計算
    */
    CommonEvent.calcDate = () => {
        var $type = $('.subscription_claim_type');
        var $start_date = $('.subscription_agreement_start_date');
        var $term = $('.subscription_agreement_term');
        var $end_date = $('.subscription_agreement_limit_date');
        var term = pInt($term.val());
        if (!$type.val() || !$start_date.val()) {
            return;
        }
        // 日付計算
        var dt = new Date($('.subscription_agreement_start_date').val());
        if ($type.val() == 'month') {
            dt.setMonth(dt.getMonth() + term);
        }
        else if ($type.val() == 'year') {
            dt.setFullYear(dt.getFullYear() + term);
        }
        dt.setDate(dt.getDate() - 1);
        // セット
        $end_date.val(dt.getFullYear() + '-'
            + ('00' + (dt.getMonth() + 1)).slice(-2)
            + '-' + ('00' + dt.getDate()).slice(-2));
    };
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
        // execute linkage event
        for (var key in linkages) {
            var link = linkages[key];
            var $target = $parent.find(CommonEvent.getClassKey(key));
            CommonEvent.linkage($target, link, $base.val(), expand);
        }
    };
    /**
     * 対象のセレクトボックスの値に応じて、表示・非表示を切り替える
     * @param $target
     */
    CommonEvent.setFormFilter = ($target) => {
        $target = CommonEvent.getParentRow($target).find('[data-filter]');
        for (var tIndex = 0; tIndex < $target.length; tIndex++) {
            var $t = $target.eq(tIndex);
            // 表示フィルターを掛ける場合
            //if (!$t.data('filter')) {
            //    continue;
            //}
            // そのinputの親要素取得
            var $parent = CommonEvent.getParentRow($t);
            // 行の要素取得
            var $eParent = $t.parents('.form-group');
            //var $elem = $parent.find('[data-filter-target]'); // 表示非表示対象
            // 検索対象のキー・値取得
            try {
                var array = $t.data('filter');
                // 配列でない場合、配列に変換
                if (!Array.isArray(array)) {
                    array = [array];
                }
                var isShow = true;
                var isReadOnly = false;
                for (var index = 0; index < array.length; index++) {
                    var a = array[index];
                    // そのkeyを持つclassの値取得
                    // 最終的に送信されるのは最後の要素なので、last-child付ける
                    // parent値ある場合
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
                        // nullかどうかのチェックの場合
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
                        // その値が、a.valueに含まれているか
                        if (a.value) {
                            var valueArray = !Array.isArray(a.value) ? a.value.split(',') : a.value;
                            if (valueArray.indexOf(filterVal) == -1) {
                                isShow = false;
                            }
                        }
                        if (a.notValue) {
                            var valueArray = !Array.isArray(a.notValue) ? a.notValue.split(',') : a.notValue;
                            if (valueArray.indexOf(filterVal) != -1) {
                                isShow = false;
                            }
                        }
                    }
                    // change readonly attribute
                    if (!isReadOnly && a.readonlyValue) {
                        var valueArray = !Array.isArray(a.readonlyValue) ? a.readonlyValue.split(',') : a.readonlyValue;
                        if (valueArray.indexOf(filterVal) != -1) {
                            isReadOnly = true;
                        }
                    }
                }
                if (isShow) {
                    $eParent.show();
                    // disabled false
                }
                else {
                    $eParent.hide();
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
                    $t.prop(propName, false);
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
                yield CommonEvent.setCalc($(ev.target).closest('.input-group').find(CommonEvent.getClassKey(ev.data.key)), ev.data.data);
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
//const comma = (x) => {
//    return rmcomma(x).replace(/(\d)(?=(?:\d{3}){2,}(?:\.|$))|(\d)(\d{3}(?:\.\d*)?$)/g
//        , '$1$2,$3');
//}
const comma = (x) => {
    return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
};
const rmcomma = (x) => {
    if (x === null || x === undefined) {
        return x;
    }
    return x.toString().replace(/,/g, '');
};
const trimAny = function (str, any) {
    return str.replace(new RegExp("^" + any + "+|" + any + "+$", "g"), '');
};
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
    urls.push(trimAny($('#admin_base_path').val(), '/'));
    var prefix = '/' + urls.join('/');
    prefix = (prefix == '/') ? '' : prefix;
    return prefix + '/' + trimAny(path, '/');
};
const getParamFromArray = function (array) {
    array = array.filter(function (x) {
        return (x.value !== (undefined || null || ''));
    });
    return $.param(array);
};
