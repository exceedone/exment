<!-- Main Footer -->
<footer class="d-flex justify-content-between align-items-center bg-white py-3 px-4 fixed-bottom" style="margin-left: 280px;">
    

    <!-- To the left -->
    <div class="d-flex justify-content-start align-items-center">
        <strong>Powered by <a href="https://github.com/exceedone/exment" target="_blank">Exment</a></strong>
        &nbsp;(Interface <a href="https://github.com/z-song/laravel-admin" target="_blank">laravel-admin</a>)
        <a href="#" role="readQRDashboard" data-qr_action="url"></a>
    </div>
    <!-- To the right -->
    <div class="d-flex align-items-center">
        @if(config('admin.show_version'))
        <strong>Version</strong>&nbsp;&nbsp;<span id="version">{!! \Exment::version() !!}</span>
        @endif
    </div>
</footer>
