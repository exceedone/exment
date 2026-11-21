{{-- File server: a folder (or the top) of a workspace. Data from FileServerController::folder. --}}
@include('exment::file_server._assets')
@php
    $list_fields = array_values(array_filter($fields, function ($f) { return $f['show_in_list']; }));
    $columns = ['name' => exmtrans('file_server.name')];
    foreach ($list_fields as $f) { $columns['meta:' . $f['name']] = $f['label']; }
    $columns['updated_at'] = exmtrans('file_server.updated_at');
    $columns['size'] = exmtrans('file_server.size');
@endphp
<div class="exm-fs" id="exm-fs-folder"
     data-base="{{ $base_url }}" data-parent="{{ $parent_id }}" data-chunk="{{ $chunk_size }}" data-max="{{ $max_file_size }}">
    <div class="exm-fs-breadcrumb">
        <a href="{{ admin_url('file_server') }}"><i class="fa fa-home" aria-hidden="true"></i> {{ exmtrans('file_server.header') }}</a>
        @foreach ($breadcrumbs as $i => $crumb)
            @if ($i === count($breadcrumbs) - 1)
                <span>{{ $crumb['name'] }}</span>
            @elseif (!isset($crumb['url']))
                <span class="exm-fs-muted">{{ $crumb['name'] }}</span>
            @else
                <a href="{{ $crumb['url'] }}">{{ $crumb['name'] }}</a>
            @endif
        @endforeach
    </div>

    <div class="exm-fs-toolbar">
        @if ($can_upload)
            <button type="button" class="btn btn-sm btn-default" data-fs="new-folder"><i class="fa fa-folder" aria-hidden="true"></i> {{ exmtrans('file_server.new_folder') }}</button>
            <label class="btn btn-sm btn-primary" style="margin:0"><i class="fa fa-upload" aria-hidden="true"></i> {{ exmtrans('file_server.upload_files') }}
                <input type="file" multiple hidden data-fs="file-input"></label>
            <label class="btn btn-sm btn-default" style="margin:0"><i class="fa fa-folder-open" aria-hidden="true"></i> {{ exmtrans('file_server.upload_folder') }}
                <input type="file" multiple webkitdirectory hidden data-fs="folder-input"></label>
        @endif
        <span class="exm-fs-selected" hidden>
            <button type="button" class="btn btn-sm btn-default" data-fs="zip"><i class="fa fa-download" aria-hidden="true"></i> {{ exmtrans('file_server.download') }}</button>
            @if ($can_edit)
                <button type="button" class="btn btn-sm btn-default" data-fs="move"><i class="fa fa-arrows" aria-hidden="true"></i> {{ exmtrans('file_server.move') }}</button>
            @endif
            <button type="button" class="btn btn-sm btn-default" data-fs="copy"><i class="fa fa-clone" aria-hidden="true"></i> {{ exmtrans('file_server.copy') }}</button>
            @if ($can_edit)
                <button type="button" class="btn btn-sm btn-danger" data-fs="trash"><i class="fa fa-trash" aria-hidden="true"></i> {{ exmtrans('file_server.delete') }}</button>
            @endif
        </span>
        <span class="exm-fs-spacer"></span>
        @if (isset($quota))
            <span class="exm-fs-quota">{{ exmtrans('file_server.used') }}: <span data-fs-size="{{ $used_bytes }}"></span> / <span data-fs-size="{{ $quota }}"></span></span>
        @endif
        @if (isset($folder))
            <a href="{{ $base_url }}/nodes/{{ $folder->id }}" class="btn btn-sm btn-default"><i class="fa fa-info-circle" aria-hidden="true"></i> {{ exmtrans('file_server.folder_detail') }}</a>
        @endif
        <button type="button" class="btn btn-sm btn-default" data-fs-follow="{{ $follow_url }}" aria-pressed="{{ $following ? 'true' : 'false' }}"><i class="fa {{ $following ? 'fa-bell' : 'fa-bell-o' }}" aria-hidden="true"></i> {{ $following ? exmtrans('file_server.following') : exmtrans('file_server.follow') }}</button>
        @if ($waiting_count > 0)
            <a href="{{ $approvals_url }}" class="btn btn-sm btn-warning"><i class="fa fa-check-square-o" aria-hidden="true"></i> {{ exmtrans('file_server.approvals') }} ({{ $waiting_count }})</a>
        @endif
        <a href="{{ $base_url }}/trash" class="btn btn-sm btn-default"><i class="fa fa-trash-o" aria-hidden="true"></i> {{ exmtrans('file_server.trash') }}</a>
        @if ($audit_url)
            <a href="{{ $audit_url }}" class="btn btn-sm btn-default"><i class="fa fa-history" aria-hidden="true"></i> {{ exmtrans('file_server.audit') }}</a>
        @endif
        @if ($can_manage_workspace)
            <a href="{{ $base_url }}/settings" class="btn btn-sm btn-default"><i class="fa fa-cog" aria-hidden="true"></i> {{ exmtrans('file_server.workspace_settings') }}</a>
        @endif
    </div>

    @if ($held)
        <div class="alert alert-info" style="padding:8px 12px"><i class="fa fa-lock" aria-hidden="true"></i> {{ exmtrans('file_server.held_help') }}</div>
    @endif
    @if ($needs_approval)
        <div class="alert alert-info" style="padding:8px 12px"><i class="fa fa-check-square-o" aria-hidden="true"></i> {{ exmtrans('file_server.needs_approval_help') }}</div>
    @endif

    <form class="exm-fs-filters" method="get" action="{{ $search_url }}" role="search">
        <input type="hidden" name="workspace" value="{{ $workspace->id }}">
        <div class="input-group input-group-sm" style="max-width:420px">
            <input type="search" name="q" class="form-control" placeholder="{{ exmtrans('file_server.search_placeholder') }}" aria-label="{{ exmtrans('file_server.search') }}">
            <span class="input-group-btn"><button class="btn btn-default" type="submit"><i class="fa fa-search" aria-hidden="true"></i> {{ exmtrans('file_server.search') }}</button></span>
        </div>
    </form>

    @if (count($fields) > 0)
        <form class="exm-fs-filters" method="get" action="{{ $folder_url }}" data-fs="filters">
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            @foreach ($fields as $field)
                <label class="exm-fs-filter">
                    <span>{{ $field['label'] }}</span>
                    @if ($field['groupable'])
                        <select name="meta[{{ $field['name'] }}]" class="form-control input-sm">
                            <option value=""></option>
                            @if ($field['field_type'] === 'boolean')
                                <option value="1" @selected(strval(array_get($filters, $field['name'])) === '1')>{{ exmtrans('file_server.yes') }}</option>
                                <option value="0" @selected(strval(array_get($filters, $field['name'])) === '0')>{{ exmtrans('file_server.no') }}</option>
                            @else
                                @foreach ($field['choices'] as $choice)
                                    <option value="{{ $choice }}" @selected(strval(array_get($filters, $field['name'])) === $choice)>{{ $choice }}</option>
                                @endforeach
                            @endif
                            <option value="__none__" @selected(strval(array_get($filters, $field['name'])) === '__none__')>{{ exmtrans('file_server.no_value') }}</option>
                        </select>
                    @else
                        <input type="{{ $field['field_type'] === 'date' ? 'date' : 'text' }}" name="meta[{{ $field['name'] }}]" class="form-control input-sm" value="{{ array_get($filters, $field['name']) }}">
                    @endif
                </label>
            @endforeach
            @if (collect($fields)->contains('groupable', true))
                <label class="exm-fs-filter">
                    <span>{{ exmtrans('file_server.group_by') }}</span>
                    <select name="group" class="form-control input-sm">
                        <option value=""></option>
                        @foreach ($fields as $field)
                            @if ($field['groupable'])
                                <option value="{{ $field['name'] }}" @selected($group === $field['name'])>{{ $field['label'] }}</option>
                            @endif
                        @endforeach
                    </select>
                </label>
            @endif
            <span class="exm-fs-filter-buttons">
                <button type="submit" class="btn btn-sm btn-default"><i class="fa fa-filter" aria-hidden="true"></i> {{ exmtrans('file_server.apply_filter') }}</button>
                @if (count($filters) > 0 || isset($group))
                    <a href="{{ $folder_url }}" class="btn btn-sm btn-link">{{ exmtrans('file_server.clear_filter') }}</a>
                @endif
            </span>
        </form>
    @endif

    @if ($can_upload)
        <div class="exm-fs-drop" data-fs="drop">{{ exmtrans('file_server.drop_here') }}</div>
        <div class="exm-fs-progress" data-fs="progress"></div>
    @endif

    <div class="box card">
        <div class="box-body table-responsive p-0">
            <table class="table table-hover exm-fs-table">
                <thead>
                    <tr>
                        <th class="exm-fs-check"><input type="checkbox" data-fs="check-all" aria-label="{{ exmtrans('file_server.select_all') }}"></th>
                        @foreach ($columns as $key => $label)
                            @php $next = ($sort === $key && $direction === 'asc') ? 'desc' : 'asc'; @endphp
                            <th><a href="{{ $folder_url }}?{{ http_build_query($query + ['sort' => $key, 'direction' => $next]) }}" style="color:inherit">{{ $label }}
                                @if ($sort === $key)<i class="fa fa-sort-{{ $direction === 'asc' ? 'asc' : 'desc' }}" aria-hidden="true"></i>@endif</a></th>
                        @endforeach
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @if (count($items) === 0)
                        <tr><td colspan="{{ 5 + count($list_fields) }}" class="exm-fs-muted">{{ count($filters) > 0 ? exmtrans('file_server.no_match') : exmtrans('file_server.empty_folder') }}</td></tr>
                    @endif
                    @if (isset($groups))
                        @foreach ($groups as $group)
                            <tr class="exm-fs-group"><th colspan="{{ 5 + count($list_fields) }}">{{ $group['label'] }} <span class="exm-fs-muted">({{ count($group['items']) }})</span></th></tr>
                            @foreach ($group['items'] as $item)
                                @include('exment::file_server._row', ['item' => $item, 'list_fields' => $list_fields, 'can_edit' => $can_edit])
                            @endforeach
                        @endforeach
                    @else
                        @foreach ($items as $item)
                            @include('exment::file_server._row', ['item' => $item, 'list_fields' => $list_fields, 'can_edit' => $can_edit])
                        @endforeach
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    <dialog class="exm-fs-dialog" data-fs="target-dialog" style="min-width:320px;border:1px solid #ccc;border-radius:6px">
        <form method="dialog">
            <h4 data-fs="target-title" style="margin-top:0"></h4>
            <select class="form-control" data-fs="target-select"></select>
            <div style="margin-top:12px;text-align:right">
                <button value="cancel" class="btn btn-default btn-sm">{{ exmtrans('file_server.cancel') }}</button>
                <button value="ok" class="btn btn-primary btn-sm">{{ trans('admin.submit') }}</button>
            </div>
        </form>
    </dialog>
