<div class="form-group">
    <span class="control-label col-sm-3">{{exmtrans('custom_form.image')}}</span>
    <div class="col-sm-9">
    @if(isset($custom_form_column['image_url']))
        <img src="{{$custom_form_column['image_url']}}" style="max-width:100%; max-height:200px;" class="d-block">
        <span class="help-block">
            <i class="fa fa-info-circle"></i>&nbsp;{{exmtrans('custom_form.message.image_need_delete')}}
        </span>
    @else
        {{ Form::file("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][image]", ['class' => 'form-control', 'accept' => '.jpeg,.jpg,.png,.gif,.svg']) }}
    @endif
    </div>
</div>