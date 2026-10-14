@php
    $options = $definition['options'] ?? [];
    $selected_types = $definition['column_types'] ?? [];
    $preset_key = $definition['key'] ?? null;
    $editable = boolval($definition['editable'] ?? false);
@endphp
{{--
    The preset editor.

    Deliberately not a laravel-admin form: it never posts. Both save buttons
    read the same fields and send them to a different url, and the sample on
    the right has to follow every keystroke, so the whole thing is driven by
    cellstyle_preset.js instead.
--}}
<div class="exm-preset-modal"
    data-preset-modal="1"
    data-preset-endpoint="{{ $endpoint }}"
    data-preset-key="{{ $preset_key }}"
    data-preset-editable="{{ $editable ? '1' : '0' }}">

    <div class="row">
        <div class="col-md-7">
            <div class="exm-preset-row">
                <label class="exm-preset-label" for="exm-preset-name">{{ exmtrans('cell_style_preset.preset_name') }}</label>
                <input type="text" id="exm-preset-name" class="form-control exm-preset-field" name="preset_name"
                    maxlength="40" value="{{ $definition['name'] ?? '' }}"
                    placeholder="{{ exmtrans('cell_style_preset.preset_name') }}">
            </div>

            <div class="exm-preset-row">
                <label class="exm-preset-label" for="exm-preset-style">{{ exmtrans('custom_column.options.grid_style') }}</label>
                <select id="exm-preset-style" class="form-control exm-preset-field" name="grid_style">
                    @foreach($style_options as $value => $label)
                        <option value="{{ $value }}" {{ isMatchString($value, array_get($options, 'grid_style')) ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="exm-preset-hint">{!! exmtrans('custom_column.help.grid_style') !!}</div>
            </div>

            @foreach(['grid_color', 'grid_bg_color', 'grid_border_color'] as $color_key)
                <div class="exm-preset-row">
                    <label class="exm-preset-label" for="exm-preset-{{ $color_key }}">{{ exmtrans('custom_column.options.' . $color_key) }}</label>
                    <div class="exm-preset-color">
                        <input type="color" class="exm-preset-color-swatch" tabindex="-1"
                            aria-label="{{ exmtrans('custom_column.options.' . $color_key) }}"
                            value="{{ array_get($options, $color_key) ?: '#3c8dbc' }}">
                        <input type="text" id="exm-preset-{{ $color_key }}" class="form-control exm-preset-field"
                            name="{{ $color_key }}" maxlength="7" value="{{ array_get($options, $color_key) }}"
                            placeholder="{{ exmtrans('cell_style_preset.color_placeholder') }}">
                        <button type="button" class="btn btn-default exm-preset-color-clear"
                            title="{{ exmtrans('cell_style_preset.clear_color') }}">&times;</button>
                    </div>
                    <div class="exm-preset-hint">{!! exmtrans('custom_column.help.' . $color_key) !!}</div>
                </div>
            @endforeach

            <div class="exm-preset-row">
                <label class="exm-preset-label" for="exm-preset-weight">{{ exmtrans('custom_column.options.grid_font_weight') }}</label>
                <select id="exm-preset-weight" class="form-control exm-preset-field" name="grid_font_weight">
                    <option value="">{{ exmtrans('cell_style_preset.not_specified') }}</option>
                    @foreach($weight_options as $value => $label)
                        <option value="{{ $value }}" {{ isMatchString($value, array_get($options, 'grid_font_weight')) ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
                <div class="exm-preset-hint">{!! exmtrans('custom_column.help.grid_font_weight') !!}</div>
            </div>

            <div class="exm-preset-row">
                <label class="exm-preset-label" for="exm-preset-icon">{{ exmtrans('custom_column.options.grid_icon') }}</label>
                <input type="text" id="exm-preset-icon" class="form-control exm-preset-field" name="grid_icon"
                    maxlength="40" value="{{ array_get($options, 'grid_icon') }}" placeholder="fa-check"
                    data-cellstyle-iconpicker="1"
                    data-cellstyle-iconlabels="{{ json_encode(\Exceedone\Exment\ColumnItems\GridCellStyle::iconPickerLabels()) }}">
                <div class="exm-preset-hint">{!! exmtrans('custom_column.help.grid_icon') !!}</div>
            </div>

            <div class="exm-preset-row">
                <label class="exm-preset-label" for="exm-preset-nowrap">{{ exmtrans('custom_column.options.grid_nowrap') }}</label>
                <div class="exm-preset-check">
                    <input type="checkbox" id="exm-preset-nowrap" class="exm-preset-field" name="grid_nowrap"
                        value="1" {{ boolval(array_get($options, 'grid_nowrap')) ? 'checked' : '' }}>
                    <label for="exm-preset-nowrap">{{ exmtrans('custom_column.options.grid_nowrap') }}</label>
                </div>
                <div class="exm-preset-hint">{!! exmtrans('custom_column.help.grid_nowrap') !!}</div>
            </div>

            <div class="exm-preset-row">
                <label class="exm-preset-label" for="exm-preset-value-colors">{{ exmtrans('custom_column.options.grid_value_colors') }}</label>
                <textarea id="exm-preset-value-colors" class="form-control exm-preset-field" name="grid_value_colors"
                    rows="4" maxlength="{{ \Exceedone\Exment\Model\CellStylePreset::VALUE_COLORS_MAX }}"
                    data-cellstyle-valuecolors="1"
                    data-cellstyle-labels="{{ json_encode(\Exceedone\Exment\ColumnItems\GridCellStyle::valueColorLabels()) }}"
                    placeholder="1,#c0392b">{{ array_get($options, 'grid_value_colors') }}</textarea>
                <div class="exm-preset-hint">{!! exmtrans('custom_column.help.grid_value_colors') !!}</div>
            </div>
        </div>

        <div class="col-md-5">
            <div class="exm-preset-row">
                <label class="exm-preset-label">{{ exmtrans('cell_style_preset.preview') }}</label>
                <div class="exm-cellstyle-preview" data-cellstyle-preview="modal">
                    <div class="exm-cellstyle-preview-body"></div>
                </div>
            </div>

            <div class="exm-preset-row">
                <label class="exm-preset-label">{{ exmtrans('cell_style_preset.column_types') }}</label>
                <div class="exm-preset-hint">{{ exmtrans('cell_style_preset.column_types_hint') }}</div>
                <div class="exm-preset-types">
                    @foreach($column_type_options as $value => $label)
                        <label class="exm-preset-type">
                            <input type="checkbox" name="column_types" value="{{ $value }}"
                                {{ in_array($value, $selected_types, true) ? 'checked' : '' }}>
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    <div class="exm-preset-actions">
        @if($editable && isset($preset_key))
            {{-- Two clicks rather than a browser confirm: the second click is
                 the confirmation, and nothing blocks the page while it waits. --}}
            <button type="button" class="btn btn-danger exm-preset-delete"
                data-confirm-label="{{ exmtrans('cell_style_preset.delete_confirm') }}">{{ trans('admin.delete') }}</button>
        @endif

        <div class="exm-preset-actions-right">
            <button type="button" class="btn btn-default exm-preset-save" data-preset-save="new">
                {{ exmtrans('cell_style_preset.save_as_new') }}
            </button>
            <button type="button" class="btn btn-primary exm-preset-save" data-preset-save="update"
                {{ $editable && isset($preset_key) ? '' : 'disabled' }}>
                {{ exmtrans('cell_style_preset.save_update') }}
            </button>
        </div>
    </div>

    @unless($editable && isset($preset_key))
        <div class="exm-preset-note">{{ exmtrans('cell_style_preset.readonly_note') }}</div>
    @endunless
</div>
