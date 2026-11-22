{{-- The versions of the system setting (ISS02828): SystemInfo::items, a copy button, the latest version and a link to tuning --}}
<div class="exm-version-info">
    <table class="table table-condensed" style="max-width: 640px;">
        <tbody>
            @foreach($items as $label => $value)
            <tr>
                <th style="width: 30%;">{{ $label }}</th>
                <td data-version-info="{{ $label }}">{{ $value }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
    <textarea id="exm-version-info-text" readonly hidden>{{ $text }}</textarea>
    <p>
        <button type="button" class="btn btn-sm btn-default" id="exm-version-info-copy"><i class="fa fa-clipboard" aria-hidden="true"></i> {{ exmtrans('system.version_info.copy') }}</button>
    </p>

    @if(!is_null($latestForm))
    {!! $latestForm !!}
    @endif

    @if(!is_null($tuningUrl))
    <p>{{ exmtrans('system.version_info.tuning') }} <a href="{{ $tuningUrl }}">{{ exmtrans('tuning.header') }}</a></p>
    @endif
</div>
<script>
(function () {
    var button = document.getElementById('exm-version-info-copy');
    if (!button) {
        return;
    }
    button.addEventListener('click', function () {
        var area = document.getElementById('exm-version-info-text');
        var text = area.value;
        var done = function () {
            if (window.toastr) {
                toastr.success(@json(exmtrans('system.version_info.copied')));
            }
        };
        if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { window.prompt('', text); });
            return;
        }
        // no clipboard API (http other than localhost): copy from the text area
        area.hidden = false;
        area.select();
        var copied = false;
        try { copied = document.execCommand('copy'); } catch (e) { copied = false; }
        area.hidden = true;
        copied ? done() : window.prompt('', text);
    });
})();
</script>
