<!DOCTYPE html>
<html lang="{{ config('app.locale') }}">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ Admin::title() }}</title>
    <!-- Tell the browser to be responsive to screen width -->
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/AdminLTE/bootstrap/css/bootstrap.min.css") }}">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/font-awesome/css/font-awesome.min.css") }}">

    <!-- Theme style -->
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/AdminLTE/dist/css/skins/" . config('admin.skin') .".min.css") }}">

    {!! Admin::css() !!}
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/open-admin/open-admin.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/nprogress/nprogress.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/sweetalert2/dist/sweetalert2.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/nestable/nestable.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/toastr/build/toastr.min.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/bootstrap3-editable/css/bootstrap-editable.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/google-fonts/fonts.css") }}">
    <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/AdminLTE/dist/css/AdminLTE.min.css") }}">

    <!-- REQUIRED JS SCRIPTS -->
    <script src="{{ admin_asset ("/vendor/open-admin/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js") }}"></script>
    <script src="{{ admin_asset ("/vendor/open-admin/AdminLTE/bootstrap/js/bootstrap.min.js") }}"></script>
    <script src="{{ admin_asset ("/vendor/open-admin/AdminLTE/plugins/slimScroll/jquery.slimscroll.min.js") }}"></script>
    <script src="{{ admin_asset ("/vendor/open-admin/AdminLTE/dist/js/app.min.js") }}"></script>
    <script src="{{ admin_asset ("/vendor/open-admin/jquery-pjax/jquery.pjax.js") }}"></script>
    <script src="{{ admin_asset ("/vendor/open-admin/nprogress/nprogress.js") }}"></script>

    <!--[if lt IE 9]>
    <script src="https://oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js"></script>
    <script src="https://oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
    <![endif]-->

</head>

<body class="hold-transition {{config('admin.skin')}} {{join(' ', config('admin.layout'))}}">
<div class="wrapper">

    <div class="" id="pjax-container" style="width:80%; min-width:960px; margin:0 auto;">
        @yield('content')
        {!! Admin::script() !!}
    </div>

</div>

<!-- ./wrapper -->

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

<!-- REQUIRED JS SCRIPTS -->
<script src="{{ admin_asset ("/vendor/open-admin/nestable/jquery.nestable.js") }}"></script>
<script src="{{ admin_asset ("/vendor/open-admin/toastr/build/toastr.min.js") }}"></script>
<script src="{{ admin_asset ("/vendor/open-admin/bootstrap3-editable/js/bootstrap-editable.min.js") }}"></script>
<script src="{{ admin_asset ("/vendor/open-admin/sweetalert2/dist/sweetalert2.min.js") }}"></script>
{!! Admin::js() !!}
<script src="{{ admin_asset ("/vendor/open-admin/open-admin/open-admin.js") }}"></script>

</body>
</html>
