@if(!isset($template_item) && (isset($custom_form_column['has_custom_forms']) && boolval($custom_form_column['has_custom_forms'])))

@else
<li class="ui-state-default custom_form_column_item draggable"
    style="{{boolval(array_get($custom_form_column, 'delete_flg')) ? 'display:none' : ''}}"
    id="{{preg_replace('/\[|\]/', '_', $custom_form_column['header_column_name'])}}" data-header_column_name="{{preg_replace('/\[|\]/', '_', $custom_form_column['header_column_name'])}}">
        <span class="item-label {{array_get($custom_form_column, 'required') ? 'asterisk' : ''}}">{{ $custom_form_column['column_view_name'] }}</span>

        <a href="javascript:void(0);" class="delete" style="position: absolute; margin-left: 10px; display:{{!boolval($suggest) ? 'inline-block' : 'none'}};">
            <i class="fa fa-trash"></i>
        </a>
        
        @if($custom_form_column['form_column_type'] != '1')
        <a class="pull-right" 
            data-toggle="collapse" 
            data-parent="#{{$custom_form_column['toggle_key_name']}}" 
            href="#{{$custom_form_column['toggle_key_name']}}"
            aria-expanded="false"
            style="display:{{!boolval($suggest) ? 'block' : 'none'}};">
            <i class="fa fa-chevron-down"></i>
        </a>
        @endif

        <div id="{{$custom_form_column['toggle_key_name']}}" class="panel-collapse collapse" class="options">
            <div class="form-horizontal">
                    @if($custom_form_column['form_column_type'] == '0')
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

                        @if(\Exceedone\Exment\Enums\ColumnType::isSelectTable(array_get($custom_form_column, 'column_type')) || \Exceedone\Exment\Enums\ColumnType::isSelectTable(array_get($custom_form_column, 'custom_column.column_type')))
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
                    @endif

                    @if($custom_form_column['form_column_type'] == '99' && in_array($custom_form_column['form_column_target_id'],[1,2]))
                    @include("exment::custom-form.form-items.other.text")
                    @endif

                    @if($custom_form_column['form_column_type'] == '99' && in_array($custom_form_column['form_column_target_id'],[3,4]))
                    @include("exment::custom-form.form-items.other.html")
                    @endif
                    
                    @if($custom_form_column['form_column_type'] == '99' && in_array($custom_form_column['form_column_target_id'],[5]))
                    @include("exment::custom-form.form-items.other.image")
                    @endif

                    @if($custom_form_column['form_column_type'] == '99' && in_array($custom_form_column['form_column_target_id'],[6]))
                    @include("exment::custom-form.form-items.other.hr")
                    @endif
            </div>
        </div>
        
        {{-- Show only items. not show suggests --}}
        @if(!boolval($suggest))
        {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[form_column_target_id]", $custom_form_column['form_column_target_id']) }}
        {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[form_column_type]", $custom_form_column['form_column_type']) }}
        {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[column_no]", $custom_form_column['column_no'], ['class' => 'column_no']) }}
        {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[required]", $custom_form_column['required'], ['class' => 'required']) }}
        @endif

        @if(boolval(array_get($custom_form_column, 'delete_flg')))
        {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[delete_flg]", $custom_form_column['delete_flg']) }}
        @endif

        {{-- set value for script, and set disabled(don't post. only use script) --}}
        {{ Form::hidden("", $custom_form_column['form_column_type'], ['class' => 'form_column_type', 'disabled' => 'disabled']) }}
        {{ Form::hidden("", $custom_form_column['form_column_target_id'], ['class' => 'form_column_target_id', 'disabled' => 'disabled']) }}
        {{ Form::hidden("", $custom_form_column['header_column_name'], ['class' => 'header_column_name', 'disabled' => 'disabled']) }}
        {{ Form::hidden("", $custom_form_column['required'], ['class' => 'required', 'disabled' => 'disabled']) }}
    </li>    
@endif
