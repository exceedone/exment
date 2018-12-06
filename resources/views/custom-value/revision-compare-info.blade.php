<div class="box box-solid box-default no-margin box-show">
    <!-- /.box-header -->
    <div class="box-body">
        リビジョンNo：{{ array_get($revinfo, 'revision_no') }}
        <br/>リビジョンID：{{ array_get($revinfo, 'suuid') }}
        <br/>更新ユーザー：{{ array_get($revinfo, 'user') }}
        <br/>更新日時：{{ array_get($revinfo, 'updated_at') }}
    </div>
    <!-- /.box-body -->
</div>