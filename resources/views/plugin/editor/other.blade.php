<div class="alert alert-warning">
    <button type="button" class="close" aria-hidden="true">×</button>
    <h4><i class="icon fa fa-ban"></i>{{ exmtrans('plugincode.message.irregular_ext') }}</h4>
    <p>({{$filepath}})</p>
</div>
<div class="col-md-12">
    <div class="btn-group pull-right">
        <button id="delete_plugin_file" class="btn btn-danger">{{ exmtrans('common.deleted') }}</button>
    </div>
</div>
<input type="hidden" id="plugin_file_path" value="{{ $filepath }}">

<script type="text/javascript">
    $('#delete_plugin_file').off('click').on('click', function() {
        Exment.CommonEvent.ShowSwal("{{$url}}", {
            title: "{{ trans('admin.delete_confirm') }}",
            confirm:"{{ trans('admin.confirm') }}",
            reload: true,
            method: 'delete',
            cancel:"{{ trans('admin.cancel') }}",
            data: {
                file_path: $('#plugin_file_path').val()
            },
        });
    });
</script>
