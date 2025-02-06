<div class="form-group">
    <div class="{{$viewClass['field']}} {{$offset}} ms-auto p-3 me-5" {!! $attributes !!} >
        @if($escape)
        {{ $label }}
        @else
        {!! html_clean($label) !!}
        @endif
    </div>
</div>