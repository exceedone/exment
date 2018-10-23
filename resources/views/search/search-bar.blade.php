
<style>
    .search-form {
        width: 400px;
        max-width: 70%;
        margin: 10px 0 0 20px;
        border-radius: 3px;
        float: left;
    }

    .search-form input[type="text"] {
        color: #666;
        border: 0;
    }

    .search-form .btn {
        color: #999;
        background-color: #fff;
        border: 0;
    }
        
    .ui-autocomplete {
        z-index: 1031;
    }

    .search-item-icon {
        display: inline-block;
        font-size: 0.9em;
        margin: 0 5px 0 2px;
        padding: 2px 5px;
        background-color: #3c8dbc;
        border-radius:5px;
        color:white;
    }
    .search-item-icon i{
        padding-right:2px;
    }
</style>

<form action="{{ admin_base_path('search') }}" method="get" class="search-form" pjax-container>
    <div class="input-group input-group-sm ">
        <input type="text" name="query" id="query" class="form-control" placeholder="{{ exmtrans('search.placeholder') }}" />
        <span class="input-group-btn">
            <button type="submit" id="search-btn" class="btn btn-flat">
                <i class="fa fa-search"></i>
            </button>
        </span>
    </div>
</form>
