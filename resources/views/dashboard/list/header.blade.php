<div>
    <div class="btn-group pull-right">
        @if(isset($list_url))
        <a href="{{$list_url}}" class="btn btn-sm btn-default" style="margin-right:5px;">
            <i class="fa fa-list"></i>
        </a>
        @endif
        @if(isset($new_url))
        <a href="{{$new_url}}" class="btn btn-sm btn-success">
            <i class="fa fa-plus"></i>
        </a>
        @endif
    </div>
</div>