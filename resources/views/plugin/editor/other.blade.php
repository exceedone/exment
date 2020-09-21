<div class="alert alert-warning">
    <button type="button" class="close" aria-hidden="true">×</button>
    <h4><i class="icon fa fa-ban"></i>{{ $message }}</h4>
    <p>({{$filepath}})</p>
</div>
<input type="hidden" id="nodeid" value="{{ $nodeid }}">
@if(isset($can_delete) && boolval($can_delete))
<div class="col-md-12">
    <div class="btn-group pull-right">
        <button id="delete_plugin_file" class="btn btn-danger">{{ exmtrans('common.deleted') }}</button>
    </div>
</div>

<script type="text/javascript">
    $('#delete_plugin_file').off('click').on('click', function() {
        Exment.CommonEvent.ShowSwal("{{$url}}", {
            title: "{{ trans('admin.delete_confirm') }}",
            confirm:"{{ trans('admin.confirm') }}",
            reload: true,
            method: 'delete',
            cancel:"{{ trans('admin.cancel') }}",
            data: {
                nodeid: $('#nodeid').val()
            },
        });
    });
</script>
@endif
