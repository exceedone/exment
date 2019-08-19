
namespace Exment {
    const EVENT_FORM_LOADED = 'exment:form_loaded';
    const EVENT_LIST_LOADED = 'exment:list_loaded';

    /**
    * Column Event Script.
    */
    export class CustomScriptEvent {
        public static AddEvent() {
            CustomScriptEvent.fireListEvent();
            CustomScriptEvent.fireFormEvent();
        }

        public static AddEventOnce() {
            $(document).on('pjax:complete', function (event) {
                CustomScriptEvent.AddEvent();
            });    
        }

        private static fireFormEvent(){
            if(!hasValue($('.custom_value_form'))){
                return;
            }

            $(window).trigger(EVENT_FORM_LOADED);
        }

        private static fireListEvent(){
            if(!hasValue($('.custom_value_grid'))){
                return;
            }

            $(window).trigger(EVENT_LIST_LOADED);
        }
    }
}
$(function () {
    Exment.CustomScriptEvent.AddEvent();
    Exment.CustomScriptEvent.AddEventOnce();
});
