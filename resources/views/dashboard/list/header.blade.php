<div>
    <div class="btn-group float-end">
        @if(isset($list_url))
        <a href="{{$list_url}}" class="btn btn-sm btn-default p-1" style="margin-right:5px;" data-bs-toggle="tooltip" data-bs-placement="left" title="{{trans('admin.list')}}">
            <i class="fa fa-list p-2"></i>
        </a>
        @endif
        @if(isset($new_url))
        <a href="{{$new_url}}" class="btn btn-sm btn-success p-1" data-bs-toggle="tooltip" title="{{trans('admin.new')}}" data-bs-placement="left">
            <i class="fa fa-plus p-2"></i>
        </a>
        @endif
    </div>
</div>