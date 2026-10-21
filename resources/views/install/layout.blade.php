<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <title>{{ config('admin.title') }} | {{ exmtrans('install.title') }}</title>
  <!-- Tell the browser to be responsive to screen width -->
  <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">

 <!-- Bootstrap 5 CSS (served locally so the installer also works without internet access) -->
 <link rel="stylesheet" href="{{ admin_asset('/vendor/open-admin/AdminLTE4/css/bootstrap.min.css') }}">
 <!-- Font Awesome 6 -->
 <link rel="stylesheet" href="{{ admin_asset('/vendor/open-admin/font-awesome/css/all.min.css') }}">
 <link rel="stylesheet" href="{{ admin_asset('/vendor/open-admin/font-awesome/css/v4-shims.min.css') }}">
 <!-- toastr (unchanged) -->
 <link rel="stylesheet" href="{{ admin_asset('/vendor/open-admin/toastr/build/toastr.min.css') }}">
 <!-- iCheck (kept for now, but consider replacing with Bootstrap 5 native form controls) -->
 <link rel="stylesheet" href="{{ admin_asset('/vendor/open-admin/AdminLTE/plugins/iCheck/square/blue.css') }}">
 <link rel="stylesheet" href="{{ asset('vendor/exment/css/install.css')}}">
 
  <!-- custom css -->
  <link rel="stylesheet" href="{{ admin_asset('vendor/open-admin/open-admin/css/custom.css') }}">
  
</head>
<body class="d-flex justify-content-center bg-light" style="padding-top: 130px;">
  <div class="d-flex flex-column justify-content-center">
    <div class="login-logo mb-3 text-center">
      <img src="{{ asset('images/exment_logo_side.png') }}" />
    </div>
    <div class="card shadow rounded p-4 {{ $login_box_classname ?? '' }}">
      @yield('content')
    </div>

  </div>
</div>
  <!-- jQuery (kept for iCheck and toastr) -->
  <script src="{{ admin_asset('/vendor/open-admin/AdminLTE/plugins/jQuery/jQuery-3.7.1.min.js') }}"></script>
  <!-- Bootstrap 5 JS -->
  <script src="{{ admin_asset('/vendor/open-admin/open-admin/js/bootstrap.bundle.min.js') }}"></script>
  <!-- iCheck (kept for now, but consider replacing with native Bootstrap 5 form controls) -->
  <script src="{{ admin_asset('/vendor/open-admin/AdminLTE/plugins/iCheck/icheck.min.js') }}"></script>
  <!-- toastr -->
  <script src="{{ admin_asset('/vendor/open-admin/toastr/build/toastr.min.js') }}"></script>
  <!-- Exment Common JS -->
  <script src="{{ admin_asset('/vendor/exment/js/common_all.js') }}"></script>

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

        // Disable the submit button
        $(':submit').attr("disabled", true);
        $('.click_disabled').css("pointer-events", 'none').css("tabindex", '-1').attr("disabled", true);
        return true;
      });
    });
  </script>
</body>
</html>