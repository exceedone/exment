{!! \Exceedone\Exment\Form\Widgets\Modal::widgetModalRender() !!}
<aside id="sidebar" class="menu-width sidebar hidden-mb collapse show" style="border-right:1px solid #d2d6de">


    <div class="user-panel d-flex">
        <div class="float-start image">
            <img src="{{ Admin::user()->display_avatar }}" class="rounded-circle" alt="User Image">
        </div>
        <div class="float-start info ms-2" style="line-height: 1;padding: 5px 5px 5px 5px;">
            <p>{{ Admin::user()->name }}</p>
            <p class="small gray">&#064;{{ Admin::user()->user_code }}</p>
            {{ trans('admin.online') }}</a>
        </div>
    </div>



    <nav>

        <div class="custom-menu">
            <ul class="list-unstyled ps-0 root" id="menu">
                <li class="header">{{ trans('admin.menu') }}</li>
                @each('admin::partials.menu', Admin::menu(), 'item')
            </ul>
        </div>
    </nav>
</aside>

<script>
$(document).ready(function () {
    function updateSidebar() {
        const isMobile = $(window).width() <= 768;

        if (isMobile) {
            $('#sidebar').addClass('collapse').removeClass('show hidden-mb');
            $('#main.content-wrapper').css('margin-left', '0');
        } else {
            $('#sidebar').removeClass('collapse hidden-mb').addClass('show');
            $('#main.content-wrapper').css('margin-left', '');
            $('.main-header .logo').css('width', '');
        }
    }

    $('#sidebar').on('show.bs.collapse', function () {
        if ($(window).width() <= 768) {
            $('#main.content-wrapper').css('margin-left', '230px');
            $('#sidebar').removeClass('hidden-mb');
        } else {
            $('.main-header .logo').css('width', '');
        }
    });

    $('#sidebar').on('hidden.bs.collapse', function () {
        if ($(window).width() <= 768) {
            $('#main.content-wrapper').css('margin-left', '0');
            $('#sidebar').addClass('hidden-mb');
        } else {
            $('.main-header .logo').css('width', '0');
        }
    });

    $(window).on('resize', updateSidebar);
    updateSidebar();
});
</script>
