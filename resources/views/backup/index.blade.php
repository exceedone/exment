<div class="box">
    <div class="box-header with-border">
        <div class="pull-right">
            <div class="btn-group pull-right" style="margin-right: 5px">
                <button type="button" style="margin-right:5px;" class="btn btn-sm btn-twitter btn-backup">
                    <i class="fa fa-download"></i> {{exmtrans("backup.backup")}}
                </button>

                <a href="javascript:void(0);" data-toggle="modal" data-target="#data_import_modal" type="button" class="btn btn-sm btn-twitter">
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
            <a class="btn btn-sm btn-primary grid-refresh" title="{{exmtrans('backup.reload')}}">
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
                    <td>
                        <a href="javascript:void(0);" data-id="{{$file['file_key']}}" data-toggle="tooltip" title="{{exmtrans('backup.restore')}}" class="grid-row-restore">
                            <i class="fa fa-undo"></i>
                        </a>
                        <a href="javascript:void(0);" data-id="{{$file['file_key']}}" data-toggle="tooltip" title="{{trans('admin.delete')}}" class="grid-row-delete">
                            <i class="fa fa-trash"></i>
                        </a>
                        <a href="{{admin_url('backup/download/'.$file['file_key'])}}" data-toggle="tooltip" title="{{exmtrans('backup.download')}}" target="_blank">
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
{!! $modal !!} 
<script type="text/javascript">
    function deletefile(id) {
        swal({
                title: "{{trans('admin.delete_confirm')}}",
                type: "warning",
                showCancelButton: true,
                confirmButtonColor: "#DD6B55",
                confirmButtonText: "{{trans('admin.confirm')}}",
                showLoaderOnConfirm: true,
                cancelButtonText: "{{trans('admin.cancel')}}",
                preConfirm: function() {
                    return new Promise(function(resolve) {
                        $.ajax({
                            method: 'post',
                            url: '{{admin_url("backup/delete")}}',
                            data: {
                                _method:'delete',
                                _token:'{{ csrf_token() }}',
                                files: id
                            },
                            success: function (data) {
                                $.pjax.reload('#pjax-container');
                                resolve(data);
                            }
                        });
                    });
                }
            }).then(function(result) {
                var data = result.value;
                if (typeof data === 'object') {
                    if (data.status) {
                        swal(data.message, '', 'success');
                    } else {
                        swal(data.message, '', 'error');
                    }
                }
            });
    }
    function restore(id) {
        swal({
                title: "{{exmtrans('backup.message.restore_confirm')}}",
                type: "warning",
                showCancelButton: true,
                confirmButtonColor: "#DD6B55",
                confirmButtonText: "{{trans('admin.confirm')}}",
                showLoaderOnConfirm: true,
                cancelButtonText: "{{trans('admin.cancel')}}",
                preConfirm: function() {
                    return new Promise(function(resolve) {
                        $.ajax({
                            method: 'post',
                            url: '{{admin_url("backup/restore")}}',
                            data: {
                                _method:'post',
                                _token:'{{ csrf_token() }}',
                                file: id
                            },
                            success: function (data) {
                                $.pjax.reload('#pjax-container');
                                resolve(data);
                            }
                        });
                    });
                }
            }).then(function(result) {
                var data = result.value;
                if (typeof data === 'object') {
                    if (data.status) {
                        swal(data.message, '', 'success');
                    } else {
                        swal(data.message, '', 'error');
                    }
                }
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
        $('.grid-row-restore').unbind('click').click(function() {
            var id = $(this).data('id');
            restore(id);
        });
        $('.btn-backup').unbind('click').click(function() {
            swal({
                title: "{{exmtrans('backup.message.backup_confirm')}}",
                type: "warning",
                showCancelButton: true,
                confirmButtonColor: "#DD6B55",
                confirmButtonText: "{{trans('admin.confirm')}}",
                showLoaderOnConfirm: true,
                cancelButtonText: "{{trans('admin.cancel')}}",
                preConfirm: function() {
                    return new Promise(function(resolve) {
                        $.ajax({
                            method: 'post',
                            url: '{{admin_url("backup/save")}}',
                            data: {
                                _method:'post',
                                _token:'{{ csrf_token() }}',
                            },
                            success: function (data) {
                                $.pjax.reload('#pjax-container');
                                resolve(data);
                            }
                        });
                    });
                }
            }).then(function(result) {
                var data = result.value;
                if (typeof data === 'object') {
                    if (data.status) {
                        swal(data.message, '', 'success');
                    } else {
                        swal(data.message, '', 'error');
                    }
                }
            });
        });

    });
</script>