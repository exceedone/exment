
<div id="has-many-table-{{$column}}" class="has-many-table-div">
    <div class="row">
        <div class="{{$viewClass['label']}}"><h4 class="pull-right">{{ $label }}</h4></div>
        <div class="{{$viewClass['field']}}"></div>
    </div>

    <hr style="margin-top: 0px;">
    @if(isset($description))
        <div class="col-sm-{{$tablewidth['width']}} col-sm-offset-{{$tablewidth['offset']}}" style="margin-bottom:20px;">
            {!! $description !!}
        </div>
    @endif
    <div class="col-sm-{{$tablewidth['width']}} col-sm-offset-{{$tablewidth['offset']}}">
        <table id="has-many-table-{{$column}}-table" class="table table-bordered has-many-table has-many-table-{{$column}}-table">
            <thead>
            <tr class="active">
                @foreach($tableitems as $tableitem)
                    <th class="text-center {{$loop->index < count($tablecolumnwidths) ? 'col-sm-'.$tablecolumnwidths[$loop->index] : ''}}">{{ $tableitem->label() }}</th>
                @endforeach
                <th class="text-center {{count($tableitems) < count($tablecolumnwidths) ? 'col-sm-'.$tablecolumnwidths[count($tableitems)] : ''}}">{{trans('admin.action')}}</th>
            </tr>
            </thead>
            <tbody>
            @foreach($forms as $pk => $form)
            <tr class="has-many-table-{{$column}}-row">
                @foreach($form['tableitems'] as $tableitem)
                <td>{!! $tableitem->render() !!}</td>
                @endforeach

                <td class="text-center">
                    @foreach($form['hiddens'] as $hidden)
                    {!! $hidden->render() !!}
                    @endforeach
                    <a href="javascript:void(0);" class="btn btn-warning remove">
                        <i class="fa fa-trash" style=""></i>
                    </a>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>

        <div id="has-many-table-button-{{$column}}" class="form-group">
            <div class="col-sm-12">
                <div class="add btn btn-success btn-sm"><i class="fa fa-save"></i>&nbsp;{{ trans('admin.new') }}</div>
            </div>
        </div>
    </div>
    <template class="{{$column}}-tpl">
        <tr class="has-many-table-{{$column}}-row">
            @foreach($tableitems as $tableitem)
                <td>{!! $tableitem->render() !!}</td>
            @endforeach
            
            <td class="text-center">
                @foreach($hiddens as $hidden)
                {!! $hidden->render() !!}
                @endforeach
                <a href="javascript:void(0);" class="btn btn-warning remove">
                    <i class="fa fa-trash" style=""></i>
                </a>
            </td>
        </tr>
    </template>

    <style type="text/css">
    .has-many-table .form-group{
        margin-bottom: 0;
    }
    </style>
</div>