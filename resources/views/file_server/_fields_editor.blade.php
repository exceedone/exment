{{-- File server (ISS00378): the columns of a workspace. Data from FileServerController::settings. --}}
@php
    $types = [];
    foreach (\Exceedone\Exment\Model\FileMetadataField::TYPES as $type) { $types[$type] = exmtrans('file_server.field_type_' . $type); }
@endphp
<div class="box card exm-fs" id="exm-fs-fields" data-url="{{ $fields_url }}">
    <div class="box-header"><h4 style="margin:0"><i class="fa fa-tags" aria-hidden="true"></i> {{ exmtrans('file_server.metadata_fields') }}</h4></div>
    <div class="box-body">
        <p class="help-block" style="margin-top:0">{{ exmtrans('file_server.metadata_fields_help') }}</p>
        <div class="exm-fs-field-row exm-fs-muted" aria-hidden="true">
            <span>{{ exmtrans('file_server.field_label') }}</span>
            <span>{{ exmtrans('file_server.field_name') }}</span>
            <span>{{ exmtrans('file_server.field_type') }}</span>
            <span>{{ exmtrans('file_server.field_options') }}</span>
            <span>{{ exmtrans('file_server.field_default') }}</span>
            <span>{{ exmtrans('file_server.required') }}</span>
            <span>{{ exmtrans('file_server.field_show_in_list') }}</span>
            <span></span>
        </div>
        <div data-fs-fields></div>
        <template data-fs-field-template>
            <div class="exm-fs-field-row" data-fs-field>
                <input type="text" class="form-control input-sm" data-key="label" maxlength="256" aria-label="{{ exmtrans('file_server.field_label') }}" placeholder="{{ exmtrans('file_server.field_label') }}">
                <input type="text" class="form-control input-sm" data-key="name" maxlength="64" pattern="[a-z][a-z0-9_]*" aria-label="{{ exmtrans('file_server.field_name') }}" placeholder="customer">
                <select class="form-control input-sm" data-key="field_type" aria-label="{{ exmtrans('file_server.field_type') }}">
                    @foreach ($types as $type => $label)
                        <option value="{{ $type }}">{{ $label }}</option>
                    @endforeach
                </select>
                <textarea class="form-control input-sm" data-key="options" rows="2" aria-label="{{ exmtrans('file_server.field_options') }}" placeholder="{{ exmtrans('file_server.field_options_placeholder') }}"></textarea>
                <input type="text" class="form-control input-sm" data-key="default_value" aria-label="{{ exmtrans('file_server.field_default') }}">
                <input type="checkbox" data-key="required" aria-label="{{ exmtrans('file_server.required') }}">
                <input type="checkbox" data-key="show_in_list" aria-label="{{ exmtrans('file_server.field_show_in_list') }}" checked>
                <span style="white-space:nowrap">
                    <button type="button" class="btn btn-xs btn-default" data-fs-field-up title="↑" aria-label="↑"><i class="fa fa-arrow-up" aria-hidden="true"></i></button>
                    <button type="button" class="btn btn-xs btn-danger" data-fs-field-remove aria-label="{{ exmtrans('file_server.delete') }}"><i class="fa fa-times" aria-hidden="true"></i></button>
                </span>
            </div>
        </template>
        <div style="margin-top:8px">
            <button type="button" class="btn btn-sm btn-default" data-fs-field-add><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('file_server.field_add') }}</button>
            <button type="button" class="btn btn-sm btn-primary" data-fs-fields-save>{{ exmtrans('common.save') }}</button>
        </div>
    </div>
</div>
<script>
(function () {
    var root = document.getElementById('exm-fs-fields');
    if (!root || root.dataset.ready) { return; }
    root.dataset.ready = '1';
    var Fs = window.ExmFs;
    var list = root.querySelector('[data-fs-fields]');
    var template = root.querySelector('[data-fs-field-template]');
    var fields = {!! json_encode(array_map(function ($f) {
        return array_intersect_key($f, array_flip(['name', 'label', 'field_type', 'options', 'required', 'default_value', 'show_in_list']));
    }, $fields), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) !!};

    function add(field) {
        var row = template.content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[data-key]').forEach(function (el) {
            var key = el.getAttribute('data-key');
            if (!field || !(key in field)) { return; }
            if (el.type === 'checkbox') { el.checked = !!field[key]; } else { el.value = field[key] === null ? '' : field[key]; }
        });
        if (field && field.name) { row.querySelector('[data-key="name"]').readOnly = true; }
        list.appendChild(row);
    }
    fields.forEach(add);

    root.addEventListener('click', function (e) {
        var row = e.target.closest('[data-fs-field]');
        if (e.target.closest('[data-fs-field-remove]') && row) { row.remove(); }
        if (e.target.closest('[data-fs-field-up]') && row && row.previousElementSibling) { list.insertBefore(row, row.previousElementSibling); }
        if (e.target.closest('[data-fs-field-add]')) { add(null); }
        if (e.target.closest('[data-fs-fields-save]')) {
            var entries = Array.prototype.map.call(list.querySelectorAll('[data-fs-field]'), function (r) {
                var entry = {};
                r.querySelectorAll('[data-key]').forEach(function (el) {
                    entry[el.getAttribute('data-key')] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value;
                });
                return entry;
            });
            Fs.post(root.dataset.url, { fields: JSON.stringify(entries) }).then(Fs.done, Fs.fail);
        }
    });
})();
</script>
