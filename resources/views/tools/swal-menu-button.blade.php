<div class="btn-group pull-right" style="margin-right: 5px">
        <button type="button" class="btn btn-sm btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="fa fa-cog"></i>&nbsp;<span class="hidden-xs">{{ $button_label }}&nbsp;</span>
            <span class="caret"></span>
        </button>
        <ul id="custom-table-menu" class="dropdown-menu">
            @foreach($menulist as $menu)
            <li>
                <a href="javascript:void(0);" data-add-swal="{{ array_get($menu, 'url') }}" data-add-swal-title="{{ array_get($menu, 'title') }}" data-add-swal-text="{{ array_get($menu, 'text') }}" data-add-swal-method="{{ array_get($menu, 'method', 'GET') }}" data-add-swal-confirm="{{ array_get($menu, 'confirm') }}" data-add-swal-cancel="{{ array_get($menu, 'cancel') }}">&nbsp;{{ array_get($menu, 'label') }}</a>
            </li>
            @endforeach
        </ul>
    </div>    