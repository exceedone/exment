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
                CommonEvent.addSelect2();
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('switchChange.bootstrapSwitch', '[data-filter],[data-filtertrigger]', {}, (ev) => {
                CommonEvent.setFormFilter($(ev.target));
            });
            $(document).on('change', '[data-linkage]', {}, CommonEvent.setLinkageEvent);
            $(document).off('click', '[data-help-text]').on('click', '[data-help-text]', {}, CommonEvent.showHelpModalEvent);
            $(document).off('click', '[copyScript]').on('click', '[copyScript]', {}, CommonEvent.copyScriptEvent);
            $(document).on('pjax:complete', function (event) {
                CommonEvent.AddEvent();
            });
            $(document).on('pjax:error', function (xhr, textStatus, error, options) {
                CommonEvent.pjaxError(xhr, textStatus, error, options);
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
                    //$manual.children('i').addClass('help_personal');
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
            if (input.prop('type') == 'password') {
                return;
            }
            input.select();
            document.execCommand('copy');
            toastr.success($('#copy_toastr').val(), null, { timeOut: 1000 });
        }
        /**
         *
         */
        static CallbackExmentAjax(res, resolve = null) {
            if (hasValue(res.responseJSON)) {
                res = res.responseJSON;
            }
            if (res.result === true || res.status === true) {
                // update value
                if (hasValue(res.updateValue)) {
                    for (let key in res.updateValue) {
                        let updatevalue = res.updateValue[key];
                        $('.' + key).val(updatevalue);
                    }
                }
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
                if (!hasValue(res.keepModal) || !res.keepModal) {
                    $('.modal').modal('hide');
                }
                // response as file
                if (hasValue(res.fileBase64)) {
                    const blob = b64toBlob(res.fileBase64, res.fileContentType);
                    const blobUrl = URL.createObjectURL(blob);
                    const a = document.createElement("a");
                    document.body.appendChild(a);
                    a.download = res.fileName;
                    a.href = blobUrl;
                    a.click();
                    a.remove();
                    setTimeout(() => {
                        URL.revokeObjectURL(blobUrl);
                    }, 1E4);
                }
                if (hasValue(resolve) && !hasValue(res.swal)) {
                    resolve(res);
                }
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
                // if has message, show swal
                else if (hasValue(res.message)) {
                    if (swal.isVisible()) {
                        swal.close();
                    }
                    swal($('#exment_error_title').val(), res.message, 'error');
                }
                else {
                    Exment.CommonEvent.UndefinedError();
                    return;
                }
            }
        }
        static redirectCallback(res) {
            if (hasValue(res.reload) && res.reload === false) {
                return;
            }
            if (hasValue(res.redirect)) {
                // whether other site redirect
                if (trimAny(res.redirect, '/').indexOf(trimAny(admin_url(''), '/')) === -1) {
                    window.location.href = res.redirect;
                }
                else {
                    $.pjax({ container: '#pjax-container', url: res.redirect });
                }
            }
            else if (res.logoutAsync) {
                setTimeout(function () {
                    location.href = admin_url('auth/logout');
                }, 5000);
            }
            else {
                $.pjax.reload('#pjax-container');
            }
        }
        static UndefinedError() {
            let undefined_error = $('#exment_undefined_error').val();
            if (!hasValue(undefined_error)) {
                undefined_error = 'Undefined Error';
            }
            toastr.error(undefined_error);
            if (swal.isVisible()) {
                swal.close();
            }
            return;
        }
        /**
         * Show Swal Event
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
                preConfirmValidate: null,
                postEvent: null,
                showCancelButton: true,
                confirmCallback: null,
                htmlTitle: false,
            }, options);
            let data = $.extend({
                _pjax: true,
                _token: LA.token,
            }, options.data);
            if (options.method.toLowerCase() == 'delete') {
                data._method = 'delete';
                options.method = 'POST';
            }
            // Escape title
            if (!options.htmlTitle) {
                options.title = $('<span/>').text(options.title).html();
            }
            let swalOptions = {
                title: options.title,
                type: options.type,
                showCancelButton: options.showCancelButton,
                confirmButtonColor: "#DD6B55",
                confirmButtonText: options.confirm,
                showLoaderOnConfirm: true,
                allowOutsideClick: false,
                cancelButtonText: options.cancel,
                preConfirm: function (input) {
                    if (!hasValue(url)) {
                        return;
                    }
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
                    if (hasValue(options.postEvent)) {
                        return options.postEvent(data);
                    }
                    return new Promise(function (resolve) {
                        $.ajax({
                            type: options.method,
                            url: url,
                            //container: "#pjax-container",
                            data: data,
                            success: function (repsonse) {
                                if (hasValue(options.reload)) {
                                    repsonse.reload = options.reload;
                                }
                                if (hasValue(options.redirect)) {
                                    repsonse.redirect = options.redirect;
                                }
                                Exment.CommonEvent.CallbackExmentAjax(repsonse, resolve);
                                //resolve(repsonse);
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
                if (hasValue(options.confirmCallback)) {
                    options.confirmCallback(result);
                    return;
                }
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
            $('table').find('[data-id],.rowclick').closest('tr').not('.tableHoverLinkEvent').on('click', function (ev) {
                // if e.target closest"a" is length > 0, return
                if ($(ev.target).closest('a,.rowclick').length > 0) {
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
                linkElem.closest('a,.rowclick').trigger('click');
            }).addClass('tableHoverLinkEvent');
        }
        /**
         * Set changedata event
         */
        static setChangedataEvent(datalist) {
            // loop "data-changedata" targets   
            for (let key in datalist) {
                let data = datalist[key];
                // set change event
                let $targetBox = CommonEvent.getBlockElement(key.split('/')[0]);
                let column_name = key.split('/')[1];
                $targetBox.on('change', CommonEvent.getClassKey(column_name), { data: data }, (ev) => __awaiter(this, void 0, void 0, function* () {
                    Exment.CalcEvent.resetLoopConnt();
                    yield CommonEvent.changeModelData($(ev.target), ev.data.data);
                }));
                // if hasvalue to_block, add event when click add button
                for (let table_name in data) {
                    let target_table_data = data[table_name];
                    if (!hasValue(target_table_data)) {
                        continue;
                    }
                    for (let i = 0; i < target_table_data.length; i++) {
                        let d = target_table_data[i];
                        if (!hasValue(d.to_block)) {
                            continue;
                        }
                        $(d.to_block).on('click', '.add', { key: key, data: target_table_data, index: i, table_name: table_name }, (ev) => __awaiter(this, void 0, void 0, function* () {
                            // get target
                            let $target = CommonEvent.getParentRow($(ev.target)).find(CommonEvent.getClassKey(ev.data.key));
                            let data = ev.data.data;
                            // set to_lastindex matched index
                            for (let i = 0; i < data.length; i++) {
                                if (i != ev.data.index) {
                                    continue;
                                }
                                data[i]['to_lastindex'] = true;
                            }
                            // create rensou array.
                            let modelArray = {};
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
                        const webapi = Exment.WebApi.make();
                        webapi.findValue(table_name, value, {
                            data: target_table_data,
                        })
                            .done(function (modeldata, context) {
                            return __awaiter(this, void 0, void 0, function* () {
                                yield CommonEvent.setModelItem(modeldata, $parent, $target, context.data);
                                $d.resolve();
                            });
                        })
                            .fail(function (errordata) {
                            console.log(errordata);
                            $d.reject();
                        });
                    }
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
            return __awaiter(this, void 0, void 0, function* () {
                // loop for options
                for (let i = 0; i < options.length; i++) {
                    let option = options[i];
                    // if has changedata_to_block, get $elem using changedata_to_block
                    if (hasValue(option.to_block)) {
                        $changedata_target = $(option.to_block);
                        // if has to_lastindex, get last children item
                        if (hasValue(option.to_lastindex)) {
                            $changedata_target = $changedata_target.find(option.to_block_form).last();
                        }
                    }
                    // get element
                    let $elem = $changedata_target.find(CommonEvent.getClassKey(option.to));
                    if (!hasValue(modeldata)) {
                        yield CommonEvent.setValue($elem, null);
                    }
                    else {
                        // get element value from model
                        let from = modeldata['value'][option.from];
                        yield CommonEvent.setValue($elem, from);
                    }
                    // view filter execute
                    CommonEvent.setFormFilter($elem);
                    // add $elem to option
                    option['elem'] = $elem;
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
                Exment.GetBox.make().getBox().on('change', CommonEvent.getClassKey(key), { data: data, key: key }, CommonEvent.setRelatedLinkageChangeEvent);
            }
        }
        /**
         * Set linkage expand info for modal search
         * @param expand
         * @param $target
         */
        static setLinkgaeExpandToSearchButton(expand, $target, linkage_value_id) {
            let $button = $target.parent().find('[data-widgetmodal_url]');
            if (!hasValue($button)) {
                return;
            }
            let buttonExpand = $button.data('widgetmodal_expand');
            if (!hasValue(buttonExpand)) {
                buttonExpand = {};
            }
            expand['linkage_value_id'] = linkage_value_id;
            buttonExpand['linkage'] = expand;
            $button.data('widgetmodal_expand', buttonExpand);
        }
        /**
         * find table data
         * @param table_name
         * @param value
         * @param context
         *
         * @deprecated Please use webapi model
         */
        static findModel(table_name, value, context = null) {
            return Exment.WebApi.make().findValue(table_name, value, context);
        }
        /**
         * set value. check number format, column type, etc...
         * @param $target
         */
        static setValue($target, value) {
            if (!hasValue($target)) {
                return;
            }
            let column_type = $target.data('column_type');
            // if has data-disable-setvalue, return (For use view only)
            if (pBool($target.data('disable-setvalue'))) {
                return;
            }
            // if 'image' or 'file', cannot setValue, continue
            if ($.inArray(column_type, ['file', 'image']) != -1) {
                return;
            }
            let isNumber = $.inArray(column_type, ['integer', 'decimal', 'currency']) != -1;
            // if number, remove comma
            if (isNumber) {
                value = rmcomma(value);
            }
            // if integer, floor value
            if (column_type == 'integer') {
                if (hasValue(value)) {
                    let bn = new BigNumber(value);
                    value = bn.integerValue().toPrecision();
                }
            }
            // if 'decimal' or 'currency', floor 
            if ($.inArray(column_type, ['decimal', 'currency']) != -1 && hasValue($target.attr('decimal_digit'))) {
                if (hasValue(value)) {
                    let bn = new BigNumber(value);
                    value = bn.decimalPlaces(pInt($target.attr('decimal_digit'))).toPrecision();
                }
            }
            // switch bootstrapSwitch
            if ($.inArray(column_type, ['boolean', 'yesno']) != -1) {
                let $bootstrapSwitch = $target.filter('[type="checkbox"]');
                $bootstrapSwitch.bootstrapSwitch('toggleReadonly').bootstrapSwitch('state', $bootstrapSwitch.data('onvalue') == value).bootstrapSwitch('toggleReadonly');
            }
            // if select2 and has 'data-add-select2-ajax-webapi', call api, and select2 options
            if ($target.filter('[data-add-select2-ajax-webapi]').length > 0) {
                let uri = URLJoin($target.data('add-select2-ajax-webapi'), value);
                Exment.WebApi.make().select2Option(uri, $target);
            }
            // set value and trigger next
            let isChange = !isMatchString(value, $target.val());
            // If editor, call tinymce event
            if (column_type == 'editor') {
                let t = tinyMCE.get($target.attr('id'));
                if (hasValue(t)) {
                    t.setContent(hasValue(value) ? value : '');
                }
            }
            // default 
            else {
                $target.val(value);
            }
            if (isChange) {
                $target.trigger('change');
            }
        }
        /**
         * add select2
         */
        static addSelect2() {
            $('[data-add-select2]').not('.added-select2').each(function (index, elem) {
                let $elem = $(elem);
                let allowClear = hasValue($elem.data('add-select2-allow-clear')) ? $elem.data('add-select2-allow-clear') : true;
                let options = {
                    "allowClear": allowClear,
                    "placeholder": $elem.data('add-select2'),
                    width: '100%',
                    dropdownParent: pBool($elem.data('add-select2-as-modal')) ? $("#modal-showmodal .modal-dialog") : null,
                };
                if (hasValue($elem.data('add-select2-ajax'))) {
                    // get ue
                    options['ajax'] = Exment.WebApi.make().getSelect2AjaxOption($elem);
                    options['escapeMarkup'] = function (markup) {
                        return markup;
                    };
                    options['minimumInputLength'] = 1;
                }
                $(elem).select2(options);
            }).addClass('added-select2');
        }
        /**
         * Get form block erea. (hasmany or default form)
         * @param block_name block name
         */
        static getBlockElement(block_name) {
            const box = Exment.GetBox.make();
            if (!hasValue(block_name) || block_name == 'default') {
                return CommonEvent.getDefaultBox();
            }
            if (block_name == 'parent_id') {
                return box.getBox().find('.parent_id').closest('.form-group');
            }
            // if 1:n, return children.
            return box.getBox().find('.hasmanyblock-' + block_name);
        }
        static getDefaultBox() {
            return Exment.GetBox.make().getBox().children('.fields-group').children('.embed-value');
        }
        /**
         * add field event (datepicker, icheck)
         */
        static addFieldEvent() {
            $('[data-add-date]').not('.added-datepicker').each(function (index, elem) {
                $(elem).datetimepicker({ "useCurrent": false, "format": "YYYY-MM-DD", "locale": "ja", "allowInputToggle": true });
                $(elem).addClass('added-datepicker');
            });
            $('[data-add-icheck]').not('.added-icheck').each(function (index, elem) {
                $(elem).iCheck({ checkboxClass: 'icheckbox_minimal-blue' });
                $(elem).addClass('added-icheck');
            });
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
        static pjaxError(xhr, textStatus, error, options) {
            if (textStatus.status == 419) {
                toastr.error($('#exment_expired_error').val(), null, { timeOut: 10000 });
            }
        }
    }
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
            var uri = link.uri;
            var expand = link.expand;
            var $target = $parent.find(CommonEvent.getClassKey(link.to)).filter('select');
            // if has 'widgetmodal_expand' on button, append linkage_value_id
            CommonEvent.setLinkgaeExpandToSearchButton(expand, $target, $base.val());
            // if target has 'data-add-select2-ajax'(Call as ajax), set data to $target, and not call linkage
            if (hasValue($target.data('add-select2-ajax'))) {
                let select2_expand = $target.data('add-select2-expand');
                if (!hasValue(select2_expand)) {
                    select2_expand = {};
                }
                select2_expand['linkage_value_id'] = $base.val();
                $target.data('add-select2-expand', select2_expand).val(null).trigger("change");
                continue;
            }
            Exment.WebApi.make().linkage($target, uri, $base.val(), expand);
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
                let $target = $getdata.find(CommonEvent.getClassKey(key));
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
            Exment.WebApi.make().linkage($target, url, $base.val(), expand, linkage_text);
        }
    };
    /**
     * Switch display / non-display according to the target input value
     * @param $target
     */
    CommonEvent.setFormFilter = ($target) => {
        $target = CommonEvent.getParentRow($target).find('[data-filter]');
        for (let tIndex = 0; tIndex < $target.length; tIndex++) {
            let $t = $target.eq(tIndex);
            // Get parent element of that input
            let $parent = CommonEvent.getParentRow($t);
            // Get parent element with row
            let $eParent = $t.parents('.form-group');
            // Get search target key and value
            try {
                let array = $t.data('filter');
                // if not array, convert array
                if (!Array.isArray(array)) {
                    array = [array];
                }
                // check isshow, isReadOnly, isRequired(default is null:not toggle)
                let isShow = true;
                let isReadOnly = false;
                let isRequired = null;
                for (let index = 0; index < array.length; index++) {
                    let a = array[index];
                    // Get value of class with that key
                    // if has parent value
                    let parentCount = a.parent ? a.parent : 0;
                    if (parentCount > 0) {
                        let $calcParent = $parent;
                        for (let i = 0; i < parentCount; i++) {
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
                    // change isrequired
                    if (isRequired === null && a.requiredValue) {
                        isRequired = CommonEvent.findValue(filterVal, a.requiredValue);
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
                const propName = $t.prop('type') == 'select-one' || $t.prop('tagName').toLowerCase() == 'select'
                    ? 'disabled' : 'readonly';
                if (isReadOnly) {
                    $t.prop(propName, true);
                }
                else {
                    if (propName != 'disabled' || isShow) {
                        $t.prop(propName, false);
                    }
                }
                // toggle required
                if (isRequired !== null) {
                    $t.prop('required', isRequired);
                    // find label
                    let $label = $eParent.find('label');
                    if (isRequired) {
                        $label.addClass('asterisk');
                    }
                    else {
                        $label.removeClass('asterisk');
                    }
                }
            }
            catch (e) {
            }
        }
    };
    Exment.CommonEvent = CommonEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CommonEvent.AddEvent();
    Exment.CommonEvent.AddEventOnce();
});
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
const getFormData = function (form) {
    var formData = new FormData(form);
    if (tinyMCE.activeEditor) {
        tinyMCE.activeEditor.save();
    }
    $('textarea[data-column_type="editor"]').each(function (index, elem) {
        let $elem = $(elem);
        formData.append($elem.attr('name'), $elem.val());
    });
    return formData;
};
/**
 * Convert base64 to blob
 * @param b64Data base64 string
 * @param contentType download content type
 * @param sliceSize
 * @returns blob data
 */
const b64toBlob = (b64Data, contentType = '', sliceSize = 512) => {
    const byteCharacters = atob(b64Data);
    const byteArrays = [];
    for (let offset = 0; offset < byteCharacters.length; offset += sliceSize) {
        const slice = byteCharacters.slice(offset, offset + sliceSize);
        const byteNumbers = new Array(slice.length);
        for (let i = 0; i < slice.length; i++) {
            byteNumbers[i] = slice.charCodeAt(i);
        }
        const byteArray = new Uint8Array(byteNumbers);
        byteArrays.push(byteArray);
    }
    const blob = new Blob(byteArrays, { type: contentType });
    return blob;
};
function waitForElm(selector) {
    return new Promise((resolve) => {
        if (document.querySelector(selector)) {
            return resolve(document.querySelector(selector));
        }

        const observer = new MutationObserver((mutations) => {
            if (document.querySelector(selector)) {
                resolve(document.querySelector(selector));
                observer.disconnect();
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true,
        });
    });
}
