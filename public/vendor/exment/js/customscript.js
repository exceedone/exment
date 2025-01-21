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
            const actionUrl = form.attr('action'); // Lấy URL của form
        
            // Kiểm tra xem form có đang trong quá trình gửi yêu cầu không
            if (form.data('submitted')) {
                // Nếu form đã gửi, ngừng gửi
                button.innerHTML = originalText;
                button.disabled = originalDisabledState;
                return;
            }
        
            form.data('submitted', true); // Đánh dấu form đã được gửi
        
            setTimeout(() => {
                // Khôi phục trạng thái button sau khi xử lý xong
                button.innerHTML = originalText;
                button.disabled = originalDisabledState;
        
                if (form.length) {
                    form.submit();
                }
        
                // Đánh dấu là đã hoàn tất gửi và có thể gửi lại nếu cần
                form.data('submitted', false);
            }, 1000); // Giả lập thời gian tải 1 giây
        }
        
        static bindSubmitButtons() {
            $('button.submit, button[type="submit"]').off('click').on('click', function (event) {
                Exment.CustomScriptEvent.setLoading(event);
            });
        }
        
    }
    Exment.CustomScriptEvent = CustomScriptEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CustomScriptEvent.bindSubmitButtons(); // Gán sự kiện click ban đầu
    Exment.CustomScriptEvent.AddEvent();
    Exment.CustomScriptEvent.AddEventOnce();
    $(document).on('pjax:complete', function () {
        Exment.CustomScriptEvent.bindSubmitButtons(); 
    });
    $('#filter-box').on('show', function () {        
        Exment.CustomScriptEvent.bindSubmitButtons(); // Gán lại sự kiện khi phần tử được hiển thị
    });
});
