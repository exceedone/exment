@if(!isset($template_item) && (isset($custom_form_column['has_custom_forms']) && boolval($custom_form_column['has_custom_forms'])))

@else
<div class="ui-state-default custom_form_column_item draggable form_column_type_{{$custom_form_column['form_column_type']}}"
    style="{{boolval(array_get($custom_form_column, 'delete_flg')) ? 'display:none' : ''}}"
    id="{{preg_replace('/\[|\]/', '_', $custom_form_column['header_column_name'])}}" 
    data-header_column_name="{{preg_replace('/\[|\]/', '_', $custom_form_column['header_column_name'])}}"
    data-option_labels_definitions="{{$custom_form_column['option_labels_definitions'] }}"
    >
        <p class="item-label-top">
            <span class="item-label {{array_get($custom_form_column, 'required') ? 'asterisk' : ''}}">
                @if(isset($custom_form_column['font_awesome']))
                <i class="text-center fa {{$custom_form_column['font_awesome']}}" aria-hidden="true" style="width:16px;"></i>
                @endif
                <span class="item-label-inner">
                {{ $custom_form_column['column_view_name'] }}
                </span>
            </span>

            <a href="javascript:void(0);" class="config-icon pull-right delete" style="display:{{!boolval($suggest) ? 'inline-block' : 'none'}};" data-toggle="tooltip" title="{{exmtrans('common.deleted')}}">
                <i class="fa fa-trash"></i>
            </a>
            
            @if(boolval($custom_form_column['use_setting']))
            <a href="javascript:void(0);" class="config-icon pull-right setting" style="display:{{!boolval($suggest) ? 'inline-block' : 'none'}};" data-widgetmodal_method="POST" data-toggle="tooltip" title="{{trans('admin.setting')}}">
                <i class="fa fa-cog"></i>
            </a>
            @endif
        </p>
        <p class="item-label-bottom">
            @foreach($custom_form_column['option_labels'] ?? [] as $option_label)
            {{$option_label}}
            @if(!$loop->last)
            <br/>
            @endif
            @endforeach
        </p>
        
        @include('exment::custom-form.fields.block-hidden', ['param_name' => 'form_block_type'])
        @include('exment::custom-form.fields.block-hidden', ['param_name' => 'form_block_target_table_id'])
        @include('exment::custom-form.fields.column-hidden', ['param_name' => 'options'])

        {{-- Show only items. not show suggests --}}
        @if(!boolval($suggest))
        @include('exment::custom-form.fields.column-hidden', ['param_name' => 'form_column_target_id'])
        @include('exment::custom-form.fields.column-hidden', ['param_name' => 'form_column_type'])
        @include('exment::custom-form.fields.column-hidden', ['param_name' => 'row_no'])
        @include('exment::custom-form.fields.column-hidden', ['param_name' => 'column_no'])
        @include('exment::custom-form.fields.column-hidden', ['param_name' => 'width'])
        {{ Form::hidden("{$custom_form_block['header_name']}{$custom_form_column['header_column_name']}[required]", array_get($custom_form_column, 'required'), ['class' => 'required_item']) }}
        @endif

        @include('exment::custom-form.fields.column-hidden', ['param_name' => 'delete_flg'])

        {{-- set value for script, and set disabled(don't post. only use script) --}}
        @include('exment::custom-form.fields.column-hidden-disabled', ['param_name' => 'form_column_type'])
        @include('exment::custom-form.fields.column-hidden-disabled', ['param_name' => 'form_column_target_id'])
        @include('exment::custom-form.fields.column-hidden-disabled', ['param_name' => 'header_column_name'])
        @include('exment::custom-form.fields.column-hidden-disabled', ['param_name' => 'required'])
        @include('exment::custom-form.fields.column-hidden-disabled', ['param_name' => 'validation_rules'])
    </div>    
@endif
