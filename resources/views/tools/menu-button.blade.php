<div class="btn-group pull-right" style="margin-right: 5px">
        <button type="button" class="btn btn-sm btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="fa {{ $button_icon??'fa-exchange' }}"></i>&nbsp;<span class="hidden-xs">{{ $button_label }}&nbsp;</span>
            <span class="caret"></span>
        </button>
        <ul id="custom-table-menu" class="dropdown-menu">
            @foreach($menulist as $menu)
                <li><a href="{{ array_get($menu, 'href', 'javascript:void(0);') }}" ><i class="fa {{ array_get($menu, 'icon')??'fa-asterisk' }}"></i>&nbsp;{{ array_get($menu, 'label') }}</a></li>
            @endforeach
        </ul>
    </div>    