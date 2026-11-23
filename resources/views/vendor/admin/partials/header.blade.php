<!-- Main Header -->
<header class="main-header">

    <!-- Logo -->
    <a href="{{ admin_url('/') }}" class="logo">
        <!-- mini logo for sidebar mini 50x50 pixels -->
        <span class="logo-mini">{!! config('admin.logo-mini', config('admin.name')) !!}</span>
        <!-- logo for regular state and mobile devices -->
        <span class="logo-lg">{!! config('admin.logo', config('admin.name')) !!}</span>
    </a>

    <!-- Header Navbar -->
    <nav class="navbar navbar-static-top" role="navigation">
        <!-- Sidebar toggle button-->
        <a href="#" class="sidebar-toggle" id="sidebar-toggle" role="button">
            <span class="sr-only">Toggle navigation</span>
        </a>

        {!! Admin::getNavbar()->render('left') !!}

        <!-- Navbar Right Menu -->
        <div class="navbar-custom-menu">
            <ul class="nav navbar-nav">

                {!! Admin::getNavbar()->render() !!}

                <!-- User Account Menu -->
                <li class="dropdown user user-menu">
                    <!-- Menu Toggle Button -->
                    <a href="#" class="dropdown-toggle" data-bs-toggle="dropdown">
                        <!-- The user image in the navbar-->
                        <img src="{{ Admin::user()->display_avatar }}" class="user-image" alt="User Image">
                        <!-- hidden-xs hides the username on small devices so only the image appears. -->
                        <span class="hidden-xs">{{ Admin::user()->name }}</span>
                    </a>
                    <ul class="dropdown-menu">
                        <!-- The user image in the menu -->
                        <li class="user-header">
                            <img src="{{ Admin::user()->display_avatar }}" class="img-circle" alt="User Image">

                            <p>
                                {{ Admin::user()->name }}
                                <small>{!! Admin::user()->getHeaderInfo() !!}</small>
                            </p>
                        </li>
                        @if(\Exceedone\Exment\Services\Line\LineInstaller::linkAvailable())
                        <li class="user-body">
                            <a href="{{ admin_url('line/link') }}" class="btn btn-default btn-flat btn-block"><i class="fa fa-comments"></i> {{ exmtrans('line.link_menu_title') }}</a>
                        </li>
                        @endif
                        @php($exmentLocales = \Exceedone\Exment\Services\UserLocale::allowedOptions())
                        @if(count($exmentLocales) > 1)
                        <li class="user-body">
                            {{-- one button for each language (not a select: the pages have their own selects) --}}
                            <form method="POST" action="{{ admin_url('auth/setting/locale') }}" id="exment-user-locale" class="exment-user-locale" data-nopjax>
                                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                                <div class="small mb-1"><i class="fa fa-language"></i> {{ exmtrans('user.locale') }}</div>
                                <div class="d-flex flex-wrap gap-1">
                                    @foreach($exmentLocales as $exmentLocale => $exmentLocaleLabel)
                                    <button type="submit" name="locale" value="{{ $exmentLocale }}" lang="{{ str_replace('_', '-', $exmentLocale) }}"
                                        class="btn btn-sm {{ $exmentLocale === \App::getLocale() ? 'btn-primary' : 'btn-default' }}"
                                        @if($exmentLocale === \App::getLocale()) aria-current="true" @endif>{{ $exmentLocaleLabel }}</button>
                                    @endforeach
                                </div>
                            </form>
                        </li>
                        @endif
                        <li class="user-footer">
                            @if(Admin::user()->visible('auth/setting'))
                            <div class="pull-left">
                                <a href="{{ admin_url('auth/setting') }}" class="btn btn-default btn-flat">{{ trans('admin.setting') }}</a>
                            </div>
                            @endif
                            <div class="pull-right">
                                <a href="{{ admin_url('auth/logout') }}" class="btn btn-default btn-flat" data-nopjax>{{ trans('admin.logout') }}</a>
                            </div>
                        </li>
                    </ul>
                </li>
                <!-- Control Sidebar Toggle Button -->
                {{--<li>--}}
                    {{--<a href="#" data-toggle="control-sidebar"><i class="fa fa-gears"></i></a>--}}
                {{--</li>--}}
            </ul>
        </div>
    </nav>
</header>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const toggle = document.getElementById('sidebar-toggle');
    if (toggle) {
        toggle.addEventListener('click', function (e) {
            e.preventDefault();
            if (window.innerWidth < 768) {
                // Mobile: use sidebar-open to slide sidebar in
                document.body.classList.toggle('sidebar-open');
            } else {
                // Desktop: use sidebar-collapse to collapse/expand sidebar
                document.body.classList.toggle('sidebar-collapse');
                // Remembered, so the next page opens the same way: the server
                // reads it (Middleware\Initialize::applySidebarState).
                var state = document.body.classList.contains('sidebar-collapse') ? 'collapsed' : 'expanded';
                document.cookie = '{{ \Exceedone\Exment\Model\Define::COOKIE_SIDEBAR_STATE }}=' + state
                    + '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
            }
        });
    }
});
</script>
