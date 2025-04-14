<div class="form-group">
    <div class="{{$viewClass['field']}} {{$offset}}" {!! $attributes !!} >
        @if($escape)
        {{ $label }}
        @else
        {!! html_clean($label) !!}
        @endif
    </div>
</div>