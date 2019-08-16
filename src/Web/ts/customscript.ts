
namespace Exment {
    const EVENT_FORM_LOADED = 'exment:form_loaded';
    const EVENT_LIST_LOADED = 'exment:list_loaded';

    /**
    * Column Event Script.
    */
    export class CustomScriptEvent {
        public static AddEvent() {
            CustomScriptEvent.fireLoadEvent();
            CustomScriptEvent.fireFormEvent();
        }

        public static AddEventOnce() {
            $(document).on('pjax:complete', function (event) {
                CustomScriptEvent.AddEvent();
            });    
        }

        private static fireFormEvent(){
            // get endpoint
            const pathname = CustomScriptEvent.getEndpoint();
            
            if(!hasValue(pathname)){
                return;
            }

            // if path is not 'data', return
            if(pathname.indexOf('data/') === -1){
                return;
            }

            // check create or edit using regex
            const regexCreate = new RegExp('^data\/[a-zA-Z0-9\-_]+\/create');
            const regexEdit = new RegExp('^data\/[a-zA-Z0-9\-_]+\/[0-9]+\/edit');

            if(!pathname.match(regexCreate) && !pathname.match(regexEdit)){
                return;
            }

            $(window).trigger(EVENT_FORM_LOADED);
        }

        private static fireLoadEvent(){
            // get endpoint
            const pathname = CustomScriptEvent.getEndpoint();
            
            if(!hasValue(pathname)){
                return;
            }

            // if path is not 'data', return
            if(pathname.indexOf('data/') === -1){
                return;
            }

            // check list
            const regexListFix = new RegExp('^data\/[a-zA-Z0-9\-_]+$');
            const regexListQuery = new RegExp('^data\/[a-zA-Z0-9\-_]+\?.*$');

            if(!pathname.match(regexListFix) && !pathname.match(regexListQuery)){
                return;
            }

            $(window).trigger(EVENT_LIST_LOADED);
        }

        private static getEndpoint() : string{
            return trimAny(location.pathname, '/');
        }
    }
}
$(function () {
    Exment.CustomScriptEvent.AddEvent();
    Exment.CustomScriptEvent.AddEventOnce();
});
