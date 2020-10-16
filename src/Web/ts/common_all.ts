
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

            $(document).off('click', '.click_disabled').on('click', '.click_disabled', {}, function(ev){
                // not working ".prop('disabled', true)" ... why??
                $(ev.target).closest('.click_disabled').attr('disabled', 'true');
            });
            
            $(document).off('submit', 'form.click_disabled_submit').on('submit', 'form.click_disabled_submit', {}, function(ev){
                $('form.click_disabled_submit [type="submit"]').attr('disabled', 'true');
                return true;
            });
        }
        
        public static AddEvent() {
            $('form').submit(function(ev){
                let $button = $(ev.target).find('.submit_disabled');
                if($button.length > 1){
                    return true;
                }

                // create hidden 
                $(ev.target).append($('<input />', {
                    'name' : $button.prop('name'),
                    'value': $button.prop('value'),
                    'type': 'hidden',
                }));

                $button.prop('disabled', true);

                return true;
            });
        }
    }
}

$(function () {
    Exment.CommonAllEvent.AddEvent();
    Exment.CommonAllEvent.AddEventOnce();
});
