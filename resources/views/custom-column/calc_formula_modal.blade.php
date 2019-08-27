<div class="row">
    {{-- calc field list --}}
    <div class="col-sm-6 col-value">
        <h5>{{exmtrans('custom_column.calc_formula.calc_formula')}}</h5>
        <div class="calc_formula_area" style="width:100%; border:1px solid black; min-height:100px;">
            @if(is_array($value))
            @foreach($value as $v)
            <p class="col-value-item" data-type="{{ array_get($v, 'type') }}" data-val="{{ array_get($v, 'val') }}" data-table="{{ array_get($v, 'table') }}">
                <span>{{ array_get($v, 'text') }}</span>
                <i class="fa fa-close pull-right col-value-item-remove"></i>
            </p>
            @endforeach
            @endif
        </div>
    </div>

    <template class="col-value-template">
        <p class="col-value-item" data-type="" data-val="">
            <span></span>
            <i class="fa fa-close pull-right col-value-item-remove"></i>
        </p>
    </template>

    {{--// calc field list --}} {{-- calc suggest item list --}}
    <div class="col-sm-6 col-target">
        <div class="col-target-block col-target-block-column">
            <h5>{{exmtrans('custom_column.calc_formula.dynamic')}}</h5>
            <div class="row">
                @foreach($custom_columns as $custom_column)
                <div class="col-sm-6">
                    <button type="button" class="btn btn-default button-addcalcitem" style="width:100%;" 
                        data-type="{{ array_get($custom_column, 'type')??'dynamic' }}" data-val="{{ array_get($custom_column, 'id') }}" data-table="{{ array_get($custom_column, 'custom_table_id') }}">
                    {{ array_get($custom_column, 'column_view_name') }}
                </button>
                </div>
                @endforeach
            </div>
        </div>

        {{-- fixed value --}}
        <div class="col-target-block">
            <h5>{{exmtrans('custom_column.calc_formula.fixed')}}</h5>
            <div class="row">
                <div class="col-sm-12">
                    <div class="form-inline">
                        <button type="button" class="btn btn-default button-addcalcitem" data-type="fixed">
                            <i class="fa fa-caret-left"></i>
                        </button>
                        <input type="text" class="form-control w100px col-target-fixedval" placeholder="{{exmtrans('custom_column.calc_formula.input_number')}}">
                    </div>
                </div>
            </div>
        </div>

        {{-- calc mark --}}
        <div class="col-target-block">
            <h5>{{exmtrans('custom_column.calc_formula.symbol')}}</h5>
            <div class="row">
                @foreach($symbols as $key => $symbol)
                <div class="col-sm-3">
                    <button type="button" class="btn btn-success button-addcalcitem" style="width:100%;" data-type="symbol" data-val="{{$key}}">
                        {{ $symbol }}
                    </button>
                </div>
                @endforeach
            </div>
        </div>

    </div>
    {{--// calc suggest item list --}}
</div>

{{-- TODO:CSS--}}
<style type="text/css">
    .col-value-item{
        margin:2px;
        padding: 4px 5px;
        border: 1px solid black;
        display: inline-block;
        border-radius: 3px;
    }
    .col-value-item span{
        padding: 0px 12px;
    }
    .col-target .col-target-block{
        margin-bottom: 2em;
    }
    .col-target .col-target-block h5,.col-value h5{
        margin-bottom: 0.25em;
    }
    .col-target-block-column .btn{
        margin-top: 2px;
        margin-bottom: 2px;
    }
    .col-value-item-remove{
        cursor: pointer;
    }
</style>