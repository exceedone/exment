
namespace Exment {
    export class NotifyNavbarEvent {
        private static poller: NavbarPoll = null;
        private static reget_flg;
        private static before_count;
        private static notify_navbar_ids = [];

        /**
         * Call only once. It's $(document).on event.
         */
        public static AddEventOnce() {
            if($('.navbar-notify').length == 0){
                return;
            }

            NotifyNavbarEvent.notifyNavbar(false);

            // click item after notify list
            $(document).on('click', '.notifications-menu-dropdown li', {}, function (event) {
                NotifyNavbarEvent.reget_flg = true;
            });
            $(document).on('pjax:complete', function (event) {
                if(NotifyNavbarEvent.reget_flg){
                    NotifyNavbarEvent.notifyNavbar();
                    NotifyNavbarEvent.reget_flg = false;
                }
            });
        }

        /**
         * ask for the notifications (force: also when another tab has just asked)
         */
        private static notifyNavbar(force: boolean = true){
            // one timer, shared by the tabs, quiet while the tab is hidden (navbar_poll.ts, ISS02824)
            if(!NotifyNavbarEvent.poller){
                NotifyNavbarEvent.poller = new NavbarPoll({
                    name: 'notify',
                    url: admin_url(URLJoin('webapi', 'notifyPage')),
                    intervalMs: NavbarPoll.intervalMs('#notify_navbar_interval', 60),
                    userKey: $('#notify_navbar_interval').data('user'),
                    render: NotifyNavbarEvent.render,
                });
            }
            NotifyNavbarEvent.poller.poll(force);
        }

        /**
         * draw an answer of notifyPage: the badge and the dropdown
         */
        private static render(data){
            $('.navbar-notify ul.menu').empty();
            $('.container-notify .label-danger').remove();
            if(data.count > 0){

                // if up data.count, append ring move class
                if(NotifyNavbarEvent.before_count === null || NotifyNavbarEvent.before_count === undefined || NotifyNavbarEvent.before_count < data.count){
                    NotifyNavbarEvent.before_count = data.count;
                    $('.navbar-notify .fa-bell').addClass('ring').delay(2500).queue(function(){
                        $('.navbar-notify .fa-bell').removeClass('ring');
                    });
                }

                $('.container-notify').append('<span class="label label-danger fw-bold">' + data.count + '</span>');

                for(let i = 0; i < data.items.length; i++){
                    let d = data.items[i];
                    const isNew = $.inArray(d.id, NotifyNavbarEvent.notify_navbar_ids) === -1;
                    let li = $('<li/>', {
                        html: $('<a/>', {
                            href: hasValue(d.href) ? d.href : 'javascript:void(0);',
                            html: [
                                $('<p/>', {
                                    html:[
                                        $('<i/>', {
                                            'class': 'fa ' + d.icon,
                                        }),
                                        $('<span></span>', {
                                            'text': d.table_view_name,
                                        }),
                                    ],
                                    'class': 'search-item-icon',
                                    'style': hasValue(d.color) ? 'background-color:' + d.color : null
                                }),
                                $('<span/>', {
                                    'text': d.label,
                                }),
                            ],
                        }),
                    });

                    $('.navbar-notify ul.menu').append(li);
                }
            }
            else{
                let li = $('<li/>', {
                    text: $('#notify_navbar_noitem').val(),
                    'class': 'text-center',
                    style: 'padding:7px;'
                });

                $('.navbar-notify ul.menu').append(li);
            }
        }
    }
}

$(function () {
    Exment.NotifyNavbarEvent.AddEventOnce();
});
