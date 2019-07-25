<link rel="stylesheet" type="text/css" href="{{$css}}" />

<div class="workflow_wrapper">
@foreach($workflow_statuses as $workflow_status)


@foreach($workflow_status as $s)
<div class="workflow_wrapper_item">
<p class="workflow_status_name">{{ $s->status_name }}</p>
<div class="workflow_status_{{ $s->status_type }}">

@foreach($s->workflow_status_blocks as $workflow_status_block)
<div class="workflow_status_block">

@if(in_array($s->status_type, [0, 99]))
@include('exment::workflow.status_item.start_end')
@else
@include('exment::workflow.status_item.flow')
@endif

</div>
@endforeach
{{-- /workflow_status_blocks --}}


</div>
</div>

{{-- action arrow --}}
<div class="workflow_wrapper_action_item">
<i class="action_icon fa fa-arrow-right" aria-hidden="true"></i>
<p>aaaa</p>
</div>
{{-- /action arrow --}}

@endforeach
{{-- /workflow_status --}}

@endforeach
{{-- /workflow_statuses --}}
</div>

<script type="text/javascript" src="{{ $js }}"></script>