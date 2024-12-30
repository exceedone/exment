<!-- Main Header -->
<header class="custom-navbar navbar navbar-light p-0 align-items-stretch main-header">
    <a class="navbar-brand menu-width container-md bg-semi-dark text-center logo" href="{{ admin_url('/') }}">
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
<style>
    .custom-navbar .header-items>a:hover,
    .custom-navbar .header-items>ul>li:hover,
    .custom-navbar .navbar-brand :hover {
        background: transparent !important;
    }

    .custom-menu ul a:hover {
        color: black !important;
    }

    .custom-menu>ul>li.active {
        background: transparent !important;
    }

    .custom-menu ul li ul {
        border-left: none !important;
    }

    .custom-navbar .navbar-brand span {
        color: white !important;
    }

    section.content {
        padding-top: 15px;
    }

    span.d-none d-sm-inline {
        color: white;
    }

    th.column-__row_selector__ {
        /* display: none; */
    }

    .table-responsive th.column-__actions__ {
        text-align: start;
    }

    .table-responsive .__actions__div {
        background: none;
    }

    a {
        text-decoration: none;
    }

    .content>*:not(footer) {
        flex-grow: 1;
    }

    footer {
        margin-top: auto;
    }

    a#menu-toggle {
        color: white;
    }

    .user-panel {
        margin-top: 0px;
        padding: 0.5rem 1.2rem;
    }


    .text-dotted {
        text-decoration-style: dotted;
    }

    .btn-outline-secondary {
        color: #ffffff;
        border-color: #ffffff;
    }

    .btn-outline-secondary:hover,
    .btn-outline-secondary.dropdown-toggle.show {
        color: #fff;
        background-color: #0d6aad;
        border-color: #0d6aad;
    }

    .dropdown-menu>li>a {
        display: block;
        padding: 3px 20px;
        clear: both;
        font-weight: 400;
        line-height: 1.42857143;
        color: #333;
        white-space: nowrap;
        text-decoration: none;
    }

    ul.dropdown-menu.show {
        transform: translate(0, 29px) !important;
    }

    .btn-outline-secondary.dropdown-toggle.show:focus {
        box-shadow: none;
    }

    #filter-box>form>div.box-footer>div>div {
        display: flex;
    }

    .col-form-label {
        text-align: right;
        font-weight: 700 !important;

    }

    .btn-default {
        background-color: #f4f4f4;
        color: #444;
        border-color: #ddd;
    }

    .hide {
        display: none !important;
    }

    .show {
        box-shadow: none !important;
    }

    form.search-form {
        margin: 0 !important;
    }

    .header-items.navbar {
        padding-top: 0;
        padding-bottom: 0;
    }

    .custom-navbar .valign-header>*,
    .custom-navbar .navbar-brand,
    .custom-navbar .header-items>.nav {
        line-height: 0px !important;
    }

    html {
        font-size: 13px !important;
    }

    .btn-sm,
    .btn-group-sm>.btn {
        padding: 0.375rem 0.75rem;
        font-size: 1rem;
    }

    .custom-menu a:active,
    .custom-menu a:focus,
    .custom-menu li>ul>li.active a {
        color: #444;
    }
    main#main{
        height: 91vh;
    }
    span.long.user-image img {
        width: unset;
    }

    @media (min-width: 768px) {
        .col-md-6 {
            padding: 12px;
        }
    }
</style>