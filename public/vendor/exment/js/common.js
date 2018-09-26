var Exment;
(function (Exment) {
    var CommonEvent = /** @class */ (function () {
        function CommonEvent() {
        }
        /**
         * Call only once. It's $(document).on event.
         */
        CommonEvent.AddEventOnce = function () {
            $(document).on('change focusout', '.subscription_agreement_start_date,.subscription_claim_type,.subscription_agreement_term', {}, CommonEvent.calcDate);
            $(document).on('click', '[for="subscription_agreement_term"] + div .btn', {}, CommonEvent.calcDate);
            $(document).on('change', '[data-changedata]', {}, CommonEvent.changeModelData);
            // $(document).on('click', '.input-group-btn .btn,.remove', {}, (ev: JQueryEventObject) => {
            //     CommonEvent.setCalc($(ev.target));
            // });
            $(document).on('ifChanged change check', '[data-filter],[data-filtertrigger]', {}, function (ev) {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('click', '.add,.remove', {}, function (ev) {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('change', '[data-linkage]', {}, CommonEvent.setLinkageEvent);
            $(document).on('pjax:complete', function (event) {
                CommonEvent.AddEvent();
            });
        };
        CommonEvent.AddEvent = function () {
            CommonEvent.addSelect2();
            // 表示・非表示は読み込み時に全レコード実行する
            CommonEvent.setFormFilter($('[data-filter]'));
            CommonEvent.tableHoverLink();
            CommonEvent.setchangedata();
            $.numberformat('[number_format]');
        };
        /**
         * if click grid row, move page
         */
        CommonEvent.tableHoverLink = function () {
            $('table.table-hover').find('[data-id]').closest('tr').on('click', function (ev) {
                //e.targetはクリックした要素自体、それがa要素以外であれば
                if ($(ev.target).closest('a').length > 0) {
                    return;
                }
                //その要素の先祖要素で一番近いtrの
                //data-href属性の値に書かれているURLに遷移する
                var linkElem = $(ev.target).closest('tr').find('.fa-edit');
                if (!hasValue(linkElem)) {
                    linkElem = $(ev.target).closest('tr').find('.fa-eye');
                }
                if (!hasValue(linkElem)) {
                    return;
                }
                linkElem.closest('a').click();
            });
        };
        /**
         * add comma and remove comma if focus
         */
        CommonEvent.numberComma = function () {
        };
        /**
         * Set changedata event
         */
        CommonEvent.setChangedataEvent = function (datalist) {
            // loop "data-changedata" targets   
            for (var key in datalist) {
                var data = datalist[key];
                // set change event
                $(document).on('change', CommonEvent.getClassKey(key), { data: data }, CommonEvent.changeModelData);
                // var options = $target.data('changedata');
                // for (var index in options) {
                //     var option = options[index];
                //     // target endpoint name
                //     var target_table_name = option.target_table_name;
                //     var target_column_name = option.target_column_name;
                //     // changedata target element
                //     var $changedata_target = CommonEvent.getParentRow($target).find('.' + target_column_name);
                //     if($changedata_target.length == 0){
                //         continue;
                //     }
                //     // get changedata_target
                //     var changedata_target_datalist = $changedata_target.data('changedata_target');
                //     if(!hasValue(changedata_target_datalist)){
                //         changedata_target_datalist = [];
                //     }
                //     // has_target_table_name
                //     var changedata_target_data = null;
                //     for(var dataindex in changedata_target_datalist){
                //         // same table name,     
                //         if(changedata_target_datalist[dataindex].target_table_name == target_table_name){
                //             changedata_target_data = 
                //         }
                //     }
                //     // get event target object key
                //     var key = options[index].key;
                //     $(document).on('change', '.' + key, {}, (ev) => { CommonEvent.setCalc($(ev.target)); });
                // }
            }
        };
        /**
         * set getmodel or getitem data to form
         */
        CommonEvent.setModelItem = function ($target, $parent, modeldata, changedata_from, changedata_to) {
            var $elem = $parent.find(CommonEvent.getClassKey(changedata_to));
            if (!hasValue(modeldata)) {
                $elem.val('');
            }
            else {
                // get element value from model
                var from = modeldata['value'][changedata_from];
                // copy to element from model
                var val = $elem.prop('number_format') ? comma(from) : from;
                $elem.val(val);
            }
            CommonEvent.setFormFilter($target);
        };
        /**
         * set getmodel or getitem data to form
         */
        CommonEvent.setModelItemKey = function ($target, $parent, data) {
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
                    var val = $e.prop('number_format') ? comma(data[key]) : data[key];
                    $e.val(val);
                    // if target-item is "iconpicker-input", set icon
                    if ($e.hasClass('iconpicker-input')) {
                        $e.closest('.iconpicker-container').find('i').removeClass().addClass('fa ' + val);
                    }
                }
            }
            CommonEvent.setFormFilter($target);
        };
        /**
         * call select2 items using changedata
         */
        CommonEvent.setchangedata = function () {
            var $d = $.Deferred();
            var $targets = $('[data-changedata-from]');
            for (var i = 0; i < $targets.length; i++) {
                var $target = $targets.eq(i);
                if ($target.children('option').length > 0) {
                    var continueFlg = false;
                    for (var j = 0; j < $target.children('option').length; j++) {
                        if (hasValue($target.children('option').eq(j).val())) {
                            continueFlg = true;
                            break;
                        }
                    }
                    if (continueFlg) {
                        continue;
                    }
                }
                var $parent = CommonEvent.getParentRow($target);
                var link = $target.data('changedata-from');
                var $base = $parent.find('.' + link);
                if (!hasValue($base.val())) {
                    continue;
                }
                var data = $base.data('changedata');
                if (!hasValue(data)) {
                    return;
                }
                var changedatas = data.changedata;
                if (hasValue(changedatas)) {
                    for (var key in changedatas) {
                        if (!$target.hasClass(key)) {
                            continue;
                        }
                        console.log('changedata from setchangedata');
                        CommonEvent.changedata($target, changedatas[key], $base.val());
                    }
                }
            }
        };
        CommonEvent.changedata = function ($target, url, val) {
            var $d = $.Deferred();
            console.log('start changedata. url : ' + url + ', q=' + val);
            $.get(url + '?q=' + val, function (data) {
                $target.find("option").remove();
                $target.select2({
                    data: $.map(data, function (d) {
                        d.id = hasValue(d.id) ? d.id : '';
                        d.text = d.text;
                        return d;
                    })
                }).trigger('change');
                $d.resolve();
            });
            return $d.promise();
        };
        CommonEvent.linkage = function ($target, url, val) {
            var $d = $.Deferred();
            console.log('start linkage. url : ' + url + ', q=' + val);
            $.get(url + '?q=' + val, function (data) {
                $target.find("option").remove();
                $target.select2({
                    data: $.map(data, function (d) {
                        d.id = hasValue(d.id) ? d.id : '';
                        d.text = d.text;
                        return d;
                    })
                }).trigger('change');
                $d.resolve();
            });
            return $d.promise();
        };
        /**
         * select2の追加
         */
        CommonEvent.addSelect2 = function () {
            $('[data-add-select2]').not('.added-select2').each(function (index, elem) {
                $(elem).select2({
                    "allowClear": true, "placeholder": $(elem).data('add-select2'), width: '100%'
                });
            }).addClass('added-select2');
        };
        CommonEvent.getFilterVal = function ($parent, a) {
            // get filter object
            var $filterObj = $parent.find(CommonEvent.getClassKey(a.key)).filter(':last');
            if ($filterObj.is(':checkbox')) {
                return $filterObj.is(':checked') ? $filterObj.val() : null;
            }
            return $filterObj.val();
        };
        CommonEvent.getParentRow = function ($query) {
            if ($query.closest('tr').length > 0) {
                return $query.closest('tr');
            }
            //return $query.closest('.fields-group');
            // if hasClass ".fields-group" in $query, return parents
            if ($query.hasClass('fields-group')) {
                return $query.parents('.fields-group').eq(0);
            }
            return $query.closest('.fields-group');
        };
        CommonEvent.getClassKey = function (key, prefix) {
            if (prefix === void 0) { prefix = ''; }
            return '.' + prefix + key + ',.' + prefix + 'value_' + key;
        };
        /**
        * 日付の計算
        */
        CommonEvent.calcDate = function () {
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
        * モデルを取得、値の変更
        */
        CommonEvent.changeModelData = function (ev) {
            var $d = $.Deferred();
            // get model name
            var $target = $(ev.target);
            // get parent element from the form field.
            var $parent = CommonEvent.getParentRow($target);
            // get data
            var data = ev.data.data;
            if (hasValue(data)) {
                // loop for model table
                for (var i = 0; i < data.length; i++) {
                    var d = data[i];
                    // get selected model
                    var target_table = d.target_table;
                    if (!hasValue(target_table)) {
                        continue;
                    }
                    // get value.
                    var value = $target.val();
                    if (!hasValue(value)) {
                        CommonEvent.setModelItem($target, $parent, null, d.from, d.to);
                        continue;
                    }
                    $.ajaxSetup({
                        headers: {
                            'X-CSRF-TOKEN': $('[name="_token"]').val()
                        }
                    });
                    $.ajax({
                        url: admin_base_path(URLJoin('api', target_table, value)),
                        type: 'POST'
                    })
                        .done(function (modeldata) {
                        CommonEvent.setModelItem($target, $parent, modeldata, d.from, d.to);
                    })
                        .fail(function (errordata) {
                        console.log(errordata);
                    });
                }
            }
            // getItem
            var changedata_data = $(ev.target).data('changedata');
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
                    data: send_data
                })
                    .done(function (data) {
                    CommonEvent.setModelItemKey($target, $parent, data);
                })
                    .fail(function (data) {
                    console.log(data);
                });
            }
            // // changedata
            // var changedatas = data.changedata;
            // if (hasValue(changedatas)) {
            //     for (var k in changedatas) {
            //         console.log('changedata from changeModelData');
            //         CommonEvent.changedata($parent.find("." + k), changedatas[k], $target.val());
            //     }
            // }
        };
        /**
         * call select2 items using linkage
         */
        CommonEvent.setLinkageEvent = function (ev) {
            var $d = $.Deferred();
            var $base = $(ev.target).closest('[data-linkage]');
            if (!hasValue($base)) {
                return;
            }
            // if ($target.children('option').length > 0) {
            //     var continueFlg = false;
            //     for (var j = 0; j < $target.children('option').length; j++) {
            //         if (hasValue($target.children('option').eq(j).val())) {
            //             continueFlg = true;
            //             break;
            //         }
            //     }
            //     if (continueFlg) {
            //         return;
            //     }
            // }
            var $parent = CommonEvent.getParentRow($base);
            var linkages = $base.data('linkage');
            // var $base = $parent.find(CommonEvent.getClassKey(link));
            // if (!hasValue($base.val())) {
            //     continue;
            // }
            // var linkages = data.linkage;
            if (hasValue(linkages)) {
                for (var key in linkages) {
                    var link = linkages[key];
                    var $target = $parent.find(CommonEvent.getClassKey(key));
                    console.log('linkage from setLinkage');
                    CommonEvent.linkage($target, link, $base.val());
                }
            }
        };
        /**
         * 対象のセレクトボックスの値に応じて、表示・非表示を切り替える
         * @param $target
         */
        CommonEvent.setFormFilter = function ($target) {
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
                        // remove value
                        $t.val('');
                    }
                    if (isReadOnly) {
                        $t.prop('readonly', true);
                    }
                    else {
                        $t.prop('readonly', false);
                    }
                }
                catch (e) {
                }
            }
        };
        /**
         * Set calc event
         */
        CommonEvent.setCalcEvent = function (datalist) {
            // loop "data-calc" targets   
            for (var key in datalist) {
                var data = datalist[key];
                // set calc event
                $(document).on('change', CommonEvent.getClassKey(key), { data: data }, function (ev) {
                    CommonEvent.setCalc($(ev.target), ev.data.data);
                });
                // set event for plus minus button
                $(document).on('click', CommonEvent.getClassKey(key, 'btn-number-'), { data: data, key: key }, function (ev) {
                    CommonEvent.setCalc($(ev.target).closest('.input-group').find(CommonEvent.getClassKey(ev.data.key)), ev.data.data);
                });
            }
        };
        /**
         * set calc
         */
        CommonEvent.setCalc = function ($target, data) {
            var $parent = CommonEvent.getParentRow($target);
            if (!hasValue(data)) {
                return;
            }
            // loop for calc target
            for (var i = 0; i < data.length; i++) {
                var values = [];
                var calc_type = null;
                var $to = $parent.find(CommonEvent.getClassKey(data[i].to));
                for (var j = 0; j < data[i].options.length; j++) {
                    // calc option
                    var option = data[i].options[j];
                    // when fixed value
                    if (option.type == 'fixed') {
                        values.push(rmcomma(option.val));
                    }
                    // when dynamic value, get value
                    else if (option.type == 'dynamic') {
                        var val = rmcomma($parent.find(CommonEvent.getClassKey(option.val)).val());
                        if (!hasValue(val)) {
                            val = 0;
                        }
                        values.push(val);
                    }
                    // when symbol
                    else if (option.type == 'symbol') {
                        calc_type = option.val;
                    }
                }
                if (!calc_type) {
                    continue;
                }
                if (values.length < 2) {
                    continue;
                }
                // get value
                var bn = new BigNumber(values[0]);
                switch (calc_type) {
                    case 'plus':
                        bn = bn.plus(values[1]);
                        break;
                    case 'minus':
                        bn = bn.minus(values[1]);
                        break;
                    case 'times':
                        bn = bn.times(values[1]);
                        break;
                    case 'div':
                        if (values[1] == 0) {
                            bn = new BigNumber(0);
                        }
                        else {
                            bn = bn.div(values[1]);
                        }
                        break;
                }
                $to.val(bn.toPrecision());
            }
        };
        return CommonEvent;
    }());
    Exment.CommonEvent = CommonEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CommonEvent.AddEvent();
    Exment.CommonEvent.AddEventOnce();
});
var URLJoin = function () {
    var args = [];
    for (var _i = 0; _i < arguments.length; _i++) {
        args[_i] = arguments[_i];
    }
    return args
        .join('/')
        .replace(/[\/]+/g, '/')
        .replace(/^(.+):\//, '$1://')
        .replace(/^file:/, 'file:/')
        .replace(/\/(\?|&|#[^!])/g, '$1')
        .replace(/\?/g, '&')
        .replace('&', '?');
};
var pInt = function (obj) {
    if (!hasValue(obj)) {
        return 0;
    }
    obj = obj.toString().replace(/,/g, '');
    return parseInt(obj);
};
var hasValue = function (obj) {
    if (obj == null || obj == undefined || obj.length == 0) {
        return false;
    }
    return true;
};
//const comma = (x) => {
//    return rmcomma(x).replace(/(\d)(?=(?:\d{3}){2,}(?:\.|$))|(\d)(\d{3}(?:\.\d*)?$)/g
//        , '$1$2,$3');
//}
var comma = function (x) {
    return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
};
var rmcomma = function (x) {
    return x.toString().replace(',', '');
};
var trimAny = function (str, any) {
    return str.replace(new RegExp("^" + any + "+|" + any + "+$", "g"), '');
};
var selectedRow = function () {
    var id = $('.grid-row-checkbox:checked').eq(0).data('id');
    return id;
};
var selectedRows = function () {
    var rows = [];
    $('.grid-row-checkbox:checked').each(function (num, element) {
        rows.push($(element).data('id'));
    });
    return rows;
};
var admin_base_path = function (path) {
    var prefix = '/' + trimAny($('#admin_base_path').val(), '/');
    prefix = (prefix == '/') ? '' : prefix;
    return prefix + '/' + trimAny(path, '/');
};
