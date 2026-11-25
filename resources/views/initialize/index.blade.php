<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ Admin::title() }}</title>

    {{-- the same files as the login page (auth/layout.blade.php). AdminLTE 4 has Bootstrap 5 in it. --}}
    <!-- Font Awesome -->
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/font-awesome/css/all.min.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/font-awesome/css/v4-shims.min.css") }}">

    <!-- AdminLTE 4 (Bootstrap 5) -->
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/AdminLTE4/css/adminlte.min.css") }}">

    {!! Admin::css() !!}
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/open-admin/open-admin.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/nprogress/nprogress.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/sweetalert2/dist/sweetalert2.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/nestable/nestable.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/toastr/build/toastr.min.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/google-fonts/fonts.css") }}">

    <!-- REQUIRED JS SCRIPTS -->
    <script src="{{ admin_asset ("/vendor/open-admin/AdminLTE/plugins/jQuery/jQuery-3.7.1.min.js") }}"></script>
    <!-- Bootstrap 5 JS (No jQuery dependency) -->
    <script src="{{ admin_asset ("/vendor/open-admin/open-admin/js/bootstrap.bundle.min.js") }}" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="{{ admin_asset ("/vendor/open-admin/nprogress/nprogress.js") }}"></script>
</head>

<body class="hold-transition {{config('admin.skin')}} {{join(' ', config('admin.layout'))}}">
<div class="wrapper" id="app">
    <div class="container-fluid" id="pjax-container" style="width:80%; min-width:960px; margin:0 auto;">
        @yield('content')
        {!! Admin::script() !!}
    </div>
</div>

<!-- CSRF Token for Laravel -->
<script>
    function LA() {}
    LA.token = "{{ csrf_token() }}";
</script>

@if (session('status'))
<script>
    $(function () {
        toastr.success('{{ session("status") }}', null, []);
    });
</script>
@endif

<!-- Additional JS Scripts -->
<script src="{{ admin_asset ("/vendor/open-admin/nestable/jquery.nestable.js") }}"></script>
<script src="{{ admin_asset ("/vendor/open-admin/toastr/build/toastr.min.js") }}"></script>
<script src="{{ admin_asset ("/vendor/open-admin/sweetalert2/dist/sweetalert2.min.js") }}"></script>
{!! Admin::js() !!}

</body>
</html>