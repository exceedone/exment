<!-- Main Footer -->
<footer class="main-footer clearfix">
    <!-- To the right -->
    <div class="pull-right hidden-xs">

        @if(config('admin.show_version'))
        <strong>Version</strong>&nbsp;&nbsp; <span id="version">{!! \Exment::version() !!}</span>
        @endif
    </div>
    <!-- Default to the left -->
    <strong>Powered by <a href="https://github.com/exceedone/exment" target="_blank">Exment</a></strong> (Interface <a href="https://github.com/z-song/laravel-admin" target="_blank">laravel-admin</a>)
    <a href="#" role="readQRDashboard" data-qr_action="url"></a>
</footer>