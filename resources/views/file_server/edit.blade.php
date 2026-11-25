{{-- File server: a text, Markdown or CSV file written in the browser (ISS00434). Data from FileServerController::editText. --}}
@include('exment::file_server._assets')
<style>
.exm-fs-editor { display: grid; grid-template-columns: 1fr; gap: 12px; position: relative; }
.exm-fs-editor.is-markdown { grid-template-columns: 1fr 1fr; }
@media (max-width: 900px) { .exm-fs-editor.is-markdown { grid-template-columns: 1fr; } }
.exm-fs-editor textarea { width: 100%; min-height: 60vh; font-family: Consolas, Menlo, monospace; font-size: 13px; }
.exm-fs-editor .CodeMirror { height: 60vh; border: 1px solid rgba(0,0,0,.15); font-size: 13px; }
.exm-fs-md { min-height: 60vh; max-height: 60vh; overflow: auto; padding: 8px 14px; border: 1px solid rgba(0,0,0,.12); border-radius: 4px; overflow-wrap: anywhere; }
.exm-fs-md table { border-collapse: collapse; } .exm-fs-md td, .exm-fs-md th { border: 1px solid rgba(0,0,0,.2); padding: 2px 6px; }
.exm-fs-md pre { white-space: pre-wrap; }
.exm-fs-conflict .btn { margin: 4px 6px 0 0; }
</style>
<div class="exm-fs" id="exm-fs-edit" data-url="{{ $node_url }}" data-version="{{ $version }}" data-max="{{ $max_bytes }}" data-preview-max="{{ $preview_bytes }}"
     data-new-url="{{ $new_file_url }}" data-parent="{{ $parent_id }}" data-name="{{ $node->name }}">
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
    @elseif ($needs_manager)
        <div class="alert alert-info" style="padding:8px 12px">{{ exmtrans('file_server.text_needs_manager') }}</div>
    @elseif (!$can_edit)
        <div class="alert alert-info" style="padding:8px 12px">{{ exmtrans('file_server.text_read_only') }}</div>
    @endif

    {{-- after someone else saved first: what to do with the text written here --}}
    <div class="alert alert-warning exm-fs-conflict" data-fs-edit="conflict" hidden>
        <div>{{ exmtrans('file_server.text_conflict') }}</div>
        <button type="button" class="btn btn-sm btn-default" data-fs-edit="copy"><i class="fa fa-clipboard" aria-hidden="true"></i> {{ exmtrans('file_server.text_copy') }}</button>
        <button type="button" class="btn btn-sm btn-default" data-fs-edit="save-as"><i class="fa fa-files-o" aria-hidden="true"></i> {{ exmtrans('file_server.text_save_as') }}</button>
        <button type="button" class="btn btn-sm btn-default" data-fs-edit="reload"><i class="fa fa-refresh" aria-hidden="true"></i> {{ exmtrans('file_server.text_reload') }}</button>
    </div>

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
        @include('exment::file_server._watermark')
        <div>
            {{-- the line break after the tag is dropped by the browser: one is put there, so that a text starting with one keeps it --}}
            <textarea class="form-control" data-fs-edit="text" aria-label="{{ $node->name }}" spellcheck="false" @if (!$can_edit) readonly @endif>
{{ $text }}</textarea>
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
    if (!root || root.dataset.started) { return; }
    root.dataset.started = '1';
    var Fs = window.ExmFs;
    var url = root.dataset.url;
    var version = root.dataset.version;
    var maxBytes = Number(root.dataset.max) || 0;
    var previewMax = Number(root.dataset.previewMax) || 0;
    var t = {!! json_encode([
        'saving' => exmtrans('file_server.text_saving'),
        'unsaved' => exmtrans('file_server.text_unsaved'),
        'version' => exmtrans('file_server.text_version', ['no' => ':no']),
        'too_large' => exmtrans('file_server.error_text_too_large', ['size' => round($max_bytes / 1048576, 1)]),
        'preview_too_large' => exmtrans('file_server.error_preview_too_large', ['size' => round($preview_bytes / 1024)]),
        'leave' => exmtrans('file_server.text_leave'),
        'copied' => exmtrans('file_server.text_copied'),
        'save_as_prompt' => exmtrans('file_server.prompt_file_name'),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!};
    var area = root.querySelector('[data-fs-edit="text"]');
    var preview = root.querySelector('[data-fs-edit="preview"]');
    var status = root.querySelector('[data-fs-edit="status"]');
    var conflict = root.querySelector('[data-fs-edit="conflict"]');
    var readOnly = area.hasAttribute('readonly');
    var editor = null;
    if (window.CodeMirror) {
        editor = CodeMirror.fromTextArea(area, { lineNumbers: true, lineWrapping: true, readOnly: readOnly, mode: null });
    }
    // the text with LF: the server gives it the line breaks of the file again
    function value() { return editor ? editor.getValue() : area.value; }
    var saved = value();
    var dirty = function () { return !readOnly && value() !== saved; };
    function alive() { return document.body.contains(root); }

    // the preview of Markdown: made by the server (the HTML in the text stays text, no picture of another site)
    var timer = null;
    var sent = 0;
    function renderPreview() {
        if (!preview) { return; }
        var text = value();
        if (previewMax > 0 && new Blob([text]).size > previewMax) { preview.textContent = t.preview_too_large; return; }
        var mine = ++sent;
        Fs.post(url + '/markdown', { text: text }).then(function (json) {
            if (mine !== sent) { return; }
            preview.innerHTML = json.html || '';
            preview.querySelectorAll('a[href]').forEach(function (a) { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
        }, function () {});
    }
    function changed() {
        if (status) { status.textContent = dirty() ? t.unsaved : t.version.replace(':no', status.dataset.no || ''); }
        if (preview) {
            clearTimeout(timer);
            // a long text: asked less often
            timer = setTimeout(renderPreview, value().length > 50000 ? 1500 : 500);
        }
    }
    if (editor) { editor.on('change', changed); } else { area.addEventListener('input', changed); }
    renderPreview();

    var saveButton = root.querySelector('[data-fs-edit="save"]');
    function postText(nodeUrl, text, base, comment) {
        // the text as a file part: its line breaks and its last line break are kept as they are
        var body = new FormData();
        body.append('text', new Blob([text], { type: 'text/plain' }), 'text.txt');
        body.append('base_version', base);
        body.append('comment', comment || '');
        return Fs.post(nodeUrl + '/text', body, true);
    }
    function save() {
        if (!saveButton || saveButton.disabled) { return; }
        var text = value();
        if (maxBytes > 0 && new Blob([text]).size > maxBytes) { Fs.notify(t.too_large, false); return; }
        var comment = root.querySelector('[data-fs-edit="comment"]');
        saveButton.disabled = true;
        status.textContent = t.saving;
        postText(url, text, version, comment ? comment.value : '').then(function (json) {
            version = String(json.version);
            saved = text;
            status.dataset.no = json.version_no;
            status.textContent = t.version.replace(':no', json.version_no);
            if (comment && json.changed) { comment.value = ''; }
            if (conflict) { conflict.hidden = true; }
            Fs.notify(json.message, true);
        }, function (err) {
            status.textContent = t.unsaved;
            Fs.fail(err);
            if (err.response && err.response._status === 409 && conflict) { conflict.hidden = false; }
        }).then(function () { saveButton.disabled = false; });
    }
    if (saveButton) { saveButton.addEventListener('click', save); }

    // after a conflict: keep the text written here
    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-fs-edit]');
        if (!btn) { return; }
        var action = btn.getAttribute('data-fs-edit');
        if (action === 'copy') {
            var text = value();
            var done = function () { Fs.notify(t.copied, true); };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done, function () { prompt('', text); });
            } else {
                area.value = text;
                if (editor) { editor.focus(); editor.execCommand('selectAll'); } else { area.select(); }
                try { document.execCommand('copy'); done(); } catch (ex) { prompt('', text); }
            }
        } else if (action === 'reload') {
            // the dialogs of the file server when there are (Fs.confirm, Fs.ask), else those of the browser
            var sure = !dirty() ? Promise.resolve(true) : (Fs.confirm ? Fs.confirm(t.leave) : Promise.resolve(confirm(t.leave)));
            sure.then(function (ok) {
                if (!ok) { return; }
                saved = value();
                location.reload();
            });
        } else if (action === 'save-as') {
            var name = root.dataset.name;
            var dot = name.lastIndexOf('.');
            var type = dot > 0 ? name.substring(dot + 1).toLowerCase() : 'txt';
            var suggested = (dot > 0 ? name.substring(0, dot) : name) + ' (2)';
            var text2 = value();
            (Fs.ask ? Fs.ask(t.save_as_prompt, suggested) : Promise.resolve(prompt(t.save_as_prompt, suggested))).then(function (newName) {
                if (!newName) { return; }
                return Fs.post(root.dataset.newUrl, { parent_id: root.dataset.parent || '', name: newName, type: type }).then(function (json) {
                    var nodeUrl = json.redirect.replace(/\/edit$/, '');
                    return postText(nodeUrl, text2, json.version, '').then(function () {
                        saved = value();
                        location.href = json.redirect;
                    });
                });
            }).catch(Fs.fail);
        }
    });

    // Ctrl+S / Cmd+S saves
    document.addEventListener('keydown', function (e) {
        // the page may have been left without a reload (pjax)
        if (!alive()) { return; }
        if ((e.ctrlKey || e.metaKey) && (e.key === 's' || e.key === 'S') && saveButton) { e.preventDefault(); save(); }
    });
    // leaving with text not saved: asked first, for a link in the site (pjax) as for a reload
    document.addEventListener('click', function (e) {
        if (!alive() || !dirty() || e.defaultPrevented) { return; }
        var a = e.target.closest('a[href]');
        if (!a || a.target === '_blank' || (preview && preview.contains(a))) { return; }
        var href = a.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#' || /^javascript:/i.test(href)) { return; }
        if (!confirm(t.leave)) { e.preventDefault(); e.stopImmediatePropagation(); }
        else { saved = value(); }
    }, true);
    window.addEventListener('beforeunload', function (e) {
        if (alive() && dirty()) { e.preventDefault(); e.returnValue = ''; }
    });
}
if (window.CodeMirror || document.readyState === 'complete') { start(); } else { window.addEventListener('load', start); }
})();
</script>
