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
            <a href="#" id="toggle-submenu-{{$item['id']}}" onclick="toggleMenu(event)" class="has-subs" data-target="#submenu-{{$item['id']}}">
                <i class="fa {{$item['icon']}}"></i>
                <span>{{$item['title']}}</span>
            </a>
            <ul id="submenu-{{$item['id']}}" class="submenu list-unstyled fw-normal pb-1" style="display: none;">
                @foreach($item['children'] as $item)
                    @include('admin::partials.menu', $item)
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

    if (submenu) {
        // Đóng tất cả các submenu khác và xóa class 'active' từ các toggle link khác
        document.querySelectorAll('.submenu').forEach(function (otherSubmenu) {
            if (otherSubmenu !== submenu) {
                otherSubmenu.style.display = 'none';
            }
        });
        document.querySelectorAll('.has-subs').forEach(function (otherLink) {
            if (otherLink !== toggleLink) {
                otherLink.classList.remove('active');
            }
        });

        // Toggle submenu hiện tại
        if (submenu.style.display === 'none' || submenu.style.display === '') {
            submenu.style.display = 'block';
            toggleLink.classList.add('active');
        } else {
            submenu.style.display = 'none';
            toggleLink.classList.remove('active');
        }
    }
}
</script>