var Exment;
(function (Exment) {
    const EVENT_FORM_LOADED = 'exment:form_loaded';
    const EVENT_LIST_LOADED = 'exment:list_loaded';
    /**
    * Column Event Script.
    */
    class CustomScriptEvent {
        static AddEvent() {
            CustomScriptEvent.fireListEvent();
            CustomScriptEvent.fireFormEvent();
        }
        static AddEventOnce() {
            $(document).on('pjax:complete', function (event) {
                CustomScriptEvent.AddEvent();
            });
        }
        static fireFormEvent() {
            if (!hasValue($('.custom_value_form'))) {
                return;
            }
            $(window).trigger(EVENT_FORM_LOADED);
        }
        static fireListEvent() {
            if (!hasValue($('.custom_value_grid'))) {
                return;
            }
            $(window).trigger(EVENT_LIST_LOADED);
        }
    }
    Exment.CustomScriptEvent = CustomScriptEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CustomScriptEvent.AddEvent();
    Exment.CustomScriptEvent.AddEventOnce();
});
