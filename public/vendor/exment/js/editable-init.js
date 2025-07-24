if ($.fn.editable) {
    $.fn.editable.defaults.ajaxOptions = {
        type: 'POST',
        headers: {
            'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content')
        }
    };

    $.fn.editable.defaults.params = function (params) {
        params._method = 'PUT';
        return params;
    };
} else {
    console.warn('⚠️ $.fn.editable is not defined yet.');
}
