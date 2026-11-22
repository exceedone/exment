{{-- File server: the PDF of an Office file is made when the preview is first opened (ISS01704). Asks until it is there. --}}
<div class="exm-fs-preview-wait" style="width:100%;padding:12px" data-fs-preview-wait="{{ $node_url }}/rendition" data-title="{{ $node->name }}">
    <p class="exm-fs-muted"><i class="fa fa-spinner fa-spin" aria-hidden="true"></i> <span data-fs-wait-text>{{ exmtrans('file_server.preview_converting') }}</span></p>
</div>
<script>
(function () {
    function start() {
        var box = document.querySelector('[data-fs-preview-wait]');
        if (!box || box.dataset.started || !window.ExmFs) { return; }
        box.dataset.started = '1';
        var failed = @json(exmtrans('file_server.preview_convert_failed'));
        var tries = 0;
        function ask() {
            // the page was left (pjax): stop asking
            if (!document.body.contains(box)) { return; }
            tries++;
            window.ExmFs.post(box.dataset.fsPreviewWait, {}).then(function (json) {
                if (json.status === 'done' && json.pdf_url) {
                    var frame = document.createElement('iframe');
                    frame.src = json.pdf_url;
                    frame.title = box.dataset.title || '';
                    box.replaceWith(frame);
                } else if (json.status === 'converting' && tries < 200) {
                    setTimeout(ask, 3000);
                } else {
                    box.querySelector('[data-fs-wait-text]').textContent = failed;
                    box.querySelector('.fa-spinner').remove();
                }
            }, function () {
                if (tries < 200) { setTimeout(ask, 5000); }
            });
        }
        ask();
    }
    if (window.ExmFs) { start(); } else { window.addEventListener('load', start); }
})();
</script>
