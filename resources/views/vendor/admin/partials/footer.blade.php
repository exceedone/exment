<!-- Main Footer -->
<footer class="main-footer  d-flex justify-content-between align-items-center">
    

    <!-- To the left -->
    <div class="d-flex justify-content-start align-items-center">
        <strong>Powered by <a href="https://github.com/exceedone/exment" target="_blank">Exment</a></strong> (Interface <a href="https://github.com/z-song/laravel-admin" target="_blank">laravel-admin</a>)
        <button href="#" style="display: none" role="scanButtonDashboard" data-qr_action="url"></button>
    </div>
    <!-- To the right -->
    <div class="d-flex align-items-center d-none d-lg-block">
        @if(config('admin.show_version'))
        <strong>Version</strong>&nbsp;&nbsp;<span id="version">{!! \Exment::version() !!}</span>
        @endif
    </div>
</footer>


<script>
    var backButton = "{{ exmtrans('common.back_button') }}";
</script>