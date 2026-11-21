{{-- File server: a text, Markdown or CSV file written in the browser (ISS00434). Data from FileServerController::editText. --}}
@include('exment::file_server._assets')
<style>
.exm-fs-editor { display: grid; grid-template-columns: 1fr; gap: 12px; }
.exm-fs-editor.is-markdown { grid-template-columns: 1fr 1fr; }
@media (max-width: 900px) { .exm-fs-editor.is-markdown { grid-template-columns: 1fr; } }
.exm-fs-editor textarea { width: 100%; min-height: 60vh; font-family: Consolas, Menlo, monospace; font-size: 13px; }
.exm-fs-editor .CodeMirror { height: 60vh; border: 1px solid rgba(0,0,0,.15); font-size: 13px; }
.exm-fs-md { min-height: 60vh; max-height: 60vh; overflow: auto; padding: 8px 14px; border: 1px solid rgba(0,0,0,.12); border-radius: 4px; overflow-wrap: anywhere; }
.exm-fs-md table { border-collapse: collapse; } .exm-fs-md td, .exm-fs-md th { border: 1px solid rgba(0,0,0,.2); padding: 2px 6px; }
.exm-fs-md pre { white-space: pre-wrap; }
</style>
<div class="exm-fs" id="exm-fs-edit" data-url="{{ $node_url }}" data-version="{{ $version }}" data-max="{{ $max_bytes }}">
    <div class="exm-fs-breadcrumb">
        <a href="{{ admin_url('file_server') }}"><i class="fa fa-home" aria-hidden="true"></i> {{ exmtrans('file_server.header') }}</a>
        @foreach ($breadcrumbs as $i => $crumb)
            @if ($i === count($breadcrumbs) - 1)
                <span>{{ $crumb['name'] }}</span>
            @else
                <a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>
            @endif
        @endforeach
    </div>

    @if ($checked_out_by !== null)
        <div class="alert alert-warning" style="padding:8px 12px"><i class="fa fa-lock" aria-hidden="true"></i>
            {{ exmtrans('file_server.text_locked', ['user' => $checked_out_by]) }}</div>
    @elseif (!$can_edit)
        <div class="alert alert-info" style="padding:8px 12px">{{ exmtrans('file_server.text_read_only') }}</div>
    @endif

    <div class="exm-fs-toolbar">
        @if ($can_edit)
            <button type="button" class="btn btn-sm btn-primary" data-fs-edit="save"><i class="fa fa-save" aria-hidden="true"></i> {{ exmtrans('file_server.text_save') }}</button>
            <input type="text" class="form-control input-sm" style="max-width:320px" data-fs-edit="comment" maxlength="1000" placeholder="{{ exmtrans('file_server.version_comment') }}" aria-label="{{ exmtrans('file_server.version_comment') }}">
        @endif
        <a class="btn btn-sm btn-default" href="{{ $node_url }}"><i class="fa fa-file-o" aria-hidden="true"></i> {{ exmtrans('file_server.text_back') }}</a>
        <span class="exm-fs-spacer"></span>
        <span class="exm-fs-muted" data-fs-edit="status" data-no="{{ $version_no }}">{{ exmtrans('file_server.text_version', ['no' => $version_no]) }}</span>
    </div>

    <div class="exm-fs-editor {{ $markdown ? 'is-markdown' : '' }}">
        <div>
            <textarea class="form-control" data-fs-edit="text" aria-label="{{ $node->name }}" spellcheck="false" @if (!$can_edit) readonly @endif>{{ $text }}</textarea>
        </div>
        @if ($markdown)
            <div>
                <div class="exm-fs-muted" style="margin-bottom:4px">{{ exmtrans('file_server.text_preview') }}</div>
                <div class="exm-fs-md" data-fs-edit="preview" aria-live="polite"></div>
            </div>
        @endif
    </div>
</div>
<script>
(function () {
// CodeMirror comes with the scripts at the end of the page: on a full load, start after them
function start() {
    var root = document.getElementById('exm-fs-edit');
    if (!root) { return; }
    var Fs = window.ExmFs;
    var url = root.dataset.url;
    var version = root.dataset.version;
    var maxBytes = Number(root.dataset.max) || 0;
    var t = {!! json_encode([
        'saving' => exmtrans('file_server.text_saving'),
        'unsaved' => exmtrans('file_server.text_unsaved'),
        'version' => exmtrans('file_server.text_version', ['no' => ':no']),
        'too_large' => exmtrans('file_server.error_text_too_large', ['size' => round($max_bytes / 1048576, 1)]),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!};
    var area = root.querySelector('[data-fs-edit="text"]');
    var preview = root.querySelector('[data-fs-edit="preview"]');
    var status = root.querySelector('[data-fs-edit="status"]');
    var readOnly = area.hasAttribute('readonly');
    var editor = null;
    if (window.CodeMirror) {
        editor = CodeMirror.fromTextArea(area, { lineNumbers: true, lineWrapping: true, readOnly: readOnly, mode: null });
    }
    function value() { return editor ? editor.getValue() : area.value; }
    var saved = value();
    var dirty = function () { return value() !== saved; };

    // the preview of Markdown: made by the server (the HTML in the text stays text)
    var timer = null;
    var sent = 0;
    function renderPreview() {
        if (!preview) { return; }
        var mine = ++sent;
        Fs.post(url + '/markdown', { text: value() }).then(function (json) {
            if (mine === sent) { preview.innerHTML = json.html || ''; }
        }, function () {});
    }
    function changed() {
        if (status) { status.textContent = dirty() ? t.unsaved : t.version.replace(':no', status.dataset.no || ''); }
        if (preview) { clearTimeout(timer); timer = setTimeout(renderPreview, 400); }
    }
    if (editor) { editor.on('change', changed); } else { area.addEventListener('input', changed); }
    renderPreview();

    var saveButton = root.querySelector('[data-fs-edit="save"]');
    function save() {
        if (!saveButton || saveButton.disabled) { return; }
        var text = value();
        if (maxBytes > 0 && new Blob([text]).size > maxBytes) { Fs.notify(t.too_large, false); return; }
        var comment = root.querySelector('[data-fs-edit="comment"]');
        saveButton.disabled = true;
        status.textContent = t.saving;
        // the text as a file part: its line breaks and its last line break are kept as they are
        var body = new FormData();
        body.append('text', new Blob([text], { type: 'text/plain' }), 'text.txt');
        body.append('base_version', version);
        body.append('comment', comment ? comment.value : '');
        Fs.post(url + '/text', body, true).then(function (json) {
            version = String(json.version);
            saved = text;
            status.dataset.no = json.version_no;
            status.textContent = t.version.replace(':no', json.version_no);
            if (comment) { comment.value = ''; }
            Fs.notify(json.message, true);
        }, function (err) {
            status.textContent = t.unsaved;
            Fs.fail(err);
        }).then(function () { saveButton.disabled = false; });
    }
    if (saveButton) { saveButton.addEventListener('click', save); }
    // Ctrl+S / Cmd+S saves
    document.addEventListener('keydown', function (e) {
        // the page may have been left without a reload (pjax)
        if (!document.body.contains(root)) { return; }
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S') && saveButton) { e.preventDefault(); save(); }
    });
    window.addEventListener('beforeunload', function (e) {
        if (document.body.contains(root) && !readOnly && dirty()) { e.preventDefault(); e.returnValue = ''; }
    });
}
if (window.CodeMirror || document.readyState === 'complete') { start(); } else { window.addEventListener('load', start); }
})();
</script>
