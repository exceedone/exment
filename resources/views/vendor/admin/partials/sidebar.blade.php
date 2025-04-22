{!! \Exceedone\Exment\Form\Widgets\Modal::widgetModalRender() !!}
<aside id="sidebar" class="menu-width sidebar collapse" style="border-right:1px solid #d2d6de">


    <div class="user-panel d-flex">
        <div class="float-start image">
            <img src="{{ Admin::user()->display_avatar }}" class="rounded-circle" alt="User Image">
        </div>
        <div class="float-start info ms-2">
            <p>{{ Admin::user()->name }}</p>
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
            if ($(window).width() > 576) {
                $('#sidebar').removeClass('collapse').addClass('show');
                $('.main-header .logo').css('width', '');

                $('#sidebar').on('hidden.bs.collapse', function () {
                    $('.main-header .logo').css('width', '0');
                });

                $('#sidebar').on('show.bs.collapse', function () {
                    $('.main-header .logo').css('width', '');
                });
            } else {
                $('#sidebar').addClass('collapse').removeClass('show');
                $('.main-header .logo').css('width', '');
            }
        }
        $('#sidebar').on('show.bs.collapse', function () {
            if ($(window).width() <= 576) {
                $('#main.content-wrapper').css('margin-left', '230px');
            }
        });
        $('#sidebar').on('hidden.bs.collapse', function () {
            if ($(window).width() <= 576) {
                $('#main.content-wrapper').css('margin-left', '0');
            }
        });

        $(window).on('resize', updateSidebar);
        updateSidebar();
    });
</script>