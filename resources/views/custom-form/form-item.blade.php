@if(!isset($template_item) && (isset($custom_form_column['has_custom_forms']) && boolval($custom_form_column['has_custom_forms'])))

@else
<div class="ui-state-default custom_form_column_item draggable"
    style="{{boolval(array_get($custom_form_column, 'delete_flg')) ? 'display:none' : ''}}"
    id="{{preg_replace('/\[|\]/', '_', $custom_form_column['header_column_name'])}}" data-header_column_name="{{preg_replace('/\[|\]/', '_', $custom_form_column['header_column_name'])}}">
        <span class="item-label {{array_get($custom_form_column, 'required') ? 'asterisk' : ''}}">{{ $custom_form_column['column_view_name'] }}</span>

        <a href="javascript:void(0);" class="config-icon pull-right delete" style="display:{{!boolval($suggest) ? 'inline-block' : 'none'}};">
            <i class="fa fa-trash"></i>
        </a>
        
        <a href="javascript:void(0);" class="config-icon pull-right setting" style="display:{{!boolval($suggest) ? 'inline-block' : 'none'}};" data-widgetmodal_method="POST">
            <i class="fa fa-cog"></i>
        </a>
        
        {{ Form::hidden("{$custom_form_block['header_name']}[form_block_type]", $custom_form_block['form_block_type'], ['class' => 'form_block_type'])}}
        {{ Form::hidden("{$custom_form_block['header_name']}[form_block_target_table_id]", $custom_form_block['form_block_target_table_id'], ['class' => 'form_block_target_table_id'])}}
        {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[options]", collect($custom_form_column['options'])->toJson()) }}

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
    </div>    
@endif
