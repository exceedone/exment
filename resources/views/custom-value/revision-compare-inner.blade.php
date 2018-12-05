
<div class="form-group ">
    <label class="col-sm-5 col-sm-offset-2">過去リビジョン</label>
    <label class="col-sm-5">最新リビジョン</label>
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
 
<div class="form-group ">
    <div class="col-sm-5 col-sm-offset-2">
        <form action="{{ $form_url }}" method="POST" pjax-container>
            <input type="hidden" name="revision" value="{{$revision_suuid}}" />
            <input type="submit" class="btn btn-primary" value="復元" />
            {{csrf_field() }}
        </form>
    </div>
</div>   