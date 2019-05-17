<div id='calendar' data-calendar-id="{{$view_id}}"></div>
<script type="text/javascript">
    $(function () {
        var calendarEl = $('#calendar[data-calendar-id="{{$view_id}}"]').get(0);

        var calendar = new FullCalendar.Calendar(calendarEl, {
            locale : "{{ $locale }}",
            height: 'auto',
            eventRender: function(info) {
                $(info.el).popover({
                    content: info.event.title,
                    trigger: 'hover',
                    placement: 'top',
                    container: 'body'
                });
            },
            @if($calendar_type == 'month') 
            plugins: [ 'dayGrid', 'interaction' ],
            fixedWeekCount: false,
            @else
            plugins: [ 'list' ],
            defaultView: 'listWeek',
            views: {
                listDay: { buttonText: "{{ exmtrans("calendar.calendar_button_options.day") }}" },
                listWeek: { buttonText: "{{ exmtrans("calendar.calendar_button_options.week") }}" },
                listMonth: { buttonText: "{{ exmtrans("calendar.calendar_button_options.month") }}" }
            },
            header: {
              left: 'prev,next today',
              center: 'title',
              right: 'listDay,listWeek,listMonth'
            },
            @endif
            events: {
                url: "{{ $data_url }}",
                extraParams: {
                    view: "{{ $view_id }}",
                },
            },
        });

        calendar.render();
    });
</script>

<style>

.fc-sun {
    color: red;
}
.fc-sat {
    color: blue;
}
.fc-center h2 {
    font-size: 1.2em;
}
.box-body .fc {
    margin-top: 0px;
}
.fc-toolbar.fc-header-toolbar {
    margin-bottom: 0.2em;
}
.fc-button {
    padding: .2em .4em
}
.box.box-dashboard .box-body .box-body-inner-body {
    margin-top: 0px;
}
.box.box-dashboard .box-body {
    padding-top: 0;
}
</style>