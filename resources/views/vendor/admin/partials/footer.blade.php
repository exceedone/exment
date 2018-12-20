<!-- Main Footer -->
<footer class="main-footer">
    <!-- To the right -->
    <div class="pull-right hidden-xs">

        @if(config('admin.show_version'))
        <strong>Version</strong>&nbsp;&nbsp; <span id="version">{!! \Exment::version(false) !!}</span>
        @endif
    </div>
</footer>