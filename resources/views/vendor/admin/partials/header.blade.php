<!-- Main Header -->
<header class="custom-navbar navbar navbar-light p-0 align-items-stretch main-header">
    <a class="navbar-brand menu-width bg-semi-dark text-center logo" href="{{ admin_url('/') }}">
        <span class="short">{!! config('admin.logo-mini', config('admin.name')) !!}</span><span
            class="long user-image">{!! config('admin.logo', config('admin.name')) !!}</span>
    </a>
    <div class="d-flex flex-fill flex-wrap header-items navbar">

        <a class="flex-shrink order-1 order-sm-0 valign-header px-4" type="button" id='menu-toggle' aria-controls="menu"
            aria-expanded="false" aria-label="Toggle navigation">
            <i class="fa fa-bars"></i>
        </a>

        {{-- <ul class="nav navbar-nav hidden-sm visible-lg-block"> --}}
            {!! Admin::getNavbar()->render('left') !!}
            {{--
        </ul> --}}


        <ul class="nav order-2 ms-auto d-flex align-items-center gap-3">

            {!! Admin::getNavbar()->render() !!}

            <li class="nav-item">
                <div class="dropdown user-menu d-flex align-items-center px-3" href="#" role="button"
                    id="user-menu-link" data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="inline rounded-circle user-image">
                        <img src="{{ Admin::user()->display_avatar }}" alt="User Image">
                    </span>
                    <span class="d-none d-sm-inline text-white">{{ Admin::user()->name }}</span>
                </div>
                <ul class="dropdown-menu dropdown-menu-end user-menu" aria-labelledby="user-menu-link">
                    <!-- The user image in the menu -->
                    <li class="user-header text-center bg-semi-dark p-3">
                        <span class="bg-light inline rounded-circle user-image medium">
                            <img src="{{ Admin::user()->display_avatar }}" alt="User Image">
                        </span>
                        <p>
                        <h2>{{ Admin::user()->name }}</h2>
                        <small>{!! Admin::user()->getHeaderInfo() !!}</small>
                        </p>
                    </li>
                    <li class="user-footer p-2 clearfix">
                        @if(Admin::user()->visible('auth/setting'))
                            <div class="float-start">
                                <a href="{{ admin_url('auth/setting') }}"
                                    class="btn btn-secondary">{{ trans('admin.setting') }}</a>
                            </div>
                        @endif
                        <div class="float-end">
                            <a href="{{ admin_url('auth/logout') }}"
                                class="btn no-ajax btn-secondary">{{ trans('admin.logout') }}</a>
                        </div>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</header>