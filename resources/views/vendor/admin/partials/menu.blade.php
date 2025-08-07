@if(Admin::user()->visible($item))
    @if(!isset($item['children']))
        <li class="menu-item p-0" data-uri="{{ $item['uri'] }}">
            @if(url()->isValidUrl($item['uri']))
                <a href="{{ $item['uri'] }}" target="_blank" class="ps-3 py-2">
            @else
                <a href="{{ admin_url($item['uri']) }}" class="ps-3 py-2">
            @endif
                <i class="fa {{$item['icon']}}"></i>
                <span>{{$item['title']}}</span>
            </a>
        </li>
    @else
        <li class="treeview menu-item p-0" data-uri="{{ $item['uri'] }}">
            <a href="javascript:void(0)" id="toggle-submenu-{{$item['id']}}" class="has-subs p-3" data-target="#submenu-{{$item['id']}}" role="button">
                <i class="fa {{$item['icon']}}"></i>
                <span>{{$item['title']}}</span>
            </a>
            <ul id="submenu-{{$item['id']}}" class="submenu list-unstyled fw-normal pb-1 mt-0 ms-2" style="display: none;">
                @foreach($item['children'] as $child)
                    @include('admin::partials.menu', ['item' => $child])
                @endforeach
            </ul>
        </li>
    @endif
@endif