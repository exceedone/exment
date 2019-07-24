<div class="btn-group pull-right" style="margin-right: 5px">
    <a href="{{admin_url('data/'.$table_name.'/create'). ( isset($params) ? '?' . http_build_query($params) : '' ) }}" class="btn btn-sm btn-success">
        <i class="fa fa-plus"></i><span class="hidden-xs">&nbsp;&nbsp;{{trans('admin.new')}}</span>
    </a>
</div>