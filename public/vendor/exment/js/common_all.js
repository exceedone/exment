var Exment;
(function (Exment) {
    /**
     * Common (login and not login) Event
     */
    var CommonAllEvent = /** @class */ (function () {
        function CommonAllEvent() {
        }
        /**
         * Call only once. It's $(document).on event.
         */
        CommonAllEvent.AddEventOnce = function () {
            $(document).on('pjax:complete', function (event) {
                Exment.CommonEvent.AddEvent();
            });
        };
        CommonAllEvent.AddEvent = function () {
            $('form').submit(function (ev) {
                $(ev.target).find('.submit_disabled').prop('disabled', true);
                return true;
            });
        };
        return CommonAllEvent;
    }());
    Exment.CommonAllEvent = CommonAllEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.CommonAllEvent.AddEvent();
    Exment.CommonAllEvent.AddEventOnce();
});
