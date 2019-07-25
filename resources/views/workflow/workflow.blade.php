<link rel="stylesheet" type="text/css" href="{{$css}}" />

<div class="workflow_wrapper">
@foreach($workflow_statuses as $workflow_status)
<div class="workflow_status">

@foreach($workflow_status as $s)
@foreach($s->workflow_status_blocks as $workflow_status_block)
<div style="border:1px solid black;">
aaa
</div>
@endforeach
@endforeach

</div>
@endforeach
</div>

<script type="text/javascript" src="{{ $js }}"></script>