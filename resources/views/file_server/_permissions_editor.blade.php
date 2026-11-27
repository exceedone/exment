{{-- File server: the editor of a list of permissions (user or organization, and the level).
     $editor_id, $entries, $picker_url, $picker_orgs, $levels. The users and organizations
     are searched (FileServerController::picker), a page at a time. --}}
<div class="exm-fs-perms" id="{{ $editor_id }}">
    <div data-fs-perm-rows></div>
    <button type="button" class="btn btn-xs btn-default" data-fs-perm-add><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('file_server.add_permission') }}</button>
</div>
<script>
(function () {
    var el = document.getElementById(@json($editor_id));
    if (!el || el.dataset.ready) { return; }
    el.dataset.ready = '1';
    var pickerUrl = @json($picker_url);
    var orgs = @json($picker_orgs);
    var levels = @json($levels);
    var entries = @json(array_values($entries));
    var labels = {
        user: @json(exmtrans('file_server.target_user')),
        organization: @json(exmtrans('file_server.target_organization')),
        remove: @json(exmtrans('file_server.remove')),
        searching: @json(exmtrans('file_server.picker_searching')),
        no_result: @json(exmtrans('file_server.picker_no_result'))
    };
    var rows = el.querySelector('[data-fs-perm-rows]');

    function option(value, text, selected) {
        var o = document.createElement('option');
        o.value = value; o.textContent = text; if (selected) { o.selected = true; }
        return o;
    }

    function addRow(entry) {
        entry = entry || { target_type: 'user', target_id: '', level: 1 };
        var row = document.createElement('div');
        row.className = 'exm-fs-perm-row';
        var type = document.createElement('select');
        type.className = 'form-control input-sm';
        type.style.width = 'auto';
        type.appendChild(option('user', labels.user, entry.target_type === 'user'));
        if (orgs) { type.appendChild(option('organization', labels.organization, entry.target_type === 'organization')); }
        var target = document.createElement('select');
        target.className = 'form-control input-sm';
        var level = document.createElement('select');
        level.className = 'form-control input-sm';
        level.style.width = 'auto';
        Object.keys(levels).forEach(function (k) { level.appendChild(option(k, levels[k], String(entry.level) === String(k))); });
        // the one chosen; the others are searched (select2), or the first page without it
        function fill() {
            target.innerHTML = '';
            if (entry.target_id !== '' && entry.target_id !== null && entry.target_id !== undefined) {
                target.appendChild(option(entry.target_id, entry.name || ('#' + entry.target_id), true));
            }
            if (!select2()) {
                pickerPage(type.value, '', 1).then(function (json) {
                    (json.results || []).forEach(function (r) {
                        if (String(r.id) !== String(entry.target_id)) { target.appendChild(option(r.id, r.text, false)); }
                    });
                });
            }
        }
        type.addEventListener('change', function () { entry.target_id = ''; entry.name = ''; fill(); if (select2()) { window.jQuery(target).val(null).trigger('change'); } });
        fill();
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-default';
        remove.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
        remove.title = labels.remove;
        remove.addEventListener('click', function () { row.remove(); });
        row.append(type, target, level, remove);
        if (select2()) {
            window.jQuery(target).select2({
                width: '100%',
                allowClear: false,
                // the first page is shown before anything is typed, in the language of the
                // page (select2's own texts are English when its i18n file is not loaded, #2276)
                minimumInputLength: 0,
                language: {
                    searching: function () { return labels.searching; },
                    loadingMore: function () { return labels.searching; },
                    noResults: function () { return labels.no_result; },
                    errorLoading: function () { return labels.no_result; }
                },
                ajax: {
                    url: pickerUrl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) { return { type: type.value, q: params.term || '', page: params.page || 1 }; },
                    processResults: function (json) { return { results: json.results || [], pagination: json.pagination || { more: false } }; }
                }
            });
        }
        row._fs = function () { return { target_type: type.value, target_id: target.value, level: level.value }; };
        rows.appendChild(row);
    }

    function select2() { return !!(pickerUrl && window.jQuery && window.jQuery.fn && window.jQuery.fn.select2); }
    function pickerPage(kind, q, page) {
        if (!pickerUrl) { return Promise.resolve({ results: [] }); }
        var url = pickerUrl + (pickerUrl.indexOf('?') < 0 ? '?' : '&') + 'type=' + encodeURIComponent(kind) + '&q=' + encodeURIComponent(q) + '&page=' + page;
        return window.ExmFs.send('GET', url).catch(function () { return { results: [] }; });
    }

    entries.forEach(addRow);
    el.querySelector('[data-fs-perm-add]').addEventListener('click', function () { addRow(); });

    // put the entries into a FormData as permissions[i][...]
    el.fsAppendTo = function (fd) {
        Array.prototype.forEach.call(rows.children, function (row, i) {
            var v = row._fs();
            fd.append('permissions[' + i + '][target_type]', v.target_type);
            fd.append('permissions[' + i + '][target_id]', v.target_id);
            fd.append('permissions[' + i + '][level]', v.level);
        });
    };
})();
</script>
