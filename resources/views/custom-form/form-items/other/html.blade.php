<div class="form-group">
    <span class="control-label col-sm-3">{{exmtrans('custom_form.html')}}
        <i class="fa fa-info-circle" data-help-text="{{exmtrans('custom_form.help.html')}}" data-help-title="{{exmtrans('custom_form.html')}}"></i>
    </span>
    <div class="col-sm-9">
        <p class="input_texthtml-label" style="padding-top: 7px;">
            {{get_omitted_string(array_get($custom_form_column, 'options.html'))}}
        </p>
        <button type="button" class="btn btn-sm btn-default input_texthtml-modal">@lang('admin.edit')</button> 
        {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][html]", array_get($custom_form_column, 'options.html'), ['class' => 'input_texthtml']) }}
    </div>
</div>