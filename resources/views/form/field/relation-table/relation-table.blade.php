
<div class="row">
    <div class="{{$viewClass['label']}}">
        <h4 class="pull-right">{{ $label }}</h4>
    </div>
    <div class="{{$viewClass['field']}}"></div>
</div>
<div class="row">
    <div class="col-sm-8 col-sm-offset-2" style="padding:10px;">
        <div class="pull-right">
            {{-- Create Button --}}
            <div class="btn-group pull-right" style="margin-right: 10px">

                <button type="button" id="btn-create-{{$column}}" class="btn btn-sm btn-success" data-toggle="modal" data-target="#relation-modal-{{$column}}">
                    <i class="fa fa-save"></i>&nbsp;&nbsp;@lang('admin.new')
                </button>
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div id="relation-table-{{$column}}" class="relation-table-{{$column}} col-sm-8 col-sm-offset-2">
        <table class="table table-hover" style="margin-bottom:70px">
            <thead>
                <tr>
                    @foreach($header_columns as $k => $header_column)
                    <th data-field-name="{{ $k }}">{{$header_column}}</th>
                    @endforeach
                    <th>@lang('admin.action')</th>
                </tr>
            </thead>
            <tbody>
                @foreach($forms as $pk => $form)
                <tr>
                    @foreach($form->fields() as $field)
                @if($field instanceof \Encore\Admin\Form\Field\Hidden)
                @continue
                @endif
                    <td>
                        {!! $field->render() !!}
                    </td>
                    @endforeach
                    <td>
                        <a href="javascript:void(0);" data-id="1" class="grid-row-delete">
                            <i class="fa fa-trash"></i>
                        </a>
                        @foreach($form->fields() as $field)
                    @if($field instanceof \Encore\Admin\Form\Field\Hidden)
                    {!! $field->render() !!}
                    @endif
                    @endforeach
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
<template id="relation-table-template-action-{{$column}}">
    <td>
        <a href="javascript:void(0);" data-id="" class="grid-row-delete">
            <i class="fa fa-trash"></i>
        </a>

        {!! $template !!}
    </td>

</template>


{{-- create dialog --}}
<div id="relation-modal-{{$column}}" class="modal fade" role="dialog" aria-labelledby="gridSystemModalLabel" data-backdrop="static">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title">@lang('admin.new')</h4>
            </div>
            <div class="modal-body fields-group">
                @foreach($modal_form->fields() as $field)
                    {!! $field->render() !!}
                @endforeach
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">@lang('admin.close')</button>
                <button type="button" class="btn btn-primary setting">@lang('admin.setting')</button>
            </div>
        </div>
        <!-- /.modal-content -->
    </div>
    <!-- /.modal-dialog -->
</div>
<!-- /.modal -->
