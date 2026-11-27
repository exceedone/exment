{{-- The URL and the code to put a web form on a web site (ISS02942), with copy buttons and the form itself --}}
<div class="public-form-embed">
    @if(!$active)
    <div class="alert alert-warning">{{ exmtrans('custom_form_public.easy.not_active') }}</div>
    @endif
    @if($no_notice)
    <div class="alert alert-warning">{{ exmtrans('custom_form_public.easy.warning_no_notice') }}</div>
    @endif
    @if(!$secure)
    <div class="alert alert-info">{{ exmtrans('custom_form_public.easy.not_https') }}</div>
    @endif

    <div class="row">
        <div class="col-md-6">
            @foreach(['url', 'script'] as $kind)
            <div class="form-group" style="margin-bottom:16px">
                <label class="fw-bold">{{ exmtrans('custom_form_public.easy.tag_' . $kind) }}</label>
                <p class="text-muted small" style="margin-bottom:4px">{{ exmtrans('custom_form_public.easy.tag_' . $kind . '_help') }}</p>
                <div class="input-group">
                    <textarea class="form-control public-form-embed-tag" rows="{{ $kind === 'url' ? 1 : 2 }}" readonly data-kind="{{ $kind }}" style="font-family:monospace">{{ $tags[$kind] }}</textarea>
                    <button type="button" class="btn btn-primary public-form-embed-copy" data-kind="{{ $kind }}" data-done="{{ exmtrans('custom_form_public.easy.copied') }}">
                        <i class="fa fa-clipboard"></i> {{ exmtrans('custom_form_public.easy.copy') }}
                    </button>
                </div>
            </div>
            @endforeach
            <details style="margin-bottom:16px">
                <summary style="cursor:pointer">{{ exmtrans('custom_form_public.easy.tag_iframe') }}</summary>
                <p class="text-muted small" style="margin:4px 0">{{ exmtrans('custom_form_public.easy.tag_iframe_help') }}</p>
                <div class="input-group">
                    <textarea class="form-control public-form-embed-tag" rows="2" readonly data-kind="iframe" style="font-family:monospace">{{ $tags['iframe'] }}</textarea>
                    <button type="button" class="btn btn-default public-form-embed-copy" data-kind="iframe" data-done="{{ exmtrans('custom_form_public.easy.copied') }}">
                        <i class="fa fa-clipboard"></i> {{ exmtrans('custom_form_public.easy.copy') }}
                    </button>
                </div>
            </details>
            <div>
                <a href="{{ $tags['url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-default"><i class="fa fa-external-link"></i> {{ exmtrans('custom_form_public.easy.open') }}</a>
                <a href="{{ $edit_url }}" class="btn btn-default"><i class="fa fa-cog"></i> {{ exmtrans('custom_form_public.easy.detail') }}</a>
            </div>
        </div>
        <div class="col-md-6">
            <label class="fw-bold">{{ exmtrans('custom_form_public.easy.preview') }}</label>
            <p class="text-muted small" style="margin-bottom:4px">{{ exmtrans('custom_form_public.easy.preview_help') }}</p>
            @if($active)
            <div style="border:1px dashed #bbb;padding:8px;background:#fafafa" class="public-form-embed-preview">
                {!! $tags['iframe'] !!}
            </div>
            @else
            <p class="text-muted">{{ exmtrans('custom_form_public.easy.not_active') }}</p>
            @endif
        </div>
    </div>
</div>
<script>
(function () {
    // the preview takes the height of the form, as embed.js does on a web site
    var frame = document.querySelector('.public-form-embed-preview iframe');
    if (frame) {
        window.addEventListener('message', function (e) {
            if (e.origin !== window.location.origin || !e.data || !e.data.exmentPublicForm) { return; }
            var h = parseInt(e.data.height, 10);
            if (h > 0) { frame.style.height = (h + 16) + 'px'; frame.style.minHeight = '0'; }
        });
    }
    document.querySelectorAll('.public-form-embed-copy').forEach(function (button) {
        button.addEventListener('click', function () {
            var area = document.querySelector('.public-form-embed-tag[data-kind="' + button.dataset.kind + '"]');
            var done = function () {
                var was = button.innerHTML;
                button.textContent = button.dataset.done;
                setTimeout(function () { button.innerHTML = was; }, 1500);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(area.value).then(done);
            } else {
                area.select();
                document.execCommand('copy');
                done();
            }
        });
    });
})();
</script>
