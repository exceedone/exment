<div style='display:inline-block;min-width:70px;padding-top:5px'>
    <small>
    {{ $workflow->workflow_status_name }}
    </small>
</div>
<small>
    {{ $workflow->created_at }}&nbsp;&nbsp;&nbsp;
    ({{ exmtrans("common.updated_user") }}&nbsp;:&nbsp;{!! $workflow->created_user_avatar !!})
    @if ($workflow->latest_flg)
        &nbsp;<span style='color:red'>{{ exmtrans("common.available_true") }}</span>
    @endif
</small>
