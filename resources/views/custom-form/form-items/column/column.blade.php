<div class="form-group">
    <span class="small control-label col-sm-5">
        {{exmtrans('custom_form.read_only')}}
        <i class="fa fa-info-circle" data-help-text="{{exmtrans('custom_form.help.read_only')}}" data-help-title="{{exmtrans('custom_form.read_only')}}"></i>
    </span>
    <div class="col-sm-7" style="padding-top:4px;">
    {{ Form::checkbox("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][read_only]", 1, array_get($custom_form_column, 'options.read_only'), ['id' => "custom_form_block_{$custom_form_block['id']}__options__read_only_{$loop->index}", 'class' => 'icheck']) }}
    </div>
</div>
<div class="form-group">
    <span class="small control-label col-sm-5">
        {{exmtrans('custom_form.view_only')}}
        <i class="fa fa-info-circle" data-help-text="{{exmtrans('custom_form.help.view_only')}}" data-help-title="{{exmtrans('custom_form.view_only')}}"></i>
    </span>
    <div class="col-sm-7" style="padding-top:4px;">
    {{ Form::checkbox("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][view_only]", 1, array_get($custom_form_column, 'options.view_only'), ['id' => "custom_form_block_{$custom_form_block['id']}__options__view_only_{$loop->index}", 'class' => 'icheck']) }}
    </div>
</div>

<div class="form-group">
    <span class="small control-label col-sm-5">{{exmtrans('custom_form.hidden')}}</span>
    <div class="col-sm-7" style="padding-top:4px;">
            {{ Form::checkbox("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][hidden]", 1, array_get($custom_form_column, 'options.hidden'), ['id' => "custom_form_block_{$custom_form_block['id']}__options__hidden_{$loop->index}", 'class' => 'icheck']) }}
    </div>
</div>

<div class="form-group">
    <span class="small control-label col-sm-5">{{exmtrans('custom_form.required')}}</span>
    <div class="col-sm-7" style="padding-top:4px;">
            {{ Form::checkbox("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][required]", 1, array_get($custom_form_column, 'options.required'), ['id' => "custom_form_block_{$custom_form_block['id']}__options__required_{$loop->index}", 'class' => 'icheck']) }}
    </div>
</div>

<div class="form-group">
    <span class="small control-label col-sm-5">
        {{exmtrans('custom_form.changedata')}}
        <i class="fa fa-info-circle" data-help-text="{{exmtrans('custom_form.help.changedata')}}" data-help-title="{{exmtrans('custom_form.changedata')}}"></i>
    </span>
    <div class="col-sm-7" style="padding-top:4px;">
            <a class="btn btn-sm btn-default changedata-modal" href="javascript:void(0);">@lang('admin.setting')</a> 
            {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][changedata_target_column_id]", array_get($custom_form_column, 'options.changedata_target_column_id'), ['id' => "custom_form_block_{$custom_form_block['id']}__options__changedata_target_column_id_", 'class' => 'changedata_target_column_id']) }}
            {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][changedata_column_id]", array_get($custom_form_column, 'options.changedata_column_id'), ['id' => "custom_form_block_{$custom_form_block['id']}__options__changedata_column_id_", 'class' => 'changedata_column_id']) }}

            <span class="small red changedata_available" style="margin-left:5px; display:{{(array_key_value_exists('options.changedata_target_column_id', $custom_form_column) && array_key_value_exists('options.changedata_target_column_id', $custom_form_column)) ? 'inline' : 'none'}};">{{exmtrans('custom_form.changedata_target_column_available')}}</span>
    </div>
</div>

@if(boolval(array_get($custom_form_column, 'is_select_table')))
<div class="form-group">
    <span class="small control-label col-sm-5">
        {{exmtrans('custom_form.relation_filter')}}
        <i class="fa fa-info-circle" data-help-text="{{$relationFilterHelp}}" data-help-title="{{exmtrans('custom_form.relation_filter')}}"></i>
    </span>
    <div class="col-sm-7" style="padding-top:4px;">
            <a class="btn btn-sm btn-default relation_filter-modal" href="javascript:void(0);">@lang('admin.setting')</a> 
            {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options][relation_filter_target_column_id]", array_get($custom_form_column, 'options.relation_filter_target_column_id'), ['id' => "custom_form_block_{$custom_form_block['id']}__options__relation_filter_target_column_id_", 'class' => 'relation_filter_target_column_id']) }}

            <span class="small red relation_filter_available" style="margin-left:5px; display:{{(array_key_value_exists('options.relation_filter_target_column_id', $custom_form_column) && array_key_value_exists('options.relation_filter_target_column_id', $custom_form_column)) ? 'inline' : 'none'}};">{{exmtrans('custom_form.changedata_target_column_available')}}</span>
    </div>
</div>
@endif