
namespace Exment {
    const EVENT_LOADED = 'exment:loaded';
    const EVENT_FORM_LOADED = 'exment:form_loaded';
    const EVENT_LIST_LOADED = 'exment:list_loaded';
    const EVENT_SHOW_LOADED = 'exment:show_loaded';

    /**
    * Column Event Script.
    */
    export class CustomScriptEvent {
        public static AddEvent() {
            CustomScriptEvent.fireEvent();
            CustomScriptEvent.fireListEvent();
            CustomScriptEvent.fireFormEvent();
            CustomScriptEvent.fireShowEvent();
        }

        public static AddEventOnce() {
            $(document).on('pjax:complete', function (event) {
                CustomScriptEvent.AddEvent();
            });    
        }

        private static fireEvent(){
            $(window).trigger(EVENT_LOADED);
        }

        private static fireFormEvent(){
            if(!hasValue($('.block_custom_value_form'))){
                return;
            }

            $(window).trigger(EVENT_FORM_LOADED);
        }

        private static fireListEvent(){
            if(!hasValue($('.block_custom_value_grid'))){
                return;
            }

            $(window).trigger(EVENT_LIST_LOADED);
        }

        private static fireShowEvent(){
            if(!hasValue($('.block_custom_value_show'))){
                return;
            }

            $(window).trigger(EVENT_SHOW_LOADED);
        }
    }
}
$(function () {
    Exment.CustomScriptEvent.AddEvent();
    Exment.CustomScriptEvent.AddEventOnce();
});
