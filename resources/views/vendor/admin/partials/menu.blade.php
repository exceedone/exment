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

@once
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            let submenuClicking = false;

            document.querySelectorAll('.submenu').forEach(function (submenu) {
                submenu.addEventListener('click', function (e) {
                    e.stopPropagation();
                });
            });

            document.querySelectorAll('.has-subs').forEach(function (toggleLink) {
                toggleLink.addEventListener('click', function (e) {
                    // Chỉ xử lý sự kiện click thật từ người dùng
                    if (!e.isTrusted) {
                        return;
                    }


                    if (e.detail > 1) {
                        e.preventDefault();
                        return;
                    }
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

            var currentPath = window.location.pathname;
            document.querySelectorAll('.menu-item[data-uri]').forEach(function (item) {
                var uri = item.getAttribute('data-uri');
                if (uri && currentPath.indexOf(uri) !== -1) {
                    var submenu = item.querySelector('.submenu');
                    if (submenu) {
                        submenu.style.display = 'block';
                        item.classList.add('active');
                        var toggleLink = item.querySelector('.has-subs');
                        if (toggleLink) toggleLink.classList.add('active');
                    }
                    var parent = item.parentElement;
                    while (parent && parent.classList) {
                        if (parent.classList.contains('submenu')) {
                            parent.style.display = 'block';
                            var parentItem = parent.closest('.menu-item');
                            if (parentItem) parentItem.classList.add('active');
                            var parentToggle = parentItem ? parentItem.querySelector('.has-subs') : null;
                            if (parentToggle) parentToggle.classList.add('active');
                        }
                        parent = parent.parentElement;
                    }
                }
            });
        });
    </script>
@endonce