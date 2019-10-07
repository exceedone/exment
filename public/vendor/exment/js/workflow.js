var Exment;
(function (Exment) {
    class WorkflowEvent {
        /**
         * Call only once. It's $(document).on event.
         */
        static AddEventOnce() {
        }
        static AddEvent() {
        }
        static GetSettingValText() {
            const targetKeys = ['modal_user', 'modal_organization', 'modal_column', 'modal_system'];
            // get col value item list
            let form = $('[data-contentname="workflow_actions_work_targets"] form');
            // get value
            let val = serializeFromArray(form);
            // filter
            let values = {};
            for (let key in val) {
                if ($.inArray(key, targetKeys) === -1) {
                    continue;
                }
                values[key] = val[key];
            }
            let texts = [];
            $.each(targetKeys, function (index, value) {
                let target = form.find('.' + value + '.form-control');
                if (!hasValue(target)) {
                    return true;
                }
                $.each(target.select2('data'), function (index, value) {
                    texts.push(escHtml(value.text));
                });
            });
            return { value: JSON.stringify(values), text: texts.join('<br />') };
        }
    }
    Exment.WorkflowEvent = WorkflowEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.ModalEvent.AddEvent();
    Exment.ModalEvent.AddEventOnce();
});
