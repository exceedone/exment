namespace Exment {
    export class WorkflowEvent {

        /**
         * Call only once. It's $(document).on event.
         */
        public static AddEventOnce() {
        }

        public static AddEvent() {
        }

        public static GetSettingValText(){
            const targetKeys = ['modal_user', 'modal_organization'];

            // get col value item list
            let form = $('[data-contentname="workflow_actions_work_targets"] form');

            // get value
            let val:any = serializeFromArray(form);
            // filter
            let values = {};
            for(let key in val){
                if($.inArray(key, targetKeys) === -1){
                    continue;
                }
                values[key] = val[key];
            }
            
            let texts = [];
            $.each(targetKeys, function(index, value){
                $.each(form.find('.' + value + '.form-control').select2('data'), function(index, value){
                    texts.push(escHtml(value.text));
                });
            });

            return {value: JSON.stringify(values), text: texts.join('<br />')};
        }
    }
}

$(function () {
    Exment.ModalEvent.AddEvent();
    Exment.ModalEvent.AddEventOnce();
});


