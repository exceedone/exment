<div class="form-group">
    <span class="control-label col-sm-3">{{exmtrans('custom_form.text')}}</span>
    <div class="col-sm-9">
        @if($custom_form_column['form_column_target_id'] == "1")
        {{ Form::text("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][text]", array_get($custom_form_column, 'options.text'), ['class' => 'form-control']) }}
        @else
        <p class="input_texthtml-label" style="padding-top: 7px;">
            {{get_omitted_string(array_get($custom_form_column, 'options.text'))}}
        </p>
        <button type="button" class="btn btn-sm btn-default input_texthtml-modal">@lang('admin.edit')</button> 
        {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][text]", array_get($custom_form_column, 'options.text'), ['class' => 'input_texthtml']) }}
        @endif
    </div>
</div>