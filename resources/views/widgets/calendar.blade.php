<div class="box">
    <div class="box-header with-border">
        <span>
            @foreach($tools as $tool)
            {!! $tool !!}
            @endforeach
        </span>
    </div>
    <div id='calendar'></div>
</div>

<script>
    $(function () {
        const date = new UltraDate();
        var calendarEl = document.getElementById('calendar');

        var calendar = new FullCalendar.Calendar(calendarEl, {
            locale : "{{ $locale }}",
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            height: 'auto',
            fixedWeekCount: false,
            weekNumbers: true,
            navLinks: true,
            eventDidMount: function(info) {
                info.el.setAttribute('data-toggle', 'tooltip');
                info.el.setAttribute('data-original-title', info.event.title);
            },
            // call when reading event data
            eventDataTransform: function(event) { 
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
            // like '14:30:00'
            eventTimeFormat: { 
                hour: '2-digit',
                minute: '2-digit'
            },
            eventDisplay: "block",
            dayCellDidMount: function(info) {
                date.setFullYear(
                    info.date.getFullYear(),
                    info.date.getMonth(),
                    info.date.getDate()
                );
                const holiday = date.getHoliday();
                if (holiday !== "") {
                    switch (info.view.type) {
                        case 'dayGridMonth':
                            info.el.getElementsByClassName('fc-daygrid-day-top')[0].insertAdjacentHTML("beforeend", "<div class=\"holiday-name fc-daygrid-day-number\">" + holiday + "</div>");
                            info.el.getElementsByClassName('fc-daygrid-day-number')[0].setAttribute('style','margin-left:auto');
                            info.el.classList.add("fc-day-hol");                            
                            break;
                        default:
                            break;
                    }
                }
            },
            dayHeaderDidMount: function(info) {
                date.setFullYear(
                    info.date.getFullYear(),
                    info.date.getMonth(),
                    info.date.getDate()
                );
                const holiday = date.getHoliday();
                if (holiday !== "") {
                    info.el.getElementsByTagName('a')[0].insertAdjacentHTML("afterend", "<a class=\"holiday-name fc-col-header-cell-cushion\">" + holiday + "</a>");
                    info.el.classList.add("fc-day-hol");
                }
            },
            dayCellContent: function (e) {
                return e.dayNumberText.replace("日", "");
            },
            // showing event size
            dayMaxEventRows: 5,
            // put your options and callbacks here
            events: {
                url: "{{ $data_url }}",
                extraParams: {
                    view: "{{ $view_id }}",
                },
            }
        });

        calendar.render();
        $(calendarEl).data('fullCalendarObj',calendar);
      });
</script>
<style>

.fc-day-sun,.fc-day-hol {
    .fc-col-header-cell-cushion,.fc-daygrid-day-number{
        color: red;
    }
}
.fc-day-sat {
    .fc-col-header-cell-cushion,.fc-daygrid-day-number{
        color: blue;
    }
}
.fc-day-grid-event:hover {
    opacity:0.8;
}
.holiday-name {
    width: 90px;
    font-size: 13px;
    color: red;
}
.fc-daygrid-day-number {
    font-size: 20px;
}
</style>