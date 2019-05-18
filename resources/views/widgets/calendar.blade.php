<div class="box">
    <div class="box-header with-border">
        <div class="pull-right">
            <div class="btn-group pull-right" style="margin-right: 10px">
                <a href="{{ $createUrl }}" class="btn btn-sm btn-success" title="{$new}">
                    <i class="fa fa-plus"></i><span class="hidden-xs">&nbsp;&nbsp;{{$new}}</span>
                </a>
            </div>
        </div>
        <span>
            @foreach($tools as $tool)
            {!! $tool->render() !!}
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
            plugins: [ 'dayGrid', 'interaction' ],
            height: 'parent',
            fixedWeekCount: false,
            eventRender: function(info) {
                info.el.setAttribute('data-toggle', 'tooltip');
                info.el.setAttribute('data-original-title', info.event.title);
            },
            eventDataTransform: function(event) { // call when reading event data
                if(event.allDayBetween) {
                    event.end = moment(event.end).add(1, 'days').format('YYYY-MM-DD');
                }
                return event;
            },
            eventTimeFormat: { // like '14:30:00'
                hour: '2-digit',
                minute: '2-digit'
            },
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