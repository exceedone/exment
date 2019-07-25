<div class="workflow_wrapper_item">
    <p class="workflow_status_name">
        {{exmtrans('workflow.workflow_status')}} : 
        {{ $s->status_name }}
        <br />
        <button type="button" class="btn btn-xs btn-default" data-widgetmodal_url="{{$modalurl_status}}"
            data-widgetmodal_getdata='["enabled_flg", "status_name", "status_type", "status_group_id", "editable_flg"]'>
            {{trans('admin.setting')}}
                    
            <input type="hidden" class="enabled_flg" value="{{$s->enabled_flg}}" />
            <input type="hidden" class="status_name" value="{{$s->status_name}}" />
            <input type="hidden" class="status_type" value="{{$s->status_type}}" />
            <input type="hidden" class="status_group_id" value="{{$s->workflow_group_id}}" />
            <input type="hidden" class="editable_flg" value="{{$s->editable_flg}}" />

        </button>
    </p>
    <div class="workflow_status workflow_status_{{ $s->status_type }}">
        <div class="workflow_status_disable" style="display:{{$s->enabled_flg ? 'none' : 'block'}};">
        </div>

        <i class="status_icon fa fa-circle" aria-hidden="true"></i>

    </div>
</div>