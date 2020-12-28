<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>{{config('admin.title')}} | {{ exmtrans('install.title') }}</title>
  <!-- Tell the browser to be responsive to screen width -->
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

  <!-- Bootstrap 3.3.5 -->
  <link rel="stylesheet" href="{{ admin_asset("/vendor/laravel-admin/AdminLTE/bootstrap/css/bootstrap.min.css") }}">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="{{ admin_asset("/vendor/laravel-admin/font-awesome/css/font-awesome.min.css") }}">
  <!-- Theme style -->
  <link rel="stylesheet" href="{{ admin_asset("/vendor/laravel-admin/AdminLTE/dist/css/AdminLTE.min.css") }}">
  <!-- iCheck -->
  <link rel="stylesheet" href="{{ admin_asset("/vendor/laravel-admin/AdminLTE/plugins/iCheck/square/blue.css") }}">
  <link rel="stylesheet" href="{{ admin_asset("/vendor/laravel-admin/toastr/build/toastr.min.css") }}">
  <link rel="stylesheet" href="{{ asset('vendor/exment/css/install.css')}}">
  <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
  <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
  <!--[if lt IE 9]>
  <script src="//oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js"></script>
  <script src="//oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
  <![endif]-->
</head>
<body class="hold-transition login-page">
    <div class="login-box {{$login_box_classname ?? ''}}">
            <div class="login-logo">
                <img src="{{ asset('images/exment_logo_side.png') }}" />
            </div>
            <!-- /.login-logo -->
            <div class="login-box-body">
              @yield('content')
            </div>
  <!-- /.login-box-body -->
</div>
<!-- jQuery 2.1.4 -->
<script src="{{ admin_asset("/vendor/laravel-admin/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js")}} "></script>
<!-- Bootstrap 3.3.5 -->
<script src="{{ admin_asset("/vendor/laravel-admin/AdminLTE/bootstrap/js/bootstrap.min.js")}}"></script>
<!-- iCheck -->
<script src="{{ admin_asset("/vendor/laravel-admin/AdminLTE/plugins/iCheck/icheck.min.js")}}"></script>

<script src="{{ admin_asset("/vendor/laravel-admin/toastr/build/toastr.min.js")}}"></script>
<script src="{{ admin_asset("/vendor/exment/js/common_all.js")}}"></script>

@if (session('status'))
<script>
    $(function () {
      toastr.success('{{ session("status") }}', null, []);
  });
</script>

@elseif(session('status_error'))
<script>
    $(function () {
      toastr.error('{{ session("status_error") }}', null, []);
  });
</script>
@endif

@yield('script')
<script>
  $(function () {
    $('input').iCheck({
      checkboxClass: 'icheckbox_square-blue',
      radioClass: 'iradio_square-blue',
      increaseArea: '20%' // optional
    });

    $("form").submit(function (ev) {
      if($(ev.target).hasClass('check_has_warning') && pBool($('#has_warning').val())){
        if(!window.confirm($('#has_warning_text').val())){
            return false;
        }
      }

      //disable the submit button
      $(':submit').attr("disabled", true);
      $('.click_disabled').css("pointer-events", 'none').css("tabindex", '-1').attr("disabled", true);
      return true;
    });
  });
</script>
</body>
</html>
