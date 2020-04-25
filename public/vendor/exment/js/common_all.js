var Exment;
(function (Exment) {
    /**
     * Common (login and not login) Event
     */
    class CommonAllEvent {
        /**
         * Call only once. It's $(document).on event.
         */
        static AddEventOnce() {
            $(document).on('pjax:complete', function (event) {
                Exment.CommonEvent.AddEvent();
            });
            $(document).off('click', '.click_disabled').on('click', '.click_disabled', {}, function (ev) {
                // not working ".prop('disabled', true)" ... why??
                $(ev.target).closest('.click_disabled').attr('disabled', 'true');
            });
        }
        static AddEvent() {
            $('form').submit(function (ev) {
                let $button = $(ev.target).find('.submit_disabled').prop('disabled', true);
                // create hidden 
                $(ev.target).append($('<input>', {
                    'name': $button.prop('name'),
                    'value': $button.prop('value'),
                    'type': 'hidden',
                }));
                return true;
            });
        }
    }
    Exment.CommonAllEvent = CommonAllEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CommonAllEvent.AddEvent();
    Exment.CommonAllEvent.AddEventOnce();
});
