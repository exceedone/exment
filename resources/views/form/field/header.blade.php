
<div class="form-group box-header with-border">
    <div class="row" {!! $attributes !!} >
        <div class="col-sm-12" >
            <h{{$no}} class="field-header" style="margin: 0px 70px;">
                @if($escape)
                    {{ $headerLabel }}
                @else
                    {!! $headerLabel !!}
                @endif
            </h{{$no}}>
        </div>
    </div>
</div>

