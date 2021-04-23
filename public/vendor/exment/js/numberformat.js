(function ($) {
    $.numberformat = function (selector, options) {
        var $elem = $(selector);
        // option
        var setting = $.extend({
            align: 'left',
            separator: ',',
            readonly: false
        }, options);
        // remove comma
        var rmcomma = function (val) {
            if (val === null || val === undefined) {
                return null;
            }
            return val.toString().replace(new RegExp(setting.separator, 'g'), '');
        };
        // format number
        var fmcomma = function (val) {
            if (val === null || val === undefined) {
                return null;
            }
            var v = rmcomma(val);
            return v.replace(/(\d)(?=(?:\d{3}){2,}(?:\.|$))|(\d)(\d{3}(?:\.\d*)?$)/g, '$1$2' + setting.separator + '$3');
        };
        // make the string formatted on changed
        $(document).on('change', selector, {}, function () {
            if ($(this).prop('readonly') && !setting.readonly) {
                return;
            }
            var val = $(this).val();
            $(this).val(fmcomma($(this).val()))
                .css({
                textAlign: setting.align
            });
        }).change();
        // Move caret to end of string on focused
        $(document).on('focus', selector, {}, function () {
            if ($(this).prop('readonly') && !setting.readonly) {
                return;
            }
            var v = $(this).val();
            $(this).val('').val(rmcomma(v));
        });
        // format number on blur
        $(document).on('blur', selector, {}, function () {
            if ($(this).prop('readonly') && !setting.readonly) {
                return;
            }
            var v = $(this).val();
            $(this).val(fmcomma(v));
        });
        // Remove comma on submit
        $(document).on('submit', 'form', {}, function () {
            $(this).find('input').filter(selector).each(function () {
                $(this).val(rmcomma($(this).val()));
            });
        });
        // add comma
        $elem.each(function () {
            // Do something to each element here.
            $(this).val(fmcomma($(this).val()));
        });
        return this;
    };
})(jQuery);
