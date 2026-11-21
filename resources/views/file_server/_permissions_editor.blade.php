{{-- File server: the editor of a list of permissions (user or organization, and the level).
     $editor_id, $entries, $picker_users, $picker_orgs, $levels --}}
<div class="exm-fs-perms" id="{{ $editor_id }}">
    <div data-fs-perm-rows></div>
    <button type="button" class="btn btn-xs btn-default" data-fs-perm-add><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('file_server.add_permission') }}</button>
</div>
<script>
(function () {
    var el = document.getElementById(@json($editor_id));
    if (!el || el.dataset.ready) { return; }
    el.dataset.ready = '1';
    var users = @json($picker_users);
    var orgs = @json($picker_orgs);
    var levels = @json($levels);
    var entries = @json(array_values($entries));
    var labels = @json(['user' => exmtrans('file_server.target_user'), 'organization' => exmtrans('file_server.target_organization'), 'remove' => exmtrans('file_server.remove')]);
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
        if (orgs.length) { type.appendChild(option('organization', labels.organization, entry.target_type === 'organization')); }
        var target = document.createElement('select');
        target.className = 'form-control input-sm';
        var level = document.createElement('select');
        level.className = 'form-control input-sm';
        level.style.width = 'auto';
        Object.keys(levels).forEach(function (k) { level.appendChild(option(k, levels[k], String(entry.level) === String(k))); });
        function fill() {
            target.innerHTML = '';
            (type.value === 'organization' ? orgs : users).forEach(function (t) { target.appendChild(option(t.id, t.name, String(t.id) === String(entry.target_id))); });
        }
        type.addEventListener('change', function () { entry.target_id = ''; fill(); });
        fill();
        var remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn btn-sm btn-default';
        remove.innerHTML = '<i class="fa fa-times" aria-hidden="true"></i>';
        remove.title = labels.remove;
        remove.addEventListener('click', function () { row.remove(); });
        row.append(type, target, level, remove);
        row._fs = function () { return { target_type: type.value, target_id: target.value, level: level.value }; };
        rows.appendChild(row);
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
