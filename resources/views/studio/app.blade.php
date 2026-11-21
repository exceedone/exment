<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#00704a">
<meta name="csrf-token" content="{{ csrf_token() }}">
{{-- a manifest is fetched without the cookies unless asked; the app needs the login --}}
<link rel="manifest" href="{{ $manifest }}" crossorigin="use-credentials">
<title>{{ $title }}</title>
<style>
:root { --green: #00704a; --line: #e3e6ea; --text: #1f2933; --sub: #6b7280; --bg: #f5f6f8; --danger: #c0392b; }
* { box-sizing: border-box; }
body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Hiragino Sans", "Noto Sans JP", "Segoe UI", sans-serif; color: var(--text); background: var(--bg); font-size: 16px; }
header { position: sticky; top: 0; z-index: 2; display: flex; align-items: center; gap: 8px; height: 52px; padding: 0 12px; padding-top: env(safe-area-inset-top); background: var(--green); color: #fff; }
header h1 { flex: 1; margin: 0; font-size: 1.05rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
header button, header a { color: #fff; background: none; border: 0; font-size: 1rem; padding: 6px; text-decoration: none; }
header button[hidden] { display: none; }
main { max-width: 900px; margin: 0 auto; padding: 12px; padding-bottom: 96px; }
.card { background: #fff; border: 1px solid var(--line); border-radius: 8px; margin-bottom: 10px; }
.list { list-style: none; margin: 0; padding: 0; }
.list li { border-bottom: 1px solid var(--line); }
.list li:last-child { border-bottom: 0; }
.list a, .list button.row { display: block; width: 100%; text-align: left; padding: 14px 14px; color: inherit; text-decoration: none; background: none; border: 0; font-size: 1rem; }
.list .sub { display: block; color: var(--sub); font-size: .82rem; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.group { color: var(--sub); font-size: .85rem; margin: 16px 4px 6px; }
.empty { color: var(--sub); padding: 16px; text-align: center; }
.pager { display: flex; justify-content: space-between; align-items: center; margin: 10px 0; color: var(--sub); font-size: .9rem; }
.btn { display: inline-block; border: 1px solid var(--line); background: #fff; color: var(--text); border-radius: 6px; padding: 10px 14px; font-size: 1rem; }
.btn.primary { background: var(--green); border-color: var(--green); color: #fff; }
.btn.small { padding: 6px 10px; font-size: .85rem; }
.btn:disabled { opacity: .5; }
.fab { position: fixed; right: 18px; bottom: calc(18px + env(safe-area-inset-bottom)); width: 56px; height: 56px; border-radius: 50%; background: var(--green); color: #fff; border: 0; font-size: 1.8rem; box-shadow: 0 2px 8px rgba(0,0,0,.25); }
.form { padding: 12px; }
.grid { display: grid; gap: 12px; margin-bottom: 12px; }
@media (max-width: 640px) { .grid { grid-template-columns: 1fr !important; } }
.field { margin-bottom: 14px; }
.field[hidden] { display: none; }
.field label.name { display: block; font-size: .85rem; font-weight: 600; margin-bottom: 4px; }
.field .req { color: var(--danger); margin-left: 2px; }
.field .help { color: var(--sub); font-size: .8rem; margin-top: 3px; }
.field input, .field select, .field textarea { width: 100%; font-size: 16px; padding: 10px; border: 1px solid #c9ced6; border-radius: 6px; background: #fff; }
.field input[readonly] { background: #f3f4f6; }
.field select[multiple] { min-height: 110px; }
.header-item { font-weight: 700; font-size: 1.05rem; margin: 6px 0 10px; }
.text-item { color: var(--sub); white-space: pre-wrap; margin: 0 0 10px; }
.line-item { border: 0; border-top: 1px solid var(--line); margin: 6px 0 14px; }
.image-item { max-width: 100%; margin-bottom: 10px; }
.detail { border-top: 2px solid var(--line); margin-top: 8px; padding-top: 10px; }
.detail h2 { font-size: 1rem; margin: 0 0 8px; }
.detail-row { border: 1px solid var(--line); border-radius: 6px; padding: 10px; margin-bottom: 8px; background: #fbfbfc; }
.detail-row .remove { float: right; color: var(--danger); background: none; border: 0; font-size: .9rem; }
.errors { background: #fdecea; color: var(--danger); border-radius: 6px; padding: 10px 12px; margin-bottom: 12px; font-size: .9rem; }
.errors ul { margin: 0; padding-left: 18px; }
.actions { position: fixed; left: 0; right: 0; bottom: 0; display: flex; gap: 8px; justify-content: flex-end; padding: 10px 12px; padding-bottom: calc(10px + env(safe-area-inset-bottom)); background: #fff; border-top: 1px solid var(--line); }
.toast { position: fixed; left: 50%; bottom: 90px; transform: translateX(-50%); background: #1f2933; color: #fff; padding: 10px 16px; border-radius: 20px; font-size: .9rem; }
.notice { color: var(--sub); font-size: .85rem; }
.previews { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.previews img { width: 88px; height: 88px; object-fit: cover; border-radius: 6px; border: 1px solid var(--line); }
.field input[type=file] { padding: 8px; }
header .scan svg, .scan-btn svg { width: 22px; height: 22px; vertical-align: middle; }
.with-scan { display: flex; gap: 6px; }
.with-scan input { flex: 1; min-width: 0; }
.scan-btn { flex: 0 0 44px; border: 1px solid #c9ced6; border-radius: 6px; background: #fff; color: var(--text); }
.scanner { position: fixed; inset: 0; z-index: 10; display: flex; flex-direction: column; background: #000; color: #fff; }
.scanner-bar { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px; padding-top: calc(12px + env(safe-area-inset-top)); font-size: .9rem; }
.scanner video { flex: 1; min-height: 0; width: 100%; object-fit: cover; }
.notice-bar { display: block; background: #fff8e1; border: 1px solid #f3d27a; color: #6b4e00; border-radius: 8px; padding: 10px 12px; margin-bottom: 10px; font-size: .9rem; text-decoration: none; }
a.notice-bar { color: #6b4e00; }
.draft-actions { display: flex; gap: 8px; padding: 0 14px 12px; }
.draft-error { color: var(--danger); font-size: .82rem; padding: 0 14px 8px; }
.scanner-frame { position: absolute; left: 12%; right: 12%; top: 38%; height: 24%; border: 3px solid rgba(255,255,255,.85); border-radius: 12px; pointer-events: none; }
</style>
</head>
<body>
<header>
    <button type="button" id="back" aria-label="{{ exmtrans('studio.app_back') }}" hidden>&#8249;</button>
    <h1 id="title">{{ $title }}</h1>
    <button type="button" id="scan" class="scan" aria-label="{{ exmtrans('studio.app_scan') }}" title="{{ exmtrans('studio.app_scan') }}" hidden></button>
    <a href="{{ $pc_url }}">{{ exmtrans('studio.app_pc') }}</a>
</header>
<main id="main" aria-live="polite"></main>

@php
    $texts = [
        'title' => $title,
        'screens' => exmtrans('studio.app_screens'),
        'no_screens' => exmtrans('studio.app_no_screens'),
        'no_data' => exmtrans('studio.app_no_data'),
        'new' => exmtrans('studio.app_new'),
        'save' => exmtrans('studio.app_save'),
        'saving' => exmtrans('studio.app_saving'),
        'saved' => exmtrans('studio.app_saved'),
        'cancel' => exmtrans('studio.app_cancel'),
        'add_row' => exmtrans('studio.app_add_row'),
        'remove_row' => exmtrans('studio.app_remove_row'),
        'prev' => exmtrans('studio.app_prev'),
        'next' => exmtrans('studio.app_next'),
        'page' => exmtrans('studio.app_page'),
        'loading' => exmtrans('studio.app_loading'),
        'failed' => exmtrans('studio.app_failed'),
        'file_saved' => exmtrans('studio.app_file_saved'),
        'file_too_large' => exmtrans('studio.app_file_too_large'),
        'file_unreadable' => exmtrans('studio.app_file_unreadable'),
        'auto_number' => exmtrans('studio.auto_number_preview'),
        'choose' => exmtrans('studio.app_choose'),
        'scan' => exmtrans('studio.app_scan'),
        'scan_hint' => exmtrans('studio.app_scan_hint'),
        'scan_close' => exmtrans('studio.app_scan_close'),
        'scan_no_camera' => exmtrans('studio.app_scan_no_camera'),
        'scan_not_found' => exmtrans('studio.app_scan_not_found'),
        'scan_no_screen' => exmtrans('studio.app_scan_no_screen'),
        'scan_found' => exmtrans('studio.app_scan_found'),
        'scan_not_number' => exmtrans('studio.app_scan_not_number'),
        'offline' => exmtrans('studio.app_offline'),
        'draft_saved' => exmtrans('studio.app_draft_saved'),
        'drafts' => exmtrans('studio.app_drafts'),
        'drafts_count' => exmtrans('studio.app_drafts_count'),
        'drafts_sent' => exmtrans('studio.app_drafts_sent'),
        'drafts_none' => exmtrans('studio.app_drafts_none'),
        'draft_send' => exmtrans('studio.app_draft_send'),
        'draft_open' => exmtrans('studio.app_draft_open'),
        'draft_delete' => exmtrans('studio.app_draft_delete'),
        'draft_delete_confirm' => exmtrans('studio.app_draft_delete_confirm'),
        'draft_edit' => exmtrans('studio.app_draft_edit'),
        'draft_failed' => exmtrans('studio.app_draft_failed'),
        'yes' => exmtrans('common.yes'),
        'no' => exmtrans('common.no'),
    ];
@endphp
<script>
(function () {
    var t = @json($texts);
    var webapi = @json($webapi);
    var loginUrl = @json($pc_url);
    var csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    var main = document.getElementById('main');
    var titleBox = document.getElementById('title');
    var back = document.getElementById('back');
    var scanButton = document.getElementById('scan');
    var codeUrl = @json($code_url);
    var zxingUrl = @json($zxing_url);
    var userKey = @json($user_key);
    var screensCache = {};
    var optionsCache = {};

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register(@json($service_worker), { scope: @json($scope) }).catch(function () {});
    }

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            var v = attrs[k];
            if (v === null || v === undefined || v === false) { return; }
            if (k === 'text') { node.textContent = v; }
            else if (k === 'className') { node.className = v; }
            else if (k === 'hidden' || k === 'required' || k === 'multiple' || k === 'readOnly' || k === 'disabled') { node[k] = !!v; }
            else { node.setAttribute(k, v); }
        });
        (children || []).forEach(function (c) { if (c) { node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return node;
    }
    function toast(message) {
        var box = el('div', { className: 'toast', text: message, role: 'status' });
        document.body.appendChild(box);
        setTimeout(function () { box.remove(); }, 2500);
    }

    // the data API of the web session -------------------------------------------------
    function api(method, path, body) { return call(method, webapi + path, body); }
    function call(method, url, body) {
        var options = { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf } };
        if (body !== undefined) { options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(body); }
        return fetch(url, options).catch(function () {
            var offline = new Error('offline');
            offline.offline = true;
            throw offline;
        }).then(function (r) {
            if (r.status === 401 || r.status === 419) { location.href = loginUrl; throw new Error('login'); }
            return r.text().then(function (text) {
                var data = null;
                try { data = text ? JSON.parse(text) : null; } catch (e) { data = null; }
                if (!r.ok) { var error = new Error('api'); error.status = r.status; error.data = data; throw error; }
                return data;
            });
        });
    }
    // the phone's own store (IndexedDB): the screens it opened, and the drafts not sent yet.
    // Both are kept per user: another user of the same phone neither sees nor sends them.
    var dbOpening = null;
    function db() {
        if (!dbOpening) {
            dbOpening = new Promise(function (resolve, reject) {
                if (!window.indexedDB) { reject(new Error('indexedDB')); return; }
                var request = indexedDB.open('exment-app', 1);
                request.onupgradeneeded = function () {
                    request.result.createObjectStore('drafts', { keyPath: 'id', autoIncrement: true });
                    request.result.createObjectStore('cache');
                };
                request.onsuccess = function () { resolve(request.result); };
                request.onerror = function () { reject(request.error); };
            });
        }
        return dbOpening;
    }
    function store(name, mode, work) {
        return db().then(function (d) {
            return new Promise(function (resolve, reject) {
                var tx = d.transaction(name, mode);
                var request = work(tx.objectStore(name));
                tx.oncomplete = function () { resolve(request ? request.result : undefined); };
                tx.onerror = tx.onabort = function () { reject(tx.error); };
            });
        });
    }
    function cacheGet(key) { return store('cache', 'readonly', function (o) { return o.get(userKey + ':' + key); }).catch(function () { return undefined; }); }
    function cachePut(key, value) { return store('cache', 'readwrite', function (o) { return o.put(value, userKey + ':' + key); }).catch(function () {}); }
    // from the server, kept for later; the kept one when the phone is offline
    function kept(key, load) {
        return load().then(function (value) { cachePut(key, value); return value; }).catch(function (error) {
            if (!error || !error.offline) { throw error; }
            return cacheGet(key).then(function (value) { if (value === undefined) { throw error; } return value; });
        });
    }
    function draftsAll() {
        return store('drafts', 'readonly', function (o) { return o.getAll(); })
            .then(function (list) { return (list || []).filter(function (d) { return d.user === userKey; }); })
            .catch(function () { return []; });
    }
    function draftGet(id) { return store('drafts', 'readonly', function (o) { return o.get(id); }).then(function (d) { return d && d.user === userKey ? d : null; }); }
    function draftPut(draft) { return store('drafts', 'readwrite', function (o) { return o.put(draft); }); }
    function draftDelete(id) { return store('drafts', 'readwrite', function (o) { return o.delete(id); }); }

    var screensList = null;
    function allScreens() {
        if (screensList) { return Promise.resolve(screensList); }
        return kept('screens', function () { return api('GET', '/studio/screens'); }).then(function (result) { screensList = (result && result.data) || []; return screensList; });
    }
    function screen(id) {
        if (screensCache[id]) { return Promise.resolve(screensCache[id]); }
        return kept('screen:' + id, function () { return api('GET', '/studio/screens/' + encodeURIComponent(id)); }).then(function (s) { screensCache[id] = s; return s; });
    }
    // the rows a "select from a table" (or a user, an organization) item chooses from
    function optionsOf(table) {
        if (!table) { return Promise.resolve([]); }
        if (optionsCache[table]) { return Promise.resolve(optionsCache[table]); }
        return kept('options:' + table, function () { return api('GET', '/data/' + encodeURIComponent(table) + '?label=1&count=200'); }).then(function (page) {
            var list = (page && page.data ? page.data : []).map(function (row) { return { id: String(row.id), label: row.label || ('#' + row.id) }; });
            optionsCache[table] = list;
            return list;
        }).catch(function () { return []; });
    }

    // pages -------------------------------------------------------------------------
    function setTitle(text, backTo, scanIn) {
        titleBox.textContent = text;
        document.title = text;
        back.hidden = backTo === null;
        back.onclick = function () {
            // the page of found data has no address of its own: draw the one under it again
            if (location.hash.replace(/^#/, '') === (backTo || '')) { route(); } else { location.hash = backTo || ''; }
        };
        scanContext = scanIn || null;
        scanButton.hidden = !canScan || !scanIn;
    }
    function loading() { main.innerHTML = ''; main.appendChild(el('p', { className: 'empty', text: t.loading })); }
    function failed(error) {
        main.innerHTML = '';
        main.appendChild(el('p', { className: 'empty', text: t.failed + (error && error.status ? ' (' + error.status + ')' : '') }));
    }

    function route() {
        var parts = location.hash.replace(/^#/, '').split('/').map(decodeURIComponent);
        window.scrollTo(0, 0);
        if (parts[0] === 's' && parts[1] && parts[2] === 'new') { return formPage(parts[1], null); }
        if (parts[0] === 's' && parts[1] && parts[2] === 'edit' && parts[3]) { return formPage(parts[1], parts[3]); }
        if (parts[0] === 's' && parts[1]) { return listPage(parts[1], parseInt(parts[3] || '1', 10)); }
        if (parts[0] === 'drafts') { return draftsPage(); }
        if (parts[0] === 'd' && parts[1]) { return draftPage(parseInt(parts[1], 10)); }
        return screensPage();
    }
    window.addEventListener('hashchange', route);

    // the screens, by table
    function screensPage() {
        setTitle(t.title, null, {});
        loading();
        screensList = null;
        allScreens().then(function (screens) {
            main.innerHTML = '';
            notices();
            if (screens.length === 0) { main.appendChild(el('p', { className: 'empty', text: t.no_screens })); return; }
            var groups = {};
            var order = [];
            screens.forEach(function (s) {
                if (!groups[s.table]) { groups[s.table] = { label: s.table_label, items: [] }; order.push(s.table); }
                groups[s.table].items.push(s);
            });
            order.forEach(function (table) {
                var group = groups[table];
                group.items.sort(function (a, b) { return (b.default ? 1 : 0) - (a.default ? 1 : 0); });
                main.appendChild(el('div', { className: 'group', text: group.label }));
                var list = el('ul', { className: 'list' });
                group.items.forEach(function (s) {
                    list.appendChild(el('li', {}, [el('a', { href: '#s/' + encodeURIComponent(s.id) }, [s.name])]));
                });
                main.appendChild(el('div', { className: 'card' }, [list]));
            });
        }).catch(failed);
    }

    // the data of a screen
    function listPage(id, page) {
        loading();
        screen(id).then(function (s) {
            setTitle(s.table.label, '', { screen: id, table: s.table.name });
            // the values shown under the title: not a file (its value is a URL)
            var fields = itemsOf(s.blocks[0] || { rows: [] }).filter(function (i) { return i.kind === 'field' && i.type !== 'file' && i.type !== 'image'; });
            // the newest first
            return api('GET', '/data/' + encodeURIComponent(s.table.name) + '?label=1&valuetype=text&count=20&orderby=' + encodeURIComponent('id desc') + '&page=' + page).catch(function (error) {
                if (error && error.offline) { return null; }
                throw error;
            }).then(function (result) {
                main.innerHTML = '';
                notices(result === null);
                var rows = (result && result.data) || [];
                if (result === null) { /* offline: the notice says so */ }
                else if (rows.length === 0) { main.appendChild(el('p', { className: 'empty', text: t.no_data })); }
                else {
                    var list = el('ul', { className: 'list' });
                    rows.forEach(function (row) {
                        // the second line: the first values that are not the title itself
                        var sub = fields.map(function (f) { var v = (row.value || {})[f.name]; return v === null || v === undefined || v === '' ? null : String(v); })
                            .filter(function (v) { return v !== null && v !== row.label; }).slice(0, 3).join(' / ');
                        list.appendChild(el('li', {}, [el('a', { href: '#s/' + encodeURIComponent(id) + '/edit/' + row.id }, [
                            row.label || ('#' + row.id), el('span', { className: 'sub', text: sub }),
                        ])]));
                    });
                    main.appendChild(el('div', { className: 'card' }, [list]));
                }
                var last = (result && result.last_page) || 1;
                if (last > 1) {
                    var prev = el('a', { className: 'btn small', href: '#s/' + encodeURIComponent(id) + '/p/' + (page - 1), text: t.prev });
                    var next = el('a', { className: 'btn small', href: '#s/' + encodeURIComponent(id) + '/p/' + (page + 1), text: t.next });
                    if (page <= 1) { prev.style.visibility = 'hidden'; }
                    if (page >= last) { next.style.visibility = 'hidden'; }
                    main.appendChild(el('div', { className: 'pager' }, [prev, el('span', { text: t.page.replace('%1$s', page).replace('%2$s', last) }), next]));
                }
                if (s.permissions.create) {
                    var add = el('button', { type: 'button', className: 'fab', 'aria-label': t.new, text: '+' });
                    add.addEventListener('click', function () { location.hash = 's/' + encodeURIComponent(id) + '/new'; });
                    main.appendChild(add);
                }
            });
        }).catch(failed);
    }

    // drafts: what was filled in while offline ---------------------------------------
    // the notices at the top of a page: offline, drafts not sent
    function notices(offline) {
        var box = el('div', {});
        main.insertBefore(box, main.firstChild);
        if (offline || !navigator.onLine) { box.appendChild(el('div', { className: 'notice-bar', role: 'status', text: t.offline })); }
        draftsAll().then(function (list) {
            if (list.length > 0) { box.appendChild(el('a', { className: 'notice-bar', href: '#drafts', text: t.drafts_count.replace('%s', list.length) })); }
        });
    }
    // a time as the phone shows it
    function timeOf(iso) {
        try { return new Date(iso).toLocaleString(); } catch (e) { return iso; }
    }
    function draftsPage() {
        setTitle(t.drafts, '', null);
        loading();
        draftsAll().then(function (list) {
            main.innerHTML = '';
            if (list.length === 0) { main.appendChild(el('p', { className: 'empty', text: t.drafts_none })); return; }
            var ul = el('ul', { className: 'list' });
            list.forEach(function (d) {
                var values = Object.keys(d.value || {}).map(function (k) { var v = d.value[k]; return v === null || typeof v === 'object' ? null : String(v); })
                    .filter(function (v) { return v !== null && v !== ''; }).slice(0, 3).join(' / ');
                var send = el('button', { type: 'button', className: 'btn small primary', text: t.draft_send });
                var remove = el('button', { type: 'button', className: 'btn small', text: t.draft_delete });
                send.addEventListener('click', function () {
                    send.disabled = true;
                    sendDrafts([d]).then(function () { route(); });
                });
                remove.addEventListener('click', function () {
                    if (!window.confirm(t.draft_delete_confirm)) { return; }
                    draftDelete(d.id).then(function () { route(); });
                });
                ul.appendChild(el('li', {}, [
                    el('a', { href: '#d/' + d.id }, [
                        d.label + ' (' + (d.created ? t.new : t.draft_edit) + ')',
                        el('span', { className: 'sub', text: timeOf(d.saved_at) + (values ? ' / ' + values : '') }),
                    ]),
                    d.error ? el('div', { className: 'draft-error', text: t.draft_failed.replace('%s', d.error) }) : null,
                    el('div', { className: 'draft-actions' }, [send, remove]),
                ]));
            });
            main.appendChild(el('div', { className: 'card' }, [ul]));
        });
    }
    // a draft, opened in its form to look at or fix
    function draftPage(draftId) {
        loading();
        draftGet(draftId).then(function (d) {
            if (!d) { location.hash = 'drafts'; return; }
            return screen(d.screen).then(function (s) {
                var children = {};
                (d.details || []).forEach(function (detail) { children[detail.table] = detail.rows; });
                drawForm(d.screen, s, { id: d.dataId, value: d.value, children: children }, d);
            });
        }).catch(failed);
    }

    // saving: the parent, then the rows of its details, one after another. The plan
    // keeps what is done (the new id, the rows saved), so sending it again goes on from there.
    function submit(plan) {
        var table = encodeURIComponent(plan.table);
        var first = plan.dataId
            ? api('PUT', '/data/' + table + '/' + encodeURIComponent(plan.dataId), { value: plan.value })
            : api('POST', '/data/' + table, { value: plan.value }).then(function (saved) { plan.dataId = saved && (saved.id || (saved.data && saved.data.id)); });
        return (plan.details || []).reduce(function (p, d) {
            var child = encodeURIComponent(d.table);
            p = d.removed.slice().reduce(function (q, rowId) {
                return q.then(function () { return api('DELETE', '/data/' + child + '/' + encodeURIComponent(rowId)); })
                    .then(function () { d.removed.splice(d.removed.indexOf(rowId), 1); });
            }, p);
            return d.rows.reduce(function (q, row) {
                return q.then(function () {
                    if (row.id) { return api('PUT', '/data/' + child + '/' + encodeURIComponent(row.id), { value: row.value }); }
                    return api('POST', '/data/' + child, { value: row.value, parent_id: plan.dataId, parent_type: plan.table })
                        .then(function (saved) { row.id = saved && (saved.id || (saved.data && saved.data.id)); });
                });
            }, p);
        }, first);
    }
    // the messages of an error of the server, as one line
    function messagesOf(error) {
        var messages = [];
        var body = error && error.data;
        var collect = function (v) {
            if (typeof v === 'string') { messages.push(v); }
            else if (Array.isArray(v)) { v.forEach(collect); }
            else if (v && typeof v === 'object') { Object.keys(v).forEach(function (k) { collect(v[k]); }); }
        };
        collect(body && (body.errors || body.message) ? (body.errors || body.message) : (error && error.message && error.message !== 'api' ? error.message : t.failed));
        return messages;
    }
    // send the drafts (all of them when none given); stops when the phone is offline again
    var sending = null;
    function sendDrafts(only) {
        if (sending) { return sending; }
        sending = (only ? Promise.resolve(only) : draftsAll()).then(function (list) {
            var sent = 0;
            return list.reduce(function (p, d) {
                return p.then(function (stop) {
                    if (stop) { return true; }
                    return submit(d).then(function () {
                        sent++;
                        return draftDelete(d.id).then(function () { return false; });
                    }).catch(function (error) {
                        // keep what was done; offline: try again later, otherwise the user fixes it
                        d.error = error && error.offline ? null : messagesOf(error).join(' ');
                        return draftPut(d).then(function () { return !!(error && error.offline); });
                    });
                });
            }, Promise.resolve(false)).then(function () { return sent; });
        }).then(function (sent) {
            sending = null;
            if (sent > 0) { toast(t.drafts_sent.replace('%s', sent)); }
            return sent;
        }, function () { sending = null; return 0; });
        return sending;
    }
    function sendWhenOnline() {
        if (!navigator.onLine) { return; }
        draftsAll().then(function (list) {
            if (list.filter(function (d) { return !d.error; }).length === 0) { return; }
            sendDrafts(list.filter(function (d) { return !d.error; })).then(function (sent) {
                // the page may show the drafts: draw it again
                var here = location.hash.replace(/^#/, '');
                if (sent > 0 && (here === '' || here === 'drafts' || /^s\/[^/]+(\/p\/\d+)?$/.test(here))) { route(); }
            });
        });
    }
    window.addEventListener('online', sendWhenOnline);

    // reading a QR / bar code with the camera ---------------------------------------
    // zxing.js of Exment, loaded when first needed (the camera works on https and on localhost)
    var canScan = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
    var scanContext = null;
    var zxingLoading = null;
    var SCAN_ICON = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 7V4h3M18 4h3v3M21 17v3h-3M6 20H3v-3"/><path d="M7 8v8M10 8v8M13 8v8M16 8v8"/></svg>';
    scanButton.innerHTML = SCAN_ICON;
    function loadZxing() {
        if (window.ZXing) { return Promise.resolve(); }
        if (!zxingLoading) {
            zxingLoading = new Promise(function (resolve, reject) {
                var script = document.createElement('script');
                script.src = zxingUrl;
                script.onload = resolve;
                script.onerror = function () { zxingLoading = null; reject(new Error('zxing')); };
                document.head.appendChild(script);
            });
        }
        return zxingLoading;
    }
    // the text of the code read, null when closed
    function scanCode() {
        return new Promise(function (resolve) {
            var video = el('video', { playsinline: 'true', muted: 'muted' });
            var close = el('button', { type: 'button', className: 'btn small', text: t.scan_close });
            var overlay = el('div', { className: 'scanner', role: 'dialog', 'aria-label': t.scan }, [
                el('div', { className: 'scanner-bar' }, [el('span', { text: t.scan_hint }), close]), video, el('div', { className: 'scanner-frame' }),
            ]);
            document.body.appendChild(overlay);
            var reader = null;
            var done = false;
            function finish(text) {
                if (done) { return; }
                done = true;
                if (reader) { try { reader.reset(); } catch (e) { /* the camera is already off */ } }
                overlay.remove();
                resolve(text);
            }
            close.addEventListener('click', function () { finish(null); });
            loadZxing().then(function () {
                if (done) { return; }
                reader = new ZXing.BrowserMultiFormatReader();
                return reader.decodeFromConstraints({ video: { facingMode: 'environment' } }, video, function (result) {
                    if (result) { finish(String(result.getText())); }
                }).then(function () { if (done) { reader.reset(); } });
            }).catch(function () { finish(null); toast(t.scan_no_camera); });
        });
    }
    // the screen to open a data of a table with: the one on the page, else the main one of the table
    function screenFor(screens, table) {
        var list = screens.filter(function (s) { return s.table === table; });
        var here = scanContext && scanContext.screen ? list.filter(function (s) { return String(s.id) === String(scanContext.screen); })[0] : null;
        return here || list.filter(function (s) { return s.default; })[0] || list[0] || null;
    }
    scanButton.addEventListener('click', function () {
        var context = scanContext || {};
        var backTo = location.hash.replace(/^#/, '');
        scanCode().then(function (value) {
            if (value === null) { return; }
            var url = codeUrl + '?value=' + encodeURIComponent(value) + (context.table ? '&table=' + encodeURIComponent(context.table) : '');
            return Promise.all([call('GET', url), allScreens()]).then(function (got) {
                var found = ((got[0] && got[0].found) || []).map(function (f) { return { data: f, screen: screenFor(got[1], f.table) }; });
                if (found.length === 0) { toast(t.scan_not_found.replace('%s', value)); return; }
                var openable = found.filter(function (x) { return x.screen; });
                if (openable.length === 0) { toast(t.scan_no_screen); return; }
                var hrefOf = function (x) { return '#s/' + encodeURIComponent(x.screen.id) + '/edit/' + encodeURIComponent(x.data.id); };
                if (openable.length === 1) { location.hash = hrefOf(openable[0]).slice(1); return; }
                // more than one: choose
                setTitle(t.scan_found, backTo, null);
                main.innerHTML = '';
                main.appendChild(el('div', { className: 'group', text: value }));
                var list = el('ul', { className: 'list' });
                openable.forEach(function (x) {
                    list.appendChild(el('li', {}, [el('a', { href: hrefOf(x) }, [
                        x.data.label || ('#' + x.data.id), el('span', { className: 'sub', text: x.screen.table_label }),
                    ])]));
                });
                main.appendChild(el('div', { className: 'card' }, [list]));
            });
        }).catch(function () { toast(t.failed); });
    });

    // a form ------------------------------------------------------------------------
    function itemsOf(block) {
        var items = [];
        (block.rows || []).forEach(function (row) { row.columns.forEach(function (c) { c.items.forEach(function (i) { items.push(i); }); }); });
        return items;
    }

    // one input of a field; get() reads its value, set() writes it
    function inputOf(field, value, onChange) {
        var box, get, set;
        var type = field.type;
        var choices = field.choices;
        if (choices || ['select_table', 'user', 'organization'].indexOf(type) !== -1) {
            box = el('select', { multiple: field.multiple, required: field.required });
            if (!field.multiple) { box.appendChild(el('option', { value: '', text: t.choose })); }
            var fill = function (list) {
                list.forEach(function (c) { box.appendChild(el('option', { value: String(c.value !== undefined ? c.value : c.id), text: c.label })); });
                set(value);
            };
            get = function () {
                var selected = Array.prototype.filter.call(box.options, function (o) { return o.selected && o.value !== ''; }).map(function (o) { return o.value; });
                return field.multiple ? selected : (selected[0] === undefined ? null : selected[0]);
            };
            set = function (v) {
                var values = (Array.isArray(v) ? v : [v]).filter(function (x) { return x !== null && x !== undefined; }).map(String);
                Array.prototype.forEach.call(box.options, function (o) { o.selected = values.indexOf(o.value) !== -1; });
            };
            if (choices) { fill(choices); } else { optionsOf(field.reference ? field.reference.table : type).then(fill); }
        } else if (type === 'textarea' || type === 'editor') {
            box = el('textarea', { rows: '4', required: field.required });
            get = function () { return box.value === '' ? null : box.value; };
            set = function (v) { box.value = v === null || v === undefined ? '' : String(v); };
        } else if (type === 'file' || type === 'image') {
            // the files chosen, read as {name, base64} as the data API takes them;
            // nothing chosen: the key is not sent and the saved files stay
            var chosen = null;
            var picker = el('input', { type: 'file', multiple: field.multiple, accept: type === 'image' ? 'image/*' : null });
            var saved = el('div', { className: 'help' });
            var previews = el('div', { className: 'previews' });
            box = el('div', {}, [saved, picker, previews]);
            picker.addEventListener('change', function () {
                var files = Array.prototype.slice.call(picker.files || []);
                previews.innerHTML = '';
                if (files.length === 0) { chosen = null; return; }
                var reading = Promise.all(files.map(function (file) {
                    return readFile(file, type === 'image').then(function (read) {
                        if (read.preview) { previews.appendChild(el('img', { src: read.preview, alt: '' })); }
                        else { previews.appendChild(el('div', { className: 'help', text: read.name })); }
                        return { name: read.name, base64: read.base64 };
                    });
                })).then(function (list) { chosen = list; }).catch(function (error) {
                    // a file that cannot be read is not sent: say so under the item
                    chosen = null;
                    picker.value = '';
                    previews.innerHTML = '';
                    previews.appendChild(el('div', { className: 'errors', text: error && error.message ? error.message : t.failed }));
                });
                pendingReads.push(reading);
            });
            get = function () {
                if (chosen === null) { return undefined; }
                return field.multiple ? chosen : chosen[0];
            };
            set = function (v) {
                var list = (Array.isArray(v) ? v : [v]).filter(function (x) { return x !== null && x !== undefined && x !== ''; });
                // a draft: the files read before, not sent yet
                var pending = list.filter(function (x) { return typeof x === 'object' && x.base64; });
                if (pending.length > 0) {
                    chosen = pending;
                    previews.innerHTML = '';
                    pending.forEach(function (f) {
                        previews.appendChild(/\.jpe?g$/i.test(f.name) ? el('img', { src: 'data:image/jpeg;base64,' + f.base64, alt: '' }) : el('div', { className: 'help', text: f.name }));
                    });
                    return;
                }
                // the server keeps the files under its own names: tell how many there are
                saved.textContent = list.length > 0 ? t.file_saved.replace('%s', list.length) : '';
            };
        } else if (type === 'auto_number') {
            box = el('input', { type: 'text', readOnly: true, placeholder: t.auto_number });
            get = function () { return undefined; };
            set = function (v) { box.value = v === null || v === undefined ? '' : String(v); };
        } else {
            var kinds = { integer: 'number', decimal: 'number', currency: 'number', date: 'date', datetime: 'datetime-local', time: 'time', email: 'email', url: 'url' };
            box = el('input', { type: kinds[type] || 'text', required: field.required, readOnly: !!field.formula });
            if (kinds[type] === 'number') { box.setAttribute('step', 'any'); box.setAttribute('inputmode', 'decimal'); }
            get = function () {
                if (box.value === '') { return null; }
                if (type === 'datetime') { return box.value.replace('T', ' ') + (box.value.length === 16 ? ':00' : ''); }
                return box.value;
            };
            set = function (v) {
                var s = v === null || v === undefined ? '' : String(v);
                if (type === 'datetime') { s = s.replace(' ', 'T').slice(0, 16); }
                if (type === 'date') { s = s.slice(0, 10); }
                if (type === 'time') { s = s.slice(0, 5); }
                if (kinds[type] === 'number') { s = s.replace(/,/g, ''); }
                box.value = s;
            };
        }
        if (!choices && ['select_table', 'user', 'organization'].indexOf(type) === -1) { set(value); }
        box.addEventListener('input', onChange);
        box.addEventListener('change', onChange);
        return { node: box, get: get, set: set };
    }

    function fieldBox(field, value, onChange) {
        var input = inputOf(field, value, onChange);
        var label = el('label', { className: 'name' }, [field.label, field.required ? el('span', { className: 'req', text: '*' }) : null]);
        var control = input.node;
        // a code (a product code, a JAN...) can be read into a text or a number item
        if (canScan && (field.type === 'text' || field.type === 'integer') && !field.formula && !field.choices) {
            var read = el('button', { type: 'button', className: 'scan-btn', 'aria-label': t.scan + ': ' + field.label, title: t.scan });
            read.innerHTML = SCAN_ICON;
            read.addEventListener('click', function () {
                scanCode().then(function (code) {
                    if (code === null) { return; }
                    if (field.type === 'integer' && !/^-?\d+$/.test(code)) { toast(t.scan_not_number.replace('%s', code)); return; }
                    input.set(code);
                    onChange();
                });
            });
            control = el('div', { className: 'with-scan' }, [input.node, read]);
        }
        var box = el('div', { className: 'field' }, [label, control, field.help ? el('div', { className: 'help', text: field.help }) : null]);
        return { field: field, node: box, input: input };
    }

    // the files being read: saving waits for them
    var pendingReads = [];
    var MAX_PHOTO = 1600;
    var MAX_FILE_MB = 10;
    function readFile(file, isImage) {
        return new Promise(function (resolve, reject) {
            if (!isImage || !/^image\//.test(file.type) || /gif|svg/.test(file.type)) {
                if (file.size > MAX_FILE_MB * 1024 * 1024) { reject(new Error(t.file_too_large.replace('%s', MAX_FILE_MB))); return; }
                var reader = new FileReader();
                reader.onload = function () { resolve({ name: file.name, base64: String(reader.result).split(',')[1] || '', preview: null }); };
                reader.onerror = function () { reject(reader.error); };
                reader.readAsDataURL(file);
                return;
            }
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                var scale = Math.min(1, MAX_PHOTO / Math.max(img.width, img.height));
                var canvas = document.createElement('canvas');
                canvas.width = Math.round(img.width * scale);
                canvas.height = Math.round(img.height * scale);
                canvas.getContext('2d').drawImage(img, 0, 0, canvas.width, canvas.height);
                URL.revokeObjectURL(url);
                var data = canvas.toDataURL('image/jpeg', 0.85);
                resolve({ name: file.name.replace(/\.[^.]+$/, '') + '.jpg', base64: data.split(',')[1] || '', preview: data });
            };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error(t.file_unreadable)); };
            img.src = url;
        });
    }

    // a formula of Exment: ${value:column} and ${sum:child table.column}
    function calculate(formula, values, sums) {
        var expr = formula.replace(/\$\{value:([^}]+)\}/g, function (m, name) {
            var v = parseFloat(String(values[name] === null || values[name] === undefined ? '' : values[name]).replace(/,/g, ''));
            return isNaN(v) ? '0' : String(v);
        }).replace(/\$\{sum:([^.}]+)\.([^}]+)\}/g, function (m, table, name) {
            return String((sums[table] && sums[table][name]) || 0);
        });
        if (!/^[0-9\s.+\-*/()]*$/.test(expr) || expr.trim() === '') { return null; }
        try {
            var result = Function('"use strict"; return (' + expr + ');')();
            return isFinite(result) ? Math.round(result * 100) / 100 : null;
        } catch (e) { return null; }
    }

    function holds(condition, values) {
        var v = values[condition.column];
        var list = (Array.isArray(v) ? v : [v]).filter(function (x) { return x !== null && x !== undefined && String(x) !== ''; }).map(String);
        switch (condition.operator) {
            case 'eq': return list.indexOf(String(condition.value)) !== -1;
            case 'ne': return list.indexOf(String(condition.value)) === -1;
            case 'empty': return list.length === 0;
            case 'not_empty': return list.length > 0;
        }
        return true;
    }

    function formPage(id, dataId) {
        loading();
        Promise.all([
            screen(id),
            dataId ? null : Promise.resolve(null),
        ]).then(function (got) {
            var s = got[0];
            var load = dataId ? api('GET', '/data/' + encodeURIComponent(s.table.name) + '/' + encodeURIComponent(dataId) + '?children=1') : Promise.resolve(null);
            return load.then(function (data) { drawForm(id, s, data); });
        }).catch(failed);
    }

    function drawForm(id, s, data, draft) {
        pendingReads = [];
        setTitle(s.table.label, draft ? 'drafts' : 's/' + encodeURIComponent(id));
        main.innerHTML = '';
        var value = (data && data.value) || {};
        var children = (data && data.children) || {};
        var errorsBox = el('div', { className: 'errors', hidden: true });
        var form = el('form', { className: 'card form', novalidate: 'novalidate' }, [errorsBox]);
        var fields = [];   // the fields of the parent
        var details = [];  // {block, rows: [{id, fields}], removed: [ids]}

        function readValues(list) {
            var values = {};
            list.forEach(function (f) { var v = f.input.get(); if (v !== undefined) { values[f.field.name] = v; } });
            return values;
        }
        function sumsOf() {
            var sums = {};
            details.forEach(function (d) {
                var table = d.block.table;
                sums[table] = {};
                d.rows.forEach(function (row) {
                    var values = readValues(row.fields);
                    Object.keys(values).forEach(function (k) {
                        var n = parseFloat(String(values[k]).replace(/,/g, ''));
                        if (!isNaN(n)) { sums[table][k] = (sums[table][k] || 0) + n; }
                    });
                });
            });
            return sums;
        }
        // calculations and conditions, after any change
        function refresh() {
            details.forEach(function (d) {
                d.rows.forEach(function (row) {
                    var values = readValues(row.fields);
                    row.fields.forEach(function (f) {
                        if (f.field.formula) { var r = calculate(f.field.formula, values, {}); f.input.set(r); values[f.field.name] = r; }
                    });
                });
            });
            var sums = sumsOf();
            var values = readValues(fields);
            fields.forEach(function (f) {
                if (f.field.formula) { var r = calculate(f.field.formula, values, sums); f.input.set(r); values[f.field.name] = r; }
            });
            fields.forEach(function (f) {
                var conditions = f.field.visible_if && f.field.visible_if.all;
                if (conditions) { f.node.hidden = !conditions.every(function (c) { return holds(c, values); }); }
            });
        }

        s.blocks.forEach(function (block) {
            if (block.type === 'default') {
                block.rows.forEach(function (row) {
                    var grid = el('div', { className: 'grid' });
                    grid.style.gridTemplateColumns = row.columns.map(function (c) { return 'minmax(0, ' + c.width + 'fr)'; }).join(' ');
                    row.columns.forEach(function (column) {
                        var cell = el('div', {});
                        column.items.forEach(function (item) {
                            if (item.kind === 'field') {
                                var f = fieldBox(item, value[item.name], refresh);
                                fields.push(f);
                                cell.appendChild(f.node);
                            } else if (item.kind === 'header') { cell.appendChild(el('div', { className: 'header-item', text: item.text })); }
                            else if (item.kind === 'text' || item.kind === 'html') {
                                // html is shown as its text: the app does not run what an administrator wrote
                                var tmp = document.createElement('div');
                                tmp.innerHTML = item.kind === 'html' ? item.html : '';
                                cell.appendChild(el('p', { className: 'text-item', text: item.kind === 'html' ? tmp.textContent : item.text }));
                            }
                            else if (item.kind === 'image' && item.url) { cell.appendChild(el('img', { className: 'image-item', src: item.url, alt: '' })); }
                            else if (item.kind === 'line') { cell.appendChild(el('hr', { className: 'line-item' })); }
                        });
                        grid.appendChild(cell);
                    });
                    form.appendChild(grid);
                });
            } else if (block.type === 'one_to_many') {
                var removedBefore = draft ? ((draft.details || []).filter(function (x) { return x.table === block.table; })[0] || { removed: [] }).removed : [];
                var detail = { block: block, rows: [], removed: removedBefore.slice() };
                details.push(detail);
                var items = itemsOf(block).filter(function (i) { return i.kind === 'field'; });
                var rowsBox = el('div', {});
                var addRow = function (rowData) {
                    var row = { id: rowData ? rowData.id : null, fields: [] };
                    var remove = el('button', { type: 'button', className: 'remove', text: t.remove_row });
                    var box = el('div', { className: 'detail-row' }, [remove]);
                    items.forEach(function (item) {
                        var f = fieldBox(item, rowData && rowData.value ? rowData.value[item.name] : null, refresh);
                        row.fields.push(f);
                        box.appendChild(f.node);
                    });
                    remove.addEventListener('click', function () {
                        if (row.id) { detail.removed.push(row.id); }
                        detail.rows.splice(detail.rows.indexOf(row), 1);
                        box.remove();
                        refresh();
                    });
                    detail.rows.push(row);
                    rowsBox.appendChild(box);
                };
                (children[block.table] || []).forEach(addRow);
                var add = el('button', { type: 'button', className: 'btn small', text: '+ ' + t.add_row });
                add.addEventListener('click', function () { addRow(null); refresh(); });
                form.appendChild(el('div', { className: 'detail' }, [el('h2', { text: block.label || block.table }), rowsBox, add]));
            }
        });
        main.appendChild(form);

        var save = el('button', { type: 'button', className: 'btn primary', text: t.save, disabled: !(data && data.id ? s.permissions.edit : s.permissions.create) });
        var cancel = el('a', { className: 'btn', href: draft ? '#drafts' : '#s/' + encodeURIComponent(id), text: t.cancel });
        if (draft && draft.error) {
            errorsBox.appendChild(el('ul', {}, [el('li', { text: draft.error })]));
            errorsBox.hidden = false;
        }
        main.appendChild(el('div', { className: 'actions' }, [cancel, save]));
        refresh();

        function showErrors(error) {
            errorsBox.innerHTML = '';
            errorsBox.appendChild(el('ul', {}, messagesOf(error).map(function (m) { return el('li', { text: m }); })));
            errorsBox.hidden = false;
            window.scrollTo(0, 0);
        }

        save.addEventListener('click', function () {
            refresh();
            save.disabled = true;
            save.textContent = t.saving;
            var plan = null;
            Promise.all(pendingReads).then(function () {
                plan = {
                    user: userKey,
                    screen: id,
                    label: s.table.label,
                    table: s.table.name,
                    dataId: data && data.id ? data.id : null,
                    // a new data (still said so when its first part is already saved)
                    created: draft ? !!draft.created : !(data && data.id),
                    value: readValues(fields),
                    details: details.map(function (d) {
                        return { table: d.block.table, removed: d.removed.slice(), rows: d.rows.map(function (row) { return { id: row.id, value: readValues(row.fields) }; }) };
                    }),
                    saved_at: new Date().toISOString(),
                };
                if (draft) { plan.id = draft.id; }
                return submit(plan);
            }).then(function () {
                return draft ? draftDelete(draft.id) : null;
            }).then(function () {
                toast(t.saved);
                location.hash = 's/' + encodeURIComponent(id);
                // the connection is there: send what was kept before
                sendWhenOnline();
            }).catch(function (error) {
                if (error && error.offline && plan) {
                    // no connection: keep it on the phone, with what was already saved
                    plan.error = null;
                    return draftPut(plan).then(function () {
                        toast(t.draft_saved);
                        location.hash = 's/' + encodeURIComponent(id);
                    }, function () { showErrors(error); save.disabled = false; save.textContent = t.save; });
                }
                // what was saved before the error is not saved twice when the user saves again
                if (plan && plan.dataId) {
                    data = data || {};
                    data.id = plan.dataId;
                    details.forEach(function (d, i) {
                        d.removed = plan.details[i].removed.slice();
                        d.rows.forEach(function (row, k) { row.id = plan.details[i].rows[k].id; });
                    });
                }
                save.disabled = false;
                save.textContent = t.save;
                showErrors(error);
            });
        });
    }

    route();
    // drafts kept while offline go out when the app opens online
    sendWhenOnline();
})();
</script>
</body>
</html>
