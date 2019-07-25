<link rel="stylesheet" type="text/css" href="{{$css}}" />

<div class="workflow_wrapper">
@foreach($workflow_statuses as $workflow_status)


@foreach($workflow_status as $s)
<div class="workflow_status">
@foreach($s->workflow_status_blocks as $workflow_status_block)
<div class="workflow_status_block">

@if(in_array($s->status_type, [0, 99]))
@include('exment::workflow.status_item.start_end')
@endif

</div>
@endforeach
</div>
@endforeach

@endforeach
</div>

<script type="text/javascript" src="{{ $js }}"></script>