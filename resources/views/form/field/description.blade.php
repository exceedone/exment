<div class="form-group">
    <div class="{{$viewClass['field']}} {{$offset}}" {!! $attributes !!} >
        @if($escape)
        {{ $label }}
        @else
        {!! $label !!}
        @endif
    </div>
</div>