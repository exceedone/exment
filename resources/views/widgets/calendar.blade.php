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
        var calendarEl = document.getElementById('calendar');

        var calendar = new FullCalendar.Calendar(calendarEl, {
            locale : "{{ $locale }}",
            plugins: [ 'dayGrid', 'interaction', 'timeGrid' ],
            header: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            height: 'parent',
            fixedWeekCount: false,
            eventRender: function(info) {
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
            // showing event size
            eventLimit:5,
            // put your options and callbacks here
            events: {
                url: "{{ $data_url }}",
                extraParams: {
                    view: "{{ $view_id }}",
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
</style>