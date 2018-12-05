
<div class="form-group ">
    <label class="col-sm-5 col-sm-offset-2"><div class="box-body">過去リビジョン</div></label>
    <label class="col-sm-5"><div class="box-body">最新リビジョン</div></label>
</div>


<div class="form-group ">
    <label class="col-sm-2 control-label">リビジョン情報</label>
    <div class="col-sm-5 old-col">
        @include('exment::custom-value.revision-compare-info', ['revinfo' => $old_revision]) 
    </div>
    <div class="col-sm-5 old-col">
        @include('exment::custom-value.revision-compare-info', ['revinfo' => $newest_revision]) 
    </div>
</div>
    
@foreach($table_columns as $table_column)

<div class="form-group ">
    <label class="col-sm-2 control-label">{{ $table_column['label'] }}</label>
    <div class="col-sm-5 old-col">
        <div class="box box-solid box-default no-margin box-show {{$table_column['diff'] ? 'box-diff' : ''}}">
            <!-- /.box-header -->
            <div class="box-body">{{ $table_column['old_value'] }} </div>
            <!-- /.box-body -->
        </div>
    </div>
    
    <div class="col-sm-5 new-col">
        <div class="box box-solid box-default no-margin box-show {{$table_column['diff'] ? 'box-diff' : ''}}">
            <!-- /.box-header -->
            <div class="box-body">{{ $table_column['new_value'] }} </div>
            <!-- /.box-body -->
        </div>
    </div>
</div>                    
@endforeach
 
@if($has_diff)
<div class="form-group ">
    <div class="col-sm-5 col-sm-offset-2">
        <form action="{{ $form_url }}" method="POST" pjax-container>
            <input type="hidden" name="revision" value="{{$revision_suuid}}" />
            <input type="submit" class="btn btn-primary" value="このリビジョンを復元" />
            {{csrf_field() }}
        </form>
    </div>
</div>   
@endif