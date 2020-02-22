<div class="box box-solid box-default no-margin box-show">
    <!-- /.box-header -->
    <div class="box-body">
        {{exmtrans('revision.revision_no')}}&nbsp;:&nbsp;{{ array_get($revinfo, 'revision_no') }}
        <br/>{{exmtrans('revision.revision_id')}}&nbsp;:&nbsp;{{ array_get($revinfo, 'suuid') }}
        <br/>{{exmtrans('common.updated_user')}}&nbsp;:&nbsp;{{ array_get($revinfo, 'user') }}
        <br/>{{exmtrans('common.updated_at')}}&nbsp;:&nbsp;{{ array_get($revinfo, 'updated_at') }}

        @if($trashed)
        <br/>{{exmtrans('common.deleted_user')}}&nbsp;:&nbsp;{{ array_get($revinfo, 'delete_user') }}
        <br/>{{exmtrans('common.deleted_at')}}&nbsp;:&nbsp;{{ array_get($revinfo, 'deleted_at') }}
        @endif
    </div>
    <!-- /.box-body -->
</div>