</div>

<script>
(function () {
    var root = document.getElementById('exm-fs-folder');
    if (!root || root.dataset.ready) { return; }
    root.dataset.ready = '1';
    var Fs = window.ExmFs;
    var base = root.dataset.base;
    var parentId = root.dataset.parent || '';
    var chunkSize = Number(root.dataset.chunk) || 5242880;
    var maxSize = Number(root.dataset.max) || 0;
    var t = {!! json_encode([
        'folder_name' => exmtrans('file_server.prompt_folder_name'),
        'new_name' => exmtrans('file_server.prompt_new_name'),
        'confirm_trash' => exmtrans('file_server.confirm_trash'),
        'move_to' => exmtrans('file_server.move_to'),
        'copy_to' => exmtrans('file_server.copy_to'),
        'too_large' => exmtrans('file_server.error_too_large'),
        'uploading' => exmtrans('file_server.uploading'),
    ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!};

    root.querySelectorAll('[data-fs-size]').forEach(function (el) { el.textContent = Fs.size(el.getAttribute('data-fs-size')); });

    function q(sel) { return root.querySelector('[data-fs="' + sel + '"]'); }
    function selected() { return Array.prototype.map.call(root.querySelectorAll('[data-fs="check"]:checked'), function (c) { return c.value; }); }
    function refreshSelection() { var bar = root.querySelector('.exm-fs-selected'); if (bar) { bar.hidden = selected().length === 0; } }

    root.addEventListener('change', function (e) {
        if (e.target.matches('[data-fs="check-all"]')) {
            root.querySelectorAll('[data-fs="check"]').forEach(function (c) { c.checked = e.target.checked; });
        }
        if (e.target.matches('[data-fs="file-input"], [data-fs="folder-input"]')) {
            var list = Array.prototype.map.call(e.target.files, function (f) {
                var rel = f.webkitRelativePath || '';
                return { file: f, dir: rel.indexOf('/') >= 0 ? rel.substring(0, rel.lastIndexOf('/')) : '' };
            });
            e.target.value = '';
            uploadAll(list);
        }
        refreshSelection();
    });

    // ---------------------------------------------------------------- operations
    root.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-fs]');
        if (!btn || btn.tagName === 'INPUT' || btn.tagName === 'LABEL') { return; }
        var action = btn.getAttribute('data-fs');
        var row = btn.closest('tr[data-id]');
        if (action === 'new-folder') {
            var name = prompt(t.folder_name);
            if (name) { Fs.post(base + '/folders', { parent_id: parentId, name: name }).then(Fs.done, Fs.fail); }
        } else if (action === 'rename' && row) {
            var newName = prompt(t.new_name, row.dataset.name);
            if (newName && newName !== row.dataset.name) { Fs.post(base + '/nodes/' + row.dataset.id + '/rename', { name: newName }).then(Fs.done, Fs.fail); }
        } else if (action === 'trash') {
            if (!confirm(t.confirm_trash)) { return; }
            sequence(selected(), function (id) { return Fs.post(base + '/nodes/' + id + '/trash', {}); });
        } else if (action === 'zip') {
            var form = document.createElement('form');
            form.method = 'POST';
            form.action = base + '/download';
            form.innerHTML = '<input type="hidden" name="_token" value="' + Fs.token + '">';
            selected().forEach(function (id) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'ids[]'; i.value = id; form.appendChild(i); });
            document.body.appendChild(form);
            form.submit();
            form.remove();
        } else if (action === 'move' || action === 'copy') {
            chooseTarget(action === 'move' ? t.move_to : t.copy_to, function (target) {
                sequence(selected(), function (id) { return Fs.post(base + '/nodes/' + id + '/' + action, { target_id: target }); });
            });
        }
    });

    function sequence(ids, fn) {
        var p = Promise.resolve();
        ids.forEach(function (id) { p = p.then(function () { return fn(id); }); });
        p.then(function () { location.reload(); }, Fs.fail);
    }

    function chooseTarget(title, callback) {
        var dialog = q('target-dialog');
        q('target-title').textContent = title;
        Fs.send('GET', base + '/folders.json').then(function (json) {
            var select = q('target-select');
            select.innerHTML = '';
            json.folders.forEach(function (f) {
                var o = document.createElement('option');
                o.value = f.id === null ? '' : f.id;
                o.textContent = f.name;
                select.appendChild(o);
            });
            dialog.onclose = function () { if (dialog.returnValue === 'ok') { callback(select.value); } };
            dialog.showModal();
        }, Fs.fail);
    }

    // ---------------------------------------------------------------- upload
    var drop = q('drop');
    if (drop) {
        ['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
        ['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
        drop.addEventListener('drop', function (e) {
            var items = e.dataTransfer.items;
            var entries = [];
            if (items && items.length && items[0].webkitGetAsEntry) {
                for (var i = 0; i < items.length; i++) { var en = items[i].webkitGetAsEntry(); if (en) { entries.push(en); } }
                collect(entries).then(uploadAll);
            } else {
                uploadAll(Array.prototype.map.call(e.dataTransfer.files, function (f) { return { file: f, dir: '' }; }));
            }
        });
    }

    // the files in dropped folders, with the folder of each
    function collect(entries) {
        var out = [];
        function walk(entry, dir) {
            return new Promise(function (resolve) {
                if (entry.isFile) {
                    entry.file(function (f) { out.push({ file: f, dir: dir }); resolve(); }, function () { resolve(); });
                } else if (entry.isDirectory) {
                    var reader = entry.createReader();
                    var all = [];
                    (function read() {
                        reader.readEntries(function (batch) {
                            if (!batch.length) {
                                var sub = dir ? dir + '/' + entry.name : entry.name;
                                Promise.all(all.map(function (c) { return walk(c, sub); })).then(resolve);
                                return;
                            }
                            all = all.concat(Array.prototype.slice.call(batch));
                            read();
                        }, function () { resolve(); });
                    })();
                } else { resolve(); }
            });
        }
        return Promise.all(entries.map(function (en) { return walk(en, ''); })).then(function () { return out; });
    }

    function progressRow(name) {
        var el = document.createElement('div');
        el.className = 'exm-fs-progress-item';
        el.innerHTML = '<span class="exm-fs-pname"></span><span class="exm-fs-bar"><span></span></span><span class="exm-fs-pct">0%</span>';
        el.querySelector('.exm-fs-pname').textContent = name;
        q('progress').appendChild(el);
        return {
            set: function (ratio) { var p = Math.round(ratio * 100); el.querySelector('.exm-fs-bar span').style.width = p + '%'; el.querySelector('.exm-fs-pct').textContent = p + '%'; },
            error: function (msg) { el.classList.add('is-error'); el.querySelector('.exm-fs-pct').textContent = msg; }
        };
    }

    function uploadAll(list) {
        if (!list.length) { return; }
        var failed = 0;
        var p = Promise.resolve();
        list.forEach(function (item) {
            p = p.then(function () {
                var row = progressRow((item.dir ? item.dir + '/' : '') + item.file.name);
                if (maxSize > 0 && item.file.size > maxSize) { row.error(t.too_large); failed++; return; }
                var job = item.file.size > chunkSize ? uploadChunked(item, row) : uploadSmall(item, row);
                return job.then(function () { row.set(1); }, function (err) { failed++; row.error(err.message); });
            });
        });
        p.then(function () { if (failed === 0) { location.reload(); } });
    }

    function uploadSmall(item, row) {
        var fd = new FormData();
        fd.append('parent_id', parentId);
        fd.append('files[]', item.file, item.file.name);
        fd.append('paths[]', item.dir);
        row.set(0.1);
        return Fs.post(base + '/upload', fd, true);
    }

    function uploadChunked(item, row) {
        var file = item.file;
        return Fs.post(base + '/upload/init', { parent_id: parentId, name: file.name, size: file.size, path: item.dir }).then(function (json) {
            var session = json.session;
            var size = json.chunk_size || chunkSize;
            function sendFrom(offset, tries) {
                if (offset >= file.size) {
                    return Fs.post(base + '/upload/' + session + '/complete', {});
                }
                var fd = new FormData();
                fd.append('offset', offset);
                fd.append('chunk', file.slice(offset, offset + size), 'chunk');
                return Fs.post(base + '/upload/' + session + '/chunk', fd, true).then(function (res) {
                    row.set(res.received / file.size);
                    return sendFrom(res.received, 0);
                }, function (err) {
                    var res = err.response || {};
                    // the server tells where to go on from; or try the part again (the line broke)
                    if (typeof res.received === 'number') { return sendFrom(res.received, tries + 1); }
                    if (tries < 3 && (!res._status || res._status >= 500)) {
                        return new Promise(function (r) { setTimeout(r, 1000 * (tries + 1)); }).then(function () {
                            return Fs.send('GET', base + '/upload/' + session).then(function (st) { return sendFrom(st.received, tries + 1); });
                        });
                    }
                    throw err;
                });
            }
            // not finished: removed, so that it does not keep its space and its place
            return sendFrom(0, 0).catch(function (err) {
                Fs.send('DELETE', base + '/upload/' + session).catch(function () {});
                throw err;
            });
        });
    }
})();
</script>
<script>
(function () {
    var btn = document.querySelector('#exm-fs-folder [data-fs-follow]');
    if (!btn || btn.dataset.ready) { return; }
    btn.dataset.ready = '1';
    btn.addEventListener('click', function () {
        window.ExmFs.post(btn.getAttribute('data-fs-follow'), {}).then(window.ExmFs.done, window.ExmFs.fail);
    });
})();
</script>
