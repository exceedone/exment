var Exment;
(function (Exment) {
    const EVENT_LOADED = 'exment:loaded';
    const EVENT_FIRST_LOADED = 'exment:first_loaded';
    const EVENT_FORM_LOADED = 'exment:form_loaded';
    const EVENT_LIST_LOADED = 'exment:list_loaded';
    const EVENT_SHOW_LOADED = 'exment:show_loaded';
    //const EVENT_CALENDAR_BIND = 'exment:calendar_bind'; // Used by calendar.blade.php
    /**
    * Column Event Script.
    */
    class CustomScriptEvent {
        static AddEvent() {
            CustomScriptEvent.fireEvent();
            CustomScriptEvent.fireListEvent();
            CustomScriptEvent.fireFormEvent();
            CustomScriptEvent.fireShowEvent();
        }
        static AddEventOnce() {
            $(document).on('pjax:complete', function (event) {
                CustomScriptEvent.AddEvent();
            });
            $(window).trigger(EVENT_FIRST_LOADED);
        }
        static fireEvent() {
            $(window).trigger(EVENT_LOADED);
        }
        static fireFormEvent() {
            if (!hasValue($('.block_custom_value_form'))) {
                return;
            }
            $(window).trigger(EVENT_FORM_LOADED);
        }
        static fireListEvent() {
            if (!hasValue($('.block_custom_value_grid'))) {
                return;
            }
            $(window).trigger(EVENT_LIST_LOADED);
        }
        static fireShowEvent() {
            if (!hasValue($('.block_custom_value_show'))) {
                return;
            }
            $(window).trigger(EVENT_SHOW_LOADED);
        }
        static setLoading(event) {
            const button = event.target;
            if (!(button && (button.classList.contains('submit') || button.type === 'submit'))) {
                return;
            }
        
            const originalText = button.innerHTML;
            const originalDisabledState = button.disabled;
            button.innerHTML = 'Loading...';
            button.disabled = true;
        
            const form = $(button).closest('form');
        
            if (form.data('submitted')) {
                button.innerHTML = originalText;
                button.disabled = originalDisabledState;
                return;
            }
        
            form.data('submitted', true);
            const timeoutId = setTimeout(() => {
                button.innerHTML = originalText;
                button.disabled = originalDisabledState;
                form.data('submitted', false);
            }, 2000);
        
            if (form.length) {
                form.submit();
            }
        }
        
        static bindSubmitButtons() {
            $('button.submit, button[type="submit"], #search-btn').off('click').on('click', function (event) {
                Exment.CustomScriptEvent.setLoading(event);
            });
        }
        
    }
    Exment.CustomScriptEvent = CustomScriptEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CustomScriptEvent.bindSubmitButtons();
    Exment.CustomScriptEvent.AddEvent();
    Exment.CustomScriptEvent.AddEventOnce();
    $(document).on('pjax:complete', function () {
        Exment.CustomScriptEvent.bindSubmitButtons(); 
    });
    $('#filter-box').on('show', function () {        
        Exment.CustomScriptEvent.bindSubmitButtons();
    });
});
