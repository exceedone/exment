<div id='calendar' data-calendar-id="{{$suuid}}"></div>
<script type="text/javascript">
    $(function () {
        const date = new UltraDate();
        var calendarEl = $('#calendar[data-calendar-id="{{$suuid}}"]').get(0);

        var calendar = new FullCalendar.Calendar(calendarEl, {
            locale : "{{ $locale }}",
            //height: 'auto',
            height: 395, // dashboard box height - 5
            eventDidMount: function(info) {
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
            dayMaxEventRows: 3,
            eventDisplay: "block",
            @if($calendar_type == 'month') 
            fixedWeekCount: false,
            dayCellDidMount: function(info) {
                date.setFullYear(
                    info.date.getFullYear(),
                    info.date.getMonth(),
                    info.date.getDate()
                );
                const holiday = date.getHoliday();
                if (holiday !== "") {
                    info.el.getElementsByClassName('fc-daygrid-day-top')[0].insertAdjacentHTML("beforeend", "<div class=\"holiday-name fc-daygrid-day-number\">" + holiday + "</div>");
                    info.el.getElementsByClassName('fc-daygrid-day-number')[0].setAttribute('style','margin-left:auto');
                    info.el.classList.add("fc-day-hol");
                }
            },
            dayCellContent: function (e) {
                return e.dayNumberText.replace("日", "");
            },
            @else
            navLinks: true,
            initialView : 'listWeek',
            views: {
                listDay: { buttonText: "{{ exmtrans("calendar.calendar_button_options.day") }}" },
                listWeek: { buttonText: "{{ exmtrans("calendar.calendar_button_options.week") }}" },
                listMonth: { buttonText: "{{ exmtrans("calendar.calendar_button_options.month") }}" }
            },
            headerToolbar: {
              left: 'prev,next today',
              center: 'title',
              right: 'listDay,listWeek,listMonth'
            },
            dayHeaderDidMount: function(info) {
                date.setFullYear(
                    info.date.getFullYear(),
                    info.date.getMonth(),
                    info.date.getDate()
                );
                const holiday = date.getHoliday();
                if (holiday !== "") {
                    info.el.getElementsByClassName('fc-list-day-text')[0].insertAdjacentHTML("afterend", "<a class=\"holiday-name\">" + holiday + "</a>");
                    info.el.classList.add("fc-day-hol");
                }
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
        $(calendarEl).data('fullCalendarObj',calendar);
    });
</script>

<style>

.fc-day-sun,.fc-day-hol {
    .fc-col-header-cell-cushion,.fc-daygrid-day-number,.fc-list-day-text,.fc-list-day-side-text{
        color: red;
    }
}
.fc-day-sat {
    .fc-col-header-cell-cushion,.fc-daygrid-day-number,.fc-list-day-text,.fc-list-day-side-text{
        color: blue;
    }
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
.fc .fc-toolbar.fc-header-toolbar {
    margin-bottom: 0.2em;
}
.fc .fc-button {
    padding: .2em .4em
}
.box.box-dashboard .box-body .box-body-inner-body {
    margin-top: 0px;
}
.box.box-dashboard .box-body {
    padding-top: 0;
}
.holiday-name {
    width: 90px;
    font-size: 13px;
    color: red;
}
.fc-h-event .fc-event-time {
    overflow: visible;
}
</style>