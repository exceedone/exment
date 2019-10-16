var Exment;
(function (Exment) {
    class ChangeFieldEvent {
        /**
         * toggle right-top help link and color
         */
        static ChangeFieldEvent(ajax, eventTriggerSelector, eventTargetSelector) {
            if (!hasValue(ajax)) {
                return;
            }
            $('.has-many-table').off('change').on('change', eventTriggerSelector, function (ev) {
                var changeTd = $(ev.target).closest('tr').find('td:nth-child(3)>div>div');
                if (!hasValue($(ev.target).val())) {
                    changeTd.html('');
                    return;
                }
                $.ajax({
                    url: ajax,
                    type: "GET",
                    data: {
                        'target': $(this).closest('tr').find(eventTargetSelector).val(),
                        'cond_name': $(this).attr('name'),
                        'cond_key': $(this).val(),
                    },
                    context: this,
                    success: function (data) {
                        var json = JSON.parse(data);
                        $(this).closest('tr').find('td:nth-child(3)>div>div').html(json.html);
                        if (json.script) {
                            eval(json.script);
                        }
                    },
                });
            });
        }
    }
    Exment.ChangeFieldEvent = ChangeFieldEvent;
})(Exment || (Exment = {}));
