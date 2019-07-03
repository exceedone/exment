<div class="btn-group pull-right" style="margin-right: 5px">
@if(isset($params))
    <a href="{{admin_url('data/'.$table_name.'/create'). '?' . http_build_query($params)}}" class="btn btn-sm btn-success">
@else
    <a href="{{admin_url('data/'.$table_name.'/create')}}" class="btn btn-sm btn-success">
@endif
        <i class="fa fa-plus"></i><span class="hidden-xs">&nbsp;&nbsp;{{trans('admin.new')}}</span>
    </a>
</div>