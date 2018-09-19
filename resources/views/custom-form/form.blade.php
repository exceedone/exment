<link rel="stylesheet" type="text/css" href="{{$css}}" />

<form method="POST" action="{{$endpoint}}" accept-charset="UTF-8" pjax-container>
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
                    {{ Form::label("", exmtrans('custom_form.form_view_name'), ['class' => 'control-label col-sm-2'])}}
                    <div class="col-sm-8">
                        {{ Form::text('form_view_name', $form_view_name, ['class' => 'form-control']) }}
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
            {{-- Use checkbox only relation block --}} @if($custom_form_block['form_block_type'] != 'default')
            <div class="custom_form_block_available">
                {{ Form::checkbox("{$custom_form_block['header_name']}[available]", 1, $custom_form_block['available'], ['id' => "custom_form_block_{$custom_form_block['id']}__available_",
                'class' => 'icheck icheck_toggleblock']) }} {{ Form::label("custom_form_block_{$custom_form_block['id']}__available_",
                exmtrans('custom_form.available')) }}
            </div>
            @else {{ Form::hidden("{$custom_form_block['header_name']}[available]", $custom_form_block['available']) }} @endif
            <div class="custom_form_block" style="display:{{ boolval($custom_form_block['available']) ? 'block' : 'none' }}">
                {{-- Form Block Label --}}
                <div class="form-inline col-sm-12">
                    <div class="form-group">
                        {{ Form::label("", exmtrans('custom_form.form_block_name'), ['class' => 'control-label', 'style' => 'padding-right:15px;'])
                        }} {{ Form::text("{$custom_form_block['header_name']}[form_block_view_name]", $custom_form_block['form_block_view_name'],
                        ['class' => 'form-control', 'style' => 'width:400px;']) }}
                    </div>
                </div>

                <div id="items_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}" class="col-xs-12 col-md-7 custom_form_column_block"
                    data-form_block_type="{{$custom_form_block['form_block_type']}}" data-form_block_target_table_id="{{$custom_form_block['form_block_target_table_id']}}">

                    <h5>{{ exmtrans('custom_form.items') }}</h5>
                    <ul class="custom_form_column_items draggables" data-connecttosortable="suggests_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}">
                        @foreach($custom_form_block['custom_form_columns'] as $custom_form_column)
    @include("exment::custom-form.form-item", ['custom_form_column'
                        => $custom_form_column, 'suggest' => false]) @endforeach
                    </ul>
                </div>
                <div class="col-xs-12 col-md-1 arrows-h">
                    <i class="fa fa-arrows-h"></i>
                </div>
                <div id="suggests_{{$custom_form_block['form_block_type']}}_{{$custom_form_block['form_block_target_table_id']}}" class="col-xs-12 col-md-4 custom_form_column_block"
                    data-form_block_type="{{$custom_form_block['form_block_type']}}" data-form_block_target_table_id="{{$custom_form_block['form_block_target_table_id']}}">
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
    @include("exment::custom-form.form-item", ['custom_form_column'
                            => $custom_form_column, 'suggest' => true]) @endforeach
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
    <button type="submit" class="btn btn-info pull-right" data-loading-text="<i class='fa fa-spinner fa-spin '></i> {{__('admin.submit')}}">@lang('admin.submit')</button>
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
                        <select data-add-select2="{{exmtrans('custom_form.changedata_target_column')}}" class="form-control select2 changedata_target_column" style="width: 100%;" tabindex="-1" aria-hidden="true">
                        </select>
                    </div>    
                    <div class="col-sm-12 small" style="margin-bottom:1em;">
                        {{exmtrans('custom_form.changedata_target_column_when')}}
                    </div>
                    <div class="col-sm-12">
                        <select data-add-select2="{{exmtrans('custom_form.changedata_column')}}" class="form-control select2 changedata_column" style="width: 100%;" tabindex="-1" aria-hidden="true">
                        </select>
                    </div>    
                    <div class="col-sm-12 small" style="margin-bottom:1em;">
                        {{exmtrans('custom_form.changedata_column_then')}}
                    </div>
                </div>
                <div class="modal-footer">
                    <div class="col-sm-12">
                            <button id="changedata-button-reset" type="button" class="btn btn-default">{{trans('admin.reset')}}</button>
                            <button id="changedata-button-setting" type="button" class="btn btn-info">{{trans('admin.setting')}}</button>

                            <input type="hidden" class="target_header_column_name" />
                    </div>
                </div>
            </div>
        </div>
    </div>

<script type="text/javascript" src="{{ $js }}"></script>