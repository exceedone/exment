
<form action="{{ admin_url('search') }}" method="get" class="search-form" pjax-container>
    <div class="input-group input-group-sm ">
        <input type="text" name="query" id="query" class="form-control" placeholder="{{ exmtrans('search.placeholder') }}" data-ajax_url="{{$ajax_url}}" data-list_url="{{$list_url}}" />
        <span class="input-group-btn">
            <button type="submit" id="search-btn" class="btn btn-flat">
                <i class="fa fa-search"></i>
            </button>
        </span>
    </div>
</form>
