<div class="btn-group float-end" style="margin-right: 5px">
    <a href="{{ admin_urls('plugin', $id, 'edit') }}" class="btn btn-sm btn-info" title="{{ trans('admin.back') }}">
        <i class="fa fa-edit"></i>
        <span class="d-none d-md-inline">{{ trans('admin.back') }}</span>
    </a>
</div>
<div class="btn-group float-end" style="margin-right: 5px">
    <a href="{{ admin_url('plugin') }}" class="btn btn-sm btn-default" title="{{ trans('admin.list') }}">
        <i class="fa fa-list"></i><span class="d-none d-md-inline">{{ trans('admin.list') }}</span>
    </a>
</div>