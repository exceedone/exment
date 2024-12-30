@if(Admin::user()->visible($item))
    @if(!isset($item['children']))
        <li>
            @if(url()->isValidUrl($item['uri']))
                <a href="{{ $item['uri'] }}" target="_blank">
            @else
                 <a href="{{ admin_url($item['uri']) }}">
            @endif
                <i class="fa {{$item['icon']}}"></i>
                <span>{{$item['title']}}</span>
            </a>
        </li>
    @else
        <li class="treeview">
            <a href="#" class="has-subs" data-bs-toggle="collapse" data-bs-target="#collapse-{{$item['id']}}" aria-expanded="false">
            <i class="fa {{$item['icon']}}"></i>
                <span>{{$item['title']}}</span>
            </a>
            <ul id="collapse-{{$item['id']}}" class="btn-toggle-nav list-unstyled collapse fw-normal pb-1">
                @foreach($item['children'] as $item)
                    @include('admin::partials.menu', $item)
                @endforeach
            </ul>
        </li>
    @endif
@endif
