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
            // put your options and callbacks here
            events : [
                @foreach($tasks as $task)
                {
                    title : '{{ array_get($task, "title") }}',
                    start : '{{ array_get($task, "start") }}',
                    url : '{{ array_get($task, "url") }}',
                    @if(!is_null(array_get($task, "color")))
                        color : '{{ array_get($task, "color") }}'
                    @endif
                },
                @endforeach
            ]
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

</style>