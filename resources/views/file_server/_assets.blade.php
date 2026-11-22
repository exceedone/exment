{{-- File server: the styles and the small helpers shared by its pages. --}}
<style>
.exm-fs [hidden] { display: none !important; }
.exm-fs-toolbar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-bottom: 10px; }
.exm-fs-toolbar .exm-fs-spacer { flex: 1; }
.exm-fs-breadcrumb { margin: 0 0 10px; padding: 6px 10px; background: rgba(0,0,0,.03); border-radius: 4px; overflow-wrap: anywhere; }
.exm-fs-breadcrumb a + a::before, .exm-fs-breadcrumb a + span::before { content: '/'; margin: 0 6px; opacity: .5; }
.exm-fs-table { margin: 0; }
.exm-fs-table td, .exm-fs-table th { vertical-align: middle !important; }
.exm-fs-table .exm-fs-check { width: 1%; }
.exm-fs-name { overflow-wrap: anywhere; }
.exm-fs-name .fa { width: 18px; text-align: center; margin-right: 4px; }
.exm-fs-name .fa-folder { color: #e8a33d; }
.exm-fs-thumb { width: 40px; height: 40px; object-fit: cover; border-radius: 3px; margin-right: 6px; vertical-align: middle; }
.exm-fs-muted { opacity: .65; font-size: .85em; }
.exm-fs-badge { font-size: .72rem; padding: 0 6px; border-radius: 8px; border: 1px solid currentColor; opacity: .75; margin-left: 4px; white-space: nowrap; }
.exm-fs-actions { white-space: nowrap; text-align: right; }
.exm-fs-actions .btn { padding: 1px 6px; }
.exm-fs-drop { border: 2px dashed #b8c2cc; border-radius: 6px; padding: 18px; text-align: center; opacity: .8; margin-bottom: 10px; }
.exm-fs-drop.is-over { border-color: #3c8dbc; background: rgba(60,141,188,.06); opacity: 1; }
.exm-fs-progress { margin-bottom: 10px; }
.exm-fs-progress-item { display: flex; gap: 8px; align-items: center; font-size: .85rem; margin-bottom: 4px; }
.exm-fs-progress-item .exm-fs-bar { flex: 1; height: 6px; background: rgba(0,0,0,.08); border-radius: 3px; overflow: hidden; }
.exm-fs-progress-item .exm-fs-bar span { display: block; height: 100%; width: 0; background: #3c8dbc; }
.exm-fs-progress-item.is-error .exm-fs-bar span { background: #dd4b39; }
.exm-fs-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.exm-fs-card { display: block; padding: 12px; border: 1px solid rgba(0,0,0,.12); border-radius: 6px; color: inherit; }
.exm-fs-card:hover { border-color: #3c8dbc; text-decoration: none; }
.exm-fs-card h5 { margin: 0 0 4px; font-weight: 600; }
.exm-fs-preview { min-height: 200px; background: rgba(0,0,0,.03); border-radius: 4px; display: flex; align-items: center; justify-content: center; overflow: auto; position: relative; }
.exm-fs-watermark { position: absolute; inset: 0; pointer-events: none; z-index: 2; display: flex; flex-wrap: wrap; align-content: space-around; justify-content: space-around; overflow: hidden; }
.exm-fs-watermark span { transform: rotate(-24deg); color: rgba(128,128,128,.28); font-size: 1.1rem; white-space: nowrap; padding: 24px; user-select: none; }
.exm-fs-preview img, .exm-fs-preview video { max-width: 100%; max-height: 75vh; }
.exm-fs-preview iframe { width: 100%; height: 75vh; border: 0; background: #fff; }
.exm-fs-preview pre { width: 100%; max-height: 75vh; margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.exm-fs-section { margin-bottom: 16px; }
.exm-fs-section h4 { font-size: 1.05rem; margin: 0 0 8px; }
.exm-fs-quota { font-size: .85rem; opacity: .8; }
.exm-fs-perm-row { display: flex; gap: 6px; margin-bottom: 6px; flex-wrap: wrap; }
.exm-fs-perm-row select { max-width: 260px; }
.exm-fs-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin-bottom: 10px; }
.exm-fs-filter { display: flex; flex-direction: column; gap: 2px; margin: 0; font-weight: normal; font-size: .85rem; min-width: 140px; }
.exm-fs-filter span { opacity: .75; }
.exm-fs-group th { background: rgba(0,0,0,.04); font-weight: 600; }
.exm-fs-meta { overflow-wrap: anywhere; max-width: 240px; }
.exm-fs-meta-table th { width: 30%; font-weight: 600; }
.exm-fs-snippet { font-size: .85rem; opacity: .85; margin-top: 2px; overflow-wrap: anywhere; }
.exm-fs-field-row { display: grid; grid-template-columns: 1.2fr 1fr 1fr 1.4fr 1fr auto auto auto; gap: 6px; align-items: start; margin-bottom: 6px; }
@media (max-width: 900px) { .exm-fs-field-row { grid-template-columns: 1fr 1fr; } }
</style>
<script>
window.ExmFs = window.ExmFs || (function () {
    var token = @json(csrf_token());

    function post(url, data, isForm) {
        var body;
        if (isForm) {
            body = data;
        } else {
            body = new FormData();
            Object.keys(data || {}).forEach(function (key) {
                var value = data[key];
                if (Array.isArray(value)) {
                    value.forEach(function (v) { body.append(key + '[]', v === null ? '' : v); });
                } else if (value !== undefined) {
                    body.append(key, value === null ? '' : value);
                }
            });
        }
        return send('POST', url, body);
    }

    function send(method, url, body) {
        var headers = { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
        return fetch(url, { method: method, headers: headers, body: body, credentials: 'same-origin' }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (json) {
                json = json || {};
                json._status = res.status;
                if (!res.ok || json.result === false) {
                    var err = new Error(json.message || ('HTTP ' + res.status));
                    err.response = json;
                    throw err;
                }
                return json;
            });
        });
    }

    function escape(text) {
        return String(text === undefined || text === null ? '' : text).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    // toastr shows HTML: the names of the files in the messages are made text (ISS00410)
    function notify(message, ok) {
        if (window.toastr) { (ok === false ? toastr.error : toastr.success)(escape(message)); } else { alert(message); }
    }

    function done(json) {
        if (json && json.message) { notify(json.message, true); }
        if (json && json.redirect) { location.href = json.redirect; } else { location.reload(); }
    }

    function fail(err) { notify(err.message, false); }

    // the questions of the pages in the dialogs of the admin pages (SweetAlert2 v7;
    // the title is HTML, so the texts are escaped), with the browser's own when it is not there
    var buttons = { ok: @json(trans('admin.confirm')), cancel: @json(trans('admin.cancel')) };
    function confirmIt(text) {
        if (typeof window.swal !== 'function') { return Promise.resolve(window.confirm(text)); }
        return window.swal({ title: escape(text), type: 'warning', showCancelButton: true,
            confirmButtonText: escape(buttons.ok), cancelButtonText: escape(buttons.cancel) })
            .then(function (r) { return !!(r && r.value); });
    }
    // the text typed, or null when cancelled
    function ask(text, value) {
        if (typeof window.swal !== 'function') { return Promise.resolve(window.prompt(text, value || '')); }
        return window.swal({ title: escape(text), input: 'text', inputValue: value || '', showCancelButton: true,
            confirmButtonText: escape(buttons.ok), cancelButtonText: escape(buttons.cancel) })
            .then(function (r) { return r && !r.dismiss && r.value !== undefined ? String(r.value) : null; });
    }
    // a message with a list (as the files that could not be sent); resolved when closed
    function report(title, lines) {
        var text = lines.join('\n');
        if (typeof window.swal !== 'function') { window.alert(title + '\n\n' + text); return Promise.resolve(); }
        var html = '<ul style="text-align:left;max-height:40vh;overflow:auto;margin:0;padding-left:1.2em">'
            + lines.map(function (line) { return '<li style="overflow-wrap:anywhere">' + escape(line) + '</li>'; }).join('') + '</ul>';
        return window.swal({ title: escape(title), html: html, type: 'error', confirmButtonText: escape(buttons.ok) }).then(function () {});
    }
    // a text to copy by hand (the clipboard is not there)
    function show(title, text) {
        if (typeof window.swal !== 'function') { window.prompt(title, text); return; }
        window.swal({ title: escape(title), input: 'text', inputValue: text, confirmButtonText: escape(buttons.ok),
            onOpen: function () { var i = document.querySelector('.swal2-input'); if (i) { i.select(); } } });
    }

    function size(bytes) {
        bytes = Number(bytes) || 0;
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        return (i === 0 ? bytes : bytes.toFixed(1)) + ' ' + units[i];
    }

    return { post: post, send: send, notify: notify, done: done, fail: fail, size: size, token: token, escape: escape, confirm: confirmIt, ask: ask, show: show, report: report };
})();
</script>
<script>
// "リンクをコピー" (ISS02815): the name as a link (HTML: a paste into Teams, OneNote,
// Outlook or Word is a link with the name) and the address as text, as the copy
// button of the issues of ExmentPM. Plain text where the browser has no ClipboardItem.
if (!window.ExmFsCopyLink) {
    window.ExmFsCopyLink = function (url, name) {
        var copied = @json(exmtrans('file_server.link_copied'));
        var ok = function () { window.ExmFs.notify(copied, true); };
        var plain = function () {
            if (navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext) {
                navigator.clipboard.writeText(url).then(ok, function () { prompt('URL', url); });
            } else {
                prompt('URL', url);
            }
        };
        var html = '<a href="' + window.ExmFs.escape(url) + '">' + window.ExmFs.escape(name || url) + '</a>';
        try {
            if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write && window.isSecureContext) {
                navigator.clipboard.write([new window.ClipboardItem({
                    'text/plain': new Blob([url], { type: 'text/plain' }),
                    'text/html': new Blob([html], { type: 'text/html' })
                })]).then(ok, plain);
                return;
            }
        } catch (e) { /* plain text below */ }
        plain();
    };
    document.addEventListener('click', function (e) {
        var b = e.target && e.target.closest ? e.target.closest('[data-fs-copy-link]') : null;
        if (!b) { return; }
        e.preventDefault();
        window.ExmFsCopyLink(b.getAttribute('data-fs-copy-link'), b.getAttribute('data-fs-copy-name'));
    });
}
</script>
