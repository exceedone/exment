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
    <style>
    .treeview .has-subs::after {
        content: '\f054';
        font-family: 'Font Awesome 5 Free';
        font-weight: 900;
        float: right;
        transition: transform 0.3s;
        top: 19px !important;
    }

    .treeview .has-subs.active::after {
        transform: rotate(135deg);
    }
    </style>

    <script>
    if (!window.menuScriptInitialized) {
        window.menuScriptInitialized = true;

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.has-subs').forEach(function (toggleLink, index) {
                toggleLink.removeEventListener('click', handleSubmenuClick);
                toggleLink.addEventListener('click', handleSubmenuClick);

            });

            function handleSubmenuClick(e) {
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
                    }
                });
                document.querySelectorAll('.has-subs').forEach(function (otherLink) {
                    if (otherLink !== this) {
                        otherLink.classList.remove('active');
                    }
                }, this);
                document.querySelectorAll('.menu-item').forEach(function (otherItem) {
                    if (otherItem !== menuItem) {
                        otherItem.classList.remove('active');
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

                
            }

            document.querySelectorAll('.menu-item:not(.treeview)').forEach(function (menuItem, index) {
                menuItem.removeEventListener('click', handleMenuItemClick);
                menuItem.addEventListener('click', handleMenuItemClick);

            });

            function handleMenuItemClick(e) {
                e.stopPropagation(); 

                const isInSubmenu = this.closest('.submenu') !== null;

                if (isInSubmenu) {
                    document.querySelectorAll('.menu-item:not(.treeview)').forEach(function (item) {
                        item.classList.remove('active');
                    });
                } else {
                    document.querySelectorAll('.menu-item').forEach(function (item) {
                        item.classList.remove('active');
                    });
                    document.querySelectorAll('.submenu').forEach(function (submenu) {
                        submenu.style.display = 'none';
                    });
                    document.querySelectorAll('.has-subs').forEach(function (link) {
                        link.classList.remove('active');
                    });
                }

                this.classList.add('active');
            }
        });
    }
    </script>
@endonce