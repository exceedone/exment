{{-- Studio, phase 2: make an input screen, for a new table or for a table that exists. Saved by StudioController@store. --}}
<form method="post" action="{{ $action }}" class="exm-builder" id="exm-builder">
    {{ csrf_field() }}
    <input type="hidden" name="fields" id="exm-builder-fields" value="">
    <input type="hidden" name="layout" id="exm-builder-layout" value="">
    <input type="hidden" name="children" id="exm-builder-children-input" value="">
    <input type="hidden" name="actions" id="exm-builder-actions-input" value="">
    <input type="hidden" name="bundle" id="exm-builder-bundle-input" value="">

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

            @if ($edit)
                <div class="exm-builder-edit-info">
                    <span><i class="fa fa-table" aria-hidden="true"></i> {{ exmtrans('studio.edit_table') }}: <strong>{{ $edit['table_view_name'] }}</strong></span>
                    <a href="{{ $edit['data_url'] }}" target="_blank" rel="noopener"><i class="fa fa-external-link" aria-hidden="true"></i> {{ exmtrans('studio.edit_open_data') }}</a>
                    <a href="{{ $edit['form_url'] }}"><i class="fa fa-cog" aria-hidden="true"></i> {{ exmtrans('studio.form_setting') }}</a>
                </div>
                <p class="exm-builder-hint">{{ exmtrans('studio.edit_help') }}</p>
                @if ($edit['adjusted'])<p class="exm-builder-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> {{ exmtrans('studio.edit_adjusted') }}</p>@endif
                @if ($edit['has_details'])<p class="exm-builder-hint"><i class="fa fa-table" aria-hidden="true"></i> {{ exmtrans('studio.edit_details_kept') }}</p>@endif
            @endif

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
                <p class="exm-builder-hint" data-mode-only="existing" data-in-edit>{{ exmtrans('studio.palette_existing_help') }}</p>
                <div class="exm-builder-parts" id="exm-builder-columns" data-mode-only="existing" data-in-edit></div>
                @if ($edit && $edit['can_add_columns'])
                    <p class="exm-builder-hint exm-builder-new-parts-title" data-mode-only="new" data-in-edit>{{ exmtrans('studio.edit_new_parts') }}</p>
                @endif
                <div class="exm-builder-parts" data-mode-only="new" @if ($edit && $edit['can_add_columns']) data-in-edit @endif>
                    @foreach ($palette as $part)
                        @if ($edit && !in_array($part['type'], $edit['new_types'], true)) @continue @endif
                        <button type="button" class="btn btn-default exm-builder-part" draggable="true" data-type="{{ $part['type'] }}">
                            <i class="fa {{ $part['icon'] }}" aria-hidden="true"></i> {{ $part['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="box card exm-builder-canvas-box">
            <div class="box-header with-border">{{ exmtrans('studio.canvas') }}</div>
            <div class="box-body">
                @if (isset($errors_by_key['fields']))<p class="exm-builder-error">{{ $errors_by_key['fields'] }}</p>@endif
                @if (isset($errors_by_key['layout']))<p class="exm-builder-error">{{ $errors_by_key['layout'] }}</p>@endif
                <p class="exm-builder-hint">{{ exmtrans('studio.layout_help') }}</p>
                <div class="exm-builder-canvas" id="exm-builder-canvas" aria-label="{{ exmtrans('studio.canvas') }}"></div>
                <button type="button" class="btn btn-default btn-sm exm-builder-add-row" id="exm-builder-add-row"><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('studio.add_row') }}</button>

                <div class="exm-builder-children" data-mode-only="new">
                    <div class="exm-builder-children-title"><i class="fa fa-table" aria-hidden="true"></i> {{ exmtrans('studio.children_title') }}</div>
                    <p class="exm-builder-hint">{{ sprintf(exmtrans('studio.children_help'), $max_children) }}</p>
                    @if (isset($errors_by_key['children']))<p class="exm-builder-error">{{ $errors_by_key['children'] }}</p>@endif
                    <div id="exm-builder-children"></div>
                    <button type="button" class="btn btn-default btn-sm" id="exm-builder-add-child"><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('studio.add_child') }}</button>
                </div>

                {{-- the buttons of the screen (phase 4, ScreenAction): "Done" sets the status to "done" --}}
                <div class="exm-builder-buttons" id="exm-builder-buttons-box" data-in-edit>
                    <div class="exm-builder-children-title"><i class="fa fa-hand-pointer-o" aria-hidden="true"></i> {{ exmtrans('studio.buttons_title') }}</div>
                    <p class="exm-builder-hint">{{ sprintf(exmtrans('studio.buttons_help'), $max_actions) }}</p>
                    @if (isset($errors_by_key['actions']))<p class="exm-builder-error">{{ $errors_by_key['actions'] }}</p>@endif
                    <div id="exm-builder-buttons"></div>
                    <button type="button" class="btn btn-default btn-sm" id="exm-builder-add-button"><i class="fa fa-plus" aria-hidden="true"></i> {{ exmtrans('studio.add_button') }}</button>
                </div>

                {{-- the app made in one go (ScreenBundle): a kanban, a calendar, a chart, a dashboard --}}
                <div class="exm-builder-buttons" id="exm-builder-bundle-box" data-mode-only="new">
                    <div class="exm-builder-children-title"><i class="fa fa-th-large" aria-hidden="true"></i> {{ exmtrans('studio.bundle_title') }}</div>
                    <p class="exm-builder-hint">{{ exmtrans('studio.bundle_help') }}</p>
                    @foreach (['kanban', 'calendar', 'chart', 'dashboard'] as $part)
                        @if (isset($errors_by_key['bundle.' . $part]))<p class="exm-builder-error">{{ $errors_by_key['bundle.' . $part] }}</p>@endif
                    @endforeach
                    <div id="exm-builder-bundle"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="exm-builder-actions">
        <p class="exm-builder-notice" id="exm-builder-notice" aria-live="polite"></p>
        <a href="{{ $back_url }}" class="btn btn-default">{{ trans('admin.back') }}</a>
        <button type="submit" class="btn btn-primary">{{ $edit ? exmtrans('studio.save_submit') : exmtrans('studio.create_submit') }}</button>
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
.exm-builder-edit-info { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 16px; margin-top: 10px; }
.exm-builder-new-parts-title { margin-top: 12px; font-weight: 600; opacity: .8; }
.exm-builder-kept { background: #f7f8fa; }
.exm-builder-kept .exm-builder-label-text { font-weight: normal; opacity: .85; }
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
.exm-builder-condition { grid-column: 1 / -1; }
.exm-builder-chips { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.exm-builder-condition-row { display: flex; flex-wrap: wrap; gap: 6px; }
.exm-builder-condition-row .form-control { width: auto; min-width: 0; flex: 1 1 120px; }
.exm-builder-row { border: 1px solid #e3e6ea; border-radius: 4px; padding: 8px; margin-bottom: 10px; background: #fafbfc; }
.exm-builder-row-head { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 6px; font-size: .8rem; }
.exm-builder-row-head .exm-builder-row-name { font-weight: 600; opacity: .75; }
.exm-builder-row-head select { width: auto; height: 26px; padding: 0 6px; font-size: .8rem; }
.exm-builder-row-head .exm-builder-tools { margin-left: auto; }
.exm-builder-cells { display: grid; gap: 8px; }
@media (max-width: 767px) { .exm-builder-cells { grid-template-columns: 1fr !important; } }
.exm-builder-cell { min-width: 0; border: 1px dashed #c9ced6; border-radius: 4px; padding: 6px; background: #fff; cursor: pointer; }
.exm-builder-cell.is-active { border: 2px solid #3c8dbc; padding: 5px; }
.exm-builder-cell.is-over { background: #eef6fb; }
.exm-builder-cell ol { list-style: none; padding: 0; margin: 0; }
.exm-builder-cell .exm-builder-field { cursor: default; }
.exm-builder-cell-drop { text-align: center; font-size: .8rem; opacity: .6; padding: 8px 4px; }
.exm-builder-field-head { flex-wrap: wrap; }
.exm-builder-add-row { margin-top: 2px; }
.exm-builder-children { margin-top: 18px; padding-top: 12px; border-top: 1px solid #e3e6ea; }
.exm-builder-children-title { font-weight: 600; }
.exm-builder-child { border: 1px solid #d2d6de; border-radius: 4px; padding: 10px 12px; margin: 8px 0; background: #fff; }
.exm-builder-child-head { display: flex; align-items: center; gap: 8px; }
.exm-builder-child-head input { flex: 1; min-width: 0; }
.exm-builder-child-fields { list-style: none; padding: 0; margin: 8px 0 0; }
.exm-builder-child-field { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 4px 0; border-bottom: 1px dashed #eef0f2; }
.exm-builder-child-field .exm-builder-type { min-width: 110px; }
.exm-builder-child-field input[type=text] { flex: 1 1 140px; min-width: 0; }
.exm-builder-child-field .exm-builder-child-choices { flex: 1 1 100%; }
.exm-builder-child-add { display: flex; gap: 6px; margin-top: 6px; }
.exm-builder-child-add select { width: auto; }
.exm-builder-buttons { margin-top: 18px; padding-top: 12px; border-top: 1px solid #e3e6ea; }
.exm-builder-button { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; border: 1px solid #d2d6de; border-radius: 4px; padding: 8px 10px; margin: 8px 0; background: #fff; }
.exm-builder-button .form-control { width: auto; min-width: 0; flex: 1 1 140px; }
.exm-builder select.form-control.input-sm { height: auto; }
.exm-builder-button .exm-builder-button-word { font-size: .85rem; opacity: .8; white-space: nowrap; }
.exm-builder-button .exm-builder-error { flex: 1 1 100%; }
.exm-builder-bundle-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; padding: 6px 0; border-bottom: 1px dashed #eef0f2; }
.exm-builder-bundle-row > label { font-weight: 600; margin: 0; min-width: 150px; }
.exm-builder-bundle-row select.form-control { width: auto; min-width: 160px; flex: 0 1 260px; }
.exm-builder-bundle-row .exm-builder-hint { margin: 0; }
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
        'row' => exmtrans('studio.row'),
        'target_table' => exmtrans('studio.target_table_of_item'),
        'target_table_placeholder' => exmtrans('studio.target_table_placeholder'),
        'formula' => exmtrans('studio.formula'),
        'formula_help' => exmtrans('studio.formula_help'),
        'auto_number' => exmtrans('studio.auto_number_preview'),
        'sum_of' => exmtrans('studio.sum_of'),
        'child_name' => exmtrans('studio.child_name'),
        'child_name_placeholder' => exmtrans('studio.child_name_placeholder'),
        'add_child_field' => exmtrans('studio.add_child_field'),
        'remove_child' => exmtrans('studio.remove_child'),
        'notice_children' => exmtrans('studio.notice_children'),
        'too_many_children' => sprintf(exmtrans('studio.error.too_many_children'), $max_children),
        'condition' => exmtrans('studio.condition'),
        'condition_always' => exmtrans('studio.condition_always'),
        'condition_value' => exmtrans('studio.condition_value'),
        'condition_required' => exmtrans('studio.error.condition_required'),
        'operators' => [
            'eq' => exmtrans('studio.condition_operators.eq'),
            'ne' => exmtrans('studio.condition_operators.ne'),
            'empty' => exmtrans('studio.condition_operators.empty'),
            'not_empty' => exmtrans('studio.condition_operators.not_empty'),
        ],
        'row_layout' => exmtrans('studio.row_layout'),
        'row_up' => exmtrans('studio.row_up'),
        'row_down' => exmtrans('studio.row_down'),
        'row_remove' => exmtrans('studio.row_remove'),
        'cell_drop' => exmtrans('studio.cell_drop'),
        'presets' => [
            exmtrans('studio.layout_presets.1'),
            exmtrans('studio.layout_presets.2'),
            exmtrans('studio.layout_presets.2_left'),
            exmtrans('studio.layout_presets.2_right'),
            exmtrans('studio.layout_presets.3'),
            exmtrans('studio.layout_presets.4'),
        ],
        'name_separator' => exmtrans('studio.name_separator'),
        'button_label' => exmtrans('studio.button_label'),
        'button_label_placeholder' => exmtrans('studio.button_label_placeholder'),
        'button_target' => exmtrans('studio.button_target'),
        'button_target_placeholder' => exmtrans('studio.button_target_placeholder'),
        'button_value' => exmtrans('studio.button_value'),
        'button_to' => exmtrans('studio.button_to'),
        'button_set' => exmtrans('studio.button_set'),
        'remove_button' => exmtrans('studio.remove_button'),
        'no_button_target' => exmtrans('studio.no_button_target'),
        'notice_buttons' => exmtrans('studio.notice_buttons'),
        'too_many_buttons' => sprintf(exmtrans('studio.error.too_many_actions'), $max_actions),
        'kept_help' => exmtrans('studio.kept_help'),
        'bundle' => [
            'kanban' => [exmtrans('studio.bundle_kanban'), exmtrans('studio.bundle_kanban_by')],
            'calendar' => [exmtrans('studio.bundle_calendar'), exmtrans('studio.bundle_calendar_by')],
            'chart' => [exmtrans('studio.bundle_chart'), exmtrans('studio.bundle_chart_by')],
            'dashboard' => [exmtrans('studio.bundle_dashboard'), ''],
        ],
        'bundle_no_item' => exmtrans('studio.bundle_no_item'),
        'notice_bundle' => exmtrans('studio.notice_bundle'),
        'notice_edit' => exmtrans('studio.notice_edit'),
        'notice_new_columns' => exmtrans('studio.notice_new_columns'),
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
    var referenceTables = @json($reference_tables);
    var presets = @json($layout_presets);
    var initialLayout = @json($screen ? ($screen['layout'] ?? null) : null);
    var initialConditions = @json($screen ? (object) ($screen['conditions'] ?? []) : (object) []);
    var maxChildren = {{ intval($max_children) }};
    var maxButtons = {{ intval($max_actions) }};
    var buttonTypes = @json($action_types);
    var initialButtons = @json($old_actions);
    var editing = @json($edit);
    var bundleSources = @json($bundle_sources);
    var canDashboard = @json($can_dashboard);
    var initialBundle = @json($old_bundle);
    // the details of the screen: [{label, fields: [{type, label, required, choices}]}]
    var children = (@json($old_children) || []).map(function (c) {
        return { label: c.label || '', fields: (c.fields || []).map(function (f) {
            return { type: f.type, label: f.label || '', required: !!f.required, choices: f.choices || '', target_table: f.target_table || null, formula: f.formula || '' };
        }) };
    });

    var form = document.getElementById('exm-builder');
    var canvas = document.getElementById('exm-builder-canvas');
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
    // the rows of the screen: the widths of the columns of each (ScreenLayout::PRESETS)
    var rows = initialLayout && initialLayout.rows && initialLayout.rows.length ? initialLayout.rows.map(function (w) { return w.slice(); }) : [[4]];
    // an item: {type, label, column_name, required, choices} for a new table, {column_id} for a table that exists,
    // with the row and the column it is in. The list is kept in reading order: row by row,
    // in a row column by column, top to bottom.
    // A changed screen has the three kinds side by side: a column of the table ({column_id}), a new
    // part ({type, label...}) and an item kept as it is ({form_column_id, text, icon}: a heading...).
    var fields = (initial || []).map(function (f, i) {
        var item = f.column_id !== undefined && f.column_id !== null
            ? { column_id: f.column_id }
            : (f.form_column_id !== undefined && f.form_column_id !== null
                ? { form_column_id: f.form_column_id, text: f.text || '', icon: f.icon || 'fa-square-o' }
                : { type: f.type, label: f.label, column_name: f.column_name, required: !!f.required, choices: f.choices || '', source: f.source === undefined ? null : f.source,
                    target_table: f.target_table || null, formula: f.formula || '' });
        var place = initialLayout && initialLayout.places ? initialLayout.places[i] : null;
        item.row = place && rows[place.row] ? place.row : 0;
        item.col = place && rows[item.row][place.column] !== undefined ? place.column : 0;
        item.error = i;
        return item;
    });
    // a condition points at its item itself: moving or removing items does not break it
    Object.keys(initialConditions).forEach(function (index) {
        var c = initialConditions[index];
        if (fields[index] && fields[c.target]) {
            fields[index].cond = { target: fields[c.target], operator: c.operator, value: c.value };
        }
    });
    // the buttons of the screen: {label, target (an item), value}. A button points at its item itself.
    var buttons = (initialButtons || []).map(function (b) {
        return { label: b.label || '', target: fields[b.target] || null, value: b.value || '' };
    });
    // the parts of the app made with it: each on or off, and the item it is made from.
    // A new app gets them all when it has the items they need; after a mistake, what was posted.
    var bundle = {};
    ['kanban', 'calendar', 'chart'].forEach(function (part) {
        var index = initialBundle ? initialBundle[part] : null;
        bundle[part] = { on: initialBundle ? index !== null && index !== undefined : true, target: index !== null && index !== undefined ? (fields[index] || null) : null };
    });
    bundle.dashboard = { on: canDashboard && (initialBundle ? !!initialBundle.dashboard : true) };
    var active = { row: 0, col: 0 }; // the cell the parts are added to
    var dragFrom = null; // index of an item being moved, or null for a new part

    function sortFields() {
        fields = fields.map(function (f, i) { return { f: f, i: i }; })
            .sort(function (a, b) { return (a.f.row - b.f.row) || (a.f.col - b.f.col) || (a.i - b.i); })
            .map(function (x) { return x.f; });
    }
    function sameCell(a, b) { return a.row === b.row && a.col === b.col; }
    function presetIndex(widths) {
        for (var i = 0; i < presets.length; i++) { if (presets[i].join() === widths.join()) { return i; } }
        return 0;
    }

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
            case 'calc': input = el('input', { type: 'text', className: 'form-control', tabindex: '-1', placeholder: '= ' + (choices || '') }); break;
            case 'auto_number': input = el('input', { type: 'text', className: 'form-control', tabindex: '-1', placeholder: t.auto_number }); break;
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
        up.disabled = index === 0 || !sameCell(fields[index - 1], fields[index]);
        down.disabled = index === fields.length - 1 || !sameCell(fields[index + 1], fields[index]);
        up.addEventListener('click', function () { move(index, index - 1); });
        down.addEventListener('click', function () { move(index, index + 2); });
        remove.addEventListener('click', function () { fields.splice(index, 1); render(); });
        return el('span', { className: 'exm-builder-tools' }, [up, down, remove]);
    }

    function isColumn(field) { return field.column_id !== undefined; }
    function isKept(field) { return field.form_column_id !== undefined; }
    function labelOf(field) {
        if (isColumn(field)) { var c = columnOf(field.column_id); return c ? c.column_view_name : '#' + field.column_id; }
        if (isKept(field)) { return field.text; }
        return field.label || (types[field.type] || {}).label || '';
    }
    function choicesOf(field) {
        if (isColumn(field)) { return (columnOf(field.column_id) || {}).choices || []; }
        if (field.type === 'select') { return field.choices.split('\n').map(function (c) { return c.trim(); }).filter(function (c) { return c !== ''; }); }
        return [];
    }
    function isYesNo(field) {
        return isColumn(field) ? (columnOf(field.column_id) || {}).type === 'yesno' : field.type === 'yesno';
    }

    // "show this item only when <item> <is / is not / is empty / is not empty> <value>"
    function conditionBox(field) {
        if (field.cond && fields.indexOf(field.cond.target) === -1) { field.cond = null; }
        var cond = field.cond;
        var target = el('select', { className: 'form-control input-sm', 'aria-label': t.condition });
        target.appendChild(el('option', { value: '', text: t.condition_always }));
        fields.forEach(function (other, i) {
            if (other === field || isKept(other)) { return; }
            var option = el('option', { value: String(i), text: labelOf(other) });
            if (cond && cond.target === other) { option.selected = true; }
            target.appendChild(option);
        });
        var box = el('div', { className: 'exm-builder-condition' }, [el('label', { text: t.condition }), el('div', { className: 'exm-builder-condition-row' }, [target])]);
        if (cond) {
            var operator = el('select', { className: 'form-control input-sm' });
            Object.keys(t.operators).forEach(function (op) {
                var option = el('option', { value: op, text: t.operators[op] });
                if (cond.operator === op) { option.selected = true; }
                operator.appendChild(option);
            });
            operator.addEventListener('change', function () { cond.operator = operator.value; render(); });
            var row = box.lastChild;
            if (cond.operator === 'eq' || cond.operator === 'ne') {
                var value;
                if (isYesNo(cond.target)) {
                    value = el('select', { className: 'form-control input-sm' }, [
                        el('option', { value: '1', text: t.yes }), el('option', { value: '0', text: t.no }),
                    ]);
                    if (cond.value !== '0') { cond.value = '1'; }
                    value.value = cond.value;
                } else {
                    var listId = 'exm-builder-choices-' + fields.indexOf(field);
                    value = el('input', { type: 'text', className: 'form-control input-sm', placeholder: t.condition_value, list: listId });
                    value.value = cond.value || '';
                    var list = el('datalist', { id: listId });
                    choicesOf(cond.target).forEach(function (c) { list.appendChild(el('option', { value: c })); });
                    box.appendChild(list);
                }
                value.addEventListener('input', function () { cond.value = value.value; });
                value.addEventListener('change', function () { cond.value = value.value; });
                row.appendChild(value);
            }
            row.appendChild(operator);
        }
        target.addEventListener('change', function () {
            var other = target.value === '' ? null : fields[parseInt(target.value, 10)];
            field.cond = other ? { target: other, operator: 'eq', value: '' } : null;
            render();
        });
        var error = errorOf(field, 'visible_if');
        if (error) { box.appendChild(error); }
        return box;
    }

    function newItem(field, index) {
        var part = types[field.type] || { label: field.type, icon: 'fa-question', choices: false };
        var li = el('li', { className: 'exm-builder-field', draggable: 'true' });

        var label = el('input', { type: 'text', className: 'form-control exm-builder-label', maxlength: '40', placeholder: t.label, 'aria-label': t.label });
        label.value = field.label;
        label.addEventListener('input', function () { field.label = label.value; if (mode === 'edit') { renderNotice(); } });

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

        var previewBox = el('div', {}, [el('label', { text: t.preview }), preview(field.type, field.type === 'calc' ? field.formula : field.choices)]);
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
        if (part.target) {
            var target = el('select', { className: 'form-control input-sm', 'aria-label': t.target_table });
            target.appendChild(el('option', { value: '', text: t.target_table_placeholder }));
            referenceTables.forEach(function (table) {
                var option = el('option', { value: String(table.id), text: table.name });
                if (field.target_table === table.id) { option.selected = true; }
                target.appendChild(option);
            });
            target.addEventListener('change', function () { field.target_table = target.value === '' ? null : parseInt(target.value, 10); });
            body.appendChild(el('div', { className: 'exm-builder-choices' }, [el('label', { text: t.target_table }), target, errorOf(field, 'target_table')]));
        }
        if (part.formula) {
            var formula = el('input', { type: 'text', className: 'form-control input-sm', placeholder: t.formula_help, 'aria-label': t.formula });
            formula.value = field.formula;
            formula.addEventListener('input', function () {
                field.formula = formula.value;
                previewBox.replaceChild(preview(field.type, field.formula), previewBox.lastChild);
            });
            // the other number items, as buttons that write "[name]" into the formula
            var chips = el('div', { className: 'exm-builder-chips' });
            fields.forEach(function (other) {
                if (other === field || !(types[other.type] || {}).number) { return; }
                var chip = el('button', { type: 'button', className: 'btn btn-default btn-xs', text: '[' + other.label + ']' });
                chip.addEventListener('click', function () {
                    var at = formula.selectionStart === null ? formula.value.length : formula.selectionStart;
                    formula.value = formula.value.slice(0, at) + '[' + other.label + ']' + formula.value.slice(at);
                    field.formula = formula.value;
                    formula.focus();
                    previewBox.replaceChild(preview(field.type, field.formula), previewBox.lastChild);
                });
                chips.appendChild(chip);
            });
            children.forEach(function (child) {
                child.fields.forEach(function (item) {
                    if (!(types[item.type] || {}).number || !child.label.trim()) { return; }
                    var text = t.sum_of.replace('%s', child.label.trim() + '.' + item.label);
                    var chip = el('button', { type: 'button', className: 'btn btn-default btn-xs', text: text });
                    chip.addEventListener('click', function () {
                        var at = formula.selectionStart === null ? formula.value.length : formula.selectionStart;
                        formula.value = formula.value.slice(0, at) + text + formula.value.slice(at);
                        field.formula = formula.value;
                        formula.focus();
                    });
                    chips.appendChild(chip);
                });
            });
            body.appendChild(el('div', { className: 'exm-builder-choices' }, [
                el('label', { text: t.formula }), formula, chips, el('p', { className: 'exm-builder-hint', text: t.formula_help }), errorOf(field, 'formula'),
            ]));
        }
        body.appendChild(conditionBox(field));
        li.appendChild(body);
        return li;
    }

    // an item the builder does not make (a heading, HTML...): moved or taken off, changed on the form setting page
    function keptItem(field, index) {
        var li = el('li', { className: 'exm-builder-field exm-builder-kept', draggable: 'true', title: t.kept_help });
        li.appendChild(el('div', { className: 'exm-builder-field-head' }, [
            el('i', { className: 'fa fa-bars exm-builder-grip', 'aria-hidden': 'true' }),
            el('span', { className: 'exm-builder-type' }, [el('i', { className: 'fa ' + field.icon, 'aria-hidden': 'true' })]),
            el('span', { className: 'exm-builder-label-text', text: field.text }),
            tools(index),
        ]));
        var error = errorOf(field, 'form_column_id');
        if (error) { li.appendChild(error); }
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
            conditionBox(field),
        ]));
        return li;
    }

    function rowHead(r) {
        var select = el('select', { className: 'form-control', 'aria-label': t.row_layout });
        presets.forEach(function (widths, i) {
            var option = el('option', { value: String(i), text: t.presets[i] });
            select.appendChild(option);
        });
        select.value = String(presetIndex(rows[r]));
        select.addEventListener('change', function () { setLayout(r, presets[parseInt(select.value, 10)]); });

        var up = el('button', { type: 'button', title: t.row_up, 'aria-label': t.row_up }, [el('i', { className: 'fa fa-arrow-up' })]);
        var down = el('button', { type: 'button', title: t.row_down, 'aria-label': t.row_down }, [el('i', { className: 'fa fa-arrow-down' })]);
        var remove = el('button', { type: 'button', title: t.row_remove, 'aria-label': t.row_remove }, [el('i', { className: 'fa fa-trash' })]);
        up.disabled = r === 0;
        down.disabled = r === rows.length - 1;
        remove.disabled = rows.length === 1;
        up.addEventListener('click', function () { swapRows(r, r - 1); });
        down.addEventListener('click', function () { swapRows(r, r + 1); });
        remove.addEventListener('click', function () { removeRow(r); });
        return el('div', { className: 'exm-builder-row-head' }, [
            el('span', { className: 'exm-builder-row-name', text: t.row.replace('%s', r + 1) }),
            select,
            el('span', { className: 'exm-builder-tools' }, [up, down, remove]),
        ]);
    }

    function render() {
        canvas.innerHTML = '';
        var lists = [];
        rows.forEach(function (widths, r) {
            var cells = el('div', { className: 'exm-builder-cells' });
            cells.style.gridTemplateColumns = widths.map(function (w) { return 'minmax(0, ' + w + 'fr)'; }).join(' ');
            lists[r] = [];
            widths.forEach(function (w, c) {
                var list = el('ol', {});
                var cell = el('div', { className: 'exm-builder-cell' + (active.row === r && active.col === c ? ' is-active' : '') }, [
                    list, el('div', { className: 'exm-builder-cell-drop', text: t.cell_drop }),
                ]);
                cell.addEventListener('click', function (e) {
                    if (e.target.closest('input, textarea, select, button')) { return; }
                    active = { row: r, col: c };
                    canvas.querySelectorAll('.exm-builder-cell.is-active').forEach(function (n) { n.classList.remove('is-active'); });
                    cell.classList.add('is-active');
                });
                cell.addEventListener('dragover', function (e) { e.preventDefault(); cell.classList.add('is-over'); });
                cell.addEventListener('dragleave', function () { cell.classList.remove('is-over'); });
                cell.addEventListener('drop', function (e) {
                    e.preventDefault();
                    cell.classList.remove('is-over');
                    dropInto(e, r, c);
                });
                lists[r][c] = list;
                cells.appendChild(cell);
            });
            canvas.appendChild(el('div', { className: 'exm-builder-row' }, [rowHead(r), cells]));
        });

        fields.forEach(function (field, index) {
            var li = isKept(field) ? keptItem(field, index) : (isColumn(field) ? existingItem(field, index) : newItem(field, index));
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
                e.stopPropagation();
                li.classList.remove('is-over');
                dropAt(e, index);
            });
            lists[field.row][field.col].appendChild(li);
        });
        renderColumns();
        renderButtons();
        renderBundle();
        renderNotice();
    }

    // the app ----------------------------------------------------------------------
    var bundleBox = document.getElementById('exm-builder-bundle');
    function bundleTargets(part) {
        return fields.filter(function (f) { return !isColumn(f) && !isKept(f) && bundleSources[part].indexOf(f.type) !== -1; });
    }
    // the parts that will be made: on, and with their item
    function bundleMade() {
        var made = [];
        ['kanban', 'calendar', 'chart'].forEach(function (part) {
            if (bundle[part].on && bundle[part].target && fields.indexOf(bundle[part].target) !== -1) { made.push(part); }
        });
        if (canDashboard && bundle.dashboard.on) { made.push('dashboard'); }
        return made;
    }
    function renderBundle() {
        bundleBox.innerHTML = '';
        if (mode !== 'new') { return; }
        ['kanban', 'calendar', 'chart', 'dashboard'].forEach(function (part) {
            if (part === 'dashboard' && !canDashboard) { return; }
            var state = bundle[part];
            var id = 'exm-builder-bundle-' + part;
            var check = el('input', { type: 'checkbox', id: id });
            check.checked = state.on;
            check.addEventListener('change', function () { state.on = check.checked; renderBundle(); renderNotice(); });
            var row = el('div', { className: 'exm-builder-bundle-row' }, [
                el('label', { 'for': id }, [check, document.createTextNode(' ' + t.bundle[part][0])]),
            ]);
            if (part !== 'dashboard') {
                var targets = bundleTargets(part);
                if (!state.target || targets.indexOf(state.target) === -1) { state.target = targets[0] || null; }
                if (targets.length === 0) {
                    check.disabled = true;
                    row.appendChild(el('p', { className: 'exm-builder-hint', text: t.bundle_no_item }));
                } else {
                    var select = el('select', { className: 'form-control input-sm', 'aria-label': t.bundle[part][1], title: t.bundle[part][1] });
                    targets.forEach(function (f) {
                        var option = el('option', { value: String(fields.indexOf(f)), text: labelOf(f) });
                        if (state.target === f) { option.selected = true; }
                        select.appendChild(option);
                    });
                    select.disabled = !state.on;
                    select.addEventListener('change', function () { state.target = fields[parseInt(select.value, 10)]; });
                    row.appendChild(el('span', { className: 'exm-builder-button-word', text: t.bundle[part][1] }));
                    row.appendChild(select);
                }
            }
            bundleBox.appendChild(row);
        });
    }

    // buttons ------------------------------------------------------------------------
    var buttonsBox = document.getElementById('exm-builder-buttons');
    var buttonsWrap = document.getElementById('exm-builder-buttons-box');
    function typeOf(field) {
        if (isKept(field)) { return ''; }
        return isColumn(field) ? (columnOf(field.column_id) || {}).type : field.type;
    }
    // the values a button may set the item to: null for a value typed in
    function valuesOf(field) {
        if (isYesNo(field)) { return [{ value: '1', text: t.yes }, { value: '0', text: t.no }]; }
        var list = isColumn(field) ? ((columnOf(field.column_id) || {}).choices || null) : (field.type === 'select' ? choicesOf(field) : null);
        return list ? list.map(function (c) { return { value: c, text: c }; }) : null;
    }
    function buttonError(bi, name) {
        var message = errors['actions.' + bi + '.' + name];
        return message ? el('p', { className: 'exm-builder-error', text: message }) : null;
    }
    function canButton() {
        if (mode === 'edit') { return !!editing.can_add_columns; }
        if (mode !== 'existing') { return true; }
        var table = currentTable();
        return !!(table && table.can_button);
    }
    function renderButtons() {
        buttonsWrap.hidden = !canButton();
        buttonsBox.innerHTML = '';
        var targets = fields.filter(function (f) { return buttonTypes.indexOf(typeOf(f)) !== -1; });
        buttons.forEach(function (button, bi) {
            if (button.target && fields.indexOf(button.target) === -1) { button.target = null; }
            var label = el('input', { type: 'text', className: 'form-control input-sm', maxlength: '40', placeholder: t.button_label_placeholder, 'aria-label': t.button_label });
            label.value = button.label;
            label.addEventListener('input', function () { button.label = label.value; renderNotice(); });

            var target = el('select', { className: 'form-control input-sm', 'aria-label': t.button_target });
            target.appendChild(el('option', { value: '', text: targets.length ? t.button_target_placeholder : t.no_button_target }));
            targets.forEach(function (f) {
                var option = el('option', { value: String(fields.indexOf(f)), text: labelOf(f) });
                if (button.target === f) { option.selected = true; }
                target.appendChild(option);
            });
            target.addEventListener('change', function () {
                button.target = target.value === '' ? null : fields[parseInt(target.value, 10)];
                button.value = '';
                renderButtons();
            });

            var value;
            var values = button.target ? valuesOf(button.target) : null;
            if (values) {
                value = el('select', { className: 'form-control input-sm', 'aria-label': t.button_value });
                value.appendChild(el('option', { value: '', text: t.button_value }));
                values.forEach(function (v) {
                    var option = el('option', { value: v.value, text: v.text });
                    if (button.value === v.value) { option.selected = true; }
                    value.appendChild(option);
                });
            } else {
                value = el('input', { type: typeOf(button.target || {}) === 'integer' ? 'number' : 'text', className: 'form-control input-sm', placeholder: t.button_value, 'aria-label': t.button_value });
                value.value = button.value;
                value.disabled = !button.target;
            }
            value.addEventListener('input', function () { button.value = value.value; });
            value.addEventListener('change', function () { button.value = value.value; });

            var remove = el('button', { type: 'button', className: 'btn btn-default btn-sm', title: t.remove_button, 'aria-label': t.remove_button }, [el('i', { className: 'fa fa-trash', 'aria-hidden': 'true' })]);
            remove.addEventListener('click', function () { buttons.splice(bi, 1); renderButtons(); renderNotice(); });

            buttonsBox.appendChild(el('div', { className: 'exm-builder-button' }, [
                el('i', { className: 'fa fa-hand-pointer-o', 'aria-hidden': 'true' }), label,
                el('span', { className: 'exm-builder-button-word', text: t.button_to }), target,
                el('span', { className: 'exm-builder-button-word', text: t.button_set }), value,
                remove,
                buttonError(bi, 'label'), buttonError(bi, 'target'), buttonError(bi, 'value'),
            ]));
        });
    }
    document.getElementById('exm-builder-add-button').addEventListener('click', function () {
        if (buttons.length >= maxButtons) { alert(t.too_many_buttons); return; }
        buttons.push({ label: '', target: null, value: '' });
        renderButtons();
        var names = buttonsBox.querySelectorAll('.exm-builder-button input[type=text]');
        if (names.length) { names[names.length - 1].focus(); }
    });
    // a name or the choices of an item changed: the buttons show them
    canvas.addEventListener('change', function (e) {
        if (e.target.closest('.exm-builder-field')) { renderButtons(); renderBundle(); renderNotice(); }
    });

    // details (child tables) ---------------------------------------------------------
    var childrenBox = document.getElementById('exm-builder-children');
    function childError(key) {
        var message = errors['children.' + key];
        return message ? el('p', { className: 'exm-builder-error', text: message }) : null;
    }
    function renderChildren() {
        childrenBox.innerHTML = '';
        children.forEach(function (child, ci) {
            var name = el('input', { type: 'text', className: 'form-control input-sm', maxlength: '40', placeholder: t.child_name_placeholder, 'aria-label': t.child_name });
            name.value = child.label;
            name.addEventListener('input', function () { child.label = name.value; renderNotice(); });
            name.addEventListener('change', function () { render(); });
            var remove = el('button', { type: 'button', className: 'btn btn-default btn-sm', title: t.remove_child, 'aria-label': t.remove_child }, [el('i', { className: 'fa fa-trash' })]);
            remove.addEventListener('click', function () { children.splice(ci, 1); renderChildren(); renderNotice(); });

            var list = el('ol', { className: 'exm-builder-child-fields' });
            child.fields.forEach(function (field, fi) {
                var part = types[field.type] || { label: field.type, icon: 'fa-question', choices: false };
                var label = el('input', { type: 'text', className: 'form-control input-sm', maxlength: '40', placeholder: t.label, 'aria-label': t.label });
                label.value = field.label;
                label.addEventListener('input', function () { field.label = label.value; });
                label.addEventListener('change', function () { renderChildren(); render(); });
                var required = el('input', { type: 'checkbox' });
                required.checked = field.required;
                required.addEventListener('change', function () { field.required = required.checked; });
                var del = el('button', { type: 'button', className: 'btn btn-link btn-sm', title: t.remove, 'aria-label': t.remove }, [el('i', { className: 'fa fa-trash' })]);
                del.addEventListener('click', function () { child.fields.splice(fi, 1); renderChildren(); });
                var li = el('li', { className: 'exm-builder-child-field' }, [
                    el('span', { className: 'exm-builder-type' }, [el('i', { className: 'fa ' + part.icon, 'aria-hidden': 'true' }), document.createTextNode(' ' + part.label)]),
                    label,
                    el('label', {}, [required, document.createTextNode(' ' + t.required)]),
                    del,
                ]);
                if (part.choices) {
                    var choices = el('textarea', { className: 'form-control input-sm exm-builder-child-choices', rows: '2', placeholder: t.choices_help, 'aria-label': t.choices });
                    choices.value = field.choices;
                    choices.addEventListener('input', function () { field.choices = choices.value; });
                    li.appendChild(choices);
                }
                if (part.target) {
                    var target = el('select', { className: 'form-control input-sm exm-builder-child-choices', 'aria-label': t.target_table });
                    target.appendChild(el('option', { value: '', text: t.target_table_placeholder }));
                    referenceTables.forEach(function (table) {
                        var option = el('option', { value: String(table.id), text: table.name });
                        if (field.target_table === table.id) { option.selected = true; }
                        target.appendChild(option);
                    });
                    target.addEventListener('change', function () { field.target_table = target.value === '' ? null : parseInt(target.value, 10); });
                    li.appendChild(target);
                }
                if (part.formula) {
                    var formula = el('input', { type: 'text', className: 'form-control input-sm exm-builder-child-choices', placeholder: t.formula_help, 'aria-label': t.formula });
                    formula.value = field.formula;
                    formula.addEventListener('input', function () { field.formula = formula.value; });
                    var chips = el('div', { className: 'exm-builder-chips exm-builder-child-choices' });
                    child.fields.forEach(function (other) {
                        if (other === field || !(types[other.type] || {}).number) { return; }
                        var chip = el('button', { type: 'button', className: 'btn btn-default btn-xs', text: '[' + other.label + ']' });
                        chip.addEventListener('click', function () {
                            var at = formula.selectionStart === null ? formula.value.length : formula.selectionStart;
                            formula.value = formula.value.slice(0, at) + '[' + other.label + ']' + formula.value.slice(at);
                            field.formula = formula.value;
                            formula.focus();
                        });
                        chips.appendChild(chip);
                    });
                    li.appendChild(formula);
                    li.appendChild(chips);
                }
                ['label', 'column_name', 'choices', 'type', 'target_table', 'formula'].forEach(function (k) {
                    var e = childError(ci + '.fields.' + fi + '.' + k);
                    if (e) { li.appendChild(e); }
                });
                list.appendChild(li);
            });

            var typeSelect = el('select', { className: 'form-control input-sm', 'aria-label': t.add_child_field });
            palette.forEach(function (p) { typeSelect.appendChild(el('option', { value: p.type, text: p.label })); });
            var add = el('button', { type: 'button', className: 'btn btn-default btn-sm' }, [el('i', { className: 'fa fa-plus', 'aria-hidden': 'true' }), document.createTextNode(' ' + t.add_child_field)]);
            add.addEventListener('click', function () {
                child.fields.push({ type: typeSelect.value, label: types[typeSelect.value].label, required: false, choices: '', target_table: null, formula: '' });
                renderChildren();
                // the name of the new item: the first box of the last row (a calculation has a formula box after it)
                var rowsOfChild = childrenBox.querySelectorAll('.exm-builder-child')[ci].querySelectorAll('.exm-builder-child-field');
                var nameBox = rowsOfChild.length ? rowsOfChild[rowsOfChild.length - 1].querySelector('input[type=text]') : null;
                if (nameBox) { nameBox.focus(); nameBox.select(); }
            });

            childrenBox.appendChild(el('div', { className: 'exm-builder-child' }, [
                el('div', { className: 'exm-builder-child-head' }, [el('i', { className: 'fa fa-table', 'aria-hidden': 'true' }), name, remove]),
                childError(ci + '.table_view_name'), childError(ci + '.table_name'), childError(ci + '.fields'),
                list,
                el('div', { className: 'exm-builder-child-add' }, [typeSelect, add]),
            ]));
        });
    }
    document.getElementById('exm-builder-add-child').addEventListener('click', function () {
        if (children.length >= maxChildren) { alert(t.too_many_children); return; }
        children.push({ label: '', fields: [] });
        renderChildren();
        var names = childrenBox.querySelectorAll('.exm-builder-child-head input');
        if (names.length) { names[names.length - 1].focus(); }
    });

    // the columns of the chosen table, as parts; a column already placed is greyed out
    function renderColumns() {
        columnsBox.innerHTML = '';
        if (mode !== 'existing' && mode !== 'edit') { return; }
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
        if (mode === 'edit') {
            text = t.notice_edit;
            var newNames = fields.filter(function (f) { return !isColumn(f) && !isKept(f); }).map(function (f) { return t.name_quote.replace('%s', labelOf(f)); });
            if (newNames.length > 0) {
                text += t.notice_new_columns.replace('%1$s', editing.table_view_name).replace('%2$s', newNames.join(t.name_separator));
            }
        } else if (mode === 'existing') {
            var table = currentTable();
            text = table ? t.notice_existing.replace('%s', table.table_view_name) : t.notice_existing_none;
        } else {
            var name = nameInput.value.trim();
            text = name ? t.notice_new.replace('%s', name) : t.notice_new_unnamed;
            if (importBox.checked && tokenInput.value) {
                text += t.notice_import.replace('%s', rowsInput.value);
            }
            var childNames = children.map(function (c) { return c.label.trim(); }).filter(function (n) { return n !== ''; });
            if (childNames.length > 0) {
                text += t.notice_children.replace('%s', childNames.map(function (n) { return t.name_quote.replace('%s', n); }).join(t.name_separator));
            }
        }
        var bundleNames = mode === 'new' ? bundleMade().map(function (part) { return t.name_quote.replace('%s', t.bundle[part][0].replace(/[（(].*$/, '').trim()); }) : [];
        if (bundleNames.length > 0) {
            text += t.notice_bundle.replace('%s', bundleNames.join(t.name_separator));
        }
        var buttonNames = canButton() ? buttons.map(function (b) { return b.label.trim(); }).filter(function (n) { return n !== ''; }) : [];
        if (buttonNames.length > 0) {
            text += t.notice_buttons.replace('%s', buttonNames.map(function (n) { return t.name_quote.replace('%s', n); }).join(t.name_separator));
        }
        notice.appendChild(el('i', { className: 'fa fa-info-circle', 'aria-hidden': 'true' }));
        notice.appendChild(document.createTextNode(text));

        // the required columns left out are saved empty from this screen: say so
        if (mode !== 'new' && currentTable()) {
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
            node.hidden = mode === 'edit' ? !node.hasAttribute('data-in-edit') : node.getAttribute('data-mode-only') !== mode;
        });
    }

    // move an item before the item at "to". A drag ("takeCell") also moves it into that item's
    // cell; the up and down buttons keep it in its own cell.
    function move(from, to, takeCell) {
        var item = fields[from];
        var target = fields[to];
        if (takeCell && target && target !== item) { item.row = target.row; item.col = target.col; }
        fields.splice(from, 1);
        if (to > from) { to--; }
        fields.splice(Math.max(0, Math.min(to, fields.length)), 0, item);
        sortFields();
        render();
    }

    // put a new item before the item at "at", or at the end of a cell
    function insert(item, at, cell) {
        if (fields.length >= maxFields) { alert(t.too_many); return false; }
        var place = cell || (at !== undefined && fields[at] ? { row: fields[at].row, col: fields[at].col } : active);
        item.row = place.row;
        item.col = place.col;
        fields.splice(at === undefined ? fields.length : at, 0, item);
        sortFields();
        render();
        return true;
    }

    function add(type, at, cell) {
        var item = { type: type, label: types[type].label, column_name: '', required: false, choices: '', source: null, target_table: null, formula: '', error: null };
        if (!insert(item, at, cell)) { return; }
        var target = canvas.querySelectorAll('.exm-builder-label')[fields.indexOf(item)];
        if (target) { target.focus(); target.select(); }
    }

    function addColumn(id, at, cell) {
        for (var i = 0; i < fields.length; i++) { if (fields[i].column_id === id) { return; } }
        insert({ column_id: id, error: null }, at, cell);
    }

    function dropAt(e, at) {
        var type = e.dataTransfer.getData('application/x-exm-part');
        var column = e.dataTransfer.getData('application/x-exm-column');
        if (mode !== 'existing' && type && types[type]) { add(type, at); }
        else if (mode !== 'new' && column) { addColumn(parseInt(column, 10), at); }
        else if (dragFrom !== null) { move(dragFrom, at, true); }
        dragFrom = null;
    }

    // a drop on a cell, not on an item: to the end of the cell
    function dropInto(e, r, c) {
        var cell = { row: r, col: c };
        var type = e.dataTransfer.getData('application/x-exm-part');
        var column = e.dataTransfer.getData('application/x-exm-column');
        if (mode !== 'existing' && type && types[type]) { add(type, undefined, cell); }
        else if (mode !== 'new' && column) { addColumn(parseInt(column, 10), undefined, cell); }
        else if (dragFrom !== null) {
            var item = fields[dragFrom];
            item.row = r;
            item.col = c;
            fields.splice(dragFrom, 1);
            fields.push(item);
            sortFields();
            render();
        }
        dragFrom = null;
    }

    // rows -----------------------------------------------------------------------
    function setLayout(r, widths) {
        rows[r] = widths.slice();
        // the items of a column that is gone go to the last column
        fields.forEach(function (f) { if (f.row === r && f.col >= widths.length) { f.col = widths.length - 1; } });
        if (active.row === r && active.col >= widths.length) { active.col = widths.length - 1; }
        sortFields();
        render();
    }
    function swapRows(a, b) {
        var w = rows[a]; rows[a] = rows[b]; rows[b] = w;
        fields.forEach(function (f) { if (f.row === a) { f.row = b; } else if (f.row === b) { f.row = a; } });
        if (active.row === a) { active.row = b; } else if (active.row === b) { active.row = a; }
        sortFields();
        render();
    }
    function removeRow(r) {
        // the items of the row go to the first column of the row above (or below)
        var to = r > 0 ? r - 1 : 0;
        fields.forEach(function (f) {
            if (f.row === r) { f.row = to; f.col = 0; }
            else if (f.row > r) { f.row--; }
        });
        rows.splice(r, 1);
        active = { row: Math.min(active.row, rows.length - 1), col: 0 };
        sortFields();
        render();
    }
    document.getElementById('exm-builder-add-row').addEventListener('click', function () {
        rows.push([4]);
        active = { row: rows.length - 1, col: 0 };
        render();
    });

    // switching the way or the table starts the screen again, after asking when items are placed
    function restart(apply, undo) {
        if (fields.length > 0 && !confirm(t.confirm_clear)) { undo(); return; }
        apply();
        fields = [];
        rows = [[4]];
        active = { row: 0, col: 0 };
        children = [];
        buttons = [];
        ['kanban', 'calendar', 'chart'].forEach(function (part) { bundle[part] = { on: true, target: null }; });
        bundle.dashboard = { on: canDashboard };
        renderChildren();
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

    form.addEventListener('submit', function () {
        document.getElementById('exm-builder-fields').value = JSON.stringify(fields.map(function (f) {
            var item = isColumn(f)
                ? { column_id: f.column_id }
                : (isKept(f) ? { form_column_id: f.form_column_id }
                    : { type: f.type, label: f.label, column_name: f.column_name, required: f.required, choices: f.choices, source: f.source,
                        target_table: f.target_table, formula: f.formula });
            item.row = f.row;
            item.column = f.col;
            if (f.cond && fields.indexOf(f.cond.target) !== -1) {
                item.visible_if = { target: fields.indexOf(f.cond.target), operator: f.cond.operator, value: f.cond.value || '' };
            }
            return item;
        }));
        document.getElementById('exm-builder-layout').value = JSON.stringify(rows.map(function (w) { return { widths: w }; }));
        document.getElementById('exm-builder-children-input').value = mode !== 'new' ? '[]' : JSON.stringify(children);
        var made = mode === 'new' ? bundleMade() : [];
        var posted = { kanban: null, calendar: null, chart: null, dashboard: made.indexOf('dashboard') !== -1 };
        ['kanban', 'calendar', 'chart'].forEach(function (part) {
            if (made.indexOf(part) !== -1) { posted[part] = fields.indexOf(bundle[part].target); }
        });
        document.getElementById('exm-builder-bundle-input').value = JSON.stringify(posted);
        document.getElementById('exm-builder-actions-input').value = !canButton() ? '[]' : JSON.stringify(buttons.map(function (b) {
            return { label: b.label, target: b.target ? fields.indexOf(b.target) : null, value: b.value };
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
                rows = [[4]];
                active = { row: 0, col: 0 };
                fields = data.fields.map(function (f) {
                    return { type: f.type, label: f.label, column_name: '', required: false, choices: f.choices || '', source: f.source, row: 0, col: 0, error: null };
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
    renderChildren();
    render();
    // after a mistake the page comes back with the sheet still read
    if (tokenInput.value) {
        showSheet(t.sheet_loaded.replace('%1$s', sheetNameInput.value).replace('%2$s', fields.length).replace('%3$s', rowsInput.value), false);
    }
})();
</script>
