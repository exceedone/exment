<div>
    <div class="btn-group pull-right">
        @if(isset($list_url))
        <a href="{{$list_url}}" class="btn btn-sm btn-default pe-1" style="margin-right:5px;" data-toggle="tooltip" data-placement="left" title="{{trans('admin.list')}}">
            <i class="fa fa-list"></i>
        </a>
        @endif
        @if(isset($new_url))
        <a href="{{$new_url}}" class="btn btn-sm btn-success pe-1" data-toggle="tooltip" title="{{trans('admin.new')}}" data-placement="left">
            <i class="fa fa-plus"></i>
        </a>
        @endif
    </div>
</div>