{{-- Studio, phase 2: make an input screen, for a new table or for a table that exists. Saved by StudioController@store. --}}
<form method="post" action="{{ $action }}" class="exm-builder" id="exm-builder">
    {{ csrf_field() }}
    <input type="hidden" name="fields" id="exm-builder-fields" value="">

    <div class="box card exm-builder-head">
        <div class="box-body">
            @if ($can_new && count($existing_tables) > 0)
                <fieldset class="exm-builder-modes">
                    <legend class="sr-only">{{ exmtrans('studio.mode') }}</legend>
                    <label class="exm-builder-mode">
                        <input type="radio" name="mode" value="new" @if ($mode === 'new') checked @endif>
                        <span><strong>{{ exmtrans('studio.mode_new') }}</strong><small>{{ exmtrans('studio.mode_new_help') }}</small></span>
                    </label>
                    <label class="exm-builder-mode">
                        <input type="radio" name="mode" value="existing" @if ($mode === 'existing') checked @endif>
                        <span><strong>{{ exmtrans('studio.mode_existing') }}</strong><small>{{ exmtrans('studio.mode_existing_help') }}</small></span>
                    </label>
                </fieldset>
            @else
                <input type="hidden" name="mode" value="{{ $mode }}">
            @endif

            <div class="exm-builder-name">
                <label for="exm-builder-view-name">{{ exmtrans('studio.screen_view_name') }} <span class="exm-builder-required">*</span></label>
                <input type="text" id="exm-builder-view-name" name="table_view_name" class="form-control" maxlength="40" required
                    value="{{ $screen['table_view_name'] ?? '' }}" placeholder="{{ exmtrans('studio.screen_view_name_placeholder') }}">
                @if (isset($errors_by_key['table_view_name']))<p class="exm-builder-error">{{ $errors_by_key['table_view_name'] }}</p>@endif
            </div>

            <div class="exm-builder-table" data-mode-only="existing">
                <label for="exm-builder-table-id">{{ exmtrans('studio.target_table') }} <span class="exm-builder-required">*</span></label>
                <select id="exm-builder-table-id" name="table_id" class="form-control">
                    <option value="">{{ exmtrans('studio.target_table_placeholder') }}</option>
                    @foreach ($existing_tables as $table)
                        <option value="{{ $table['id'] }}" @if (($screen['table_id'] ?? null) == $table['id']) selected @endif>{{ $table['table_view_name'] }}</option>
                    @endforeach
                </select>
                @if (isset($errors_by_key['table_id']))<p class="exm-builder-error">{{ $errors_by_key['table_id'] }}</p>@endif
            </div>

            <div class="exm-builder-sheet" data-mode-only="new">
                <div class="exm-builder-sheet-title"><i class="fa fa-file-excel-o" aria-hidden="true"></i> {{ exmtrans('studio.sheet_title') }}</div>
                <div class="exm-builder-sheet-row">
                    <input type="file" id="exm-builder-sheet-file" accept=".xlsx,.csv" aria-label="{{ exmtrans('studio.sheet_title') }}">
                    <button type="button" class="btn btn-default btn-sm" id="exm-builder-sheet-read">{{ exmtrans('studio.sheet_button') }}</button>
                </div>
                <p class="exm-builder-hint">{{ sprintf(exmtrans('studio.sheet_help'), $sheet_max_rows) }}</p>
                <p class="exm-builder-sheet-result" id="exm-builder-sheet-result" aria-live="polite"></p>
                <label class="exm-builder-import" id="exm-builder-import" hidden>
                    <input type="checkbox" name="import_data" value="1" id="exm-builder-import-data" @if (old('import_data')) checked @endif>
                    <span id="exm-builder-import-label"></span>
                </label>
                <input type="hidden" name="sheet_token" id="exm-builder-sheet-token" value="{{ old('sheet_token') }}">
                <input type="hidden" name="sheet_rows" id="exm-builder-sheet-rows" value="{{ old('sheet_rows') }}">
                <input type="hidden" name="sheet_file_name" id="exm-builder-sheet-name" value="{{ old('sheet_file_name') }}">
                @if (isset($errors_by_key['sheet']))<p class="exm-builder-error">{{ $errors_by_key['sheet'] }}</p>@endif
            </div>

            <details class="exm-builder-detail" data-mode-only="new" @if (isset($errors_by_key['table_name']) || isset($errors_by_key['menu_parent_id'])) open @endif>
                <summary>{{ exmtrans('studio.detail_setting') }}</summary>
                <div class="exm-builder-detail-body">
                    <div>
                        <label for="exm-builder-table-name">{{ exmtrans('studio.table_name') }}</label>
                        <input type="text" id="exm-builder-table-name" name="table_name" class="form-control" maxlength="30"
                            value="{{ $screen['table_name'] ?? '' }}" placeholder="{{ exmtrans('studio.auto_name') }}">
                        <p class="exm-builder-hint">{{ exmtrans('studio.table_name_help') }}</p>
                        @if (isset($errors_by_key['table_name']))<p class="exm-builder-error">{{ $errors_by_key['table_name'] }}</p>@endif
                    </div>
                    <div>
                        <label for="exm-builder-menu">{{ exmtrans('studio.menu_parent') }}</label>
                        <select id="exm-builder-menu" name="menu_parent_id" class="form-control">
                            <option value="">{{ exmtrans('studio.menu_none') }}</option>
                            @foreach ($menu_parents as $menu)
                                <option value="{{ $menu['id'] }}" @if (isset($screen['menu_parent_id']) && $screen['menu_parent_id'] == $menu['id']) selected @endif>{{ $menu['title'] }}</option>
                            @endforeach
                        </select>
                        @if (isset($errors_by_key['menu_parent_id']))<p class="exm-builder-error">{{ $errors_by_key['menu_parent_id'] }}</p>@endif
                    </div>
                </div>
            </details>
        </div>
    </div>

    <div class="exm-builder-main">
        <div class="box card exm-builder-palette">
            <div class="box-header with-border">{{ exmtrans('studio.palette') }}</div>
            <div class="box-body">
                <p class="exm-builder-hint" data-mode-only="new">{{ exmtrans('studio.palette_help') }}</p>
                <p class="exm-builder-hint" data-mode-only="existing">{{ exmtrans('studio.palette_existing_help') }}</p>
                <div class="exm-builder-parts" data-mode-only="new">
                    @foreach ($palette as $part)
                        <button type="button" class="btn btn-default exm-builder-part" draggable="true" data-type="{{ $part['type'] }}">
                            <i class="fa {{ $part['icon'] }}" aria-hidden="true"></i> {{ $part['label'] }}
                        </button>
                    @endforeach
                </div>
                <div class="exm-builder-parts" id="exm-builder-columns" data-mode-only="existing"></div>
            </div>
        </div>

        <div class="box card exm-builder-canvas-box">
            <div class="box-header with-border">{{ exmtrans('studio.canvas') }}</div>
            <div class="box-body">
                @if (isset($errors_by_key['fields']))<p class="exm-builder-error">{{ $errors_by_key['fields'] }}</p>@endif
                <ol class="exm-builder-canvas" id="exm-builder-canvas" aria-label="{{ exmtrans('studio.canvas') }}"></ol>
                <div class="exm-builder-drop" id="exm-builder-drop">{{ exmtrans('studio.drop_here') }}</div>
            </div>
        </div>
    </div>

    <div class="exm-builder-actions">
        <p class="exm-builder-notice" id="exm-builder-notice" aria-live="polite"></p>
        <a href="{{ $back_url }}" class="btn btn-default">{{ trans('admin.back') }}</a>
        <button type="submit" class="btn btn-primary">{{ exmtrans('studio.create_submit') }}</button>
    </div>
