<link rel="stylesheet" type="text/css" href="{{$css}}" />
<input type="hidden" id="relationFilterUrl" value="{{$relationFilterUrl}}">
<input type="hidden" id="cofirm_required_title" value="{{trans('admin.confirm')}}">
<input type="hidden" id="cofirm_required_text" value="{{exmtrans('custom_form.message.confirm_required')}}">


<form method="POST" action="{{$endpoint}}" accept-charset="UTF-8" pjax-container class="custom_form_form">
    {{-- Form basic setting --}}
    <div class="box box-info box-custom_form_block">
        <div class="box-header with-border">
            <h3 class="box-title">{{ exmtrans('custom_form.header_basic_setting') }}</h3>

            <div class="pull-right btn-group " style="margin-right: 10px">
                <a href="{{ $formroot }}" class="btn btn-sm btn-default"><i class="fa fa-list"></i>&nbsp;{{ trans('admin.list') }}</a>
            </div>
            
            {!! $change_page_menu !!}

        </div>
        <!-- /.box-header -->

        <div class="box-body">
            <div class="form-horizontal">
                <div class="form-group">
                    {{ Form::label("", exmtrans('custom_form.form_view_name'), ['class' => 'control-label col-sm-2 asterisk'])}}
                    <div class="col-sm-8">
                        {{ Form::text('form_view_name', $form_view_name, ['class' => 'form-control', 'required' => 'required']) }}
                    </div>
                </div>
                <div class="form-group">
                    {{ Form::label("", exmtrans('custom_form.default_flg'), ['class' => 'control-label col-sm-2'])}}
                    <div class="col-sm-8">
                        {{ Form::checkbox('default_flg', $default_flg, $default_flg=='1', ['class' => 'default_flg la_checkbox', 'data-onvalue' => '1', 'data-offvalue' => '0']) }}
                        {{ Form::hidden('default_flg', $default_flg, ['class' => 'default_flg']) }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    @foreach($custom_form_blocks as $custom_form_block)
    <div class="box box-custom_form_block">
        <div class="box-header with-border">
            <h3 class="box-title">{{$custom_form_block['label']}}</h3>
            <div class="box-tools pull-right">
                <button type="button" class="btn btn-box-tool" data-widget="collapse">
                <i class="fa fa-minus"></i>
            </button>
                <button type="button" class="btn btn-box-tool" data-widget="remove"><i class="fa fa-times"></i></button>
            </div>
        </div>
        <!-- /.box-header -->

        <div class="box-body">
            {{-- Use checkbox only relation block --}} 
            @if($custom_form_block['form_block_type'] != '0')
            <div class="custom_form_block_available">
                {{ Form::checkbox("{$custom_form_block['header_name']}[available]", 1, $custom_form_block['available'], ['id' => "custom_form_block_{$custom_form_block['id']}__available_",
                'class' => 'icheck icheck_toggleblock custom_form_block_available']) }} {{ Form::label("custom_form_block_{$custom_form_block['id']}__available_",
                exmtrans('common.available')) }}
            </div>
            @else 
            {{ Form::hidden("{$custom_form_block['header_name']}[available]", $custom_form_block['available'], ['class' => 'custom_form_block_available']) }} 
            @endif

            <div class="custom_form_block" style="display:{{ boolval($custom_form_block['available']) ? 'block' : 'none' }}">
                {{-- Form Block Label --}}
                <div class="col-sm-12">
                    {{-- select hasmany or hasmanytable --}}
                    @if($custom_form_block['form_block_type'] == '1')
                    <div class="form-group">
                        {{ Form::checkbox("{$custom_form_block['header_name']}[options][hasmany_type]", 1, array_get($custom_form_block, 'options.hasmany_type'), ['id' => "custom_form_block_{$custom_form_block['id']}__options__hasmany_type_",
                        'class' => 'icheck icheck_hasmany_type']) }} {{ Form::label("custom_form_block_{$custom_form_block['id']}__options__hasmany_type_",
                        exmtrans('custom_form.hasmany_type')) }}
                    </div>
                    @endif
                </div>
                <div class="form-inline col-sm-12">
                    <div class="form-group">
                        {{ Form::label("", exmtrans('custom_form.form_block_name'), ['class' => 'control-label', 'style' => 'padding-right:15px;'])
                        }} {{ Form::text("{$custom_form_block['header_name']}[form_block_view_name]", $custom_form_block['form_block_view_name'],
                        ['class' => 'form-control', 'style' => 'width:400px;']) }}
                    </div>
                </div>

                @if($custom_form_block['form_block_type'] != '2')
                <div class="col-xs-12 col-md-12" style="margin-top:2em;">
                    <h4>{{ exmtrans('custom_form.items') }}</h4>
                    <span class="help-block">
                        <i class="fa fa-info-circle"></i>&nbsp;{!! exmtrans('custom_form.help.items') !!}
                    </span>
                </div>

                <div class="col-md-8">
                
                <div class="col-xs-12 col-md-6 custom_form_column_block items_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}"
                    data-form_block_type="{{$custom_form_block['form_block_type']}}" data-form_block_target_table_id="{{$custom_form_block['form_block_target_table_id']}}" data-form_column_no="1">

                    <h5 class="bold">
                        {{ exmtrans('custom_form.items') }} {{ exmtrans('common.column') }}1
                        
                        &nbsp;
                        <button type="button" class="btn btn-default btn-xs" data-toggle-expanded-value="false"><i class="fa fa-angle-double-down" aria-hidden="true"></i>{{exmtrans('common.open_all')}}</button>
                        <button type="button" class="btn btn-default btn-xs" data-toggle-expanded-value="true"><i class="fa fa-angle-double-up" aria-hidden="true"></i>{{exmtrans('common.close_all')}}</button>
                    </h5>
                    <ul class="custom_form_column_items draggables ul_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}" data-connecttosortable="suggests_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}">
                        @foreach($custom_form_block['custom_form_columns'] as $custom_form_column)
                        @if(array_get($custom_form_column, 'column_no') != 1) @continue @endif
    @include("exment::custom-form.form-item", ['custom_form_column'
                        => $custom_form_column, 'suggest' => false]) @endforeach
                    </ul>
                </div>
                <div class="col-xs-12 col-md-6 custom_form_column_block items_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}"
                    data-form_block_type="{{$custom_form_block['form_block_type']}}" data-form_block_target_table_id="{{$custom_form_block['form_block_target_table_id']}}" data-form_column_no="2">

                    <h5 class="bold">
                        {{ exmtrans('custom_form.items') }} {{ exmtrans('common.column') }}2
                        
                        &nbsp;
                        <button type="button" class="btn btn-default btn-xs" data-toggle-expanded-value="false"><i class="fa fa-angle-double-down" aria-hidden="true"></i>{{exmtrans('common.open_all')}}</button>
                        <button type="button" class="btn btn-default btn-xs" data-toggle-expanded-value="true"><i class="fa fa-angle-double-up" aria-hidden="true"></i>{{exmtrans('common.close_all')}}</button>
                    </h5>
                    <ul class="custom_form_column_items draggables ul_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}" data-connecttosortable="suggests_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}">
                        @foreach($custom_form_block['custom_form_columns'] as $custom_form_column)
                        @if(array_get($custom_form_column, 'column_no') != 2) @continue @endif
    @include("exment::custom-form.form-item", ['custom_form_column'
                        => $custom_form_column, 'suggest' => false]) @endforeach
                    </ul>
                </div>
                </div>
                <div class="col-xs-12 col-md-1 arrows-h">
                    <i class="fa fa-arrow-left"></i>
                </div>
                <div id="suggests_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}" class="col-xs-12 col-md-3 custom_form_column_block"
                    data-form_block_type="{{$custom_form_block['form_block_type']}}" data-form_block_target_table_id="{{$custom_form_block['form_block_target_table_id']}}">
                    <h5 class="bold">{{ exmtrans('custom_form.items') }} {{ exmtrans('custom_form.suggest_items') }}</h5>
                    @foreach($custom_form_block['suggests'] as $suggest)
                    <div class="custom_form_column_block_inner">
                        <h5>{{$suggest['label']}}
                            @if($suggest['form_column_type'] == 'column')
                            <button type="button" class="btn-addallitems btn btn-xs btn-default"><i class="fa fa-arrow-left"></i>&nbsp;{{ exmtrans('custom_form.add_all_items') }}</button>
                            @endif
                        </h5>
                        <ul class="custom_form_column_suggests draggables" data-connecttosortable="items_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}"
                            data-draggable_clone="{{$suggest['clone']}}" data-form_column_type="{{$suggest['form_column_type']}}">
                            @foreach($suggest['custom_form_columns'] as $custom_form_column)
                                @include("exment::custom-form.form-item", ['custom_form_column' => $custom_form_column, 'suggest' => true])
                            @endforeach
                        </ul>
                    </div>
                    @endforeach 
                    
                    {{-- for template --}}
                    <div class="template_item_block">
                    @foreach($custom_form_block['suggests'] as $suggest) 
                    @foreach($suggest['custom_form_columns'] as $custom_form_column)
                    <div style="display:none;" data-form_column_target_id="{{$custom_form_column['form_column_target_id']}}" data-form_column_type="{{$custom_form_column['form_column_type']}}">
    @include("exment::custom-form.form-item", ['custom_form_column' => $custom_form_column, 'suggest' => true, 'template_item'
                        => true])
                    </div>
                    @endforeach 
                    @endforeach
                    </div>
                </div>

                @endif {{-- / custom_form_block_form_block_type != '2' --}}

            </div>
        </div>
        <!-- /.box-body -->

        {{-- set form --}} {{ Form::hidden("{$custom_form_block['header_name']}[form_block_type]", $custom_form_block['form_block_type'])
        }} {{ Form::hidden("{$custom_form_block['header_name']}[form_block_target_table_id]", $custom_form_block['form_block_target_table_id'])
        }} {{ Form::hidden("", $custom_form_block['header_name'], ['class' => 'header_name', 'disabled' => 'disabled']) }}

        <input type="hidden" class="select-table-columns" value="{{$custom_form_block['select_table_columns']}}" />
    </div>

    @endforeach
    {{-- /custom_form_block --}}
    {{csrf_field() }} @if($editmode)
    <input type="hidden" name="_method" value="PUT" class="_method"> @endif
    <button type="submit" id="admin-submit" class="btn btn-info pull-right" style="margin-bottom:2em;" data-loading-text="<i class='fa fa-spinner fa-spin '></i> {{__('admin.save')}}">@lang('admin.save')</button>
</form>

{{-- Modal --}}
<div class="modal fade" id="form-changedata-modal" data-backdrop="static">
    <div class="modal-dialog" >
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>×</span></button>
                <h4 class="modal-title" id="modal-label">{{exmtrans('custom_form.changedata')}}</h4>
            </div>
            <div class="modal-body" id="modal-body">
                <div class="col-sm-12">
                    <span class="help-block">
                        <i class="fa fa-info-circle"></i>&nbsp;{!! sprintf(exmtrans('custom_form.help.changedata'), getManualUrl('form#'.exmtrans('custom_form.changedata'))) !!}
                    </span>
                </div>    
                <div class="col-sm-12 select_no_item red small" style="display:none;">
                    {{exmtrans('custom_form.help.changedata_no_item')}}
                </div>    
                <div class="col-sm-12 select_item">
                    <select data-add-select2="{{exmtrans('custom_form.changedata_target_column')}}" class="form-control select2 changedata_target_column" style="width: 100%;" tabindex="-1" aria-hidden="true">
                    </select>
                </div>    
                <div class="col-sm-12 small select_item" style="margin-bottom:1em;">
                    {{exmtrans('custom_form.changedata_target_column_when')}}
                </div>
                <div class="col-sm-12 select_item">
                    <select data-add-select2="{{exmtrans('custom_form.changedata_column')}}" class="form-control select2 changedata_column" style="width: 100%;" tabindex="-1" aria-hidden="true">
                    </select>
                </div>    
                <div class="col-sm-12 small select_item" style="margin-bottom:1em;">
                    {{exmtrans('custom_form.changedata_column_then')}}
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default pull-left modal-close" data-dismiss="modal">{{trans('admin.close')}}</button>
                <button id="changedata-button-reset" type="button" class="btn btn-default pull-left">{{trans('admin.reset')}}</button>
                <button id="changedata-button-setting" type="button" class="btn btn-info select_item">{{trans('admin.setting')}}</button>
                <input type="hidden" class="target_header_column_name" />
            </div>
        </div>
    </div>
</div>


<div class="modal fade" id="form-relation_filter-modal" data-backdrop="static">
</div>


<div class="modal fade" id="form-textinput-modal" data-backdrop="static">
    <div class="modal-dialog modal-xl" >
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>×</span></button>
                <h4 class="modal-title" id="modal-label">{{trans('admin.edit')}}</h4>
            </div>
            <div class="modal-body" id="modal-body">
                <textarea id="textinput-modal-textarea" class="w-100" rows="20"></textarea>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default pull-left modal-close" data-dismiss="modal">{{trans('admin.close')}}</button>
                <button id="textinput-button-reset" type="button" class="btn btn-default pull-left">{{trans('admin.reset')}}</button>
                <button id="textinput-button-setting" type="button" class="btn btn-info select_item">{{trans('admin.setting')}}</button>
            </div>
        </div>
    </div>
</div>



<script type="text/javascript" src="{{ $js }}"></script>