<input type="hidden" id="confirm_required_title" value="{{trans('admin.confirm')}}">
<input type="hidden" id="confirm_required_text" value="{{exmtrans('custom_form.message.confirm_required')}}">
<input type="hidden" id="formroot" value="{{ $formroot }}">
<input type="hidden" id="resize_box_tooltip" value="{{ exmtrans('custom_form.resize_box_tooltip') }}">
<input type="hidden" id="delete_title" value="{{ exmtrans('common.deleted') }}">
<input type="hidden" id="delete_revert_message" value="{{ exmtrans('custom_form.message.delete_revert_message') }}">
<input type="hidden" id="validate_error_message" value="{{ exmtrans('custom_form.message.validate_error_message') }}">
<input type="hidden" id="validate_error_title" value="{{ exmtrans('common.error') }}">

<form id="custom_form_form" method="POST" action="{{$endpoint}}" accept-charset="UTF-8" pjax-container class="custom_form_form">
    {{-- Form basic setting --}}
    <div class="form-horizontal">
        {!! $headerBox !!}
    </div>
    
    @foreach($custom_form_blocks as $index => $custom_form_block)
    <div class="box box-custom_form_block">
        <div class="box-header with-border">
            <h3 class="box-title">{{$custom_form_block['label']}}</h3>
            <div class="box-tools pull-right">
                <button type="button" class="btn btn-box-tool" data-widget="collapse">
                    <i class="fa fa-minus"></i>
                </button>
            </div>
        </div>
        <!-- /.box-header -->

        <div class="box-body">
            {{-- Use checkbox only relation block --}} 
            @if($custom_form_block['form_block_type'] != '0')
            <div class="custom_form_block_available">
                {{ Form::checkbox("{$custom_form_block['header_name']}[available]", 1, $custom_form_block['available'], 
                ['id' => "custom_form_block_{$custom_form_block['header_name']}__available_",
                'class' => 'icheck icheck_toggleblock custom_form_block_available', 'data-add-icheck' => '1']) }} 
                {{ Form::label("custom_form_block_{$custom_form_block['header_name']}__available_",
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
                        {{ Form::checkbox("{$custom_form_block['header_name']}[options][hasmany_type]", 1, array_get($custom_form_block, 'hasmany_type'), ['id' => "custom_form_block_{$custom_form_block['id']}__options__hasmany_type_",
                        'class' => 'icheck icheck_hasmany_type', 'data-add-icheck' => '1']) }} {{ Form::label("custom_form_block_{$custom_form_block['id']}__options__hasmany_type_",
                        exmtrans('custom_form.hasmany_type')) }}
                        <i class="fa fa-info-circle" data-help-text="{{exmtrans('custom_form.help.hasmany_type_table')}}" data-help-title="{{exmtrans('custom_form.hasmany_type')}}"></i>
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


                <div class="col-md-9">
                    <div class="custom_form_column_block"
                        data-form_block_type="{{$custom_form_block['form_block_type']}}" data-form_block_target_table_id="{{$custom_form_block['form_block_target_table_id']}}">

                        <div class="custom_form_column_items">
                            @foreach($custom_form_block['custom_form_rows'] as $custom_form_item_row)
                                @include('exment::custom-form.row-columns.row', ['row' => $custom_form_item_row])
                            @endforeach

                            <div class="row row-eq-height row_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}">
                                @include('exment::custom-form.row-columns.addarea')
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xs-12 col-md-3 custom_form_column_block"
                    data-form_block_type="{{$custom_form_block['form_block_type']}}" data-form_block_target_table_id="{{$custom_form_block['form_block_target_table_id']}}">
                    <h5 class="bold">{{ exmtrans('custom_form.items') }} {{ exmtrans('custom_form.suggest_items') }}</h5>
                    @foreach($custom_form_block['suggests'] as $suggest)
                    <div class="custom_form_column_block_inner">
                        <h5>{{$suggest['label']}}
                            @if($suggest['form_column_type'] == '0')
                                <button type="button" class="btn-addallitems btn btn-xs btn-default"><i class="fa fa-arrow-left"></i>&nbsp;{{ exmtrans('custom_form.add_all_items') }}</button>
                            @endif
                        </h5>
                        <div class="custom_form_column_suggests"
                            data-draggable_clone="{{$suggest['clone']}}" data-form_column_type="{{$suggest['form_column_type']}}">
                                <div class="draggables" data-connecttosortable="row_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}">
                                    @foreach($suggest['custom_form_columns'] as $custom_form_column)
                                        @include("exment::custom-form.form-item", ['custom_form_column' => $custom_form_column, 'suggest' => true])
                                    @endforeach
                                </div>
                        </div>
                    </div>
                    @endforeach 
                    
                    {{-- for template --}}
                    <div class="template_item_block">
                    @foreach($custom_form_block['suggests'] as $suggest) 
                        @if($suggest['form_column_type'] == '99')
                            @continue
                        @endif
                        @foreach($suggest['custom_form_columns'] as $custom_form_column)
                        <div style="display:none;" data-form_column_target_id="{{$custom_form_column['form_column_target_id']}}" data-form_column_type="{{$custom_form_column['form_column_type']}}">
                            @include("exment::custom-form.form-item", ['custom_form_column' => $custom_form_column, 'suggest' => true, 'template_item' => true])
                        </div>
                        @endforeach 
                    @endforeach
                    </div>

                    <div class="template_item_column d-none">
                        @include('exment::custom-form.row-columns.column')
                    </div>

                    <div class="template_item_row d-none">
                        <div class="row row-eq-height row_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}">
                            @include('exment::custom-form.row-columns.addarea')
                        </div>
                    </div>
                </div>

                @endif {{-- / custom_form_block_form_block_type != '2' --}}

            </div>
        </div>
        <!-- /.box-body -->

        {{-- set form --}} 
        @include('exment::custom-form.fields.block-hidden', ['param_name' => 'form_block_type'])
        @include('exment::custom-form.fields.block-hidden', ['param_name' => 'form_block_target_table_id'])
        @include('exment::custom-form.fields.block-hidden-disabled', ['param_name' => 'header_name'])
    </div>

    @endforeach
    {{-- /custom_form_block --}}
    {{csrf_field() }} @if($editmode)
    <input type="hidden" name="_method" value="PUT" class="_method"> @endif

    
    <div style="background-color: #FFF; width: 100%; overflow: hidden; padding: 10px; margin-bottom:2em;">
        <div class="btn-group pull-right">
            <button type="submit" id="admin-submit" class="btn btn-info pull-right" data-loading-text="<i class='fa fa-spinner fa-spin '></i> {{__('admin.save')}}">@lang('admin.save')</button>
        
            <label class="pull-right" style="margin: 5px 10px 0 0;">
                <input type="checkbox" class="after-submit" name="after-save" value="1" {{ $after_save == 1 ? 'checked' : '' }}> @lang('admin.continue_editing')
            </label>
        </div>
    </div>

</form>