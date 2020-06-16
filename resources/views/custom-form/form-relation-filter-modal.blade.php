
<div class="modal-dialog" >
    <div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal"><span>×</span></button>
            <h4 class="modal-title" id="modal-label">{{exmtrans('custom_form.relation_filter')}}</h4>
        </div>
        <div class="modal-body" id="modal-body">
            <div class="col-sm-12">
                <span class="help-block">
                    <i class="fa fa-info-circle"></i>&nbsp;{!! sprintf(exmtrans('custom_form.help.relation_filter'), getManualUrl('form#'.exmtrans('custom_form.relation_filter'))) !!}
                </span>
            </div>    
            @if(count($columns) == 0)
            <div class="col-sm-12 select_no_item red small">
                {{exmtrans('custom_form.help.relation_filter_no_item', isset($target_column) ? $target_column->column_view_name : '')}}
            </div>    
            @else
            <div class="col-sm-12 select_item">
                <select data-add-select2="{{exmtrans('custom_form.relation_filter_target_column')}}" class="form-control select2 relation_filter_target_column" style="width: 100%;" tabindex="-1" aria-hidden="true">
                    @foreach($columns as $column)
                    <option value="{{$column['parent_column']->id}}">{{$column['parent_column']->column_view_name}}</option>
                    @endforeach
                </select>
            </div>    
            @endif
        </div>
        <div class="modal-footer">
            <div class="col-sm-12">
                    <button id="relation_filter-button-reset" type="button" class="btn btn-default">{{trans('admin.reset')}}</button>
                    <button id="relation_filter-button-setting" type="button" class="btn btn-info select_item">{{trans('admin.setting')}}</button>

                    <input type="hidden" class="target_header_column_name" />
            </div>
        </div>
    </div>
</div>
