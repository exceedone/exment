var Exment;
(function (Exment) {
    class NotifyNavbarEvent {
        /**
         * Call only once. It's $(document).on event.
         */
        static AddEventOnce() {
            if ($('.navbar-notify').length == 0) {
                return;
            }
            NotifyNavbarEvent.notifyNavbar(false);
            // click item after notify list
            $(document).on('click', '.notifications-menu-dropdown li', {}, function (event) {
                NotifyNavbarEvent.reget_flg = true;
            });
            $(document).on('pjax:complete', function (event) {
                if (NotifyNavbarEvent.reget_flg) {
                    NotifyNavbarEvent.notifyNavbar();
                    NotifyNavbarEvent.reget_flg = false;
                }
            });
        }
        /**
         * toggle right-top help link and color
         */
        static notifyNavbar(force = true) {
            // one timer, shared by the tabs, quiet while the tab is hidden (navbar_poll.js, ISS02824)
            if (!NotifyNavbarEvent.poller) {
                NotifyNavbarEvent.poller = new Exment.NavbarPoll({
                    name: 'notify',
                    url: admin_url(URLJoin('webapi', 'notifyPage')),
                    intervalMs: Exment.NavbarPoll.intervalMs('#notify_navbar_interval', 60),
                    userKey: $('#notify_navbar_interval').data('user'),
                    render: NotifyNavbarEvent.render,
                });
            }
            NotifyNavbarEvent.poller.poll(force);
        }
        /**
         * draw an answer of notifyPage: the badge and the dropdown
         */
        static render(data) {
            $('.navbar-notify ul.menu').empty();
            $('.container-notify .label-danger').remove();
            if (data.count > 0) {
                // if up data.count, append ring move class
                if (NotifyNavbarEvent.before_count === null || NotifyNavbarEvent.before_count === undefined || NotifyNavbarEvent.before_count < data.count) {
                    NotifyNavbarEvent.before_count = data.count;
                    $('.navbar-notify .fa-bell').addClass('ring').delay(2500).queue(function () {
                        $('.navbar-notify .fa-bell').removeClass('ring');
                    });
                }
                $('.container-notify').append('<span class="label label-danger fw-bold">' + data.count + '</span>');
                for (let i = 0; i < data.items.length; i++) {
                    let d = data.items[i];
                    const isNew = $.inArray(d.id, NotifyNavbarEvent.notify_navbar_ids) === -1;
                    let li = $('<li/>', {
                        class: '',
                        style: ';',
                        html: $('<a/>', {
                            href: hasValue(d.href) ? d.href : 'javascript:void(0);',
                            html: [
                                $('<p/>', {
                                    html: [
                                        $('<i/>', {
                                            'class': 'fa ' + d.icon,
                                            //'style': hasValue(d.color) ? 'color:' + d.color : null
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
            else {
                let li = $('<li/>', {
                    text: $('#notify_navbar_noitem').val(),
                    'class': 'text-center',
                    style: 'padding:7px;'
                });
                $('.navbar-notify ul.menu').append(li);
            }
        }
    }
    NotifyNavbarEvent.poller = null;
    NotifyNavbarEvent.notify_navbar_ids = [];
    Exment.NotifyNavbarEvent = NotifyNavbarEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.NotifyNavbarEvent.AddEventOnce();
});
