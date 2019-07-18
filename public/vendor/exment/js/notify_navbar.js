var Exment;
(function (Exment) {
    var NotifyNavbarEvent = /** @class */ (function () {
        function NotifyNavbarEvent() {
        }
        /**
         * Call only once. It's $(document).on event.
         */
        NotifyNavbarEvent.AddEventOnce = function () {
            NotifyNavbarEvent.notifyNavbar();
            $(document).on('pjax:complete', function (event) {
                NotifyNavbarEvent.notifyNavbar();
            });
        };
        /**
         * toggle right-top help link and color
         */
        NotifyNavbarEvent.notifyNavbar = function () {
            $.ajax({
                url: admin_url(URLJoin('webapi', 'notifyPage')),
                dataType: "json",
                type: "GET",
                success: function (data) {
                    setTimeout(function () {
                        NotifyNavbarEvent.notifyNavbar();
                    }, 60000);
                    $('.navbar-notify ul.menu').empty();
                    $('.container-notify .label-danger').remove();
                    if (data.count > 0) {
                        $('.container-notify').append('<span class="label label-danger">' + data.count + '</span>');
                        for (var i = 0; i < data.items.length; i++) {
                            var d = data.items[i];
                            var isNew = $.inArray(d.id, this.notify_navbar_ids) === -1;
                            var li = $('<li/>', {
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
                                                    'style': hasValue(d.color) ? 'background-color:' + d.color : null
                                                }),
                                            ],
                                            'class': 'search-item-icon'
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
                        var li = $('<li/>', {
                            text: data.noItemMessage,
                            'class': 'text-center',
                            style: 'padding:7px;'
                        });
                        $('.navbar-notify ul.menu').append(li);
                    }
                },
            });
        };
        return NotifyNavbarEvent;
    }());
    Exment.NotifyNavbarEvent = NotifyNavbarEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.NotifyNavbarEvent.AddEventOnce();
});
