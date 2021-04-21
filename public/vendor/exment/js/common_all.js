var Exment;
(function (Exment) {
    /**
     * Common (login and not login) Event
     */
    class CommonAllEvent {
        /**
         * Call only once. It's $(document).on event.
         */
        static AddEventOnce() {
            $(document).on('pjax:complete', function (event) {
                Exment.CommonEvent.AddEvent();
            });
            $(document).off('click', '.click_disabled').on('click', '.click_disabled', {}, function (ev) {
                // not working ".prop('disabled', true)" ... why??
                $(ev.target).closest('.click_disabled').attr('disabled', 'true');
            });
            $(document).off('submit', 'form.click_disabled_submit').on('submit', 'form.click_disabled_submit', {}, function (ev) {
                $('form.click_disabled_submit [type="submit"]').attr('disabled', 'true');
                return true;
            });
            $(document).off('click', '.submit_disabled,.click_disabled').on('click', '.submit_disabled,.click_disabled', {}, function (ev) {
                let $button = $(ev.target).closest('.submit_disabled,.click_disabled');
                // // create hidden, because disabled, cannot post value.
                if (hasValue($button.prop('name'))) {
                    $(ev.target).append($('<input />', {
                        'name': $button.prop('name'),
                        'value': $button.prop('value'),
                        'type': 'hidden',
                    }));
                }
            });
        }
        static AddEvent() {
            $('form').submit(function (ev) {
                let $button = $(ev.target).find('.submit_disabled');
                $button.prop('disabled', true);
                return true;
            });
        }
    }
    Exment.CommonAllEvent = CommonAllEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CommonAllEvent.AddEvent();
    Exment.CommonAllEvent.AddEventOnce();
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
const pFloat = (obj) => {
    if (!hasValue(obj)) {
        return 0;
    }
    obj = obj.toString().replace(/,/g, '');
    // check integer
    if (obj.indexOf('.') === -1) {
        return parseInt(obj);
    }
    return parseFloat(obj);
};
const pBool = (obj) => {
    if (!hasValue(obj)) {
        return false;
    }
    const booleanStr = obj.toString().toLowerCase();
    return booleanStr === "true" || booleanStr === "1";
};
const hasValue = (obj) => {
    if (obj == null || obj == undefined || obj.length == 0) {
        return false;
    }
    return true;
};
const isMatchString = (val1, val2) => {
    if (!hasValue(val1) && !hasValue(val2)) {
        return true;
    }
    return val1 == val2;
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
