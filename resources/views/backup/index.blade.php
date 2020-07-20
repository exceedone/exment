<div class="box">
    <div class="box-header with-border">
        <div class="pull-right">
            <div class="btn-group pull-right" style="margin-right: 5px">
                <button type="button" style="margin-right:5px;" class="btn btn-sm btn-twitter btn-backup">
                    <i class="fa fa-download"></i> {{exmtrans("backup.backup")}}
                </button>

                <a href="javascript:void(0);" data-widgetmodal_url="{{admin_urls('backup', 'importModal')}}" type="button" class="btn btn-sm btn-twitter">
                    <i class="fa fa-upload"></i> {{exmtrans("backup.restore")}}
                </a>
            </div>
        </div>
        <span>
            <input type="checkbox" class="grid-select-all" />
            &nbsp;
            <div class="btn-group">
                <a class="btn btn-sm btn-default">&nbsp;<span class="hidden-xs">{{trans('admin.action')}}</span></a>
                <button type="button" class="btn btn-sm btn-default dropdown-toggle" data-toggle="dropdown">
                    <span class="caret"></span>
                    <span class="sr-only">Toggle Dropdown</span>
                </button>
                <ul class="dropdown-menu" role="menu">
                    <li><a href="#" class="grid-batch-0">{{trans('admin.delete')}}</a></li>
                </ul>
            </div>
            <a class="btn btn-sm btn-primary grid-refresh" title="{{trans('admin.refresh')}}">
                <i class="fa fa-refresh"></i>
                <span class="hidden-xs"> {{trans('admin.refresh')}}</span>
            </a> 
        </span>
    </div>
    
    <!-- /.box-header -->
    <div class="box-body table-responsive no-padding">
        <table class="table table-hover">
            <thead>
                <tr>
                    <th> </th>
                    <th>{{exmtrans("backup.file_name")}}</th>
                    <th>{{exmtrans("backup.file_size")}}</th>
                    <th>{{exmtrans("common.created_at")}}</th>
                    <th>{{trans('admin.action')}}</th>
                </tr>
            </thead>

            <tbody>
                @foreach($files as $file)
                <tr class="tableHoverLinkEvent">
                    <td>
                        <input type="checkbox" class="grid-row-checkbox" data-id="{{$file['file_key']}}" />
                    </td>
                    <td>
                        {{ $file['file_name'] }}
                    </td>
                    <td>
                        {{ $file['file_size'] }}
                    </td>
                    <td>
                        {{ $file['created'] }}
                    </td>
                    <td class="column-__actions__">
                        <a href="javascript:void(0);" data-widgetmodal_url="{{admin_urls('backup', 'importModal', $file['file_key'])}}" data-toggle="tooltip" title="{{exmtrans('backup.restore')}}">
                            <i class="fa fa-undo"></i>
                        </a>
                        <a href="javascript:void(0);" data-id="{{$file['file_key']}}" data-toggle="tooltip" title="{{trans('admin.delete')}}" class="grid-row-delete">
                            <i class="fa fa-trash"></i>
                        </a>
                        <a href="javascript:void(0);" data-id="{{$file['file_key']}}" data-toggle="tooltip" title="{{exmtrans('backup.message.edit_filename_confirm')}}" class="grid-row-editname">
                            <i class="fa fa-edit"></i>
                        </a>
                        <a href="{{admin_url('backup/download/'.$file['file_key'])}}" data-toggle="tooltip" title="{{exmtrans('common.download')}}" target="_blank">
                            <i class="fa fa-download"></i>
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <!-- /.box-body -->
</div>

<script type="text/javascript">
    function deletefile(id) {
        Exment.CommonEvent.ShowSwal('{{admin_url("backup/delete")}}', {
            title: "{{trans('admin.delete_confirm')}}",
            method: 'delete',
            confirm:"{{trans('admin.confirm')}}",
            cancel:"{{trans('admin.cancel')}}",
            data: {
                files: id
            },
        });
    }

    function editname(id) {
        Exment.CommonEvent.ShowSwal('{{admin_url("backup/editname")}}', {
            title: "{{exmtrans('backup.message.edit_filename_confirm')}}",
            text: "{{$editname_text}}",
            confirm:"{{trans('admin.submit')}}",
            input: 'text',
            inputKey: 'filename',
            cancel:"{{trans('admin.cancel')}}",
            data: {
                file: id
            },
        });
    }
    
    $(document).ready(function () {
        $('.grid-refresh').on('click', function() {
            $.pjax.reload('#pjax-container');
            toastr.success('{{trans('admin.update_succeeded')}}');
        });
        $('.grid-batch-0').on('click', function() {
            var id = selectedRows().join();
            deletefile(id);
        });
        $('.grid-select-all').iCheck({checkboxClass:'icheckbox_minimal-blue'});
        $('.grid-select-all').on('ifChanged', function(event) {
            if (this.checked) {
                $('.grid-row-checkbox').iCheck('check');
            } else {
                $('.grid-row-checkbox').iCheck('uncheck');
            }
        });
        $('.grid-row-checkbox').iCheck({checkboxClass:'icheckbox_minimal-blue'}).on('ifChanged', function () {
            if (this.checked) {
                $(this).closest('tr').css('background-color', '#ffffd5');
            } else {
                $(this).closest('tr').css('background-color', '');
            }
        });
        $('.grid-row-delete').unbind('click').click(function() {
            var id = $(this).data('id');
            deletefile(id);
        });
        $('.grid-row-editname').unbind('click').click(function() {
            var id = $(this).data('id');
            editname(id);
        });
        $('.btn-backup').unbind('click').click(function() {
            Exment.CommonEvent.ShowSwal('{{admin_url("backup/save")}}', {
                title: "{{exmtrans('backup.message.backup_confirm')}}",
                text: "{{exmtrans('common.message.execution_takes_time')}}",
                confirm:"{{trans('admin.confirm')}}",
                cancel:"{{trans('admin.cancel')}}"
            });
        });

    });
</script>