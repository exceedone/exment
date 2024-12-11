
<div id="has-many-table-{{$column}}" class="has-many-table-{{$column}} form-group has-many-table-div">
    @if($enableHeader)
    <div class="row">
        <div class="col-sm-12">
            <h4 class="field-header">{{ $label }}</h4>
        </div>
    </div>

    <hr style="margin-top: 0px;">
    @endif
    
    @if(isset($description))
        <div class="col-sm-{{$tablewidth['width']}} col-sm-offset-{{$tablewidth['offset']}}" style="margin-bottom:20px;">
            @if($escapeDescription)
            {{ $description }}
            @else
            {!! $description !!}
            @endif
        </div>
    @endif
    <div class="{{ str_starts_with($column, 'pivot') ? 'table-container ' : '' }}col-sm-{{$tablewidth['width']}} hasmanyblock-{{$column}} col-sm-offset-{{$tablewidth['offset']}}">
        <table id="has-many-table-{{$column}}-table" class="{{ str_starts_with($column, 'pivot') ? 'scrollable-table ' : '' }}table table-bordered has-many-table has-many-table-{{$column}}-table" {!! $attributes !!} >
            <thead>
            <tr class="active">
                @foreach($tableitems as $tableitem)
                    <th class="text-center {{$loop->index < count($tablecolumnwidths) ? 'col-sm-'.$tablecolumnwidths[$loop->index] : ''}} {{$loop->index < count($requires) && boolval($requires[$loop->index]) ? 'asterisk' : ''}}">
                        {{ $tableitem->label() }}

                        @if($loop->index < count($helps) && isset($helps[$loop->index]))
                        <i class="fa fa-info-circle" data-help-text="{{$helps[$loop->index]}}" data-help-title="{{ $tableitem->label() }}"></i>
                        @endif
                    </th>
                @endforeach

                @if($options['allowOptions'])
                <th class="text-center {{count($tableitems) < count($tablecolumnwidths) ? 'col-sm-'.$tablecolumnwidths[count($tableitems)] : ''}}">{{trans('admin.action')}}</th>
                @else
                <th style="display:none;"></th>
                @endif
            </tr>
            </thead>
            <tbody>
            @foreach($forms as $pk => $form)
            <tr class="has-many-table-{{$column}}-row has-many-table-row">
                @foreach($form['tableitems'] as $tableitem)
                <td style="vertical-align:middle;">{!! $tableitem->render() !!}</td>
                @endforeach

                @if($options['allowOptions'])
                <td class="text-center" style="vertical-align:middle;">
                    @foreach($form['hiddens'] as $hidden)
                    {!! $hidden->render() !!}
                    @endforeach
                    
                    @if($hasRowUpDown)
                    <a href="javascript:void(0);" class="btn btn-xs btn-primary row-move row-move-down" data-toggle="tooltip" title="{{exmtrans('common.row_down')}}" style="margin-right:2px;">
                        <i class="fa fa-arrow-down" style=""></i>
                    </a>
                    <a href="javascript:void(0);" class="btn btn-xs btn-success row-move row-move-up" data-toggle="tooltip" title="{{exmtrans('common.row_up')}}" style="margin-right:2px;">
                        <i class="fa fa-arrow-up" style=""></i>
                    </a>
                    @endif

                    @if($options['allowDelete'] && ($hideDeleteButtonRow === null || $hideDeleteButtonRow <= $loop->index))
                    <a href="javascript:void(0);" class="btn {{$hasRowUpDown ? 'btn-xs' : ''}} btn-warning remove" data-toggle="tooltip" title="{{trans('admin.delete')}}">
                        <i class="fa fa-trash" style=""></i>
                    </a>
                    @endif
                </td>
                @else
                <td style="display:none;">
                @foreach($form['hiddens'] as $hidden)
                    {!! $hidden->render() !!}
                @endforeach
                </td>
                @endif
            </tr>
            @endforeach
            </tbody>
        </table>

        @if($options['allowOptions'] && $options['allowCreate'])
        <div id="has-many-table-button-{{$column}}" class="form-group">
            <div class="col-sm-12">
                <div class="add btn btn-success btn-sm"><i class="fa fa-plus"></i>&nbsp;{{ trans('admin.new') }}</div>
            </div>
        </div>
        @endif

    </div>
    <template class="{{$column}}-tpl">
        <tr class="has-many-table-{{$column}}-row has-many-table-row">
            @foreach($tableitems as $tableitem)
                <td style="vertical-align:middle;">{!! $tableitem->render() !!}</td>
            @endforeach
            
            <td class="text-center" style="vertical-align:middle;">
                @foreach($hiddens as $hidden)
                {!! $hidden->render() !!}
                @endforeach
                @if($hasRowUpDown)
                <a href="javascript:void(0);" class="btn btn-xs btn-primary row-move row-move-down" data-toggle="tooltip" title="{{exmtrans('common.row_down')}}" style="margin-right:2px;">
                    <i class="fa fa-arrow-down" style=""></i>
                </a>
                <a href="javascript:void(0);" class="btn btn-xs btn-success row-move row-move-up" data-toggle="tooltip" title="{{exmtrans('common.row_up')}}" style="margin-right:2px;">
                    <i class="fa fa-arrow-up" style=""></i>
                </a>
                @endif

                <a href="javascript:void(0);" class="btn {{$hasRowUpDown ? 'btn-xs' : ''}} btn-warning remove" data-toggle="tooltip" title="{{trans('admin.delete')}}">
                    <i class="fa fa-trash" style=""></i>
                </a>
            </td>
        </tr>
    </template>

    <style type="text/css">
    .has-many-table .form-group{
        margin-bottom: 0;
    }
    .table-container {
        width: 100%;
        overflow-x: auto;
    }
    .scrollable-table {
        border-collapse: collapse;
        width: 100%;
        white-space: nowrap;
    }
    input {
        min-width: 90px;
    }
    .select2 {
        min-width: 100px;
    }
    </style>
</div>