</form>

<style>
.exm-builder [hidden] { display: none !important; }
.exm-builder-head { margin-bottom: 12px; }
.exm-builder-modes { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 8px; border: 0; padding: 0; margin: 0 0 12px; }
.exm-builder-mode { display: flex; gap: 10px; align-items: flex-start; border: 1px solid #d2d6de; border-radius: 4px; padding: 10px 12px; cursor: pointer; font-weight: normal; margin: 0; }
.exm-builder-mode:has(input:checked) { border-color: #3c8dbc; box-shadow: 0 0 0 1px #3c8dbc; }
.exm-builder-mode input { margin-top: 4px; }
.exm-builder-mode span { display: flex; flex-direction: column; gap: 2px; }
.exm-builder-mode small { opacity: .75; }
.exm-builder-name label, .exm-builder-detail label, .exm-builder-table label { font-weight: 600; }
.exm-builder-name input { font-size: 1.1rem; }
.exm-builder-table { margin-top: 10px; }
.exm-builder-required { color: #dd4b39; }
.exm-builder-detail { margin-top: 10px; }
.exm-builder-sheet { margin-top: 12px; padding: 10px 12px; border: 1px dashed #d2d6de; border-radius: 4px; }
.exm-builder-sheet-title { font-weight: 600; margin-bottom: 6px; }
.exm-builder-sheet-title i { color: #1d6f42; }
.exm-builder-sheet-row { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.exm-builder-sheet-row input[type=file] { max-width: 100%; }
.exm-builder-sheet-result { margin: 6px 0 0; font-size: .9rem; }
.exm-builder-sheet-result.is-error { color: #dd4b39; }
.exm-builder-import { display: block; margin: 6px 0 0; font-weight: normal; }
.exm-builder-source { font-size: .75rem; opacity: .65; white-space: nowrap; }
.exm-builder-detail summary { cursor: pointer; font-size: .9rem; opacity: .8; }
.exm-builder-detail-body { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 12px; margin-top: 8px; }
.exm-builder-hint { font-size: .8rem; opacity: .7; margin: 4px 0 0; }
.exm-builder-error { color: #dd4b39; font-size: .85rem; margin: 4px 0 0; }
.exm-builder-main { display: grid; grid-template-columns: 240px 1fr; gap: 12px; align-items: start; }
@media (max-width: 767px) { .exm-builder-main { grid-template-columns: 1fr; } }
.exm-builder-palette, .exm-builder-canvas-box { margin: 0; }
.exm-builder-palette { position: sticky; top: 60px; }
.exm-builder-parts { display: grid; grid-template-columns: 1fr; gap: 6px; margin-top: 8px; }
@media (max-width: 767px) { .exm-builder-parts { grid-template-columns: repeat(2, 1fr); } .exm-builder-palette { position: static; } }
.exm-builder-part { text-align: left; cursor: grab; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.exm-builder-part i { width: 18px; text-align: center; opacity: .75; }
.exm-builder-part:disabled { cursor: default; opacity: .45; }
.exm-builder-canvas { list-style: none; padding: 0; margin: 0; }
.exm-builder-field { border: 1px solid #d2d6de; border-radius: 4px; padding: 10px 12px; margin-bottom: 8px; background: #fff; }
.exm-builder-field.is-dragging { opacity: .4; }
.exm-builder-field.is-over { border-top: 3px solid #3c8dbc; }
.exm-builder-field-head { display: flex; align-items: center; gap: 8px; }
.exm-builder-grip { cursor: grab; opacity: .5; }
.exm-builder-type { font-size: .75rem; opacity: .7; white-space: nowrap; }
.exm-builder-label { flex: 1; min-width: 0; }
.exm-builder-label-text { flex: 1; min-width: 0; font-weight: 600; overflow-wrap: anywhere; }
.exm-builder-tools { display: flex; gap: 2px; }
.exm-builder-tools button { border: 0; background: none; padding: 2px 6px; opacity: .6; }
.exm-builder-tools button:hover { opacity: 1; }
.exm-builder-tools button:disabled { opacity: .2; }
.exm-builder-field-body { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 8px 12px; margin-top: 8px; }
@media (max-width: 767px) { .exm-builder-field-body { grid-template-columns: 1fr; } }
.exm-builder-field-body label { font-size: .8rem; font-weight: normal; opacity: .8; margin-bottom: 2px; }
.exm-builder-preview { pointer-events: none; }
.exm-builder-preview .form-control { background: #f9fafb; }
.exm-builder-choices { grid-column: 1 / -1; }
.exm-builder-drop { border: 2px dashed #d2d6de; border-radius: 4px; padding: 18px; text-align: center; opacity: .7; }
.exm-builder-drop.is-over { border-color: #3c8dbc; opacity: 1; }
.exm-builder-actions { display: flex; justify-content: flex-end; align-items: center; flex-wrap: wrap; gap: 8px; margin: 12px 0 24px; }
.exm-builder-notice { margin: 0 auto 0 0; font-size: .9rem; }
.exm-builder-notice i { color: #3c8dbc; margin-right: 4px; }
.exm-builder-warning { display: block; color: #a94442; margin-top: 4px; }
.exm-builder-warning i { color: #e08e0b; }
.exm-builder-required-mark { margin-left: 6px; font-size: .7rem; padding: 0 5px; border-radius: 8px; border: 1px solid #dd4b39; color: #dd4b39; }
</style>

@php
    $texts = [
        'label' => exmtrans('studio.field_label'),
        'column_name' => exmtrans('studio.column_name'),
        'required' => exmtrans('studio.required'),
        'choices' => exmtrans('studio.choices'),
        'choices_help' => exmtrans('studio.choices_help'),
        'auto' => exmtrans('studio.auto_name'),
        'up' => exmtrans('studio.move_up'),
        'down' => exmtrans('studio.move_down'),
        'remove' => exmtrans('studio.remove'),
        'preview' => exmtrans('studio.preview'),
        'yes' => exmtrans('common.yes'),
        'no' => exmtrans('common.no'),
        'too_many' => sprintf(exmtrans('studio.error.too_many_fields'), $max_fields),
        'notice_new' => exmtrans('studio.notice_new'),
        'notice_new_unnamed' => exmtrans('studio.notice_new_unnamed'),
        'notice_existing' => exmtrans('studio.notice_existing'),
        'notice_existing_none' => exmtrans('studio.notice_existing_none'),
        'confirm_clear' => exmtrans('studio.confirm_clear'),
        'no_column' => exmtrans('studio.no_column'),
        'missing_required' => exmtrans('studio.missing_required'),
        'sheet_loaded' => exmtrans('studio.sheet_loaded'),
        'sheet_truncated' => exmtrans('studio.sheet_truncated'),
        'sheet_reading' => exmtrans('studio.sheet_reading'),
        'sheet_choose' => exmtrans('studio.error.sheet_file'),
        'sheet_failed' => exmtrans('studio.error.sheet_read'),
        'sheet_replace' => exmtrans('studio.sheet_replace'),
        'import_data' => exmtrans('studio.import_data'),
        'notice_import' => exmtrans('studio.notice_import'),
        'source' => exmtrans('studio.sheet_source'),
        'name_quote' => exmtrans('studio.name_quote'),
        'name_separator' => exmtrans('studio.name_separator'),
    ];
@endphp
<script>
(function () {
    var palette = @json($palette);
    var tables = @json($existing_tables);
    var initial = @json($screen ? $screen['fields'] : []);
    var errors = @json((object) $errors_by_key);
    var maxFields = {{ intval($max_fields) }};
    var t = @json($texts);
    var sheetUrl = @json($sheet_url);

    var form = document.getElementById('exm-builder');
    var canvas = document.getElementById('exm-builder-canvas');
    var drop = document.getElementById('exm-builder-drop');
    var columnsBox = document.getElementById('exm-builder-columns');
    var tableSelect = document.getElementById('exm-builder-table-id');
    var nameInput = document.getElementById('exm-builder-view-name');
    var notice = document.getElementById('exm-builder-notice');

    var types = {};
    palette.forEach(function (p) { types[p.type] = p; });

    function currentMode() {
        var checked = form.querySelector('input[name="mode"]:checked') || form.querySelector('input[name="mode"]');
        return checked ? checked.value : 'new';
    }
    function currentTable() {
        var id = parseInt(tableSelect.value, 10);
        for (var i = 0; i < tables.length; i++) { if (tables[i].id === id) { return tables[i]; } }
        return null;
    }
    function columnOf(id) {
        var table = currentTable();
        if (!table) { return null; }
        for (var i = 0; i < table.columns.length; i++) { if (table.columns[i].id === id) { return table.columns[i]; } }
        return null;
    }

    var mode = currentMode();
    // an item: {type, label, column_name, required, choices} for a new table, {column_id} for a table that exists
    var fields = (initial || []).map(function (f, i) {
        var item = mode === 'existing'
            ? { column_id: f.column_id }
            : { type: f.type, label: f.label, column_name: f.column_name, required: !!f.required, choices: f.choices || '', source: f.source === undefined ? null : f.source };
        item.error = i;
        return item;
    });
    var dragFrom = null; // index of an item being moved, or null for a new part

    function el(tag, attrs, children) {
        var node = document.createElement(tag);
        Object.keys(attrs || {}).forEach(function (k) {
            if (k === 'text') { node.textContent = attrs[k]; }
            else if (k === 'className') { node.className = attrs[k]; }
            else { node.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { node.appendChild(c); } });
        return node;
    }

    function errorOf(field, name) {
        if (field.error === null || field.error === undefined) { return null; }
        var message = errors['fields.' + field.error + '.' + name];
        return message ? el('p', { className: 'exm-builder-error', text: message }) : null;
    }

    function preview(type, choices) {
        var box = el('div', { className: 'exm-builder-preview', 'aria-hidden': 'true' });
        var input;
        switch (type) {
            case 'textarea': case 'editor': input = el('textarea', { className: 'form-control', rows: '2', tabindex: '-1' }); break;
            case 'select':
                input = el('select', { className: 'form-control', tabindex: '-1' });
                (choices || '').split('\n').filter(function (c) { return c.trim() !== ''; }).forEach(function (c) {
                    input.appendChild(el('option', { text: c.trim() }));
                });
                break;
            case 'yesno': case 'boolean':
                input = el('div', {}, [el('label', { text: '◉ ' + t.yes + '　' }), el('label', { text: '○ ' + t.no })]);
                break;
            case 'user': case 'organization': case 'select_table': case 'select_valtext':
                input = el('select', { className: 'form-control', tabindex: '-1' }); break;
            case 'file': case 'image': input = el('input', { type: 'file', className: 'form-control', tabindex: '-1' }); break;
            default:
                var kinds = { integer: 'number', decimal: 'number', currency: 'number', date: 'date', datetime: 'datetime-local', time: 'time', email: 'email', url: 'url' };
                input = el('input', { type: kinds[type] || 'text', className: 'form-control', tabindex: '-1' });
        }
        box.appendChild(input);
        return box;
    }

    function tools(index) {
        var up = el('button', { type: 'button', title: t.up, 'aria-label': t.up }, [el('i', { className: 'fa fa-arrow-up' })]);
        var down = el('button', { type: 'button', title: t.down, 'aria-label': t.down }, [el('i', { className: 'fa fa-arrow-down' })]);
        var remove = el('button', { type: 'button', title: t.remove, 'aria-label': t.remove }, [el('i', { className: 'fa fa-trash' })]);
        up.disabled = index === 0;
        down.disabled = index === fields.length - 1;
        up.addEventListener('click', function () { move(index, index - 1); });
        down.addEventListener('click', function () { move(index, index + 2); });
        remove.addEventListener('click', function () { fields.splice(index, 1); render(); });
        return el('span', { className: 'exm-builder-tools' }, [up, down, remove]);
    }

    function newItem(field, index) {
        var part = types[field.type] || { label: field.type, icon: 'fa-question', choices: false };
        var li = el('li', { className: 'exm-builder-field', draggable: 'true' });

        var label = el('input', { type: 'text', className: 'form-control exm-builder-label', maxlength: '40', placeholder: t.label, 'aria-label': t.label });
        label.value = field.label;
        label.addEventListener('input', function () { field.label = label.value; });

        li.appendChild(el('div', { className: 'exm-builder-field-head' }, [
            el('i', { className: 'fa fa-bars exm-builder-grip', 'aria-hidden': 'true' }),
            el('span', { className: 'exm-builder-type' }, [el('i', { className: 'fa ' + part.icon, 'aria-hidden': 'true' }), document.createTextNode(' ' + part.label)]),
            label,
            field.source !== null && field.source !== undefined ? el('span', { className: 'exm-builder-source', text: t.source.replace('%s', columnLetter(field.source)) }) : null,
            tools(index),
        ]));
        var labelError = errorOf(field, 'label') || errorOf(field, 'type');
        if (labelError) { li.appendChild(labelError); }

        var name = el('input', { type: 'text', className: 'form-control input-sm', maxlength: '30', placeholder: t.auto });
        name.value = field.column_name;
        name.addEventListener('input', function () { field.column_name = name.value; });
        var required = el('input', { type: 'checkbox' });
        required.checked = field.required;
        required.addEventListener('change', function () { field.required = required.checked; });

        var previewBox = el('div', {}, [el('label', { text: t.preview }), preview(field.type, field.choices)]);
        var body = el('div', { className: 'exm-builder-field-body' }, [
            previewBox,
            el('div', {}, [
                el('label', { text: t.column_name }), name, errorOf(field, 'column_name'),
                el('label', {}, [required, document.createTextNode(' ' + t.required)]),
            ]),
        ]);
        if (part.choices) {
            var choices = el('textarea', { className: 'form-control', rows: '3', placeholder: t.choices_help });
            choices.value = field.choices;
            // only this item's preview is drawn again: drawing the whole list would
            // swallow the click that took the focus away from here
            choices.addEventListener('input', function () {
                field.choices = choices.value;
                previewBox.replaceChild(preview(field.type, field.choices), previewBox.lastChild);
            });
            body.appendChild(el('div', { className: 'exm-builder-choices' }, [el('label', { text: t.choices }), choices, errorOf(field, 'choices')]));
        }
        li.appendChild(body);
        return li;
    }

    function existingItem(field, index) {
        var column = columnOf(field.column_id) || { column_view_name: '#' + field.column_id, type: '', type_label: '', icon: 'fa-question' };
        var li = el('li', { className: 'exm-builder-field', draggable: 'true' });
        li.appendChild(el('div', { className: 'exm-builder-field-head' }, [
            el('i', { className: 'fa fa-bars exm-builder-grip', 'aria-hidden': 'true' }),
            el('span', { className: 'exm-builder-type' }, [el('i', { className: 'fa ' + column.icon, 'aria-hidden': 'true' }), document.createTextNode(' ' + column.type_label)]),
            el('span', { className: 'exm-builder-label-text', text: column.column_view_name }),
            tools(index),
        ]));
        var error = errorOf(field, 'column_id');
        if (error) { li.appendChild(error); }
        li.appendChild(el('div', { className: 'exm-builder-field-body' }, [
            el('div', {}, [el('label', { text: t.preview }), preview(column.type, '')]),
        ]));
        return li;
    }

    function render() {
        canvas.innerHTML = '';
        fields.forEach(function (field, index) {
            var li = mode === 'existing' ? existingItem(field, index) : newItem(field, index);
            li.addEventListener('dragstart', function (e) {
                if (e.target !== li) { return; }
                dragFrom = index;
                li.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', 'item');
            });
            li.addEventListener('dragend', function () { li.classList.remove('is-dragging'); });
            li.addEventListener('dragover', function (e) { e.preventDefault(); li.classList.add('is-over'); });
            li.addEventListener('dragleave', function () { li.classList.remove('is-over'); });
            li.addEventListener('drop', function (e) {
                e.preventDefault();
                li.classList.remove('is-over');
                dropAt(e, index);
            });
            canvas.appendChild(li);
        });
        renderColumns();
        renderNotice();
    }

    // the columns of the chosen table, as parts; a column already placed is greyed out
    function renderColumns() {
        columnsBox.innerHTML = '';
        if (mode !== 'existing') { return; }
        var table = currentTable();
        if (!table) { return; }
        if (table.columns.length === 0) {
            columnsBox.appendChild(el('p', { className: 'exm-builder-hint', text: t.no_column }));
            return;
        }
        var placed = {};
        fields.forEach(function (f) { placed[f.column_id] = true; });
        table.columns.forEach(function (column) {
            var button = el('button', { type: 'button', className: 'btn btn-default exm-builder-part', draggable: 'true', title: column.column_view_name },
                [el('i', { className: 'fa ' + column.icon, 'aria-hidden': 'true' }), document.createTextNode(' ' + column.column_view_name)]);
            if (column.required) {
                button.appendChild(el('span', { className: 'exm-builder-required-mark', text: t.required }));
            }
            button.disabled = !!placed[column.id];
            button.addEventListener('click', function () { addColumn(column.id); });
            button.addEventListener('dragstart', function (e) {
                dragFrom = null;
                e.dataTransfer.effectAllowed = 'copy';
                e.dataTransfer.setData('application/x-exm-column', String(column.id));
            });
            columnsBox.appendChild(button);
        });
    }

    // what saving will make: a new table, or a new screen of the chosen table
    function renderNotice() {
        notice.innerHTML = '';
        var text;
        if (mode === 'existing') {
            var table = currentTable();
            text = table ? t.notice_existing.replace('%s', table.table_view_name) : t.notice_existing_none;
        } else {
            var name = nameInput.value.trim();
            text = name ? t.notice_new.replace('%s', name) : t.notice_new_unnamed;
            if (importBox.checked && tokenInput.value) {
                text += t.notice_import.replace('%s', rowsInput.value);
            }
        }
        notice.appendChild(el('i', { className: 'fa fa-info-circle', 'aria-hidden': 'true' }));
        notice.appendChild(document.createTextNode(text));

        // the required columns left out are saved empty from this screen: say so
        if (mode === 'existing' && currentTable()) {
            var placed = {};
            fields.forEach(function (f) { placed[f.column_id] = true; });
            var missing = currentTable().columns.filter(function (c) { return c.required && !placed[c.id]; })
                .map(function (c) { return t.name_quote.replace('%s', c.column_view_name); });
            if (missing.length > 0) {
                notice.appendChild(el('span', { className: 'exm-builder-warning' }, [
                    el('i', { className: 'fa fa-exclamation-triangle', 'aria-hidden': 'true' }),
                    document.createTextNode(t.missing_required.replace('%s', missing.join(t.name_separator))),
                ]));
            }
        }
    }

    function showMode() {
        form.querySelectorAll('[data-mode-only]').forEach(function (node) {
            node.hidden = node.getAttribute('data-mode-only') !== mode;
        });
    }

    function move(from, to) {
        var item = fields.splice(from, 1)[0];
        if (to > from) { to--; }
        fields.splice(Math.max(0, Math.min(to, fields.length)), 0, item);
        render();
    }

    function insert(item, at) {
        if (fields.length >= maxFields) { alert(t.too_many); return false; }
        fields.splice(at === undefined ? fields.length : at, 0, item);
        render();
        return true;
    }

    function add(type, at) {
        if (!insert({ type: type, label: types[type].label, column_name: '', required: false, choices: '', source: null, error: null }, at)) { return; }
        var inputs = canvas.querySelectorAll('.exm-builder-label');
        var target = inputs[at === undefined ? fields.length - 1 : at];
        if (target) { target.focus(); target.select(); }
    }

    function addColumn(id, at) {
        for (var i = 0; i < fields.length; i++) { if (fields[i].column_id === id) { return; } }
        insert({ column_id: id, error: null }, at);
    }

    function dropAt(e, at) {
        var type = e.dataTransfer.getData('application/x-exm-part');
        var column = e.dataTransfer.getData('application/x-exm-column');
        if (mode === 'new' && type && types[type]) { add(type, at); }
        else if (mode === 'existing' && column) { addColumn(parseInt(column, 10), at); }
        else if (dragFrom !== null) { move(dragFrom, at); }
        dragFrom = null;
    }

    // switching the way or the table starts the screen again, after asking when items are placed
    function restart(apply, undo) {
        if (fields.length > 0 && !confirm(t.confirm_clear)) { undo(); return; }
        apply();
        fields = [];
        errors = {};
        forgetSheet();
        showMode();
        render();
    }
    form.querySelectorAll('input[name="mode"][type="radio"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var before = mode;
            restart(function () { mode = radio.value; }, function () {
                form.querySelector('input[name="mode"][value="' + before + '"]').checked = true;
            });
        });
    });
    var tableBefore = tableSelect.value;
    tableSelect.addEventListener('change', function () {
        restart(function () { tableBefore = tableSelect.value; }, function () { tableSelect.value = tableBefore; });
    });
    nameInput.addEventListener('input', renderNotice);

    document.querySelectorAll('.exm-builder-part[data-type]').forEach(function (button) {
        button.addEventListener('click', function () { add(button.dataset.type); });
        button.addEventListener('dragstart', function (e) {
            dragFrom = null;
            e.dataTransfer.effectAllowed = 'copy';
            e.dataTransfer.setData('application/x-exm-part', button.dataset.type);
        });
    });
    drop.addEventListener('dragover', function (e) { e.preventDefault(); drop.classList.add('is-over'); });
    drop.addEventListener('dragleave', function () { drop.classList.remove('is-over'); });
    drop.addEventListener('drop', function (e) { e.preventDefault(); drop.classList.remove('is-over'); dropAt(e, fields.length); });

    form.addEventListener('submit', function () {
        document.getElementById('exm-builder-fields').value = JSON.stringify(fields.map(function (f) {
            return mode === 'existing'
                ? { column_id: f.column_id }
                : { type: f.type, label: f.label, column_name: f.column_name, required: f.required, choices: f.choices, source: f.source };
        }));
    });

    // Excel or CSV -------------------------------------------------------------
    var fileInput = document.getElementById('exm-builder-sheet-file');
    var readButton = document.getElementById('exm-builder-sheet-read');
    var resultLine = document.getElementById('exm-builder-sheet-result');
    var importWrap = document.getElementById('exm-builder-import');
    var importBox = document.getElementById('exm-builder-import-data');
    var importLabel = document.getElementById('exm-builder-import-label');
    var tokenInput = document.getElementById('exm-builder-sheet-token');
    var rowsInput = document.getElementById('exm-builder-sheet-rows');
    var sheetNameInput = document.getElementById('exm-builder-sheet-name');

    function columnLetter(index) {
        var n = index + 1, letters = '';
        while (n > 0) { var m = (n - 1) % 26; letters = String.fromCharCode(65 + m) + letters; n = Math.floor((n - 1) / 26); }
        return letters;
    }

    function showSheet(message, isError) {
        resultLine.textContent = message || '';
        resultLine.classList.toggle('is-error', !!isError);
        var hasRows = !!tokenInput.value && parseInt(rowsInput.value || '0', 10) > 0;
        importWrap.hidden = !hasRows;
        importLabel.textContent = t.import_data.replace('%s', rowsInput.value || '0');
        renderNotice();
    }

    function forgetSheet() {
        tokenInput.value = '';
        rowsInput.value = '';
        sheetNameInput.value = '';
        importBox.checked = false;
        showSheet('', false);
    }

    readButton.addEventListener('click', function () {
        var file = fileInput.files && fileInput.files[0];
        if (!file) { showSheet(t.sheet_choose, true); return; }
        if (fields.length > 0 && !confirm(t.sheet_replace)) { return; }
        var body = new FormData();
        body.append('sheet', file);
        body.append('_token', form.querySelector('input[name="_token"]').value);
        readButton.disabled = true;
        showSheet(t.sheet_reading, false);
        fetch(sheetUrl, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                readButton.disabled = false;
                if (!data || data.result !== true) {
                    // only the messages of the reader itself are shown; a server error is not
                    forgetSheet();
                    showSheet(data && data.result === false && data.message ? data.message : t.sheet_failed, true);
                    return;
                }
                fields = data.fields.map(function (f) {
                    return { type: f.type, label: f.label, column_name: '', required: false, choices: f.choices || '', source: f.source, error: null };
                });
                errors = {};
                tokenInput.value = data.token;
                rowsInput.value = String(data.row_count);
                sheetNameInput.value = data.file_name;
                importBox.checked = data.row_count > 0;
                if (!nameInput.value.trim()) { nameInput.value = data.file_name.replace(/\.[^.]+$/, '').slice(0, 40); }
                var message = t.sheet_loaded.replace('%1$s', data.file_name).replace('%2$s', data.column_count).replace('%3$s', data.row_count);
                if (data.truncated) { message += t.sheet_truncated.replace('%s', data.row_count); }
                render();
                showSheet(message, false);
            })
            .catch(function () { readButton.disabled = false; showSheet(t.sheet_failed, true); });
    });
    importBox.addEventListener('change', renderNotice);

    showMode();
    render();
    // after a mistake the page comes back with the sheet still read
    if (tokenInput.value) {
        showSheet(t.sheet_loaded.replace('%1$s', sheetNameInput.value).replace('%2$s', fields.length).replace('%3$s', rowsInput.value), false);
    }
})();
</script>
