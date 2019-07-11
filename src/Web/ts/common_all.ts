
namespace Exment {
    /**
     * Common (login and not login) Event
     */
    export class CommonAllEvent {
        /**
         * Call only once. It's $(document).on event.
         */
        public static AddEventOnce() {
            $(document).on('pjax:complete', function (event) {
                CommonEvent.AddEvent();
            });
        }
        
        public static AddEvent() {
            $('form').submit(function(ev){
                $(ev.target).find('.submit_disabled').prop('disabled', true);

                return true;
            })
        }
    }
}

$(function () {
    Exment.CommonAllEvent.AddEvent();
    Exment.CommonAllEvent.AddEventOnce();
});
