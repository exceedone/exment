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
  <link rel="stylesheet" href="{{ admin_asset("vendor/open-admin/bootstrap5-editable/css/bootstrap-editable.css") }}">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/font-awesome/css/all.min.css") }}">
  <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/font-awesome/css/v4-shims.min.css") }}">
  <!-- Theme style -->
  <link rel="stylesheet" href="{{ admin_asset("vendor/open-admin/AdminLTE4/css/adminlte.min.css") }}">

  <!-- iCheck -->
  <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/toastr/build/toastr.min.css") }}">
  <link rel="stylesheet" href="{{ admin_asset("/vendor/open-admin/open-admin/css/button-custom.css") }}">
  {{-- The "modern" screen design (system setting, or EXMENT_UI_DESIGN). It stays before the inline style
       below, so a color or an image set on the login setting page still wins. The one exception
       is the untouched default gray: that gives way to the skin's own page color.
       The site skin goes on <body> as data-exm-skin, so the login button and the links take the
       skin's accent color. It is not a class: a skin class would switch on the admin page rules. --}}
  @php
    $modern_ui = \Exment::isModernUi();
    $modern_ui_skin = $modern_ui && in_array(config('admin.skin'), \Exceedone\Exment\Model\Define::SYSTEM_SKIN, true)
        ? config('admin.skin') : null;
    $modern_ui_default_bg = $modern_ui && isset($background_color)
        && strtolower($background_color) === \Exceedone\Exment\Model\Define::SYSTEM_SETTING_NAME_VALUE['login_background_color']['default'];
  @endphp
  @if($modern_ui)
  <link rel="stylesheet" href="{{ admin_asset("vendor/exment/css/modern.css") }}">
  @endif
  <style>
  .login-page{
    @if(isset($background_color) && !$modern_ui_default_bg)
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

.btn[disabled], fieldset[disabled] .btn {
    cursor: not-allowed !important;
    filter: alpha(opacity=65) !important;
    -webkit-box-shadow: none !important;
    box-shadow: none !important;
    opacity: .65 !important;
}

  .form-check {
    display: flex;
    align-items: center;
    justify-content: center; 
    gap: 0.5em; 
  }

  .form-check-input {
      width: 1.2em;
      height: 1.2em;
      margin: 0; 
      border: 1px solid #000;
      flex-shrink: 0; 
  }
  .form-check-input {
      position: static !important;
  }
  .login-logo a b {
      font-weight: 700;
  }
  .has-error .control-label,
  .has-error label {
      color: #dd4b39 !important;
  }
  </style>
</head>
<body class="hold-transition login-page"@if($modern_ui_skin) data-exm-skin="{{ $modern_ui_skin }}"@endif>
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
          <div class="card p-3 login-box-body">
              @yield('content')
          </div>
          <!-- /.login-box-body -->
      </div>
      <!-- /.login-box -->
<!-- jQuery 2.1.4 -->
<!-- jQuery 3.7.1 -->
<script src="{{ admin_asset ("/vendor/open-admin/AdminLTE/plugins/jQuery/jQuery-3.7.1.min.js") }}"></script>
<!-- Bootstrap 3.3.5 -->
<!-- jQuery 2.1.4 -->
<script src="{{ admin_asset("/vendor/open-admin/AdminLTE4/js/bootstrap.bundle.min.js")}} "></script>
<!-- Bootstrap 3.3.5 -->
<script src="{{ admin_asset("/vendor/open-admin/AdminLTE4/js/adminlte.min.js")}}"></script>
<!-- iCheck -->

<script src="{{ admin_asset("/vendor/open-admin/toastr/build/toastr.min.js")}}"></script>
<script src="{{ admin_asset("/vendor/exment/js/common_all.js")}}"></script>
@if (session('exment_logged_out'))
<script>
    // after the logout: what this browser kept for the user (the navbar, the Studio app)
    (function () {
        try {
            for (var i = localStorage.length - 1; i >= 0; i--) {
                var key = localStorage.key(i);
                if (key && key.indexOf('exment.navbar.') === 0) { localStorage.removeItem(key); }
            }
        } catch (e) {}
        try { if (window.indexedDB) { indexedDB.deleteDatabase('exment-app'); } } catch (e) {}
        try {
            if (window.caches) {
                caches.keys().then(function (keys) {
                    keys.forEach(function (k) { if (k.indexOf('exment-app') === 0) { caches.delete(k); } });
                }).catch(function () {});
            }
        } catch (e) {}
    })();
</script>
@endif

<script>
  // $(function () {
  //   $('input').iCheck({
  //     checkboxClass: 'icheckbox_square-blue',
  //     radioClass: 'iradio_square-blue',
  //     increaseArea: '20%' // optional
  //   });
  // });
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
