<div class="btn-group float-end" style="margin-right: 5px">
    <a href="{{admin_url('data/'.$table_name.'/create'). ( isset($params) ? '?' . http_build_query($params) : '' ) }}" class="btn p-2 btn-sm btn-success d-flex align-items-center">
        <i class="fa fa-plus p-1"></i><span class="d-none d-lg-block">&nbsp;&nbsp;{{trans('admin.new')}}</span>
    </a>
</div>