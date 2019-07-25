<link rel="stylesheet" type="text/css" href="{{$css}}" />

<div class="workflow_wrapper">
@foreach($workflow_statuses as $workflow_status)


@foreach($workflow_status as $s)
<div class="workflow_wrapper_item">
<p class="workflow_status_name">
    {{ $s->status_name }}
    <button type="button" class="btn btn-xs btn-default">
        {{trans('admin.setting')}}
    </button>
</p>
<div class="workflow_status workflow_status_{{ $s->status_type }}">

<div class="workflow_status_disable" style="display:{{$s->enable_flg ? 'none' : 'block'}};">
</div>

<input type="hidden" class="workflow_status_enable_flg" value="{{$s->enable_flg}}" />
<input type="hidden" class="workflow_status_name" value="{{$s->status_name}}" />
<input type="hidden" class="workflow_status_type" value="{{$s->status_type}}" />
<input type="hidden" class="workflow_status_group_id" value="{{$s->workflow_group_id}}" />
<input type="hidden" class="workflow_editable_flg" value="{{$s->editable_flg}}" />

@foreach($s->workflow_status_blocks as $workflow_status_block)
<div class="workflow_status_block">

<input type="hidden" class="workflow_status_block_order" value="{{$workflow_status_block->order}}" />
<input type="hidden" class="workflow_status_block_name" value="{{$workflow_status_block->status_block_name}}" />
<input type="hidden" class="workflow_status_block_editable_flg" value="{{$workflow_status_block->editable_flg}}" />

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