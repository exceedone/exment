var __awaiter = (this && this.__awaiter) || function (thisArg, _arguments, P, generator) {
    return new (P || (P = Promise))(function (resolve, reject) {
        function fulfilled(value) { try { step(generator.next(value)); } catch (e) { reject(e); } }
        function rejected(value) { try { step(generator["throw"](value)); } catch (e) { reject(e); } }
        function step(result) { result.done ? resolve(result.value) : new P(function (resolve) { resolve(result.value); }).then(fulfilled, rejected); }
        step((generator = generator.apply(thisArg, _arguments || [])).next());
    });
};
var __generator = (this && this.__generator) || function (thisArg, body) {
    var _ = { label: 0, sent: function() { if (t[0] & 1) throw t[1]; return t[1]; }, trys: [], ops: [] }, f, y, t, g;
    return g = { next: verb(0), "throw": verb(1), "return": verb(2) }, typeof Symbol === "function" && (g[Symbol.iterator] = function() { return this; }), g;
    function verb(n) { return function (v) { return step([n, v]); }; }
    function step(op) {
        if (f) throw new TypeError("Generator is already executing.");
        while (_) try {
            if (f = 1, y && (t = op[0] & 2 ? y["return"] : op[0] ? y["throw"] || ((t = y["return"]) && t.call(y), 0) : y.next) && !(t = t.call(y, op[1])).done) return t;
            if (y = 0, t) op = [op[0] & 2, t.value];
            switch (op[0]) {
                case 0: case 1: t = op; break;
                case 4: _.label++; return { value: op[1], done: false };
                case 5: _.label++; y = op[1]; op = [0]; continue;
                case 7: op = _.ops.pop(); _.trys.pop(); continue;
                default:
                    if (!(t = _.trys, t = t.length > 0 && t[t.length - 1]) && (op[0] === 6 || op[0] === 2)) { _ = 0; continue; }
                    if (op[0] === 3 && (!t || (op[1] > t[0] && op[1] < t[3]))) { _.label = op[1]; break; }
                    if (op[0] === 6 && _.label < t[1]) { _.label = t[1]; t = op; break; }
                    if (t && _.label < t[2]) { _.label = t[2]; _.ops.push(op); break; }
                    if (t[2]) _.ops.pop();
                    _.trys.pop(); continue;
            }
            op = body.call(thisArg, _);
        } catch (e) { op = [6, e]; y = 0; } finally { f = t = 0; }
        if (op[0] & 5) throw op[1]; return { value: op[0] ? op[1] : void 0, done: true };
    }
};
var Exment;
(function (Exment) {
    var _this = this;
    var CommonEvent = /** @class */ (function () {
        function CommonEvent() {
        }
        /**
         * Call only once. It's $(document).on event.
         */
        CommonEvent.AddEventOnce = function () {
            var _this = this;
            $(document).on('change', '[data-changedata]', {}, function (ev) { return __awaiter(_this, void 0, void 0, function () { return __generator(this, function (_a) {
                switch (_a.label) {
                    case 0: return [4 /*yield*/, CommonEvent.changeModelData($(ev.target))];
                    case 1:
                        _a.sent();
                        return [2 /*return*/];
                }
            }); }); });
            $(document).on('ifChanged change check', '[data-filter],[data-filtertrigger]', {}, function (ev) {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('click', '.add,.remove', {}, function (ev) {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('switchChange.bootstrapSwitch', '[data-filter],[data-filtertrigger]', {}, function (ev, state) {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('change', '[data-linkage]', {}, CommonEvent.setLinkageEvent);
            $(document).on('pjax:complete', function (event) {
                CommonEvent.AddEvent();
            });
        };
        CommonEvent.AddEvent = function () {
            CommonEvent.ToggleHelp();
            CommonEvent.addSelect2();
            CommonEvent.setFormFilter($('[data-filter]'));
            CommonEvent.tableHoverLink();
            $.numberformat('[number_format]');
        };
        /**
         * toggle right-top help link and color
         */
        CommonEvent.ToggleHelp = function () {
            var help_urls = $('#help_urls').val();
            if (!hasValue(help_urls)) {
                return;
            }
            var helps = JSON.parse(help_urls);
            var pathname = location.pathname;
            var $manual = $('#manual_link');
            var manual_base_uri = $('#manual_base_uri').val();
            for (var i = 0; i < helps.length; i++) {
                var help = helps[i];
                // if match first current uri and pathname, set help url
                var uri = trimAny(admin_base_path(help.uri), '/');
                var isMatch = false;
                if (!hasValue(uri)) {
                    isMatch = trimAny(pathname, '/') == uri;
                }
                else {
                    isMatch = trimAny(pathname, '/').indexOf(uri) === 0;
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
        };
        /**
         *
         */
        CommonEvent.CallbackExmentAjax = function (res) {
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
        };
        CommonEvent.redirectCallback = function (res) {
            if (hasValue(res.reload) && res.reload === false) {
                return;
            }
            if (hasValue(res.redirect)) {
                $.pjax({ container: '#pjax-container', url: res.redirect });
            }
            else {
                $.pjax.reload('#pjax-container');
            }
        };
        CommonEvent.ShowSwal = function (url, options) {
            if (options === void 0) { options = []; }
            options = $.extend({
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
            }, options);
            var data = $.extend({
                _pjax: true,
                _token: LA.token,
            }, options.data);
            if (options.method.toLowerCase == 'delete') {
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
                    if (hasValue(options.preConfirmValidate)) {
                        var result = options.preConfirmValidate(input);
                        if (result !== true) {
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
            swal(swalOptions)
                .then(function (result) {
                var data = result.value;
                if (typeof data === 'object' && hasValue(data.message)) {
                    if (data.status === true || data.result === true) {
                        swal(data.message, '', 'success');
                    }
                    else {
                        swal(data.message, '', 'error');
                    }
                }
                else if (typeof data === 'string') {
                    swal(data, '', 'error');
                }
            });
        };
        /**
         * if click grid row, move page
         */
        CommonEvent.tableHoverLink = function () {
            $('table').find('[data-id]').closest('tr').not('.tableHoverLinkEvent').on('click', function (ev) {
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
        };
        /**
         * Set changedata event
         */
        CommonEvent.setChangedataEvent = function (datalist) {
            var _this = this;
            // loop "data-changedata" targets   
            for (var key in datalist) {
                var data = datalist[key];
                // set change event
                $('.box-body').on('change', CommonEvent.getClassKey(key), { data: data }, function (ev) { return __awaiter(_this, void 0, void 0, function () {
                    return __generator(this, function (_a) {
                        switch (_a.label) {
                            case 0: return [4 /*yield*/, CommonEvent.changeModelData($(ev.target), ev.data.data)];
                            case 1:
                                _a.sent();
                                return [2 /*return*/];
                        }
                    });
                }); });
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
                        $(d.to_block).on('click', '.add', { key: key, data: target_table_data, index: i, table_name: table_name }, function (ev) { return __awaiter(_this, void 0, void 0, function () {
                            var $target, data, i, modelArray;
                            return __generator(this, function (_a) {
                                switch (_a.label) {
                                    case 0:
                                        $target = CommonEvent.getParentRow($(ev.target)).find(CommonEvent.getClassKey(ev.data.key));
                                        data = ev.data.data;
                                        // set to_lastindex matched index
                                        for (i = 0; i < data.length; i++) {
                                            if (i != ev.data.index) {
                                                continue;
                                            }
                                            data[i]['to_lastindex'] = true;
                                        }
                                        modelArray = {};
                                        modelArray[ev.data.table_name] = data;
                                        return [4 /*yield*/, CommonEvent.changeModelData($target, modelArray)];
                                    case 1:
                                        _a.sent();
                                        return [2 /*return*/];
                                }
                            });
                        }); });
                    }
                }
            }
        };
        /**
        * get model and change value
        */
        CommonEvent.changeModelData = function ($target, data) {
            if (data === void 0) { data = null; }
            return __awaiter(this, void 0, void 0, function () {
                var $d, $parent, _a, _b, _i, table_name, target_table_data, value, changedata_data, getitem, send_data, index, key, $elem;
                return __generator(this, function (_c) {
                    switch (_c.label) {
                        case 0:
                            $d = $.Deferred();
                            $parent = CommonEvent.getParentRow($target);
                            if (!hasValue(data)) return [3 /*break*/, 5];
                            _a = [];
                            for (_b in data)
                                _a.push(_b);
                            _i = 0;
                            _c.label = 1;
                        case 1:
                            if (!(_i < _a.length)) return [3 /*break*/, 5];
                            table_name = _a[_i];
                            target_table_data = data[table_name];
                            if (!hasValue(target_table_data)) {
                                return [3 /*break*/, 4];
                            }
                            value = $target.val();
                            if (!!hasValue(value)) return [3 /*break*/, 3];
                            return [4 /*yield*/, CommonEvent.setModelItem(null, $parent, $target, target_table_data)];
                        case 2:
                            _c.sent();
                            return [3 /*break*/, 4];
                        case 3:
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
                                return __awaiter(this, void 0, void 0, function () {
                                    return __generator(this, function (_a) {
                                        switch (_a.label) {
                                            case 0: return [4 /*yield*/, CommonEvent.setModelItem(modeldata, $parent, $target, this.data)];
                                            case 1:
                                                _a.sent();
                                                $d.resolve();
                                                return [2 /*return*/];
                                        }
                                    });
                                });
                            })
                                .fail(function (errordata) {
                                console.log(errordata);
                                $d.reject();
                            });
                            _c.label = 4;
                        case 4:
                            _i++;
                            return [3 /*break*/, 1];
                        case 5:
                            changedata_data = $target.data('changedata');
                            if (hasValue(changedata_data)) {
                                getitem = changedata_data.getitem;
                                if (hasValue(getitem)) {
                                    send_data = {};
                                    send_data['value'] = $target.val();
                                    // get data-key
                                    for (index in getitem.key) {
                                        key = getitem.key[index];
                                        $elem = $parent.find(CommonEvent.getClassKey(key));
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
                            return [2 /*return*/, $d.promise()];
                    }
                });
            });
        };
        /**
         * set getmodel or getitem data to form
         */
        CommonEvent.setModelItem = function (modeldata, $changedata_target, $elem, options) {
            var $elem;
            return __awaiter(this, void 0, void 0, function () {
                var i, option, from, i, option, j, calcData, $filterTo;
                return __generator(this, function (_a) {
                    switch (_a.label) {
                        case 0:
                            i = 0;
                            _a.label = 1;
                        case 1:
                            if (!(i < options.length)) return [3 /*break*/, 7];
                            option = options[i];
                            // if has changedata_to_block, get $elem using changedata_to_block
                            if (hasValue(option.to_block)) {
                                $changedata_target = $(option.to_block);
                                // if has to_lastindex, get last children item
                                if (hasValue(option.to_lastindex)) {
                                    $changedata_target = $changedata_target.find(option.to_block_form).last();
                                }
                            }
                            $elem = $changedata_target.find(CommonEvent.getClassKey(option.to));
                            if (!!hasValue(modeldata)) return [3 /*break*/, 3];
                            return [4 /*yield*/, CommonEvent.setValue($elem, null)];
                        case 2:
                            _a.sent();
                            return [3 /*break*/, 5];
                        case 3:
                            from = modeldata['value'][option.from];
                            return [4 /*yield*/, CommonEvent.setValue($elem, from)];
                        case 4:
                            _a.sent();
                            _a.label = 5;
                        case 5:
                            // view filter execute
                            CommonEvent.setFormFilter($elem);
                            // add $elem to option
                            option['elem'] = $elem;
                            _a.label = 6;
                        case 6:
                            i++;
                            return [3 /*break*/, 1];
                        case 7:
                            i = 0;
                            _a.label = 8;
                        case 8:
                            if (!(i < options.length)) return [3 /*break*/, 13];
                            option = options[i];
                            $elem = option['elem'];
                            j = 0;
                            _a.label = 9;
                        case 9:
                            if (!(j < CommonEvent.calcDataList.length)) return [3 /*break*/, 12];
                            calcData = CommonEvent.calcDataList[j];
                            if (!(calcData.key == option.to)) return [3 /*break*/, 11];
                            $filterTo = $elem.filter(calcData.classKey);
                            if (!hasValue($filterTo)) return [3 /*break*/, 11];
                            return [4 /*yield*/, CommonEvent.setCalc($filterTo, calcData.data)];
                        case 10:
                            _a.sent();
                            _a.label = 11;
                        case 11:
                            j++;
                            return [3 /*break*/, 9];
                        case 12:
                            i++;
                            return [3 /*break*/, 8];
                        case 13: return [2 /*return*/];
                    }
                });
            });
        };
        /**
         * set getmodel or getitem data to form
         */
        CommonEvent.setModelItemKey = function ($target, $parent, data) {
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
        };
        /**
         * Set RelatedLinkage event
         */
        CommonEvent.setRelatedLinkageEvent = function (datalist) {
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
        };
        CommonEvent.linkage = function ($target, url, val, expand, linkage_text) {
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
        };
        /**
         * set calc
         * data : has "to" and "options". options has properties "val" and "type"
         *
         */
        CommonEvent.setCalc = function ($target, data) {
            return __awaiter(this, void 0, void 0, function () {
                var $parent, i, value_itemlist, value_item, $to, isfirst, j, val, option, $select, table_name, model, bn, j, v, precision, i, $to, _a, _b, _i, key, calcData, $filterTo;
                return __generator(this, function (_c) {
                    switch (_c.label) {
                        case 0:
                            // if not found target, return.
                            if (!hasValue($target)) {
                                return [2 /*return*/];
                            }
                            $parent = CommonEvent.getParentRow($target);
                            if (!hasValue(data)) {
                                return [2 /*return*/];
                            }
                            i = 0;
                            _c.label = 1;
                        case 1:
                            if (!(i < data.length)) return [3 /*break*/, 11];
                            value_itemlist = [];
                            value_item = { values: [], calc_type: null };
                            $to = $parent.find(CommonEvent.getClassKey(data[i].to));
                            isfirst = true;
                            j = 0;
                            _c.label = 2;
                        case 2:
                            if (!(j < data[i].options.length)) return [3 /*break*/, 9];
                            val = 0;
                            option = data[i].options[j];
                            if (!(option.type == 'fixed')) return [3 /*break*/, 3];
                            value_item.values.push(rmcomma(option.val));
                            return [3 /*break*/, 7];
                        case 3:
                            if (!(option.type == 'dynamic')) return [3 /*break*/, 4];
                            val = rmcomma($parent.find(CommonEvent.getClassKey(option.val)).val());
                            if (!hasValue(val)) {
                                val = 0;
                            }
                            value_item.values.push(val);
                            return [3 /*break*/, 7];
                        case 4:
                            if (!(option.type == 'select_table')) return [3 /*break*/, 6];
                            $select = $parent.find(CommonEvent.getClassKey(option.val));
                            table_name = $select.data('target_table_name');
                            return [4 /*yield*/, CommonEvent.findModel(table_name, $select.val())];
                        case 5:
                            model = _c.sent();
                            // get value
                            if (hasValue(model)) {
                                val = model['value'][option.from];
                                if (!hasValue(val)) {
                                    val = 0;
                                }
                            }
                            value_item.values.push(val);
                            return [3 /*break*/, 7];
                        case 6:
                            if (option.type == 'symbol') {
                                value_item.calc_type = option.val;
                            }
                            _c.label = 7;
                        case 7:
                            // if hasValue calc_type and values.length == 1 or first, set value_itemlist
                            if (hasValue(value_item.calc_type) &&
                                value_item.values.length >= 2 || (!isfirst && value_item.values.length >= 1)) {
                                value_itemlist.push(value_item);
                                // reset
                                value_item = { values: [], calc_type: null };
                                isfirst = false;
                            }
                            _c.label = 8;
                        case 8:
                            j++;
                            return [3 /*break*/, 2];
                        case 9:
                            bn = null;
                            for (j = 0; j < value_itemlist.length; j++) {
                                value_item = value_itemlist[j];
                                // if first item, new BigNumber using first item
                                if (value_item.values.length == 2) {
                                    bn = new BigNumber(value_item.values[0]);
                                }
                                v = value_item.values[value_item.values.length - 1];
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
                            precision = bn.toPrecision();
                            CommonEvent.setValue($to, precision);
                            _c.label = 10;
                        case 10:
                            i++;
                            return [3 /*break*/, 1];
                        case 11:
                            i = 0;
                            _c.label = 12;
                        case 12:
                            if (!(i < data.length)) return [3 /*break*/, 17];
                            $to = $parent.find(CommonEvent.getClassKey(data[i].to));
                            _a = [];
                            for (_b in CommonEvent.calcDataList)
                                _a.push(_b);
                            _i = 0;
                            _c.label = 13;
                        case 13:
                            if (!(_i < _a.length)) return [3 /*break*/, 16];
                            key = _a[_i];
                            calcData = CommonEvent.calcDataList[key];
                            $filterTo = $to.filter(calcData.classKey);
                            if (!hasValue($filterTo)) return [3 /*break*/, 15];
                            return [4 /*yield*/, CommonEvent.setCalc($filterTo, calcData.data)];
                        case 14:
                            _c.sent();
                            _c.label = 15;
                        case 15:
                            _i++;
                            return [3 /*break*/, 13];
                        case 16:
                            i++;
                            return [3 /*break*/, 12];
                        case 17: return [2 /*return*/];
                    }
                });
            });
        };
        /**
         * find table data
         * @param table_name
         * @param value
         * @param context
         */
        CommonEvent.findModel = function (table_name, value, context) {
            if (context === void 0) { context = null; }
            var $d = $.Deferred();
            if (!hasValue(value)) {
                $d.resolve(null);
            }
            else {
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
        };
        /**
         * set value. check number format, column type, etc...
         * @param $target
         */
        CommonEvent.setValue = function ($target, value) {
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
        };
        /**
         * add select2
         */
        CommonEvent.addSelect2 = function () {
            $('[data-add-select2]').not('.added-select2').each(function (index, elem) {
                var $elem = $(elem);
                var allowClear = hasValue($elem.data('add-select2-allow-clear')) ? $elem.data('add-select2-allow-clear') : true;
                var options = {
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
        };
        CommonEvent.getFilterVal = function ($parent, a) {
            // get filter object
            var $filterObj = $parent.find(CommonEvent.getClassKey(a.key)).filter(':last');
            // if checkbox
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
        CommonEvent.findValue = function (key, values) {
            values = !Array.isArray(values) ? values.split(',') : values;
            for (var i = 0; i < values.length; i++) {
                if (values[i] == key) {
                    return true;
                }
            }
            return false;
        };
        CommonEvent.calcDataList = [];
        CommonEvent.relatedLinkageList = [];
        /**
        * Calc Date
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
         * call select2 items using linkage
         */
        CommonEvent.setRelatedLinkageChangeEvent = function (ev) {
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
        CommonEvent.setLinkageEvent = function (ev) {
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
        CommonEvent.setFormFilter = function ($target) {
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
                            // その値が、a.valueに含まれているか
                            if (a.value) {
                                if (!CommonEvent.findValue(filterVal, a.value)) {
                                    isShow = false;
                                }
                            }
                            if (a.notValue) {
                                if (!CommonEvent.findValue(filterVal, a.notValue)) {
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
        CommonEvent.setCalcEvent = function (datalist) {
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
                $('.box-body').on('change', CommonEvent.getClassKey(key), { data: data, key: key }, function (ev) { return __awaiter(_this, void 0, void 0, function () {
                    return __generator(this, function (_a) {
                        switch (_a.label) {
                            case 0: return [4 /*yield*/, CommonEvent.setCalc($(ev.target), ev.data.data)];
                            case 1:
                                _a.sent();
                                return [2 /*return*/];
                        }
                    });
                }); });
                // set event for plus minus button
                $('.box-body').on('click', '.btn-number-plus,.btn-number-minus', { data: data, key: key }, function (ev) { return __awaiter(_this, void 0, void 0, function () {
                    return __generator(this, function (_a) {
                        switch (_a.label) {
                            case 0: return [4 /*yield*/, CommonEvent.setCalc($(ev.target).closest('.input-group').find(CommonEvent.getClassKey(ev.data.key)), ev.data.data)];
                            case 1:
                                _a.sent();
                                return [2 /*return*/];
                        }
                    });
                }); });
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
    if (x === null || x === undefined) {
        return x;
    }
    return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
};
var rmcomma = function (x) {
    if (x === null || x === undefined) {
        return x;
    }
    return x.toString().replace(/,/g, '');
};
var trimAny = function (str, any) {
    if (!hasValue(str)) {
        return str;
    }
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
var admin_url = function (path) {
    return URLJoin($('#admin_uri').val(), path);
};
var getParamFromArray = function (array) {
    array = array.filter(function (x) {
        return (x.value !== (undefined || null || ''));
    });
    return $.param(array);
};
