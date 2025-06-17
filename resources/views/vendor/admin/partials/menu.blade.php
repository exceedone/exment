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
            <a href="#" id="toggle-submenu-{{$item['id']}}" class="has-subs p-3" data-target="#submenu-{{$item['id']}}">
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

@once
    <script>
        document.addEventListener('DOMContentLoaded', function () {

            document.querySelectorAll('.has-subs').forEach(function (toggleLink) {
                toggleLink.addEventListener('click', function (e) {
                    e.preventDefault();
                    e.stopPropagation();

                    const targetId = this.getAttribute('data-target');
                    const submenu = document.querySelector(targetId);
                    const menuItem = this.closest('.menu-item');

                    if (!submenu) {
                        console.error('Submenu not found for ID:', targetId);
                        return;
                    }

                    const currentDisplay = submenu.style.display || getComputedStyle(submenu).display;

                    document.querySelectorAll('.submenu').forEach(function (otherSubmenu) {
                        if (otherSubmenu !== submenu) {
                            otherSubmenu.style.display = 'none';
                            const otherSubmenuId = otherSubmenu.id;
                            const otherToggleLink = document.querySelector(`[data-target="#${otherSubmenuId}"]`);
                            const otherMenuItem = otherToggleLink ? otherToggleLink.closest('.menu-item') : null;
                            if (otherToggleLink) otherToggleLink.classList.remove('active');
                            if (otherMenuItem) otherMenuItem.classList.remove('active');
                        }
                    });

                    if (currentDisplay === 'none' || currentDisplay === '') {
                        submenu.style.display = 'block';
                        this.classList.add('active');
                        menuItem.classList.add('active');
                    } else {
                        submenu.style.display = 'none';
                        this.classList.remove('active');
                        menuItem.classList.remove('active');
                    }
                });
            });

        });
    </script>
@endonce