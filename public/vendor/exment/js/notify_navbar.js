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
            // read all: the notifications stay in the list, greyed (ISS00360)
            $(document).on('click', '.notify-navbar-read-all', {}, function (event) {
                event.preventDefault();
                $.ajax({
                    url: $(this).data('url'),
                    type: 'POST',
                    data: { _token: LA.token },
                }).always(function () {
                    NotifyNavbarEvent.notifyNavbar();
                });
            });
            $(document).on('pjax:complete', function (event) {
                if (NotifyNavbarEvent.reget_flg) {
                    NotifyNavbarEvent.notifyNavbar();
                    NotifyNavbarEvent.reget_flg = false;
                }
            });
        }
        /**
         * ask for the notifications (force: also when another tab has just asked)
         */
        static notifyNavbar(force = true) {
            // one timer, shared by the tabs, quiet while the tab is hidden (navbar_poll.ts, ISS02824)
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
            const items = data.items || [];
            if (data.count > 0) {
                // if up data.count, append ring move class
                if (NotifyNavbarEvent.before_count === null || NotifyNavbarEvent.before_count === undefined || NotifyNavbarEvent.before_count < data.count) {
                    $('.navbar-notify .fa-bell').addClass('ring').delay(2500).queue(function () {
                        $('.navbar-notify .fa-bell').removeClass('ring');
                    });
                }
                $('.container-notify').append('<span class="label label-danger fw-bold">' + data.count + '</span>');
            }
            // the badge counts the unread ones only; after "read all" the next one rings again
            NotifyNavbarEvent.before_count = data.count;
            if (data.count == 0) {
                let li = $('<li/>', {
                    text: $('#notify_navbar_noitem').val(),
                    'class': 'text-center',
                    style: 'padding:7px;'
                });
                $('.navbar-notify ul.menu').append(li);
            }
            // the unread ones, then the ones read lately, greyed: they stay to be looked at again (ISS00360)
            for (let i = 0; i < items.length; i++) {
                let d = items[i];
                let li = $('<li/>', {
                    'class': d.read ? 'notify-navbar-read' : null,
                    style: d.read ? 'opacity:0.55;' : null,
                    html: $('<a/>', {
                        href: hasValue(d.href) ? d.href : 'javascript:void(0);',
                        html: [
                            $('<p/>', {
                                html: [
                                    $('<i/>', {
                                        'class': 'fa ' + d.icon,
                                    }),
                                    $('<span></span>', {
                                        'text': d.table_view_name,
                                    }),
                                ],
                                'class': 'search-item-icon',
                                'style': hasValue(d.color) && !d.read ? 'background-color:' + d.color : null
                            }),
                            $('<span/>', {
                                'text': d.label,
                                'style': d.read ? 'color:#777;' : null,
                            }),
                        ],
                    }),
                });
                $('.navbar-notify ul.menu').append(li);
            }
        }
    }
    NotifyNavbarEvent.poller = null;
    Exment.NotifyNavbarEvent = NotifyNavbarEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.NotifyNavbarEvent.AddEventOnce();
});
