<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>{{config('admin.title')}} | {{ trans('admin.login') }}</title>
  <!-- Tell the browser to be responsive to screen width -->
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

  @if(!is_null($favicon = Admin::favicon()))
    <link rel="shortcut icon" href="{{$favicon}}">
  @endif

  <!-- Bootstrap 3.3.5 -->
  <link rel="stylesheet" href="{{ admin_asset("/vendor/laravel-admin/AdminLTE/bootstrap/css/bootstrap.min.css") }}">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="{{ admin_asset("/vendor/laravel-admin/font-awesome/css/all.min.css") }}">
  <link rel="stylesheet" href="{{ admin_asset("/vendor/laravel-admin/font-awesome/css/v4-shims.min.css") }}">
  <!-- Theme style -->
  <link rel="stylesheet" href="{{ admin_asset("/vendor/laravel-admin/AdminLTE/dist/css/AdminLTE.min.css") }}">
  <!-- iCheck -->
  <link rel="stylesheet" href="{{ admin_asset("/vendor/laravel-admin/AdminLTE/plugins/iCheck/square/blue.css") }}">
  <link rel="stylesheet" href="{{ admin_asset("/vendor/laravel-admin/toastr/build/toastr.min.css") }}">
  <!-- HTML5 Shim and Respond.js IE8 support of HTML5 elements and media queries -->
  <!-- WARNING: Respond.js doesn't work if you view the page via file:// -->
  <!--[if lt IE 9]>
  <script src="//oss.maxcdn.com/html5shiv/3.7.3/html5shiv.min.js"></script>
  <script src="//oss.maxcdn.com/respond/1.4.2/respond.min.js"></script>
  <![endif]-->
  <style>
  .login-page{
    @if(isset($background_color))
      background-color:{{ $background_color }};
    @endif
    @if(isset($background_image))
      background-image: url("{{ $background_image }}");
    @endif

    @if(isset($background_image_type) && $background_image_type == 'repeat')
      background-repeat: repeat;
    @elseif(isset($background_image_type) && $background_image_type == 'cover')
      background-size: cover;
      background-position: center;
    @endif
  }
  </style>
</head>
<body class="hold-transition login-page">
      <div class="login-box">
          <div class="login-logo">
              <a href="{{ admin_url('/') }}">
                @if(isset($header_image))
                <img src="{{$header_image}}" style="width:100%; max-height:100px;" />
                @else
                <b>{{$site_name ?? \Exceedone\Exment\Model\System::site_name()}}</b>
                @endif
              </a>
          </div>
          <!-- /.login-logo -->
          <div class="login-box-body">
              @yield('content')
          </div>
          <!-- /.login-box-body -->
      </div>
      <!-- /.login-box -->
<!-- jQuery 2.1.4 -->
<script src="{{ admin_asset("/vendor/laravel-admin/AdminLTE/plugins/jQuery/jQuery-2.1.4.min.js")}} "></script>
<!-- Bootstrap 3.3.5 -->
<script src="{{ admin_asset("/vendor/laravel-admin/AdminLTE/bootstrap/js/bootstrap.min.js")}}"></script>
<!-- iCheck -->
<script src="{{ admin_asset("/vendor/laravel-admin/AdminLTE/plugins/iCheck/icheck.min.js")}}"></script>

<script src="{{ admin_asset("/vendor/laravel-admin/toastr/build/toastr.min.js")}}"></script>
<script src="{{ admin_asset("/vendor/exment/js/common_all.js")}}"></script>

<script>
  $(function () {
    $('input').iCheck({
      checkboxClass: 'icheckbox_square-blue',
      radioClass: 'iradio_square-blue',
      increaseArea: '20%' // optional
    });
  });
</script>

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

</body>
</html>
