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
            const targetKeys = ['work_target_type', 'user', 'organization', 'column', 'system'];

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
                let target = form.find('.modal_' + value + '.form-control');
                if(!hasValue(target)){
                    target = form.find('.modal_' + value + ':checked');
                    if(!hasValue(target)){
                        return true;
                    }
                }

                if(target.is(':hidden')){
                    return true;
                }
                
                // if not select
                if($.inArray(target.prop('type'), ['select', 'select-multiple']) !== -1){
                    $.each(target.select2('data'), function(index, value){
                        texts.push(escHtml(value.text));
                    }); 
                }else if(target.prop('type') == 'radio'){
                    texts.push(escHtml(target.closest('.radio-inline').text().trim()));
                }
            });

            return {value: JSON.stringify(values), text: texts.join('<br />')};
        }
        
        public static GetConditionSettingValText(){
            const targetKeys = ['filter', 'status_to', 'enabled'];

            // get col value item list
            let form = $('[data-contentname="workflow_actions_work_conditions"] form');

            // get value
            let val:any = serializeFromArray(form);
            // filter
            let values = {};
            for(let key in val){
                if(!hasValue(val[key])){
                    continue;
                }
                
                let exists = false;
                for(let targetKey in targetKeys){
                    if(!key.startsWith(targetKeys[targetKey])){
                        continue;
                    }

                    exists = true;
                    break;
                }

                if(exists){
                    values[key] = val[key];
                }
            }
            
            let texts = [];
            form.find('.work_conditions_status_to').each(function(index, element){
                let target = $(element);
                if(target.is(':hidden')){
                    return;
                }
                $.each(target.select2('data'), function(index, value){
                    texts.push(escHtml(value.text));
                }); 
            });

            return {value: JSON.stringify(values), text: texts.join(',')};
        }
    }
}

$(function () {
    Exment.ModalEvent.AddEvent();
    Exment.ModalEvent.AddEventOnce();
});


