<div class="form-group">
    <span class="control-label col-sm-3">{{exmtrans('custom_form.image')}}</span>
    <div class="col-sm-9">
        {{ Form::file("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][image]", ['class' => 'form-control', 'accept' => '.jpeg,.jpg,.png,.gif,.svg']) }}
    </div>
</div>