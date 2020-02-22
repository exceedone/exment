
<div class="form-group ">
    <label class="col-sm-5 col-sm-offset-2"><div class="box-body">{{exmtrans('revision.old_revision')}}</div></label>
    <label class="col-sm-5"><div class="box-body">{{exmtrans('revision.new_revision')}}</div></label>
</div>


<div class="form-group ">
    <label class="col-sm-2 control-label">{{exmtrans('revision.revision_info')}}</label>
    <div class="col-sm-5 old-col">
        @include('exment::custom-value.revision-compare-info', ['revinfo' => $old_revision, 'trashed' => $trashed]) 
    </div>
    <div class="col-sm-5 old-col">
        @include('exment::custom-value.revision-compare-info', ['revinfo' => $newest_revision, 'trashed' => $trashed]) 
    </div>
</div>
    
@foreach($table_columns as $table_column)

<div class="form-group flexheight">
    <label class="col-sm-2 control-label">{{ $table_column['label'] }}</label>
    <div class="col-sm-5 old-col">
        <div class="box box-solid box-default no-margin box-show flexheight-inner {{$table_column['diff'] ? 'box-diff' : ''}}">
            <!-- /.box-header -->
            <div class="box-body flexheight-inner">{{ $table_column['old_value'] }} </div>
            <!-- /.box-body -->
        </div>
    </div>
    
    <div class="col-sm-5 new-col">
        <div class="box box-solid box-default no-margin box-show flexheight-inner {{$table_column['diff'] ? 'box-diff' : ''}}">
            <!-- /.box-header -->
            <div class="box-body flexheight-inner">{{ $table_column['new_value'] }} </div>
            <!-- /.box-body -->
        </div>
    </div>
</div>                    
@endforeach
 
@if($has_edit_permission && $has_diff)
<div class="form-group ">
    <div class="col-sm-5 col-sm-offset-2">
        <form action="{{ $form_url }}" method="POST" pjax-container>
            <input type="hidden" name="revision" value="{{$revision_suuid}}" />
            <input type="submit" class="btn btn-primary" value="{{exmtrans('revision.restore_revision')}}" />
            {{csrf_field() }}
        </form>
    </div>
</div>   
@endif