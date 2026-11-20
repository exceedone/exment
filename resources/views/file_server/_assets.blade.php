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
.exm-fs-preview { min-height: 200px; background: rgba(0,0,0,.03); border-radius: 4px; display: flex; align-items: center; justify-content: center; overflow: auto; }
.exm-fs-preview img, .exm-fs-preview video { max-width: 100%; max-height: 75vh; }
.exm-fs-preview iframe { width: 100%; height: 75vh; border: 0; background: #fff; }
.exm-fs-preview pre { width: 100%; max-height: 75vh; margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; }
.exm-fs-section { margin-bottom: 16px; }
.exm-fs-section h4 { font-size: 1.05rem; margin: 0 0 8px; }
.exm-fs-quota { font-size: .85rem; opacity: .8; }
.exm-fs-perm-row { display: flex; gap: 6px; margin-bottom: 6px; flex-wrap: wrap; }
.exm-fs-perm-row select { max-width: 260px; }
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

    function size(bytes) {
        bytes = Number(bytes) || 0;
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = 0;
        while (bytes >= 1024 && i < units.length - 1) { bytes /= 1024; i++; }
        return (i === 0 ? bytes : bytes.toFixed(1)) + ' ' + units[i];
    }

    return { post: post, send: send, notify: notify, done: done, fail: fail, size: size, token: token, escape: escape };
})();
</script>
