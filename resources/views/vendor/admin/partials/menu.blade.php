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
            <a href="#" id="toggle-submenu-{{$item['id']}}" onclick="toggleMenu(event)" class="has-subs p-3" data-target="#submenu-{{$item['id']}}">
                <i class="fa {{$item['icon']}}"></i>
                <span>{{$item['title']}}</span>
            </a>
            <ul id="submenu-{{$item['id']}}" class="submenu list-unstyled fw-normal pb-1 mt-0 ms-4" style="display: none;">
                @foreach($item['children'] as $child)
                    @include('admin::partials.menu', ['item' => $child])
                @endforeach
            </ul>
        </li>
    @endif
@endif
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
function toggleMenu(e) {
    e.preventDefault();
    const targetId = e.currentTarget.getAttribute('data-target');
    const submenu = document.querySelector(targetId);    
    const toggleLink = e.currentTarget;
    const menuItem = toggleLink.closest('.menu-item');
    
    const topLevelMenuItem = !menuItem.closest('.submenu') ? menuItem : null;
    

    document.querySelectorAll('.submenu').forEach(function(otherSubmenu) {
        if (otherSubmenu !== submenu) {
            otherSubmenu.style.display = 'none';
        }
    });
    document.querySelectorAll('.has-subs').forEach(function(otherLink) {
        if (otherLink !== toggleLink) {
            otherLink.classList.remove('active');
        }
    });
    document.querySelectorAll('.menu-item').forEach(function(otherItem) {
        if (otherItem !== menuItem) {
            otherItem.classList.remove('active');
        }
    });

    if (submenu) {
        if (submenu.style.display === 'none' || submenu.style.display === '') {
            submenu.style.display = 'block';
            toggleLink.classList.add('active');
            menuItem.classList.add('active');
        } else {
            submenu.style.display = 'none';
            toggleLink.classList.remove('active');
            menuItem.classList.remove('active');
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.menu-item:not(.treeview)').forEach(function(menuItem) {
        menuItem.addEventListener('click', function(e) {
            const isInSubmenu = menuItem.closest('.submenu') !== null;
            

            if (isInSubmenu) {
                document.querySelectorAll('.menu-item:not(.treeview)').forEach(function(item) {
                    item.classList.remove('active');
                });
            } else {
                document.querySelectorAll('.menu-item').forEach(function(item) {
                    item.classList.remove('active');
                });
                
                document.querySelectorAll('.submenu').forEach(function(submenu) {
                submenu.style.display = 'none';
            });
            }
            this.classList.add('active');
            
            document.querySelectorAll('.has-subs').forEach(function(link) {
                link.classList.remove('active');
            });
        });
    });
});
</script>