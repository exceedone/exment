
<div class="form-group pb-3">
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

    @if($hr)
    <hr class="fixed-border-color" style="margin: 0px 15px;"  />
    @endif
</div>

