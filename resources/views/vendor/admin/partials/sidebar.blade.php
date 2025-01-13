{!! \Exceedone\Exment\Form\Widgets\Modal::widgetModalRender() !!}
<aside id="sidebar" class="menu-width sidebar" style="border-right:1px solid #d2d6de">


    <div class="user-panel d-flex">
        <div class="float-start image">
            <img src="{{ Admin::user()->display_avatar }}" class="rounded-circle" alt="User Image">
            <!-- http://127.0.0.1:8000/admin/files/fc601fe0-c365-11ef-85ea-cdd7fa3934d4 -->
        </div>
        <div class="float-start info ms-2">
            <p>{{ Admin::user()->name }}</p>
            <!-- Status -->
            <!-- <a href="#"><i class="icon-circle text-success"></i> {{ trans('admin.online') }}</a> -->
        </div>
    </div>



    <nav>

        <div class="custom-menu">
            <ul class="list-unstyled ps-0 root" id="menu">
            <li class="header">Menu</li>
                @each('admin::partials.menu', Admin::menu(), 'item')
            </ul>
        </div>
    </nav>
</aside>
