<div class="row">
    {{-- calc field list --}}
    <div class="col-sm-6 col-value">
        <h4 class="bold">{{exmtrans('custom_column.calc_formula.calc_formula')}}</h4>
            <span>
            {{exmtrans('custom_column.calc_formula.message.description')}}
            {!! \Exment::getMoreTag('column', 'custom_column.options.calc_formula') !!}
            </span>
        <textarea id="calc_formula_input" rows="3" class="w-100">{{$value}}</textarea>

        <button type="button" id="validateFormula" class="btn btn-info">{{exmtrans('custom_column.calc_formula.validate_formula')}}</button>
        <span id="validateResult" style="margin-left: 5px;">
            <span id="validateResultSuccess" class="validateResultSuccess"><i class="fa fa-circle-o"></i>{{exmtrans('custom_column.calc_formula.message.validate_success')}}</span>
            <span id="validateResultError" class="validateResultError"><i class="fa fa-times"></i>{{exmtrans('custom_column.calc_formula.message.validate_error')}}</span>
        </span>
    </div>

    {{--// calc field list --}} {{-- calc suggest item list --}}
    <div class="col-sm-6 col-target">
        <div class="col-target-block col-target-block-column">
            <h4 class="bold">{{exmtrans('custom_column.calc_formula.dynamic')}}</h4>
            <div class="row">
                @foreach($custom_columns as $custom_column)
                <div class="col-sm-6">
                    <button type="button" class="btn btn-default button-addcalcitem" style="width:100%;" 
                        data-type="{{ $custom_column->type() ?? 'dynamic' }}" data-val="{{ $custom_column->val() }}" data-display-text="{{ $custom_column->displayText() }}" >
                        {{ $custom_column->text() }}
                    </button>
                </div>
                @endforeach
            </div>
        </div>

        {{-- calc mark --}}
        <div class="col-target-block">
            <h4 class="bold">{{exmtrans('custom_column.calc_formula.symbol')}}</h4>
            <div class="row">
                @foreach($symbols as $symbol)
                <div class="col-sm-3">
                    <button type="button" class="btn btn-success button-addcalcitem" style="width:100%;" data-type="symbol" data-val="{{$symbol['val']}}" data-displayText="{{$symbol['displayText']}}">
                        {{ $symbol['displayText'] }}
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
    .col-target .col-target-block h4,.col-value h4{
        margin-bottom: 0.25em;
    }
    .col-target-block-column .btn{
        margin-top: 2px;
        margin-bottom: 2px;
        white-space: normal;
        word-break: break-all;
    }
    .col-value-item-remove{
        cursor: pointer;
    }

    .validateResultSuccess{
        color: green;
        display: none;
    }
    .validateResultError{
        color: red;
        display: none;
    }
</style>