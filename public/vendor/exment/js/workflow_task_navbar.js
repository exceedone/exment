var Exment;
(function (Exment) {
    class WorkflowTaskNavbarEvent {
        /**
         * Call only once. It's $(document).on event.
         */
        static AddEventOnce() {
            if ($('.navbar-workflow-task').length == 0) {
                return;
            }
            WorkflowTaskNavbarEvent.workflowTaskNavbar();
            // click item after task list (re-fetch so the badge reflects "seen")
            $(document).on('click', '.navbar-workflow-task .notifications-menu-dropdown li', {}, function (event) {
                WorkflowTaskNavbarEvent.reget_flg = true;
            });
            $(document).on('pjax:complete', function (event) {
                if (WorkflowTaskNavbarEvent.reget_flg) {
                    WorkflowTaskNavbarEvent.workflowTaskNavbar();
                    WorkflowTaskNavbarEvent.reget_flg = false;
                }
            });
        }
        /**
         * fetch the current user's un-actioned tasks and render the dropdown + badge
         */
        static workflowTaskNavbar() {
            if (WorkflowTaskNavbarEvent.timeout_id !== null) {
                clearTimeout(WorkflowTaskNavbarEvent.timeout_id);
                WorkflowTaskNavbarEvent.timeout_id = null;
            }
            $.ajax({
                url: admin_url(URLJoin('webapi', 'workflowTaskPage')),
                dataType: "json",
                type: "GET",
                success: function (data) {
                    WorkflowTaskNavbarEvent.timeout_id = setTimeout(function () {
                        WorkflowTaskNavbarEvent.workflowTaskNavbar();
                    }, 60000);
                    $('.navbar-workflow-task ul.menu').empty();
                    $('.container-workflow-task .label-danger').remove();
                    if (data.count > 0) {
                        // if count increased, ring the icon
                        if (WorkflowTaskNavbarEvent.before_count === null || WorkflowTaskNavbarEvent.before_count === undefined || WorkflowTaskNavbarEvent.before_count < data.count) {
                            WorkflowTaskNavbarEvent.before_count = data.count;
                            $('.navbar-workflow-task .fa-sitemap').addClass('ring').delay(2500).queue(function () {
                                $('.navbar-workflow-task .fa-sitemap').removeClass('ring');
                            });
                        }
                        $('.container-workflow-task').append('<span class="label label-danger">' + data.count + '</span>');
                        for (let i = 0; i < data.items.length; i++) {
                            let d = data.items[i];
                            let li = $('<li/>', {
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
                                            'style': hasValue(d.color) ? 'background-color:' + d.color : null
                                        }),
                                        $('<span/>', {
                                            'text': d.label,
                                        }),
                                        $('<small/>', {
                                            'text': hasValue(d.status_name) ? ' [' + d.status_name + ']' : '',
                                            'style': 'color:#999;'
                                        }),
                                    ],
                                }),
                            });
                            $('.navbar-workflow-task ul.menu').append(li);
                        }
                    }
                    else {
                        let li = $('<li/>', {
                            text: $('#workflow_task_navbar_noitem').val(),
                            'class': 'text-center',
                            style: 'padding:7px;'
                        });
                        $('.navbar-workflow-task ul.menu').append(li);
                    }
                },
            });
        }
    }
    WorkflowTaskNavbarEvent.timeout_id = null;
    WorkflowTaskNavbarEvent.before_count = null;
    WorkflowTaskNavbarEvent.reget_flg = false;
    Exment.WorkflowTaskNavbarEvent = WorkflowTaskNavbarEvent;
})(Exment || (Exment = {}));
$(function () {
    Exment.WorkflowTaskNavbarEvent.AddEventOnce();
});
