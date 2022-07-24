<div id='calendar' data-calendar-id="{{$suuid}}"></div>
<script type="text/javascript">
    $(function () {
        var calendarEl = $('#calendar[data-calendar-id="{{$suuid}}"]').get(0);

        var calendar = new FullCalendar.Calendar(calendarEl, {
            locale : "{{ $locale }}",
            //height: 'auto',
            height: 395, // dashboard box height - 5
            eventRender: function(info) {
                info.el.setAttribute('data-toggle', 'tooltip');
                info.el.setAttribute('data-original-title', info.event.title);
            },
            eventDataTransform: function(event) { // call when reading event data
                if(event.allDayBetween) {
                    event.end = moment(event.end).add(1, 'days').format('YYYY-MM-DD');
                }
                
                // call handle event
                let jqEvent = $.Event('exment:calendar_bind');
                $(window).trigger(jqEvent, event);
                // If can get result, replace result.
                if(jqEvent && jqEvent.result){
                    event = jqEvent.result;
                }
                
                return event;
            },
             eventTimeFormat: { // like '14:30:00'
                hour: '2-digit',
                minute: '2-digit'
            },
            // showing event size. if over, dialog.
            eventLimit:5,
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
                    dashboard: 1
                },
            }
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
.fc-day-grid-event:hover{
    opacity:0.8;
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