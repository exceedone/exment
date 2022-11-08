<div class="form-group ">
    <span>{{$filepath}}</span>
</div>
<style>
    .CodeMirror {
        height: 78vh;
    }
</style>
<div class="form-group">
    <textarea id="edit_plugin_file" class="form-control">{{$filedata}}</textarea>
</div>
<div class="col-md-12">
    <div class="btn-group pull-right">
        <button id="update_plugin_file" class="btn btn-primary" style="margin-right: 5px">{{ exmtrans('common.updated') }}</button>
        <button id="delete_plugin_file" class="btn btn-danger">{{ exmtrans('common.deleted') }}</button>
    </div>
</div>
<input type="hidden" id="nodeid" value="{{ $nodeid }}">

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
    $('#update_plugin_file').off('click').on('click', function(ev) {
        const button = $(ev.target);
        button.text(button.data('loading-label'));
        button.prop('disabled', true);

        var editor = document.querySelector(".CodeMirror").CodeMirror;
        editor.save();

        $.ajax({
            type: "POST",
            url: "{{$url}}",
            data: {
                _token: LA.token,
                edit_file: $('#edit_plugin_file').val(),
                nodeid: $('#nodeid').val(),
                },
            success:function(repsonse) {
                button.text(button.data('default-label'));
                button.prop('disabled', false);
                Exment.CommonEvent.CallbackExmentAjax(repsonse);
            },
            error: function(repsonse){
                button.text(button.data('default-label'));
                button.prop('disabled', false);
                Exment.CommonEvent.CallbackExmentAjax(repsonse);
            }
        });
    });
    function selected_jstree_node() {
        $('#edit_plugin_file').each(function(index, elem){
            CodeMirror.fromTextArea(elem, {
                @if(is_string($mode))
                    mode: '{{ $mode }}',
                @elseif(isset($mode))
                    mode: {!! $mode !!},
                @endif
                lineNumbers: true,
                indentUnit: 4
            });
        });
    }
</script>
