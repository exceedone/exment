<div class="btn-group pull-right" style="margin-right: 5px">
    <a href="{{ admin_urls('plugin', $id, 'edit') }}" class="btn btn-sm btn-info" title="{{ trans('admin.back') }}">
        <i class="fa fa-edit"></i>
        <span class="hidden-xs">{{ trans('admin.back') }}</span>
    </a>
</div>
@php $tenantUuid = config('exment.market_tenant_uuid'); @endphp
@if(empty($tenantUuid))
<div class="btn-group pull-right" style="margin-right: 5px">
    <a href="{{ admin_url('plugin-market') }}" class="btn btn-sm btn-success" title="{{ exmtrans('plugin.market.title') }}">
        <i class="fa fa-shopping-cart"></i><span class="hidden-xs">&nbsp;{{ exmtrans('plugin.market.title') }}</span>
    </a>
</div>
@endif
<div class="btn-group pull-right" style="margin-right: 5px">
    <a href="{{ admin_url('plugin') }}" class="btn btn-sm btn-default" title="{{ trans('admin.list') }}">
        <i class="fa fa-list"></i><span class="hidden-xs">{{ trans('admin.list') }}</span>
    </a>
</div>