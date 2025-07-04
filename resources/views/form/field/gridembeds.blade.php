
@if($enableHeader)
<div class="">
    <h4 class="field-header">{{ $label }}</h4>
</div>
<hr style="margin-top: 0px;">
@endif

<div id="embed-{{$column}}" class="embed-{{$column}}">
    <label class="ai-ocr-uploaded-label" style="display: none;">
        {{ exmtrans('custom_column.help.ai_ocr_file_imported', [
            'button' => exmtrans('change_page_menu.ai_ocr_run')
        ]) }}
    </label>
    <div class="embed-{{$column}}-forms">
        <div class="embed-{{$column}}-form fields-group">
            @foreach($fieldGroups as $fieldRow)
                <div class="row gridembeds-row">
                    @foreach($fieldRow['columns'] as $fieldColumn)
                        <div class="col-md-{{array_get($fieldColumn, 'col_md', 12)}} gridembeds-column">
                        @if (!empty($aiOcrEnabled))
                            <input type="hidden" name="value[ai_ocr_temp_path]" id="ai-ocr-temp-path" value="">
                        @endif
                        @foreach($fieldColumn['fields'] as $field)
                            <div class="row"><div class="col-md-{{array_get($field, 'field_sm', 12)}} col-md-offset-{{array_get($field, 'field_offset', 0)}}">
                                @php
                                    $options = method_exists($field['field'], 'getCustomOptions') ? $field['field']->getCustomOptions() : [];
                                @endphp
                                @if (!empty($options['ocr_search_keyword']) && !empty($options['ocr_extraction_role']))
                                    <div class="form-group ">
                                        <label class="col-md-2  control-label"></label>
                                        <div class="col-md-8 ">
                                            <span class="text-danger fw-semibold">
                                                {{ exmtrans('custom_column.help.ai_ocr_hint', [
                                                    'keyword' => $options['ocr_search_keyword'] ?? '',
                                                    'role' => $options['ocr_extraction_role'] ?? ''
                                                ]) }}
                                            </span>
                                        </div>
                                    </div>
                                @endif

                                {!! $field['field']->render() !!}
                            </div></div>
                        @endforeach
                    </div>
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
</div>

@if($footer_hr)
<hr style="margin-top: 0px;">
@endif
