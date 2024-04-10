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
            const targetKeys = ['work_target_type', 'modal_user', 'modal_organization', 'modal_column', 'modal_system', 'modal_login_user_column'];

            // get col value item list
            let form = $('[data-contentname="workflow_actions_work_targets"] form');
            if(!(form.get(0) as HTMLFormElement).reportValidity()){
                return;
            }
            
            // get value
            let val:any = serializeFromArray(form);
            // filter
            let values = {};
            for(let key in val){
                if($.inArray(key, targetKeys) === -1){
                    continue;
                }

                // remove 'modal_' name.
                values[key.replace('modal_', '')] = val[key];
            }
            
            let texts = [];

            let label = $('.work_target_type:checked').closest('label').text().trim();
            texts.push('[' + label + ']');

            $.each(targetKeys, function(index, value){
                let target = form.find('.' + value + '.form-control');
                if(!hasValue(target)){
                    target = form.find('.' + value + ':checked');
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
                }
                // else if(target.prop('type') == 'radio'){
                //     texts.push(escHtml(target.closest('.radio-inline').text().trim()));
                // }
            });

            return {value: JSON.stringify(values), text: texts.join('<br />')};
        }
        
        public static GetConditionSettingValText(){
            const targetKeys = ['workflow_conditions', 'status_to', 'enabled_flg', 'condition_join', 'condition_reverse', 'id'];

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

                let text = '';
                $.each(target.select2('data'), function(index, value){
                    text = escHtml(value.text);
                }); 

                // if has condition table tr, set condition label
                if($(element).closest('.form-group').next('.form-group.has-many-table-div').find('table tbody tr:visible').length > 0){
                    text += target.closest('.modal').find('.has_condition').val();
                }

                texts.push(text);
            });

            return {value: JSON.stringify(values), text: texts.join('<br />')};
        }
    }
}

$(function () {
    Exment.WorkflowEvent.AddEvent();
    Exment.WorkflowEvent.AddEventOnce();
});


